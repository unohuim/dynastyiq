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
            $table->foreignId('player_id');
            $table->boolean('is_prospect')->default(0);
            $table->foreignId('nhl_team_id');
            $table->string('nhl_team_abbrev');
            $table->string('player_name')->nullable();
            $table->string('season_id');
            $table->string('league_abbrev');
            $table->string('team_name');
            $table->integer('G')->default(0);
            $table->integer('A')->default(0);
            $table->integer('PTS')->default(0);
            $table->integer('GP')->default(0);
            $table->float('avgGpGP')->default(0);
            $table->float('avgApGP')->default(0);
            $table->float('avgPTSpGP')->default(0);

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
