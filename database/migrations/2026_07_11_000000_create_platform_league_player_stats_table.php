<?php

declare(strict_types=1);

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
        Schema::create('platform_league_player_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_league_id')->constrained('platform_leagues')->cascadeOnDelete();
            $table->foreignId('platform_team_id')->nullable()->constrained('platform_teams')->nullOnDelete();
            $table->foreignId('player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->string('platform', 32);
            $table->string('provider_identity_key');
            $table->string('platform_player_id')->nullable();
            $table->string('season', 16);
            $table->string('scoring_period', 64)->nullable();
            $table->string('scope', 32)->default('season');
            $table->json('stats');
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['platform_league_id', 'provider_identity_key'],
                'uq_platform_league_player_stat_identity'
            );
            $table->index(['platform_league_id', 'season', 'scope'], 'ix_platform_league_player_stat_scope');
            $table->index(['platform_team_id', 'season'], 'ix_platform_league_player_stat_team');
            $table->index(['player_id', 'season'], 'ix_platform_league_player_stat_player');
            $table->index(['platform', 'platform_player_id'], 'ix_platform_league_player_stat_provider');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_league_player_stats');
    }
};
