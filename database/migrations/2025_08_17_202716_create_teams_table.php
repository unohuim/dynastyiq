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
        Schema::create('platform_teams', function (Blueprint $table) {
            $table->id();

            // Parent league
            $table->foreignId('platform_league_id')->constrained('platform_leagues')->cascadeOnDelete();

            // External team id within the provider/league
            $table->string('platform_team_id');

            // Names
            $table->string('name');
            $table->string('short_name')->nullable();

            // Provider-specific payload
            $table->json('extras')->nullable();

            // Sync/audit
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            // Uniqueness scoped to league
            $table->unique(['platform_league_id', 'platform_team_id'], 'uq_league_platform_team');

            // Lookup index
            $table->index('platform_league_id', 'idx_team_league');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_teams');
    }
};
