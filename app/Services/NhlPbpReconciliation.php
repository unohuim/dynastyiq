<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlayByPlay;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Reconcile complete provider snapshots while retaining event correction history. */
final class NhlPbpReconciliation
{
    /** Validate source identity and event uniqueness before any reconciliation writes. */
    public function validate(int $gameId, array $payload): void
    {
        if ((int) ($payload['id'] ?? 0) !== $gameId || ! isset($payload['plays']) || ! is_array($payload['plays'])
            || ! isset($payload['homeTeam']['id'], $payload['awayTeam']['id'], $payload['gameType'])) {
            throw new InvalidArgumentException('Incomplete NHL play-by-play snapshot.');
        }
        $ids = [];
        foreach ($payload['plays'] as $event) {
            $id = $event['eventId'] ?? null;
            if ($id === null || isset($ids[(string) $id]) || ! isset($event['typeDescKey'], $event['periodDescriptor']['number'])) {
                throw new InvalidArgumentException('Incomplete or duplicate NHL event.');
            }
            $ids[(string) $id] = true;
        }
        foreach (['homeTeam', 'awayTeam'] as $side) {
            $team = $payload[$side];
            $goals = collect($payload['plays'])->filter(fn ($event) => $event['typeDescKey'] === 'goal'
                && ($event['periodDescriptor']['periodType'] ?? null) !== 'SO'
                && (int) ($event['details']['eventOwnerTeamId'] ?? 0) === (int) $team['id'])->count();
            $score = $team['score'] ?? null;
            $shootout = ($payload['periodDescriptor']['periodType'] ?? null) === 'SO';
            if ($score === null || (! $shootout && $goals !== (int) $score)
                || ($shootout && abs($goals - (int) $score) > 1)) {
                throw new InvalidArgumentException('NHL goal timeline does not match the scoreboard.');
            }
        }
        if ($payload['plays'] === []) {
            throw new InvalidArgumentException('Empty snapshots cannot withdraw stored events.');
        }
    }

    /** Accept changed event evidence; clock-only updates do not create work. */
    public function accept(int $gameId, array $payload, int $version, bool $force = false): void
    {
        $this->validate($gameId, $payload);
        $fingerprint = hash('sha256', json_encode($payload['plays'], JSON_THROW_ON_ERROR));
        DB::transaction(function () use ($gameId, $payload, $version, $fingerprint, $force): void {
            DB::table('nhl_pbp_processing_states')->insertOrIgnore([
                'nhl_game_id' => $gameId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $state = DB::table('nhl_pbp_processing_states')->where('nhl_game_id', $gameId)->lockForUpdate()->first();
            if ($version <= $state->pending_version) {
                return;
            }
            if (! $force && $fingerprint === $state->fingerprint) {
                DB::table('nhl_pbp_processing_states')->where('nhl_game_id', $gameId)->update(['removal_candidate' => null]);
                return;
            }
            $missing = PlayByPlay::query()->where('nhl_game_id', $gameId)
                ->whereNotIn('nhl_event_id', array_column($payload['plays'], 'eventId'))->orderBy('nhl_event_id')->pluck('nhl_event_id')->all();
            $candidate = $missing === [] ? null : hash('sha256', json_encode($missing, JSON_THROW_ON_ERROR));
            if ($candidate !== null && $state->removal_candidate !== $candidate) {
                DB::table('nhl_pbp_processing_states')->where('nhl_game_id', $gameId)->update(['removal_candidate' => $candidate]);
                return;
            }
            DB::table('nhl_pbp_processing_states')->where('nhl_game_id', $gameId)->update([
                'removal_candidate' => null,
                'pending_version' => $version, 'fingerprint' => $fingerprint,
                'pending_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'observed_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    /** Apply one snapshot atomically; callers must hold the shared game import lock. */
    public function reconcile(int $gameId, array $payload, int $version): int
    {
        $this->validate($gameId, $payload);
        return DB::transaction(function () use ($gameId, $payload, $version): int {
            $stored = PlayByPlay::withoutGlobalScope('active')->where('nhl_game_id', $gameId)->get()->keyBy('nhl_event_id');
            $incoming = collect($payload['plays'])->keyBy('eventId');
            foreach ($incoming as $id => $event) {
                $old = $stored->get($id);
                $previous = $old?->metadata['event'] ?? null;
                if ($previous != $event || $old?->withdrawn_at !== null) {
                    $this->revision($gameId, (string) $id, $version, $old === null ? 'added' : 'changed', $previous, $event);
                }
            }
            foreach ($stored as $id => $old) {
                if (! $incoming->has($id) && $old->withdrawn_at === null) {
                    $this->revision($gameId, (string) $id, $version, 'withdrawn', $old->metadata['event'] ?? null, null);
                    $old->update(['withdrawn_at' => now()]);
                }
            }
            return app(ImportNHLPlayByPlay::class)->import($gameId, $payload);
        });
    }

    /** Preserve both sides of a correction independently of active derived rows. */
    private function revision(int $gameId, string $eventId, int $version, string $action, ?array $before, ?array $after): void
    {
        DB::table('nhl_pbp_event_revisions')->insertOrIgnore([
            'nhl_game_id' => $gameId, 'nhl_event_id' => $eventId, 'version' => $version, 'action' => $action,
            'previous_event' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'current_event' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
