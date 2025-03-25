<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Classes\ImportPlayer;


class ImportPlayerJob implements ShouldQueue
{
    use Queueable, HasAPITrait;

    protected string $player_id;
    protected bool $is_prospect;



    /**
     * Create a new job instance.
     */
    public function __construct(string $player_id, bool $is_prospect=false)
    {
        $this->player_id = $player_id;
        $this->is_prospect = $is_prospect;
    }
   


    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $importPlayer = new ImportPlayer();
        $importPlayer->import($this->player_id, $this->is_prospect);
    }
}
