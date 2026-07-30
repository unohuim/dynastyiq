<?php

declare(strict_types=1);

use App\Services\NhlExpectedGoalsBackfiller;
use Illuminate\Support\Facades\DB;

it('empties NHL shot attempt facts and dependent predictions', function (): void {
    DB::table('nhl_games')->insert([
        'nhl_game_id' => 2025020001,
        'season_id' => '20252026',
        'game_type' => 2,
        'game_date' => '2025-10-07',
        'game_dow' => 'Tue',
        'game_month' => 'Oct',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $playId = DB::table('play_by_plays')->insertGetId([
        'nhl_game_id' => 2025020001,
        'nhl_player_id' => 8470001,
        'type_desc_key' => 'shot-on-goal',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $factId = DB::table('nhl_shot_attempts_facts')->insertGetId([
        'play_by_play_id' => $playId,
        'nhl_game_id' => 2025020001,
        'season_id' => '20252026',
        'game_date' => '2025-10-07',
        'attempt_result' => 'shot_on_goal',
        'is_unblocked_attempt' => true,
        'is_shot_on_goal' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $modelId = DB::table('nhl_expected_goals_models')->insertGetId([
        'name' => NhlExpectedGoalsBackfiller::MODEL_NAME,
        'version' => 'empty-facts-test',
        'prediction_target' => NhlExpectedGoalsBackfiller::TARGET_GOAL,
        'training_season_id' => '20252026',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('nhl_shot_attempt_predictions')->insert([
        'expected_goals_model_id' => $modelId,
        'prediction_target' => NhlExpectedGoalsBackfiller::TARGET_GOAL,
        'shot_attempt_fact_id' => $factId,
        'play_by_play_id' => $playId,
        'nhl_game_id' => 2025020001,
        'season_id' => '20252026',
        'game_date' => '2025-10-07',
        'is_scored' => true,
        'xg' => 0.123,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('nhl:shots:empty-facts', ['--force' => true])
        ->expectsOutput('Removed NHL shot-attempt facts.')
        ->assertExitCode(0);

    expect(DB::table('nhl_shot_attempts_facts')->count())->toBe(0)
        ->and(DB::table('nhl_shot_attempt_predictions')->count())->toBe(0)
        ->and(DB::table('play_by_plays')->count())->toBe(1);
});
