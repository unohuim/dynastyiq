<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\LeagueSyncStatusUpdated;
use App\Models\PlatformTeam;
use App\Services\YahooFantasyRosterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Syncs one Yahoo platform team's roster into platform roster memberships.
 */
class SyncYahooTeamRosterJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $platformTeamId,
        public ?int $userId = null,
    ) {
    }

    public function handle(YahooFantasyRosterService $service): void
    {
        $this->broadcastStatus('processing');

        try {
            $service->syncTeam($this->platformTeamId);
            $this->broadcastStatus('completed');
        } catch (\Throwable $throwable) {
            $this->broadcastStatus('failed');

            throw $throwable;
        }
    }

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return ['sync-yahoo-roster', "platform-team:{$this->platformTeamId}"];
    }

    private function broadcastStatus(string $status): void
    {
        if ($this->userId === null) {
            return;
        }

        $team = PlatformTeam::query()
            ->select('id', 'platform_league_id')
            ->with('league:id,platform')
            ->find($this->platformTeamId);

        if (! $team instanceof PlatformTeam || ! $team->league) {
            return;
        }

        LeagueSyncStatusUpdated::dispatch(
            $this->userId,
            (int) $team->platform_league_id,
            (string) $team->league->platform,
            $status,
        );
    }
}
