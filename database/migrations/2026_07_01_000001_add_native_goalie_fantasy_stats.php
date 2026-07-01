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
            $table->boolean('goalie_started')->default(false)->after('so');
            $table->string('goalie_decision', 3)->nullable()->after('goalie_started');
            $table->boolean('quality_start')->default(false)->after('goalie_decision');
            $table->boolean('really_bad_start')->default(false)->after('quality_start');
            $table->decimal('sv_pct', 6, 3)->default(0)->after('really_bad_start');
            $table->decimal('gaa', 6, 3)->default(0)->after('sv_pct');
        });

        Schema::table('nhl_season_stats', function (Blueprint $table): void {
            $table->unsignedSmallInteger('wins')->default(0)->after('so');
            $table->unsignedSmallInteger('losses')->default(0)->after('wins');
            $table->unsignedSmallInteger('ot_losses')->default(0)->after('losses');
            $table->unsignedSmallInteger('starts')->default(0)->after('ot_losses');
            $table->unsignedSmallInteger('relief_appearances')->default(0)->after('starts');
            $table->unsignedSmallInteger('quality_starts')->default(0)->after('relief_appearances');
            $table->unsignedSmallInteger('really_bad_starts')->default(0)->after('quality_starts');
            $table->decimal('quality_start_percentage', 6, 3)->default(0)->after('really_bad_starts');
            $table->decimal('sv_pct', 6, 3)->default(0)->after('quality_start_percentage');
            $table->decimal('gaa', 6, 3)->default(0)->after('sv_pct');
            $table->decimal('ev_sv_pct', 6, 3)->default(0)->after('gaa');
            $table->decimal('pp_sv_pct', 6, 3)->default(0)->after('ev_sv_pct');
            $table->decimal('pk_sv_pct', 6, 3)->default(0)->after('pp_sv_pct');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhl_season_stats', function (Blueprint $table): void {
            $table->dropColumn([
                'wins',
                'losses',
                'ot_losses',
                'starts',
                'relief_appearances',
                'quality_starts',
                'really_bad_starts',
                'quality_start_percentage',
                'sv_pct',
                'gaa',
                'ev_sv_pct',
                'pp_sv_pct',
                'pk_sv_pct',
            ]);
        });

        Schema::table('nhl_game_summaries', function (Blueprint $table): void {
            $table->dropColumn([
                'goalie_started',
                'goalie_decision',
                'quality_start',
                'really_bad_start',
                'sv_pct',
                'gaa',
            ]);
        });
    }
};
