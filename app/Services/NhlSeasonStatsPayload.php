<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the gner8-facing NHL season stats payload.
 */
class NhlSeasonStatsPayload
{
    private const LEAGUE_ABBREV = 'NHL';
    private const SOURCE_SYSTEM = 'dynastyiq';
    private const STAT_GROUPS = ['basic', 'on_ice', 'expected'];
    private const WINDOWS = [5, 10, 20];
    private const WINDOW_KEYS = ['season', 'last_5', 'last_10', 'last_20'];

    /**
     * Return a season-level player stats payload keyed for gner8 ingestion.
     *
     * @return array<string,mixed>
     */
    public function build(
        string $seasonKey,
        int $gameType = 2,
        ?string $statGroup = null,
        ?string $windowKey = null
    ): array {
        $season = $this->season($seasonKey, $gameType);
        $sourceFetchedAt = now()->toIso8601String();
        $requestedStatGroup = $this->validStatGroup($statGroup);
        $requestedWindowKey = $this->validWindowKey($windowKey);
        $allStatTypes = $this->statTypes();
        $statTypes = collect($allStatTypes)
            ->when($requestedStatGroup !== null, fn (Collection $types): Collection => $types
                ->where('stat_group', $requestedStatGroup))
            ->values()
            ->all();
        $statTypeGroups = collect($allStatTypes)->pluck('stat_group', 'slug')->all();

        $playerStats = collect();

        if ($this->shouldIncludeStatGroup('basic', $requestedStatGroup)) {
            if ($this->shouldIncludeWindow('season', $requestedWindowKey)) {
                $playerStats = $playerStats->merge($this->basicSeasonStats(
                    $seasonKey,
                    $gameType,
                    $season,
                    $sourceFetchedAt,
                    $statTypeGroups
                ));
            }

            if ($this->hasRollingWindow($requestedWindowKey)) {
                $playerStats = $playerStats->merge($this->basicWindowStats(
                    $seasonKey,
                    $gameType,
                    $sourceFetchedAt,
                    $statTypeGroups,
                    $requestedWindowKey
                ));
            }
        }

        if ($this->shouldIncludeStatGroup('on_ice', $requestedStatGroup)) {
            if ($this->shouldIncludeWindow('season', $requestedWindowKey)) {
                $playerStats = $playerStats->merge($this->onIceSeasonStats(
                    $seasonKey,
                    $gameType,
                    $season,
                    $sourceFetchedAt,
                    $statTypeGroups
                ));
            }

            if ($this->hasRollingWindow($requestedWindowKey)) {
                $playerStats = $playerStats->merge($this->onIceWindowStats(
                    $seasonKey,
                    $gameType,
                    $sourceFetchedAt,
                    $statTypeGroups,
                    $requestedWindowKey
                ));
            }
        }

        if ($this->shouldIncludeStatGroup('expected', $requestedStatGroup)) {
            if ($this->shouldIncludeWindow('season', $requestedWindowKey)) {
                $playerStats = $playerStats->merge($this->expectedSeasonStats(
                    $seasonKey,
                    $gameType,
                    $season,
                    $sourceFetchedAt,
                    $statTypeGroups
                ));
            }

            if ($this->hasRollingWindow($requestedWindowKey)) {
                $playerStats = $playerStats->merge($this->expectedWindowStats(
                    $seasonKey,
                    $gameType,
                    $sourceFetchedAt,
                    $statTypeGroups,
                    $requestedWindowKey
                ));
            }
        }

        $features = $this->shouldIncludeFeatures($requestedStatGroup, $requestedWindowKey)
            ? $this->expectedFeatures($seasonKey, $gameType, $sourceFetchedAt, $statTypeGroups)
            : collect();

        return [
            'league_abbrev' => self::LEAGUE_ABBREV,
            'season' => $season,
            'stat_types' => $statTypes,
            'player_stats' => $playerStats->values(),
            'player_stat_features' => $features,
            'meta' => [
                'source_system' => self::SOURCE_SYSTEM,
                'source_fetched_at' => $sourceFetchedAt,
                'season_key' => $seasonKey,
                'game_type' => $gameType,
                'stat_group' => $requestedStatGroup,
                'window_key' => $requestedWindowKey,
            ],
        ];
    }

    /**
     * Return a supported stat group filter.
     */
    private function validStatGroup(?string $statGroup): ?string
    {
        return in_array($statGroup, self::STAT_GROUPS, true) ? $statGroup : null;
    }

    /**
     * Return a supported window filter.
     */
    private function validWindowKey(?string $windowKey): ?string
    {
        return in_array($windowKey, self::WINDOW_KEYS, true) ? $windowKey : null;
    }

    /**
     * Determine whether a stat group should be emitted.
     */
    private function shouldIncludeStatGroup(string $statGroup, ?string $requestedStatGroup): bool
    {
        return $requestedStatGroup === null || $requestedStatGroup === $statGroup;
    }

    /**
     * Determine whether a window should be emitted.
     */
    private function shouldIncludeWindow(string $windowKey, ?string $requestedWindowKey): bool
    {
        return $requestedWindowKey === null || $requestedWindowKey === $windowKey;
    }

    /**
     * Determine whether rolling-window rows are needed.
     */
    private function hasRollingWindow(?string $requestedWindowKey): bool
    {
        return $requestedWindowKey === null || str_starts_with($requestedWindowKey, 'last_');
    }

    /**
     * @return array<int,int>
     */
    private function rollingWindows(?string $requestedWindowKey): array
    {
        if ($requestedWindowKey === null) {
            return self::WINDOWS;
        }

        if (! str_starts_with($requestedWindowKey, 'last_')) {
            return [];
        }

        $window = (int) substr($requestedWindowKey, 5);

        return in_array($window, self::WINDOWS, true) ? [$window] : [];
    }

    /**
     * Determine whether derived comparison features are needed.
     */
    private function shouldIncludeFeatures(?string $requestedStatGroup, ?string $requestedWindowKey): bool
    {
        return $this->shouldIncludeStatGroup('expected', $requestedStatGroup)
            && $this->shouldIncludeWindow('last_10', $requestedWindowKey);
    }

    /**
     * @return array<string,mixed>
     */
    private function season(string $seasonKey, int $gameType): array
    {
        $row = DB::table('nhl_games')
            ->where('season_id', $seasonKey)
            ->where('game_type', $gameType)
            ->selectRaw('MIN(game_date) as starts_on')
            ->selectRaw('MAX(game_date) as ends_on')
            ->first();

        return [
            'league_abbrev' => self::LEAGUE_ABBREV,
            'season_key' => $seasonKey,
            'label' => substr($seasonKey, 0, 4) . '-' . substr($seasonKey, 6, 2),
            'starts_on' => $row->starts_on ?? null,
            'ends_on' => $row->ends_on ?? null,
            'current' => $seasonKey === $this->currentSeasonKey(),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function statTypes(): array
    {
        return [
            $this->statType('games_played', 'Games Played', 'basic', 'integer', 'games', true, false, true),
            $this->statType('goals', 'Goals', 'basic', 'integer', 'goals', true, true, true),
            $this->statType('assists', 'Assists', 'basic', 'integer', 'assists', true, true, true),
            $this->statType('points', 'Points', 'basic', 'integer', 'points', true, true, true),
            $this->statType('shots_on_goal', 'Shots On Goal', 'basic', 'integer', 'shots', true, true, true),
            $this->statType('sat', 'Shot Attempts', 'basic', 'integer', 'attempts', true, true, true),
            $this->statType('toi_seconds', 'TOI Seconds', 'basic', 'integer', 'seconds', false, false, true),
            $this->statType('on_ice_toi_seconds', 'On-Ice TOI Seconds', 'on_ice', 'integer', 'seconds', false, false, true),
            $this->statType('on_ice_gf', 'On-Ice Goals For', 'on_ice', 'integer', 'goals', true, true, true),
            $this->statType('on_ice_ga', 'On-Ice Goals Against', 'on_ice', 'integer', 'goals', true, true, false),
            $this->statType('on_ice_sf', 'On-Ice Shots For', 'on_ice', 'integer', 'shots', true, true, true),
            $this->statType('on_ice_sa', 'On-Ice Shots Against', 'on_ice', 'integer', 'shots', true, true, false),
            $this->statType('on_ice_satf', 'On-Ice SAT For', 'on_ice', 'integer', 'attempts', true, true, true),
            $this->statType('on_ice_sata', 'On-Ice SAT Against', 'on_ice', 'integer', 'attempts', true, true, false),
            $this->statType('ixg', 'Individual xG', 'expected', 'decimal', 'goals', true, true, true),
            $this->statType('xsog', 'Expected Shots On Goal', 'expected', 'decimal', 'shots', true, true, true),
            $this->statType('xg_per_sat', 'xG / SAT', 'expected', 'percentage', 'percent', false, false, true),
            $this->statType('xsog_per_sat', 'xSOG / SAT', 'expected', 'percentage', 'percent', false, false, true),
            $this->statType('sog_minus_xsog', 'SOG - xSOG', 'expected', 'decimal', 'shots', false, false, true),
            $this->statType('goals_minus_ixg', 'Goals - ixG', 'expected', 'decimal', 'goals', false, false, true),
            $this->statType('ixg_share', 'ixG%', 'expected', 'percentage', 'percent', false, false, true),
            $this->statType('on_ice_xgf', 'On-Ice xGF', 'expected', 'decimal', 'goals', true, true, true),
            $this->statType('on_ice_xga', 'On-Ice xGA', 'expected', 'decimal', 'goals', true, true, false),
            $this->statType('on_ice_xg_pct', 'On-Ice xG%', 'expected', 'percentage', 'percent', false, false, true),
            $this->statType('on_ice_xg_diff', 'On-Ice xG Diff', 'expected', 'decimal', 'goals', false, false, true),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function statType(
        string $slug,
        string $name,
        string $group,
        string $valueType,
        ?string $unit,
        bool $supportsPerGame,
        bool $supportsPer60,
        ?bool $higherIsBetter
    ): array {
        return [
            'league_abbrev' => self::LEAGUE_ABBREV,
            'slug' => $slug,
            'name' => $name,
            'stat_group' => $group,
            'value_type' => $valueType,
            'unit' => $unit,
            'supports_per_game' => $supportsPerGame,
            'supports_per_60' => $supportsPer60,
            'higher_is_better' => $higherIsBetter,
            'active' => true,
            'metadata' => [],
        ];
    }

    /**
     * @param array<string,string> $statTypeGroups
     * @return Collection<int,array<string,mixed>>
     */
    private function basicSeasonStats(
        string $seasonKey,
        int $gameType,
        array $season,
        string $sourceFetchedAt,
        array $statTypeGroups
    ): Collection {
        if (! Schema::hasTable('nhl_season_stats')) {
            return collect();
        }

        $stats = [
            'games_played' => 'gp',
            'goals' => 'g',
            'assists' => 'a',
            'points' => 'pts',
            'shots_on_goal' => 'sog',
            'sat' => 'sat',
            'toi_seconds' => 'toi',
        ];

        return DB::table('nhl_season_stats')
            ->where('season_id', $seasonKey)
            ->where('game_type', $gameType)
            ->select(['nhl_player_id', 'nhl_team_id', 'gp', 'g', 'a', 'pts', 'sog', 'sat', 'toi'])
            ->get()
            ->flatMap(function (object $row) use ($stats, $seasonKey, $season, $sourceFetchedAt, $statTypeGroups): array {
                return collect($stats)
                    ->map(fn (string $column, string $slug): array => $this->playerStatRow(
                        seasonKey: $seasonKey,
                        nhlPlayerId: (int) $row->nhl_player_id,
                        statSlug: $slug,
                        statGroup: $statTypeGroups[$slug] ?? 'basic',
                        windowKey: 'season',
                        windowGames: (int) $row->gp,
                        startDate: $season['starts_on'] ?? null,
                        endDate: $season['ends_on'] ?? null,
                        value: $row->{$column},
                        sourceFetchedAt: $sourceFetchedAt,
                        metadata: ['nhl_team_id' => (int) $row->nhl_team_id]
                    ))
                    ->values()
                    ->all();
            });
    }

    /**
     * @param array<string,string> $statTypeGroups
     * @return Collection<int,array<string,mixed>>
     */
    private function basicWindowStats(
        string $seasonKey,
        int $gameType,
        string $sourceFetchedAt,
        array $statTypeGroups,
        ?string $windowKey = null
    ): Collection {
        if (! Schema::hasTable('nhl_game_summaries')) {
            return collect();
        }

        $stats = [
            'games_played' => 'gp',
            'goals' => 'g',
            'assists' => 'a',
            'points' => 'pts',
            'shots_on_goal' => 'sog',
            'sat' => 'sat',
            'toi_seconds' => 'toi',
        ];

        return $this->basicWindowRows($seasonKey, $gameType)
            ->flatMap(function (object $row) use ($stats, $seasonKey, $sourceFetchedAt, $statTypeGroups, $windowKey): array {
                $rows = [];

                foreach ($this->rollingWindows($windowKey) as $windowGames) {
                    $actualGames = (int) ($row->{'gp_' . $windowGames} ?? 0);

                    if ($actualGames === 0) {
                        continue;
                    }

                    foreach ($stats as $slug => $column) {
                        $rows[] = $this->playerStatRow(
                            seasonKey: $seasonKey,
                            nhlPlayerId: (int) $row->nhl_player_id,
                            statSlug: $slug,
                            statGroup: $statTypeGroups[$slug] ?? 'basic',
                            windowKey: 'last_' . $windowGames,
                            windowGames: $actualGames,
                            startDate: $row->{'start_date_' . $windowGames} ?? null,
                            endDate: $row->{'end_date_' . $windowGames} ?? null,
                            value: $slug === 'games_played' ? $actualGames : $row->{$column . '_' . $windowGames},
                            sourceFetchedAt: $sourceFetchedAt,
                            metadata: ['nhl_team_id' => $row->nhl_team_id !== null ? (int) $row->nhl_team_id : null]
                        );
                    }
                }

                return $rows;
            });
    }

    /**
     * @return Collection<int,object>
     */
    private function basicWindowRows(string $seasonKey, int $gameType): Collection
    {
        $sql = <<<SQL
WITH player_games AS (
    SELECT
        summaries.nhl_player_id,
        MAX(summaries.nhl_team_id) as nhl_team_id,
        games.game_date,
        SUM(summaries.g) as g,
        SUM(summaries.a) as a,
        SUM(summaries.pts) as pts,
        SUM(summaries.sog) as sog,
        SUM(summaries.sat) as sat,
        SUM(summaries.toi) as toi
    FROM nhl_game_summaries summaries
    INNER JOIN nhl_games games ON games.nhl_game_id = summaries.nhl_game_id
    INNER JOIN players ON players.nhl_id = summaries.nhl_player_id
    WHERE games.season_id = ?
        AND games.game_type = ?
        AND (
            players.position IS DISTINCT FROM 'G'
            OR COALESCE(summaries.goalie_started, false) IS TRUE
            OR COALESCE(summaries.quality_start, false) IS TRUE
            OR COALESCE(summaries.really_bad_start, false) IS TRUE
            OR summaries.goalie_decision IS NOT NULL
            OR COALESCE(summaries.sa, 0) > 0
            OR COALESCE(summaries.sv, 0) > 0
            OR COALESCE(summaries.ga, 0) > 0
            OR COALESCE(summaries.evsa, 0) > 0
            OR COALESCE(summaries.evsv, 0) > 0
            OR COALESCE(summaries.evga, 0) > 0
            OR COALESCE(summaries.ppsa, 0) > 0
            OR COALESCE(summaries.ppsv, 0) > 0
            OR COALESCE(summaries.ppga, 0) > 0
            OR COALESCE(summaries.pksa, 0) > 0
            OR COALESCE(summaries.pksv, 0) > 0
            OR COALESCE(summaries.pkga, 0) > 0
            OR COALESCE(summaries.shosv, 0) > 0
            OR COALESCE(summaries.so, 0) > 0
        )
    GROUP BY summaries.nhl_player_id, games.game_date
),
ranked_games AS (
    SELECT
        player_games.*,
        ROW_NUMBER() OVER (PARTITION BY player_games.nhl_player_id ORDER BY player_games.game_date DESC) as recent_rank
    FROM player_games
)
SELECT
    nhl_player_id,
    MAX(nhl_team_id) as nhl_team_id,
    COUNT(*) FILTER (WHERE recent_rank <= 5) as gp_5,
    MIN(game_date) FILTER (WHERE recent_rank <= 5) as start_date_5,
    MAX(game_date) FILTER (WHERE recent_rank <= 5) as end_date_5,
    SUM(g) FILTER (WHERE recent_rank <= 5) as g_5,
    SUM(a) FILTER (WHERE recent_rank <= 5) as a_5,
    SUM(pts) FILTER (WHERE recent_rank <= 5) as pts_5,
    SUM(sog) FILTER (WHERE recent_rank <= 5) as sog_5,
    SUM(sat) FILTER (WHERE recent_rank <= 5) as sat_5,
    SUM(toi) FILTER (WHERE recent_rank <= 5) as toi_5,
    COUNT(*) FILTER (WHERE recent_rank <= 10) as gp_10,
    MIN(game_date) FILTER (WHERE recent_rank <= 10) as start_date_10,
    MAX(game_date) FILTER (WHERE recent_rank <= 10) as end_date_10,
    SUM(g) FILTER (WHERE recent_rank <= 10) as g_10,
    SUM(a) FILTER (WHERE recent_rank <= 10) as a_10,
    SUM(pts) FILTER (WHERE recent_rank <= 10) as pts_10,
    SUM(sog) FILTER (WHERE recent_rank <= 10) as sog_10,
    SUM(sat) FILTER (WHERE recent_rank <= 10) as sat_10,
    SUM(toi) FILTER (WHERE recent_rank <= 10) as toi_10,
    COUNT(*) FILTER (WHERE recent_rank <= 20) as gp_20,
    MIN(game_date) FILTER (WHERE recent_rank <= 20) as start_date_20,
    MAX(game_date) FILTER (WHERE recent_rank <= 20) as end_date_20,
    SUM(g) FILTER (WHERE recent_rank <= 20) as g_20,
    SUM(a) FILTER (WHERE recent_rank <= 20) as a_20,
    SUM(pts) FILTER (WHERE recent_rank <= 20) as pts_20,
    SUM(sog) FILTER (WHERE recent_rank <= 20) as sog_20,
    SUM(sat) FILTER (WHERE recent_rank <= 20) as sat_20,
    SUM(toi) FILTER (WHERE recent_rank <= 20) as toi_20
FROM ranked_games
GROUP BY nhl_player_id
SQL;

        return collect(DB::select($sql, [$seasonKey, $gameType]));
    }

    /**
     * @param array<string,string> $statTypeGroups
     * @return Collection<int,array<string,mixed>>
     */
    private function onIceSeasonStats(
        string $seasonKey,
        int $gameType,
        array $season,
        string $sourceFetchedAt,
        array $statTypeGroups
    ): Collection {
        if (! Schema::hasTable('nhl_player_game_strength_summaries')) {
            return collect();
        }

        $stats = [
            'on_ice_toi_seconds' => 'toi',
            'on_ice_gf' => 'gf',
            'on_ice_ga' => 'ga',
            'on_ice_sf' => 'sf',
            'on_ice_sa' => 'sa',
            'on_ice_satf' => 'satf',
            'on_ice_sata' => 'sata',
        ];

        return DB::table('nhl_player_game_strength_summaries as summaries')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'summaries.nhl_game_id')
            ->where('games.season_id', $seasonKey)
            ->where('games.game_type', $gameType)
            ->groupBy('summaries.nhl_player_id')
            ->select('summaries.nhl_player_id')
            ->selectRaw('COUNT(DISTINCT summaries.nhl_game_id) as window_games')
            ->selectRaw('SUM(summaries.toi) as toi')
            ->selectRaw('SUM(summaries.gf) as gf')
            ->selectRaw('SUM(summaries.ga) as ga')
            ->selectRaw('SUM(summaries.sf) as sf')
            ->selectRaw('SUM(summaries.sa) as sa')
            ->selectRaw('SUM(summaries.satf) as satf')
            ->selectRaw('SUM(summaries.sata) as sata')
            ->get()
            ->flatMap(function (object $row) use ($stats, $seasonKey, $season, $sourceFetchedAt, $statTypeGroups): array {
                return collect($stats)
                    ->map(fn (string $column, string $slug): array => $this->playerStatRow(
                        seasonKey: $seasonKey,
                        nhlPlayerId: (int) $row->nhl_player_id,
                        statSlug: $slug,
                        statGroup: $statTypeGroups[$slug] ?? 'on_ice',
                        windowKey: 'season',
                        windowGames: (int) $row->window_games,
                        startDate: $season['starts_on'] ?? null,
                        endDate: $season['ends_on'] ?? null,
                        value: $row->{$column},
                        sourceFetchedAt: $sourceFetchedAt
                    ))
                    ->values()
                    ->all();
            });
    }

    /**
     * @param array<string,string> $statTypeGroups
     * @return Collection<int,array<string,mixed>>
     */
    private function onIceWindowStats(
        string $seasonKey,
        int $gameType,
        string $sourceFetchedAt,
        array $statTypeGroups,
        ?string $windowKey = null
    ): Collection {
        if (! Schema::hasTable('nhl_player_game_strength_summaries')) {
            return collect();
        }

        $stats = [
            'on_ice_toi_seconds' => 'toi',
            'on_ice_gf' => 'gf',
            'on_ice_ga' => 'ga',
            'on_ice_sf' => 'sf',
            'on_ice_sa' => 'sa',
            'on_ice_satf' => 'satf',
            'on_ice_sata' => 'sata',
        ];

        return $this->onIceWindowRows($seasonKey, $gameType)
            ->flatMap(function (object $row) use ($stats, $seasonKey, $sourceFetchedAt, $statTypeGroups, $windowKey): array {
                $rows = [];

                foreach ($this->rollingWindows($windowKey) as $windowGames) {
                    $actualGames = (int) ($row->{'window_games_' . $windowGames} ?? 0);

                    if ($actualGames === 0) {
                        continue;
                    }

                    foreach ($stats as $slug => $column) {
                        $rows[] = $this->playerStatRow(
                            seasonKey: $seasonKey,
                            nhlPlayerId: (int) $row->nhl_player_id,
                            statSlug: $slug,
                            statGroup: $statTypeGroups[$slug] ?? 'on_ice',
                            windowKey: 'last_' . $windowGames,
                            windowGames: $actualGames,
                            startDate: $row->{'start_date_' . $windowGames} ?? null,
                            endDate: $row->{'end_date_' . $windowGames} ?? null,
                            value: $row->{$column . '_' . $windowGames},
                            sourceFetchedAt: $sourceFetchedAt
                        );
                    }
                }

                return $rows;
            });
    }

    /**
     * @return Collection<int,object>
     */
    private function onIceWindowRows(string $seasonKey, int $gameType): Collection
    {
        $sql = <<<SQL
WITH player_games AS (
    SELECT
        summaries.nhl_player_id,
        games.game_date,
        SUM(summaries.toi) as toi,
        SUM(summaries.gf) as gf,
        SUM(summaries.ga) as ga,
        SUM(summaries.sf) as sf,
        SUM(summaries.sa) as sa,
        SUM(summaries.satf) as satf,
        SUM(summaries.sata) as sata
    FROM nhl_player_game_strength_summaries summaries
    INNER JOIN nhl_games games ON games.nhl_game_id = summaries.nhl_game_id
    WHERE games.season_id = ?
        AND games.game_type = ?
    GROUP BY summaries.nhl_player_id, games.game_date
),
ranked_games AS (
    SELECT
        player_games.*,
        ROW_NUMBER() OVER (PARTITION BY player_games.nhl_player_id ORDER BY player_games.game_date DESC) as recent_rank
    FROM player_games
)
SELECT
    nhl_player_id,
    COUNT(*) FILTER (WHERE recent_rank <= 5) as window_games_5,
    MIN(game_date) FILTER (WHERE recent_rank <= 5) as start_date_5,
    MAX(game_date) FILTER (WHERE recent_rank <= 5) as end_date_5,
    SUM(toi) FILTER (WHERE recent_rank <= 5) as toi_5,
    SUM(gf) FILTER (WHERE recent_rank <= 5) as gf_5,
    SUM(ga) FILTER (WHERE recent_rank <= 5) as ga_5,
    SUM(sf) FILTER (WHERE recent_rank <= 5) as sf_5,
    SUM(sa) FILTER (WHERE recent_rank <= 5) as sa_5,
    SUM(satf) FILTER (WHERE recent_rank <= 5) as satf_5,
    SUM(sata) FILTER (WHERE recent_rank <= 5) as sata_5,
    COUNT(*) FILTER (WHERE recent_rank <= 10) as window_games_10,
    MIN(game_date) FILTER (WHERE recent_rank <= 10) as start_date_10,
    MAX(game_date) FILTER (WHERE recent_rank <= 10) as end_date_10,
    SUM(toi) FILTER (WHERE recent_rank <= 10) as toi_10,
    SUM(gf) FILTER (WHERE recent_rank <= 10) as gf_10,
    SUM(ga) FILTER (WHERE recent_rank <= 10) as ga_10,
    SUM(sf) FILTER (WHERE recent_rank <= 10) as sf_10,
    SUM(sa) FILTER (WHERE recent_rank <= 10) as sa_10,
    SUM(satf) FILTER (WHERE recent_rank <= 10) as satf_10,
    SUM(sata) FILTER (WHERE recent_rank <= 10) as sata_10,
    COUNT(*) FILTER (WHERE recent_rank <= 20) as window_games_20,
    MIN(game_date) FILTER (WHERE recent_rank <= 20) as start_date_20,
    MAX(game_date) FILTER (WHERE recent_rank <= 20) as end_date_20,
    SUM(toi) FILTER (WHERE recent_rank <= 20) as toi_20,
    SUM(gf) FILTER (WHERE recent_rank <= 20) as gf_20,
    SUM(ga) FILTER (WHERE recent_rank <= 20) as ga_20,
    SUM(sf) FILTER (WHERE recent_rank <= 20) as sf_20,
    SUM(sa) FILTER (WHERE recent_rank <= 20) as sa_20,
    SUM(satf) FILTER (WHERE recent_rank <= 20) as satf_20,
    SUM(sata) FILTER (WHERE recent_rank <= 20) as sata_20
FROM ranked_games
GROUP BY nhl_player_id
SQL;

        return collect(DB::select($sql, [$seasonKey, $gameType]));
    }

    /**
     * @param array<string,string> $statTypeGroups
     * @return Collection<int,array<string,mixed>>
     */
    private function expectedSeasonStats(
        string $seasonKey,
        int $gameType,
        array $season,
        string $sourceFetchedAt,
        array $statTypeGroups
    ): Collection {
        $models = $this->latestModelIds($seasonKey);

        if ($models['goal'] === null || $models['shot_on_goal'] === null) {
            return collect();
        }

        return $this->expectedShooterRows($seasonKey, $gameType, $models)
            ->flatMap(function (object $row) use ($seasonKey, $season, $sourceFetchedAt, $statTypeGroups): array {
                $stats = [
                    'ixg' => $row->ixg,
                    'xsog' => $row->xsog,
                    'xg_per_sat' => $row->xg_per_sat,
                    'xsog_per_sat' => $row->xsog_per_sat,
                    'sog_minus_xsog' => $row->sog_minus_xsog,
                    'goals_minus_ixg' => $row->goals_minus_ixg,
                    'ixg_share' => $row->ixg_share,
                ];

                return collect($stats)
                    ->map(fn (mixed $value, string $slug): array => $this->playerStatRow(
                        seasonKey: $seasonKey,
                        nhlPlayerId: (int) $row->nhl_player_id,
                        statSlug: $slug,
                        statGroup: $statTypeGroups[$slug] ?? 'expected',
                        windowKey: 'season',
                        windowGames: (int) $row->window_games,
                        startDate: $season['starts_on'] ?? null,
                        endDate: $season['ends_on'] ?? null,
                        value: $value,
                        sourceFetchedAt: $sourceFetchedAt,
                        metadata: ['nhl_team_id' => $row->team_id !== null ? (int) $row->team_id : null]
                    ))
                    ->values()
                    ->all();
            })
            ->merge($this->onIceExpectedStats($seasonKey, $gameType, $models, $sourceFetchedAt, $statTypeGroups));
    }

    /**
     * @param array{goal:int|null,shot_on_goal:int|null} $models
     * @return Collection<int,object>
     */
    private function expectedShooterRows(string $seasonKey, int $gameType, array $models): Collection
    {
        $sql = <<<SQL
WITH player_totals AS (
    SELECT
        facts.shooter_player_id as nhl_player_id,
        MAX(facts.team_id) as team_id,
        COUNT(DISTINCT facts.nhl_game_id) as window_games,
        COUNT(*) as sat,
        SUM(CASE WHEN facts.is_shot_on_goal THEN 1 ELSE 0 END) as sog,
        SUM(CASE WHEN facts.is_goal THEN 1 ELSE 0 END) as goals,
        SUM(goal_predictions.xg) as ixg,
        SUM(sog_predictions.xg) as xsog
    FROM nhl_shot_attempts_facts facts
    INNER JOIN nhl_games games ON games.nhl_game_id = facts.nhl_game_id
    INNER JOIN nhl_shot_attempt_predictions goal_predictions
        ON goal_predictions.shot_attempt_fact_id = facts.id
        AND goal_predictions.expected_goals_model_id = ?
        AND goal_predictions.prediction_target = ?
        AND goal_predictions.is_scored = true
    INNER JOIN nhl_shot_attempt_predictions sog_predictions
        ON sog_predictions.shot_attempt_fact_id = facts.id
        AND sog_predictions.expected_goals_model_id = ?
        AND sog_predictions.prediction_target = ?
        AND sog_predictions.is_scored = true
    WHERE facts.season_id = ?
        AND games.game_type = ?
        AND facts.shooter_player_id IS NOT NULL
    GROUP BY facts.shooter_player_id
),
team_totals AS (
    SELECT team_id, SUM(ixg) as team_ixg
    FROM player_totals
    GROUP BY team_id
)
SELECT
    player_totals.*,
    CASE WHEN player_totals.sat > 0 THEN player_totals.ixg / player_totals.sat ELSE NULL END as xg_per_sat,
    CASE WHEN player_totals.sat > 0 THEN player_totals.xsog / player_totals.sat ELSE NULL END as xsog_per_sat,
    player_totals.sog - player_totals.xsog as sog_minus_xsog,
    player_totals.goals - player_totals.ixg as goals_minus_ixg,
    CASE WHEN team_totals.team_ixg > 0 THEN player_totals.ixg / team_totals.team_ixg ELSE NULL END as ixg_share
FROM player_totals
LEFT JOIN team_totals ON team_totals.team_id = player_totals.team_id
SQL;

        return collect(DB::select($sql, [
            $models['goal'],
            NhlExpectedGoalsBackfiller::TARGET_GOAL,
            $models['shot_on_goal'],
            NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL,
            $seasonKey,
            $gameType,
        ]));
    }

    /**
     * @param array<string,string> $statTypeGroups
     * @param array{goal:int|null,shot_on_goal:int|null} $models
     * @return Collection<int,array<string,mixed>>
     */
    private function onIceExpectedStats(
        string $seasonKey,
        int $gameType,
        array $models,
        string $sourceFetchedAt,
        array $statTypeGroups
    ): Collection {
        if (! $this->canBuildOnIceExpected()) {
            return collect();
        }

        $sql = <<<SQL
SELECT
    players.nhl_id as nhl_player_id,
    COUNT(DISTINCT facts.nhl_game_id) as window_games,
    SUM(CASE WHEN unit_shifts.team_id = facts.team_id THEN predictions.xg ELSE 0 END) as on_ice_xgf,
    SUM(CASE WHEN unit_shifts.team_id = facts.opponent_team_id THEN predictions.xg ELSE 0 END) as on_ice_xga
FROM nhl_shot_attempt_predictions predictions
INNER JOIN nhl_shot_attempts_facts facts ON facts.id = predictions.shot_attempt_fact_id
INNER JOIN nhl_games games ON games.nhl_game_id = facts.nhl_game_id
INNER JOIN event_unit_shifts event_links ON event_links.event_id = facts.play_by_play_id
INNER JOIN nhl_unit_shifts unit_shifts ON unit_shifts.id = event_links.unit_shift_id
INNER JOIN nhl_unit_shift_players shift_players ON shift_players.unit_shift_id = unit_shifts.id
INNER JOIN players ON players.id = shift_players.player_id
WHERE predictions.expected_goals_model_id = ?
    AND predictions.prediction_target = ?
    AND predictions.is_scored = true
    AND facts.season_id = ?
    AND games.game_type = ?
    AND players.nhl_id IS NOT NULL
GROUP BY players.nhl_id
SQL;

        return collect(DB::select($sql, [
            $models['goal'],
            NhlExpectedGoalsBackfiller::TARGET_GOAL,
            $seasonKey,
            $gameType,
        ]))->flatMap(function (object $row) use ($seasonKey, $sourceFetchedAt, $statTypeGroups): array {
            $xgf = (float) $row->on_ice_xgf;
            $xga = (float) $row->on_ice_xga;
            $stats = [
                'on_ice_xgf' => $xgf,
                'on_ice_xga' => $xga,
                'on_ice_xg_pct' => ($xgf + $xga) > 0 ? $xgf / ($xgf + $xga) : null,
                'on_ice_xg_diff' => $xgf - $xga,
            ];

            return collect($stats)
                ->map(fn (mixed $value, string $slug): array => $this->playerStatRow(
                    seasonKey: $seasonKey,
                    nhlPlayerId: (int) $row->nhl_player_id,
                    statSlug: $slug,
                    statGroup: $statTypeGroups[$slug] ?? 'expected',
                    windowKey: 'season',
                    windowGames: (int) $row->window_games,
                    startDate: null,
                    endDate: null,
                    value: $value,
                    sourceFetchedAt: $sourceFetchedAt
                ))
                ->values()
                ->all();
        });
    }

    /**
     * @param array<string,string> $statTypeGroups
     * @return Collection<int,array<string,mixed>>
     */
    private function expectedWindowStats(
        string $seasonKey,
        int $gameType,
        string $sourceFetchedAt,
        array $statTypeGroups,
        ?string $windowKey = null
    ): Collection {
        $models = $this->latestModelIds($seasonKey);

        if ($models['goal'] === null || $models['shot_on_goal'] === null) {
            return collect();
        }

        return $this->expectedWindowRows($seasonKey, $gameType, $models)
            ->flatMap(function (object $row) use ($seasonKey, $sourceFetchedAt, $statTypeGroups, $windowKey): array {
                $rows = [];

                foreach ($this->rollingWindows($windowKey) as $windowGames) {
                    foreach ([
                        'ixg' => $row->{'ixg_' . $windowGames},
                        'xsog' => $row->{'xsog_' . $windowGames},
                        'xg_per_sat' => $row->{'xg_per_sat_' . $windowGames},
                        'xsog_per_sat' => $row->{'xsog_per_sat_' . $windowGames},
                        'sog_minus_xsog' => $row->{'sog_minus_xsog_' . $windowGames},
                        'goals_minus_ixg' => $row->{'goals_minus_ixg_' . $windowGames},
                        'ixg_share' => $row->{'ixg_share_' . $windowGames},
                    ] as $slug => $value) {
                        $rows[] = $this->playerStatRow(
                            seasonKey: $seasonKey,
                            nhlPlayerId: (int) $row->nhl_player_id,
                            statSlug: $slug,
                            statGroup: $statTypeGroups[$slug] ?? 'expected',
                            windowKey: 'last_' . $windowGames,
                            windowGames: (int) $row->{'window_games_' . $windowGames},
                            startDate: $row->{'start_date_' . $windowGames} ?? null,
                            endDate: $row->{'end_date_' . $windowGames} ?? null,
                            value: $value,
                            sourceFetchedAt: $sourceFetchedAt
                        );
                    }
                }

                return $rows;
            })
            ->merge($this->onIceExpectedWindowStats(
                $seasonKey,
                $gameType,
                $models,
                $sourceFetchedAt,
                $statTypeGroups,
                $windowKey
            ));
    }

    /**
     * @param array{goal:int|null,shot_on_goal:int|null} $models
     * @return Collection<int,object>
     */
    private function expectedWindowRows(string $seasonKey, int $gameType, array $models): Collection
    {
        $sql = <<<SQL
WITH player_games AS (
    SELECT
        facts.shooter_player_id as nhl_player_id,
        MAX(facts.team_id) as team_id,
        facts.game_date,
        COUNT(*) as sat,
        SUM(CASE WHEN facts.is_shot_on_goal THEN 1 ELSE 0 END) as sog,
        SUM(CASE WHEN facts.is_goal THEN 1 ELSE 0 END) as goals,
        SUM(goal_predictions.xg) as ixg,
        SUM(sog_predictions.xg) as xsog
    FROM nhl_shot_attempts_facts facts
    INNER JOIN nhl_games games ON games.nhl_game_id = facts.nhl_game_id
    INNER JOIN nhl_shot_attempt_predictions goal_predictions
        ON goal_predictions.shot_attempt_fact_id = facts.id
        AND goal_predictions.expected_goals_model_id = ?
        AND goal_predictions.prediction_target = ?
        AND goal_predictions.is_scored = true
    INNER JOIN nhl_shot_attempt_predictions sog_predictions
        ON sog_predictions.shot_attempt_fact_id = facts.id
        AND sog_predictions.expected_goals_model_id = ?
        AND sog_predictions.prediction_target = ?
        AND sog_predictions.is_scored = true
    WHERE facts.season_id = ?
        AND games.game_type = ?
        AND facts.shooter_player_id IS NOT NULL
    GROUP BY facts.shooter_player_id, facts.game_date
),
ranked_games AS (
    SELECT
        player_games.*,
        ROW_NUMBER() OVER (PARTITION BY player_games.nhl_player_id ORDER BY player_games.game_date DESC) as recent_rank
    FROM player_games
),
player_rollups AS (
    SELECT
        nhl_player_id,
        MAX(team_id) as team_id,
        COUNT(*) FILTER (WHERE recent_rank <= 5) as window_games_5,
        MIN(game_date) FILTER (WHERE recent_rank <= 5) as start_date_5,
        MAX(game_date) FILTER (WHERE recent_rank <= 5) as end_date_5,
        SUM(sat) FILTER (WHERE recent_rank <= 5) as sat_5,
        SUM(sog) FILTER (WHERE recent_rank <= 5) as sog_5,
        SUM(goals) FILTER (WHERE recent_rank <= 5) as goals_5,
        SUM(ixg) FILTER (WHERE recent_rank <= 5) as ixg_5,
        SUM(xsog) FILTER (WHERE recent_rank <= 5) as xsog_5,
        COUNT(*) FILTER (WHERE recent_rank <= 10) as window_games_10,
        MIN(game_date) FILTER (WHERE recent_rank <= 10) as start_date_10,
        MAX(game_date) FILTER (WHERE recent_rank <= 10) as end_date_10,
        SUM(sat) FILTER (WHERE recent_rank <= 10) as sat_10,
        SUM(sog) FILTER (WHERE recent_rank <= 10) as sog_10,
        SUM(goals) FILTER (WHERE recent_rank <= 10) as goals_10,
        SUM(ixg) FILTER (WHERE recent_rank <= 10) as ixg_10,
        SUM(xsog) FILTER (WHERE recent_rank <= 10) as xsog_10,
        COUNT(*) FILTER (WHERE recent_rank <= 20) as window_games_20,
        MIN(game_date) FILTER (WHERE recent_rank <= 20) as start_date_20,
        MAX(game_date) FILTER (WHERE recent_rank <= 20) as end_date_20,
        SUM(sat) FILTER (WHERE recent_rank <= 20) as sat_20,
        SUM(sog) FILTER (WHERE recent_rank <= 20) as sog_20,
        SUM(goals) FILTER (WHERE recent_rank <= 20) as goals_20,
        SUM(ixg) FILTER (WHERE recent_rank <= 20) as ixg_20,
        SUM(xsog) FILTER (WHERE recent_rank <= 20) as xsog_20
    FROM ranked_games
    GROUP BY nhl_player_id
),
team_rollups AS (
    SELECT
        team_id,
        SUM(ixg_5) as team_ixg_5,
        SUM(ixg_10) as team_ixg_10,
        SUM(ixg_20) as team_ixg_20
    FROM player_rollups
    GROUP BY team_id
)
SELECT
    player_rollups.*,
    CASE WHEN sat_5 > 0 THEN ixg_5 / sat_5 ELSE NULL END as xg_per_sat_5,
    CASE WHEN sat_5 > 0 THEN xsog_5 / sat_5 ELSE NULL END as xsog_per_sat_5,
    sog_5 - xsog_5 as sog_minus_xsog_5,
    goals_5 - ixg_5 as goals_minus_ixg_5,
    CASE WHEN team_rollups.team_ixg_5 > 0 THEN ixg_5 / team_rollups.team_ixg_5 ELSE NULL END as ixg_share_5,
    CASE WHEN sat_10 > 0 THEN ixg_10 / sat_10 ELSE NULL END as xg_per_sat_10,
    CASE WHEN sat_10 > 0 THEN xsog_10 / sat_10 ELSE NULL END as xsog_per_sat_10,
    sog_10 - xsog_10 as sog_minus_xsog_10,
    goals_10 - ixg_10 as goals_minus_ixg_10,
    CASE WHEN team_rollups.team_ixg_10 > 0 THEN ixg_10 / team_rollups.team_ixg_10 ELSE NULL END as ixg_share_10,
    CASE WHEN sat_20 > 0 THEN ixg_20 / sat_20 ELSE NULL END as xg_per_sat_20,
    CASE WHEN sat_20 > 0 THEN xsog_20 / sat_20 ELSE NULL END as xsog_per_sat_20,
    sog_20 - xsog_20 as sog_minus_xsog_20,
    goals_20 - ixg_20 as goals_minus_ixg_20,
    CASE WHEN team_rollups.team_ixg_20 > 0 THEN ixg_20 / team_rollups.team_ixg_20 ELSE NULL END as ixg_share_20
FROM player_rollups
LEFT JOIN team_rollups ON team_rollups.team_id = player_rollups.team_id
SQL;

        return collect(DB::select($sql, [
            $models['goal'],
            NhlExpectedGoalsBackfiller::TARGET_GOAL,
            $models['shot_on_goal'],
            NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL,
            $seasonKey,
            $gameType,
        ]));
    }

    /**
     * @param array<string,string> $statTypeGroups
     * @param array{goal:int|null,shot_on_goal:int|null} $models
     * @return Collection<int,array<string,mixed>>
     */
    private function onIceExpectedWindowStats(
        string $seasonKey,
        int $gameType,
        array $models,
        string $sourceFetchedAt,
        array $statTypeGroups,
        ?string $windowKey = null
    ): Collection {
        if (! $this->canBuildOnIceExpected()) {
            return collect();
        }

        return $this->onIceExpectedWindowRows($seasonKey, $gameType, $models)
            ->flatMap(function (object $row) use ($seasonKey, $sourceFetchedAt, $statTypeGroups, $windowKey): array {
                $rows = [];

                foreach ($this->rollingWindows($windowKey) as $windowGames) {
                    $xgf = $row->{'on_ice_xgf_' . $windowGames} !== null
                        ? (float) $row->{'on_ice_xgf_' . $windowGames}
                        : null;
                    $xga = $row->{'on_ice_xga_' . $windowGames} !== null
                        ? (float) $row->{'on_ice_xga_' . $windowGames}
                        : null;
                    $xgPct = $xgf !== null && $xga !== null && ($xgf + $xga) > 0 ? $xgf / ($xgf + $xga) : null;

                    foreach ([
                        'on_ice_xgf' => $xgf,
                        'on_ice_xga' => $xga,
                        'on_ice_xg_pct' => $xgPct,
                        'on_ice_xg_diff' => $xgf !== null && $xga !== null ? $xgf - $xga : null,
                    ] as $slug => $value) {
                        $rows[] = $this->playerStatRow(
                            seasonKey: $seasonKey,
                            nhlPlayerId: (int) $row->nhl_player_id,
                            statSlug: $slug,
                            statGroup: $statTypeGroups[$slug] ?? 'expected',
                            windowKey: 'last_' . $windowGames,
                            windowGames: (int) $row->{'window_games_' . $windowGames},
                            startDate: $row->{'start_date_' . $windowGames} ?? null,
                            endDate: $row->{'end_date_' . $windowGames} ?? null,
                            value: $value,
                            sourceFetchedAt: $sourceFetchedAt
                        );
                    }
                }

                return $rows;
            });
    }

    /**
     * @param array{goal:int|null,shot_on_goal:int|null} $models
     * @return Collection<int,object>
     */
    private function onIceExpectedWindowRows(string $seasonKey, int $gameType, array $models): Collection
    {
        $sql = <<<SQL
WITH player_games AS (
    SELECT
        players.nhl_id as nhl_player_id,
        facts.game_date,
        SUM(CASE WHEN unit_shifts.team_id = facts.team_id THEN predictions.xg ELSE 0 END) as on_ice_xgf,
        SUM(CASE WHEN unit_shifts.team_id = facts.opponent_team_id THEN predictions.xg ELSE 0 END) as on_ice_xga
    FROM nhl_shot_attempt_predictions predictions
    INNER JOIN nhl_shot_attempts_facts facts ON facts.id = predictions.shot_attempt_fact_id
    INNER JOIN nhl_games games ON games.nhl_game_id = facts.nhl_game_id
    INNER JOIN event_unit_shifts event_links ON event_links.event_id = facts.play_by_play_id
    INNER JOIN nhl_unit_shifts unit_shifts ON unit_shifts.id = event_links.unit_shift_id
    INNER JOIN nhl_unit_shift_players shift_players ON shift_players.unit_shift_id = unit_shifts.id
    INNER JOIN players ON players.id = shift_players.player_id
    WHERE predictions.expected_goals_model_id = ?
        AND predictions.prediction_target = ?
        AND predictions.is_scored = true
        AND facts.season_id = ?
        AND games.game_type = ?
        AND players.nhl_id IS NOT NULL
    GROUP BY players.nhl_id, facts.game_date
),
ranked_games AS (
    SELECT
        player_games.*,
        ROW_NUMBER() OVER (PARTITION BY player_games.nhl_player_id ORDER BY player_games.game_date DESC) as recent_rank
    FROM player_games
)
SELECT
    nhl_player_id,
    COUNT(*) FILTER (WHERE recent_rank <= 5) as window_games_5,
    MIN(game_date) FILTER (WHERE recent_rank <= 5) as start_date_5,
    MAX(game_date) FILTER (WHERE recent_rank <= 5) as end_date_5,
    SUM(on_ice_xgf) FILTER (WHERE recent_rank <= 5) as on_ice_xgf_5,
    SUM(on_ice_xga) FILTER (WHERE recent_rank <= 5) as on_ice_xga_5,
    COUNT(*) FILTER (WHERE recent_rank <= 10) as window_games_10,
    MIN(game_date) FILTER (WHERE recent_rank <= 10) as start_date_10,
    MAX(game_date) FILTER (WHERE recent_rank <= 10) as end_date_10,
    SUM(on_ice_xgf) FILTER (WHERE recent_rank <= 10) as on_ice_xgf_10,
    SUM(on_ice_xga) FILTER (WHERE recent_rank <= 10) as on_ice_xga_10,
    COUNT(*) FILTER (WHERE recent_rank <= 20) as window_games_20,
    MIN(game_date) FILTER (WHERE recent_rank <= 20) as start_date_20,
    MAX(game_date) FILTER (WHERE recent_rank <= 20) as end_date_20,
    SUM(on_ice_xgf) FILTER (WHERE recent_rank <= 20) as on_ice_xgf_20,
    SUM(on_ice_xga) FILTER (WHERE recent_rank <= 20) as on_ice_xga_20
FROM ranked_games
GROUP BY nhl_player_id
SQL;

        return collect(DB::select($sql, [
            $models['goal'],
            NhlExpectedGoalsBackfiller::TARGET_GOAL,
            $seasonKey,
            $gameType,
        ]));
    }

    /**
     * @param array<string,string> $statTypeGroups
     * @return Collection<int,array<string,mixed>>
     */
    private function expectedFeatures(string $seasonKey, int $gameType, string $sourceFetchedAt, array $statTypeGroups): Collection
    {
        $models = $this->latestModelIds($seasonKey);

        if ($models['goal'] === null || $models['shot_on_goal'] === null) {
            return collect();
        }

        return $this->expectedFeatureRows($seasonKey, $gameType, $models)
            ->flatMap(function (object $row) use ($seasonKey, $sourceFetchedAt, $statTypeGroups): array {
                return collect([
                    'xg_per_sat' => [$row->xg_per_sat_10, $row->season_xg_per_sat],
                    'xsog_per_sat' => [$row->xsog_per_sat_10, $row->season_xsog_per_sat],
                ])->map(function (array $values, string $slug) use ($row, $seasonKey, $sourceFetchedAt, $statTypeGroups): array {
                    [$rawValue, $baselineValue] = $values;
                    $raw = $rawValue !== null ? (float) $rawValue : null;
                    $baseline = $baselineValue !== null ? (float) $baselineValue : null;
                    $absolute = $raw !== null && $baseline !== null ? $raw - $baseline : null;

                    return [
                        'league_abbrev' => self::LEAGUE_ABBREV,
                        'season_key' => $seasonKey,
                        'nhl_player_id' => (int) $row->nhl_player_id,
                        'stat_slug' => $slug,
                        'stat_group' => $statTypeGroups[$slug] ?? 'expected',
                        'window_key' => 'last_10',
                        'baseline_window_key' => 'season',
                        'raw_value' => $raw,
                        'per_game' => null,
                        'per_60' => null,
                        'baseline_value' => $baseline,
                        'baseline_per_game' => null,
                        'baseline_per_60' => null,
                        'deviation_absolute' => $absolute,
                        'deviation_percent' => $baseline !== null && abs($baseline) > 0.000001 && $absolute !== null
                            ? $absolute / $baseline
                            : null,
                        'sample_games' => (int) $row->sample_games,
                        'reliable_games_required' => 10,
                        'coverage_ratio' => min(1, ((int) $row->sample_games) / 10),
                        'confidence_label' => ((int) $row->sample_games) >= 10 ? 'high' : (((int) $row->sample_games) >= 5 ? 'medium' : 'low'),
                        'confidence_score' => min(1, ((int) $row->sample_games) / 10),
                        'generated_at' => $sourceFetchedAt,
                        'metadata' => [
                            'source_system' => self::SOURCE_SYSTEM,
                        ],
                    ];
                })->values()->all();
            });
    }

    /**
     * @param array{goal:int|null,shot_on_goal:int|null} $models
     * @return Collection<int,object>
     */
    private function expectedFeatureRows(string $seasonKey, int $gameType, array $models): Collection
    {
        $sql = <<<SQL
WITH player_games AS (
    SELECT
        facts.shooter_player_id as nhl_player_id,
        facts.game_date,
        COUNT(*) as sat,
        SUM(goal_predictions.xg) as ixg,
        SUM(sog_predictions.xg) as xsog
    FROM nhl_shot_attempts_facts facts
    INNER JOIN nhl_games games ON games.nhl_game_id = facts.nhl_game_id
    INNER JOIN nhl_shot_attempt_predictions goal_predictions
        ON goal_predictions.shot_attempt_fact_id = facts.id
        AND goal_predictions.expected_goals_model_id = ?
        AND goal_predictions.prediction_target = ?
        AND goal_predictions.is_scored = true
    INNER JOIN nhl_shot_attempt_predictions sog_predictions
        ON sog_predictions.shot_attempt_fact_id = facts.id
        AND sog_predictions.expected_goals_model_id = ?
        AND sog_predictions.prediction_target = ?
        AND sog_predictions.is_scored = true
    WHERE facts.season_id = ?
        AND games.game_type = ?
        AND facts.shooter_player_id IS NOT NULL
    GROUP BY facts.shooter_player_id, facts.game_date
),
ranked_games AS (
    SELECT
        player_games.*,
        ROW_NUMBER() OVER (PARTITION BY player_games.nhl_player_id ORDER BY player_games.game_date DESC) as recent_rank
    FROM player_games
),
rollups AS (
    SELECT
        nhl_player_id,
        COUNT(*) FILTER (WHERE recent_rank <= 10) as sample_games,
        SUM(sat) FILTER (WHERE recent_rank <= 10) as sat_10,
        SUM(ixg) FILTER (WHERE recent_rank <= 10) as ixg_10,
        SUM(xsog) FILTER (WHERE recent_rank <= 10) as xsog_10,
        SUM(sat) as season_sat,
        SUM(ixg) as season_ixg,
        SUM(xsog) as season_xsog
    FROM ranked_games
    GROUP BY nhl_player_id
)
SELECT
    nhl_player_id,
    sample_games,
    CASE WHEN sat_10 > 0 THEN ixg_10 / sat_10 ELSE NULL END as xg_per_sat_10,
    CASE WHEN sat_10 > 0 THEN xsog_10 / sat_10 ELSE NULL END as xsog_per_sat_10,
    CASE WHEN season_sat > 0 THEN season_ixg / season_sat ELSE NULL END as season_xg_per_sat,
    CASE WHEN season_sat > 0 THEN season_xsog / season_sat ELSE NULL END as season_xsog_per_sat
FROM rollups
SQL;

        return collect(DB::select($sql, [
            $models['goal'],
            NhlExpectedGoalsBackfiller::TARGET_GOAL,
            $models['shot_on_goal'],
            NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL,
            $seasonKey,
            $gameType,
        ]));
    }

    /**
     * @return array{goal:int|null,shot_on_goal:int|null}
     */
    private function latestModelIds(string $seasonKey): array
    {
        if (! $this->hasExpectedModelTables()) {
            return ['goal' => null, 'shot_on_goal' => null];
        }

        return [
            'goal' => $this->latestModelId($seasonKey, NhlExpectedGoalsBackfiller::TARGET_GOAL),
            'shot_on_goal' => $this->latestModelId($seasonKey, NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL),
        ];
    }

    private function latestModelId(string $seasonKey, string $target): ?int
    {
        $id = DB::table('nhl_expected_goals_models')
            ->where('training_season_id', $seasonKey)
            ->where('prediction_target', $target)
            ->where('status', 'draft')
            ->orderByDesc('trained_at')
            ->orderByDesc('id')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function hasExpectedModelTables(): bool
    {
        return Schema::hasTable('nhl_expected_goals_models')
            && Schema::hasTable('nhl_shot_attempt_predictions')
            && Schema::hasColumn('nhl_expected_goals_models', 'prediction_target')
            && Schema::hasColumn('nhl_shot_attempt_predictions', 'prediction_target');
    }

    private function canBuildOnIceExpected(): bool
    {
        return $this->hasExpectedModelTables()
            && Schema::hasTable('event_unit_shifts')
            && Schema::hasTable('nhl_unit_shifts')
            && Schema::hasTable('nhl_unit_shift_players');
    }

    /**
     * @return array<string,mixed>
     */
    private function playerStatRow(
        string $seasonKey,
        int $nhlPlayerId,
        string $statSlug,
        string $statGroup,
        string $windowKey,
        ?int $windowGames,
        mixed $startDate,
        mixed $endDate,
        mixed $value,
        string $sourceFetchedAt,
        array $metadata = []
    ): array {
        return [
            'league_abbrev' => self::LEAGUE_ABBREV,
            'season_key' => $seasonKey,
            'nhl_player_id' => $nhlPlayerId,
            'stat_slug' => $statSlug,
            'stat_group' => $statGroup,
            'window_key' => $windowKey,
            'window_games' => $windowGames,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'value' => $value !== null ? (float) $value : null,
            'source_system' => self::SOURCE_SYSTEM,
            'source_fetched_at' => $sourceFetchedAt,
            'metadata' => $metadata,
        ];
    }

    private function currentSeasonKey(): string
    {
        $now = now();
        $startYear = (int) $now->month >= 7 ? (int) $now->year : (int) $now->year - 1;

        return (string) (($startYear * 10000) + $startYear + 1);
    }
}
