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
        Schema::table('nhl_player_season_projections', function (Blueprint $table): void {
            $table->decimal('source_goals_above_xgf', 10, 4)->nullable()->after('source_xgf');
            $table->decimal('finishing_regression_weight', 5, 4)->nullable()->after('projected_xgf');
            $table->decimal('projected_goals_adjustment', 10, 4)->nullable()->after('finishing_regression_weight');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_player_season_projections', function (Blueprint $table): void {
            $table->dropColumn([
                'source_goals_above_xgf',
                'finishing_regression_weight',
                'projected_goals_adjustment',
            ]);
        });
    }
};
