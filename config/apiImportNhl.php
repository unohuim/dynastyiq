<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | NHL Import Configuration
    |--------------------------------------------------------------------------
    |
    | Controls how far back the NHL importer will fetch games.
    | The value should be the *earliest* NHL season_id to import.
    | Format: YYYYYYYY where the first 4 digits are the season start year,
    | and the last 4 digits are the season end year.
    |
    | Examples:
    |   20092010 = 2009–2010 season
    |   20232024 = 2023–2024 season
    |
    | Adjust this to widen or narrow your historical backfill window.
    | Moving this to an earlier season_id will cause the importer to
    | discover and schedule older games. Moving it forward will
    | stop discovering those older games but will not delete them.
    |
    */

    'min_season_id'                 => env('NHL_MIN_SEASON_ID', '20192020'),
    'max_weeks_discovery_job'       => env('MAX_WEEKS_DISCOVERY_JOB', 13),
    'max_pbp_seconds'               => env('MAX_PBP_SECONDS', 7200),
    'max_shifts_seconds'            => env('MAX_SHIFTS_SECONDS', 7200),
    'max_boxscore_seconds'          => env('MAX_BOXSCORE_SECONDS', 7200),
    'max_game_summaries_seconds'    => env('MAX_GAME_SUMMARIES_SECONDS', 7200),
    'max_shift_units_seconds'       => env('MAX_SHIFT_UNITS_SECONDS', 7200),
    'max_connect_events_seconds'    => env('MAX_CONNECT_EVENTS_SECONDS', 7200),
    'max_sum_game_units_seconds'    => env('MAX_SUM_GAME_UNITS_SECONDS', 7200),

];
