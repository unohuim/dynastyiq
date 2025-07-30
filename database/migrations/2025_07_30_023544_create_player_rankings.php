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
        Schema::create('player_rankings', function (Blueprint $table) {
            $table->id();

            // which ranking profile this belongs to
            $table->foreignId('ranking_profile_id')
                  ->constrained('ranking_profiles')
                  ->cascadeOnDelete();

            // which player this row applies to
            $table->foreignId('player_id')
                  ->constrained()
                  ->cascadeOnDelete();

            // the ranking value itself (e.g. "A+", "001", etc.)
            $table->string('score')->index();

            // user‑entered note or description for this ranking
            $table->text('description')->nullable();

            // controls who can see *this* ranking row
            $table->enum('visibility', [
                'private',
                'public_authenticated',
                'public_guest',
            ])->default('private');

            // if you support multiple sports
            $table->enum('sport', [
                'hockey',
                'football',
                'basketball',
            ])->default('hockey');

            // any extra per‑row settings
            $table->json('settings')->nullable();

            $table->timestamps();

            // ensure each profile only ranks a given player once
            $table->unique(['ranking_profile_id', 'player_id']);
        });


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('player_rankings');
    }
};
