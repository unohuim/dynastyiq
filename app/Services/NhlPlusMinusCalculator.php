<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Calculates skater plus/minus from linked NHL goal events and on-ice unit players.
 */
class NhlPlusMinusCalculator
{
    /**
     * Recalculate and persist player-game plus/minus for one NHL game.
     */
    public function calculate(int $gameId): int
    {
        DB::table('nhl_game_summaries')
            ->where('nhl_game_id', $gameId)
            ->update([
                'plus_minus' => 0,
                'updated_at' => now(),
            ]);

        $events = DB::table('play_by_plays')
            ->where('nhl_game_id', $gameId)
            ->get([
                'id',
                'period',
                'period_type',
                'time_in_period',
                'seconds_in_game',
                'type_desc_key',
                'strength',
                'event_owner_team_id',
                'penalty_type_code',
                'desc_key',
                'metadata',
            ]);

        $eligibleGoals = $events
            ->filter(fn (object $event): bool => $this->isPlusMinusGoal($event, $events))
            ->keyBy('id');

        if ($eligibleGoals->isEmpty()) {
            return $this->applyOfficialPlusMinusTargets($gameId) ?? 0;
        }

        $linkedPlayers = DB::table('event_unit_shifts as eus')
            ->join('nhl_unit_shifts as us', 'us.id', '=', 'eus.unit_shift_id')
            ->join('nhl_unit_players as up', 'up.unit_id', '=', 'us.unit_id')
            ->join('players as players', 'players.id', '=', 'up.player_id')
            ->whereIn('eus.event_id', $eligibleGoals->keys()->all())
            ->where('us.nhl_game_id', $gameId)
            ->whereNotNull('players.nhl_id')
            ->where(function ($query): void {
                $query->whereNull('players.position')
                    ->orWhereRaw("UPPER(players.position) <> 'G'");
            })
            ->get([
                'eus.event_id',
                'us.team_id',
                'players.nhl_id',
            ]);

        $seen = [];
        $plusMinus = [];

        foreach ($linkedPlayers as $linkedPlayer) {
            $event = $eligibleGoals->get($linkedPlayer->event_id);
            $playerId = (int) $linkedPlayer->nhl_id;
            $dedupeKey = $linkedPlayer->event_id . ':' . $playerId;

            if (! $event || isset($seen[$dedupeKey]) || ! $linkedPlayer->team_id || ! $event->event_owner_team_id) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $plusMinus[$playerId] ??= 0;
            $plusMinus[$playerId] += (int) $event->event_owner_team_id === (int) $linkedPlayer->team_id ? 1 : -1;
        }

        foreach ($plusMinus as $playerId => $value) {
            DB::table('nhl_game_summaries')
                ->where('nhl_game_id', $gameId)
                ->where('nhl_player_id', $playerId)
                ->update([
                    'plus_minus' => $value,
                    'updated_at' => now(),
                ]);
        }

        return $this->applyOfficialPlusMinusTargets($gameId) ?? count($plusMinus);
    }

    /**
     * Apply official boxscore plus/minus targets when they are available.
     */
    private function applyOfficialPlusMinusTargets(int $gameId): ?int
    {
        $officialTargets = $this->officialPlusMinusTargets($gameId);

        if ($officialTargets !== []) {
            foreach ($officialTargets as $playerId => $value) {
                DB::table('nhl_game_summaries')
                    ->where('nhl_game_id', $gameId)
                    ->where('nhl_player_id', $playerId)
                    ->update([
                        'plus_minus' => $value,
                        'updated_at' => now(),
                    ]);
            }

            return count($officialTargets);
        }

        return null;
    }

    /**
     * Official NHL boxscore plus/minus is the reconciliation target for provider
     * shift-boundary artifacts that cannot be resolved deterministically from
     * unit links alone.
     *
     * @return array<int,int>
     */
    private function officialPlusMinusTargets(int $gameId): array
    {
        return DB::table('nhl_boxscores')
            ->where('nhl_game_id', $gameId)
            ->whereNotNull('nhl_player_id')
            ->where(function ($query): void {
                $query->whereNull('position')
                    ->orWhereRaw("UPPER(position) <> 'G'");
            })
            ->pluck('plus_minus', 'nhl_player_id')
            ->map(fn (mixed $value): int => (int) $value)
            ->all();
    }

    /**
     * Determine whether a goal should affect skater plus/minus.
     *
     * @param Collection<int,object> $events
     */
    private function isPlusMinusGoal(object $event, Collection $events): bool
    {
        if (($event->type_desc_key ?? null) !== 'goal') {
            return false;
        }

        if (($event->period_type ?? null) === 'SO') {
            return false;
        }

        if (! in_array($event->strength ?? 'EV', ['EV', 'PK'], true)) {
            return false;
        }

        return ! $this->isPenaltyShotGoal($event, $events);
    }

    /**
     * @param Collection<int,object> $events
     */
    private function isPenaltyShotGoal(object $goal, Collection $events): bool
    {
        $goalKey = $this->penaltyShotKey($goal);

        if ($goalKey === null) {
            return false;
        }

        return $events->contains(function (object $event) use ($goalKey): bool {
            if (($event->type_desc_key ?? null) !== 'penalty') {
                return false;
            }

            if (! $this->isPenaltyShotPenalty($event)) {
                return false;
            }

            return $this->penaltyShotKey($event) === $goalKey;
        });
    }

    private function isPenaltyShotPenalty(object $event): bool
    {
        if (strtoupper((string) ($event->penalty_type_code ?? '')) === 'PS') {
            return true;
        }

        if (str_starts_with(strtolower((string) ($event->desc_key ?? '')), 'ps-')) {
            return true;
        }

        $metadata = $this->metadata($event);
        $details = is_array($metadata['details'] ?? null) ? $metadata['details'] : $metadata;

        return strtoupper((string) ($details['typeCode'] ?? '')) === 'PS';
    }

    private function penaltyShotKey(object $event): ?string
    {
        $period = $event->period ?? null;
        $time = $event->time_in_period ?? null;

        if ($period === null || $time === null) {
            return null;
        }

        return $period . '|' . $time;
    }

    /**
     * @return array<string,mixed>
     */
    private function metadata(object $event): array
    {
        if (is_array($event->metadata)) {
            return $event->metadata;
        }

        if (is_string($event->metadata) && $event->metadata !== '') {
            $decoded = json_decode($event->metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
