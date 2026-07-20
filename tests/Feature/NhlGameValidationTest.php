<?php

declare(strict_types=1);

use App\Jobs\ImportBoxscoreNhlJob;
use App\Jobs\ImportPbpNhlJob;
use App\Jobs\ImportShiftsNhlJob;
use App\Jobs\MakeShiftUnitsNhlJob;
use App\Jobs\BaseNhlJob;
use App\Jobs\PreflightNhlGameImportRebuildJob;
use App\Jobs\RebuildNhlGameImportJob;
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
use App\Services\NhlGameImportEligibility;
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
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-29 12:00:00');
    Config::set('apiImportNhl.validation_troubleshooting_path', sys_get_temp_dir() . '/dynastyiq-validation-troubleshooting-tests');
    File::deleteDirectory((string) config('apiImportNhl.validation_troubleshooting_path'));
    Http::fake([
        '*' => Http::response([]),
    ]);

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

    $this->insertGame = static function (int $gameId = 2026020001, array $overrides = []): void {
        DB::table('nhl_games')->insert(array_merge([
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
        ], $overrides));
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
            'power_play_assists' => 0,
            'short_handed_goals' => 0,
            'short_handed_assists' => 0,
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
            'ppa' => 0,
            'pkg' => 0,
            'pka' => 0,
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

it('tolerates the boxscore penalty-minute gap for committed match penalties', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8477149, [
        'first_name' => 'Scott',
        'last_name' => 'Sabourin',
        'full_name' => 'Scott Sabourin',
    ]);
    ($this->insertBoxscore)(2026020001, 8477149, ['penalty_minutes' => 5]);
    ($this->insertSummary)(2026020001, 8477149, ['pim' => 15]);

    DB::table('play_by_plays')->insert([
        'nhl_game_id' => 2026020001,
        'nhl_event_id' => '83',
        'period' => 1,
        'period_type' => 'REG',
        'time_in_period' => '02:18',
        'time_remaining' => '17:42',
        'seconds_in_period' => 138,
        'seconds_in_game' => 138,
        'seconds_remaining' => 1062,
        'type_desc_key' => 'penalty',
        'desc_key' => 'match-penalty',
        'sort_order' => 83,
        'committed_by_player_id' => 8477149,
        'duration' => 5,
        'penalty_type_code' => 'MAT',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(collect(app(CompareNhlPbPBoxscore::class)->compare(2026020001))->pluck('field')->all())
        ->not->toContain('penalty_minutes');
});

it('keeps ordinary penalty-minute mismatches blocking without a committed match penalty', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8477416, [
        'first_name' => 'Oliver',
        'last_name' => 'Bjorkstrand',
        'full_name' => 'Oliver Bjorkstrand',
    ]);
    ($this->insertBoxscore)(2026020001, 8477416, ['penalty_minutes' => 7]);
    ($this->insertSummary)(2026020001, 8477416, ['pim' => 17]);

    DB::table('play_by_plays')->insert([
        'nhl_game_id' => 2026020001,
        'nhl_event_id' => '83',
        'period' => 1,
        'period_type' => 'REG',
        'time_in_period' => '02:18',
        'time_remaining' => '17:42',
        'seconds_in_period' => 138,
        'seconds_in_game' => 138,
        'seconds_remaining' => 1062,
        'type_desc_key' => 'penalty',
        'desc_key' => 'match-penalty',
        'sort_order' => 83,
        'committed_by_player_id' => 8477149,
        'duration' => 5,
        'penalty_type_code' => 'MAT',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(collect(app(CompareNhlPbPBoxscore::class)->compare(2026020001))->pluck('field')->all())
        ->toContain('penalty_minutes');
});

it('does not validate skater special teams fields absent from the NHL boxscore feed', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026020001, 8478402, [
        'power_play_assists' => 2,
        'short_handed_goals' => 1,
        'short_handed_assists' => 1,
    ]);
    ($this->insertSummary)(2026020001, 8478402);

    expect(collect(app(CompareNhlPbPBoxscore::class)->compare(2026020001))->pluck('field')->all())
        ->not->toContain(
            'power_play_assists',
            'short_handed_goals',
            'short_handed_assists'
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

it('uses penalty shot penalty situation to classify same-clock goal strength', function (): void {
    ($this->makePlayer)(8478398, [
        'first_name' => 'Kyle',
        'last_name' => 'Connor',
        'full_name' => 'Kyle Connor',
        'team_abbrev' => 'WPG',
    ]);
    ($this->makePlayer)(8481668, [
        'first_name' => 'Arturs',
        'last_name' => 'Silovs',
        'full_name' => 'Arturs Silovs',
        'position' => 'G',
        'team_abbrev' => 'PIT',
    ]);

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
                'homeTeam' => ['id' => 52, 'score' => 4, 'sog' => 20, 'abbrev' => 'WPG'],
                'awayTeam' => ['id' => 5, 'score' => 0, 'sog' => 10, 'abbrev' => 'PIT'],
                'plays' => [
                    [
                        'eventId' => 31,
                        'periodDescriptor' => ['number' => 2, 'periodType' => 'REG'],
                        'timeInPeriod' => '12:13',
                        'timeRemaining' => '07:47',
                        'situationCode' => '1541',
                        'typeCode' => 509,
                        'typeDescKey' => 'penalty',
                        'sortOrder' => 398,
                        'details' => [
                            'typeCode' => 'PS',
                            'descKey' => 'ps-throwing-object-at-puck',
                            'duration' => 0,
                            'committedByPlayerId' => 8481668,
                            'eventOwnerTeamId' => 5,
                        ],
                    ],
                    [
                        'eventId' => 160,
                        'periodDescriptor' => ['number' => 2, 'periodType' => 'REG'],
                        'timeInPeriod' => '12:13',
                        'timeRemaining' => '07:47',
                        'situationCode' => '1010',
                        'typeCode' => 505,
                        'typeDescKey' => 'goal',
                        'sortOrder' => 402,
                        'details' => [
                            'xCoord' => 76,
                            'yCoord' => 0,
                            'zoneCode' => 'O',
                            'shotType' => 'backhand',
                            'scoringPlayerId' => 8478398,
                            'scoringPlayerTotal' => 7,
                            'eventOwnerTeamId' => 52,
                            'goalieInNetId' => 8481668,
                            'awayScore' => 0,
                            'homeScore' => 4,
                        ],
                    ],
                ],
            ];
        }
    };

    expect($importer->import(2026020001))->toBe(2);

    $goal = \App\Models\PlayByPlay::where('nhl_game_id', 2026020001)
        ->where('nhl_event_id', 160)
        ->firstOrFail();

    expect($goal->situation_code)->toBe('1010')
        ->and($goal->strength)->toBe('PK');

    expect(app(SumNHLPlayByPlay::class)->summarize(2026020001))->toBeGreaterThan(0);

    $goalieSummary = DB::table('nhl_game_summaries')
        ->where('nhl_game_id', 2026020001)
        ->where('nhl_player_id', '8481668')
        ->first();

    expect($goalieSummary)->not->toBeNull()
        ->and((int) $goalieSummary->evga)->toBe(0)
        ->and((int) $goalieSummary->pkga)->toBe(1);
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
            'shot_type' => 'wrist',
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
            'goalie_in_net_player_id' => null,
            'shot_type' => null,
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
        'sog' => 2,
        'evsog' => 2,
        'sat' => 2,
        'evsat' => 2,
    ]);
    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8474593,
        'ga' => 2,
        'evga' => 2,
        'sa' => 2,
        'evsa' => 2,
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
        'shot_type' => 'wrist',
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
        'goals' => 1,
        'assists' => 1,
        'points' => 2,
        'sog' => 1,
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
        'evsa' => 14,
        'ppsv' => 6,
        'ppsa' => 6,
        'pksv' => 2,
        'pksa' => 2,
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

it('does not validate offensive goalie fields absent from the NHL boxscore goalie feed', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402, ['position' => 'G']);
    ($this->insertBoxscore)(2026020001, 8478402, [
        'position' => 'G',
        'goals' => 0,
        'assists' => 0,
        'points' => 0,
        'sog' => 0,
    ]);
    ($this->insertSummary)(2026020001, 8478402, [
        'g' => 1,
        'a' => 1,
        'pts' => 2,
        'sog' => 1,
    ]);

    expect(collect(app(CompareNhlPbPBoxscore::class)->compare(2026020001))->pluck('field')->all())
        ->not->toContain('goals', 'assists', 'points', 'sog');
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

it('clears game-scoped source and derived rows before a reprocess PBP stage', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);

    DB::table('nhl_game_import_runs')->insert([
        'id' => 777,
        'action' => 'discover',
        'mode' => 'range',
        'status' => 'running',
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-01',
        'date_count' => 1,
        'queued_jobs' => 1,
        'payload' => json_encode(['reprocess_existing' => true]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('nhl_import_progress')->insert([
        'run_id' => 777,
        'season_id' => '20262027',
        'game_date' => '2026-10-01',
        'game_id' => '2026020001',
        'game_type' => 2,
        'import_type' => NhlImportStages::PBP,
        'items_count' => 0,
        'status' => 'running',
        'discovered_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('play_by_plays')->insert([
        'nhl_game_id' => 2026020001,
        'nhl_event_id' => 'stale-event',
        'type_desc_key' => 'goal',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    ($this->insertBoxscore)(2026020001, 8478402);
    ($this->insertSummary)(2026020001, 8478402);

    $job = new class(2026020001) extends BaseNhlJob {
        protected function stageName(): string
        {
            return NhlImportStages::PBP;
        }

        protected function perform(int $gameId): int
        {
            return DB::table('play_by_plays')->where('nhl_game_id', $gameId)->count()
                + DB::table('nhl_boxscores')->where('nhl_game_id', $gameId)->count()
                + DB::table('nhl_game_summaries')->where('nhl_game_id', $gameId)->count();
        }
    };

    $job->handle(app(NhlImportOrchestrator::class), app(NhlGameImportEligibility::class));

    expect(DB::table('play_by_plays')->where('nhl_game_id', 2026020001)->count())->toBe(0)
        ->and(DB::table('nhl_boxscores')->where('nhl_game_id', 2026020001)->count())->toBe(0)
        ->and(DB::table('nhl_game_summaries')->where('nhl_game_id', 2026020001)->count())->toBe(0)
        ->and(DB::table('nhl_import_progress')->where('game_id', '2026020001')->where('import_type', NhlImportStages::PBP)->value('items_count'))->toBe(0);
});

it('keeps normal PBP stage processing idempotent without reprocess cleanup', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);

    DB::table('nhl_import_progress')->insert([
        'season_id' => '20262027',
        'game_date' => '2026-10-01',
        'game_id' => '2026020001',
        'game_type' => 2,
        'import_type' => NhlImportStages::PBP,
        'items_count' => 0,
        'status' => 'running',
        'discovered_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('play_by_plays')->insert([
        'nhl_game_id' => 2026020001,
        'nhl_event_id' => 'existing-event',
        'type_desc_key' => 'goal',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    ($this->insertBoxscore)(2026020001, 8478402);
    ($this->insertSummary)(2026020001, 8478402);

    $job = new class(2026020001) extends BaseNhlJob {
        protected function stageName(): string
        {
            return NhlImportStages::PBP;
        }

        protected function perform(int $gameId): int
        {
            return DB::table('play_by_plays')->where('nhl_game_id', $gameId)->count()
                + DB::table('nhl_boxscores')->where('nhl_game_id', $gameId)->count()
                + DB::table('nhl_game_summaries')->where('nhl_game_id', $gameId)->count();
        }
    };

    $job->handle(app(NhlImportOrchestrator::class), app(NhlGameImportEligibility::class));

    expect(DB::table('play_by_plays')->where('nhl_game_id', 2026020001)->count())->toBe(1)
        ->and(DB::table('nhl_boxscores')->where('nhl_game_id', 2026020001)->count())->toBe(1)
        ->and(DB::table('nhl_game_summaries')->where('nhl_game_id', 2026020001)->count())->toBe(1)
        ->and(DB::table('nhl_import_progress')->where('game_id', '2026020001')->where('import_type', NhlImportStages::PBP)->value('items_count'))->toBe(3);
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

it('reconciles tiny zero appearance goalie toi artifacts when pbp does not show the goalie in net', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8476914, [
        'first_name' => 'Joonas',
        'last_name' => 'Korpisalo',
        'full_name' => 'Joonas Korpisalo',
        'position' => 'G',
    ]);
    ($this->insertBoxscore)(2026020001, 8476914, [
        'toi' => '00:00',
        'toi_seconds' => 0,
        'shifts' => 0,
        'position' => 'G',
    ]);
    ($this->insertSummary)(2026020001, 8476914, [
        'toi' => 9,
        'shifts' => 1,
    ]);
    DB::table('play_by_plays')->insert([
        'nhl_game_id' => 2026020001,
        'nhl_event_id' => '993',
        'period' => 3,
        'period_type' => 'REG',
        'time_in_period' => '03:21',
        'time_remaining' => '16:39',
        'seconds_in_period' => 201,
        'seconds_in_game' => 2601,
        'seconds_remaining' => 999,
        'type_desc_key' => 'hit',
        'sort_order' => 648,
        'hitting_player_id' => 8473419,
        'hittee_player_id' => 8481582,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $validation = app(ValidateNhlGameSummary::class)->validate(2026020001);

    expect($validation->status)->toBe(NhlGameValidation::STATUS_APPROVED)
        ->and($validation->mismatch_count)->toBe(0)
        ->and(DB::table('nhl_game_summaries')
            ->where('nhl_game_id', 2026020001)
            ->where('nhl_player_id', 8476914)
            ->value('toi'))->toBe(0)
        ->and(DB::table('nhl_game_summaries')
            ->where('nhl_game_id', 2026020001)
            ->where('nhl_player_id', 8476914)
            ->value('shifts'))->toBe(0);
});

it('keeps zero appearance goalie toi artifacts at thirty seconds failed for review', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8476914, [
        'first_name' => 'Joonas',
        'last_name' => 'Korpisalo',
        'full_name' => 'Joonas Korpisalo',
        'position' => 'G',
    ]);
    ($this->insertBoxscore)(2026020001, 8476914, [
        'toi' => '00:00',
        'toi_seconds' => 0,
        'shifts' => 0,
        'position' => 'G',
    ]);
    ($this->insertSummary)(2026020001, 8476914, [
        'toi' => 30,
        'shifts' => 1,
    ]);

    $validation = app(ValidateNhlGameSummary::class)->validate(2026020001);

    expect($validation->status)->toBe(NhlGameValidation::STATUS_FAILED)
        ->and($validation->mismatch_count)->toBe(1)
        ->and($validation->deltas()->first()?->field)->toBe('toi_seconds')
        ->and(DB::table('nhl_game_summaries')
            ->where('nhl_game_id', 2026020001)
            ->where('nhl_player_id', 8476914)
            ->value('toi'))->toBe(30);
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

    $this->postJson(route('admin.nhl-game-imports.games.rerun', ['gameId' => 2026020001]))
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

    $this->actingAs($user)
        ->postJson(route('admin.nhl-game-imports.games.rerun', ['gameId' => 2026020001]))
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

    Bus::assertDispatched(RebuildNhlGameImportJob::class, function (RebuildNhlGameImportJob $job): bool {
        return $job->gameId === 2026020001 && $job->runId === null;
    });
    Bus::assertNotDispatched(PreflightNhlGameImportRebuildJob::class);
    Bus::assertNotDispatched(ImportPbpNhlJob::class);
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

it('rebuilds a stopped game import from the admin rerun endpoint', function (): void {
    Bus::fake();
    $admin = ($this->makeSuperAdmin)();
    ($this->insertGame)();
    ($this->fakeSourcePreflight)([]);
    ($this->insertPipeline)(2026020001, [
        NhlImportStages::PBP => 'completed',
        NhlImportStages::SUMMARY => 'error',
    ]);

    $this->actingAs($admin)
        ->postJson(route('admin.nhl-game-imports.games.rerun', ['gameId' => 2026020001]))
        ->assertAccepted()
        ->assertJsonPath('status', 'game_rebuild_queued')
        ->assertJsonPath('game_id', 2026020001);

    Bus::assertDispatched(RebuildNhlGameImportJob::class, function (RebuildNhlGameImportJob $job): bool {
        return $job->gameId === 2026020001 && $job->runId === null;
    });
    Bus::assertNotDispatched(PreflightNhlGameImportRebuildJob::class);
    Bus::assertNotDispatched(ImportPbpNhlJob::class);
});

it('writes raw provider troubleshooting files when a game import stage stops', function (): void {
    ($this->insertGame)();
    ($this->insertProgress)(2026020001, NhlImportStages::SUMMARY, 'running');
    Http::fake([
        'https://api-web.nhle.com/v1/gamecenter/2026020001/boxscore' => Http::response([
            'playerByGameStats' => ['homeTeam' => ['forwards' => []]],
        ]),
        'https://api-web.nhle.com/v1/gamecenter/2026020001/play-by-play' => Http::response([
            'plays' => [['eventId' => 1, 'typeDescKey' => 'goal']],
        ]),
        'https://api.nhle.com/stats/rest/en/shiftcharts?cayenneExp=gameId=2026020001' => Http::response([
            'data' => [['playerId' => 8478402, 'shiftNumber' => 1]],
        ]),
    ]);

    $job = new class(2026020001) extends BaseNhlJob {
        protected function stageName(): string
        {
            return \App\Support\NhlImportStages::SUMMARY;
        }

        protected function perform(int $gameId): int
        {
            throw new \RuntimeException('Unable to resolve NHL team id for game 2026020001 PBP summary player 8478402.');
        }
    };

    $job->handle(app(NhlImportOrchestrator::class), app(NhlGameImportEligibility::class));
    $directory = (string) config('apiImportNhl.validation_troubleshooting_path');

    expect(File::exists($directory . '/stoppage_2026020001.md'))->toBeTrue()
        ->and(File::exists($directory . '/raw_boxscore_2026020001.txt'))->toBeTrue()
        ->and(File::exists($directory . '/raw_pbp_2026020001.txt'))->toBeTrue()
        ->and(File::exists($directory . '/raw_shifts_2026020001.txt'))->toBeTrue()
        ->and(File::get($directory . '/stoppage_2026020001.md'))->toContain('summary', 'Unable to resolve NHL team id')
        ->and(File::get($directory . '/raw_boxscore_2026020001.txt'))->toContain('"source": "boxscore"')
        ->and(File::get($directory . '/raw_pbp_2026020001.txt'))->toContain('"source": "pbp"')
        ->and(File::get($directory . '/raw_shifts_2026020001.txt'))->toContain('"source": "shifts"');

    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026020001',
        'import_type' => NhlImportStages::SUMMARY,
        'status' => 'error',
    ]);
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

it('persists an invalidated validation with field deltas for preseason games', function (): void {
    ($this->insertGame)(2026010001, ['game_type' => 1]);
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026010001, 8478402, ['goals' => 2]);
    ($this->insertSummary)(2026010001, 8478402);

    $validation = app(ValidateNhlGameSummary::class)->validate(2026010001);

    expect($validation->status)->toBe(NhlGameValidation::STATUS_INVALIDATED)
        ->and($validation->mismatch_count)->toBe(1)
        ->and($validation->approved_at)->toBeNull();
    $this->assertDatabaseHas('nhl_game_validation_deltas', [
        'validation_id' => $validation->id,
        'field' => 'goals',
        'severity' => NhlGameValidationDelta::SEVERITY_ERROR,
    ]);
});

it('keeps validation deltas as failed hard stops for regular season games', function (): void {
    ($this->insertGame)(2026020001, ['game_type' => 2]);
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026020001, 8478402, ['goals' => 2]);
    ($this->insertSummary)(2026020001, 8478402);

    $validation = app(ValidateNhlGameSummary::class)->validate(2026020001);

    expect($validation->status)->toBe(NhlGameValidation::STATUS_FAILED)
        ->and($validation->mismatch_count)->toBe(1);
});

it('keeps validation deltas as failed hard stops for playoff games', function (): void {
    ($this->insertGame)(2026030001, ['game_type' => 3]);
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026030001, 8478402, ['goals' => 2]);
    ($this->insertSummary)(2026030001, 8478402);

    $validation = app(ValidateNhlGameSummary::class)->validate(2026030001);

    expect($validation->status)->toBe(NhlGameValidation::STATUS_FAILED)
        ->and($validation->mismatch_count)->toBe(1);
});

it('lets preseason invalidated validation jobs complete without failing the stage', function (): void {
    ($this->insertGame)(2026010001, ['game_type' => 1]);
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026010001, 8478402, ['goals' => 2]);
    ($this->insertSummary)(2026010001, 8478402);
    ($this->insertProgress)(2026010001, NhlImportStages::VALIDATE_SUMMARY, 'running', ['game_type' => 1]);

    (new ValidateNhlGameSummaryJob(2026010001))->handle(
        app(NhlImportOrchestrator::class),
        app(NhlGameImportEligibility::class)
    );

    $this->assertDatabaseHas('nhl_game_validations', [
        'nhl_game_id' => 2026010001,
        'status' => NhlGameValidation::STATUS_INVALIDATED,
        'mismatch_count' => 1,
    ]);
    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026010001',
        'import_type' => NhlImportStages::VALIDATE_SUMMARY,
        'status' => 'completed',
    ]);
});

it('keeps regular season validation jobs failed when deltas exist', function (): void {
    ($this->insertGame)(2026020001, ['game_type' => 2]);
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026020001, 8478402, ['goals' => 2]);
    ($this->insertSummary)(2026020001, 8478402);
    ($this->insertProgress)(2026020001, NhlImportStages::VALIDATE_SUMMARY, 'running');

    (new ValidateNhlGameSummaryJob(2026020001))->handle(
        app(NhlImportOrchestrator::class),
        app(NhlGameImportEligibility::class)
    );

    $this->assertDatabaseHas('nhl_game_validations', [
        'nhl_game_id' => 2026020001,
        'status' => NhlGameValidation::STATUS_FAILED,
        'mismatch_count' => 1,
    ]);
    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026020001',
        'import_type' => NhlImportStages::VALIDATE_SUMMARY,
        'status' => 'error',
    ]);
});

it('writes troubleshooting markdown snapshots when validation fails', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026020001, 8478402, ['goals' => 2]);
    ($this->insertSummary)(2026020001, 8478402);
    Http::fake([
        'https://api-web.nhle.com/v1/gamecenter/2026020001/boxscore' => Http::response([
            'playerByGameStats' => [
                'homeTeam' => [
                    'forwards' => [
                        ['playerId' => 8478402, 'goals' => 2],
                    ],
                ],
            ],
        ]),
        'https://api-web.nhle.com/v1/gamecenter/2026020001/play-by-play' => Http::response([
            'plays' => [
                ['eventId' => 704, 'typeDescKey' => 'goal'],
            ],
        ]),
        'https://api.nhle.com/stats/rest/en/shiftcharts?cayenneExp=gameId=2026020001' => Http::response([
            'data' => [
                [
                    'playerId' => 8478402,
                    'shiftNumber' => 1,
                    'period' => 1,
                    'startTime' => '00:00',
                    'endTime' => '00:45',
                    'duration' => '00:45',
                    'typeCode' => 517,
                ],
            ],
        ]),
    ]);

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
        ->and(File::exists($directory . '/raw_boxscore_2026020001.txt'))->toBeTrue()
        ->and(File::exists($directory . '/raw_pbp_2026020001.txt'))->toBeTrue()
        ->and(File::exists($directory . '/raw_shifts_2026020001.txt'))->toBeTrue()
        ->and(File::get($directory . '/raw_boxscore_2026020001.txt'))->toContain('"source": "boxscore"', 'gamecenter/2026020001/boxscore')
        ->and(File::get($directory . '/raw_pbp_2026020001.txt'))->toContain('"source": "pbp"', 'gamecenter/2026020001/play-by-play')
        ->and(File::get($directory . '/raw_shifts_2026020001.txt'))->toContain('"source": "shifts"', 'shiftcharts?cayenneExp=gameId=2026020001')
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

it('ignores shiftchart rows for teams outside the imported game', function (): void {
    ($this->insertGame)(2025020565, [
        'season_id' => '20252026',
        'game_date' => '2025-12-21',
        'game_dow' => 'Sun',
        'game_month' => 'Dec',
        'home_team_id' => 1,
        'home_team_abbrev' => 'NJD',
        'away_team_id' => 7,
        'away_team_abbrev' => 'BUF',
    ]);
    ($this->makePlayer)(8484145, [
        'first_name' => 'Zach',
        'last_name' => 'Benson',
        'full_name' => 'Zach Benson',
        'team_abbrev' => 'BUF',
    ]);

    $importer = new class extends ImportNhlShifts {
        public function getAPIDataFullUrl(string $url): array
        {
            return [
                'data' => [
                    [
                        'playerId' => 8474565,
                        'shiftNumber' => 1,
                        'period' => 1,
                        'startTime' => '00:00',
                        'endTime' => '00:36',
                        'duration' => '00:36',
                        'teamAbbrev' => 'VGK',
                        'teamName' => 'Vegas Golden Knights',
                        'firstName' => 'Alex',
                        'lastName' => 'Pietrangelo',
                        'eventNumber' => 7,
                        'typeCode' => 517,
                    ],
                    [
                        'playerId' => 8484801,
                        'shiftNumber' => 1,
                        'period' => 1,
                        'startTime' => '00:00',
                        'endTime' => '00:40',
                        'duration' => '00:40',
                        'teamAbbrev' => 'SJS',
                        'teamName' => 'San Jose Sharks',
                        'firstName' => 'Macklin',
                        'lastName' => 'Celebrini',
                        'eventNumber' => 8,
                        'typeCode' => 517,
                    ],
                    [
                        'playerId' => 8484145,
                        'shiftNumber' => 1,
                        'period' => 1,
                        'startTime' => '00:00',
                        'endTime' => '00:45',
                        'duration' => '00:45',
                        'teamAbbrev' => 'BUF',
                        'teamName' => 'Buffalo Sabres',
                        'firstName' => 'Zach',
                        'lastName' => 'Benson',
                        'eventNumber' => 9,
                        'typeCode' => 517,
                    ],
                ],
            ];
        }
    };

    expect($importer->import('2025020565'))->toBe(1);

    $this->assertDatabaseHas('nhl_shifts', [
        'nhl_game_id' => 2025020565,
        'nhl_player_id' => 8484145,
        'team_abbrev' => 'BUF',
        'shift_duration_seconds' => 45,
    ]);
    $this->assertDatabaseMissing('nhl_shifts', [
        'nhl_game_id' => 2025020565,
        'team_abbrev' => 'VGK',
    ]);
    $this->assertDatabaseMissing('nhl_shifts', [
        'nhl_game_id' => 2025020565,
        'team_abbrev' => 'SJS',
    ]);
    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2025020565,
        'nhl_player_id' => 8484145,
        'nhl_team_id' => 7,
        'toi' => 45,
        'shifts' => 1,
    ]);
});

it('drops shiftchart-only players missing from both boxscore and pbp', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8477939, [
        'first_name' => 'William',
        'last_name' => 'Nylander',
        'full_name' => 'William Nylander',
        'team_abbrev' => 'TOR',
    ]);
    ($this->makePlayer)(8479423, [
        'first_name' => 'Alexander',
        'last_name' => 'Nylander',
        'full_name' => 'Alexander Nylander',
        'team_abbrev' => 'TOR',
    ]);
    ($this->makePlayer)(8480001, [
        'first_name' => 'Pbp',
        'last_name' => 'Only',
        'full_name' => 'Pbp Only',
        'team_abbrev' => 'TOR',
    ]);
    ($this->insertBoxscore)(2026020001, 8477939, [
        'toi' => '02:47',
        'toi_seconds' => 167,
        'shifts' => 4,
    ]);

    DB::table('play_by_plays')->insert([
        [
            'nhl_game_id' => 2026020001,
            'nhl_event_id' => 54,
            'period' => 1,
            'time_in_period' => '00:25',
            'seconds_in_period' => 25,
            'seconds_in_game' => 25,
            'type_desc_key' => 'shot-on-goal',
            'shooting_player_id' => 8477939,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'nhl_game_id' => 2026020001,
            'nhl_event_id' => 55,
            'period' => 1,
            'time_in_period' => '01:20',
            'seconds_in_period' => 80,
            'seconds_in_game' => 80,
            'type_desc_key' => 'hit',
            'hitting_player_id' => 8480001,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $importer = new class extends ImportNhlShifts {
        public function getAPIDataFullUrl(string $url): array
        {
            return [
                'data' => [
                    $this->shift(8477939, 'William', 1, '00:25', '01:17', '00:52', 54),
                    $this->shift(8477939, 'William', 2, '02:52', '03:45', '00:53', 88),
                    $this->shift(8477939, 'William', 3, '06:12', '06:39', '00:27', 146),
                    $this->shift(8477939, 'William', 4, '07:13', '07:48', '00:35', 301),
                    $this->shift(8479423, 'Alexander', 1, '00:25', '01:17', '00:52', 54),
                    $this->shift(8479423, 'Alexander', 2, '02:52', '03:45', '00:53', 88),
                    $this->shift(8479423, 'Alexander', 3, '06:12', '06:39', '00:27', 146),
                    $this->shift(8479423, 'Alexander', 4, '07:13', '07:48', '00:35', 301),
                    $this->shift(8480001, 'Pbp', 1, '01:20', '01:50', '00:30', 55),
                ],
            ];
        }

        /**
         * @return array<string,mixed>
         */
        private function shift(
            int $playerId,
            string $firstName,
            int $shiftNumber,
            string $start,
            string $end,
            string $duration,
            int $eventNumber
        ): array {
            return [
                'gameId' => 2026020001,
                'playerId' => $playerId,
                'shiftNumber' => $shiftNumber,
                'period' => 1,
                'startTime' => $start,
                'endTime' => $end,
                'duration' => $duration,
                'teamAbbrev' => 'TOR',
                'teamName' => 'Toronto Maple Leafs',
                'firstName' => $firstName,
                'lastName' => 'Nylander',
                'eventNumber' => $eventNumber,
                'typeCode' => 517,
            ];
        }
    };

    expect($importer->import('2026020001'))->toBe(5);

    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8477939,
        'toi' => 167,
        'shifts' => 4,
    ]);
    $this->assertDatabaseMissing('nhl_shifts', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8479423,
    ]);
    $this->assertDatabaseMissing('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8479423,
    ]);
    $this->assertDatabaseHas('nhl_shifts', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8480001,
        'shift_duration_seconds' => 30,
    ]);
});

it('drops goalie shiftchart artifacts when pbp proves the goalie was empty net after a goal', function (): void {
    ($this->insertGame)(2025020031, [
        'season_id' => '20252026',
        'game_date' => '2025-10-11',
        'game_dow' => 'Sat',
        'game_month' => 'Oct',
        'home_team_id' => 16,
        'home_team_abbrev' => 'CHI',
        'away_team_id' => 8,
        'away_team_abbrev' => 'MTL',
    ]);
    ($this->makePlayer)(8481519, [
        'first_name' => 'Spencer',
        'last_name' => 'Knight',
        'full_name' => 'Spencer Knight',
        'position' => 'G',
        'team_abbrev' => 'CHI',
    ]);
    ($this->insertBoxscore)(2025020031, 8481519, [
        'nhl_team_id' => 16,
        'toi' => '59:45',
        'toi_seconds' => 3585,
        'shifts' => 0,
    ]);

    DB::table('play_by_plays')->insert([
        [
            'nhl_game_id' => 2025020031,
            'nhl_player_id' => 8471675,
            'event_owner_team_id' => 8,
            'period' => 3,
            'time_in_period' => '19:45',
            'seconds_in_period' => 1185,
            'seconds_in_game' => 3585,
            'type_desc_key' => 'goal',
            'situation_code' => '1551',
            'sort_order' => 1108,
            'goalie_in_net_player_id' => 8481519,
            'shot_type' => 'wrist',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'nhl_game_id' => 2025020031,
            'nhl_player_id' => 8484185,
            'event_owner_team_id' => 16,
            'period' => 3,
            'time_in_period' => '19:45',
            'seconds_in_period' => 1185,
            'seconds_in_game' => 3585,
            'type_desc_key' => 'faceoff',
            'situation_code' => '1560',
            'sort_order' => 1109,
            'goalie_in_net_player_id' => null,
            'shot_type' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $importer = new class extends ImportNhlShifts {
        public function getAPIDataFullUrl(string $url): array
        {
            return [
                'data' => [
                    $this->shift(1, 1, '00:00', '20:00', '20:00', 6),
                    $this->shift(2, 2, '00:00', '20:00', '20:00', 435),
                    $this->shift(3, 3, '00:00', '19:45', '19:45', 832),
                    $this->shift(4, 3, '19:45', '20:00', '00:15', 1112),
                ],
            ];
        }

        /**
         * @return array<string, mixed>
         */
        private function shift(int $shiftNumber, int $period, string $start, string $end, string $duration, int $eventNumber): array
        {
            return [
                'playerId' => 8481519,
                'shiftNumber' => $shiftNumber,
                'period' => $period,
                'startTime' => $start,
                'endTime' => $end,
                'duration' => $duration,
                'teamAbbrev' => 'CHI',
                'teamName' => 'Chicago Blackhawks',
                'firstName' => 'Spencer',
                'lastName' => 'Knight',
                'eventNumber' => $eventNumber,
                'typeCode' => 517,
            ];
        }
    };

    expect($importer->import('2025020031'))->toBe(3);

    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2025020031,
        'nhl_player_id' => 8481519,
        'nhl_team_id' => 16,
        'toi' => 3585,
        'shifts' => 3,
    ]);
    $this->assertDatabaseMissing('nhl_shifts', [
        'nhl_game_id' => 2025020031,
        'nhl_player_id' => 8481519,
        'shift_number' => 4,
        'event_number' => 1112,
    ]);
});

it('trims overtime shiftchart rows after pbp proves the game ended early', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8478048, [
        'first_name' => 'Igor',
        'last_name' => 'Shesterkin',
        'full_name' => 'Igor Shesterkin',
        'position' => 'G',
        'team_abbrev' => 'MTL',
    ]);
    ($this->makePlayer)(8481533, [
        'first_name' => 'Trevor',
        'last_name' => 'Zegras',
        'full_name' => 'Trevor Zegras',
        'team_abbrev' => 'TOR',
    ]);
    ($this->makePlayer)(8482118, [
        'first_name' => 'Sam',
        'last_name' => 'Colangelo',
        'full_name' => 'Sam Colangelo',
        'team_abbrev' => 'TOR',
    ]);
    ($this->insertBoxscore)(2026020001, 8478048, [
        'nhl_team_id' => 2,
        'toi' => '00:59',
        'toi_seconds' => 59,
        'shifts' => 0,
        'position' => 'G',
    ]);
    ($this->insertBoxscore)(2026020001, 8481533, [
        'toi' => '00:59',
        'toi_seconds' => 59,
        'shifts' => 1,
    ]);
    ($this->insertBoxscore)(2026020001, 8482118, [
        'toi' => '00:59',
        'toi_seconds' => 59,
        'shifts' => 1,
    ]);

    DB::table('play_by_plays')->insert([
        [
            'nhl_game_id' => 2026020001,
            'nhl_event_id' => 1082,
            'period' => 4,
            'period_type' => 'OT',
            'time_in_period' => '00:59',
            'time_remaining' => '04:01',
            'seconds_in_period' => 59,
            'seconds_remaining' => 241,
            'seconds_in_game' => 3659,
            'type_desc_key' => 'goal',
            'situation_code' => '1331',
            'sort_order' => 821,
            'goalie_in_net_player_id' => 8478048,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'nhl_game_id' => 2026020001,
            'nhl_event_id' => 370,
            'period' => 4,
            'period_type' => 'OT',
            'time_in_period' => '00:59',
            'time_remaining' => '04:01',
            'seconds_in_period' => 59,
            'seconds_remaining' => 241,
            'seconds_in_game' => 3659,
            'type_desc_key' => 'game-end',
            'situation_code' => '0431',
            'sort_order' => 826,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $importer = new class extends ImportNhlShifts {
        public function getAPIDataFullUrl(string $url): array
        {
            return [
                'data' => [
                    $this->shift(8478048, 'Igor', 'Shesterkin', 'MTL', 1, '00:00', '05:00', '05:00', 1081),
                    $this->shift(8481533, 'Trevor', 'Zegras', 'TOR', 1, '00:00', '00:59', '00:59', 1082),
                    $this->shift(8481533, 'Trevor', 'Zegras', 'TOR', 2, '00:59', '05:00', '04:01', 1083),
                    $this->shift(8482118, 'Sam', 'Colangelo', 'TOR', 1, '00:00', '00:59', '00:59', 1082),
                    $this->shift(8482118, 'Sam', 'Colangelo', 'TOR', 2, '00:59', '05:00', '04:01', 1083),
                ],
            ];
        }

        /**
         * @return array<string,mixed>
         */
        private function shift(
            int $playerId,
            string $firstName,
            string $lastName,
            string $teamAbbrev,
            int $shiftNumber,
            string $start,
            string $end,
            string $duration,
            int $eventNumber
        ): array {
            return [
                'gameId' => 2026020001,
                'playerId' => $playerId,
                'shiftNumber' => $shiftNumber,
                'period' => 4,
                'startTime' => $start,
                'endTime' => $end,
                'duration' => $duration,
                'teamAbbrev' => $teamAbbrev,
                'teamName' => $teamAbbrev,
                'firstName' => $firstName,
                'lastName' => $lastName,
                'eventNumber' => $eventNumber,
                'typeCode' => 517,
            ];
        }
    };

    expect($importer->import('2026020001'))->toBe(3);

    $this->assertDatabaseHas('nhl_shifts', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8478048,
        'shift_number' => 1,
        'end_time' => '00:59',
        'duration' => '00:59',
        'shift_duration_seconds' => 59,
    ]);
    foreach ([8481533, 8482118] as $playerId) {
        $this->assertDatabaseMissing('nhl_shifts', [
            'nhl_game_id' => 2026020001,
            'nhl_player_id' => $playerId,
            'event_number' => 1083,
        ]);
        $this->assertDatabaseHas('nhl_game_summaries', [
            'nhl_game_id' => 2026020001,
            'nhl_player_id' => $playerId,
            'toi' => 59,
            'shifts' => 1,
        ]);
    }
    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8478048,
        'toi' => 59,
    ]);
});

it('drops a malformed goalie shift row when official goalie toi exactly proves the overage', function (): void {
    ($this->insertGame)(2025020634, [
        'season_id' => '20252026',
        'game_date' => '2026-01-06',
        'game_dow' => 'Tue',
        'game_month' => 'Jan',
        'home_team_id' => 10,
        'home_team_abbrev' => 'TOR',
        'away_team_id' => 52,
        'away_team_abbrev' => 'WPG',
    ]);
    ($this->makePlayer)(8483710, [
        'first_name' => 'Dennis',
        'last_name' => 'Hildeby',
        'full_name' => 'Dennis Hildeby',
        'position' => 'G',
        'team_abbrev' => 'TOR',
    ]);
    ($this->insertBoxscore)(2025020634, 8483710, [
        'nhl_team_id' => 10,
        'toi' => '34:36',
        'toi_seconds' => 2076,
        'shifts' => 0,
        'position' => 'G',
    ]);

    $importer = new class extends ImportNhlShifts {
        public function getAPIDataFullUrl(string $url): array
        {
            return [
                'data' => [
                    $this->shift(1, 2, '05:24', '20:00', '14:36', 608),
                    $this->shift(2, 2, '00:00', '13:31', '04:43', null),
                    $this->shift(2, 3, '00:00', '20:00', '20:00', 812),
                ],
            ];
        }

        /**
         * @return array<string, mixed>
         */
        private function shift(
            int $shiftNumber,
            int $period,
            string $start,
            string $end,
            string $duration,
            ?int $eventNumber
        ): array {
            return [
                'playerId' => 8483710,
                'shiftNumber' => $shiftNumber,
                'period' => $period,
                'startTime' => $start,
                'endTime' => $end,
                'duration' => $duration,
                'teamAbbrev' => 'TOR',
                'teamName' => 'Toronto Maple Leafs',
                'firstName' => 'Dennis',
                'lastName' => 'Hildeby',
                'eventNumber' => $eventNumber,
                'typeCode' => 517,
            ];
        }
    };

    expect($importer->import('2025020634'))->toBe(2);

    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2025020634,
        'nhl_player_id' => 8483710,
        'nhl_team_id' => 10,
        'toi' => 2076,
        'shifts' => 2,
    ]);
    $this->assertDatabaseMissing('nhl_shifts', [
        'nhl_game_id' => 2025020634,
        'nhl_player_id' => 8483710,
        'shift_number' => 2,
        'period' => 2,
        'shift_duration_seconds' => 283,
    ]);
});

it('keeps goalie shift rows when no suspicious row exactly reconciles official goalie toi', function (): void {
    ($this->insertGame)(2025020635, [
        'season_id' => '20252026',
        'game_date' => '2026-01-06',
        'game_dow' => 'Tue',
        'game_month' => 'Jan',
        'home_team_id' => 10,
        'home_team_abbrev' => 'TOR',
        'away_team_id' => 52,
        'away_team_abbrev' => 'WPG',
    ]);
    ($this->makePlayer)(8483711, [
        'first_name' => 'Sample',
        'last_name' => 'Goalie',
        'full_name' => 'Sample Goalie',
        'position' => 'G',
        'team_abbrev' => 'TOR',
    ]);
    ($this->insertBoxscore)(2025020635, 8483711, [
        'nhl_team_id' => 10,
        'toi' => '34:35',
        'toi_seconds' => 2075,
        'shifts' => 0,
        'position' => 'G',
    ]);

    $importer = new class extends ImportNhlShifts {
        public function getAPIDataFullUrl(string $url): array
        {
            return [
                'data' => [
                    $this->shift(1, 2, '05:24', '20:00', '14:36', 608),
                    $this->shift(2, 2, '00:00', '13:31', '04:43', null),
                    $this->shift(2, 3, '00:00', '20:00', '20:00', 812),
                ],
            ];
        }

        /**
         * @return array<string, mixed>
         */
        private function shift(
            int $shiftNumber,
            int $period,
            string $start,
            string $end,
            string $duration,
            ?int $eventNumber
        ): array {
            return [
                'playerId' => 8483711,
                'shiftNumber' => $shiftNumber,
                'period' => $period,
                'startTime' => $start,
                'endTime' => $end,
                'duration' => $duration,
                'teamAbbrev' => 'TOR',
                'teamName' => 'Toronto Maple Leafs',
                'firstName' => 'Sample',
                'lastName' => 'Goalie',
                'eventNumber' => $eventNumber,
                'typeCode' => 517,
            ];
        }
    };

    expect($importer->import('2025020635'))->toBe(3);

    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2025020635,
        'nhl_player_id' => 8483711,
        'nhl_team_id' => 10,
        'toi' => 2359,
        'shifts' => 3,
    ]);
    $this->assertDatabaseHas('nhl_shifts', [
        'nhl_game_id' => 2025020635,
        'nhl_player_id' => 8483711,
        'shift_number' => 2,
        'period' => 2,
        'shift_duration_seconds' => 283,
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

it('drops skater shiftchart artifacts inside a committed major penalty window', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8473994, [
        'first_name' => 'Jamie',
        'last_name' => 'Benn',
        'full_name' => 'Jamie Benn',
    ]);
    ($this->insertBoxscore)(2026020001, 8473994, [
        'toi' => '12:38',
        'toi_seconds' => 758,
        'shifts' => 17,
        'penalty_minutes' => 7,
    ]);

    DB::table('play_by_plays')->insert([
        'nhl_game_id' => 2026020001,
        'nhl_event_id' => 25,
        'period' => 1,
        'period_type' => 'REG',
        'time_in_period' => '12:45',
        'seconds_in_period' => 765,
        'seconds_in_game' => 765,
        'type_desc_key' => 'penalty',
        'desc_key' => 'fighting',
        'committed_by_player_id' => 8473994,
        'drawn_by_player_id' => 8477507,
        'duration' => 5,
        'penalty_type_code' => 'MAJ',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $importer = new class extends ImportNhlShifts {
        public function getAPIDataFullUrl(string $url): array
        {
            return [
                'data' => [
                    $this->shift(1, 1, '00:25', '00:44', '00:19', 105),
                    $this->shift(2, 1, '02:21', '03:02', '00:41', 128),
                    $this->shift(3, 1, '05:05', '05:46', '00:41', 216),
                    $this->shift(4, 1, '08:29', '09:13', '00:44', 249),
                    $this->shift(5, 1, '12:01', '12:45', '00:44', 285),
                    $this->shift(6, 1, '13:09', '13:46', '00:37', 455),
                    $this->shift(7, 1, '13:49', '14:45', '00:56', 468),
                    $this->shift(8, 2, '02:30', '03:40', '01:10', 648),
                    $this->shift(9, 2, '05:18', '06:25', '01:07', 676),
                    $this->shift(10, 2, '09:02', '09:57', '00:55', 724),
                    $this->shift(11, 2, '12:37', '13:13', '00:36', 764),
                    $this->shift(12, 2, '13:42', '14:15', '00:33', 779),
                    $this->shift(13, 2, '17:34', '18:00', '00:26', 819),
                    $this->shift(14, 3, '01:16', '02:08', '00:52', 864),
                    $this->shift(15, 3, '04:05', '04:45', '00:40', 906),
                    $this->shift(16, 3, '07:21', '08:16', '00:55', 947),
                    $this->shift(17, 3, '10:53', '11:29', '00:36', 994),
                    $this->shift(18, 3, '14:11', '15:04', '00:53', 1087),
                    $this->shift(19, 3, '19:14', '20:00', '00:46', 1148),
                ],
            ];
        }

        /**
         * @return array<string,mixed>
         */
        private function shift(
            int $shiftNumber,
            int $period,
            string $start,
            string $end,
            string $duration,
            int $eventNumber
        ): array {
            return [
                'gameId' => 2026020001,
                'playerId' => 8473994,
                'shiftNumber' => $shiftNumber,
                'period' => $period,
                'startTime' => $start,
                'endTime' => $end,
                'duration' => $duration,
                'teamAbbrev' => 'TOR',
                'teamName' => 'Toronto Maple Leafs',
                'firstName' => 'Jamie',
                'lastName' => 'Benn',
                'eventNumber' => $eventNumber,
                'typeCode' => 517,
            ];
        }
    };

    expect($importer->import('2026020001'))->toBe(17);

    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8473994,
        'toi' => 758,
        'shifts' => 17,
    ]);
    foreach ([455, 468] as $eventNumber) {
        $this->assertDatabaseMissing('nhl_shifts', [
            'nhl_game_id' => 2026020001,
            'nhl_player_id' => 8473994,
            'event_number' => $eventNumber,
        ]);
    }
});

it('uses a contained alternate shift row when it exactly reconciles boxscore time on ice', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8475786, [
        'first_name' => 'Zach',
        'last_name' => 'Hyman',
        'full_name' => 'Zach Hyman',
    ]);
    ($this->insertBoxscore)(2026020001, 8475786, [
        'toi' => '15:42',
        'toi_seconds' => 942,
        'shifts' => 17,
    ]);

    $importer = new class extends ImportNhlShifts {
        public function getAPIDataFullUrl(string $url): array
        {
            return [
                'data' => [
                    $this->shift(1, 1, '02:00', '02:27', '00:27', 71),
                    $this->shift(2, 1, '05:00', '06:17', '01:17', 105),
                    $this->shift(3, 1, '09:28', '10:30', '01:02', 215),
                    $this->shift(4, 1, '14:35', '16:08', '01:33', 339),
                    $this->shift(5, 1, '17:18', '18:45', '01:27', 421),
                    $this->shift(6, 2, '01:28', '02:13', '00:45', 527),
                    $this->shift(7, 2, '05:07', '06:08', '01:01', 576),
                    $this->shift(8, 2, '09:01', '09:41', '00:40', 625),
                    $this->shift(9, 2, '10:37', '12:04', '01:27', 647),
                    $this->shift(10, 2, '14:22', '15:06', '00:44', 705),
                    $this->shift(11, 2, '16:19', '16:35', '00:16', 724),
                    $this->shift(12, 2, '19:34', '20:00', '00:26', 811),
                    $this->shift(13, 3, '01:15', '02:03', '00:48', 833),
                    $this->shift(14, 3, '03:55', '04:49', '00:54', 923),
                    $this->shift(15, 3, '08:44', '09:28', '00:44', 980),
                    $this->shift(15, 3, '08:45', '09:05', '00:20', 856),
                    $this->shift(16, 3, '12:59', '14:58', '01:59', 1035),
                    $this->shift(17, 3, '17:11', '17:47', '00:36', 1090),
                ],
            ];
        }

        /**
         * @return array<string,mixed>
         */
        private function shift(
            int $shiftNumber,
            int $period,
            string $start,
            string $end,
            string $duration,
            int $eventNumber
        ): array {
            return [
                'gameId' => 2026020001,
                'playerId' => 8475786,
                'shiftNumber' => $shiftNumber,
                'period' => $period,
                'startTime' => $start,
                'endTime' => $end,
                'duration' => $duration,
                'teamAbbrev' => 'TOR',
                'teamName' => 'Toronto Maple Leafs',
                'firstName' => 'Zach',
                'lastName' => 'Hyman',
                'eventNumber' => $eventNumber,
                'typeCode' => 517,
            ];
        }
    };

    expect($importer->import('2026020001'))->toBe(17);

    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8475786,
        'toi' => 942,
        'shifts' => 17,
    ]);
    $this->assertDatabaseHas('nhl_shifts', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8475786,
        'shift_number' => 15,
        'event_number' => 856,
        'shift_duration_seconds' => 20,
    ]);
    $this->assertDatabaseMissing('nhl_shifts', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8475786,
        'shift_number' => 15,
        'event_number' => 980,
    ]);
});

it('uses a duplicate shift alternative when it exactly fixes boxscore time on ice undercount', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8484471, [
        'first_name' => 'Emmitt',
        'last_name' => 'Finnie',
        'full_name' => 'Emmitt Finnie',
    ]);
    ($this->insertBoxscore)(2026020001, 8484471, [
        'toi' => '01:51',
        'toi_seconds' => 111,
        'shifts' => 2,
    ]);

    $importer = new class extends ImportNhlShifts {
        public function getAPIDataFullUrl(string $url): array
        {
            return [
                'data' => [
                    $this->shift(1, '00:00', '01:00', '01:00', 100),
                    $this->shift(2, '05:57', '06:46', '00:49', 563),
                    $this->shift(2, '05:57', '06:48', '00:51', 563),
                ],
            ];
        }

        /**
         * @return array<string, mixed>
         */
        private function shift(int $shiftNumber, string $start, string $end, string $duration, int $eventNumber): array
        {
            return [
                'playerId' => 8484471,
                'shiftNumber' => $shiftNumber,
                'period' => 2,
                'startTime' => $start,
                'endTime' => $end,
                'duration' => $duration,
                'teamAbbrev' => 'TOR',
                'teamName' => 'Toronto Maple Leafs',
                'firstName' => 'Emmitt',
                'lastName' => 'Finnie',
                'eventNumber' => $eventNumber,
                'typeCode' => 517,
            ];
        }
    };

    expect($importer->import('2026020001'))->toBe(2);
    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8484471,
        'toi' => 111,
        'shifts' => 2,
    ]);
    $this->assertDatabaseHas('nhl_shifts', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8484471,
        'event_number' => 563,
        'shift_duration_seconds' => 51,
    ]);
});

it('uses a shorter duplicate shift alternative when it exactly fixes boxscore time on ice overcount', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8482142, [
        'first_name' => 'Jamie',
        'last_name' => 'Drysdale',
        'full_name' => 'Jamie Drysdale',
    ]);
    ($this->insertBoxscore)(2026020001, 8482142, [
        'toi' => '01:41',
        'toi_seconds' => 101,
        'shifts' => 2,
    ]);

    $importer = new class extends ImportNhlShifts {
        public function getAPIDataFullUrl(string $url): array
        {
            return [
                'data' => [
                    $this->shift(1, '00:00', '01:00', '01:00', 100),
                    $this->shift(27, '01:30', '02:17', '00:47', 1220),
                    $this->shift(27, '01:30', '02:11', '00:41', 1220),
                ],
            ];
        }

        /**
         * @return array<string, mixed>
         */
        private function shift(int $shiftNumber, string $start, string $end, string $duration, int $eventNumber): array
        {
            return [
                'playerId' => 8482142,
                'shiftNumber' => $shiftNumber,
                'period' => 4,
                'startTime' => $start,
                'endTime' => $end,
                'duration' => $duration,
                'teamAbbrev' => 'TOR',
                'teamName' => 'Toronto Maple Leafs',
                'firstName' => 'Jamie',
                'lastName' => 'Drysdale',
                'eventNumber' => $eventNumber,
                'typeCode' => 517,
            ];
        }
    };

    expect($importer->import('2026020001'))->toBe(2);
    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8482142,
        'toi' => 101,
        'shifts' => 2,
    ]);
    $this->assertDatabaseHas('nhl_shifts', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8482142,
        'event_number' => 1220,
        'shift_duration_seconds' => 41,
    ]);
});

it('keeps the selected duplicate shift when it already matches official boxscore totals', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8481542, [
        'first_name' => 'Moritz',
        'last_name' => 'Seider',
        'full_name' => 'Moritz Seider',
    ]);
    ($this->insertBoxscore)(2026020001, 8481542, [
        'toi' => '01:49',
        'toi_seconds' => 109,
        'shifts' => 2,
    ]);

    $importer = new class extends ImportNhlShifts {
        public function getAPIDataFullUrl(string $url): array
        {
            return [
                'data' => [
                    $this->shift(1, '00:00', '01:00', '01:00', 100),
                    $this->shift(14, '06:43', '07:32', '00:49', 572),
                    $this->shift(14, '06:43', '07:34', '00:51', 572),
                ],
            ];
        }

        /**
         * @return array<string, mixed>
         */
        private function shift(int $shiftNumber, string $start, string $end, string $duration, int $eventNumber): array
        {
            return [
                'playerId' => 8481542,
                'shiftNumber' => $shiftNumber,
                'period' => 2,
                'startTime' => $start,
                'endTime' => $end,
                'duration' => $duration,
                'teamAbbrev' => 'TOR',
                'teamName' => 'Toronto Maple Leafs',
                'firstName' => 'Moritz',
                'lastName' => 'Seider',
                'eventNumber' => $eventNumber,
                'typeCode' => 517,
            ];
        }
    };

    expect($importer->import('2026020001'))->toBe(2);
    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8481542,
        'toi' => 109,
        'shifts' => 2,
    ]);
    $this->assertDatabaseHas('nhl_shifts', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8481542,
        'event_number' => 572,
        'shift_duration_seconds' => 49,
    ]);
});

it('does not guess between duplicate shift alternatives when none exactly matches boxscore totals', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8484387, [
        'first_name' => 'Matvei',
        'last_name' => 'Michkov',
        'full_name' => 'Matvei Michkov',
    ]);
    ($this->insertBoxscore)(2026020001, 8484387, [
        'toi' => '01:50',
        'toi_seconds' => 110,
        'shifts' => 2,
    ]);

    $importer = new class extends ImportNhlShifts {
        public function getAPIDataFullUrl(string $url): array
        {
            return [
                'data' => [
                    $this->shift(1, '00:00', '01:00', '01:00', 100),
                    $this->shift(20, '01:28', '02:17', '00:49', 1218),
                    $this->shift(20, '01:28', '02:19', '00:51', 1218),
                ],
            ];
        }

        /**
         * @return array<string, mixed>
         */
        private function shift(int $shiftNumber, string $start, string $end, string $duration, int $eventNumber): array
        {
            return [
                'playerId' => 8484387,
                'shiftNumber' => $shiftNumber,
                'period' => 4,
                'startTime' => $start,
                'endTime' => $end,
                'duration' => $duration,
                'teamAbbrev' => 'TOR',
                'teamName' => 'Toronto Maple Leafs',
                'firstName' => 'Matvei',
                'lastName' => 'Michkov',
                'eventNumber' => $eventNumber,
                'typeCode' => 517,
            ];
        }
    };

    expect($importer->import('2026020001'))->toBe(2);
    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8484387,
        'toi' => 109,
        'shifts' => 2,
    ]);
    $this->assertDatabaseHas('nhl_shifts', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8484387,
        'event_number' => 1218,
        'shift_duration_seconds' => 49,
    ]);
});

it('drops a thirty-second-or-less shift artifact when it exactly matches boxscore overage', function (): void {
    ($this->insertGame)(2026020001, [
        'home_team_id' => 17,
        'home_team_abbrev' => 'DET',
    ]);
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

it('keeps a short reused shift number when official targets prove it is valid', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8481532, [
        'first_name' => 'Alex',
        'last_name' => 'Turcotte',
        'full_name' => 'Alex Turcotte',
    ]);
    ($this->insertBoxscore)(2026020001, 8481532, [
        'toi' => '12:24',
        'toi_seconds' => 744,
        'shifts' => 20,
    ]);

    $importer = new class extends ImportNhlShifts {
        public function getAPIDataFullUrl(string $url): array
        {
            return [
                'data' => [
                    $this->shift(1, 1, '01:25', '01:46', '00:21', 76),
                    $this->shift(2, 1, '04:09', '05:01', '00:52', 167),
                    $this->shift(3, 1, '08:45', '09:34', '00:49', 242),
                    $this->shift(4, 1, '11:39', '12:37', '00:58', 331),
                    $this->shift(5, 1, '15:34', '16:17', '00:43', 384),
                    $this->shift(6, 1, '19:58', '20:00', '00:02', 258),
                    $this->shift(6, 2, '01:27', '02:16', '00:49', 569),
                    $this->shift(8, 2, '04:01', '04:37', '00:36', 606),
                    $this->shift(9, 2, '06:33', '07:27', '00:54', 642),
                    $this->shift(10, 2, '11:30', '12:11', '00:41', 763),
                    $this->shift(11, 2, '14:39', '14:51', '00:12', 796),
                    $this->shift(12, 2, '18:40', '19:10', '00:30', 853),
                    $this->shift(12, 3, '01:26', '01:51', '00:25', 890),
                    $this->shift(14, 3, '05:13', '05:43', '00:30', 1044),
                    $this->shift(15, 3, '06:37', '07:35', '00:58', 1068),
                    $this->shift(16, 3, '11:18', '11:55', '00:37', 1143),
                    $this->shift(17, 3, '13:38', '14:07', '00:29', 1181),
                    $this->shift(18, 3, '14:22', '15:30', '01:08', 1199),
                    $this->shift(19, 3, '15:36', '15:54', '00:18', 1215),
                    $this->shift(20, 3, '17:11', '17:43', '00:32', 1237),
                ],
            ];
        }

        /**
         * @return array<string, mixed>
         */
        private function shift(int $shiftNumber, int $period, string $start, string $end, string $duration, int $eventNumber): array
        {
            return [
                'playerId' => 8481532,
                'shiftNumber' => $shiftNumber,
                'period' => $period,
                'startTime' => $start,
                'endTime' => $end,
                'duration' => $duration,
                'teamAbbrev' => 'TOR',
                'teamName' => 'Toronto Maple Leafs',
                'firstName' => 'Alex',
                'lastName' => 'Turcotte',
                'eventNumber' => $eventNumber,
                'typeCode' => 517,
            ];
        }
    };

    expect($importer->import('2026020001'))->toBe(20);
    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8481532,
        'toi' => 744,
        'shifts' => 20,
    ]);
    $this->assertDatabaseHas('nhl_shifts', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8481532,
        'event_number' => 258,
        'shift_duration_seconds' => 2,
    ]);
});

it('drops a thirty-two-second shift artifact when official targets exactly prove the overage', function (): void {
    ($this->insertGame)(2026020001, [
        'home_team_id' => 59,
        'home_team_abbrev' => 'UTA',
    ]);
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

it('drops impossible over-cap skater shifts when pbp manpower and boxscore targets prove the artifact', function (): void {
    ($this->insertGame)(2026020001, [
        'home_team_id' => 14,
        'home_team_abbrev' => 'TBL',
        'away_team_id' => 5,
        'away_team_abbrev' => 'PIT',
    ]);

    foreach ([
        8477465 => ['Tristan', 'Jarry', 'G'],
        8481481 => ['Blake', 'Lizotte', 'C'],
        8478450 => ['Parker', 'Wotherspoon', 'D'],
        8474578 => ['Erik', 'Karlsson', 'D'],
        8475810 => ['Bryan', 'Rust', 'R'],
        8471675 => ['Sidney', 'Crosby', 'C'],
        8471215 => ['Evgeni', 'Malkin', 'C'],
        8477511 => ['Anthony', 'Mantha', 'R'],
    ] as $nhlId => [$firstName, $lastName, $position]) {
        ($this->makePlayer)((int) $nhlId, [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => "{$firstName} {$lastName}",
            'position' => $position,
            'team_abbrev' => 'PIT',
        ]);
    }

    DB::table('play_by_plays')->insert([
        'nhl_game_id' => 2026020001,
        'nhl_event_id' => 1234,
        'period' => 3,
        'period_type' => 'REG',
        'time_in_period' => '19:16',
        'time_remaining' => '00:44',
        'seconds_in_period' => 1156,
        'seconds_in_game' => 3556,
        'seconds_remaining' => 44,
        'situation_code' => '1560',
        'type_code' => 503,
        'type_desc_key' => 'hit',
        'sort_order' => 778,
        'event_owner_team_id' => 5,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([
        [8477465, '20:00', 1200, 0, 'G'],
        [8481481, '02:15', 135, 1, 'C'],
        [8478450, '01:18', 78, 1, 'D'],
        [8474578, '01:17', 77, 1, 'D'],
        [8475810, '01:17', 77, 1, 'R'],
        [8471675, '01:07', 67, 1, 'C'],
        [8471215, '00:10', 10, 1, 'C'],
        [8477511, '00:10', 10, 1, 'R'],
    ] as [$nhlId, $toi, $toiSeconds, $shifts, $position]) {
        ($this->insertBoxscore)(2026020001, $nhlId, [
            'toi' => $toi,
            'toi_seconds' => $toiSeconds,
            'shifts' => $shifts,
            'position' => $position,
        ]);
    }

    $importer = new class extends ImportNhlShifts {
        public function getAPIDataFullUrl(string $url): array
        {
            return [
                'data' => [
                    $this->shift(8477465, 3, '00:00', '20:00', '20:00', 845, 3, 'Tristan', 'Jarry'),
                    $this->shift(8481481, 24, '17:45', '20:00', '02:15', 1203, 3, 'Blake', 'Lizotte'),
                    $this->shift(8478450, 26, '18:42', '20:00', '01:18', 1214, 3, 'Parker', 'Wotherspoon'),
                    $this->shift(8474578, 25, '18:43', '20:00', '01:17', 1215, 3, 'Erik', 'Karlsson'),
                    $this->shift(8475810, 27, '18:43', '20:00', '01:17', 1215, 3, 'Bryan', 'Rust'),
                    $this->shift(8471675, 25, '18:53', '20:00', '01:07', 1223, 3, 'Sidney', 'Crosby'),
                    $this->shift(8471215, 19, '16:53', '17:03', '00:10', 1194, 3, 'Evgeni', 'Malkin'),
                    $this->shift(8471215, 20, '19:04', '20:00', '00:56', 1221, 3, 'Evgeni', 'Malkin'),
                    $this->shift(8477511, 18, '16:53', '17:03', '00:10', 1194, 3, 'Anthony', 'Mantha'),
                    $this->shift(8477511, 19, '19:04', '20:00', '00:56', 1221, 3, 'Anthony', 'Mantha'),
                ],
            ];
        }

        /**
         * @return array<string, mixed>
         */
        private function shift(
            int $playerId,
            int $shiftNumber,
            string $start,
            string $end,
            string $duration,
            int $eventNumber,
            int $period,
            string $firstName,
            string $lastName
        ): array {
            return [
                'playerId' => $playerId,
                'shiftNumber' => $shiftNumber,
                'period' => $period,
                'startTime' => $start,
                'endTime' => $end,
                'duration' => $duration,
                'teamAbbrev' => 'PIT',
                'teamName' => 'Pittsburgh Penguins',
                'firstName' => $firstName,
                'lastName' => $lastName,
                'eventNumber' => $eventNumber,
                'typeCode' => 517,
            ];
        }
    };

    expect($importer->import('2026020001'))->toBe(8);

    foreach ([8471215, 8477511] as $playerId) {
        $this->assertDatabaseMissing('nhl_shifts', [
            'nhl_game_id' => 2026020001,
            'nhl_player_id' => $playerId,
            'event_number' => 1221,
        ]);
        $this->assertDatabaseHas('nhl_game_summaries', [
            'nhl_game_id' => 2026020001,
            'nhl_player_id' => $playerId,
            'toi' => 10,
            'shifts' => 1,
        ]);
    }

    foreach ([8481481, 8478450, 8474578, 8475810, 8471675] as $playerId) {
        $this->assertDatabaseHas('nhl_shifts', [
            'nhl_game_id' => 2026020001,
            'nhl_player_id' => $playerId,
            'shift_end_seconds' => 3600,
        ]);
    }
});

it('borrows official skater shift count when shiftcharts are missing one count-only row', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8482176, [
        'first_name' => 'Wyatt',
        'last_name' => 'Kaiser',
        'full_name' => 'Wyatt Kaiser',
        'position' => 'D',
        'team_abbrev' => 'TOR',
    ]);
    ($this->insertBoxscore)(2026020001, 8482176, [
        'toi' => '19:51',
        'toi_seconds' => 1191,
        'shifts' => 23,
        'position' => 'D',
    ]);

    $importer = new class extends ImportNhlShifts {
        public function getAPIDataFullUrl(string $url): array
        {
            $rows = [];

            for ($shiftNumber = 1; $shiftNumber <= 22; $shiftNumber++) {
                $start = ($shiftNumber - 1) * 54;
                $duration = $shiftNumber === 22 ? 56 : 54;
                $rows[] = [
                    'playerId' => 8482176,
                    'shiftNumber' => $shiftNumber,
                    'period' => 1,
                    'startTime' => sprintf('%02d:%02d', intdiv($start, 60), $start % 60),
                    'endTime' => sprintf('%02d:%02d', intdiv($start + $duration, 60), ($start + $duration) % 60),
                    'duration' => sprintf('%02d:%02d', intdiv($duration, 60), $duration % 60),
                    'teamAbbrev' => 'TOR',
                    'teamName' => 'Toronto Maple Leafs',
                    'firstName' => 'Wyatt',
                    'lastName' => 'Kaiser',
                    'eventNumber' => 100 + $shiftNumber,
                    'typeCode' => 517,
                ];
            }

            return ['data' => $rows];
        }
    };

    expect($importer->import('2026020001'))->toBe(22);
    $this->assertDatabaseCount('nhl_shifts', 22);
    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8482176,
        'toi' => 1190,
        'shifts' => 23,
    ]);
});

it('transfers summary-only toi between paired skaters when boxscore proves source misallocation', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(8480064, [
        'first_name' => 'Josh',
        'last_name' => 'Norris',
        'full_name' => 'Josh Norris',
        'position' => 'C',
        'team_abbrev' => 'TOR',
    ]);
    ($this->makePlayer)(8479359, [
        'first_name' => 'Beck',
        'last_name' => 'Malenstyn',
        'full_name' => 'Beck Malenstyn',
        'position' => 'L',
        'team_abbrev' => 'TOR',
    ]);
    ($this->insertBoxscore)(2026020001, 8480064, [
        'toi' => '13:07',
        'toi_seconds' => 787,
        'shifts' => 21,
        'position' => 'C',
    ]);
    ($this->insertBoxscore)(2026020001, 8479359, [
        'toi' => '13:36',
        'toi_seconds' => 816,
        'shifts' => 20,
        'position' => 'L',
    ]);

    $importer = new class extends ImportNhlShifts {
        public function getAPIDataFullUrl(string $url): array
        {
            return [
                'data' => array_merge(
                    $this->rowsForPlayer(8480064, 'Josh', 'Norris', 21, 38, 57),
                    $this->rowsForPlayer(8479359, 'Beck', 'Malenstyn', 19, 40, 66)
                ),
            ];
        }

        /**
         * @return array<int,array<string,mixed>>
         */
        private function rowsForPlayer(
            int $playerId,
            string $firstName,
            string $lastName,
            int $shiftCount,
            int $defaultSeconds,
            int $lastSeconds
        ): array {
            $rows = [];
            $start = 0;

            for ($shiftNumber = 1; $shiftNumber <= $shiftCount; $shiftNumber++) {
                $duration = $shiftNumber === $shiftCount ? $lastSeconds : $defaultSeconds;
                $rows[] = [
                    'playerId' => $playerId,
                    'shiftNumber' => $shiftNumber,
                    'period' => 1,
                    'startTime' => sprintf('%02d:%02d', intdiv($start, 60), $start % 60),
                    'endTime' => sprintf('%02d:%02d', intdiv($start + $duration, 60), ($start + $duration) % 60),
                    'duration' => sprintf('%02d:%02d', intdiv($duration, 60), $duration % 60),
                    'teamAbbrev' => 'TOR',
                    'teamName' => 'Toronto Maple Leafs',
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'eventNumber' => ($playerId % 1000) + $shiftNumber,
                    'typeCode' => 517,
                ];
                $start += $duration + 1;
            }

            return $rows;
        }
    };

    expect($importer->import('2026020001'))->toBe(40);
    $this->assertDatabaseCount('nhl_shifts', 40);
    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8480064,
        'toi' => 787,
        'shifts' => 21,
    ]);
    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 8479359,
        'toi' => 816,
        'shifts' => 20,
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
    ($this->fakeSourcePreflight)([]);
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

    $response = $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.nhl-validations.index', ['admin_panel' => 1]))
        ->assertOk()
        ->assertJsonStructure(['html'])
        ->assertSee('2026020001')
        ->assertSee('data-validation-toggle')
        ->assertDontSee('Review');

    expect((string) $response->json('html'))
        ->toContain('data-validation-id="'.$validation->id.'"')
        ->toContain(route('admin.nhl-validations.show', ['validation' => $validation->id, 'admin_panel' => 1]));
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

it('reruns invalidated preseason validation and marks validation progress completed', function (): void {
    ($this->insertGame)(2026010001, ['game_type' => 1]);
    ($this->makePlayer)(8478402);
    ($this->insertBoxscore)(2026010001, 8478402, ['goals' => 2]);
    ($this->insertSummary)(2026010001, 8478402);
    ($this->insertProgress)(2026010001, NhlImportStages::VALIDATE_SUMMARY, 'error', ['game_type' => 1]);
    $validation = app(ValidateNhlGameSummary::class)->validate(2026010001);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.nhl-validations.rerun', $validation))
        ->assertOk()
        ->assertJsonPath('status', NhlGameValidation::STATUS_INVALIDATED);

    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026010001',
        'import_type' => NhlImportStages::VALIDATE_SUMMARY,
        'status' => 'completed',
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
    ($this->fakeSourcePreflight)([]);
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
    ($this->fakeSourcePreflight)([]);
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

it('queues validation game rebuild setup without doing rebuild work in the request', function (): void {
    Bus::fake();
    ($this->insertGame)();
    ($this->fakeSourcePreflight)([]);
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

    $this->assertDatabaseHas('nhl_units', ['id' => $unitId]);
    $this->assertDatabaseHas('nhl_import_progress', [
        'game_id' => '2026020001',
        'import_type' => NhlImportStages::PBP,
        'status' => 'completed',
    ]);
    $this->assertDatabaseHas('nhl_game_validations', ['id' => $validation->id]);

    Bus::assertDispatched(RebuildNhlGameImportJob::class, function (RebuildNhlGameImportJob $job): bool {
        return $job->gameId === 2026020001 && $job->runId === null;
    });
    Bus::assertNotDispatched(PreflightNhlGameImportRebuildJob::class);
    Bus::assertNotDispatched(ImportPbpNhlJob::class);
});
