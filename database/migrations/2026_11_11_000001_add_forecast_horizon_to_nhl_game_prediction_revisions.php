<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Add revision-owned forecast metadata without rewriting historical payloads. */
    public function up(): void
    {
        Schema::table('nhl_game_prediction_revisions', function (Blueprint $table): void {
            $table->integer('days_away')->nullable();
            $table->decimal('public_confidence_score', 5, 2)->nullable();
        });
    }

    /** Remove only the forecast metadata introduced by this migration. */
    public function down(): void
    {
        Schema::table('nhl_game_prediction_revisions', function (Blueprint $table): void {
            $table->dropColumn(['days_away', 'public_confidence_score']);
        });
    }
};
