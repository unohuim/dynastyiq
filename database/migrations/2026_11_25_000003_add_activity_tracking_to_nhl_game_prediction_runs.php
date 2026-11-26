<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Keep prediction admission, progress and worker ownership on the existing run records. */
return new class extends Migration
{
    /** Extend existing trackers without backfilling or copying historical forecast evidence. */
    public function up(): void
    {
        Schema::table('nhl_game_prediction_runs', function (Blueprint $table): void {
            $table->string('trigger_source', 24)->default('admin');
        });
        Schema::table('nhl_game_prediction_run_games', function (Blueprint $table): void {
            $table->json('request_inputs')->nullable();
            $table->string('phase', 32)->default('queued');
            $table->unsignedInteger('progress_current')->default(0);
            $table->unsignedInteger('progress_total')->nullable();
            $table->unsignedInteger('generation')->default(1);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('recoveries')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->string('claim_token', 64)->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('last_progress_at')->nullable();
            $table->index(['status', 'heartbeat_at'], 'nhl_prediction_activity_heartbeat_idx');
            $table->index(['nhl_game_id', 'status'], 'nhl_prediction_activity_game_idx');
        });
    }

    /** Remove only the activity fields and indexes introduced by this migration. */
    public function down(): void
    {
        Schema::table('nhl_game_prediction_run_games', function (Blueprint $table): void {
            $table->dropIndex('nhl_prediction_activity_heartbeat_idx');
            $table->dropIndex('nhl_prediction_activity_game_idx');
            $table->dropColumn(['request_inputs', 'phase', 'progress_current', 'progress_total', 'generation',
                'attempts', 'recoveries', 'failures', 'claim_token', 'claim_expires_at', 'heartbeat_at', 'dispatched_at', 'last_progress_at']);
        });
        Schema::table('nhl_game_prediction_runs', fn (Blueprint $table) => $table->dropColumn('trigger_source'));
    }
};
