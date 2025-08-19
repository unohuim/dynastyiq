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

            // Provider
            $table->enum('platform', ['fantrax', 'yahoo', 'espn'])->index();

            // External league id (unique per platform)
            $table->string('platform_league_id');

            // Canonical fields
            $table->string('name');
            $table->string('sport')->nullable();

            // Sync/audit
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            // Ensure uniqueness is scoped by platform
            $table->unique(['platform', 'platform_league_id'], 'uq_platform_league');
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
