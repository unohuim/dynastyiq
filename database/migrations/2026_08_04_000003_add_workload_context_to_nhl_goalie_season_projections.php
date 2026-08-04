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
        Schema::table('nhl_goalie_season_projections', function (Blueprint $table): void {
            $table->string('goalie_workload_projection_version', 80)->nullable()->after('projection_version');
            $table->string('toi_projection_version', 80)->nullable()->after('goalie_workload_projection_version');
            $table->decimal('projected_starts', 8, 2)->nullable()->after('projected_games');
            $table->decimal('projected_relief_games', 8, 2)->nullable()->after('projected_starts');
            $table->unsignedInteger('projected_toi_seconds')->nullable()->after('projected_relief_games');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_goalie_season_projections', function (Blueprint $table): void {
            $table->dropColumn([
                'goalie_workload_projection_version',
                'toi_projection_version',
                'projected_starts',
                'projected_relief_games',
                'projected_toi_seconds',
            ]);
        });
    }
};
