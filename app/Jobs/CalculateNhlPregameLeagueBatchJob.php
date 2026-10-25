<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\NhlPregameContextRunStopped;
use App\Models\NhlPregameContextRun;
use App\Models\NhlPregameContextRunDay;
use App\Services\NhlPregameContextBuilder;
use App\Services\NhlPregameContextOrchestrator;
use App\Services\NhlPregameLeagueResults;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Checkpoint one league population per continuation before any dependent context. */
class CalculateNhlPregameLeagueBatchJob extends CalculateNhlPregameContextRunDayLeagueAveragesJob
{
    // Lock-contention releases may retry; a real exception or timeout fails immediately.
    public int $tries = 0;
    public int $maxExceptions = 1;
    public int $timeout = 180;
    public ?string $claimToken = null;

    /** Capture the exact dispatch lease; older serialized jobs retain a null legacy token. */
    public function __construct(int $runId, int $runDayId, ?string $claimToken = null)
    {
        parent::__construct($runId, $runDayId);
        $this->claimToken = $claimToken;
    }

    /** Keep duplicate deliveries from advancing the same cursor concurrently. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('pregame-league-day:'.$this->runId.':'.$this->runDayId))
            ->releaseAfter(5)->expireAfter($this->timeout + 60)];
    }

    /** Commit one raw-result population and return the date to the bounded loader. */
    public function handle(NhlPregameContextBuilder $builder, NhlPregameLeagueResults $results): void
    {
        $run = NhlPregameContextRun::query()->find($this->runId);
        $day = NhlPregameContextRunDay::query()->whereKey($this->runDayId)->where('run_id', $this->runId)->first();
        if ($run === null || $day === null || data_get($run->options, 'pipeline_version') !== NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE
            || ! $this->ownsClaim()) {
            return;
        }
        if (! in_array($run->status, ['running', 'pausing'], true)) {
            NhlPregameContextRunDay::query()->whereKey($day->id)->where('league_claim_token', $this->claimToken)
                ->where('league_state', 'processing')->update(['league_state' => $run->status === 'stopped' ? 'stopped' : 'pending']);

            return;
        }
        try {
            $day->update(['league_started_at' => $day->league_started_at ?? now()]);
            $started = hrtime(true);
            $pages = 0;
            do {
                $cursor = [(int) $day->league_cursor, (int) $day->league_entity_cursor];
                $complete = $results->buildNextAverages($run, $day, fn (): bool => $this->runStopped());
                $day->refresh();
                $pages++;
                // Every page commits separately. Yield promptly for Pause and never spin on a stale cursor.
            } while (! $complete
                && $cursor !== [(int) $day->league_cursor, (int) $day->league_entity_cursor]
                && $pages < max(1, (int) config('nhl-pregame.league_continuation_pages', 4))
                && (hrtime(true) - $started) / 1e9 < max(1, (int) config('nhl-pregame.league_continuation_seconds', 8))
                && NhlPregameContextRun::query()->whereKey($run->id)->value('status') === 'running');
            DB::transaction(function () use ($run, $day, $complete): void {
                if ($this->runStopped()) {
                    throw new NhlPregameContextRunStopped('Pregame work is no longer active.');
                }
                $owner = NhlPregameContextRun::query()->whereKey($run->id)->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->firstOrFail();
                if (! in_array($owner->status, ['running', 'pausing'], true) || ! $this->ownsClaim()) {
                    throw new NhlPregameContextRunStopped('Pregame work is no longer active.');
                }
                $day->update(['league_state' => $complete ? 'complete' : 'pending',
                    'league_completed_at' => $complete ? now() : null, 'last_error' => null]);
            });
        } catch (NhlPregameContextRunStopped) {
            NhlPregameContextRunDay::query()->whereKey($day->id)->where('league_state', 'processing')
                ->where('league_claim_token', $this->claimToken)
                ->update(['league_state' => $run->fresh()->status === 'stopped' ? 'stopped' : 'pending']);

            return;
        } catch (Throwable $exception) {
            $this->failed($exception);

            throw $exception;
        } finally {
            LoadNhlPregameContextRunJob::dispatch($run->id);
        }
    }

    /** Ignore obsolete timeout callbacks after a different delivery reclaimed the work. */
    public function failed(Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            NhlPregameContextRun::query()->whereKey($this->runId)->lock('for no key update')->first();
            if ($this->ownsClaim()) {
                parent::failed($exception);
            }
        });
    }

    /** Verify the persisted delivery ownership before work and after acquiring commit fences. */
    private function ownsClaim(): bool
    {
        return app(NhlPregameContextOrchestrator::class)->ownsDayClaim($this->runId, $this->runDayId, 'league', $this->claimToken);
    }

    /** Honor the cancellation signal before waiting for any checkpoint locks. */
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
            // Durable state remains available when Redis is unavailable.
        }

        return ! in_array(NhlPregameContextRun::query()->whereKey($this->runId)->value('status'), ['running', 'pausing'], true);
    }

}
