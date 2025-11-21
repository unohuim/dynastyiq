<?php

declare(strict_types=1);

return [
    'base_url' => env('PATREON_BASE_URL', 'https://www.patreon.com/api/oauth2/v2'),
    'oauth' => [
        'authorize' => env('PATREON_AUTHORIZE_URL', 'https://www.patreon.com/oauth2/authorize'),
        'token' => env('PATREON_TOKEN_URL', 'https://www.patreon.com/api/oauth2/token'),
    ],
    'scopes' => [
        'identity',
        'identity[email]',
        'campaigns',
        'campaigns.members',
    ],
];
