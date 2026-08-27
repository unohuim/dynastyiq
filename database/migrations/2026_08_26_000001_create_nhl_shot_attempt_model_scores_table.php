<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('nhl_shot_attempt_model_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_run_id')
                ->nullable()
                ->constrained('nhl_model_runs')
                ->nullOnDelete();
            $table->foreignId('expected_goals_model_id')
                ->constrained('nhl_expected_goals_models')
                ->cascadeOnDelete();
            $table->string('prediction_target', 32);
            $table->foreignId('shot_attempt_fact_id')
                ->constrained('nhl_shot_attempts_facts')
                ->cascadeOnDelete();
            $table->foreignId('play_by_play_id')
                ->constrained('play_by_plays')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('nhl_game_id');
            $table->string('season_id', 8)->nullable();
            $table->unsignedTinyInteger('game_type')->nullable();
            $table->date('game_date')->nullable();
            $table->integer('team_id')->nullable();
            $table->integer('opponent_team_id')->nullable();
            $table->integer('shooter_player_id')->nullable();
            $table->integer('goalie_player_id')->nullable();
            $table->boolean('is_scored')->default(false);
            $table->string('exclusion_reason', 80)->nullable();
            $table->decimal('probability', 9, 6)->nullable();
            $table->boolean('is_high_danger')->default(false);
            $table->decimal('high_danger_threshold', 9, 6)->nullable();
            $table->string('matched_bucket_key', 600)->nullable();
            $table->unsignedTinyInteger('fallback_level')->nullable();
            $table->json('matched_bucket_payload')->nullable();
            $table->timestamp('scored_at')->nullable();
            $table->timestamps();

            $table->foreign('nhl_game_id')
                ->references('nhl_game_id')
                ->on('nhl_games')
                ->cascadeOnDelete();
            $table->unique(
                ['expected_goals_model_id', 'shot_attempt_fact_id'],
                'uq_nhl_sat_scores_model_fact'
            );
            $table->index(['model_run_id', 'season_id'], 'ix_nhl_sat_scores_run_season');
            $table->index(
                ['expected_goals_model_id', 'season_id', 'game_type'],
                'ix_nhl_sat_scores_model_season_type'
            );
            $table->index(
                ['expected_goals_model_id', 'nhl_game_id', 'shooter_player_id'],
                'ix_nhl_sat_scores_model_game_shooter'
            );
            $table->index(
                ['expected_goals_model_id', 'season_id', 'is_high_danger'],
                'ix_nhl_sat_scores_model_season_hd'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_shot_attempt_model_scores');
    }
};
