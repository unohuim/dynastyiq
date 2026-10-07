<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Create isolated evaluation outputs; NHL source and projection tables are unchanged. */
    public function up(): void
    {
        Schema::create('nhl_next_game_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_run_id')->constrained('nhl_model_runs')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('season_id', 8);
            $table->string('status', 16)->default('queued');
            $table->string('version', 40);
            $table->json('inputs')->nullable();
            $table->unsignedInteger('total_games')->default(0);
            $table->unsignedInteger('completed_games')->default(0);
            $table->unsignedInteger('excluded_games')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['model_run_id', 'status']);
        });
        Schema::create('nhl_next_game_evaluation_games', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('evaluation_id')->constrained('nhl_next_game_evaluations')->cascadeOnDelete();
            $table->unsignedInteger('nhl_game_id');
            $table->timestamp('starts_at');
            $table->string('state', 16)->default('pending');
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['evaluation_id', 'nhl_game_id'], 'uq_next_game_work');
            $table->index(['evaluation_id', 'state', 'starts_at'], 'ix_next_game_work');
        });
        Schema::create('nhl_next_game_evaluation_baselines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('evaluation_id')->constrained('nhl_next_game_evaluations')->cascadeOnDelete();
            $table->unsignedInteger('nhl_player_id');
            $table->string('strength', 8);
            $table->json('rates');
            $table->unique(['evaluation_id', 'nhl_player_id', 'strength'], 'uq_next_game_baseline');
        });
        Schema::create('nhl_next_game_evaluation_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('evaluation_id')->constrained('nhl_next_game_evaluations')->cascadeOnDelete();
            $table->unsignedInteger('nhl_game_id');
            $table->unsignedInteger('nhl_player_id');
            $table->unsignedInteger('nhl_team_id');
            $table->string('player_name');
            $table->string('strength', 8);
            $table->unsignedInteger('toi_seconds');
            $table->unsignedSmallInteger('prior_games');
            $table->string('baseline_source', 40);
            $table->json('buckets');
            $table->json('metrics');
            $table->unique(['evaluation_id', 'nhl_game_id', 'nhl_player_id', 'strength'], 'uq_next_game_result');
            $table->index(['evaluation_id', 'strength', 'nhl_player_id'], 'ix_next_game_player');
            $table->index(['evaluation_id', 'nhl_team_id'], 'ix_next_game_team');
        });
    }

    /** Remove only the evaluation-owned tables. */
    public function down(): void
    {
        Schema::dropIfExists('nhl_next_game_evaluation_results');
        Schema::dropIfExists('nhl_next_game_evaluation_baselines');
        Schema::dropIfExists('nhl_next_game_evaluation_games');
        Schema::dropIfExists('nhl_next_game_evaluations');
    }
};
