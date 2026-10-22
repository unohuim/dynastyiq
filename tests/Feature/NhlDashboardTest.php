<?php

declare(strict_types=1);

use App\Models\NhlGame;
use App\Models\Player;
use App\Models\User;
use App\Services\NhlDashboardNews;
use App\Services\NhlDashboardPayload;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-22 14:00:00 UTC'));
    config(['cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();
    User::factory()->create();
    $this->article = fn (int $id, array $extra = []): array => [
        '_entityId' => 'story-'.$id, 'slug' => 'story-'.$id, 'headline' => 'Story '.$id,
        'summary' => 'Summary '.$id, 'contentDate' => '2026-10-21T12:00:00Z', 'tags' => [],
        'thumbnail' => ['templateUrl' => 'https://media.d3.nhle.com/image/private/{formatInstructions}/test-'.$id],
        ...$extra,
    ];
    $this->feedItems = array_map($this->article, range(1, 8));
    $this->feedStatus = 200;
    Http::fake(fn () => Http::response(['items' => $this->feedItems], $this->feedStatus));
    $this->game = fn (int $id = 2026020001, string $date = '2026-10-21', string $state = 'OFF'): NhlGame => NhlGame::create([
        'nhl_game_id' => $id, 'season_id' => '20262027', 'game_type' => 2, 'game_date' => $date,
        'game_dow' => 'Wednesday', 'game_month' => 'October', 'game_state' => $state,
        'home_team_id' => 10, 'home_team_abbrev' => 'TOR', 'home_team_score' => 4,
        'away_team_id' => 6, 'away_team_abbrev' => 'BOS', 'away_team_score' => 2,
    ]);
    $this->box = function (int $id, array $extra = [], int $gameId = 2026020001): void {
        DB::table('nhl_boxscores')->insert([
            'nhl_game_id' => $gameId, 'nhl_player_id' => $id, 'nhl_team_id' => 10,
            'player_name' => 'Player '.$id, 'position' => 'C', 'toi_seconds' => 1200,
            'goals' => 0, 'assists' => 1, 'points' => 1, 'sog' => 2, ...$extra,
        ]);
    };
    $this->goalie = function (int $id, int $shots = 20, int $ga = 1, float $xg = .2, bool $complete = true): void {
        ($this->box)($id, ['position' => 'G', 'shots_against' => $shots, 'saves' => $shots - $ga,
            'goals_against' => $ga, 'goals' => 0, 'assists' => 0, 'points' => 0]);
        $model = DB::table('nhl_expected_goals_models')->value('id') ?? DB::table('nhl_expected_goals_models')->insertGetId([
            'name' => 'test-goals', 'version' => 'test-v1', 'prediction_target' => 'goal',
            'status' => 'draft', 'trained_at' => now(),
        ]);
        for ($shot = 0; $shot < $shots; $shot++) {
            $event = DB::table('play_by_plays')->insertGetId(['nhl_game_id' => 2026020001]);
            $fact = DB::table('nhl_shot_attempts_facts')->insertGetId([
                'nhl_game_id' => 2026020001, 'play_by_play_id' => $event,
                'goalie_player_id' => $id, 'attempt_result' => 'saved', 'is_shot_on_goal' => true,
                'is_empty_net' => false, 'period_type' => 'REG',
            ]);
            if ($complete || $shot > 0) {
                DB::table('nhl_shot_attempt_predictions')->insert([
                    'nhl_game_id' => 2026020001, 'play_by_play_id' => $event,
                    'expected_goals_model_id' => $model, 'shot_attempt_fact_id' => $fact,
                    'prediction_target' => 'goal', 'is_scored' => true, 'xg' => $xg,
                ]);
            }
        }
    };
});

afterEach(function (): void { $this->travelBack(); });

it('serves the public dashboard to guests at the root URI', function (): void {
    $this->get(route('welcome'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Dashboard/Index')->has('dashboard.featured', 3));
});

it('serves the same dashboard to authenticated root visitors', function (): void {
    $this->actingAs(User::factory()->create())->get(route('welcome'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Index')->has('dashboard.headlines', 5)
            ->where('shell.primary.0.href', route('welcome'))->where('shell.primary.0.active', true));
});

it('serves the shared dashboard at the authenticated dashboard URI', function (): void {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Index')
            ->where('shell.primary.0.href', route('welcome'))->where('shell.primary.0.active', true));
});

it('preserves authentication on the dashboard URI', function (): void {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    Http::assertNothingSent();
});

it('does not require admin permissions or community membership to read the homepage', function (): void {
    $this->actingAs(User::factory()->create())->get(route('welcome'))->assertOk();
});

it('rejects overlong dashboard searches before requesting editorial content', function (): void {
    $this->getJson(route('welcome', ['q' => str_repeat('x', 101)]))->assertUnprocessable()->assertJsonValidationErrors('q');
    Http::assertNothingSent();
});

it('partitions the first three stories from the remaining headlines without duplication', function (): void {
    $data = app(NhlDashboardPayload::class)->build();
    expect($data['featured'])->toHaveCount(3)->and($data['headlines'])->toHaveCount(5)
        ->and(array_intersect(array_column($data['featured'], 'id'), array_column($data['headlines'], 'id')))->toBe([]);
});

it('uses official NHL article URLs and provider imagery', function (): void {
    $story = app(NhlDashboardNews::class)->feed()['items'][0];
    expect($story['url'])->toBe('https://www.nhl.com/news/story-1')
        ->and($story['image_url'])->toBe('https://media.d3.nhle.com/image/private/t_ratio16_9-size50/test-1');
});

it('normalizes provider HTML into plain headline and summary text', function (): void {
    $this->feedItems = [($this->article)(1, ['headline' => '<b>A &amp; B</b>', 'summary' => '<p>Summary</p>'])];
    $story = app(NhlDashboardNews::class)->feed()['items'][0];
    expect($story['title'])->toBe('A & B')->and($story['summary'])->toBe('Summary');
});

it('rejects malformed external story slugs instead of exposing unsafe links', function (): void {
    $this->feedItems = [($this->article)(1, ['slug' => 'https://other.example/story'])];
    expect(app(NhlDashboardNews::class)->feed()['items'])->toBe([]);
});

it('omits future-dated editorial stories', function (): void {
    $this->feedItems = [($this->article)(1, ['contentDate' => '2026-10-23T12:00:00Z'])];
    expect(app(NhlDashboardNews::class)->feed()['items'])->toBe([]);
});

it('deduplicates editorial entities before selecting featured stories', function (): void {
    $this->feedItems = [($this->article)(1), ($this->article)(1)];
    expect(app(NhlDashboardNews::class)->feed()['items'])->toHaveCount(1);
});

it('caches news without fetching the removed video feed across dashboard visits', function (): void {
    app(NhlDashboardPayload::class)->build();
    app(NhlDashboardPayload::class)->build();
    Http::assertSentCount(1);
});

it('renders an empty news state when the first provider request fails', function (): void {
    $this->feedStatus = 503;
    $this->get(route('welcome'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.featured', [])->where('dashboard.news_unavailable', true));
});

it('retains the last successful editorial payload after a provider failure', function (): void {
    $reader = app(NhlDashboardNews::class);
    $reader->feed();
    $this->travel(6)->minutes();
    $this->feedStatus = 503;
    $result = $reader->feed();
    expect($result['unavailable'])->toBeTrue()->and($result['items'])->toHaveCount(8);
});

it('uses yesterday as its slate date before eleven Eastern', function (): void {
    expect(app(NhlDashboardPayload::class)->build()['slate_date'])->toBe('2026-10-21');
});

it('uses today as its slate date from eleven Eastern', function (): void {
    $this->travelTo(Carbon::parse('2026-10-22 15:00:00 UTC'));
    expect(app(NhlDashboardPayload::class)->build()['slate_date'])->toBe('2026-10-22');
});

it('selects the most recent fully completed game day instead of a partial slate', function (): void {
    ($this->game)(2026020001, '2026-10-20');
    ($this->game)(2026020002, '2026-10-21');
    ($this->game)(2026020003, '2026-10-21', 'LIVE');
    expect(app(NhlDashboardPayload::class)->build()['performance_date'])->toBe('2026-10-20');
});

it('always fills four cards from real skater performances on a quiet night', function (): void {
    ($this->game)();
    foreach (range(1, 6) as $id) { ($this->box)($id); }
    $data = app(NhlDashboardPayload::class)->build();
    expect($data['performers'])->toHaveCount(4)->and(array_column($data['performers'], 'qualified'))->toBe([false, false, false, false]);
    $this->assertDatabaseCount('nhl_boxscores', 6);
    $this->get(route('welcome'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('dashboard.performers', 4)->where('dashboard.performers.0.points', 1));
});

it('prioritizes qualifying skaters over nonqualifying skaters', function (): void {
    ($this->game)();
    foreach (range(1, 5) as $id) { ($this->box)($id); }
    ($this->box)(6, ['goals' => 2, 'assists' => 0, 'points' => 2]);
    expect(app(NhlDashboardPayload::class)->build()['performers'][0]['nhl_player_id'])->toBe(6);
});

it('fills three qualifying goalie cards with the best available skater below threshold', function (): void {
    ($this->game)();
    foreach (range(1, 3) as $id) { ($this->goalie)($id); }
    ($this->box)(4, ['points' => 0, 'assists' => 0]);
    ($this->box)(5);
    $rows = app(NhlDashboardPayload::class)->build()['performers'];
    expect($rows)->toHaveCount(4)->and(array_column($rows, 'goalie'))->toBe([true, true, true, false])
        ->and($rows[3]['nhl_player_id'])->toBe(5);
});

it('allows four outstanding goalies to occupy all four slots', function (): void {
    ($this->game)();
    foreach (range(1, 4) as $id) { ($this->goalie)($id); }
    ($this->box)(5);
    $rows = app(NhlDashboardPayload::class)->build()['performers'];
    expect($rows)->toHaveCount(4)->and(array_column($rows, 'goalie'))->toBe([true, true, true, true]);
});

it('requires the goalie save percentage threshold as well as positive GSAx', function (): void {
    ($this->game)();
    ($this->goalie)(1, 20, 3, .4);
    foreach (range(2, 5) as $id) { ($this->box)($id); }
    expect(array_column(app(NhlDashboardPayload::class)->build()['performers'], 'goalie'))->toBe([false, false, false, false]);
});

it('does not qualify a goalie from incomplete expected-goal coverage', function (): void {
    ($this->game)();
    ($this->goalie)(1, 20, 1, .2, false);
    foreach (range(2, 5) as $id) { ($this->box)($id); }
    expect(array_column(app(NhlDashboardPayload::class)->build()['performers'], 'goalie'))->toBe([false, false, false, false]);
});

it('does not invent cards when no imported performances exist', function (): void {
    ($this->game)();
    expect(app(NhlDashboardPayload::class)->build()['performers'])->toBe([]);
});

it('does not treat dressed backups without ice time as performances', function (): void {
    ($this->game)();
    ($this->box)(1, ['position' => 'G', 'toi_seconds' => 0]);
    foreach (range(2, 5) as $id) { ($this->box)($id); }
    expect(array_column(app(NhlDashboardPayload::class)->build()['performers'], 'nhl_player_id'))->not->toContain(1);
});

it('reports real team goal averages rather than summing predictions', function (): void {
    ($this->game)();
    $teams = collect(app(NhlDashboardPayload::class)->build()['team_trends'])->keyBy('team');
    expect((float) $teams['TOR']['offense'])->toBe(4.0)->and((float) $teams['TOR']['defense'])->toBe(2.0);
});

it('keeps unavailable special-team boxscore coverage null rather than reporting zero', function (): void {
    ($this->game)();
    $teams = app(NhlDashboardPayload::class)->build()['team_trends'];
    expect($teams[0]['special_teams'])->toBeNull();
});

it('exposes matching news search results without extra provider requests', function (): void {
    $data = app(NhlDashboardPayload::class)->build('Story 1');
    expect($data['search']['stories'])->toHaveCount(1);
    Http::assertSentCount(1);
});

it('replaces the video payload with local line combinations', function (): void {
    $data = app(NhlDashboardPayload::class)->build();
    expect($data)->not->toHaveKey('videos');
    expect($data['line_combinations'])->toBe([]);
    expect($data['links']['line_combinations'])->toContain('/stats/units');
    Http::assertSentCount(1);
});

it('selects team comparison windows from available finished games', function (int $total, int $window, int $currentGames, int $priorGames, float $offense, ?float $delta): void {
    foreach (range(0, $total - 1) as $index) {
        $game = ($this->game)(2026020100 + $index, Carbon::parse('2026-10-21')->subDays($index)->toDateString());
        $game->update(['home_team_score' => [4, 2, 6, 8][intdiv($index, 5)]]);
    }

    $team = collect(app(NhlDashboardPayload::class)->build()['team_trends'])->firstWhere('team', 'TOR');
    expect($team['comparison_games'])->toBe($window)
        ->and($team['games'])->toBe($currentGames)
        ->and($team['previous_games'])->toBe($priorGames)
        ->and((float) $team['offense'])->toBe($offense)
        ->and($team['offense_delta'] === null ? null : (float) $team['offense_delta'])->toBe($delta);

    $this->get(route('welcome'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.team_trends.0.comparison_games', $window)
        ->where('dashboard.team_trends.0.games', $currentGames)
        ->where('dashboard.team_trends.0.previous_games', $priorGames));
})->with([
    'twenty games uses ten versus ten' => [20, 10, 10, 10, 3.0, -4.0],
    'nineteen games uses five versus five' => [19, 5, 5, 5, 4.0, 2.0],
    'ten games uses five versus five' => [10, 5, 5, 5, 4.0, 2.0],
    'seven games exposes partial previous window' => [7, 5, 5, 2, 4.0, 2.0],
    'five games has no prior comparison' => [5, 5, 5, 0, 4.0, null],
    'three games exposes partial current window' => [3, 5, 3, 0, 4.0, null],
]);


it('reads strictly five-on-five trios from the completed night', function (int $defenders, int $goalies, int $goalieEnd, int $expectedCount, array $positions = ['R', 'C', 'L'], array $expectedNames = ['TOR F2', 'TOR F1', 'TOR F0'], array $drawCounts = []): void {
    ($this->game)();
    $forwardShifts = [];
    foreach (['TOR' => 10, 'BOS' => 6] as $team => $teamId) {
        foreach (['F' => 3, 'D' => $defenders, 'G' => $goalies] as $type => $count) {
            if ($count === 0) {
                continue;
            }
            $unit = DB::table('nhl_units')->insertGetId(['team_abbrev' => $team, 'unit_type' => $type]);
            for ($i = 0; $i < $count; $i++) {
                $player = Player::create(['full_name' => $team.' '.$type.$i, 'first_name' => $team, 'last_name' => $type.$i,
                    'position' => $type === 'F' ? $positions[$i] : $type,
                    'nhl_id' => $type === 'F' ? ($team === 'TOR' ? 9000000 : 9100000) + $i : null]);
                DB::table('nhl_unit_players')->insert(['unit_id' => $unit, 'player_id' => $player->id]);
            }
            $end = $type === 'G' ? $goalieEnd : 360;
            $shift = DB::table('nhl_unit_shifts')->insertGetId([
                'nhl_game_id' => 2026020001, 'unit_id' => $unit, 'team_id' => $teamId, 'team_abbrev' => $team,
                'period' => 1, 'start_time' => '00:00', 'end_time' => '06:00',
                'start_game_seconds' => 0, 'end_game_seconds' => $end, 'seconds' => $end,
            ]);
            if ($type === 'F') {
                $forwardShifts[] = $shift;
            }
        }
    }
    foreach ($drawCounts as $index => $draws) {
        for ($draw = 0; $draw < $draws; $draw++) {
            DB::table('play_by_plays')->insert([
                'nhl_game_id' => 2026020001, 'type_desc_key' => 'faceoff',
                'fo_winning_player_id' => $draw % 2 === 0 ? 9000000 + $index : 9999999,
                'fo_losing_player_id' => $draw % 2 === 0 ? 9999999 : 9000000 + $index,
            ]);
        }
    }
    foreach ([['goal', '1551'], ['missed-shot', '1551'], ['goal', '1541'], ['goal', '1441'], ['goal', '0651']] as [$type, $situation]) {
        $event = DB::table('play_by_plays')->insertGetId([
            'nhl_game_id' => 2026020001, 'type_desc_key' => $type, 'shot_type' => 'wrist',
            'period_type' => 'REG', 'situation_code' => $situation, 'event_owner_team_id' => 10,
        ]);
        foreach ($forwardShifts as $shift) {
            DB::table('event_unit_shifts')->insert(['event_id' => $event, 'unit_shift_id' => $shift]);
        }
    }
    $rows = app(NhlDashboardPayload::class)->build()['line_combinations'];
    expect($rows)->toHaveCount($expectedCount);
    if ($expectedCount > 0) {
        expect($rows[0]['team'])->toBe('TOR')->and($rows[0]['players'])->toHaveCount(3)
            ->and($rows[0]['players'])->toBe($expectedNames)
            ->and($rows[0]['toi_seconds'])->toBe($goalieEnd)->and($rows[0]['gf'])->toBe(1)
            ->and($rows[0]['ga'])->toBe(0)->and($rows[0]['cf'])->toBe(2)
            ->and($rows[0]['corsi'])->toBe(2)->and($rows[0]['shifts'])->toBe(1)
            ->and((float) $rows[0]['cf_pct'])->toBe(100.0);
        $this->get(route('welcome'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('dashboard.line_combinations', 2)->where('dashboard.line_combinations.0.gf', 1)
            ->where('dashboard.line_combinations.0.players', $expectedNames));
    }
})->with([
    'full five on five' => [2, 1, 360, 2],
    'game draws override listed wing position for centre' => [2, 1, 360, 2, ['L', 'R', 'R'], ['TOR F1', 'TOR F0', 'TOR F2'], [12, 0, 1]],
    'tied game draws prefer listed centre and wings' => [2, 1, 360, 2, ['R', 'C', 'L'], ['TOR F2', 'TOR F1', 'TOR F0'], [5, 5, 0]],
    'ambiguous roles retain stable identity order' => [2, 1, 360, 2, ['', '', ''], ['TOR F0', 'TOR F1', 'TOR F2']],
    'multi-position eligibility maximizes matching slots' => [2, 1, 360, 2, ['C/R', 'LW', 'C'], ['TOR F1', 'TOR F2', 'TOR F0']],
    'only time with both goalies counts' => [2, 1, 300, 2],
    'four on four is excluded' => [1, 1, 360, 0],
    'empty nets are excluded' => [2, 0, 360, 0],
]);


it('retains every qualifying performer beyond four without adding nonqualifying fillers', function (): void {
    ($this->game)();
    foreach (range(1, 7) as $id) {
        ($this->box)($id, ['goals' => 2, 'assists' => 1, 'points' => 3]);
    }
    ($this->box)(8, ['goals' => 0, 'assists' => 1, 'points' => 1]);
    $data = app(NhlDashboardPayload::class)->build();
    expect($data['performers'])->toHaveCount(7)
        ->and(array_column($data['performers'], 'qualified'))->toBe(array_fill(0, 7, true))
        ->and(array_column($data['performers'], 'nhl_player_id'))->not->toContain(8);
    $this->get(route('welcome'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('dashboard.performers', 7)->where('dashboard.performers.6.qualified', true));
});

it('retains qualifying goalies alongside every qualifying skater beyond four', function (): void {
    ($this->game)();
    ($this->goalie)(1);
    ($this->goalie)(2);
    foreach (range(3, 7) as $id) {
        ($this->box)($id, ['goals' => 1, 'assists' => 2, 'points' => 3]);
    }
    $rows = app(NhlDashboardPayload::class)->build()['performers'];
    expect($rows)->toHaveCount(7)
        ->and(count(array_filter(array_column($rows, 'goalie'))))->toBe(2)
        ->and(array_column($rows, 'qualified'))->toBe(array_fill(0, 7, true));
});


it('retains all line candidates and counts shared shifts once across other-unit changes', function (): void {
    ($this->game)();
    $makeUnit = function (string $team, string $type, int $count, int $start, int $end): void {
        $unit = DB::table('nhl_units')->insertGetId(['team_abbrev' => $team, 'unit_type' => $type]);
        for ($index = 0; $index < $count; $index++) {
            $player = Player::create(['full_name' => $team.' '.$type.$unit.'-'.$index, 'first_name' => $team, 'last_name' => $type.$index]);
            DB::table('nhl_unit_players')->insert(['unit_id' => $unit, 'player_id' => $player->id]);
        }
        DB::table('nhl_unit_shifts')->insert([
            'nhl_game_id' => 2026020001, 'unit_id' => $unit, 'team_id' => $team === 'TOR' ? 10 : 6, 'team_abbrev' => $team,
            'period' => 1, 'start_time' => '00:00', 'end_time' => '06:00',
            'start_game_seconds' => $start, 'end_game_seconds' => $end, 'seconds' => $end - $start,
        ]);
    };
    foreach (['TOR', 'BOS'] as $team) {
        $makeUnit($team, 'G', 1, 0, 360);
        $makeUnit($team, 'D', 2, 0, 180);
        $makeUnit($team, 'D', 2, 180, 360);
    }
    $makeUnit('BOS', 'F', 3, 0, 360);
    foreach (range(0, 5) as $index) {
        $makeUnit('TOR', 'F', 3, $index * 60, ($index + 1) * 60);
    }
    $rows = app(NhlDashboardPayload::class)->build()['line_combinations'];
    expect($rows)->toHaveCount(7);
    $away = collect($rows)->firstWhere('team', 'BOS');
    expect($away['shifts'])->toBe(1)->and($away['toi_seconds'])->toBe(360);
    $this->get(route('welcome'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('dashboard.line_combinations', 7));
});
