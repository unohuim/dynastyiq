<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE nhl_import_progress MODIFY import_type ENUM(
                'pbp',
                'summary',
                'shifts',
                'boxscore',
                'validate-summary',
                'shift-units',
                'connect-events',
                'sum-game-units'
            ) NOT NULL"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE nhl_import_progress MODIFY import_type ENUM(
                'pbp',
                'summary',
                'shifts',
                'boxscore',
                'shift-units',
                'connect-events',
                'sum-game-units'
            ) NOT NULL"
        );
    }
};
