<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Stats\NhleLeagueFactorResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Audits imported prospect league labels against seeded NHLe mappings.
 */
final class AuditNhleLeagueMappingsCommand extends Command
{
    protected $signature = 'stats:nhle-audit
        {--source=nl_ice_data : NHLe factor source slug}
        {--season= : Optional stats.season_id filter}
        {--game-type=2 : Optional stats.game_type_id filter}
        {--all-stats : Include non-prospect stats rows}';

    protected $description = 'Audit stats.league_abbrev values against seeded NHLe league mappings.';

    public function handle(NhleLeagueFactorResolver $resolver): int
    {
        $source = (string) $this->option('source');
        $season = $this->option('season');
        $gameType = $this->option('game-type');

        $query = DB::table('stats')
            ->select([
                'league_abbrev',
                DB::raw('COUNT(*) as row_count'),
                DB::raw('COUNT(DISTINCT player_id) as player_count'),
            ])
            ->whereNotNull('league_abbrev')
            ->where('league_abbrev', '<>', '');

        if (! (bool) $this->option('all-stats')) {
            $query->where('is_prospect', true);
        }

        if (is_string($season) && $season !== '') {
            $query->where('season_id', $season);
        }

        if (is_string($gameType) && $gameType !== '') {
            $query->where('game_type_id', (int) $gameType);
        }

        $rows = $query
            ->groupBy('league_abbrev')
            ->orderBy('league_abbrev')
            ->get();

        $unmapped = [];
        $tableRows = $rows->map(function (object $row) use ($resolver, $source, &$unmapped): array {
            $league = (string) $row->league_abbrev;
            $factor = $resolver->resolve($league, $source);
            $status = $factor === null ? 'unmapped' : 'matched';

            if ($factor === null) {
                $unmapped[] = $league;
            }

            return [
                'League' => $league,
                'Status' => $status,
                'Source League' => $factor?->source_league_name ?? '',
                'Points Factor' => $factor?->points_factor ?? '',
                'Players' => (int) $row->player_count,
                'Rows' => (int) $row->row_count,
            ];
        });

        $this->info(sprintf(
            'NHLe mapping audit: source=%s version=%s rows=%d',
            $source,
            $resolver->latestVersion($source) ?? 'none',
            $rows->count(),
        ));
        $this->table(['League', 'Status', 'Source League', 'Points Factor', 'Players', 'Rows'], $tableRows);

        if ($unmapped !== []) {
            $this->warn('Unmapped leagues: '.implode(', ', $unmapped));

            return self::FAILURE;
        }

        $this->info('All audited league labels are mapped.');

        return self::SUCCESS;
    }
}
