<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | NHL API Configuration
    |--------------------------------------------------------------------------
    |
    | The NHL API does not require an API key; all endpoints use simple GET
    | requests. We mark auth.in = 'none' so our HTTP client knows not to
    | inject any credentials.
    |
    */
    'nhl' => [
        'base'      => env('NHL_BASE_URL', 'https://api-web.nhle.com/v1'),
        'auth'      => [
            'in'   => 'none',
        ],
        'endpoints' => [
            'player_landing' => '/player/{playerId}/landing',
            'roster_current' => '/roster/{teamAbbrev}/current',
            'roster_season'  => '/roster/{teamAbbrev}/{seasonId}',
            'prospects'      => '/prospects/{teamAbbrev}',
            'standings_now'  => '/standings/now',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fantrax API Configuration
    |--------------------------------------------------------------------------
    |
    | Fantrax does not require an API key for these public endpoints.
    | We similarly mark auth.in = 'none'.
    |
    */
    'fantrax' => [
        'base'      => env('FANTRAX_BASE_URL', 'https://www.fantrax.com/fxea'),
        'auth'      => [
            'in'   => 'none',
        ],
        'endpoints' => [
            'players'     => '/general/getPlayerIds?sport=NHL',
            'player_data' => '/general/getPlayerProfile?leagueId={leagueId}&playerId={playerId}',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | CapWages API Configuration
    |--------------------------------------------------------------------------
    |
    | CapWages requires an API key sent as a Bearer token in the
    | Authorization header.
    |
    */
    'capwages' => [
        'base'      => env('CAPWAGES_BASE_URL', 'https://capwages.com/api/gateway/v1'),
        'key'       => env('CAPWAGES_API_KEY'),
        'auth'      => [
            'in'   => 'header',
            'name' => 'Authorization',
            'type' => 'ApiKey',
        ],
        'endpoints' => [
            'players'       => '/players',
            'player_detail' => '/players/{slug}',
        ],
    ],

];
