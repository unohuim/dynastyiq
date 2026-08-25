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
        Schema::create('nhl_sat_model_entity_toi_projections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_run_id')
                ->constrained('nhl_model_runs')
                ->cascadeOnDelete();
            $table->json('source_season_ids');
            $table->string('prior_training_season_id', 8)->nullable();
            $table->string('latest_training_season_id', 8)->nullable();
            $table->string('target_season_id', 8)->nullable();
            $table->unsignedTinyInteger('game_type')->default(2);
            $table->string('profile_type', 40);
            $table->string('entity_key', 120);
            $table->integer('entity_id')->nullable();
            $table->string('entity_name')->nullable();
            $table->string('entity_role', 40)->nullable();
            $table->string('team_context', 20)->nullable();
            $table->string('position', 12)->nullable();
            $table->decimal('age_years', 5, 2)->nullable();
            $table->integer('source_team_id')->nullable();
            $table->string('source_team_abbrev', 12)->nullable();
            $table->integer('target_team_id')->nullable();
            $table->string('target_team_abbrev', 12)->nullable();
            $table->decimal('prior_games', 8, 2)->nullable();
            $table->unsignedInteger('prior_toi_seconds')->default(0);
            $table->decimal('prior_toi_per_game_seconds', 10, 2)->nullable();
            $table->decimal('latest_games', 8, 2)->nullable();
            $table->unsignedInteger('latest_toi_seconds')->default(0);
            $table->decimal('latest_toi_per_game_seconds', 10, 2)->nullable();
            $table->decimal('train_games', 8, 2)->nullable();
            $table->unsignedInteger('train_toi_seconds')->default(0);
            $table->decimal('train_toi_per_game_seconds', 10, 2)->nullable();
            $table->string('source_role_bucket', 32)->nullable();
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
            $table->json('projection_inputs')->nullable();
            $table->json('flags')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('projected_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['model_run_id', 'profile_type', 'entity_key'],
                'uq_nhl_sat_model_entity_toi_projection'
            );
            $table->index(['model_run_id', 'profile_type'], 'ix_nhl_sat_model_entity_toi_type');
            $table->index(['profile_type', 'entity_id'], 'ix_nhl_sat_model_entity_toi_entity');
            $table->index(['target_season_id', 'position'], 'ix_nhl_sat_model_entity_toi_target_pos');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_sat_model_entity_toi_projections');
    }
};
