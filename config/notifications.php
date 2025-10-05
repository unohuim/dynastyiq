<?php

// config/notifications.php

return [
    'defaults' => [
        'discord' => [
            // Effective defaults when no user/org override exists.
            'dm'      => (bool) env('NOTIFY_DISCORD_DM_DEFAULT', true),
            'channel' => (bool) env('NOTIFY_DISCORD_CHANNEL_DEFAULT', false),
            'channel-name' => env('NOTIFY_DISCORD_CHANNEL_NAME_DEFAULT', 'diq-responses'),
        ],
    ],
];
