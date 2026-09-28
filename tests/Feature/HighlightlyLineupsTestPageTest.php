<?php

declare(strict_types=1);

use App\Http\Middleware\GlobalFreshInstallGuard;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->withoutMiddleware(GlobalFreshInstallGuard::class);
    $this->withoutVite();
    config(['services.highlightly.key' => 'private-test-key', 'apiurls.highlightly.base' => 'https://nhl.highlightly.net']);
    Http::preventStrayRequests();
    $this->game = [
        'id' => 91, 'date' => '2026-09-29T23:00:00Z',
        'awayTeam' => ['id' => 1, 'displayName' => 'Away Team'],
        'homeTeam' => ['id' => 2, 'displayName' => 'Home Team'],
    ];
    $this->gamesPayload = fn (array $games, int $total = 1) => [
        'data' => $games, 'pagination' => ['totalCount' => $total, 'limit' => 100, 'offset' => 0],
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
