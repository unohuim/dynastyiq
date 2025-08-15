<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlDiscovery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NhlDiscoveryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * If set, tells the job to run sync($daysBack) instead of init().
     *
     * @var int|null
     */
    public ?int $daysBack;

    public const TAG_DISCOVERY_RUN = 'nhl-discovery-run';

    /**
     * @param int|null $daysBack Number of days to look back (sync mode) or null for full init
     */
    public function __construct(?int $daysBack = null)
    {
        $this->daysBack = $daysBack;
    }

    /**
     * Handle the job.
     */
    public function handle(NhlDiscovery $discovery): void
    {
        $discovery->sync($this->daysBack);
    }


    /**
     * Tags for Horizon monitoring.
     */
    public function tags(): array
    {
        if (is_int($this->daysBack)) {
            return [
                self::TAG_DISCOVERY_RUN,
                "mode:sync",
                "daysBack:{$this->daysBack}",
            ];
        }

        return [
            self::TAG_DISCOVERY_RUN,
            'mode:init',
        ];
    }
}
