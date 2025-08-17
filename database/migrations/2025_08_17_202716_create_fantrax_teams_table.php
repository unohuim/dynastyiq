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
        // fantrax_teams (external string IDs; no FKs)
        Schema::create('fantrax_teams', function (Blueprint $table) {
            $table->id();
            $table->string('fantrax_league_id');   // external
            $table->string('fantrax_team_id');     // external
            $table->string('name');
            $table->timestamps();

            $table->unique(['fantrax_league_id', 'fantrax_team_id'], 'uq_league_team_ext');
            $table->index(['fantrax_league_id'], 'idx_teams_league_ext');
        });


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fantrax_teams');
    }
};
