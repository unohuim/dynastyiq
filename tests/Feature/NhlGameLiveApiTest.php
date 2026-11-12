<?php

declare(strict_types=1);

use App\Events\NhlLiveGameUpdated;
use App\Models\ApiClient;
use App\Models\NhlGame;
use App\Services\NhlLiveGameFeed;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-27 23:00:00 UTC'));
    config(['cache.default' => 'array']);
    Cache::flush();
    Bus::fake();
    Event::fake([NhlLiveGameUpdated::class]);
    Http::preventStrayRequests();
    $this->token = 'live-api-fixture';
    $this->client = ApiClient::create(['name' => 'Live reader', 'slug' => 'live-reader',
        'token_hash' => ApiClient::hashToken($this->token), 'scopes' => ['nhl-stats:read']]);
    $this->game = NhlGame::create(['nhl_game_id' => 2026020001, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => '2026-10-27', 'game_dow' => 'Tue', 'game_month' => 'Oct',
        'start_time_utc' => now(), 'game_state' => 'PRE', 'away_team_id' => 6, 'home_team_id' => 10,
        'away_team_abbrev' => 'BOS', 'home_team_abbrev' => 'TOR']);
    $this->snapshot = ['sequence' => 100, 'observed_at' => now()->toIso8601String(), 'game' => [
        'nhl_game_id' => 2026020001, 'game_date' => '2026-10-27', 'game_state' => 'LIVE', 'mode' => 'live', 'status' => 'Live',
        'period_number' => 2, 'period_type' => 'REG', 'time_remaining' => '12:34', 'in_intermission' => false,
        'away' => ['team_id' => 6, 'team_abbrev' => 'BOS', 'score' => 2, 'sog' => 25, 'sat' => 44,
            'hits' => 15, 'blocks' => 8, 'pim' => 4, 'boxscore' => [
                ['id' => 100, 'name' => 'Away goalie', 'position' => 'G', 'saves' => 19, 'shots_against' => 20, 'toi' => '27:26'],
                ['id' => 101, 'name' => 'Bench goalie', 'position' => 'G', 'saves' => 0, 'shots_against' => 0, 'toi' => '00:00'],
                ['id' => 102, 'name' => 'Skater', 'position' => 'C', 'saves' => null, 'toi' => '10:00']]],
        'home' => ['team_id' => 10, 'team_abbrev' => 'TOR', 'score' => 1, 'sog' => 20, 'sat' => 39,
            'hits' => 11, 'blocks' => 10, 'pim' => 6, 'boxscore' => [
                ['id' => 200, 'name' => 'Home goalie', 'position' => 'G', 'saves' => 23, 'shots_against' => 25, 'toi' => '27:26']]],
    ]];
    $this->publish = function (): void {
        Cache::put('nhl:live:snapshot:v1:2026020001', $this->snapshot, 172800);
    };
    $this->requestLive = fn (array $query = []) => $this->withToken($this->token)->getJson(
        '/api/nhl-game-live?'.http_build_query(['nhl_game_id' => 2026020001, ...$query]));
    $this->advanced = function (?array $metrics = null, int $pending = 7, int $processed = 7): void {
        $metrics ??= ['available' => true, 'model_ids' => ['goal' => 11, 'shot_on_goal' => 12],
            'teams' => [6 => ['xgf' => 2.75], 10 => ['xgf' => 1.25]],
            'goalies' => [100 => ['gsax' => 0.25], 200 => ['gsax' => 0.75]]];
        DB::table('nhl_pbp_processing_states')->updateOrInsert(['nhl_game_id' => 2026020001], [
            'pending_version' => $pending, 'processed_version' => $processed,
            'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR), 'processed_at' => '2026-10-27 22:59:58',
        ]);
    };
});

afterEach(function (): void {
    $this->travelBack();
});

it('requires a partner token for live game statistics', function (): void {
    $this->getJson('/api/nhl-game-live?nhl_game_id=2026020001')->assertUnauthorized();
});

it('requires the NHL statistics read scope', function (): void {
    $this->client->update(['scopes' => ['nhl-reference:read']]);
    ($this->requestLive)()->assertForbidden();
});

it('rejects revoked partner tokens', function (): void {
    $this->client->update(['revoked_at' => now()]);
    ($this->requestLive)()->assertForbidden();
});

it('requires a live game identifier', function (): void {
    $this->withToken($this->token)->getJson('/api/nhl-game-live')->assertUnprocessable();
});

it('rejects invalid game identifiers', function (mixed $id): void {
    ($this->requestLive)(['nhl_game_id' => $id])->assertUnprocessable();
})->with(['text' => 'bad', 'zero' => 0, 'negative' => -1, 'decimal' => 1.5]);

it('returns not found for an unknown game', function (): void {
    ($this->requestLive)(['nhl_game_id' => 9999999999])->assertNotFound();
});

it('returns an unavailable envelope without preparing missing live evidence', function (): void {
    ($this->requestLive)()->assertOk()->assertJsonPath('available', false)->assertJsonPath('teams', null)
        ->assertJsonPath('game', null)->assertJsonPath('goalies', [])->assertJsonPath('meta.observed_at', null);
    Http::assertNothingSent();
    Bus::assertNothingDispatched();
});

it('returns both teams requested statistics from the shared snapshot', function (): void {
    ($this->publish)();
    ($this->advanced)();
    ($this->requestLive)()->assertOk()->assertJsonPath('available', true)
        ->assertJsonPath('teams.away', ['nhl_team_id' => 6, 'team_abbrev' => 'BOS', 'score' => 2,
            'sog' => 25, 'sat' => 44, 'hits' => 15, 'blocks' => 8, 'pim' => 4, 'xg' => 2.75])
        ->assertJsonPath('teams.home.xg', 1.25)->assertJsonPath('teams.home.pim', 6);
});

it('returns goalie names identities teams saves shots and percentage units', function (): void {
    ($this->publish)();
    ($this->advanced)();
    ($this->requestLive)()->assertOk()->assertJsonCount(2, 'goalies')
        ->assertJsonPath('goalies.0', ['nhl_player_id' => 100, 'name' => 'Away goalie', 'nhl_team_id' => 6,
            'team_abbrev' => 'BOS', 'side' => 'away', 'saves' => 19, 'shots_against' => 20, 'save_pct' => 95.0, 'gsax' => 0.25])
        ->assertJsonPath('goalies.1.save_pct', 92.0)->assertJsonPath('goalies.1.gsax', 0.75);
});

it('includes each goalie who played after a replacement', function (): void {
    $this->snapshot['game']['away']['boxscore'][1]['toi'] = '02:00';
    ($this->publish)();
    ($this->requestLive)()->assertOk()->assertJsonCount(3, 'goalies')
        ->assertJsonPath('goalies.1.nhl_player_id', 101)->assertJsonPath('goalies.1.save_pct', null);
});

it('does not include bench goalies or skaters in goalie statistics', function (): void {
    ($this->publish)();
    $ids = collect(($this->requestLive)()->assertOk()->json('goalies'))->pluck('nhl_player_id')->all();
    expect($ids)->toBe([100, 200]);
});

it('keeps save percentage unavailable without shots faced', function (): void {
    $this->snapshot['game']['away']['boxscore'][0]['shots_against'] = null;
    ($this->publish)();
    ($this->requestLive)()->assertOk()->assertJsonPath('goalies.0.save_pct', null);
});

it('does not invent save percentages for inconsistent save counts', function (): void {
    $this->snapshot['game']['away']['boxscore'][0]['saves'] = 21;
    ($this->publish)();
    ($this->requestLive)()->assertOk()->assertJsonPath('goalies.0.save_pct', null);
});

it('preserves negative goalie saves above expected', function (): void {
    ($this->publish)();
    ($this->advanced)(['available' => true, 'goalies' => [100 => ['gsax' => -1.75]]]);
    ($this->requestLive)()->assertOk()->assertJsonPath('goalies.0.gsax', -1.75);
});

it('preserves measured zero team statistics and expectations', function (): void {
    foreach (['score', 'sog', 'sat', 'hits', 'blocks', 'pim'] as $field) {
        $this->snapshot['game']['away'][$field] = 0;
    }
    ($this->publish)();
    ($this->advanced)(['available' => true, 'teams' => [6 => ['xgf' => 0.0]], 'goalies' => [100 => ['gsax' => 0.0]]]);
    ($this->requestLive)()->assertOk()->assertJsonPath('teams.away.sat', 0)
        ->assertJsonPath('teams.away.xg', 0.0)->assertJsonPath('goalies.0.gsax', 0.0);
});

it('keeps missing team statistics null rather than substituting zero', function (): void {
    unset($this->snapshot['game']['away']['sat'], $this->snapshot['game']['home']['pim']);
    ($this->publish)();
    ($this->requestLive)()->assertOk()->assertJsonPath('teams.away.sat', null)->assertJsonPath('teams.home.pim', null);
});

it('keeps unscored expected metrics unavailable without running a calculation', function (): void {
    ($this->publish)();
    ($this->requestLive)()->assertOk()->assertJsonPath('teams.away.xg', null)->assertJsonPath('goalies.0.gsax', null)
        ->assertJsonPath('meta.advanced_stats.available', false);
    Http::assertNothingSent();
    Bus::assertNothingDispatched();
});

it('exposes advanced processing versions separately from live observation time', function (): void {
    ($this->publish)();
    ($this->advanced)(null, 9, 7);
    ($this->requestLive)()->assertOk()->assertJsonPath('meta.advanced_stats.pending', true)
        ->assertJsonPath('meta.advanced_stats.version', 7)
        ->assertJsonPath('meta.advanced_stats.processed_at', '2026-10-27T22:59:58+00:00')
        ->assertJsonPath('meta.advanced_stats.model_ids.goal', 11)
        ->assertJsonPath('teams.away.xg', 2.75)->assertJsonPath('meta.observed_at', now()->toIso8601String());
});

it('reports pending advanced processing before the first statistics result exists', function (): void {
    ($this->publish)();
    DB::table('nhl_pbp_processing_states')->insert(['nhl_game_id' => 2026020001,
        'pending_version' => 1, 'processed_version' => 0]);
    ($this->requestLive)()->assertOk()->assertJsonPath('meta.advanced_stats.pending', true)
        ->assertJsonPath('meta.advanced_stats.available', false)->assertJsonPath('teams.home.xg', null);
});

it('returns the provider clock period score and game state', function (): void {
    ($this->publish)();
    ($this->requestLive)()->assertOk()->assertJsonPath('game.period_number', 2)
        ->assertJsonPath('game.time_remaining', '12:34')->assertJsonPath('game.game_state', 'LIVE')
        ->assertJsonPath('teams.away.score', 2);
});

it('marks older live evidence stale without fetching replacement evidence', function (): void {
    ($this->publish)();
    $this->travel(21)->seconds();
    ($this->requestLive)()->assertOk()->assertJsonPath('meta.stale', true);
    Http::assertNothingSent();
});

it('keeps retained final evidence terminal rather than stale', function (): void {
    $this->snapshot['game']['mode'] = 'recap';
    $this->snapshot['game']['game_state'] = 'OFF';
    ($this->publish)();
    $this->travel(60)->seconds();
    ($this->requestLive)()->assertOk()->assertJsonPath('meta.stale', false)->assertJsonPath('game.game_state', 'OFF');
});

it('returns no store responses with increasing observed sequence values', function (): void {
    ($this->publish)();
    $first = ($this->requestLive)()->assertOk()->assertJsonPath('meta.sequence', 100);
    expect($first->headers->get('Cache-Control'))->toContain('no-store');
    $this->snapshot['sequence'] = 101;
    $this->snapshot['game']['away']['score'] = 1;
    ($this->publish)();
    ($this->requestLive)()->assertOk()->assertJsonPath('meta.sequence', 101)->assertJsonPath('teams.away.score', 1);
});

it('isolates metrics to the requested game', function (): void {
    $this->snapshot['game']['nhl_game_id'] = 2026020999;
    ($this->publish)();
    ($this->requestLive)()->assertOk()->assertJsonPath('available', false)->assertJsonPath('teams', null);
});

it('does not expose raw provider blobs prediction inputs or full player tables', function (): void {
    $this->snapshot['pbp'] = ['secret_source' => true];
    $this->snapshot['game']['prediction'] = ['confidence_inputs' => ['private' => true]];
    ($this->publish)();
    ($this->requestLive)()->assertOk()->assertJsonMissingPath('pbp')->assertJsonMissingPath('game.prediction')
        ->assertJsonMissingPath('teams.away.boxscore')->assertJsonMissingPath('fingerprint');
});

it('reuses one shared provider poll through persistence and authenticated HTTP reads', function (): void {
    $source = ['id' => 2026020001, 'gameState' => 'LIVE', 'gameDate' => '2026-10-27',
        'startTimeUTC' => '2026-10-27T23:00:00Z',
        'awayTeam' => ['id' => 6, 'abbrev' => 'BOS', 'score' => 1, 'sog' => 4],
        'homeTeam' => ['id' => 10, 'abbrev' => 'TOR', 'score' => 0, 'sog' => 3],
        'clock' => ['timeRemaining' => '12:34', 'inIntermission' => false],
        'periodDescriptor' => ['number' => 1, 'periodType' => 'REG'], 'plays' => [], 'rosterSpots' => [],
        'playerByGameStats' => ['awayTeam' => ['goalies' => [
            ['playerId' => 100, 'name' => ['default' => 'Away goalie'], 'shotsAgainst' => 3, 'saves' => 3, 'toi' => '07:26']]]]];
    Http::fake(['api-web.nhle.com/*' => Http::response($source)]);
    app(NhlLiveGameFeed::class)->poll(2026020001);
    $this->assertDatabaseHas('nhl_games', ['nhl_game_id' => 2026020001, 'game_state' => 'LIVE', 'away_team_score' => 1]);
    ($this->advanced)();
    Bus::fake();
    foreach (range(1, 3) as $request) {
        ($this->requestLive)()->assertOk()->assertJsonPath('teams.away.sog', 4)
            ->assertJsonPath('goalies.0.saves', 3)->assertJsonPath('goalies.0.save_pct', 100.0)
            ->assertJsonPath('teams.away.xg', 2.75);
    }
    Http::assertSentCount(2);
    Bus::assertNothingDispatched();
});


it('reads combined goalie save shots fields from the same retained provider observation', function (): void {
    $this->snapshot['game']['away']['boxscore'][0]['saves'] = null;
    $this->snapshot['game']['away']['boxscore'][0]['shots_against'] = null;
    $this->snapshot['boxscore']['playerByGameStats']['awayTeam']['goalies'] = [
        ['playerId' => 100, 'saveShotsAgainst' => '19/20'],
    ];
    ($this->publish)();
    ($this->requestLive)()->assertOk()->assertJsonPath('goalies.0.saves', 19)
        ->assertJsonPath('goalies.0.shots_against', 20)->assertJsonPath('goalies.0.save_pct', 95.0);
    Http::assertNothingSent();
});
