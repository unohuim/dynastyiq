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
            $table->string('prediction_target', 32)
                ->default('goal')
                ->after('model_type');
        });

        Schema::table('nhl_shot_attempt_predictions', function (Blueprint $table): void {
            $table->string('prediction_target', 32)
                ->default('goal')
                ->after('expected_goals_model_id');
        });

        Schema::table('nhl_expected_goals_models', function (Blueprint $table): void {
            $table->dropUnique('uq_nhl_xg_models_name_version');
            $table->unique(['name', 'version', 'prediction_target'], 'uq_nhl_xg_models_name_version_target');
        });

        Schema::table('nhl_shot_attempt_predictions', function (Blueprint $table): void {
            $table->index(['prediction_target', 'season_id', 'game_date'], 'ix_nhl_xg_predictions_target_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_shot_attempt_predictions', function (Blueprint $table): void {
            $table->dropIndex('ix_nhl_xg_predictions_target_date');
        });

        Schema::table('nhl_expected_goals_models', function (Blueprint $table): void {
            $table->dropUnique('uq_nhl_xg_models_name_version_target');
            $table->unique(['name', 'version'], 'uq_nhl_xg_models_name_version');
        });

        Schema::table('nhl_shot_attempt_predictions', function (Blueprint $table): void {
            $table->dropColumn('prediction_target');
        });

        Schema::table('nhl_expected_goals_models', function (Blueprint $table): void {
            $table->dropColumn('prediction_target');
        });
    }
};
