<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE nhl_import_progress DROP CONSTRAINT IF EXISTS nhl_import_progress_import_type_check');
        DB::statement(
            "ALTER TABLE nhl_import_progress ADD CONSTRAINT nhl_import_progress_import_type_check CHECK (
                import_type IN (
                    'pbp',
                    'summary',
                    'shifts',
                    'boxscore',
                    'validate-summary',
                    'shift-units',
                    'connect-events',
                    'sum-game-units'
                )
            )"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE nhl_import_progress DROP CONSTRAINT IF EXISTS nhl_import_progress_import_type_check');
        DB::statement(
            "ALTER TABLE nhl_import_progress ADD CONSTRAINT nhl_import_progress_import_type_check CHECK (
                import_type IN (
                    'pbp',
                    'summary',
                    'shifts',
                    'boxscore',
                    'shift-units',
                    'connect-events',
                    'sum-game-units'
                )
            )"
        );
    }
};
