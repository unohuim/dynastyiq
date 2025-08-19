<?php

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
        Schema::create('nhl_season_stats', function (Blueprint $table) {
            $table->id();

            // Keys
            $table->string('season_id', 9); // e.g., '20242025'
            $table->foreignId('nhl_player_id')->constrained('players', 'nhl_id')->cascadeOnDelete();
            $table->unsignedInteger('nhl_team_id');

            // Totals
            $table->unsignedSmallInteger('gp')->default(0);
            $table->unsignedSmallInteger('game_type')->default(0);


            $table->unsignedSmallInteger('g')->default(0);
            $table->unsignedSmallInteger('evg')->default(0);

            $table->unsignedSmallInteger('a')->default(0);
            $table->unsignedSmallInteger('eva')->default(0);

            $table->unsignedSmallInteger('a1')->default(0);
            $table->unsignedSmallInteger('eva1')->default(0);

            $table->unsignedSmallInteger('a2')->default(0);
            $table->unsignedSmallInteger('eva2')->default(0);

            $table->unsignedSmallInteger('pts')->default(0);
            $table->unsignedSmallInteger('evpts')->default(0);

            $table->smallInteger('plus_minus')->default(0);

            $table->unsignedSmallInteger('f')->default(0);
            $table->unsignedSmallInteger('pim')->default(0);

            $table->unsignedInteger('toi')->nullable(); // seconds
            $table->unsignedSmallInteger('shifts')->default(0);

            $table->unsignedSmallInteger('ppg')->default(0);
            $table->unsignedSmallInteger('ppa')->default(0);
            $table->unsignedSmallInteger('ppa1')->default(0);
            $table->unsignedSmallInteger('ppa2')->default(0);
            $table->unsignedSmallInteger('ppp')->default(0);

            $table->unsignedSmallInteger('pkg')->default(0);
            $table->unsignedSmallInteger('pka')->default(0);
            $table->unsignedSmallInteger('pkp')->default(0);

            $table->unsignedSmallInteger('b')->default(0);
            $table->unsignedSmallInteger('b_teammate')->default(0);

            $table->unsignedSmallInteger('h')->default(0);
            $table->unsignedSmallInteger('th')->default(0);

            $table->unsignedSmallInteger('gv')->default(0);
            $table->unsignedSmallInteger('tk')->default(0);
            $table->smallInteger('tkvgv')->default(0);

            $table->unsignedSmallInteger('fow')->default(0);
            $table->unsignedSmallInteger('fol')->default(0);
            $table->unsignedSmallInteger('fot')->default(0);
            $table->decimal('fow_percentage', 5, 2)->default(0);

            // Shooting / Missed / Blocked / Attempts (skater) — with EV/PP/PK splits
            $table->unsignedSmallInteger('sog')->default(0);
            $table->unsignedSmallInteger('ppsog')->default(0);
            $table->unsignedSmallInteger('evsog')->default(0);
            $table->unsignedSmallInteger('pksog')->default(0);

            $table->unsignedSmallInteger('sm')->default(0);
            $table->unsignedSmallInteger('ppsm')->default(0);
            $table->unsignedSmallInteger('evsm')->default(0);
            $table->unsignedSmallInteger('pksm')->default(0);

            $table->unsignedSmallInteger('sb')->default(0);
            $table->unsignedSmallInteger('ppsb')->default(0);
            $table->unsignedSmallInteger('evsb')->default(0);
            $table->unsignedSmallInteger('pksb')->default(0);

            $table->unsignedSmallInteger('sat')->default(0);
            $table->unsignedSmallInteger('ppsat')->default(0);
            $table->unsignedSmallInteger('evsat')->default(0);
            $table->unsignedSmallInteger('pksat')->default(0);

            // Goalie-facing (shots against, saves, goals against) — with EV/PP/PK splits
            $table->unsignedSmallInteger('sa')->default(0);
            $table->unsignedSmallInteger('evsa')->default(0);
            $table->unsignedSmallInteger('ppsa')->default(0);
            $table->unsignedSmallInteger('pksa')->default(0);

            $table->unsignedSmallInteger('sv')->default(0);
            $table->unsignedSmallInteger('evsv')->default(0);
            $table->unsignedSmallInteger('ppsv')->default(0);
            $table->unsignedSmallInteger('pksv')->default(0);

            $table->unsignedSmallInteger('ga')->default(0);
            $table->unsignedSmallInteger('evga')->default(0);
            $table->unsignedSmallInteger('ppga')->default(0);
            $table->unsignedSmallInteger('pkga')->default(0);

            // Shooting % for shots on goal
            $table->decimal('sog_p', 6, 3)->default(0);
            $table->decimal('ppsog_p', 6, 3)->default(0);
            $table->decimal('evsog_p', 6, 3)->default(0);
            $table->decimal('pksog_p', 6, 3)->default(0);

            // Shooting % for all shot attempts (SAT)
            $table->decimal('sat_p', 6, 3)->default(0);
            $table->decimal('ppsat_p', 6, 3)->default(0);
            $table->decimal('evsat_p', 6, 3)->default(0);
            $table->decimal('pksat_p', 6, 3)->default(0);

            // Rate stats (per game, per 60)
            $table->decimal('g_pg', 6, 3)->default(0);
            $table->decimal('a_pg', 6, 3)->default(0);
            $table->decimal('pts_pg', 6, 3)->default(0);
            $table->decimal('g_p60', 6, 3)->default(0);
            $table->decimal('a_p60', 6, 3)->default(0);
            $table->decimal('pts_p60', 6, 3)->default(0);
            $table->decimal('sog_p60', 6, 3)->default(0);
            $table->decimal('sat_p60', 6, 3)->default(0);
            $table->decimal('hits_p60', 6, 3)->default(0);
            $table->decimal('blocks_p60', 6, 3)->default(0);

            $table->timestamps();

            // Uniqueness & indexes
            $table->unique(['season_id', 'nhl_player_id', 'game_type']);
            $table->index(['season_id', 'nhl_player_id']);
            $table->index(['nhl_player_id', 'season_id']);
            $table->index(['season_id', 'game_type']);
        });


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_season_stats');
    }
};
