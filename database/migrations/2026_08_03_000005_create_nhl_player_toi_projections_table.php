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
        Schema::create('nhl_player_toi_projections', function (Blueprint $table): void {
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
            $table->decimal('age_years', 5, 2)->nullable();

            $table->decimal('source_games', 8, 2)->nullable();
            $table->unsignedInteger('source_toi_seconds')->default(0);
            $table->decimal('source_toi_per_game_seconds', 10, 2)->nullable();
            $table->unsignedInteger('source_points')->default(0);
            $table->unsignedInteger('source_team_points_rank')->nullable();
            $table->string('source_role_bucket', 32)->nullable();

            $table->unsignedInteger('target_team_points_rank')->nullable();
            $table->string('target_role_bucket', 32)->nullable();
            $table->decimal('projected_games', 8, 2)->nullable();
            $table->unsignedInteger('projected_toi_seconds')->default(0);
            $table->decimal('projected_toi_per_game_seconds', 10, 2)->nullable();
            $table->decimal('projected_toi_hours', 10, 4)->nullable();

            $table->decimal('age_adjustment_seconds_per_game', 10, 2)->nullable();
            $table->decimal('role_adjustment_seconds_per_game', 10, 2)->nullable();
            $table->decimal('team_change_adjustment_seconds_per_game', 10, 2)->nullable();
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
                'uq_nhl_player_toi_projection_version_player'
            );
            $table->index(['source_season_id', 'target_season_id'], 'ix_nhl_player_toi_projection_source_target');
            $table->index(['target_season_id', 'status'], 'ix_nhl_player_toi_projection_target_status');
            $table->index(['target_season_id', 'target_team_abbrev', 'position'], 'ix_nhl_player_toi_projection_team_pos');
            $table->index(['target_season_id', 'target_role_bucket'], 'ix_nhl_player_toi_projection_role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_player_toi_projections');
    }
};
