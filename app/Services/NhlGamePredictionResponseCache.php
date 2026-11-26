<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NhlGamePredictionPreparing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

/** Serves bounded fresh or queued-refresh prediction responses without caching engine evaluations. */
final class NhlGamePredictionResponseCache
{
    private const FRESH_SECONDS = 60;
    private const STALE_SECONDS = 10800;
    private const BUILD_LOCK_SECONDS = 180;

    /** @param array<string,mixed> $input @param callable():array<string,mixed> $build @return array<string,mixed> */
    public function respond(
        int $gameId,
        array $input,
        callable $build,
        bool $forceRefresh = false,
        ?int $runId = null,
        ?int $itemId = null,
        ?int $generation = null,
        ?string $claimToken = null,
    ): array {
        if ($runId === null && ($forceRefresh || ! Cache::has($this->key($gameId, $input)))
            && DB::table('nhl_game_prediction_run_games')->where('nhl_game_id', $gameId)
                ->whereIn('status', ['queued', 'running'])->exists()) {
            if (! $this->queue($gameId, $input, '')) {
                throw new ServiceUnavailableHttpException(15, 'Unable to queue prediction refresh.');
            }
            throw new NhlGamePredictionPreparing($gameId);
        }
        // Cache-only reads are not calculation attempts. Keep their existing immediate/stale contract.
        if ($runId === null && ! $forceRefresh && Cache::has($this->key($gameId, $input))) {
            try {
                $fingerprint = $this->fingerprint($gameId, $input);
            } catch (NhlGamePredictionPreparing $pending) {
                if (! $this->queue($gameId, $input, '')) {
                    throw new ServiceUnavailableHttpException(15, 'Unable to queue prediction preparation.');
                }
                throw $pending;
            }
            $cached = Cache::get($this->key($gameId, $input));
            if ($this->matches($cached, $fingerprint, self::STALE_SECONDS)) {
                $fresh = $this->matches($cached, $fingerprint, self::FRESH_SECONDS);
                if (! $fresh) {
                    if (! $this->queue($gameId, $input, $fingerprint)) {
                        throw new ServiceUnavailableHttpException(15, 'Unable to queue prediction refresh.');
                    }
                }
                return $this->annotate($cached['payload'], $fresh ? 'fresh' : 'stale');
            }
        }
        $history = app(NhlGamePredictionHistory::class);
        $synchronous = $runId === null;
        if ($synchronous) {
            $itemId = $history->queueRefresh($gameId, $input, dispatch: false);
            if (DB::table('nhl_game_prediction_run_games')->where('id', $itemId)->whereNotNull('dispatched_at')->exists()) {
                throw new NhlGamePredictionPreparing($gameId);
            }
            $claimToken = (string) \Illuminate\Support\Str::uuid();
            $generation = (int) DB::table('nhl_game_prediction_run_games')->where('id', $itemId)->value('generation');
            $item = $history->claim($itemId, $generation, $claimToken);
            if ($item === null) {
                throw new NhlGamePredictionPreparing($gameId);
            }
            $runId = (int) $item->run_id;
        }
        // Durable items already protect queued/running inputs, including after a killed worker.
        $owner = $itemId === null ? 'request:'.\Illuminate\Support\Str::uuid() : null;
        if ($owner !== null) {
            \App\Models\NhlGame::predictionActivity($gameId, $owner, true, self::BUILD_LOCK_SECONDS);
        }
        try {
            $result = $this->respondActive($gameId, $input, $build, $runId, $itemId, $generation, $claimToken);
            if ($synchronous) {
                $history->finish($itemId, ($result['prediction_available'] ?? false) ? 'completed' : 'unavailable',
                    (int) $result['meta']['prediction_revision_id'], generation: $generation, claimToken: $claimToken);
            }
            return $result;
        } catch (NhlGamePredictionPreparing $pending) {
            if ($synchronous) {
                $history->progress($itemId, $claimToken, 'preparing', release: true);
                try {
                    $admitted = DB::table('nhl_game_prediction_run_games')->where('id', $itemId)->whereNull('dispatched_at')
                        ->where('generation', $generation)->update(['dispatched_at' => now()]);
                    if ($admitted) {
                        \App\Jobs\RefreshNhlGamePredictionJob::dispatch($gameId, $input, '', $itemId, $generation);
                    }
                } catch (Throwable $error) {
                    $history->finish($itemId, 'failed', error: $error->getMessage(), generation: $generation);
                    throw $error;
                }
            }
            throw $pending;
        } catch (Throwable $error) {
            if ($synchronous) {
                $history->finish($itemId, 'failed', error: mb_substr($error->getMessage(), 0, 2000), generation: $generation, claimToken: $claimToken);
            }
            throw $error;
        } finally {
            if ($owner !== null) {
                \App\Models\NhlGame::predictionActivity($gameId, $owner, false);
            }
        }
    }

    /** Calculate/reuse a response while its game-specific input admission is held. */
    private function respondActive(int $gameId, array $input, callable $build, ?int $runId, ?int $itemId, ?int $generation, ?string $claimToken): array
    {
        $key = $this->key($gameId, $input);
        $lock = Cache::lock($this->buildLockKey($gameId), self::BUILD_LOCK_SECONDS);
        if (! $lock->get()) {
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
            $payload = $build();
            if ($fingerprint !== $this->fingerprint($gameId, $input)) {
                throw new NhlGamePredictionPreparing($gameId);
            }
            $payload = $this->store($gameId, $input, $key, $fingerprint, $evidence, $payload, $runId, $itemId, $generation, $claimToken);

            return $this->annotate($payload, 'fresh');
        } finally {
            $lock->release();
        }
    }

    /** @param array<string,mixed> $input */
    public function rebuild(int $gameId, array $input, string $fingerprint, NhlGamePredictionPayload $payload): void
    {
        $history = app(NhlGamePredictionHistory::class);
        $id = $history->queueRefresh($gameId, $input, dispatch: false);
        $generation = (int) DB::table('nhl_game_prediction_run_games')->where('id', $id)->value('generation');
        $token = (string) \Illuminate\Support\Str::uuid();
        $item = $history->claim($id, $generation, $token);
        if ($item === null) {
            throw new NhlGamePredictionPreparing($gameId);
        }
        try {
            $result = $this->respond($gameId, $input, fn (): array => $payload->build($gameId, $input), true,
                (int) $item->run_id, $id, $generation, $token);
            $history->finish($id, ($result['prediction_available'] ?? false) ? 'completed' : 'unavailable',
                (int) $result['meta']['prediction_revision_id'], generation: $generation, claimToken: $token);
        } catch (NhlGamePredictionPreparing $pending) {
            $history->progress($id, $token, $pending->phase, release: true);
            throw $pending;
        } catch (Throwable $error) {
            $history->finish($id, 'failed', error: $error->getMessage(), generation: $generation, claimToken: $token);
            throw $error;
        } finally {
            $this->refreshFailed($gameId, $input);
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

    /** Queue manual-lineup changes after commit without calculating model inputs in the save request. */
    public function refreshAfterLineupSave(int $gameId): void
    {
        DB::afterCommit(function () use ($gameId): void {
            // The refresh worker computes the real current fingerprint before building.
            // Queue admission is deduplicated by game/request, not by this initial hint.
            if (! $this->queueLineupRefresh($gameId)) {
                throw new ServiceUnavailableHttpException(15, 'The lineup was saved, but its prediction refresh could not be queued.');
            }
        });
    }

    /** Admit a lineup refresh without loading models or historical inputs in the caller. */
    public function queueLineupRefresh(int $gameId): bool
    {
        return $this->queue($gameId, ['nhl_game_id' => $gameId], '', 'lineup');
    }

    /** Refresh only existing forecasts affected by a completed material injury update. */
    public function refreshAvailabilityForTeams(array $teams): void
    {
        DB::table('nhl_games')->where('game_date', '>=', now('America/Toronto')->toDateString())
            ->whereIn('game_state', ['FUT', 'PRE'])
            ->where(fn ($query) => $query->whereIn('away_team_abbrev', $teams)->orWhereIn('home_team_abbrev', $teams))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('nhl_game_prediction_revisions as r')
                ->whereColumn('r.nhl_game_id', 'nhl_games.nhl_game_id')->where('r.is_canonical', true)->where('r.prediction_available', true))
            ->orderBy('nhl_game_id')->pluck('nhl_game_id')->each(function ($id): void {
                try {
                    if (! $this->refreshAvailability((int) $id)) {
                        throw new \RuntimeException('Unable to queue injury-corrected prediction for game '.$id.'.');
                    }
                    app(NhlPublicGamePage::class)->refreshAfterCommit((int) $id);
                } catch (Throwable $error) {
                    report($error);
                }
            });
    }

    /** Queue an injury-driven replacement through the existing deduplicated refresh pathway. */
    public function refreshAvailability(int $gameId): bool
    {
        $input = ['nhl_game_id' => $gameId];

        return $this->queue($gameId, $input, '', 'injury');
    }

    /** @param array<string,mixed> $input */
    private function queue(int $gameId, array $input, string $fingerprint, string $trigger = 'api'): bool
    {
        try {
            app(NhlGamePredictionHistory::class)->queueRefresh($gameId, $input, $trigger);
            // Replace the legacy cache-only owner with the durable item, without deleting another request's lease.
            $this->refreshFailed($gameId, $input);
            return true;
        } catch (Throwable $error) {
            report($error);
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
            'game_state', 'away_team_abbrev', 'home_team_abbrev', 'away_starter_lock', 'home_starter_lock',
        ]);
        abort_if($game === null, 404);
        $forecast = app(NhlCustomGameForecast::class);
        $model = $forecast->model(isset($input['sat_model_run_id']) ? (int) $input['sat_model_run_id'] : null, $game->season_id);
        $models = collect([['id' => $model->id, 'updated_at' => $model->updated_at, 'train_season_ids' => $model->train_season_ids]]);
        $lineups = DB::table('nhl_current_lineups')->where('nhl_game_id', $gameId)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('nhl_lineup_observations as generated')
                ->whereColumn('generated.id', 'nhl_current_lineups.nhl_lineup_observation_id')
                ->where('generated.raw_evidence->system_generated', true))
            ->orderBy('team_id')->get(['team_id', 'structure_hash']);
        $goalies = DB::table('nhl_starting_goalie_observations')->where('nhl_game_id', $gameId)
            ->orderByRaw("CASE status WHEN 'confirmed' THEN 0 WHEN 'expected' THEN 1 ELSE 2 END")
            ->orderByDesc('fetched_at')->orderByDesc('id')
            ->get(['team_abbrev', 'nhl_player_id', 'provider', 'status'])
            ->unique(fn (object $row): string => implode('|', [$row->team_abbrev, (string) $row->nhl_player_id, $row->provider, $row->status]))->values();

        $source = $forecast->resultEvidence($model, $game, bounded: true);
        $source['future_context'] = NhlCustomGamePredictionInputs::futureContextIdentity((int) $model->id,
            [(int) $game->away_team_id, (int) $game->home_team_id]);

        return ['request' => $input, 'game' => $game, 'stack' => null, 'models' => $models,
            'algorithm' => config('nhl-custom-prediction'), 'source' => $source,
            'calculation_date' => now('America/Toronto')->toDateString(),
            'availability' => app(NhlLineupAvailability::class)->evidence($game),
            'lineups' => $lineups, 'goalies' => $goalies];
    }

    /** @param array<string,mixed> $input */
    private function key(int $gameId, array $input): string
    {
        ksort($input);

        return 'nhl:game-prediction:pregame-dependencies-v19:' . $gameId . ':' . hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }

    /** Publish only after the durable revision is saved. Cache hits never append history.
     * @param array<string,mixed> $input
     * @param array<string,mixed> $evidence
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function store(
        int $gameId,
        array $input,
        string $key,
        string $fingerprint,
        array $evidence,
        array $payload,
        ?int $runId = null,
        ?int $itemId = null,
        ?int $generation = null,
        ?string $claimToken = null,
    ): array {
        $payload = DB::transaction(function () use ($gameId, $input, $fingerprint, $evidence, $payload, $runId, $itemId, $generation, $claimToken): array {
            if ($itemId !== null) {
                DB::table('nhl_game_prediction_run_games')->where('id', $itemId)->lockForUpdate()->first();
                if (! app(NhlGamePredictionHistory::class)->owns($itemId, $generation, $claimToken)) {
                    throw new \DomainException('This prediction delivery was replaced; it cannot publish.');
                }
            }

            return app(NhlGamePredictionHistory::class)->record($gameId, $input, $fingerprint, $evidence, $payload, $runId);
        });
        // Never expose a revision through cache before its transaction has committed.
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
