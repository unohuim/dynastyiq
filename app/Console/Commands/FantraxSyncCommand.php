<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncFantraxLeagueJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class FantraxSyncCommand extends Command
{
    protected $signature = 'fx:sync';
    protected $description = 'Dispatch sync jobs for all Fantrax leagues.';

    public function handle(): int
    {
        $leagueIds = DB::table('platform_leagues')
            ->where('platform', 'fantrax') // <- ensure only Fantrax leagues
            ->pluck('id')
            ->map(fn ($i) => (int) $i)
            ->values();

        if ($leagueIds->isEmpty()) {
            $this->warn('No Fantrax leagues found (platform = fantrax).');
            return self::SUCCESS;
        }

        foreach ($leagueIds as $id) {
            SyncFantraxLeagueJob::dispatch($id);
            $this->line("→ Dispatched SyncFantraxLeagueJob for league {$id}");
        }

        $this->info("Done. Dispatched {$leagueIds->count()} job(s).");
        return self::SUCCESS;
    }
}
