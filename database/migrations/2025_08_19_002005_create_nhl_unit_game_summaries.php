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
        Schema::create('nhl_unit_game_summaries', function (Blueprint $table) {
            $table->id();

            // Keys
            $table->foreignId('nhl_game_id')->constrained('nhl_games', 'nhl_game_id')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('nhl_units')->cascadeOnDelete();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('team_abbrev')->nullable()->index();

            // TOI / shifts
            $table->unsignedInteger('toi')->default(0);
            $table->unsignedSmallInteger('shifts')->default(0);

            // Zone starts
            $table->unsignedSmallInteger('ozs')->default(0);
            $table->unsignedSmallInteger('nzs')->default(0);
            $table->unsignedSmallInteger('dzs')->default(0);

            // On-ice goals for/against (strength splits)
            $table->unsignedSmallInteger('gf')->default(0);
            $table->unsignedSmallInteger('ga')->default(0);
            $table->unsignedSmallInteger('ev_gf')->default(0);
            $table->unsignedSmallInteger('pp_gf')->default(0);
            $table->unsignedSmallInteger('pk_gf')->default(0);
            $table->unsignedSmallInteger('ev_ga')->default(0);
            $table->unsignedSmallInteger('pp_ga')->default(0);
            $table->unsignedSmallInteger('pk_ga')->default(0);

            // On-ice shots on goal for/against (team perspective)
            $table->unsignedSmallInteger('sf')->default(0);
            $table->unsignedSmallInteger('sa')->default(0);
            $table->unsignedSmallInteger('ev_sf')->default(0);
            $table->unsignedSmallInteger('pp_sf')->default(0);
            $table->unsignedSmallInteger('pk_sf')->default(0);
            $table->unsignedSmallInteger('ev_sa')->default(0);
            $table->unsignedSmallInteger('pp_sa')->default(0);
            $table->unsignedSmallInteger('pk_sa')->default(0);

            // Shot attempts (Corsi)
            $table->unsignedSmallInteger('satf')->default(0);
            $table->unsignedSmallInteger('sata')->default(0);

            // Fenwick (exclude blocks)
            $table->unsignedSmallInteger('ff')->default(0);
            $table->unsignedSmallInteger('fa')->default(0);

            // Blocks & Hits (team perspective)
            $table->unsignedSmallInteger('bf')->default(0);
            $table->unsignedSmallInteger('ba')->default(0);
            $table->unsignedSmallInteger('hf')->default(0);
            $table->unsignedSmallInteger('ha')->default(0);

            // Faceoffs while on ice
            $table->unsignedSmallInteger('fow')->default(0);
            $table->unsignedSmallInteger('fol')->default(0);
            $table->unsignedSmallInteger('fot')->default(0);

            // Fights (team perspective) and PIM
            $table->unsignedSmallInteger('f')->default(0);
            $table->unsignedSmallInteger('pim_f')->default(0);
            $table->unsignedSmallInteger('pim_a')->default(0);
            $table->unsignedSmallInteger('penalties_f')->default(0);
            $table->unsignedSmallInteger('penalties_a')->default(0);


            $table->timestamps();

            $table->unique(['nhl_game_id', 'unit_id']);
            $table->index(['nhl_game_id']);
        });


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_unit_game_summaries');
    }
};
