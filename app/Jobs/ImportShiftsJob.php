<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Bus\Batchable;
use App\Classes\ShiftsImporter;

class ImportShiftsJob implements ShouldQueue
{
    use Queueable, Batchable;

    protected $shifts;


    /**
     * Create a new job instance.
     */
    public function __construct($shifts)
    {
        $this->shifts = $shifts;
    }



    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $importer = new ShiftsImporter();
        $importer->import($this->shifts);
    }
}
