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
        Schema::create('nhl_skater_defensive_chance_projections', function (Blueprint $table): void {
            $table->id();
            $table->string('projection_version', 80);
            $table->string('source_season_id', 8);
            $table->string('target_season_id', 8);
            $table->integer('player_id');
            $table->integer('source_team_id')->nullable();
            $table->string('source_team_abbrev', 12)->nullable();
            $table->integer('target_team_id')->nullable();
            $table->string('target_team_abbrev', 12)->nullable();
            $table->string('position', 12)->nullable();
            $table->unsignedBigInteger('toi_projection_id')->nullable();
            $table->string('toi_projection_version', 80)->nullable();

            $table->decimal('source_games', 8, 2)->nullable();
            $table->unsignedInteger('source_toi_seconds')->nullable();
            $table->unsignedInteger('source_sat_against_on_ice')->default(0);
            $table->unsignedInteger('source_sog_against_on_ice')->default(0);
            $table->unsignedInteger('source_goals_against_on_ice')->default(0);
            $table->decimal('source_xga_on_ice', 10, 4)->nullable();
            $table->decimal('source_xsoga_on_ice', 10, 4)->nullable();

            $table->decimal('projected_games', 8, 2)->nullable();
            $table->decimal('projected_toi_hours', 10, 4)->nullable();
            $table->decimal('projected_sata', 10, 2)->nullable();
            $table->decimal('projected_soga', 10, 2)->nullable();
            $table->decimal('projected_xga', 10, 4)->nullable();
            $table->decimal('projected_xsoga', 10, 4)->nullable();
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
                'uq_nhl_sdc_projection_version_player'
            );
            $table->foreign('toi_projection_id', 'fk_nhl_sdc_projection_toi')
                ->references('id')
                ->on('nhl_player_toi_projections')
                ->nullOnDelete();
            $table->index(['target_season_id', 'target_team_abbrev', 'position'], 'ix_nhl_sdc_projection_team_pos');
            $table->index(['source_season_id', 'target_season_id'], 'ix_nhl_sdc_projection_source_target');
        });

        Schema::create('nhl_skater_defensive_chance_projection_buckets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('skater_defensive_chance_projection_id');
            $table->unsignedBigInteger('source_profile_bucket_id')->nullable();
            $table->string('projection_version', 80);
            $table->string('source_season_id', 8);
            $table->string('target_season_id', 8);
            $table->integer('player_id');
            $table->integer('source_team_id')->nullable();
            $table->string('source_team_abbrev', 12)->nullable();
            $table->integer('target_team_id')->nullable();
            $table->string('target_team_abbrev', 12)->nullable();
            $table->string('position', 12)->nullable();

            $table->string('matched_bucket_key', 600);
            $table->unsignedSmallInteger('fallback_level');
            $table->json('bucket_dimensions');
            $table->string('shot_type_group', 32)->nullable();
            $table->string('distance_group', 32)->nullable();
            $table->string('angle_group', 32)->nullable();
            $table->string('sequence_group', 32)->nullable();

            $table->unsignedInteger('source_sat_against_on_ice')->default(0);
            $table->unsignedInteger('source_sog_against_on_ice')->default(0);
            $table->unsignedInteger('source_goals_against_on_ice')->default(0);
            $table->decimal('source_xga_on_ice', 10, 4)->nullable();
            $table->decimal('source_xsoga_on_ice', 10, 4)->nullable();
            $table->decimal('source_profile_share_against', 9, 6)->nullable();

            $table->decimal('projected_sata', 10, 2)->nullable();
            $table->decimal('projected_soga', 10, 2)->nullable();
            $table->decimal('projected_xga', 10, 4)->nullable();
            $table->decimal('projected_xsoga', 10, 4)->nullable();
            $table->decimal('projected_profile_share_against', 9, 6)->nullable();
            $table->decimal('goal_probability_against', 9, 6)->nullable();
            $table->decimal('shot_on_goal_probability_against', 9, 6)->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->string('confidence_bucket', 24)->nullable();
            $table->json('projection_inputs')->nullable();
            $table->json('flags')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['projection_version', 'target_season_id', 'player_id', 'matched_bucket_key'],
                'uq_nhl_sdc_projection_bucket_version_player'
            );
            $table->foreign('skater_defensive_chance_projection_id', 'fk_nhl_sdc_bucket_projection')
                ->references('id')
                ->on('nhl_skater_defensive_chance_projections')
                ->cascadeOnDelete();
            $table->foreign('source_profile_bucket_id', 'fk_nhl_sdc_bucket_source_profile')
                ->references('id')
                ->on('nhl_skater_defensive_chance_profile_buckets')
                ->nullOnDelete();
            $table->index(['target_season_id', 'player_id', 'fallback_level'], 'ix_nhl_sdc_projection_bucket_player');
            $table->index(['target_season_id', 'shot_type_group', 'distance_group', 'angle_group'], 'ix_nhl_sdc_projection_bucket_profile');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_skater_defensive_chance_projection_buckets');
        Schema::dropIfExists('nhl_skater_defensive_chance_projections');
    }
};
