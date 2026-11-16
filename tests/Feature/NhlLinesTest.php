<?php

declare(strict_types=1);

use App\Models\NhlGame;
use App\Models\Player;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-18 14:00:00 UTC'));
    Bus::fake();
    Http::preventStrayRequests();
    DB::table('nhl_teams')->insertOrIgnore([
        ['nhl_id' => 6, 'abbrev' => 'BOS', 'full_name' => 'Boston Bruins'],
        ['nhl_id' => 8, 'abbrev' => 'MTL', 'full_name' => 'Montreal Canadiens'],
        ['nhl_id' => 10, 'abbrev' => 'TOR', 'full_name' => 'Toronto Maple Leafs'],
    ]);
    $this->game = fn (int $id, string $date, array $extra = []): NhlGame => NhlGame::create([
        'nhl_game_id' => $id, 'season_id' => '20262027', 'game_type' => 2, 'game_date' => $date,
        'game_dow' => 'Tuesday', 'game_month' => 'November', 'game_state' => 'OFF',
        'game_schedule_state' => 'OK', 'start_time_utc' => $date.' 23:00:00',
        'home_team_id' => 10, 'home_team_abbrev' => 'TOR', 'away_team_id' => 8, 'away_team_abbrev' => 'MTL',
        ...$extra,
    ]);
    ($this->game)(2026020001, '2026-11-16');
    ($this->game)(2026020002, '2026-11-17');
    ($this->game)(2026020003, '2026-11-20', ['away_team_id' => 6, 'away_team_abbrev' => 'BOS', 'game_state' => 'FUT']);
    $this->players = collect(range(1, 31))->mapWithKeys(fn (int $index): array => [$index => Player::create([
        'nhl_id' => 9000000 + $index, 'full_name' => 'Player '.$index, 'first_name' => 'Player', 'last_name' => (string) $index,
        'position' => $index === 31 ? 'G' : ($index > 18 ? 'D' : 'C'),
        'pos_type' => $index === 31 ? 'G' : ($index > 18 ? 'D' : 'F'), 'team_abbrev' => 'TOR',
    ])]);
    $this->unit = function (array $indexes, string $type = 'F', string $strength = 'EV', int $toi = 600, array $extra = []): int {
        $id = DB::table('nhl_units')->insertGetId(['team_abbrev' => $extra['team_abbrev'] ?? 'TOR', 'unit_type' => $type]);
        foreach ($indexes as $index) {
            DB::table('nhl_unit_players')->insert(['unit_id' => $id, 'player_id' => $this->players[$index]->id]);
        }
        DB::table('nhl_unit_game_strength_summaries')->insert([
            'nhl_game_id' => 2026020002, 'unit_id' => $id, 'team_id' => 10, 'team_abbrev' => 'TOR',
            'strength' => $strength, 'toi' => $toi, ...$extra,
        ]);
        return $id;
    };
    $this->read = function (): array {
        $response = $this->get(route('tools.lines'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Tools/Lines'));
        return $response->viewData('page')['props']['lines'];
    };
    $this->team = fn (array $payload, string $abbrev = 'TOR'): array => collect($payload['teams'])->firstWhere('abbrev', $abbrev);
});

afterEach(function (): void {
    $this->travelBack();
});

it('serves guests and exposes the active Lines tool beside Schedule', function (): void {
    $this->get(route('tools.lines'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Tools/Lines')->where('shell.tools.0.label', 'Schedule')
        ->where('shell.tools.1.label', 'Lines')->where('shell.tools.1.href', route('tools.lines'))
        ->where('shell.tools.1.active', true));
});

it('allows ordinary authenticated members to read the same public lines', function (): void {
    $this->actingAs(User::factory()->create());
    expect(($this->read)()['season_id'])->toBe('20262027');
});

it('lists current-season teams alphabetically including teams without a completed game', function (): void {
    $payload = ($this->read)();
    expect(array_column($payload['teams'], 'abbrev'))->toBe(['BOS', 'MTL', 'TOR'])
        ->and(($this->team)($payload, 'BOS')['game'])->toBeNull();
});

it('omits defunct directory teams and teams found only in a previous season', function (): void {
    DB::table('nhl_teams')->insert(['nhl_id' => 99, 'abbrev' => 'OLD', 'full_name' => 'Old Team']);
    ($this->game)(2025020001, '2025-11-17', ['season_id' => '20252026', 'away_team_id' => 99, 'away_team_abbrev' => 'OLD']);
    expect(array_column(($this->read)()['teams'], 'abbrev'))->not->toContain('OLD');
});

it('ignores live and upcoming games when selecting the latest completed game', function (): void {
    ($this->game)(2026020004, '2026-11-18', ['game_state' => 'LIVE']);
    expect(($this->team)(($this->read)())['game']['id'])->toBe(2026020002);
});

it('accepts FINAL games and ignores cancelled schedule states', function (): void {
    ($this->game)(2026020004, '2026-11-18', ['game_state' => 'FINAL', 'game_schedule_state' => 'CNCL']);
    ($this->game)(2026020005, '2026-11-18', ['game_state' => 'FINAL']);
    expect(($this->team)(($this->read)())['game']['id'])->toBe(2026020005);
});

it('selects the later start time when completed games share a date', function (): void {
    ($this->game)(2026020004, '2026-11-17', ['start_time_utc' => '2026-11-17 22:00:00']);
    expect(($this->team)(($this->read)())['game']['id'])->toBe(2026020002);
});

it('uses a stable game ID tie break when date and start time match', function (): void {
    ($this->game)(2026020004, '2026-11-17');
    expect(($this->team)(($this->read)())['game']['id'])->toBe(2026020004);
});

it('does not substitute older combinations when the latest game has missing summaries', function (): void {
    ($this->unit)([1, 2, 3], extra: ['nhl_game_id' => 2026020001]);
    $team = ($this->team)(($this->read)());
    expect($team['game']['id'])->toBe(2026020002)->and($team['strengths']['EV']['F'])->toBe([]);
});

it('uses each teams own latest game rather than one league-wide game date', function (): void {
    ($this->game)(2026020004, '2026-11-18', ['away_team_id' => 6, 'away_team_abbrev' => 'BOS']);
    $payload = ($this->read)();
    expect(($this->team)($payload)['game']['id'])->toBe(2026020004)
        ->and(($this->team)($payload, 'MTL')['game']['id'])->toBe(2026020002);
});

it('takes the highest TOI forward trio and skips its overlapping alternate line', function (): void {
    $third = ($this->unit)([7, 8, 9], toi: 300);
    ($this->unit)([1, 4, 5], toi: 500);
    $first = ($this->unit)([1, 2, 3], toi: 700);
    $lines = ($this->team)(($this->read)())['strengths']['EV']['F'];
    expect(array_column($lines, 'unit_id'))->toBe([$first, $third])
        ->and(array_column($lines, 'toi_seconds'))->toBe([700, 300]);
});

it('shows at most four non-overlapping EV forward trios', function (): void {
    foreach (array_chunk(range(1, 15), 3) as $index => $players) {
        ($this->unit)($players, toi: 700 - $index * 60);
    }
    expect(($this->team)(($this->read)())['strengths']['EV']['F'])->toHaveCount(4);
});

it('shows at most three non-overlapping EV defensive pairs', function (): void {
    foreach (array_chunk(range(19, 26), 2) as $index => $players) {
        ($this->unit)($players, 'D', toi: 700 - $index * 60);
    }
    expect(($this->team)(($this->read)())['strengths']['EV']['D'])->toHaveCount(3);
});

it('shows two PP forward groups including four-forward units', function (): void {
    foreach ([[1, 2, 3, 4], [5, 6, 7, 8], [9, 10, 11, 12]] as $index => $players) {
        ($this->unit)($players, strength: 'PP', toi: 300 - $index * 60);
    }
    $lines = ($this->team)(($this->read)())['strengths']['PP']['F'];
    expect($lines)->toHaveCount(2)->and($lines[0]['players'])->toHaveCount(4);
});

it('shows two PK forward groups without assuming trios', function (): void {
    foreach ([[1, 2], [3, 4], [5, 6]] as $players) {
        ($this->unit)($players, strength: 'PK');
    }
    $lines = ($this->team)(($this->read)())['strengths']['PK']['F'];
    expect($lines)->toHaveCount(2)->and($lines[0]['players'])->toHaveCount(2);
});

it('shows two PP defensive groups including a single defenseman', function (): void {
    foreach ([[19], [20], [21, 22]] as $players) {
        ($this->unit)($players, 'D', 'PP');
    }
    $lines = ($this->team)(($this->read)())['strengths']['PP']['D'];
    expect($lines)->toHaveCount(2)->and($lines[0]['players'])->toHaveCount(1);
});

it('shows at most two PK defensive pairs', function (): void {
    foreach ([[19, 20], [21, 22], [23, 24]] as $players) {
        ($this->unit)($players, 'D', 'PK');
    }
    expect(($this->team)(($this->read)())['strengths']['PK']['D'])->toHaveCount(2);
});

it('permits the same player in different strengths', function (): void {
    foreach (['EV', 'PP', 'PK'] as $strength) {
        ($this->unit)([1, 2, 3], strength: $strength);
    }
    $strengths = ($this->team)(($this->read)())['strengths'];
    foreach ($strengths as $groups) {
        expect(array_column($groups['F'][0]['players'], 'name'))->toBe(['Player 1', 'Player 2', 'Player 3']);
    }
});

it('does not reuse a player across forward and defensive groups at the same strength', function (): void {
    ($this->unit)([1, 2, 3], toi: 800);
    ($this->unit)([3, 19], 'D', toi: 700);
    $pair = ($this->unit)([20, 21], 'D', toi: 600);
    expect(array_column(($this->team)(($this->read)())['strengths']['EV']['D'], 'unit_id'))->toBe([$pair]);
});

it('excludes zero TOI and goalie units', function (): void {
    ($this->unit)([1, 2, 3], toi: 0);
    ($this->unit)([31], 'G');
    expect(($this->team)(($this->read)())['strengths']['EV'])->toBe(['F' => [], 'D' => []]);
});

it('rejects malformed EV group sizes and goalies inside skater groups', function (): void {
    ($this->unit)([1, 2]);
    ($this->unit)([19], 'D');
    ($this->unit)([1, 2, 31]);
    expect(($this->team)(($this->read)())['strengths']['EV'])->toBe(['F' => [], 'D' => []]);
});

it('keeps opposing team summaries separate even when the game is shared', function (): void {
    ($this->unit)([1, 2, 3], extra: ['team_id' => 8, 'team_abbrev' => 'MTL']);
    $payload = ($this->read)();
    expect(($this->team)($payload)['strengths']['EV']['F'])->toBe([])
        ->and(($this->team)($payload, 'MTL')['strengths']['EV']['F'])->toHaveCount(1);
});

it('combines identical stored memberships before ranking without repeating a composition', function (): void {
    $first = ($this->unit)([1, 2, 3], toi: 200);
    ($this->unit)([3, 2, 1], toi: 200);
    ($this->unit)([1, 4, 5], toi: 350);
    $lines = ($this->team)(($this->read)())['strengths']['EV']['F'];
    expect($lines)->toHaveCount(1)->and($lines[0]['unit_id'])->toBe($first)->and($lines[0]['toi_seconds'])->toBe(400);
});

it('formats shared TOI without multiplying it by the number of players', function (): void {
    ($this->unit)([1, 2, 3], toi: 623);
    $line = ($this->team)(($this->read)())['strengths']['EV']['F'][0];
    expect($line['toi'])->toBe('10:23')->and($line['toi_seconds'])->toBe(623);
});

it('serves current names and game links without leaking private player attributes', function (): void {
    ($this->unit)([1, 2, 3]);
    $team = ($this->team)(($this->read)());
    expect($team['name'])->toBe('Toronto Maple Leafs')->and($team['game']['venue'])->toBe('home')
        ->and($team['game']['opponent'])->toBe('MTL')->and($team['game']['href'])->toBe('/games/2026020002')
        ->and(array_keys($team['strengths']['EV']['F'][0]['players'][0]))->toBe(['id', 'nhl_id', 'name']);
});

it('reads only prepared summaries without raw event scans or queued work', function (): void {
    ($this->unit)([1, 2, 3]);
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        app(\App\Services\NhlTeamLines::class)->read();
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries)->not->toContain('play_by_plays', 'nhl_unit_shifts', 'event_unit_shifts');
    Bus::assertNothingDispatched();
});

it('reads EV PP and PK lines after the existing aggregation action persists shift summaries', function (): void {
    $sources = [
        ['F', [1, 2, 3], 'EV', 610], ['D', [19, 20], 'EV', 700],
        ['PP', [1, 2, 3, 4, 19], 'PP', 180], ['PK', [5, 6, 20, 21], 'PK', 120],
    ];
    foreach ($sources as [$type, $indexes, $strength, $seconds]) {
        $unit = DB::table('nhl_units')->insertGetId(['team_abbrev' => 'TOR', 'unit_type' => $type]);
        foreach ($indexes as $index) {
            DB::table('nhl_unit_players')->insert(['unit_id' => $unit, 'player_id' => $this->players[$index]->id]);
        }
        DB::table('nhl_unit_shifts')->insert([
            'unit_id' => $unit, 'nhl_game_id' => 2026020002, 'team_id' => 10, 'team_abbrev' => 'TOR',
            'period' => 1, 'start_time' => '00:00', 'end_time' => '11:40',
            'start_game_seconds' => 0, 'end_game_seconds' => $seconds, 'seconds' => $seconds,
        ]);
    }
    (new \App\Services\SumNhlGameStrengthUnits(2026020002))->sum();
    foreach ($sources as [$type, $indexes, $strength, $seconds]) {
        $this->assertDatabaseHas('nhl_unit_game_strength_summaries', [
            'nhl_game_id' => 2026020002, 'team_id' => 10, 'strength' => $strength, 'toi' => $seconds,
        ]);
    }
    $team = ($this->team)(($this->read)());
    expect($team['strengths']['EV']['F'][0]['toi'])->toBe('10:10')
        ->and($team['strengths']['EV']['D'][0]['toi'])->toBe('11:40')
        ->and(array_column($team['strengths']['PP']['F'][0]['players'], 'name'))->toBe(['Player 1', 'Player 2', 'Player 3', 'Player 4'])
        ->and($team['strengths']['PP']['D'][0]['players'][0]['name'])->toBe('Player 19')
        ->and($team['strengths']['PP']['F'][0]['toi'])->toBe('3:00')
        ->and($team['strengths']['PK']['F'][0]['players'])->toHaveCount(2)
        ->and($team['strengths']['PK']['D'][0]['players'])->toHaveCount(2)
        ->and($team['strengths']['PK']['D'][0]['toi'])->toBe('2:00');
});

it('sums a repeated PP forward subgroup across changes to its defenseman', function (): void {
    ($this->unit)([1, 2, 3, 4, 19], 'PP', 'PP', 150);
    ($this->unit)([1, 2, 3, 4, 20], 'PP', 'PP', 100);
    $groups = ($this->team)(($this->read)())['strengths']['PP'];
    expect($groups['F'])->toHaveCount(1)->and($groups['F'][0]['toi_seconds'])->toBe(250)
        ->and(array_column($groups['D'], 'toi_seconds'))->toBe([150, 100]);
});

it('sums a repeated PK defensive subgroup across changes to its forwards', function (): void {
    ($this->unit)([1, 2, 19, 20], 'PK', 'PK', 140);
    ($this->unit)([3, 4, 19, 20], 'PK', 'PK', 110);
    $groups = ($this->team)(($this->read)())['strengths']['PK'];
    expect($groups['D'])->toHaveCount(1)->and($groups['D'][0]['toi_seconds'])->toBe(250)
        ->and($groups['F'])->toHaveCount(2);
});

it('uses position as a fallback and omits unclassifiable special-teams combinations', function (): void {
    $this->players[19]->update(['pos_type' => null, 'position' => 'D']);
    ($this->unit)([1, 2, 3, 4, 19], 'PP', 'PP', 180);
    $this->players[20]->update(['pos_type' => null, 'position' => null]);
    ($this->unit)([5, 6, 20, 21], 'PK', 'PK', 120);
    $groups = ($this->team)(($this->read)())['strengths'];
    expect($groups['PP']['D'][0]['players'][0]['name'])->toBe('Player 19')
        ->and($groups['PK'])->toBe(['F' => [], 'D' => []]);
});
