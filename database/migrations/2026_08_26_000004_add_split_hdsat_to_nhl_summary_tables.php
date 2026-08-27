<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nhl_game_summaries', function (Blueprint $table): void {
            if (! Schema::hasColumn('nhl_game_summaries', 'evhdsat')) {
                $table->unsignedSmallInteger('evhdsat')->default(0)->after('hdsat');
            }

            if (! Schema::hasColumn('nhl_game_summaries', 'pphdsat')) {
                $table->unsignedSmallInteger('pphdsat')->default(0)->after('evhdsat');
            }

            if (! Schema::hasColumn('nhl_game_summaries', 'pkhdsat')) {
                $table->unsignedSmallInteger('pkhdsat')->default(0)->after('pphdsat');
            }
        });

        Schema::table('nhl_season_stats', function (Blueprint $table): void {
            if (! Schema::hasColumn('nhl_season_stats', 'evhdsat')) {
                $table->unsignedSmallInteger('evhdsat')->default(0)->after('hdsat');
            }

            if (! Schema::hasColumn('nhl_season_stats', 'pphdsat')) {
                $table->unsignedSmallInteger('pphdsat')->default(0)->after('evhdsat');
            }

            if (! Schema::hasColumn('nhl_season_stats', 'pkhdsat')) {
                $table->unsignedSmallInteger('pkhdsat')->default(0)->after('pphdsat');
            }

            if (! Schema::hasColumn('nhl_season_stats', 'evhdsat_p60')) {
                $table->decimal('evhdsat_p60', 6, 3)->default(0)->after('hdsat_p60');
            }

            if (! Schema::hasColumn('nhl_season_stats', 'pphdsat_p60')) {
                $table->decimal('pphdsat_p60', 6, 3)->default(0)->after('evhdsat_p60');
            }

            if (! Schema::hasColumn('nhl_season_stats', 'pkhdsat_p60')) {
                $table->decimal('pkhdsat_p60', 6, 3)->default(0)->after('pphdsat_p60');
            }
        });
    }

    public function down(): void
    {
        Schema::table('nhl_season_stats', function (Blueprint $table): void {
            foreach (['pkhdsat_p60', 'pphdsat_p60', 'evhdsat_p60', 'pkhdsat', 'pphdsat', 'evhdsat'] as $column) {
                if (Schema::hasColumn('nhl_season_stats', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('nhl_game_summaries', function (Blueprint $table): void {
            foreach (['pkhdsat', 'pphdsat', 'evhdsat'] as $column) {
                if (Schema::hasColumn('nhl_game_summaries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
