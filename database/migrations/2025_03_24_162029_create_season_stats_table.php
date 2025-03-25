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
        Schema::create('season_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id');
            $table->integer('nhl_player_id')->nullable();
            $table->foreignId('season_id');
            $table->foreignId('nhl_team_id');
            $table->integer('GP')->default(0);

            //GOALS
            $table->integer('G');
            $table->integer('EVG');

            // //ASSISTS
            $table->integer('A');
            $table->integer('EVA');

            // //PRIMARY ASSISTS
            $table->integer('A1');
            $table->integer('EVA1');

            // //SECONDARY ASSISTS
            $table->integer('A2');
            $table->integer('EVA2');

            // //POINTS
            $table->integer('PTS');
            $table->integer('EVPTS');

            // //PIM
            $table->integer('PIM');

            // //TOI
            $table->integer('TOI');

            // //SHIFTS
            $table->integer('SHIFTS');

            // //POWERPLAY
            $table->integer('PPG');
            $table->integer('PPA');
            $table->integer('PPA1');
            $table->integer('PPA2');
            $table->integer('PPP');

            // //PENALTY KILL
            $table->integer('SHG');
            $table->integer('SHA');
            $table->integer("SHP");

            // //BLOCKS
            $table->integer('B');
      

            // //HITS
            $table->integer('H');
            $table->integer('TH');
            

            //GIVEAWAYS & TAKEAWAYS
            $table->integer('GV');
            $table->integer('TK');
            $table->integer('TKvGV');
        

            // //FACEOFFS
            $table->integer('FOW')->default(0);
            $table->integer('FOL')->default(0);
            $table->integer('FOT')->default(0);
            $table->float('FOW_percentage')->default(0);
          

            //SHOTS
            $table->integer('SOG');
            $table->integer('PPSOG');
            $table->integer('EVSOG');
            $table->integer('SM');
            $table->integer('PPSM');
            $table->integer('EVSM');
            $table->integer('SB');
            $table->integer('PPSB');
            $table->integer('EVSB');
            $table->integer('SA');
            $table->integer('PPSA');
            $table->integer('EVSA');
            $table->float('SOGvSA_p');


            //SHOOTING PERCENTAGE
            $table->float('SOG_p');
            $table->float('PPSOG_p');
            $table->float('EVSOG_p');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('season_stats');
    }
};
