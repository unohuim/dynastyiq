<?php

declare(strict_types=1);

use App\Jobs\ImportBoxscoreNhlJob;
use App\Jobs\ImportPbpNhlJob;
use App\Jobs\ImportShiftsNhlJob;
use App\Jobs\MakeShiftUnitsNhlJob;
use App\Jobs\SummarizePbpNhlJob;
use App\Jobs\ValidateNhlGameSummaryJob;
use App\Models\NhlGameSourceStatus;
use App\Models\NhlGameValidation;
use App\Models\NhlGameValidationDelta;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Repositories\NhlImportProgressRepo;
use App\Services\ConnectEventsToUnitShifts;
use App\Services\CompareNhlPbPBoxscore;
use App\Services\ImportNHLPlayByPlay;
use App\Services\ImportNhlShifts;
use App\Services\NhlGameSourcePreflight;
use App\Services\NhlImportOrchestrator;
use App\Services\SumNhlGameUnits;
use App\Services\SumNHLPlayByPlay;
use App\Services\ValidateNhlGameSummary;
use App\Support\NhlImportStages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-29 12:00:00');
    Config::set('apiImportNhl.validation_troubleshooting_path', sys_get_temp_dir() . '/dynastyiq-validation-troubleshooting-tests');
    File::deleteDirectory((string) config('apiImportNhl.validation_troubleshooting_path'));

    $this->makeSuperAdmin = function (): User {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'Super Admin',
            'slug' => 'super-admin',
            'level' => 99,
            'scope' => 'global',
            'is_active' => true,
        ]);

        $user->roles()->attach($role->id, ['organization_id' => null]);

        return $user;
    };

    $this->insertGame = static function (int $gameId = 2026020001): void {
        DB::table('nhl_games')->insert([
            'nhl_game_id' => $gameId,
            'season_id' => '20262027',
            'game_type' => 2,
            'game_date' => '2026-10-01',
            'game_dow' => 'Thu',
            'game_month' => 'Oct',
            'home_team_id' => 1,
            'home_team_abbrev' => 'TOR',
            'away_team_id' => 2,
            'away_team_abbrev' => 'MTL',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    $this->makePlayer = static function (int $nhlId = 8478402, array $overrides = []): Player {
        return Player::create(array_merge([
            'nhl_id' => $nhlId,
            'first_name' => 'Test',
            'last_name' => 'Player',
            'full_name' => 'Test Player',
            'position' => 'C',
            'team_abbrev' => 'TOR',
            'current_league_abbrev' => 'NHL',
        ], $overrides));
    };

    $this->insertBoxscore = static function (int $gameId, int $playerId, array $overrides = []): void {
        DB::table('nhl_boxscores')->insert(array_merge([
            'nhl_game_id' => $gameId,
            'nhl_player_id' => $playerId,
            'nhl_team_id' => 1,
            'goals' => 1,
            'assists' => 1,
            'points' => 2,
            'penalty_minutes' => 0,
            'toi' => '18:00',
            'toi_seconds' => 1080,
            'shifts' => 18,
            'sog' => 4,
            'hits' => 2,
            'blocks' => 1,
            'faceoffs_won' => 6,
            'faceoffs_lost' => 4,
            'faceoff_win_percentage' => 0.6,
            'power_play_goals' => 1,
            'giveaways' => 1,
            'takeaways' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    };

    $this->insertSummary = static function (int $gameId, int $playerId, array $overrides = []): void {
        DB::table('nhl_game_summaries')->insert(array_merge([
            'nhl_game_id' => $gameId,
            'nhl_player_id' => $playerId,
            'nhl_team_id' => 1,
            'g' => 1,
            'a' => 1,
            'pts' => 2,
            'pim' => 0,
            'toi' => 1080,
            'shifts' => 18,
            'sog' => 4,
            'h' => 2,
            'b' => 1,
            'fow' => 6,
            'fol' => 4,
            'fot' => 10,
            'fow_percentage' => 60.0,
            'ppg' => 1,
            'gv' => 1,
            'tk' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    };

    $this->insertProgress = static function (
        int $gameId,
        string $stage,
        string $status = 'scheduled',
        array $overrides = []
    ): void {
        DB::table('nhl_import_progress')->insert(array_merge([
            'season_id' => '20262027',
            'game_date' => '2026-10-01',
            'game_id' => (string) $gameId,
            'game_type' => 2,
            'import_type' => $stage,
            'items_count' => 0,
            'status' => $status,
            'discovered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    };

    $this->insertPipeline = function (int $gameId, array $statuses = []): void {
        foreach (NhlImportStages::ordered() as $stage) {
            ($this->insertProgress)($gameId, $stage, $statuses[$stage] ?? 'scheduled');
        }
    };

    $this->fakeSourcePreflight = function (array $sourceStatuses): void {
        app()->instance(NhlGameSourcePreflight::class, new class($sourceStatuses) extends NhlGameSourcePreflight {
            /**
             * @param array<string, array<string, mixed>> $sourceStatuses
             */
            public function __construct(private readonly array $sourceStatuses)
            {
            }

            /**
             * @return array{
             *     allowed: bool,
             *     core_allowed: bool,
             *     on_ice_allowed: bool,
             *     statuses: array<int, array<string, mixed>>,
             *     message: string|null,
             *     core_message: string|null,
             *     on_ice_message: string|null
             * }
             */
            public function check(int $gameId): array
            {
                $statuses = collect([
                    NhlGameSourceStatus::SOURCE_PBP,
                    NhlGameSourceStatus::SOURCE_BOXSCORE,
                    NhlGameSourceStatus::SOURCE_SHIFTS,
                ])->map(function (string $source) use ($gameId): array {
                    $status = $this->sourceStatuses[$source] ?? [
                        'status' => NhlGameSourceStatus::STATUS_AVAILABLE,
                        'reason' => null,
                    ];

                    return [
                        'nhl_game_id' => $gameId,
                        'source' => $source,
                        'status' => $status['status'],
                        'reason' => $status['reason'] ?? null,
                        'url' => $status['url'] ?? "https://example.test/{$source}/{$gameId}",
                        'details' => $status['details'] ?? [],
                    ];
                })->all();

                foreach ($statuses as $status) {
                    NhlGameSourceStatus::query()->updateOrCreate(
                        [
                            'nhl_game_id' => $gameId,
                            'source' => $status['source'],
                        ],
                        [
                            'status' => $status['status'],
                            'reason' => $status['reason'],
                            'url' => $status['url'],
                            'details' => $status['details'],
                            'checked_at' => now(),
                        ]
                    );
                }

                $blockedCore = array_values(array_filter(
                    $statuses,
                    fn (array $status): bool => in_array($status['source'], [
                        NhlGameSourceStatus::SOURCE_PBP,
                        NhlGameSourceStatus::SOURCE_BOXSCORE,
                    ], true) && $status['status'] !== NhlGameSourceStatus::STATUS_AVAILABLE
                ));
                $blockedOnIce = array_values(array_filter(
                    $statuses,
                    fn (array $status): bool => $status['source'] === NhlGameSourceStatus::SOURCE_SHIFTS
                        && $status['status'] !== NhlGameSourceStatus::STATUS_AVAILABLE
                ));
                $coreAllowed = $blockedCore === [];
                $onIceAllowed = $blockedOnIce === [];

                return [
                    'allowed' => $coreAllowed,
                    'core_allowed' => $coreAllowed,
                    'on_ice_allowed' => $onIceAllowed,
                    'statuses' => $statuses,
                    'message' => $coreAllowed && $onIceAllowed ? null : 'blocked',
                    'core_message' => $coreAllowed ? null : 'core blocked',
                    'on_ice_message' => $onIceAllowed ? null : 'on-ice blocked',
                ];
            }
        });
    };
});

afterEach(function (): void {
    File::deleteDirectory((string) config('apiImportNhl.validation_troubleshooting_path'));
    Carbon::setTestNow();
});

it('returns no deltas when exact comparable totals match', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026020001, 8478402);
    ($this->insertSummary)(2026020001, 8478402);

    expect(app(CompareNhlPbPBoxscore::class)->compare(2026020001))->toBe([]);
});

it('returns a field delta when an exact comparable total differs', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026020001, 8478402, ['goals' => 2, 'points' => 3]);
    ($this->insertSummary)(2026020001, 8478402);

    $deltas = app(CompareNhlPbPBoxscore::class)->compare(2026020001);

    expect(collect($deltas)->pluck('field')->all())->toContain('goals', 'points');
});

it('does not return a missing summary delta when a boxscore player has no summary row', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026020001, 8478402);

    expect(app(CompareNhlPbPBoxscore::class)->compare(2026020001))->toBe([]);
});

it('returns a missing boxscore delta when a summary row has comparable totals', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    ($this->insertSummary)(2026020001, 8478402);

    $deltas = app(CompareNhlPbPBoxscore::class)->compare(2026020001);

    expect($deltas[0]['field'])->toBe('boxscore_record');
});

it('ignores extra summary rows that have no comparable totals', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    ($this->insertSummary)(2026020001, 8478402, [
        'g' => 0,
        'a' => 0,
        'pts' => 0,
        'sog' => 0,
        'shifts' => 0,
        'h' => 0,
        'b' => 0,
        'fow' => 0,
        'fol' => 0,
        'ppg' => 0,
        'gv' => 0,
        'tk' => 0,
    ]);

    expect(app(CompareNhlPbPBoxscore::class)->compare(2026020001))->toBe([]);
});

it('tolerates one second time-on-ice drift', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026020001, 8478402, ['toi_seconds' => 1081]);
    ($this->insertSummary)(2026020001, 8478402, ['toi' => 1080]);

    expect(app(CompareNhlPbPBoxscore::class)->compare(2026020001))->toBe([]);
});

it('returns a time-on-ice delta beyond tolerance', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026020001, 8478402, ['toi_seconds' => 1085]);
    ($this->insertSummary)(2026020001, 8478402, ['toi' => 1080]);

    expect(app(CompareNhlPbPBoxscore::class)->compare(2026020001)[0]['field'])->toBe('toi_seconds');
});

it('does not validate faceoff counts giveaways or takeaways', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026020001, 8478402, [
        'faceoffs_won' => 9,
        'faceoffs_lost' => 7,
        'giveaways' => 8,
        'takeaways' => 6,
    ]);
    ($this->insertSummary)(2026020001, 8478402, [
        'fow' => 6,
        'fol' => 4,
        'gv' => 1,
        'tk' => 2,
    ]);

    expect(app(CompareNhlPbPBoxscore::class)->compare(2026020001))->toBe([]);
});

it('normalizes faceoff percentage before validation', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026020001, 8478402, ['faceoff_win_percentage' => 0.75]);
    ($this->insertSummary)(2026020001, 8478402, ['fow_percentage' => 75.0]);

    expect(app(CompareNhlPbPBoxscore::class)->compare(2026020001))->toBe([]);
});

it('returns a faceoff percentage delta when normalized percentages differ', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026020001, 8478402, ['faceoff_win_percentage' => 0.65]);
    ($this->insertSummary)(2026020001, 8478402, ['fow_percentage' => 75.0]);

    expect(app(CompareNhlPbPBoxscore::class)->compare(2026020001)[0]['field'])->toBe('faceoff_win_percentage');
});

it('returns deltas for retained skater validation fields', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026020001, 8478402, [
        'goals' => 2,
        'assists' => 2,
        'points' => 4,
        'penalty_minutes' => 2,
        'hits' => 3,
        'power_play_goals' => 2,
        'sog' => 5,
        'shifts' => 20,
        'blocks' => 2,
    ]);
    ($this->insertSummary)(2026020001, 8478402);

    expect(collect(app(CompareNhlPbPBoxscore::class)->compare(2026020001))->pluck('field')->all())
        ->toContain(
            'goals',
            'assists',
            'points',
            'penalty_minutes',
            'hits',
            'power_play_goals',
            'sog',
            'shifts',
            'blocks'
        );
});

it('persists raw play-by-play event metadata during import', function (): void {
    $importer = new class extends ImportNHLPlayByPlay {
        public function getAPIData(
            string $service,
            string $endpointKey,
            array $replacements = [],
            array $query = [],
            bool $decodeJson = true
        ): array|string {
            return [
                'season' => 20262027,
                'gameType' => 2,
                'gameDate' => '2026-10-01',
                'homeTeam' => ['id' => 1, 'score' => 0, 'sog' => 0, 'abbrev' => 'TOR'],
                'awayTeam' => ['id' => 2, 'score' => 0, 'sog' => 0, 'abbrev' => 'MTL'],
                'plays' => [
                    [
                        'eventId' => 391,
                        'periodDescriptor' => ['number' => 5, 'periodType' => 'SO'],
                        'timeInPeriod' => '00:00',
                        'timeRemaining' => '00:00',
                        'situationCode' => '1010',
                        'typeCode' => 506,
                        'typeDescKey' => 'shot-on-goal',
                        'sortOrder' => 920,
                        'details' => [
                            'shootingPlayerId' => 8477933,
                            'goalieInNetId' => 8476914,
                            'eventOwnerTeamId' => 1,
                            'awaySOG' => 25,
                            'homeSOG' => 23,
                        ],
                    ],
                ],
            ];
        }
    };

    expect($importer->import(2026020001))->toBe(1);

    $play = \App\Models\PlayByPlay::where('nhl_game_id', 2026020001)->firstOrFail();

    expect($play->metadata['event']['eventId'])->toBe(391)
        ->and($play->metadata['details']['awaySOG'])->toBe(25)
        ->and($play->metadata['details']['homeSOG'])->toBe(23);
});

it('counts match penalties as duration plus ten penalty minutes in player summaries', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8479393, [
        'first_name' => 'Noah',
        'last_name' => 'Gregor',
        'full_name' => 'Noah Gregor',
    ]);
    ($this->makePlayer)(8484759);

    DB::table('play_by_plays')->insert([
        'nhl_game_id' => 2026020001,
        'nhl_event_id' => '982',
        'period' => 3,
        'period_type' => 'REG',
        'time_in_period' => '11:26',
        'time_remaining' => '08:34',
        'seconds_in_period' => 686,
        'seconds_in_game' => 3086,
        'seconds_remaining' => 514,
        'situation_code' => '1551',
        'type_code' => 509,
        'type_desc_key' => 'penalty',
        'desc_key' => 'match-penalty',
        'sort_order' => 672,
        'event_owner_team_id' => 1,
        'zone_code' => 'D',
        'committed_by_player_id' => 8479393,
        'drawn_by_player_id' => 8484759,
        'duration' => 5,
        'penalty_type_code' => 'MAT',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(SumNHLPlayByPlay::class)->summarize(2026020001);

    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8479393,
        'pim' => 15,
    ]);
});

it('excludes shootout attempts from boxscore comparable shot and goalie summaries', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8473419, [
        'first_name' => 'Brad',
        'last_name' => 'Marchand',
        'full_name' => 'Brad Marchand',
    ]);
    ($this->makePlayer)(8476914, [
        'first_name' => 'Joonas',
        'last_name' => 'Korpisalo',
        'full_name' => 'Joonas Korpisalo',
        'position' => 'G',
    ]);

    DB::table('play_by_plays')->insert([
        [
            'nhl_game_id' => 2026020001,
            'nhl_event_id' => '386',
            'period' => 4,
            'period_type' => 'OT',
            'time_in_period' => '04:50',
            'time_remaining' => '00:10',
            'seconds_in_period' => 290,
            'seconds_in_game' => 3890,
            'seconds_remaining' => 10,
            'situation_code' => '1331',
            'type_code' => 506,
            'type_desc_key' => 'shot-on-goal',
            'sort_order' => 909,
            'event_owner_team_id' => 1,
            'shooting_player_id' => 8473419,
            'goalie_in_net_player_id' => 8476914,
            'strength' => 'EV',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'nhl_game_id' => 2026020001,
            'nhl_event_id' => '391',
            'period' => 5,
            'period_type' => 'SO',
            'time_in_period' => '00:00',
            'time_remaining' => '00:00',
            'seconds_in_period' => 0,
            'seconds_in_game' => 3900,
            'seconds_remaining' => 0,
            'situation_code' => '1010',
            'type_code' => 506,
            'type_desc_key' => 'shot-on-goal',
            'sort_order' => 920,
            'event_owner_team_id' => 1,
            'shooting_player_id' => 8473419,
            'goalie_in_net_player_id' => 8476914,
            'strength' => 'EV',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'nhl_game_id' => 2026020001,
            'nhl_event_id' => '395',
            'period' => 5,
            'period_type' => 'SO',
            'time_in_period' => '00:00',
            'time_remaining' => '00:00',
            'seconds_in_period' => 0,
            'seconds_in_game' => 3900,
            'seconds_remaining' => 0,
            'situation_code' => '1010',
            'type_code' => 505,
            'type_desc_key' => 'goal',
            'sort_order' => 924,
            'event_owner_team_id' => 1,
            'scoring_player_id' => 8473419,
            'goalie_in_net_player_id' => 8476914,
            'strength' => 'EV',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    app(SumNHLPlayByPlay::class)->summarize(2026020001);

    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8473419,
        'sog' => 1,
        'evsog' => 1,
        'g' => 0,
        'evg' => 0,
    ]);

    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8476914,
        'sv' => 1,
        'evsv' => 1,
        'ga' => 0,
        'evga' => 0,
        'sa' => 1,
        'evsa' => 1,
        'shosv' => 1,
    ]);
});

it('excludes no-shot goal records from shots on goal and goalie shots against', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8479671, [
        'first_name' => 'Mathieu',
        'last_name' => 'Olivier',
        'full_name' => 'Mathieu Olivier',
    ]);
    ($this->makePlayer)(8474593, [
        'first_name' => 'Jacob',
        'last_name' => 'Markstrom',
        'full_name' => 'Jacob Markstrom',
        'position' => 'G',
    ]);

    DB::table('play_by_plays')->insert([
        [
            'nhl_game_id' => 2026020001,
            'nhl_event_id' => '905',
            'period' => 3,
            'period_type' => 'REG',
            'time_in_period' => '12:05',
            'time_remaining' => '07:55',
            'seconds_in_period' => 725,
            'seconds_in_game' => 3125,
            'seconds_remaining' => 475,
            'situation_code' => '1551',
            'type_code' => 505,
            'type_desc_key' => 'goal',
            'sort_order' => 653,
            'event_owner_team_id' => 2,
            'scoring_player_id' => 8479671,
            'goalie_in_net_player_id' => 8474593,
            'strength' => 'EV',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'nhl_game_id' => 2026020001,
            'nhl_event_id' => '906',
            'period' => 3,
            'period_type' => 'REG',
            'time_in_period' => '12:08',
            'time_remaining' => '07:52',
            'seconds_in_period' => 728,
            'seconds_in_game' => 3128,
            'seconds_remaining' => 472,
            'situation_code' => '1551',
            'type_code' => 505,
            'type_desc_key' => 'goal',
            'sort_order' => 654,
            'event_owner_team_id' => 2,
            'scoring_player_id' => 8479671,
            'goalie_in_net_player_id' => 8474593,
            'shot_type' => 'backhand',
            'strength' => 'EV',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'nhl_game_id' => 2026020001,
            'nhl_event_id' => '309',
            'period' => 3,
            'period_type' => 'REG',
            'time_in_period' => '16:00',
            'time_remaining' => '04:00',
            'seconds_in_period' => 960,
            'seconds_in_game' => 3360,
            'seconds_remaining' => 240,
            'situation_code' => '1560',
            'type_code' => 505,
            'type_desc_key' => 'goal',
            'sort_order' => 696,
            'event_owner_team_id' => 2,
            'scoring_player_id' => 8479671,
            'strength' => 'EV',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    app(SumNHLPlayByPlay::class)->summarize(2026020001);

    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8479671,
        'g' => 3,
        'eng' => 1,
        'sog' => 1,
        'evsog' => 1,
        'sat' => 1,
        'evsat' => 1,
    ]);
    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8474593,
        'ga' => 2,
        'evga' => 2,
        'sa' => 1,
        'evsa' => 1,
    ]);
});

it('rebuilds event-unit links idempotently for a game', function (): void {
    ($this->insertGame)();

    $unitId = DB::table('nhl_units')->insertGetId([
        'team_abbrev' => 'TOR',
        'unit_type' => 'F',
        'composition_hash' => 'unit-a',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $staleUnitId = DB::table('nhl_units')->insertGetId([
        'team_abbrev' => 'TOR',
        'unit_type' => 'F',
        'composition_hash' => 'unit-b',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $activeShiftId = DB::table('nhl_unit_shifts')->insertGetId([
        'unit_id' => $unitId,
        'nhl_game_id' => 2026020001,
        'team_id' => 1,
        'team_abbrev' => 'TOR',
        'period' => 1,
        'start_time' => '00:00',
        'end_time' => '00:20',
        'start_game_seconds' => 0,
        'end_game_seconds' => 20,
        'seconds' => 20,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $staleShiftId = DB::table('nhl_unit_shifts')->insertGetId([
        'unit_id' => $staleUnitId,
        'nhl_game_id' => 2026020001,
        'team_id' => 1,
        'team_abbrev' => 'TOR',
        'period' => 1,
        'start_time' => '00:30',
        'end_time' => '00:40',
        'start_game_seconds' => 30,
        'end_game_seconds' => 40,
        'seconds' => 10,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $eventId = DB::table('play_by_plays')->insertGetId([
        'nhl_game_id' => 2026020001,
        'nhl_event_id' => '10',
        'period' => 1,
        'period_type' => 'REG',
        'time_in_period' => '00:10',
        'seconds_in_game' => 10,
        'type_desc_key' => 'shot-on-goal',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('event_unit_shifts')->insert([
        'event_id' => $eventId,
        'unit_shift_id' => $staleShiftId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(app()->make(ConnectEventsToUnitShifts::class, ['gameId' => 2026020001])->connect())->toBe(1);

    expect(DB::table('event_unit_shifts')->where('event_id', $eventId)->pluck('unit_shift_id')->all())
        ->toBe([$activeShiftId]);
});

it('uses boxscore shot semantics for unit shot aggregations', function (): void {
    ($this->insertGame)();

    $unitId = DB::table('nhl_units')->insertGetId([
        'team_abbrev' => 'TOR',
        'unit_type' => 'F',
        'composition_hash' => 'unit-shot',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $shiftId = DB::table('nhl_unit_shifts')->insertGetId([
        'unit_id' => $unitId,
        'nhl_game_id' => 2026020001,
        'team_id' => 1,
        'team_abbrev' => 'TOR',
        'period' => 1,
        'start_time' => '00:00',
        'end_time' => '01:00',
        'start_game_seconds' => 0,
        'end_game_seconds' => 60,
        'seconds' => 60,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $normalGoalId = DB::table('play_by_plays')->insertGetId([
        'nhl_game_id' => 2026020001,
        'nhl_event_id' => '20',
        'period' => 1,
        'period_type' => 'REG',
        'seconds_in_game' => 20,
        'type_desc_key' => 'goal',
        'event_owner_team_id' => 1,
        'goalie_in_net_player_id' => 8470001,
        'strength' => 'EV',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $awardedGoalId = DB::table('play_by_plays')->insertGetId([
        'nhl_game_id' => 2026020001,
        'nhl_event_id' => '21',
        'period' => 1,
        'period_type' => 'REG',
        'seconds_in_game' => 30,
        'type_desc_key' => 'goal',
        'event_owner_team_id' => 1,
        'strength' => 'EV',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $shootoutMissId = DB::table('play_by_plays')->insertGetId([
        'nhl_game_id' => 2026020001,
        'nhl_event_id' => '22',
        'period' => 5,
        'period_type' => 'SO',
        'seconds_in_game' => 3900,
        'type_desc_key' => 'missed-shot',
        'event_owner_team_id' => 1,
        'strength' => 'EV',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $shootoutGoalId = DB::table('play_by_plays')->insertGetId([
        'nhl_game_id' => 2026020001,
        'nhl_event_id' => '23',
        'period' => 5,
        'period_type' => 'SO',
        'seconds_in_game' => 3900,
        'type_desc_key' => 'goal',
        'event_owner_team_id' => 1,
        'goalie_in_net_player_id' => 8470001,
        'strength' => 'EV',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('event_unit_shifts')->insert([
        ['event_id' => $normalGoalId, 'unit_shift_id' => $shiftId, 'created_at' => now(), 'updated_at' => now()],
        ['event_id' => $awardedGoalId, 'unit_shift_id' => $shiftId, 'created_at' => now(), 'updated_at' => now()],
        ['event_id' => $shootoutMissId, 'unit_shift_id' => $shiftId, 'created_at' => now(), 'updated_at' => now()],
        ['event_id' => $shootoutGoalId, 'unit_shift_id' => $shiftId, 'created_at' => now(), 'updated_at' => now()],
    ]);

    app()->make(SumNhlGameUnits::class, ['gameId' => 2026020001])->sum();

    $this->assertDatabaseHas('nhl_unit_game_summaries', [
        'nhl_game_id' => 2026020001,
        'unit_id' => $unitId,
        'gf' => 2,
        'sf' => 1,
        'satf' => 1,
        'ff' => 1,
    ]);
});

it('returns goalie-only deltas for persisted and derived goalie fields', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402, ['position' => 'G']);
    ($this->insertBoxscore)(2026020001, 8478402, [
        'position' => 'G',
        'goals' => 0,
        'assists' => 0,
        'points' => 0,
        'sog' => 0,
        'hits' => 0,
        'blocks' => 0,
        'power_play_goals' => 0,
        'toi_seconds' => 3495,
        'goals_against' => 3,
        'saves' => 22,
        'shots_against' => 25,
        'ev_saves' => 12,
        'ev_shots_against' => 15,
        'pp_saves' => 7,
        'pp_shots_against' => 7,
        'pk_saves' => 3,
        'pk_shots_against' => 3,
    ]);
    ($this->insertSummary)(2026020001, 8478402, [
        'g' => 0,
        'a' => 0,
        'pts' => 0,
        'sog' => 0,
        'h' => 0,
        'b' => 0,
        'ppg' => 0,
        'toi' => 3495,
        'ga' => 2,
        'sv' => 21,
        'sa' => 24,
        'evsv' => 13,
        'evsa' => 15,
        'ppsv' => 6,
        'ppsa' => 7,
        'pksv' => 2,
        'pksa' => 3,
        'evga' => 1,
        'ppga' => 1,
        'pkga' => 1,
    ]);

    expect(collect(app(CompareNhlPbPBoxscore::class)->compare(2026020001))->pluck('field')->all())
        ->toContain(
            'goals_against',
            'saves',
            'shots_against',
            'ev_saves',
            'ev_shots_against',
            'pp_saves',
            'pp_shots_against',
            'pk_saves',
            'pk_shots_against',
            'ev_goals_against',
            'pp_goals_against',
            'pk_goals_against',
            'save_percentage'
        );
});

it('uses official goalie strength goals against instead of deriving them from shots minus saves', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8480981, ['position' => 'G']);
    ($this->insertBoxscore)(2026020001, 8480981, [
        'position' => 'G',
        'goals' => 0,
        'assists' => 0,
        'points' => 0,
        'sog' => 0,
        'hits' => 0,
        'blocks' => 0,
        'power_play_goals' => 0,
        'goals_against' => 4,
        'saves' => 46,
        'shots_against' => 49,
        'ev_saves' => 36,
        'ev_shots_against' => 38,
        'ev_goals_against' => 2,
        'pp_saves' => 10,
        'pp_shots_against' => 11,
        'pp_goals_against' => 2,
        'pk_saves' => 0,
        'pk_shots_against' => 0,
        'pk_goals_against' => 0,
    ]);
    ($this->insertSummary)(2026020001, 8480981, [
        'g' => 0,
        'a' => 0,
        'pts' => 0,
        'sog' => 0,
        'h' => 0,
        'b' => 0,
        'ppg' => 0,
        'ga' => 4,
        'sv' => 46,
        'sa' => 49,
        'evsv' => 36,
        'evsa' => 38,
        'evga' => 2,
        'ppsv' => 10,
        'ppsa' => 11,
        'ppga' => 2,
        'pksv' => 0,
        'pksa' => 0,
        'pkga' => 0,
    ]);

    expect(collect(app(CompareNhlPbPBoxscore::class)->compare(2026020001))->pluck('field')->all())
        ->not->toContain('pp_goals_against');
});

it('persists an approved validation when no deltas exist', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026020001, 8478402);
    ($this->insertSummary)(2026020001, 8478402);

    $validation = app(ValidateNhlGameSummary::class)->validate(2026020001);

    expect($validation->status)->toBe(NhlGameValidation::STATUS_APPROVED)
        ->and($validation->mismatch_count)->toBe(0)
        ->and($validation->approved_at)->not->toBeNull();
});

it('persists an incomplete validation when core deltas pass but shifts are unavailable', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026020001, 8478402, [
        'toi_seconds' => 1080,
        'shifts' => 18,
    ]);
    ($this->insertSummary)(2026020001, 8478402, [
        'toi' => 0,
        'shifts' => 0,
    ]);
    DB::table('nhl_game_source_statuses')->insert([
        'nhl_game_id' => 2026020001,
        'source' => 'shifts',
        'status' => 'empty',
        'reason' => 'empty_shiftcharts',
        'url' => 'https://api.nhle.com/stats/rest/en/shiftcharts?cayenneExp=gameId=2026020001',
        'details' => json_encode(['data_count' => 0, 'total' => 0]),
        'checked_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $validation = app(ValidateNhlGameSummary::class)->validate(2026020001);

    expect($validation->status)->toBe(NhlGameValidation::STATUS_INCOMPLETE)
        ->and($validation->mismatch_count)->toBe(0)
        ->and($validation->approved_at)->toBeNull();
});

it('lists games with missing source records for admin rerun triage', function (): void {
    $admin = ($this->makeSuperAdmin)();
    ($this->insertGame)();

    DB::table('nhl_game_source_statuses')->insert([
        'nhl_game_id' => 2026020001,
        'source' => NhlGameSourceStatus::SOURCE_SHIFTS,
        'status' => NhlGameSourceStatus::STATUS_EMPTY,
        'reason' => 'empty_shiftcharts',
        'url' => 'https://api.nhle.com/stats/rest/en/shiftcharts?cayenneExp=gameId=2026020001',
        'details' => json_encode(['data_count' => 0]),
        'checked_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($admin)
        ->getJson(route('admin.nhl-game-imports.source-gaps'))
        ->assertOk()
        ->assertJsonPath('gaps.0.game_id', 2026020001)
        ->assertJsonPath('gaps.0.away_team_abbrev', 'MTL')
        ->assertJsonPath('gaps.0.home_team_abbrev', 'TOR')
        ->assertJsonPath('gaps.0.sources.0.source', NhlGameSourceStatus::SOURCE_SHIFTS)
        ->assertJsonPath('gaps.0.sources.0.reason', 'empty_shiftcharts')
        ->assertJsonPath('gaps.0.sources.0.details.data_count', 0);
});

it('requires authentication for source gap admin endpoints', function (): void {
    $this->getJson(route('admin.nhl-game-imports.source-gaps'))
        ->assertUnauthorized();

    $this->postJson(route('admin.nhl-game-imports.source-gaps.rerun', ['gameId' => 2026020001]))
        ->assertUnauthorized();
});

it('blocks non-admin users from source gap admin endpoints', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('admin.nhl-game-imports.source-gaps'))
        ->assertForbidden();

    $this->actingAs($user)
        ->postJson(route('admin.nhl-game-imports.source-gaps.rerun', ['gameId' => 2026020001]))
        ->assertForbidden();
});

it('queues only shift-derived stages when a missing shifts source recovers', function (): void {
    Bus::fake();
    $admin = ($this->makeSuperAdmin)();
    ($this->insertGame)();
    ($this->insertPipeline)(2026020001, [
        NhlImportStages::PBP => 'completed',
        NhlImportStages::SUMMARY => 'completed',
        NhlImportStages::BOXSCORE => 'completed',
        NhlImportStages::SHIFTS => 'skipped',
        NhlImportStages::SHIFT_UNITS => 'skipped',
        NhlImportStages::CONNECT_EVENTS => 'skipped',
        NhlImportStages::SUM_GAME_UNITS => 'skipped',
        NhlImportStages::VALIDATE_SUMMARY => 'completed',
    ]);
    DB::table('nhl_game_source_statuses')->insert([
        'nhl_game_id' => 2026020001,
        'source' => NhlGameSourceStatus::SOURCE_SHIFTS,
        'status' => NhlGameSourceStatus::STATUS_EMPTY,
        'reason' => 'empty_shiftcharts',
        'url' => 'https://example.test/shifts/2026020001',
        'details' => json_encode([]),
        'checked_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    ($this->fakeSourcePreflight)([]);

    $this->actingAs($admin)
        ->postJson(route('admin.nhl-game-imports.source-gaps.rerun', ['gameId' => 2026020001]))
        ->assertAccepted()
        ->assertJsonPath('status', 'shift_stages_queued')
        ->assertJsonCount(0, 'gaps');

    Bus::assertDispatched(ImportShiftsNhlJob::class);
    Bus::assertNotDispatched(ImportPbpNhlJob::class);
    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026020001',
        'import_type' => NhlImportStages::SHIFTS,
        'status' => 'running',
    ]);
    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026020001',
        'import_type' => NhlImportStages::VALIDATE_SUMMARY,
        'status' => 'scheduled',
    ]);
});

it('rebuilds the full game when a missing core source recovers', function (): void {
    Bus::fake();
    $admin = ($this->makeSuperAdmin)();
    ($this->insertGame)();
    ($this->insertPipeline)(2026020001, [
        NhlImportStages::PBP => 'skipped',
        NhlImportStages::SUMMARY => 'skipped',
        NhlImportStages::BOXSCORE => 'skipped',
        NhlImportStages::SHIFTS => 'skipped',
        NhlImportStages::SHIFT_UNITS => 'skipped',
        NhlImportStages::CONNECT_EVENTS => 'skipped',
        NhlImportStages::SUM_GAME_UNITS => 'skipped',
        NhlImportStages::VALIDATE_SUMMARY => 'skipped',
    ]);
    DB::table('nhl_game_source_statuses')->insert([
        'nhl_game_id' => 2026020001,
        'source' => NhlGameSourceStatus::SOURCE_PBP,
        'status' => NhlGameSourceStatus::STATUS_EMPTY,
        'reason' => 'empty_plays',
        'url' => 'https://example.test/pbp/2026020001',
        'details' => json_encode([]),
        'checked_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    ($this->fakeSourcePreflight)([]);

    $this->actingAs($admin)
        ->postJson(route('admin.nhl-game-imports.source-gaps.rerun', ['gameId' => 2026020001]))
        ->assertAccepted()
        ->assertJsonPath('status', 'game_rebuild_queued')
        ->assertJsonCount(0, 'gaps');

    Bus::assertDispatched(ImportPbpNhlJob::class);
    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026020001',
        'import_type' => NhlImportStages::PBP,
        'status' => 'running',
    ]);
});

it('keeps a game in source gaps when a rerun still finds a missing core source', function (): void {
    Bus::fake();
    $admin = ($this->makeSuperAdmin)();
    ($this->insertGame)();
    ($this->insertPipeline)(2026020001);
    DB::table('nhl_game_source_statuses')->insert([
        'nhl_game_id' => 2026020001,
        'source' => NhlGameSourceStatus::SOURCE_BOXSCORE,
        'status' => NhlGameSourceStatus::STATUS_EMPTY,
        'reason' => 'empty_player_stats',
        'url' => 'https://example.test/boxscore/2026020001',
        'details' => json_encode([]),
        'checked_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    ($this->fakeSourcePreflight)([
        NhlGameSourceStatus::SOURCE_BOXSCORE => [
            'status' => NhlGameSourceStatus::STATUS_EMPTY,
            'reason' => 'empty_player_stats',
        ],
    ]);

    $this->actingAs($admin)
        ->postJson(route('admin.nhl-game-imports.source-gaps.rerun', ['gameId' => 2026020001]))
        ->assertAccepted()
        ->assertJsonPath('status', 'source_checked')
        ->assertJsonPath('gaps.0.sources.0.source', NhlGameSourceStatus::SOURCE_BOXSCORE);

    Bus::assertNothingDispatched();
});

it('persists a failed validation with field deltas', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026020001, 8478402, ['goals' => 2]);
    ($this->insertSummary)(2026020001, 8478402);

    $validation = app(ValidateNhlGameSummary::class)->validate(2026020001);

    expect($validation->status)->toBe(NhlGameValidation::STATUS_FAILED)
        ->and($validation->mismatch_count)->toBe(1);
    $this->assertDatabaseHas('nhl_game_validation_deltas', [
        'validation_id' => $validation->id,
        'field' => 'goals',
        'severity' => NhlGameValidationDelta::SEVERITY_ERROR,
    ]);
});

it('writes troubleshooting markdown snapshots when validation fails', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026020001, 8478402, ['goals' => 2]);
    ($this->insertSummary)(2026020001, 8478402);

    DB::table('play_by_plays')->insert([
        'nhl_game_id' => 2026020001,
        'nhl_event_id' => '704',
        'period' => 3,
        'period_type' => 'REG',
        'time_in_period' => '05:52',
        'time_remaining' => '14:08',
        'seconds_in_period' => 352,
        'seconds_in_game' => 2752,
        'seconds_remaining' => 848,
        'situation_code' => '1541',
        'type_code' => 505,
        'type_desc_key' => 'goal',
        'sort_order' => 638,
        'event_owner_team_id' => 1,
        'scoring_player_id' => 8478402,
        'goalie_in_net_player_id' => 8480981,
        'strength' => 'PP',
        'metadata' => json_encode([
            'details' => ['awaySOG' => 34, 'homeSOG' => 14],
            'event' => ['typeCode' => 505],
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $validation = app(ValidateNhlGameSummary::class)->validate(2026020001);
    $directory = (string) config('apiImportNhl.validation_troubleshooting_path');

    expect($validation->status)->toBe(NhlGameValidation::STATUS_FAILED)
        ->and(File::exists($directory . '/boxscore_2026020001.md'))->toBeTrue()
        ->and(File::exists($directory . '/pbp_2026020001.md'))->toBeTrue()
        ->and(File::exists($directory . '/shifts_2026020001.md'))->toBeTrue()
        ->and(File::exists($directory . '/deltas_2026020001.md'))->toBeTrue()
        ->and(File::get($directory . '/pbp_2026020001.md'))->toContain('Counts SOG', '704');
});

it('includes linked unit context for plus-minus troubleshooting snapshots', function (): void {
    ($this->insertGame)();
    $player = ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026020001, 8478402, ['plus_minus' => 1]);
    ($this->insertSummary)(2026020001, 8478402, ['plus_minus' => 0]);

    $playId = DB::table('play_by_plays')->insertGetId([
        'nhl_game_id' => 2026020001,
        'nhl_event_id' => '746',
        'period' => 2,
        'period_type' => 'REG',
        'time_in_period' => '14:20',
        'time_remaining' => '05:40',
        'seconds_in_period' => 860,
        'seconds_in_game' => 2060,
        'seconds_remaining' => 1540,
        'situation_code' => '1551',
        'type_code' => 505,
        'type_desc_key' => 'goal',
        'sort_order' => 510,
        'event_owner_team_id' => 1,
        'scoring_player_id' => 8478402,
        'strength' => 'EV',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $unitId = DB::table('nhl_units')->insertGetId([
        'team_abbrev' => 'TOR',
        'unit_type' => 'F',
        'composition_hash' => 'plus-minus-unit',
        'composition_player_ids' => json_encode([8478402]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('nhl_unit_players')->insert([
        'unit_id' => $unitId,
        'player_id' => $player->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $unitShiftId = DB::table('nhl_unit_shifts')->insertGetId([
        'team_id' => 1,
        'team_abbrev' => 'TOR',
        'unit_id' => $unitId,
        'nhl_game_id' => 2026020001,
        'period' => 2,
        'start_time' => '14:08',
        'end_time' => '15:24',
        'start_game_seconds' => 2048,
        'end_game_seconds' => 2124,
        'seconds' => 76,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('event_unit_shifts')->insert([
        'event_id' => $playId,
        'unit_shift_id' => $unitShiftId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $validation = app(ValidateNhlGameSummary::class)->validate(2026020001);
    $directory = (string) config('apiImportNhl.validation_troubleshooting_path');

    expect($validation->status)->toBe(NhlGameValidation::STATUS_FAILED)
        ->and(File::get($directory . '/pbp_2026020001.md'))
        ->toContain('Plus/Minus Linked Goal Context', '746', 'Test Player (8478402)', '+1');
});

it('replaces stale deltas on rerun', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026020001, 8478402, ['goals' => 2]);
    ($this->insertSummary)(2026020001, 8478402);

    app(ValidateNhlGameSummary::class)->validate(2026020001);
    DB::table('nhl_boxscores')->where('nhl_game_id', 2026020001)->update(['goals' => 1]);
    $validation = app(ValidateNhlGameSummary::class)->validate(2026020001);

    expect($validation->status)->toBe(NhlGameValidation::STATUS_APPROVED)
        ->and(DB::table('nhl_game_validation_deltas')->where('validation_id', $validation->id)->count())->toBe(0);
});

it('updates game summary time on ice and shifts after importing raw shifts', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);

    DB::table('nhl_shifts')->insert([
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8478402,
        'shift_number' => 0,
        'period' => 1,
        'start_time' => '06:18',
        'end_time' => '06:18',
        'duration' => '00:00',
        'shift_start_seconds' => 378,
        'shift_end_seconds' => 378,
        'shift_duration_seconds' => 0,
        'team_abbrev' => 'TOR',
        'team_name' => 'Toronto Maple Leafs',
        'first_name' => 'Test',
        'last_name' => 'Player',
        'event_description' => 'PPG',
        'event_details' => 'Primary Assist',
        'type_code' => 810,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $importer = new class extends ImportNhlShifts {
        public function getAPIDataFullUrl(string $url): array
        {
            return [
                'data' => [
                    [
                        'playerId' => 8478402,
                        'shiftNumber' => 1,
                        'period' => 1,
                        'startTime' => '00:00',
                        'endTime' => '00:45',
                        'duration' => '00:45',
                        'teamAbbrev' => 'TOR',
                        'teamName' => 'Toronto Maple Leafs',
                        'firstName' => 'Test',
                        'lastName' => 'Player',
                        'eventNumber' => 10,
                        'typeCode' => 517,
                    ],
                    [
                        'playerId' => 8478402,
                        'shiftNumber' => 4,
                        'period' => 1,
                        'startTime' => '00:30',
                        'endTime' => '00:45',
                        'duration' => '00:15',
                        'teamAbbrev' => 'TOR',
                        'teamName' => 'Toronto Maple Leafs',
                        'firstName' => 'Test',
                        'lastName' => 'Player',
                        'eventNumber' => 15,
                        'typeCode' => 517,
                    ],
                    [
                        'playerId' => 8478402,
                        'shiftNumber' => 2,
                        'period' => 1,
                        'startTime' => '02:00',
                        'endTime' => '02:45',
                        'duration' => '00:45',
                        'teamAbbrev' => 'TOR',
                        'teamName' => 'Toronto Maple Leafs',
                        'firstName' => 'Test',
                        'lastName' => 'Player',
                        'eventNumber' => 20,
                        'typeCode' => 517,
                    ],
                    [
                        'playerId' => 8478402,
                        'shiftNumber' => 3,
                        'period' => 1,
                        'startTime' => '02:00',
                        'endTime' => '03:00',
                        'duration' => '01:00',
                        'teamAbbrev' => 'TOR',
                        'teamName' => 'Toronto Maple Leafs',
                        'firstName' => 'Test',
                        'lastName' => 'Player',
                        'eventNumber' => 20,
                        'typeCode' => 517,
                    ],
                    [
                        'playerId' => 8478402,
                        'shiftNumber' => 5,
                        'period' => 1,
                        'startTime' => '03:59',
                        'endTime' => '04:01',
                        'duration' => '00:02',
                        'teamAbbrev' => 'TOR',
                        'teamName' => 'Toronto Maple Leafs',
                        'firstName' => 'Test',
                        'lastName' => 'Player',
                        'eventNumber' => 30,
                        'typeCode' => 517,
                    ],
                    [
                        'playerId' => 8478402,
                        'shiftNumber' => 5,
                        'period' => 2,
                        'startTime' => '00:10',
                        'endTime' => '00:40',
                        'duration' => '00:30',
                        'teamAbbrev' => 'TOR',
                        'teamName' => 'Toronto Maple Leafs',
                        'firstName' => 'Test',
                        'lastName' => 'Player',
                        'eventNumber' => 31,
                        'typeCode' => 517,
                    ],
                    [
                        'playerId' => 8478402,
                        'shiftNumber' => 0,
                        'period' => 1,
                        'startTime' => '06:18',
                        'endTime' => '06:18',
                        'duration' => '00:00',
                        'teamAbbrev' => 'TOR',
                        'teamName' => 'Toronto Maple Leafs',
                        'firstName' => 'Test',
                        'lastName' => 'Player',
                        'eventDescription' => 'PPG',
                        'eventDetails' => 'Primary Assist',
                        'typeCode' => 810,
                    ],
                ],
            ];
        }
    };

    expect($importer->import('2026020001'))->toBe(3);

    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8478402,
        'nhl_team_id' => 1,
        'toi' => 120,
        'shifts' => 3,
    ]);

    $this->assertDatabaseMissing('nhl_shifts', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8478402,
        'shift_number' => 0,
        'event_description' => 'PPG',
    ]);

    $this->assertDatabaseMissing('nhl_shifts', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8478402,
        'shift_number' => 3,
        'event_number' => 20,
    ]);

    $this->assertDatabaseMissing('nhl_shifts', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8478402,
        'shift_number' => 4,
        'event_number' => 15,
    ]);

    $this->assertDatabaseMissing('nhl_shifts', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8478402,
        'shift_number' => 5,
        'event_number' => 30,
    ]);

    $this->assertDatabaseHas('nhl_shifts', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8478402,
        'shift_number' => 2,
        'event_number' => 20,
        'shift_end_seconds' => 165,
    ]);

    $this->assertDatabaseHas('nhl_shifts', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8478402,
        'shift_number' => 5,
        'event_number' => 31,
        'shift_end_seconds' => 1240,
    ]);
});

it('reconciles shiftchart artifacts against boxscore shift targets when available', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8476473, [
        'first_name' => 'Connor',
        'last_name' => 'Murphy',
        'full_name' => 'Connor Murphy',
    ]);
    ($this->makePlayer)(8475718, [
        'first_name' => 'Justin',
        'last_name' => 'Holl',
        'full_name' => 'Justin Holl',
    ]);
    ($this->insertBoxscore)(2026020001, 8476473, [
        'toi' => '01:21',
        'toi_seconds' => 81,
        'shifts' => 2,
    ]);
    ($this->insertBoxscore)(2026020001, 8475718, [
        'toi' => '01:00',
        'toi_seconds' => 60,
        'shifts' => 1,
    ]);

    $importer = new class extends ImportNhlShifts {
        public function getAPIDataFullUrl(string $url): array
        {
            return [
                'data' => [
                    [
                        'playerId' => 8476473,
                        'shiftNumber' => 1,
                        'period' => 3,
                        'startTime' => '18:00',
                        'endTime' => '19:00',
                        'duration' => '01:00',
                        'teamAbbrev' => 'TOR',
                        'teamName' => 'Toronto Maple Leafs',
                        'firstName' => 'Connor',
                        'lastName' => 'Murphy',
                        'eventNumber' => 900,
                        'typeCode' => 517,
                    ],
                    [
                        'playerId' => 8476473,
                        'shiftNumber' => 2,
                        'period' => 3,
                        'startTime' => '19:29',
                        'endTime' => '20:00',
                        'duration' => '00:31',
                        'teamAbbrev' => 'TOR',
                        'teamName' => 'Toronto Maple Leafs',
                        'firstName' => 'Connor',
                        'lastName' => 'Murphy',
                        'eventNumber' => 1026,
                        'typeCode' => 517,
                    ],
                    [
                        'playerId' => 8476473,
                        'shiftNumber' => 3,
                        'period' => 3,
                        'startTime' => '19:39',
                        'endTime' => '20:00',
                        'duration' => '00:21',
                        'teamAbbrev' => 'TOR',
                        'teamName' => 'Toronto Maple Leafs',
                        'firstName' => 'Connor',
                        'lastName' => 'Murphy',
                        'eventNumber' => 1020,
                        'typeCode' => 517,
                    ],
                    [
                        'playerId' => 8475718,
                        'shiftNumber' => 1,
                        'period' => 3,
                        'startTime' => '18:00',
                        'endTime' => '19:00',
                        'duration' => '01:00',
                        'teamAbbrev' => 'TOR',
                        'teamName' => 'Toronto Maple Leafs',
                        'firstName' => 'Justin',
                        'lastName' => 'Holl',
                        'eventNumber' => 900,
                        'typeCode' => 517,
                    ],
                    [
                        'playerId' => 8475718,
                        'shiftNumber' => 2,
                        'period' => 3,
                        'startTime' => '19:08',
                        'endTime' => '19:11',
                        'duration' => '00:03',
                        'teamAbbrev' => 'TOR',
                        'teamName' => 'Toronto Maple Leafs',
                        'firstName' => 'Justin',
                        'lastName' => 'Holl',
                        'eventNumber' => 1024,
                        'typeCode' => 517,
                    ],
                    [
                        'playerId' => 8475718,
                        'shiftNumber' => 3,
                        'period' => 3,
                        'startTime' => '19:15',
                        'endTime' => '19:24',
                        'duration' => '00:09',
                        'teamAbbrev' => 'TOR',
                        'teamName' => 'Toronto Maple Leafs',
                        'firstName' => 'Justin',
                        'lastName' => 'Holl',
                        'eventNumber' => 1025,
                        'typeCode' => 517,
                    ],
                    [
                        'playerId' => 8475718,
                        'shiftNumber' => 4,
                        'period' => 3,
                        'startTime' => '19:52',
                        'endTime' => '20:00',
                        'duration' => '00:08',
                        'teamAbbrev' => 'TOR',
                        'teamName' => 'Toronto Maple Leafs',
                        'firstName' => 'Justin',
                        'lastName' => 'Holl',
                        'eventNumber' => 1028,
                        'typeCode' => 517,
                    ],
                ],
            ];
        }
    };

    expect($importer->import('2026020001'))->toBe(3);

    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8476473,
        'toi' => 81,
        'shifts' => 2,
    ]);
    $this->assertDatabaseHas('nhl_shifts', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8476473,
        'shift_number' => 3,
        'event_number' => 1020,
        'shift_duration_seconds' => 21,
    ]);
    $this->assertDatabaseMissing('nhl_shifts', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8476473,
        'shift_number' => 2,
        'event_number' => 1026,
    ]);

    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8475718,
        'toi' => 60,
        'shifts' => 1,
    ]);
    foreach ([1024, 1025, 1028] as $eventNumber) {
        $this->assertDatabaseMissing('nhl_shifts', [
            'nhl_game_id' => 2026020001,
            'nhl_player_id' => 8475718,
            'event_number' => $eventNumber,
        ]);
    }
});

it('drops a thirty-second-or-less shift artifact when it exactly matches boxscore overage', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8477456, [
        'first_name' => 'J.T.',
        'last_name' => 'Compher',
        'full_name' => 'J.T. Compher',
    ]);
    ($this->insertBoxscore)(2026020001, 8477456, [
        'toi' => '11:05',
        'toi_seconds' => 665,
        'shifts' => 16,
    ]);

    $importer = new class extends ImportNhlShifts {
        public function getAPIDataFullUrl(string $url): array
        {
            $rows = [];
            $rows[] = $this->shift(1, 1, '00:00', '00:39', '00:39', 54);
            $rows[] = $this->shift(2, 1, '02:43', '03:19', '00:36', 135);
            $rows[] = $this->shift(3, 1, '05:58', '07:12', '01:14', 188);
            $rows[] = $this->shift(4, 1, '13:27', '14:11', '00:44', 332);
            $rows[] = $this->shift(5, 1, '18:28', '18:47', '00:19', 455);
            $rows[] = $this->shift(6, 2, '02:51', '03:25', '00:34', 613);
            $rows[] = $this->shift(7, 2, '04:34', '05:09', '00:35', 633);
            $rows[] = $this->shift(8, 2, '06:19', '07:08', '00:49', 646);
            $rows[] = $this->shift(9, 2, '11:08', '12:32', '01:24', 700);
            $rows[] = $this->shift(10, 2, '16:19', '17:12', '00:53', 756);
            $rows[] = $this->shift(11, 2, '19:36', '20:00', '00:24', 855);
            $rows[] = $this->shift(12, 3, '00:00', '00:46', '00:46', 860);
            $rows[] = $this->shift(13, 3, '02:52', '03:33', '00:41', 894);
            $rows[] = $this->shift(14, 3, '11:37', '12:37', '01:00', 1050);
            $rows[] = $this->shift(15, 3, '16:18', '16:36', '00:18', 1103);
            $rows[] = $this->shift(16, 3, '16:37', '17:06', '00:29', 1114);
            $rows[] = $this->shift(17, 4, '01:43', '01:52', '00:09', 1177);

            return ['data' => $rows];
        }

        /**
         * @return array<string, mixed>
         */
        private function shift(int $shiftNumber, int $period, string $start, string $end, string $duration, int $eventNumber): array
        {
            return [
                'playerId' => 8477456,
                'shiftNumber' => $shiftNumber,
                'period' => $period,
                'startTime' => $start,
                'endTime' => $end,
                'duration' => $duration,
                'teamAbbrev' => 'DET',
                'teamName' => 'Detroit Red Wings',
                'firstName' => 'J.T.',
                'lastName' => 'Compher',
                'eventNumber' => $eventNumber,
                'typeCode' => 517,
            ];
        }
    };

    expect($importer->import('2026020001'))->toBe(16);
    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8477456,
        'toi' => 665,
        'shifts' => 16,
    ]);
    $this->assertDatabaseMissing('nhl_shifts', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8477456,
        'event_number' => 1114,
    ]);
});

it('drops a thirty-two-second shift artifact when official targets exactly prove the overage', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8477021, [
        'first_name' => 'Alexander',
        'last_name' => 'Kerfoot',
        'full_name' => 'Alexander Kerfoot',
    ]);
    ($this->insertBoxscore)(2026020001, 8477021, [
        'toi' => '00:40',
        'toi_seconds' => 40,
        'shifts' => 1,
    ]);

    $importer = new class extends ImportNhlShifts {
        public function getAPIDataFullUrl(string $url): array
        {
            return [
                'data' => [
                    $this->shift(1, '00:00', '00:40', '00:40', 10),
                    $this->shift(2, '19:28', '20:00', '00:32', 436),
                ],
            ];
        }

        /**
         * @return array<string, mixed>
         */
        private function shift(int $shiftNumber, string $start, string $end, string $duration, int $eventNumber): array
        {
            return [
                'playerId' => 8477021,
                'shiftNumber' => $shiftNumber,
                'period' => 1,
                'startTime' => $start,
                'endTime' => $end,
                'duration' => $duration,
                'teamAbbrev' => 'UTA',
                'teamName' => 'Utah Mammoth',
                'firstName' => 'Alexander',
                'lastName' => 'Kerfoot',
                'eventNumber' => $eventNumber,
                'typeCode' => 517,
            ];
        }
    };

    expect($importer->import('2026020001'))->toBe(1);
    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8477021,
        'toi' => 40,
        'shifts' => 1,
    ]);
    $this->assertDatabaseMissing('nhl_shifts', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8477021,
        'event_number' => 436,
    ]);
});

it('documents validate-summary in the canonical stage order', function (): void {
    expect(NhlImportStages::ordered())->toContain(NhlImportStages::VALIDATE_SUMMARY);
});

it('places shifts after boxscore so shifts can reconcile against official targets', function (): void {
    expect(NhlImportStages::nextAfter(NhlImportStages::BOXSCORE))->toBe(NhlImportStages::SHIFTS)
        ->and(NhlImportStages::nextAfter(NhlImportStages::SHIFTS))->toBe(NhlImportStages::SHIFT_UNITS);
});

it('places validate-summary after game unit aggregation', function (): void {
    expect(NhlImportStages::nextAfter(NhlImportStages::SUM_GAME_UNITS))->toBe(NhlImportStages::VALIDATE_SUMMARY);
});

it('requires upstream imports before validate-summary can run', function (): void {
    expect(NhlImportStages::dependenciesFor(NhlImportStages::VALIDATE_SUMMARY))->toBe([
        NhlImportStages::PBP,
        NhlImportStages::SUMMARY,
        NhlImportStages::BOXSCORE,
        NhlImportStages::SHIFTS,
        NhlImportStages::SHIFT_UNITS,
        NhlImportStages::CONNECT_EVENTS,
        NhlImportStages::SUM_GAME_UNITS,
    ]);
});

it('does not require validate-summary before shift-units can run', function (): void {
    expect(NhlImportStages::dependenciesFor(NhlImportStages::SHIFT_UNITS))
        ->not->toContain(NhlImportStages::VALIDATE_SUMMARY);
});

it('maps validate-summary to its queue job and timeout config', function (): void {
    expect(NhlImportStages::jobClassFor(NhlImportStages::VALIDATE_SUMMARY))->toBe(ValidateNhlGameSummaryJob::class)
        ->and(NhlImportStages::timeoutConfigKeyFor(NhlImportStages::VALIDATE_SUMMARY))
        ->toBe('apiImportNhl.max_validate_summary_seconds');
});

it('marks shift-units ready once upstream imports are completed', function (): void {
    ($this->insertPipeline)(2026020001, [
        NhlImportStages::PBP => 'completed',
        NhlImportStages::SUMMARY => 'completed',
        NhlImportStages::SHIFTS => 'completed',
        NhlImportStages::BOXSCORE => 'completed',
    ]);

    expect(app(NhlImportOrchestrator::class)->readyFor(2026020001, NhlImportStages::SHIFT_UNITS))->toBeTrue();
});

it('does not mark validation ready until game unit aggregation completes', function (): void {
    ($this->insertPipeline)(2026020001, [
        NhlImportStages::PBP => 'completed',
        NhlImportStages::SUMMARY => 'completed',
        NhlImportStages::SHIFTS => 'completed',
        NhlImportStages::BOXSCORE => 'completed',
        NhlImportStages::SHIFT_UNITS => 'completed',
        NhlImportStages::CONNECT_EVENTS => 'completed',
        NhlImportStages::SUM_GAME_UNITS => 'running',
    ]);

    expect(app(NhlImportOrchestrator::class)->readyFor(2026020001, NhlImportStages::VALIDATE_SUMMARY))->toBeFalse();
});

it('dispatches validation after game unit aggregation completes', function (): void {
    Bus::fake();
    ($this->insertPipeline)(2026020001, [
        NhlImportStages::PBP => 'completed',
        NhlImportStages::SUMMARY => 'completed',
        NhlImportStages::SHIFTS => 'completed',
        NhlImportStages::BOXSCORE => 'completed',
        NhlImportStages::SHIFT_UNITS => 'completed',
        NhlImportStages::CONNECT_EVENTS => 'completed',
        NhlImportStages::SUM_GAME_UNITS => 'completed',
    ]);

    app(NhlImportOrchestrator::class)->advance(2026020001, NhlImportStages::SUM_GAME_UNITS);

    Bus::assertDispatched(ValidateNhlGameSummaryJob::class);
});

it('blocks guests from validation triage', function (): void {
    $this->getJson(route('admin.nhl-validations.index'))->assertUnauthorized();
});

it('blocks non-admin users from validation triage', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson(route('admin.nhl-validations.index'))
        ->assertForbidden();
});

it('returns validation triage JSON to super admins', function (): void {
    ($this->insertGame)();
    NhlGameValidation::create([
        'nhl_game_id' => 2026020001,
        'validation_type' => NhlGameValidation::TYPE_SUMMARY_BOXSCORE,
        'status' => NhlGameValidation::STATUS_FAILED,
        'mismatch_count' => 2,
        'checked_at' => now(),
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.nhl-validations.index'))
        ->assertOk()
        ->assertJsonPath('data.0.nhl_game_id', 2026020001);
});

it('returns embedded validation triage HTML to super admins', function (): void {
    ($this->insertGame)();
    $validation = NhlGameValidation::create([
        'nhl_game_id' => 2026020001,
        'validation_type' => NhlGameValidation::TYPE_SUMMARY_BOXSCORE,
        'status' => NhlGameValidation::STATUS_FAILED,
        'mismatch_count' => 2,
        'checked_at' => now(),
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.nhl-validations.index', ['admin_panel' => 1]))
        ->assertOk()
        ->assertJsonStructure(['html'])
        ->assertSee('2026020001')
        ->assertSee('data-validation-toggle')
        ->assertSee('data-validation-id="'.$validation->id.'"', false)
        ->assertSee(route('admin.nhl-validations.show', ['validation' => $validation->id, 'admin_panel' => 1]), false)
        ->assertDontSee('Review');
});

it('shows validation deltas as JSON to super admins', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    $validation = NhlGameValidation::create([
        'nhl_game_id' => 2026020001,
        'validation_type' => NhlGameValidation::TYPE_SUMMARY_BOXSCORE,
        'status' => NhlGameValidation::STATUS_FAILED,
        'mismatch_count' => 1,
        'checked_at' => now(),
    ]);
    $validation->deltas()->create([
        'nhl_player_id' => 8478402,
        'field' => 'goals',
        'boxscore_value' => '2',
        'summary_value' => '1',
        'delta' => -1,
        'severity' => NhlGameValidationDelta::SEVERITY_ERROR,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.nhl-validations.show', $validation))
        ->assertOk()
        ->assertJsonPath('deltas.0.field', 'goals');
});

it('returns embedded validation detail HTML to super admins', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    $validation = NhlGameValidation::create([
        'nhl_game_id' => 2026020001,
        'validation_type' => NhlGameValidation::TYPE_SUMMARY_BOXSCORE,
        'status' => NhlGameValidation::STATUS_FAILED,
        'mismatch_count' => 1,
        'checked_at' => now(),
    ]);
    $validation->deltas()->create([
        'nhl_player_id' => 8478402,
        'field' => 'goals',
        'boxscore_value' => '2',
        'summary_value' => '1',
        'delta' => -1,
        'severity' => NhlGameValidationDelta::SEVERITY_ERROR,
    ]);
    DB::table('play_by_plays')->insert([
        'nhl_game_id' => 2026020001,
        'nhl_event_id' => '77',
        'period' => 1,
        'period_type' => 'REG',
        'time_in_period' => '06:43',
        'time_remaining' => '13:17',
        'seconds_in_period' => 403,
        'seconds_in_game' => 403,
        'seconds_remaining' => 797,
        'type_code' => 505,
        'type_desc_key' => 'goal',
        'sort_order' => 88,
        'event_owner_team_id' => 1,
        'scoring_player_id' => 8478402,
        'strength' => 'EV',
        'metadata' => json_encode([
            'event' => ['typeCode' => 505],
            'details' => ['awaySOG' => 1, 'homeSOG' => 2],
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.nhl-validations.show', ['validation' => $validation->id, 'admin_panel' => 1]))
        ->assertOk()
        ->assertJsonPath('id', $validation->id)
        ->assertJsonStructure(['html'])
        ->assertSee('goals')
        ->assertSee('Test Player')
        ->assertSee('NHL ID 8478402')
        ->assertSee('PBP context')
        ->assertSee('Event 77')
        ->assertSee('SOG away 1');
});

it('accepts a validation exception without dispatching unit work', function (): void {
    Bus::fake();
    ($this->insertGame)();
    ($this->insertPipeline)(2026020001, [
        NhlImportStages::PBP => 'completed',
        NhlImportStages::SUMMARY => 'completed',
        NhlImportStages::SHIFTS => 'completed',
        NhlImportStages::BOXSCORE => 'completed',
        NhlImportStages::VALIDATE_SUMMARY => 'error',
    ]);
    $validation = NhlGameValidation::create([
        'nhl_game_id' => 2026020001,
        'validation_type' => NhlGameValidation::TYPE_SUMMARY_BOXSCORE,
        'status' => NhlGameValidation::STATUS_FAILED,
        'mismatch_count' => 1,
        'checked_at' => now(),
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-validations.accept-exception', $validation))
        ->assertOk()
        ->assertJsonPath('status', NhlGameValidation::STATUS_ACCEPTED_EXCEPTION);

    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026020001',
        'import_type' => NhlImportStages::VALIDATE_SUMMARY,
        'status' => 'completed',
    ]);
    Bus::assertNotDispatched(MakeShiftUnitsNhlJob::class);
});

it('reruns validation and keeps failed validation progress in error', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026020001, 8478402, ['goals' => 2]);
    ($this->insertSummary)(2026020001, 8478402);
    ($this->insertProgress)(2026020001, NhlImportStages::VALIDATE_SUMMARY, 'error');
    $validation = app(ValidateNhlGameSummary::class)->validate(2026020001);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-validations.rerun', $validation))
        ->assertOk()
        ->assertJsonPath('status', NhlGameValidation::STATUS_FAILED);

    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026020001',
        'import_type' => NhlImportStages::VALIDATE_SUMMARY,
        'status' => 'error',
    ]);
});

it('reruns validation without dispatching unit work when totals now match', function (): void {
    Bus::fake();
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026020001, 8478402);
    ($this->insertSummary)(2026020001, 8478402);
    ($this->insertPipeline)(2026020001, [
        NhlImportStages::PBP => 'completed',
        NhlImportStages::SUMMARY => 'completed',
        NhlImportStages::SHIFTS => 'completed',
        NhlImportStages::BOXSCORE => 'completed',
        NhlImportStages::VALIDATE_SUMMARY => 'error',
    ]);
    $validation = NhlGameValidation::create([
        'nhl_game_id' => 2026020001,
        'validation_type' => NhlGameValidation::TYPE_SUMMARY_BOXSCORE,
        'status' => NhlGameValidation::STATUS_FAILED,
        'mismatch_count' => 1,
        'checked_at' => now(),
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-validations.rerun', $validation))
        ->assertOk()
        ->assertJsonPath('status', NhlGameValidation::STATUS_APPROVED);

    Bus::assertNotDispatched(MakeShiftUnitsNhlJob::class);
});

it('queues a summary rerun from validation triage', function (): void {
    Bus::fake();
    ($this->insertGame)();
    ($this->insertProgress)(2026020001, NhlImportStages::SUMMARY, 'error');
    $validation = NhlGameValidation::create([
        'nhl_game_id' => 2026020001,
        'validation_type' => NhlGameValidation::TYPE_SUMMARY_BOXSCORE,
        'status' => NhlGameValidation::STATUS_FAILED,
        'mismatch_count' => 1,
        'checked_at' => now(),
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-validations.rerun-summary', $validation))
        ->assertOk()
        ->assertJsonPath('status', 'summary_queued');

    Bus::assertDispatched(SummarizePbpNhlJob::class);
});

it('queues a boxscore rerun from validation triage', function (): void {
    Bus::fake();
    ($this->insertGame)();
    ($this->insertProgress)(2026020001, NhlImportStages::BOXSCORE, 'error');
    $validation = NhlGameValidation::create([
        'nhl_game_id' => 2026020001,
        'validation_type' => NhlGameValidation::TYPE_SUMMARY_BOXSCORE,
        'status' => NhlGameValidation::STATUS_FAILED,
        'mismatch_count' => 1,
        'checked_at' => now(),
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-validations.rerun-boxscore', $validation))
        ->assertOk()
        ->assertJsonPath('status', 'boxscore_queued');

    Bus::assertDispatched(ImportBoxscoreNhlJob::class);
});

it('rejects unsupported play-by-play game types before storing events', function (): void {
    $importer = new class extends ImportNHLPlayByPlay {
        public function getAPIData(
            string $service,
            string $endpointKey,
            array $replacements = [],
            array $query = [],
            bool $decodeJson = true
        ): array|string {
            return [
                'season' => 20262027,
                'gameType' => 9,
                'gameDate' => '2026-10-01',
                'homeTeam' => ['id' => 1, 'score' => 0, 'sog' => 0, 'abbrev' => 'TOR'],
                'awayTeam' => ['id' => 2, 'score' => 0, 'sog' => 0, 'abbrev' => 'MTL'],
                'plays' => [
                    [
                        'eventId' => 1,
                        'periodDescriptor' => ['number' => 1, 'periodType' => 'REG'],
                        'timeInPeriod' => '00:01',
                        'timeRemaining' => '19:59',
                        'typeCode' => 506,
                        'typeDescKey' => 'shot-on-goal',
                        'sortOrder' => 1,
                        'details' => [
                            'eventOwnerTeamId' => 1,
                            'shootingPlayerId' => 8478402,
                        ],
                    ],
                ],
            ];
        }
    };

    expect(fn () => $importer->import(2026020001))
        ->toThrow(\DomainException::class, 'Unsupported NHL game type 9');

    $this->assertDatabaseCount('play_by_plays', 0);
    $this->assertDatabaseMissing('nhl_games', ['nhl_game_id' => 2026020001]);
});

it('rebuilds a validation game by clearing game-scoped imports and requeueing from pbp', function (): void {
    Bus::fake();
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    ($this->insertPipeline)(2026020001, [
        NhlImportStages::PBP => 'completed',
        NhlImportStages::SUMMARY => 'completed',
        NhlImportStages::BOXSCORE => 'completed',
        NhlImportStages::SHIFTS => 'completed',
        NhlImportStages::SHIFT_UNITS => 'completed',
        NhlImportStages::CONNECT_EVENTS => 'completed',
        NhlImportStages::SUM_GAME_UNITS => 'completed',
        NhlImportStages::VALIDATE_SUMMARY => 'error',
    ]);

    $playId = DB::table('play_by_plays')->insertGetId([
        'nhl_game_id' => 2026020001,
        'nhl_event_id' => 55,
        'period' => 1,
        'time_in_period' => '00:08',
        'type_desc_key' => 'goal',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    ($this->insertBoxscore)(2026020001, 8478402);
    ($this->insertSummary)(2026020001, 8478402);

    DB::table('nhl_shifts')->insert([
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8478402,
        'shift_number' => 1,
        'period' => 1,
        'start_time' => '00:00',
        'end_time' => '00:30',
        'duration' => '00:30',
        'shift_start_seconds' => 0,
        'shift_end_seconds' => 30,
        'shift_duration_seconds' => 30,
        'team_abbrev' => 'TOR',
        'team_name' => 'Toronto Maple Leafs',
        'first_name' => 'Test',
        'last_name' => 'Player',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $unitId = DB::table('nhl_units')->insertGetId([
        'team_abbrev' => 'TOR',
        'unit_type' => 'F',
        'composition_hash' => 'test-unit',
        'composition_player_ids' => json_encode([8478402]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $unitShiftId = DB::table('nhl_unit_shifts')->insertGetId([
        'team_id' => 1,
        'team_abbrev' => 'TOR',
        'unit_id' => $unitId,
        'nhl_game_id' => 2026020001,
        'period' => 1,
        'start_time' => '00:00',
        'end_time' => '00:30',
        'start_game_seconds' => 0,
        'end_game_seconds' => 30,
        'seconds' => 30,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('event_unit_shifts')->insert([
        'event_id' => $playId,
        'unit_shift_id' => $unitShiftId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('nhl_unit_game_summaries')->insert([
        'nhl_game_id' => 2026020001,
        'unit_id' => $unitId,
        'team_id' => 1,
        'team_abbrev' => 'TOR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $validation = NhlGameValidation::create([
        'nhl_game_id' => 2026020001,
        'validation_type' => NhlGameValidation::TYPE_SUMMARY_BOXSCORE,
        'status' => NhlGameValidation::STATUS_FAILED,
        'mismatch_count' => 1,
        'checked_at' => now(),
    ]);
    NhlGameValidationDelta::create([
        'validation_id' => $validation->id,
        'nhl_player_id' => 8478402,
        'field' => 'sog',
        'boxscore_value' => '1',
        'summary_value' => '2',
        'delta' => 1,
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-validations.rebuild-game', $validation))
        ->assertOk()
        ->assertJsonPath('status', 'game_rebuild_queued');

    foreach ([
        'event_unit_shifts',
        'nhl_unit_game_summaries',
        'nhl_unit_shifts',
        'nhl_shifts',
        'nhl_game_validation_deltas',
        'nhl_game_validations',
        'nhl_boxscores',
        'nhl_game_summaries',
        'play_by_plays',
    ] as $table) {
        $this->assertDatabaseCount($table, 0);
    }

    $this->assertDatabaseHas('nhl_units', ['id' => $unitId]);
    $this->assertDatabaseCount('nhl_import_progress', count(NhlImportStages::ordered()));
    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026020001',
        'import_type' => NhlImportStages::PBP,
        'status' => 'running',
    ]);
    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026020001',
        'import_type' => NhlImportStages::SHIFTS,
        'status' => 'scheduled',
    ]);

    Bus::assertDispatched(ImportPbpNhlJob::class);
});
