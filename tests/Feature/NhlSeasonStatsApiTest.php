<?php

declare(strict_types=1);

use App\Models\ApiClient;
use App\Services\NhlExpectedGoalsBackfiller;
use Illuminate\Support\Facades\DB;

function createNhlStatsApiToken(): string
{
    $token = 'diq_gner8_testing-token';

    ApiClient::query()->create([
        'name' => 'Gner8',
        'slug' => 'gner8',
        'token_prefix' => substr($token, 0, 24),
        'token_hash' => ApiClient::hashToken($token),
        'scopes' => ['nhl-stats:read'],
    ]);

    return $token;
}

function insertNhlSeasonStatsApiGame(int $gameId, string $date): void
{
    DB::table('nhl_games')->insert([
        'nhl_game_id' => $gameId,
        'season_id' => '20252026',
        'game_type' => 2,
        'game_date' => $date,
        'game_dow' => 'Mon',
        'game_month' => 'Apr',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function insertNhlSeasonStatsApiGoalie(int $nhlId = 8470001): int
{
    return DB::table('players')->insertGetId([
        'nhl_id' => $nhlId,
        'nhl_team_id' => 14,
        'full_name' => 'Test Goalie',
        'first_name' => 'Test',
        'last_name' => 'Goalie',
        'is_goalie' => true,
        'position' => 'G',
        'pos_type' => 'G',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('emits goalie basic and on ice stats for scoped season stats pulls', function (): void {
    $token = createNhlStatsApiToken();
    $playerId = insertNhlSeasonStatsApiGoalie();
    insertNhlSeasonStatsApiGame(2025020001, '2025-10-07');
    insertNhlSeasonStatsApiGame(2025020002, '2025-10-09');

    DB::table('nhl_season_stats')->insert([
        'season_id' => '20252026',
        'nhl_player_id' => 8470001,
        'nhl_team_id' => 14,
        'gp' => 2,
        'game_type' => 2,
        'toi' => 7200,
        'sa' => 64,
        'sv' => 60,
        'ga' => 4,
        'wins' => 1,
        'losses' => 1,
        'starts' => 2,
        'quality_starts' => 1,
        'sv_pct' => 0.938,
        'gaa' => 2.000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([2025020001 => '2025-10-07', 2025020002 => '2025-10-09'] as $gameId => $date) {
        DB::table('nhl_game_summaries')->insert([
            'nhl_game_id' => $gameId,
            'nhl_player_id' => 8470001,
            'nhl_team_id' => 14,
            'toi' => 3600,
            'sa' => 32,
            'sv' => 30,
            'ga' => 2,
            'goalie_started' => true,
            'goalie_decision' => $date === '2025-10-07' ? 'W' : 'L',
            'quality_start' => $date === '2025-10-07',
            'sv_pct' => 0.938,
            'gaa' => 2.000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('nhl_player_game_strength_summaries')->insert([
            'nhl_game_id' => $gameId,
            'player_id' => $playerId,
            'nhl_player_id' => 8470001,
            'team_id' => 14,
            'team_abbrev' => 'TBL',
            'strength' => 'EV',
            'toi' => 3600,
            'gf' => 3,
            'ga' => 2,
            'sf' => 28,
            'sa' => 32,
            'satf' => 45,
            'sata' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $basicResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/nhl-season-stats?season=20252026&game_type=2&stat_group=basic&window_key=season')
        ->assertOk()
        ->json('player_stats');

    $basicStats = collect($basicResponse)->where('nhl_player_id', 8470001)->pluck('value', 'stat_slug');

    expect($basicStats->get('goalie_starts'))->toBe(2.0)
        ->and($basicStats->get('goalie_wins'))->toBe(1.0)
        ->and($basicStats->get('goalie_shots_against'))->toBe(64.0)
        ->and($basicStats->get('goalie_save_percentage'))->toBe(0.938);

    $windowResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/nhl-season-stats?season=20252026&game_type=2&stat_group=basic&window_key=last_5')
        ->assertOk()
        ->json('player_stats');

    $windowStats = collect($windowResponse)->where('nhl_player_id', 8470001)->pluck('value', 'stat_slug');

    expect($windowStats->get('goalie_goals_against_average'))->toBe(2.0)
        ->and($windowStats->get('goalie_quality_start_percentage'))->toBe(0.5);

    $onIceResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/nhl-season-stats?season=20252026&game_type=2&stat_group=on_ice&window_key=last_5')
        ->assertOk()
        ->json('player_stats');

    $onIceStats = collect($onIceResponse)->where('nhl_player_id', 8470001)->pluck('value', 'stat_slug');

    expect($onIceStats->get('on_ice_sa'))->toBe(64.0)
        ->and($onIceStats->get('on_ice_ga'))->toBe(4.0);
});

it('emits goalie expected stats for scoped season stats pulls', function (): void {
    $token = createNhlStatsApiToken();
    insertNhlSeasonStatsApiGoalie();
    insertNhlSeasonStatsApiGame(2025020003, '2025-10-10');

    $goalModelId = DB::table('nhl_expected_goals_models')->insertGetId([
        'name' => NhlExpectedGoalsBackfiller::MODEL_NAME,
        'version' => 'goal-test',
        'prediction_target' => NhlExpectedGoalsBackfiller::TARGET_GOAL,
        'training_season_id' => '20252026',
        'status' => 'draft',
        'trained_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $sogModelId = DB::table('nhl_expected_goals_models')->insertGetId([
        'name' => NhlExpectedGoalsBackfiller::MODEL_NAME,
        'version' => 'sog-test',
        'prediction_target' => NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL,
        'training_season_id' => '20252026',
        'status' => 'draft',
        'trained_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $playId = DB::table('play_by_plays')->insertGetId([
        'nhl_game_id' => 2025020003,
        'nhl_player_id' => 8471001,
        'event_owner_team_id' => 15,
        'type_desc_key' => 'shot-on-goal',
        'shooting_player_id' => 8471001,
        'goalie_in_net_player_id' => 8470001,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $factId = DB::table('nhl_shot_attempts_facts')->insertGetId([
        'play_by_play_id' => $playId,
        'nhl_game_id' => 2025020003,
        'season_id' => '20252026',
        'game_date' => '2025-10-10',
        'attempt_result' => 'shot_on_goal',
        'is_unblocked_attempt' => true,
        'is_shot_on_goal' => true,
        'is_goal' => false,
        'team_id' => 15,
        'opponent_team_id' => 14,
        'shooter_player_id' => 8471001,
        'goalie_player_id' => 8470001,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([[$goalModelId, NhlExpectedGoalsBackfiller::TARGET_GOAL, 0.25], [$sogModelId, NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL, 0.80]] as [$modelId, $target, $xg]) {
        DB::table('nhl_shot_attempt_predictions')->insert([
            'expected_goals_model_id' => $modelId,
            'prediction_target' => $target,
            'shot_attempt_fact_id' => $factId,
            'play_by_play_id' => $playId,
            'nhl_game_id' => 2025020003,
            'season_id' => '20252026',
            'game_date' => '2025-10-10',
            'team_id' => 15,
            'opponent_team_id' => 14,
            'shooter_player_id' => 8471001,
            'goalie_player_id' => 8470001,
            'is_scored' => true,
            'xg' => $xg,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $response = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/nhl-season-stats?season=20252026&game_type=2&stat_group=expected&window_key=last_5')
        ->assertOk()
        ->json('player_stats');

    $stats = collect($response)->where('nhl_player_id', 8470001)->pluck('value', 'stat_slug');

    expect($stats->get('goalie_xga'))->toBe(0.25)
        ->and($stats->get('goalie_xsoga'))->toBe(0.8)
        ->and($stats->get('goalie_xsaves'))->toBe(0.55)
        ->and($stats->get('goalie_gsax'))->toBe(0.25)
        ->and(round((float) $stats->get('goalie_xsave_percentage'), 4))->toBe(0.6875);
});
