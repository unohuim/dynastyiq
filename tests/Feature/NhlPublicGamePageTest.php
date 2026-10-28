<?php

declare(strict_types=1);

use App\Jobs\RefreshNhlPublicGamePageJob;
use App\Models\NhlGame;
use App\Models\User;
use App\Services\NhlAnticipatedLineupPayload;
use App\Services\NhlGameLiveContext;
use App\Services\NhlGamePredictionHistory;
use App\Services\NhlPublicGamePage;
use App\Services\NhlStartingGoalieSelector;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-27 12:00:00 UTC'));
    config(['cache.default' => 'array']);
    Cache::flush();
    Bus::fake();
    Http::preventStrayRequests();
    $this->mock(\App\Services\PlatformState::class)->shouldReceive('seeded')->andReturn(true);
    $this->live = $this->mock(NhlGameLiveContext::class);
    $this->live->shouldReceive('publicDetail')->andReturnNull()->byDefault();
    $this->lineups = $this->mock(NhlAnticipatedLineupPayload::class);
    $this->lineups->shouldReceive('gamePage')->andReturn(['game' => ['away' => [], 'home' => []]])->byDefault();
    $this->mock(NhlStartingGoalieSelector::class)->shouldReceive('select')->andReturnNull();
    $this->makeGame = fn (int $id = 2026020001, array $extra = []): NhlGame => NhlGame::create([
        'nhl_game_id' => $id, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => '2026-10-26', 'game_dow' => 'Mon', 'game_month' => 'Oct',
        'start_time_utc' => '2026-10-26 23:00:00', 'game_state' => 'OFF',
        'away_team_id' => 6, 'home_team_id' => 10, 'away_team_abbrev' => 'BOS', 'home_team_abbrev' => 'TOR',
        'away_team_common_name' => 'Bruins', 'home_team_common_name' => 'Maple Leafs',
        'away_team_score' => 2, 'home_team_score' => 4, 'away_team_sog' => 20, 'home_team_sog' => 30,
        ...$extra,
    ]);
    $this->timelineProvider = static function (): array {
        return ['game' => ['game_state' => 'LIVE', 'game_date' => '2026-10-27',
            'away' => ['team_id' => 6, 'team_abbrev' => 'BOS', 'score' => 1, 'sog' => 1],
            'home' => ['team_id' => 10, 'team_abbrev' => 'TOR', 'score' => 0, 'sog' => 1]],
            'boxscore' => ['clock' => ['timeRemaining' => '18:00'], 'periodDescriptor' => ['number' => 1]],
            'events_available' => true,
            'roster' => [['playerId' => 100, 'firstName' => ['default' => 'First'], 'lastName' => ['default' => 'Scorer']]],
            'plays' => [
                ['eventId' => 1, 'typeDescKey' => 'goal', 'periodDescriptor' => ['number' => 1, 'periodType' => 'REG'],
                    'timeInPeriod' => '01:00', 'details' => ['scoringPlayerId' => 100, 'eventOwnerTeamId' => 6,
                        'xCoord' => 0, 'yCoord' => 0, 'awayScore' => 1, 'homeScore' => 0]],
                ['eventId' => 2, 'typeDescKey' => 'shot-on-goal', 'periodDescriptor' => ['number' => 1, 'periodType' => 'REG'],
                    'timeInPeriod' => '02:00', 'details' => ['shootingPlayerId' => 200, 'eventOwnerTeamId' => 10,
                        'xCoord' => 75, 'yCoord' => 10, 'private_field' => 'never public']],
            ]];
    };
    $this->readGame = fn (int $id = 2026020001, bool $refresh = false): array => app(NhlPublicGamePage::class)->payload($id, $refresh)['game'];
    $this->savePrediction = function (array $inputs = [], array $extra = []): array {
        return app(NhlGamePredictionHistory::class)->record(2026020001, ['nhl_game_id' => 2026020001, ...$inputs],
            str_repeat('a', 64), ['private_evidence' => 'secret'], [
                'prediction_available' => true,
                'prediction' => ['predicted_score' => ['away' => 2.4, 'home' => 3.1]],
                'market_probabilities' => [
                    ['market_key' => 'moneyline', 'period_key' => 'full_game', 'selection_key' => 'home', 'team_abbrev' => 'TOR', 'probability' => .57],
                ], ...$extra,
            ]);
    };
});

afterEach(function (): void { $this->travelBack(); });

it('opens the public recap for guests at the existing numeric URL', function (): void {
    ($this->makeGame)();
    $this->get('/games/2026020001')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Games/Show')->where('game.mode', 'recap')->where('canManageLineups', false));
});

it('allows ordinary authenticated viewers without granting lineup management', function (): void {
    ($this->makeGame)();
    $this->actingAs(User::factory()->create())->get('/games/2026020001')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManageLineups', false));
});

it('returns not found for an unknown schedule game', function (): void {
    $this->get('/games/9999999999')->assertNotFound();
});

it('renders upcoming games as previews', function (string $state): void {
    ($this->makeGame)(2026020001, ['game_state' => $state, 'game_date' => '2026-10-28']);
    expect(($this->readGame)()['mode'])->toBe('preview');
})->with(['FUT', 'PRE']);

it('treats both stored terminal states as recaps', function (string $state): void {
    ($this->makeGame)(2026020001, ['game_state' => $state]);
    expect(($this->readGame)()['mode'])->toBe('recap');
})->with(['OFF', 'FINAL']);

it('hides stored placeholder scores in previews', function (): void {
    ($this->makeGame)(2026020001, ['game_state' => 'FUT', 'game_date' => '2026-10-28']);
    expect(($this->readGame)()['away']['score'])->toBeNull()->and(($this->readGame)()['home']['score'])->toBeNull();
});

it('reuses prepared recap facts without rereading changed source rows', function (): void {
    ($this->makeGame)();
    ($this->readGame)();
    DB::table('nhl_boxscores')->insert(['nhl_game_id' => 2026020001, 'nhl_player_id' => 100, 'nhl_team_id' => 6, 'position' => 'C', 'goals' => 2]);
    expect(($this->readGame)()['away']['boxscore'])->toBe([]);
});

it('rebuilds a requested snapshot after source preparation', function (): void {
    ($this->makeGame)();
    ($this->readGame)();
    DB::table('nhl_boxscores')->insert(['nhl_game_id' => 2026020001, 'nhl_player_id' => 100, 'nhl_team_id' => 6, 'position' => 'C', 'goals' => 2]);
    expect(($this->readGame)(2026020001, true)['away']['boxscore'][0]['goals'])->toBe(2);
});

it('uses a new snapshot when the stored game changes', function (): void {
    $game = ($this->makeGame)();
    ($this->readGame)();
    $this->travel(1)->seconds();
    $game->update(['home_team_score' => 5]);
    expect(($this->readGame)()['home']['score'])->toBe(5);
});

it('publishes saved public probabilities without private prediction evidence', function (): void {
    ($this->makeGame)(2026020001, ['game_state' => 'FUT', 'game_date' => '2026-10-28']);
    ($this->savePrediction)();
    $this->assertDatabaseHas('nhl_game_prediction_revisions', ['nhl_game_id' => 2026020001, 'is_canonical' => true]);
    $this->get('/games/2026020001')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('game.prediction.probabilities.home', .57)->missing('game.prediction.evidence')->missing('game.prediction.request_inputs'));
});

it('ignores overridden prediction revisions', function (): void {
    ($this->makeGame)();
    ($this->savePrediction)(['home_goalie_id' => 123]);
    expect(($this->readGame)()['prediction'])->toBeNull();
});

it('ignores unsuccessful saved predictions', function (): void {
    ($this->makeGame)();
    ($this->savePrediction)([], ['prediction_available' => false]);
    expect(($this->readGame)()['prediction'])->toBeNull();
});

it('does not invent predictions when no revision exists', function (): void {
    ($this->makeGame)();
    expect(($this->readGame)()['prediction'])->toBeNull();
});

it('excludes the selected and later results from pregame form', function (): void {
    ($this->makeGame)();
    ($this->makeGame)(2026020002, ['game_date' => '2026-10-25']);
    ($this->makeGame)(2026020003, ['game_date' => '2026-10-28']);
    $form = ($this->readGame)()['home']['form'];
    expect($form['gp'])->toBe(1)->and($form['wins'])->toBe(1)->and($form['gf_gp'])->toEqual(4);
});

it('limits recent form to the previous ten completed games', function (): void {
    ($this->makeGame)();
    foreach (range(1, 12) as $day) {
        ($this->makeGame)(2026020001 + $day, ['game_date' => sprintf('2026-10-%02d', $day)]);
    }
    expect(($this->readGame)()['home']['form']['l10_gp'])->toBe(10)
        ->and(($this->readGame)()['home']['form']['gp'])->toBe(12);
});

it('lists the five most recent prior meetings without the selected result', function (): void {
    ($this->makeGame)();
    foreach (range(1, 6) as $day) {
        ($this->makeGame)(2026020001 + $day, ['game_date' => sprintf('2026-10-%02d', $day)]);
    }
    $meetings = ($this->readGame)()['head_to_head'];
    expect($meetings)->toHaveCount(5)->and($meetings[0]['date'])->toBe('2026-10-06')
        ->and(array_column($meetings, 'id'))->not->toContain(2026020001);
});

it('leaves missing imported statistics unknown', function (): void {
    ($this->makeGame)();
    $game = ($this->readGame)();
    expect($game['away']['sat'])->toBeNull()->and($game['away']['hits'])->toBeNull()->and($game['away']['boxscore'])->toBe([]);
});

it('preserves actual zero power play totals', function (): void {
    ($this->makeGame)(2026020001, ['away_pp_goals' => 0, 'away_pp_opportunities' => 3]);
    expect(($this->readGame)()['away']['pp_goals'])->toBe(0)->and(($this->readGame)()['away']['pp_opportunities'])->toBe(3);
});

it('reads imported scoring events in period order', function (): void {
    ($this->makeGame)();
    foreach ([3, 1] as $period) {
        DB::table('play_by_plays')->insert(['nhl_game_id' => 2026020001, 'type_desc_key' => 'goal', 'period' => $period,
            'scoring_player_id' => 100, 'event_owner_team_id' => 6, 'time_in_period' => '01:00']);
    }
    expect(array_column(($this->readGame)()['events'], 'period'))->toBe([1, 3]);
});

it('prepares a recap using the bounded refresh job', function (): void {
    ($this->makeGame)();
    (new RefreshNhlPublicGamePageJob(2026020001))->handle(app(NhlPublicGamePage::class));
    expect(($this->readGame)()['mode'])->toBe('recap');
    Http::assertNothingSent();
});

it('lets a refresh job for a deleted game exit harmlessly', function (): void {
    (new RefreshNhlPublicGamePageJob(9999999999))->handle(app(NhlPublicGamePage::class));
    expect(NhlGame::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('queues public page preparation after a canonical prediction', function (): void {
    ($this->makeGame)();
    ($this->savePrediction)();
    Bus::assertDispatched(RefreshNhlPublicGamePageJob::class, fn ($job): bool => $job->gameId === 2026020001 && $job->queue === 'default');
});

it('uses provider identities and boxscores for live games', function (): void {
    ($this->makeGame)(2026020001, ['game_date' => '2026-10-27', 'game_state' => 'LIVE']);
    Cache::put('nhl:live:snapshot:v1:2026020001', [
        'sequence' => 1, 'observed_at' => now()->toIso8601String(),
        'game' => ['nhl_game_id' => 2026020001, 'game_state' => 'LIVE', 'mode' => 'live',
            'away' => ['team_abbrev' => 'MTL', 'score' => 0, 'sog' => 12],
            'home' => ['team_abbrev' => 'NYR', 'score' => 1, 'sog' => 14]],
    ], 120);
    $this->get('/games/2026020001')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('game.mode', 'live')->where('game.away.team_abbrev', 'MTL')->where('game.away.score', 0));
});

it('does not expose stored scores when live provider evidence is unavailable', function (): void {
    ($this->makeGame)(2026020001, ['game_date' => '2026-10-27', 'game_state' => 'LIVE']);
    $this->live->shouldReceive('publicDetail')->once()->andReturnNull();
    $game = ($this->readGame)();
    expect($game['mode'])->toBe('live')->and($game['live_data_unavailable'])->toBeTrue()
        ->and($game['away'])->toBe([])->and($game['home'])->toBe([]);
});

it('invalidates a prepared snapshot explicitly', function (): void {
    ($this->makeGame)();
    ($this->readGame)();
    DB::table('nhl_games')->where('nhl_game_id', 2026020001)->update(['home_team_score' => 7]);
    app(NhlPublicGamePage::class)->forget(2026020001);
    expect(($this->readGame)()['home']['score'])->toBe(7);
});


it('keeps administrator controls outside the shared public payload cache', function (): void {
    ($this->makeGame)();
    $admin = User::factory()->create();
    $role = \App\Models\Role::query()->firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin', 'level' => 99]);
    $admin->roles()->attach($role->id, ['organization_id' => null]);
    $this->actingAs($admin)->get('/games/2026020001')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManageLineups', true));
    $this->actingAs(User::factory()->create())->get('/games/2026020001')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManageLineups', false));
});


it('renders the overview without calling the player projection builder on a cold cache', function (): void {
    ($this->makeGame)(2026020001, ['game_state' => 'FUT', 'game_date' => '2026-10-28']);
    $this->lineups->shouldNotReceive('gamePage');
    $this->get('/games/2026020001')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('game.away.team_abbrev', 'BOS')->where('game.home.team_abbrev', 'TOR')
        ->where('detailsUrl', route('games.details', 2026020001))
        ->missing('game.home.predictions')->missing('game.home.form')->missing('game.head_to_head'));
    Http::assertNothingSent();
});

it('defers recap boxscores and events while showing the final result immediately', function (): void {
    ($this->makeGame)();
    $this->get('/games/2026020001')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('game.home.score', 4)->missing('game.events')->missing('game.home.boxscore'));
});

it('queues cold lineup preparation instead of calculating during the section request', function (): void {
    ($this->makeGame)(2026020001, ['game_state' => 'FUT', 'game_date' => '2026-10-28']);
    $this->lineups->shouldNotReceive('gamePage');
    $this->getJson('/games/2026020001/details?section=lineups')->assertStatus(202)->assertJsonPath('status', 'preparing');
    Bus::assertDispatched(RefreshNhlPublicGamePageJob::class);
});

it('returns prepared lineup data without leaking overview fields into the deferred response', function (): void {
    ($this->makeGame)(2026020001, ['game_state' => 'FUT', 'game_date' => '2026-10-28']);
    app(NhlPublicGamePage::class)->prepare(2026020001);
    $this->getJson('/games/2026020001/details?section=lineups')->assertOk()
        ->assertJsonPath('section', 'lineups')->assertJsonMissingPath('data.home.score')
        ->assertJsonMissingPath('data.game_state')->assertJsonMissingPath('data.prediction');
});

it('allows guests to read matchup form without preparing player projections', function (): void {
    ($this->makeGame)(2026020001, ['game_state' => 'FUT', 'game_date' => '2026-10-28']);
    $this->lineups->shouldNotReceive('gamePage');
    $this->getJson('/games/2026020001/details?section=matchup')->assertOk()->assertJsonPath('data.home.form.gp', 0);
});

it('allows ordinary authenticated viewers to read the same deferred sections', function (): void {
    ($this->makeGame)();
    $this->actingAs(User::factory()->create())->getJson('/games/2026020001/details?section=meetings')->assertOk();
});

it('reads previous meetings independently of lineup preparation', function (): void {
    ($this->makeGame)(2026020001, ['game_state' => 'FUT', 'game_date' => '2026-10-28']);
    ($this->makeGame)(2026020002, ['game_date' => '2026-10-25']);
    $this->lineups->shouldNotReceive('gamePage');
    $this->getJson('/games/2026020001/details?section=meetings')->assertOk()->assertJsonPath('data.head_to_head.0.id', 2026020002);
});

it('rejects missing or invalid deferred section names', function (string $suffix): void {
    ($this->makeGame)();
    $this->getJson('/games/2026020001/details'.$suffix)->assertUnprocessable()->assertJsonValidationErrors('section');
})->with(['', '?section=private']);

it('returns not found for deferred sections of an unknown game', function (): void {
    $this->getJson('/games/9999999999/details?section=lineups')->assertNotFound();
});

it('does not run concurrent detail preparation while another builder holds the game lease', function (): void {
    ($this->makeGame)();
    $lock = Cache::lock('nhl:public-game:prepare:2026020001', 65);
    $lock->get();
    try {
        expect(app(NhlPublicGamePage::class)->prepare(2026020001))->toBeNull();
    } finally {
        $lock->release();
    }
});

it('exposes imported recap sections after the preparation job without changing the overview', function (): void {
    ($this->makeGame)();
    DB::table('nhl_boxscores')->insert(['nhl_game_id' => 2026020001, 'nhl_player_id' => 100,
        'nhl_team_id' => 6, 'position' => 'C', 'goals' => 2]);
    (new RefreshNhlPublicGamePageJob(2026020001))->handle(app(NhlPublicGamePage::class));
    $this->getJson('/games/2026020001/details?section=boxscore')->assertOk()->assertJsonPath('data.away.boxscore.0.goals', 2);
    $this->get('/games/2026020001')->assertOk()->assertInertia(fn (Assert $page) => $page->where('game.home.score', 4));
});


it('shows the saved normalized goal total on the lightweight public preview', function (): void {
    ($this->makeGame)(2026020001, ['game_state' => 'FUT', 'game_date' => '2026-10-28']);
    ($this->savePrediction)([], ['prediction' => ['predicted_score' => ['away' => 2.4, 'home' => 3.1],
        'predicted_total_goals' => 5.5, 'selection_score' => ['away' => 4.8, 'home' => 6.2]]]);
    $this->assertDatabaseHas('nhl_game_prediction_revisions', ['nhl_game_id' => 2026020001, 'is_canonical' => true]);
    $this->get('/games/2026020001')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('game.prediction.total_goals', 5.5)->where('game.prediction.score.home', 3.1)
        ->missing('game.prediction.selection_score'));
    Http::assertNothingSent();
});


it('projects shared live plays shot locations and names without exposing raw provider details', function (): void {
    $result = app(NhlPublicGamePage::class)->livePayload(2026020001, ($this->timelineProvider)())['game'];
    expect($result['plays'])->toHaveCount(2)
        ->and($result['plays'][0]['player'])->toBe('First Scorer')
        ->and($result['plays'][1]['player'])->toBe('Player #200')
        ->and($result['plays'][1])->not->toHaveKey('details')
        ->and($result['shots'][0]['x'])->toBe(0.0)
        ->and($result['shots'][0]['y'])->toBe(0.0)
        ->and($result['shots'][0]['side'])->toBe('away');
    Http::assertNothingSent();
});

it('omits invalid or unknown-team rink coordinates', function (mixed $x, mixed $y, ?int $team): void {
    $provider = ($this->timelineProvider)();
    $provider['plays'][1]['details'] = ['xCoord' => $x, 'yCoord' => $y, 'eventOwnerTeamId' => $team];
    $result = app(NhlPublicGamePage::class)->livePayload(2026020001, $provider)['game'];
    expect($result['plays'])->toHaveCount(2)->and($result['shots'])->toHaveCount(1);
})->with([[null, 10, 10], [101, 10, 10], [20, 43, 10], [20, -43, 10], [20, 10, null], [20, 10, 999]]);

it('keeps unstarted periods unknown while showing verified current-period goals', function (): void {
    $result = app(NhlPublicGamePage::class)->livePayload(2026020001, ($this->timelineProvider)())['game'];
    expect($result['period_scores'][0])->toBe(['period' => 1, 'label' => '1st', 'away' => 1, 'home' => 0])
        ->and($result['period_scores'][1]['away'])->toBeNull()
        ->and($result['period_scores'][2]['home'])->toBeNull();
});

it('does not publish period zeros when the score and play source are inconsistent', function (): void {
    $provider = ($this->timelineProvider)();
    $provider['game']['away']['score'] = 2;
    $result = app(NhlPublicGamePage::class)->livePayload(2026020001, $provider)['game'];
    expect($result['period_scores'][0]['away'])->toBeNull()->and($result['away']['score'])->toBe(2);
});

it('keeps missing play-by-play explicitly unavailable', function (): void {
    $provider = ($this->timelineProvider)();
    $provider['events_available'] = false;
    $provider['plays'] = [];
    $result = app(NhlPublicGamePage::class)->livePayload(2026020001, $provider)['game'];
    expect($result['plays_available'])->toBeFalse()->and($result['plays'])->toBeEmpty()
        ->and($result['period_scores'][0]['home'])->toBeNull();
});

it('excludes shootout attempts from normal shot and scoring rows while showing the deciding goal separately', function (): void {
    $provider = ($this->timelineProvider)();
    $provider['game']['away']['score'] = 2;
    $provider['boxscore']['periodDescriptor'] = ['number' => 5, 'periodType' => 'SO'];
    $provider['game']['home']['score'] = 1;
    $homeGoal = $provider['plays'][0];
    $homeGoal['eventId'] = 3;
    $homeGoal['periodDescriptor'] = ['number' => 3, 'periodType' => 'REG'];
    $homeGoal['details']['eventOwnerTeamId'] = 10;
    $provider['plays'][] = $homeGoal;
    $shootout = $provider['plays'][0];
    $shootout['eventId'] = 4;
    $shootout['periodDescriptor'] = ['number' => 5, 'periodType' => 'SO'];
    $provider['plays'][] = $shootout;
    $result = app(NhlPublicGamePage::class)->livePayload(2026020001, $provider)['game'];
    expect($result['events'])->toHaveCount(2)->and($result['shots'])->toHaveCount(3)
        ->and($result['period_scores'][4])->toBe(['period' => 5, 'label' => 'SO', 'away' => 1, 'home' => 0]);
});

it('reads prepared imported play-by-play publicly with batched participant names and an allowlisted payload', function (bool $authenticated): void {
    if ($authenticated) {
        $this->actingAs(User::factory()->create());
    }
    ($this->makeGame)(2026020001, ['away_team_score' => 2, 'home_team_score' => 0]);
    foreach ([100 => 'First Scorer', 101 => 'Second Scorer'] as $id => $name) {
        DB::table('players')->insert(['nhl_id' => $id, 'full_name' => $name, 'first_name' => 'Scorer', 'last_name' => (string) $id]);
        DB::table('play_by_plays')->insert(['nhl_game_id' => 2026020001, 'nhl_event_id' => (string) $id,
            'type_desc_key' => 'goal', 'period' => 1, 'period_type' => 'REG', 'scoring_player_id' => $id,
            'event_owner_team_id' => 6, 'time_in_period' => '01:00', 'x_coord' => 70, 'y_coord' => 10]);
    }
    $this->assertDatabaseCount('play_by_plays', 2);
    (new RefreshNhlPublicGamePageJob(2026020001))->handle(app(NhlPublicGamePage::class));
    $this->getJson('/games/2026020001/details?section=play_by_play')->assertOk()
        ->assertJsonPath('data.plays_available', true)->assertJsonCount(2, 'data.shots')
        ->assertJsonPath('data.plays.0.player', 'First Scorer')->assertJsonPath('data.plays.1.player', 'Second Scorer')
        ->assertJsonMissingPath('data.away')->assertJsonMissingPath('data.evidence');
    $this->get('/games/2026020001')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->missing('game.plays')->missing('game.shots')->missing('game.period_scores'));
    Http::assertNothingSent();
})->with(['guest' => [false], 'ordinary user' => [true]]);


it('counts live attempts independently of rink coordinates and excludes shootouts and unknown teams', function (): void {
    $provider = ($this->timelineProvider)();
    foreach (['missed-shot', 'blocked-shot'] as $index => $type) {
        $provider['plays'][] = ['eventId' => 3 + $index, 'typeDescKey' => $type,
            'periodDescriptor' => ['number' => 1, 'periodType' => 'REG'],
            'details' => ['eventOwnerTeamId' => 6]];
    }
    $provider['plays'][] = ['eventId' => 5, 'typeDescKey' => 'goal',
        'periodDescriptor' => ['number' => 5, 'periodType' => 'SO'], 'details' => ['eventOwnerTeamId' => 6]];
    $provider['plays'][] = ['eventId' => 6, 'typeDescKey' => 'shot-on-goal',
        'periodDescriptor' => ['number' => 1, 'periodType' => 'REG'], 'details' => ['eventOwnerTeamId' => 999]];
    $game = app(NhlPublicGamePage::class)->livePayload(2026020001, $provider)['game'];
    expect($game['away']['sat'])->toBe(3)->and($game['home']['sat'])->toBe(1)
        ->and($game['shots'])->toHaveCount(2)
        ->and(collect($game['comparison'])->firstWhere('label', 'Shot attempts'))
        ->toBe(['label' => 'Shot attempts', 'away' => 3, 'home' => 1]);
    Http::assertNothingSent();
});

it('distinguishes unknown live attempts from a genuine recorded zero', function (): void {
    $provider = ($this->timelineProvider)();
    $provider['plays'] = [];
    $game = app(NhlPublicGamePage::class)->livePayload(2026020001, $provider)['game'];
    expect($game['away']['sat'])->toBe(0);
    $provider['events_available'] = false;
    $game = app(NhlPublicGamePage::class)->livePayload(2026020001, $provider)['game'];
    expect($game['away']['sat'])->toBeNull()->and($game['home']['sat'])->toBeNull();
});

it('derives goalie saves only from known provider shot and goal counts', function (): void {
    $provider = ($this->timelineProvider)();
    $provider['boxscore']['playerByGameStats']['awayTeam']['goalies'] = [
        ['playerId' => 301, 'shotsAgainst' => 20, 'goalsAgainst' => 1],
        ['playerId' => 302, 'shotsAgainst' => 10],
        ['playerId' => 303, 'shotsAgainst' => 0, 'goalsAgainst' => 0],
    ];
    $rows = app(NhlPublicGamePage::class)->livePayload(2026020001, $provider)['game']['away']['boxscore'];
    expect($rows[0]['saves'])->toBe(19)->and($rows[0]['shots_against'])->toBe(20)
        ->and($rows[1]['saves'])->toBeNull()->and($rows[2]['saves'])->toBe(0);
});

it('serves newly calculated live SAT and goalie facts through public deferred reads', function (bool $authenticated): void {
    if ($authenticated) {
        $this->actingAs(User::factory()->create());
    }
    ($this->makeGame)(2026020001, ['game_date' => '2026-10-27', 'game_state' => 'LIVE']);
    $provider = ($this->timelineProvider)();
    $provider['boxscore']['playerByGameStats']['awayTeam']['goalies'] = [
        ['playerId' => 301, 'shotsAgainst' => 20, 'goalsAgainst' => 1, 'toi' => '20:00'],
    ];
    $game = app(NhlPublicGamePage::class)->livePayload(2026020001, $provider)['game'];
    Cache::put('nhl:live:snapshot:v1:2026020001', [
        'sequence' => 1, 'observed_at' => now()->toIso8601String(), 'game' => $game,
    ], 120);
    $this->getJson('/games/2026020001/details?section=summary')->assertOk()
        ->assertJsonPath('data.comparison.1.label', 'Shot attempts')
        ->assertJsonPath('data.comparison.1.away', 1)->assertJsonPath('data.comparison.1.home', 1);
    $this->getJson('/games/2026020001/details?section=boxscore')->assertOk()
        ->assertJsonPath('data.away.boxscore.0.saves', 19)->assertJsonPath('data.away.boxscore.0.shots_against', 20);
    Http::assertNothingSent();
})->with(['guest' => [false], 'ordinary user' => [true]]);
