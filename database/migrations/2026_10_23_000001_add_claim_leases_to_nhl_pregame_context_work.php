<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Add ownership fences and a resumable entity cursor without rebuilding historical data. */
    public function up(): void
    {
        Schema::table('nhl_pregame_context_run_days', function (Blueprint $table): void {
            foreach (['league', 'context'] as $stage) {
                $table->uuid($stage.'_claim_token')->nullable();
                $table->timestamp($stage.'_claim_expires_at')->nullable();
            }
            $table->unsignedBigInteger('league_entity_cursor')->default(0);
        });
    }

    /** Remove only the new recovery metadata. */
    public function down(): void
    {
        Schema::table('nhl_pregame_context_run_days', function (Blueprint $table): void {
            foreach (['league', 'context'] as $stage) {
                $table->dropColumn([$stage.'_claim_token', $stage.'_claim_expires_at']);
            }
            $table->dropColumn('league_entity_cursor');
        });
    }
};
