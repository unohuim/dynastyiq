<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Selects a game's starting goalie using the canonical prediction priority. */
class NhlStartingGoalieSelector
{
    /**
     * Select a goalie identity using official, observed, season, and workload evidence.
     *
     * @return array<string,mixed>|null
     */
    public function select(
        int $nhlGameId,
        string $teamAbbrev,
        ?string $targetSeasonId = null,
        ?string $goalieProjectionVersion = null,
        mixed $providedGoalieId = null
    ): ?array {
        $teamAbbrev = mb_strtoupper($teamAbbrev);
        if ($providedGoalieId !== null && $providedGoalieId !== '') {
            return $this->result((int) $providedGoalieId, 'provided', 'projected');
        }

        $official = $this->official($nhlGameId, $teamAbbrev);
        if ($official !== null) {
            return $this->result($official, 'nhl_boxscore', 'confirmed');
        }

        $observed = $this->observed($nhlGameId, $teamAbbrev);
        if ($observed !== null) {
            return $observed;
        }

        $targetSeasonId ??= $this->latestTargetSeasonId();
        if ($targetSeasonId === null) {
            return null;
        }

        $goalieProjectionVersion ??= $this->latestGoalieProjectionVersion($targetSeasonId);
        $projected = $goalieProjectionVersion === null
            ? null
            : DB::table('nhl_goalie_season_projections')
                ->where('target_season_id', $targetSeasonId)
                ->where('projection_version', $goalieProjectionVersion)
                ->where('target_team_abbrev', $teamAbbrev)
                ->orderByDesc('projected_starts')
                ->orderByDesc('projected_games')
                ->value('goalie_player_id');
        if ($projected !== null) {
            return $this->result((int) $projected, 'goalie_projection', 'projected');
        }

        if (! Schema::hasTable('nhl_goalie_workload_projections')) {
            return null;
        }

        $workload = DB::table('nhl_goalie_workload_projections')
            ->where('target_season_id', $targetSeasonId)
            ->where('target_team_abbrev', $teamAbbrev)
            ->orderByDesc('projected_starts')
            ->orderByDesc('projected_games')
            ->value('goalie_player_id');

        return $workload === null
            ? null
            : $this->result((int) $workload, 'workload_projection', 'projected');
    }

    private function official(int $nhlGameId, string $teamAbbrev): ?int
    {
        if (! Schema::hasTable('nhl_game_summaries') || ! Schema::hasColumn('nhl_game_summaries', 'goalie_started')) {
            return null;
        }

        $game = DB::table('nhl_games')->where('nhl_game_id', $nhlGameId)->first();
        if ($game === null) {
            return null;
        }
        $teamId = $game->home_team_abbrev === $teamAbbrev ? $game->home_team_id : $game->away_team_id;
        $goalieId = DB::table('nhl_game_summaries')
            ->where('nhl_game_id', $nhlGameId)
            ->where('nhl_team_id', $teamId)
            ->where('goalie_started', true)
            ->value('nhl_player_id');

        return $goalieId === null ? null : (int) $goalieId;
    }

    /** @return array<string,mixed>|null */
    private function observed(int $nhlGameId, string $teamAbbrev): ?array
    {
        if (! Schema::hasTable('nhl_starting_goalie_observations')) {
            return null;
        }

        $row = DB::table('nhl_starting_goalie_observations')
            ->where('nhl_game_id', $nhlGameId)
            ->where('team_abbrev', $teamAbbrev)
            ->whereIn('status', ['confirmed', 'expected'])
            ->whereNotNull('nhl_player_id')
            ->orderByRaw("CASE WHEN status = 'confirmed' THEN 0 ELSE 1 END")
            ->orderByDesc('fetched_at')
            ->first();

        return $row === null ? null : [
            ...$this->result((int) $row->nhl_player_id, 'starting_goalie_observation', (string) $row->status),
            'name' => $row->player_name,
            'provider' => $row->provider,
            'observed_at' => $row->fetched_at,
        ];
    }

    /** @return array<string,mixed> */
    private function result(int $goalieId, string $source, string $status): array
    {
        $player = DB::table('players')->where('nhl_id', $goalieId)->first(['full_name', 'head_shot_url']);

        return [
            'nhl_player_id' => $goalieId,
            'name' => $player?->full_name ?? (string) $goalieId,
            'avatar_url' => $player?->head_shot_url,
            'status' => $status,
            'selection_source' => $source,
        ];
    }

    private function latestTargetSeasonId(): ?string
    {
        return Schema::hasTable('nhl_goalie_season_projections')
            ? DB::table('nhl_goalie_season_projections')->max('target_season_id')
            : null;
    }

    private function latestGoalieProjectionVersion(string $targetSeasonId): ?string
    {
        if (! Schema::hasTable('nhl_goalie_season_projections')
            || ! Schema::hasTable('nhl_goalie_projection_chance_buckets')) {
            return null;
        }

        return DB::table('nhl_goalie_season_projections as projections')
            ->join('nhl_goalie_projection_chance_buckets as buckets', function ($join): void {
                $join->on('buckets.projection_version', '=', 'projections.projection_version')
                    ->on('buckets.target_season_id', '=', 'projections.target_season_id')
                    ->on('buckets.goalie_player_id', '=', 'projections.goalie_player_id')
                    ->where('buckets.projection_strength', '=', 'ev');
            })
            ->where('projections.target_season_id', $targetSeasonId)
            ->groupBy('projections.projection_version')
            ->orderByDesc(DB::raw('MAX(projections.projected_at)'))
            ->orderByDesc('projections.projection_version')
            ->value('projections.projection_version');
    }
}
