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
        Schema::create('nhl_boxscores', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('nhl_game_id')->index();

            // Player and team identifiers
            $table->unsignedBigInteger('nhl_player_id')->index()->nullable();
            $table->unsignedBigInteger('nhl_team_id')->index();

            $table->integer('sweater_number')->default(0);
            
            // Basic stats
            $table->integer('goals')->default(0);
            $table->integer('assists')->default(0);
            $table->integer('points')->default(0);
            $table->integer('plus_minus')->default(0);
            $table->integer('penalty_minutes')->default(0);
            $table->string('toi')->nullable();
            $table->integer('toi_seconds')->nullable();

            $table->integer('sog')->default(0);
            $table->integer('hits')->default(0);
            $table->integer('blocks')->default(0);

            // Faceoffs
            $table->integer('faceoffs_won')->default(0);
            $table->integer('faceoffs_lost')->default(0);
            $table->float('faceoff_win_percentage')->default(0);

            // Power play stats
            $table->integer('power_play_goals')->default(0);
            $table->integer('power_play_assists')->default(0);

            // Shorthanded stats
            $table->integer('short_handed_goals')->default(0);
            $table->integer('short_handed_assists')->default(0);


            // Giveaways, takeaways
            $table->integer('giveaways')->default(0);
            $table->integer('takeaways')->default(0);

            // Goalie specific
            $table->integer('goals_against')->default(0);
            $table->integer('saves')->default(0);
            $table->integer('shots_against')->default(0);

            $table->integer('ev_saves')->default(0);
            $table->integer('ev_shots_against')->default(0);

            $table->integer('pp_saves')->default(0);
            $table->integer('pp_shots_against')->default(0);

            $table->integer('pk_saves')->default(0);
            $table->integer('pk_shots_against')->default(0);

            // Game metadata
            $table->string('position')->nullable();
            $table->string('player_name')->nullable();

            $table->timestamps();

            $table->unique(['nhl_game_id', 'nhl_player_id']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_boxscores');
    }
};
