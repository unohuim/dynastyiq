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
        Schema::create('fantrax_draft_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_league_id')
                ->constrained('platform_leagues')
                ->cascadeOnDelete();
            $table->timestamp('draft_at')->nullable();
            $table->string('status', 32)->default('unknown');
            $table->unsignedInteger('current_draft_pick_count')->default(0);
            $table->unsignedSmallInteger('poll_interval_minutes')->default(1);
            $table->string('draft_results_hash', 64)->nullable();
            $table->string('draft_picks_hash', 64)->nullable();
            $table->json('raw_draft_results')->nullable();
            $table->json('raw_draft_pick_info')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_detected_pick_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique('platform_league_id', 'uq_fantrax_draft_state_league');
            $table->index(['status', 'last_checked_at'], 'idx_fantrax_draft_state_status_checked');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fantrax_draft_states');
    }
};
