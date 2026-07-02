<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\ImportStreamEvent;
use App\Jobs\ImportFantraxPlayersChunkJob;
use App\Jobs\ImportFantraxPlayersJob;
use App\Models\ImportRun;
use App\Services\ImportFantraxPlayers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class FantraxImportPlayersCommand extends Command
{
    /**
     * Usage: php artisan fx:import --players
     *
     * @var string
     */
    protected $signature = 'fx:import
                            {--players : Import Fantrax players}
                            {--import-run-id= : Internal admin import run id}';

    /**
     * @var string
     */
    protected $description = 'Run Fantrax import tasks';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('players')) {
            $importRunId = $this->option('import-run-id')
                ? (int) $this->option('import-run-id')
                : null;

            if ($importRunId !== null) {
                $entries = (new ImportFantraxPlayers())->fetchEntries();
                $cacheKey = 'fantrax-import:entries:' . Str::uuid()->toString();
                Cache::put($cacheKey, $entries, now()->addHour());

                ImportRun::query()
                    ->find($importRunId)
                    ?->setProgressTotal(count($entries), 'Fantrax player records');

                ImportStreamEvent::dispatch('fantrax', 'Importing Fantrax players', 'started');
                ImportFantraxPlayersChunkJob::dispatch($cacheKey, 0, count($entries), $importRunId);

                $this->info('Queued Fantrax player chunks.');
                return self::SUCCESS;
            }

            ImportFantraxPlayersJob::dispatch($importRunId);
            $this->info('Dispatched ImportFantraxPlayersJob.');
            return self::SUCCESS;
        }

        $this->error('Nothing to do. Try: fx:import --players');
        return self::FAILURE;
    }
}
