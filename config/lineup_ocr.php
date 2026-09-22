<?php

declare(strict_types=1);

return [
    'enabled' => env('LINEUP_OCR_ENABLED', true),
    'node' => env('LINEUP_OCR_NODE', env('FANTRAX_NODE_PATH', 'node')),
    'timeout_seconds' => 25,
    'discovery_budget_seconds' => 120,
    'download_timeout_seconds' => 10,
    'max_bytes' => 10 * 1024 * 1024,
    'max_pixels' => 20_000_000,
    'max_images_per_post' => 4,
    'minimum_confidence' => 0.7,
    'cache_seconds' => 7 * 86400,
];
