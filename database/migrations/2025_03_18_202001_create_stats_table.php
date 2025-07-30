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
        Schema::create('stats', function (Blueprint $table) {
            $table->id();

            // Identifiers
            $table->foreignId('player_id');
            $table->boolean('is_prospect')->default(false);
            $table->unsignedBigInteger('nhl_team_id')->nullable();
            $table->string('nhl_team_abbrev')->nullable();;

            // Player & team info
            $table->string('player_name')->nullable();
            $table->string('season_id');
            $table->string('league_abbrev');
            $table->string('team_name');

            // Shared metadata
            $table->integer('sequence')->nullable();
            $table->integer('game_type_id')->nullable();

            // Skater stats
            $table->integer('gp')->default(0);
            $table->integer('g')->default(0);
            $table->integer('a')->default(0);
            $table->integer('pts')->default(0);
            $table->integer('gwg')->nullable();
            $table->integer('ppg')->nullable();
            $table->integer('ppp')->nullable();
            $table->integer('shg')->nullable();
            $table->integer('ot_goals')->nullable();
            $table->integer('pim')->nullable();
            $table->integer('plus_minus')->nullable();
            $table->integer('sog')->nullable();
            $table->float('shooting_percentage')->nullable();

            // Time on ice
            $table->string('avg_toi')->nullable();       // e.g., "13:07"
            $table->string('total_toi')->nullable();     // e.g., "3268:33"
            $table->float('toi_minutes')->nullable();    // Parsed total TOI in minutes

            // Per-game and per-60 averages (skater)
            $table->float('g_per_gp')->default(0);
            $table->float('a_per_gp')->default(0);
            $table->float('pts_per_gp')->default(0);
            $table->float('sog_per_gp')->default(0);
            $table->float('g_per_60')->default(0);
            $table->float('a_per_60')->default(0);
            $table->float('pts_per_60')->default(0);
            $table->float('sog_per_60')->default(0);

            // Goalie stats
            $table->integer('wins')->nullable();
            $table->integer('losses')->nullable();
            $table->integer('ot_losses')->nullable();
            $table->integer('shutouts')->nullable();
            $table->float('gaa')->nullable();         // Goals Against Average
            $table->float('sv_pct')->nullable();      // Save Percentage
            $table->integer('saves')->nullable();
            $table->integer('shots_against')->nullable();
            $table->integer('goals_against')->nullable();

            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stats');
    }
};
