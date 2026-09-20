<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlCurrentLineup;
use App\Models\NhlGame;
use App\Models\NhlLineupObservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Builds public and partner-facing anticipated-lineup payloads from current projections. */
class NhlAnticipatedLineupPayload
{
    /** @return array<string,mixed> */
    public function page(Carbon $date): array
    {
        $lineups = collect($this->build($date)['anticipated_lineups'])
            ->keyBy(fn (array $lineup): string => $lineup['nhl_game_id'] . ':' . $lineup['team_abbrev']);
        $games = NhlGame::query()
            ->whereDate('game_date', $date->toDateString())
            ->orderBy('start_time_utc')
            ->orderBy('nhl_game_id')
            ->get()
            ->map(fn (NhlGame $game): array => $this->game($game, $lineups));

        return [
            'games' => $games,
            'meta' => [
                'date' => $date->toDateString(),
                'count' => $games->count(),
                'generated_at' => now()->toIso8601String(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function gamePage(int $nhlGameId): array
    {
        $game = NhlGame::query()->findOrFail($nhlGameId);
        $lineups = collect($this->build($game->game_date, $nhlGameId)['anticipated_lineups'])
            ->keyBy(fn (array $lineup): string => $lineup['nhl_game_id'] . ':' . $lineup['team_abbrev']);

        return ['game' => $this->game($game, $lineups)];
    }

    /** @return array<string,mixed> */
    public function build(Carbon $date, ?int $nhlGameId = null): array
    {
        $gameIds = DB::table('nhl_games')->whereDate('game_date', $date->toDateString())
            ->when($nhlGameId, fn ($query) => $query->where('nhl_game_id', $nhlGameId))
            ->pluck('nhl_game_id');
        $rows = NhlCurrentLineup::query()->with(['observation.players', 'observation.source'])
            ->whereIn('nhl_game_id', $gameIds)->orderBy('nhl_game_id')->orderBy('team_abbrev')->get();

        return [
            'anticipated_lineups' => $rows->map(fn (NhlCurrentLineup $row): array => $this->lineup($row))->values(),
            'meta' => [
                'date' => $date->toDateString(),
                'nhl_game_id' => $nhlGameId,
                'count' => $rows->count(),
                'generated_at' => now()->toIso8601String(),
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public function forGameTeam(int $nhlGameId, string $teamAbbrev, bool $requireCorroboration = true): ?array
    {
        $row = NhlCurrentLineup::query()->with(['observation.players', 'observation.source'])
            ->where('nhl_game_id', $nhlGameId)->where('team_abbrev', mb_strtoupper($teamAbbrev))
            ->when($requireCorroboration, fn ($query) => $query->whereIn('evidence_status', ['corroborated', 'strongly_corroborated']))
            ->first();

        return $row ? $this->lineup($row) : null;
    }

    /** @return array<string,mixed> */
    private function lineup(NhlCurrentLineup $row): array
    {
        $observation = $row->observation;
        $cutoff = ($observation->provider_published_at ?? $observation->observed_at)->copy()->subHours(6);
        $sources = NhlLineupObservation::query()->with('source')
            ->where('nhl_game_id', $row->nhl_game_id)->where('team_id', $row->team_id)
            ->where('structure_hash', $row->structure_hash)
            ->where(fn ($query) => $query->where('provider_published_at', '>=', $cutoff)
                ->orWhere(fn ($fallback) => $fallback->whereNull('provider_published_at')->where('observed_at', '>=', $cutoff)))
            ->get()->unique('source_id');

        return [
            'nhl_game_id' => $row->nhl_game_id,
            'team_id' => $row->team_id,
            'team_abbrev' => $row->team_abbrev,
            'evidence_status' => $row->evidence_status,
            'source_count' => $row->source_count,
            'first_observed_at' => $row->first_observed_at?->toIso8601String(),
            'last_observed_at' => $row->last_observed_at?->toIso8601String(),
            'players' => $observation->players->sortBy(fn ($player): string => sprintf('%s:%02d', $player->line_key, $player->slot_index))
                ->map(fn ($player): array => [
                    'player_id' => $player->player_id,
                    'nhl_player_id' => $player->nhl_player_id,
                    'player_name' => $player->player_name,
                    'lineup_role' => $player->lineup_role,
                    'line_key' => $player->line_key,
                    'slot_index' => $player->slot_index,
                    'resolution_status' => $player->resolution_status,
                ])->values(),
            'sources' => $sources->map(fn (NhlLineupObservation $sourceObservation): array => [
                'source_id' => $sourceObservation->source_id,
                'name' => $sourceObservation->source->name,
                'handle' => $sourceObservation->source->handle,
                'platform' => $sourceObservation->source->platform,
                'post_url' => $sourceObservation->post_url,
                'published_at' => $sourceObservation->provider_published_at?->toIso8601String(),
                'observed_at' => $sourceObservation->observed_at?->toIso8601String(),
                'engagement' => [
                    'likes' => $sourceObservation->like_count,
                    'replies' => $sourceObservation->reply_count,
                    'reposts' => $sourceObservation->repost_count,
                    'views' => $sourceObservation->view_count,
                ],
            ])->values(),
        ];
    }

    /**
     * @param Collection<string,array<string,mixed>> $lineups
     * @return array<string,mixed>
     */
    private function game(NhlGame $game, Collection $lineups): array
    {
        return [
            'nhl_game_id' => $game->nhl_game_id,
            'game_date' => $game->game_date->toDateString(),
            'start_time_utc' => $game->start_time_utc?->toIso8601String(),
            'game_type' => $game->game_type,
            'away' => [
                'team_id' => $game->away_team_id,
                'team_abbrev' => $game->away_team_abbrev,
                'team_name' => $game->away_team_common_name,
                'team_logo' => $game->away_team_logo,
                'lineup' => $lineups->get($game->nhl_game_id . ':' . $game->away_team_abbrev),
            ],
            'home' => [
                'team_id' => $game->home_team_id,
                'team_abbrev' => $game->home_team_abbrev,
                'team_name' => $game->home_team_common_name,
                'team_logo' => $game->home_team_logo,
                'lineup' => $lineups->get($game->nhl_game_id . ':' . $game->home_team_abbrev),
            ],
        ];
    }
}
