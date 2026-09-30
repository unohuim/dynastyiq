<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlModelRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Historical rate fallback, expressed in the selected SAT run's bucket vocabulary. */
class NhlHistoricalPredictionService
{
    /** @var array<string,Collection<int,object>> Request-local historical averages. */
    private array $averages = [];

    /** @var array<string,array{toi_seconds:float,profiles:Collection<int,object>}> */
    private array $thirdLineAverages = [];

    /**
     * Aggregate existing evidence confidence using the source's attempt weights.
     *
     * @param Collection<array-key,object> $rows
     */
    public function confidence(Collection $rows, string $weight = 'source_sat', string $score = 'confidence_score'): float
    {
        $total = (float) $rows->sum($weight);
        if ($total <= 0) {
            return 0.0;
        }

        return round(max(0.0, min(1.0, (float) $rows->sum(
            fn (object $row): float => max(0.0, (float) ($row->{$weight} ?? 0))
                * max(0.0, min(1.0, (float) ($row->{$score} ?? 0)))
        ) / $total)), 4);
    }

    /**
     * Estimate a missing defensive player from training-only F3 or D3 peers.
     * Rank the latest-season active skaters within each source team by historical TOI.
     *
     * @return array{toi_seconds:float,profiles:Collection<int,object>}
     */
    public function thirdLineDefense(int $modelId, bool $defenseman): array
    {
        $key = $modelId . ':' . ($defenseman ? 'D3' : 'F3');
        if (isset($this->thirdLineAverages[$key])) {
            return $this->thirdLineAverages[$key];
        }
        $candidates = DB::table('nhl_sat_model_entity_toi_projections')
            ->where('model_run_id', $modelId)->where('profile_type', 'skater_defense')->where('game_type', 2)
            ->where('latest_games', '>', 0)->where('latest_toi_per_game_seconds', '>', 0)
            ->where('projected_toi_per_game_seconds', '>', 0)->get()
            ->filter(fn (object $row): bool => (mb_strtoupper((string) $row->position) === 'D') === $defenseman);
        $peers = $candidates->groupBy('source_team_id')->flatMap(
            fn (Collection $team): Collection => $team->sortBy([
                ['latest_toi_per_game_seconds', 'desc'], ['entity_id', 'asc'],
            ])->values()->slice($defenseman ? 4 : 6, $defenseman ? 2 : 3)
        )->values();
        // If a small model has no complete third line, use its available same-position peers.
        if ($peers->isEmpty()) {
            $peers = $candidates;
        }
        $profiles = $this->profiles($modelId, 'skater_defense', $peers->pluck('entity_id')->all());
        $profiles = $profiles->filter(fn (Collection $rows): bool => (float) $rows->max('source_toi_seconds') > 0);
        $peers = $peers->whereIn('entity_id', $profiles->keys()->all());
        $seconds = (float) $peers->avg('projected_toi_per_game_seconds');
        $exposure = (float) $profiles->sum(fn (Collection $rows): float => (float) $rows->max('source_toi_seconds'));
        $averages = $profiles->flatten(1)->groupBy('matched_bucket_key')->map(function (Collection $rows) use ($exposure): object {
            $row = clone $rows->first();
            foreach (['source_sat', 'source_sog', 'source_goals', 'expected_sog', 'expected_goals'] as $field) {
                $row->{$field} = (float) $rows->sum($field);
            }
            $row->source_toi_seconds = $exposure;
            $row->projection_source = 'third_line_average';

            return $row;
        })->values();
        if ($averages->isEmpty()) {
            $averages = $this->averages($modelId, 'skater_defense')->map(function (object $row): object {
                $copy = clone $row;
                $copy->projection_source = 'third_line_average';

                return $copy;
            });
        }
        // A run with no defensive evidence at all retains the neutral environment.
        // Do not fabricate bucket rates when even its training pool is empty.

        return $this->thirdLineAverages[$key] = [
            'toi_seconds' => $seconds > 0 ? $seconds : ($defenseman ? 960.0 : 810.0),
            'profiles' => $averages,
        ];
    }

    /** Pool training-only evidence; absent entity buckets contribute zero attempts, not zero exposure. */
    public function averages(int $modelId, string $type): Collection
    {
        $key = $modelId . ':' . $type;
        if (isset($this->averages[$key])) {
            return $this->averages[$key];
        }
        $ids = DB::table('nhl_sat_model_entity_profile_buckets')
            ->where('model_run_id', $modelId)->where('profile_type', $type)->where('game_type', 2)
            ->whereNotNull('entity_id')->distinct()->pluck('entity_id')->all();
        $profiles = $this->profiles($modelId, $type, $ids);
        $exposure = (float) $profiles->sum(fn (Collection $rows): float => (float) $rows->max('source_toi_seconds'));

        return $this->averages[$key] = $profiles->flatten(1)->groupBy('matched_bucket_key')
            ->map(function (Collection $rows) use ($exposure): object {
                $row = clone $rows->first();
                foreach (['source_sat', 'source_sog', 'source_goals', 'expected_sog', 'expected_goals'] as $field) {
                    $row->{$field} = (float) $rows->sum($field);
                }
                $row->source_toi_seconds = $exposure;
                $row->projection_source = 'bucket_average';

                return $row;
            })->values();
    }

    /**
     * Resolve observed conversion separately from the availability of an attempt projection.
     *
     * @return array{on_target:float,finishing:float,on_target_source:string,finishing_source:string}
     */
    public function conversion(?object $personal, ?object $average, ?object $modelBaseline = null): array
    {
        $accuracy = (float) ($personal->source_sat ?? 0) > 0 ? $personal : $average;
        $finishing = (float) ($personal->source_sog ?? 0) > 0 ? $personal : $average;

        return [
            'on_target' => max(0.0, min(1.0, (float) ($accuracy->source_sat ?? 0) > 0
                ? (float) $accuracy->source_sog / (float) $accuracy->source_sat
                : (float) ($modelBaseline->sat_probability ?? 0))),
            'finishing' => max(0.0, min(1.0, (float) ($finishing->source_sog ?? 0) > 0
                ? (float) $finishing->source_goals / (float) $finishing->source_sog
                : (float) ($modelBaseline->goal_probability ?? 0))),
            'on_target_source' => $accuracy !== null && $accuracy === $personal ? 'historical_fallback' : 'bucket_average',
            'finishing_source' => $finishing !== null && $finishing === $personal ? 'historical_fallback' : 'bucket_average',
        ];
    }
    /**
     * Read training-only profiles. Never borrow held-out evaluation snapshots.
     *
     * @param array<int,int> $ids
     * @return Collection<int,Collection<int,object>>
     */
    public function profiles(int $modelId, string $type, array $ids): Collection
    {
        if (! Schema::hasTable('nhl_sat_model_entity_profile_buckets')) {
            return collect();
        }

        $run = NhlModelRun::query()->find($modelId);
        $season = collect($run?->train_season_ids ?? [])->sort()->last();
        $rows = collect();
        if ($season !== null && Schema::hasTable('nhl_sat_model_entity_test_profile_buckets')) {
            $rows = DB::table('nhl_sat_model_entity_test_profile_buckets')
                ->where('model_run_id', $modelId)->where('test_season_id', $season)
                ->where('profile_type', $type)->where('game_type', 2)->whereIn('entity_id', $ids)
                ->get()->groupBy('entity_id');
        }
        $missing = array_values(array_diff($ids, $rows->keys()->all()));
        if ($missing !== []) {
            $training = DB::table('nhl_sat_model_entity_profile_buckets')
                ->where('model_run_id', $modelId)->where('profile_type', $type)
                ->where('game_type', 2)->whereIn('entity_id', $missing)->get()->groupBy('entity_id');
            foreach ($training as $id => $profile) {
                $rows->put($id, $profile);
            }
        }

        return $rows;
    }

    /**
     * Scale historical bucket rates by historical-path projected opportunity.
     *
     * @param Collection<int,object> $profiles
     * @return Collection<int,object>
     */
    public function project(Collection $profiles, float $toiSeconds, int $modelId): Collection
    {
        if ($toiSeconds <= 0 || $profiles->isEmpty()
            || $profiles->contains(fn (object $row): bool => (float) ($row->source_toi_seconds ?? 0) <= 0)) {
            return collect();
        }

        return $profiles->map(function (object $row) use ($toiSeconds, $modelId): object {
            $sourceSeconds = (float) ($row->source_toi_seconds ?? 0);
            $multiplier = $sourceSeconds > 0 ? $toiSeconds / $sourceSeconds : 0.0;

            return $this->bucket(
                $row,
                (float) $row->source_sat * $multiplier,
                (float) (($row->profile_type ?? '') === 'skater_defense' ? $row->expected_sog : $row->source_sog) * $multiplier,
                (float) (($row->profile_type ?? '') === 'skater_defense' ? $row->expected_goals : $row->source_goals) * $multiplier,
                $row->projection_source ?? 'historical_fallback',
                $modelId
            );
        });
    }

    /** Common per-game bucket contract used by both prediction services. */
    public function bucket(object $row, float $sat, float $sog, float $goals, string $source, int $modelId): object
    {
        $dimensions = is_string($row->bucket_dimensions ?? null)
            ? json_decode($row->bucket_dimensions, true, 512, JSON_THROW_ON_ERROR)
            : (array) ($row->bucket_dimensions ?? []);

        return (object) [
            'matched_bucket_key' => (string) $row->matched_bucket_key,
            'bucket_dimensions' => $dimensions,
            'shot_type_group' => $dimensions['shot_type_group'] ?? 'Any',
            'distance_group' => $dimensions['distance_group'] ?? 'Any',
            'angle_group' => $dimensions['angle_group'] ?? 'Any',
            'sequence_group' => $dimensions['sequence_group'] ?? 'Any',
            'projected_games' => 1.0,
            'baseline_xsat' => max(0.0, $sat),
            'baseline_xsog' => max(0.0, $sog),
            'baseline_xgf' => max(0.0, $goals),
            'projection_source' => $source,
            'model_run_id' => $modelId,
        ];
    }
}
