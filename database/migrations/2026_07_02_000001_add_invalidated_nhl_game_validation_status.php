<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE nhl_game_validations DROP CONSTRAINT IF EXISTS nhl_game_validations_status_check');
            DB::statement(
                "ALTER TABLE nhl_game_validations ADD CONSTRAINT nhl_game_validations_status_check CHECK (
                    status IN ('approved', 'failed', 'accepted_exception', 'incomplete', 'invalidated')
                )"
            );
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE nhl_game_validations MODIFY status ENUM(
                    'approved',
                    'failed',
                    'accepted_exception',
                    'incomplete',
                    'invalidated'
                ) NOT NULL"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE nhl_game_validations DROP CONSTRAINT IF EXISTS nhl_game_validations_status_check');
            DB::statement(
                "ALTER TABLE nhl_game_validations ADD CONSTRAINT nhl_game_validations_status_check CHECK (
                    status IN ('approved', 'failed', 'accepted_exception', 'incomplete')
                )"
            );
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE nhl_game_validations MODIFY status ENUM(
                    'approved',
                    'failed',
                    'accepted_exception',
                    'incomplete'
                ) NOT NULL"
            );
        }
    }
};
