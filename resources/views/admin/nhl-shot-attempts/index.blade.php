@php
    $tabs = [
        'explorer' => 'Explorer',
        'aggregates' => 'Aggregates',
        'buckets' => 'Buckets',
        'predictive' => 'Predictive',
        'biometrics' => 'Biometrics',
        'player-profiles' => 'Player Profiles',
        'skater-o-profiles' => 'Skater O Profiles',
        'g-sat-profiles' => 'G SAT Profiles',
        'skater-d-profiles' => 'Skater D Profiles',
        'xg' => 'xG',
        'projections' => 'Projections',
        'matchup' => 'Matchup',
        'qa' => 'QA',
    ];
    $groupLabels = [
        'team_abbrev' => 'Team',
        'shooter_player_id' => 'Shooter',
        'goalie_player_id' => 'Goalie',
        'strength_bucket' => 'Strength',
        'attempt_result' => 'Result',
        'distance_bucket' => 'Distance',
        'angle_bucket' => 'Angle',
        'shot_type_bucket' => 'Shot Type',
        'shot_side' => 'Goalie Side',
        'is_off_wing_attempt' => 'Off-Wing',
        'is_rebound' => 'Rebound',
        'previous_event_type' => 'Previous Event',
    ];
    $formatNumber = fn ($value) => number_format((int) $value);
    $formatDecimal = fn ($value, $places = 2) => $value === null ? 'N/A' : number_format((float) $value, $places);
    $formatOffWing = function ($value) {
        if ($value === null) {
            return 'unknown';
        }

        if (in_array($value, [true, 1, '1', 't', 'true'], true)) {
            return 'off-wing';
        }

        return 'strong-side';
    };
    $tabUrl = fn ($tab) => route('admin.nhl-shot-attempts.index', array_merge(request()->except('page'), ['tab' => $tab]));
    $sortUrl = fn ($key, $tab = null) => route('admin.nhl-shot-attempts.index', array_merge(
        request()->except(['page', 'sort', 'direction']),
        [
            'tab' => $tab ?? $activeTab,
            'sort' => $key,
            'direction' => $sort === $key && $direction === 'desc' ? 'asc' : 'desc',
        ]
    ));
    $sortArrow = function ($key) use ($sort, $direction) {
        if ($sort !== $key) {
            return '';
        }

        return $direction === 'desc' ? '↓' : '↑';
    };
    $xgSortUrl = function ($table, $key) use ($xgSorts, $xgDirections) {
        $sortParam = 'xg_' . $table . '_sort';
        $directionParam = 'xg_' . $table . '_direction';

        return route('admin.nhl-shot-attempts.index', array_merge(
            request()->except(['page', $sortParam, $directionParam]),
            [
                'tab' => 'xg',
                $sortParam => $key,
                $directionParam => ($xgSorts[$table] ?? '') === $key && ($xgDirections[$table] ?? 'desc') === 'desc' ? 'asc' : 'desc',
            ]
        ));
    };
    $xgSortArrow = function ($table, $key) use ($xgSorts, $xgDirections) {
        if (($xgSorts[$table] ?? '') !== $key) {
            return '';
        }

        return ($xgDirections[$table] ?? 'desc') === 'desc' ? '↓' : '↑';
    };
    $profileSortUrl = function ($table, $key) use ($profileSort, $profileDirection, $profileBucketSort, $profileBucketDirection) {
        $sortParam = $table === 'bucket' ? 'profile_bucket_sort' : 'profile_sort';
        $directionParam = $table === 'bucket' ? 'profile_bucket_direction' : 'profile_direction';
        $currentSort = $table === 'bucket' ? $profileBucketSort : $profileSort;
        $currentDirection = $table === 'bucket' ? $profileBucketDirection : $profileDirection;

        return route('admin.nhl-shot-attempts.index', array_merge(
            request()->except(['page', $sortParam, $directionParam]),
            [
                'tab' => 'player-profiles',
                $sortParam => $key,
                $directionParam => $currentSort === $key && $currentDirection === 'desc' ? 'asc' : 'desc',
            ]
        ));
    };
    $profileSortArrow = function ($table, $key) use ($profileSort, $profileDirection, $profileBucketSort, $profileBucketDirection) {
        $currentSort = $table === 'bucket' ? $profileBucketSort : $profileSort;
        $currentDirection = $table === 'bucket' ? $profileBucketDirection : $profileDirection;

        if ($currentSort !== $key) {
            return '';
        }

        return $currentDirection === 'desc' ? '↓' : '↑';
    };
    $goalieProfileSortUrl = function ($key) use ($goalieProfileSort, $goalieProfileDirection) {
        return route('admin.nhl-shot-attempts.index', array_merge(
            request()->except(['page', 'goalie_profile_sort', 'goalie_profile_direction']),
            [
                'tab' => 'g-sat-profiles',
                'goalie_profile_sort' => $key,
                'goalie_profile_direction' => $goalieProfileSort === $key && $goalieProfileDirection === 'desc' ? 'asc' : 'desc',
            ]
        ));
    };
    $goalieProfileSortArrow = function ($key) use ($goalieProfileSort, $goalieProfileDirection) {
        if ($goalieProfileSort !== $key) {
            return '';
        }

        return $goalieProfileDirection === 'desc' ? '↓' : '↑';
    };
    $skaterDProfileSortUrl = function ($key) use ($skaterDProfileSort, $skaterDProfileDirection) {
        return route('admin.nhl-shot-attempts.index', array_merge(
            request()->except(['page', 'skater_d_profile_sort', 'skater_d_profile_direction']),
            [
                'tab' => 'skater-d-profiles',
                'skater_d_profile_sort' => $key,
                'skater_d_profile_direction' => $skaterDProfileSort === $key && $skaterDProfileDirection === 'desc' ? 'asc' : 'desc',
            ]
        ));
    };
    $skaterDProfileSortArrow = function ($key) use ($skaterDProfileSort, $skaterDProfileDirection) {
        if ($skaterDProfileSort !== $key) {
            return '';
        }

        return $skaterDProfileDirection === 'desc' ? '↓' : '↑';
    };
    $skaterOProfileSortUrl = function ($key) use ($skaterOProfileSort, $skaterOProfileDirection) {
        return route('admin.nhl-shot-attempts.index', array_merge(
            request()->except(['page', 'skater_o_profile_sort', 'skater_o_profile_direction']),
            [
                'tab' => 'skater-o-profiles',
                'skater_o_profile_sort' => $key,
                'skater_o_profile_direction' => $skaterOProfileSort === $key && $skaterOProfileDirection === 'desc' ? 'asc' : 'desc',
            ]
        ));
    };
    $skaterOProfileSortArrow = function ($key) use ($skaterOProfileSort, $skaterOProfileDirection) {
        if ($skaterOProfileSort !== $key) {
            return '';
        }

        return $skaterOProfileDirection === 'desc' ? '↓' : '↑';
    };
    $xgAccordionState = fn ($key) => "{ key: 'dynastyiq:admin:nhl-shot-attempts:xg:accordion:{$key}', open: false, init() { try { this.open = JSON.parse(window.localStorage?.getItem(this.key) || 'false') === true } catch (e) { this.open = false } }, toggle() { this.open = !this.open; try { window.localStorage?.setItem(this.key, JSON.stringify(this.open)) } catch (e) {} } }";
    $projectionAccordionState = fn ($key) => "{ key: 'dynastyiq:admin:nhl-shot-attempts:projections:accordion:{$key}', open: false, init() { try { this.open = JSON.parse(window.localStorage?.getItem(this.key) || 'false') === true } catch (e) { this.open = false } }, toggle() { this.open = !this.open; try { window.localStorage?.setItem(this.key, JSON.stringify(this.open)) } catch (e) {} } }";
    $projectionSortUrl = function ($key) use ($projectionSort, $projectionDirection) {
        return route('admin.nhl-shot-attempts.index', array_merge(
            request()->except(['page', 'projection_sort', 'projection_direction']),
            [
                'tab' => 'projections',
                'projection_sort' => $key,
                'projection_direction' => $projectionSort === $key && $projectionDirection === 'desc' ? 'asc' : 'desc',
            ]
        ));
    };
    $projectionSortArrow = function ($key) use ($projectionSort, $projectionDirection) {
        if ($projectionSort !== $key) {
            return '';
        }

        return $projectionDirection === 'desc' ? '↓' : '↑';
    };
    $projectionBucketSortUrl = function ($key) use ($projectionBucketSort, $projectionBucketDirection) {
        return route('admin.nhl-shot-attempts.index', array_merge(
            request()->except(['page', 'projection_bucket_sort', 'projection_bucket_direction']),
            [
                'tab' => 'projections',
                'projection_bucket_sort' => $key,
                'projection_bucket_direction' => $projectionBucketSort === $key && $projectionBucketDirection === 'desc' ? 'asc' : 'desc',
            ]
        ));
    };
    $projectionBucketSortArrow = function ($key) use ($projectionBucketSort, $projectionBucketDirection) {
        if ($projectionBucketSort !== $key) {
            return '';
        }

        return $projectionBucketDirection === 'desc' ? '↓' : '↑';
    };
    $toiProjectionSortUrl = function ($key) use ($toiProjectionSort, $toiProjectionDirection) {
        return route('admin.nhl-shot-attempts.index', array_merge(
            request()->except(['page', 'toi_projection_sort', 'toi_projection_direction']),
            [
                'tab' => 'projections',
                'toi_projection_sort' => $key,
                'toi_projection_direction' => $toiProjectionSort === $key && $toiProjectionDirection === 'desc' ? 'asc' : 'desc',
            ]
        ));
    };
    $toiProjectionSortArrow = function ($key) use ($toiProjectionSort, $toiProjectionDirection) {
        if ($toiProjectionSort !== $key) {
            return '';
        }

        return $toiProjectionDirection === 'desc' ? '↓' : '↑';
    };
    $goalieWorkloadSortUrl = function ($key) use ($goalieWorkloadSort, $goalieWorkloadDirection) {
        return route('admin.nhl-shot-attempts.index', array_merge(
            request()->except(['page', 'goalie_workload_sort', 'goalie_workload_direction']),
            [
                'tab' => 'projections',
                'goalie_workload_sort' => $key,
                'goalie_workload_direction' => $goalieWorkloadSort === $key && $goalieWorkloadDirection === 'desc' ? 'asc' : 'desc',
            ]
        ));
    };
    $goalieWorkloadSortArrow = function ($key) use ($goalieWorkloadSort, $goalieWorkloadDirection) {
        if ($goalieWorkloadSort !== $key) {
            return '';
        }

        return $goalieWorkloadDirection === 'desc' ? '↓' : '↑';
    };
    $goalieProjectionSortUrl = function ($key) use ($goalieProjectionSort, $goalieProjectionDirection) {
        return route('admin.nhl-shot-attempts.index', array_merge(
            request()->except(['page', 'goalie_projection_sort', 'goalie_projection_direction']),
            [
                'tab' => 'projections',
                'goalie_projection_sort' => $key,
                'goalie_projection_direction' => $goalieProjectionSort === $key && $goalieProjectionDirection === 'desc' ? 'asc' : 'desc',
            ]
        ));
    };
    $goalieProjectionSortArrow = function ($key) use ($goalieProjectionSort, $goalieProjectionDirection) {
        if ($goalieProjectionSort !== $key) {
            return '';
        }

        return $goalieProjectionDirection === 'desc' ? '↓' : '↑';
    };
    $formatSeconds = function ($value) {
        if ($value === null) {
            return 'N/A';
        }

        $seconds = (int) round((float) $value);
        $prefix = $seconds < 0 ? '-' : '';
        $seconds = abs($seconds);

        return $prefix . sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    };
    $predictiveColumns = $predictiveGroups[$predictiveGroup]['columns'] ?? ['Dimension 1', 'Dimension 2', 'Dimension 3'];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-1">
            <h2 class="text-xl font-semibold leading-tight text-gray-900">NHL Shot Attempts</h2>
            <p class="text-sm text-gray-600">Review shot-attempt facts, grouped rates, bucket behavior, and QA coverage.</p>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            @unless($tableExists)
                <div class="mb-4 border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    The shot-attempt facts table is not available in this environment yet.
                </div>
            @endunless

            <div class="border border-gray-200 bg-white">
                <div class="border-b border-gray-200 px-5 py-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">Shot Attempt Facts</h3>
                            <p class="mt-1 text-sm text-gray-600">Use this panel to validate the derived facts before modeling shot quality.</p>
                        </div>
                        <a href="{{ route('admin.dashboard', ['tab' => 'game-imports']) }}" class="inline-flex min-h-9 items-center rounded-md border border-gray-300 px-3 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Game Imports
                        </a>
                    </div>
                </div>

                <div class="grid gap-3 border-b border-gray-200 px-5 py-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                    <div>
                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Attempts</div>
                        <div class="mt-1 text-lg font-semibold text-gray-900">{{ $formatNumber($summary['attempts']) }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Unblocked</div>
                        <div class="mt-1 text-lg font-semibold text-gray-900">{{ $formatNumber($summary['unblocked_attempts']) }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">SOG</div>
                        <div class="mt-1 text-lg font-semibold text-gray-900">{{ $formatNumber($summary['shots_on_goal']) }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Goals</div>
                        <div class="mt-1 text-lg font-semibold text-gray-900">{{ $formatNumber($summary['goals']) }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Goal Rate</div>
                        <div class="mt-1 text-lg font-semibold text-gray-900">{{ $summary['goal_rate'] === null ? 'N/A' : $formatDecimal($summary['goal_rate']) . '%' }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Rebounds</div>
                        <div class="mt-1 text-lg font-semibold text-gray-900">{{ $formatNumber($summary['rebounds']) }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Missing Geometry</div>
                        <div class="mt-1 text-lg font-semibold text-gray-900">{{ $formatNumber($summary['missing_geometry']) }}</div>
                    </div>
                </div>

                <form method="GET" action="{{ route('admin.nhl-shot-attempts.index') }}" class="border-b border-gray-200 px-5 py-4">
                    <input type="hidden" name="tab" value="{{ $activeTab }}">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8">
                        <label class="block">
                            <span class="text-xs font-medium text-gray-600">Season</span>
                            <select name="season_id" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">All</option>
                                @foreach($options['seasons'] as $season)
                                    <option value="{{ $season }}" @selected((string) $filters['season_id'] === (string) $season)>{{ $season }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-600">Start</span>
                            <input type="date" name="start_date" value="{{ $filters['start_date'] }}" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-600">End</span>
                            <input type="date" name="end_date" value="{{ $filters['end_date'] }}" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-600">Game Type</span>
                            <select name="game_type" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">All</option>
                                <option value="2" @selected((string) $filters['game_type'] === '2')>Regular</option>
                                <option value="3" @selected((string) $filters['game_type'] === '3')>Playoffs</option>
                                <option value="1" @selected((string) $filters['game_type'] === '1')>Preseason</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-600">Team ID</span>
                            <input type="number" name="team_id" value="{{ $filters['team_id'] }}" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-600">Strength</span>
                            <select name="strength_bucket" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">All</option>
                                @foreach($options['strengthBuckets'] as $option)
                                    <option value="{{ $option }}" @selected($filters['strength_bucket'] === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-600">Result</span>
                            <select name="attempt_result" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">All</option>
                                @foreach($options['attemptResults'] as $option)
                                    <option value="{{ $option }}" @selected($filters['attempt_result'] === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-600">Distance</span>
                            <select name="distance_bucket" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">All</option>
                                @foreach($options['distanceBuckets'] as $option)
                                    <option value="{{ $option }}" @selected($filters['distance_bucket'] === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-600">Angle</span>
                            <select name="angle_bucket" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">All</option>
                                @foreach($options['angleBuckets'] as $option)
                                    <option value="{{ $option }}" @selected($filters['angle_bucket'] === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-600">Shot Type</span>
                            <select name="shot_type_bucket" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">All</option>
                                @foreach($options['shotTypeBuckets'] as $option)
                                    <option value="{{ $option }}" @selected($filters['shot_type_bucket'] === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-600">Goalie Side</span>
                            <select name="shot_side" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">All</option>
                                @foreach($options['shotSides'] as $option)
                                    <option value="{{ $option }}" @selected($filters['shot_side'] === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-600">Off-Wing</span>
                            <select name="is_off_wing_attempt" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">All</option>
                                <option value="1" @selected($filters['is_off_wing_attempt'] === '1')>Off-Wing</option>
                                <option value="0" @selected($filters['is_off_wing_attempt'] === '0')>Strong-Side</option>
                            </select>
                        </label>
                    </div>
                    <div class="mt-4 flex flex-wrap justify-end gap-2">
                        <a href="{{ route('admin.nhl-shot-attempts.index', ['tab' => $activeTab]) }}" class="inline-flex min-h-10 items-center rounded-md border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Clear</a>
                        <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Apply</button>
                    </div>
                </form>

                <div class="border-b border-gray-200 px-5">
                    <nav class="-mb-px flex flex-wrap gap-5" aria-label="Shot attempt tabs">
                        @foreach($tabs as $tab => $label)
                            <a href="{{ $tabUrl($tab) }}" class="border-b-2 px-1 py-3 text-sm font-semibold {{ $activeTab === $tab ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </nav>
                </div>

                <div class="overflow-x-auto">
                    @if($activeTab === 'explorer')
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    @foreach([
                                        'game' => 'Game',
                                        'time' => 'Time',
                                        'team_id' => 'Team',
                                        'shooter_player_id' => 'Shooter',
                                        'goalie_player_id' => 'Goalie',
                                        'attempt_result' => 'Result',
                                        'shot_distance' => 'Distance',
                                        'abs_shot_angle' => 'Angle',
                                        'shot_side' => 'Side',
                                        'context' => 'Context',
                                    ] as $sortKey => $label)
                                        <th class="px-4 py-3">
                                            <a href="{{ $sortUrl($sortKey, 'explorer') }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                <span>{{ $label }}</span>
                                                @if($sortArrow($sortKey) !== '')
                                                    <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $sortArrow($sortKey) }}</span>
                                                @endif
                                            </a>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @forelse($explorerRows as $row)
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-900">{{ $row->nhl_game_id }}<div class="text-xs text-gray-500">{{ $row->game_date }}</div></td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">P{{ $row->period ?? 'N/A' }}<div class="text-xs text-gray-500">{{ $row->seconds_in_game ?? 'N/A' }}s</div></td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->team_id ?? 'N/A' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->shooter_player_id ?? 'N/A' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->goalie_player_id ?? 'N/A' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->attempt_result }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->shot_distance) }}<div class="text-xs text-gray-500">{{ $row->distance_bucket ?? 'N/A' }}</div></td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->abs_shot_angle, 1) }}<div class="text-xs text-gray-500">{{ $row->angle_bucket ?? 'N/A' }}</div></td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->shot_side ?? 'N/A' }}<div class="text-xs text-gray-500">{{ $formatOffWing($row->is_off_wing_attempt) }}</div></td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->strength_bucket ?? 'N/A' }}<div class="text-xs text-gray-500">{{ $row->is_rebound ? 'rebound' : 'no rebound' }} · {{ $row->is_rush ? 'rush' : 'settled' }}</div></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="10" class="px-4 py-8 text-center text-sm text-gray-500">No shot attempts match these filters.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                        <div class="border-t border-gray-200 px-4 py-3">
                            {{ $explorerRows->links() }}
                        </div>
                    @elseif($activeTab === 'aggregates')
                        <form method="GET" action="{{ route('admin.nhl-shot-attempts.index') }}" class="border-b border-gray-200 px-4 py-3">
                            @foreach(request()->except(['group_by', 'page']) as $key => $value)
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endforeach
                            <input type="hidden" name="tab" value="aggregates">
                            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                Group by
                                <select name="group_by" class="min-h-9 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    @foreach($groupLabels as $key => $label)
                                        <option value="{{ $key }}" @selected($groupBy === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="inline-flex min-h-9 items-center rounded-md bg-indigo-600 px-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Apply</button>
                            </label>
                        </form>
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    @foreach([
                                        'group_value' => $groupLabels[$groupBy] ?? 'Group',
                                        'attempts' => 'Attempts',
                                        'unblocked_attempts' => 'Unblocked',
                                        'shots_on_goal' => 'SOG',
                                        'sog_rate' => 'SOG %',
                                        'goals' => 'Goals',
                                        'goal_rate' => 'Goal Rate',
                                        'avg_distance' => 'Avg Dist',
                                        'avg_angle' => 'Avg Angle',
                                    ] as $sortKey => $label)
                                        <th class="px-4 py-3">
                                            <a href="{{ $sortUrl($sortKey, 'aggregates') }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                <span>{{ $label }}</span>
                                                @if($sortArrow($sortKey) !== '')
                                                    <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $sortArrow($sortKey) }}</span>
                                                @endif
                                            </a>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @forelse($aggregateRows as $row)
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900">{{ $row->group_value ?? 'N/A' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->attempts) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->unblocked_attempts) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->shots_on_goal) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->sog_rate) }}{{ $row->sog_rate === null ? '' : '%' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->goals) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->goal_rate) }}{{ $row->goal_rate === null ? '' : '%' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->avg_distance) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->avg_angle, 1) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="9" class="px-4 py-8 text-center text-sm text-gray-500">No aggregate rows match these filters.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    @elseif($activeTab === 'buckets')
                        <div class="border-b border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600">
                            Analysis buckets roll sparse raw combinations into broader parent groups. Only buckets with at least 300 SAT are shown.
                        </div>
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    @foreach([
                                        'fallback_level' => 'Level',
                                        'shot_type_group' => 'Shot Type',
                                        'distance_group' => 'Distance',
                                        'angle_group' => 'Angle',
                                        'sequence_group' => 'Sequence',
                                        'attempts' => 'Attempts',
                                        'shots_on_goal' => 'SOG',
                                        'sog_rate' => 'SOG %',
                                        'goals' => 'Goals',
                                        'goal_rate' => 'Goal Rate',
                                        'avg_distance' => 'Avg Dist',
                                        'avg_angle' => 'Avg Angle',
                                    ] as $sortKey => $label)
                                        <th class="px-4 py-3">
                                            <a href="{{ $sortUrl($sortKey, 'buckets') }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                <span>{{ $label }}</span>
                                                @if($sortArrow($sortKey) !== '')
                                                    <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $sortArrow($sortKey) }}</span>
                                                @endif
                                            </a>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @forelse($bucketRows as $row)
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900">L{{ str_pad((string) $row->fallback_level, 2, '0', STR_PAD_LEFT) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->shot_type_group ?? 'Any' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->distance_group ?? 'Any' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->angle_group ?? 'Any' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->sequence_group ?? 'Any' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->attempts) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->shots_on_goal) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->sog_rate) }}{{ $row->sog_rate === null ? '' : '%' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->goals) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->goal_rate) }}{{ $row->goal_rate === null ? '' : '%' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->avg_distance) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->avg_angle, 1) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="12" class="px-4 py-8 text-center text-sm text-gray-500">No analysis buckets meet the 300-SAT floor for these filters.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    @elseif($activeTab === 'predictive')
                        <form method="GET" action="{{ route('admin.nhl-shot-attempts.index') }}" class="border-b border-gray-200 px-4 py-3">
                            @foreach(request()->except(['predictive_group', 'min_attempts', 'page']) as $key => $value)
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endforeach
                            <input type="hidden" name="tab" value="predictive">
                            <div class="flex flex-wrap items-end gap-3">
                                <label class="block text-sm text-gray-700">
                                    <span class="text-xs font-medium text-gray-600">View</span>
                                    <select name="predictive_group" class="mt-1 block min-h-9 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        @foreach($predictiveGroups as $key => $definition)
                                            <option value="{{ $key }}" @selected($predictiveGroup === $key)>{{ $definition['label'] }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="block text-sm text-gray-700">
                                    <span class="text-xs font-medium text-gray-600">Minimum Attempts</span>
                                    <input type="number" min="1" max="10000" name="min_attempts" value="{{ $minAttempts }}" class="mt-1 block min-h-9 w-32 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </label>
                                <button type="submit" class="inline-flex min-h-9 items-center rounded-md bg-indigo-600 px-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Apply</button>
                            </div>
                        </form>
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    @foreach([
                                        'dimension_1' => $predictiveColumns[0] ?: 'Dimension 1',
                                        'dimension_2' => $predictiveColumns[1] ?: 'Dimension 2',
                                        'dimension_3' => $predictiveColumns[2] ?: 'Dimension 3',
                                        'attempts' => 'Attempts',
                                        'shots_on_goal' => 'SOG',
                                        'sog_rate' => 'SOG %',
                                        'goals' => 'Goals',
                                        'goal_rate' => 'Goal Rate',
                                        'shooting_rate' => 'Shooting %',
                                        'avg_distance' => 'Avg Dist',
                                        'avg_angle' => 'Avg Angle',
                                    ] as $sortKey => $label)
                                        <th class="px-4 py-3">
                                            <a href="{{ $sortUrl($sortKey, 'predictive') }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                <span>{{ $label }}</span>
                                                @if($sortArrow($sortKey) !== '')
                                                    <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $sortArrow($sortKey) }}</span>
                                                @endif
                                            </a>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @forelse($predictiveRows as $row)
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900">{{ $row->dimension_1 ?? 'N/A' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->dimension_2 ?? 'N/A' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->dimension_3 ?? 'N/A' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->attempts) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->shots_on_goal) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->sog_rate) }}{{ $row->sog_rate === null ? '' : '%' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->goals) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->goal_rate) }}{{ $row->goal_rate === null ? '' : '%' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->shooting_rate) }}{{ $row->shooting_rate === null ? '' : '%' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->avg_distance) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->avg_angle, 1) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="11" class="px-4 py-8 text-center text-sm text-gray-500">No predictive rows meet the current filters and minimum attempt threshold.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    @elseif($activeTab === 'biometrics')
                        <form method="GET" action="{{ route('admin.nhl-shot-attempts.index') }}" class="border-b border-gray-200 px-4 py-3">
                            @foreach(request()->except(['biometric_min_attempts', 'page']) as $key => $value)
                                @if(is_scalar($value))
                                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                @endif
                            @endforeach
                            <input type="hidden" name="tab" value="biometrics">
                            <div class="flex flex-wrap items-end justify-end gap-3">
                                <label class="block">
                                    <span class="text-xs font-medium text-gray-600">Min Attempts</span>
                                    <input type="number" name="biometric_min_attempts" min="1" max="10000" value="{{ $biometricMinAttempts }}" class="mt-1 block min-h-10 w-36 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </label>
                                <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Apply</button>
                            </div>
                        </form>
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    @foreach([
                                        'profile' => 'Profile',
                                        'bucket' => 'Bucket',
                                        'context_1' => 'Shot / Weight',
                                        'context_2' => 'Distance',
                                        'context_3' => 'Angle',
                                        'attempts' => 'Attempts',
                                        'shots_on_goal' => 'SOG',
                                        'sog_rate' => 'SOG %',
                                        'goals' => 'Goals',
                                        'goal_rate' => 'Goal Rate',
                                        'avg_xg_per_sat' => 'Avg xG/SAT',
                                        'goals_minus_xg' => 'Goals - xG',
                                        'avg_distance' => 'Avg Dist',
                                        'avg_angle' => 'Avg Angle',
                                    ] as $sortKey => $label)
                                        <th class="px-4 py-3">
                                            <a href="{{ $sortUrl($sortKey, 'biometrics') }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                <span>{{ $label }}</span>
                                                @if($sortArrow($sortKey) !== '')
                                                    <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $sortArrow($sortKey) }}</span>
                                                @endif
                                            </a>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @forelse($biometricRows as $row)
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900">{{ $row->profile }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->bucket }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->context_1 ?? 'N/A' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->context_2 ?? 'N/A' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->context_3 ?? 'N/A' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->attempts) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->shots_on_goal) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->sog_rate) }}{{ $row->sog_rate === null ? '' : '%' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->goals) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->goal_rate) }}{{ $row->goal_rate === null ? '' : '%' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->avg_xg_per_sat === null ? 'N/A' : $formatDecimal(((float) $row->avg_xg_per_sat) * 100) . '%' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->goals_minus_xg === null ? 'N/A' : $formatDecimal($row->goals_minus_xg) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->avg_distance) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->avg_angle, 1) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="14" class="px-4 py-8 text-center text-sm text-gray-500">No biometric rows are available. Run the biometric migration and rebuild shot facts for the selected range.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    @elseif($activeTab === 'player-profiles')
                        <form method="GET" action="{{ route('admin.nhl-shot-attempts.index') }}" class="border-b border-gray-200 px-4 py-3">
                            @foreach(request()->except(['player_search', 'position', 'profile_min_attempts', 'profile_sort', 'profile_direction', 'profile_bucket_sort', 'profile_bucket_direction', 'page']) as $key => $value)
                                @if(is_scalar($value))
                                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                @endif
                            @endforeach
                            <input type="hidden" name="tab" value="player-profiles">
                            <div class="flex flex-wrap items-end gap-3">
                                <label class="block">
                                    <span class="text-xs font-medium text-gray-600">Player</span>
                                    <input type="search" name="player_search" value="{{ $filters['player_search'] }}" placeholder="Search name or NHL ID" class="mt-1 block min-h-10 w-56 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </label>
                                <label class="block">
                                    <span class="text-xs font-medium text-gray-600">Position</span>
                                    <select name="position" class="mt-1 block min-h-10 w-36 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        <option value="">All</option>
                                        @foreach($options['positions'] as $option)
                                            <option value="{{ $option }}" @selected($filters['position'] === $option)>{{ $option }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="block">
                                    <span class="text-xs font-medium text-gray-600">Min Attempts</span>
                                    <input type="number" name="profile_min_attempts" min="1" max="10000" value="{{ $profileMinAttempts }}" class="mt-1 block min-h-10 w-36 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </label>
                                <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Apply</button>
                            </div>
                        </form>

                        <div class="border-b border-gray-200">
                            <div class="bg-gray-50 px-4 py-3">
                                <h4 class="text-sm font-semibold text-gray-900">Player Shot Profile Summary</h4>
                            </div>
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    <tr>
                                        @foreach([
                                            'player_name' => 'Player',
                                            'team_abbrev' => 'Team',
                                            'position' => 'Pos',
                                            'attempts' => 'SAT',
                                            'shots_on_goal' => 'SOG',
                                            'sog_rate' => 'SOG %',
                                            'goals' => 'Goals',
                                            'goal_rate' => 'Goal Rate',
                                            'xg' => 'xG',
                                            'xsog' => 'xSOG',
                                            'xg_per_sat' => 'xG/SAT',
                                            'xsog_per_sat' => 'xSOG/SAT',
                                            'avg_distance' => 'Avg Dist',
                                            'avg_angle' => 'Avg Angle',
                                        ] as $sortKey => $label)
                                            <th class="px-4 py-3">
                                                <a href="{{ $profileSortUrl('summary', $sortKey) }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                    <span>{{ $label }}</span>
                                                    @if($profileSortArrow('summary', $sortKey) !== '')
                                                        <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $profileSortArrow('summary', $sortKey) }}</span>
                                                    @endif
                                                </a>
                                            </th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    @forelse($playerProfileRows as $row)
                                        <tr>
                                            <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900">
                                                {{ $row->player_name }}
                                                <div class="text-xs font-normal text-gray-500">NHL {{ $row->nhl_player_id }}</div>
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->team_abbrev ?? 'N/A' }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->position ?? 'N/A' }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->attempts) }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->shots_on_goal) }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->sog_rate) }}{{ $row->sog_rate === null ? '' : '%' }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->goals) }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->goal_rate) }}{{ $row->goal_rate === null ? '' : '%' }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->xg === null ? 'N/A' : $formatDecimal($row->xg) }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->xsog === null ? 'N/A' : $formatDecimal($row->xsog) }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->xg_per_sat === null ? 'N/A' : $formatDecimal($row->xg_per_sat) . '%' }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->xsog_per_sat === null ? 'N/A' : $formatDecimal($row->xsog_per_sat) . '%' }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->avg_distance) }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->avg_angle, 1) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="14" class="px-4 py-8 text-center text-sm text-gray-500">No player profile rows match these filters.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        <div>
                            <div class="bg-gray-50 px-4 py-3">
                                <h4 class="text-sm font-semibold text-gray-900">Selected Player Bucket Profiles</h4>
                            </div>
                            @if(trim((string) $filters['player_search']) === '')
                                <div class="px-4 py-8 text-center text-sm text-gray-500">Search a player to view resolved model bucket quantities.</div>
                            @else
                                <table class="w-full table-fixed divide-y divide-gray-200 text-[10px] leading-tight">
                                    <thead class="bg-gray-50 text-left text-[9px] font-semibold uppercase text-gray-500">
                                        <tr>
                                            @foreach([
                                                'player_name' => 'Player',
                                                'team_abbrev' => 'Team',
                                                'fallback_level' => 'Level',
                                                'shot_type_group' => 'Shot Type',
                                                'distance_group' => 'Distance',
                                                'angle_group' => 'Angle',
                                                'sequence_group' => 'Sequence',
                                                'attempts' => 'SAT',
                                                'shots_on_goal' => 'SOG',
                                                'sog_rate' => 'SOG %',
                                                'goals' => 'Goals',
                                                'goal_rate' => 'Goal Rate',
                                                'xg' => 'xG',
                                                'xsog' => 'xSOG',
                                            ] as $sortKey => $label)
                                                <th class="px-4 py-3">
                                                    <a href="{{ $profileSortUrl('bucket', $sortKey) }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                        <span>{{ $label }}</span>
                                                        @if($profileSortArrow('bucket', $sortKey) !== '')
                                                            <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $profileSortArrow('bucket', $sortKey) }}</span>
                                                        @endif
                                                    </a>
                                                </th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 bg-white">
                                        @forelse($playerProfileBucketRows as $row)
                                            <tr>
                                                <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900">{{ $row->player_name }}</td>
                                                <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->team_abbrev ?? 'N/A' }}</td>
                                                <td class="whitespace-nowrap px-4 py-3 text-gray-700">L{{ str_pad((string) $row->fallback_level, 2, '0', STR_PAD_LEFT) }}</td>
                                                <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->shot_type_group ?? 'Any' }}</td>
                                                <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->distance_group ?? 'Any' }}</td>
                                                <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->angle_group ?? 'Any' }}</td>
                                                <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->sequence_group ?? 'Any' }}</td>
                                                <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->attempts) }}</td>
                                                <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->shots_on_goal) }}</td>
                                                <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->sog_rate) }}{{ $row->sog_rate === null ? '' : '%' }}</td>
                                                <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->goals) }}</td>
                                                <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->goal_rate) }}{{ $row->goal_rate === null ? '' : '%' }}</td>
                                                <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->xg === null ? 'N/A' : $formatDecimal($row->xg) }}</td>
                                                <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->xsog === null ? 'N/A' : $formatDecimal($row->xsog) }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="14" class="px-4 py-8 text-center text-sm text-gray-500">No resolved profile buckets meet the current minimum attempt threshold.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            @endif
                        </div>
                    @elseif($activeTab === 'skater-o-profiles')
                        <div class="space-y-4 p-4">
                            @if(session('status'))
                                <div class="border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                                    {{ session('status') }}
                                </div>
                            @endif
                            @if(session('error'))
                                <div class="border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                                    {{ session('error') }}
                                </div>
                            @endif

                            @unless($skaterOProfileTableExists)
                                <div class="border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                    The skater offensive profile table is not available in this environment yet. Run the profile migration first.
                                </div>
                            @else
                                <div class="flex flex-wrap items-end justify-between gap-4 border-b border-gray-200 pb-4">
                                    <div>
                                        <h4 class="text-sm font-semibold text-gray-900">Skater O Profiles</h4>
                                        <p class="mt-1 text-sm text-gray-600">Review historical SATF chance mix by granular shrunk bucket.</p>
                                    </div>
                                    <form method="POST" action="{{ route('admin.nhl-shot-attempts.skater-offensive-chance-profiles.build') }}" class="flex flex-wrap items-end gap-3">
                                        @csrf
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Season</span>
                                            <input type="text" name="source_season_id" value="{{ $skaterOProfileFilters['season_id'] ?: ($filters['season_id'] ?: '20252026') }}" class="mt-1 block min-h-9 w-28 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Game Type</span>
                                            <input type="number" name="game_type" min="1" max="99" value="{{ $skaterOProfileFilters['game_type'] ?: 2 }}" class="mt-1 block min-h-9 w-24 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </label>
                                        <button type="submit" class="inline-flex min-h-9 items-center rounded-md bg-slate-900 px-4 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                                            Build Profiles
                                        </button>
                                    </form>
                                </div>

                                <form method="GET" action="{{ route('admin.nhl-shot-attempts.index') }}" class="border-b border-gray-200 pb-4">
                                    @foreach(request()->except([
                                        'skater_o_profile_season_id',
                                        'skater_o_profile_game_type',
                                        'skater_o_profile_team_abbrev',
                                        'skater_o_profile_position',
                                        'skater_o_profile_player_search',
                                        'skater_o_profile_shot_type_group',
                                        'skater_o_profile_distance_group',
                                        'skater_o_profile_angle_group',
                                        'skater_o_profile_sequence_group',
                                        'skater_o_profile_min_sat_for',
                                        'page',
                                    ]) as $key => $value)
                                        @if(is_scalar($value))
                                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                        @endif
                                    @endforeach
                                    <input type="hidden" name="tab" value="skater-o-profiles">
                                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-10">
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Season</span>
                                            <select name="skater_o_profile_season_id" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($skaterOProfileOptions['seasons'] as $season)
                                                    <option value="{{ $season }}" @selected((string) $skaterOProfileFilters['season_id'] === (string) $season)>{{ $season }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Game Type</span>
                                            <select name="skater_o_profile_game_type" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($skaterOProfileOptions['gameTypes'] as $gameType)
                                                    <option value="{{ $gameType }}" @selected((string) $skaterOProfileFilters['game_type'] === (string) $gameType)>{{ $gameType }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Team</span>
                                            <select name="skater_o_profile_team_abbrev" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($skaterOProfileOptions['teams'] as $team)
                                                    <option value="{{ $team }}" @selected((string) $skaterOProfileFilters['team_abbrev'] === (string) $team)>{{ $team }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Pos</span>
                                            <select name="skater_o_profile_position" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($skaterOProfileOptions['positions'] as $position)
                                                    <option value="{{ $position }}" @selected((string) $skaterOProfileFilters['position'] === (string) $position)>{{ $position }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Player</span>
                                            <input type="search" name="skater_o_profile_player_search" value="{{ $skaterOProfileFilters['player_search'] }}" placeholder="Name or NHL ID" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Shot Type</span>
                                            <select name="skater_o_profile_shot_type_group" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($skaterOProfileOptions['shotTypes'] as $shotType)
                                                    <option value="{{ $shotType }}" @selected((string) $skaterOProfileFilters['shot_type_group'] === (string) $shotType)>{{ $shotType }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Distance</span>
                                            <select name="skater_o_profile_distance_group" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($skaterOProfileOptions['distances'] as $distance)
                                                    <option value="{{ $distance }}" @selected((string) $skaterOProfileFilters['distance_group'] === (string) $distance)>{{ $distance }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Angle</span>
                                            <select name="skater_o_profile_angle_group" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($skaterOProfileOptions['angles'] as $angle)
                                                    <option value="{{ $angle }}" @selected((string) $skaterOProfileFilters['angle_group'] === (string) $angle)>{{ $angle }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Sequence</span>
                                            <select name="skater_o_profile_sequence_group" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($skaterOProfileOptions['sequences'] as $sequence)
                                                    <option value="{{ $sequence }}" @selected((string) $skaterOProfileFilters['sequence_group'] === (string) $sequence)>{{ $sequence }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Min SATF</span>
                                            <input type="number" name="skater_o_profile_min_sat_for" min="1" max="10000" value="{{ $skaterOProfileFilters['min_sat_for'] }}" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </label>
                                    </div>
                                    <div class="mt-4 flex flex-wrap justify-end gap-2">
                                        <a href="{{ route('admin.nhl-shot-attempts.index', ['tab' => 'skater-o-profiles']) }}" class="inline-flex min-h-10 items-center rounded-md border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Clear</a>
                                        <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Apply</button>
                                    </div>
                                </form>

                                <div class="overflow-x-auto">
                                    <table class="w-full table-fixed divide-y divide-gray-200 text-[10px] leading-tight">
                                        <thead class="bg-gray-50 text-left text-[9px] font-semibold uppercase text-gray-500">
                                            <tr>
                                                @foreach([
                                                    'player_name' => 'Player',
                                                    'source_season_id' => 'Season',
                                                    'game_type' => 'GT',
                                                    'team_abbrev' => 'Team',
                                                    'position' => 'Pos',
                                                    'fallback_level' => 'Lvl',
                                                    'shot_type_group' => 'Type',
                                                    'distance_group' => 'Dist',
                                                    'angle_group' => 'Angle',
                                                    'sequence_group' => 'Seq',
                                                    'source_toi_seconds' => 'TOI',
                                                    'source_sat_for' => 'SATF',
                                                    'source_sog_for' => 'SOGF',
                                                    'source_goals_for' => 'GF',
                                                    'source_xgf' => 'xGF',
                                                    'source_xsog' => 'xSOG',
                                                    'source_xgf_per_60' => 'xGF/60',
                                                    'source_xsog_per_60' => 'xSOG/60',
                                                    'source_profile_share' => 'Share',
                                                    'goal_probability' => 'G Prob',
                                                    'shot_on_goal_probability' => 'SOG Prob',
                                                    'confidence_score' => 'Conf',
                                                ] as $sortKey => $label)
                                                    <th class="px-1.5 py-2 first:w-44">
                                                        <a href="{{ $skaterOProfileSortUrl($sortKey) }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                            <span>{{ $label }}</span>
                                                            @if($skaterOProfileSortArrow($sortKey) !== '')
                                                                <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $skaterOProfileSortArrow($sortKey) }}</span>
                                                            @endif
                                                        </a>
                                                    </th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 bg-white">
                                            @forelse($skaterOProfileRows as $row)
                                                <tr>
                                                    <td class="truncate px-1.5 py-2 font-medium text-gray-900" title="{{ $row->player_name }}">
                                                        <span class="block truncate">{{ $row->player_name }}</span>
                                                        <span class="block truncate text-[9px] font-normal text-gray-500">NHL {{ $row->player_id }}</span>
                                                    </td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->source_season_id }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->game_type }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->team_abbrev ?? 'N/A' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->position ?? 'N/A' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">L{{ str_pad((string) $row->fallback_level, 2, '0', STR_PAD_LEFT) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700" title="{{ $row->shot_type_group ?? 'Any' }}">{{ $row->shot_type_group ?? 'Any' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700" title="{{ $row->distance_group ?? 'Any' }}">{{ $row->distance_group ?? 'Any' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700" title="{{ $row->angle_group ?? 'Any' }}">{{ $row->angle_group ?? 'Any' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700" title="{{ $row->sequence_group ?? 'Any' }}">{{ $row->sequence_group ?? 'Any' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatSeconds($row->source_toi_seconds) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_sat_for) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_sog_for) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_goals_for) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_xgf) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_xsog) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_xgf_per_60) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_xsog_per_60) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->source_profile_share === null ? 'N/A' : $formatDecimal(((float) $row->source_profile_share) * 100) . '%' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->goal_probability === null ? 'N/A' : $formatDecimal(((float) $row->goal_probability) * 100) . '%' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->shot_on_goal_probability === null ? 'N/A' : $formatDecimal(((float) $row->shot_on_goal_probability) * 100) . '%' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">
                                                        {{ $row->confidence_score === null ? 'N/A' : $formatDecimal(((float) $row->confidence_score) * 100, 0) . '%' }}
                                                        <div class="truncate text-[9px] text-gray-500">{{ $row->confidence_bucket ?? 'unscored' }}</div>
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="22" class="px-2 py-6 text-center text-xs text-gray-500">No skater O profile rows match these filters.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            @endunless
                        </div>
                    @elseif($activeTab === 'g-sat-profiles')
                        <div class="space-y-4 p-4">
                            @if(session('status'))
                                <div class="border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                                    {{ session('status') }}
                                </div>
                            @endif
                            @if(session('error'))
                                <div class="border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                                    {{ session('error') }}
                                </div>
                            @endif

                            @unless($goalieProfileTableExists)
                                <div class="border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                    The goalie SAT profile table is not available in this environment yet. Run the profile migration first.
                                </div>
                            @else
                                <div class="flex flex-wrap items-end justify-between gap-4 border-b border-gray-200 pb-4">
                                    <div>
                                        <h4 class="text-sm font-semibold text-gray-900">Goalie SAT Profiles</h4>
                                        <p class="mt-1 text-sm text-gray-600">Review historical goalie chance mix and performance over expected by resolved SAT bucket.</p>
                                    </div>
                                    <form method="POST" action="{{ route('admin.nhl-shot-attempts.goalie-chance-profiles.build') }}" class="flex flex-wrap items-end gap-3">
                                        @csrf
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Season</span>
                                            <input type="text" name="source_season_id" value="{{ $goalieProfileFilters['season_id'] ?: ($filters['season_id'] ?: '20252026') }}" class="mt-1 block min-h-9 w-28 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Game Type</span>
                                            <input type="number" name="game_type" min="1" max="99" value="{{ $goalieProfileFilters['game_type'] ?: 2 }}" class="mt-1 block min-h-9 w-24 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </label>
                                        <button type="submit" class="inline-flex min-h-9 items-center rounded-md bg-slate-900 px-4 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                                            Build Profiles
                                        </button>
                                    </form>
                                </div>

                                <form method="GET" action="{{ route('admin.nhl-shot-attempts.index') }}" class="border-b border-gray-200 pb-4">
                                    @foreach(request()->except([
                                        'goalie_profile_season_id',
                                        'goalie_profile_game_type',
                                        'goalie_profile_team_abbrev',
                                        'goalie_profile_goalie_search',
                                        'goalie_profile_shot_type_group',
                                        'goalie_profile_distance_group',
                                        'goalie_profile_angle_group',
                                        'goalie_profile_sequence_group',
                                        'goalie_profile_min_sat_against',
                                        'page',
                                    ]) as $key => $value)
                                        @if(is_scalar($value))
                                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                        @endif
                                    @endforeach
                                    <input type="hidden" name="tab" value="g-sat-profiles">
                                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-9">
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Season</span>
                                            <select name="goalie_profile_season_id" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($goalieProfileOptions['seasons'] as $season)
                                                    <option value="{{ $season }}" @selected((string) $goalieProfileFilters['season_id'] === (string) $season)>{{ $season }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Game Type</span>
                                            <select name="goalie_profile_game_type" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($goalieProfileOptions['gameTypes'] as $gameType)
                                                    <option value="{{ $gameType }}" @selected((string) $goalieProfileFilters['game_type'] === (string) $gameType)>{{ $gameType }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Team</span>
                                            <select name="goalie_profile_team_abbrev" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($goalieProfileOptions['teams'] as $team)
                                                    <option value="{{ $team }}" @selected((string) $goalieProfileFilters['team_abbrev'] === (string) $team)>{{ $team }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Player</span>
                                            <input type="search" name="goalie_profile_goalie_search" value="{{ $goalieProfileFilters['goalie_search'] }}" placeholder="Name or NHL ID" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Shot Type</span>
                                            <select name="goalie_profile_shot_type_group" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($goalieProfileOptions['shotTypes'] as $shotType)
                                                    <option value="{{ $shotType }}" @selected((string) $goalieProfileFilters['shot_type_group'] === (string) $shotType)>{{ $shotType }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Distance</span>
                                            <select name="goalie_profile_distance_group" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($goalieProfileOptions['distances'] as $distance)
                                                    <option value="{{ $distance }}" @selected((string) $goalieProfileFilters['distance_group'] === (string) $distance)>{{ $distance }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Angle</span>
                                            <select name="goalie_profile_angle_group" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($goalieProfileOptions['angles'] as $angle)
                                                    <option value="{{ $angle }}" @selected((string) $goalieProfileFilters['angle_group'] === (string) $angle)>{{ $angle }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Sequence</span>
                                            <select name="goalie_profile_sequence_group" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($goalieProfileOptions['sequences'] as $sequence)
                                                    <option value="{{ $sequence }}" @selected((string) $goalieProfileFilters['sequence_group'] === (string) $sequence)>{{ $sequence }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Min Bucket SATA</span>
                                            <input type="number" name="goalie_profile_min_sat_against" min="1" max="10000" value="{{ $goalieProfileFilters['min_sat_against'] }}" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </label>
                                    </div>
                                    <div class="mt-4 flex flex-wrap justify-end gap-2">
                                        <a href="{{ route('admin.nhl-shot-attempts.index', ['tab' => 'g-sat-profiles']) }}" class="inline-flex min-h-10 items-center rounded-md border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Clear</a>
                                        <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Apply</button>
                                    </div>
                                </form>

                                <div class="overflow-x-auto">
                                    <table class="w-full table-fixed divide-y divide-gray-200 text-[10px] leading-tight">
                                        <thead class="bg-gray-50 text-left text-[9px] font-semibold uppercase text-gray-500">
                                            <tr>
                                                @foreach([
                                                    'goalie_name' => 'Goalie',
                                                    'source_season_id' => 'Season',
                                                    'game_type' => 'GT',
                                                    'team_abbrev' => 'Team',
                                                    'fallback_level' => 'Lvl',
                                                    'shot_type_group' => 'Type',
                                                    'distance_group' => 'Dist',
                                                    'angle_group' => 'Angle',
                                                    'sequence_group' => 'Seq',
                                                    'source_games' => 'GP',
                                                    'source_sat_against' => 'SATA',
                                                    'source_sog_against' => 'SOGA',
                                                    'source_goals_against' => 'GA',
                                                    'source_xga' => 'xGA',
                                                    'source_xsoga' => 'xSOGA',
                                                    'source_xga_per_60' => 'xGA/60',
                                                    'source_xsoga_per_60' => 'xSOGA/60',
                                                    'source_gsax' => 'GSAx',
                                                    'source_gsax_per_100_sat_against' => 'GSAx/100',
                                                    'source_profile_share' => 'Share',
                                                    'goal_probability_against' => 'G Prob',
                                                    'shot_on_goal_probability_against' => 'SOG Prob',
                                                    'confidence_score' => 'Conf',
                                                ] as $sortKey => $label)
                                                    <th class="px-1.5 py-2 first:w-44">
                                                        <a href="{{ $goalieProfileSortUrl($sortKey) }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                            <span>{{ $label }}</span>
                                                            @if($goalieProfileSortArrow($sortKey) !== '')
                                                                <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $goalieProfileSortArrow($sortKey) }}</span>
                                                            @endif
                                                        </a>
                                                    </th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 bg-white">
                                            @forelse($goalieProfileRows as $row)
                                                <tr>
                                                    <td class="truncate px-1.5 py-2 font-medium text-gray-900" title="{{ $row->goalie_name }}">
                                                        <span class="block truncate">{{ $row->goalie_name }}</span>
                                                        <span class="block truncate text-[9px] font-normal text-gray-500">NHL {{ $row->goalie_player_id }}</span>
                                                    </td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->source_season_id }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->game_type }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->team_abbrev ?? 'N/A' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">L{{ str_pad((string) $row->fallback_level, 2, '0', STR_PAD_LEFT) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700" title="{{ $row->shot_type_group ?? 'Any' }}">{{ $row->shot_type_group ?? 'Any' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700" title="{{ $row->distance_group ?? 'Any' }}">{{ $row->distance_group ?? 'Any' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700" title="{{ $row->angle_group ?? 'Any' }}">{{ $row->angle_group ?? 'Any' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700" title="{{ $row->sequence_group ?? 'Any' }}">{{ $row->sequence_group ?? 'Any' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_games, 0) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_sat_against) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_sog_against) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_goals_against) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_xga) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_xsoga) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_xga_per_60) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_xsoga_per_60) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_gsax) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_gsax_per_100_sat_against) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->source_profile_share === null ? 'N/A' : $formatDecimal(((float) $row->source_profile_share) * 100) . '%' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->goal_probability_against === null ? 'N/A' : $formatDecimal(((float) $row->goal_probability_against) * 100) . '%' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->shot_on_goal_probability_against === null ? 'N/A' : $formatDecimal(((float) $row->shot_on_goal_probability_against) * 100) . '%' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">
                                                        {{ $row->confidence_score === null ? 'N/A' : $formatDecimal(((float) $row->confidence_score) * 100, 0) . '%' }}
                                                        <div class="truncate text-[9px] text-gray-500">{{ $row->confidence_bucket ?? 'unscored' }}</div>
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="23" class="px-2 py-6 text-center text-xs text-gray-500">No goalie SAT profile rows match these filters.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            @endunless
                        </div>
                    @elseif($activeTab === 'skater-d-profiles')
                        <div class="space-y-4 p-4">
                            @if(session('status'))
                                <div class="border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                                    {{ session('status') }}
                                </div>
                            @endif
                            @if(session('error'))
                                <div class="border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                                    {{ session('error') }}
                                </div>
                            @endif

                            @unless($skaterDProfileTableExists)
                                <div class="border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                    The skater defensive profile table is not available in this environment yet. Run the profile migration first.
                                </div>
                            @else
                                <div class="flex flex-wrap items-end justify-between gap-4 border-b border-gray-200 pb-4">
                                    <div>
                                        <h4 class="text-sm font-semibold text-gray-900">Skater D Profiles</h4>
                                        <p class="mt-1 text-sm text-gray-600">Review historical on-ice defensive chance mix by resolved SAT bucket.</p>
                                    </div>
                                    <form method="POST" action="{{ route('admin.nhl-shot-attempts.skater-defensive-chance-profiles.build') }}" class="flex flex-wrap items-end gap-3">
                                        @csrf
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Season</span>
                                            <input type="text" name="source_season_id" value="{{ $skaterDProfileFilters['season_id'] ?: ($filters['season_id'] ?: '20252026') }}" class="mt-1 block min-h-9 w-28 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Game Type</span>
                                            <input type="number" name="game_type" min="1" max="99" value="{{ $skaterDProfileFilters['game_type'] ?: 2 }}" class="mt-1 block min-h-9 w-24 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </label>
                                        <button type="submit" class="inline-flex min-h-9 items-center rounded-md bg-slate-900 px-4 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                                            Build Profiles
                                        </button>
                                    </form>
                                </div>

                                <form method="GET" action="{{ route('admin.nhl-shot-attempts.index') }}" class="border-b border-gray-200 pb-4">
                                    @foreach(request()->except([
                                        'skater_d_profile_season_id',
                                        'skater_d_profile_game_type',
                                        'skater_d_profile_team_abbrev',
                                        'skater_d_profile_position',
                                        'skater_d_profile_player_search',
                                        'skater_d_profile_shot_type_group',
                                        'skater_d_profile_distance_group',
                                        'skater_d_profile_angle_group',
                                        'skater_d_profile_sequence_group',
                                        'skater_d_profile_min_sat_against',
                                        'page',
                                    ]) as $key => $value)
                                        @if(is_scalar($value))
                                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                        @endif
                                    @endforeach
                                    <input type="hidden" name="tab" value="skater-d-profiles">
                                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-10">
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Season</span>
                                            <select name="skater_d_profile_season_id" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($skaterDProfileOptions['seasons'] as $season)
                                                    <option value="{{ $season }}" @selected((string) $skaterDProfileFilters['season_id'] === (string) $season)>{{ $season }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Game Type</span>
                                            <select name="skater_d_profile_game_type" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($skaterDProfileOptions['gameTypes'] as $gameType)
                                                    <option value="{{ $gameType }}" @selected((string) $skaterDProfileFilters['game_type'] === (string) $gameType)>{{ $gameType }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Team</span>
                                            <select name="skater_d_profile_team_abbrev" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($skaterDProfileOptions['teams'] as $team)
                                                    <option value="{{ $team }}" @selected((string) $skaterDProfileFilters['team_abbrev'] === (string) $team)>{{ $team }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Pos</span>
                                            <select name="skater_d_profile_position" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($skaterDProfileOptions['positions'] as $position)
                                                    <option value="{{ $position }}" @selected((string) $skaterDProfileFilters['position'] === (string) $position)>{{ $position }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Player</span>
                                            <input type="search" name="skater_d_profile_player_search" value="{{ $skaterDProfileFilters['player_search'] }}" placeholder="Name or NHL ID" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Shot Type</span>
                                            <select name="skater_d_profile_shot_type_group" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($skaterDProfileOptions['shotTypes'] as $shotType)
                                                    <option value="{{ $shotType }}" @selected((string) $skaterDProfileFilters['shot_type_group'] === (string) $shotType)>{{ $shotType }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Distance</span>
                                            <select name="skater_d_profile_distance_group" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($skaterDProfileOptions['distances'] as $distance)
                                                    <option value="{{ $distance }}" @selected((string) $skaterDProfileFilters['distance_group'] === (string) $distance)>{{ $distance }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Angle</span>
                                            <select name="skater_d_profile_angle_group" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($skaterDProfileOptions['angles'] as $angle)
                                                    <option value="{{ $angle }}" @selected((string) $skaterDProfileFilters['angle_group'] === (string) $angle)>{{ $angle }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Sequence</span>
                                            <select name="skater_d_profile_sequence_group" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($skaterDProfileOptions['sequences'] as $sequence)
                                                    <option value="{{ $sequence }}" @selected((string) $skaterDProfileFilters['sequence_group'] === (string) $sequence)>{{ $sequence }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Min SATA</span>
                                            <input type="number" name="skater_d_profile_min_sat_against" min="1" max="10000" value="{{ $skaterDProfileFilters['min_sat_against'] }}" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </label>
                                    </div>
                                    <div class="mt-4 flex flex-wrap justify-end gap-2">
                                        <a href="{{ route('admin.nhl-shot-attempts.index', ['tab' => 'skater-d-profiles']) }}" class="inline-flex min-h-10 items-center rounded-md border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Clear</a>
                                        <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Apply</button>
                                    </div>
                                </form>

                                <div class="overflow-x-auto">
                                    <table class="w-full table-fixed divide-y divide-gray-200 text-[10px] leading-tight">
                                        <thead class="bg-gray-50 text-left text-[9px] font-semibold uppercase text-gray-500">
                                            <tr>
                                                @foreach([
                                                    'player_name' => 'Player',
                                                    'source_season_id' => 'Season',
                                                    'game_type' => 'GT',
                                                    'team_abbrev' => 'Team',
                                                    'position' => 'Pos',
                                                    'fallback_level' => 'Lvl',
                                                    'shot_type_group' => 'Type',
                                                    'distance_group' => 'Dist',
                                                    'angle_group' => 'Angle',
                                                    'sequence_group' => 'Seq',
                                                    'source_toi_seconds' => 'TOI',
                                                    'source_sat_against_on_ice' => 'SATA',
                                                    'source_sog_against_on_ice' => 'SOGA',
                                                    'source_goals_against_on_ice' => 'GA',
                                                    'source_xga_on_ice' => 'xGA',
                                                    'source_xsoga_on_ice' => 'xSOGA',
                                                    'source_xga_per_60' => 'xGA/60',
                                                    'source_xsoga_per_60' => 'xSOGA/60',
                                                    'source_profile_share_against' => 'Share',
                                                    'goal_probability_against' => 'G Prob',
                                                    'shot_on_goal_probability_against' => 'SOG Prob',
                                                    'confidence_score' => 'Conf',
                                                ] as $sortKey => $label)
                                                    <th class="px-1.5 py-2 first:w-44">
                                                        <a href="{{ $skaterDProfileSortUrl($sortKey) }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                            <span>{{ $label }}</span>
                                                            @if($skaterDProfileSortArrow($sortKey) !== '')
                                                                <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $skaterDProfileSortArrow($sortKey) }}</span>
                                                            @endif
                                                        </a>
                                                    </th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 bg-white">
                                            @forelse($skaterDProfileRows as $row)
                                                <tr>
                                                    <td class="truncate px-1.5 py-2 font-medium text-gray-900" title="{{ $row->player_name }}">
                                                        <span class="block truncate">{{ $row->player_name }}</span>
                                                        <span class="block truncate text-[9px] font-normal text-gray-500">NHL {{ $row->player_id }}</span>
                                                    </td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->source_season_id }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->game_type }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->team_abbrev ?? 'N/A' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->position ?? 'N/A' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">L{{ str_pad((string) $row->fallback_level, 2, '0', STR_PAD_LEFT) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700" title="{{ $row->shot_type_group ?? 'Any' }}">{{ $row->shot_type_group ?? 'Any' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700" title="{{ $row->distance_group ?? 'Any' }}">{{ $row->distance_group ?? 'Any' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700" title="{{ $row->angle_group ?? 'Any' }}">{{ $row->angle_group ?? 'Any' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700" title="{{ $row->sequence_group ?? 'Any' }}">{{ $row->sequence_group ?? 'Any' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatSeconds($row->source_toi_seconds) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_sat_against_on_ice) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_sog_against_on_ice) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_goals_against_on_ice) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_xga_on_ice) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_xsoga_on_ice) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_xga_per_60) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_xsoga_per_60) }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->source_profile_share_against === null ? 'N/A' : $formatDecimal(((float) $row->source_profile_share_against) * 100) . '%' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->goal_probability_against === null ? 'N/A' : $formatDecimal(((float) $row->goal_probability_against) * 100) . '%' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->shot_on_goal_probability_against === null ? 'N/A' : $formatDecimal(((float) $row->shot_on_goal_probability_against) * 100) . '%' }}</td>
                                                    <td class="truncate px-1.5 py-2 text-gray-700">
                                                        {{ $row->confidence_score === null ? 'N/A' : $formatDecimal(((float) $row->confidence_score) * 100, 0) . '%' }}
                                                        <div class="truncate text-[9px] text-gray-500">{{ $row->confidence_bucket ?? 'unscored' }}</div>
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="22" class="px-2 py-6 text-center text-xs text-gray-500">No skater D profile rows match these filters.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            @endunless
                        </div>
                    @elseif($activeTab === 'xg')
                        <div class="space-y-5 p-4">
                            @if(session('status'))
                                <div class="border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                                    {{ session('status') }}
                                </div>
                            @endif
                            @if(session('error'))
                                <div class="border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                                    {{ session('error') }}
                                </div>
                            @endif

                            @unless($xgTableExists)
                                <div class="border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                    The xG tables are not available in this environment yet.
                                </div>
                            @else
                                <div class="flex flex-wrap items-end justify-between gap-4 border border-gray-200 bg-gray-50 p-4">
                                    <div>
                                        <h4 class="text-sm font-semibold text-gray-900">Expected Goals Model</h4>
                                        <p class="mt-1 text-sm text-gray-600">Build bucket-smoothed xG predictions from the current shot-attempt facts.</p>
                                    </div>
                                    <form method="POST" action="{{ route('admin.nhl-shot-attempts.xg.build') }}" class="flex flex-wrap items-end gap-3">
                                        @csrf
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Season</span>
                                            <input type="text" name="season_id" value="{{ $filters['season_id'] ?: '20252026' }}" class="mt-1 block min-h-9 w-28 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Version</span>
                                            <input type="text" name="version" value="bucket_smoothed_xg_v1" class="mt-1 block min-h-9 w-52 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Min Attempts</span>
                                            <input type="number" min="1" max="10000" name="minimum_bucket_attempts" value="300" class="mt-1 block min-h-9 w-28 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Prior</span>
                                            <input type="number" min="0" max="10000" name="smoothing_prior_attempts" value="100" class="mt-1 block min-h-9 w-24 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </label>
                                        <button type="submit" class="inline-flex min-h-9 items-center rounded-md bg-indigo-600 px-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Build Shot Models</button>
                                    </form>
                                </div>

                                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8">
                                    @foreach([
                                        'Models' => $formatNumber($xgSummary['model_count']),
                                        'Latest' => $xgSummary['latest_version'] ?? 'N/A',
                                        'Target' => $xgSummary['latest_target'] ?? 'N/A',
                                        'Status' => $xgSummary['latest_status'] ?? 'N/A',
                                        'Season' => $xgSummary['training_season_id'] ?? 'N/A',
                                        'Buckets' => $formatNumber($xgSummary['bucket_count']),
                                        'Predictions' => $formatNumber($xgSummary['prediction_count']),
                                        'Excluded' => $formatNumber($xgSummary['excluded_count']),
                                        'Total xG' => $formatDecimal($xgSummary['total_xg']),
                                    ] as $label => $value)
                                        <div class="border border-gray-200 bg-white px-3 py-3">
                                            <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">{{ $label }}</div>
                                            <div class="mt-1 text-base font-semibold text-gray-900">{{ $value }}</div>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="border border-gray-200" x-data="{!! $xgAccordionState('models') !!}">
                                    <button type="button" class="flex min-h-11 w-full items-center justify-between border-b border-gray-200 bg-gray-50 px-4 py-3 text-left" @click="toggle()" :aria-expanded="open.toString()">
                                        <span class="text-sm font-semibold text-gray-900">Models</span>
                                        <span class="text-sm font-semibold text-gray-500" aria-hidden="true" x-text="open ? '↑' : '↓'"></span>
                                    </button>
                                    <table x-show="open" x-cloak class="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                            <tr>
                                                @foreach([
                                                    'version' => 'Version',
                                                    'prediction_target' => 'Target',
                                                    'training_season_id' => 'Season',
                                                    'status' => 'Status',
                                                    'bucket_count' => 'Buckets',
                                                    'prediction_count' => 'Predictions',
                                                    'scored_count' => 'Scored',
                                                    'excluded_count' => 'Excluded',
                                                    'total_xg' => 'Total',
                                                    'trained_at' => 'Trained',
                                                ] as $sortKey => $label)
                                                    <th class="px-4 py-3">
                                                        <a href="{{ $xgSortUrl('model', $sortKey) }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                            <span>{{ $label }}</span>
                                                            @if($xgSortArrow('model', $sortKey) !== '')
                                                                <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $xgSortArrow('model', $sortKey) }}</span>
                                                            @endif
                                                        </a>
                                                    </th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 bg-white">
                                            @forelse($xgModelRows as $row)
                                                <tr>
                                                    <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900">{{ $row->version }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->prediction_target }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->training_season_id }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->status }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->bucket_count) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->prediction_count) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->scored_count) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->excluded_count) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->total_xg) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->trained_at ?? 'N/A' }}</td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="10" class="px-4 py-8 text-center text-sm text-gray-500">No shot outcome models have been built for the current filters.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>

                                <div class="border border-gray-200" x-data="{!! $xgAccordionState('latest-model-buckets') !!}">
                                    <button type="button" class="flex min-h-11 w-full items-center justify-between border-b border-gray-200 bg-gray-50 px-4 py-3 text-left" @click="toggle()" :aria-expanded="open.toString()">
                                        <span class="text-sm font-semibold text-gray-900">Latest Model Buckets</span>
                                        <span class="text-sm font-semibold text-gray-500" aria-hidden="true" x-text="open ? '↑' : '↓'"></span>
                                    </button>
                                    <table x-show="open" x-cloak class="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                            <tr>
                                                @foreach([
                                                    'bucket_key' => 'Bucket',
                                                    'fallback_level' => 'Level',
                                                    'attempts' => 'Attempts',
                                                    'goals' => 'Goals',
                                                    'raw_goal_rate' => 'Raw Goal %',
                                                    'smoothed_goal_probability' => 'xG Prob',
                                                ] as $sortKey => $label)
                                                    <th class="px-4 py-3">
                                                        <a href="{{ $xgSortUrl('bucket', $sortKey) }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                            <span>{{ $label }}</span>
                                                            @if($xgSortArrow('bucket', $sortKey) !== '')
                                                                <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $xgSortArrow('bucket', $sortKey) }}</span>
                                                            @endif
                                                        </a>
                                                    </th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 bg-white">
                                            @forelse($xgBucketRows as $row)
                                                @php
                                                    $dimensions = json_decode((string) $row->bucket_dimensions, true) ?: [];
                                                    $dimensionLabel = collect($dimensions)->map(fn ($value, $key) => $key . ': ' . $value)->implode(' · ');
                                                @endphp
                                                <tr>
                                                    <td class="min-w-80 px-4 py-3 font-medium text-gray-900">{{ $dimensionLabel ?: $row->bucket_key }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->fallback_level }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->attempts) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->goals) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal(((float) $row->raw_goal_rate) * 100) }}%</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal(((float) $row->smoothed_goal_probability) * 100) }}%</td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">No buckets exist for the latest xG model.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>

                                <div class="border border-gray-200" x-data="{!! $xgAccordionState('team-xg-xga') !!}">
                                    <button type="button" class="flex min-h-11 w-full items-center justify-between border-b border-gray-200 bg-gray-50 px-4 py-3 text-left" @click="toggle()" :aria-expanded="open.toString()">
                                        <span class="text-sm font-semibold text-gray-900">Team xG / xGA</span>
                                        <span class="text-sm font-semibold text-gray-500" aria-hidden="true" x-text="open ? '↑' : '↓'"></span>
                                    </button>
                                    <table x-show="open" x-cloak class="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                            <tr>
                                                @foreach([
                                                    'team_abbrev' => 'Team',
                                                    'attempts_for' => 'Attempts For',
                                                    'xg_for' => 'xG',
                                                    'goals_for' => 'Goals',
                                                    'attempts_against' => 'Attempts Against',
                                                    'xg_against' => 'xGA',
                                                    'goals_against' => 'GA',
                                                    'goal_diff' => 'Goal Diff',
                                                    'xg_diff' => 'xG Diff',
                                                ] as $sortKey => $label)
                                                    <th class="px-4 py-3">
                                                        <a href="{{ $xgSortUrl('team', $sortKey) }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                            <span>{{ $label }}</span>
                                                            @if($xgSortArrow('team', $sortKey) !== '')
                                                                <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $xgSortArrow('team', $sortKey) }}</span>
                                                            @endif
                                                        </a>
                                                    </th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 bg-white">
                                            @forelse($xgTeamRows as $row)
                                                <tr>
                                                    <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900">{{ $row->team_abbrev }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->attempts_for) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->xg_for) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->goals_for) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->attempts_against) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->xg_against) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->goals_against) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->goal_diff) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->xg_diff) }}</td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="9" class="px-4 py-8 text-center text-sm text-gray-500">No scored xG predictions exist for the latest model.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>

                                <div class="border border-gray-200" x-data="{!! $xgAccordionState('shooter-ixg-xsog') !!}">
                                    <button type="button" class="flex min-h-11 w-full items-center justify-between border-b border-gray-200 bg-gray-50 px-4 py-3 text-left" @click="toggle()" :aria-expanded="open.toString()">
                                        <span class="text-sm font-semibold text-gray-900">Shooter ixG / xSOG</span>
                                        <span class="text-sm font-semibold text-gray-500" aria-hidden="true" x-text="open ? '↑' : '↓'"></span>
                                    </button>
                                    <table x-show="open" x-cloak class="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                            <tr>
                                                @foreach([
                                                    'shooter_name' => 'Shooter',
                                                    'team_abbrev' => 'Team',
                                                    'sat' => 'SAT',
                                                    'sog' => 'SOG',
                                                    'goals' => 'Goals',
                                                    'ixg' => 'ixG',
                                                    'xsog' => 'xSOG',
                                                    'xg_per_sat' => 'xG/SAT',
                                                    'xsog_per_sat' => 'xSOG/SAT',
                                                    'sog_minus_xsog' => 'SOG - xSOG',
                                                    'goals_minus_ixg' => 'Goals - ixG',
                                                ] as $sortKey => $label)
                                                    <th class="px-4 py-3">
                                                        <a href="{{ $xgSortUrl('shooter', $sortKey) }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                            <span>{{ $label }}</span>
                                                            @if($xgSortArrow('shooter', $sortKey) !== '')
                                                                <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $xgSortArrow('shooter', $sortKey) }}</span>
                                                            @endif
                                                        </a>
                                                    </th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 bg-white">
                                            @forelse($xgShooterRows as $row)
                                                <tr>
                                                    <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900">{{ $row->shooter_name }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->team_abbrev }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->sat) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->sog) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->goals) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->ixg) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->xsog) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal(((float) $row->xg_per_sat) * 100) }}%</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal(((float) $row->xsog_per_sat) * 100) }}%</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->sog_minus_xsog) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->goals_minus_ixg) }}</td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="11" class="px-4 py-8 text-center text-sm text-gray-500">Build both goal and shot-on-goal models to review shooter rows.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>

                                <div class="border border-gray-200" x-data="{!! $xgAccordionState('rolling-shooter-trend') !!}">
                                    <button type="button" class="flex min-h-11 w-full items-center justify-between border-b border-gray-200 bg-gray-50 px-4 py-3 text-left" @click="toggle()" :aria-expanded="open.toString()">
                                        <span class="text-sm font-semibold text-gray-900">Rolling Shooter Trend</span>
                                        <span class="text-sm font-semibold text-gray-500" aria-hidden="true" x-text="open ? '↑' : '↓'"></span>
                                    </button>
                                    <table x-show="open" x-cloak class="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                            <tr>
                                                @foreach([
                                                    'shooter_name' => 'Shooter',
                                                    'team_abbrev' => 'Team',
                                                    'sat_5' => 'SAT 5',
                                                    'xg_per_sat_5' => 'xG/SAT 5',
                                                    'xsog_per_sat_5' => 'xSOG/SAT 5',
                                                    'sat_10' => 'SAT 10',
                                                    'xg_per_sat_10' => 'xG/SAT 10',
                                                    'xsog_per_sat_10' => 'xSOG/SAT 10',
                                                    'sat_20' => 'SAT 20',
                                                    'xg_per_sat_20' => 'xG/SAT 20',
                                                    'xsog_per_sat_20' => 'xSOG/SAT 20',
                                                    'xg_per_sat_delta' => 'xG/SAT Δ',
                                                    'xsog_per_sat_delta' => 'xSOG/SAT Δ',
                                                ] as $sortKey => $label)
                                                    <th class="px-4 py-3">
                                                        <a href="{{ $xgSortUrl('trend', $sortKey) }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                            <span>{{ $label }}</span>
                                                            @if($xgSortArrow('trend', $sortKey) !== '')
                                                                <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $xgSortArrow('trend', $sortKey) }}</span>
                                                            @endif
                                                        </a>
                                                    </th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 bg-white">
                                            @forelse($xgTrendRows as $row)
                                                <tr>
                                                    <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900">{{ $row->shooter_name }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->team_abbrev }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->sat_5) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal(((float) $row->xg_per_sat_5) * 100) }}%</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal(((float) $row->xsog_per_sat_5) * 100) }}%</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->sat_10) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal(((float) $row->xg_per_sat_10) * 100) }}%</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal(((float) $row->xsog_per_sat_10) * 100) }}%</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->sat_20) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal(((float) $row->xg_per_sat_20) * 100) }}%</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal(((float) $row->xsog_per_sat_20) * 100) }}%</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal(((float) $row->xg_per_sat_delta) * 100) }} pts</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal(((float) $row->xsog_per_sat_delta) * 100) }} pts</td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="13" class="px-4 py-8 text-center text-sm text-gray-500">No rolling trend rows are available for the current models.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>

                                <div class="border border-gray-200" x-data="{!! $xgAccordionState('recent-profile-shift') !!}">
                                    <button type="button" class="flex min-h-11 w-full items-center justify-between border-b border-gray-200 bg-gray-50 px-4 py-3 text-left" @click="toggle()" :aria-expanded="open.toString()">
                                        <span class="text-sm font-semibold text-gray-900">Recent Profile Shift</span>
                                        <span class="text-sm font-semibold text-gray-500" aria-hidden="true" x-text="open ? '↑' : '↓'"></span>
                                    </button>
                                    <table x-show="open" x-cloak class="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                            <tr>
                                                @foreach([
                                                    'shooter_name' => 'Shooter',
                                                    'team_abbrev' => 'Team',
                                                    'season_sat' => 'Season SAT',
                                                    'recent_sat' => 'Recent SAT',
                                                    'recent_xg_per_sat' => 'Recent xG/SAT',
                                                    'recent_xsog_per_sat' => 'Recent xSOG/SAT',
                                                    'avg_distance_delta' => 'Dist Δ',
                                                    'avg_angle_delta' => 'Angle Δ',
                                                    'rush_rate_delta' => 'Rush Δ',
                                                    'rebound_rate_delta' => 'Rebound Δ',
                                                    'offwing_rate_delta' => 'Off-Wing Δ',
                                                ] as $sortKey => $label)
                                                    <th class="px-4 py-3">
                                                        <a href="{{ $xgSortUrl('profile', $sortKey) }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                            <span>{{ $label }}</span>
                                                            @if($xgSortArrow('profile', $sortKey) !== '')
                                                                <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $xgSortArrow('profile', $sortKey) }}</span>
                                                            @endif
                                                        </a>
                                                    </th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 bg-white">
                                            @forelse($xgProfileRows as $row)
                                                <tr>
                                                    <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900">{{ $row->shooter_name }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->team_abbrev }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->season_sat) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->recent_sat) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal(((float) $row->recent_xg_per_sat) * 100) }}%</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal(((float) $row->recent_xsog_per_sat) * 100) }}%</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->avg_distance_delta) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->avg_angle_delta, 1) }}</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal(((float) $row->rush_rate_delta) * 100) }} pts</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal(((float) $row->rebound_rate_delta) * 100) }} pts</td>
                                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal(((float) $row->offwing_rate_delta) * 100) }} pts</td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="11" class="px-4 py-8 text-center text-sm text-gray-500">No recent profile shift rows are available for the current models.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    @elseif($activeTab === 'projections')
                        <div class="space-y-4 p-4">
                            @if(session('status'))
                                <div class="border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                                    {{ session('status') }}
                                </div>
                            @endif
                            @if(session('error'))
                                <div class="border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                                    {{ session('error') }}
                                </div>
                            @endif
                            @if(!$projectionTablesExist && !$toiProjectionTableExists && !$goalieWorkloadProjectionTableExists && !$goalieProjectionTablesExist)
                                <div class="border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                    The projection tables are not available in this environment yet. Run the projection migrations first.
                                </div>
                            @else
                                <div class="flex flex-wrap items-end justify-between gap-4 border-b border-gray-200 pb-4">
                                    <div>
                                        <h4 class="text-sm font-semibold text-gray-900">Player Projections</h4>
                                        <p class="mt-1 text-sm text-gray-600">Build TOI opportunity projections first, then review skater performance projection rollups.</p>
                                    </div>
                                    <div class="flex flex-wrap items-end gap-3">
                                        @if($toiProjectionTableExists)
                                            <form method="POST" action="{{ route('admin.nhl-shot-attempts.toi-projections.build') }}" class="flex flex-wrap items-end gap-3">
                                                @csrf
                                                <label class="block">
                                                    <span class="text-xs font-medium text-gray-600">Source</span>
                                                    <input type="text" name="source_season_id" value="{{ $projectionFilters['source_season_id'] ?: '20252026' }}" class="mt-1 block min-h-9 w-28 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                </label>
                                                <label class="block">
                                                    <span class="text-xs font-medium text-gray-600">Target</span>
                                                    <input type="text" name="target_season_id" value="{{ $projectionFilters['target_season_id'] ?: '20262027' }}" class="mt-1 block min-h-9 w-28 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                </label>
                                                <label class="block">
                                                    <span class="text-xs font-medium text-gray-600">TOI Version</span>
                                                    <input type="text" name="version" value="{{ $projectionFilters['projection_version'] ?: 'first_pass_toi_20262027_v1' }}" class="mt-1 block min-h-9 w-56 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                </label>
                                                <button type="submit" class="inline-flex min-h-9 items-center rounded-md bg-slate-900 px-4 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                                                    Build TOI
                                                </button>
                                            </form>
                                        @endif
                                        @if($goalieWorkloadProjectionTableExists)
                                            <form method="POST" action="{{ route('admin.nhl-shot-attempts.goalie-workload-projections.build') }}" class="flex flex-wrap items-end gap-3">
                                                @csrf
                                                <label class="block">
                                                    <span class="text-xs font-medium text-gray-600">Source</span>
                                                    <input type="text" name="source_season_id" value="{{ $projectionFilters['source_season_id'] ?: '20252026' }}" class="mt-1 block min-h-9 w-28 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                </label>
                                                <label class="block">
                                                    <span class="text-xs font-medium text-gray-600">Target</span>
                                                    <input type="text" name="target_season_id" value="{{ $projectionFilters['target_season_id'] ?: '20262027' }}" class="mt-1 block min-h-9 w-28 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                </label>
                                                <label class="block">
                                                    <span class="text-xs font-medium text-gray-600">Workload Version</span>
                                                    <input type="text" name="version" value="{{ 'first_pass_goalie_workload_' . ($projectionFilters['target_season_id'] ?: '20262027') . '_v1' }}" class="mt-1 block min-h-9 w-64 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                </label>
                                                <button type="submit" class="inline-flex min-h-9 items-center rounded-md bg-emerald-700 px-4 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800">
                                                    Build G Workload
                                                </button>
                                            </form>
                                        @endif
                                        @if($goalieProjectionBuildReady)
                                            <form method="POST" action="{{ route('admin.nhl-shot-attempts.goalie-projections.build') }}" class="flex flex-wrap items-end gap-3">
                                                @csrf
                                                <label class="block">
                                                    <span class="text-xs font-medium text-gray-600">Source</span>
                                                    <input type="text" name="source_season_id" value="{{ $projectionFilters['source_season_id'] ?: '20252026' }}" class="mt-1 block min-h-9 w-28 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                </label>
                                                <label class="block">
                                                    <span class="text-xs font-medium text-gray-600">Target</span>
                                                    <input type="text" name="target_season_id" value="{{ $projectionFilters['target_season_id'] ?: '20262027' }}" class="mt-1 block min-h-9 w-28 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                </label>
                                                <label class="block">
                                                    <span class="text-xs font-medium text-gray-600">Workload Version</span>
                                                    <input type="text" name="goalie_workload_projection_version" value="{{ 'first_pass_goalie_workload_' . ($projectionFilters['target_season_id'] ?: '20262027') . '_v1' }}" class="mt-1 block min-h-9 w-64 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                </label>
                                                <label class="block">
                                                    <span class="text-xs font-medium text-gray-600">TOI Version</span>
                                                    <input type="text" name="toi_projection_version" value="{{ 'first_pass_toi_' . ($projectionFilters['target_season_id'] ?: '20262027') . '_v1' }}" class="mt-1 block min-h-9 w-56 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                </label>
                                                <label class="block">
                                                    <span class="text-xs font-medium text-gray-600">G Projection Version</span>
                                                    <input type="text" name="version" value="{{ 'first_pass_goalie_' . ($projectionFilters['target_season_id'] ?: '20262027') . '_v1' }}" class="mt-1 block min-h-9 w-56 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                </label>
                                                <button type="submit" class="inline-flex min-h-9 items-center rounded-md bg-cyan-700 px-4 text-sm font-semibold text-white shadow-sm hover:bg-cyan-800">
                                                    Build G Projections
                                                </button>
                                            </form>
                                        @endif
                                        @if($projectionTablesExist)
                                            <form method="POST" action="{{ route('admin.nhl-shot-attempts.projections.build') }}" class="flex flex-wrap items-end gap-3">
                                                @csrf
                                                <label class="block">
                                                    <span class="text-xs font-medium text-gray-600">Source</span>
                                                    <input type="text" name="source_season_id" value="{{ $projectionFilters['source_season_id'] ?: '20252026' }}" class="mt-1 block min-h-9 w-28 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                </label>
                                                <label class="block">
                                                    <span class="text-xs font-medium text-gray-600">Target</span>
                                                    <input type="text" name="target_season_id" value="{{ $projectionFilters['target_season_id'] ?: '20262027' }}" class="mt-1 block min-h-9 w-28 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                </label>
                                                <label class="block">
                                                    <span class="text-xs font-medium text-gray-600">Skater Version</span>
                                                    <input type="text" name="version" value="{{ $projectionFilters['projection_version'] ?: 'first_pass_20262027_v1' }}" class="mt-1 block min-h-9 w-52 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                </label>
                                                <button type="submit" class="inline-flex min-h-9 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">
                                                    Build Skaters
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>

                                <form method="GET" action="{{ route('admin.nhl-shot-attempts.index') }}" class="border-b border-gray-200 pb-4">
                                    @foreach(request()->except([
                                        'projection_source_season_id',
                                        'projection_target_season_id',
                                        'projection_version',
                                        'projection_status',
                                        'projection_team_abbrev',
                                        'projection_position',
                                        'projection_player_search',
                                        'projection_min_xsat',
                                        'page',
                                    ]) as $key => $value)
                                        @if(is_scalar($value))
                                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                        @endif
                                    @endforeach
                                    <input type="hidden" name="tab" value="projections">
                                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8">
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Source Season</span>
                                            <select name="projection_source_season_id" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($projectionOptions['sourceSeasons'] as $season)
                                                    <option value="{{ $season }}" @selected((string) $projectionFilters['source_season_id'] === (string) $season)>{{ $season }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Target Season</span>
                                            <select name="projection_target_season_id" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($projectionOptions['targetSeasons'] as $season)
                                                    <option value="{{ $season }}" @selected((string) $projectionFilters['target_season_id'] === (string) $season)>{{ $season }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Version</span>
                                            <select name="projection_version" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($projectionOptions['versions'] as $version)
                                                    <option value="{{ $version }}" @selected((string) $projectionFilters['projection_version'] === (string) $version)>{{ $version }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Status</span>
                                            <select name="projection_status" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($projectionOptions['statuses'] as $status)
                                                    <option value="{{ $status }}" @selected((string) $projectionFilters['status'] === (string) $status)>{{ $status }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Team</span>
                                            <select name="projection_team_abbrev" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($projectionOptions['teams'] as $team)
                                                    <option value="{{ $team }}" @selected((string) $projectionFilters['team_abbrev'] === (string) $team)>{{ $team }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Position</span>
                                            <select name="projection_position" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">All</option>
                                                @foreach($projectionOptions['positions'] as $position)
                                                    <option value="{{ $position }}" @selected((string) $projectionFilters['position'] === (string) $position)>{{ $position }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Player</span>
                                            <input type="search" name="projection_player_search" value="{{ $projectionFilters['player_search'] }}" placeholder="Name or NHL ID" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">Min xSAT</span>
                                            <input type="number" name="projection_min_xsat" min="0" max="10000" step="1" value="{{ $projectionFilters['min_xsat'] }}" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </label>
                                    </div>
                                    <div class="mt-4 flex flex-wrap justify-end gap-2">
                                        <a href="{{ route('admin.nhl-shot-attempts.index', ['tab' => 'projections']) }}" class="inline-flex min-h-10 items-center rounded-md border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Clear</a>
                                        <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Apply</button>
                                    </div>
                                </form>

                                @if($toiProjectionTableExists)
                                    <div x-data="{{ $projectionAccordionState('toi') }}" class="border border-gray-200">
                                        <button type="button" class="flex w-full items-center justify-between gap-4 px-4 py-3 text-left hover:bg-gray-50" x-on:click="toggle()" x-bind:aria-expanded="open.toString()" aria-controls="toi-projections-panel">
                                            <span>
                                                <span class="block text-sm font-semibold text-gray-900">TOI Projections</span>
                                                <span class="mt-1 block text-sm text-gray-600">Review projected opportunity before using it in skater performance projections.</span>
                                            </span>
                                            <span class="text-lg leading-none text-gray-500 transition-transform duration-300 ease-out motion-reduce:transition-none" x-bind:class="{ 'rotate-180': open }" aria-hidden="true">⌄</span>
                                        </button>
                                        <div id="toi-projections-panel" x-cloak x-show="open" class="border-t border-gray-200">
                                        <div class="overflow-x-auto">
                                            <table class="w-full table-fixed divide-y divide-gray-200 text-[11px] leading-tight">
                                                <thead class="bg-gray-50 text-left text-[10px] font-semibold uppercase tracking-wide text-gray-500">
                                                    <tr>
                                                        @foreach([
                                                            'player_name' => 'Player',
                                                            'source_team_abbrev' => 'Src',
                                                            'target_team_abbrev' => 'Team',
                                                            'position' => 'Pos',
                                                            'age_years' => 'Age',
                                                            'source_games' => 'GP',
                                                            'source_toi_per_game_seconds' => 'Src TOI',
                                                            'source_points' => 'Pts',
                                                            'source_team_points_rank' => 'Src Rk',
                                                            'source_role_bucket' => 'Src Role',
                                                            'target_team_points_rank' => 'Rank',
                                                            'target_role_bucket' => 'Role',
                                                            'projected_games' => 'P-GP',
                                                            'projected_toi_per_game_seconds' => 'P-TOI',
                                                            'toi_diff_per_game_seconds' => 'Diff',
                                                            'projected_toi_hours' => 'Hrs',
                                                            'age_adjustment_seconds_per_game' => 'Age',
                                                            'role_adjustment_seconds_per_game' => 'Role',
                                                            'confidence_score' => 'Conf',
                                                            'status' => 'St',
                                                        ] as $sortKey => $label)
                                                            <th class="whitespace-nowrap px-1.5 py-2 first:w-48">
                                                                <a href="{{ $toiProjectionSortUrl($sortKey) }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                                    <span>{{ $label }}</span>
                                                                    @if($toiProjectionSortArrow($sortKey) !== '')
                                                                        <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $toiProjectionSortArrow($sortKey) }}</span>
                                                                    @endif
                                                                </a>
                                                            </th>
                                                        @endforeach
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-100 bg-white">
                                                    @forelse($toiProjectionRows as $row)
                                                        <tr>
                                                            <td class="truncate px-1.5 py-2 font-medium text-gray-900" title="{{ $row->player_name }} · NHL {{ $row->player_id }}">
                                                                <span class="block truncate font-semibold">{{ $row->player_name }}</span>
                                                                <span class="block truncate text-[10px] font-normal text-gray-500">{{ $row->source_season_id }} → {{ $row->target_season_id }}</span>
                                                            </td>
                                                            <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->source_team_abbrev ?? 'N/A' }}</td>
                                                            <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->target_team_abbrev ?? 'N/A' }}</td>
                                                            <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->position ?? 'N/A' }}</td>
                                                            <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->age_years, 1) }}</td>
                                                            <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_games, 0) }}</td>
                                                            <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatSeconds($row->source_toi_per_game_seconds) }}</td>
                                                            <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_points) }}</td>
                                                            <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->source_team_points_rank === null ? 'N/A' : $formatNumber($row->source_team_points_rank) }}</td>
                                                            <td class="truncate px-1.5 py-2 text-gray-700" title="{{ $row->source_role_bucket ?? 'N/A' }}">{{ $row->source_role_bucket ?? 'N/A' }}</td>
                                                            <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->target_team_points_rank === null ? 'N/A' : $formatNumber($row->target_team_points_rank) }}</td>
                                                            <td class="truncate px-1.5 py-2 text-gray-700" title="{{ $row->target_role_bucket ?? 'N/A' }}">{{ $row->target_role_bucket ?? 'N/A' }}</td>
                                                            <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_games, 0) }}</td>
                                                            <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatSeconds($row->projected_toi_per_game_seconds) }}</td>
                                                            <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->toi_diff_per_game_seconds > 0 ? '+' : '' }}{{ $formatSeconds($row->toi_diff_per_game_seconds) }}</td>
                                                            <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_toi_hours, 1) }}</td>
                                                            <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatSeconds($row->age_adjustment_seconds_per_game) }}</td>
                                                            <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatSeconds($row->role_adjustment_seconds_per_game) }}</td>
                                                            <td class="truncate px-1.5 py-2 text-gray-700">
                                                                {{ $row->confidence_score === null ? 'N/A' : $formatDecimal(((float) $row->confidence_score) * 100, 0) . '%' }}
                                                                <div class="truncate text-[10px] text-gray-500">{{ $row->confidence_bucket ?? 'unscored' }}</div>
                                                            </td>
                                                            <td class="truncate px-1.5 py-2 text-gray-700">{{ mb_substr((string) $row->status, 0, 1) }}</td>
                                                        </tr>
                                                    @empty
                                                        <tr><td colspan="19" class="px-2 py-6 text-center text-xs text-gray-500">No TOI projections match these filters.</td></tr>
                                                    @endforelse
                                                </tbody>
                                            </table>
                                        </div>
                                        </div>
                                    </div>
                                @endif

                                @if($goalieWorkloadProjectionTableExists)
                                    <div x-data="{{ $projectionAccordionState('goalie-workload') }}" class="border border-gray-200">
                                        <button type="button" class="flex w-full items-center justify-between gap-4 px-4 py-3 text-left hover:bg-gray-50" x-on:click="toggle()" x-bind:aria-expanded="open.toString()" aria-controls="goalie-workload-projections-panel">
                                            <span>
                                                <span class="block text-sm font-semibold text-gray-900">Goalie Workloads</span>
                                                <span class="mt-1 block text-sm text-gray-600">Review projected goalie starts, games, TOI, role, and workload drivers.</span>
                                            </span>
                                            <span class="text-lg leading-none text-gray-500 transition-transform duration-300 ease-out motion-reduce:transition-none" x-bind:class="{ 'rotate-180': open }" aria-hidden="true">⌄</span>
                                        </button>
                                        <div id="goalie-workload-projections-panel" x-cloak x-show="open" class="border-t border-gray-200">
                                            <form method="GET" action="{{ route('admin.nhl-shot-attempts.index') }}" class="border-b border-gray-200 p-4">
                                                @foreach(request()->except([
                                                    'goalie_workload_target_season_id',
                                                    'goalie_workload_version',
                                                    'goalie_workload_status',
                                                    'goalie_workload_team_abbrev',
                                                    'goalie_workload_goalie_search',
                                                    'goalie_workload_min_career_gp',
                                                    'goalie_workload_sort',
                                                    'goalie_workload_direction',
                                                    'page',
                                                ]) as $key => $value)
                                                    @if(is_scalar($value))
                                                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                                    @endif
                                                @endforeach
                                                <input type="hidden" name="tab" value="projections">
                                                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                                                    <label class="block">
                                                        <span class="text-xs font-medium text-gray-600">Target Season</span>
                                                        <select name="goalie_workload_target_season_id" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                            <option value="">All</option>
                                                            @foreach($goalieWorkloadOptions['targetSeasons'] as $season)
                                                                <option value="{{ $season }}" @selected((string) $goalieWorkloadFilters['target_season_id'] === (string) $season)>{{ $season }}</option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                    <label class="block">
                                                        <span class="text-xs font-medium text-gray-600">Version</span>
                                                        <select name="goalie_workload_version" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                            <option value="">All</option>
                                                            @foreach($goalieWorkloadOptions['versions'] as $version)
                                                                <option value="{{ $version }}" @selected((string) $goalieWorkloadFilters['projection_version'] === (string) $version)>{{ $version }}</option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                    <label class="block">
                                                        <span class="text-xs font-medium text-gray-600">Team</span>
                                                        <select name="goalie_workload_team_abbrev" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                            <option value="">All</option>
                                                            @foreach($goalieWorkloadOptions['teams'] as $team)
                                                                <option value="{{ $team }}" @selected((string) $goalieWorkloadFilters['team_abbrev'] === (string) $team)>{{ $team }}</option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                    <label class="block">
                                                        <span class="text-xs font-medium text-gray-600">Status</span>
                                                        <select name="goalie_workload_status" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                            <option value="">All</option>
                                                            @foreach($goalieWorkloadOptions['statuses'] as $status)
                                                                <option value="{{ $status }}" @selected((string) $goalieWorkloadFilters['status'] === (string) $status)>{{ $status }}</option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                    <label class="block">
                                                        <span class="text-xs font-medium text-gray-600">Goalie</span>
                                                        <input type="search" name="goalie_workload_goalie_search" value="{{ $goalieWorkloadFilters['goalie_search'] }}" placeholder="Name or NHL ID" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                    </label>
                                                    <label class="block">
                                                        <span class="text-xs font-medium text-gray-600">Min Car GP</span>
                                                        <input type="number" name="goalie_workload_min_career_gp" min="0" max="2000" step="1" value="{{ $goalieWorkloadFilters['min_career_gp'] }}" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                    </label>
                                                </div>
                                                <div class="mt-4 flex flex-wrap justify-end gap-2">
                                                    <a href="{{ route('admin.nhl-shot-attempts.index', ['tab' => 'projections']) }}" class="inline-flex min-h-10 items-center rounded-md border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Clear</a>
                                                    <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Apply</button>
                                                </div>
                                            </form>
                                            <div class="overflow-x-auto">
                                                <table class="w-full table-fixed divide-y divide-gray-200 text-[11px] leading-tight">
                                                    <thead class="bg-gray-50 text-left text-[10px] font-semibold uppercase tracking-wide text-gray-500">
                                                        <tr>
                                                            @foreach([
                                                                'goalie_name' => 'Goalie',
                                                                'target_team_abbrev' => 'Team',
                                                                'age_years' => 'Age',
                                                                'career_games' => 'Car GP',
                                                                'source_games' => 'GP',
                                                                'source_starts' => 'Starts',
                                                                'source_role_bucket' => 'Src Role',
                                                                'target_role_bucket' => 'Role',
                                                                'projected_games' => 'P-GP',
                                                                'projected_starts' => 'P-St',
                                                                'starts_diff' => 'Diff',
                                                                'projected_relief_games' => 'Relief',
                                                                'projected_toi_hours' => 'Hrs',
                                                                'role_adjustment_starts' => 'Role Adj',
                                                                'contract_adjustment_starts' => 'AAV Adj',
                                                                'durability_adjustment_starts' => 'Dur Adj',
                                                                'contract_aav' => 'AAV',
                                                                'team_contract_rank' => 'AAV Rk',
                                                                'confidence_score' => 'Conf',
                                                                'status' => 'St',
                                                            ] as $sortKey => $label)
                                                                <th class="whitespace-nowrap px-1.5 py-2 first:w-48">
                                                                    <a href="{{ $goalieWorkloadSortUrl($sortKey) }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                                        <span>{{ $label }}</span>
                                                                        @if($goalieWorkloadSortArrow($sortKey) !== '')
                                                                            <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $goalieWorkloadSortArrow($sortKey) }}</span>
                                                                        @endif
                                                                    </a>
                                                                </th>
                                                            @endforeach
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-100 bg-white">
                                                        @forelse($goalieWorkloadRows as $row)
                                                            <tr>
                                                                <td class="truncate px-1.5 py-2 font-medium text-gray-900" title="{{ $row->goalie_name }} · NHL {{ $row->goalie_player_id }}">
                                                                    <span class="block truncate font-semibold">{{ $row->goalie_name }}</span>
                                                                    <span class="block truncate text-[10px] font-normal text-gray-500">{{ $row->source_season_id }} → {{ $row->target_season_id }}</span>
                                                                </td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->target_team_abbrev ?? 'N/A' }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->age_years, 1) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->career_games, 0) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_games, 0) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_starts, 0) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700" title="{{ $row->source_role_bucket ?? 'N/A' }}">{{ $row->source_role_bucket ?? 'N/A' }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700" title="{{ $row->target_role_bucket ?? 'N/A' }}">{{ $row->target_role_bucket ?? 'N/A' }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_games, 0) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_starts, 0) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->starts_diff === null ? 'N/A' : (((float) $row->starts_diff > 0 ? '+' : '') . $formatDecimal($row->starts_diff, 1)) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_relief_games, 0) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_toi_hours, 1) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->role_adjustment_starts, 1) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->contract_adjustment_starts, 1) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->durability_adjustment_starts, 1) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->contract_aav === null ? 'N/A' : '$' . number_format(((float) $row->contract_aav) / 1000000, 1) . 'M' }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->team_contract_rank === null ? 'N/A' : $formatNumber($row->team_contract_rank) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">
                                                                    {{ $row->confidence_score === null ? 'N/A' : $formatDecimal(((float) $row->confidence_score) * 100, 0) . '%' }}
                                                                    <div class="truncate text-[10px] text-gray-500">{{ $row->confidence_bucket ?? 'unscored' }}</div>
                                                                </td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ mb_substr((string) $row->status, 0, 1) }}</td>
                                                            </tr>
                                                        @empty
                                                            <tr><td colspan="20" class="px-2 py-6 text-center text-xs text-gray-500">No goalie workload projections match these filters.</td></tr>
                                                        @endforelse
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                @if($goalieProjectionTablesExist)
                                    <div x-data="{{ $projectionAccordionState('goalies') }}" class="border border-gray-200">
                                        <button type="button" class="flex w-full items-center justify-between gap-4 px-4 py-3 text-left hover:bg-gray-50" x-on:click="toggle()" x-bind:aria-expanded="open.toString()" aria-controls="goalie-projections-panel">
                                            <span>
                                                <span class="block text-sm font-semibold text-gray-900">Goalie Projections</span>
                                                <span class="mt-1 block text-sm text-gray-600">Review projected goalie performance from workload, team defensive buckets, and goalie bucket skill.</span>
                                            </span>
                                            <span class="text-lg leading-none text-gray-500 transition-transform duration-300 ease-out motion-reduce:transition-none" x-bind:class="{ 'rotate-180': open }" aria-hidden="true">⌄</span>
                                        </button>
                                        <div id="goalie-projections-panel" x-cloak x-show="open" class="border-t border-gray-200">
                                            <form method="GET" action="{{ route('admin.nhl-shot-attempts.index') }}" class="border-b border-gray-200 p-4">
                                                @foreach(request()->except([
                                                    'goalie_projection_target_season_id',
                                                    'goalie_projection_version',
                                                    'goalie_projection_status',
                                                    'goalie_projection_team_abbrev',
                                                    'goalie_projection_goalie_search',
                                                    'goalie_projection_min_projected_starts',
                                                    'goalie_projection_sort',
                                                    'goalie_projection_direction',
                                                    'page',
                                                ]) as $key => $value)
                                                    @if(is_scalar($value))
                                                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                                    @endif
                                                @endforeach
                                                <input type="hidden" name="tab" value="projections">
                                                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                                                    <label class="block">
                                                        <span class="text-xs font-medium text-gray-600">Target Season</span>
                                                        <select name="goalie_projection_target_season_id" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                            <option value="">All</option>
                                                            @foreach($goalieProjectionOptions['targetSeasons'] as $season)
                                                                <option value="{{ $season }}" @selected((string) $goalieProjectionFilters['target_season_id'] === (string) $season)>{{ $season }}</option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                    <label class="block">
                                                        <span class="text-xs font-medium text-gray-600">Version</span>
                                                        <select name="goalie_projection_version" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                            <option value="">All</option>
                                                            @foreach($goalieProjectionOptions['versions'] as $version)
                                                                <option value="{{ $version }}" @selected((string) $goalieProjectionFilters['projection_version'] === (string) $version)>{{ $version }}</option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                    <label class="block">
                                                        <span class="text-xs font-medium text-gray-600">Team</span>
                                                        <select name="goalie_projection_team_abbrev" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                            <option value="">All</option>
                                                            @foreach($goalieProjectionOptions['teams'] as $team)
                                                                <option value="{{ $team }}" @selected((string) $goalieProjectionFilters['team_abbrev'] === (string) $team)>{{ $team }}</option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                    <label class="block">
                                                        <span class="text-xs font-medium text-gray-600">Status</span>
                                                        <select name="goalie_projection_status" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                            <option value="">All</option>
                                                            @foreach($goalieProjectionOptions['statuses'] as $status)
                                                                <option value="{{ $status }}" @selected((string) $goalieProjectionFilters['status'] === (string) $status)>{{ $status }}</option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                    <label class="block">
                                                        <span class="text-xs font-medium text-gray-600">Goalie</span>
                                                        <input type="search" name="goalie_projection_goalie_search" value="{{ $goalieProjectionFilters['goalie_search'] }}" placeholder="Name or NHL ID" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                    </label>
                                                    <label class="block">
                                                        <span class="text-xs font-medium text-gray-600">Min P-St</span>
                                                        <input type="number" name="goalie_projection_min_projected_starts" min="0" max="84" step="1" value="{{ $goalieProjectionFilters['min_projected_starts'] }}" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                    </label>
                                                </div>
                                                <div class="mt-4 flex flex-wrap justify-end gap-2">
                                                    <a href="{{ route('admin.nhl-shot-attempts.index', ['tab' => 'projections']) }}" class="inline-flex min-h-10 items-center rounded-md border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Clear</a>
                                                    <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Apply</button>
                                                </div>
                                            </form>
                                            <div class="overflow-x-auto">
                                                <table class="w-full table-fixed divide-y divide-gray-200 text-[11px] leading-tight">
                                                    <thead class="bg-gray-50 text-left text-[10px] font-semibold uppercase tracking-wide text-gray-500">
                                                        <tr>
                                                            @foreach([
                                                                'goalie_name' => 'Goalie',
                                                                'target_team_abbrev' => 'Team',
                                                                'source_games' => 'GP',
                                                                'projected_games' => 'P-GP',
                                                                'projected_starts' => 'P-St',
                                                                'projected_toi_hours' => 'P-Hrs',
                                                                'projected_xgaa' => 'P-xGAA',
                                                                'projected_gaa' => 'P-GAA',
                                                                'projected_gsax_per_game' => 'P-GSAx/GP',
                                                                'projected_ev_xga_per_game' => 'P-EV xGA/GP',
                                                                'projected_ev_ga_per_game' => 'P-EV GA/GP',
                                                                'projected_pk_xga_per_game' => 'P-PK xGA/GP',
                                                                'projected_pk_ga_per_game' => 'P-PK GA/GP',
                                                                'confidence_score' => 'Conf',
                                                            ] as $sortKey => $label)
                                                                <th class="whitespace-nowrap px-1.5 py-2 first:w-48">
                                                                    <a href="{{ $goalieProjectionSortUrl($sortKey) }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                                        <span>{{ $label }}</span>
                                                                        @if($goalieProjectionSortArrow($sortKey) !== '')
                                                                            <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $goalieProjectionSortArrow($sortKey) }}</span>
                                                                        @endif
                                                                    </a>
                                                                </th>
                                                            @endforeach
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-100 bg-white">
                                                        @forelse($goalieProjectionRows as $row)
                                                            <tr>
                                                                <td class="truncate px-1.5 py-2 font-medium text-gray-900" title="{{ $row->goalie_name }} · NHL {{ $row->goalie_player_id }}">
                                                                    <span class="block truncate font-semibold">{{ $row->goalie_name }}</span>
                                                                    <span class="block truncate text-[10px] font-normal text-gray-500">{{ $row->source_season_id }} → {{ $row->target_season_id }}</span>
                                                                </td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->target_team_abbrev ?? 'N/A' }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_games, 0) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_games, 0) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_starts, 0) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_toi_hours, 1) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_xgaa, 2) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_gaa, 2) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->projected_gsax_per_game === null ? 'N/A' : (((float) $row->projected_gsax_per_game > 0 ? '+' : '') . $formatDecimal($row->projected_gsax_per_game, 2)) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_ev_xga_per_game, 2) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_ev_ga_per_game, 2) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_pk_xga_per_game, 2) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_pk_ga_per_game, 2) }}</td>
                                                                <td class="truncate px-1.5 py-2 text-gray-700">
                                                                    {{ $row->confidence_score === null ? 'N/A' : $formatDecimal(((float) $row->confidence_score) * 100, 0) . '%' }}
                                                                    <div class="truncate text-[10px] text-gray-500">{{ $row->confidence_bucket ?? 'unscored' }}</div>
                                                                </td>
                                                            </tr>
                                                        @empty
                                                            <tr><td colspan="14" class="px-2 py-6 text-center text-xs text-gray-500">No goalie projections match these filters.</td></tr>
                                                        @endforelse
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                @if($projectionTablesExist)
                                <div x-data="{{ $projectionAccordionState('skaters') }}" class="border border-gray-200">
                                    <button type="button" class="flex w-full items-center justify-between gap-4 px-4 py-3 text-left hover:bg-gray-50" x-on:click="toggle()" x-bind:aria-expanded="open.toString()" aria-controls="skater-projections-panel">
                                        <span>
                                            <span class="block text-sm font-semibold text-gray-900">Skater Performance Projections</span>
                                            <span class="mt-1 block text-sm text-gray-600">Review player-level projection rollups and the resolved shot profile buckets that explain them.</span>
                                        </span>
                                        <span class="text-lg leading-none text-gray-500 transition-transform duration-300 ease-out motion-reduce:transition-none" x-bind:class="{ 'rotate-180': open }" aria-hidden="true">⌄</span>
                                    </button>
                                    <div id="skater-projections-panel" x-cloak x-show="open" class="overflow-x-auto border-t border-gray-200">
                                <table class="w-full table-fixed divide-y divide-gray-200 text-[10px] leading-tight">
                                    <thead class="bg-gray-50 text-left text-[9px] font-semibold uppercase text-gray-500">
                                        <tr>
                                            @foreach([
                                                'player_name' => 'Player',
                                                'team_abbrev' => 'Team',
                                                'position' => 'Pos',
                                                'source_sat' => 'Source SAT',
                                                'source_sog' => 'Source SOG',
                                                'source_goals' => 'Source Goals',
                                                'source_model_goals' => 'Model Goals',
                                                'source_xgf' => 'Source xG',
                                                'source_goals_above_xgf' => 'G - xG',
                                                'source_xsog' => 'Source xSOG',
                                                'source_xsat_per_60' => 'Src SAT/60',
                                                'source_xsog_per_60' => 'Src xSOG/60',
                                                'source_xgf_per_60' => 'Src xGF/60',
                                                'projected_games' => 'Proj Games',
                                                'projected_xsat' => 'Proj xSAT',
                                                'projected_xsog' => 'Proj xSOG',
                                                'projected_xgf' => 'Proj xGF',
                                                'projected_xsat_per_60' => 'P SAT/60',
                                                'projected_xsog_per_60' => 'P xSOG/60',
                                                'projected_xgf_per_60' => 'P xGF/60',
                                                'finishing_regression_weight' => 'Finish Wt',
                                                'projected_goals_adjustment' => 'Goal Adj',
                                                'projected_goals' => 'Proj Goals',
                                                'confidence_score' => 'Confidence',
                                                'status' => 'Status',
                                            ] as $sortKey => $label)
                                                <th class="px-1.5 py-2 first:w-44">
                                                    <a href="{{ $projectionSortUrl($sortKey) }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                        <span>{{ $label }}</span>
                                                        @if($projectionSortArrow($sortKey) !== '')
                                                            <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $projectionSortArrow($sortKey) }}</span>
                                                        @endif
                                                    </a>
                                                </th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 bg-white">
                                        @forelse($projectionRows as $row)
                                            @php
                                                $bucketRows = $projectionBucketRowsByProjection->get($row->id, collect());
                                            @endphp
                                            <tr>
                                                <td class="truncate px-1.5 py-2 font-medium text-gray-900">
                                                    <details>
                                                        <summary class="cursor-pointer list-none">
                                                            <span class="block truncate font-semibold">{{ $row->player_name }}</span>
                                                            <span class="block truncate text-[9px] font-normal text-gray-500">NHL {{ $row->player_id }} · {{ $row->projection_version }}</span>
                                                            <div class="mt-0.5 truncate text-[9px] font-normal text-gray-500">{{ $row->source_season_id }} → {{ $row->target_season_id }}</div>
                                                        </summary>
                                                        <div class="mt-3 overflow-x-auto border border-gray-200">
                                                            <table class="min-w-full divide-y divide-gray-200 text-[10px] leading-tight">
                                                                <thead class="bg-gray-50 text-left font-semibold uppercase text-gray-500">
                                                                    <tr>
                                                                        @foreach([
                                                                            'fallback_level' => 'Level',
                                                                            'shot_type_group' => 'Shot Type',
                                                                            'distance_group' => 'Distance',
                                                                            'angle_group' => 'Angle',
                                                                            'sequence_group' => 'Sequence',
                                                                            'source_sat' => 'Source SAT',
                                                                            'source_profile_share' => 'Share',
                                                                            'projected_xsat' => 'Proj xSAT',
                                                                            'projected_xsog' => 'Proj xSOG',
                                                                            'projected_xgf' => 'Proj xGF',
                                                                            'goal_probability' => 'Goal Prob',
                                                                            'shot_on_goal_probability' => 'SOG Prob',
                                                                        ] as $sortKey => $label)
                                                                            <th class="whitespace-nowrap px-1.5 py-1.5">
                                                                                <a href="{{ $projectionBucketSortUrl($sortKey) }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                                                    <span>{{ $label }}</span>
                                                                                    @if($projectionBucketSortArrow($sortKey) !== '')
                                                                                        <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $projectionBucketSortArrow($sortKey) }}</span>
                                                                                    @endif
                                                                                </a>
                                                                            </th>
                                                                        @endforeach
                                                                    </tr>
                                                                </thead>
                                                                <tbody class="divide-y divide-gray-100 bg-white">
                                                                    @forelse($bucketRows as $bucketRow)
                                                                        <tr>
                                                                            <td class="whitespace-nowrap px-1.5 py-1.5">L{{ str_pad((string) $bucketRow->fallback_level, 2, '0', STR_PAD_LEFT) }}</td>
                                                                            <td class="whitespace-nowrap px-1.5 py-1.5">{{ $bucketRow->shot_type_group ?? 'Any' }}</td>
                                                                            <td class="whitespace-nowrap px-1.5 py-1.5">{{ $bucketRow->distance_group ?? 'Any' }}</td>
                                                                            <td class="whitespace-nowrap px-1.5 py-1.5">{{ $bucketRow->angle_group ?? 'Any' }}</td>
                                                                            <td class="whitespace-nowrap px-1.5 py-1.5">{{ $bucketRow->sequence_group ?? 'Any' }}</td>
                                                                            <td class="whitespace-nowrap px-1.5 py-1.5">{{ $formatNumber($bucketRow->source_sat) }}</td>
                                                                            <td class="whitespace-nowrap px-1.5 py-1.5">{{ $bucketRow->source_profile_share === null ? 'N/A' : $formatDecimal(((float) $bucketRow->source_profile_share) * 100) . '%' }}</td>
                                                                            <td class="whitespace-nowrap px-1.5 py-1.5">{{ $formatDecimal($bucketRow->projected_xsat) }}</td>
                                                                            <td class="whitespace-nowrap px-1.5 py-1.5">{{ $formatDecimal($bucketRow->projected_xsog) }}</td>
                                                                            <td class="whitespace-nowrap px-1.5 py-1.5">{{ $formatDecimal($bucketRow->projected_xgf) }}</td>
                                                                            <td class="whitespace-nowrap px-1.5 py-1.5">{{ $bucketRow->goal_probability === null ? 'N/A' : $formatDecimal(((float) $bucketRow->goal_probability) * 100) . '%' }}</td>
                                                                            <td class="whitespace-nowrap px-1.5 py-1.5">{{ $bucketRow->shot_on_goal_probability === null ? 'N/A' : $formatDecimal(((float) $bucketRow->shot_on_goal_probability) * 100) . '%' }}</td>
                                                                        </tr>
                                                                    @empty
                                                                        <tr><td colspan="12" class="px-3 py-6 text-center text-gray-500">No profile bucket rows stored for this projection.</td></tr>
                                                                    @endforelse
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </details>
                                                </td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->team_abbrev ?? 'N/A' }}</td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->position ?? 'N/A' }}</td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_sat) }}</td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_sog) }}</td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_goals) }}</td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_model_goals) }}</td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_xgf) }}</td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_goals_above_xgf) }}</td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_xsog) }}</td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_xsat_per_60) }}</td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_xsog_per_60) }}</td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_xgf_per_60) }}</td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_games) }}</td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_xsat) }}</td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_xsog) }}</td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_xgf) }}</td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_xsat_per_60) }}</td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_xsog_per_60) }}</td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_xgf_per_60) }}</td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->finishing_regression_weight === null ? 'N/A' : $formatDecimal(((float) $row->finishing_regression_weight) * 100) . '%' }}</td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_goals_adjustment) }}</td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->projected_goals) }}</td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">
                                                    {{ $row->confidence_score === null ? 'N/A' : $formatDecimal(((float) $row->confidence_score) * 100) . '%' }}
                                                    <div class="truncate text-[9px] text-gray-500">{{ $row->confidence_bucket ?? 'unscored' }}</div>
                                                </td>
                                                <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->status }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="25" class="px-2 py-6 text-center text-xs text-gray-500">No player projections match these filters.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                                </div>
                                </div>
                                @endif

                            @endif
                        </div>
                    @elseif($activeTab === 'matchup')
                        <div class="space-y-4 p-4">
                            <div class="border-b border-gray-200 pb-4">
                                <h4 class="text-sm font-semibold text-gray-900">Projected Team Matchup</h4>
                                <p class="mt-1 text-sm text-gray-600">Simulate projected team offense, composed roster chance profiles, and selected goalie impact.</p>
                            </div>

                            <form method="GET" action="{{ route('admin.nhl-shot-attempts.index') }}" class="border-b border-gray-200 pb-4" data-nhl-matchup-form>
                                <input type="hidden" name="tab" value="matchup">
                                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8">
                                    <label class="block">
                                        <span class="text-xs font-medium text-gray-600">Source</span>
                                        <select name="matchup_source_season_id" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" data-nhl-matchup-refresh="all">
                                            <option value="">Select</option>
                                            @foreach($matchupOptions['sourceSeasons'] as $season)
                                                <option value="{{ $season }}" @selected((string) $matchupFilters['source_season_id'] === (string) $season)>{{ $season }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-medium text-gray-600">Target</span>
                                        <select name="matchup_target_season_id" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" data-nhl-matchup-refresh="all">
                                            <option value="">Select</option>
                                            @foreach($matchupOptions['targetSeasons'] as $season)
                                                <option value="{{ $season }}" @selected((string) $matchupFilters['target_season_id'] === (string) $season)>{{ $season }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-medium text-gray-600">Skater Version</span>
                                        <select name="matchup_projection_version" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" data-nhl-matchup-refresh="result">
                                            <option value="">Select</option>
                                            @foreach($matchupOptions['projectionVersions'] as $version)
                                                <option value="{{ $version }}" @selected((string) $matchupFilters['projection_version'] === (string) $version)>{{ $version }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-medium text-gray-600">TOI Version</span>
                                        <select name="matchup_toi_projection_version" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" data-nhl-matchup-refresh="result">
                                            <option value="">Select</option>
                                            @foreach($matchupOptions['toiProjectionVersions'] as $version)
                                                <option value="{{ $version }}" @selected((string) $matchupFilters['toi_projection_version'] === (string) $version)>{{ $version }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-medium text-gray-600">Goalie Version</span>
                                        <select name="matchup_goalie_projection_version" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" data-nhl-matchup-refresh="all">
                                            <option value="">Auto</option>
                                            @foreach($matchupOptions['goalieProjectionVersions'] as $version)
                                                <option value="{{ $version }}" @selected((string) $matchupFilters['goalie_projection_version'] === (string) $version)>{{ $version }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-medium text-gray-600">Team A</span>
                                        <select name="matchup_team_a" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" data-nhl-matchup-refresh="team-a">
                                            <option value="">Select</option>
                                            @foreach($matchupOptions['teams'] as $team)
                                                <option value="{{ $team }}" @selected((string) $matchupFilters['team_a'] === (string) $team)>{{ $team }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-medium text-gray-600">Team B</span>
                                        <select name="matchup_team_b" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" data-nhl-matchup-refresh="team-b">
                                            <option value="">Select</option>
                                            @foreach($matchupOptions['teams'] as $team)
                                                <option value="{{ $team }}" @selected((string) $matchupFilters['team_b'] === (string) $team)>{{ $team }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-medium text-gray-600">Team A G</span>
                                        <select name="matchup_team_a_goalie_id" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                            <option value="">No goalie adj</option>
                                            @foreach($matchupOptions['teamAGoalies'] as $goalie)
                                                <option value="{{ $goalie->goalie_player_id }}" @selected((string) $matchupFilters['team_a_goalie_id'] === (string) $goalie->goalie_player_id)>{{ $goalie->goalie_name }} ({{ $goalie->projected_starts === null ? 'N/A' : $formatDecimal($goalie->projected_starts, 0) }} starts, src {{ $goalie->source_team_abbrev ?? 'N/A' }})</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-medium text-gray-600">Team B G</span>
                                        <select name="matchup_team_b_goalie_id" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                            <option value="">No goalie adj</option>
                                            @foreach($matchupOptions['teamBGoalies'] as $goalie)
                                                <option value="{{ $goalie->goalie_player_id }}" @selected((string) $matchupFilters['team_b_goalie_id'] === (string) $goalie->goalie_player_id)>{{ $goalie->goalie_name }} ({{ $goalie->projected_starts === null ? 'N/A' : $formatDecimal($goalie->projected_starts, 0) }} starts, src {{ $goalie->source_team_abbrev ?? 'N/A' }})</option>
                                            @endforeach
                                        </select>
                                    </label>
                                </div>
                                <div class="mt-4 flex justify-end">
                                    <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Simulate</button>
                                </div>
                            </form>

                            @if($matchupResult !== null && !($matchupResult['is_available'] ?? false))
                                <div class="border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                    {{ $matchupResult['error'] }}
                                </div>
                            @elseif($matchupResult !== null)
                                <div class="grid gap-4 lg:grid-cols-2">
                                    @foreach($matchupResult['sides'] as $side)
                                        <div class="border border-gray-200 p-4">
                                            <div class="flex items-start justify-between gap-4">
                                                <div>
                                                    <h5 class="text-sm font-semibold text-gray-900">{{ $side['offense_team'] }} Offense vs {{ $side['defense_team'] }} Defense</h5>
                                                    <p class="mt-1 text-xs text-gray-600">
                                                        {{ $side['goalie']['goalie_name'] ?? 'No goalie adjustment' }}
                                                    </p>
                                                </div>
                                                <div class="text-right">
                                                    <div class="text-xs font-semibold uppercase text-gray-500">Expected Goals/G</div>
                                                    <div class="text-3xl font-semibold tracking-normal text-gray-900">{{ $formatDecimal($side['summary']['total_goalie_adjusted_xgf_per_game'], 2) }}</div>
                                                </div>
                                            </div>
                                            <div class="mt-4 grid grid-cols-5 gap-3 text-xs">
                                                <div>
                                                    <div class="font-semibold uppercase text-gray-500">xSAT/G</div>
                                                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $formatDecimal($side['summary']['adjusted_xsat_per_game']) }}</div>
                                                    <div class="text-[10px] text-gray-500">{{ $formatDecimal($side['summary']['xsat_delta_per_game']) }} adj/G</div>
                                                </div>
                                                <div>
                                                    <div class="font-semibold uppercase text-gray-500">xSOG/G</div>
                                                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $formatDecimal($side['summary']['adjusted_xsog_per_game']) }}</div>
                                                    <div class="text-[10px] text-gray-500">{{ $formatDecimal($side['summary']['xsog_delta_per_game']) }} adj/G</div>
                                                </div>
                                                <div>
                                                    <div class="font-semibold uppercase text-gray-500">EV xGF/G</div>
                                                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $formatDecimal($side['summary']['adjusted_xgf_per_game'], 2) }}</div>
                                                    <div class="text-[10px] text-gray-500">offense base/G</div>
                                                </div>
                                                <div>
                                                    <div class="font-semibold uppercase text-gray-500">PK xG/G</div>
                                                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $formatDecimal($side['summary']['pk_goalie_adjusted_xgf_per_game'], 2) }}</div>
                                                    <div class="text-[10px] text-gray-500">{{ $formatDecimal($side['summary']['pk_goalie_adjustment_per_game'], 3) }} G adj/G</div>
                                                </div>
                                                <div>
                                                    <div class="font-semibold uppercase text-gray-500">Goalie</div>
                                                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $formatDecimal($side['summary']['goalie_adjustment_per_game'], 3) }}</div>
                                                    <div class="text-[10px] text-gray-500">{{ $formatDecimal($side['summary']['total_goalie_adjustment_per_game'], 3) }} total/G</div>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                @foreach([
                                    ['key' => 'offense_profile', 'title' => 'Offense Generated', 'copy' => 'Projected skater chance mix created by the composed roster.'],
                                    ['key' => 'defense_profile', 'title' => 'Defensive Chances Given Up', 'copy' => 'Projected skater on-ice chance mix allowed by the composed roster.'],
                                ] as $profileSection)
                                    <div class="border border-gray-200">
                                        <div class="border-b border-gray-200 px-4 py-3">
                                            <h5 class="text-sm font-semibold text-gray-900">{{ $profileSection['title'] }}</h5>
                                            <div class="mt-1 text-xs text-gray-600">{{ $profileSection['copy'] }}</div>
                                        </div>
                                        <div class="grid gap-0 lg:grid-cols-2">
                                            @foreach($matchupResult['sides'] as $side)
                                                @php($profile = $side[$profileSection['key']] ?? ['rows' => [], 'summary' => []])
                                                @php($profileSummary = $profile['summary'] ?? [])
                                                <div class="overflow-x-auto border-b border-gray-200 lg:border-b-0 lg:border-r lg:last:border-r-0">
                                                    <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-gray-50 px-3 py-2 text-[10px] leading-tight text-gray-600">
                                                        <div class="font-semibold text-gray-900">{{ $side['offense_team'] }}</div>
                                                        <div class="text-right">
                                                            <span>{{ $formatNumber($profileSummary['row_count'] ?? count($profile['rows'] ?? [])) }} groups / {{ $formatNumber($profileSummary['total_row_count'] ?? count($profile['rows'] ?? [])) }} bkts</span>
                                                            <span class="ml-2">{{ $formatDecimal(((float) ($profileSummary['represented_xsog'] ?? 0)) * 100, 0) }}% xSOG</span>
                                                        </div>
                                                    </div>
                                                    <table class="w-full table-fixed divide-y divide-gray-200 text-[10px] leading-tight">
                                                        <thead class="bg-gray-50 text-left text-[9px] font-semibold uppercase text-gray-500">
                                                            <tr>
                                                                <th class="px-2 py-2">Type</th>
                                                                <th class="px-2 py-2">Dist</th>
                                                                <th class="px-2 py-2">Angle</th>
                                                                <th class="px-2 py-2">Seq</th>
                                                                <th class="px-2 py-2">xSAT/G</th>
                                                                <th class="px-2 py-2">xSOG/G</th>
                                                                <th class="px-2 py-2">xG/G</th>
                                                                <th class="px-2 py-2">xSOG Sh</th>
                                                                <th class="px-2 py-2">Bkts</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody class="divide-y divide-gray-100 bg-white">
                                                            @forelse($profile['rows'] ?? [] as $row)
                                                                <tr>
                                                                    <td class="truncate px-2 py-2 text-gray-700" title="{{ $row['shot_type_group'] ?? 'Any' }}">{{ $row['shot_type_group'] ?? 'Any' }}</td>
                                                                    <td class="truncate px-2 py-2 text-gray-700" title="{{ $row['distance_group'] ?? 'Any' }}">{{ $row['distance_group'] ?? 'Any' }}</td>
                                                                    <td class="truncate px-2 py-2 text-gray-700" title="{{ $row['angle_group'] ?? 'Any' }}">{{ $row['angle_group'] ?? 'Any' }}</td>
                                                                    <td class="truncate px-2 py-2 text-gray-700" title="{{ $row['sequence_group'] ?? 'Any' }}">{{ $row['sequence_group'] ?? 'Any' }}</td>
                                                                    <td class="px-2 py-2 text-gray-700">{{ $formatDecimal($row['xsat_per_game'] ?? 0, 3) }}</td>
                                                                    <td class="px-2 py-2 text-gray-700">{{ $formatDecimal($row['xsog_per_game'] ?? 0, 3) }}</td>
                                                                    <td class="px-2 py-2 text-gray-700">{{ $formatDecimal($row['xgf_per_game'] ?? 0, 3) }}</td>
                                                                    <td class="px-2 py-2 text-gray-700">{{ isset($row['represented_xsog']) ? $formatDecimal(((float) $row['represented_xsog']) * 100, 1) . '%' : 'N/A' }}</td>
                                                                    <td class="px-2 py-2 text-gray-700">{{ $formatNumber($row['bucket_count'] ?? 1) }}</td>
                                                                </tr>
                                                            @empty
                                                                <tr><td colspan="9" class="px-3 py-6 text-center text-xs text-gray-500">No composed profile rows found.</td></tr>
                                                            @endforelse
                                                        </tbody>
                                                    </table>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach

                                <details class="border border-gray-200">
                                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-4 py-3 hover:bg-gray-50">
                                        <span>
                                            <span class="block text-sm font-semibold text-gray-900">Adjusted Env For Goalie</span>
                                            <span class="mt-1 block text-xs text-gray-600">Goalie-facing bucket mix from 70% offense generated and 30% opponent defensive chances given up.</span>
                                        </span>
                                    </summary>
                                    <div class="grid gap-0 border-t border-gray-200 lg:grid-cols-2">
                                        @foreach($matchupResult['sides'] as $side)
                                            @php($profile = $side['goalie_environment_profile'] ?? ['rows' => [], 'summary' => []])
                                            @php($profileSummary = $profile['summary'] ?? [])
                                            <div class="overflow-x-auto border-b border-gray-200 lg:border-b-0 lg:border-r lg:last:border-r-0">
                                                <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-gray-50 px-3 py-2 text-[10px] leading-tight text-gray-600">
                                                    <div class="font-semibold text-gray-900">{{ $side['offense_team'] }} vs {{ $side['defense_team'] }} G</div>
                                                    <div class="text-right">
                                                        <span>{{ $formatNumber($profileSummary['row_count'] ?? count($profile['rows'] ?? [])) }} groups / {{ $formatNumber($profileSummary['total_row_count'] ?? count($profile['rows'] ?? [])) }} bkts</span>
                                                        <span class="ml-2">{{ $formatDecimal(((float) ($profileSummary['represented_xsog'] ?? 0)) * 100, 0) }}% xSOG</span>
                                                    </div>
                                                </div>
                                                <table class="w-full table-fixed divide-y divide-gray-200 text-[10px] leading-tight">
                                                    <thead class="bg-gray-50 text-left text-[9px] font-semibold uppercase text-gray-500">
                                                        <tr>
                                                            <th class="px-2 py-2">Type</th>
                                                            <th class="px-2 py-2">Dist</th>
                                                            <th class="px-2 py-2">Angle</th>
                                                            <th class="px-2 py-2">Seq</th>
                                                            <th class="px-2 py-2">xSAT/G</th>
                                                            <th class="px-2 py-2">xSOG/G</th>
                                                            <th class="px-2 py-2">xG/G</th>
                                                            <th class="px-2 py-2">xSOG Sh</th>
                                                            <th class="px-2 py-2">Bkts</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-100 bg-white">
                                                        @forelse($profile['rows'] ?? [] as $row)
                                                            <tr>
                                                                <td class="truncate px-2 py-2 text-gray-700" title="{{ $row['shot_type_group'] ?? 'Any' }}">{{ $row['shot_type_group'] ?? 'Any' }}</td>
                                                                <td class="truncate px-2 py-2 text-gray-700" title="{{ $row['distance_group'] ?? 'Any' }}">{{ $row['distance_group'] ?? 'Any' }}</td>
                                                                <td class="truncate px-2 py-2 text-gray-700" title="{{ $row['angle_group'] ?? 'Any' }}">{{ $row['angle_group'] ?? 'Any' }}</td>
                                                                <td class="truncate px-2 py-2 text-gray-700" title="{{ $row['sequence_group'] ?? 'Any' }}">{{ $row['sequence_group'] ?? 'Any' }}</td>
                                                                <td class="px-2 py-2 text-gray-700">{{ $formatDecimal($row['xsat_per_game'] ?? 0, 3) }}</td>
                                                                <td class="px-2 py-2 text-gray-700">{{ $formatDecimal($row['xsog_per_game'] ?? 0, 3) }}</td>
                                                                <td class="px-2 py-2 text-gray-700">{{ $formatDecimal($row['xgf_per_game'] ?? 0, 3) }}</td>
                                                                <td class="px-2 py-2 text-gray-700">{{ isset($row['represented_xsog']) ? $formatDecimal(((float) $row['represented_xsog']) * 100, 1) . '%' : 'N/A' }}</td>
                                                                <td class="px-2 py-2 text-gray-700">{{ $formatNumber($row['bucket_count'] ?? 1) }}</td>
                                                            </tr>
                                                        @empty
                                                            <tr><td colspan="9" class="px-3 py-6 text-center text-xs text-gray-500">No adjusted environment rows found.</td></tr>
                                                        @endforelse
                                                    </tbody>
                                                </table>
                                            </div>
                                        @endforeach
                                    </div>
                                </details>

                                <div class="space-y-3">
                                    @foreach($matchupResult['sides'] as $side)
                                        <details class="border border-gray-200">
                                            @php($goalieReasonSummary = $side['goalie_reason_summary'] ?? [])
                                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-4 py-3 hover:bg-gray-50">
                                                <span>
                                                    <span class="block text-sm font-semibold text-gray-900">{{ $side['defense_team'] }} Goalie In Adjusted Env</span>
                                                    <span class="mt-1 block text-xs text-gray-600">How the selected goalie projects against the adjusted {{ $side['offense_team'] }} chance environment.</span>
                                                </span>
                                                <span class="text-right text-xs font-semibold text-gray-500">
                                                    <span class="block">{{ $formatNumber($goalieReasonSummary['row_count'] ?? count($side['goalie_reasons'])) }} groups / {{ $formatNumber($goalieReasonSummary['total_row_count'] ?? count($side['goalie_reasons'])) }} bkts</span>
                                                    <span class="block">{{ $formatDecimal(((float) ($goalieReasonSummary['represented_xsog'] ?? 0)) * 100, 0) }}% xSOG</span>
                                                </span>
                                            </summary>
                                            <div class="border-t border-gray-200">
                                                <div class="grid grid-cols-6 gap-2 border-b border-gray-200 bg-gray-50 px-4 py-3 text-[10px] leading-tight text-gray-600">
                                                    <div>
                                                        <div class="font-semibold uppercase text-gray-500">Shown xSAT/G</div>
                                                        <div class="mt-1 text-xs font-semibold text-gray-900">{{ $formatDecimal($goalieReasonSummary['shown_xsat_per_game'] ?? 0, 2) }}</div>
                                                        <div>{{ $formatDecimal(((float) ($goalieReasonSummary['represented_xsat'] ?? 0)) * 100, 0) }}% rep</div>
                                                    </div>
                                                    <div>
                                                        <div class="font-semibold uppercase text-gray-500">Shown xSOG/G</div>
                                                        <div class="mt-1 text-xs font-semibold text-gray-900">{{ $formatDecimal($goalieReasonSummary['shown_xsog_per_game'] ?? 0, 2) }}</div>
                                                        <div>{{ $formatDecimal(((float) ($goalieReasonSummary['represented_xsog'] ?? 0)) * 100, 0) }}% rep</div>
                                                    </div>
                                                    <div>
                                                        <div class="font-semibold uppercase text-gray-500">Shown xG/G</div>
                                                        <div class="mt-1 text-xs font-semibold text-gray-900">{{ $formatDecimal($goalieReasonSummary['shown_xgf_per_game'] ?? 0, 3) }}</div>
                                                        <div>{{ $formatDecimal(((float) ($goalieReasonSummary['represented_xgf'] ?? 0)) * 100, 0) }}% rep</div>
                                                    </div>
                                                    <div>
                                                        <div class="font-semibold uppercase text-gray-500">GSAx/G</div>
                                                        <div class="mt-1 text-xs font-semibold text-gray-900">{{ $formatDecimal($goalieReasonSummary['shown_goalie_saves_above_expected_per_game'] ?? 0, 3) }}</div>
                                                        <div>shown rows</div>
                                                    </div>
                                                    <div>
                                                        <div class="font-semibold uppercase text-gray-500">GA/G</div>
                                                        <div class="mt-1 text-xs font-semibold text-gray-900">{{ $formatDecimal($goalieReasonSummary['shown_goalie_adjusted_xgf_per_game'] ?? 0, 3) }}</div>
                                                        <div>shown rows</div>
                                                    </div>
                                                    <div>
                                                        <div class="font-semibold uppercase text-gray-500">Target</div>
                                                        <div class="mt-1 text-xs font-semibold text-gray-900">{{ $formatDecimal(((float) ($goalieReasonSummary['coverage_target'] ?? 0)) * 100, 0) }}%</div>
                                                        <div>{{ ($goalieReasonSummary['hit_max_rows'] ?? false) ? 'max rows hit' : 'covered' }}</div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="overflow-x-auto">
                                                <table class="w-full table-fixed divide-y divide-gray-200 text-[10px] leading-tight">
                                                    <thead class="bg-gray-50 text-left text-[9px] font-semibold uppercase text-gray-500">
                                                        <tr>
                                                            <th class="px-2 py-2">Str</th>
                                                            <th class="px-2 py-2">Type</th>
                                                            <th class="px-2 py-2">Dist</th>
                                                            <th class="px-2 py-2">Angle</th>
                                                            <th class="px-2 py-2">Seq</th>
                                                            <th class="px-2 py-2">xSAT/G</th>
                                                            <th class="px-2 py-2">xSOG/G</th>
                                                            <th class="px-2 py-2">xG/G</th>
                                                            <th class="px-2 py-2">xSOG Sh</th>
                                                            <th class="px-2 py-2">G Rt</th>
                                                            <th class="px-2 py-2">GSAx/G</th>
                                                            <th class="px-2 py-2">GA/G</th>
                                                            <th class="px-2 py-2">Conf</th>
                                                            <th class="px-2 py-2">Bkts</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-100 bg-white">
                                                        @forelse($side['goalie_reasons'] as $row)
                                                            <tr>
                                                                <td class="truncate px-2 py-2 text-gray-700">{{ ($row['projection_strength'] ?? 'ev') === 'pk' ? 'PP' : strtoupper($row['projection_strength'] ?? 'ev') }}</td>
                                                                <td class="truncate px-2 py-2 text-gray-700" title="{{ $row['shot_type_group'] ?? 'Any' }}">{{ $row['shot_type_group'] ?? 'Any' }}</td>
                                                                <td class="truncate px-2 py-2 text-gray-700" title="{{ $row['distance_group'] ?? 'Any' }}">{{ $row['distance_group'] ?? 'Any' }}</td>
                                                                <td class="truncate px-2 py-2 text-gray-700" title="{{ $row['angle_group'] ?? 'Any' }}">{{ $row['angle_group'] ?? 'Any' }}</td>
                                                                <td class="truncate px-2 py-2 text-gray-700" title="{{ $row['sequence_group'] ?? 'Any' }}">{{ $row['sequence_group'] ?? 'Any' }}</td>
                                                                <td class="px-2 py-2 text-gray-700">{{ $formatDecimal($row['adjusted_xsat_per_game'] ?? 0, 3) }}</td>
                                                                <td class="px-2 py-2 text-gray-700">{{ $formatDecimal($row['adjusted_xsog_per_game'] ?? 0, 3) }}</td>
                                                                <td class="px-2 py-2 text-gray-700">{{ $formatDecimal($row['adjusted_xgf_per_game'] ?? ($row['goalie_adjusted_xgf_per_game'] - $row['goalie_adjustment_per_game']), 3) }}</td>
                                                                <td class="px-2 py-2 text-gray-700">{{ isset($row['represented_xsog']) ? $formatDecimal(((float) $row['represented_xsog']) * 100, 1) . '%' : 'N/A' }}</td>
                                                                <td class="px-2 py-2 text-gray-700">{{ isset($row['goalie_ga_xga_ratio']) ? $formatDecimal($row['goalie_ga_xga_ratio'], 2) : 'N/A' }}</td>
                                                                <td class="px-2 py-2 text-gray-700">{{ $formatDecimal($row['goalie_saves_above_expected_per_game'] ?? (-1 * (float) ($row['goalie_adjustment_per_game'] ?? 0)), 3) }}</td>
                                                                <td class="px-2 py-2 text-gray-700">{{ $formatDecimal($row['goalie_adjusted_xgf_per_game'], 3) }}</td>
                                                                <td class="px-2 py-2 text-gray-700">{{ $row['goalie_confidence'] === null ? 'N/A' : $formatDecimal(((float) $row['goalie_confidence']) * 100, 0) . '%' }}</td>
                                                                <td class="px-2 py-2 text-gray-700" title="{{ $row['goalie_reason_source'] ?? 'bucket' }}">{{ $formatNumber($row['bucket_count'] ?? 1) }}</td>
                                                            </tr>
                                                        @empty
                                                            <tr><td colspan="14" class="px-3 py-6 text-center text-xs text-gray-500">No projected goalie rows found for the selected goalie/version.</td></tr>
                                                        @endforelse
                                                    </tbody>
                                                </table>
                                            </div>
                                        </details>
                                    @endforeach
                                </div>

                                <div class="border border-gray-200">
                                    <div class="border-b border-gray-200 px-4 py-3">
                                        <h5 class="text-sm font-semibold text-gray-900">Roster xGF/G Comparison</h5>
                                    </div>
                                    <div class="grid gap-0 lg:grid-cols-2">
                                        @foreach($matchupResult['sides'] as $side)
                                            <div class="overflow-x-auto border-b border-gray-200 lg:border-b-0 lg:border-r lg:last:border-r-0">
                                                <table class="w-full table-fixed divide-y divide-gray-200 text-[10px] leading-tight">
                                                    <thead class="bg-gray-50 text-left text-[9px] font-semibold uppercase text-gray-500">
                                                        <tr>
                                                            <th class="w-36 px-2 py-2">{{ $side['offense_team'] }}</th>
                                                            <th class="px-2 py-2">Pos</th>
                                                            <th class="px-2 py-2">xGF/G</th>
                                                            <th class="px-2 py-2">Share</th>
                                                            <th class="px-2 py-2">Conf</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-100 bg-white">
                                                        @forelse($side['roster'] as $row)
                                                            <tr>
                                                                <td class="truncate px-2 py-2 font-medium text-gray-900" title="{{ $row['player_name'] }}">{{ $row['player_name'] }}</td>
                                                                <td class="truncate px-2 py-2 text-gray-700">{{ $row['position'] ?? 'N/A' }}</td>
                                                                <td class="px-2 py-2 text-gray-700">{{ $formatDecimal($row['baseline_xgf_per_game'], 3) }}</td>
                                                                <td class="px-2 py-2 text-gray-700">{{ $formatDecimal(((float) $row['team_xgf_share']) * 100, 1) }}%</td>
                                                                <td class="px-2 py-2 text-gray-700">
                                                                    {{ $row['confidence_score'] === null ? 'N/A' : $formatDecimal(((float) $row['confidence_score']) * 100, 0) . '%' }}
                                                                    <div class="truncate text-[9px] text-gray-500">{{ $row['confidence_bucket'] ?? 'unscored' }}</div>
                                                                </td>
                                                            </tr>
                                                        @empty
                                                            <tr><td colspan="5" class="px-3 py-6 text-center text-xs text-gray-500">No roster projection rows found.</td></tr>
                                                        @endforelse
                                                    </tbody>
                                                </table>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @else
                                <div class="border border-gray-200 px-4 py-6 text-sm text-gray-600">
                                    Select both teams, source/target seasons, skater projection version, and TOI version to simulate.
                                </div>
                            @endif
                        </div>
                    @else
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    @foreach([
                                        'check' => 'Check',
                                        'rows' => 'Rows',
                                    ] as $sortKey => $label)
                                        <th class="px-4 py-3">
                                            <a href="{{ $sortUrl($sortKey, 'qa') }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                <span>{{ $label }}</span>
                                                @if($sortArrow($sortKey) !== '')
                                                    <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $sortArrow($sortKey) }}</span>
                                                @endif
                                            </a>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @foreach($qaTableRows as $row)
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900">{{ $row->check }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->rows) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
