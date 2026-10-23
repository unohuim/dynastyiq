<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Store actual team PP numerators and denominators without rewriting historical games. */
    public function up(): void
    {
        Schema::table('nhl_games', function (Blueprint $table): void {
            $table->unsignedSmallInteger('home_pp_goals')->nullable();
            $table->unsignedSmallInteger('home_pp_opportunities')->nullable();
            $table->unsignedSmallInteger('away_pp_goals')->nullable();
            $table->unsignedSmallInteger('away_pp_opportunities')->nullable();
        });
    }

    /** Remove only the added right-rail totals. */
    public function down(): void
    {
        Schema::table('nhl_games', function (Blueprint $table): void {
            $table->dropColumn(['home_pp_goals', 'home_pp_opportunities', 'away_pp_goals', 'away_pp_opportunities']);
        });
    }
};
