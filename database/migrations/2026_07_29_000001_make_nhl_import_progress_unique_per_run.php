<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => $this->upPostgres(),
            'sqlite' => $this->upSqlite(),
            default => $this->upFallback(),
        };
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => $this->downPostgres(),
            'sqlite' => $this->downSqlite(),
            default => $this->downFallback(),
        };
    }

    private function upPostgres(): void
    {
        DB::statement(
            'ALTER TABLE nhl_import_progress '
            . 'DROP CONSTRAINT IF EXISTS nhl_import_progress_game_id_import_type_unique'
        );
        DB::statement('DROP INDEX IF EXISTS nhl_import_progress_game_id_import_type_unique');
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_nhl_import_progress_legacy_game_stage '
            . 'ON nhl_import_progress (game_id, import_type) '
            . 'WHERE run_id IS NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_nhl_import_progress_run_game_stage '
            . 'ON nhl_import_progress (run_id, game_id, import_type) '
            . 'WHERE run_id IS NOT NULL'
        );
    }

    private function downPostgres(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_nhl_import_progress_run_game_stage');
        DB::statement('DROP INDEX IF EXISTS uq_nhl_import_progress_legacy_game_stage');
        DB::statement(
            'ALTER TABLE nhl_import_progress '
            . 'ADD CONSTRAINT nhl_import_progress_game_id_import_type_unique UNIQUE (game_id, import_type)'
        );
    }

    private function upSqlite(): void
    {
        DB::statement('DROP INDEX IF EXISTS nhl_import_progress_game_id_import_type_unique');
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_nhl_import_progress_legacy_game_stage '
            . 'ON nhl_import_progress (game_id, import_type) '
            . 'WHERE run_id IS NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_nhl_import_progress_run_game_stage '
            . 'ON nhl_import_progress (run_id, game_id, import_type) '
            . 'WHERE run_id IS NOT NULL'
        );
    }

    private function downSqlite(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_nhl_import_progress_run_game_stage');
        DB::statement('DROP INDEX IF EXISTS uq_nhl_import_progress_legacy_game_stage');
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS nhl_import_progress_game_id_import_type_unique '
            . 'ON nhl_import_progress (game_id, import_type)'
        );
    }

    private function upFallback(): void
    {
        Schema::table('nhl_import_progress', function ($table): void {
            $table->dropUnique('nhl_import_progress_game_id_import_type_unique');
            $table->unique(['run_id', 'game_id', 'import_type'], 'uq_nhl_import_progress_run_game_stage');
        });
    }

    private function downFallback(): void
    {
        Schema::table('nhl_import_progress', function ($table): void {
            $table->dropUnique('uq_nhl_import_progress_run_game_stage');
            $table->unique(['game_id', 'import_type'], 'nhl_import_progress_game_id_import_type_unique');
        });
    }
};
