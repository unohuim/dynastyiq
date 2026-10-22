<?php

declare(strict_types=1);

use App\Models\NhlGame;
use App\Models\User;
use App\Services\NhlGameLiveContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-22 15:00:00 UTC'));
    Http::preventStrayRequests();
    config(['cache.default' => 'array']);
    Cache::flush();
    $this->viewer = User::factory()->create();
    $this->range = ['start' => '2025-10-01', 'end' => '2025-10-15'];
    $this->game = function (int $id = 2025020001, string $date = '2025-10-10', array $extra = []): NhlGame {
        return NhlGame::create([
            'nhl_game_id' => $id, 'season_id' => '20252026', 'game_type' => 2,
            'game_date' => $date, 'game_dow' => 'Friday', 'game_month' => 'October',
            'start_time_utc' => $date.' 23:00:00', 'game_state' => 'OFF',
            'away_team_score' => 2, 'home_team_score' => 4,
            'away_team_id' => 6, 'away_team_abbrev' => 'BOS', 'away_team_logo' => '/bos.svg',
            'home_team_id' => 10, 'home_team_abbrev' => 'TOR', 'home_team_logo' => '/tor.svg', ...$extra,
        ]);
    };
    $this->boxscore = function (NhlGame $game, bool $bothTeams = true): void {
        DB::table('nhl_import_progress')->insert([
            'season_id' => $game->season_id, 'game_date' => $game->game_date->toDateString(),
            'game_id' => (string) $game->nhl_game_id, 'game_type' => 2, 'import_type' => 'boxscore',
            'status' => 'completed', 'items_count' => $bothTeams ? 2 : 1, 'discovered_at' => now(),
        ]);
        DB::table('nhl_boxscores')->insert(['nhl_game_id' => $game->nhl_game_id, 'nhl_player_id' => 101, 'nhl_team_id' => 6]);
        if ($bothTeams) {
            DB::table('nhl_boxscores')->insert(['nhl_game_id' => $game->nhl_game_id, 'nhl_player_id' => 102, 'nhl_team_id' => 10]);
        }
    };
});

afterEach(function (): void { $this->travelBack(); });

it('allows guests to read public scoreboard facts', function (): void {
    ($this->game)();
    $this->getJson(route('games.scoreboard', $this->range))->assertOk()->assertJsonCount(1, 'games');
});

it('allows ordinary authenticated users without admin permissions', function (): void {
    $this->actingAs($this->viewer)->getJson(route('games.scoreboard', $this->range))->assertOk();
});

it('requires the start date', function (): void {
    $this->getJson(route('games.scoreboard', ['end' => '2025-10-15']))->assertUnprocessable()->assertJsonValidationErrors('start');
});

it('requires the end date', function (): void {
    $this->getJson(route('games.scoreboard', ['start' => '2025-10-01']))->assertUnprocessable()->assertJsonValidationErrors('end');
});

it('rejects malformed start dates', function (): void {
    $this->getJson(route('games.scoreboard', ['start' => 'October 1', 'end' => '2025-10-15']))->assertUnprocessable();
});

it('rejects malformed end dates', function (): void {
    $this->getJson(route('games.scoreboard', ['start' => '2025-10-01', 'end' => 'tomorrow']))->assertUnprocessable();
});

it('rejects reversed date ranges', function (): void {
    $this->getJson(route('games.scoreboard', ['start' => '2025-10-15', 'end' => '2025-10-01']))
        ->assertUnprocessable()->assertJsonValidationErrors('end');
});

it('rejects ranges longer than thirty one calendar days', function (): void {
    $this->getJson(route('games.scoreboard', ['start' => '2025-10-01', 'end' => '2025-11-01']))
        ->assertUnprocessable()->assertJsonValidationErrors('end');
});

it('accepts thirty one inclusive calendar days', function (): void {
    $this->getJson(route('games.scoreboard', ['start' => '2025-10-01', 'end' => '2025-10-31']))->assertOk();
});

it('includes games on both requested boundaries', function (): void {
    ($this->game)(2025020001, '2025-10-01');
    ($this->game)(2025020002, '2025-10-15');
    $this->getJson(route('games.scoreboard', $this->range))->assertOk()->assertJsonCount(2, 'games');
});

it('excludes outside games but reports stored schedule bounds for infinite browsing', function (): void {
    ($this->game)(2025020001, '2025-09-30');
    ($this->game)(2025020002, '2025-10-16');
    $this->getJson(route('games.scoreboard', $this->range))->assertOk()->assertJsonCount(0, 'games')
        ->assertJsonPath('meta.min_date', '2025-09-30')->assertJsonPath('meta.max_date', '2025-10-16');
});

it('orders the strip chronologically instead of grouping final and live states', function (): void {
    ($this->game)(2025020002, '2025-10-11', ['game_state' => 'FUT']);
    ($this->game)(2025020001, '2025-10-10');
    $this->getJson(route('games.scoreboard', $this->range))->assertOk()
        ->assertJsonPath('games.0.nhl_game_id', 2025020001)->assertJsonPath('games.1.nhl_game_id', 2025020002);
});

it('uses game identity as the stable tie break for simultaneous starts', function (): void {
    ($this->game)(2025020002);
    ($this->game)(2025020001);
    $this->getJson(route('games.scoreboard', $this->range))->assertOk()->assertJsonPath('games.0.nhl_game_id', 2025020001);
});

it('returns existing game URLs and team identity for compact tiles', function (): void {
    ($this->game)();
    $this->getJson(route('games.scoreboard', $this->range))->assertOk()
        ->assertJsonPath('games.0.href', route('games.show', 2025020001))
        ->assertJsonPath('games.0.away.team_abbrev', 'BOS')->assertJsonPath('games.0.home.team_logo', '/tor.svg');
});

it('omits lineups goalie projections and prediction inputs from compact responses', function (): void {
    ($this->game)();
    $response = $this->getJson(route('games.scoreboard', $this->range))->assertOk();
    expect(array_keys($response->json('games.0.away')))->toBe(['team_abbrev', 'team_logo', 'score', 'win_probability']);
    Http::assertNothingSent();
});

it('does not display stored zero scores before puck drop', function (): void {
    ($this->game)(2025020001, '2025-10-10', ['game_state' => 'FUT', 'away_team_score' => 0, 'home_team_score' => 0]);
    $this->getJson(route('games.scoreboard', $this->range))->assertOk()
        ->assertJsonPath('games.0.away.score', null)->assertJsonPath('games.0.home.score', null);
});

it('shows stored final scores without a completed boxscore import or provider request', function (): void {
    ($this->game)(2025020001, '2025-10-10', ['away_team_score' => 2, 'home_team_score' => 4]);
    $this->getJson(route('games.scoreboard', $this->range))->assertOk()
        ->assertJsonPath('games.0.away.score', 2)->assertJsonPath('games.0.home.score', 4);
    Http::assertNothingSent();
});

it('shows stored final scores even when only one teams boxscores are imported', function (): void {
    $game = ($this->game)(2025020001, '2025-10-10', ['away_team_score' => 2, 'home_team_score' => 4]);
    ($this->boxscore)($game, false);
    $this->getJson(route('games.scoreboard', $this->range))->assertOk()->assertJsonPath('games.0.home.score', 4);
    Http::assertNothingSent();
});

it('preserves shutout zeroes once both teams have processed boxscores', function (): void {
    $game = ($this->game)(2025020001, '2025-10-10', ['away_team_score' => 0, 'home_team_score' => 4]);
    ($this->boxscore)($game);
    $this->getJson(route('games.scoreboard', $this->range))->assertOk()
        ->assertJsonPath('games.0.away.score', 0)->assertJsonPath('games.0.home.score', 4);
});

it('repairs missing historical scores and reads the persisted result without fetching again', function (): void {
    ($this->game)(2025020001, '2025-10-10', ['away_team_score' => null, 'home_team_score' => null]);
    Http::fake(['*' => Http::response([
        'id' => 2025020001, 'gameState' => 'OFF',
        'awayTeam' => ['score' => 0], 'homeTeam' => ['score' => 3],
    ])]);
    $response = $this->getJson(route('games.scoreboard', $this->range))->assertOk();
    $response->assertJsonPath('games.0.away.score', 0)->assertJsonPath('games.0.home.score', 3);
    $this->assertDatabaseHas('nhl_games', [
        'nhl_game_id' => 2025020001, 'away_team_score' => 0, 'home_team_score' => 3, 'game_state' => 'OFF',
    ]);
    $this->getJson(route('games.scoreboard', $this->range))->assertOk()->assertJsonPath('games.0.home.score', 3);
    Http::assertSentCount(1);
});

it('repairs stale historical schedule state from final provider evidence', function (): void {
    ($this->game)(2025020001, '2025-10-10', ['game_state' => 'FUT', 'away_team_score' => 0, 'home_team_score' => 0]);
    Http::fake(['*' => Http::response([
        'id' => 2025020001, 'gameState' => 'FINAL',
        'awayTeam' => ['score' => 2], 'homeTeam' => ['score' => 5],
    ])]);
    $this->getJson(route('games.scoreboard', $this->range))->assertOk()
        ->assertJsonPath('games.0.game_state', 'FINAL')->assertJsonPath('games.0.home.score', 5);
});

it('caches failed historical lookups and leaves missing scores unknown', function (): void {
    ($this->game)(2025020001, '2025-10-10', ['away_team_score' => null, 'home_team_score' => null]);
    Http::fake(['*' => Http::response([], 503)]);
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $this->getJson(route('games.scoreboard', $this->range))->assertOk()
            ->assertJsonPath('games.0.away.score', null)->assertJsonPath('games.0.home.score', null);
    }
    Http::assertSentCount(1);
});

it('refuses incomplete nonfinal or mismatched historical provider scores', function (array $provider): void {
    ($this->game)(2025020001, '2025-10-10', ['away_team_score' => null, 'home_team_score' => null]);
    Http::fake(['*' => Http::response($provider)]);
    $this->getJson(route('games.scoreboard', $this->range))->assertOk()
        ->assertJsonPath('games.0.away.score', null)->assertJsonPath('games.0.home.score', null);
    $this->assertDatabaseHas('nhl_games', ['nhl_game_id' => 2025020001, 'home_team_score' => null]);
})->with([
    'missing score' => [['id' => 2025020001, 'gameState' => 'FINAL', 'awayTeam' => ['score' => 2]]],
    'nonfinal' => [['id' => 2025020001, 'gameState' => 'LIVE', 'awayTeam' => ['score' => 2], 'homeTeam' => ['score' => 3]]],
    'wrong game' => [['id' => 2025020002, 'gameState' => 'OFF', 'awayTeam' => ['score' => 2], 'homeTeam' => ['score' => 3]]],
]);

it('uses provider identity and scores for currently live games', function (): void {
    ($this->game)(2026020001, '2026-10-22', ['game_state' => 'LIVE', 'away_team_score' => 99, 'home_team_score' => 99]);
    $live = Mockery::mock(NhlGameLiveContext::class);
    $live->shouldReceive('forGame')->once()->andReturn(['state' => 'LIVE', 'show_score' => true, 'live_game' => [
        'game_state' => 'LIVE', 'start_time_utc' => '2026-10-22T23:00:00Z',
        'away' => ['team_abbrev' => 'MTL', 'team_logo' => '/live-mtl.svg', 'score' => 0],
        'home' => ['team_abbrev' => 'NYR', 'team_logo' => '/live-nyr.svg', 'score' => 2],
    ]]);
    $this->app->instance(NhlGameLiveContext::class, $live);
    $this->getJson(route('games.scoreboard', ['start' => '2026-10-22', 'end' => '2026-10-22']))->assertOk()
        ->assertJsonPath('games.0.away.team_abbrev', 'MTL')->assertJsonPath('games.0.away.score', 0)
        ->assertJsonPath('games.0.home.score', 2);
});

it('keeps unavailable live facts unavailable instead of falling back to database scores', function (): void {
    ($this->game)(2026020001, '2026-10-22', ['game_state' => 'LIVE', 'away_team_score' => 99, 'home_team_score' => 99]);
    $live = Mockery::mock(NhlGameLiveContext::class);
    $live->shouldReceive('forGame')->once()->andReturnNull();
    $this->app->instance(NhlGameLiveContext::class, $live);
    $this->getJson(route('games.scoreboard', ['start' => '2026-10-22', 'end' => '2026-10-22']))->assertOk()
        ->assertJsonPath('games.0.live_data_unavailable', true)->assertJsonPath('games.0.away.score', null)
        ->assertJsonPath('games.0.away.team_abbrev', null);
});

it('returns null bounds for an empty stored schedule', function (): void {
    $this->getJson(route('games.scoreboard', $this->range))->assertOk()->assertJsonCount(0, 'games')
        ->assertJsonPath('meta.min_date', null)->assertJsonPath('meta.max_date', null);
});

it('declares Eastern time for client schedule presentation', function (): void {
    $this->getJson(route('games.scoreboard', $this->range))->assertOk()->assertJsonPath('meta.timezone', 'America/Toronto');
});

it('prevents browser caching from freezing scores and range boundaries', function (): void {
    $response = $this->getJson(route('games.scoreboard', $this->range))->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('exposes only a read route for the public scoreboard', function (): void {
    $this->postJson(route('games.scoreboard'), $this->range)->assertMethodNotAllowed();
});

it('reads compact live facts without selecting projected goalies or assembling rosters', function (): void {
    config(['cache.default' => 'array']);
    $game = ($this->game)(2026020001, '2026-10-22', ['game_state' => 'LIVE']);
    Http::fake(['*' => Http::response([
        'id' => 2026020001, 'gameState' => 'LIVE', 'startTimeUTC' => '2026-10-22T23:00:00Z',
        'awayTeam' => ['abbrev' => 'BOS', 'logo' => '/bos.svg', 'score' => 0],
        'homeTeam' => ['abbrev' => 'TOR', 'logo' => '/tor.svg', 'score' => 2],
    ])]);
    $goalies = Mockery::mock(\App\Services\NhlStartingGoalieSelector::class);
    $goalies->shouldReceive('lockBoxscoreStarters')->once();
    $goalies->shouldNotReceive('select');
    $payload = (new NhlGameLiveContext($goalies))->forGame($game, true);
    expect($payload['live_game']['away']['score'])->toBe(0)
        ->and(array_keys($payload['live_game']['home']))->toBe(['team_abbrev', 'team_logo', 'score']);
});


it('reads both upcoming moneyline probabilities from the newest successful canonical revision', function (): void {
    $game = ($this->game)(2026020001, '2026-10-23', ['game_state' => 'FUT']);
    $history = app(\App\Services\NhlGamePredictionHistory::class);
    $payload = fn (float $away, float $home): array => ['prediction_available' => true, 'pick_qualified' => false,
        'market_probabilities' => [
            ['market_key' => 'moneyline', 'period_key' => 'full_game', 'selection_key' => 'away', 'team_abbrev' => 'BOS', 'probability' => $away],
            ['market_key' => 'moneyline', 'period_key' => 'full_game', 'selection_key' => 'home', 'team_abbrev' => 'TOR', 'probability' => $home],
        ]];
    $history->record($game->nhl_game_id, ['nhl_game_id' => $game->nhl_game_id], str_repeat('a', 64), ['private_input' => 'secret'], $payload(.4, .6));
    $history->record($game->nhl_game_id, ['nhl_game_id' => $game->nhl_game_id], str_repeat('b', 64), [], $payload(.454, .546));
    // A newer overridden or unavailable calculation must not replace the canonical saved result.
    $history->record($game->nhl_game_id, ['nhl_game_id' => $game->nhl_game_id, 'home_goalie_id' => 999], str_repeat('c', 64), [], $payload(.2, .8));
    $history->record($game->nhl_game_id, ['nhl_game_id' => $game->nhl_game_id], str_repeat('d', 64), [], [...$payload(.1, .9), 'prediction_available' => false]);
    $builder = Mockery::mock(\App\Services\NhlGamePredictionPayload::class);
    $builder->shouldNotReceive('build');
    $this->app->instance(\App\Services\NhlGamePredictionPayload::class, $builder);
    $response = $this->getJson(route('games.scoreboard', ['start' => '2026-10-23', 'end' => '2026-10-23']))->assertOk()
        ->assertJsonPath('games.0.away.win_probability', .454)->assertJsonPath('games.0.home.win_probability', .546)
        ->assertJsonPath('games.0.away.score', null)->assertJsonMissingPath('games.0.evidence');
    expect($response->getContent())->not->toContain('private_input');
    Http::assertNothingSent();
});

it('keeps probability positions empty without a saved prediction', function (): void {
    ($this->game)(2026020001, '2026-10-23', ['game_state' => 'FUT']);
    $this->getJson(route('games.scoreboard', ['start' => '2026-10-23', 'end' => '2026-10-23']))->assertOk()
        ->assertJsonPath('games.0.away.win_probability', null)->assertJsonPath('games.0.home.win_probability', null);
});

it('never uses invalid different-team or non-moneyline values as win probabilities', function (): void {
    $game = ($this->game)(2026020001, '2026-10-23', ['game_state' => 'PRE']);
    app(\App\Services\NhlGamePredictionHistory::class)->record($game->nhl_game_id, [], str_repeat('a', 64), [], [
        'prediction_available' => true, 'market_probabilities' => [
            ['market_key' => 'moneyline', 'period_key' => 'full_game', 'selection_key' => 'away', 'team_abbrev' => 'BOS', 'probability' => 1.5],
            ['market_key' => 'moneyline', 'period_key' => 'full_game', 'selection_key' => 'home', 'team_abbrev' => 'MTL', 'probability' => .6],
            ['market_key' => 'puckline', 'period_key' => 'full_game', 'selection_key' => 'home', 'team_abbrev' => 'TOR', 'probability' => .7],
        ],
    ]);
    $this->getJson(route('games.scoreboard', ['start' => '2026-10-23', 'end' => '2026-10-23']))->assertOk()
        ->assertJsonPath('games.0.away.win_probability', null)->assertJsonPath('games.0.home.win_probability', null);
});

it('preserves live and final scores instead of displaying saved probabilities', function (string $state): void {
    $date = $state === 'LIVE' ? '2026-10-22' : '2026-10-21';
    $game = ($this->game)(2026020001, $date, ['game_state' => $state, 'away_team_score' => 0, 'home_team_score' => 3]);
    app(\App\Services\NhlGamePredictionHistory::class)->record($game->nhl_game_id, [], str_repeat('a', 64), [], [
        'prediction_available' => true, 'market_probabilities' => [
            ['market_key' => 'moneyline', 'period_key' => 'full_game', 'selection_key' => 'away', 'team_abbrev' => 'BOS', 'probability' => .454],
            ['market_key' => 'moneyline', 'period_key' => 'full_game', 'selection_key' => 'home', 'team_abbrev' => 'TOR', 'probability' => .546],
        ],
    ]);
    if ($state === 'LIVE') {
        Http::fake(['*' => Http::response(['id' => 2026020001, 'gameState' => 'LIVE', 'startTimeUTC' => '2026-10-22T23:00:00Z',
            'awayTeam' => ['abbrev' => 'BOS', 'score' => 0], 'homeTeam' => ['abbrev' => 'TOR', 'score' => 3]])]);
    }
    $this->getJson(route('games.scoreboard', ['start' => $date, 'end' => $date]))->assertOk()
        ->assertJsonPath('games.0.away.score', 0)->assertJsonPath('games.0.home.score', 3)
        ->assertJsonPath('games.0.away.win_probability', null)->assertJsonPath('games.0.home.win_probability', null);
})->with(['LIVE', 'FINAL', 'OFF']);
