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
        Schema::create('nhl_expected_goals_models', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('version', 80);
            $table->string('model_type', 80)->default('bucket_smoothed');
            $table->string('training_season_id', 8)->nullable();
            $table->unsignedInteger('minimum_bucket_attempts')->default(300);
            $table->unsignedInteger('smoothing_prior_attempts')->default(100);
            $table->json('training_filters')->nullable();
            $table->json('feature_config')->nullable();
            $table->json('calibration_config')->nullable();
            $table->json('metrics')->nullable();
            $table->string('status', 32)->default('draft');
            $table->timestamp('trained_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['name', 'version'], 'uq_nhl_xg_models_name_version');
            $table->index(['training_season_id', 'status'], 'ix_nhl_xg_models_training_status');
        });

        Schema::create('nhl_expected_goals_model_buckets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expected_goals_model_id')
                ->constrained('nhl_expected_goals_models')
                ->cascadeOnDelete();
            $table->string('bucket_key', 600);
            $table->unsignedTinyInteger('fallback_level');
            $table->json('bucket_dimensions');
            $table->unsignedInteger('attempts');
            $table->unsignedInteger('goals');
            $table->decimal('raw_goal_rate', 9, 6);
            $table->decimal('smoothed_goal_probability', 9, 6);
            $table->timestamps();

            $table->unique(['expected_goals_model_id', 'bucket_key'], 'uq_nhl_xg_buckets_model_key');
            $table->index(['expected_goals_model_id', 'fallback_level'], 'ix_nhl_xg_buckets_level');
        });

        Schema::create('nhl_shot_attempt_predictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expected_goals_model_id')
                ->constrained('nhl_expected_goals_models')
                ->cascadeOnDelete();
            $table->foreignId('shot_attempt_fact_id')
                ->constrained('nhl_shot_attempts_facts')
                ->cascadeOnDelete();
            $table->foreignId('play_by_play_id')
                ->constrained('play_by_plays')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('nhl_game_id');
            $table->string('season_id', 8)->nullable();
            $table->date('game_date')->nullable();
            $table->integer('team_id')->nullable();
            $table->integer('opponent_team_id')->nullable();
            $table->integer('shooter_player_id')->nullable();
            $table->integer('goalie_player_id')->nullable();
            $table->boolean('is_scored')->default(false);
            $table->string('exclusion_reason', 80)->nullable();
            $table->decimal('raw_xg', 9, 6)->nullable();
            $table->decimal('calibrated_xg', 9, 6)->nullable();
            $table->decimal('xg', 9, 6)->nullable();
            $table->string('matched_bucket_key', 600)->nullable();
            $table->unsignedTinyInteger('fallback_level')->nullable();
            $table->json('matched_bucket_payload')->nullable();
            $table->timestamps();

            $table->foreign('nhl_game_id')
                ->references('nhl_game_id')
                ->on('nhl_games')
                ->cascadeOnDelete();
            $table->unique(['expected_goals_model_id', 'shot_attempt_fact_id'], 'uq_nhl_xg_predictions_model_fact');
            $table->index(['expected_goals_model_id', 'season_id', 'game_date'], 'ix_nhl_xg_predictions_model_date');
            $table->index(['expected_goals_model_id', 'nhl_game_id', 'team_id'], 'ix_nhl_xg_predictions_game_team');
            $table->index(['expected_goals_model_id', 'team_id', 'game_date'], 'ix_nhl_xg_predictions_team_date');
            $table->index(['expected_goals_model_id', 'opponent_team_id', 'game_date'], 'ix_nhl_xg_predictions_opp_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_shot_attempt_predictions');
        Schema::dropIfExists('nhl_expected_goals_model_buckets');
        Schema::dropIfExists('nhl_expected_goals_models');
    }
};

