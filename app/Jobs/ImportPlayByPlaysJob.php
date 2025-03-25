<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Classes\PlayByPlayImporter;



class ImportPlayByPlaysJob implements ShouldQueue
{
    use Queueable;

    protected $importer;
    

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->importer = new PlayByPlayImporter();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->importer->import();
    }
}
