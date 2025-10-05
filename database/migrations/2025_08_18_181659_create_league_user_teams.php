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
        Schema::create('league_user_teams', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // point league_id at platform_leagues.id (NOT leagues.id)
            $table->foreignId('platform_league_id')->constrained('platform_leagues')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('platform_teams')->cascadeOnDelete();

            $table->boolean('is_active')->default(true);
            $table->json('extras')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            // One team assignment per user per league; and no duplicate user↔team rows
            $table->unique(['user_id', 'platform_league_id'], 'uq_user_league');
            $table->unique(['user_id', 'team_id'], 'uq_user_team');

            $table->index(['platform_league_id', 'team_id'], 'idx_league_team_lookup');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('league_user_teams');
    }
};
