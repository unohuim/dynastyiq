<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlStatsDashboardPayload;
use App\Services\NhlDashboardPayload;
use App\Models\Perspective;
use App\Support\Stats\StatsPayloadBuilder;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Replace one prepared public stats view without evicting the last successful result. */
class RefreshNhlStatsDashboardJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable, Batchable;

    public int $tries = 0;
    public int $maxExceptions = 3;
    public int $timeout = 180;
    public int $uniqueFor = 600;
    public int $backoff = 30;
    public ?string $leadersRefreshId = null;
    public ?int $originatingRunId = null;

    /** Scope refreshes by season and public view; private report data never enters this cache. */
    public function __construct(
        public string $seasonId,
        public string $view,
        public ?int $perspectiveId = null,
        public int $gameType = 2,
        ?int $originatingRunId = null,
    ) {
        if (! preg_match('/^\d{8}$/', $seasonId) || ! in_array($view, ['leaders', 'skaters', 'goalies', 'teams', 'highlights', 'table'], true)
            || ($view === 'table' && $perspectiveId === null) || ! in_array($gameType, [1, 2, 3], true)) {
            throw new InvalidArgumentException('A stats refresh requires a season and supported public view.');
        }
        $this->onQueue('projections');
        $this->afterCommit = true;
        $this->originatingRunId = $originatingRunId;
        $this->leadersRefreshId = $view === 'leaders' ? (string) Str::uuid() : null;
    }

    /** Coalesce pending triggers while permitting a follow-up refresh during active work. */
    public function uniqueId(): string
    {
        if ($this->view === 'highlights') {
            return 'nhl-stats-dashboard:highlights';
        }
        return 'nhl-stats-dashboard:'.$this->seasonId.':'.$this->view.($this->view === 'table' ? ':'.$this->perspectiveId.':'.$this->gameType : '');
    }

    /** Serialize publishers so an older refresh cannot overwrite a newer completed refresh. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->releaseAfter(10)->expireAfter($this->timeout + 60)];
    }

    /** Calculate first, then publish the complete replacement under its existing cache key. */
    public function handle(NhlStatsDashboardPayload $payload): void
    {
        if ($this->batch()?->cancelled()) {
            if ($this->view === 'leaders') {
                $payload->releaseLeadersPreparation($this->seasonId, $this->leadersIdentity());
            }
            return;
        }
        if ($this->view === 'leaders') {
            $refreshId = $this->leadersIdentity();
            $previous = $payload->leadersPreparationProgress($this->seasonId, $refreshId);
            $complete = $payload->advanceLeadersPreparation($this->seasonId, $refreshId);
            $progress = $payload->leadersPreparationProgress($this->seasonId, $refreshId);
            if ($progress !== []) {
                $this->recordLeadersProgress($progress);
            }
            if (! $complete) {
                // Releasing this same batch member does not acknowledge report completion.
                $this->release($progress !== $previous ? 0 : 15);
            }
        } elseif ($this->view === 'highlights') {
            app(NhlDashboardPayload::class)->refreshStatsHighlights();
        } elseif ($this->view === 'table') {
            $perspective = Perspective::query()->where('visibility', 'public_guest')->find($this->perspectiveId);
            if ($perspective !== null) {
                app(StatsPayloadBuilder::class)->refreshPreparedSeasonPayload($perspective, $this->seasonId, $this->gameType);
            }
        } else {
            $payload->refreshCachedFacts($this->seasonId, $this->view);
        }
    }

    /** Old serialized jobs share a stable legacy scope, including in reconstructed failure callbacks. */
    private function leadersIdentity(): string
    {
        return $this->leadersRefreshId ??= 'legacy-'.hash('sha256', json_encode([
            $this->seasonId, $this->view, $this->batchId, $this->perspectiveId, $this->gameType,
        ], JSON_THROW_ON_ERROR));
    }

    /** Merge bounded preparation diagnostics without replacing cache, goalie or aggregation state. */
    private function recordLeadersProgress(array $progress, ?string $error = null): void
    {
        if ($this->originatingRunId === null) {
            return;
        }
        DB::transaction(function () use ($progress, $error): void {
            $run = \App\Models\NhlGameImportRun::query()->lockForUpdate()->find($this->originatingRunId);
            if ($run === null || $run->action !== 'season-sync') {
                return;
            }
            $payload = $run->payload ?? [];
            // A later tracked refresh must not inherit diagnostics from an older batch.
            if ($this->batchId !== null && data_get($payload, 'stats_cache.batch_id') !== $this->batchId) {
                return;
            }
            $payload['stats_cache']['leaders_preparation'] = ['season_id' => $this->seasonId, ...$progress];
            $values = ['payload' => $payload];
            if ($error !== null) {
                $phase = $progress['phase'] ?? 'initialization';
                $elapsed = isset($progress['started_at'])
                    ? max(0, now()->timestamp - strtotime($progress['started_at'])) : 0;
                $message = $error.' Leaders stage: '.$phase.', cursor '.($progress['cursor'] ?? 0).', elapsed '.$elapsed.'s.';
                $payload['stats_cache']['error'] = $message;
                $values = ['payload' => $payload, 'last_error' => $message];
            }
            $run->update($values);
        }, 3);
    }

    /** Identify terminal page failures and unblock following refreshes without deleting published data. */
    public function failed(?Throwable $error): void
    {
        if ($this->view !== 'leaders') {
            return;
        }
        $payload = app(NhlStatsDashboardPayload::class);
        $refreshId = $this->leadersIdentity();
        try {
            $this->recordLeadersProgress($payload->leadersPreparationProgress($this->seasonId, $refreshId),
                $error?->getMessage() ?? 'Leaders preparation failed.');
        } finally {
            $payload->releaseLeadersPreparation($this->seasonId, $refreshId);
        }
    }

    /** Identify the affected public season and view in Horizon. */
    public function tags(): array
    {
        return ['nhl-stats-dashboard', 'season:'.$this->seasonId, 'view:'.$this->view,
            ...($this->view === 'table' ? ['perspective:'.$this->perspectiveId, 'game-type:'.$this->gameType] : [])];
    }
}
