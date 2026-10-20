<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Store custom game forecasts independently from existing SAT engines and player evaluations. */
    public function up(): void
    {
        Schema::create('nhl_custom_game_prediction_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('algorithm_version', 40);
            $table->string('season_id', 8);
            $table->foreignId('model_run_id')->nullable()->constrained('nhl_model_runs')->nullOnDelete();
            $table->string('status', 20)->default('queued');
            $table->json('definition');
            $table->unsignedInteger('total_games')->default(0);
            $table->unsignedInteger('completed_games')->default(0);
            $table->unsignedInteger('excluded_games')->default(0);
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['season_id', 'status']);
        });

        Schema::create('nhl_custom_game_predictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('nhl_custom_game_prediction_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('nhl_game_id');
            $table->string('status', 20)->default('pending');
            $table->timestamp('source_cutoff_at');
            $table->foreignId('home_team_context_id')->nullable()
                ->constrained('nhl_team_game_pregame_contexts')->nullOnDelete();
            $table->foreignId('away_team_context_id')->nullable()
                ->constrained('nhl_team_game_pregame_contexts')->nullOnDelete();
            $table->decimal('home_goals_prediction', 10, 4)->nullable();
            $table->decimal('away_goals_prediction', 10, 4)->nullable();
            $table->decimal('total_goals_prediction', 10, 4)->nullable();
            $table->decimal('home_win_probability', 8, 7)->nullable();
            $table->decimal('away_win_probability', 8, 7)->nullable();
            $table->decimal('confidence_score', 7, 4)->nullable();
            $table->json('confidence_inputs')->nullable();
            $table->string('recommendation', 8)->nullable();
            $table->unsignedSmallInteger('home_actual_goals')->nullable();
            $table->unsignedSmallInteger('away_actual_goals')->nullable();
            $table->string('actual_winner', 8)->nullable();
            $table->boolean('winner_correct')->nullable();
            $table->timestamp('predicted_at')->nullable();
            $table->timestamp('compared_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'nhl_game_id'], 'uq_custom_game_prediction');
            $table->index(['run_id', 'status']);
        });

        Schema::create('nhl_custom_game_prediction_teams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prediction_id')->constrained('nhl_custom_game_predictions')->cascadeOnDelete();
            $table->unsignedInteger('nhl_team_id');
            $table->unsignedInteger('opponent_team_id');
            $table->string('venue', 8);
            $table->string('lineup_source', 32)->nullable();
            $table->unsignedBigInteger('starting_goalie_nhl_player_id')->nullable();
            $table->foreignId('pregame_context_id')->nullable()
                ->constrained('nhl_team_game_pregame_contexts')->nullOnDelete();
            $table->json('inputs')->nullable();
            $table->decimal('ev_goals_prediction', 10, 4)->nullable();
            $table->decimal('pp_goals_prediction', 10, 4)->nullable();
            $table->decimal('pk_goals_prediction', 10, 4)->nullable();
            $table->decimal('goals_prediction', 10, 4)->nullable();
            $table->decimal('sat_prediction', 10, 4)->nullable();
            $table->decimal('sog_prediction', 10, 4)->nullable();
            $table->decimal('shooting_pct_prediction', 8, 5)->nullable();
            $table->decimal('pp_opportunities_prediction', 8, 4)->nullable();
            $table->decimal('pp_success_pct_prediction', 8, 5)->nullable();
            $table->decimal('ev_toi_seconds_prediction', 12, 4)->nullable();
            $table->decimal('pp_toi_seconds_prediction', 12, 4)->nullable();
            $table->decimal('pk_toi_seconds_prediction', 12, 4)->nullable();
            $table->unsignedSmallInteger('actual_goals')->nullable();
            $table->unsignedSmallInteger('actual_ev_goals')->nullable();
            $table->unsignedSmallInteger('actual_pp_goals')->nullable();
            $table->unsignedSmallInteger('actual_pk_goals')->nullable();
            $table->unsignedSmallInteger('actual_sat')->nullable();
            $table->unsignedSmallInteger('actual_sog')->nullable();
            $table->decimal('actual_shooting_pct', 8, 5)->nullable();
            $table->unsignedSmallInteger('actual_pp_opportunities')->nullable();
            $table->decimal('actual_pp_success_pct', 8, 5)->nullable();
            $table->decimal('actual_ev_toi_seconds', 12, 4)->nullable();
            $table->decimal('actual_pp_toi_seconds', 12, 4)->nullable();
            $table->decimal('actual_pk_toi_seconds', 12, 4)->nullable();
            $table->timestamps();

            $table->unique(['prediction_id', 'nhl_team_id'], 'uq_custom_game_prediction_team');
            $table->index(['nhl_team_id', 'prediction_id']);
        });

        Schema::create('nhl_custom_game_prediction_player_inputs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_prediction_id')
                ->constrained('nhl_custom_game_prediction_teams')->cascadeOnDelete();
            $table->foreignId('opponent_team_prediction_id')->nullable()
                ->constrained('nhl_custom_game_prediction_teams')->nullOnDelete();
            $table->unsignedBigInteger('nhl_player_id');
            $table->string('player_role', 16);
            $table->string('player_name')->nullable();
            $table->foreignId('pregame_context_id')->nullable()
                ->constrained('nhl_player_game_pregame_contexts')->nullOnDelete();
            $table->json('inputs');
            $table->json('prediction')->nullable();
            $table->json('actual')->nullable();
            $table->decimal('sat_prediction', 10, 4)->nullable();
            $table->decimal('toi_share_prediction', 8, 5)->nullable();
            $table->decimal('toi_seconds_prediction', 12, 4)->nullable();
            $table->timestamps();

            $table->unique(['team_prediction_id', 'nhl_player_id'], 'uq_custom_game_prediction_player');
            $table->index(['nhl_player_id', 'team_prediction_id']);
        });
    }

    /** Remove only this custom prediction pathway's tables. */
    public function down(): void
    {
        Schema::dropIfExists('nhl_custom_game_prediction_player_inputs');
        Schema::dropIfExists('nhl_custom_game_prediction_teams');
        Schema::dropIfExists('nhl_custom_game_predictions');
        Schema::dropIfExists('nhl_custom_game_prediction_runs');
    }
};
