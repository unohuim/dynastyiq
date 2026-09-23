<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlModelRun;
use App\Support\Stats\NhleLeagueFactorResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Builds complete, game-specific skater inputs from a reported lineup. */
final class NhlGameLineupProjectionBuilder
{
    private const FORWARD_SECONDS = 10800.0;
    private const DEFENSE_SECONDS = 7200.0;

    public function __construct(private readonly NhleLeagueFactorResolver $factors)
    {
    }

    /**
     * Select the existing TOI-ranked roster without importing or declaring reported evidence.
     *
     * @param array<int,int>|null $rosterIds
     * @return array<int,array<string,mixed>>
     */
    public function projectedRosterPreview(string $targetSeasonId, string $toiProjectionVersion, string $team, ?array $rosterIds = null): array
    {
        $rows = DB::table('nhl_player_toi_projections as toi')
            ->leftJoin('players', 'players.nhl_id', '=', 'toi.player_id')
            ->where('toi.target_season_id', $targetSeasonId)
            ->where('toi.projection_version', $toiProjectionVersion)
            ->where('toi.target_team_abbrev', $team)
            ->when($rosterIds !== null, fn ($query) => $query->whereIn('toi.player_id', $rosterIds))
            ->when($rosterIds === null, fn ($query) => $query->whereNotIn('toi.player_id', $this->unavailablePlayerIds($team)))
            ->whereRaw("UPPER(COALESCE(toi.position, '')) <> 'G'")
            ->orderByDesc('toi.projected_toi_per_game_seconds')->orderBy('toi.player_id')
            ->get(['toi.player_id', 'players.id as dynasty_player_id', 'players.full_name as player_name',
                'toi.position', 'toi.projected_toi_per_game_seconds', 'toi.confidence_score', 'toi.confidence_bucket']);
        $forwards = $rows->filter(fn (object $row): bool => mb_strtoupper((string) $row->position) !== 'D')->take(12);
        $defense = $rows->filter(fn (object $row): bool => mb_strtoupper((string) $row->position) === 'D')->take(6);

        return $forwards->concat($defense)->map(fn (object $row): array => [
            'player_id' => $row->dynasty_player_id === null ? null : (int) $row->dynasty_player_id,
            'nhl_player_id' => (int) $row->player_id,
            'player_name' => $row->player_name ?? (string) $row->player_id,
            'position' => $row->position,
            'projection_source' => $rosterIds === null ? 'projected_roster' : 'nhl_boxscore',
            'baseline_toi_seconds' => $row->projected_toi_per_game_seconds === null
                ? null : round((float) $row->projected_toi_per_game_seconds, 2),
            'confidence_score' => $row->confidence_score === null ? null : round((float) $row->confidence_score, 4),
            'confidence' => $row->confidence_bucket,
        ])->values()->all();
    }

    /** @return array<int,int> */
    public function unavailablePlayerIds(string $team): array
    {
        if (! Schema::hasTable('nhl_player_injuries')) {
            return [];
        }

        return DB::table('nhl_player_injuries')->where('team_abbrev', $team)
            ->where('availability', 'out')
            ->whereIn('evidence_level', ['reported', 'corroborated', 'confirmed_unavailable'])
            ->whereNotNull('nhl_player_id')->pluck('nhl_player_id')
            ->map(fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * Read-only per-team preview, independent of goalie models and game-prediction eligibility.
     *
     * @param array<string,mixed>|null $lineup
     * @return array<string,mixed>
     */
    public function teamPreview(?array $lineup, string $team, string $targetSeasonId, int $gameType, ?int $modelId): array
    {
        $projection = DB::table('nhl_player_season_projections')->where('target_season_id', $targetSeasonId)
            ->orderByDesc('projected_at')->orderByDesc('projection_version')->first();
        $toiVersion = (string) DB::table('nhl_player_toi_projections')->where('target_season_id', $targetSeasonId)
            ->orderByDesc('projected_at')->orderByDesc('projection_version')->value('projection_version');
        $projected = $lineup === null;
        if ($projected) {
            $positions = ['forward' => 0, 'defense' => 0];
            $players = collect($this->projectedRosterPreview($targetSeasonId, $toiVersion, $team))
                ->map(function (array $player) use (&$positions): array {
                    $role = mb_strtoupper((string) $player['position']) === 'D' ? 'defense' : 'forward';
                    $index = $positions[$role]++;
                    $size = $role === 'defense' ? 2 : 3;

                    return [...$player, 'lineup_role' => $role,
                        'line_key' => ($role === 'defense' ? 'D' : 'F') . (intdiv($index, $size) + 1),
                        'slot_index' => ($index % $size) + 1,
                        'resolution_status' => $player['player_id'] === null ? 'unresolved' : 'resolved'];
                })->all();
            $lineup = ['evidence_status' => 'projected', 'players' => $players, 'sources' => [], 'source_count' => 0];
        }
        $sourceSeason = (string) ($projection?->source_season_id
            ?? ((int) substr($targetSeasonId, 0, 4) - 1) . substr($targetSeasonId, 0, 4));
        $predictions = $this->build($lineup, $sourceSeason, $targetSeasonId, (string) ($projection?->projection_version ?? ''), $toiVersion, $gameType);
        // Partial projected rosters remain visible without inventing legacy production inputs.
        $predictions ??= collect($lineup['players'])->whereIn('lineup_role', ['forward', 'defense'])
            ->map(fn (array $player): array => [...$player,
                'projection_source' => null,
                'game_projected_toi_seconds' => $player['baseline_toi_seconds'] ?? null,
            ])->values()->all();
        $predictions = $this->applySatModel($predictions, $modelId, $targetSeasonId, $gameType);

        return ['display_lineup' => $lineup, 'is_projected' => $projected, 'predictions' => $predictions];
    }

    /**
     * Select the newest completed model with usable rate outputs; TOI is optional.
     * The request season is retained for caller compatibility, not model eligibility:
     * a model run's target_season_id identifies its evaluation season.
     */
    public function latestUsableSatModelId(string $targetSeasonId): ?int
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
            foreach (['rate'] as $stage) {
                $done = $metrics[$stage . '_projections_completed_at'] ?? null;
                $started = $metrics[$stage . '_projections_started_at'] ?? null;
                if (! $done || ($started && strtotime($done) < strtotime($started))
                    || (int) ($metrics[$stage . '_projection_entities_queued'] ?? 0) < 1
                    || (int) ($metrics[$stage . '_projection_entities_completed'] ?? 0)
                        !== (int) $metrics[$stage . '_projection_entities_queued']) {
                    continue 2;
                }
            }
            // A missing goal model must not be interpreted as a genuine zero scoring rate.
            if (! DB::table('nhl_expected_goals_models')->where('model_run_id', $run->id)
                ->where('prediction_target', 'goal')->exists()) {
                continue;
            }
            if ($this->satPlayerInputs((int) $run->id)->isNotEmpty()) {
                return (int) $run->id;
            }
        }

        return null;
    }

    /**
     * Apply model-run SAT rates and TOI to an already selected roster, never selecting different players.
     *
     * @param array<int,array<string,mixed>> $players
     * @return array<int,array<string,mixed>>
     */
    public function applySatModel(array $players, ?int $modelId, string $targetSeasonId, int $gameType): array
    {
        $inputs = $modelId === null ? collect() : $this->satPlayerInputs($modelId);
        $modelToi = $modelId === null ? collect() : DB::table('nhl_sat_model_entity_toi_projections')
            ->where('model_run_id', $modelId)->where('profile_type', 'skater_offense')->where('game_type', 2)
            ->where('projected_toi_per_game_seconds', '>', 0)
            ->pluck('projected_toi_per_game_seconds', 'entity_id');
        $previousYear = (int) substr($targetSeasonId, 0, 4) - 1;
        $previousSeason = (string) $previousYear . ($previousYear + 1);
        $nhlIdFor = fn (array $player): ?int => isset($player['nhl_player_id']) ? (int) $player['nhl_player_id']
            : (array_key_exists('nhl_player_id', $player) ? null : ($player['player_id'] ?? null));
        $history = DB::table('nhl_season_stats')->where('season_id', $previousSeason)->where('game_type', 2)
            ->whereIn('nhl_player_id', collect($players)->map($nhlIdFor)->filter()->all())
            ->where('gp', '>', 0)->where('toi', '>', 0)
            ->selectRaw('nhl_player_id, SUM(toi) * 1.0 / SUM(gp) AS seconds')->groupBy('nhl_player_id')
            ->pluck('seconds', 'nhl_player_id');
        // Resolve independent anchors first so inferred linemates never feed each other.
        $anchors = collect($players)->map(function (array $player) use ($nhlIdFor, $modelToi, $history): array {
            $id = $nhlIdFor($player);
            $modelSeconds = $id === null ? 0.0 : (float) $modelToi->get($id, 0);
            $historicalSeconds = $id === null ? 0.0 : (float) $history->get($id, 0);

            return ['line_key' => $player['line_key'] ?? null,
                'seconds' => $modelSeconds > 0 ? $modelSeconds : $historicalSeconds,
                'source' => $modelSeconds > 0 ? 'sat_model' : 'previous_season'];
        });
        $rows = collect($players)->map(function (array $player, int $index) use ($inputs, $modelId, $modelToi, $anchors): array {
            $nhlId = array_key_exists('nhl_player_id', $player) ? $player['nhl_player_id'] : ($player['player_id'] ?? null);
            $input = $nhlId === null ? null : $inputs->get((int) $nhlId);
            $anchor = $anchors->get($index);
            $seconds = (float) $anchor['seconds'];
            $source = $anchor['source'];
            if ($seconds <= 0) {
                $peers = $anchors->filter(fn (array $peer, int $key): bool => $key !== $index
                    && $anchor['line_key'] !== null && $peer['line_key'] === $anchor['line_key'] && $peer['seconds'] > 0);
                $seconds = (float) $peers->avg('seconds');
                $source = 'linemate_average';
            }
            if ($seconds <= 0) {
                $seconds = match ($player['line_key'] ?? null) {
                    'F1' => 1200.0, 'F2' => 990.0, 'F3' => 810.0, 'F4' => 510.0,
                    'D1' => 1440.0, 'D2' => 1200.0, 'D3' => 960.0,
                    default => 900.0,
                };
                $source = 'line_estimate';
            }
            $oldSeconds = (float) ($player['game_projected_toi_seconds'] ?? 0);
            foreach (['projected_goals', 'adjusted_xgf_per_game', 'projected_assists', 'projected_sog', 'projected_sat'] as $field) {
                if ($oldSeconds > 0 && isset($player[$field])) {
                    $player[$field] = round((float) $player[$field] * $seconds / $oldSeconds, 4);
                }
            }
            $player['toi_source'] = $source;
            $player['model_projected_toi_per_game_seconds'] = $nhlId !== null && $modelToi->has($nhlId)
                ? (float) $modelToi->get($nhlId) : null;
            $player['game_projected_toi_seconds'] = (int) round($seconds);
            $player['game_projected_toi'] = sprintf('%d:%02d', intdiv((int) round($seconds), 60), (int) round($seconds) % 60);
            if ($input === null || in_array($player['projection_source'] ?? null, ['nhle_non_nhl_history', 'line_peer_average'], true)) {
                return $player;
            }
            $player['projection_source'] = 'sat_model';
            $player['model_run_id'] = $modelId;
            $player['projected_sat_per_60'] = (float) $input->sat_rate;
            $player['projected_sog_per_60'] = (float) $input->sog_rate;
            $player['projected_goals_per_60'] = (float) $input->goal_rate;
            $player['projected_sat'] = round((float) $input->sat_rate * $seconds / 3600, 3);
            $player['projected_sog'] = round((float) $input->sog_rate * $seconds / 3600, 3);
            $player['projected_goals'] = round((float) $input->goal_rate * $seconds / 3600, 4);
            $player['adjusted_xgf_per_game'] = $player['projected_goals'];

            return $player;
        });

        return $this->applyFinalPeerAverages($rows)->all();
    }

    /** Read same-run rate buckets independently of optional TOI rows. */
    private function satPlayerInputs(int $modelId): Collection
    {
        return DB::table('nhl_sat_model_entity_rate_projection_buckets as rates')
            ->where('rates.model_run_id', $modelId)->where('rates.profile_type', 'skater_offense')
            ->where('rates.game_type', 2)
            ->whereNotNull('rates.entity_id')
            ->select('rates.entity_id')
            ->selectRaw('SUM(rates.projected_xsat_per_60) AS sat_rate')
            ->selectRaw('SUM(rates.projected_xsat_per_60 * rates.sat_probability) AS sog_rate')
            ->selectRaw('SUM(rates.projected_xsat_per_60 * rates.sat_probability * rates.goal_probability) AS goal_rate')
            ->groupBy('rates.entity_id')
            ->havingRaw('COUNT(*) = COUNT(rates.projected_xsat_per_60)')
            ->havingRaw('MIN(rates.projected_xsat_per_60) >= 0')
            ->havingRaw('MIN(rates.sat_probability) >= 0 AND MAX(rates.sat_probability) <= 1')
            ->havingRaw('MIN(rates.goal_probability) >= 0 AND MAX(rates.goal_probability) <= 1')
            ->get()->keyBy('entity_id');
    }

    /** @param array<string,mixed>|null $lineup @return array<int,array<string,mixed>>|null */
    public function build(
        ?array $lineup,
        string $sourceSeasonId,
        string $targetSeasonId,
        string $projectionVersion,
        string $toiProjectionVersion,
        int $gameType = 2
    ): ?array {
        $players = collect($lineup['players'] ?? [])->whereIn('lineup_role', ['forward', 'defense'])->values();
        if (! $this->hasUsableLineup($players, $gameType)) {
            return null;
        }

        $ids = $players->pluck('nhl_player_id')->filter()->map(fn (mixed $id): int => (int) $id)->all();
        $projections = DB::table('nhl_player_season_projections')
            ->where('target_season_id', $targetSeasonId)->where('projection_version', $projectionVersion)
            ->whereIn('player_id', $ids)->get()->keyBy('player_id');
        $toi = DB::table('nhl_player_toi_projections')
            ->where('target_season_id', $targetSeasonId)->where('projection_version', $toiProjectionVersion)
            ->whereIn('player_id', $ids)->get()->keyBy('player_id');
        $careerGames = DB::table('nhl_season_stats')->where('game_type', 2)->whereIn('nhl_player_id', $ids)
            ->selectRaw('nhl_player_id, SUM(gp) AS gp')->groupBy('nhl_player_id')->pluck('gp', 'nhl_player_id');
        $nhlHistory = DB::table('nhl_season_stats')->where('season_id', $sourceSeasonId)->where('game_type', 2)
            ->whereIn('nhl_player_id', $ids)->get()->keyBy('nhl_player_id');
        $internalIds = $players->pluck('player_id')->filter()->map(fn (mixed $id): int => (int) $id)->all();
        $nonNhl = DB::table('stats')->where('season_id', $sourceSeasonId)->where('game_type_id', 2)
            ->where('league_abbrev', '<>', 'NHL')->whereIn('player_id', $internalIds)
            ->orderBy('gp')->get()->keyBy('player_id');

        $rows = $players->map(function (array $player) use ($projections, $toi, $careerGames, $nhlHistory, $nonNhl): array {
            $nhlId = empty($player['nhl_player_id']) ? null : (int) $player['nhl_player_id'];
            if ($nhlId === null) {
                return [
                    'player_id' => $player['player_id'] ?? null,
                    'nhl_player_id' => null,
                    'player_name' => $player['player_name'],
                    'position' => $player['lineup_role'],
                    'line_key' => $player['line_key'],
                    'slot_index' => $player['slot_index'],
                    'power_play_unit' => $player['power_play_unit'] ?? null,
                    'penalty_kill_unit' => $player['penalty_kill_unit'] ?? null,
                    'nhl_games_played' => 0,
                    'projection_source' => 'line_peer_average',
                    'nhle_factor' => null,
                    'confidence' => 'low',
                    'confidence_score' => 0.20,
                    'role_weight' => $this->roleWeight($player),
                    'baseline_toi_seconds' => 0.0,
                    'goals_per_game_unscaled' => 0.0,
                    'assists_per_game_unscaled' => 0.0,
                    'sog_per_game_unscaled' => 0.0,
                ];
            }
            $projection = $projections->get($nhlId);
            $toiProjection = $toi->get($nhlId);
            $nhlGames = (int) ($careerGames->get($nhlId) ?? 0);
            $weight = $this->roleWeight($player);
            $source = 'nhl_projection';
            $factor = null;
            $confidence = $projection?->confidence_bucket ?? 'medium';
            $games = max(1.0, (float) ($projection?->projected_games ?? 0));
            $goalRate = (float) ($projection?->projected_goals ?? $projection?->projected_xgf ?? 0) / $games;
            $sogRate = (float) ($projection?->projected_xsog ?? 0) / $games;
            $nhlStat = $nhlHistory->get($nhlId);
            $assistRate = $nhlStat !== null && (int) $nhlStat->gp > 0
                ? (float) $nhlStat->a / (int) $nhlStat->gp : 0.0;

            $historical = $nonNhl->get((int) ($player['player_id'] ?? 0));
            if ($nhlGames < 25 && $historical !== null && (int) $historical->gp > 0) {
                $factorRow = $this->factors->resolve((string) $historical->league_abbrev);
                $factor = $factorRow === null ? null : (float) $factorRow->points_factor;
                if ($factor !== null) {
                    $source = 'nhle_non_nhl_history';
                    $confidence = 'low';
                    $goalRate = ((float) $historical->g / (int) $historical->gp) * $factor;
                    $assistRate = ((float) $historical->a / (int) $historical->gp) * $factor;
                    $sogRate = ((float) $historical->sog / (int) $historical->gp) * $factor;
                }
            }
            if ($projection === null && $source === 'nhl_projection') {
                $source = 'replacement_level';
                $confidence = 'low';
                $goalRate = 0.08;
                $assistRate = 0.12;
                $sogRate = 1.20;
            }

            return [
                'player_id' => $player['player_id'],
                'nhl_player_id' => $nhlId,
                'player_name' => $player['player_name'],
                'position' => $player['lineup_role'],
                'line_key' => $player['line_key'],
                'slot_index' => $player['slot_index'],
                'power_play_unit' => $player['power_play_unit'] ?? null,
                'penalty_kill_unit' => $player['penalty_kill_unit'] ?? null,
                'nhl_games_played' => $nhlGames,
                'projection_source' => $source,
                'nhle_factor' => $factor,
                'confidence' => $confidence,
                'confidence_score' => $source === 'nhl_projection' && $projection?->confidence_score !== null
                    ? (float) $projection->confidence_score : 0.25,
                'role_weight' => $weight,
                'baseline_toi_seconds' => (float) ($toiProjection?->projected_toi_per_game_seconds ?? 0),
                'goals_per_game_unscaled' => $goalRate,
                'assists_per_game_unscaled' => $assistRate,
                'sog_per_game_unscaled' => $sogRate,
            ];
        });

        $rows = $this->applyPeerAverages($rows);

        return $this->applyFinalPeerAverages($this->applyGameToi($rows))->all();
    }

    /** @param Collection<int,array<string,mixed>> $players */
    private function hasUsableLineup(Collection $players, int $gameType = 2): bool
    {
        if ($players->count() !== 18) {
            return false;
        }

        $unresolved = $players->filter(fn (array $player): bool => empty($player['nhl_player_id']));
        if ($gameType !== 1 && $unresolved->contains(fn (array $player): bool => ! in_array($player['line_key'] ?? null, ['F4', 'D3'], true))) {
            return false;
        }

        foreach (['F1' => 3, 'F2' => 3, 'F3' => 3, 'F4' => 3, 'D1' => 2, 'D2' => 2, 'D3' => 2] as $lineKey => $expected) {
            $group = $players->where('line_key', $lineKey);
            if ($group->count() !== $expected || $group->pluck('nhl_player_id')->filter()->isEmpty()) {
                return false;
            }
        }

        return $players->pluck('nhl_player_id')->filter()->unique()->count()
            === $players->pluck('nhl_player_id')->filter()->count();
    }

    /** @param Collection<int,array<string,mixed>> $rows @return Collection<int,array<string,mixed>> */
    private function applyPeerAverages(Collection $rows): Collection
    {
        return $rows->map(function (array $row) use ($rows): array {
            if ($row['projection_source'] !== 'line_peer_average') {
                return $row;
            }

            $peers = $rows->where('line_key', $row['line_key'])
                ->where('projection_source', '!=', 'line_peer_average');
            foreach ([
                'baseline_toi_seconds',
                'goals_per_game_unscaled',
                'assists_per_game_unscaled',
                'sog_per_game_unscaled',
            ] as $field) {
                $row[$field] = (float) $peers->avg($field);
            }

            return $row;
        });
    }

    /** @param Collection<int,array<string,mixed>> $rows @return Collection<int,array<string,mixed>> */
    private function applyFinalPeerAverages(Collection $rows): Collection
    {
        return $rows->map(function (array $row) use ($rows): array {
            if ($row['projection_source'] !== 'line_peer_average') {
                return $row;
            }

            $peers = $rows->where('line_key', $row['line_key'])
                ->where('projection_source', '!=', 'line_peer_average');
            foreach (['game_projected_toi_seconds', 'projected_goals', 'adjusted_xgf_per_game', 'projected_assists', 'projected_sog'] as $field) {
                $row[$field] = $field === 'game_projected_toi_seconds'
                    ? (int) round((float) $peers->avg($field))
                    : round((float) $peers->avg($field), $field === 'projected_sog' ? 3 : 4);
            }
            $seconds = (int) $row['game_projected_toi_seconds'];
            foreach (['projected_sat', 'projected_sat_per_60', 'projected_sog_per_60', 'projected_goals_per_60'] as $field) {
                $values = $peers->pluck($field)->filter(fn ($value): bool => $value !== null);
                $row[$field] = $values->isEmpty() ? null : round((float) $values->avg(), 4);
            }
            $row['game_projected_toi'] = sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);

            return $row;
        });
    }

    /**
     * Build game projections when official boxscore IDs exist before an official lineup observation does.
     *
     * @param array<int,int> $rosterIds
     * @return array<int,array<string,mixed>>|null
     */
    public function buildFromRosterIds(
        array $rosterIds,
        string $sourceSeasonId,
        string $targetSeasonId,
        string $projectionVersion,
        string $toiProjectionVersion
    ): ?array {
        $players = DB::table('nhl_boxscores as boxscores')
            ->leftJoin('players', 'players.nhl_id', '=', 'boxscores.nhl_player_id')
            ->leftJoin('nhl_player_toi_projections as toi', function ($join) use (
                $targetSeasonId,
                $toiProjectionVersion
            ): void {
                $join->on('toi.player_id', '=', 'boxscores.nhl_player_id')
                    ->where('toi.target_season_id', '=', $targetSeasonId)
                    ->where('toi.projection_version', '=', $toiProjectionVersion);
            })
            ->whereIn('boxscores.nhl_player_id', $rosterIds)
            ->whereRaw("UPPER(COALESCE(players.position, boxscores.position, '')) <> 'G'")
            ->selectRaw('boxscores.nhl_player_id')
            ->selectRaw('MAX(players.id) as id')
            ->selectRaw('MAX(COALESCE(players.full_name, boxscores.player_name, boxscores.nhl_player_id::text)) as full_name')
            ->selectRaw('MAX(COALESCE(players.position, boxscores.position)) as position')
            ->selectRaw('MAX(toi.projected_toi_per_game_seconds) as projected_toi_per_game_seconds')
            ->groupBy('boxscores.nhl_player_id')
            ->orderByDesc('projected_toi_per_game_seconds')
            ->orderBy('boxscores.nhl_player_id')
            ->get();
        $forwards = $players->filter(
            fn (object $player): bool => mb_strtoupper((string) $player->position) !== 'D'
        )->take(12)->values();
        $defense = $players->filter(
            fn (object $player): bool => mb_strtoupper((string) $player->position) === 'D'
        )->take(6)->values();

        if ($forwards->count() !== 12 || $defense->count() !== 6) {
            return null;
        }

        $lineupPlayers = $forwards->map(fn (object $player, int $index): array => [
            'player_id' => $player->id === null ? null : (int) $player->id,
            'nhl_player_id' => (int) $player->nhl_player_id,
            'player_name' => (string) $player->full_name,
            'lineup_role' => 'forward',
            'line_key' => 'F' . ((int) floor($index / 3) + 1),
            'slot_index' => ($index % 3) + 1,
        ])->concat($defense->map(fn (object $player, int $index): array => [
            'player_id' => $player->id === null ? null : (int) $player->id,
            'nhl_player_id' => (int) $player->nhl_player_id,
            'player_name' => (string) $player->full_name,
            'lineup_role' => 'defense',
            'line_key' => 'D' . ((int) floor($index / 2) + 1),
            'slot_index' => ($index % 2) + 1,
        ]))->values();

        return $this->build(
            ['players' => $lineupPlayers->all()],
            $sourceSeasonId,
            $targetSeasonId,
            $projectionVersion,
            $toiProjectionVersion
        );
    }

    /** @param array<string,mixed> $player */
    private function roleWeight(array $player): float
    {
        $weights = ['F1' => 1.30, 'F2' => 1.10, 'F3' => 0.90, 'F4' => 0.70, 'D1' => 1.20, 'D2' => 1.00, 'D3' => 0.80];
        $weight = $weights[(string) $player['line_key']] ?? 1.0;
        $weight += match ((int) ($player['power_play_unit'] ?? 0)) { 1 => 0.22, 2 => 0.12, default => 0.0 };
        $weight += match ((int) ($player['penalty_kill_unit'] ?? 0)) { 1 => 0.10, 2 => 0.06, default => 0.0 };

        return $weight;
    }

    /** @param Collection<int,array<string,mixed>> $rows @return Collection<int,array<string,mixed>> */
    private function applyGameToi(Collection $rows): Collection
    {
        return $rows->map(function (array $row) use ($rows): array {
            $group = $row['position'] === 'defense' ? 'defense' : 'forward';
            $groupRows = $rows->where('position', $group);
            $roleSeconds = ($group === 'defense' ? self::DEFENSE_SECONDS : self::FORWARD_SECONDS)
                * ((float) $row['role_weight'] / max(0.01, (float) $groupRows->sum('role_weight')));
            $baseline = (float) $row['baseline_toi_seconds'];
            $gameSeconds = $baseline > 0 ? ($roleSeconds * 0.65) + ($baseline * 0.35) : $roleSeconds;
            $scale = $baseline > 0 ? $gameSeconds / $baseline : $gameSeconds / 900.0;
            $row['game_projected_toi_seconds'] = (int) round($gameSeconds);
            $row['game_projected_toi'] = sprintf('%d:%02d', intdiv((int) round($gameSeconds), 60), (int) round($gameSeconds) % 60);
            $row['projected_goals'] = round((float) $row['goals_per_game_unscaled'] * $scale, 4);
            $row['adjusted_xgf_per_game'] = $row['projected_goals'];
            $row['projected_assists'] = round((float) $row['assists_per_game_unscaled'] * $scale, 4);
            $row['projected_sog'] = round((float) $row['sog_per_game_unscaled'] * $scale, 3);
            unset($row['role_weight'], $row['goals_per_game_unscaled'], $row['assists_per_game_unscaled'], $row['sog_per_game_unscaled']);

            return $row;
        })->values();
    }
}
