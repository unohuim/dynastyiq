<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Bus\Batchable;
use App\Classes\SumSeason;


class SumSeasonJob implements ShouldQueue
{
    use Queueable, Batchable;

    protected $sumSeason;
    protected string $season;



    /**
     * Create a new job instance.
     */
    public function __construct(string $season)
    {
        $this->sumSeason = new SumSeason();
        $this->season = $season;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->sumSeason->sum($this->season);
    }
}
