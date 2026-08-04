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
        Schema::create('nhl_player_season_projections', function (Blueprint $table): void {
            $table->id();
            $table->string('projection_version', 80);
            $table->string('source_season_id', 8);
            $table->string('target_season_id', 8);
            $table->unsignedBigInteger('goal_expected_goals_model_id')->nullable();
            $table->unsignedBigInteger('shot_on_goal_expected_goals_model_id')->nullable();

            $table->integer('player_id');
            $table->integer('team_id')->nullable();
            $table->string('team_abbrev', 12)->nullable();
            $table->string('position', 12)->nullable();

            $table->decimal('source_games', 8, 2)->nullable();
            $table->unsignedInteger('source_sat')->default(0);
            $table->unsignedInteger('source_sog')->default(0);
            $table->unsignedInteger('source_goals')->default(0);
            $table->decimal('source_xgf', 10, 4)->nullable();
            $table->decimal('source_xsog', 10, 4)->nullable();

            $table->decimal('projected_games', 8, 2)->nullable();
            $table->decimal('projected_xsat', 10, 2)->nullable();
            $table->decimal('projected_xsog', 10, 2)->nullable();
            $table->decimal('projected_xgf', 10, 4)->nullable();
            $table->decimal('projected_goals', 10, 4)->nullable();

            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->string('confidence_bucket', 24)->nullable();
            $table->string('status', 32)->default('draft');
            $table->json('projection_inputs')->nullable();
            $table->json('flags')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('projected_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['projection_version', 'target_season_id', 'player_id'],
                'uq_nhl_player_season_projection_version_player'
            );
            $table->foreign('goal_expected_goals_model_id', 'fk_nhl_psp_goal_model')
                ->references('id')
                ->on('nhl_expected_goals_models')
                ->nullOnDelete();
            $table->foreign('shot_on_goal_expected_goals_model_id', 'fk_nhl_psp_sog_model')
                ->references('id')
                ->on('nhl_expected_goals_models')
                ->nullOnDelete();
            $table->index(['target_season_id', 'status'], 'ix_nhl_player_season_projection_target_status');
            $table->index(['target_season_id', 'team_abbrev', 'position'], 'ix_nhl_player_season_projection_team_pos');
            $table->index(['source_season_id', 'target_season_id'], 'ix_nhl_player_season_projection_source_target');
        });

        Schema::create('nhl_player_projection_profile_buckets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_season_projection_id');
            $table->string('projection_version', 80);
            $table->string('source_season_id', 8);
            $table->string('target_season_id', 8);
            $table->integer('player_id');
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

            $table->unsignedInteger('source_sat')->default(0);
            $table->unsignedInteger('source_sog')->default(0);
            $table->unsignedInteger('source_goals')->default(0);
            $table->decimal('source_xgf', 10, 4)->nullable();
            $table->decimal('source_xsog', 10, 4)->nullable();
            $table->decimal('source_profile_share', 9, 6)->nullable();

            $table->decimal('projected_xsat', 10, 2)->nullable();
            $table->decimal('projected_xsog', 10, 2)->nullable();
            $table->decimal('projected_xgf', 10, 4)->nullable();
            $table->decimal('projected_goals', 10, 4)->nullable();

            $table->decimal('goal_probability', 9, 6)->nullable();
            $table->decimal('shot_on_goal_probability', 9, 6)->nullable();
            $table->json('projection_inputs')->nullable();
            $table->json('flags')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['projection_version', 'target_season_id', 'player_id', 'matched_bucket_key'],
                'uq_nhl_player_projection_bucket_version_player_bucket'
            );
            $table->foreign('player_season_projection_id', 'fk_nhl_ppb_season_projection')
                ->references('id')
                ->on('nhl_player_season_projections')
                ->cascadeOnDelete();
            $table->index(
                ['target_season_id', 'player_id', 'fallback_level'],
                'ix_nhl_player_projection_bucket_player_level'
            );
            $table->index(
                ['target_season_id', 'shot_type_group', 'distance_group', 'angle_group'],
                'ix_nhl_player_projection_bucket_profile'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_player_projection_profile_buckets');
        Schema::dropIfExists('nhl_player_season_projections');
    }
};
