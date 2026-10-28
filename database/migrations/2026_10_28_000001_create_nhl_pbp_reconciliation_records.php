<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Add durable live checkpoints and correction history without backfilling events. */
    public function up(): void
    {
        Schema::table('play_by_plays', function (Blueprint $table): void {
            $table->timestamp('withdrawn_at')->nullable();
        });
        Schema::create('nhl_pbp_processing_states', function (Blueprint $table): void {
            $table->unsignedBigInteger('nhl_game_id')->primary();
            $table->unsignedBigInteger('pending_version')->default(0);
            $table->unsignedBigInteger('processed_version')->default(0);
            $table->string('fingerprint', 64)->nullable();
            $table->string('removal_candidate', 64)->nullable();
            $table->json('pending_payload')->nullable();
            $table->json('model_ids')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
        Schema::create('nhl_pbp_event_revisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('nhl_game_id');
            $table->string('nhl_event_id');
            $table->unsignedBigInteger('version');
            $table->string('action', 16);
            $table->json('previous_event')->nullable();
            $table->json('current_event')->nullable();
            $table->timestamp('created_at');
            $table->unique(['nhl_game_id', 'nhl_event_id', 'version'], 'nhl_pbp_revision_identity');
        });
    }

    /** Remove only the reconciliation storage introduced here. */
    public function down(): void
    {
        Schema::dropIfExists('nhl_pbp_event_revisions');
        Schema::dropIfExists('nhl_pbp_processing_states');
        Schema::table('play_by_plays', fn (Blueprint $table) => $table->dropColumn('withdrawn_at'));
    }
};
