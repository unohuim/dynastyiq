<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds first-pass goalie workload projections from usage, current roster, and contract context.
 */
class NhlGoalieWorkloadProjectionBuilder
{
    public const DEFAULT_VERSION_PREFIX = 'first_pass_goalie_workload';

    private const REGULAR_SEASON_GAME_TYPE = 2;
    private const TEAM_STARTS = 84.0;
    private const START_TOI_SECONDS = 3570.0;
    private const RELIEF_TOI_SECONDS = 1500.0;
    private const HIGH_COMMITMENT_GOALIE_CAP_SHARE = 0.55;

    public function defaultVersion(string $targetSeasonId): string
    {
        return self::DEFAULT_VERSION_PREFIX . '_' . $targetSeasonId . '_v1';
    }

    /**
     * Prepare one goalie workload projection version and return goalie jobs to queue.
     *
     * @return array{projection_version:string,source_season_id:string,target_season_id:string,goalie_player_ids:array<int,int>}
     */
    public function prepareBuild(string $sourceSeasonId, string $targetSeasonId, ?string $version = null): array
    {
        $version = $version ?: $this->defaultVersion($targetSeasonId);

        return [
            'projection_version' => $version,
            'source_season_id' => $sourceSeasonId,
            'target_season_id' => $targetSeasonId,
            'goalie_player_ids' => $this->eligibleGoalieIds()->all(),
        ];
    }

    /**
     * Build one goalie's workload projection row.
     *
     * @return array{goalie_player_id:int,rows:int}
     */
    public function buildGoalie(
        string $sourceSeasonId,
        string $targetSeasonId,
        string $version,
        int $goaliePlayerId
    ): array {
        return DB::transaction(function () use ($sourceSeasonId, $targetSeasonId, $version, $goaliePlayerId): array {
            $source = $this->sourceRow($sourceSeasonId, $targetSeasonId, $goaliePlayerId);

            if ($source === null || $source->target_team_abbrev === null || $source->target_team_abbrev === '') {
                return ['goalie_player_id' => $goaliePlayerId, 'rows' => 0];
            }

            $allocations = $this->teamAllocations(
                $this->teamPoolRows($sourceSeasonId, $targetSeasonId, (string) $source->target_team_abbrev),
                $targetSeasonId
            );
            $allocation = $allocations[$goaliePlayerId] ?? null;

            if ($allocation === null) {
                return ['goalie_player_id' => $goaliePlayerId, 'rows' => 0];
            }

            DB::table('nhl_goalie_workload_projections')->upsert(
                [$this->projectionPayload($source, $allocation, $sourceSeasonId, $targetSeasonId, $version)],
                ['projection_version', 'target_season_id', 'goalie_player_id'],
                $this->projectionUpdateColumns()
            );

            return ['goalie_player_id' => $goaliePlayerId, 'rows' => 1];
        });
    }

    /**
     * @return array<int, string>
     */
    private function projectionUpdateColumns(): array
    {
        return [
            'source_season_id',
            'source_team_id',
            'source_team_abbrev',
            'target_team_id',
            'target_team_abbrev',
            'position',
            'source_role_bucket',
            'target_role_bucket',
            'source_games',
            'source_starts',
            'source_relief_games',
            'source_toi_seconds',
            'source_sat_against',
            'source_sog_against',
            'source_goals_against',
            'source_xga',
            'source_xsoga',
            'source_gsax',
            'projected_games',
            'projected_starts',
            'projected_relief_games',
            'projected_toi_seconds',
            'projected_toi_hours',
            'projected_sata',
            'projected_soga',
            'projected_xga',
            'projected_ga',
            'projected_gsax',
            'projected_xsoga',
            'age_adjustment_starts',
            'role_adjustment_starts',
            'contract_adjustment_starts',
            'durability_adjustment_starts',
            'contract_cap_hit',
            'contract_aav',
            'contract_years_remaining',
            'team_contract_rank',
            'confidence_score',
            'confidence_bucket',
            'status',
            'projection_inputs',
            'flags',
            'metadata',
            'projected_at',
            'updated_at',
        ];
    }

    /**
     * @return Collection<int, int>
     */
    private function eligibleGoalieIds(): Collection
    {
        return DB::table('players')
            ->whereNotNull('nhl_id')
            ->whereNotNull('team_abbrev')
            ->where(function ($query): void {
                $query->where('is_goalie', true)
                    ->orWhere('position', 'G')
                    ->orWhere('pos_type', 'G');
            })
            ->orderBy('nhl_id')
            ->pluck('nhl_id')
            ->map(fn (mixed $goaliePlayerId): int => (int) $goaliePlayerId);
    }

    private function sourceRow(string $sourceSeasonId, string $targetSeasonId, int $goaliePlayerId): ?object
    {
        return $this->sourceBaseQuery($sourceSeasonId, $targetSeasonId)
            ->where('players.nhl_id', $goaliePlayerId)
            ->first();
    }

    /**
     * @return Collection<int, object>
     */
    private function teamPoolRows(string $sourceSeasonId, string $targetSeasonId, string $teamAbbrev): Collection
    {
        return $this->sourceBaseQuery($sourceSeasonId, $targetSeasonId)
            ->where('players.team_abbrev', mb_strtoupper($teamAbbrev))
            ->get();
    }

    private function sourceBaseQuery(string $sourceSeasonId, string $targetSeasonId)
    {
        $usageTotals = $this->usageTotalsSubquery($sourceSeasonId);
        $profileTotals = $this->profileTotalsSubquery($sourceSeasonId);
        $careerTotals = $this->careerTotalsSubquery($sourceSeasonId);
        $recentMaxUsage = $this->recentMaxUsageSubquery($sourceSeasonId);
        $targetContracts = $this->targetContractSubquery($targetSeasonId);
        $remainingContracts = $this->remainingContractSubquery($targetSeasonId);

        return DB::table('players')
            ->leftJoinSub($usageTotals, 'stats', 'stats.nhl_player_id', '=', 'players.nhl_id')
            ->leftJoin('nhl_teams as source_teams', 'source_teams.nhl_id', '=', 'stats.nhl_team_id')
            ->leftJoin('nhl_teams as target_teams', 'target_teams.abbrev', '=', 'players.team_abbrev')
            ->leftJoinSub($profileTotals, 'profiles', 'profiles.goalie_player_id', '=', 'players.nhl_id')
            ->leftJoinSub($careerTotals, 'career', 'career.nhl_player_id', '=', 'players.nhl_id')
            ->leftJoinSub($recentMaxUsage, 'recent_usage', 'recent_usage.nhl_player_id', '=', 'players.nhl_id')
            ->leftJoinSub($targetContracts, 'target_contracts', 'target_contracts.player_id', '=', 'players.id')
            ->leftJoinSub($remainingContracts, 'remaining_contracts', 'remaining_contracts.player_id', '=', 'players.id')
            ->whereNotNull('players.nhl_id')
            ->whereNotNull('players.team_abbrev')
            ->where(function ($query): void {
                $query->where('players.is_goalie', true)
                    ->orWhere('players.position', 'G')
                    ->orWhere('players.pos_type', 'G');
            })
            ->select([
                'players.id',
                'players.nhl_id as goalie_player_id',
                'players.nhl_team_id as target_team_id',
                'players.team_abbrev as target_team_abbrev',
                'players.position',
                'players.pos_type',
                'players.dob',
                'stats.nhl_team_id as source_team_id',
                'stats.gp as source_games',
                'stats.starts as source_starts',
                'stats.relief_appearances as source_relief_games',
                'stats.toi as source_toi_seconds',
                'stats.shots_against as source_sog_against',
                'stats.goals_against as source_goals_against',
                'source_teams.abbrev as source_team_abbrev',
                'target_contracts.contract_cap_hit',
                'target_contracts.contract_aav',
                'remaining_contracts.contract_years_remaining',
                'career.career_games',
                'career.career_starts',
                'recent_usage.recent_three_year_max_games',
                'recent_usage.recent_three_year_max_starts',
                'profiles.source_sat_against',
                'profiles.source_xga',
                'profiles.source_xsoga',
                'profiles.source_gsax',
                'target_teams.nhl_id as resolved_target_team_id',
            ]);
    }

    private function usageTotalsSubquery(string $sourceSeasonId)
    {
        return DB::table('nhl_season_stats')
            ->where('season_id', $sourceSeasonId)
            ->where('game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->selectRaw('nhl_player_id')
            ->selectRaw('MAX(nhl_team_id) as nhl_team_id')
            ->selectRaw('SUM(gp) as gp')
            ->selectRaw('SUM(starts) as starts')
            ->selectRaw('SUM(relief_appearances) as relief_appearances')
            ->selectRaw('SUM(toi) as toi')
            ->selectRaw('SUM(sa) as shots_against')
            ->selectRaw('SUM(ga) as goals_against')
            ->groupBy('nhl_player_id');
    }

    private function profileTotalsSubquery(string $sourceSeasonId)
    {
        return DB::table('nhl_goalie_chance_profile_buckets')
            ->where('source_season_id', $sourceSeasonId)
            ->where('game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->selectRaw('goalie_player_id')
            ->selectRaw('SUM(source_sat_against) as source_sat_against')
            ->selectRaw('SUM(source_xga) as source_xga')
            ->selectRaw('SUM(source_xsoga) as source_xsoga')
            ->selectRaw('SUM(source_gsax) as source_gsax')
            ->groupBy('goalie_player_id');
    }

    private function careerTotalsSubquery(string $sourceSeasonId)
    {
        return DB::table('nhl_season_stats')
            ->where('game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->where('season_id', '<=', $sourceSeasonId)
            ->selectRaw('nhl_player_id')
            ->selectRaw('SUM(gp) as career_games')
            ->selectRaw('SUM(starts) as career_starts')
            ->groupBy('nhl_player_id');
    }

    private function recentMaxUsageSubquery(string $sourceSeasonId)
    {
        $minimumSeasonId = (string) (((int) mb_substr($sourceSeasonId, 0, 4) - 2) * 10000 + ((int) mb_substr($sourceSeasonId, 4, 4) - 2));

        return DB::table('nhl_season_stats')
            ->where('game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->whereBetween('season_id', [$minimumSeasonId, $sourceSeasonId])
            ->selectRaw('nhl_player_id')
            ->selectRaw('MAX(gp) as recent_three_year_max_games')
            ->selectRaw('MAX(starts) as recent_three_year_max_starts')
            ->groupBy('nhl_player_id');
    }

    private function targetContractSubquery(string $targetSeasonId)
    {
        return DB::table('contracts')
            ->join('contract_seasons', 'contract_seasons.contract_id', '=', 'contracts.id')
            ->where('contract_seasons.season_key', (int) $targetSeasonId)
            ->selectRaw('contracts.player_id')
            ->selectRaw('MAX(contract_seasons.cap_hit) as contract_cap_hit')
            ->selectRaw('MAX(contract_seasons.aav) as contract_aav')
            ->groupBy('contracts.player_id');
    }

    private function remainingContractSubquery(string $targetSeasonId)
    {
        return DB::table('contracts')
            ->join('contract_seasons', 'contract_seasons.contract_id', '=', 'contracts.id')
            ->where('contract_seasons.season_key', '>=', (int) $targetSeasonId)
            ->selectRaw('contracts.player_id')
            ->selectRaw('COUNT(DISTINCT contract_seasons.season_key) as contract_years_remaining')
            ->groupBy('contracts.player_id');
    }

    /**
     * @param Collection<int, object> $rows
     * @return array<int, array<string, mixed>>
     */
    private function teamAllocations(Collection $rows, string $targetSeasonId): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $contractRanks = $this->contractRanks($rows);
        $teamGoalieAavTotal = max(1, $rows->sum(fn (object $row): int => (int) ($row->contract_aav ?? $row->contract_cap_hit ?? 0)));
        $prepared = $rows
            ->map(function (object $row) use ($targetSeasonId, $contractRanks, $teamGoalieAavTotal): array {
                $goalieId = (int) $row->goalie_player_id;
                $age = $this->ageAtTargetSeason($row->dob, $targetSeasonId);
                $sourceStarts = $this->sourceStarts($row);
                $careerGames = (float) ($row->career_games ?? 0);
                $aav = (int) ($row->contract_aav ?? $row->contract_cap_hit ?? 0);
                $contractRank = $contractRanks[$goalieId] ?? null;
                $youngGoalie = $this->isYoungGoalie($age, $careerGames);

                return [
                    'goalie_player_id' => $goalieId,
                    'age' => $age,
                    'source_starts' => $sourceStarts,
                    'source_games' => (float) ($row->source_games ?? 0),
                    'career_games' => $careerGames,
                    'recent_three_year_max_games' => (float) ($row->recent_three_year_max_games ?? 0),
                    'recent_three_year_max_starts' => (float) ($row->recent_three_year_max_starts ?? 0),
                    'contract_aav' => $aav,
                    'contract_cap_hit' => (int) ($row->contract_cap_hit ?? 0),
                    'contract_years_remaining' => (int) ($row->contract_years_remaining ?? 0),
                    'team_goalie_aav_total' => $teamGoalieAavTotal,
                    'goalie_aav_share' => round($aav / $teamGoalieAavTotal, 4),
                    'team_contract_rank' => $contractRank,
                    'young_goalie' => $youngGoalie,
                    'score' => $this->workloadScore($row, $age, $careerGames, $contractRank, $youngGoalie),
                ];
            })
            ->sortByDesc('score')
            ->values();

        $top = $prepared->first();
        $raw = [];

        foreach ($prepared as $rank => $row) {
            $sourceRole = $this->roleBucket((float) $row['source_starts']);
            $baseline = $this->baselineStarts($rank, (float) $row['score'], (int) $row['contract_aav'], (float) $row['source_starts']);
            $ageAdjustment = $this->ageAdjustmentStarts($row['age'], (float) $row['career_games'], $rank);
            $contractAdjustment = $this->contractAdjustmentStarts($row, $rank, $top);
            $durabilityAdjustment = $this->durabilityAdjustmentStarts($row['age'], (float) $row['source_starts']);
            $roleAdjustedStarts = $baseline + (0.25 * ((float) $row['source_starts'] - $baseline));
            $projectedStarts = max(0.0, $roleAdjustedStarts + $ageAdjustment + $contractAdjustment + $durabilityAdjustment);
            $starterRebound = $this->starterReboundRange($row, $rank);
            $flags = [];

            if ((bool) $row['young_goalie'] && (int) $row['contract_aav'] > 0 && (int) $row['contract_aav'] < 1500000) {
                $flags[] = 'young_goalie_contract_discounted';
            }

            if ($starterRebound !== null) {
                $projectedStarts = min($starterRebound['ceiling'], max($starterRebound['floor'], $projectedStarts));
                $flags[] = 'high_commitment_starter_rebound';
            }

            if ($this->isSuppressedVeteranBackup($row, $rank, $top)) {
                $projectedStarts = min($projectedStarts, 28.0);
                $flags[] = 'veteran_backup_behind_committed_starter';

                if ((float) $row['source_starts'] >= 35) {
                    $flags[] = 'source_workload_may_be_injury_inflated';
                }
            }

            if ($rank >= 2) {
                $projectedStarts = min($projectedStarts, 10.0);
            }

            $raw[] = $row + [
                'rank' => $rank,
                'source_role_bucket' => $sourceRole,
                'baseline_starts' => round($baseline, 2),
                'age_adjustment_starts' => round($ageAdjustment, 2),
                'role_adjustment_starts' => round($roleAdjustedStarts - (float) $row['source_starts'], 2),
                'contract_adjustment_starts' => round($contractAdjustment, 2),
                'durability_adjustment_starts' => round($durabilityAdjustment, 2),
                'starter_rebound_floor' => $starterRebound['floor'] ?? null,
                'starter_rebound_ceiling' => $starterRebound['ceiling'] ?? null,
                'raw_projected_starts' => round($projectedStarts, 2),
                'flags' => $flags,
            ];
        }

        $rawTotal = max(0.01, array_sum(array_column($raw, 'raw_projected_starts')));
        $scale = self::TEAM_STARTS / $rawTotal;
        $allocations = [];

        foreach ($raw as $row) {
            $projectedStarts = round(max(0.0, min(self::TEAM_STARTS, (float) $row['raw_projected_starts'] * $scale)), 2);
            $projectedRelief = $this->projectedReliefGames($projectedStarts, (float) $row['source_games'], (float) $row['source_starts']);
            $projectedGames = min(self::TEAM_STARTS, round($projectedStarts + $projectedRelief, 2));
            $projectedToiSeconds = (int) round(($projectedStarts * self::START_TOI_SECONDS) + ($projectedRelief * self::RELIEF_TOI_SECONDS));

            $allocations[(int) $row['goalie_player_id']] = $row + [
                'projected_starts' => $projectedStarts,
                'projected_relief_games' => $projectedRelief,
                'projected_games' => $projectedGames,
                'projected_toi_seconds' => $projectedToiSeconds,
                'projected_toi_hours' => round($projectedToiSeconds / 3600, 4),
                'target_role_bucket' => $this->roleBucket($projectedStarts),
            ];
        }

        return $allocations;
    }

    /**
     * @param Collection<int, object> $rows
     * @return array<int, int>
     */
    private function contractRanks(Collection $rows): array
    {
        $ranked = $rows
            ->sortByDesc(fn (object $row): int => (int) ($row->contract_aav ?? $row->contract_cap_hit ?? 0))
            ->values();
        $ranks = [];

        foreach ($ranked as $index => $row) {
            $ranks[(int) $row->goalie_player_id] = $index + 1;
        }

        return $ranks;
    }

    private function sourceStarts(object $row): float
    {
        $starts = (float) ($row->source_starts ?? 0);

        if ($starts > 0) {
            return $starts;
        }

        $sourceGames = (float) ($row->source_games ?? 0);
        $relief = (float) ($row->source_relief_games ?? 0);

        return max(0.0, $sourceGames - $relief);
    }

    private function workloadScore(object $row, ?float $age, float $careerGames, ?int $contractRank, bool $youngGoalie): float
    {
        $sourceStarts = $this->sourceStarts($row);
        $aav = (int) ($row->contract_aav ?? $row->contract_cap_hit ?? 0);
        $term = (int) ($row->contract_years_remaining ?? 0);
        $contractWeight = $youngGoalie ? 0.12 : 0.32;
        $rankBonus = $contractRank === 1 ? 0.08 : ($contractRank === 2 ? 0.03 : 0.0);

        return round(min(1.0,
            (0.45 * min(1.0, $sourceStarts / 55.0))
            + ($contractWeight * min(1.0, $aav / 8500000.0))
            + (0.10 * min(1.0, $term / 4.0))
            + (0.08 * min(1.0, $careerGames / 250.0))
            + ($youngGoalie ? 0.08 : 0.0)
            + $rankBonus
        ), 4);
    }

    private function baselineStarts(int $rank, float $score, int $aav, float $sourceStarts): float
    {
        if ($rank === 0) {
            return match (true) {
                $score >= 0.72 || $aav >= 7000000 || $sourceStarts >= 52 => 56.0,
                $score >= 0.55 || $sourceStarts >= 40 => 48.0,
                default => 42.0,
            };
        }

        if ($rank === 1) {
            return match (true) {
                $score >= 0.52 || $sourceStarts >= 32 => 34.0,
                $aav >= 3000000 => 28.0,
                default => 24.0,
            };
        }

        return $rank === 2 ? 8.0 : 3.0;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $top
     */
    private function contractAdjustmentStarts(array $row, int $rank, ?array $top): float
    {
        $aav = (int) $row['contract_aav'];

        if ((bool) $row['young_goalie']) {
            return $aav >= 3500000 ? 2.0 : 0.0;
        }

        if ($aav >= 7000000 && $rank === 0) {
            return 3.0;
        }

        if ($this->isSuppressedVeteranBackup($row, $rank, $top)) {
            return -8.0;
        }

        if ($aav >= 4000000 && $rank <= 1) {
            return 2.0;
        }

        if ($aav > 0 && $aav < 1500000 && $rank > 0) {
            return -3.0;
        }

        return 0.0;
    }

    private function ageAdjustmentStarts(?float $age, float $careerGames, int $rank): float
    {
        if ($age === null) {
            return 0.0;
        }

        if ($this->isYoungGoalie($age, $careerGames) && $rank <= 1) {
            return 2.0;
        }

        if ($age >= 37) {
            return -4.0;
        }

        if ($age >= 35) {
            return -2.0;
        }

        if ($age >= 33 && $rank > 0) {
            return -1.0;
        }

        return 0.0;
    }

    private function durabilityAdjustmentStarts(?float $age, float $sourceStarts): float
    {
        if ($sourceStarts >= 62) {
            return -3.0;
        }

        if ($sourceStarts >= 58) {
            return -1.0;
        }

        if ($age !== null && $age >= 34 && $sourceStarts <= 20) {
            return -2.0;
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{floor:float,ceiling:float}|null
     */
    private function starterReboundRange(array $row, int $rank): ?array
    {
        $sourceStarts = (float) $row['source_starts'];
        $age = $row['age'] === null ? null : (float) $row['age'];
        $careerGames = (float) $row['career_games'];
        $goalieAavShare = (float) $row['goalie_aav_share'];

        if (
            $rank !== 0
            || $goalieAavShare < self::HIGH_COMMITMENT_GOALIE_CAP_SHARE
            || $careerGames < 180
            || ($age !== null && $age >= 34)
            || $sourceStarts < 10
            || $sourceStarts > 35
        ) {
            return null;
        }

        $recentMaxStarts = (float) $row['recent_three_year_max_starts'];
        $recentMaxGames = (float) $row['recent_three_year_max_games'];
        $recentCeilingBase = max($recentMaxStarts, $recentMaxGames - 2.0, $sourceStarts);
        $recoveryAllowance = $age !== null && $age <= 32 ? 9.0 : 6.0;

        return [
            'floor' => 40.0,
            'ceiling' => max(40.0, min(52.0, $recentCeilingBase + $recoveryAllowance)),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $top
     */
    private function isSuppressedVeteranBackup(array $row, int $rank, ?array $top): bool
    {
        if ($rank === 0 || $top === null || (bool) $row['young_goalie']) {
            return false;
        }

        $topAav = (int) ($top['contract_aav'] ?? 0);
        $aav = (int) ($row['contract_aav'] ?? 0);

        return $topAav >= 6500000
            && $aav > 0
            && $aav <= 5000000
            && $topAav >= ($aav * 1.5);
    }

    private function projectedReliefGames(float $projectedStarts, float $sourceGames, float $sourceStarts): float
    {
        $sourceRelief = max(0.0, $sourceGames - $sourceStarts);
        $baseline = match (true) {
            $projectedStarts >= 45 => 2.0,
            $projectedStarts >= 30 => 3.0,
            $projectedStarts >= 15 => 4.0,
            $projectedStarts > 0 => 2.0,
            default => 0.0,
        };

        return round(min(6.0, max(0.0, ($baseline * 0.75) + ($sourceRelief * 0.25))), 2);
    }

    private function projectionPayload(
        object $source,
        array $allocation,
        string $sourceSeasonId,
        string $targetSeasonId,
        string $version
    ): array {
        $sourceStarts = $this->sourceStarts($source);
        $sourceGames = (float) ($source->source_games ?? 0);
        $projectedSata = $this->scaleByToi($source->source_sat_against, $source->source_toi_seconds, $allocation['projected_toi_seconds']);
        $projectedSoga = $this->scaleByToi($source->source_sog_against, $source->source_toi_seconds, $allocation['projected_toi_seconds']);
        $projectedXga = $this->scaleByToi($source->source_xga, $source->source_toi_seconds, $allocation['projected_toi_seconds']);
        $projectedXsoga = $this->scaleByToi($source->source_xsoga, $source->source_toi_seconds, $allocation['projected_toi_seconds']);
        $projectedGsax = $this->scaleByToi($source->source_gsax, $source->source_toi_seconds, $allocation['projected_toi_seconds']);
        $now = now();

        return [
            'projection_version' => $version,
            'source_season_id' => $sourceSeasonId,
            'target_season_id' => $targetSeasonId,
            'goalie_player_id' => (int) $source->goalie_player_id,
            'source_team_id' => $source->source_team_id === null ? null : (int) $source->source_team_id,
            'source_team_abbrev' => $source->source_team_abbrev,
            'target_team_id' => $source->target_team_id === null ? (int) ($source->resolved_target_team_id ?? 0) ?: null : (int) $source->target_team_id,
            'target_team_abbrev' => $source->target_team_abbrev,
            'position' => $source->position ?: $source->pos_type ?: 'G',
            'source_role_bucket' => $allocation['source_role_bucket'],
            'target_role_bucket' => $allocation['target_role_bucket'],
            'source_games' => $sourceGames,
            'source_starts' => $sourceStarts,
            'source_relief_games' => max(0.0, $sourceGames - $sourceStarts),
            'source_toi_seconds' => $source->source_toi_seconds === null ? null : (int) $source->source_toi_seconds,
            'source_sat_against' => (int) ($source->source_sat_against ?? 0),
            'source_sog_against' => (int) ($source->source_sog_against ?? 0),
            'source_goals_against' => (int) ($source->source_goals_against ?? 0),
            'source_xga' => $source->source_xga === null ? null : round((float) $source->source_xga, 4),
            'source_xsoga' => $source->source_xsoga === null ? null : round((float) $source->source_xsoga, 4),
            'source_gsax' => $source->source_gsax === null ? null : round((float) $source->source_gsax, 4),
            'projected_games' => $allocation['projected_games'],
            'projected_starts' => $allocation['projected_starts'],
            'projected_relief_games' => $allocation['projected_relief_games'],
            'projected_toi_seconds' => $allocation['projected_toi_seconds'],
            'projected_toi_hours' => $allocation['projected_toi_hours'],
            'projected_sata' => $projectedSata,
            'projected_soga' => $projectedSoga,
            'projected_xga' => $projectedXga,
            'projected_ga' => $projectedXga === null || $projectedGsax === null ? null : round($projectedXga - $projectedGsax, 4),
            'projected_gsax' => $projectedGsax,
            'projected_xsoga' => $projectedXsoga,
            'age_adjustment_starts' => $allocation['age_adjustment_starts'],
            'role_adjustment_starts' => $allocation['role_adjustment_starts'],
            'contract_adjustment_starts' => $allocation['contract_adjustment_starts'],
            'durability_adjustment_starts' => $allocation['durability_adjustment_starts'],
            'contract_cap_hit' => $source->contract_cap_hit === null ? null : (int) $source->contract_cap_hit,
            'contract_aav' => $source->contract_aav === null ? null : (int) $source->contract_aav,
            'contract_years_remaining' => $source->contract_years_remaining === null ? null : (int) $source->contract_years_remaining,
            'team_contract_rank' => $allocation['team_contract_rank'],
            'confidence_score' => $this->confidenceScore($source, $allocation),
            'confidence_bucket' => $this->confidenceBucket($this->confidenceScore($source, $allocation)),
            'status' => 'draft',
            'projection_inputs' => json_encode([
                'method' => 'goalie_starts_first_workload_projection',
                'source_starts' => $sourceStarts,
                'baseline_starts' => $allocation['baseline_starts'],
                'raw_projected_starts' => $allocation['raw_projected_starts'],
                'team_start_reconciliation_target' => self::TEAM_STARTS,
                'team_goalie_aav_total' => $allocation['team_goalie_aav_total'],
                'goalie_aav_share' => $allocation['goalie_aav_share'],
                'recent_three_year_max_games' => $allocation['recent_three_year_max_games'],
                'recent_three_year_max_starts' => $allocation['recent_three_year_max_starts'],
                'starter_rebound_floor' => $allocation['starter_rebound_floor'],
                'starter_rebound_ceiling' => $allocation['starter_rebound_ceiling'],
            ], JSON_THROW_ON_ERROR),
            'flags' => json_encode($this->flags($source, $allocation), JSON_THROW_ON_ERROR),
            'metadata' => json_encode(['builder' => 'NhlGoalieWorkloadProjectionBuilder'], JSON_THROW_ON_ERROR),
            'projected_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function scaleByToi(mixed $sourceValue, mixed $sourceToiSeconds, mixed $projectedToiSeconds): ?float
    {
        if ($sourceValue === null || $sourceToiSeconds === null || (int) $sourceToiSeconds <= 0) {
            return null;
        }

        return round(((float) $sourceValue) * ((float) $projectedToiSeconds / (float) $sourceToiSeconds), 4);
    }

    private function roleBucket(float $starts): string
    {
        return match (true) {
            $starts >= 55 => 'workhorse_starter',
            $starts >= 45 => 'starter',
            $starts >= 38 => 'tandem_1a',
            $starts >= 30 => 'tandem_1b',
            $starts >= 15 => 'backup',
            default => 'third_goalie',
        };
    }

    private function ageAtTargetSeason(mixed $dob, string $targetSeasonId): ?float
    {
        if ($dob === null || $dob === '') {
            return null;
        }

        $targetYear = (int) mb_substr($targetSeasonId, 0, 4);
        $targetDate = Carbon::create($targetYear, 9, 15);

        return round(Carbon::parse($dob)->floatDiffInYears($targetDate), 2);
    }

    private function isYoungGoalie(?float $age, float $careerGames): bool
    {
        return ($age !== null && $age <= 25.5) || $careerGames < 80;
    }

    private function confidenceScore(object $source, array $allocation): float
    {
        $sourceGames = (float) ($source->source_games ?? 0);
        $score = min(1.0, $sourceGames / 55.0);

        if ($sourceGames <= 0) {
            $score -= 0.35;
        }

        if ($source->contract_aav === null && $source->contract_cap_hit === null) {
            $score -= 0.15;
        }

        if ($source->source_team_abbrev !== null && $source->target_team_abbrev !== null && $source->source_team_abbrev !== $source->target_team_abbrev) {
            $score -= 0.10;
        }

        if (($allocation['career_games'] ?? 0) < 40) {
            $score -= 0.10;
        }

        return round(max(0.1, min(1.0, $score)), 4);
    }

    private function confidenceBucket(float $confidenceScore): string
    {
        return match (true) {
            $confidenceScore >= 0.8 => 'high',
            $confidenceScore >= 0.5 => 'medium',
            default => 'low',
        };
    }

    /**
     * @param array<string, mixed> $allocation
     * @return array<int, string>
     */
    private function flags(object $source, array $allocation): array
    {
        $flags = $allocation['flags'];

        if ((float) ($source->source_games ?? 0) <= 0) {
            $flags[] = 'missing_source_usage';
        }

        if ($source->contract_aav === null && $source->contract_cap_hit === null) {
            $flags[] = 'missing_target_contract';
        }

        if ($source->source_team_abbrev !== null && $source->target_team_abbrev !== null && $source->source_team_abbrev !== $source->target_team_abbrev) {
            $flags[] = 'team_changed';
        }

        return array_values(array_unique($flags));
    }
}
