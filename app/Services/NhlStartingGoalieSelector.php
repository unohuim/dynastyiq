<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlStartingGoalieObservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

        // Team-level workload cannot identify which split squad a goalie belongs to.
        $gameDate = DB::table('nhl_games')->where('nhl_game_id', $nhlGameId)->value('game_date');
        if ($gameDate !== null && DB::table('nhl_games')
            ->whereDate('game_date', $gameDate)
            ->where(fn ($query) => $query->where('home_team_abbrev', $teamAbbrev)
                ->orWhere('away_team_abbrev', $teamAbbrev))
            ->count() > 1) {
            return null;
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

        $date = DB::table('nhl_starting_goalie_observations')
            ->where('nhl_game_id', $nhlGameId)
            ->where('team_abbrev', $teamAbbrev)
            ->value('game_date');
        $row = $date === null ? null : $this->rankedObservations(Carbon::parse($date))
            ->first(fn (NhlStartingGoalieObservation $observation): bool => (int) $observation->nhl_game_id === $nhlGameId
                && $observation->team_abbrev === $teamAbbrev
                && $observation->nhl_player_id !== null
                && in_array($observation->status, ['confirmed', 'expected'], true));

        return $row === null ? null : [
            ...$this->result((int) $row->nhl_player_id, 'starting_goalie_observation', (string) $row->status),
            'name' => $row->player_name,
            'provider' => $row->provider,
            'observed_at' => $row->getRawOriginal('fetched_at'),
        ];
    }

    /**
     * Rank current provider evidence consistently for public payloads and predictions.
     * Inspect the entire date before filtering a game: a split-squad conflict may
     * exist only in the other game's row. Historical evidence remains immutable.
     *
     * @return Collection<int, NhlStartingGoalieObservation>
     */
    public function rankedObservations(Carbon $date): Collection
    {
        $rows = NhlStartingGoalieObservation::query()
            ->whereDate('game_date', $date->toDateString())
            ->orderByRaw("CASE status WHEN 'confirmed' THEN 0 WHEN 'expected' THEN 1 ELSE 2 END")
            ->orderByDesc('fetched_at')->orderByDesc('id')->get()
            ->unique(fn (NhlStartingGoalieObservation $row): string => $row->provider . ':'
                . ($row->nhl_game_id ?? $row->game_date->toDateString()) . ':' . $row->team_abbrev);

        $identities = [];
        $normalizer = app(PlayerIdentityNormalizer::class);
        foreach ($rows as $row) {
            if ($row->provider !== 'rotowire' || $row->nhl_game_id === null) {
                continue;
            }

            // Either canonical/provider identity or normalized name can expose a
            // duplicate, including observations recorded before name resolution.
            $keys = array_filter([
                $row->nhl_player_id ? 'nhl:' . $row->nhl_player_id : null,
                $row->provider_player_key ? 'provider:' . $row->provider_player_key : null,
                ($name = $normalizer->compactNormalizedName($row->player_name)) ? 'name:' . $name : null,
            ]);
            foreach ($keys as $key) {
                $identities[$row->team_abbrev . ':' . $key][$row->nhl_game_id][] = $row->id;
            }
        }

        $conflicting = [];
        foreach ($identities as $games) {
            if (count($games) > 1) {
                foreach ($games as $ids) {
                    foreach ($ids as $id) {
                        $conflicting[$id] = true;
                    }
                }
            }
        }

        return $rows->reject(fn (NhlStartingGoalieObservation $row): bool => isset($conflicting[$row->id]))
            ->sortBy(fn (NhlStartingGoalieObservation $row): int => match (true) {
                $row->provider === 'nhl_boxscore' && $row->status === 'confirmed' => 0,
                $row->status === 'confirmed' => 1,
                $row->provider === 'public_lineup' && $row->status === 'expected' => 2,
                $row->status === 'expected' => 3,
                default => 4,
            })->values();
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
