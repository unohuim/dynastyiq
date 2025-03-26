<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Classes\PlayByPlayImporter;



class ImportPlayByPlaysJob implements ShouldQueue
{
    use Queueable;

    protected $importer;
    protected $date;
    

    /**
     * Create a new job instance.
     */
    public function __construct($date)
    {
        $this->date = $date;
        $this->importer = new PlayByPlayImporter();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->importer->importPlayByPlaysByDate($this->date);
    }
}
