<?php

declare(strict_types=1);

use App\Models\NhlGameImportRun;
use Illuminate\Support\Facades\DB;

it('empties NHL import progress and game import runs', function (): void {
    DB::table('nhl_games')->insert([
        'nhl_game_id' => 2025020001,
        'season_id' => '20252026',
        'game_type' => 2,
        'game_date' => '2025-10-07',
        'game_dow' => 'Tue',
        'game_month' => 'Oct',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $run = NhlGameImportRun::query()->create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_DATE,
        'status' => NhlGameImportRun::STATUS_COMPLETED,
        'start_date' => '2025-10-07',
        'end_date' => '2025-10-07',
        'date_count' => 1,
        'queued_jobs' => 1,
    ]);

    DB::table('nhl_import_progress')->insert([
        'run_id' => $run->id,
        'season_id' => '20252026',
        'game_date' => '2025-10-07',
        'game_id' => '2025020001',
        'game_type' => 2,
        'import_type' => 'pbp',
        'status' => 'completed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('nhl:imports:empty-progress', ['--force' => true])
        ->expectsOutput('Removed NHL import progress and game import runs.')
        ->assertExitCode(0);

    expect(DB::table('nhl_import_progress')->count())->toBe(0)
        ->and(DB::table('nhl_game_import_runs')->count())->toBe(0)
        ->and(DB::table('nhl_games')->count())->toBe(1);
});
