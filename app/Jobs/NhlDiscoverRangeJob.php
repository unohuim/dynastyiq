<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Repositories\NhlImportProgressRepo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Class NhlDiscoverRangeJob
 *
 * Inserts pre-discovered import rows for a date range (max N weeks) into the
 * nhl import progress repository. The payload already contains the full set
 * of rows for the chunk (built by the service).
 */
class NhlDiscoverRangeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const TAG_DISCOVERY = 'import-nhl-discovery';
    

    /** @var Carbon */
    public Carbon $from;

    /** @var Carbon */
    public Carbon $to;

    /**
     * @var array<int,array<string,mixed>> Rows to insert (scheduled jobs for each game × import type)
     */
    public array $rows;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public int $timeout = 300;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * @param Carbon $from Inclusive start of the chunk
     * @param Carbon $to   Inclusive end of the chunk
     * @param array<int,array<string,mixed>> $rows Scheduled rows payload for this chunk
     */
    public function __construct(Carbon $from, Carbon $to, array $rows)
    {
        $this->from = $from;
        $this->to   = $to;
        $this->rows = $rows;
    }

    /**
     * Handle the job.
     */
    public function handle(NhlImportProgressRepo $repo): void
    {
        if (empty($this->rows)) {
            return;
        }

        // Insert in manageable chunks to avoid oversized single inserts.
        foreach (array_chunk($this->rows, 1000) as $chunk) {
            $repo->insertScheduledRows($chunk);
        }
    }

    /**
     * Tags for queue monitoring.
     *
     * @return array<int,string>
     */
    public function tags(): array
    {
        return [
            self::TAG_DISCOVERY,
            "from:{$this->from->toDateString()}",
            "to:{$this->to->toDateString()}",
        ];
    }
}
