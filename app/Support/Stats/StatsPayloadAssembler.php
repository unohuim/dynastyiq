<?php

declare(strict_types=1);

namespace App\Support\Stats;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Assembles stats model collections into frontend payload rows.
 */
final class StatsPayloadAssembler
{
    /**
     * @param Collection<int,object> $collection
     * @param array<int,array<string,mixed>> $columns
     * @param array<string,mixed> $filters
     * @return Collection<int,array<string,mixed>>
     */
    public function assembleRowsFromCollection(
        Collection $collection,
        array $columns,
        string $slice,
        bool $canSlice,
        string $mode,
        array $filters = []
    ): Collection {
        $rows = collect();
        $officialToiByPlayer = $this->officialBoxscoreToiByPlayer($collection, $mode, $filters);

        $isDraftContext = (bool) ($filters['draft_context'] ?? false);
        $grouped = $collection->groupBy(function ($row) use ($mode, $isDraftContext): string {
            $playerId = (string) ($row->player_id ?? $row->nhl_player_id ?? '');

            if ($mode === 'prospects' && ! $isDraftContext) {
                return $playerId . '|' . (string) ($row->league_abbrev ?? '');
            }

            return $playerId;
        });

        foreach ($grouped as $playerStats) {
            if ($mode === 'prospects' && $isDraftContext) {
                $playerStats = $this->prospectStatsForPrimaryLeague($playerStats);
            }

            $entry = $playerStats->count() === 1 ? $playerStats->first() : $playerStats->sortByDesc('gp')->first();
            $player = $entry->player;
            $isSeason = ($mode === 'season' || ($mode === 'prospects' && (bool) ($filters['draft_context'] ?? false)));

            $contract = $player?->relationLoaded('contracts')
                ? $player->contracts->first()
                : $player?->contracts()->first();
            $contractSeason = $contract?->seasons->last();
            $contractLastLabel = $contractSeason?->label ?? '';
            $contractCapHitRaw = is_numeric($contractSeason?->cap_hit) ? (float) $contractSeason->cap_hit : 0.0;

            $contractCapHitMillions = $contractCapHitRaw > 0 ? $contractCapHitRaw / 1_000_000 : 0.0;
            $contractCapHit = $contractCapHitRaw > 0 ? '$' . number_format($contractCapHitMillions, 2) . 'm' : '$0.00m';
            $lastYearNumber = $this->parseContractLastYear($contractLastLabel);

            if ($isSeason) {
                $gamesPlayed = (int) ($entry->gp ?? 0);
                $toiSeconds = $this->entryToiSeconds($entry);
            } else {
                $gamesPlayed = ($mode === 'range')
                    ? (int) $playerStats->pluck('nhl_game_id')->unique()->count()
                    : (int) $playerStats->sum('gp');
                $toiSeconds = $this->collectionToiSeconds($playerStats);
            }

            $nhlPlayerId = (int) ($player?->nhl_id ?? $entry->nhl_player_id ?? 0);
            $officialToiSeconds = (int) ($officialToiByPlayer->get($nhlPlayerId) ?? 0);
            if ($officialToiSeconds > 0) {
                $toiSeconds = $officialToiSeconds;
            }

            $toiPerGameSeconds = ($gamesPlayed > 0) ? (int) floor($toiSeconds / $gamesPlayed) : 0;

            $row = [
                'name' => $player?->full_name ?? trim(($player->first_name ?? '') . ' ' . ($player->last_name ?? '')),
                'player_id' => $player?->id ?? $entry->player_id ?? null,
                'avatar_url' => $player?->head_shot_url,
                'age' => $this->playerAge($player),
                'team' => $player?->team_abbrev ?? $entry->team_abbrev ?? ($entry->nhl_team_abbrev ?? null),
                'league' => $mode === 'prospects' ? ($entry->league_abbrev ?? null) : null,
                'pos' => (bool) ($player?->is_goalie ?? false) ? 'G' : $player?->position,
                'pos_type' => (bool) ($player?->is_goalie ?? false) ? 'G' : $player?->pos_type,
                'is_goalie' => (bool) ($player?->is_goalie ?? false),
                'contract_value' => $contractCapHit,
                'contract_value_num' => round($contractCapHitMillions, 2),
                'contract_last_year' => $contractLastLabel,
                'contract_last_year_num' => $lastYearNumber,
                'drafted_overall_pick' => $player?->draft_oa,
                'drafted_year' => $player?->draft_year,
                'drafted_label' => $this->draftedLabel($player?->draft_oa, $player?->draft_year),
                'gp' => max(0, $gamesPlayed),
                'nhl_player_id' => $player?->nhl_id ?? $entry->nhl_player_id ?? null,
                'toi_seconds' => $toiPerGameSeconds,
                'toi' => $this->formatTimeOnIce($toiPerGameSeconds),
            ];

            foreach ($columns as $column) {
                $key = $column['key'] ?? null;
                if (
                    ! $key
                    || ! empty($column['formula'])
                    || ! empty($column['computed_fantasy_points'])
                    || ! empty($column['computed_fantasy_points_per_game'])
                    || $key === 'gp'
                    || $key === 'toi'
                    || $key === 'toi_seconds'
                ) {
                    continue;
                }

                $total = $isSeason
                    ? (float) ($entry->{$key} ?? 0)
                    : (float) $playerStats->sum($key);

                if ($canSlice && $slice !== 'total') {
                    if ($slice === 'pgp') {
                        $row[$key] = $gamesPlayed > 0 ? round($total / $gamesPlayed, 2) : 0.0;
                    } elseif ($slice === 'p60') {
                        $row[$key] = $toiSeconds > 0 ? round($total / ($toiSeconds / 3600), 2) : 0.0;
                    }
                } else {
                    $row[$key] = fmod($total, 1.0) === 0.0 ? (int) $total : $total;
                }
            }

            $row = $this->withFormulaDependencyValues($row, $columns, $entry, $playerStats, $isSeason);
            $row = $this->withNativeFantasyAliases($row, $playerStats, $gamesPlayed, $toiSeconds, $isSeason, $entry);
            $row = $this->withFormulaColumns($row, $columns);
            $row = $this->withComputedFantasyPoints($row, $columns);

            $rows->push($row);
        }

        return $rows;
    }

    private function draftedLabel(mixed $overallPick, mixed $year): ?string
    {
        $overallPick = (int) ($overallPick ?? 0);
        $year = (int) ($year ?? 0);
        if ($overallPick <= 0 || $year <= 0) {
            return null;
        }

        return $overallPick . '-' . $year;
    }

    /**
     * @param Collection<int,array<string,mixed>> $rows
     * @param array<int,array<string,mixed>> $columns
     * @param array<string,mixed> $filters
     * @return Collection<int,array<string,mixed>>
     */
    public function appendOnIceRows(Collection $rows, array $columns, array $filters): Collection
    {
        if (! $this->columnsNeedOnIce($columns) || $rows->isEmpty()) {
            return $rows;
        }

        $playerIds = $rows
            ->pluck('nhl_player_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($playerIds === []) {
            return $rows;
        }

        $onIceRows = $this->nativeOnIceTotals($filters, $playerIds);
        if ($onIceRows->isEmpty()) {
            return $rows;
        }

        return $rows->map(function (array $row) use ($columns, $onIceRows): array {
            $nhlPlayerId = (int) ($row['nhl_player_id'] ?? 0);

            if ($nhlPlayerId === 0 || ! $onIceRows->has($nhlPlayerId)) {
                return $row;
            }

            $row = array_merge($row, $this->nativeOnIceAliases($onIceRows->get($nhlPlayerId)));
            $row = $this->withFormulaColumns($row, $columns);

            return $this->withComputedFantasyPoints($row, $columns);
        });
    }

    private function entryToiSeconds(object $entry): int
    {
        if (isset($entry->toi_seconds) && is_numeric($entry->toi_seconds)) {
            return (int) $entry->toi_seconds;
        }
        if (isset($entry->toi) && is_numeric($entry->toi)) {
            return (int) $entry->toi;
        }
        if (isset($entry->toi_minutes) && is_numeric($entry->toi_minutes)) {
            $value = (float) $entry->toi_minutes;

            return (int) (($value > 4000) ? $value : $value * 60.0);
        }

        return 0;
    }

    /**
     * Keep only the prospect league where the player had the most games played.
     *
     * @param Collection<int,object> $playerStats
     * @return Collection<int,object>
     */
    private function prospectStatsForPrimaryLeague(Collection $playerStats): Collection
    {
        if ($playerStats->count() <= 1) {
            return $playerStats;
        }

        $primaryLeague = $playerStats
            ->groupBy(static fn (object $row): string => (string) ($row->league_abbrev ?? ''))
            ->map(static fn (Collection $leagueRows): int => (int) $leagueRows->sum('gp'))
            ->sortDesc()
            ->keys()
            ->first();

        if ($primaryLeague === null) {
            return $playerStats;
        }

        return $playerStats
            ->filter(static fn (object $row): bool => (string) ($row->league_abbrev ?? '') === (string) $primaryLeague)
            ->values();
    }

    /**
     * Add safe arithmetic formula columns from already assembled row values.
     *
     * @param array<string,mixed> $row
     * @param array<int,array<string,mixed>> $columns
     * @return array<string,mixed>
     */
    private function withFormulaColumns(array $row, array $columns): array
    {
        foreach ($columns as $column) {
            $key = trim((string) ($column['key'] ?? ''));
            $formula = trim((string) ($column['formula'] ?? ''));

            if ($key === '' || $formula === '') {
                continue;
            }

            $row[$key] = $this->evaluateFormula($formula, $row);
        }

        return $row;
    }

    /**
     * Compute points-league fantasy totals from already assembled scoring columns.
     *
     * @param array<string,mixed> $row
     * @param array<int,array<string,mixed>> $columns
     * @return array<string,mixed>
     */
    private function withComputedFantasyPoints(array $row, array $columns): array
    {
        $shouldCompute = collect($columns)->contains(
            static fn (array $column): bool => (bool) ($column['computed_fantasy_points'] ?? false)
        );

        if (! $shouldCompute) {
            return $row;
        }

        $total = 0.0;
        $unsupported = 0;
        $missingWeights = 0;

        foreach ($columns as $column) {
            if (! $this->fantasyColumnAppliesToRow($column, $row)) {
                continue;
            }

            if (! (bool) ($column['is_supported'] ?? false)) {
                $unsupported++;
                continue;
            }

            $key = trim((string) ($column['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $value = $row[$key] ?? 0;
            if (! is_numeric($value)) {
                continue;
            }

            [$weight, $usedDefaultWeight] = $this->fantasyWeightForRow($column, $row);
            if ($usedDefaultWeight) {
                $missingWeights++;
            }

            $total += ((float) $value) * $weight;
        }

        $gamesPlayed = (int) ($row['gp'] ?? 0);
        $row['fantasy_pts'] = $this->roundFantasyPoints($total);
        $row['fantasy_pts_pg'] = $gamesPlayed > 0
            ? $this->roundFantasyPointsPerGame($total / $gamesPlayed)
            : 0.0;
        $row['fantasy_pts_complete'] = $unsupported === 0 && $missingWeights === 0;
        $row['fantasy_pts_unsupported_categories'] = $unsupported;
        $row['fantasy_pts_default_weight_categories'] = $missingWeights;

        return $row;
    }

    /**
     * @param array<string,mixed> $column
     * @param array<string,mixed> $row
     */
    private function fantasyColumnAppliesToRow(array $column, array $row): bool
    {
        if (! (bool) ($column['fantasy_scoring_category'] ?? false)) {
            return false;
        }

        $rowIsGoalie = (bool) ($row['is_goalie'] ?? false)
            || strtoupper(trim((string) ($row['pos'] ?? ''))) === 'G'
            || strtoupper(trim((string) ($row['pos_type'] ?? ''))) === 'G';
        $isGoalieColumn = $this->columnHasExplicitGoalieGroup($column);
        $hasGoaliePositionValue = $this->columnHasPositionValue($column, ['G', 'GOALIE']);

        if ($rowIsGoalie) {
            return $isGoalieColumn || $hasGoaliePositionValue;
        }

        return ! $isGoalieColumn;
    }

    /**
     * @param array<string,mixed> $column
     * @param array<int,string> $positions
     */
    private function columnHasPositionValue(array $column, array $positions): bool
    {
        $positionValues = is_array($column['position_values'] ?? null)
            ? $column['position_values']
            : [];
        $positions = collect($positions)
            ->map(static fn (string $position): string => strtoupper(trim($position)))
            ->all();

        foreach (array_keys($positionValues) as $position) {
            if (in_array(strtoupper(trim((string) $position)), $positions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $column
     */
    private function columnHasExplicitGoalieGroup(array $column): bool
    {
        $group = strtoupper(trim((string) (
            $column['normalized_group']
            ?? $column['group']
            ?? $column['provider_group']
            ?? ''
        )));
        $key = strtoupper(trim((string) ($column['key'] ?? '')));

        return $group === 'HOCKEY_GOALIE'
            || $group === 'GOALIE'
            || str_starts_with($key, 'HOCKEY_GOALIE:');
    }

    /**
     * @param array<string,mixed> $column
     * @param array<string,mixed> $row
     */
    private function fantasyWeightForRow(array $column, array $row): array
    {
        $positionValues = is_array($column['position_values'] ?? null)
            ? $column['position_values']
            : [];

        $positionCandidates = collect([
            $row['pos'] ?? null,
            $row['pos_type'] ?? null,
            ($row['pos'] ?? null) === 'D' ? 'DEFENSE' : null,
            in_array($row['pos'] ?? null, ['C', 'LW', 'RW', 'F'], true) ? 'FORWARD' : null,
            ($row['pos'] ?? null) === 'G' ? 'GOALIE' : null,
            'DEFAULT',
            'Default',
        ])
            ->map(static fn (mixed $position): string => strtoupper(trim((string) $position)))
            ->filter()
            ->unique()
            ->values();

        foreach ($positionCandidates as $position) {
            foreach ($positionValues as $key => $value) {
                if (strtoupper(trim((string) $key)) === $position && is_numeric($value)) {
                    return [(float) $value, false];
                }
            }
        }

        if (is_numeric($column['fantasy_weight'] ?? null)) {
            return [(float) $column['fantasy_weight'], false];
        }

        return [1.0, true];
    }

    private function roundFantasyPoints(float $value): float|int
    {
        $rounded = round($value, 3);

        return fmod($rounded, 1.0) === 0.0 ? (int) $rounded : $rounded;
    }

    private function roundFantasyPointsPerGame(float $value): float
    {
        return round($value, 2);
    }

    /**
     * Add non-visible formula dependency values from the stats source.
     *
     * @param array<string,mixed> $row
     * @param array<int,array<string,mixed>> $columns
     * @param Collection<int,object> $playerStats
     * @return array<string,mixed>
     */
    private function withFormulaDependencyValues(
        array $row,
        array $columns,
        object $entry,
        Collection $playerStats,
        bool $isSeason,
    ): array {
        $dependencies = collect($columns)
            ->filter(static fn (array $column): bool => filled($column['formula'] ?? null))
            ->flatMap(static fn (array $column): array => is_array($column['required_schema_columns'] ?? null)
                ? $column['required_schema_columns']
                : [])
            ->map(static fn (mixed $key): string => trim((string) $key))
            ->filter(static fn (string $key): bool => $key !== '')
            ->unique()
            ->values();

        foreach ($dependencies as $key) {
            if (array_key_exists($key, $row)) {
                continue;
            }

            $total = $isSeason
                ? (float) ($entry->{$key} ?? 0)
                : (float) $playerStats->sum($key);
            $row[$key] = fmod($total, 1.0) === 0.0 ? (int) $total : $total;
        }

        return $row;
    }

    /**
     * Evaluate formulas containing stat keys, numbers, parentheses, and + - * /.
     *
     * @param array<string,mixed> $row
     */
    private function evaluateFormula(string $formula, array $row): float|int
    {
        $expression = preg_replace_callback(
            '/\b[a-z][a-z0-9_]*\b/i',
            static function (array $matches) use ($row): string {
                $key = strtolower($matches[0]);
                $value = $row[$key] ?? 0;

                return is_numeric($value) ? (string) ((float) $value) : '0';
            },
            strtolower($formula),
        ) ?? '0';

        if (preg_match('/[^0-9+\-*\/().\s]/', $expression) === 1) {
            return 0;
        }

        try {
            $value = eval('return ' . $expression . ';');
        } catch (\Throwable) {
            return 0;
        }

        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return 0;
        }

        $number = round((float) $value, 4);

        return fmod($number, 1.0) === 0.0 ? (int) $number : $number;
    }

    /**
     * @param Collection<int,object> $playerStats
     */
    private function collectionToiSeconds(Collection $playerStats): int
    {
        if (($sum = (int) $playerStats->sum('toi_seconds')) > 0) {
            return $sum;
        }
        if (($sum = (float) $playerStats->sum('toi_minutes')) > 0) {
            return (int) (($sum > 4000) ? $sum : $sum * 60.0);
        }
        if (($sum = (float) $playerStats->sum('toi')) > 0) {
            return (int) (($sum > 4000) ? $sum : $sum * 60.0);
        }

        return 0;
    }

    /**
     * @param Collection<int,object> $collection
     * @param array<string,mixed> $filters
     * @return Collection<int,int>
     */
    private function officialBoxscoreToiByPlayer(Collection $collection, string $mode, array $filters): Collection
    {
        if ($mode === 'prospects' || $collection->isEmpty()) {
            return collect();
        }

        $playerIds = $collection
            ->map(function (object $row): int {
                if (isset($row->nhl_player_id)) {
                    return (int) $row->nhl_player_id;
                }

                $player = $row->player ?? null;

                return (int) ($player?->nhl_id ?? 0);
            })
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($playerIds->isEmpty()) {
            return collect();
        }

        $query = DB::table('nhl_boxscores as b')
            ->join('nhl_games as g', 'g.nhl_game_id', '=', 'b.nhl_game_id')
            ->whereIn('b.nhl_player_id', $playerIds->all())
            ->select('b.nhl_player_id', 'b.toi_seconds', 'b.toi');

        if (! empty($filters['season_id'])) {
            $query->where('g.season_id', (string) $filters['season_id']);
        }
        if (! empty($filters['game_type'])) {
            $query->where('g.game_type', (int) $filters['game_type']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('g.game_date', '>=', (string) $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('g.game_date', '<=', (string) $filters['date_to']);
        }

        return $query->get()
            ->groupBy(fn (object $row): int => (int) $row->nhl_player_id)
            ->map(fn (Collection $rows): int => (int) $rows->sum(
                fn (object $row): int => $this->boxscoreToiSeconds($row->toi_seconds, $row->toi)
            ));
    }

    private function boxscoreToiSeconds(mixed $seconds, mixed $toi): int
    {
        if (is_numeric($seconds) && (int) $seconds > 0) {
            return (int) $seconds;
        }
        if (! is_string($toi) || ! str_contains($toi, ':')) {
            return 0;
        }

        [$minutes, $remainingSeconds] = array_pad(explode(':', $toi, 2), 2, '0');

        return ((int) $minutes * 60) + (int) $remainingSeconds;
    }

    /**
     * @param array<string,mixed> $row
     * @param Collection<int,object> $playerStats
     * @return array<string,mixed>
     */
    private function withNativeFantasyAliases(
        array $row,
        Collection $playerStats,
        int $gamesPlayed,
        int $toiSeconds,
        bool $isSeason,
        ?object $seasonEntry = null
    ): array {
        $total = function (string $key) use ($playerStats, $isSeason, $seasonEntry): float {
            if ($isSeason) {
                return (float) ($seasonEntry->{$key} ?? 0);
            }

            return (float) $playerStats->sum($key);
        };
        $totalFirstAvailable = function (array $keys) use ($playerStats, $isSeason, $seasonEntry): float {
            if ($isSeason) {
                foreach ($keys as $key) {
                    if (isset($seasonEntry->{$key}) && is_numeric($seasonEntry->{$key})) {
                        return (float) $seasonEntry->{$key};
                    }
                }

                return 0.0;
            }

            foreach ($keys as $key) {
                $value = (float) $playerStats->sum($key);

                if ($value !== 0.0) {
                    return $value;
                }
            }

            return 0.0;
        };

        $sog = $total('sog');
        $sat = $total('sat');
        $hits = $total('h');
        $blocks = $total('b');
        $shotsAgainst = $totalFirstAvailable(['sa', 'shots_against']);
        $goalsAgainst = $totalFirstAvailable(['ga', 'goals_against']);
        $saves = $totalFirstAvailable(['sv', 'saves']);
        if ($saves <= 0 && $shotsAgainst > 0) {
            $saves = max(0, $shotsAgainst - $goalsAgainst);
        }
        $evShotsAgainst = $total('evsa');
        $evSaves = $total('evsv');
        $ppShotsAgainst = $total('ppsa');
        $ppSaves = $total('ppsv');
        $pkShotsAgainst = $total('pksa');
        $pkSaves = $total('pksv');

        return array_merge($row, [
            'g_per_gp' => $this->perGameAlias($total('g'), $gamesPlayed),
            'a_per_gp' => $this->perGameAlias($total('a'), $gamesPlayed),
            'pts_per_gp' => $this->perGameAlias($total('pts'), $gamesPlayed),
            'sog_per_gp' => $this->perGameAlias($sog, $gamesPlayed),
            'sat_per_gp' => $this->perGameAlias($sat, $gamesPlayed),
            'hits_per_gp' => $this->perGameAlias($hits, $gamesPlayed),
            'blocks_per_gp' => $this->perGameAlias($blocks, $gamesPlayed),
            'fow_per_gp' => $this->perGameAlias($total('fow'), $gamesPlayed),
            'saves_per_gp' => $this->perGameAlias($saves, $gamesPlayed),
            'shots_against_per_gp' => $this->perGameAlias($shotsAgainst, $gamesPlayed),
            'ga_per_gp' => $this->perGameAlias($goalsAgainst, $gamesPlayed),
            'sog_per_60' => $this->per60Alias($sog, $toiSeconds),
            'sat_per_60' => $this->per60Alias($sat, $toiSeconds),
            'hits_per_60' => $this->per60Alias($hits, $toiSeconds),
            'blocks_per_60' => $this->per60Alias($blocks, $toiSeconds),
            'a1_per_60' => $this->per60Alias($total('a1'), $toiSeconds),
            'a2_per_60' => $this->per60Alias($total('a2'), $toiSeconds),
            'shots_plus_blocks' => (int) ($sog + $blocks),
            'hits_plus_blocks' => (int) ($hits + $blocks),
            'saves' => (int) $saves,
            'shots_against' => (int) $shotsAgainst,
            'goals_against' => (int) $goalsAgainst,
            'sv_pct' => $shotsAgainst > 0 ? round($saves / $shotsAgainst, 3) : (float) ($row['sv_pct'] ?? 0),
            'gaa' => $toiSeconds > 0 ? round(($goalsAgainst * 3600) / $toiSeconds, 3) : (float) ($row['gaa'] ?? 0),
            'ev_sv_pct' => $evShotsAgainst > 0 ? round($evSaves / $evShotsAgainst, 3) : (float) ($row['ev_sv_pct'] ?? 0),
            'pp_sv_pct' => $ppShotsAgainst > 0 ? round($ppSaves / $ppShotsAgainst, 3) : (float) ($row['pp_sv_pct'] ?? 0),
            'pk_sv_pct' => $pkShotsAgainst > 0 ? round($pkSaves / $pkShotsAgainst, 3) : (float) ($row['pk_sv_pct'] ?? 0),
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $columns
     */
    private function columnsNeedOnIce(array $columns): bool
    {
        $onIceKeys = [
            'ipp', 'individual_g', 'individual_a', 'individual_pts',
            'gf', 'ga', 'gf_pct', 'cf', 'ca', 'cf_pct', 'ff', 'fa', 'ff_pct',
            'sf', 'sa', 'sf_pct', 'pdo', 'on_ice_shooting_percentage',
            'on_ice_save_percentage', 'ozs_pct', 'dzs_pct',
        ];

        return collect($columns)
            ->filter(fn (array $column): bool => $this->columnRequestsOnIceStats($column, $onIceKeys))
            ->isNotEmpty();
    }

    /**
     * @param array<string,mixed> $column
     * @param array<int,string> $onIceKeys
     */
    private function columnRequestsOnIceStats(array $column, array $onIceKeys): bool
    {
        $candidateKeys = collect([(string) ($column['key'] ?? '')])
            ->merge(is_array($column['required_schema_columns'] ?? null) ? $column['required_schema_columns'] : [])
            ->merge($this->formulaIdentifiers((string) ($column['formula'] ?? '')))
            ->map(static fn (mixed $key): string => trim(strtolower((string) $key)))
            ->filter(static fn (string $key): bool => $key !== '')
            ->unique();

        if ($candidateKeys->intersect($onIceKeys)->isEmpty()) {
            return false;
        }

        if (! (bool) ($column['fantasy_scoring_category'] ?? false)) {
            return true;
        }

        $group = strtoupper((string) (
            $column['normalized_group']
            ?? $column['provider_group']
            ?? $column['group']
            ?? ''
        ));

        return ! str_contains($group, 'GOALIE');
    }

    /**
     * @return array<int,string>
     */
    private function formulaIdentifiers(string $formula): array
    {
        if ($formula === '') {
            return [];
        }

        preg_match_all('/\b[a-z][a-z0-9_]*\b/i', $formula, $matches);

        return array_values(array_unique(array_map(
            static fn (string $key): string => strtolower($key),
            $matches[0] ?? [],
        )));
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<int,int> $playerIds
     * @return Collection<int,object>
     */
    private function nativeOnIceTotals(array $filters, array $playerIds): Collection
    {
        $query = DB::table('nhl_player_game_strength_summaries as s')
            ->join('nhl_games as g', 'g.nhl_game_id', '=', 's.nhl_game_id')
            ->whereIn('s.nhl_player_id', $playerIds)
            ->groupBy('s.nhl_player_id')
            ->selectRaw(<<<'SQL'
                s.nhl_player_id,
                COUNT(DISTINCT s.nhl_game_id) as gp,
                SUM(s.toi) as toi,
                SUM(s.gf) as gf,
                SUM(s.ga) as ga,
                SUM(s.sf) as sf,
                SUM(s.sa) as sa,
                SUM(s.satf) as satf,
                SUM(s.sata) as sata,
                SUM(s.ff) as ff,
                SUM(s.fa) as fa,
                SUM(s.ozs) as ozs,
                SUM(s.dzs) as dzs,
                SUM(s.individual_g) as individual_g,
                SUM(s.individual_a) as individual_a,
                SUM(s.individual_pts) as individual_pts
            SQL);

        if (! empty($filters['season_id'])) {
            $query->where('g.season_id', (string) $filters['season_id']);
        }
        if (! empty($filters['game_type'])) {
            $query->where('g.game_type', (int) $filters['game_type']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('g.game_date', '>=', (string) $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('g.game_date', '<=', (string) $filters['date_to']);
        }

        return $query->get()->keyBy(fn (object $row): int => (int) $row->nhl_player_id);
    }

    /**
     * @return array<string,float|int>
     */
    private function nativeOnIceAliases(object $row): array
    {
        $gf = (float) ($row->gf ?? 0);
        $ga = (float) ($row->ga ?? 0);
        $sf = (float) ($row->sf ?? 0);
        $sa = (float) ($row->sa ?? 0);
        $cf = (float) ($row->satf ?? 0);
        $ca = (float) ($row->sata ?? 0);
        $ff = (float) ($row->ff ?? 0);
        $fa = (float) ($row->fa ?? 0);
        $ozs = (float) ($row->ozs ?? 0);
        $dzs = (float) ($row->dzs ?? 0);
        $toi = (int) ($row->toi ?? 0);
        $individualPoints = (float) ($row->individual_pts ?? 0);
        $onIceShooting = $sf > 0 ? round($gf / $sf, 3) : 0.0;
        $onIceSave = $sa > 0 ? round(1 - ($ga / $sa), 3) : 0.0;

        return [
            'individual_g' => (int) ($row->individual_g ?? 0),
            'individual_a' => (int) ($row->individual_a ?? 0),
            'individual_pts' => (int) ($row->individual_pts ?? 0),
            'ipp' => $gf > 0 ? round($individualPoints / $gf, 3) : 0.0,
            'gf' => (int) $gf,
            'ga' => (int) $ga,
            'gf_pct' => $this->ratioAlias($gf, $gf + $ga),
            'sf' => (int) $sf,
            'sa' => (int) $sa,
            'sf_pct' => $this->ratioAlias($sf, $sf + $sa),
            'cf' => (int) $cf,
            'ca' => (int) $ca,
            'cf_pct' => $this->ratioAlias($cf, $cf + $ca),
            'ff' => (int) $ff,
            'fa' => (int) $fa,
            'ff_pct' => $this->ratioAlias($ff, $ff + $fa),
            'gf_per_60' => $this->per60Alias($gf, $toi),
            'ga_per_60' => $this->per60Alias($ga, $toi),
            'sf_per_60' => $this->per60Alias($sf, $toi),
            'sa_per_60' => $this->per60Alias($sa, $toi),
            'cf_per_60' => $this->per60Alias($cf, $toi),
            'ca_per_60' => $this->per60Alias($ca, $toi),
            'ff_per_60' => $this->per60Alias($ff, $toi),
            'fa_per_60' => $this->per60Alias($fa, $toi),
            'on_ice_shooting_percentage' => $onIceShooting,
            'on_ice_save_percentage' => $onIceSave,
            'pdo' => round($onIceShooting + $onIceSave, 3),
            'ozs' => (int) $ozs,
            'dzs' => (int) $dzs,
            'ozs_pct' => $this->ratioAlias($ozs, $ozs + $dzs),
            'dzs_pct' => $this->ratioAlias($dzs, $ozs + $dzs),
        ];
    }

    private function playerAge($player): ?int
    {
        if (! $player) {
            return null;
        }
        if (method_exists($player, 'age')) {
            return $player->age();
        }
        if (! empty($player->dob)) {
            return Carbon::parse($player->dob)->age;
        }

        return null;
    }

    private function formatTimeOnIce(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        return sprintf('%d:%02d', $minutes, $remainingSeconds);
    }

    private function parseContractLastYear(?string $label): ?int
    {
        if (! $label) {
            return null;
        }

        $label = trim($label);
        if (preg_match('/\b(20\d{2})\b/', $label, $matches)) {
            $year = (int) $matches[1];

            if (preg_match('/20(\d{2})\D+(\d{2})\b/', $label, $seasonMatches)) {
                $first = (int) ('20' . $seasonMatches[1]);
                $end = (int) $seasonMatches[2];
                $second = $first >= 2000 ? ($first - 2000) : 0;

                return $second <= 99 ? (int) ('20' . str_pad((string) $end, 2, '0', STR_PAD_LEFT)) : $year;
            }

            return $year;
        }

        return null;
    }

    private function perGameAlias(float $total, int $gamesPlayed): float
    {
        return $gamesPlayed > 0 ? round($total / $gamesPlayed, 3) : 0.0;
    }

    private function per60Alias(float $total, int $toiSeconds): float
    {
        return $toiSeconds > 0 ? round($total / ($toiSeconds / 3600), 3) : 0.0;
    }

    private function ratioAlias(float $numerator, float $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator, 3) : 0.0;
    }
}
