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
        Schema::create('nhl_official_sat_aggregate_profile_buckets', function (Blueprint $table): void {
            $table->id();
            $table->string('source_season_id', 8);
            $table->unsignedTinyInteger('game_type')->default(2);
            $table->unsignedBigInteger('goal_expected_goals_model_id');
            $table->unsignedBigInteger('shot_on_goal_expected_goals_model_id');
            $table->foreignId('nhl_official_id')->constrained('nhl_officials')->cascadeOnDelete();
            $table->string('role', 32);
            $table->string('aggregate_bucket_key', 600);
            $table->unsignedTinyInteger('aggregate_level');
            $table->string('aggregate_label', 160);
            $table->json('aggregate_dimensions');
            $table->decimal('source_games', 8, 2)->nullable();
            $table->unsignedInteger('source_sat')->default(0);
            $table->unsignedInteger('source_unblocked_sat')->default(0);
            $table->unsignedInteger('source_sog')->default(0);
            $table->unsignedInteger('source_goals')->default(0);
            $table->decimal('source_xg', 10, 4)->nullable();
            $table->decimal('source_xsog', 10, 4)->nullable();
            $table->decimal('source_profile_share', 9, 6)->nullable();
            $table->decimal('goal_probability', 9, 6)->nullable();
            $table->decimal('shot_on_goal_probability', 9, 6)->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->string('confidence_bucket', 24)->nullable();
            $table->decimal('shrinkage_weight', 5, 4)->default(0);
            $table->unsignedSmallInteger('included_bucket_count')->default(0);
            $table->json('included_bucket_keys');
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
                    'nhl_official_id',
                    'role',
                    'aggregate_bucket_key',
                ],
                'uq_nhl_official_sat_aggregate_profile_bucket'
            );
            $table->foreign('goal_expected_goals_model_id', 'fk_nhl_off_sat_agg_goal_model')
                ->references('id')
                ->on('nhl_expected_goals_models')
                ->cascadeOnDelete();
            $table->foreign('shot_on_goal_expected_goals_model_id', 'fk_nhl_off_sat_agg_sog_model')
                ->references('id')
                ->on('nhl_expected_goals_models')
                ->cascadeOnDelete();
            $table->index(['source_season_id', 'role'], 'ix_nhl_off_sat_agg_role');
            $table->index(['source_season_id', 'aggregate_level'], 'ix_nhl_off_sat_agg_level');
            $table->index(['source_season_id', 'source_sat'], 'ix_nhl_off_sat_agg_source_sat');
        });

        Schema::create('nhl_staff_sat_aggregate_profile_buckets', function (Blueprint $table): void {
            $table->id();
            $table->string('source_season_id', 8);
            $table->unsignedTinyInteger('game_type')->default(2);
            $table->unsignedBigInteger('goal_expected_goals_model_id');
            $table->unsignedBigInteger('shot_on_goal_expected_goals_model_id');
            $table->foreignId('nhl_staff_id')->constrained('nhl_staff')->cascadeOnDelete();
            $table->string('role', 32);
            $table->string('team_context', 16);
            $table->string('aggregate_bucket_key', 600);
            $table->unsignedTinyInteger('aggregate_level');
            $table->string('aggregate_label', 160);
            $table->json('aggregate_dimensions');
            $table->decimal('source_games', 8, 2)->nullable();
            $table->unsignedInteger('source_sat')->default(0);
            $table->unsignedInteger('source_unblocked_sat')->default(0);
            $table->unsignedInteger('source_sog')->default(0);
            $table->unsignedInteger('source_goals')->default(0);
            $table->decimal('source_xg', 10, 4)->nullable();
            $table->decimal('source_xsog', 10, 4)->nullable();
            $table->decimal('source_profile_share', 9, 6)->nullable();
            $table->decimal('goal_probability', 9, 6)->nullable();
            $table->decimal('shot_on_goal_probability', 9, 6)->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->string('confidence_bucket', 24)->nullable();
            $table->decimal('shrinkage_weight', 5, 4)->default(0);
            $table->unsignedSmallInteger('included_bucket_count')->default(0);
            $table->json('included_bucket_keys');
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
                    'nhl_staff_id',
                    'role',
                    'team_context',
                    'aggregate_bucket_key',
                ],
                'uq_nhl_staff_sat_aggregate_profile_bucket'
            );
            $table->foreign('goal_expected_goals_model_id', 'fk_nhl_staff_sat_agg_goal_model')
                ->references('id')
                ->on('nhl_expected_goals_models')
                ->cascadeOnDelete();
            $table->foreign('shot_on_goal_expected_goals_model_id', 'fk_nhl_staff_sat_agg_sog_model')
                ->references('id')
                ->on('nhl_expected_goals_models')
                ->cascadeOnDelete();
            $table->index(['source_season_id', 'role', 'team_context'], 'ix_nhl_staff_sat_agg_role_context');
            $table->index(['source_season_id', 'aggregate_level'], 'ix_nhl_staff_sat_agg_level');
            $table->index(['source_season_id', 'source_sat'], 'ix_nhl_staff_sat_agg_source_sat');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_staff_sat_aggregate_profile_buckets');
        Schema::dropIfExists('nhl_official_sat_aggregate_profile_buckets');
    }
};
