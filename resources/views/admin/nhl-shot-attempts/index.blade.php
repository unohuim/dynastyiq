@php
    $tabs = [
        'explorer' => 'Explorer',
        'aggregates' => 'Aggregates',
        'buckets' => 'Buckets',
        'predictive' => 'Predictive',
        'biometrics' => 'Biometrics',
        'xg' => 'xG',
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
    $xgAccordionState = fn ($key) => "{ key: 'dynastyiq:admin:nhl-shot-attempts:xg:accordion:{$key}', open: false, init() { try { this.open = JSON.parse(window.localStorage?.getItem(this.key) || 'false') === true } catch (e) { this.open = false } }, toggle() { this.open = !this.open; try { window.localStorage?.setItem(this.key, JSON.stringify(this.open)) } catch (e) {} } }";
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
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    @foreach([
                                        'distance_bucket' => 'Distance',
                                        'angle_bucket' => 'Angle',
                                        'strength_bucket' => 'Strength',
                                        'shot_side' => 'Side',
                                        'is_off_wing_attempt' => 'Off-Wing',
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
                                        <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900">{{ $row->distance_bucket ?? 'N/A' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->angle_bucket ?? 'N/A' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->strength_bucket ?? 'N/A' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->shot_side ?? 'N/A' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatOffWing($row->is_off_wing_attempt) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->attempts) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->shots_on_goal) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->sog_rate) }}{{ $row->sog_rate === null ? '' : '%' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->goals) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->goal_rate) }}{{ $row->goal_rate === null ? '' : '%' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->avg_distance) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->avg_angle, 1) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="12" class="px-4 py-8 text-center text-sm text-gray-500">No bucket rows match these filters.</td></tr>
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
                            @endunless
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
