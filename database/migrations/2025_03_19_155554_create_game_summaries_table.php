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

            // FKs
            $table->foreignId('nhl_game_id')->constrained('nhl_games', 'nhl_game_id')->cascadeOnDelete();
            $table->foreignId('nhl_player_id')->constrained('players', 'nhl_id')->cascadeOnDelete();
            $table->unsignedInteger('nhl_team_id');

            // Core counting
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

            // Game winners / OT / Shootout / Penalty shots
            $table->unsignedTinyInteger('gwg')->default(0);
            $table->unsignedTinyInteger('otg')->default(0);
            $table->unsignedTinyInteger('ota')->default(0);
            $table->unsignedSmallInteger('shog')->default(0);
            $table->unsignedTinyInteger('shogwg')->default(0);
            $table->unsignedSmallInteger('ps')->default(0);
            $table->unsignedSmallInteger('psg')->default(0);

            // Empty net
            $table->unsignedSmallInteger('ens')->default(0); // empty-net shots on goal
            $table->unsignedSmallInteger('eng')->default(0); // empty-net goals

            // Milestones
            $table->unsignedTinyInteger('fg')->default(0);   // first goal of the game
            $table->unsignedTinyInteger('htk')->default(0);  // hat trick (>=3 goals in game)

            $table->smallInteger('plus_minus')->default(0);
            $table->unsignedSmallInteger('pim')->default(0);

            // Fights
            $table->unsignedSmallInteger('f')->default(0);

            // TOI / shifts
            $table->unsignedInteger('toi')->nullable(); // seconds
            $table->unsignedSmallInteger('shifts')->default(0);

            // Special teams (for points splits)
            $table->unsignedSmallInteger('ppg')->default(0);
            $table->unsignedSmallInteger('ppa')->default(0);
            $table->unsignedSmallInteger('ppa1')->default(0);
            $table->unsignedSmallInteger('ppa2')->default(0);
            $table->unsignedSmallInteger('ppp')->default(0);

            $table->unsignedSmallInteger('pkg')->default(0);
            $table->unsignedSmallInteger('pka')->default(0);
            $table->unsignedSmallInteger('pkp')->default(0);

            // Blocks / Hits
            $table->unsignedSmallInteger('b')->default(0);
            $table->unsignedSmallInteger('b_teammate')->default(0);

            $table->unsignedSmallInteger('h')->default(0);
            $table->unsignedSmallInteger('th')->default(0);

            // Giveaways / Takeaways
            $table->unsignedSmallInteger('gv')->default(0);
            $table->unsignedSmallInteger('tk')->default(0);
            $table->smallInteger('tkvgv')->default(0);

            // Faceoffs
            $table->unsignedSmallInteger('fow')->default(0);
            $table->unsignedSmallInteger('fol')->default(0);
            $table->unsignedSmallInteger('fot')->default(0);
            $table->decimal('fow_percentage', 5, 2)->default(0);

            // Shooting / Missed / Blocked / Attempts (by skater) — with EV/PP/PK splits
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

            // Goalie-facing
            $table->unsignedSmallInteger('sa')->default(0);
            $table->unsignedSmallInteger('evsa')->default(0);
            $table->unsignedSmallInteger('ppsa')->default(0);
            $table->unsignedSmallInteger('pksa')->default(0);

            $table->unsignedSmallInteger('sv')->default(0);
            $table->unsignedSmallInteger('evsv')->default(0);
            $table->unsignedSmallInteger('ppsv')->default(0);
            $table->unsignedSmallInteger('pksv')->default(0);

            $table->unsignedSmallInteger('shosv')->default(0);          //shootout saves
            $table->unsignedSmallInteger('so')->default(0);             //shutouts

            $table->unsignedSmallInteger('ga')->default(0);
            $table->unsignedSmallInteger('evga')->default(0);
            $table->unsignedSmallInteger('ppga')->default(0);
            $table->unsignedSmallInteger('pkga')->default(0);

            // Shooting % (goals / SOG)
            $table->decimal('sog_p', 6, 3)->default(0);
            $table->decimal('ppsog_p', 6, 3)->default(0);
            $table->decimal('evsog_p', 6, 3)->default(0);
            $table->decimal('pksog_p', 6, 3)->default(0);

            // Maintenance
            $table->timestamps();

            // Keys
            $table->unique(['nhl_game_id', 'nhl_player_id']);
            $table->index(['nhl_player_id', 'nhl_team_id']);
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
