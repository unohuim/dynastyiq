<?php

declare(strict_types=1);

use App\Models\NhlGame;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-18 14:00:00 UTC'));
    config(['cache.default' => 'array']);
    Cache::flush();
    Bus::fake();
    Http::preventStrayRequests();
    $this->viewer = User::factory()->create();
    DB::table('nhl_teams')->insertOrIgnore([
        ['nhl_id' => 6, 'abbrev' => 'BOS', 'full_name' => 'Boston Bruins'],
        ['nhl_id' => 8, 'abbrev' => 'MTL', 'full_name' => 'Montreal Canadiens'],
        ['nhl_id' => 10, 'abbrev' => 'TOR', 'full_name' => 'Toronto Maple Leafs'],
    ]);
    $this->game = fn (int $id = 2026020003, string $date = '2026-11-16', array $extra = []): NhlGame => NhlGame::create([
        'nhl_game_id' => $id, 'season_id' => '20262027', 'game_type' => 2, 'game_date' => $date,
        'game_dow' => 'Monday', 'game_month' => 'November', 'game_state' => 'FUT',
        'game_schedule_state' => 'OK', 'start_time_utc' => $date.' 23:00:00',
        'home_team_id' => 10, 'home_team_abbrev' => 'TOR', 'away_team_id' => 8, 'away_team_abbrev' => 'MTL',
        ...$extra,
    ]);
    ($this->game)(2026020001, '2026-11-09');
    ($this->game)(2026020002, '2026-11-10', ['away_team_id' => 6, 'away_team_abbrev' => 'BOS']);
    $this->read = function (array $query = []): array {
        $response = $this->get(route('tools.schedule', $query))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Tools/Schedule')->has('schedule.days', 7));
        return $response->viewData('page')['props']['schedule'];
    };
    $this->row = fn (array $schedule, string $team): array => collect($schedule['teams'])->keyBy('abbrev')[$team];
});

afterEach(function (): void { $this->travelBack(); });

it('allows guests to view the schedule in the shared shell', function (): void {
    $this->get(route('tools.schedule'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Tools/Schedule')->where('shell.tools.0.label', 'Schedule')
        ->where('shell.tools.0.href', route('tools.schedule'))->where('shell.tools.0.active', true));
});

it('allows authenticated members without an admin role to view the same schedule', function (): void {
    $this->actingAs($this->viewer);
    expect(($this->read)()['week_start'])->toBe('2026-11-16');
});

it('defaults to the current Monday through Sunday', function (): void {
    $schedule = ($this->read)();
    expect($schedule['week_start'])->toBe('2026-11-16')->and($schedule['week_end'])->toBe('2026-11-22')
        ->and(array_column($schedule['days'], 'label'))->toBe(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']);
});

it('uses Eastern calendar time before the UTC Monday rollover reaches Toronto', function (): void {
    $this->travelTo(Carbon::parse('2026-11-16 02:00:00 UTC'));
    expect(($this->read)()['week_start'])->toBe('2026-11-09');
});

it('starts the new week at Eastern Monday midnight without the scoreboard eleven am rule', function (): void {
    $this->travelTo(Carbon::parse('2026-11-16 05:00:00 UTC'));
    expect(($this->read)()['week_start'])->toBe('2026-11-16');
});

it('normalizes a selected midweek date to Monday', function (): void {
    expect(($this->read)(['week' => '2026-11-20'])['week_start'])->toBe('2026-11-16');
});

it('preserves the correct week across a year boundary', function (): void {
    $schedule = ($this->read)(['week' => '2027-01-01']);
    expect($schedule['week_start'])->toBe('2026-12-28')->and($schedule['week_end'])->toBe('2027-01-03');
});

it('provides previous next and current week navigation URLs', function (): void {
    $schedule = ($this->read)();
    expect($schedule['previous_url'])->toBe(route('tools.schedule', ['week' => '2026-11-09']))
        ->and($schedule['next_url'])->toBe(route('tools.schedule', ['week' => '2026-11-23']))
        ->and($schedule['current_url'])->toBe(route('tools.schedule'))->and($schedule['is_current_week'])->toBeTrue();
});

it('identifies a selected historical week independently of the current week', function (): void {
    expect(($this->read)(['week' => '2026-11-09'])['is_current_week'])->toBeFalse();
});

it('keeps all season teams visible even when they have no games in the week', function (): void {
    $schedule = ($this->read)();
    expect(array_column($schedule['teams'], 'abbrev'))->toBe(['BOS', 'MTL', 'TOR'])
        ->and(($this->row)($schedule, 'BOS')['total_gp'])->toBe(0);
});

it('shows all thirty-two season participants without requiring a game in the selected week', function (): void {
    foreach (range(1, 29) as $number) {
        DB::table('nhl_teams')->insert(['nhl_id' => 100 + $number, 'abbrev' => 'X'.$number]);
        ($this->game)(2026020100 + $number, '2026-11-11', ['away_team_id' => 100 + $number, 'away_team_abbrev' => 'X'.$number]);
    }
    expect(($this->read)()['teams'])->toHaveCount(32);
});

it('does not add defunct teams merely because they remain in the team directory', function (): void {
    DB::table('nhl_teams')->insert(['nhl_id' => 27, 'abbrev' => 'PHX']);
    expect(array_column(($this->read)()['teams'], 'abbrev'))->not->toContain('PHX');
});

it('places reciprocal home and away entries in the same day', function (): void {
    ($this->game)();
    $schedule = ($this->read)();
    $home = ($this->row)($schedule, 'TOR')['days']['2026-11-16'][0];
    $away = ($this->row)($schedule, 'MTL')['days']['2026-11-16'][0];
    expect($home['venue'])->toBe('home')->and($home['opponent'])->toBe('MTL')
        ->and($away['venue'])->toBe('away')->and($away['opponent'])->toBe('TOR')
        ->and($home['id'])->toBe($away['id']);
});

it('uses scheduled game date rather than the UTC timestamp date for the column', function (): void {
    ($this->game)(2026020003, '2026-11-16', ['start_time_utc' => '2026-11-17 02:30:00']);
    $row = ($this->row)(($this->read)(), 'TOR');
    expect($row['days']['2026-11-16'][0]['time'])->toBe('9:30 PM')->and($row['days']['2026-11-17'])->toBe([]);
});

it('formats summer start times using the daylight saving offset', function (): void {
    ($this->game)(2026020003, '2026-10-05', ['start_time_utc' => '2026-10-05 23:00:00']);
    $row = ($this->row)(($this->read)(['week' => '2026-10-05']), 'TOR');
    expect($row['days']['2026-10-05'][0]['time'])->toBe('7:00 PM');
});

it('keeps unknown start times as TBD', function (): void {
    ($this->game)(2026020003, '2026-11-16', ['start_time_utc' => null]);
    expect(($this->row)(($this->read)(), 'TOR')['days']['2026-11-16'][0]['time'])->toBe('TBD');
});

it('counts completed live and upcoming games once per participating team', function (): void {
    foreach (['OFF', 'LIVE', 'FUT'] as $offset => $state) {
        ($this->game)(2026020003 + $offset, '2026-11-'.(16 + $offset), ['game_state' => $state]);
    }
    $schedule = ($this->read)();
    expect(($this->row)($schedule, 'TOR')['total_gp'])->toBe(3)
        ->and(($this->row)($schedule, 'MTL')['total_gp'])->toBe(3)->and($schedule['game_count'])->toBe(3);
});

it('keeps postponed and cancelled games visible without counting them toward GP', function (string $field, string $state, string $label): void {
    ($this->game)(2026020003, '2026-11-16', [$field => $state]);
    $schedule = ($this->read)();
    $row = ($this->row)($schedule, 'TOR');
    expect($row['total_gp'])->toBe(0)->and($row['days']['2026-11-16'][0]['status'])->toBe($label)
        ->and($schedule['game_count'])->toBe(0);
})->with([['game_state', 'PPD', 'Postponed'], ['game_state', 'CNCL', 'Cancelled'], ['game_schedule_state', 'PPD', 'Postponed']]);

it('includes Sunday and excludes both adjacent weeks from totals', function (): void {
    foreach (['2026-11-15', '2026-11-22', '2026-11-23'] as $offset => $date) {
        ($this->game)(2026020003 + $offset, $date);
    }
    expect(($this->row)(($this->read)(), 'TOR')['total_gp'])->toBe(1);
});

it('retains multiple games in one day in chronological order', function (): void {
    ($this->game)(2026020003, '2026-11-16', ['start_time_utc' => '2026-11-17 02:00:00']);
    ($this->game)(2026020004, '2026-11-16', ['start_time_utc' => '2026-11-16 18:00:00']);
    $row = ($this->row)(($this->read)(), 'TOR');
    expect(array_column($row['days']['2026-11-16'], 'id'))->toBe([2026020004, 2026020003])->and($row['total_gp'])->toBe(2);
});

it('links each scheduled game to its existing preview or recap route', function (): void {
    ($this->game)();
    expect(($this->row)(($this->read)(), 'TOR')['days']['2026-11-16'][0]['href'])
        ->toBe(route('games.show', ['nhlGameId' => 2026020003]));
});

it('does not require imported boxscores or completed pregame context', function (): void {
    ($this->game)();
    expect(($this->read)()['game_count'])->toBe(1);
    $this->assertDatabaseCount('nhl_boxscores', 0);
    $this->assertDatabaseCount('nhl_team_game_pregame_contexts', 0);
});

it('never imports source data or dispatches predictions while reading a schedule', function (): void {
    ($this->game)();
    ($this->read)();
    Http::assertNothingSent();
    Bus::assertNothingDispatched();
});

it('returns an honest empty schedule when the selected season has no stored games', function (): void {
    $schedule = ($this->read)(['week' => '2030-11-18']);
    expect($schedule['teams'])->toBe([])->and($schedule['game_count'])->toBe(0);
});

it('rejects malformed impossible and non-scalar week dates', function (mixed $week): void {
    $this->getJson(route('tools.schedule', ['week' => $week]))->assertUnprocessable()->assertJsonValidationErrors('week');
})->with(['invalid', '2026-02-30', '11/16/2026', [['2026-11-16']]]);

it('marks today only within the selected week', function (): void {
    expect(collect(($this->read)()['days'])->where('is_today', true)->pluck('date')->all())->toBe(['2026-11-18'])
        ->and(collect(($this->read)(['week' => '2026-11-09'])['days'])->where('is_today', true))->toHaveCount(0);
});

it('keeps Schedule reachable from legacy desktop and mobile Tools navigation', function (): void {
    $html = view('nav.main')->render();
    expect($html)->toContain(route('tools.schedule'), 'Tools navigation', 'tools-menu-title', 'legacy-tools-panel');
});
