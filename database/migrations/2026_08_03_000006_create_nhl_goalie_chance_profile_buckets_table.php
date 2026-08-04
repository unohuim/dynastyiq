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
        Schema::create('nhl_goalie_chance_profile_buckets', function (Blueprint $table): void {
            $table->id();
            $table->string('source_season_id', 8);
            $table->unsignedTinyInteger('game_type')->default(2);
            $table->unsignedBigInteger('goal_expected_goals_model_id');
            $table->unsignedBigInteger('shot_on_goal_expected_goals_model_id');

            $table->integer('goalie_player_id');
            $table->integer('team_id')->nullable();
            $table->string('team_abbrev', 12)->nullable();
            $table->string('position', 12)->nullable();

            $table->string('matched_bucket_key', 600);
            $table->unsignedTinyInteger('fallback_level');
            $table->json('bucket_dimensions');
            $table->string('shot_type_group', 32)->nullable();
            $table->string('distance_group', 32)->nullable();
            $table->string('angle_group', 32)->nullable();
            $table->string('sequence_group', 32)->nullable();

            $table->decimal('source_games', 8, 2)->nullable();
            $table->unsignedInteger('source_toi_seconds')->nullable();
            $table->unsignedInteger('source_sat_against')->default(0);
            $table->unsignedInteger('source_sog_against')->default(0);
            $table->unsignedInteger('source_goals_against')->default(0);
            $table->decimal('source_xga', 10, 4)->nullable();
            $table->decimal('source_xsoga', 10, 4)->nullable();
            $table->decimal('source_gsax', 10, 4)->nullable();
            $table->decimal('source_gsax_per_100_sat_against', 10, 4)->nullable();
            $table->decimal('source_profile_share', 9, 6)->nullable();
            $table->decimal('goal_probability_against', 9, 6)->nullable();
            $table->decimal('shot_on_goal_probability_against', 9, 6)->nullable();

            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->string('confidence_bucket', 24)->nullable();
            $table->json('profile_inputs')->nullable();
            $table->json('flags')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('profiled_at')->nullable();
            $table->timestamps();

            $table->unique(
                [
                    'source_season_id',
                    'game_type',
                    'goal_expected_goals_model_id',
                    'shot_on_goal_expected_goals_model_id',
                    'goalie_player_id',
                    'matched_bucket_key',
                ],
                'uq_nhl_goalie_chance_profile_bucket'
            );
            $table->foreign('goal_expected_goals_model_id', 'fk_nhl_gcpb_goal_model')
                ->references('id')
                ->on('nhl_expected_goals_models')
                ->cascadeOnDelete();
            $table->foreign('shot_on_goal_expected_goals_model_id', 'fk_nhl_gcpb_sog_model')
                ->references('id')
                ->on('nhl_expected_goals_models')
                ->cascadeOnDelete();
            $table->index(['source_season_id', 'goalie_player_id'], 'ix_nhl_gcpb_season_goalie');
            $table->index(['source_season_id', 'team_abbrev'], 'ix_nhl_gcpb_season_team');
            $table->index(['source_season_id', 'fallback_level'], 'ix_nhl_gcpb_season_fallback');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_goalie_chance_profile_buckets');
    }
};
