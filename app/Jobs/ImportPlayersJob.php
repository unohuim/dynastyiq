<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Traits\HasAPITrait;
use App\Jobs\ImportPlayerJob;


class ImportPlayersJob implements ShouldQueue
{
    use Queueable, HasAPITrait;

    protected string $NHL_API_BASE = "https://api-web.nhle.com/v1";
    protected string $teamAbbrev;


    /**
     * Create a new job instance.
     */
    public function __construct(string $teamAbbrev)
    {
        $this->teamAbbrev = $teamAbbrev;
    }



    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $url = $this->NHL_API_BASE . "/roster/" . $this->teamAbbrev . "/current";
        $players = $this->getAPIData($url);
        $url = $this->NHL_API_BASE . "/prospects/" . $this->teamAbbrev;
        $prospects = $this->getAPIData($url);

        //forwards
        foreach($players['forwards'] as $p) {
            ImportPlayerJob::dispatch($p['id']);
        }

        //dmen
        foreach($players['defensemen'] as $p) {
            ImportPlayerJob::dispatch($p['id']);
        }

        //goalies
        foreach($players['goalies'] as $p) {
            ImportPlayerJob::dispatch($p['id']);
        }

        //prospects - forwards
        foreach($prospects['forwards'] as $prospect) {
            ImportPlayerJob::dispatch($p['id'], true);
        }

        //prospects - defensemen
        foreach($prospects['defensemen'] as $prospect) {
            ImportPlayerJob::dispatch($prospect['id'], true);
        }

        //prospects - goalies
        foreach($prospects['goalies'] as $prospect) {
            ImportPlayerJob::dispatch($prospect['id'], true);
        }
    }
}
