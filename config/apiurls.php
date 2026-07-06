<?php

declare(strict_types=1);

return [


    /*
    |--------------------------------------------------------------------------
    | Patreon API Configuration
    |--------------------------------------------------------------------------
    */
    'patreon' => [
        'base' => env('PATREON_BASE_URL', 'https://www.patreon.com/api/oauth2/v2'),

        'client_id'     => env('PATREON_CLIENT_ID'),
        'client_secret'=> env('PATREON_CLIENT_SECRET'),

        'auth' => [
            'in' => 'none',
        ],

        'endpoints' => [
            // OAuth
            'authorize' => '/oauth2/authorize',
            'token'     => 'https://www.patreon.com/api/oauth2/token',

            // API v2
            'identity'          => '/identity',
            'campaigns'         => '/campaigns',
            'campaign'          => '/campaigns/{campaignId}',
            'campaign_tiers'    => '/campaigns/{campaignId}/tiers',
            'campaign_members'  => '/campaigns/{campaignId}/members',
        ],
    ],

    
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
            'draft_picks'    => '/draft/picks/{year}/all',
            'standings_now'  => '/standings/now',
            'pbp'            => '/gamecenter/{gameId}/play-by-play',
            'boxscore'       => '/gamecenter/{gameId}/boxscore',
            'dailyscores'    => '/score/{date}',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | NHL Stats API Configuration
    |--------------------------------------------------------------------------
    |
    | The NHL stats API is a separate namespace from the web API above.
    |
    */
    'nhl_stats' => [
        'base'      => env('NHL_STATS_BASE_URL', 'https://api.nhle.com/stats/rest/en'),
        'auth'      => [
            'in'   => 'none',
        ],
        'endpoints' => [
            'teams' => '/team',
            'team'  => '/team/id/{teamId}',
            'players' => '/players',
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
        'browser_profile_path' => env('FANTRAX_BROWSER_PROFILE_PATH'),
        'browser_headless' => env('FANTRAX_BROWSER_HEADLESS', false),
        'node_path' => env('FANTRAX_NODE_PATH'),
        'auth'      => [
            'in'   => 'none',
        ],
        'endpoints' => [
            'players'           => '/general/getPlayerIds?sport=NHL',
            'adp'               => '/general/getAdp',
            'player_data'       => '/general/getPlayerProfile?leagueId={leagueId}&playerId={playerId}',
            'user_leagues'      => '/general/getLeagues?userSecretId={userSecretId}',
            'league_info'       => '/general/getLeagueInfo?leagueId={leagueId}',
            'draft_picks'       => '/general/getDraftPicks?leagueId={leagueId}',
            'draft_results'     => '/general/getDraftResults?leagueId={leagueId}',
            'team_rosters'      => '/general/getTeamRosters?leagueId={leagueId}',
            'standings'         => '/general/getStandings?leagueId={leagueId}',
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


    /*
    |--------------------------------------------------------------------------
    | Discord API Configuration
    |--------------------------------------------------------------------------
    |
    | Discord requires an API key sent as a Bearer token in the
    | Authorization header.
    |
    */
    'discord-bot' => [
        'base'      => env('DISCORD_BASE_URL', 'https://discord.com/api/v10'),
        'key'       => env('DISCORD_BOT_TOKEN'),
        'auth'      => [
            'in'   => 'header',
            'name' => 'Authorization',
            'type' => 'Bot',
        ],
        'endpoints' => [
            'guild_members'       => '/guilds/{guildId}/members',
            'guild_member'       => '/guilds/{guildId}/members/{discordUserId}',
        ],
    ],

];
