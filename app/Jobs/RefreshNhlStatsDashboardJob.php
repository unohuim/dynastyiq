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

/** Replace one prepared public stats view without evicting the last successful result. */
class RefreshNhlStatsDashboardJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable, Batchable;

    public int $tries = 0;
    public int $maxExceptions = 3;
    public int $timeout = 180;
    public int $uniqueFor = 600;
    public int $backoff = 30;

    /** Scope refreshes by season and public view; private report data never enters this cache. */
    public function __construct(
        public string $seasonId,
        public string $view,
        public ?int $perspectiveId = null,
        public int $gameType = 2,
    ) {
        if (! preg_match('/^\d{8}$/', $seasonId) || ! in_array($view, ['leaders', 'skaters', 'goalies', 'teams', 'highlights', 'table'], true)
            || ($view === 'table' && $perspectiveId === null) || ! in_array($gameType, [1, 2, 3], true)) {
            throw new InvalidArgumentException('A stats refresh requires a season and supported public view.');
        }
        $this->onQueue('projections');
        $this->afterCommit = true;
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
            return;
        }
        if ($this->view === 'highlights') {
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

    /** Identify the affected public season and view in Horizon. */
    public function tags(): array
    {
        return ['nhl-stats-dashboard', 'season:'.$this->seasonId, 'view:'.$this->view,
            ...($this->view === 'table' ? ['perspective:'.$this->perspectiveId, 'game-type:'.$this->gameType] : [])];
    }
}
