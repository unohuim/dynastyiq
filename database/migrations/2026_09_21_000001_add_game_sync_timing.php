<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Persist game-specific sync settings and the last successful scheduled refresh. */
    public function up(): void
    {
        Schema::table('admin_import_schedules', function (Blueprint $table): void {
            $table->json('game_sync_timing')->nullable();
        });
        Schema::table('nhl_games', function (Blueprint $table): void {
            $table->timestamp('boxscore_synced_at')->nullable();
        });
    }

    /** Remove the game sync settings and refresh timestamp. */
    public function down(): void
    {
        Schema::table('admin_import_schedules', fn (Blueprint $table) => $table->dropColumn('game_sync_timing'));
        Schema::table('nhl_games', fn (Blueprint $table) => $table->dropColumn('boxscore_synced_at'));
    }
};
