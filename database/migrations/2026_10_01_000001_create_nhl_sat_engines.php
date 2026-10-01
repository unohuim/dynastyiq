<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Create engine definitions and immutable evaluation snapshots. */
    public function up(): void
    {
        Schema::create('nhl_sat_engines', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->foreignId('model_run_id')->constrained('nhl_model_runs')->restrictOnDelete();
            $table->json('settings');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('nhl_sat_engine_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('engine_id')->nullable()->constrained('nhl_sat_engines')->nullOnDelete();
            $table->foreignId('model_run_id')->constrained('nhl_model_runs')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('status', 20)->default('queued')->index();
            $table->json('definition');
            $table->unsignedInteger('game_count');
            $table->unsignedInteger('prediction_count');
            $table->unsignedInteger('predictions_completed')->default(0);
            $table->unsignedInteger('candidate_count');
            $table->unsignedInteger('candidates_completed')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('nhl_sat_engine_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('nhl_sat_engine_runs')->cascadeOnDelete();
            $table->unsignedInteger('split_index');
            $table->json('settings');
            $table->json('metrics')->nullable();
            $table->decimal('win_pct', 8, 4)->nullable();
            $table->decimal('coverage_pct', 8, 4)->nullable();
            $table->boolean('meets_targets')->default(false);
            $table->timestamps();
            $table->index(['run_id', 'split_index']);
        });
        Schema::create('nhl_sat_engine_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('nhl_sat_engine_runs')->cascadeOnDelete();
            $table->unsignedInteger('split_index');
            $table->unsignedBigInteger('nhl_game_id');
            $table->string('status', 20);
            $table->string('reason', 500)->nullable();
            $table->json('game');
            $table->unsignedSmallInteger('confidence')->nullable();
            $table->decimal('gap', 12, 6)->nullable();
            $table->boolean('correct')->nullable();
            $table->decimal('pred_sat', 14, 6)->nullable();
            $table->decimal('pred_sog', 14, 6)->nullable();
            $table->decimal('pred_goals', 14, 6)->nullable();
            $table->unsignedInteger('actual_sat')->nullable();
            $table->unsignedInteger('actual_sog')->nullable();
            $table->unsignedInteger('actual_goals')->nullable();
            $table->json('prediction')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'split_index', 'nhl_game_id'], 'nhl_engine_result_unique');
        });
    }

    /** Remove only engine-management storage. */
    public function down(): void
    {
        Schema::dropIfExists('nhl_sat_engine_results');
        Schema::dropIfExists('nhl_sat_engine_candidates');
        Schema::dropIfExists('nhl_sat_engine_runs');
        Schema::dropIfExists('nhl_sat_engines');
    }
};
