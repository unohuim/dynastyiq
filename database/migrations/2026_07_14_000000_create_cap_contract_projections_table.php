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
        Schema::create('cap_contract_projections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_league_id')->constrained('platform_leagues')->cascadeOnDelete();
            $table->foreignId('platform_team_id')->constrained('platform_teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('season_key');
            $table->unsignedBigInteger('projected_aav');
            $table->string('source', 24)->default('system');
            $table->string('basis', 48)->default('last_cap_hit');
            $table->timestamps();

            $table->unique(
                ['platform_league_id', 'platform_team_id', 'user_id', 'player_id', 'season_key'],
                'uq_cap_projection_player_season'
            );
            $table->index(['platform_league_id', 'user_id', 'platform_team_id'], 'idx_cap_projection_team');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cap_contract_projections');
    }
};
