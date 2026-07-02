<?php

return [
    'base_url' => env('YAHOO_BASE_URL', 'https://fantasysports.yahooapis.com/fantasy/v2'),

    'oauth' => [
        'authorize' => env('YAHOO_AUTHORIZE_URL', 'https://api.login.yahoo.com/oauth2/request_auth'),
        'token' => env('YAHOO_TOKEN_URL', 'https://api.login.yahoo.com/oauth2/get_token'),
    ],

    'fantasy' => [
        'game_code' => env('YAHOO_FANTASY_GAME_CODE', 'nhl'),
        'players_path' => env('YAHOO_PLAYERS_PATH', 'game/{game_key}/players'),
        'players_page_size' => (int) env('YAHOO_PLAYERS_PAGE_SIZE', 25),
        'players_import_max' => (int) env('YAHOO_PLAYERS_IMPORT_MAX', 2000),
    ],
];
