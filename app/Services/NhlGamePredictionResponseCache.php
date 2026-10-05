<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\RefreshNhlGamePredictionJob;
use App\Models\NhlModelRun;
use App\Models\NhlSatEngineStack;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Serves bounded fresh or queued-refresh prediction responses without caching engine evaluations. */
final class NhlGamePredictionResponseCache
{
    private const FRESH_SECONDS = 60;
    private const STALE_SECONDS = 300;

    /** @param array<string,mixed> $input @param callable():array<string,mixed> $build @return array<string,mixed> */
    public function respond(int $gameId, array $input, callable $build): array
    {
        $fingerprint = $this->fingerprint($gameId, $input);
        $key = $this->key($gameId, $input);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            $age = now()->diffInSeconds($cached['built_at'] ?? now()->subSeconds(self::STALE_SECONDS + 1));
            if (($cached['fingerprint'] ?? null) === $fingerprint && $age <= self::FRESH_SECONDS) {
                return $this->annotate($cached['payload'], 'fresh');
            }
            if ($age <= self::STALE_SECONDS && $this->queue($gameId, $input, $fingerprint)) {
                return $this->annotate($cached['payload'], 'stale');
            }
        }

        $payload = $build();
        $this->store($key, $fingerprint, $payload);

        return $this->annotate($payload, 'fresh');
    }

    /** @param array<string,mixed> $input */
    public function rebuild(int $gameId, array $input, string $fingerprint, NhlGamePredictionPayload $payload): void
    {
        try {
            if ($fingerprint !== $this->fingerprint($gameId, $input)) {
                return;
            }
            $this->store($this->key($gameId, $input), $fingerprint, $payload->build($gameId, $input));
        } finally {
            Cache::forget($this->queuedKey($gameId, $input, $fingerprint));
        }
    }

    /** @param array<string,mixed> $input */
    private function queue(int $gameId, array $input, string $fingerprint): bool
    {
        $queued = $this->queuedKey($gameId, $input, $fingerprint);
        if (! Cache::add($queued, true, self::STALE_SECONDS)) {
            return true;
        }
        try {
            RefreshNhlGamePredictionJob::dispatch($gameId, $input, $fingerprint)->onQueue('lineups');

            return true;
        } catch (Throwable) {
            Cache::forget($queued);

            return false;
        }
    }

    /** @param array<string,mixed> $input */
    private function fingerprint(int $gameId, array $input): string
    {
        $game = DB::table('nhl_games')->where('nhl_game_id', $gameId)->first(['updated_at', 'away_starter_lock', 'home_starter_lock']);
        $stack = NhlSatEngineStack::query()->where('is_default', true)->with('members.engine')->first();
        $modelIds = $stack?->production_model_run_id !== null
            ? [$stack->production_model_run_id]
            : ($stack?->members->pluck('engine.model_run_id')->filter()->unique()->values()->all() ?? []);
        $models = NhlModelRun::query()->whereIn('id', $modelIds)->get(['id', 'updated_at']);
        $lineups = DB::table('nhl_current_lineups')->where('nhl_game_id', $gameId)
            ->orderBy('team_id')->get(['team_id', 'nhl_lineup_observation_id', 'structure_hash', 'last_observed_at', 'updated_at']);
        $goalies = DB::table('nhl_starting_goalie_observations')->where('nhl_game_id', $gameId)
            ->orderBy('id')->get(['id', 'team_abbrev', 'nhl_player_id', 'updated_at']);

        return hash('sha256', json_encode([$input, $game, $stack, $models, $lineups, $goalies], JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $input */
    private function key(int $gameId, array $input): string
    {
        ksort($input);

        return 'nhl:game-prediction:' . $gameId . ':' . hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $input */
    private function queuedKey(int $gameId, array $input, string $fingerprint): string
    {
        return $this->key($gameId, $input) . ':queued:' . $fingerprint;
    }

    /** @param array<string,mixed> $payload */
    private function store(string $key, string $fingerprint, array $payload): void
    {
        Cache::put($key, ['fingerprint' => $fingerprint, 'built_at' => now(), 'payload' => $payload], self::STALE_SECONDS);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function annotate(array $payload, string $state): array
    {
        $payload['meta']['cache'] = $state;

        return $payload;
    }
}
