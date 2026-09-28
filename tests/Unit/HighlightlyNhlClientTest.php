<?php

declare(strict_types=1);

use App\Services\HighlightlyNhlClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    config([
        'apiurls.highlightly.base' => 'https://nhl.highlightly.net',
        'services.highlightly.key' => 'test-highlightly-key',
        'services.highlightly.timeout_seconds' => 17,
        'services.highlightly.connect_timeout_seconds' => 4,
    ]);

    Http::preventStrayRequests();
    $this->client = app(HighlightlyNhlClient::class);
    $this->assertEndpoint = function (string $method, array $arguments, string $path, array $query = []) {
        $payload = ['providerField' => ['preserve' => 'this']];
        Http::fake(['*' => Http::response($payload)]);

        expect($this->client->{$method}(...$arguments)->json())->toBe($payload);

        Http::assertSent(function (Request $request) use ($path, $query) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $sentQuery);

            return $request->method() === 'GET'
                && parse_url($request->url(), PHP_URL_PATH) === $path
                && $sentQuery === array_map(static fn ($value) => (string) $value, $query)
                && $request->hasHeader('x-rapidapi-key', 'test-highlightly-key')
                && $request->hasHeader('Accept', 'application/json')
                && ! $request->hasHeader('x-rapidapi-host');
        });
        Http::assertSentCount(1);
    };
});

it('requests bookmakers with name and pagination', function () {
    $query = ['name' => 'Example Book', 'limit' => 20, 'offset' => 40];
    ($this->assertEndpoint)('bookmakers', [$query], '/bookmakers', $query);
});

it('requests a bookmaker by provider id', function () {
    ($this->assertEndpoint)('bookmaker', [7], '/bookmakers/7');
});

it('requests head to head with both team ids', function () {
    ($this->assertEndpoint)('headToHead', [7, 8], '/head-2-head', ['teamIdOne' => 7, 'teamIdTwo' => 8]);
});

it('requests highlights with match and pagination filters', function () {
    $query = ['matchId' => 91, 'limit' => 40, 'offset' => 0];
    ($this->assertEndpoint)('highlights', [$query], '/highlights', $query);
});

it('requests restrictions using the highlight id', function () {
    ($this->assertEndpoint)('highlightGeoRestrictions', [23], '/highlights/geo-restrictions/23');
});

it('requests one highlight by provider id', function () {
    ($this->assertEndpoint)('highlight', [23], '/highlights/23');
});

it('requests recent games using the team id query', function () {
    ($this->assertEndpoint)('lastFiveGames', [7], '/last-five-games', ['teamId' => 7]);
});

it('requests matches with league date and timezone', function () {
    $query = ['league' => 'NHL', 'date' => '2026-01-15', 'timezone' => 'America/Toronto'];
    ($this->assertEndpoint)('matches', [$query], '/matches', $query);
});

it('requests detailed match data by provider id', function () {
    ($this->assertEndpoint)('matchDetails', [91], '/matches/91');
});

it('requests live odds with bookmaker and match filters', function () {
    $query = ['matchId' => 91, 'bookmakerId' => 7, 'oddsType' => 'live', 'limit' => 5];
    ($this->assertEndpoint)('odds', [$query], '/odds', $query);
});

it('requests standings with provider year and conference', function () {
    $query = ['leagueType' => 'NHL', 'year' => 2026, 'abbreviation' => 'EAST'];
    ($this->assertEndpoint)('standings', [$query], '/standings', $query);
});

it('requests teams with correctly encoded names', function () {
    $query = ['league' => 'NHL', 'displayName' => 'Example A & B'];
    ($this->assertEndpoint)('teams', [$query], '/teams', $query);
});

it('requests team aggregates with the required date and timezone', function () {
    ($this->assertEndpoint)(
        'teamStatistics',
        [7, '2026-01-15', 'America/Toronto'],
        '/teams/statistics/7',
        ['fromDate' => '2026-01-15', 'timezone' => 'America/Toronto'],
    );
});

it('requests one team by provider id', function () {
    ($this->assertEndpoint)('team', [7], '/teams/7');
});

it('requests both lineups with the match path parameter', function () {
    ($this->assertEndpoint)('lineups', [91], '/lineups/91');
});

it('requests players with provider pagination', function () {
    $query = ['name' => 'Example Player', 'limit' => 1000, 'offset' => 1000];
    ($this->assertEndpoint)('players', [$query], '/players', $query);
});

it('requests a detailed player profile', function () {
    ($this->assertEndpoint)('player', [44], '/players/44');
});

it('requests player season statistics', function () {
    ($this->assertEndpoint)('playerStatistics', [44], '/players/44/statistics');
});

it('supports every documented base url with its platform headers', function (string $base, ?string $host) {
    config(['apiurls.highlightly.base' => $base.'/']);
    Http::fake(['*' => Http::response([])]);

    $this->client->teams();

    Http::assertSent(fn (Request $request) => $request->url() === $base.'/teams'
        && $request->hasHeader('x-rapidapi-key', 'test-highlightly-key')
        && ($host === null
            ? ! $request->hasHeader('x-rapidapi-host')
            : $request->hasHeader('x-rapidapi-host', $host)));
    Http::assertSentCount(1);
})->with([
    ['https://nhl.highlightly.net', null],
    ['https://sports.highlightly.net/nhl', null],
    ['https://nhl-ncaah-api.p.rapidapi.com', 'nhl-ncaah-api.p.rapidapi.com'],
    ['https://sport-highlights-api.p.rapidapi.com/nhl', 'sport-highlights-api.p.rapidapi.com'],
]);

it('rejects missing credentials before any request', function ($key) {
    config(['services.highlightly.key' => $key]);
    Http::fake();

    expect(fn () => $this->client->teams())->toThrow(\LogicException::class);
    Http::assertNothingSent();
})->with([null, '', '   ']);

it('rejects unapproved destinations before exposing credentials', function (string $base) {
    config(['apiurls.highlightly.base' => $base]);
    Http::fake();

    expect(fn () => $this->client->teams())->toThrow(\LogicException::class);
    Http::assertNothingSent();
})->with(['http://nhl.highlightly.net', 'https://example.com', 'https://sports.highlightly.net']);

it('rejects nonpositive timeouts before any request', function (string $setting, int $value) {
    config(['services.highlightly.'.$setting => $value]);
    Http::fake();

    expect(fn () => $this->client->teams())->toThrow(\LogicException::class);
    Http::assertNothingSent();
})->with([['timeout_seconds', 0], ['connect_timeout_seconds', -1]]);

it('rejects invalid provider ids locally', function (string $method, array $arguments) {
    Http::fake();

    expect(fn () => $this->client->{$method}(...$arguments))->toThrow(\InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([
    ['player', [0]],
    ['matchDetails', [-1]],
    ['headToHead', [0, 7]],
    ['headToHead', [7, -1]],
    ['lastFiveGames', [0]],
]);

it('requires a known nonblank primary filter', function (string $method, array $query) {
    Http::fake();

    expect(fn () => $this->client->{$method}($query))->toThrow(\InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([
    ['matches', []],
    ['matches', ['limit' => 100, 'offset' => 0, 'timezone' => 'Etc/UTC']],
    ['matches', ['leagueName' => 'NHL']],
    ['matches', ['league' => '   ']],
    ['highlights', ['limit' => 40]],
    ['highlights', ['date' => null]],
    ['highlights', ['matchId' => []]],
    ['odds', ['oddsType' => 'live', 'limit' => 5]],
]);

it('rejects malformed and impossible aggregate dates', function (string $date) {
    Http::fake();

    expect(fn () => $this->client->teamStatistics(7, $date))->toThrow(\InvalidArgumentException::class);
    Http::assertNothingSent();
})->with(['', '15.01.2026', '2026-02-30', '2026-1-15']);

it('leaves the default timezone to the provider when omitted', function () {
    ($this->assertEndpoint)('teamStatistics', [7, '2024-02-29'], '/teams/statistics/7', ['fromDate' => '2024-02-29']);
});

it('preserves arrays instead of unwrapping a single entity', function () {
    $payload = [['id' => 44, 'profile' => ['providerField' => 'untouched']]];
    Http::fake(['*' => Http::response($payload)]);

    expect($this->client->player(44)->json())->toBe($payload);
});

it('preserves quota headers and plan restricted pagination without fetching more pages', function () {
    $payload = [
        'data' => [],
        'pagination' => ['totalCount' => 90, 'offset' => 0, 'limit' => 40],
        'plan' => ['tier' => 'BASIC', 'message' => 'Restricted coverage'],
    ];
    Http::fake(['*' => Http::response($payload, 200, [
        'x-ratelimit-requests-limit' => '100',
        'x-ratelimit-requests-remaining' => '0',
    ])]);

    $response = $this->client->highlights(['leagueName' => 'NHL']);

    expect($response->json())->toBe($payload)
        ->and($response->header('x-ratelimit-requests-limit'))->toBe('100')
        ->and($response->header('x-ratelimit-requests-remaining'))->toBe('0');
    Http::assertSentCount(1);
});

it('propagates provider failures without retrying or replacing their bodies', function (int $status) {
    $payload = ['statusCode' => $status, 'message' => 'Provider failure'];
    Http::fake(['*' => Http::response($payload, $status)]);

    try {
        $this->client->teams();
        $this->fail('Expected a provider HTTP exception.');
    } catch (RequestException $exception) {
        expect($exception->response->status())->toBe($status)
            ->and($exception->response->json())->toBe($payload);
    }

    Http::assertSentCount(1);
})->with([400, 401, 403, 429, 500]);

it('propagates connection failures without returning an empty result', function () {
    Http::fake(fn () => throw new ConnectionException('Simulated connection failure'));

    expect(fn () => $this->client->teams())->toThrow(ConnectionException::class);
});

it('rejects success responses without a JSON container', function (string $body) {
    Http::fake(['*' => Http::response($body, 200)]);

    expect(fn () => $this->client->teams())->toThrow(\UnexpectedValueException::class);
    Http::assertSentCount(1);
})->with(['<html>Unexpected response</html>', 'null', 'false', '"text"', '42', '']);

it('rejects redirects instead of forwarding credentials', function () {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://example.com'])]);

    expect(fn () => $this->client->teams())->toThrow(\UnexpectedValueException::class);
    Http::assertSentCount(1);
});

it('fails locally when an endpoint is missing or contains unresolved placeholders', function ($path) {
    config(['apiurls.highlightly.endpoints.teams' => $path]);
    Http::fake();

    expect(fn () => $this->client->teams())->toThrow(\LogicException::class);
    Http::assertNothingSent();
})->with([null, '', '//example.com', '/teams/{missing}']);
