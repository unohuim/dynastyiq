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
        // fantrax_league_user_teams (pivot uses external string IDs; user FK only)
        Schema::create('fantrax_league_user_teams', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('fantrax_league_id');   // external
            $table->string('fantrax_team_id');     // external

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'fantrax_league_id', 'fantrax_team_id'], 'uq_user_league_team_ext');
            $table->index(['fantrax_league_id', 'fantrax_team_id'], 'idx_league_team_ext');
        });


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fantrax_league_user_teams');
    }
};
