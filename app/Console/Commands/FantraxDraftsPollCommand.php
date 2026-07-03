<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncFantraxDraftStateJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class FantraxDraftsPollCommand extends Command
{
    protected $signature = 'fantrax:drafts:poll';
    protected $description = 'Dispatch Fantrax draft state sync jobs for due Fantrax leagues.';

    public function handle(): int
    {
        $now = now();
        $leagueIds = DB::table('platform_leagues')
            ->join('fantrax_draft_states', 'fantrax_draft_states.platform_league_id', '=', 'platform_leagues.id')
            ->where('platform_leagues.platform', 'fantrax')
            ->where('fantrax_draft_states.status', 'live')
            ->select([
                'platform_leagues.id',
                'fantrax_draft_states.last_checked_at',
                'fantrax_draft_states.poll_interval_minutes',
            ])
            ->get()
            ->filter(static function (object $row) use ($now): bool {
                if ($row->last_checked_at === null) {
                    return true;
                }

                $interval = max(1, (int) ($row->poll_interval_minutes ?? 1));

                return CarbonImmutable::parse((string) $row->last_checked_at)
                    ->addMinutes($interval)
                    ->lessThanOrEqualTo($now);
            })
            ->map(static fn (object $row): int => (int) $row->id)
            ->values();

        foreach ($leagueIds as $leagueId) {
            SyncFantraxDraftStateJob::dispatch($leagueId);
            $this->line("Dispatched SyncFantraxDraftStateJob for league {$leagueId}");
        }

        $this->info("Done. Dispatched {$leagueIds->count()} draft sync job(s).");

        return self::SUCCESS;
    }
}
