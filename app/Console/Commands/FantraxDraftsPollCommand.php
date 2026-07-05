<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncFantraxDraftStateJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class FantraxDraftsPollCommand extends Command
{
    protected $signature = 'fantrax:drafts:poll';
    protected $description = 'Dispatch Fantrax draft state sync jobs for due Fantrax leagues.';

    public function handle(): int
    {
        $leagueIds = DB::table('platform_leagues')
            ->join('drafts', 'drafts.platform_league_id', '=', 'platform_leagues.id')
            ->where('platform_leagues.platform', 'fantrax')
            ->where('drafts.platform', 'fantrax')
            ->where('drafts.source_type', 'platform_mirror')
            ->where('drafts.status', 'live')
            ->distinct()
            ->pluck('platform_leagues.id')
            ->map(static fn (mixed $leagueId): int => (int) $leagueId)
            ->values();

        foreach ($leagueIds as $leagueId) {
            SyncFantraxDraftStateJob::dispatch($leagueId);
            $this->line("Dispatched SyncFantraxDraftStateJob for league {$leagueId}");
        }

        $this->info("Done. Dispatched {$leagueIds->count()} draft sync job(s).");

        return self::SUCCESS;
    }
}
