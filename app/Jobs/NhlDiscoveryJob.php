<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlDiscovery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Dispatch discovery over a date range (inclusive), one day-per-job via NhlDiscovery.
 */
class NhlDiscoveryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const TAG_DISCOVERY_RUN = 'nhl-discovery-run';

    /** @var Carbon */
    public Carbon $start;

    /** @var Carbon */
    public Carbon $end;

    /** @var int */
    public int $timeout = 300;

    /** @var int */
    public int $tries = 1;

    /**
     * @param Carbon|string $start Later date (YYYY-MM-DD or Carbon)
     * @param Carbon|string $end   Earlier date (YYYY-MM-DD or Carbon)
     */
    public function __construct($start, $end)
    {
        $this->start = $start instanceof Carbon ? $start->copy()->startOfDay() : Carbon::parse((string) $start)->startOfDay();
        $this->end   = $end   instanceof Carbon ? $end->copy()->startOfDay()   : Carbon::parse((string) $end)->startOfDay();
    }

    public function handle(NhlDiscovery $discovery): void
    {
        $discovery->discoverRange($this->start, $this->end);
    }

    public function tags(): array
    {
        return [
            self::TAG_DISCOVERY_RUN,
            'mode:range',
            'start:' . $this->start->toDateString(),
            'end:' . $this->end->toDateString(),
        ];
    }
}
