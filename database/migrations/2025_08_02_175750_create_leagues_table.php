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
        Schema::create('leagues', function (Blueprint $table) {
            $table->id();

            // Platform enum (e.g., fantrax, nhl, yahoo)
            $table->enum('platform', ['fantrax', 'yahoo', 'espn'])->default('fantrax');

            // League ID on the platform (e.g. "gnga7rnym9mwml4l")
            $table->string('platform_league_id')->unique();

            // League name (e.g. "Champions League of Hockey")
            $table->string('name');

            // Optional sport (e.g., NHL)
            $table->string('sport')->nullable();

            // Optional Discord server ID for league communication
            $table->string('discord_server_id')->nullable();

            // Optional JSON columns for league settings
            $table->json('draft_settings')->nullable();
            $table->json('scoring_settings')->nullable();
            $table->json('roster_settings')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leagues');
    }
};
