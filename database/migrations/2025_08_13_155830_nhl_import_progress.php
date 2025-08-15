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
        Schema::create('nhl_import_progress', function (Blueprint $table) {
            $table->id();

            // The NHL season (e.g., "20232024")
            $table->string('season_id', 8);

            // Date of the game
            $table->date('game_date');

            // NHL's gamePk
            $table->string('game_id', 10);

            // Type of game: 1=Preseason, 2=Regular Season, 3=Playoffs, etc.
            $table->unsignedTinyInteger('game_type')->nullable();

            // Type of import (pbp, shifts, boxscore, summary)
            $table->enum('import_type', ['pbp', 'summary', 'shifts', 'boxscore', 'shift-units', 'connect-events']);

            // Number of items processed (e.g., events, shifts)
            $table->unsignedInteger('items_count')->default(0);

            // Status of this import
            $table->enum('status', ['scheduled', 'running', 'error', 'completed'])
                  ->default('scheduled');

            // When this game/import type was first discovered/queued
            $table->timestamp('discovered_at')->nullable();

            // Last error message (if any)
            $table->text('last_error')->nullable();

            $table->timestamps();

            // Ensure no duplicates for same game & import type
            $table->unique(['game_id', 'import_type']);

            // Common filter indexes
            $table->index(['season_id', 'game_date']);
            $table->index(['status']);
            $table->index(['game_type']);

            // Composite index for season + game_type lookups
            $table->index(['season_id', 'game_type']);
        });



    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
