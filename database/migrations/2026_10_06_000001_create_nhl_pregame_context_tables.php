<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhl_arena_locations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('nhl_team_id')->index();
            $table->string('venue_name', 160);
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['nhl_team_id', 'effective_from']);
        });

        Schema::create('nhl_pregame_context_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('action', 24);
            $table->string('status', 24)->default('queued');
            $table->json('season_ids');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('current_game_date')->nullable();
            $table->unsignedInteger('total_games')->default(0);
            $table->unsignedInteger('ready_games')->default(0);
            $table->unsignedInteger('processing_games')->default(0);
            $table->unsignedInteger('completed_games')->default(0);
            $table->unsignedInteger('blocked_games')->default(0);
            $table->unsignedInteger('failed_games')->default(0);
            $table->json('options')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['action', 'status']);
            $table->index(['start_date', 'end_date']);
        });

        Schema::create('nhl_pregame_context_run_games', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('nhl_pregame_context_runs')->cascadeOnDelete();
            $table->unsignedInteger('nhl_game_id');
            $table->date('game_date');
            $table->unsignedInteger('sequence');
            $table->string('state', 24)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('team_context_count')->default(0);
            $table->unsignedSmallInteger('player_context_count')->default(0);
            $table->json('readiness')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'nhl_game_id']);
            $table->index(['run_id', 'state', 'game_date']);
        });

        Schema::create('nhl_team_game_pregame_contexts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->nullable()->constrained('nhl_pregame_context_runs')->nullOnDelete();
            $table->unsignedInteger('nhl_game_id');
            $table->unsignedInteger('nhl_team_id');
            $table->unsignedInteger('opponent_team_id');
            $table->date('game_date');
            $table->timestamp('source_cutoff_at');
            $table->string('venue', 8);
            $table->string('context_version', 40);
            $table->json('schedule_metrics');
            $table->json('opponent_metrics');
            $table->json('metrics')->nullable();
            $table->json('ranks')->nullable();
            $table->timestamps();

            $table->unique(['nhl_game_id', 'nhl_team_id', 'context_version'], 'nhl_team_pregame_context_unique');
            $table->index(['nhl_team_id', 'game_date']);
            $table->index(['opponent_team_id', 'game_date']);
        });

        Schema::create('nhl_player_game_pregame_contexts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->nullable()->constrained('nhl_pregame_context_runs')->nullOnDelete();
            $table->foreignId('team_context_id')->nullable()->constrained('nhl_team_game_pregame_contexts')->nullOnDelete();
            $table->unsignedInteger('nhl_game_id');
            $table->unsignedInteger('nhl_player_id');
            $table->unsignedInteger('nhl_team_id');
            $table->unsignedInteger('opponent_team_id');
            $table->date('game_date');
            $table->timestamp('source_cutoff_at');
            $table->string('venue', 8);
            $table->string('participant_source', 32);
            $table->string('context_version', 40);
            $table->json('metrics');
            $table->json('ranks')->nullable();
            $table->timestamps();

            $table->unique(['nhl_game_id', 'nhl_player_id', 'nhl_team_id', 'context_version'], 'nhl_player_pregame_context_unique');
            $table->index(['nhl_player_id', 'game_date']);
            $table->index(['nhl_team_id', 'opponent_team_id', 'game_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhl_player_game_pregame_contexts');
        Schema::dropIfExists('nhl_team_game_pregame_contexts');
        Schema::dropIfExists('nhl_pregame_context_run_games');
        Schema::dropIfExists('nhl_pregame_context_runs');
        Schema::dropIfExists('nhl_arena_locations');
    }
};
