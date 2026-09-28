<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

/**
 * Fetch Highlightly hockey resources while preserving payloads and quota headers.
 *
 * Each method performs one GET request; callers own pagination and persistence.
 */
class HighlightlyNhlClient
{
    /**
     * Retrieve one page of bookmakers.
     *
     * @param array<string, mixed> $query
     */
    public function bookmakers(array $query = []): Response
    {
        return $this->get('bookmakers', query: $query);
    }

    /** Retrieve a bookmaker by its Highlightly ID. */
    public function bookmaker(int $id): Response
    {
        return $this->get('bookmaker', ['id' => $id]);
    }

    /** Retrieve the last ten meetings between two Highlightly teams. */
    public function headToHead(int $teamIdOne, int $teamIdTwo): Response
    {
        $this->assertId($teamIdOne);
        $this->assertId($teamIdTwo);

        return $this->get('head_to_head', query: compact('teamIdOne', 'teamIdTwo'));
    }

    /**
     * Retrieve one page of highlights.
     *
     * @param array<string, mixed> $query
     */
    public function highlights(array $query): Response
    {
        $this->requirePrimaryFilter($query, [
            'leagueName', 'date', 'season', 'matchId', 'homeTeamId', 'awayTeamId',
            'homeTeamName', 'awayTeamName', 'homeTeamAbbreviation', 'awayTeamAbbreviation',
            'homeTeamDisplayName', 'awayTeamDisplayName',
        ]);

        return $this->get('highlights', query: $query);
    }

    /** Retrieve geography and embedding restrictions for a highlight. */
    public function highlightGeoRestrictions(int $id): Response
    {
        return $this->get('highlight_geo_restrictions', ['id' => $id]);
    }

    /** Retrieve a highlight by its Highlightly ID. */
    public function highlight(int $id): Response
    {
        return $this->get('highlight', ['id' => $id]);
    }

    /** Retrieve the last five finished games for a Highlightly team. */
    public function lastFiveGames(int $teamId): Response
    {
        $this->assertId($teamId);

        return $this->get('last_five_games', query: compact('teamId'));
    }

    /**
     * Retrieve one page of matches.
     *
     * @param array<string, mixed> $query
     */
    public function matches(array $query): Response
    {
        $this->requirePrimaryFilter($query, [
            'league', 'date', 'season', 'homeTeamId', 'awayTeamId',
            'homeTeamName', 'awayTeamName', 'homeTeamAbbreviation', 'awayTeamAbbreviation',
            'homeTeamDisplayName', 'awayTeamDisplayName',
        ]);

        return $this->get('matches', query: $query);
    }

    /** Retrieve detailed match data by its Highlightly ID. */
    public function matchDetails(int $id): Response
    {
        return $this->get('match', ['id' => $id]);
    }

    /**
     * Retrieve one page of match odds.
     *
     * @param array<string, mixed> $query
     */
    public function odds(array $query): Response
    {
        $this->requirePrimaryFilter($query, [
            'leagueName', 'bookmakerId', 'bookmakerName', 'matchId', 'date',
        ]);

        return $this->get('odds', query: $query);
    }

    /**
     * Retrieve one page of standings.
     *
     * @param array<string, mixed> $query
     */
    public function standings(array $query = []): Response
    {
        return $this->get('standings', query: $query);
    }

    /**
     * Retrieve teams matching the filters.
     *
     * @param array<string, mixed> $query
     */
    public function teams(array $query = []): Response
    {
        return $this->get('teams', query: $query);
    }

    /** Retrieve team aggregates from a YYYY-MM-DD date, with an optional timezone. */
    public function teamStatistics(int $id, string $fromDate, ?string $timezone = null): Response
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $fromDate);

        if ($date === false || $date->format('Y-m-d') !== $fromDate) {
            throw new InvalidArgumentException('Highlightly fromDate must be a valid YYYY-MM-DD date.');
        }

        $query = ['fromDate' => $fromDate];

        if ($timezone !== null) {
            $query['timezone'] = $timezone;
        }

        return $this->get('team_statistics', ['id' => $id], $query);
    }

    /** Retrieve a team by its Highlightly ID. */
    public function team(int $id): Response
    {
        return $this->get('team', ['id' => $id]);
    }

    /** Retrieve both team lineups for a Highlightly match. */
    public function lineups(int $matchId): Response
    {
        return $this->get('lineups', ['matchId' => $matchId]);
    }

    /**
     * Retrieve one page of players.
     *
     * @param array<string, mixed> $query
     */
    public function players(array $query = []): Response
    {
        return $this->get('players', query: $query);
    }

    /** Retrieve a player's profile by its Highlightly ID. */
    public function player(int $id): Response
    {
        return $this->get('player', ['id' => $id]);
    }

    /** Retrieve a player's season statistics by its Highlightly ID. */
    public function playerStatistics(int $id): Response
    {
        return $this->get('player_statistics', ['id' => $id]);
    }

    /**
     * Send one authenticated request without retries, redirects, or payload reshaping.
     *
     * @param array<string, int> $replacements
     * @param array<string, mixed> $query
     */
    private function get(string $endpoint, array $replacements = [], array $query = []): Response
    {
        $key = config('services.highlightly.key');
        $base = rtrim((string) config('apiurls.highlightly.base'), '/');
        $timeout = (int) config('services.highlightly.timeout_seconds', 30);
        $connectTimeout = (int) config('services.highlightly.connect_timeout_seconds', 10);

        if (! is_string($key) || trim($key) === '') {
            throw new LogicException('Configure services.highlightly.key before calling Highlightly.');
        }

        // Restrict credential-bearing requests to the documented product hosts.
        if (! in_array($base, [
            'https://nhl.highlightly.net',
            'https://sports.highlightly.net/nhl',
            'https://nhl-ncaah-api.p.rapidapi.com',
            'https://sport-highlights-api.p.rapidapi.com/nhl',
        ], true)) {
            throw new LogicException('Configure a documented Highlightly NHL base URL.');
        }

        if ($timeout <= 0 || $connectTimeout <= 0) {
            throw new LogicException('Highlightly timeouts must be greater than zero.');
        }

        $path = config("apiurls.highlightly.endpoints.{$endpoint}");

        if (! is_string($path) || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new LogicException('The Highlightly endpoint path is not configured correctly.');
        }

        foreach ($replacements as $name => $id) {
            $this->assertId($id);
            $path = str_replace('{'.$name.'}', (string) $id, $path);
        }

        if (str_contains($path, '{') || str_contains($path, '}')) {
            throw new LogicException('The Highlightly endpoint contains an unresolved placeholder.');
        }

        $headers = ['x-rapidapi-key' => $key];
        $host = (string) parse_url($base, PHP_URL_HOST);

        if (str_ends_with($host, '.p.rapidapi.com')) {
            $headers['x-rapidapi-host'] = $host;
        }

        $response = Http::acceptJson()
            ->withHeaders($headers)
            ->timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->withoutRedirecting()
            ->get($base.$path, $query)
            ->throw();

        if (! $response->successful() || ! is_array($response->json())) {
            throw new UnexpectedValueException('Highlightly returned a non-success or non-container JSON response.');
        }

        return $response;
    }

    /** Require a positive provider identifier before sending a request. */
    private function assertId(int $id): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Highlightly IDs must be positive integers.');
        }
    }

    /**
     * Prevent secondary-only queries from consuming provider quota.
     *
     * @param array<string, mixed> $query
     * @param list<string> $primaryKeys
     */
    private function requirePrimaryFilter(array $query, array $primaryKeys): void
    {
        foreach ($primaryKeys as $key) {
            $value = $query[$key] ?? null;

            if ((is_string($value) && trim($value) !== '') || is_int($value) || is_float($value)) {
                return;
            }
        }

        throw new InvalidArgumentException('Highlightly requires at least one primary query filter.');
    }
}
