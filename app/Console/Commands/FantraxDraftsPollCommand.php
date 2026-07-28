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
        $rows = DB::table('platform_leagues')
            ->join('drafts', 'drafts.platform_league_id', '=', 'platform_leagues.id')
            ->join('league_platform_league', 'league_platform_league.platform_league_id', '=', 'platform_leagues.id')
            ->join('organization_leagues', 'organization_leagues.league_id', '=', 'league_platform_league.league_id')
            ->where('platform_leagues.platform', 'fantrax')
            ->where('drafts.platform', 'fantrax')
            ->where('drafts.source_type', 'platform_mirror')
            ->where('drafts.status', 'live')
            ->select([
                'platform_leagues.id',
                'organization_leagues.meta',
            ])
            ->get();

        $leagueIds = $rows
            ->filter(static fn (object $row): bool => self::draftSyncEnabled($row->meta ?? null))
            ->pluck('id')
            ->map(static fn (mixed $leagueId): int => (int) $leagueId)
            ->unique()
            ->values();

        foreach ($leagueIds as $leagueId) {
            SyncFantraxDraftStateJob::dispatch($leagueId);
            $this->line("Dispatched SyncFantraxDraftStateJob for league {$leagueId}");
        }

        $this->info("Done. Dispatched {$leagueIds->count()} draft sync job(s).");

        return self::SUCCESS;
    }

    /**
     * Determine whether a community league has opted into scheduled Fantrax draft syncing.
     */
    private static function draftSyncEnabled(mixed $meta): bool
    {
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($meta)) {
            return false;
        }

        return (bool) data_get($meta, 'draft_sync.enabled', false);
    }
}
