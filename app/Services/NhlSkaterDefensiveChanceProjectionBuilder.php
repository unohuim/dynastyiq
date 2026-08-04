<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds skater defensive chance projections from TOI projections and historical on-ice defensive profiles.
 */
class NhlSkaterDefensiveChanceProjectionBuilder
{
    public const DEFAULT_VERSION_PREFIX = 'first_pass_skater_d';
    private const PROJECTION_BUCKET_COVERAGE_TARGET = 0.95;
    private const MIN_PROJECTED_BUCKET_SATA = 1.0;
    private const MIN_PROJECTED_BUCKET_SHARE = 0.0025;

    public function defaultVersion(string $targetSeasonId): string
    {
        return self::DEFAULT_VERSION_PREFIX . '_' . $targetSeasonId . '_v1';
    }

    /**
     * Build projections for all skaters with matching TOI projections and defensive profiles.
     *
     * @return array{projection_version:string,skater_rows:int,bucket_rows:int}
     */
    public function build(
        string $sourceSeasonId,
        string $targetSeasonId,
        ?string $projectionVersion = null,
        ?string $toiProjectionVersion = null
    ): array {
        $projectionVersion = $projectionVersion ?: $this->defaultVersion($targetSeasonId);
        $toiProjectionVersion = $toiProjectionVersion
            ?: NhlPlayerToiProjectionBuilder::DEFAULT_VERSION_PREFIX . '_' . $targetSeasonId . '_v1';

        DB::table('nhl_skater_defensive_chance_projections')
            ->where('projection_version', $projectionVersion)
            ->where('target_season_id', $targetSeasonId)
            ->delete();

        $skaterRows = 0;
        $bucketRows = 0;

        foreach ($this->eligiblePlayerIds($sourceSeasonId, $targetSeasonId, $toiProjectionVersion) as $playerId) {
            $result = $this->buildPlayer($sourceSeasonId, $targetSeasonId, $projectionVersion, $toiProjectionVersion, $playerId);
            $skaterRows += $result['skater_rows'];
            $bucketRows += $result['bucket_rows'];
        }

        return [
            'projection_version' => $projectionVersion,
            'skater_rows' => $skaterRows,
            'bucket_rows' => $bucketRows,
        ];
    }

    /**
     * @return array{player_id:int,skater_rows:int,bucket_rows:int}
     */
    public function buildPlayer(
        string $sourceSeasonId,
        string $targetSeasonId,
        string $projectionVersion,
        string $toiProjectionVersion,
        int $playerId
    ): array {
        return DB::transaction(function () use (
            $sourceSeasonId,
            $targetSeasonId,
            $projectionVersion,
            $toiProjectionVersion,
            $playerId
        ): array {
            DB::table('nhl_skater_defensive_chance_projections')
                ->where('projection_version', $projectionVersion)
                ->where('target_season_id', $targetSeasonId)
                ->where('player_id', $playerId)
                ->delete();

            $toi = $this->toiProjection($sourceSeasonId, $targetSeasonId, $toiProjectionVersion, $playerId);
            $profileRows = $this->profileRows($sourceSeasonId, $playerId);

            if ($toi === null || $profileRows->isEmpty()) {
                return ['player_id' => $playerId, 'skater_rows' => 0, 'bucket_rows' => 0];
            }

            $sourceTotals = $this->sourceTotals($profileRows);
            $sourceToiHours = ((int) ($profileRows->first()->source_toi_seconds ?? 0)) / 3600;
            $projectedToiHours = (float) ($toi->projected_toi_hours ?? 0);
            $projectedTotalSata = $sourceToiHours > 0
                ? ($sourceTotals['source_sat_against_on_ice'] / $sourceToiHours) * $projectedToiHours
                : $sourceTotals['source_sat_against_on_ice'];
            $bucketPayloads = $this->bucketPayloads(
                $profileRows,
                $toi,
                $sourceTotals,
                $projectedTotalSata,
                $sourceSeasonId,
                $targetSeasonId,
                $projectionVersion
            );

            if ($bucketPayloads === []) {
                return ['player_id' => $playerId, 'skater_rows' => 0, 'bucket_rows' => 0];
            }

            $projectionId = $this->insertProjection(
                $toi,
                $sourceTotals,
                $bucketPayloads,
                $sourceSeasonId,
                $targetSeasonId,
                $projectionVersion,
                $toiProjectionVersion
            );

            foreach ($bucketPayloads as &$payload) {
                $payload['skater_defensive_chance_projection_id'] = $projectionId;
            }
            unset($payload);

            foreach (array_chunk($bucketPayloads, 100) as $chunk) {
                DB::table('nhl_skater_defensive_chance_projection_buckets')->insert($chunk);
            }

            return ['player_id' => $playerId, 'skater_rows' => 1, 'bucket_rows' => count($bucketPayloads)];
        });
    }

    /**
     * @return Collection<int, int>
     */
    private function eligiblePlayerIds(string $sourceSeasonId, string $targetSeasonId, string $toiProjectionVersion): Collection
    {
        return DB::table('nhl_player_toi_projections as toi')
            ->join('nhl_skater_defensive_chance_profile_buckets as profiles', function ($join) use ($sourceSeasonId): void {
                $join->on('profiles.player_id', '=', 'toi.player_id')
                    ->where('profiles.source_season_id', '=', $sourceSeasonId);
            })
            ->where('toi.source_season_id', $sourceSeasonId)
            ->where('toi.target_season_id', $targetSeasonId)
            ->where('toi.projection_version', $toiProjectionVersion)
            ->distinct()
            ->orderBy('toi.player_id')
            ->pluck('toi.player_id')
            ->map(fn (mixed $playerId): int => (int) $playerId);
    }

    private function toiProjection(
        string $sourceSeasonId,
        string $targetSeasonId,
        string $toiProjectionVersion,
        int $playerId
    ): ?object {
        return DB::table('nhl_player_toi_projections')
            ->where('source_season_id', $sourceSeasonId)
            ->where('target_season_id', $targetSeasonId)
            ->where('projection_version', $toiProjectionVersion)
            ->where('player_id', $playerId)
            ->first();
    }

    /**
     * @return Collection<int, object>
     */
    private function profileRows(string $sourceSeasonId, int $playerId): Collection
    {
        return DB::table('nhl_skater_defensive_chance_profile_buckets')
            ->where('source_season_id', $sourceSeasonId)
            ->where('player_id', $playerId)
            ->orderByDesc('source_xga_on_ice')
            ->get();
    }

    /**
     * @param Collection<int, object> $profileRows
     * @return array<string, float|int|null>
     */
    private function sourceTotals(Collection $profileRows): array
    {
        return [
            'source_games' => $profileRows->max('source_games'),
            'source_toi_seconds' => $profileRows->max('source_toi_seconds'),
            'source_sat_against_on_ice' => (int) $profileRows->sum('source_sat_against_on_ice'),
            'source_sog_against_on_ice' => (int) $profileRows->sum('source_sog_against_on_ice'),
            'source_goals_against_on_ice' => (int) $profileRows->sum('source_goals_against_on_ice'),
            'source_xga_on_ice' => round((float) $profileRows->sum('source_xga_on_ice'), 4),
            'source_xsoga_on_ice' => round((float) $profileRows->sum('source_xsoga_on_ice'), 4),
        ];
    }

    /**
     * @param Collection<int, object> $profileRows
     * @param array<string, float|int|null> $sourceTotals
     * @return array<int, array<string, mixed>>
     */
    private function bucketPayloads(
        Collection $profileRows,
        object $toi,
        array $sourceTotals,
        float $projectedTotalSata,
        string $sourceSeasonId,
        string $targetSeasonId,
        string $projectionVersion
    ): array {
        $now = now();
        $sourceTotalSata = max(1, (int) $sourceTotals['source_sat_against_on_ice']);
        $rows = $profileRows
            ->map(function (object $row) use ($sourceTotalSata, $projectedTotalSata): object {
                $share = (float) ($row->source_profile_share_against ?? 0);

                if ($share <= 0) {
                    $share = (int) $row->source_sat_against_on_ice / $sourceTotalSata;
                }

                $row->projected_profile_share_against = round($share, 6);
                $row->projected_sata = round($projectedTotalSata * $share, 2);
                $row->projected_soga = round($row->projected_sata * (float) $row->shot_on_goal_probability_against, 2);
                $row->projected_xga = round($row->projected_sata * (float) $row->goal_probability_against, 4);
                $row->projected_xsoga = round($row->projected_sata * (float) $row->shot_on_goal_probability_against, 4);

                return $row;
            })
            ->sortByDesc(fn (object $row): float => (float) $row->projected_xga)
            ->values();
        $retainedBucketKeys = $this->retainedBucketKeys($rows);
        $payloads = [];

        foreach ($rows->filter(fn (object $row): bool => $retainedBucketKeys->contains((string) $row->matched_bucket_key)) as $row) {
            $payloads[] = $this->bucketPayload($row, $toi, $sourceSeasonId, $targetSeasonId, $projectionVersion, 'retained', $now);
        }

        $tailRows = $rows->reject(fn (object $row): bool => $retainedBucketKeys->contains((string) $row->matched_bucket_key));
        if ($tailRows->isNotEmpty()) {
            $payloads[] = $this->otherBucketPayload($tailRows, $toi, $sourceSeasonId, $targetSeasonId, $projectionVersion, $now);
        }

        return $payloads;
    }

    private function insertProjection(
        object $toi,
        array $sourceTotals,
        array $bucketPayloads,
        string $sourceSeasonId,
        string $targetSeasonId,
        string $projectionVersion,
        string $toiProjectionVersion
    ): int {
        $now = now();

        return (int) DB::table('nhl_skater_defensive_chance_projections')->insertGetId([
            'projection_version' => $projectionVersion,
            'source_season_id' => $sourceSeasonId,
            'target_season_id' => $targetSeasonId,
            'player_id' => (int) $toi->player_id,
            'source_team_id' => $toi->source_team_id === null ? null : (int) $toi->source_team_id,
            'source_team_abbrev' => $toi->source_team_abbrev,
            'target_team_id' => $toi->target_team_id === null ? null : (int) $toi->target_team_id,
            'target_team_abbrev' => $toi->target_team_abbrev,
            'position' => $toi->position,
            'toi_projection_id' => (int) $toi->id,
            'toi_projection_version' => $toiProjectionVersion,
            'source_games' => $sourceTotals['source_games'],
            'source_toi_seconds' => $sourceTotals['source_toi_seconds'],
            'source_sat_against_on_ice' => $sourceTotals['source_sat_against_on_ice'],
            'source_sog_against_on_ice' => $sourceTotals['source_sog_against_on_ice'],
            'source_goals_against_on_ice' => $sourceTotals['source_goals_against_on_ice'],
            'source_xga_on_ice' => $sourceTotals['source_xga_on_ice'],
            'source_xsoga_on_ice' => $sourceTotals['source_xsoga_on_ice'],
            'projected_games' => $toi->projected_games,
            'projected_toi_hours' => $toi->projected_toi_hours,
            'projected_sata' => round(array_sum(array_column($bucketPayloads, 'projected_sata')), 2),
            'projected_soga' => round(array_sum(array_column($bucketPayloads, 'projected_soga')), 2),
            'projected_xga' => round(array_sum(array_column($bucketPayloads, 'projected_xga')), 4),
            'projected_xsoga' => round(array_sum(array_column($bucketPayloads, 'projected_xsoga')), 4),
            'confidence_score' => round((float) $toi->confidence_score, 4),
            'confidence_bucket' => $toi->confidence_bucket,
            'status' => 'draft',
            'projection_inputs' => json_encode([
                'method' => 'skater_defensive_profile_scaled_by_toi_projection',
                'toi_projection_id' => (int) $toi->id,
                'toi_projection_version' => $toiProjectionVersion,
            ], JSON_THROW_ON_ERROR),
            'flags' => json_encode([], JSON_THROW_ON_ERROR),
            'metadata' => json_encode(['builder' => 'NhlSkaterDefensiveChanceProjectionBuilder'], JSON_THROW_ON_ERROR),
            'projected_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param Collection<int, object> $rows
     * @return Collection<int, string>
     */
    private function retainedBucketKeys(Collection $rows): Collection
    {
        $totalProjectedSata = max(0.01, (float) $rows->sum('projected_sata'));
        $cumulativeShare = 0.0;
        $retained = [];

        foreach ($rows as $row) {
            $share = (float) $row->projected_sata / $totalProjectedSata;

            if ((float) $row->projected_sata >= self::MIN_PROJECTED_BUCKET_SATA || $share >= self::MIN_PROJECTED_BUCKET_SHARE || $cumulativeShare < self::PROJECTION_BUCKET_COVERAGE_TARGET) {
                $retained[] = (string) $row->matched_bucket_key;
                $cumulativeShare += $share;
            }

            if ($cumulativeShare >= self::PROJECTION_BUCKET_COVERAGE_TARGET && (float) $row->projected_sata < self::MIN_PROJECTED_BUCKET_SATA && $share < self::MIN_PROJECTED_BUCKET_SHARE) {
                break;
            }
        }

        return collect($retained);
    }

    private function bucketPayload(
        object $row,
        object $toi,
        string $sourceSeasonId,
        string $targetSeasonId,
        string $projectionVersion,
        string $bucketRole,
        object $now
    ): array {
        return [
            'skater_defensive_chance_projection_id' => 0,
            'source_profile_bucket_id' => (int) $row->id,
            'projection_version' => $projectionVersion,
            'source_season_id' => $sourceSeasonId,
            'target_season_id' => $targetSeasonId,
            'player_id' => (int) $row->player_id,
            'source_team_id' => $row->team_id === null ? null : (int) $row->team_id,
            'source_team_abbrev' => $row->team_abbrev,
            'target_team_id' => $toi->target_team_id === null ? null : (int) $toi->target_team_id,
            'target_team_abbrev' => $toi->target_team_abbrev,
            'position' => $row->position,
            'matched_bucket_key' => (string) $row->matched_bucket_key,
            'fallback_level' => (int) $row->fallback_level,
            'bucket_dimensions' => is_string($row->bucket_dimensions) ? $row->bucket_dimensions : json_encode($row->bucket_dimensions, JSON_THROW_ON_ERROR),
            'shot_type_group' => $row->shot_type_group,
            'distance_group' => $row->distance_group,
            'angle_group' => $row->angle_group,
            'sequence_group' => $row->sequence_group,
            'source_sat_against_on_ice' => (int) $row->source_sat_against_on_ice,
            'source_sog_against_on_ice' => (int) $row->source_sog_against_on_ice,
            'source_goals_against_on_ice' => (int) $row->source_goals_against_on_ice,
            'source_xga_on_ice' => $row->source_xga_on_ice,
            'source_xsoga_on_ice' => $row->source_xsoga_on_ice,
            'source_profile_share_against' => $row->source_profile_share_against,
            'projected_sata' => $row->projected_sata,
            'projected_soga' => $row->projected_soga,
            'projected_xga' => $row->projected_xga,
            'projected_xsoga' => $row->projected_xsoga,
            'projected_profile_share_against' => $row->projected_profile_share_against,
            'goal_probability_against' => $row->goal_probability_against,
            'shot_on_goal_probability_against' => $row->shot_on_goal_probability_against,
            'confidence_score' => $row->confidence_score,
            'confidence_bucket' => $row->confidence_bucket,
            'projection_inputs' => json_encode([
                'method' => 'skater_defensive_profile_bucket_scaled_by_toi_projection',
                'source_profile_bucket_id' => (int) $row->id,
            ], JSON_THROW_ON_ERROR),
            'flags' => json_encode([], JSON_THROW_ON_ERROR),
            'metadata' => json_encode([
                'builder' => 'NhlSkaterDefensiveChanceProjectionBuilder',
                'retention' => [
                    'coverage_target' => self::PROJECTION_BUCKET_COVERAGE_TARGET,
                    'minimum_projected_sata' => self::MIN_PROJECTED_BUCKET_SATA,
                    'minimum_projected_share' => self::MIN_PROJECTED_BUCKET_SHARE,
                    'bucket_role' => $bucketRole,
                ],
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param Collection<int, object> $tailRows
     * @return array<string, mixed>
     */
    private function otherBucketPayload(
        Collection $tailRows,
        object $toi,
        string $sourceSeasonId,
        string $targetSeasonId,
        string $projectionVersion,
        object $now
    ): array {
        $sourceSat = max(1, (int) $tailRows->sum('source_sat_against_on_ice'));
        $sourceXga = round((float) $tailRows->sum('source_xga_on_ice'), 4);
        $sourceXsoga = round((float) $tailRows->sum('source_xsoga_on_ice'), 4);

        return [
            'skater_defensive_chance_projection_id' => 0,
            'source_profile_bucket_id' => null,
            'projection_version' => $projectionVersion,
            'source_season_id' => $sourceSeasonId,
            'target_season_id' => $targetSeasonId,
            'player_id' => (int) $toi->player_id,
            'source_team_id' => $toi->source_team_id === null ? null : (int) $toi->source_team_id,
            'source_team_abbrev' => $toi->source_team_abbrev,
            'target_team_id' => $toi->target_team_id === null ? null : (int) $toi->target_team_id,
            'target_team_abbrev' => $toi->target_team_abbrev,
            'position' => $toi->position,
            'matched_bucket_key' => 'OTHER|profile_tail=projection',
            'fallback_level' => 100,
            'bucket_dimensions' => json_encode(['profile_tail' => 'projection'], JSON_THROW_ON_ERROR),
            'shot_type_group' => 'Other',
            'distance_group' => 'Other',
            'angle_group' => 'Other',
            'sequence_group' => 'Other',
            'source_sat_against_on_ice' => $sourceSat,
            'source_sog_against_on_ice' => (int) $tailRows->sum('source_sog_against_on_ice'),
            'source_goals_against_on_ice' => (int) $tailRows->sum('source_goals_against_on_ice'),
            'source_xga_on_ice' => $sourceXga,
            'source_xsoga_on_ice' => $sourceXsoga,
            'source_profile_share_against' => round((float) $tailRows->sum('source_profile_share_against'), 6),
            'projected_sata' => round((float) $tailRows->sum('projected_sata'), 2),
            'projected_soga' => round((float) $tailRows->sum('projected_soga'), 2),
            'projected_xga' => round((float) $tailRows->sum('projected_xga'), 4),
            'projected_xsoga' => round((float) $tailRows->sum('projected_xsoga'), 4),
            'projected_profile_share_against' => round((float) $tailRows->sum('projected_profile_share_against'), 6),
            'goal_probability_against' => round($sourceXga / $sourceSat, 6),
            'shot_on_goal_probability_against' => round($sourceXsoga / $sourceSat, 6),
            'confidence_score' => round((float) $tailRows->avg('confidence_score'), 4),
            'confidence_bucket' => 'tail',
            'projection_inputs' => json_encode([
                'method' => 'skater_defensive_profile_bucket_scaled_by_toi_projection_other_tail',
                'tail_bucket_count' => $tailRows->count(),
            ], JSON_THROW_ON_ERROR),
            'flags' => json_encode(['projection_profile_tail_other'], JSON_THROW_ON_ERROR),
            'metadata' => json_encode([
                'builder' => 'NhlSkaterDefensiveChanceProjectionBuilder',
                'retention' => [
                    'coverage_target' => self::PROJECTION_BUCKET_COVERAGE_TARGET,
                    'minimum_projected_sata' => self::MIN_PROJECTED_BUCKET_SATA,
                    'minimum_projected_share' => self::MIN_PROJECTED_BUCKET_SHARE,
                    'bucket_role' => 'other_tail',
                    'tail_bucket_count' => $tailRows->count(),
                ],
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
