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
        Schema::table('nhl_expected_goals_models', function (Blueprint $table): void {
            $table->foreignId('model_run_id')
                ->nullable()
                ->after('id')
                ->constrained('nhl_model_runs')
                ->nullOnDelete();

            $table->index('model_run_id', 'ix_nhl_xg_models_model_run');
        });

        Schema::table('nhl_shot_attempt_predictions', function (Blueprint $table): void {
            $table->foreignId('model_run_id')
                ->nullable()
                ->after('id')
                ->constrained('nhl_model_runs')
                ->nullOnDelete();

            $table->index(['model_run_id', 'season_id'], 'ix_nhl_xg_predictions_model_run_season');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_shot_attempt_predictions', function (Blueprint $table): void {
            $table->dropIndex('ix_nhl_xg_predictions_model_run_season');
            $table->dropConstrainedForeignId('model_run_id');
        });

        Schema::table('nhl_expected_goals_models', function (Blueprint $table): void {
            $table->dropIndex('ix_nhl_xg_models_model_run');
            $table->dropConstrainedForeignId('model_run_id');
        });
    }
};
