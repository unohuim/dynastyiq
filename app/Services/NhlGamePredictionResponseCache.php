<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NhlGamePredictionPreparing;
use App\Jobs\RefreshNhlGamePredictionJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

/** Serves bounded fresh or queued-refresh prediction responses without caching engine evaluations. */
final class NhlGamePredictionResponseCache
{
    private const FRESH_SECONDS = 60;
    private const STALE_SECONDS = 10800;
    private const BUILD_LOCK_SECONDS = 600;

    /** @param array<string,mixed> $input @param callable():array<string,mixed> $build @return array<string,mixed> */
    public function respond(int $gameId, array $input, callable $build, bool $forceRefresh = false, ?int $runId = null): array
    {
        $owner = 'request:'.\Illuminate\Support\Str::uuid();
        \App\Models\NhlGame::predictionActivity($gameId, $owner, true, self::BUILD_LOCK_SECONDS);
        try {
            return $this->respondActive($gameId, $input, $build, $forceRefresh, $runId);
        } finally {
            \App\Models\NhlGame::predictionActivity($gameId, $owner, false);
        }
    }

    /** Calculate/reuse a response while its game-specific input admission is held. */
    private function respondActive(int $gameId, array $input, callable $build, bool $forceRefresh, ?int $runId): array
    {
        $fingerprint = $this->fingerprint($gameId, $input);
        $key = $this->key($gameId, $input);
        $cached = Cache::get($key);
        if (! $forceRefresh && $this->matches($cached, $fingerprint, self::STALE_SECONDS)) {
            if ($this->matches($cached, $fingerprint, self::FRESH_SECONDS)) {
                return $this->annotate($cached['payload'], 'fresh');
            }
            if ($this->queue($gameId, $input, $fingerprint)) {
                return $this->annotate($cached['payload'], 'stale');
            }
        }

        $lock = Cache::lock($this->buildLockKey($gameId), self::BUILD_LOCK_SECONDS);
        if (! $lock->get()) {
            if (! $this->queue($gameId, $input, $fingerprint)) {
                throw new ServiceUnavailableHttpException(15, 'Unable to queue the pending prediction.');
            }
            throw new NhlGamePredictionPreparing($gameId);
        }
        try {
            if ($runId !== null) {
                $saved = DB::table('nhl_game_prediction_revisions')->where('run_id', $runId)->where('nhl_game_id', $gameId)->first();
                if ($saved !== null) {
                    return $this->annotate(app(NhlGamePredictionHistory::class)->payload($saved), 'fresh');
                }
            }
            $evidence = $this->evidence($gameId, $input);
            $fingerprint = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR));
            $current = Cache::get($key);
            if ($runId === null && $this->matches($current, $fingerprint, self::FRESH_SECONDS)
                && (! $forceRefresh || ($current['build_id'] ?? null) !== ($cached['build_id'] ?? null))) {
                return $this->annotate($current['payload'], 'fresh');
            }
            try {
                $payload = $build();
            } catch (NhlGamePredictionPreparing $pending) {
                if (! $this->queue($gameId, $input, $fingerprint)) {
                    throw new ServiceUnavailableHttpException(15, 'Unable to queue the pending prediction.');
                }
                throw $pending;
            }
            if ($fingerprint !== $this->fingerprint($gameId, $input)) {
                if (! $this->queue($gameId, $input, $fingerprint)) {
                    throw new ServiceUnavailableHttpException(15, 'Unable to queue the changed-input prediction.');
                }
                throw new NhlGamePredictionPreparing($gameId);
            }
            $payload = $this->store($gameId, $input, $key, $fingerprint, $evidence, $payload, $runId);

            return $this->annotate($payload, 'fresh');
        } finally {
            $lock->release();
        }
    }

    /** @param array<string,mixed> $input */
    public function rebuild(int $gameId, array $input, string $fingerprint, NhlGamePredictionPayload $payload): void
    {
        \App\Models\NhlGame::predictionActivity($gameId, $this->refreshOwner($input), true);
        $pending = false;
        try {
            // A waiting prediction follows current source evidence instead of silently abandoning it.
            $fingerprint = $this->fingerprint($gameId, $input);
            $lock = Cache::lock($this->buildLockKey($gameId), self::BUILD_LOCK_SECONDS);
            if (! $lock->get()) {
                $pending = true;
                throw new NhlGamePredictionPreparing($gameId);
            }
            try {
                if ($this->matches(Cache::get($this->key($gameId, $input)), $fingerprint, self::FRESH_SECONDS)) {
                    return;
                }
                $evidence = $this->evidence($gameId, $input);
                $built = $payload->build($gameId, $input);
                if ($fingerprint !== $this->fingerprint($gameId, $input)) {
                    throw new NhlGamePredictionPreparing($gameId);
                }
                $this->store($gameId, $input, $this->key($gameId, $input), $fingerprint, $evidence, $built);
            } finally {
                $lock->release();
            }
        } catch (NhlGamePredictionPreparing $waiting) {
            $pending = true;
            Cache::put($this->queuedKey($gameId, $input, $fingerprint), true, 21600);
            throw $waiting;
        } catch (Throwable $error) {
            // A retryable worker exception is still active work; failed() owns terminal cleanup.
            $pending = true;
            throw $error;
        } finally {
            if (! $pending) {
                Cache::forget($this->queuedKey($gameId, $input, $fingerprint));
                \App\Models\NhlGame::predictionActivity($gameId, $this->refreshOwner($input), false);
            }
        }
    }

    /** Release admission after a terminal worker failure so a later request can retry.
     * @param array<string,mixed> $input
     */
    public function refreshFailed(int $gameId, array $input): void
    {
        Cache::forget($this->key($gameId, $input).':queued');
        \App\Models\NhlGame::predictionActivity($gameId, $this->refreshOwner($input), false);
    }

    /** @param array<string,mixed> $input */
    private function queue(int $gameId, array $input, string $fingerprint): bool
    {
        \App\Models\NhlGame::predictionActivity($gameId, $this->refreshOwner($input), true);
        $queued = $this->queuedKey($gameId, $input, $fingerprint);
        if (! Cache::add($queued, true, 21600)) {
            return true;
        }
        try {
            RefreshNhlGamePredictionJob::dispatch($gameId, $input, $fingerprint)->onQueue('lineups');

            return true;
        } catch (Throwable) {
            Cache::forget($queued);
            \App\Models\NhlGame::predictionActivity($gameId, $this->refreshOwner($input), false);

            return false;
        }
    }

    /** Keep distinct request variants protected until their own refresh finishes. */
    private function refreshOwner(array $input): string
    {
        ksort($input);

        return 'refresh:'.hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $input */
    private function fingerprint(int $gameId, array $input): string
    {
        return hash('sha256', json_encode($this->evidence($gameId, $input), JSON_THROW_ON_ERROR));
    }

    /** Freeze the configuration and lineup/starter identities used by the same fingerprint boundary.
     * @param array<string,mixed> $input @return array<string,mixed>
     */
    private function evidence(int $gameId, array $input): array
    {
        ksort($input);
        $game = DB::table('nhl_games')->where('nhl_game_id', $gameId)->first([
            'nhl_game_id', 'season_id', 'game_date', 'start_time_utc', 'home_team_id', 'away_team_id',
            'game_state', 'away_starter_lock', 'home_starter_lock',
        ]);
        abort_if($game === null, 404);
        $forecast = app(NhlCustomGameForecast::class);
        $model = $forecast->model(isset($input['sat_model_run_id']) ? (int) $input['sat_model_run_id'] : null, $game->season_id);
        $models = collect([['id' => $model->id, 'updated_at' => $model->updated_at, 'train_season_ids' => $model->train_season_ids]]);
        $lineups = DB::table('nhl_current_lineups')->where('nhl_game_id', $gameId)
            ->orderBy('team_id')->get(['team_id', 'structure_hash']);
        $goalies = DB::table('nhl_starting_goalie_observations')->where('nhl_game_id', $gameId)
            ->orderByRaw("CASE status WHEN 'confirmed' THEN 0 WHEN 'expected' THEN 1 ELSE 2 END")
            ->orderByDesc('fetched_at')->orderByDesc('id')
            ->get(['team_abbrev', 'nhl_player_id', 'provider', 'status'])
            ->unique(fn (object $row): string => implode('|', [$row->team_abbrev, (string) $row->nhl_player_id, $row->provider, $row->status]))->values();

        $source = $forecast->resultEvidence($model, $game);

        return ['request' => $input, 'game' => $game, 'stack' => null, 'models' => $models,
            'algorithm' => config('nhl-custom-prediction'), 'source' => $source,
            'lineups' => $lineups, 'goalies' => $goalies];
    }

    /** @param array<string,mixed> $input */
    private function key(int $gameId, array $input): string
    {
        ksort($input);

        return 'nhl:game-prediction:pregame-dependencies-v13:' . $gameId . ':' . hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $input */
    private function queuedKey(int $gameId, array $input, string $fingerprint): string
    {
        return $this->key($gameId, $input) . ':queued';
    }

    /** Publish only after the durable revision is saved. Cache hits never append history.
     * @param array<string,mixed> $input
     * @param array<string,mixed> $evidence
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function store(int $gameId, array $input, string $key, string $fingerprint, array $evidence, array $payload, ?int $runId = null): array
    {
        $payload = app(NhlGamePredictionHistory::class)->record($gameId, $input, $fingerprint, $evidence, $payload, $runId);
        Cache::put($key, ['fingerprint' => $fingerprint, 'built_at' => now(),
            'build_id' => (string) \Illuminate\Support\Str::uuid(), 'payload' => $payload], self::STALE_SECONDS);

        return $payload;
    }

    /** Accept only matching, bounded snapshots; future or malformed timestamps are unusable. */
    private function matches(mixed $cached, string $fingerprint, int $maxAge): bool
    {
        if (! is_array($cached) || ($cached['fingerprint'] ?? null) !== $fingerprint
            || ! is_array($cached['payload'] ?? null) || ! isset($cached['built_at'])) {
            return false;
        }
        try {
            $age = \Illuminate\Support\Carbon::parse($cached['built_at'])->diffInSeconds(now(), false);
        } catch (Throwable) {
            return false;
        }

        return $age >= 0 && $age <= $maxAge;
    }

    /** One lock covers all input variants and refresh paths for a game. */
    private function buildLockKey(int $gameId): string
    {
        return 'nhl:game-prediction:' . $gameId . ':build';
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function annotate(array $payload, string $state): array
    {
        $payload['meta']['cache'] = $state;

        return $payload;
    }
}
