<?php

declare(strict_types=1);

return [
    'context_concurrency' => (int) env('NHL_PREGAME_CONTEXT_CONCURRENCY', 2),
    'league_concurrency' => (int) env('NHL_PREGAME_LEAGUE_CONCURRENCY', 4),
    'player_batch_size' => 8,
    'league_entity_page_size' => 32,
    'league_continuation_seconds' => 8,
    'league_continuation_pages' => 4,
    'league_rank_group_page_size' => 8,
    'peer_cleanup_page_size' => 10000,
    'peer_cleanup_pages' => 8,
    'peer_cleanup_seconds' => 8,
    'timeout_retries' => 3,
    'timeout_retry_delay_seconds' => 10,
    'claim_grace_seconds' => 120,
    'league_lookahead_days' => (int) env('NHL_PREGAME_LEAGUE_LOOKAHEAD_DAYS', 8),
];
