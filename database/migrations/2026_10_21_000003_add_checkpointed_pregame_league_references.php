<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhl_pregame_context_league_peers', function (Blueprint $table): void {
            $table->foreignId('league_average_id')->constrained('nhl_pregame_context_league_averages')->cascadeOnDelete();
            $table->unsignedBigInteger('entity_id');
            $table->decimal('value', 18, 6);
            $table->unsignedInteger('league_rank');
            $table->primary(['league_average_id', 'entity_id'], 'nhl_pregame_league_peer_primary');
            $table->index(['league_average_id', 'value', 'entity_id'], 'ix_nhl_pregame_league_peer_rank');
        });
        Schema::table('nhl_pregame_context_run_days', function (Blueprint $table): void {
            $table->unsignedInteger('league_cursor')->default(0);
            $table->decimal('league_seconds', 14, 3)->default(0);
            $table->decimal('context_seconds', 14, 3)->default(0);
            $table->decimal('comparison_seconds', 14, 3)->default(0);
        });
        Schema::table('nhl_pregame_context_run_games', function (Blueprint $table): void {
            $table->timestamp('snapshot_completed_at')->nullable();
            $table->timestamp('comparisons_completed_at')->nullable();
            $table->decimal('context_seconds', 14, 3)->default(0);
            $table->decimal('comparison_seconds', 14, 3)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('nhl_pregame_context_run_games', function (Blueprint $table): void {
            $table->dropColumn(['snapshot_completed_at', 'comparisons_completed_at', 'context_seconds', 'comparison_seconds']);
        });
        Schema::table('nhl_pregame_context_run_days', function (Blueprint $table): void {
            $table->dropColumn(['league_cursor', 'league_seconds', 'context_seconds', 'comparison_seconds']);
        });
        Schema::dropIfExists('nhl_pregame_context_league_peers');
    }
};
