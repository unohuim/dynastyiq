<?php

declare(strict_types=1);

return [
    'context_concurrency' => (int) env('NHL_PREGAME_CONTEXT_CONCURRENCY', 2),
    'league_concurrency' => (int) env('NHL_PREGAME_LEAGUE_CONCURRENCY', 4),
    'player_batch_size' => 8,
    'league_entity_page_size' => 32,
    'league_continuation_seconds' => 8,
    'league_continuation_pages' => 4,
    'claim_grace_seconds' => 120,
    'league_lookahead_days' => (int) env('NHL_PREGAME_LEAGUE_LOOKAHEAD_DAYS', 8),
];
