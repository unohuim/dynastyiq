<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nhl_pregame_context_runs', function (Blueprint $table): void {
            $table->foreignId('model_run_id')->nullable()->after('id')
                ->constrained('nhl_model_runs')->nullOnDelete();
            $table->index(['model_run_id', 'status'], 'ix_nhl_pregame_runs_model_status');
        });

        Schema::table('nhl_team_game_pregame_contexts', function (Blueprint $table): void {
            $table->foreignId('model_run_id')->nullable()->after('run_id')
                ->constrained('nhl_model_runs')->nullOnDelete();
            $table->dropUnique('nhl_team_pregame_context_unique');
            $table->unique(
                ['model_run_id', 'nhl_game_id', 'nhl_team_id', 'context_version'],
                'nhl_team_pregame_model_context_unique'
            );
            $table->unique(
                ['id', 'model_run_id', 'nhl_game_id', 'context_version'],
                'nhl_team_pregame_context_scope_unique'
            );
        });

        Schema::table('nhl_player_game_pregame_contexts', function (Blueprint $table): void {
            $table->foreignId('model_run_id')->nullable()->after('run_id')
                ->constrained('nhl_model_runs')->nullOnDelete();
            $table->dropUnique('nhl_player_pregame_context_unique');
            $table->unique(
                ['model_run_id', 'nhl_game_id', 'nhl_player_id', 'nhl_team_id', 'context_version'],
                'nhl_player_pregame_model_context_unique'
            );
            $table->dropForeign(['team_context_id']);
            $table->foreign(
                ['team_context_id', 'model_run_id', 'nhl_game_id', 'context_version'],
                'fk_nhl_player_pregame_team_scope'
            )->references(['id', 'model_run_id', 'nhl_game_id', 'context_version'])
                ->on('nhl_team_game_pregame_contexts')->cascadeOnDelete();
        });

        DB::table('nhl_pregame_context_runs')->orderBy('id')->chunkById(200, function ($runs): void {
            foreach ($runs as $run) {
                $modelRunId = data_get(json_decode((string) $run->options, true) ?: [], 'model_run_id');
                if ($modelRunId === null) {
                    continue;
                }

                DB::table('nhl_pregame_context_runs')->where('id', $run->id)->update(['model_run_id' => $modelRunId]);
                DB::table('nhl_team_game_pregame_contexts')->where('run_id', $run->id)->update(['model_run_id' => $modelRunId]);
                DB::table('nhl_player_game_pregame_contexts')->where('run_id', $run->id)->update(['model_run_id' => $modelRunId]);
            }
        });

        Schema::create('nhl_team_game_pregame_context_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_context_id')->constrained('nhl_team_game_pregame_contexts')->cascadeOnDelete();
            $table->string('metric_key', 80);
            $table->string('window_key', 32);
            $table->string('strength', 8)->default('ALL');
            $table->string('measure_key', 32);
            $table->decimal('value', 18, 6)->nullable();
            $table->unsignedInteger('sample_games')->nullable();
            $table->decimal('exposure_seconds', 18, 3)->nullable();
            $table->decimal('league_average', 18, 6)->nullable();
            $table->decimal('signed_difference', 18, 6)->nullable();
            $table->decimal('absolute_difference', 18, 6)->nullable();
            $table->decimal('percentage_difference', 12, 6)->nullable();
            $table->unsignedSmallInteger('league_rank')->nullable();
            $table->unsignedSmallInteger('rank_population')->nullable();
            $table->timestamps();

            $table->unique(
                ['team_context_id', 'metric_key', 'window_key', 'strength', 'measure_key'],
                'nhl_team_pregame_context_metric_unique'
            );
            $table->index(['metric_key', 'window_key', 'strength'], 'ix_nhl_team_pregame_metric_lookup');
        });

        Schema::create('nhl_player_game_pregame_context_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_context_id')->constrained('nhl_player_game_pregame_contexts')->cascadeOnDelete();
            $table->string('metric_key', 80);
            $table->string('window_key', 32);
            $table->string('strength', 8)->default('ALL');
            $table->string('measure_key', 32);
            $table->decimal('value', 18, 6)->nullable();
            $table->unsignedInteger('sample_games')->nullable();
            $table->decimal('exposure_seconds', 18, 3)->nullable();
            $table->decimal('league_average', 18, 6)->nullable();
            $table->decimal('signed_difference', 18, 6)->nullable();
            $table->decimal('absolute_difference', 18, 6)->nullable();
            $table->decimal('percentage_difference', 12, 6)->nullable();
            $table->unsignedInteger('league_rank')->nullable();
            $table->unsignedInteger('rank_population')->nullable();
            $table->timestamps();

            $table->unique(
                ['player_context_id', 'metric_key', 'window_key', 'strength', 'measure_key'],
                'nhl_player_pregame_context_metric_unique'
            );
            $table->index(['metric_key', 'window_key', 'strength'], 'ix_nhl_player_pregame_metric_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhl_player_game_pregame_context_metrics');
        Schema::dropIfExists('nhl_team_game_pregame_context_metrics');

        Schema::table('nhl_player_game_pregame_contexts', function (Blueprint $table): void {
            $table->dropForeign('fk_nhl_player_pregame_team_scope');
            $table->foreign('team_context_id')->references('id')->on('nhl_team_game_pregame_contexts')->nullOnDelete();
            $table->dropUnique('nhl_player_pregame_model_context_unique');
            $table->dropForeign(['model_run_id']);
            $table->dropColumn('model_run_id');
            $table->unique(
                ['nhl_game_id', 'nhl_player_id', 'nhl_team_id', 'context_version'],
                'nhl_player_pregame_context_unique'
            );
        });

        Schema::table('nhl_team_game_pregame_contexts', function (Blueprint $table): void {
            $table->dropUnique('nhl_team_pregame_context_scope_unique');
            $table->dropUnique('nhl_team_pregame_model_context_unique');
            $table->dropForeign(['model_run_id']);
            $table->dropColumn('model_run_id');
            $table->unique(
                ['nhl_game_id', 'nhl_team_id', 'context_version'],
                'nhl_team_pregame_context_unique'
            );
        });

        Schema::table('nhl_pregame_context_runs', function (Blueprint $table): void {
            $table->dropIndex('ix_nhl_pregame_runs_model_status');
            $table->dropForeign(['model_run_id']);
            $table->dropColumn('model_run_id');
        });
    }
};
