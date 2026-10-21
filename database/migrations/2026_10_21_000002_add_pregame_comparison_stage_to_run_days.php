<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nhl_pregame_context_run_days', function (Blueprint $table): void {
            $table->string('comparison_state', 24)->default('pending');
            $table->timestamp('comparison_started_at')->nullable();
            $table->timestamp('comparison_completed_at')->nullable();
            $table->index(['run_id', 'comparison_state', 'game_date'], 'ix_nhl_pregame_run_day_comparison');
        });
    }

    public function down(): void
    {
        Schema::table('nhl_pregame_context_run_days', function (Blueprint $table): void {
            $table->dropIndex('ix_nhl_pregame_run_day_comparison');
            $table->dropColumn(['comparison_state', 'comparison_started_at', 'comparison_completed_at']);
        });
    }
};
