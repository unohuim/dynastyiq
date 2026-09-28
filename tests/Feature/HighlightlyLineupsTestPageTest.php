<?php

declare(strict_types=1);

use App\Http\Middleware\GlobalFreshInstallGuard;
use App\Models\CapWagesPlayer;
use App\Models\NhlGame;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->withoutMiddleware(GlobalFreshInstallGuard::class);
    $this->withoutVite();
    config(['services.highlightly.key' => 'private-test-key', 'apiurls.highlightly.base' => 'https://nhl.highlightly.net']);
    config(['apiurls.capwages.key' => 'private-cap-key', 'apiurls.capwages.base' => 'https://capwages.com/api/gateway/v1']);
    Http::preventStrayRequests();
    $this->game = [
        'id' => 91, 'date' => '2026-09-29T23:00:00Z',
        'awayTeam' => ['id' => 1, 'displayName' => 'Away Team'],
        'homeTeam' => ['id' => 2, 'displayName' => 'Home Team'],
    ];
    $this->gamesPayload = fn (array $games, int $total = 1) => [
        'data' => $games, 'pagination' => ['totalCount' => $total, 'limit' => 100, 'offset' => 0],
    ];
    $this->storedGame = fn (array $overrides = []) => NhlGame::query()->create(array_replace([
        'nhl_game_id' => 2026010001, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-29', 'game_dow' => 'Tue', 'game_month' => 'Sep',
        'start_time_utc' => '2026-09-29 23:00:00',
        'away_team_abbrev' => 'TOR', 'away_team_place_name' => 'Toronto', 'away_team_common_name' => 'Maple Leafs',
        'home_team_abbrev' => 'EDM', 'home_team_place_name' => 'Edmonton', 'home_team_common_name' => 'Oilers',
    ], $overrides));
    $this->capResponse = fn (string $teamSlug, array $players = []) => [
        'success' => true, 'data' => compact('teamSlug', 'players'),
        'meta' => ['lastUpdated' => '2026-09-28T16:00:00Z'],
    ];
});

it('renders a public app page without a provider request', function () {
    Http::fake();
    $this->get('/lineups-test')->assertOk()->assertViewIs('lineups-test')->assertSee('Lineups test');
    Http::assertNothingSent();
});

it('defaults to September 29 2026', function () {
    $response = $this->get('/lineups-test');
    $response->assertViewHas('payload', fn ($payload) => $payload['date'] === '2026-09-29');

    expect($response->getContent())->toMatch('/<input\b(?=[^>]*id="lineups-test-date")(?=[^>]*value="2026-09-29")[^>]*>/s');
});

it('accepts an explicitly selected page date', function () {
    $response = $this->get('/lineups-test?date=2026-10-01');
    $response->assertViewHas('payload', fn ($payload) => $payload['date'] === '2026-10-01');

    expect($response->getContent())->toMatch('/<input\b(?=[^>]*id="lineups-test-date")(?=[^>]*value="2026-10-01")[^>]*>/s');
});

it('renders date controls with arrows on either side', function () {
    $this->get('/lineups-test')->assertSeeInOrder(['Previous date', 'lineups-test-date', 'Next date']);
});

it('never embeds the provider credential into the page', function () {
    $this->get('/lineups-test')->assertDontSee('private-test-key')->assertSee('lineups-test-payload');
});

it('rejects an invalid initial date', function () {
    $this->getJson('/lineups-test?date=2026-02-30')->assertUnprocessable()->assertJsonValidationErrors('date');
});

it('requires a date for the games payload', function () {
    Http::fake();
    $this->getJson('/lineups-test/games')->assertUnprocessable()->assertJsonValidationErrors('date');
    Http::assertNothingSent();
});

it('rejects malformed payload dates without spending quota', function () {
    Http::fake();
    $this->getJson('/lineups-test/games?date=tomorrow')->assertUnprocessable();
    Http::assertNothingSent();
});

it('allows guests to fetch NHL games for a Toronto calendar date', function () {
    Http::fake(['*' => Http::response(($this->gamesPayload)([$this->game]))]);
    $this->getJson('/lineups-test/games?date=2026-09-29')->assertOk()->assertJsonPath('games.0.id', 91);
    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query === ['league' => 'NHL', 'date' => '2026-09-29', 'timezone' => 'America/Toronto', 'limit' => '100', 'offset' => '0'];
    });
});

it('returns an empty game list when the provider has no matches', function () {
    Http::fake(['*' => Http::response(($this->gamesPayload)([], 0))]);
    $this->getJson('/lineups-test/games?date=2026-09-29')->assertOk()->assertJsonPath('games', []);
});

it('fetches remaining pages and deduplicates provider game ids', function () {
    $second = array_replace($this->game, ['id' => 92]);
    Http::fake(['*' => Http::sequence()
        ->push(($this->gamesPayload)([$this->game], 101))
        ->push(($this->gamesPayload)([$this->game, $second], 101))]);
    $this->getJson('/lineups-test/games?date=2026-09-29')->assertOk()->assertJsonCount(2, 'games');
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'offset=100'));
});

it('does not return a misleading partial list if a later page fails', function () {
    Http::fake(['*' => Http::sequence()->push(($this->gamesPayload)([$this->game], 101))->push([], 500)]);
    $this->getJson('/lineups-test/games?date=2026-09-29')->assertStatus(503)->assertJsonMissingPath('games');
});

it('bounds unexpected daily pagination', function () {
    Http::fake(['*' => Http::response(($this->gamesPayload)([], 1001))]);
    $this->getJson('/lineups-test/games?date=2026-09-29')->assertStatus(503);
    Http::assertSentCount(1);
});

it('handles missing credentials with an inline compatible error', function () {
    config(['services.highlightly.key' => null]);
    Http::fake();
    $this->getJson('/lineups-test/games?date=2026-09-29')->assertStatus(503)->assertJsonStructure(['message']);
    Http::assertNothingSent();
});

it('does not expose provider error bodies', function () {
    Http::fake(['*' => Http::response(['message' => 'private-test-key provider details'], 401)]);
    $this->getJson('/lineups-test/games?date=2026-09-29')->assertStatus(503)->assertDontSee('private-test-key');
});

it('rejects malformed provider game payloads', function () {
    Http::fake(['*' => Http::response(['data' => 'invalid'])]);
    $this->getJson('/lineups-test/games?date=2026-09-29')->assertStatus(503);
});

it('rejects games without usable provider identities', function () {
    Http::fake(['*' => Http::response(($this->gamesPayload)([['date' => '2026-09-29']]))]);
    $this->getJson('/lineups-test/games?date=2026-09-29')->assertStatus(503);
});

it('allows guests to retrieve both team lineups in one request', function () {
    $away = ['team' => ['displayName' => 'Away Team'], 'lineup' => [['id' => 1, 'player' => 'Away Player', 'jersey' => 9, 'positionAbbreviation' => 'C', 'isScratched' => true]]];
    $home = ['team' => ['displayName' => 'Home Team'], 'lineup' => [['id' => 2, 'player' => 'Home Player']]];
    Http::fake(['https://nhl.highlightly.net/lineups/91' => Http::response(compact('away', 'home'))]);
    $this->getJson('/lineups-test/91/lineups')->assertOk()->assertExactJson(['matchId' => 91, 'away' => $away, 'home' => $home]);
    Http::assertSentCount(1);
});

it('preserves a lineup available for only one team', function () {
    Http::fake(['*' => Http::response(['away' => ['lineup' => [['player' => 'Away Player']]]])]);
    $this->getJson('/lineups-test/91/lineups')->assertOk()->assertJsonPath('home', null)->assertJsonPath('away.lineup.0.player', 'Away Player');
});

it('represents unpublished lineups as unavailable for both teams', function () {
    Http::fake(['*' => Http::response([])]);
    $this->getJson('/lineups-test/91/lineups')->assertOk()->assertExactJson(['matchId' => 91, 'away' => null, 'home' => null]);
});

it('handles provider lineup failures without leaking response details', function () {
    Http::fake(['*' => Http::response(['message' => 'private-test-key'], 500)]);
    $this->getJson('/lineups-test/91/lineups')->assertStatus(503)->assertDontSee('private-test-key');
});

it('rejects malformed player lists', function () {
    Http::fake(['*' => Http::response(['home' => ['lineup' => [null]]])]);
    $this->getJson('/lineups-test/91/lineups')->assertStatus(503);
});

it('rejects nonnumeric match paths', function () {
    Http::fake();
    $this->getJson('/lineups-test/invalid/lineups')->assertNotFound();
    Http::assertNothingSent();
});

it('rejects a zero match id without a provider request', function () {
    Http::fake();
    $this->getJson('/lineups-test/0/lineups')->assertUnprocessable();
    Http::assertNothingSent();
});

it('allows ordinary authenticated users to access the public page and payloads', function () {
    $this->actingAs(User::factory()->create());
    Http::fake([
        'https://nhl.highlightly.net/matches*' => Http::response(($this->gamesPayload)([$this->game])),
        'https://nhl.highlightly.net/lineups/91' => Http::response([]),
    ]);
    $this->get('/lineups-test')->assertOk();
    $this->getJson('/lineups-test/games?date=2026-09-29')->assertOk();
    $this->getJson('/lineups-test/91/lineups')->assertOk();
});

it('renders both source buttons with Highlightly selected by default', function () {
    $this->get('/lineups-test')->assertOk()->assertSee('Cap Wages')->assertSee('Highlightly')
        ->assertViewHas('payload', fn ($payload) => $payload['source'] === 'highlightly');
});

it('accepts a Cap Wages page source without exposing its key', function () {
    $this->get('/lineups-test?source=capwages')->assertOk()->assertDontSee('private-cap-key')
        ->assertViewHas('payload', fn ($payload) => $payload['source'] === 'capwages');
});

it('rejects unknown sources on every page endpoint', function (string $url) {
    Http::fake();
    $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('source');
    Http::assertNothingSent();
})->with([
    '/lineups-test?source=invalid',
    '/lineups-test/games?date=2026-09-29&source=invalid',
    '/lineups-test/2026010001/lineups?source=invalid',
]);

it('allows guests to list stored games without calling either provider', function () {
    ($this->storedGame)();
    Http::fake();
    $this->getJson('/lineups-test/games?date=2026-09-29&source=capwages')->assertOk()
        ->assertJsonPath('games.0.id', 2026010001)
        ->assertJsonPath('games.0.awayTeam.displayName', 'Toronto Maple Leafs')
        ->assertJsonPath('games.0.homeTeam.displayName', 'Edmonton Oilers');
    Http::assertNothingSent();
});

it('filters stored games by their schedule date and orders by puck drop', function () {
    ($this->storedGame)();
    ($this->storedGame)(['nhl_game_id' => 2026010002, 'start_time_utc' => '2026-09-29 20:00:00']);
    ($this->storedGame)(['nhl_game_id' => 2026010003, 'game_date' => '2026-09-30']);
    $this->getJson('/lineups-test/games?date=2026-09-29&source=capwages')->assertOk()
        ->assertJsonCount(2, 'games')->assertJsonPath('games.0.id', 2026010002)->assertJsonPath('games.1.id', 2026010001);
});

it('returns an empty stored schedule without calling Highlightly', function () {
    Http::fake();
    $this->getJson('/lineups-test/games?date=2026-09-29&source=capwages')->assertOk()->assertJsonPath('games', []);
    Http::assertNothingSent();
});

it('lists stored games even when both provider credentials are absent', function () {
    ($this->storedGame)();
    config(['apiurls.capwages.key' => null, 'services.highlightly.key' => null]);
    $this->getJson('/lineups-test/games?date=2026-09-29&source=capwages')->assertOk()->assertJsonCount(1, 'games');
});

it('requires a stored game before requesting Cap Wages lineups', function () {
    Http::fake();
    $this->getJson('/lineups-test/2026010001/lineups?source=capwages')->assertNotFound();
    Http::assertNothingSent();
});

it('fetches one current lineup per stored team with header authentication and no date query', function () {
    ($this->storedGame)();
    Http::fake([
        'https://capwages.com/api/gateway/v1/lineups/toronto-maple-leafs' => Http::response(($this->capResponse)('toronto-maple-leafs')),
        'https://capwages.com/api/gateway/v1/lineups/edmonton-oilers' => Http::response(($this->capResponse)('edmonton-oilers')),
    ]);
    $this->getJson('/lineups-test/2026010001/lineups?source=capwages')->assertOk()
        ->assertJsonPath('matchId', 2026010001)->assertJsonPath('away.error', null)->assertJsonPath('home.error', null);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://capwages.com/api/gateway/v1/lineups/toronto-maple-leafs'
        && $request->hasHeader('Authorization', 'ApiKey private-cap-key'));
    Http::assertSent(fn (Request $request) => $request->url() === 'https://capwages.com/api/gateway/v1/lineups/edmonton-oilers'
        && $request->hasHeader('Authorization', 'ApiKey private-cap-key'));
});

it('uses stored Cap Wages names and jerseys while preserving provider order and positions', function () {
    ($this->storedGame)();
    CapWagesPlayer::query()->create(['slug' => 'example-player', 'name' => 'Example Player', 'jersey_number' => 97]);
    $players = [
        ['playerSlug' => 'example-player', 'line' => 'f1', 'position' => 'c'],
        ['playerSlug' => 'unmatched-player', 'line' => 'provider-extra', 'position' => 'extra'],
    ];
    Http::fake([
        '*toronto-maple-leafs' => Http::response(($this->capResponse)('toronto-maple-leafs', $players)),
        '*edmonton-oilers' => Http::response(($this->capResponse)('edmonton-oilers')),
    ]);
    $this->getJson('/lineups-test/2026010001/lineups?source=capwages')->assertOk()
        ->assertJsonPath('away.lineup.0.player', 'Example Player')->assertJsonPath('away.lineup.0.jersey', 97)
        ->assertJsonPath('away.lineup.0.position', 'c')->assertJsonPath('away.lineup.0.line', 'f1')
        ->assertJsonPath('away.lineup.1.player', 'unmatched-player')->assertJsonPath('away.lineup.1.jersey', null)
        ->assertJsonPath('away.lineup.1.line', 'provider-extra')->assertJsonPath('away.lineup.1.position', 'extra')
        ->assertJsonPath('away.lastUpdated', '2026-09-28T16:00:00Z');
    $this->assertDatabaseCount('capwages_players', 1);
    $this->assertDatabaseCount('nhl_games', 1);
});

it('keeps the other lineup visible when a team has no published lineup', function () {
    ($this->storedGame)();
    Http::fake([
        '*toronto-maple-leafs' => Http::response(['success' => false, 'error' => ['code' => 404, 'message' => 'Lineup not found']], 404),
        '*edmonton-oilers' => Http::response(($this->capResponse)('edmonton-oilers', [['playerSlug' => 'home-player', 'line' => 'f1', 'position' => 'c']])),
    ]);
    $this->getJson('/lineups-test/2026010001/lineups?source=capwages')->assertOk()
        ->assertJsonPath('away.lineup', [])->assertJsonPath('away.error', null)->assertJsonPath('home.lineup.0.player', 'home-player');
});

it('reports one provider failure independently from the other team', function () {
    ($this->storedGame)();
    Http::fake([
        '*toronto-maple-leafs' => Http::response(['message' => 'private-cap-key'], 500),
        '*edmonton-oilers' => Http::response(($this->capResponse)('edmonton-oilers')),
    ]);
    $this->getJson('/lineups-test/2026010001/lineups?source=capwages')->assertOk()
        ->assertJsonPath('away.error', 'Unable to load this team’s Cap Wages lineup. Please try again.')
        ->assertJsonPath('home.error', null)->assertDontSee('private-cap-key');
    Http::assertSentCount(2);
});

it('does not guess a slug for an unmapped team', function () {
    ($this->storedGame)(['away_team_abbrev' => 'UNKNOWN']);
    Http::fake(['*edmonton-oilers' => Http::response(($this->capResponse)('edmonton-oilers'))]);
    $this->getJson('/lineups-test/2026010001/lineups?source=capwages')->assertOk()
        ->assertJsonPath('away.error', 'Cap Wages team mapping is unavailable.')->assertJsonPath('home.error', null);
    Http::assertSentCount(1);
});

it('reports missing Cap Wages credentials without making requests', function () {
    ($this->storedGame)();
    config(['apiurls.capwages.key' => null]);
    Http::fake();
    $this->getJson('/lineups-test/2026010001/lineups?source=capwages')->assertOk()
        ->assertJsonPath('home.error', 'Unable to load this team’s Cap Wages lineup. Please try again.');
    Http::assertNothingSent();
});

it('rejects a lineup returned for a different team', function () {
    ($this->storedGame)();
    Http::fake(['*' => Http::response(($this->capResponse)('unexpected-team'))]);
    $this->getJson('/lineups-test/2026010001/lineups?source=capwages')->assertOk()
        ->assertJsonPath('away.lineup', [])->assertJsonPath('home.lineup', [])
        ->assertJsonPath('away.error', 'Unable to load this team’s Cap Wages lineup. Please try again.');
});

it('handles malformed Cap Wages bodies as errors rather than empty successful lineups', function () {
    ($this->storedGame)();
    Http::fake(['*' => Http::response('<html>Not JSON</html>')]);
    $this->getJson('/lineups-test/2026010001/lineups?source=capwages')->assertOk()
        ->assertJsonPath('away.error', 'Unable to load this team’s Cap Wages lineup. Please try again.');
});

it('handles Cap Wages connection errors independently', function () {
    ($this->storedGame)();
    Http::fake(fn () => throw new ConnectionException('Simulated failure'));
    $this->getJson('/lineups-test/2026010001/lineups?source=capwages')->assertOk()
        ->assertJsonPath('away.error', 'Unable to load this team’s Cap Wages lineup. Please try again.')
        ->assertJsonPath('home.error', 'Unable to load this team’s Cap Wages lineup. Please try again.');
});

it('ignores caller supplied team slugs and uses only the stored matchup', function () {
    ($this->storedGame)();
    Http::fake([
        '*toronto-maple-leafs' => Http::response(($this->capResponse)('toronto-maple-leafs')),
        '*edmonton-oilers' => Http::response(($this->capResponse)('edmonton-oilers')),
    ]);
    $this->getJson('/lineups-test/2026010001/lineups?source=capwages&teamSlug=other-team')->assertOk();
    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'other-team'));
});

it('allows ordinary authenticated users to read Cap Wages games and lineups', function () {
    ($this->storedGame)();
    $this->actingAs(User::factory()->create());
    Http::fake([
        '*toronto-maple-leafs' => Http::response(($this->capResponse)('toronto-maple-leafs')),
        '*edmonton-oilers' => Http::response(($this->capResponse)('edmonton-oilers')),
    ]);
    $this->get('/lineups-test?source=capwages')->assertOk();
    $this->getJson('/lineups-test/games?date=2026-09-29&source=capwages')->assertOk();
    $this->getJson('/lineups-test/2026010001/lineups?source=capwages')->assertOk();
});
