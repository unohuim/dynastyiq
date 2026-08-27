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
        Schema::table('nhl_game_summaries', function (Blueprint $table): void {
            if (! Schema::hasColumn('nhl_game_summaries', 'hdsat')) {
                $table->unsignedSmallInteger('hdsat')
                    ->default(0)
                    ->after('pksat');
            }
        });

        Schema::table('nhl_season_stats', function (Blueprint $table): void {
            if (! Schema::hasColumn('nhl_season_stats', 'hdsat')) {
                $table->unsignedSmallInteger('hdsat')
                    ->default(0)
                    ->after('pksat');
            }

            if (! Schema::hasColumn('nhl_season_stats', 'hdsat_p60')) {
                $table->decimal('hdsat_p60', 6, 3)
                    ->default(0)
                    ->after('sat_p60');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_season_stats', function (Blueprint $table): void {
            if (Schema::hasColumn('nhl_season_stats', 'hdsat_p60')) {
                $table->dropColumn('hdsat_p60');
            }

            if (Schema::hasColumn('nhl_season_stats', 'hdsat')) {
                $table->dropColumn('hdsat');
            }
        });

        Schema::table('nhl_game_summaries', function (Blueprint $table): void {
            if (Schema::hasColumn('nhl_game_summaries', 'hdsat')) {
                $table->dropColumn('hdsat');
            }
        });
    }
};
