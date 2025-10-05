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

        Schema::create('platform_leagues', function (Blueprint $table) {
            $table->id();

            // External provider (must be known if a row exists)
            $table->enum('platform', ['fantrax', 'yahoo', 'espn'])->index();

            // Provider’s league id (required with platform)
            $table->string('platform_league_id');

            // Snapshotty fields (don’t drive app logic)
            $table->string('name');
            $table->string('sport')->nullable();

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            // Uniqueness at the provider boundary
            $table->unique(['platform', 'platform_league_id'], 'uq_platform_league');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_leagues');
    }
};
