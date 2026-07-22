<?php

declare(strict_types=1);

use App\Events\NhlGameImportStatusUpdated;
use App\Jobs\ImportPbpNhlJob;
use App\Jobs\ImportShiftsNhlJob;
use App\Jobs\MakeShiftUnitsNhlJob;
use App\Jobs\NhlDiscoveryJob;
use App\Jobs\SumNhlGameUnitsJob;
use App\Jobs\SummarizePbpNhlJob;
use App\Jobs\ValidateNhlGameSummaryJob;
use App\Jobs\VerifyHtmlPbpNhlJob;
use App\Models\NhlGameImportRun;
use App\Repositories\NhlImportProgressRepo;
use App\Services\NhlDiscoverGames;
use App\Services\NhlGameSourcePreflight;
use App\Services\NhlImportOrchestrator;
use App\Support\NhlImportStages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-29 12:00:00');
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
});

afterEach(function (): void {
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
        ->and($result['on_ice_allowed'])->toBeFalse()
        ->and($result['message'])->toBe('NHL source preflight blocked import: shifts:empty_shiftcharts');
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
