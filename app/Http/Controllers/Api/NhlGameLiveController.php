<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NhlGame;
use App\Services\NhlLiveGameFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/** Expose compact observed team/goalie statistics from the existing shared live snapshot. */
final class NhlGameLiveController extends Controller
{
    /** Read existing evidence only; requests never poll NHL, score attempts or prepare predictions. */
    public function __invoke(Request $request, NhlLiveGameFeed $feed): JsonResponse
    {
        $input = $request->validate(['nhl_game_id' => ['required', 'integer', 'min:1']]);
        $id = (int) $input['nhl_game_id'];
        NhlGame::query()->findOrFail($id);
        $snapshot = $feed->snapshot($id);
        $game = $snapshot['game'] ?? null;
        $available = is_array($game) && (int) ($game['nhl_game_id'] ?? 0) === $id;
        $advanced = $available ? ($game['advanced_stats'] ?? []) : [];
        $teams = null;
        $goalies = [];
        if ($available) {
            $teams = [];
            foreach (['away', 'home'] as $side) {
                $team = $game[$side] ?? [];
                $teamId = isset($team['team_id']) ? (int) $team['team_id'] : null;
                $teams[$side] = ['nhl_team_id' => $teamId, 'team_abbrev' => $team['team_abbrev'] ?? null];
                foreach (['score', 'sog', 'sat', 'hits', 'blocks', 'pim'] as $metric) {
                    $teams[$side][$metric] = $this->count($team[$metric] ?? null);
                }
                $teams[$side]['xg'] = ($advanced['available'] ?? false)
                    ? $this->number($advanced['teams'][$teamId]['xgf'] ?? null) : null;
                $sourceGoalies = collect(data_get($snapshot, 'boxscore.playerByGameStats.'.$side.'Team.goalies', []))
                    ->keyBy('playerId');
                foreach (collect($team['boxscore'] ?? [])->unique('id') as $player) {
                    if (($player['position'] ?? null) !== 'G' || empty($player['id'])) {
                        continue;
                    }
                    $source = $sourceGoalies->get($player['id'], []);
                    $pair = [];
                    preg_match('/^(\d+)\/(\d+)$/', (string) ($source['saveShotsAgainst'] ?? ''), $pair);
                    $saves = $this->count($player['saves'] ?? null) ?? $this->count($source['saves'] ?? null)
                        ?? $this->count($pair[1] ?? null);
                    $against = $this->count($player['shots_against'] ?? null) ?? $this->count($source['shotsAgainst'] ?? null)
                        ?? $this->count($pair[2] ?? null);
                    if (! $this->played([...$player, 'saves' => $saves, 'shots_against' => $against])) {
                        continue;
                    }
                    $goalies[] = ['nhl_player_id' => (int) $player['id'], 'name' => $player['name'] ?? null,
                        'nhl_team_id' => $teamId, 'team_abbrev' => $team['team_abbrev'] ?? null,
                        'side' => $side, 'saves' => $saves, 'shots_against' => $against,
                        'save_pct' => $saves !== null && $against !== null && $against > 0 && $saves <= $against
                            ? round(100 * $saves / $against, 2) : null,
                        'gsax' => ($advanced['available'] ?? false)
                            ? $this->number($advanced['goalies'][$player['id']]['gsax'] ?? null, true) : null];
                }
            }
        }

        return response()->json([
            'nhl_game_id' => $id, 'available' => $available,
            'game' => $available ? Arr::only($game, ['game_date', 'start_time_utc', 'game_state', 'mode', 'status',
                'period_number', 'period_type', 'time_remaining', 'in_intermission']) : null,
            'teams' => $teams, 'goalies' => $goalies,
            'meta' => ['sequence' => $available ? ($snapshot['sequence'] ?? null) : null,
                'observed_at' => $available ? ($snapshot['observed_at'] ?? null) : null,
                'stale' => $available ? (! $feed->fresh($snapshot) && ($game['mode'] ?? null) !== 'recap') : null,
                'advanced_stats' => ['available' => (bool) ($advanced['available'] ?? false),
                    'pending' => $advanced['pending'] ?? null, 'version' => $advanced['version'] ?? null,
                    'processed_at' => isset($advanced['processed_at'])
                        ? Carbon::parse($advanced['processed_at'], 'UTC')->toIso8601String() : null,
                    'model_ids' => $advanced['model_ids'] ?? []]],
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION)->header('Cache-Control', 'no-store');
    }

    /** Distinguish a participating goalie from an unused bench entry.
     * @param array<string,mixed> $player
     */
    private function played(array $player): bool
    {
        foreach (['saves', 'shots_against', 'goals_against'] as $field) {
            if (($this->count($player[$field] ?? null) ?? 0) > 0) {
                return true;
            }
        }

        return preg_match('/^(\d+):(\d{2})$/', (string) ($player['toi'] ?? ''), $time) === 1
            && ((int) $time[1] * 60 + (int) $time[2]) > 0;
    }

    /** Preserve measured zero counts and leave missing/invalid values unknown. */
    private function count(mixed $value): ?int
    {
        $number = $this->number($value);

        return $number !== null && floor($number) === $number ? (int) $number : null;
    }

    /** GSAx may be negative; counts and expected goals may not. */
    private function number(mixed $value, bool $signed = false): ?float
    {
        return is_numeric($value) && is_finite((float) $value) && ($signed || (float) $value >= 0)
            ? (float) $value : null;
    }
}
