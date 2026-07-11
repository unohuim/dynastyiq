<?php

declare(strict_types=1);

use App\Models\Perspective;
use App\Models\Player;
use App\Models\User;
use App\Services\ImportNhlBoxscore;
use App\Services\NhlStrengthStatsQuery;
use App\Services\ResolveNhlUnit;
use App\Services\SumNhlGameUnits;
use App\Services\SumNhlGameStrengthUnits;
use App\Services\SumNhlSeasonStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-29 12:00:00');
    User::factory()->create();

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

    $this->makePlayer = static function (int $nhlId, string $name = 'Test Player'): Player {
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, 'Player');

        return Player::create([
            'nhl_id' => $nhlId,
            'first_name' => $first,
            'last_name' => $last,
            'full_name' => $name,
            'position' => 'C',
            'pos_type' => 'F',
            'team_abbrev' => 'TOR',
            'current_league_abbrev' => 'NHL',
        ]);
    };

    $this->insertUnitShift = static function (
        int $unitId,
        string $unitType,
        int $start = 0,
        int $end = 60,
        array $overrides = []
    ): int {
        return (int) DB::table('nhl_unit_shifts')->insertGetId(array_merge([
            'unit_id' => $unitId,
            'nhl_game_id' => 2026020001,
            'period' => 1,
            'start_time' => '00:00',
            'end_time' => '01:00',
            'start_game_seconds' => $start,
            'end_game_seconds' => $end,
            'seconds' => $end - $start,
            'team_id' => 1,
            'team_abbrev' => 'TOR',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    };

    $this->insertEvent = static function (string $type, int $seconds, array $overrides = []): int {
        return (int) DB::table('play_by_plays')->insertGetId(array_merge([
            'nhl_game_id' => 2026020001,
            'event_owner_team_id' => 1,
            'period' => 1,
            'seconds_in_game' => $seconds,
            'type_desc_key' => $type,
            'strength' => 'EV',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    };

    $this->insertStatsUnitGame = static function (
        int $gameId,
        string $seasonId = '20262027',
        int $gameType = 2,
        string $gameDate = '2026-10-01'
    ): void {
        DB::table('nhl_games')->insert([
            'nhl_game_id' => $gameId,
            'season_id' => $seasonId,
            'game_type' => $gameType,
            'game_date' => $gameDate,
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

    $this->insertStatsUnitSummary = static function (int $gameId, int $unitId, array $overrides = []): void {
        DB::table('nhl_unit_game_strength_summaries')->insert(array_merge([
            'nhl_game_id' => $gameId,
            'unit_id' => $unitId,
            'team_id' => 1,
            'team_abbrev' => 'TOR',
            'strength' => 'EV',
            'toi' => 60,
            'shifts' => 1,
            'ozs' => 0,
            'nzs' => 0,
            'dzs' => 0,
            'gf' => 0,
            'ga' => 0,
            'sf' => 0,
            'sa' => 0,
            'satf' => 0,
            'sata' => 0,
            'ff' => 0,
            'fa' => 0,
            'bf' => 0,
            'ba' => 0,
            'hf' => 0,
            'ha' => 0,
            'fow' => 0,
            'fol' => 0,
            'fot' => 0,
            'pim_f' => 0,
            'pim_a' => 0,
            'penalties_f' => 0,
            'penalties_a' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    };
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('builds the same unit hash regardless of player order', function (): void {
    $resolver = app(ResolveNhlUnit::class);

    expect($resolver->compositionHash('F', [3, 1, 2]))
        ->toBe($resolver->compositionHash('F', [1, 2, 3]));
});

it('builds a different unit hash for a different unit type', function (): void {
    $resolver = app(ResolveNhlUnit::class);

    expect($resolver->compositionHash('F', [1, 2, 3]))
        ->not->toBe($resolver->compositionHash('PP', [1, 2, 3]));
});

it('resolves the same unit for the same sorted composition', function (): void {
    ($this->makePlayer)(1, 'One Player');
    ($this->makePlayer)(2, 'Two Player');

    $first = app(ResolveNhlUnit::class)->resolve('F', [2, 1], 'TOR');
    $second = app(ResolveNhlUnit::class)->resolve('F', [1, 2], 'TOR');

    expect($second->id)->toBe($first->id);
});

it('stores normalized composition player ids on resolved units', function (): void {
    ($this->makePlayer)(1, 'One Player');
    ($this->makePlayer)(2, 'Two Player');

    $unit = app(ResolveNhlUnit::class)->resolve('F', [2, 1], 'TOR');

    expect($unit->refresh()->composition_player_ids)->toBe([1, 2]);
});

it('syncs resolved unit players into the pivot table', function (): void {
    ($this->makePlayer)(1, 'One Player');
    ($this->makePlayer)(2, 'Two Player');

    $unit = app(ResolveNhlUnit::class)->resolve('F', [1, 2], 'TOR');

    expect(DB::table('nhl_unit_players')->where('unit_id', $unit->id)->count())->toBe(2);
});

it('creates EV unit strength summaries from forward unit shifts', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(1);
    $unit = app(ResolveNhlUnit::class)->resolve('F', [1], 'TOR');
    ($this->insertUnitShift)($unit->id, 'F');

    app()->make(SumNhlGameStrengthUnits::class, ['gameId' => 2026020001])->sum();

    $this->assertDatabaseHas('nhl_unit_game_strength_summaries', [
        'unit_id' => $unit->id,
        'strength' => 'EV',
        'toi' => 60,
    ]);
});

it('creates PP unit strength summaries from power-play units', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(1);
    $unit = app(ResolveNhlUnit::class)->resolve('PP', [1], 'TOR');
    ($this->insertUnitShift)($unit->id, 'PP');

    app()->make(SumNhlGameStrengthUnits::class, ['gameId' => 2026020001])->sum();

    $this->assertDatabaseHas('nhl_unit_game_strength_summaries', [
        'unit_id' => $unit->id,
        'strength' => 'PP',
    ]);
});

it('creates PK unit strength summaries from penalty-kill units', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(1);
    $unit = app(ResolveNhlUnit::class)->resolve('PK', [1], 'TOR');
    ($this->insertUnitShift)($unit->id, 'PK');

    app()->make(SumNhlGameStrengthUnits::class, ['gameId' => 2026020001])->sum();

    $this->assertDatabaseHas('nhl_unit_game_strength_summaries', [
        'unit_id' => $unit->id,
        'strength' => 'PK',
    ]);
});

it('counts goals for while the unit is on ice', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(1);
    $unit = app(ResolveNhlUnit::class)->resolve('F', [1], 'TOR');
    $shiftId = ($this->insertUnitShift)($unit->id, 'F');
    $eventId = ($this->insertEvent)('goal', 30, ['scoring_player_id' => 1]);
    DB::table('event_unit_shifts')->insert(['event_id' => $eventId, 'unit_shift_id' => $shiftId, 'created_at' => now(), 'updated_at' => now()]);

    app()->make(SumNhlGameStrengthUnits::class, ['gameId' => 2026020001])->sum();

    $this->assertDatabaseHas('nhl_unit_game_strength_summaries', ['unit_id' => $unit->id, 'gf' => 1]);
});

it('counts goals against while the unit is on ice', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(1);
    $unit = app(ResolveNhlUnit::class)->resolve('F', [1], 'TOR');
    $shiftId = ($this->insertUnitShift)($unit->id, 'F');
    $eventId = ($this->insertEvent)('goal', 30, ['event_owner_team_id' => 2]);
    DB::table('event_unit_shifts')->insert(['event_id' => $eventId, 'unit_shift_id' => $shiftId, 'created_at' => now(), 'updated_at' => now()]);

    app()->make(SumNhlGameStrengthUnits::class, ['gameId' => 2026020001])->sum();

    $this->assertDatabaseHas('nhl_unit_game_strength_summaries', ['unit_id' => $unit->id, 'ga' => 1]);
});

it('calculates plus minus from even-strength linked goal events', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(1, 'Home Skater');
    ($this->makePlayer)(2, 'Away Skater');
    DB::table('nhl_game_summaries')->insert([
        [
            'nhl_game_id' => 2026020001,
            'nhl_player_id' => 1,
            'nhl_team_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'nhl_game_id' => 2026020001,
            'nhl_player_id' => 2,
            'nhl_team_id' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    $homeUnit = app(ResolveNhlUnit::class)->resolve('F', [1], 'TOR');
    $awayUnit = app(ResolveNhlUnit::class)->resolve('F', [2], 'MTL');
    $homeShiftId = ($this->insertUnitShift)($homeUnit->id, 'F', 0, 60, ['team_id' => 1, 'team_abbrev' => 'TOR']);
    $awayShiftId = ($this->insertUnitShift)($awayUnit->id, 'F', 0, 60, ['team_id' => 2, 'team_abbrev' => 'MTL']);
    $eventId = ($this->insertEvent)('goal', 30, ['event_owner_team_id' => 1, 'strength' => 'EV']);
    DB::table('event_unit_shifts')->insert([
        ['event_id' => $eventId, 'unit_shift_id' => $homeShiftId, 'created_at' => now(), 'updated_at' => now()],
        ['event_id' => $eventId, 'unit_shift_id' => $awayShiftId, 'created_at' => now(), 'updated_at' => now()],
    ]);

    app()->make(SumNhlGameUnits::class, ['gameId' => 2026020001])->sum();

    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 1,
        'plus_minus' => 1,
    ]);
    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 2,
        'plus_minus' => -1,
    ]);
});

it('reconciles persisted plus minus to official boxscore values when available', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(1, 'Home Skater');
    DB::table('nhl_game_summaries')->insert([
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 1,
        'nhl_team_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('nhl_boxscores')->insert([
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 1,
        'nhl_team_id' => 1,
        'position' => 'C',
        'plus_minus' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $unit = app(ResolveNhlUnit::class)->resolve('F', [1], 'TOR');
    $shiftId = ($this->insertUnitShift)($unit->id, 'F', 0, 60, ['team_id' => 1, 'team_abbrev' => 'TOR']);
    $eventId = ($this->insertEvent)('goal', 30, ['event_owner_team_id' => 1, 'strength' => 'EV']);
    DB::table('event_unit_shifts')->insert([
        'event_id' => $eventId,
        'unit_shift_id' => $shiftId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app()->make(SumNhlGameUnits::class, ['gameId' => 2026020001])->sum();

    $this->assertDatabaseHas('nhl_game_summaries', [
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 1,
        'plus_minus' => 0,
    ]);
});

it('excludes power-play goals from plus minus', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(1, 'Home Skater');
    ($this->makePlayer)(2, 'Away Skater');
    DB::table('nhl_game_summaries')->insert([
        ['nhl_game_id' => 2026020001, 'nhl_player_id' => 1, 'nhl_team_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['nhl_game_id' => 2026020001, 'nhl_player_id' => 2, 'nhl_team_id' => 2, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $homeUnit = app(ResolveNhlUnit::class)->resolve('PP', [1], 'TOR');
    $awayUnit = app(ResolveNhlUnit::class)->resolve('PK', [2], 'MTL');
    $homeShiftId = ($this->insertUnitShift)($homeUnit->id, 'PP', 0, 60, ['team_id' => 1, 'team_abbrev' => 'TOR']);
    $awayShiftId = ($this->insertUnitShift)($awayUnit->id, 'PK', 0, 60, ['team_id' => 2, 'team_abbrev' => 'MTL']);
    $eventId = ($this->insertEvent)('goal', 30, ['event_owner_team_id' => 1, 'strength' => 'PP']);
    DB::table('event_unit_shifts')->insert([
        ['event_id' => $eventId, 'unit_shift_id' => $homeShiftId, 'created_at' => now(), 'updated_at' => now()],
        ['event_id' => $eventId, 'unit_shift_id' => $awayShiftId, 'created_at' => now(), 'updated_at' => now()],
    ]);

    app()->make(SumNhlGameUnits::class, ['gameId' => 2026020001])->sum();

    $this->assertDatabaseHas('nhl_game_summaries', ['nhl_player_id' => 1, 'plus_minus' => 0]);
    $this->assertDatabaseHas('nhl_game_summaries', ['nhl_player_id' => 2, 'plus_minus' => 0]);
});

it('includes shorthanded goals in plus minus', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(1, 'Home Skater');
    ($this->makePlayer)(2, 'Away Skater');
    DB::table('nhl_game_summaries')->insert([
        ['nhl_game_id' => 2026020001, 'nhl_player_id' => 1, 'nhl_team_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['nhl_game_id' => 2026020001, 'nhl_player_id' => 2, 'nhl_team_id' => 2, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $homeUnit = app(ResolveNhlUnit::class)->resolve('PP', [1], 'TOR');
    $awayUnit = app(ResolveNhlUnit::class)->resolve('PK', [2], 'MTL');
    $homeShiftId = ($this->insertUnitShift)($homeUnit->id, 'PP', 0, 60, ['team_id' => 1, 'team_abbrev' => 'TOR']);
    $awayShiftId = ($this->insertUnitShift)($awayUnit->id, 'PK', 0, 60, ['team_id' => 2, 'team_abbrev' => 'MTL']);
    $eventId = ($this->insertEvent)('goal', 30, ['event_owner_team_id' => 2, 'strength' => 'PK']);
    DB::table('event_unit_shifts')->insert([
        ['event_id' => $eventId, 'unit_shift_id' => $homeShiftId, 'created_at' => now(), 'updated_at' => now()],
        ['event_id' => $eventId, 'unit_shift_id' => $awayShiftId, 'created_at' => now(), 'updated_at' => now()],
    ]);

    app()->make(SumNhlGameUnits::class, ['gameId' => 2026020001])->sum();

    $this->assertDatabaseHas('nhl_game_summaries', ['nhl_player_id' => 1, 'plus_minus' => -1]);
    $this->assertDatabaseHas('nhl_game_summaries', ['nhl_player_id' => 2, 'plus_minus' => 1]);
});

it('deduplicates a player linked through multiple units on the same goal', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(1, 'Home Skater');
    DB::table('nhl_game_summaries')->insert([
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 1,
        'nhl_team_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $forwardUnit = app(ResolveNhlUnit::class)->resolve('F', [1], 'TOR');
    $powerPlayUnit = app(ResolveNhlUnit::class)->resolve('PP', [1], 'TOR');
    $forwardShiftId = ($this->insertUnitShift)($forwardUnit->id, 'F', 0, 60, ['team_id' => 1, 'team_abbrev' => 'TOR']);
    $powerPlayShiftId = ($this->insertUnitShift)($powerPlayUnit->id, 'PP', 0, 60, ['team_id' => 1, 'team_abbrev' => 'TOR']);
    $eventId = ($this->insertEvent)('goal', 30, ['event_owner_team_id' => 1, 'strength' => 'EV']);
    DB::table('event_unit_shifts')->insert([
        ['event_id' => $eventId, 'unit_shift_id' => $forwardShiftId, 'created_at' => now(), 'updated_at' => now()],
        ['event_id' => $eventId, 'unit_shift_id' => $powerPlayShiftId, 'created_at' => now(), 'updated_at' => now()],
    ]);

    app()->make(SumNhlGameUnits::class, ['gameId' => 2026020001])->sum();

    $this->assertDatabaseHas('nhl_game_summaries', ['nhl_player_id' => 1, 'plus_minus' => 1]);
});

it('excludes penalty shot goals from plus minus', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(1, 'Home Skater');
    ($this->makePlayer)(2, 'Away Skater');
    DB::table('nhl_game_summaries')->insert([
        ['nhl_game_id' => 2026020001, 'nhl_player_id' => 1, 'nhl_team_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['nhl_game_id' => 2026020001, 'nhl_player_id' => 2, 'nhl_team_id' => 2, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $homeUnit = app(ResolveNhlUnit::class)->resolve('F', [1], 'TOR');
    $awayUnit = app(ResolveNhlUnit::class)->resolve('F', [2], 'MTL');
    $homeShiftId = ($this->insertUnitShift)($homeUnit->id, 'F', 0, 60, ['team_id' => 1, 'team_abbrev' => 'TOR']);
    $awayShiftId = ($this->insertUnitShift)($awayUnit->id, 'F', 0, 60, ['team_id' => 2, 'team_abbrev' => 'MTL']);
    ($this->insertEvent)('penalty', 30, [
        'event_owner_team_id' => 2,
        'time_in_period' => '00:30',
        'penalty_type_code' => 'PS',
    ]);
    $eventId = ($this->insertEvent)('goal', 30, [
        'event_owner_team_id' => 1,
        'strength' => 'EV',
        'time_in_period' => '00:30',
    ]);
    DB::table('event_unit_shifts')->insert([
        ['event_id' => $eventId, 'unit_shift_id' => $homeShiftId, 'created_at' => now(), 'updated_at' => now()],
        ['event_id' => $eventId, 'unit_shift_id' => $awayShiftId, 'created_at' => now(), 'updated_at' => now()],
    ]);

    app()->make(SumNhlGameUnits::class, ['gameId' => 2026020001])->sum();

    $this->assertDatabaseHas('nhl_game_summaries', ['nhl_player_id' => 1, 'plus_minus' => 0]);
    $this->assertDatabaseHas('nhl_game_summaries', ['nhl_player_id' => 2, 'plus_minus' => 0]);
});

it('counts goals as shots for', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(1);
    $unit = app(ResolveNhlUnit::class)->resolve('F', [1], 'TOR');
    $shiftId = ($this->insertUnitShift)($unit->id, 'F');
    $eventId = ($this->insertEvent)('goal', 30, ['shot_type' => 'wrist']);
    DB::table('event_unit_shifts')->insert(['event_id' => $eventId, 'unit_shift_id' => $shiftId, 'created_at' => now(), 'updated_at' => now()]);

    app()->make(SumNhlGameStrengthUnits::class, ['gameId' => 2026020001])->sum();

    $this->assertDatabaseHas('nhl_unit_game_strength_summaries', ['unit_id' => $unit->id, 'sf' => 1]);
});

it('counts missed shots as Fenwick and shot attempts', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(1);
    $unit = app(ResolveNhlUnit::class)->resolve('F', [1], 'TOR');
    $shiftId = ($this->insertUnitShift)($unit->id, 'F');
    $eventId = ($this->insertEvent)('missed-shot', 30);
    DB::table('event_unit_shifts')->insert(['event_id' => $eventId, 'unit_shift_id' => $shiftId, 'created_at' => now(), 'updated_at' => now()]);

    app()->make(SumNhlGameStrengthUnits::class, ['gameId' => 2026020001])->sum();

    $this->assertDatabaseHas('nhl_unit_game_strength_summaries', ['unit_id' => $unit->id, 'ff' => 1, 'satf' => 1]);
});

it('counts blocked shots as shot attempts and blocks for the defending unit', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(1);
    $unit = app(ResolveNhlUnit::class)->resolve('F', [1], 'TOR');
    $shiftId = ($this->insertUnitShift)($unit->id, 'F');
    $eventId = ($this->insertEvent)('blocked-shot', 30, ['event_owner_team_id' => 2]);
    DB::table('event_unit_shifts')->insert(['event_id' => $eventId, 'unit_shift_id' => $shiftId, 'created_at' => now(), 'updated_at' => now()]);

    app()->make(SumNhlGameStrengthUnits::class, ['gameId' => 2026020001])->sum();

    $this->assertDatabaseHas('nhl_unit_game_strength_summaries', ['unit_id' => $unit->id, 'bf' => 1, 'sata' => 1]);
});

it('counts faceoff wins and totals', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(1);
    $unit = app(ResolveNhlUnit::class)->resolve('F', [1], 'TOR');
    $shiftId = ($this->insertUnitShift)($unit->id, 'F');
    $eventId = ($this->insertEvent)('faceoff', 30, ['fo_winning_player_id' => 1, 'zone_code' => 'O']);
    DB::table('event_unit_shifts')->insert(['event_id' => $eventId, 'unit_shift_id' => $shiftId, 'created_at' => now(), 'updated_at' => now()]);

    app()->make(SumNhlGameStrengthUnits::class, ['gameId' => 2026020001])->sum();

    $this->assertDatabaseHas('nhl_unit_game_strength_summaries', ['unit_id' => $unit->id, 'fow' => 1, 'fot' => 1, 'ozs' => 1]);
});

it('creates player strength summaries from unit membership', function (): void {
    ($this->insertGame)();
    $player = ($this->makePlayer)(1);
    $unit = app(ResolveNhlUnit::class)->resolve('F', [1], 'TOR');
    ($this->insertUnitShift)($unit->id, 'F');

    app()->make(SumNhlGameStrengthUnits::class, ['gameId' => 2026020001])->sum();

    $this->assertDatabaseHas('nhl_player_game_strength_summaries', [
        'player_id' => $player->id,
        'strength' => 'EV',
        'toi' => 60,
    ]);
});

it('stores individual points for player IPP', function (): void {
    ($this->insertGame)();
    $player = ($this->makePlayer)(1);
    $unit = app(ResolveNhlUnit::class)->resolve('F', [1], 'TOR');
    $shiftId = ($this->insertUnitShift)($unit->id, 'F');
    $eventId = ($this->insertEvent)('goal', 30, ['scoring_player_id' => 1]);
    DB::table('event_unit_shifts')->insert(['event_id' => $eventId, 'unit_shift_id' => $shiftId, 'created_at' => now(), 'updated_at' => now()]);

    app()->make(SumNhlGameStrengthUnits::class, ['gameId' => 2026020001])->sum();

    $this->assertDatabaseHas('nhl_player_game_strength_summaries', [
        'player_id' => $player->id,
        'individual_g' => 1,
        'individual_pts' => 1,
        'ipp' => 1,
    ]);
});

it('queries unit totals by strength from summary tables', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(1);
    $unit = app(ResolveNhlUnit::class)->resolve('F', [1], 'TOR');
    ($this->insertUnitShift)($unit->id, 'F');
    app()->make(SumNhlGameStrengthUnits::class, ['gameId' => 2026020001])->sum();

    $rows = app(NhlStrengthStatsQuery::class)->units(['strength' => 'EV'], 'total');

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->toi)->toBe(60);
});

it('queries player totals by season and game type', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(1);
    $unit = app(ResolveNhlUnit::class)->resolve('F', [1], 'TOR');
    ($this->insertUnitShift)($unit->id, 'F');
    app()->make(SumNhlGameStrengthUnits::class, ['gameId' => 2026020001])->sum();

    $rows = app(NhlStrengthStatsQuery::class)->players(['season_id' => '20262027', 'game_type' => 2], 'total');

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->gp)->toBe(1);
});

it('derives per-game slices from totals', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(1);
    $unit = app(ResolveNhlUnit::class)->resolve('F', [1], 'TOR');
    ($this->insertUnitShift)($unit->id, 'F', 0, 120);
    app()->make(SumNhlGameStrengthUnits::class, ['gameId' => 2026020001])->sum();

    $row = app(NhlStrengthStatsQuery::class)->units(['strength' => 'EV'], 'pgp')->first();

    expect($row->toi)->toBe(120.0);
});

it('derives per-60 slices from totals', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(1);
    $unit = app(ResolveNhlUnit::class)->resolve('F', [1], 'TOR');
    $shiftId = ($this->insertUnitShift)($unit->id, 'F', 0, 1800);
    $eventId = ($this->insertEvent)('goal', 30);
    DB::table('event_unit_shifts')->insert(['event_id' => $eventId, 'unit_shift_id' => $shiftId, 'created_at' => now(), 'updated_at' => now()]);
    app()->make(SumNhlGameStrengthUnits::class, ['gameId' => 2026020001])->sum();

    $row = app(NhlStrengthStatsQuery::class)->units(['strength' => 'EV'], 'p60')->first();

    expect($row->gf)->toBe(2.0);
});

it('filters query results by date range', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(1);
    $unit = app(ResolveNhlUnit::class)->resolve('F', [1], 'TOR');
    ($this->insertUnitShift)($unit->id, 'F');
    app()->make(SumNhlGameStrengthUnits::class, ['gameId' => 2026020001])->sum();

    $rows = app(NhlStrengthStatsQuery::class)->units([
        'date_from' => '2026-10-01',
        'date_to' => '2026-10-01',
    ]);

    expect($rows)->toHaveCount(1);
});

it('returns no rows for a date range outside the game date', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(1);
    $unit = app(ResolveNhlUnit::class)->resolve('F', [1], 'TOR');
    ($this->insertUnitShift)($unit->id, 'F');
    app()->make(SumNhlGameStrengthUnits::class, ['gameId' => 2026020001])->sum();

    $rows = app(NhlStrengthStatsQuery::class)->units([
        'date_from' => '2026-10-02',
        'date_to' => '2026-10-03',
    ]);

    expect($rows)->toHaveCount(0);
});

it('rolls goalie fantasy fields into nhl season stats', function (): void {
    ($this->insertGame)();
    ($this->insertGame)(2026020002);
    $goalie = ($this->makePlayer)(30, 'Goalie Player');
    $goalie->update([
        'position' => 'G',
        'pos_type' => 'G',
        'is_goalie' => false,
    ]);

    DB::table('nhl_game_summaries')->insert([
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 30,
        'nhl_team_id' => 1,
        'toi' => 3600,
        'sa' => 40,
        'sv' => 38,
        'ga' => 2,
        'evsa' => 30,
        'evsv' => 29,
        'ppsa' => 5,
        'ppsv' => 4,
        'pksa' => 5,
        'pksv' => 5,
        'goalie_started' => true,
        'goalie_decision' => 'W',
        'quality_start' => true,
        'really_bad_start' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('nhl_game_summaries')->insert([
        'nhl_game_id' => 2026020002,
        'nhl_player_id' => 30,
        'nhl_team_id' => 1,
        'toi' => 0,
        'sa' => 0,
        'sv' => 0,
        'ga' => 0,
        'goalie_started' => false,
        'goalie_decision' => null,
        'quality_start' => false,
        'really_bad_start' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(SumNhlSeasonStats::class)->sum('20262027');

    $row = DB::table('nhl_season_stats')
        ->where('season_id', '20262027')
        ->where('nhl_player_id', 30)
        ->where('game_type', 2)
        ->first();

    expect((int) $row->wins)->toBe(1)
        ->and((int) $row->gp)->toBe(1)
        ->and((int) $row->losses)->toBe(0)
        ->and((int) $row->ot_losses)->toBe(0)
        ->and((int) $row->starts)->toBe(1)
        ->and((int) $row->quality_starts)->toBe(1)
        ->and((int) $row->really_bad_starts)->toBe(0)
        ->and((float) $row->sv_pct)->toBe(0.95)
        ->and((float) $row->gaa)->toBe(2.0)
        ->and((float) $row->ev_sv_pct)->toBe(0.967)
        ->and((float) $row->pp_sv_pct)->toBe(0.8)
        ->and((float) $row->pk_sv_pct)->toBe(1.0)
        ->and((float) $row->quality_start_percentage)->toBe(1.0);
});

it('uses official boxscore goalie decisions before primary goalie fallback', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(31, 'Relief Winner')->update([
        'position' => 'G',
        'pos_type' => 'G',
    ]);
    ($this->makePlayer)(32, 'Starter No Decision')->update([
        'position' => 'G',
        'pos_type' => 'G',
    ]);

    $method = new ReflectionMethod(ImportNhlBoxscore::class, 'processGoalieFantasyStats');
    $method->setAccessible(true);
    $method->invoke(app(ImportNhlBoxscore::class), 2026020001, 1, [
        [
            'playerId' => 31,
            'toi' => '20:00',
            'shotsAgainst' => 10,
            'saves' => 10,
            'goalsAgainst' => 0,
            'evenStrengthShotsAgainst' => '10/10',
            'powerPlayShotsAgainst' => '0/0',
            'shorthandedShotsAgainst' => '0/0',
            'starter' => false,
            'decision' => 'W',
        ],
        [
            'playerId' => 32,
            'toi' => '40:00',
            'shotsAgainst' => 20,
            'saves' => 18,
            'goalsAgainst' => 2,
            'evenStrengthShotsAgainst' => '18/20',
            'powerPlayShotsAgainst' => '0/0',
            'shorthandedShotsAgainst' => '0/0',
            'starter' => true,
            'decision' => 'ND',
        ],
    ], [
        'homeTeam' => ['id' => 1, 'score' => 3],
        'awayTeam' => ['id' => 2, 'score' => 2],
        'periodDescriptor' => ['periodType' => 'REG'],
    ]);

    $reliefWinner = DB::table('nhl_game_summaries')
        ->where('nhl_game_id', 2026020001)
        ->where('nhl_player_id', 31)
        ->first();
    $starter = DB::table('nhl_game_summaries')
        ->where('nhl_game_id', 2026020001)
        ->where('nhl_player_id', 32)
        ->first();

    expect($reliefWinner?->goalie_decision)->toBe('W')
        ->and($starter?->goalie_decision)->toBe('ND');
});

it('exposes native advanced skater aliases and perspective position buttons in stats payload', function (): void {
    ($this->insertGame)();
    $player = ($this->makePlayer)(1, 'Advanced Skater');

    DB::table('nhl_season_stats')->insert([
        'season_id' => '20262027',
        'nhl_player_id' => 1,
        'nhl_team_id' => 1,
        'gp' => 1,
        'game_type' => 2,
        'g' => 1,
        'a' => 1,
        'pts' => 2,
        'toi' => 1200,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('nhl_player_game_strength_summaries')->insert([
        'nhl_game_id' => 2026020001,
        'player_id' => $player->id,
        'nhl_player_id' => 1,
        'team_id' => 1,
        'team_abbrev' => 'TOR',
        'strength' => 'EV',
        'toi' => 1200,
        'gf' => 4,
        'ga' => 1,
        'sf' => 20,
        'sa' => 10,
        'satf' => 30,
        'sata' => 15,
        'ff' => 24,
        'fa' => 12,
        'ozs' => 8,
        'dzs' => 2,
        'individual_g' => 1,
        'individual_a' => 2,
        'individual_pts' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Perspective::create([
        'name' => 'Native Advanced Test',
        'slug' => 'native-advanced-test',
        'visibility' => 'public_guest',
        'sport' => 'hockey',
        'is_slicable' => false,
        'settings' => [
            'columns' => [
                ['key' => 'ipp', 'label' => 'IPP', 'type' => 'float'],
                ['key' => 'gf', 'label' => 'GF', 'type' => 'int'],
                ['key' => 'ga', 'label' => 'GA', 'type' => 'int'],
                ['key' => 'cf_pct', 'label' => 'CF%', 'type' => 'float'],
                ['key' => 'pdo', 'label' => 'PDO', 'type' => 'float'],
                ['key' => 'ozs_pct', 'label' => 'OZS%', 'type' => 'float'],
            ],
            'sort' => [
                'sortKey' => 'ipp',
                'sortDirection' => 'desc',
            ],
            'filters' => [
                'pos_type' => [
                    'operator' => '!=',
                    'value' => 'G',
                    'locked' => true,
                ],
            ],
            'ui' => [
                'positionButtons' => ['F', 'C', 'LW', 'RW', 'D'],
            ],
        ],
    ]);

    $response = $this->getJson(route('stats.payload', [
        'perspective' => 'native-advanced-test',
        'season_id' => '20262027',
        'game_type' => 2,
    ]));

    $response->assertOk()
        ->assertJsonPath('meta.positionButtons', ['F', 'C', 'LW', 'RW', 'D']);

    $row = $response->json('data.0');

    expect($row['name'])->toBe('Advanced Skater')
        ->and($row['ipp'])->toBe(0.75)
        ->and($row['gf'])->toBe(4)
        ->and($row['ga'])->toBe(1)
        ->and($row['cf'])->toBe(30)
        ->and($row['ca'])->toBe(15)
        ->and($row['cf_pct'])->toBe(0.667)
        ->and($row['pdo'])->toBe(1.1)
        ->and($row['ozs_pct'])->toBe(0.8);
});

it('keeps native goalie goals against when goalie scoring columns share on-ice keys', function (): void {
    $this->actingAs(User::factory()->create());
    ($this->insertGame)();
    $goalie = ($this->makePlayer)(48, 'Native Goalie');
    $goalie->update([
        'position' => 'G',
        'pos_type' => 'G',
        'is_goalie' => true,
    ]);

    DB::table('nhl_season_stats')->insert([
        'season_id' => '20262027',
        'nhl_player_id' => 48,
        'nhl_team_id' => 1,
        'gp' => 47,
        'game_type' => 2,
        'ga' => 160,
        'sa' => 1285,
        'sv' => 1125,
        'toi' => 155541,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('nhl_player_game_strength_summaries')->insert([
        'nhl_game_id' => 2026020001,
        'player_id' => $goalie->id,
        'nhl_player_id' => 48,
        'team_id' => 1,
        'team_abbrev' => 'TOR',
        'strength' => 'EV',
        'toi' => 1200,
        'gf' => 2,
        'ga' => 91,
        'sf' => 10,
        'sa' => 20,
        'satf' => 12,
        'sata' => 22,
        'ff' => 11,
        'fa' => 21,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Perspective::create([
        'name' => 'Goalie Scoring Test',
        'slug' => 'goalie-scoring-test',
        'visibility' => 'public_guest',
        'sport' => 'hockey',
        'is_slicable' => false,
        'settings' => [
            'columns' => [
                [
                    'key' => 'ga',
                    'label' => 'GA',
                    'type' => 'int',
                    'fantasy_scoring_category' => true,
                    'normalized_group' => 'HOCKEY_GOALIE',
                    'is_supported' => true,
                ],
            ],
            'filters' => [
                'pos_type' => [
                    'operator' => '=',
                    'value' => ['G'],
                    'locked' => true,
                ],
            ],
        ],
    ]);

    $response = $this->getJson(route('stats.payload', [
        'perspective' => 'goalie-scoring-test',
        'season_id' => '20262027',
        'game_type' => 2,
    ]));

    $response->assertOk();

    $row = $response->json('data.0');

    expect($row['gp'])->toBe(47)
        ->and($row['ga'])->toBe(160);
});

it('defaults line combos to the latest season regular-season segment', function (): void {
    $this->actingAs(User::factory()->create());
    ($this->makePlayer)(41, 'Old Player');
    ($this->makePlayer)(42, 'Fresh Player');
    $oldUnit = app(ResolveNhlUnit::class)->resolve('F', [41], 'TOR');
    $freshUnit = app(ResolveNhlUnit::class)->resolve('F', [42], 'TOR');
    ($this->insertStatsUnitGame)(2025020001, '20252026', 2, '2025-10-01');
    ($this->insertStatsUnitGame)(2026020001, '20262027', 2, '2026-10-01');
    ($this->insertStatsUnitSummary)(2025020001, $oldUnit->id, ['gf' => 4]);
    ($this->insertStatsUnitSummary)(2026020001, $freshUnit->id, ['gf' => 7]);

    $response = $this->get(route('stats.units.index'));

    $response->assertOk()
        ->assertSee('2026-27')
        ->assertSee('Regular Season')
        ->assertSee('F. Player')
        ->assertDontSee('O. Player');
});

it('renders player avatars in line combo season cards', function (): void {
    $this->actingAs(User::factory()->create());
    $player = ($this->makePlayer)(50, 'Avatar Player');
    $player->update(['head_shot_url' => 'https://example.test/avatar-player.png']);
    $unit = app(ResolveNhlUnit::class)->resolve('F', [50], 'TOR');
    ($this->insertStatsUnitGame)(2026020007, '20262027', 2, '2026-10-10');
    ($this->insertStatsUnitSummary)(2026020007, $unit->id, ['gf' => 1]);

    $response = $this->get(route('stats.units.index', [
        'season_id' => '20262027',
        'game_type' => 2,
        'pos' => ['F'],
    ]));

    $response->assertOk()
        ->assertSee('https://example.test/avatar-player.png')
        ->assertSee('A. Player');
});

it('renders penalty kill units as forwards row above defense row when positions are known', function (): void {
    $this->actingAs(User::factory()->create());
    $forwardOne = ($this->makePlayer)(51, 'First Forward');
    $forwardTwo = ($this->makePlayer)(52, 'Second Forward');
    $defenseOne = ($this->makePlayer)(53, 'First Defense');
    $defenseTwo = ($this->makePlayer)(54, 'Second Defense');
    $defenseOne->update(['position' => 'D', 'pos_type' => 'D']);
    $defenseTwo->update(['position' => 'D', 'pos_type' => 'D']);
    $unit = app(ResolveNhlUnit::class)->resolve('PK', [51, 52, 53, 54], 'TOR');
    ($this->insertStatsUnitGame)(2026020008, '20262027', 2, '2026-10-11');
    ($this->insertStatsUnitSummary)(2026020008, $unit->id, ['strength' => 'PK', 'gf' => 1]);

    $response = $this->get(route('stats.units.index', [
        'season_id' => '20262027',
        'game_type' => 2,
        'pos' => ['PK'],
    ]));

    $html = $response->getContent();

    $response->assertOk()
        ->assertSee('data-unit-player-layout="pk"', false)
        ->assertSee('data-unit-player-row="forwards"', false)
        ->assertSee('data-unit-player-row="defense"', false);
    expect(strpos($html, 'F. Forward'))->toBeLessThan(strpos($html, 'F. Defense'))
        ->and(strpos($html, 'S. Forward'))->toBeLessThan(strpos($html, 'S. Defense'));
});

it('filters line combo season totals by preseason segment', function (): void {
    $this->actingAs(User::factory()->create());
    ($this->makePlayer)(43, 'Pre Player');
    ($this->makePlayer)(44, 'Reg Player');
    $preUnit = app(ResolveNhlUnit::class)->resolve('F', [43], 'TOR');
    $regUnit = app(ResolveNhlUnit::class)->resolve('F', [44], 'TOR');
    ($this->insertStatsUnitGame)(2026010001, '20262027', 1, '2026-09-20');
    ($this->insertStatsUnitGame)(2026020002, '20262027', 2, '2026-10-05');
    ($this->insertStatsUnitSummary)(2026010001, $preUnit->id, ['gf' => 2]);
    ($this->insertStatsUnitSummary)(2026020002, $regUnit->id, ['gf' => 8]);

    $response = $this->get(route('stats.units.index', [
        'season_id' => '20262027',
        'game_type' => 1,
        'pos' => ['F'],
    ]));

    $response->assertOk()
        ->assertSee('Preseason')
        ->assertSee('P. Player')
        ->assertDontSee('R. Player');
});

it('aggregates line combo strength summaries across the selected season segment', function (): void {
    $this->actingAs(User::factory()->create());
    ($this->makePlayer)(45, 'Aggregate Player');
    $unit = app(ResolveNhlUnit::class)->resolve('F', [45], 'TOR');
    ($this->insertStatsUnitGame)(2026020003, '20262027', 2, '2026-10-06');
    ($this->insertStatsUnitGame)(2026020004, '20262027', 2, '2026-10-07');
    ($this->insertStatsUnitSummary)(2026020003, $unit->id, ['gf' => 1, 'sf' => 4, 'toi' => 120]);
    ($this->insertStatsUnitSummary)(2026020004, $unit->id, ['gf' => 2, 'sf' => 5, 'toi' => 180]);

    $response = $this->get(route('stats.units.index', [
        'season_id' => '20262027',
        'game_type' => 2,
        'pos' => ['F'],
    ]));

    $row = $response->viewData('units')->items()[0];

    $response->assertOk();
    expect((int) $row->gp)->toBe(2)
        ->and((int) $row->gf)->toBe(3)
        ->and((int) $row->sf)->toBe(9)
        ->and((int) $row->toi)->toBe(300);
});

it('sorts line combo season totals by the selected aggregate column', function (): void {
    $this->actingAs(User::factory()->create());
    ($this->makePlayer)(46, 'Lower Player');
    ($this->makePlayer)(47, 'Higher Player');
    $lowerUnit = app(ResolveNhlUnit::class)->resolve('F', [46], 'TOR');
    $higherUnit = app(ResolveNhlUnit::class)->resolve('F', [47], 'TOR');
    ($this->insertStatsUnitGame)(2026020005, '20262027', 2, '2026-10-08');
    ($this->insertStatsUnitSummary)(2026020005, $lowerUnit->id, ['sf' => 3]);
    ($this->insertStatsUnitSummary)(2026020005, $higherUnit->id, ['sf' => 9]);

    $response = $this->get(route('stats.units.index', [
        'season_id' => '20262027',
        'game_type' => 2,
        'pos' => ['F'],
        'sort' => 'sf',
        'dir' => 'desc',
    ]));

    $row = $response->viewData('units')->items()[0];

    $response->assertOk();
    expect((int) $row->unit_id)->toBe((int) $higherUnit->id);
});

it('filters line combo season totals by selected season', function (): void {
    $this->actingAs(User::factory()->create());
    ($this->makePlayer)(48, 'Selected Season Player');
    ($this->makePlayer)(49, 'Other Season Player');
    $selectedUnit = app(ResolveNhlUnit::class)->resolve('F', [48], 'TOR');
    $otherUnit = app(ResolveNhlUnit::class)->resolve('F', [49], 'TOR');
    ($this->insertStatsUnitGame)(2025020002, '20252026', 2, '2025-10-02');
    ($this->insertStatsUnitGame)(2026020006, '20262027', 2, '2026-10-09');
    ($this->insertStatsUnitSummary)(2025020002, $selectedUnit->id, ['gf' => 5]);
    ($this->insertStatsUnitSummary)(2026020006, $otherUnit->id, ['gf' => 9]);

    $response = $this->get(route('stats.units.index', [
        'season_id' => '20252026',
        'game_type' => 2,
        'pos' => ['F'],
    ]));

    $response->assertOk()
        ->assertSee('2025-26')
        ->assertSee('S. Season Player')
        ->assertDontSee('O. Season Player');
});

it('defaults the stats page to skaters instead of the first visible perspective id', function (): void {
    $skater = ($this->makePlayer)(201, 'Season Skater');
    $prospect = ($this->makePlayer)(202, 'Draft Prospect');
    $prospect->update([
        'is_prospect' => true,
        'current_league_abbrev' => 'WHL',
    ]);

    DB::table('stats')->insert([
        'player_id' => $prospect->id,
        'is_prospect' => true,
        'player_name' => 'Draft Prospect',
        'season_id' => '20262027',
        'league_abbrev' => 'WHL',
        'team_name' => 'Seattle',
        'game_type_id' => 2,
        'gp' => 10,
        'pts' => 99,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('nhl_season_stats')->insert([
        'season_id' => '20262027',
        'nhl_player_id' => $skater->nhl_id,
        'nhl_team_id' => 1,
        'gp' => 10,
        'game_type' => 2,
        'pts' => 12,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Perspective::create([
        'name' => 'Prospects',
        'slug' => 'prospects',
        'visibility' => 'public_guest',
        'sport' => 'hockey',
        'is_slicable' => false,
        'settings' => [
            'columns' => [
                ['key' => 'pts', 'label' => 'PTS', 'type' => 'int'],
            ],
            'sort' => [
                'sortKey' => 'pts',
                'sortDirection' => 'desc',
            ],
            'filters' => [
                'league_abbrev' => [
                    'operator' => '!=',
                    'value' => 'NHL',
                    'locked' => true,
                ],
                'is_prospect' => [
                    'value' => true,
                    'locked' => true,
                ],
            ],
        ],
    ]);

    Perspective::create([
        'name' => 'Skaters',
        'slug' => 'skaters',
        'visibility' => 'public_guest',
        'sport' => 'hockey',
        'is_slicable' => true,
        'settings' => [
            'columns' => [
                ['key' => 'pts', 'label' => 'PTS', 'type' => 'int'],
            ],
            'sort' => [
                'sortKey' => 'pts',
                'sortDirection' => 'desc',
            ],
            'filters' => [
                'pos_type' => [
                    'operator' => '!=',
                    'value' => 'G',
                    'locked' => true,
                ],
            ],
        ],
    ]);

    $response = $this->get(route('stats.index'));

    $response->assertOk()
        ->assertViewHas('selectedSlug', 'skaters')
        ->assertViewHas('payload', function (array $payload): bool {
            $names = collect($payload['data'])->pluck('name')->all();

            return $names === ['Season Skater'];
        });
});

it('uses official boxscore toi for stats page per-60 rate calculations', function (): void {
    ($this->insertGame)();
    ($this->makePlayer)(201, 'Boxscore Toi');

    DB::table('nhl_season_stats')->insert([
        'season_id' => '20262027',
        'nhl_player_id' => 201,
        'nhl_team_id' => 1,
        'gp' => 1,
        'game_type' => 2,
        'toi' => 0,
        'g' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('nhl_boxscores')->insert([
        'nhl_game_id' => 2026020001,
        'nhl_player_id' => 201,
        'nhl_team_id' => 1,
        'toi' => '20:00',
        'toi_seconds' => null,
        'position' => 'C',
        'player_name' => 'Boxscore Toi',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Perspective::create([
        'name' => 'Boxscore Toi Test',
        'slug' => 'boxscore-toi-test',
        'visibility' => 'public_guest',
        'sport' => 'hockey',
        'is_slicable' => true,
        'settings' => [
            'columns' => [
                ['key' => 'toi', 'label' => 'TOI', 'type' => 'string'],
                ['key' => 'g', 'label' => 'G', 'type' => 'int'],
            ],
            'sort' => [
                'sortKey' => 'g',
                'sortDirection' => 'desc',
            ],
            'filters' => [
                'pos_type' => [
                    'operator' => '!=',
                    'value' => 'G',
                    'locked' => true,
                ],
            ],
        ],
    ]);

    $response = $this->getJson(route('stats.payload', [
        'perspective' => 'boxscore-toi-test',
        'season_id' => '20262027',
        'game_type' => 2,
        'slice' => 'p60',
    ]));

    $response->assertOk();

    $row = $response->json('data.0');

    expect($row['toi'])->toBe('20:00')
        ->and($row['g'])->toEqual(3.0);
});

it('maps wing position filters to stored NHL left and right position codes', function (): void {
    $leftWing = ($this->makePlayer)(101, 'Left Wing');
    $leftWing->update(['position' => 'L', 'pos_type' => 'F']);
    $center = ($this->makePlayer)(102, 'Center Skater');
    $center->update(['position' => 'C', 'pos_type' => 'F']);
    $rightWing = ($this->makePlayer)(103, 'Right Wing');
    $rightWing->update(['position' => 'R', 'pos_type' => 'F']);

    DB::table('nhl_season_stats')->insert([
        [
            'season_id' => '20262027',
            'nhl_player_id' => 101,
            'nhl_team_id' => 1,
            'gp' => 1,
            'game_type' => 2,
            'pts' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'season_id' => '20262027',
            'nhl_player_id' => 102,
            'nhl_team_id' => 1,
            'gp' => 1,
            'game_type' => 2,
            'pts' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'season_id' => '20262027',
            'nhl_player_id' => 103,
            'nhl_team_id' => 1,
            'gp' => 1,
            'game_type' => 2,
            'pts' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    Perspective::create([
        'name' => 'Position Filter Test',
        'slug' => 'position-filter-test',
        'visibility' => 'public_guest',
        'sport' => 'hockey',
        'settings' => [
            'columns' => [
                ['key' => 'pts', 'label' => 'PTS', 'type' => 'int'],
            ],
            'sort' => [
                'sortKey' => 'pts',
                'sortDirection' => 'desc',
            ],
            'filters' => [
                'pos_type' => [
                    'operator' => '!=',
                    'value' => 'G',
                    'locked' => true,
                ],
            ],
            'ui' => [
                'positionButtons' => ['F', 'C', 'LW', 'RW', 'D'],
            ],
        ],
    ]);

    $lw = $this->getJson(route('stats.payload', [
        'perspective' => 'position-filter-test',
        'season_id' => '20262027',
        'game_type' => 2,
        'pos' => ['LW'],
    ]));
    $c = $this->getJson(route('stats.payload', [
        'perspective' => 'position-filter-test',
        'season_id' => '20262027',
        'game_type' => 2,
        'pos' => ['C'],
    ]));
    $rw = $this->getJson(route('stats.payload', [
        'perspective' => 'position-filter-test',
        'season_id' => '20262027',
        'game_type' => 2,
        'pos' => ['RW'],
    ]));

    $lw->assertOk();
    $c->assertOk();
    $rw->assertOk();

    expect(collect($lw->json('data'))->pluck('name')->all())->toBe(['Left Wing'])
        ->and(collect($c->json('data'))->pluck('name')->all())->toBe(['Center Skater'])
        ->and(collect($rw->json('data'))->pluck('name')->all())->toBe(['Right Wing']);
});

it('limits the prospects perspective to players marked as prospects', function (): void {
    $prospect = ($this->makePlayer)(101, 'Future Prospect');
    $prospect->update([
        'is_prospect' => true,
        'current_league_abbrev' => 'OHL',
        'head_shot_url' => 'https://example.test/future-prospect.png',
    ]);
    $olderNonProspect = ($this->makePlayer)(102, 'Older Nonprospect');
    $olderNonProspect->update([
        'is_prospect' => false,
        'current_league_abbrev' => 'AHL',
    ]);

    DB::table('stats')->insert([
        [
            'player_id' => $prospect->id,
            'is_prospect' => true,
            'player_name' => 'Future Prospect',
            'season_id' => '20262027',
            'league_abbrev' => 'OHL',
            'team_name' => 'London',
            'game_type_id' => 2,
            'gp' => 10,
            'g' => 4,
            'a' => 6,
            'pts' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'player_id' => $olderNonProspect->id,
            'is_prospect' => false,
            'player_name' => 'Older Nonprospect',
            'season_id' => '20262027',
            'league_abbrev' => 'AHL',
            'team_name' => 'Marlies',
            'game_type_id' => 2,
            'gp' => 20,
            'g' => 8,
            'a' => 12,
            'pts' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    Perspective::create([
        'name' => 'Prospects',
        'slug' => 'prospects',
        'visibility' => 'public_guest',
        'sport' => 'hockey',
        'settings' => [
            'columns' => [
                ['key' => 'g', 'label' => 'G', 'type' => 'int'],
                ['key' => 'a', 'label' => 'A', 'type' => 'int'],
                ['key' => 'pts', 'label' => 'PTS', 'type' => 'int'],
            ],
            'sort' => [
                'sortKey' => 'pts',
                'sortDirection' => 'desc',
            ],
            'filters' => [
                'league_abbrev' => [
                    'operator' => '!=',
                    'value' => 'NHL',
                    'locked' => true,
                ],
            ],
        ],
    ]);

    $response = $this->getJson(route('stats.payload', [
        'perspective' => 'prospects',
        'season_id' => '20262027',
    ]));

    $response->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())
        ->toBe(['Future Prospect']);
    expect($response->json('data.0.avatar_url'))
        ->toBe('https://example.test/future-prospect.png');
});

it('exposes prospect goalie stats from legacy stats goalie columns', function (): void {
    $goalie = ($this->makePlayer)(201, 'Future Goalie');
    $goalie->update([
        'position' => 'G',
        'pos_type' => 'G',
        'is_goalie' => true,
        'is_prospect' => true,
        'current_league_abbrev' => 'WHL',
    ]);

    DB::table('stats')->insert([
        'player_id' => $goalie->id,
        'is_prospect' => true,
        'player_name' => 'Future Goalie',
        'season_id' => '20262027',
        'league_abbrev' => 'WHL',
        'team_name' => 'Seattle',
        'game_type_id' => 2,
        'gp' => 5,
        'wins' => 3,
        'losses' => 2,
        'saves' => null,
        'shots_against' => 100,
        'goals_against' => 10,
        'sv_pct' => null,
        'gaa' => null,
        'toi_minutes' => 300,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Perspective::create([
        'name' => 'Prospects - Goalies',
        'slug' => 'prospects-goalies',
        'visibility' => 'public_guest',
        'sport' => 'hockey',
        'is_slicable' => false,
        'settings' => [
            'columns' => [
                ['key' => 'wins', 'label' => 'W', 'type' => 'int'],
                ['key' => 'saves', 'label' => 'SV', 'type' => 'int'],
                ['key' => 'shots_against', 'label' => 'SA', 'type' => 'int'],
                ['key' => 'goals_against', 'label' => 'GA', 'type' => 'int'],
                ['key' => 'sv_pct', 'label' => 'SV%', 'type' => 'float'],
                ['key' => 'gaa', 'label' => 'GAA', 'type' => 'float'],
            ],
            'sort' => [
                'sortKey' => 'wins',
                'sortDirection' => 'desc',
            ],
            'filters' => [
                'league_abbrev' => [
                    'operator' => '!=',
                    'value' => 'NHL',
                    'locked' => true,
                ],
                'is_prospect' => [
                    'value' => true,
                    'locked' => true,
                ],
                'pos_type' => [
                    'value' => 'G',
                    'locked' => true,
                ],
            ],
            'ui' => [
                'positionButtons' => [],
            ],
        ],
    ]);

    $response = $this->getJson(route('stats.payload', [
        'perspective' => 'prospects-goalies',
        'season_id' => '20262027',
    ]));

    $response->assertOk();

    $row = $response->json('data.0');

    expect($row['name'])->toBe('Future Goalie')
        ->and($row['saves'])->toBe(90)
        ->and($row['shots_against'])->toBe(100)
        ->and($row['goals_against'])->toBe(10)
        ->and($row['sv_pct'])->toBe(0.9)
        ->and($row['gaa'])->toEqual(2.0);
});

it('groups prospect stats by player and league while summing same league teams', function (): void {
    $player = ($this->makePlayer)(301, 'League Split Prospect');
    $player->update([
        'is_prospect' => true,
        'team_abbrev' => 'CHI',
        'current_league_abbrev' => 'WHL',
    ]);

    DB::table('stats')->insert([
        [
            'player_id' => $player->id,
            'is_prospect' => true,
            'player_name' => 'League Split Prospect',
            'season_id' => '20262027',
            'league_abbrev' => 'WHL',
            'team_name' => 'Seattle',
            'game_type_id' => 2,
            'gp' => 10,
            'g' => 4,
            'a' => 6,
            'pts' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'player_id' => $player->id,
            'is_prospect' => true,
            'player_name' => 'League Split Prospect',
            'season_id' => '20262027',
            'league_abbrev' => 'WHL',
            'team_name' => 'Wenatchee',
            'game_type_id' => 2,
            'gp' => 12,
            'g' => 5,
            'a' => 7,
            'pts' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'player_id' => $player->id,
            'is_prospect' => true,
            'player_name' => 'League Split Prospect',
            'season_id' => '20262027',
            'league_abbrev' => 'AHL',
            'team_name' => 'Rockford',
            'game_type_id' => 2,
            'gp' => 3,
            'g' => 1,
            'a' => 2,
            'pts' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    Perspective::create([
        'name' => 'Prospects',
        'slug' => 'prospects',
        'visibility' => 'public_guest',
        'sport' => 'hockey',
        'is_slicable' => false,
        'settings' => [
            'columns' => [
                ['key' => 'g', 'label' => 'G', 'type' => 'int'],
                ['key' => 'a', 'label' => 'A', 'type' => 'int'],
                ['key' => 'pts', 'label' => 'PTS', 'type' => 'int'],
            ],
            'sort' => [
                'sortKey' => 'pts',
                'sortDirection' => 'desc',
            ],
            'filters' => [
                'league_abbrev' => [
                    'operator' => '!=',
                    'value' => 'NHL',
                    'locked' => true,
                ],
                'is_prospect' => [
                    'value' => true,
                    'locked' => true,
                ],
            ],
        ],
    ]);

    $response = $this->getJson(route('stats.payload', [
        'perspective' => 'prospects',
        'season_id' => '20262027',
    ]));

    $response->assertOk()
        ->assertJsonPath('meta.availableLeagues', ['AHL', 'WHL']);

    $rows = collect($response->json('data'));
    $whl = $rows->firstWhere('league', 'WHL');
    $ahl = $rows->firstWhere('league', 'AHL');

    expect($rows)->toHaveCount(2)
        ->and($whl['team'])->toBe('CHI')
        ->and($whl['gp'])->toBe(22)
        ->and($whl['pts'])->toBe(22)
        ->and($ahl['team'])->toBe('CHI')
        ->and($ahl['gp'])->toBe(3)
        ->and($ahl['pts'])->toBe(3);

    $filtered = $this->getJson(route('stats.payload', [
        'perspective' => 'prospects',
        'season_id' => '20262027',
        'league' => ['AHL'],
    ]));

    $filtered->assertOk();

    expect($filtered->json('data'))->toHaveCount(1)
        ->and($filtered->json('data.0.league'))->toBe('AHL');
});
