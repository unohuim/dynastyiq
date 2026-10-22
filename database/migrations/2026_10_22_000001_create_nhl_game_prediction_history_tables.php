<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Keep operational default-stack runs and every published calculation independently of custom engines. */
    public function up(): void
    {
        Schema::create('nhl_game_prediction_runs', function (Blueprint $table): void {
            $table->id();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 32)->default('queued');
            $table->unsignedInteger('total_games');
            $table->json('requested_stack');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('nhl_game_prediction_revisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('nhl_game_id');
            $table->foreignId('run_id')->nullable()->constrained('nhl_game_prediction_runs')->nullOnDelete();
            $table->string('source', 16);
            $table->string('fingerprint', 64);
            $table->boolean('is_canonical');
            $table->boolean('prediction_available');
            $table->json('request_inputs');
            $table->json('evidence');
            $table->json('payload');
            $table->json('summary');
            $table->timestamp('predicted_at');
            $table->unique(['run_id', 'nhl_game_id'], 'nhl_prediction_run_revision_unique');
            $table->index(['nhl_game_id', 'is_canonical', 'prediction_available', 'id'], 'nhl_prediction_latest_index');
        });
        Schema::create('nhl_game_prediction_run_games', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('nhl_game_prediction_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('nhl_game_id');
            $table->string('status', 16)->default('queued');
            $table->foreignId('revision_id')->nullable()->constrained('nhl_game_prediction_revisions')->restrictOnDelete();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'nhl_game_id']);
            $table->index(['run_id', 'status']);
        });
    }

    /** Drop only the operational prediction history tables. */
    public function down(): void
    {
        Schema::dropIfExists('nhl_game_prediction_run_games');
        Schema::dropIfExists('nhl_game_prediction_revisions');
        Schema::dropIfExists('nhl_game_prediction_runs');
    }
};
