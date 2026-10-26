<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\NhlPregameContextRunStopped;
use App\Models\NhlPregameContextRun;
use App\Models\NhlPregameContextRunDay;
use App\Models\NhlPregameContextRunGame;
use App\Services\NhlPregameContextBuilder;
use App\Services\NhlPregameContextOrchestrator;
use App\Services\NhlPregameLeagueResults;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Checkpoint one game phase or bounded peer cleanup per delivery. */
class BuildNhlPregameContextDayBatchJob extends BuildNhlPregameContextDayJob
{
    // Timeout retries use durable checkpoints; other exceptions fail visibly.
    public int $tries = 0;
    public int $maxExceptions = 1;
    public int $timeout = 300;
    public ?string $claimToken = null;

    /** Bind each continuation to the lease selected by the bounded loader. */
    public function __construct(int $runId, int $runDayId, ?string $claimToken = null)
    {
        parent::__construct($runId, $runDayId);
        $this->claimToken = $claimToken;
    }

    /** Prevent duplicate deliveries from processing the same date concurrently. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('pregame-context-day:'.$this->runId.':'.$this->runDayId))
            ->releaseAfter(5)->expireAfter($this->timeout + 60)];
    }

    /** Save the snapshot and comparisons separately, then yield to the loader. */
    public function handle(NhlPregameContextBuilder $builder): void
    {
        $run = NhlPregameContextRun::query()->find($this->runId);
        $day = NhlPregameContextRunDay::query()->whereKey($this->runDayId)->where('run_id', $this->runId)->first();
        if ($run === null || $day === null || data_get($run->options, 'pipeline_version') !== NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE
            || ! app(NhlPregameContextOrchestrator::class)->startDayClaim($this->runId, $this->runDayId, 'context', $this->claimToken)) {
            if ($run !== null && in_array($run->status, ['running', 'pausing'], true)) {
                LoadNhlPregameContextRunJob::dispatch($run->id);
            }
            return;
        }
        if (! in_array($run->status, [NhlPregameContextRun::STATUS_RUNNING, NhlPregameContextRun::STATUS_PAUSING], true)) {
            NhlPregameContextRunDay::query()->whereKey($day->id)->where('context_claim_token', $this->claimToken)
                ->where('context_state', 'processing')->update(['context_state' => $run->status === NhlPregameContextRun::STATUS_STOPPED ? 'stopped' : 'pending']);

            return;
        }
        if ($day->league_state !== NhlPregameContextRunDay::STATE_COMPLETE) {
            return;
        }
        $service = app(NhlPregameLeagueResults::class);
        $work = null;
        $cleanupComplete = false;
        try {
            $work = DB::transaction(function () use ($run, $day): ?NhlPregameContextRunGame {
                $this->assertOwnerActive();
                $day->update(['context_started_at' => $day->context_started_at ?? now()]);
                $game = NhlPregameContextRunGame::query()->where('run_id', $run->id)->whereDate('game_date', $day->game_date)
                    ->where(function ($query): void {
                        $query->whereIn('state', ['pending', 'ready', 'processing'])
                            ->orWhere(fn ($complete) => $complete->where('state', 'complete')->whereNull('comparisons_completed_at'));
                    })->orderBy('sequence')->lockForUpdate()->first();
                if ($game !== null) {
                    $game->update(['snapshot_completed_at' => $game->snapshot_completed_at ?? ($game->state === 'complete' ? now() : null),
                        'state' => 'processing', 'attempts' => (int) $game->attempts + 1, 'started_at' => now(), 'last_error' => null]);
                }

                return $game;
            });
            if ($work !== null && $work->snapshot_completed_at === null) {
                $started = hrtime(true);
                DB::transaction(function () use ($builder, $run, $day, $work, $started): void {
                    $result = $builder->buildDay($run, [(int) $work->nhl_game_id], fn (): bool => $this->runStopped());
                    $counts = $result['games'][$work->nhl_game_id] ?? ['blocked' => true];
                    $this->assertOwnerActive();
                    $seconds = round((hrtime(true) - $started) / 1e9, 3);
                    $work->update(['snapshot_completed_at' => now(), 'context_seconds' => $seconds,
                        'state' => ! empty($counts['blocked']) ? 'blocked' : 'processing',
                        'team_context_count' => $counts['team_context_count'] ?? 0, 'player_context_count' => $counts['player_context_count'] ?? 0,
                        'last_error' => ! empty($counts['blocked']) ? 'The game is missing completed schedule or player summary context.' : null]);
                    NhlPregameContextRunDay::query()->whereKey($day->id)->increment('context_seconds', $seconds);
                });
            } elseif ($work !== null && $work->fresh()->state !== 'blocked') {
                $started = hrtime(true);
                DB::transaction(function () use ($service, $run, $day, $work, $started): void {
                    $service->attachGameComparisons($run, $day, (int) $work->nhl_game_id, fn (): bool => $this->runStopped());
                    $this->assertOwnerActive();
                    $seconds = round((hrtime(true) - $started) / 1e9, 3);
                    $work->update(['state' => 'complete', 'comparisons_completed_at' => now(), 'completed_at' => now(),
                        'comparison_seconds' => $seconds, 'last_error' => null]);
                    NhlPregameContextRunDay::query()->whereKey($day->id)->increment('comparison_seconds', $seconds);
                });
            }
            if ($work === null) {
                $started = hrtime(true);
                $pages = 0;
                do {
                    $more = $service->releaseDayPeerPage($run, $day, fn (): bool => $this->runStopped());
                    $pages++;
                } while ($more && $pages < max(1, (int) config('nhl-pregame.peer_cleanup_pages', 8))
                    && (hrtime(true) - $started) / 1e9 < max(1, (int) config('nhl-pregame.peer_cleanup_seconds', 8)));
                $cleanupComplete = ! $more;
            }
            DB::transaction(function () use ($run, $day, $cleanupComplete): void {
                $owner = $this->assertOwnerActive();
                $games = NhlPregameContextRunGame::query()->where('run_id', $run->id)->whereDate('game_date', $day->game_date);
                $remaining = (clone $games)->where(function ($query): void {
                    $query->whereNotIn('state', ['complete', 'blocked'])
                        ->orWhere(fn ($complete) => $complete->where('state', 'complete')->whereNull('comparisons_completed_at'));
                })->exists();
                $complete = ! $remaining && $cleanupComplete;
                $day->update(['context_state' => $complete ? 'complete' : 'pending',
                    'completed_games' => (clone $games)->where('state', 'complete')->count(),
                    'blocked_games' => (clone $games)->where('state', 'blocked')->count(),
                    'context_completed_at' => $complete ? now() : null, 'last_error' => null]);
                app(NhlPregameContextOrchestrator::class)->clearDayTimeoutRetries($owner, $day->id, 'context');
            });
        } catch (NhlPregameContextRunStopped) {
            // Stop already fences work; a failed sibling run leaves this batch resumable.
            $status = NhlPregameContextRun::query()->whereKey($run->id)->value('status');
            NhlPregameContextRunDay::query()->whereKey($day->id)->where('context_state', 'processing')
                ->where('context_claim_token', $this->claimToken)
                ->update(['context_state' => $status === 'stopped' ? 'stopped' : 'pending']);

            return;
        } catch (Throwable $exception) {
            $this->failed($exception);

            throw $exception;
        } finally {
            LoadNhlPregameContextRunJob::dispatch($run->id);
        }
        app(NhlPregameContextOrchestrator::class)->refreshRunCounts($run->fresh());
    }

    /** Fail only the current game; committed games and snapshots remain usable. */
    public function failed(Throwable $exception): void
    {
        if (app(NhlPregameContextOrchestrator::class)->retryTimedOutDay($this->runId, $this->runDayId, 'context', $this->claimToken, $exception)) {
            return;
        }
        DB::transaction(function () use ($exception): void {
            // Status updates must coexist with foreign-key key-share locks held by other dates.
            $run = NhlPregameContextRun::query()->whereKey($this->runId)->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->first();
            if ($run === null || ! in_array($run->status, ['running', 'pausing', 'failed'], true)) {
                return;
            }
            $day = NhlPregameContextRunDay::query()->whereKey($this->runDayId)->where('run_id', $run->id)->first();
            if ($day === null || ! $this->ownsClaim()) {
                return;
            }
            $error = mb_strimwidth($exception->getMessage(), 0, 2000);
            $games = NhlPregameContextRunGame::query()->where('run_id', $run->id)->whereDate('game_date', $day->game_date);
            (clone $games)->where('state', 'processing')->update(['state' => 'failed', 'last_error' => $error, 'updated_at' => now()]);
            $day->update(['context_state' => 'failed', 'failed_games' => (clone $games)->where('state', 'failed')->count(), 'last_error' => $error]);
            $run->update(['status' => 'failed', 'last_error' => $error]);
            app(NhlPregameContextOrchestrator::class)->refreshRunCounts($run);
        });
    }

    /** Serialize checkpoint commits with Stop, Pause, and failure transitions. */
    private function assertOwnerActive(): NhlPregameContextRun
    {
        if ($this->runStopped()) {
            throw new NhlPregameContextRunStopped('Pregame work is no longer active.');
        }
        $run = NhlPregameContextRun::query()->whereKey($this->runId)->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->firstOrFail();
        if (! in_array($run->status, ['running', 'pausing'], true) || ! $this->ownsClaim()) {
            throw new NhlPregameContextRunStopped('Pregame work is no longer active.');
        }

        return $run;
    }

    /** Check cancellation during bounded result and metric batches. */
    private function runStopped(): bool
    {
        if (! $this->ownsClaim()) {
            return true;
        }
        try {
            if (Cache::store('redis')->get(NhlPregameContextRun::stopSignalKey($this->runId))) {
                return true;
            }
        } catch (Throwable) {
            // Durable state remains authoritative when Redis is unavailable.
        }

        return ! in_array(NhlPregameContextRun::query()->whereKey($this->runId)->value('status'), ['running', 'pausing'], true);
    }

    /** A replaced or expired continuation has no authority to update snapshots or progress. */
    private function ownsClaim(): bool
    {
        return app(NhlPregameContextOrchestrator::class)->ownsDayClaim($this->runId, $this->runDayId, 'context', $this->claimToken);
    }
}
