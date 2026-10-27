<?php

declare(strict_types=1);

use App\Events\NhlGameImportStatusUpdated;
use App\Jobs\ImportPbpNhlJob;
use App\Jobs\ImportShiftsNhlJob;
use App\Jobs\MakeShiftUnitsNhlJob;
use App\Jobs\NhlDiscoverDayJob;
use App\Jobs\NhlDiscoveryJob;
use App\Jobs\SumNhlGameUnitsJob;
use App\Jobs\SummarizePbpNhlJob;
use App\Jobs\ValidateNhlGameSummaryJob;
use App\Jobs\VerifyHtmlPbpNhlJob;
use App\Models\NhlGameImportRun;
use App\Models\NhlGameValidation;
use App\Repositories\NhlImportProgressRepo;
use App\Services\BuildNhlShotAttemptFacts;
use App\Services\NhlDiscoverGames;
use App\Services\NhlGameSourcePreflight;
use App\Services\NhlImportOrchestrator;
use App\Services\NhlPbpEventNormalizer;
use App\Support\NhlImportStages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-29 12:00:00');
    Config::set('apiImportNhl.validation_troubleshooting_path', sys_get_temp_dir() . '/dynastyiq-import-pipeline-troubleshooting-tests');
    File::deleteDirectory((string) config('apiImportNhl.validation_troubleshooting_path'));
    $this->app->instance(NhlGameSourcePreflight::class, new class extends NhlGameSourcePreflight {
        public function check(int $gameId): array
        {
            return [
                'allowed' => true,
                'core_allowed' => true,
                'on_ice_allowed' => true,
                'statuses' => [],
                'message' => null,
                'core_message' => null,
                'on_ice_message' => null,
            ];
        }

        public function storedOrCheck(int $gameId): array
        {
            return $this->check($gameId);
        }
    });

    $this->insertProgress = function (
        int $gameId = 2026020001,
        string $type = NhlImportStages::PBP,
        string $status = 'scheduled',
        array $overrides = []
    ): void {
        DB::table('nhl_import_progress')->insert(array_merge([
            'season_id' => '20262027',
            'game_date' => '2026-10-01',
            'game_id' => (string) $gameId,
            'game_type' => 2,
            'import_type' => $type,
            'items_count' => 0,
            'status' => $status,
            'discovered_at' => now(),
            'last_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    };

    $this->insertPipeline = function (int $gameId = 2026020001, array $statuses = []): void {
        foreach (NhlImportStages::ordered() as $stage) {
            ($this->insertProgress)(
                $gameId,
                $stage,
                $statuses[$stage] ?? 'scheduled'
            );
        }
    };

    $this->insertGame = function (
        int $gameId = 2026020001,
        array $overrides = []
    ): void {
        DB::table('nhl_games')->insert(array_merge([
            'nhl_game_id' => $gameId,
            'season_id' => '20262027',
            'game_type' => 2,
            'game_date' => '2026-10-01',
            'game_dow' => 'Thu',
            'game_month' => 'Oct',
            'home_team_id' => 10,
            'home_team_abbrev' => 'TOR',
            'away_team_id' => 8,
            'away_team_abbrev' => 'MTL',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    };
    $this->overlapRun = static fn (array $attributes = []): NhlGameImportRun => NhlGameImportRun::create([
        'action' => 'discover', 'mode' => 'range', 'status' => 'running',
        'start_date' => '2026-10-01', 'end_date' => '2026-10-03', 'date_count' => 3,
        'payload' => ['processing_started_at' => '2026-06-29T12:00:00Z'], ...$attributes,
    ]);
    $this->overlapRows = static function (int $runId, array $gameIds): array {
        $rows = [];
        foreach ($gameIds as $gameId) {
            foreach (NhlImportStages::ordered() as $stage) {
                $rows[] = ['run_id' => $runId, 'season_id' => '20262027', 'game_date' => '2026-10-01',
                    'game_id' => (string) $gameId, 'game_type' => 2, 'import_type' => $stage,
                    'items_count' => 0, 'status' => 'scheduled', 'discovered_at' => now(), 'created_at' => now(), 'updated_at' => now()];
            }
        }

        return $rows;
    };
});

afterEach(function (): void {
    File::deleteDirectory((string) config('apiImportNhl.validation_troubleshooting_path'));
    Carbon::setTestNow();
});

it('defines the canonical NHL import stages in pipeline order', function (): void {
    expect(NhlImportStages::ordered())->toBe([
        'pbp',
        'summary',
        'boxscore',
        'shifts',
        'shift-units',
        'connect-events',
        'html-pbp-verify',
        'sum-game-units',
        'validate-summary',
    ]);
});

it('defines no dependencies for the first PBP stage', function (): void {
    expect(NhlImportStages::dependenciesFor(NhlImportStages::PBP))->toBe([]);
});

it('defines PBP as the summary dependency', function (): void {
    expect(NhlImportStages::dependenciesFor(NhlImportStages::SUMMARY))->toBe([
        NhlImportStages::PBP,
    ]);
});

it('defines all upstream dependencies for the terminal validation stage', function (): void {
    expect(NhlImportStages::dependenciesFor(NhlImportStages::VALIDATE_SUMMARY))->toBe([
        NhlImportStages::PBP,
        NhlImportStages::SUMMARY,
        NhlImportStages::BOXSCORE,
        NhlImportStages::SHIFTS,
        NhlImportStages::SHIFT_UNITS,
        NhlImportStages::CONNECT_EVENTS,
        NhlImportStages::HTML_PBP_VERIFY,
        NhlImportStages::SUM_GAME_UNITS,
    ]);
});

it('returns the next stage for a middle pipeline stage', function (): void {
    expect(NhlImportStages::nextAfter(NhlImportStages::BOXSCORE))->toBe(NhlImportStages::SHIFTS)
        ->and(NhlImportStages::nextAfter(NhlImportStages::SHIFTS))->toBe(NhlImportStages::SHIFT_UNITS)
        ->and(NhlImportStages::nextAfter(NhlImportStages::CONNECT_EVENTS))->toBe(NhlImportStages::HTML_PBP_VERIFY)
        ->and(NhlImportStages::nextAfter(NhlImportStages::HTML_PBP_VERIFY))->toBe(NhlImportStages::SUM_GAME_UNITS);
});

it('returns null for the next stage after the terminal stage', function (): void {
    expect(NhlImportStages::nextAfter(NhlImportStages::VALIDATE_SUMMARY))->toBeNull();
});

it('maps stage names to their queue job classes', function (): void {
    expect(NhlImportStages::jobClassFor(NhlImportStages::PBP))->toBe(ImportPbpNhlJob::class)
        ->and(NhlImportStages::jobClassFor(NhlImportStages::SHIFTS))->toBe(ImportShiftsNhlJob::class)
        ->and(NhlImportStages::jobClassFor(NhlImportStages::VALIDATE_SUMMARY))->toBe(ValidateNhlGameSummaryJob::class)
        ->and(NhlImportStages::jobClassFor(NhlImportStages::SHIFT_UNITS))->toBe(MakeShiftUnitsNhlJob::class)
        ->and(NhlImportStages::jobClassFor(NhlImportStages::HTML_PBP_VERIFY))->toBe(VerifyHtmlPbpNhlJob::class)
        ->and(NhlImportStages::jobClassFor(NhlImportStages::SUM_GAME_UNITS))->toBe(SumNhlGameUnitsJob::class);
});

it('maps stage names to the actual NHL import timeout config keys', function (): void {
    expect(NhlImportStages::timeoutConfigKeyFor(NhlImportStages::SUMMARY))
        ->toBe('apiImportNhl.max_game_summaries_seconds')
        ->and(NhlImportStages::timeoutConfigKeyFor(NhlImportStages::VALIDATE_SUMMARY))
        ->toBe('apiImportNhl.max_validate_summary_seconds')
        ->and(NhlImportStages::timeoutConfigKeyFor(NhlImportStages::CONNECT_EVENTS))
        ->toBe('apiImportNhl.max_connect_events_seconds')
        ->and(NhlImportStages::timeoutConfigKeyFor(NhlImportStages::HTML_PBP_VERIFY))
        ->toBe('apiImportNhl.max_html_pbp_verify_seconds');
});

it('atomically claims a scheduled progress row by marking it running', function (): void {
    ($this->insertProgress)();

    $claimed = app(NhlImportProgressRepo::class)->claim(2026020001, NhlImportStages::PBP);

    expect($claimed)->toBeTrue();
    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026020001',
        'import_type' => NhlImportStages::PBP,
        'status' => 'running',
    ]);
});

it('does not claim a completed progress row', function (): void {
    ($this->insertProgress)(2026020001, NhlImportStages::PBP, 'completed');

    $claimed = app(NhlImportProgressRepo::class)->claim(2026020001, NhlImportStages::PBP);

    expect($claimed)->toBeFalse();
    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026020001',
        'import_type' => NhlImportStages::PBP,
        'status' => 'completed',
    ]);
});

it('does not claim a missing progress row', function (): void {
    expect(app(NhlImportProgressRepo::class)->claim(2026020001, NhlImportStages::PBP))->toBeFalse();
});

it('reports a row as running after it has been claimed', function (): void {
    ($this->insertProgress)();

    app(NhlImportProgressRepo::class)->claim(2026020001, NhlImportStages::PBP);

    expect(app(NhlImportProgressRepo::class)->isRunning(2026020001, NhlImportStages::PBP))->toBeTrue();
});

it('counts only completed dependency rows', function (): void {
    ($this->insertProgress)(2026020001, NhlImportStages::PBP, 'completed');
    ($this->insertProgress)(2026020001, NhlImportStages::SUMMARY, 'running');

    $count = app(NhlImportProgressRepo::class)->completedDepsCount(2026020001, [
        NhlImportStages::PBP,
        NhlImportStages::SUMMARY,
    ]);

    expect($count)->toBe(1);
});

it('does not mark a dependent stage ready when its dependency is incomplete', function (): void {
    ($this->insertPipeline)(2026020001);

    expect(app(NhlImportOrchestrator::class)->readyFor(2026020001, NhlImportStages::SUMMARY))->toBeFalse();
});

it('marks a dependent stage ready when its dependencies are completed and target is scheduled', function (): void {
    ($this->insertPipeline)(2026020001, [
        NhlImportStages::PBP => 'completed',
    ]);

    expect(app(NhlImportOrchestrator::class)->readyFor(2026020001, NhlImportStages::SUMMARY))->toBeTrue();
});

it('does not mark a stage ready when the target row is not scheduled', function (): void {
    ($this->insertPipeline)(2026020001, [
        NhlImportStages::PBP => 'completed',
        NhlImportStages::SUMMARY => 'running',
    ]);

    expect(app(NhlImportOrchestrator::class)->readyFor(2026020001, NhlImportStages::SUMMARY))->toBeFalse();
});

it('dispatches a claimed PBP job and leaves the row running', function (): void {
    Bus::fake();
    ($this->insertProgress)();

    app(NhlImportOrchestrator::class)->dispatchJob(2026020001, NhlImportStages::PBP);

    Bus::assertDispatched(ImportPbpNhlJob::class);
    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026020001',
        'import_type' => NhlImportStages::PBP,
        'status' => 'running',
    ]);
});

it('does not dispatch a duplicate job after the row has already been claimed', function (): void {
    Bus::fake();
    ($this->insertProgress)();

    $orchestrator = app(NhlImportOrchestrator::class);
    $orchestrator->dispatchJob(2026020001, NhlImportStages::PBP);
    $orchestrator->dispatchJob(2026020001, NhlImportStages::PBP);

    Bus::assertDispatchedTimes(ImportPbpNhlJob::class, 1);
});

it('marks NHL import progress failed when the queue records a terminal job failure', function (): void {
    Bus::fake();
    Event::fake([NhlGameImportStatusUpdated::class]);
    ($this->insertProgress)(2026020001, NhlImportStages::PBP, 'running');

    (new ImportPbpNhlJob(2026020001))->failed(new RuntimeException('PBP exhausted attempts'));

    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026020001',
        'import_type' => NhlImportStages::PBP,
        'status' => 'error',
        'last_error' => 'PBP exhausted attempts',
    ]);
});

it('sweeps stale running rows before counting active run slots', function (): void {
    Bus::fake();
    config([
        'apiImportNhl.active_game_import_slots' => 1,
        'apiImportNhl.max_pbp_seconds' => 120,
    ]);
    $run = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_SEASON,
        'status' => NhlGameImportRun::STATUS_RUNNING,
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-02',
        'date_count' => 2,
        'queued_jobs' => 2,
        'payload' => [],
    ]);
    ($this->insertProgress)(2026020001, NhlImportStages::PBP, 'running', [
        'run_id' => $run->id,
        'updated_at' => now()->subSeconds(180),
    ]);
    ($this->insertProgress)(2026020002, NhlImportStages::PBP, 'scheduled', [
        'run_id' => $run->id,
        'game_date' => '2026-10-02',
    ]);

    $dispatched = app(NhlImportOrchestrator::class)->fillActiveGameSlotsForRun($run->id);

    expect($dispatched)->toBe(1);
    Bus::assertDispatched(ImportPbpNhlJob::class);
    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026020001',
        'import_type' => NhlImportStages::PBP,
        'status' => 'error',
        'last_error' => 'stale',
    ]);
    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026020002',
        'import_type' => NhlImportStages::PBP,
        'status' => 'running',
    ]);
});

it('deletes stale game troubleshooting directories when a processing stage succeeds', function (): void {
    Bus::fake();
    ($this->insertPipeline)(2026020001, [
        NhlImportStages::PBP => 'running',
    ]);
    $directory = (string) config('apiImportNhl.validation_troubleshooting_path') . '/2026020001';
    File::ensureDirectoryExists($directory);

    app(NhlImportOrchestrator::class)->onSuccess(2026020001, NhlImportStages::PBP, ['items_count' => 1]);

    expect(File::isDirectory($directory))->toBeFalse();
});

it('deletes game troubleshooting directories when processing approves summary validation', function (): void {
    Bus::fake();
    ($this->insertGame)(2026020001);
    ($this->insertProgress)(2026020001, NhlImportStages::VALIDATE_SUMMARY, 'running');
    NhlGameValidation::create([
        'nhl_game_id' => 2026020001,
        'validation_type' => NhlGameValidation::TYPE_SUMMARY_BOXSCORE,
        'status' => NhlGameValidation::STATUS_APPROVED,
        'mismatch_count' => 0,
        'checked_at' => now(),
    ]);
    $directory = (string) config('apiImportNhl.validation_troubleshooting_path') . '/2026020001';
    File::ensureDirectoryExists($directory);

    app(NhlImportOrchestrator::class)->onSuccess(2026020001, NhlImportStages::VALIDATE_SUMMARY, ['items_count' => 0]);

    expect(File::isDirectory($directory))->toBeFalse();
});

it('retains game troubleshooting directories when processing keeps invalidated summary validation', function (): void {
    Bus::fake();
    ($this->insertGame)(2026020001);
    ($this->insertProgress)(2026020001, NhlImportStages::VALIDATE_SUMMARY, 'running');
    NhlGameValidation::create([
        'nhl_game_id' => 2026020001,
        'validation_type' => NhlGameValidation::TYPE_SUMMARY_BOXSCORE,
        'status' => NhlGameValidation::STATUS_INVALIDATED,
        'mismatch_count' => 1,
        'checked_at' => now(),
    ]);
    $directory = (string) config('apiImportNhl.validation_troubleshooting_path') . '/2026020001';
    File::ensureDirectoryExists($directory);

    app(NhlImportOrchestrator::class)->onSuccess(2026020001, NhlImportStages::VALIDATE_SUMMARY, ['items_count' => 1]);

    expect(File::isDirectory($directory))->toBeTrue();
});

it('skips on-ice stages and still dispatches validation when only the shifts source is missing', function (): void {
    Bus::fake();
    ($this->insertPipeline)(2026020001, [
        NhlImportStages::PBP => 'completed',
        NhlImportStages::SUMMARY => 'completed',
        NhlImportStages::BOXSCORE => 'completed',
    ]);
    $this->app->instance(NhlGameSourcePreflight::class, new class extends NhlGameSourcePreflight {
        public function storedOrCheck(int $gameId): array
        {
            return [
                'allowed' => true,
                'core_allowed' => true,
                'on_ice_allowed' => false,
                'statuses' => [],
                'message' => 'NHL source preflight blocked import: shifts:empty_shiftcharts',
                'core_message' => null,
                'on_ice_message' => 'NHL source preflight blocked import: shifts:empty_shiftcharts',
            ];
        }
    });

    app(NhlImportOrchestrator::class)->dispatchJob(2026020001, NhlImportStages::SHIFTS);

    Bus::assertDispatched(ValidateNhlGameSummaryJob::class);
    foreach ([NhlImportStages::SHIFTS, NhlImportStages::SHIFT_UNITS, NhlImportStages::CONNECT_EVENTS, NhlImportStages::HTML_PBP_VERIFY, NhlImportStages::SUM_GAME_UNITS] as $stage) {
        $this->assertDatabaseHas('nhl_import_progress', [
            'game_id' => '2026020001',
            'import_type' => $stage,
            'status' => 'skipped',
            'last_error' => 'NHL source preflight blocked import: shifts:empty_shiftcharts',
        ]);
    }
    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026020001',
        'import_type' => NhlImportStages::VALIDATE_SUMMARY,
        'status' => 'running',
    ]);
});

it('records source preflight statuses with exact provider URLs', function (): void {
    Http::fake([
        'https://api-web.nhle.com/v1/gamecenter/2026020001/play-by-play' => Http::response([
            'gameType' => 2,
            'plays' => [
                ['eventId' => 7],
            ],
        ]),
        'https://api-web.nhle.com/v1/gamecenter/2026020001/boxscore' => Http::response([
            'playerByGameStats' => [
                'awayTeam' => ['forwards' => []],
                'homeTeam' => ['forwards' => []],
            ],
        ]),
        'https://api.nhle.com/stats/rest/en/shiftcharts?cayenneExp=gameId=2026020001' => Http::response([
            'data' => [],
            'total' => 0,
        ]),
    ]);

    $result = (new NhlGameSourcePreflight())->check(2026020001);

    expect($result['allowed'])->toBeTrue()
        ->and($result['core_allowed'])->toBeTrue()
        ->and($result['on_ice_allowed'])->toBeTrue()
        ->and($result['message'])->toBeNull()
        ->and($result['on_ice_message'])->toBe('NHL source preflight blocked import: shifts:empty_shiftcharts');
    $this->assertDatabaseHas('nhl_game_source_statuses', [
        'nhl_game_id' => 2026020001,
        'source' => 'pbp',
        'status' => 'available',
        'url' => 'https://api-web.nhle.com/v1/gamecenter/2026020001/play-by-play',
    ]);
    $this->assertDatabaseHas('nhl_game_source_statuses', [
        'nhl_game_id' => 2026020001,
        'source' => 'shifts',
        'status' => 'empty',
        'reason' => 'empty_shiftcharts',
        'url' => 'https://api.nhle.com/stats/rest/en/shiftcharts?cayenneExp=gameId=2026020001',
    ]);
});

it('builds deterministic NHL shot attempt facts from imported play by play', function (): void {
    DB::table('nhl_games')->insert([
        'nhl_game_id' => 2025020001,
        'season_id' => '20252026',
        'game_type' => 2,
        'game_date' => '2026-01-15',
        'game_dow' => 'Thu',
        'game_month' => 'Jan',
        'home_team_id' => 10,
        'home_team_abbrev' => 'TOR',
        'away_team_id' => 8,
        'away_team_abbrev' => 'MTL',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('players')->insert([
        [
            'nhl_id' => 8470001,
            'first_name' => 'Left',
            'last_name' => 'Shooter',
            'full_name' => 'Left Shooter',
            'shoots' => 'L',
            'position' => 'LW',
            'is_goalie' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'nhl_id' => 8470002,
            'first_name' => 'Right',
            'last_name' => 'Shooter',
            'full_name' => 'Right Shooter',
            'shoots' => 'R',
            'position' => 'RW',
            'is_goalie' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'nhl_id' => 8471001,
            'first_name' => 'Left',
            'last_name' => 'Goalie',
            'full_name' => 'Left Goalie',
            'shoots' => 'L',
            'position' => 'G',
            'is_goalie' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    DB::table('play_by_plays')->insert([
        [
            'id' => 9000,
            'nhl_game_id' => 2025020001,
            'event_owner_team_id' => 10,
            'period' => 1,
            'period_type' => 'REG',
            'seconds_in_period' => 295,
            'seconds_in_game' => 295,
            'seconds_remaining' => 3305,
            'seconds_since_last_event' => 9,
            'type_desc_key' => 'takeaway',
            'nhl_event_id' => '100',
            'situation_code' => '1551',
            'strength' => 'EV',
            'sort_order' => 0,
            'x_coord' => null,
            'y_coord' => null,
            'shot_distance' => null,
            'shot_angle' => null,
            'zone_code' => 'N',
            'shot_type' => null,
            'shooting_player_id' => null,
            'scoring_player_id' => null,
            'goalie_in_net_player_id' => null,
            'home_score' => 0,
            'away_score' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 9001,
            'nhl_game_id' => 2025020001,
            'event_owner_team_id' => 10,
            'period' => 1,
            'period_type' => 'REG',
            'seconds_in_period' => 300,
            'seconds_in_game' => 300,
            'seconds_remaining' => 3300,
            'seconds_since_last_event' => 12,
            'type_desc_key' => 'shot-on-goal',
            'nhl_event_id' => '101',
            'situation_code' => '1551',
            'strength' => 'EV',
            'sort_order' => 1,
            'x_coord' => 70,
            'y_coord' => 5,
            'shot_distance' => 19.65,
            'shot_angle' => -14.744,
            'zone_code' => 'O',
            'shot_type' => 'wrist',
            'shooting_player_id' => 8470001,
            'scoring_player_id' => null,
            'goalie_in_net_player_id' => 8471001,
            'home_score' => 0,
            'away_score' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 9002,
            'nhl_game_id' => 2025020001,
            'event_owner_team_id' => 10,
            'period' => 1,
            'period_type' => 'REG',
            'seconds_in_period' => 302,
            'seconds_in_game' => 302,
            'seconds_remaining' => 3298,
            'seconds_since_last_event' => 2,
            'type_desc_key' => 'goal',
            'nhl_event_id' => '102',
            'situation_code' => '1551',
            'strength' => 'EV',
            'sort_order' => 2,
            'x_coord' => 76,
            'y_coord' => -3,
            'shot_distance' => 13.34,
            'shot_angle' => 12.995,
            'zone_code' => 'O',
            'shot_type' => 'snap',
            'shooting_player_id' => null,
            'scoring_player_id' => 8470002,
            'goalie_in_net_player_id' => 8471001,
            'home_score' => 1,
            'away_score' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 9003,
            'nhl_game_id' => 2025020001,
            'event_owner_team_id' => 10,
            'period' => 1,
            'period_type' => 'REG',
            'seconds_in_period' => 340,
            'seconds_in_game' => 340,
            'seconds_remaining' => 3260,
            'seconds_since_last_event' => 38,
            'type_desc_key' => 'missed-shot',
            'nhl_event_id' => '103',
            'situation_code' => '1551',
            'strength' => 'EV',
            'sort_order' => 3,
            'x_coord' => 68,
            'y_coord' => 8,
            'shot_distance' => 22.47,
            'shot_angle' => 20.854,
            'zone_code' => 'O',
            'shot_type' => 'wrist',
            'shooting_player_id' => 8470001,
            'scoring_player_id' => null,
            'goalie_in_net_player_id' => 8471001,
            'home_score' => 0,
            'away_score' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 9004,
            'nhl_game_id' => 2025020001,
            'event_owner_team_id' => 10,
            'period' => 1,
            'period_type' => 'REG',
            'seconds_in_period' => 500,
            'seconds_in_game' => 500,
            'seconds_remaining' => 3100,
            'seconds_since_last_event' => 160,
            'type_desc_key' => 'goal',
            'nhl_event_id' => '104',
            'situation_code' => '1551',
            'strength' => 'EV',
            'sort_order' => 4,
            'x_coord' => 74,
            'y_coord' => 0,
            'shot_distance' => 15.00,
            'shot_angle' => 0.000,
            'zone_code' => 'O',
            'shot_type' => null,
            'shooting_player_id' => null,
            'scoring_player_id' => 8470002,
            'goalie_in_net_player_id' => 8471001,
            'home_score' => 2,
            'away_score' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $builder = app(BuildNhlShotAttemptFacts::class);

    expect($builder->buildForGame(2025020001))->toBe(4)
        ->and($builder->buildForGame(2025020001))->toBe(4)
        ->and(DB::table('nhl_shot_attempts_facts')->count())->toBe(4);

    $goalFact = DB::table('nhl_shot_attempts_facts')
        ->where('play_by_play_id', 9002)
        ->first();
    $rushFact = DB::table('nhl_shot_attempts_facts')
        ->where('play_by_play_id', 9001)
        ->first();
    $postGoalFact = DB::table('nhl_shot_attempts_facts')
        ->where('play_by_play_id', 9003)
        ->first();
    $unknownShotTypeGoalFact = DB::table('nhl_shot_attempts_facts')
        ->where('play_by_play_id', 9004)
        ->first();
    $unknownShotTypeGoalPlay = \App\Models\PlayByPlay::query()->find(9004);

    expect($rushFact->rush_bucket)->toBe('rush_attempt')
        ->and((bool) $rushFact->is_rush_attempt)->toBeTrue()
        ->and((bool) $rushFact->is_rush_sequence)->toBeTrue()
        ->and($rushFact->rush_sequence_origin_play_by_play_id)->toBe(9001)
        ->and($rushFact->shooter_shoots)->toBe('L')
        ->and($rushFact->goalie_catches)->toBe('L')
        ->and($rushFact->shot_side)->toBe('center')
        ->and($rushFact->is_off_wing_attempt)->toBeNull()
        ->and($rushFact->goalie_hand_matchup_bucket)->toBe('shooter_l_vs_goalie_l')
        ->and($goalFact->attempt_result)->toBe('goal')
        ->and((bool) $goalFact->is_goal)->toBeTrue()
        ->and($goalFact->shooter_shoots)->toBe('R')
        ->and($goalFact->shot_side)->toBe('center')
        ->and($goalFact->goalie_hand_matchup_bucket)->toBe('shooter_r_vs_goalie_l')
        ->and((bool) $goalFact->is_rebound)->toBeTrue()
        ->and((bool) $goalFact->is_rush_attempt)->toBeFalse()
        ->and((bool) $goalFact->is_rush_sequence)->toBeTrue()
        ->and($goalFact->rush_bucket)->toBe('rush_rebound')
        ->and($goalFact->rush_sequence_origin_play_by_play_id)->toBe(9001)
        ->and($goalFact->rebound_bucket)->toBe('rebound')
        ->and($goalFact->distance_bucket)->toBe('d_010_015')
        ->and($goalFact->angle_bucket)->toBe('a_010_020')
        ->and($goalFact->strength_bucket)->toBe('ev')
        ->and($goalFact->home_score)->toBe(0)
        ->and($goalFact->away_score)->toBe(0)
        ->and($goalFact->score_state_bucket)->toBe('tied')
        ->and($goalFact->shot_type_bucket)->toBe('snap')
        ->and($postGoalFact->home_score)->toBe(1)
        ->and($postGoalFact->away_score)->toBe(0)
        ->and($postGoalFact->score_state_bucket)->toBe('leading_by_1')
        ->and($unknownShotTypeGoalFact->attempt_result)->toBe('goal')
        ->and((bool) $unknownShotTypeGoalFact->is_shot_on_goal)->toBeTrue()
        ->and((bool) $unknownShotTypeGoalFact->is_unblocked_attempt)->toBeTrue()
        ->and($unknownShotTypeGoalFact->shot_type_bucket)->toBe('unknown')
        ->and(app(NhlPbpEventNormalizer::class)->isShotOnGoal($unknownShotTypeGoalPlay))->toBeFalse();
});

it('processes only ready scheduled stages for a date', function (): void {
    Bus::fake();
    ($this->insertPipeline)(2026020001);

    app(NhlImportOrchestrator::class)->processScheduled('2026-10-01');

    Bus::assertDispatched(ImportPbpNhlJob::class);
    Bus::assertNotDispatched(SummarizePbpNhlJob::class);
    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026020001',
        'import_type' => NhlImportStages::PBP,
        'status' => 'running',
    ]);
});

it('advances to the next ready stage after a stage completes', function (): void {
    Bus::fake();
    ($this->insertPipeline)(2026020001, [
        NhlImportStages::PBP => 'completed',
    ]);

    app(NhlImportOrchestrator::class)->advance(2026020001, NhlImportStages::PBP);

    Bus::assertDispatched(SummarizePbpNhlJob::class);
    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026020001',
        'import_type' => NhlImportStages::SUMMARY,
        'status' => 'running',
    ]);
});

it('does not advance from an unknown completed stage', function (): void {
    Bus::fake();
    ($this->insertPipeline)(2026020001);

    app(NhlImportOrchestrator::class)->advance(2026020001, 'unknown-stage');

    Bus::assertNothingDispatched();
});

it('marks stale running rows using the canonical timeout config keys', function (): void {
    config(['apiImportNhl.max_pbp_seconds' => 120]);
    ($this->insertProgress)(2026020001, NhlImportStages::PBP, 'running', [
        'updated_at' => now()->subSeconds(180),
    ]);

    app(NhlImportOrchestrator::class)->sweepStale();

    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026020001',
        'import_type' => NhlImportStages::PBP,
        'status' => 'error',
        'last_error' => 'stale',
    ]);
});

it('does not mark fresh running rows as stale', function (): void {
    config(['apiImportNhl.max_pbp_seconds' => 300]);
    ($this->insertProgress)(2026020001, NhlImportStages::PBP, 'running', [
        'updated_at' => now()->subSeconds(120),
    ]);

    app(NhlImportOrchestrator::class)->sweepStale();

    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026020001',
        'import_type' => NhlImportStages::PBP,
        'status' => 'running',
    ]);
});

it('records command-line single-date discovery as an admin-visible game import run', function (): void {
    Bus::fake();
    Event::fake([NhlGameImportStatusUpdated::class]);

    $this->artisan('nhl:discover', [
        '--date' => '2026-01-15',
    ])->assertSuccessful();

    $run = NhlGameImportRun::query()->firstOrFail();

    expect($run->action)->toBe(NhlGameImportRun::ACTION_DISCOVER)
        ->and($run->mode)->toBe(NhlGameImportRun::MODE_DATE)
        ->and($run->status)->toBe(NhlGameImportRun::STATUS_QUEUED)
        ->and($run->start_date->toDateString())->toBe('2026-01-15')
        ->and($run->end_date->toDateString())->toBe('2026-01-15')
        ->and($run->date_count)->toBe(1)
        ->and($run->queued_jobs)->toBe(1)
        ->and($run->created_by)->toBeNull();

    Bus::assertDispatched(NhlDiscoveryJob::class, function (NhlDiscoveryJob $job): bool {
        return $job->start->toDateString() === '2026-01-15'
            && $job->end->toDateString() === '2026-01-15';
    });
    Event::assertDispatched(NhlGameImportStatusUpdated::class, function (NhlGameImportStatusUpdated $event): bool {
        return $event->reason === 'discovery-queued';
    });
});

it('records command-line range discovery as an admin-visible game import run', function (): void {
    Bus::fake();
    Event::fake([NhlGameImportStatusUpdated::class]);

    $this->artisan('nhl:discover', [
        '--start' => '2026-01-17',
        '--end' => '2026-01-15',
    ])->assertSuccessful();

    $run = NhlGameImportRun::query()->firstOrFail();

    expect($run->action)->toBe(NhlGameImportRun::ACTION_DISCOVER)
        ->and($run->mode)->toBe(NhlGameImportRun::MODE_RANGE)
        ->and($run->status)->toBe(NhlGameImportRun::STATUS_QUEUED)
        ->and($run->start_date->toDateString())->toBe('2026-01-17')
        ->and($run->end_date->toDateString())->toBe('2026-01-15')
        ->and($run->date_count)->toBe(3)
        ->and($run->queued_jobs)->toBe(1)
        ->and($run->created_by)->toBeNull();

    Bus::assertDispatched(NhlDiscoveryJob::class, function (NhlDiscoveryJob $job): bool {
        return $job->start->toDateString() === '2026-01-17'
            && $job->end->toDateString() === '2026-01-15';
    });
    Event::assertDispatched(NhlGameImportStatusUpdated::class, function (NhlGameImportStatusUpdated $event): bool {
        return $event->reason === 'discovery-queued';
    });
});

it('seeds discovered completed games with every canonical import stage', function (): void {
    Http::fake([
        'https://api-web.nhle.com/v1/score/2026-10-01' => Http::response([
            'games' => [
                [
                    'id' => 2026020001,
                    'season' => 20262027,
                    'gameDate' => '2026-10-01',
                    'gameType' => 2,
                    'gameState' => 'OFF',
                ],
            ],
        ]),
    ]);

    app(NhlDiscoverGames::class)->discoverDay('2026-10-01');

    expect(DB::table('nhl_import_progress')->where('game_id', '2026020001')->count())
        ->toBe(count(NhlImportStages::ordered()));

    foreach (NhlImportStages::ordered() as $stage) {
        $this->assertDatabaseHas('nhl_import_progress', [
            'game_id' => '2026020001',
            'import_type' => $stage,
            'status' => 'scheduled',
        ]);
    }
});

it('skips stale discovery day jobs whose parent run was removed', function (): void {
    $service = new class(app(NhlImportProgressRepo::class)) extends NhlDiscoverGames {
        public bool $called = false;

        public function discoverDay(string $yyyy_mm_dd, ?int $runId = null): void
        {
            $this->called = true;

            throw new RuntimeException('Stale discovery job should not discover games.');
        }
    };

    (new NhlDiscoverDayJob('2026-10-01', 36))->handle($service);

    expect($service->called)->toBeFalse()
        ->and(DB::table('nhl_import_progress')->count())->toBe(0);
});

it('filters deleted run ids before inserting scheduled progress rows', function (): void {
    Event::fake([NhlGameImportStatusUpdated::class]);
    $run = NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_DISCOVER,
        'mode' => NhlGameImportRun::MODE_SEASON,
        'status' => NhlGameImportRun::STATUS_RUNNING,
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-01',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => [],
    ]);
    $now = now();

    app(NhlImportProgressRepo::class)->insertScheduledRows([
        [
            'run_id' => 36,
            'season_id' => '20262027',
            'game_date' => '2026-10-01',
            'game_id' => '2026020001',
            'game_type' => 2,
            'import_type' => NhlImportStages::PBP,
            'items_count' => 0,
            'status' => 'scheduled',
            'discovered_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'run_id' => $run->id,
            'season_id' => '20262027',
            'game_date' => '2026-10-01',
            'game_id' => '2026020002',
            'game_type' => 2,
            'import_type' => NhlImportStages::PBP,
            'items_count' => 0,
            'status' => 'scheduled',
            'discovered_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    expect(DB::table('nhl_import_progress')->count())->toBe(1);
    $this->assertDatabaseHas('nhl_import_progress', [
        'run_id' => $run->id,
        'game_id' => '2026020002',
        'import_type' => NhlImportStages::PBP,
        'status' => 'scheduled',
    ]);
    $this->assertDatabaseMissing('nhl_import_progress', [
        'run_id' => 36,
        'game_id' => '2026020001',
    ]);
});

it('keeps overlapping games with their existing full owner and admits unrelated games', function (bool $automaticFirst): void {
    Event::fake();
    $first = ($this->overlapRun)(['payload' => $automaticFirst ? ['scheduled_process' => 'nhl-game-discovery'] : []]);
    $second = ($this->overlapRun)(['payload' => $automaticFirst ? [] : ['scheduled_process' => 'nhl-game-discovery']]);
    $repo = app(NhlImportProgressRepo::class);
    $repo->insertScheduledRows(($this->overlapRows)($first->id, [2026020001]));
    $repo->insertScheduledRows(($this->overlapRows)($second->id, [2026020001, 2026020002]));
    expect(DB::table('nhl_import_progress')->where('run_id', $first->id)->count())->toBe(9)
        ->and(DB::table('nhl_import_progress')->where('run_id', $second->id)->count())->toBe(9)
        ->and($second->fresh()->payload['overlap_excluded_games']['2026020001'])->toBe($first->id);
    expect($repo->claim(2026020001, 'pbp', $first->id))->toBeTrue()
        ->and($repo->claim(2026020001, 'pbp', $second->id))->toBeFalse()
        ->and($repo->claim(2026020002, 'pbp', $second->id))->toBeTrue();
})->with([false, true]);

it('reserves unfinished full games during Pause and cancellation cleanup', function (string $status): void {
    Event::fake();
    $owner = ($this->overlapRun)(['status' => $status]);
    $contender = ($this->overlapRun)();
    $repo = app(NhlImportProgressRepo::class);
    $repo->insertScheduledRows(($this->overlapRows)($owner->id, [2026020001]));
    $repo->insertScheduledRows(($this->overlapRows)($contender->id, [2026020001]));
    expect(DB::table('nhl_import_progress')->where('run_id', $contender->id)->count())->toBe(0)
        ->and($contender->fresh()->payload['overlap_excluded_games']['2026020001'])->toBe($owner->id);
})->with(['queued', 'running', 'pausing', 'paused', 'cancelling']);

it('accepts only one full owner when competing runs arrive in the same insertion batch', function (): void {
    Event::fake();
    $first = ($this->overlapRun)();
    $second = ($this->overlapRun)();
    app(NhlImportProgressRepo::class)->insertScheduledRows([
        ...($this->overlapRows)($second->id, [2026020001]),
        ...($this->overlapRows)($first->id, [2026020001]),
    ]);
    expect(DB::table('nhl_import_progress')->where('run_id', $first->id)->count())->toBe(9)
        ->and(DB::table('nhl_import_progress')->where('run_id', $second->id)->count())->toBe(0)
        ->and($second->fresh()->payload['overlap_excluded_games']['2026020001'])->toBe($first->id);
});

it('does not reserve games for completed failed cancelled or scoped runs', function (string $status, ?string $scope): void {
    Event::fake();
    $owner = ($this->overlapRun)(['status' => $status, 'payload' => [
        'processing_started_at' => '2026-06-29T12:00:00Z', 'process_scope' => $scope,
    ]]);
    $contender = ($this->overlapRun)();
    $repo = app(NhlImportProgressRepo::class);
    $repo->insertScheduledRows(($this->overlapRows)($owner->id, [2026020001]));
    $repo->insertScheduledRows(($this->overlapRows)($contender->id, [2026020001]));
    expect(DB::table('nhl_import_progress')->where('run_id', $contender->id)->count())->toBe(9)
        ->and($repo->claim(2026020001, 'pbp', $contender->id))->toBeTrue();
})->with([['completed', null], ['failed', null], ['cancelled', null],
    ['running', 'shots'], ['running', 'faceoffs'], ['running', 'refs_staff'], ['running', 'on_ice']]);

it('does not let a completed discovery with scheduled rows block a new explicit import', function (): void {
    Event::fake();
    $owner = ($this->overlapRun)(['status' => 'completed', 'payload' => ['auto_process' => false]]);
    $contender = ($this->overlapRun)();
    $repo = app(NhlImportProgressRepo::class);
    $repo->insertScheduledRows(($this->overlapRows)($owner->id, [2026020001]));
    $repo->insertScheduledRows(($this->overlapRows)($contender->id, [2026020001]));
    expect(DB::table('nhl_import_progress')->where('run_id', $contender->id)->count())->toBe(9)
        ->and($repo->claim(2026020001, 'pbp', $contender->id))->toBeTrue()
        ->and(DB::table('nhl_import_progress')->where('run_id', $owner->id)->where('status', 'scheduled')->count())->toBe(9);
});

it('reports empty Full imports as failed instead of enriching zero games successfully', function (): void {
    Event::fake();
    $run = ($this->overlapRun)(['payload' => ['processing_started_at' => '2026-06-29T12:00:00Z',
        'overlap_excluded_games' => ['2026020001' => 128]]]);
    app(NhlImportOrchestrator::class)->fillActiveGameSlotsForRun($run->id);
    expect($run->fresh()->status)->toBe('failed')->and($run->fresh()->last_error)->toContain('#128')
        ->and($run->fresh()->payload)->not->toHaveKey('post_process_enrichment_completed_at');
    Bus::assertNothingDispatched();
});

it('fences predeployment duplicate claims without resetting committed stages or excluding the owner', function (): void {
    Event::fake();
    $owner = ($this->overlapRun)();
    $duplicate = ($this->overlapRun)();
    // Existing rows simulate the duplicate orchestration present before admission protection deployed.
    DB::table('nhl_import_progress')->insert(($this->overlapRows)($owner->id, [2026020001]));
    DB::table('nhl_import_progress')->insert(($this->overlapRows)($duplicate->id, [2026020001]));
    DB::table('nhl_import_progress')->where('run_id', $duplicate->id)->where('import_type', 'boxscore')
        ->update(['status' => 'completed', 'items_count' => 42]);
    $repo = app(NhlImportProgressRepo::class);
    expect($repo->claim(2026020001, 'pbp', $duplicate->id))->toBeFalse()
        ->and($repo->claim(2026020001, 'pbp', $owner->id))->toBeTrue();
    $this->assertDatabaseHas('nhl_import_progress', ['run_id' => $duplicate->id, 'import_type' => 'boxscore',
        'status' => 'completed', 'items_count' => 42]);
    expect(DB::table('nhl_import_progress')->where('run_id', $duplicate->id)->where('status', 'skipped')->count())->toBe(8);
    $owner->update(['status' => 'completed']);
    // A late loader cannot silently reacquire work intentionally excluded from this generation.
    $repo->insertScheduledRows(($this->overlapRows)($duplicate->id, [2026020001]));
    expect($repo->claim(2026020001, 'pbp', $duplicate->id))->toBeFalse();
});

it('serializes overlapping admission transactions on the game rather than the selected range', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Production admission uses PostgreSQL transaction advisory locks.');
    }
    Event::fake();
    $run = ($this->overlapRun)();
    app(NhlImportProgressRepo::class)->insertScheduledRows(($this->overlapRows)($run->id, [2026020001]));
    $configuration = config('database.connections.'.DB::getDefaultConnection());
    config(['database.connections.overlap_lock_probe' => $configuration]);
    try {
        // This separate connection cannot acquire the first transaction's still-held game fence.
        $probe = DB::connection('overlap_lock_probe');
        $sameGame = $probe->selectOne('SELECT pg_try_advisory_xact_lock(73104, ?) AS acquired', [2026020001]);
        $otherGame = $probe->selectOne('SELECT pg_try_advisory_xact_lock(73104, ?) AS acquired', [2026020002]);
        expect($sameGame->acquired)->toBeFalse()->and($otherGame->acquired)->toBeTrue();
    } finally {
        DB::purge('overlap_lock_probe');
        config(['database.connections.overlap_lock_probe' => null]);
    }
});

it('clears overlap exclusions for an explicit fresh rerun without changing manual processing preferences', function (): void {
    $control = app(\App\Services\NhlGameImportRunControl::class);
    $payload = ['overlap_excluded_games' => ['2026020001' => 17], 'auto_process' => false,
        'run_control' => ['generation' => 'old'], 'completed_at' => '2026-06-29T12:00:00Z'];
    $fresh = $control->freshPayload($payload);
    expect($fresh)->not->toHaveKey('overlap_excluded_games')->not->toHaveKey('completed_at')
        ->and($fresh['auto_process'])->toBeFalse()->and($fresh['run_control']['generation'])->not->toBe('old');
});

it('excludes overlap-owned games from full post-processing without dropping their completed stage history', function (): void {
    Event::fake();
    $run = ($this->overlapRun)(['payload' => ['processing_started_at' => '2026-06-29T12:00:00Z',
        'overlap_excluded_games' => ['2026020001' => 17]]]);
    DB::table('nhl_import_progress')->insert(($this->overlapRows)($run->id, [2026020001, 2026020002]));
    DB::table('nhl_import_progress')->where('run_id', $run->id)->update(['status' => 'completed']);

    app(NhlImportOrchestrator::class)->fillActiveGameSlotsForRun($run->id);

    Bus::assertBatched(fn (\Illuminate\Bus\PendingBatch $batch): bool => $batch->name === 'NHL:FullPostProcess:'.$run->id
        && count($batch->jobs) === 3 && collect($batch->jobs)->every(fn ($job): bool => $job->nhlGameId === 2026020002));
    expect(DB::table('nhl_import_progress')->where('run_id', $run->id)->where('status', 'completed')->count())->toBe(18)
        ->and($run->fresh()->payload['post_process_enrichment_game_count'])->toBe(1);
});

it('does not seed unfinished discovered games', function (): void {
    Http::fake([
        'https://api-web.nhle.com/v1/score/2026-10-01' => Http::response([
            'games' => [
                [
                    'id' => 2026020001,
                    'season' => 20262027,
                    'gameDate' => '2026-10-01',
                    'gameType' => 2,
                    'gameState' => 'LIVE',
                    'clock' => [
                        'secondsRemaining' => 422,
                        'running' => true,
                    ],
                ],
            ],
        ]),
    ]);

    app(NhlDiscoverGames::class)->discoverDay('2026-10-01');

    expect(DB::table('nhl_import_progress')->count())->toBe(0);
});


describe('season sync after a completed Full import', function (): void {
    beforeEach(function (): void {
        Bus::fake();
        Event::fake();
        $this->fullRun = ($this->overlapRun)(['payload' => [
            'processing_started_at' => '2026-06-29T12:00:00Z',
            'post_process_enrichment_started_at' => '2026-06-29T12:00:00Z',
            'post_process_enrichment_batch_id' => 'full-fixture',
        ]]);
        ($this->insertGame)();
        $rows = ($this->overlapRows)($this->fullRun->id, [2026020001]);
        foreach ($rows as &$row) {
            $row['status'] = 'completed';
        }
        unset($row);
        DB::table('nhl_import_progress')->insert($rows);
        $this->finishFull = function (string $batchId = 'full-fixture'): void {
            (new \ReflectionMethod(NhlImportOrchestrator::class, 'recordFullPostProcessCompleted'))
                ->invoke(null, $this->fullRun->id, $batchId);
        };
    });

    it('queues a tracked season sync despite unfinished validations in other runs', function (string $status): void {
        $other = ($this->overlapRun)(['status' => 'paused']);
        ($this->insertProgress)(2026020002, NhlImportStages::VALIDATE_SUMMARY, $status, ['run_id' => $other->id]);

        ($this->finishFull)();

        $ids = $this->fullRun->fresh()->payload['post_process_enrichment_season_sync_run_ids'];
        expect($this->fullRun->fresh()->status)->toBe('completed')->and($ids)->toHaveCount(1);
        $this->assertDatabaseHas('nhl_game_import_runs', [
            'id' => $ids[0], 'action' => 'season-sync', 'status' => 'queued',
        ]);
        $this->assertDatabaseHas('nhl_import_progress', ['run_id' => $other->id, 'status' => $status]);
        Bus::assertDispatchedTimes(\App\Jobs\SeasonSumJob::class, 1);
        Bus::assertDispatched(\App\Jobs\SeasonSumJob::class,
            fn ($job): bool => $job->seasonId === '20262027' && $job->runId === $ids[0]);
    })->with(['scheduled', 'running', 'error']);

    it('waits for enrichment instead of syncing after an individual validation', function (): void {
        $orchestrator = app(NhlImportOrchestrator::class);
        $orchestrator->onSuccess(2026020001, NhlImportStages::VALIDATE_SUMMARY, [], $this->fullRun->id);
        Bus::assertNotDispatched(\App\Jobs\SeasonSumJob::class);
        expect($this->fullRun->fresh()->status)->toBe('running');
        ($this->finishFull)();
        Bus::assertDispatchedTimes(\App\Jobs\SeasonSumJob::class, 1);
    });

    it('ignores duplicate completion callbacks without replacing the sync checkpoint', function (): void {
        ($this->finishFull)();
        $ids = $this->fullRun->fresh()->payload['post_process_enrichment_season_sync_run_ids'];
        ($this->finishFull)();
        expect($this->fullRun->fresh()->payload['post_process_enrichment_season_sync_run_ids'])->toBe($ids);
        Bus::assertDispatchedTimes(\App\Jobs\SeasonSumJob::class, 1);
    });

    it('keeps drained paused imports paused until Resume queues their sync', function (): void {
        $this->fullRun->update(['status' => 'paused']);
        ($this->finishFull)();
        expect($this->fullRun->fresh()->status)->toBe('paused');
        Bus::assertNotDispatched(\App\Jobs\SeasonSumJob::class);
        app(\App\Services\NhlGameImportRunControl::class)->resume($this->fullRun->id);
        expect($this->fullRun->fresh()->status)->toBe('completed');
        Bus::assertDispatchedTimes(\App\Jobs\SeasonSumJob::class, 1);
    });

    it('does not sync cancelled or cancelling imports', function (string $status): void {
        $this->fullRun->update(['status' => $status]);
        ($this->finishFull)();
        expect($this->fullRun->fresh()->status)->toBe($status);
        Bus::assertNotDispatched(\App\Jobs\SeasonSumJob::class);
    })->with(['cancelled', 'cancelling']);

    it('rejects completion from a replaced enrichment batch', function (): void {
        ($this->finishFull)('obsolete-batch');
        expect($this->fullRun->fresh()->status)->toBe('running');
        Bus::assertNotDispatched(\App\Jobs\SeasonSumJob::class);
    });

    it('queues each affected season once for a cross-season import', function (): void {
        ($this->insertGame)(2025020001, ['season_id' => '20252026', 'game_date' => '2025-10-01']);
        ($this->insertProgress)(2025020001, NhlImportStages::VALIDATE_SUMMARY, 'completed', [
            'run_id' => $this->fullRun->id, 'season_id' => '20252026', 'game_date' => '2025-10-01',
        ]);
        ($this->finishFull)();
        Bus::assertDispatchedTimes(\App\Jobs\SeasonSumJob::class, 2);
        Bus::assertDispatched(\App\Jobs\SeasonSumJob::class, fn ($job): bool => $job->seasonId === '20252026');
        Bus::assertDispatched(\App\Jobs\SeasonSumJob::class, fn ($job): bool => $job->seasonId === '20262027');
        expect($this->fullRun->fresh()->payload['post_process_enrichment_season_sync_run_ids'])->toHaveCount(2);
    });

    it('allows a fresh explicit rerun to queue its own season sync', function (): void {
        ($this->finishFull)();
        $payload = app(\App\Services\NhlGameImportRunControl::class)->freshPayload($this->fullRun->fresh()->payload);
        $payload['post_process_enrichment_batch_id'] = 'second-batch';
        $this->fullRun->update(['status' => 'running', 'payload' => $payload]);
        ($this->finishFull)('second-batch');
        Bus::assertDispatchedTimes(\App\Jobs\SeasonSumJob::class, 2);
    });

    it('excludes seasons belonging only to overlap-excluded games', function (): void {
        ($this->insertProgress)(2025020001, NhlImportStages::VALIDATE_SUMMARY, 'completed', [
            'run_id' => $this->fullRun->id, 'season_id' => '20252026', 'game_date' => '2025-10-01',
        ]);
        $payload = $this->fullRun->payload;
        $payload['overlap_excluded_games'] = ['2025020001' => 128];
        $this->fullRun->update(['payload' => $payload]);
        ($this->finishFull)();
        Bus::assertDispatchedTimes(\App\Jobs\SeasonSumJob::class, 1);
        Bus::assertNotDispatched(\App\Jobs\SeasonSumJob::class, fn ($job): bool => $job->seasonId === '20252026');
    });

    it('syncs a completed legacy game despite unrelated unfinished validations', function (): void {
        ($this->insertProgress)(2026020002, NhlImportStages::VALIDATE_SUMMARY, 'completed');
        ($this->insertProgress)(2026020003, NhlImportStages::VALIDATE_SUMMARY, 'scheduled');
        (new \ReflectionMethod(NhlImportOrchestrator::class, 'seasonSummary'))
            ->invoke(app(NhlImportOrchestrator::class), 2026020002, NhlImportStages::VALIDATE_SUMMARY);
        Bus::assertDispatched(\App\Jobs\SeasonSumJob::class, fn ($job): bool => $job->seasonId === '20262027');
    });
});
