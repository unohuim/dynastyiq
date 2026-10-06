<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlModelRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Paired, same-run SAT/60 and TOI/GP predictions; historical fallback stays separate. */
class NhlSatModelPredictionService
{
    private const GOALIE_CONFIDENCE_SAT = 100.0;

    /** Create the static annual prediction assembler. */
    public function __construct(private readonly NhlHistoricalPredictionService $historical)
    {
    }

    /** Choose a finished rate run; player-level missing TOI is handled by the fallback. */
    public function latestModelId(): ?int
    {
        foreach (['nhl_model_runs', 'nhl_expected_goals_models', 'nhl_sat_model_entity_rate_projection_buckets', 'nhl_sat_model_entity_toi_projections'] as $table) {
            if (! Schema::hasTable($table)) {
                return null;
            }
        }
        $runs = NhlModelRun::query()->where('model_family', 'sat')->where('status', 'complete')
            ->orderByDesc('created_at')->orderByDesc('id')->get();
        foreach ($runs as $run) {
            $metrics = $run->metrics ?? [];
            $done = $metrics['rate_projections_completed_at'] ?? null;
            $started = $metrics['rate_projections_started_at'] ?? null;
            if (! $done || ($started && strtotime($done) < strtotime($started))
                || (int) ($metrics['rate_projection_entities_queued'] ?? 0) < 1
                || (int) ($metrics['rate_projection_entities_completed'] ?? 0)
                    !== (int) ($metrics['rate_projection_entities_queued'] ?? 0)) {
                continue;
            }
            if (DB::table('nhl_expected_goals_models')->where('model_run_id', $run->id)
                ->where('prediction_target', 'goal')->exists()
                && DB::table('nhl_sat_model_entity_rate_projection_buckets')->where('model_run_id', $run->id)
                    ->where('profile_type', 'skater_offense')->where('game_type', 2)->exists()) {
                return (int) $run->id;
            }
        }

        return null;
    }

    /**
     * Missing or invalid rows reject the entire player pair, including partial bucket sets.
     *
     * @param array<int,int>|null $ids
     * @return Collection<int,array{toi_seconds:float,buckets:Collection<int,object>,confidence_score:float,sat_rate:float,sog_rate:float,goal_rate:float}>
     */
    public function inputs(int $modelId, ?array $ids = null): Collection
    {
        if ($ids === []) {
            return collect();
        }
        $metrics = NhlModelRun::query()->find($modelId)?->metrics ?? [];
        $started = $metrics['toi_projections_started_at'] ?? null;
        $done = $metrics['toi_projections_completed_at'] ?? null;
        if ($started !== null && ($done === null || strtotime($done) < strtotime($started))) {
            return collect();
        }
        if (array_key_exists('toi_projection_entities_queued', $metrics)
            && (int) ($metrics['toi_projection_entities_completed'] ?? 0) !== (int) $metrics['toi_projection_entities_queued']) {
            return collect();
        }
        $toi = DB::table('nhl_sat_model_entity_toi_projections')->where('model_run_id', $modelId)
            ->where('profile_type', 'skater_offense')->where('game_type', 2)
            ->when($ids !== null, fn ($query) => $query->whereIn('entity_id', $ids))
            ->where('projected_toi_per_game_seconds', '>', 0)->pluck('projected_toi_per_game_seconds', 'entity_id');
        $rows = DB::table('nhl_sat_model_entity_rate_projection_buckets')->where('model_run_id', $modelId)
            ->where('profile_type', 'skater_offense')->where('game_type', 2)
            ->when($ids !== null, fn ($query) => $query->whereIn('entity_id', $ids))
            ->whereNotNull('entity_id')->get()->groupBy('entity_id');

        if ($rows->isEmpty()) {
            return collect();
        }
        $profiles = $this->historical->profiles($modelId, 'skater_offense', $rows->keys()->all());
        $averages = $this->historical->averages($modelId, 'skater_offense')->keyBy('matched_bucket_key');

        return $rows->map(function (Collection $buckets, int $id) use ($toi, $modelId, $profiles, $averages): ?array {
            $seconds = (float) $toi->get($id, 0);
            if ($seconds <= 0 || $buckets->contains(
                fn (object $row): bool => $this->usableRate($row->projected_xsat_per_60 ?? null) === null
            )) {
                return null;
            }
            $projected = $buckets->map(function (object $row) use ($seconds, $modelId, $id, $profiles, $averages): object {
                $sat = (float) $row->projected_xsat_per_60 * $seconds / 3600;
                $conversion = $this->historical->conversion(
                    $profiles->get($id, collect())->firstWhere('matched_bucket_key', $row->matched_bucket_key),
                    $averages->get($row->matched_bucket_key), $row
                );
                $sog = $sat * $conversion['on_target'];
                $goals = $sog * $conversion['finishing'];
                $bucket = $this->historical->bucket($row, $sat, $sog, $goals, 'sat_model', $modelId);
                $bucket->on_target_source = $conversion['on_target_source'];
                $bucket->finishing_source = $conversion['finishing_source'];

                return $bucket;
            });

            return ['toi_seconds' => $seconds, 'buckets' => $projected,
                'confidence_score' => $this->historical->confidence($buckets),
                'sat_rate' => (float) $projected->sum('baseline_xsat') * 3600 / $seconds,
                'sog_rate' => (float) $projected->sum('baseline_xsog') * 3600 / $seconds,
                'goal_rate' => (float) $projected->sum('baseline_xgf') * 3600 / $seconds];
        })->filter();
    }

    /** Return an available predicted /60 rate, retaining zero and rejecting malformed values. */
    private function usableRate(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value >= 0
            ? (float) $value : null;
    }

    /**
     * Assemble a selected roster once, retaining individual provenance and bucket totals.
     *
     * @param array<int,array<string,mixed>> $players
     * @return array{players:array<int,array<string,mixed>>,buckets:Collection<int,object>}
     */
    public function offense(int $modelId, array $players): array
    {
        $ids = collect($players)->pluck('nhl_player_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $model = $this->inputs($modelId, $ids);
        $profiles = $this->historical->profiles($modelId, 'skater_offense', $ids);
        $averages = $this->historical->averages($modelId, 'skater_offense');
        $allBuckets = collect();
        foreach ($players as &$player) {
            $id = (int) ($player['nhl_player_id'] ?? 0);
            $input = $model->get($id);
            $seconds = $input['toi_seconds'] ?? (float) ($player['game_projected_toi_seconds'] ?? $player['baseline_toi_seconds'] ?? 0);
            $buckets = $input['buckets'] ?? $this->historical->project($profiles->get($id, collect()), $seconds, $modelId);
            if ($buckets->isEmpty()) {
                $buckets = $this->historical->project($averages, $seconds, $modelId);
            }
            if ($buckets->isEmpty()) {
                // No historical shape exists: preserve the existing replacement/NHLe totals
                // in Other, without fabricating fine-grained historical observations.
                $buckets = collect([$this->historical->bucket((object) [
                    'matched_bucket_key' => 'Other', 'bucket_dimensions' => [],
                ], (float) ($player['projected_sat'] ?? 0),
                    (float) ($player['projected_sog'] ?? 0), (float) ($player['projected_goals'] ?? 0),
                    'historical_fallback', $modelId)]);
            }
            $player['projection_source'] = $input === null ? ($player['projection_source'] ?? 'historical_fallback') : 'sat_model';
            $player['bucket_projection_source'] = $input === null ? ($buckets->first()->projection_source ?? 'historical_fallback') : 'sat_model';
            $player['on_target_sources'] = $buckets->pluck('on_target_source')->filter()->unique()->values()->all();
            $player['finishing_sources'] = $buckets->pluck('finishing_source')->filter()->unique()->values()->all();
            $player['bucket_model_run_id'] = $modelId;
            $player['game_projected_toi_seconds'] = (int) round($seconds);
            $player['game_projected_toi'] = sprintf('%d:%02d', intdiv((int) round($seconds), 60), (int) round($seconds) % 60);
            $player['toi_source'] = $input === null ? ($player['toi_source'] ?? 'historical_fallback') : 'sat_model';
            $player['model_projected_toi_per_game_seconds'] = $input['toi_seconds'] ?? null;
            foreach (['projected_sat' => 'baseline_xsat', 'projected_sog' => 'baseline_xsog', 'projected_goals' => 'baseline_xgf'] as $field => $bucketField) {
                $player[$field] = round((float) $buckets->sum($bucketField), 6);
            }
            $player['adjusted_xgf_per_game'] = $player['projected_goals'];
            $player['baseline_xgf_per_game'] = $player['projected_goals'];
            $player['confidence_score'] = $input['confidence_score']
                ?? $this->historical->confidence($profiles->get($id, collect()));
            $player['confidence'] = match (true) {
                $player['confidence_score'] >= 0.8 => 'high',
                $player['confidence_score'] >= 0.5 => 'medium',
                default => 'low',
            };
            if ($input !== null) {
                $player['model_run_id'] = $modelId;
                $player['projected_sat_per_60'] = $input['sat_rate'];
                $player['projected_sog_per_60'] = $input['sog_rate'];
                $player['projected_goals_per_60'] = $input['goal_rate'];
            }
            $allBuckets = $allBuckets->concat($buckets);
        }
        unset($player);

        return ['players' => $players, 'buckets' => $this->sumBuckets($allBuckets)];
    }

    /**
     * Defensive /60 projections do not yet exist: retain historical rates and opportunity.
     * Five on-ice skater attributions represent one team attempt in the EV environment.
     *
     * @param array<int,int> $ids
     * @return Collection<int,object>
     */
    public function defense(int $modelId, array $ids, string $targetSeason, string $toiVersion): Collection
    {
        return $this->sumBuckets($this->defensePlayers($modelId, $ids, $targetSeason, $toiVersion)
            ->pluck('buckets')->flatten(1));
    }

    /**
     * Build a historical PP or PK lane and scale its selected players to a
     * league-average team opportunity. This is deliberately separate from
     * the SAT /60 EV projection path.
     *
     * @param array<int,int> $ids
     * @return Collection<int,object>
     */
    public function historicalStrengthBuckets(
        int $modelId,
        array $ids,
        string $profileType,
        string $strength,
        float $teamSeconds,
        int $skatersOnIce
    ): Collection {
        $strength = mb_strtolower($strength);
        $run = NhlModelRun::query()->find($modelId);
        $seasons = $run?->train_season_ids ?? [];
        if ($ids === [] || $seasons === [] || $teamSeconds <= 0) {
            return collect();
        }

        $profiles = DB::table('nhl_sat_model_entity_profile_buckets')
            ->where('model_run_id', $modelId)->where('profile_type', $profileType)
            ->where('game_type', 2)->where('strength', $strength)->whereIn('entity_id', $ids)->get()
            ->groupBy('entity_id');
        if ($profiles->isEmpty()) {
            return collect();
        }
        $seconds = DB::table('nhl_player_game_strength_summaries as summaries')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'summaries.nhl_game_id')
            ->whereIn('summaries.nhl_player_id', $profiles->keys())
            ->whereIn('games.season_id', $seasons)->where('games.game_type', 2)
            ->where('summaries.strength', mb_strtoupper($strength))
            ->groupBy('summaries.nhl_player_id')->selectRaw('summaries.nhl_player_id, SUM(summaries.toi) as seconds')
            ->pluck('seconds', 'nhl_player_id');
        $totalSeconds = (float) $seconds->sum();
        if ($totalSeconds <= 0) {
            return collect();
        }
        $scale = ($teamSeconds * $skatersOnIce) / $totalSeconds;

        $buckets = $profiles->flatMap(function (Collection $rows, int $id) use ($seconds, $scale, $modelId, $strength): Collection {
            $sourceSeconds = (float) $seconds->get($id, 0);
            if ($sourceSeconds <= 0) {
                return collect();
            }
            return $rows->map(function (object $row) use ($sourceSeconds, $scale, $modelId, $strength): object {
                $bucket = $this->historical->bucket($row,
                    (float) $row->source_sat * $scale,
                    (float) $row->source_sog * $scale,
                    (float) $row->source_goals * $scale,
                    'historical_' . $strength, $modelId);
                $bucket->bucket_dimensions['strength_group'] = mb_strtoupper($strength);
                return $bucket;
            });
        })->values();

        return $this->sumBuckets($buckets);
    }

    /** League-average team seconds for one PP or PK opportunity lane. */
    public function leagueStrengthSeconds(int $modelId, string $strength, int $skatersOnIce): float
    {
        $seasons = NhlModelRun::query()->find($modelId)?->train_season_ids ?? [];
        if ($seasons === []) {
            return 0.0;
        }
        $rows = DB::table('nhl_player_game_strength_summaries as summaries')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'summaries.nhl_game_id')
            ->whereIn('games.season_id', $seasons)->where('games.game_type', 2)
            ->where('summaries.strength', mb_strtoupper($strength))
            ->groupBy('summaries.nhl_game_id', 'summaries.team_id')
            ->selectRaw('SUM(summaries.toi) / ? as seconds', [$skatersOnIce])->get();
        return $rows->isEmpty() ? 0.0 : (float) $rows->avg('seconds');
    }

    /**
     * Keep individual defensive inputs alongside their composed team buckets.
     *
     * @param array<int,int> $ids
     * @return Collection<int,array<string,mixed>>
     */
    public function defensePlayers(int $modelId, array $ids, string $targetSeason, string $toiVersion): Collection
    {
        $profiles = $this->historical->profiles($modelId, 'skater_defense', $ids)
            ->map(function (Collection $rows): Collection {
                $ev = $rows->filter(fn (object $row): bool => mb_strtolower((string) ($row->strength ?? '')) === 'ev');

                // Older completed runs have no strength partition. Preserve their
                // historical fallback rather than dropping defensive evidence.
                return $ev->isNotEmpty() ? $ev->values() : $rows;
            });
        $positions = DB::table('players')->whereIn('nhl_id', $ids)
            ->get(['nhl_id', 'pos_type', 'position'])->mapWithKeys(
                fn (object $player): array => [$player->nhl_id => $player->pos_type ?: $player->position]
            );
        $toi = DB::table('nhl_player_toi_projections')->where('target_season_id', $targetSeason)
            ->where('projection_version', $toiVersion)->whereIn('player_id', $ids)
            ->pluck('projected_toi_per_game_seconds', 'player_id');
        $rows = collect();
        $modelInputs = $this->inputs($modelId, $ids);
        foreach ($ids as $id) {
            $profile = $profiles->get($id, collect());
            $seconds = $modelInputs->get($id)['toi_seconds'] ?? (float) $toi->get($id, 0);
            $projected = $this->historical->project($profile, $seconds / 5, $modelId);
            $fallbackUsed = $projected->isEmpty();
            if ($projected->isEmpty()) {
                $fallback = $this->historical->thirdLineDefense($modelId, mb_strtoupper((string) $positions->get($id)) === 'D');
                $seconds = $fallback['toi_seconds'];
                $projected = $this->historical->project($fallback['profiles'], $seconds / 5, $modelId);
            }
            $projected = $projected->map(function (object $row) use ($seconds): object {
                $row->defensive_toi_seconds = $seconds;

                return $row;
            });
            $rows->push([
                'nhl_player_id' => (int) $id,
                'defensive_toi_seconds' => $seconds,
                'projected_sata' => (float) $projected->sum('baseline_xsat') * 5,
                'projected_soga' => (float) $projected->sum('baseline_xsog') * 5,
                'projected_xga' => (float) $projected->sum('baseline_xgf') * 5,
                'confidence_score' => $fallbackUsed ? 0.0 : $this->historical->confidence($profile),
                'projection_source' => $fallbackUsed ? 'third_line_average' : 'historical_fallback',
                'buckets' => $projected,
            ]);
        }

        return $rows;
    }

    /**
     * Add independently weighted offensive attempts and exact-matching defensive attempts.
     * Retain every offensive key and its conversion; never introduce defense-only buckets
     * or normalize the weights or resulting volume back to the offensive total.
     *
     * @param Collection<int,object> $offense
     * @param Collection<int,object> $defense
     * @return Collection<int,array<string,mixed>>
     */
    public function environment(int $modelId, Collection $offense, Collection $defense, float $offenseWeight = 0.88, float $defenseWeight = 0.02): Collection
    {
        $offense = $offense->keyBy('matched_bucket_key');
        $defense = $defense->keyBy('matched_bucket_key');
        $rows = $offense->keys()->map(function (string $key) use ($offense, $defense, $offenseWeight, $defenseWeight): array {
            $attack = $offense->get($key);
            $allowed = $defense->get($key);
            $dimensions = $attack;
            $sat = (float) ($attack->baseline_xsat ?? 0);
            $sog = (float) ($attack->baseline_xsog ?? 0);
            $goals = (float) ($attack->baseline_xgf ?? 0);
            $defenseWeight = $allowed === null ? 0.0 : $defenseWeight;
            $adjustedSat = $offenseWeight * $sat + $defenseWeight * (float) ($allowed->baseline_xsat ?? 0);
            $onTarget = $sat > 0 ? min(1.0, $sog / $sat) : 0.0;
            $finishing = $sog > 0 ? min(1.0, $goals / $sog) : 0.0;
            $adjustedSog = $adjustedSat * $onTarget;
            $adjustedGoals = $adjustedSog * $finishing;

            return [
                'matched_bucket_key' => $key,
                'bucket_dimensions' => $dimensions->bucket_dimensions ?? [],
                'shot_type_group' => $dimensions->shot_type_group,
                'distance_group' => $dimensions->distance_group,
                'angle_group' => $dimensions->angle_group,
                'sequence_group' => $dimensions->sequence_group,
                'baseline_xsat' => $sat, 'baseline_xsog' => $sog, 'baseline_xgf' => $goals,
                'adjusted_xsat' => $adjustedSat, 'adjusted_xsog' => $adjustedSog, 'adjusted_xgf' => $adjustedGoals,
                'xsat_delta' => $adjustedSat - $sat, 'xsog_delta' => $adjustedSog - $sog, 'xgf_delta' => $adjustedGoals - $goals,
                'environment_offense_weight' => $offenseWeight,
                'environment_defense_weight' => $defenseWeight,
                'suppressed' => $adjustedSat < $sat,
            ];
        });
        $total = (float) $rows->sum('adjusted_xsat');

        return $rows->map(fn (array $row): array => [...$row, 'offense_share' => $total > 0 ? $row['adjusted_xsat'] / $total : 0.0])
            ->sortByDesc('adjusted_xgf')->values();
    }

    /** @param Collection<int,object> $rows @return Collection<int,object> */
    public function sumBuckets(Collection $rows): Collection
    {
        return $rows->groupBy('matched_bucket_key')->map(function (Collection $group): object {
            $row = clone $group->first();
            foreach (['baseline_xsat', 'baseline_xsog', 'baseline_xgf'] as $field) {
                $row->{$field} = (float) $group->sum($field);
            }

            return $row;
        })->values();
    }

    /**
     * Build goalie response in exactly the environment's SAT-model buckets.
     * Returned volumes are per game; persisted season builds scale them by workload.
     *
     * @param Collection<int,object> $environment
     * @return Collection<string,object>
     */
    public function goalieBuckets(int $modelId, int $goalieId, Collection $environment): Collection
    {
        $profiles = $this->historical->profiles($modelId, 'goalie_faced', [$goalieId])->get($goalieId, collect());
        $evSkill = app(NhlGoalieEvSkillProjectionService::class)->adjustment($modelId, $goalieId);

        return $environment->map(function (object $bucket) use ($profiles, $evSkill): object {
            $bucketKey = (string) $bucket->matched_bucket_key;
            $profileKey = preg_replace('/^(EV|PP|PK)\\|/', '', $bucketKey) ?? $bucketKey;
            $strength = mb_strtoupper((string) ($bucket->bucket_dimensions['strength_group'] ?? ''));
            $matchingProfiles = $profiles->where('matched_bucket_key', $profileKey);
            $profile = $strength === 'EV'
                ? ($matchingProfiles->first(fn (object $row): bool => mb_strtolower((string) ($row->strength ?? '')) === 'ev')
                    ?? $matchingProfiles->first())
                : $matchingProfiles->first();
            $exact = $profile !== null;
            $sat = $profile === null ? 0.0 : (float) $profile->source_sat;
            // Stored goalie profile confidence remains the ceiling; 100 historical
            // attempts provide full same-scale evidence as skater buckets.
            $confidence = $profile === null ? 0.0 : max(0.0, min(
                1.0, (float) $profile->confidence_score, sqrt(max(0.0, $sat) / self::GOALIE_CONFIDENCE_SAT)
            ));
            $gsaxPerSat = $sat > 0 ? ((float) $profile->expected_goals - (float) $profile->source_goals) / $sat : 0.0;
            $skillWeight = $confidence * ($strength === 'PP' ? 0.25 : 1.0);
            $xga = (float) $bucket->baseline_xgf;
            // An all-strength or unlabelled environment cannot safely consume an
            // EV-only goalie signal. It remains on the established bucket path.
            $isEv = $strength === 'EV';
            $usesEvSkill = $isEv && $evSkill !== null;
            $usesLeagueStrengthGoalie = in_array($strength, ['PP', 'PK'], true);
            $ga = $usesLeagueStrengthGoalie
                ? $xga
                : ($usesEvSkill
                ? min((float) $bucket->baseline_xsog, max(0.0, $xga * (1.0 - $evSkill['adjustment'])))
                : min((float) $bucket->baseline_xsog, max(0.0, $xga - (float) $bucket->baseline_xsat * $gsaxPerSat * $skillWeight)));

            return (object) [
                ...get_object_vars($bucket),
                'projected_xga' => $xga, 'projected_ga' => $ga,
                'projected_sata' => (float) $bucket->baseline_xsat,
                'projected_soga' => (float) $bucket->baseline_xsog,
                'projected_gsax' => $xga - $ga,
                'projected_profile_share' => null,
                // Skill is already shrunk above; do not shrink it twice in matchup assembly.
                'confidence_score' => 1.0,
                'source_confidence_score' => $confidence,
                'source_sat_against' => (int) $sat,
                'source_sog_against' => (int) ($profile->source_sog ?? 0),
                'source_goals_against' => (int) ($profile->source_goals ?? 0),
                'source_xga' => $profile->expected_goals ?? null,
                'source_xsoga' => $profile->expected_sog ?? null,
                'goalie_skill_source' => $usesLeagueStrengthGoalie ? 'league_average_strength' : ($usesEvSkill ? 'ev_gsax_skill' : ($profile === null ? 'neutral_missing_bucket' : ($exact ? 'model_history' : 'model_history_fallback'))),
                'goalie_ev_skill_adjustment' => $usesEvSkill ? $evSkill['adjustment'] : null,
                'goalie_ev_gsax_edge' => $usesEvSkill ? $evSkill['gsax_edge'] : null,
                'goalie_ev_history_seasons' => $usesEvSkill ? $evSkill['history_seasons'] : null,
            ];
        })->keyBy('matched_bucket_key');
    }
}
