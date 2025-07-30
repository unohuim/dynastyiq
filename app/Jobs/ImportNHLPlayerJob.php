<?php

namespace App\Jobs;

use App\Classes\ImportNHLPlayer;
use App\Traits\HasAPITrait;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Bus\Batchable;

class ImportNHLPlayerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;
    use HasAPITrait;
    use Batchable;

    protected string $playerId;
    protected bool $isProspect;

    /**
     * Create a new job instance.
     *
     * @param string $playerId
     * @param bool $isProspect
     */
    public function __construct(string $playerId, bool $isProspect = false)
    {
        $this->playerId = $playerId;
        $this->isProspect = $isProspect;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        (new ImportNHLPlayer())->import($this->playerId, $this->isProspect);
    }
}
