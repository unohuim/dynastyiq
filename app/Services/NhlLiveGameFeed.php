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

    /** Admit only imminent or unfinished games, including games spanning midnight. */
    public function dispatchDue(): void
    {
        $games = NhlGame::query()->whereBetween('game_date', [now('UTC')->subDay()->toDateString(), now('UTC')->toDateString()])
            ->where(fn ($query) => $query->whereNotIn('game_state', ['OFF', 'FINAL', 'PPD', 'CNCL'])->orWhereNull('game_state'))
            ->where('start_time_utc', '<=', now('UTC')->addMinutes((int) config('nhl_live.start_before_minutes', 30)))
            ->pluck('nhl_game_id');
        foreach ($games as $id) {
            PollNhlLiveGameJob::dispatch((int) $id, now()->timestamp);
        }
    }

    /** Fetch both endpoint snapshots once, in parallel, behind a cross-server game lock. */
    public function poll(int $gameId): void
    {
        $lock = Cache::lock('nhl:live:poll:'.$gameId, 15);
        if (! $lock->get()) {
            return;
        }
        try {
            $game = NhlGame::query()->find($gameId);
            if ($game === null || in_array($game->game_state, ['OFF', 'FINAL', 'PPD', 'CNCL'], true)) {
                return;
            }
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
        return Cache::get($this->key($gameId));
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
