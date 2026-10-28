<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\NhlLiveGameUpdated;
use App\Jobs\PollNhlLiveGameJob;
use App\Models\NhlGame;
use App\Traits\HasAPITrait;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Shared provider ingestion and public snapshot reads; browsers never initiate NHL calls. */
final class NhlLiveGameFeed
{
    use HasAPITrait;

    /** Admit imminent games and repair recent finals whose terminal snapshot was missed. */
    public function dispatchDue(): void
    {
        \Illuminate\Support\Facades\DB::table('nhl_pbp_processing_states')->whereColumn('pending_version', '>', 'processed_version')
            ->orderBy('nhl_game_id')->chunk(100, function ($states): void {
                foreach ($states as $state) {
                    \App\Jobs\ProcessNhlLivePbpJob::dispatch((int) $state->nhl_game_id);
                }
            });
        $games = NhlGame::query()->whereBetween('game_date', [now('UTC')->subDay()->toDateString(), now('UTC')->toDateString()])
            ->where(fn ($query) => $query->whereNotIn('game_state', ['PPD', 'CNCL'])->orWhereNull('game_state'))
            ->where('start_time_utc', '<=', now('UTC')->addMinutes((int) config('nhl_live.start_before_minutes', 30)))
            ->get(['nhl_game_id', 'game_state']);
        foreach ($games as $game) {
            if (in_array($game->game_state, ['OFF', 'FINAL'], true) && $this->hasFinalSnapshot((int) $game->nhl_game_id)) {
                continue;
            }
            PollNhlLiveGameJob::dispatch((int) $game->nhl_game_id, now()->timestamp);
        }
    }

    /** Fetch both endpoint snapshots once, in parallel, behind a cross-server game lock. */
    public function poll(int $gameId, bool $reconcileFinal = false): void
    {
        $lock = Cache::lock('nhl:live:poll:'.$gameId, 15);
        if (! $lock->get()) {
            return;
        }
        try {
            $game = NhlGame::query()->find($gameId);
            if ($game === null || in_array($game->game_state, ['PPD', 'CNCL'], true)
                || (! $reconcileFinal && in_array($game->game_state, ['OFF', 'FINAL'], true) && $this->hasFinalSnapshot($gameId))) {
                return;
            }
            $sourceVersion = (int) floor(microtime(true) * 1000000);
            $timeout = (int) config('nhl_live.request_timeout_seconds', 4);
            $responses = Http::pool(fn (Pool $pool): array => [
                $pool->as('box')->acceptJson()->connectTimeout(2)->timeout($timeout)->get($this->getApiUrl('nhl', 'boxscore', ['gameId' => $gameId])),
                $pool->as('pbp')->acceptJson()->connectTimeout(2)->timeout($timeout)->get($this->getApiUrl('nhl', 'pbp', ['gameId' => $gameId])),
            ]);
            foreach (['box', 'pbp'] as $key) {
                if (! ($responses[$key] ?? null) instanceof \Illuminate\Http\Client\Response || ! $responses[$key]->successful()) {
                    throw new \RuntimeException('Live NHL '.$key.' unavailable for game '.$gameId);
                }
            }
            $box = $responses['box']->json();
            $pbp = $responses['pbp']->json();
            if (! is_array($box) || ! is_array($pbp) || (int) ($box['id'] ?? 0) !== $gameId || (int) ($pbp['id'] ?? 0) !== $gameId) {
                throw new \RuntimeException('Mismatched live NHL provider game identity.');
            }
            // Clock and score come from one PBP response, never a mixture of polling instants.
            foreach (['gameState', 'clock', 'periodDescriptor'] as $field) {
                if (array_key_exists($field, $pbp)) {
                    $box[$field] = $pbp[$field];
                }
            }
            foreach (['awayTeam', 'homeTeam'] as $side) {
                foreach (['score', 'sog'] as $field) {
                    if (isset($pbp[$side][$field])) {
                        $box[$side][$field] = $pbp[$side][$field];
                    }
                }
            }
            // Imports can mark a game final while this provider request is in flight.
            // Wait for terminal provider evidence instead of reverting the stored final.
            $game->refresh();
            if (in_array($game->game_state, ['OFF', 'FINAL'], true)
                && ! in_array($box['gameState'] ?? '', ['OFF', 'FINAL'], true)) {
                throw new \RuntimeException('Awaiting final NHL provider snapshot for game '.$gameId);
            }
            $context = app(NhlGameLiveContext::class);
            $context->persistShared($game, $box);
            $provider = $context->detailFromProvider($box, $pbp);
            $detail = app(NhlPublicGamePage::class)->livePayload($gameId, $provider)['game'];
            $previous = $this->snapshot($gameId);
            $fingerprint = hash('sha256', json_encode([$box, $pbp], JSON_THROW_ON_ERROR));
            $sequence = max((int) ($previous['sequence'] ?? 0) + 1, now()->timestamp * 1000);
            $snapshot = ['sequence' => $sequence, 'observed_at' => now()->toIso8601String(),
                'fingerprint' => $fingerprint, 'game' => $detail, 'boxscore' => $box, 'pbp' => $pbp];
            Cache::put($this->key($gameId), $snapshot, 172800);
            if (in_array($box['gameState'] ?? '', ['LIVE', 'CRIT', 'OFF', 'FINAL'], true)) {
                try {
                    app(NhlPbpReconciliation::class)->accept($gameId, $pbp, $sourceVersion);
                    \App\Jobs\ProcessNhlLivePbpJob::dispatch($gameId);
                } catch (\InvalidArgumentException $exception) {
                    report($exception);
                }
            }
            // Refresh shared legacy source evidence too; no extra requests are needed by scheduled consumers.
            Cache::put('nhl:gamecenter:boxscore:'.$gameId, $box, 15);
            NhlLiveGameUpdated::dispatch([...$this->compact($snapshot), 'changed' => ($previous['fingerprint'] ?? null) !== $fingerprint]);
            if (in_array($box['gameState'] ?? '', ['OFF', 'FINAL'], true)) {
                app(NhlPublicGamePage::class)->refreshAfterCommit($gameId);
            }
        } finally {
            $lock->release();
        }
    }

    /** Return the latest received source evidence, without making a provider request.
     * @return array<string,mixed>|null
     */
    public function snapshot(int $gameId): ?array
    {
        $snapshot = Cache::get($this->key($gameId));
        if ($snapshot === null) {
            return null;
        }
        $state = \Illuminate\Support\Facades\DB::table('nhl_pbp_processing_states')->where('nhl_game_id', $gameId)->first();
        if ($state?->metrics !== null) {
            $metrics = json_decode($state->metrics, true, 512, JSON_THROW_ON_ERROR);
            $snapshot['game']['advanced_stats'] = [...$metrics, 'version' => $state->processed_version,
                'pending' => $state->pending_version > $state->processed_version, 'processed_at' => $state->processed_at];
            foreach (['away', 'home'] as $side) {
                foreach ($snapshot['game'][$side]['boxscore'] ?? [] as $index => $player) {
                    if (isset($metrics['goalies'][$player['id'] ?? 0])) {
                        $snapshot['game'][$side]['boxscore'][$index] = [...$player, ...$metrics['goalies'][$player['id']]];
                    }
                }
            }
            foreach (['xgf' => 'xGF', 'xga' => 'xGA'] as $key => $label) {
                $snapshot['game']['comparison'][] = ['label' => $label,
                    'away' => $metrics['teams'][$snapshot['game']['away']['team_id']][$key] ?? null,
                    'home' => $metrics['teams'][$snapshot['game']['home']['team_id']][$key] ?? null];
            }
        }
        return $snapshot;
    }

    /** A stored final schedule state alone does not prove the public recap was captured. */
    private function hasFinalSnapshot(int $gameId): bool
    {
        $snapshot = $this->snapshot($gameId);

        $candidate = \Illuminate\Support\Facades\DB::table('nhl_pbp_processing_states')->where('nhl_game_id', $gameId)->value('removal_candidate');
        return $candidate === null && (int) ($snapshot['game']['nhl_game_id'] ?? 0) === $gameId
            && ($snapshot['game']['mode'] ?? null) === 'recap'
            && in_array($snapshot['game']['game_state'] ?? '', ['OFF', 'FINAL'], true);
    }

    /** Freshness is explicit; an old snapshot is never advertised as current live data. */
    public function fresh(?array $snapshot): bool
    {
        return $snapshot !== null && now()->diffInSeconds(\Illuminate\Support\Carbon::parse($snapshot['observed_at']), true)
            <= (int) config('nhl_live.stale_seconds', 20);
    }

    /** Compact public envelope stays below Reverb message limits.
     * @param array<string,mixed> $snapshot @return array<string,mixed>
     */
    public function compact(array $snapshot): array
    {
        $game = Arr::only($snapshot['game'], ['nhl_game_id', 'game_date', 'start_time_utc', 'game_state', 'mode', 'status',
            'time_remaining', 'in_intermission', 'period_number', 'period_type', 'live_data_unavailable']);
        foreach (['away', 'home'] as $side) {
            $game[$side] = Arr::only($snapshot['game'][$side], ['team_id', 'team_abbrev', 'team_name', 'team_logo', 'score', 'sog']);
        }

        return ['sequence' => $snapshot['sequence'], 'observed_at' => $snapshot['observed_at'], 'game' => $game];
    }

    /** Keep the public feed namespace independent of preview and prediction caches. */
    private function key(int $gameId): string
    {
        return 'nhl:live:snapshot:v1:'.$gameId;
    }
}
