<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Add independent, first-write-only NHL starter snapshots without backfilling games. */
    public function up(): void
    {
        Schema::table('nhl_games', function (Blueprint $table): void {
            $table->json('away_starter_lock')->nullable();
            $table->json('home_starter_lock')->nullable();
        });
    }

    /** Remove the starter snapshot columns. */
    public function down(): void
    {
        Schema::table('nhl_games', function (Blueprint $table): void {
            $table->dropColumn(['away_starter_lock', 'home_starter_lock']);
        });
    }
};
