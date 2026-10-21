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
        Schema::create('nhl_pregame_context_run_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('nhl_pregame_context_runs')->cascadeOnDelete();
            $table->date('game_date');
            $table->string('context_state', 24)->default('pending');
            $table->string('league_state', 24)->default('pending');
            $table->unsignedSmallInteger('game_count')->default(0);
            $table->unsignedSmallInteger('completed_games')->default(0);
            $table->unsignedSmallInteger('blocked_games')->default(0);
            $table->unsignedSmallInteger('failed_games')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('context_started_at')->nullable();
            $table->timestamp('context_completed_at')->nullable();
            $table->timestamp('league_started_at')->nullable();
            $table->timestamp('league_completed_at')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'game_date'], 'nhl_pregame_run_day_unique');
            $table->index(['run_id', 'context_state', 'game_date'], 'ix_nhl_pregame_run_day_context');
            $table->index(['run_id', 'league_state', 'game_date'], 'ix_nhl_pregame_run_day_league');
        });

        Schema::create('nhl_pregame_context_league_averages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('nhl_pregame_context_runs')->cascadeOnDelete();
            $table->foreignId('model_run_id')->nullable()->constrained('nhl_model_runs')->nullOnDelete();
            $table->foreignId('run_day_id')->constrained('nhl_pregame_context_run_days')->cascadeOnDelete();
            $table->date('game_date');
            $table->timestamp('source_cutoff_at');
            $table->string('entity_type', 12);
            $table->string('metric_key', 80);
            $table->string('window_key', 32);
            $table->string('strength', 8)->default('ALL');
            $table->decimal('league_average', 18, 6);
            $table->unsignedInteger('rank_population');
            $table->timestamps();

            $table->unique(
                ['run_id', 'source_cutoff_at', 'entity_type', 'metric_key', 'window_key', 'strength'],
                'nhl_pregame_league_average_group_unique'
            );
            $table->index(
                ['model_run_id', 'game_date', 'source_cutoff_at', 'entity_type'],
                'ix_nhl_pregame_league_average_scope'
            );
        });

        foreach ([
            'nhl_team_game_pregame_context_metrics' => 'nhl_team_pregame_metric_average_fk',
            'nhl_player_game_pregame_context_metrics' => 'nhl_player_pregame_metric_average_fk',
        ] as $tableName => $constraintName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('league_average_id')->nullable();
            });

            if (DB::getDriverName() === 'pgsql') {
                // Existing rows are null; enforce new links without scanning historical metrics.
                DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$constraintName} FOREIGN KEY (league_average_id) REFERENCES nhl_pregame_context_league_averages(id) ON DELETE SET NULL NOT VALID");
            } else {
                Schema::table($tableName, function (Blueprint $table) use ($constraintName): void {
                    $table->foreign('league_average_id', $constraintName)
                        ->references('id')->on('nhl_pregame_context_league_averages')->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('nhl_player_game_pregame_context_metrics', function (Blueprint $table): void {
            $table->dropForeign('nhl_player_pregame_metric_average_fk');
            $table->dropColumn('league_average_id');
        });
        Schema::table('nhl_team_game_pregame_context_metrics', function (Blueprint $table): void {
            $table->dropForeign('nhl_team_pregame_metric_average_fk');
            $table->dropColumn('league_average_id');
        });
        Schema::dropIfExists('nhl_pregame_context_league_averages');
        Schema::dropIfExists('nhl_pregame_context_run_days');
    }

};
