<?php

declare(strict_types=1);

return [
    'algorithm_version' => 'custom_ev_pp_v4',
    'input_pipeline_version' => 'saved_pregame_checkpointed_v9',
    'reference_train_seasons' => ['20222023', '20232024', '20242025'],
    // Required completion after fallbacks, not a gate on observed player history.
    'minimum_input_completeness' => 1.0,
    'default_model_name' => 'sat_v2',
    'initial_game_pp_opportunities' => 5.9,
    'pp_minutes_per_opportunity' => 1.6367061050638902,
    'opponent_opportunity_weight' => 0.75,
    'train_rate_weight' => 0.50,
    'team_l5_win_pct_weight' => 0.50,
    'ev_goal_conversion' => [
        'minimum_train_sat' => 12,
        'low_volume_weights' => ['player' => 0.47, 'team' => 0.11, 'league' => 0.42],
    ],
    'defense' => [
        'prior_minutes' => 150.0,
        'fit_iterations' => 100,
        'fit_tolerance' => 0.00000001,
        'coefficients' => ['EV' => 0.3152975390611162, 'PP' => 0.346997919269746],
    ],
    'schedule' => [
        'forward_sat_multiplier' => 0.98,
        'defense_sat_multiplier' => 0.97,
        'away_fourth_b2b_home_probability_delta' => 0.0433712599,
    ],
    'batch_games' => 25,
    'fit_bucket_batch' => 8,
    'preparation_seconds' => 21600,
];
