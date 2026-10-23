<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    /** Support bounded player history reads without blocking shot-fact imports during index creation. */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS ix_nhl_saf_pregame_shooter ON nhl_shot_attempts_facts (shooter_player_id, nhl_game_id) INCLUDE (strength, strength_bucket, is_shot_on_goal, is_goal, period_type) WHERE is_shot_attempt = true');
        }
    }

    /** Remove only the dedicated pregame history lookup index. */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ix_nhl_saf_pregame_shooter');
        }
    }
};
