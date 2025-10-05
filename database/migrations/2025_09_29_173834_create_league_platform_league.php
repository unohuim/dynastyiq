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

        Schema::create('league_platform_league', function (Blueprint $table) {
            $table->id();

            $table->foreignId('league_id')
                ->constrained('leagues')
                ->cascadeOnDelete();

            $table->foreignId('platform_league_id')
                ->constrained('platform_leagues')
                ->cascadeOnDelete();

            $table->timestamp('linked_at')->nullable();
            $table->string('status')->nullable();   // e.g., 'active','pending','unlinked'
            $table->json('meta')->nullable();
            $table->timestamps();

            // No dup links
            $table->unique(['league_id', 'platform_league_id'], 'uq_league_platform_link');

            // Rule: a PlatformLeague can belong to only one League
            $table->unique('platform_league_id', 'uq_external_single_internal');

            // Helpful indexes for common queries
            $table->index(['league_id', 'status', 'linked_at'], 'ix_league_status_linked');
            $table->index(['platform_league_id', 'linked_at'], 'ix_pl_linked');

            // ── ONE ACTIVE PER LEAGUE (pick one approach per DB) ─────────────────
            // PostgreSQL (run as a separate raw statement in migration):
            // DB::statement("CREATE UNIQUE INDEX uq_one_active_per_league
            //                ON league_platform_league (league_id)
            //                WHERE status = 'active';");

            // MySQL 8+ (uncomment both lines to enforce via generated column):
            // $table->boolean('is_active')->virtualAs("status = 'active'");
            // $table->unique(['league_id', 'is_active'], 'uq_one_active_per_league_active_only');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('league_platform_league');
    }
};
