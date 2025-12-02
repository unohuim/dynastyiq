<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\NhlImportProgressRepo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;


class NhlImportProgressRepoTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_stale_running_to_error_updates_old_rows(): void
    {
        $repo = app(NhlImportProgressRepo::class);

        DB::table('nhl_import_progress')->insert([
            'season_id' => '20242025',
            'game_date' => now()->toDateString(),
            'game_id' => 1,
            'import_type' => 'pbp',
            'status' => 'running',
            'items_count' => 0,
            'last_error' => null,
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);

        $cutoff = Carbon::now()->subMinutes(15);

        $repo->markStaleRunningToError('pbp', $cutoff);

        $this->assertDatabaseHas('nhl_import_progress', [
            'game_id' => 1,
            'import_type' => 'pbp',
            'status' => 'error',
            'last_error' => 'stale',
        ]);
    }
}
