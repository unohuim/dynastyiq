<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Server-to-server NHL reference data endpoints for partner ingestion.
 */
class NhlReferenceController extends Controller
{
    public function teams(): JsonResponse
    {
        $teams = DB::table('nhl_teams')
            ->orderBy('abbrev')
            ->get()
            ->map(fn (object $team): array => $this->teamPayload($team))
            ->values();

        return response()->json(['data' => $teams]);
    }

    public function players(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 500), 500));
        $currentSeasonKey = $this->currentNhlSeasonKey();
        $currentCapHits = DB::table('contracts')
            ->join('contract_seasons', 'contract_seasons.contract_id', '=', 'contracts.id')
            ->where('contract_seasons.season_key', $currentSeasonKey)
            ->groupBy('contracts.player_id')
            ->select([
                'contracts.player_id',
                DB::raw('MAX(contract_seasons.cap_hit) as current_cap_hit'),
            ]);

        $players = DB::table('players')
            ->leftJoin('nhl_teams', 'nhl_teams.nhl_id', '=', 'players.nhl_team_id')
            ->leftJoinSub($currentCapHits, 'current_cap_hits', function ($join): void {
                $join->on('current_cap_hits.player_id', '=', 'players.id');
            })
            ->whereNotNull('players.nhl_id')
            ->when($request->filled('updated_since'), function ($query) use ($request): void {
                $query->where('players.updated_at', '>=', (string) $request->query('updated_since'));
            })
            ->orderBy('players.id')
            ->select([
                'players.id',
                'players.nhl_id',
                'players.nhl_team_id',
                'players.full_name',
                'players.first_name',
                'players.last_name',
                'players.position',
                'players.shoots',
                'players.dob',
                'players.status',
                'players.team_abbrev',
                'players.current_league_abbrev',
                'players.is_goalie',
                'players.is_prospect',
                'players.head_shot_url',
                'players.updated_at',
                'nhl_teams.abbrev as current_team_abbreviation',
                'current_cap_hits.current_cap_hit',
            ])
            ->paginate($perPage);

        return response()->json([
            'data' => collect($players->items())
                ->map(fn (object $player): array => $this->playerPayload($player, $currentSeasonKey))
                ->values(),
            'meta' => [
                'current_page' => $players->currentPage(),
                'last_page' => $players->lastPage(),
                'per_page' => $players->perPage(),
                'total' => $players->total(),
            ],
        ]);
    }

    private function teamPayload(object $team): array
    {
        return [
            'external_id' => (int) $team->nhl_id,
            'slug' => Str::slug((string) ($team->full_name ?: $team->abbrev)),
            'name' => $team->full_name,
            'abbreviation' => $team->abbrev,
            'city' => $team->place_name,
            'active' => true,
            'metadata' => [
                'dynastyiq_id' => (int) $team->id,
                'nhl_id' => (int) $team->nhl_id,
                'common_name' => $team->common_name,
            ],
        ];
    }

    private function playerPayload(object $player, int $currentSeasonKey): array
    {
        $fullName = (string) ($player->full_name ?: trim($player->first_name . ' ' . $player->last_name));
        $nhlId = (int) $player->nhl_id;

        return [
            'external_id' => $nhlId,
            'current_team_external_id' => $player->nhl_team_id !== null ? (int) $player->nhl_team_id : null,
            'slug' => Str::slug($fullName) . '-' . $nhlId,
            'full_name' => $fullName,
            'first_name' => $player->first_name,
            'last_name' => $player->last_name,
            'position' => $player->position,
            'shoots_or_catches' => $player->shoots,
            'birth_date' => $player->dob,
            'head_shot_url' => $player->head_shot_url,
            'current_cap_hit' => $player->current_cap_hit !== null ? (int) $player->current_cap_hit : null,
            'current_cap_hit_season_key' => $currentSeasonKey,
            'active' => (string) $player->status === 'active',
            'status' => $player->status,
            'metadata' => [
                'dynastyiq_id' => (int) $player->id,
                'nhl_id' => $nhlId,
                'team_abbrev' => $player->team_abbrev,
                'current_team_abbreviation' => $player->current_team_abbreviation ?? $player->team_abbrev,
                'current_league_abbrev' => $player->current_league_abbrev,
                'is_goalie' => (bool) $player->is_goalie,
                'is_prospect' => (bool) $player->is_prospect,
                'updated_at' => $player->updated_at,
            ],
        ];
    }

    private function currentNhlSeasonKey(): int
    {
        $now = now();
        $startYear = (int) $now->month >= 7 ? (int) $now->year : (int) $now->year - 1;

        return ($startYear * 10000) + $startYear + 1;
    }
}
