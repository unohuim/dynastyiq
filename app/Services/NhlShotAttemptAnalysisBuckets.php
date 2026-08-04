<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Defines stable rollup bucket SQL for shot-attempt analysis and xG fallback.
 */
class NhlShotAttemptAnalysisBuckets
{
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
                'distance_group' => $this->distanceGroup($tableAlias),
                'angle_group' => $this->angleGroup($tableAlias),
                'sequence_group' => $this->sequenceGroup($tableAlias),
            ],
            4 => [
                'distance_group' => $this->distanceGroup($tableAlias),
                'angle_group' => $this->angleGroup($tableAlias),
            ],
            5 => [
                'shot_type_group' => $this->shotTypeGroup($tableAlias),
                'distance_group' => $this->distanceGroup($tableAlias),
            ],
            6 => [
                'distance_group' => $this->distanceGroup($tableAlias),
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
            "(3, 'L03|distance_group=' || {$distance} || '|angle_group=' || {$angle} || '|sequence_group=' || {$sequence})",
            "(4, 'L04|distance_group=' || {$distance} || '|angle_group=' || {$angle})",
            "(5, 'L05|shot_type_group=' || {$shotType} || '|distance_group=' || {$distance})",
            "(6, 'L06|distance_group=' || {$distance})",
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
            3 => ['distance_group', 'angle_group', 'sequence_group'],
            4 => ['distance_group', 'angle_group'],
            5 => ['shot_type_group', 'distance_group'],
            6 => ['distance_group'],
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
            WHEN {$tableAlias}.abs_shot_angle < 20 THEN 'sharp'
            WHEN {$tableAlias}.abs_shot_angle < 45 THEN 'inside_lane'
            WHEN {$tableAlias}.abs_shot_angle < 70 THEN 'central'
            ELSE 'straight_on'
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
