<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlCurrentLineup;
use App\Models\NhlLineupObservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Builds partner-facing anticipated-lineup payloads from current projections. */
class NhlAnticipatedLineupPayload
{
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
}
