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
    $this->draws = function (int $player, int $won, int $lost = 0, int $game = 2026020002): void {
        DB::table('nhl_boxscores')->insert([
            'nhl_game_id' => $game, 'nhl_player_id' => $this->players[$player]->nhl_id,
            'nhl_team_id' => 10, 'faceoffs_won' => $won, 'faceoffs_lost' => $lost,
        ]);
    };
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

it('shows at most two complete non-overlapping PP units ranked by TOI', function (): void {
    foreach ([[1, 2, 3, 4, 19], [5, 6, 7, 8, 20], [9, 10, 11, 12, 21]] as $index => $players) {
        ($this->unit)($players, 'PP', 'PP', 300 - $index * 60);
    }
    $lines = ($this->team)(($this->read)())['strengths']['PP']['units'];
    expect($lines)->toHaveCount(2)->and($lines[0]['players'])->toHaveCount(5)
        ->and(array_column($lines, 'toi_seconds'))->toBe([300, 240]);
});

it('shows two complete PK units with four or three skaters', function (): void {
    foreach ([[1, 2, 19, 20], [3, 21, 22], [4, 5, 23, 24]] as $players) {
        ($this->unit)($players, 'PK', 'PK');
    }
    $lines = ($this->team)(($this->read)())['strengths']['PK']['units'];
    expect($lines)->toHaveCount(2)->and($lines[0]['players'])->toHaveCount(4)
        ->and($lines[1]['players'])->toHaveCount(3);
});

it('retains a five-forward PP unit and fills its defensive display spots', function (): void {
    ($this->unit)([1, 2, 3, 4, 5], 'PP', 'PP');
    $players = ($this->team)(($this->read)())['strengths']['PP']['units'][0]['players'];
    expect($players)->toHaveCount(5)->and(array_column($players, 'slot'))->toBe(['LW', 'C', 'RW', 'LD', 'RD'])
        ->and(array_column($players, 'name'))->toContain('Player 1', 'Player 2', 'Player 3', 'Player 4', 'Player 5');
});

it('keeps a three-forward one-defenseman PK unit together', function (): void {
    ($this->unit)([1, 2, 3, 19], 'PK', 'PK');
    $players = collect(($this->team)(($this->read)())['strengths']['PK']['units'][0]['players']);
    expect($players)->toHaveCount(4)->and($players->whereIn('slot', ['LD', 'RD'])->pluck('name')->all())->toContain('Player 19')
        ->and($players->whereIn('slot', ['LD', 'RD'])->count())->toBe(2);
});

it('permits the same player in EV PP and PK without changing unit membership', function (): void {
    ($this->unit)([1, 2, 3]);
    ($this->unit)([1, 2, 3, 4, 19], 'PP', 'PP');
    ($this->unit)([1, 2, 19, 20], 'PK', 'PK');
    $strengths = ($this->team)(($this->read)())['strengths'];
    expect(array_column($strengths['EV']['F'][0]['players'], 'name'))->toContain('Player 1')
        ->and(array_column($strengths['PP']['units'][0]['players'], 'name'))->toContain('Player 1')
        ->and(array_column($strengths['PK']['units'][0]['players'], 'name'))->toContain('Player 1');
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
        ->and(array_keys($team['strengths']['EV']['F'][0]['players'][0]))->toBe(['id', 'nhl_id', 'name', 'short_name', 'slot']);
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
        ->and($team['strengths']['PP']['units'][0]['players'])->toHaveCount(5)
        ->and(array_column($team['strengths']['PP']['units'][0]['players'], 'name'))->toContain('Player 19')
        ->and($team['strengths']['PP']['units'][0]['toi'])->toBe('3:00')
        ->and($team['strengths']['PK']['units'][0]['players'])->toHaveCount(4)
        ->and($team['strengths']['PK']['units'][0]['toi'])->toBe('2:00');
});

it('ranks whole PP units without combining TOI across different defensemen', function (): void {
    $first = ($this->unit)([1, 2, 3, 4, 19], 'PP', 'PP', 150);
    ($this->unit)([1, 2, 3, 4, 20], 'PP', 'PP', 100);
    $groups = ($this->team)(($this->read)())['strengths']['PP'];
    expect(array_keys($groups))->toBe(['units'])->and($groups['units'])->toHaveCount(1)
        ->and($groups['units'][0]['unit_id'])->toBe($first)->and($groups['units'][0]['toi_seconds'])->toBe(150);
});

it('does not reuse defensemen across different selected PK units', function (): void {
    ($this->unit)([1, 2, 19, 20], 'PK', 'PK', 140);
    ($this->unit)([3, 4, 19, 20], 'PK', 'PK', 110);
    $units = ($this->team)(($this->read)())['strengths']['PK']['units'];
    expect($units)->toHaveCount(1)->and($units[0]['toi_seconds'])->toBe(140);
});

it('uses position fallback for display without discarding an unknown-position unit member', function (): void {
    $this->players[19]->update(['pos_type' => null, 'position' => 'D']);
    ($this->unit)([1, 2, 3, 4, 19], 'PP', 'PP', 180);
    $this->players[20]->update(['pos_type' => null, 'position' => null]);
    ($this->unit)([5, 6, 20, 21], 'PK', 'PK', 120);
    $groups = ($this->team)(($this->read)())['strengths'];
    $back = collect($groups['PP']['units'][0]['players'])->whereIn('slot', ['LD', 'RD']);
    expect($back->pluck('name')->all())->toContain('Player 19')
        ->and($groups['PK']['units'][0]['players'])->toHaveCount(4);
});

it('formats first initial and surname while retaining the full name', function (): void {
    $this->players[1]->update(['first_name' => 'Macklin', 'last_name' => 'Celebrini', 'full_name' => 'Macklin Celebrini']);
    ($this->unit)([1, 2, 3]);
    $player = collect(($this->team)(($this->read)())['strengths']['EV']['F'][0]['players'])->firstWhere('id', $this->players[1]->id);
    expect($player['short_name'])->toBe('M Celebrini')->and($player['name'])->toBe('Macklin Celebrini');
});

it('preserves accented initials and compound surnames', function (): void {
    $this->players[1]->update(['first_name' => 'Émile', 'last_name' => 'van Riemsdyk', 'full_name' => 'Émile van Riemsdyk']);
    ($this->unit)([1, 2, 3]);
    $player = collect(($this->team)(($this->read)())['strengths']['EV']['F'][0]['players'])->firstWhere('id', $this->players[1]->id);
    expect($player['short_name'])->toBe('É van Riemsdyk');
});

it('uses full-name parts when separate first and last names are missing', function (): void {
    $this->players[1]->update(['first_name' => null, 'last_name' => null, 'full_name' => 'Will Cuylle']);
    ($this->unit)([1, 2, 3]);
    $player = collect(($this->team)(($this->read)())['strengths']['EV']['F'][0]['players'])->firstWhere('id', $this->players[1]->id);
    expect($player['short_name'])->toBe('W Cuylle');
});

it('selects the center by faceoffs taken rather than wins alone', function (): void {
    ($this->unit)([1, 2, 3]);
    ($this->draws)(1, 10);
    ($this->draws)(2, 1, 20);
    $center = collect(($this->team)(($this->read)())['strengths']['EV']['F'][0]['players'])->firstWhere('slot', 'C');
    expect($center['id'])->toBe($this->players[2]->id);
});

it('ignores other games faceoff counts when placing the center', function (): void {
    ($this->unit)([1, 2, 3]);
    ($this->draws)(1, 4);
    ($this->draws)(3, 40, game: 2026020001);
    $center = collect(($this->team)(($this->read)())['strengths']['EV']['F'][0]['players'])->firstWhere('slot', 'C');
    expect($center['id'])->toBe($this->players[1]->id);
});

it('uses listed wings and center as stable fallbacks when faceoffs are missing', function (): void {
    $this->players[1]->update(['position' => 'RW']);
    $this->players[2]->update(['position' => 'LW']);
    $this->players[3]->update(['position' => 'C']);
    ($this->unit)([1, 2, 3]);
    $players = ($this->team)(($this->read)())['strengths']['EV']['F'][0]['players'];
    expect(array_column($players, 'id'))->toBe([$this->players[2]->id, $this->players[3]->id, $this->players[1]->id])
        ->and(array_column($players, 'slot'))->toBe(['LW', 'C', 'RW']);
});

it('places both defensemen behind the three PP forwards', function (): void {
    ($this->unit)([1, 2, 3, 19, 20], 'PP', 'PP');
    ($this->draws)(3, 7);
    $players = collect(($this->team)(($this->read)())['strengths']['PP']['units'][0]['players']);
    expect($players->firstWhere('slot', 'C')['id'])->toBe($this->players[3]->id)
        ->and($players->whereIn('slot', ['LD', 'RD'])->pluck('name')->all())->toBe(['Player 19', 'Player 20']);
});

it('places one defenseman and one extra forward behind a four-forward PP unit', function (): void {
    ($this->unit)([1, 2, 3, 4, 19], 'PP', 'PP');
    $players = collect(($this->team)(($this->read)())['strengths']['PP']['units'][0]['players']);
    $back = $players->whereIn('slot', ['LD', 'RD']);
    expect($back)->toHaveCount(2)->and($back->pluck('name')->all())->toContain('Player 19')
        ->and($players->pluck('id')->unique())->toHaveCount(5);
});

it('positions a three-skater PK unit with one center and two back spots', function (): void {
    ($this->unit)([1, 19, 20], 'PK', 'PK');
    ($this->draws)(1, 5);
    $players = ($this->team)(($this->read)())['strengths']['PK']['units'][0]['players'];
    expect(array_column($players, 'slot'))->toBe(['C', 'LD', 'RD'])
        ->and($players[0]['id'])->toBe($this->players[1]->id);
});

it('positions a four-skater PK unit using its listed wing and busiest center', function (): void {
    $this->players[2]->update(['position' => 'RW']);
    ($this->unit)([1, 2, 19, 20], 'PK', 'PK');
    ($this->draws)(1, 5);
    $players = ($this->team)(($this->read)())['strengths']['PK']['units'][0]['players'];
    expect(array_column($players, 'slot'))->toBe(['C', 'RW', 'LD', 'RD']);
});

it('rejects partial PP groups and oversized PK units without synthesizing a roster', function (): void {
    ($this->unit)([1, 2, 3, 19], 'PP', 'PP');
    ($this->unit)([1, 2, 3, 19, 20], 'PK', 'PK');
    ($this->unit)([1, 2, 3, 4, 19], 'F', 'PP');
    $strengths = ($this->team)(($this->read)())['strengths'];
    expect($strengths['PP'])->toBe(['units' => []])->and($strengths['PK'])->toBe(['units' => []]);
});
