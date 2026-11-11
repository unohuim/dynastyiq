<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'ix_event_unit_shifts_unit_shift' => ['event_unit_shifts', 'unit_shift_id'],
        'ix_nhl_faceoff_facts_next_pbp' => ['nhl_faceoff_facts', 'next_play_by_play_id'],
        'ix_nhl_pbp_mismatches_pbp' => ['nhl_pbp_source_mismatches', 'play_by_play_id'],
        'ix_nhl_sat_model_scores_pbp' => ['nhl_shot_attempt_model_scores', 'play_by_play_id'],
        'ix_nhl_sat_predictions_pbp' => ['nhl_shot_attempt_predictions', 'play_by_play_id'],
        'ix_nhl_saf_previous_pbp' => ['nhl_shot_attempts_facts', 'previous_play_by_play_id'],
        'ix_nhl_saf_rush_origin_pbp' => ['nhl_shot_attempts_facts', 'rush_sequence_origin_play_by_play_id'],
    ];

    /** Index referencing columns so cleanup does not rescan whole child tables per deleted parent. */
    public function up(): void
    {
        foreach (self::INDEXES as $name => [$table, $column]) {
            if (DB::getDriverName() === 'pgsql') {
                $existing = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]);
                if ($existing !== null && ! filter_var($existing->indisvalid, FILTER_VALIDATE_BOOL)) {
                    // Replace an interrupted concurrent build instead of accepting an unusable index.
                    DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
                }
                DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$table} ({$column})");
            } elseif (! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($column, $name));
            }
        }
    }

    /** Remove only the dedicated indexes, preserving source facts and all constraints. */
    public function down(): void
    {
        foreach (array_reverse(self::INDEXES) as $name => [$table]) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            } elseif (Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
            }
        }
    }
};
