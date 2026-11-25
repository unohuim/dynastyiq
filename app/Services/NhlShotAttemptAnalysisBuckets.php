<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Defines stable rollup bucket SQL for shot-attempt analysis and xG fallback.
 */
class NhlShotAttemptAnalysisBuckets
{
    /** Normalize shooter-side strength without confusing goalie/team perspectives. */
    public function strengthSql(string $tableAlias): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/i', $tableAlias) !== 1) {
            throw new \InvalidArgumentException('Invalid shot-fact table alias.');
        }

        return "CASE WHEN LOWER({$tableAlias}.strength) IN ('ev','pp','pk')
            THEN LOWER({$tableAlias}.strength)
            WHEN LOWER({$tableAlias}.strength_bucket) IN ('ev','pp','pk')
            THEN LOWER({$tableAlias}.strength_bucket) ELSE 'all' END";
    }

    /**
     * Resolve one row, preferring same-strength shape/baseline before combined fallback.
     * Expressions are generated internally; identifiers and model IDs are bounded here.
     */
    public function probabilityBucketJoinSql(int $modelId, string $bucketKeySql, string $alias, string $factAlias): string
    {
        if ($modelId < 1 || preg_match('/^[a-z_][a-z0-9_]*$/i', $alias) !== 1) {
            throw new \InvalidArgumentException('Invalid probability bucket lookup.');
        }

        return 'LEFT JOIN LATERAL (' . $this->probabilityBucketSelectSql($modelId, $bucketKeySql, $factAlias)
            . ") {$alias} ON true";
    }

    /** Share single-row lookup SQL with query-builder readers. */
    public function probabilityBucketSelectSql(int $modelId, string $bucketKeySql, string $factAlias): string
    {
        if ($modelId < 1) {
            throw new \InvalidArgumentException('Invalid probability model ID.');
        }
        $strength = $this->strengthSql($factAlias);

        return "SELECT probability_buckets.* FROM nhl_expected_goals_model_buckets probability_buckets
            WHERE probability_buckets.expected_goals_model_id = {$modelId}
                AND probability_buckets.bucket_key IN ({$bucketKeySql}, 'L99|baseline=league')
                AND probability_buckets.strength IN ({$strength}, 'all')
            ORDER BY CASE WHEN probability_buckets.strength = {$strength} THEN 0 ELSE 1 END,
                CASE WHEN probability_buckets.bucket_key = {$bucketKeySql} THEN 0 ELSE 1 END
            LIMIT 1";
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function aggregateDefinitions(string $tableAlias = 'nhl_shot_attempts_facts'): array
    {
        return [
            1 => [
                'shot_type_group' => $this->shotTypeGroup($tableAlias),
                'distance_group' => $this->distanceGroup($tableAlias),
                'angle_group' => $this->angleGroup($tableAlias),
                'sequence_group' => $this->sequenceGroup($tableAlias),
            ],
            2 => [
                'shot_type_group' => $this->shotTypeGroup($tableAlias),
                'distance_group' => $this->distanceGroup($tableAlias),
                'angle_group' => $this->angleGroup($tableAlias),
            ],
            3 => [
                'shot_type_group' => $this->shotTypeGroup($tableAlias),
                'distance_group' => $this->distanceGroup($tableAlias),
            ],
            4 => [
                'shot_type_group' => $this->shotTypeGroup($tableAlias),
            ],
            99 => [
                'baseline' => "'league'",
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function candidateBucketKeySql(string $tableAlias = 'facts'): array
    {
        $shotType = $this->shotTypeGroup($tableAlias);
        $distance = $this->distanceGroup($tableAlias);
        $angle = $this->angleGroup($tableAlias);
        $sequence = $this->sequenceGroup($tableAlias);

        return [
            "(1, 'L01|shot_type_group=' || {$shotType} || '|distance_group=' || {$distance} || '|angle_group=' || {$angle} || '|sequence_group=' || {$sequence})",
            "(2, 'L02|shot_type_group=' || {$shotType} || '|distance_group=' || {$distance} || '|angle_group=' || {$angle})",
            "(3, 'L03|shot_type_group=' || {$shotType} || '|distance_group=' || {$distance})",
            "(4, 'L04|shot_type_group=' || {$shotType})",
            "(99, 'L99|baseline=league')",
        ];
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function fallbackDefinitions(): array
    {
        return [
            1 => ['shot_type_group', 'distance_group', 'angle_group', 'sequence_group'],
            2 => ['shot_type_group', 'distance_group', 'angle_group'],
            3 => ['shot_type_group', 'distance_group'],
            4 => ['shot_type_group'],
            99 => ['baseline'],
        ];
    }

    private function shotTypeGroup(string $tableAlias): string
    {
        return "CASE
            WHEN COALESCE(NULLIF({$tableAlias}.shot_type_bucket, ''), 'unknown') IN ('wrist', 'snap', 'slap', 'backhand', 'tip') THEN COALESCE(NULLIF({$tableAlias}.shot_type_bucket, ''), 'unknown')
            WHEN COALESCE(NULLIF({$tableAlias}.shot_type_bucket, ''), 'unknown') = 'unknown' THEN 'unknown'
            ELSE 'other_chaos'
        END";
    }

    private function distanceGroup(string $tableAlias): string
    {
        return "CASE
            WHEN {$tableAlias}.shot_distance IS NULL THEN 'unknown'
            WHEN {$tableAlias}.shot_distance < 10 THEN 'net_front'
            WHEN {$tableAlias}.shot_distance < 25 THEN 'slot'
            WHEN {$tableAlias}.shot_distance < 40 THEN 'mid_range'
            WHEN {$tableAlias}.shot_distance < 60 THEN 'point_or_high'
            ELSE 'long'
        END";
    }

    private function angleGroup(string $tableAlias): string
    {
        return "CASE
            WHEN {$tableAlias}.abs_shot_angle IS NULL THEN 'unknown'
            WHEN {$tableAlias}.abs_shot_angle < 20 THEN 'straight_on'
            WHEN {$tableAlias}.abs_shot_angle < 45 THEN 'inside_lane'
            WHEN {$tableAlias}.abs_shot_angle < 70 THEN 'wide'
            ELSE 'sharp'
        END";
    }

    private function sequenceGroup(string $tableAlias): string
    {
        return "CASE
            WHEN {$tableAlias}.is_rush AND {$tableAlias}.is_rebound THEN 'rush_rebound'
            WHEN {$tableAlias}.is_rebound THEN 'rebound'
            WHEN {$tableAlias}.is_rush THEN 'rush'
            ELSE 'settled'
        END";
    }
}
