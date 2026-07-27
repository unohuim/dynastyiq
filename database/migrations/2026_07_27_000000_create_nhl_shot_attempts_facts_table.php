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
        Schema::create('nhl_shot_attempts_facts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('play_by_play_id')
                ->constrained('play_by_plays')
                ->cascadeOnDelete();
            $table->foreignId('previous_play_by_play_id')
                ->nullable()
                ->constrained('play_by_plays')
                ->nullOnDelete();
            $table->unsignedBigInteger('nhl_game_id');
            $table->string('nhl_event_id')->nullable();
            $table->string('season_id', 8)->nullable();
            $table->date('game_date')->nullable();

            $table->string('fact_version', 40)->default('shot_attempt_facts_v1');
            $table->string('event_type')->nullable();
            $table->string('attempt_result', 32);
            $table->boolean('is_shot_attempt')->default(true);
            $table->boolean('is_unblocked_attempt')->default(false);
            $table->boolean('is_shot_on_goal')->default(false);
            $table->boolean('is_goal')->default(false);

            $table->integer('team_id')->nullable();
            $table->integer('opponent_team_id')->nullable();
            $table->integer('shooter_player_id')->nullable();
            $table->integer('goalie_player_id')->nullable();
            $table->integer('blocking_player_id')->nullable();

            $table->integer('period')->nullable();
            $table->string('period_type', 12)->nullable();
            $table->string('period_bucket', 32)->nullable();
            $table->integer('seconds_in_game')->nullable();
            $table->integer('seconds_since_last_event')->nullable();
            $table->string('time_bucket', 32)->nullable();

            $table->string('situation_code')->nullable();
            $table->string('strength', 12)->nullable();
            $table->string('strength_bucket', 32)->nullable();
            $table->integer('home_score')->nullable();
            $table->integer('away_score')->nullable();
            $table->integer('score_differential')->nullable();
            $table->string('score_state_bucket', 32)->nullable();

            $table->integer('x_coord')->nullable();
            $table->integer('y_coord')->nullable();
            $table->decimal('shot_distance', 8, 2)->nullable();
            $table->decimal('shot_angle', 7, 3)->nullable();
            $table->decimal('abs_shot_angle', 7, 3)->nullable();
            $table->string('distance_bucket', 32)->nullable();
            $table->string('angle_bucket', 32)->nullable();
            $table->string('zone_code', 12)->nullable();
            $table->string('zone_bucket', 32)->nullable();

            $table->string('shot_type')->nullable();
            $table->string('shot_type_bucket', 32)->nullable();
            $table->boolean('is_rebound')->nullable();
            $table->unsignedSmallInteger('rebound_window_seconds')->nullable();
            $table->string('rebound_bucket', 32)->nullable();
            $table->boolean('is_rush')->nullable();
            $table->string('rush_bucket', 32)->nullable();
            $table->boolean('is_empty_net')->nullable();
            $table->string('net_state_bucket', 32)->nullable();
            $table->string('previous_event_type')->nullable();
            $table->integer('previous_event_team_id')->nullable();
            $table->integer('previous_event_seconds_delta')->nullable();

            $table->json('facts_payload')->nullable();
            $table->timestamps();

            $table->foreign('nhl_game_id')
                ->references('nhl_game_id')
                ->on('nhl_games')
                ->cascadeOnDelete();
            $table->unique('play_by_play_id', 'uq_nhl_shot_attempt_facts_pbp');
            $table->index(['season_id', 'game_date'], 'ix_nhl_saf_season_date');
            $table->index(['nhl_game_id', 'team_id'], 'ix_nhl_saf_game_team');
            $table->index(['nhl_game_id', 'shooter_player_id'], 'ix_nhl_saf_game_shooter');
            $table->index(['nhl_game_id', 'goalie_player_id'], 'ix_nhl_saf_game_goalie');
            $table->index(
                ['distance_bucket', 'angle_bucket', 'strength_bucket', 'shot_type_bucket'],
                'ix_nhl_saf_bucket_inputs'
            );
            $table->index(['attempt_result', 'is_unblocked_attempt', 'is_shot_on_goal'], 'ix_nhl_saf_attempt_surface');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_shot_attempts_facts');
    }
};
