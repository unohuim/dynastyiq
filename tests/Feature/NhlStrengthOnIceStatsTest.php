<?php

declare(strict_types=1);

use App\Models\Player;
use App\Services\NhlStrengthStatsQuery;
use App\Services\ResolveNhlUnit;
use App\Services\SumNhlGameUnits;
use App\Services\SumNhlGameStrengthUnits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-29 12:00:00');

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
    $eventId = ($this->insertEvent)('goal', 30);
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
