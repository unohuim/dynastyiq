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
        Schema::create('nhl_game_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nhl_game_id')->index();
            
            $table->foreignId('nhl_player_id')->index();
            $table->foreignId('nhl_team_id')->index();

            // Goals
            $table->integer('g')->default(0);
            $table->integer('evg')->default(0);

            // Assists
            $table->integer('a')->default(0);
            $table->integer('eva')->default(0);

            // Primary Assists
            $table->integer('a1')->default(0);
            $table->integer('eva1')->default(0);

            // Secondary Assists
            $table->integer('a2')->default(0);
            $table->integer('eva2')->default(0);

            // Points
            $table->integer('pts')->default(0);
            $table->integer('evpts')->default(0);

            // Plus/Minus
            $table->integer('plus_minus')->default(0);

            // Penalty Minutes
            $table->integer('pim')->default(0);

            // Time on Ice (seconds)
            $table->integer('toi')->nullable();
            // Shifts
            $table->integer('shifts')->default(0);

            // Powerplay
            $table->integer('ppg')->default(0);
            $table->integer('ppa')->default(0);
            $table->integer('ppa1')->default(0);
            $table->integer('ppa2')->default(0);
            $table->integer('ppp')->default(0);

            // Penalty Kill
            $table->integer('pkg')->default(0);
            $table->integer('pka')->default(0);
            $table->integer('pkp')->default(0);

            // Blocks
            $table->integer('b')->default(0);
            $table->integer('b_teammate')->default(0);

            // Hits
            $table->integer('h')->default(0);
            $table->integer('th')->default(0);

            // Giveaways & Takeaways
            $table->integer('gv')->default(0);
            $table->integer('tk')->default(0);
            $table->integer('tkvgv')->default(0);

            // Faceoffs
            $table->integer('fow')->default(0);
            $table->integer('fol')->default(0);
            $table->integer('fot')->default(0);
            $table->float('fow_percentage')->default(0);

            // Shots
            $table->integer('sog')->default(0);
            $table->integer('ppsog')->default(0);
            $table->integer('evsog')->default(0);
            $table->integer('sm')->default(0);
            $table->integer('ppsm')->default(0);
            $table->integer('evsm')->default(0);
            $table->integer('sb')->default(0);
            $table->integer('ppsb')->default(0);
            $table->integer('evsb')->default(0);

            // Shots Against
            $table->integer('sa')->default(0);
            $table->integer('evsa')->default(0);
            $table->integer('ppsa')->default(0);
            $table->integer('pksa')->default(0);

            // Saves
            $table->integer('sv')->default(0);
            $table->integer('evsv')->default(0);
            $table->integer('ppsv')->default(0);
            $table->integer('pksv')->default(0);

            // Goals Against
            $table->integer('ga')->default(0);
            $table->integer('evga')->default(0);
            $table->integer('ppga')->default(0);
            $table->integer('pkga')->default(0);

            // More shooting stats
            $table->integer('sha')->default(0);
            $table->integer('ppsha')->default(0);
            $table->integer('evsha')->default(0);

            // Shooting Percentage
            $table->float('sog_p')->default(0);
            $table->float('ppsog_p')->default(0);
            $table->float('evsog_p')->default(0);

            $table->timestamps();

            $table->unique(['nhl_game_id', 'nhl_player_id']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_summaries');
    }
};
