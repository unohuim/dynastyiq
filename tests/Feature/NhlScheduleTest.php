<?php

declare(strict_types=1);

use App\Models\NhlGame;
use App\Models\Role;
use App\Jobs\BuildNhlScheduleSnapshotsJob;
use App\Jobs\RunImportCommandJob;
use App\Services\NhlScheduleSnapshots;
use Carbon\CarbonImmutable;
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
        $snapshots = app(NhlScheduleSnapshots::class);
        $today = CarbonImmutable::now('America/Toronto');
        $week = CarbonImmutable::parse($query['week'] ?? $today->toDateString(), 'America/Toronto')->startOfWeek();
        foreach (array_unique([$snapshots->seasonId($week), $snapshots->seasonId($today)]) as $season) {
            $snapshots->build($season);
        }
        $response = $this->get(route('tools.schedule', $query))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Tools/Schedule')->has('schedule.days', 7));
        return $response->viewData('page')['props']['schedule'];
    };
    $this->league = function (): array {
        $ids = [6, 8, 10, ...range(101, 129)];
        foreach (range(101, 129) as $id) {
            DB::table('nhl_teams')->insert(['nhl_id' => $id, 'abbrev' => 'X'.($id - 100)]);
            ($this->game)(2026020100 + $id, '2026-11-11', ['away_team_id' => $id, 'away_team_abbrev' => 'X'.($id - 100)]);
        }
        return $ids;
    };
    $this->slate = function (array $ids, int $count, string $date, int $base): void {
        foreach (range(0, $count - 1) as $index) {
            ($this->game)($base + $index, $date, [
                'home_team_id' => $ids[$index * 2], 'home_team_abbrev' => 'H'.$index,
                'away_team_id' => $ids[$index * 2 + 1], 'away_team_abbrev' => 'A'.$index,
            ]);
        }
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
        ->toBe(route('games.show', ['nhlGameId' => 2026020003], false));
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

it('includes exactly half the league as an off night even on Tuesday', function (): void {
    $ids = ($this->league)();
    ($this->slate)($ids, 8, '2026-11-17', 2026021000);
    $schedule = ($this->read)();
    $day = $schedule['days'][1];
    expect($day['games'])->toBe(8)->and($day['teams_playing'])->toBe(16)
        ->and($day['league_teams'])->toBe(32)->and($day['is_off_night'])->toBeTrue()
        ->and(($this->row)($schedule, 'BOS')['off_night_gp'])->toBe(1)
        ->and(($this->row)($schedule, 'MTL')['off_night_gp'])->toBe(1);
});

it('excludes a busy Monday when more than half the league plays', function (): void {
    $ids = ($this->league)();
    ($this->slate)($ids, 9, '2026-11-16', 2026021000);
    $schedule = ($this->read)();
    expect($schedule['days'][0]['is_off_night'])->toBeFalse()
        ->and(($this->row)($schedule, 'BOS')['off_night_gp'])->toBe(0)
        ->and(($this->row)($schedule, 'BOS')['total_gp'])->toBe(1);
});

it('retains every season team with zero off-night games where appropriate', function (): void {
    $ids = ($this->league)();
    ($this->slate)($ids, 1, '2026-11-17', 2026021000);
    $schedule = ($this->read)();
    expect($schedule['teams'])->toHaveCount(32)
        ->and(($this->row)($schedule, 'TOR')['off_night_gp'])->toBe(0)
        ->and(collect($schedule['teams'])->sum('off_night_gp'))->toBe(2);
});

it('counts only unique playing teams when a team has multiple games in a day', function (): void {
    ($this->league)();
    foreach (range(1, 9) as $offset) {
        ($this->game)(2026021000 + $offset);
    }
    $schedule = ($this->read)();
    expect($schedule['days'][0]['teams_playing'])->toBe(2)
        ->and($schedule['days'][0]['games'])->toBe(9)->and($schedule['days'][0]['is_off_night'])->toBeTrue()
        ->and(($this->row)($schedule, 'TOR')['off_night_gp'])->toBe(9);
});

it('excludes postponed or cancelled games from off-night activity and counts', function (string $field, string $state): void {
    $ids = ($this->league)();
    ($this->slate)($ids, 8, '2026-11-17', 2026021000);
    ($this->game)(2026022000, '2026-11-17', ['home_team_id' => $ids[16], 'away_team_id' => $ids[17], $field => $state]);
    $schedule = ($this->read)();
    expect($schedule['days'][1]['games'])->toBe(8)->and($schedule['days'][1]['is_off_night'])->toBeTrue()
        ->and(collect($schedule['teams'])->sum('off_night_gp'))->toBe(16);
})->with([['game_state', 'PPD'], ['game_state', 'CNCL'], ['game_schedule_state', 'PPD'], ['game_schedule_state', 'CNCL']]);

it('does not invent team off-night games on dates with an empty slate', function (): void {
    ($this->league)();
    $schedule = ($this->read)();
    expect($schedule['days'][0]['is_off_night'])->toBeTrue()
        ->and($schedule['days'][0]['teams_playing'])->toBe(0)
        ->and(collect($schedule['teams'])->sum('off_night_gp'))->toBe(0);
});

it('counts completed live and future off-night games within the selected week only', function (): void {
    ($this->league)();
    foreach (['OFF', 'LIVE', 'FUT'] as $offset => $state) {
        ($this->game)(2026021000 + $offset, '2026-11-'.(16 + $offset), ['game_state' => $state]);
    }
    ($this->game)(2026022000, '2026-11-23');
    $schedule = ($this->read)();
    expect(($this->row)($schedule, 'TOR')['off_night_gp'])->toBe(3)
        ->and(($this->row)($schedule, 'MTL')['off_night_gp'])->toBe(3);
});

it('uses the season population rather than assuming thirty-two teams', function (): void {
    ($this->game)();
    $schedule = ($this->read)();
    expect($schedule['days'][0]['league_teams'])->toBe(3)
        ->and($schedule['days'][0]['teams_playing'])->toBe(2)
        ->and($schedule['days'][0]['is_off_night'])->toBeFalse();
});

it('does not classify dates without stored season coverage as off nights', function (): void {
    $schedule = ($this->read)(['week' => '2030-11-18']);
    expect($schedule['days'][0]['league_teams'])->toBe(0)
        ->and($schedule['days'][0]['is_off_night'])->toBeFalse();
});

it('uses each dates own season population in a week crossing September first', function (): void {
    ($this->game)(2026021000, '2026-11-19', ['away_team_id' => 11, 'away_team_abbrev' => 'NEW']);
    ($this->game)(2025021000, '2026-08-31', ['season_id' => '20252026']);
    ($this->game)(2026022000, '2026-09-01');
    $schedule = ($this->read)(['week' => '2026-08-31']);
    expect($schedule['days'][0]['league_teams'])->toBe(2)
        ->and($schedule['days'][0]['is_off_night'])->toBeFalse()
        ->and($schedule['days'][1]['league_teams'])->toBe(4)
        ->and($schedule['days'][1]['is_off_night'])->toBeTrue();
});


it('counts off nights across the remaining season rather than the selected week', function (): void {
    ($this->league)();
    ($this->game)(2026023000, '2026-11-18');
    ($this->game)(2026023001, '2026-12-23');
    ($this->game)(2026023002, '2027-04-07');
    $schedule = ($this->read)();
    $remaining = $schedule['off_nights'];
    expect($remaining['season_id'])->toBe('20262027')->and($remaining['from_date'])->toBe('2026-11-18')
        ->and($remaining['game_count'])->toBe(3)->and($remaining['teams'])->toHaveCount(32)
        ->and(($this->row)($remaining, 'TOR')['off_night_gp'])->toBe(3)
        ->and(array_key_exists('games', ($this->row)($remaining, 'TOR')))->toBeFalse()
        ->and(($this->row)($remaining, 'BOS')['off_night_gp'])->toBe(0)
        ->and(($this->row)($schedule, 'TOR')['total_gp'])->toBe(1);
});

it('keeps remaining off-night data identical when navigating historical or future weeks', function (): void {
    ($this->league)();
    ($this->game)(2026023000, '2026-12-23');
    $current = ($this->read)()['off_nights'];
    expect(($this->read)(['week' => '2026-10-01'])['off_nights'])->toBe($current)
        ->and(($this->read)(['week' => '2030-11-18'])['off_nights'])->toBe($current);
});

it('excludes past completed cancelled postponed and other-season games from remaining off nights', function (): void {
    ($this->league)();
    foreach ([
        ['2026-11-17', []], ['2026-11-18', ['game_state' => 'OFF']],
        ['2026-11-18', ['game_state' => 'FINAL']], ['2026-11-19', ['game_state' => 'CNCL']],
        ['2026-11-20', ['game_schedule_state' => 'PPD']],
        ['2027-11-18', ['season_id' => '20272028']],
        ['2026-11-18', ['game_state' => 'LIVE']], ['2026-11-23', []],
    ] as $offset => [$date, $extra]) {
        ($this->game)(2026023000 + $offset, $date, $extra);
    }
    $remaining = ($this->read)()['off_nights'];
    expect($remaining['game_count'])->toBe(2)
        ->and(($this->row)($remaining, 'TOR')['off_night_gp'])->toBe(2)
        ->and(array_key_exists('games', ($this->row)($remaining, 'TOR')))->toBeFalse();
});

it('applies the inclusive off-night cutoff to each future full league slate', function (): void {
    $ids = ($this->league)();
    ($this->slate)($ids, 8, '2026-12-22', 2026023000);
    ($this->slate)($ids, 9, '2026-12-23', 2026024000);
    $remaining = ($this->read)()['off_nights'];
    expect($remaining['game_count'])->toBe(17)
        ->and(($this->row)($remaining, 'BOS')['off_night_gp'])->toBe(1)
        ->and(collect($remaining['teams'])->sum('off_night_gp'))->toBe(16);
});

it('retains completed games in todays slate classification without counting them as remaining', function (): void {
    $ids = ($this->league)();
    ($this->slate)($ids, 9, '2026-11-18', 2026023000);
    NhlGame::where('nhl_game_id', 2026023000)->update(['game_state' => 'FINAL']);
    $remaining = ($this->read)()['off_nights'];
    expect($remaining['game_count'])->toBe(8)
        ->and(collect($remaining['teams'])->sum('off_night_gp'))->toBe(0);
});

it('retains zero-count teams when no uncompleted games remain this season', function (): void {
    ($this->league)();
    $remaining = ($this->read)()['off_nights'];
    expect($remaining['game_count'])->toBe(0)->and($remaining['teams'])->toHaveCount(32)
        ->and(collect($remaining['teams'])->sum('off_night_gp'))->toBe(0);
});


it('prepares source data through the command persists it and serves it through HTTP', function (): void {
    ($this->game)();
    $this->artisan('nhl:prepare-schedule', ['--season' => '20262027'])->assertSuccessful();
    $this->assertDatabaseHas('nhl_schedule_snapshots', ['snapshot_key' => 'week:2026-11-16']);
    $this->get(route('tools.schedule'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('schedule.prepared', true)->where('schedule.game_count', 1));
});

it('upserts published snapshots without duplicating weeks', function (): void {
    $service = app(NhlScheduleSnapshots::class);
    $service->build('20262027');
    $count = DB::table('nhl_schedule_snapshots')->count();
    ($this->game)();
    $service->build('20262027');
    expect(DB::table('nhl_schedule_snapshots')->count())->toBe($count);
    $row = DB::table('nhl_schedule_snapshots')->where('snapshot_key', 'week:2026-11-16')->first();
    expect(json_decode($row->payload, true)['game_count'])->toBe(1);
});

it('warm reads do not query source games or team identities', function (): void {
    app(NhlScheduleSnapshots::class)->build('20262027');
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $payload = app(NhlScheduleSnapshots::class)->read(CarbonImmutable::parse('2026-11-16', 'America/Toronto'));
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($payload['prepared'])->toBeTrue()->and($queries)->not->toContain('nhl_games', 'nhl_teams');
    Bus::assertNothingDispatched();
});

it('cold reads queue preparation without calculating or publishing source data inline', function (): void {
    $this->mock(\App\Services\NhlScheduleCalculator::class)->shouldNotReceive('week');
    $this->get(route('tools.schedule'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('schedule.prepared', false)->has('schedule.days', 7));
    $this->assertDatabaseCount('nhl_schedule_snapshots', 0);
    Bus::assertDispatched(BuildNhlScheduleSnapshotsJob::class, fn ($job) => $job->seasonId === '20262027');
});

it('keeps the last published snapshot unchanged when replacement calculation fails', function (): void {
    $service = app(NhlScheduleSnapshots::class);
    $service->build('20262027');
    $before = DB::table('nhl_schedule_snapshots')->orderBy('snapshot_key')->get()->toJson();
    $this->mock(\App\Services\NhlScheduleCalculator::class)->shouldReceive('week')->once()->andThrow(new RuntimeException('Calculation failed'));
    expect(fn () => $service->build('20262027'))->toThrow(RuntimeException::class, 'Calculation failed');
    expect(DB::table('nhl_schedule_snapshots')->orderBy('snapshot_key')->get()->toJson())->toBe($before);
});

it('retains last off-night totals while queuing the new Eastern dates preparation', function (): void {
    app(NhlScheduleSnapshots::class)->build('20262027');
    $this->travelTo(Carbon::parse('2026-11-20 05:01:00 UTC'));
    $payload = app(NhlScheduleSnapshots::class)->read(CarbonImmutable::parse('2026-11-16', 'America/Toronto'));
    expect($payload['prepared'])->toBeFalse()->and($payload['off_nights']['from_date'])->toBe('2026-11-19');
    Bus::assertDispatched(BuildNhlScheduleSnapshotsJob::class);
});

it('stores off-night counts and build timestamps without the unused game-date list', function (): void {
    ($this->league)();
    ($this->game)(2026025000, '2026-12-01');
    app(NhlScheduleSnapshots::class)->build('20262027');
    $row = DB::table('nhl_schedule_snapshots')->where('snapshot_key', 'off:20262027:2026-11-18')->first();
    $payload = json_decode($row->payload, true);
    expect($row->built_at)->not->toBeNull()->and($payload['teams'])->toHaveCount(32)
        ->and(array_key_exists('games', $payload['teams'][0]))->toBeFalse()
        ->and(array_key_exists('logo', $payload['teams'][0]))->toBeFalse();
});

it('uses the game-processing queue and shared database uniqueness for background builds', function (): void {
    $job = new BuildNhlScheduleSnapshotsJob('20262027');
    expect($job->queue)->toBe('games-processing')->and($job->uniqueId())->toBe('20262027')
        ->and($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing::class)
        ->and($job->middleware()[0])->toBeInstanceOf(\Illuminate\Queue\Middleware\WithoutOverlapping::class);
});

it('rejects invalid seasons before calculating or writing snapshots', function (string $season): void {
    expect(fn () => app(NhlScheduleSnapshots::class)->build($season))->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseCount('nhl_schedule_snapshots', 0);
})->with(['2026', '20262028', 'nonsense']);

it('queues both seasons affected by schedule edits across September first', function (): void {
    app(NhlScheduleSnapshots::class)->changedDate('2026-09-01');
    Bus::assertDispatched(BuildNhlScheduleSnapshotsJob::class, fn ($job) => $job->seasonId === '20252026');
    Bus::assertDispatched(BuildNhlScheduleSnapshotsJob::class, fn ($job) => $job->seasonId === '20262027');
});

it('blocks non-admin schedule preparation and scheduling mutations', function (bool $signedIn): void {
    if ($signedIn) {
        $this->actingAs($this->viewer);
    }
    $this->postJson(route('admin.imports.run', ['key' => 'nhl-schedule-snapshots']))->assertStatus($signedIn ? 403 : 401);
    $this->putJson(route('admin.imports.schedule.update', ['key' => 'nhl-schedule-snapshots']), ['enabled' => true])->assertStatus($signedIn ? 403 : 401);
})->with([false, true]);

it('lets super-admins queue schedule preparation with a persistent progress run', function (): void {
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99]);
    $this->viewer->roles()->attach($role->id, ['organization_id' => null]);
    $this->actingAs($this->viewer)->postJson(route('admin.imports.run', ['key' => 'nhl-schedule-snapshots']))->assertOk();
    $this->assertDatabaseHas('import_runs', ['source' => 'nhl-schedule-snapshots', 'status' => 'working']);
    Bus::assertBatched(fn ($batch) => $batch->jobs->contains(fn ($job) => $job instanceof RunImportCommandJob && $job->command === 'nhl:prepare-schedule') && $batch->options['queue'] === 'games-processing');
});

it('lets super-admins save the existing automatic preparation schedule without running it', function (): void {
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99]);
    $this->viewer->roles()->attach($role->id, ['organization_id' => null]);
    $this->actingAs($this->viewer)->putJson(route('admin.imports.schedule.update', ['key' => 'nhl-schedule-snapshots']), [
        'enabled' => true, 'intervals' => ['current' => 3600],
    ])->assertOk();
    $this->assertDatabaseHas('admin_import_schedules', ['source_key' => 'nhl-schedule-snapshots', 'enabled' => true, 'interval_seconds' => 3600]);
    Bus::assertNothingDispatched();
});

it('does not queue builds for an arbitrary season with no stored source schedule', function (): void {
    app(NhlScheduleSnapshots::class)->build('20262027');
    $payload = app(NhlScheduleSnapshots::class)->read(CarbonImmutable::parse('2090-11-13', 'America/Toronto')->startOfWeek());
    expect($payload['prepared'])->toBeTrue()->and($payload['teams'])->toBe([]);
    Bus::assertNothingDispatched();
});


it('refreshes snapshots for final changes while ignoring ordinary live clock updates', function (): void {
    $listener = new \App\Listeners\QueueNhlScheduleSnapshots;
    $listener->completed(new \App\Events\NhlLiveGameUpdated(['changed' => true, 'game' => ['game_state' => 'LIVE', 'game_date' => '2026-11-18']]));
    Bus::assertNothingDispatched();
    $listener->completed(new \App\Events\NhlLiveGameUpdated(['changed' => true, 'game' => ['game_state' => 'FINAL', 'game_date' => '2026-11-18']]));
    Bus::assertDispatched(BuildNhlScheduleSnapshotsJob::class, fn ($job) => $job->seasonId === '20262027');
});

it('queues the affected season after season-stat source rollup commits', function (): void {
    (new \App\Listeners\QueueNhlScheduleSnapshots)->synced(new \App\Events\NhlSeasonStatsSynced('20252026', 32));
    Bus::assertDispatched(BuildNhlScheduleSnapshotsJob::class, fn ($job) => $job->seasonId === '20252026');
});


it('limits scheduled refreshes to the current season while manual builds can prepare history', function (): void {
    app(\App\Services\AdminImportSchedules::class)->update('nhl-schedule-snapshots', true, ['current' => 3600]);
    $this->artisan('admin:dispatch-scheduled-imports')->assertSuccessful();
    Bus::assertBatched(fn ($batch) => $batch->jobs->contains(fn ($job) => $job instanceof RunImportCommandJob
        && $job->source === 'nhl-schedule-snapshots' && ($job->options['--season'] ?? null) === '20262027'));
    $this->assertDatabaseCount('nhl_schedule_snapshots', 0);
});


it('prepares the next Eastern day so ordinary midnight rollover remains a warm read', function (): void {
    app(NhlScheduleSnapshots::class)->build('20262027');
    $this->travelTo(Carbon::parse('2026-11-19 05:01:00 UTC'));
    $payload = app(NhlScheduleSnapshots::class)->read(CarbonImmutable::parse('2026-11-16', 'America/Toronto'));
    expect($payload['prepared'])->toBeTrue()->and($payload['off_nights']['from_date'])->toBe('2026-11-19');
    Bus::assertNothingDispatched();
});


it('defaults new automatic schedule preparation to daily without replacing saved intervals', function (): void {
    $schedules = app(\App\Services\AdminImportSchedules::class);
    $row = $schedules->forSource('nhl-schedule-snapshots')->first();
    expect($row->interval_seconds)->toBe(86400)->and($row->enabled)->toBeFalse();
    $row->update(['interval_seconds' => 7200]);
    expect($schedules->forSource('nhl-schedule-snapshots')->first()->interval_seconds)->toBe(7200);
    Bus::assertNothingDispatched();
});


it('saves daily Eastern run time through admin HTTP and returns it with the next run', function (): void {
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99]);
    $this->viewer->roles()->attach($role->id, ['organization_id' => null]);
    $this->actingAs($this->viewer)->putJson(route('admin.imports.schedule.update', ['key' => 'nhl-schedule-snapshots']), [
        'enabled' => true, 'intervals' => ['current' => 86400],
        'timing' => ['daily_start_time' => '14:30', 'timezone' => 'America/Toronto'],
    ])->assertOk()->assertJsonPath('schedule.timing.daily_start_time', '14:30')->assertJsonPath('schedule.timing.timezone', 'America/Toronto');
    $this->assertDatabaseHas('admin_import_schedules', ['source_key' => 'nhl-schedule-snapshots', 'daily_start_time' => '14:30', 'timezone' => 'America/Toronto']);
    $this->getJson(route('admin.imports.status', ['key' => 'nhl-schedule-snapshots']))->assertOk()
        ->assertJsonPath('schedule.timing.daily_start_time', '14:30')
        ->assertJsonPath('schedule.lanes.current.next_due_at', '2026-11-18T19:30:00+00:00');
    Bus::assertNothingDispatched();
});

it('retains the chosen daily time when the recycle toggle changes enabled state', function (): void {
    $service = app(\App\Services\AdminImportSchedules::class);
    $service->update('nhl-schedule-snapshots', false, ['current' => 86400], ['daily_start_time' => '14:30', 'timezone' => 'America/Toronto']);
    $payload = $service->update('nhl-schedule-snapshots', true, []);
    expect($payload['timing']['daily_start_time'])->toBe('14:30')
        ->and($payload['lanes']['current']['next_due_at'])->toBe('2026-11-18T19:30:00+00:00');
    $payload = $service->update('nhl-schedule-snapshots', false, []);
    expect($payload['timing']['daily_start_time'])->toBe('14:30')->and($payload['lanes']['current']['next_due_at'])->toBeNull();
});

it('keeps the same Eastern daily time through daylight-saving transitions', function (string $now, string $dispatched, string $next): void {
    $this->travelTo(Carbon::parse($now));
    $service = app(\App\Services\AdminImportSchedules::class);
    $service->update('nhl-schedule-snapshots', true, ['current' => 86400], ['daily_start_time' => '06:30']);
    $row = \App\Models\AdminImportSchedule::where('source_key', 'nhl-schedule-snapshots')->firstOrFail();
    $service->markDispatched($row, CarbonImmutable::parse($dispatched));
    expect($row->fresh()->next_due_at->utc()->format('Y-m-d H:i:s'))->toBe($next);
})->with([
    ['2027-03-13 11:00:00 UTC', '2027-03-13 11:30:00 UTC', '2027-03-14 10:30:00'],
    ['2026-10-31 10:00:00 UTC', '2026-10-31 10:30:00 UTC', '2026-11-01 11:30:00'],
]);

it('uses dispatch-based interval cadence when no start time is selected', function (): void {
    $service = app(\App\Services\AdminImportSchedules::class);
    $service->update('nhl-schedule-snapshots', true, ['current' => 3600], ['daily_start_time' => null]);
    $row = \App\Models\AdminImportSchedule::where('source_key', 'nhl-schedule-snapshots')->firstOrFail();
    $service->markDispatched($row, CarbonImmutable::parse('2026-11-18 14:00:00 UTC'));
    expect($row->fresh()->next_due_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-18 15:00:00');
    $service->update('nhl-schedule-snapshots', true, ['current' => 86400], ['daily_start_time' => null]);
    $row->refresh();
    $service->markDispatched($row, CarbonImmutable::parse('2026-11-18 14:00:00 UTC'));
    expect($row->fresh()->next_due_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-19 14:00:00');
});

it('rejects invalid daily clock input on the admin schedule endpoint', function (): void {
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99]);
    $this->viewer->roles()->attach($role->id, ['organization_id' => null]);
    $this->actingAs($this->viewer)->putJson(route('admin.imports.schedule.update', ['key' => 'nhl-schedule-snapshots']), [
        'enabled' => true, 'intervals' => ['current' => 86400], 'timing' => ['daily_start_time' => '25:90'],
    ])->assertUnprocessable()->assertJsonValidationErrors('timing.daily_start_time');
    Bus::assertNothingDispatched();
});


it('restricts prepared schedule timing status to super-admins', function (bool $signedIn): void {
    if ($signedIn) {
        $this->actingAs($this->viewer);
    }
    $this->getJson(route('admin.imports.status', ['key' => 'nhl-schedule-snapshots']))->assertStatus($signedIn ? 403 : 401);
})->with([false, true]);


it('aligns the first interval to its Eastern start time before or after that clock time', function (string $now, string $expected): void {
    $this->travelTo(Carbon::parse($now));
    $payload = app(\App\Services\AdminImportSchedules::class)->update('nhl-schedule-snapshots', true, ['current' => 10800], ['daily_start_time' => '06:00']);
    expect($payload['lanes']['current']['next_due_at'])->toBe($expected);
})->with([
    ['2026-11-18 09:00:00 UTC', '2026-11-18T11:00:00+00:00'],
    ['2026-11-18 14:00:00 UTC', '2026-11-18T14:00:00+00:00'],
    ['2026-11-18 14:05:00 UTC', '2026-11-18T17:00:00+00:00'],
]);

it('keeps later intervals aligned when a scheduled run is dispatched late', function (): void {
    $this->travelTo(Carbon::parse('2026-11-18 09:00:00 UTC'));
    $service = app(\App\Services\AdminImportSchedules::class);
    $service->update('nhl-schedule-snapshots', true, ['current' => 10800], ['daily_start_time' => '06:00']);
    $row = \App\Models\AdminImportSchedule::where('source_key', 'nhl-schedule-snapshots')->firstOrFail();
    $service->markDispatched($row, CarbonImmutable::parse('2026-11-18 11:07:00 UTC'));
    expect($row->fresh()->next_due_at->utc()->format('H:i'))->toBe('14:00');
    $row->refresh();
    $service->markDispatched($row, CarbonImmutable::parse('2026-11-18 14:35:00 UTC'));
    expect($row->fresh()->next_due_at->utc()->format('H:i'))->toBe('17:00');
});

it('skips missed interval slots without shifting the next slot or queuing catch-up work', function (): void {
    $this->travelTo(Carbon::parse('2026-11-18 09:00:00 UTC'));
    $service = app(\App\Services\AdminImportSchedules::class);
    $service->update('nhl-schedule-snapshots', true, ['current' => 10800], ['daily_start_time' => '06:00']);
    $row = \App\Models\AdminImportSchedule::where('source_key', 'nhl-schedule-snapshots')->firstOrFail();
    $service->markDispatched($row, CarbonImmutable::parse('2026-11-18 21:40:00 UTC'));
    expect($row->fresh()->next_due_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-18 23:00:00');
    Bus::assertNothingDispatched();
});

it('preserves a running interval across midnight and unchanged settings saves', function (): void {
    $this->travelTo(Carbon::parse('2026-11-18 21:00:00 UTC'));
    $service = app(\App\Services\AdminImportSchedules::class);
    $service->update('nhl-schedule-snapshots', true, ['current' => 18000], ['daily_start_time' => '18:00']);
    $row = \App\Models\AdminImportSchedule::where('source_key', 'nhl-schedule-snapshots')->firstOrFail();
    $service->markDispatched($row, CarbonImmutable::parse('2026-11-18 23:00:00 UTC'));
    $row->refresh();
    $service->markDispatched($row, CarbonImmutable::parse('2026-11-19 04:00:00 UTC'));
    expect($row->fresh()->next_due_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-19 09:00:00');
    $this->travelTo(Carbon::parse('2026-11-19 08:00:00 UTC'));
    $payload = $service->update('nhl-schedule-snapshots', true, ['current' => 18000], ['daily_start_time' => '18:00']);
    expect($payload['lanes']['current']['next_due_at'])->toBe('2026-11-19T09:00:00+00:00');
});

it('reanchors future intervals when the chosen start time or frequency changes', function (): void {
    $this->travelTo(Carbon::parse('2026-11-18 09:00:00 UTC'));
    $service = app(\App\Services\AdminImportSchedules::class);
    $service->update('nhl-schedule-snapshots', true, ['current' => 10800], ['daily_start_time' => '06:00']);
    $this->travelTo(Carbon::parse('2026-11-18 13:00:00 UTC'));
    $payload = $service->update('nhl-schedule-snapshots', true, ['current' => 10800], ['daily_start_time' => '07:00']);
    expect($payload['lanes']['current']['next_due_at'])->toBe('2026-11-18T15:00:00+00:00');
    $payload = $service->update('nhl-schedule-snapshots', true, ['current' => 18000], ['daily_start_time' => '07:00']);
    expect($payload['lanes']['current']['next_due_at'])->toBe('2026-11-18T17:00:00+00:00');
});

it('round trips a three-hour frequency and Eastern start time through admin HTTP', function (): void {
    $this->travelTo(Carbon::parse('2026-11-18 09:00:00 UTC'));
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99]);
    $this->viewer->roles()->attach($role->id, ['organization_id' => null]);
    $this->actingAs($this->viewer)->putJson(route('admin.imports.schedule.update', ['key' => 'nhl-schedule-snapshots']), [
        'enabled' => true, 'intervals' => ['current' => 10800], 'timing' => ['daily_start_time' => '06:00'],
    ])->assertOk()->assertJsonPath('schedule.lanes.current.interval_seconds', 10800);
    $this->assertDatabaseHas('admin_import_schedules', ['source_key' => 'nhl-schedule-snapshots', 'interval_seconds' => 10800, 'timezone' => 'America/Toronto']);
    $this->getJson(route('admin.imports.status', ['key' => 'nhl-schedule-snapshots']))->assertOk()
        ->assertJsonPath('schedule.timing.daily_start_time', '06:00')
        ->assertJsonPath('schedule.lanes.current.next_due_at', '2026-11-18T11:00:00+00:00');
    Bus::assertNothingDispatched();
});


it('does not repeat an anchored slot when the frequency changes just after dispatch', function (): void {
    $this->travelTo(Carbon::parse('2026-11-18 09:00:00 UTC'));
    $service = app(\App\Services\AdminImportSchedules::class);
    $service->update('nhl-schedule-snapshots', true, ['current' => 18000], ['daily_start_time' => '06:00']);
    $row = \App\Models\AdminImportSchedule::where('source_key', 'nhl-schedule-snapshots')->firstOrFail();
    $service->markDispatched($row, CarbonImmutable::parse('2026-11-18 11:00:00 UTC'));
    $this->travelTo(Carbon::parse('2026-11-18 11:00:00 UTC'));
    $payload = $service->update('nhl-schedule-snapshots', true, ['current' => 10800], ['daily_start_time' => '06:00']);
    expect($payload['lanes']['current']['next_due_at'])->toBe('2026-11-18T14:00:00+00:00');
    Bus::assertNothingDispatched();
});
