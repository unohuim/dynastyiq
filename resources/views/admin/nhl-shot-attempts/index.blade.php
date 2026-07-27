@php
    $tabs = [
        'explorer' => 'Explorer',
        'aggregates' => 'Aggregates',
        'buckets' => 'Buckets',
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
        'is_rebound' => 'Rebound',
        'previous_event_type' => 'Previous Event',
    ];
    $formatNumber = fn ($value) => number_format((int) $value);
    $formatDecimal = fn ($value, $places = 2) => $value === null ? 'N/A' : number_format((float) $value, $places);
    $tabUrl = fn ($tab) => route('admin.nhl-shot-attempts.index', array_merge(request()->except('page'), ['tab' => $tab]));
    $sortUrl = fn ($key) => route('admin.nhl-shot-attempts.index', array_merge(
        request()->except(['page', 'sort', 'direction']),
        [
            'tab' => 'aggregates',
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
                                    <th class="px-4 py-3">Game</th>
                                    <th class="px-4 py-3">Time</th>
                                    <th class="px-4 py-3">Team</th>
                                    <th class="px-4 py-3">Shooter</th>
                                    <th class="px-4 py-3">Goalie</th>
                                    <th class="px-4 py-3">Result</th>
                                    <th class="px-4 py-3">Distance</th>
                                    <th class="px-4 py-3">Angle</th>
                                    <th class="px-4 py-3">Context</th>
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
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->strength_bucket ?? 'N/A' }}<div class="text-xs text-gray-500">{{ $row->is_rebound ? 'rebound' : 'no rebound' }} · {{ $row->is_rush ? 'rush' : 'settled' }}</div></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="9" class="px-4 py-8 text-center text-sm text-gray-500">No shot attempts match these filters.</td></tr>
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
                                        'goals' => 'Goals',
                                        'goal_rate' => 'Goal Rate',
                                        'avg_distance' => 'Avg Dist',
                                        'avg_angle' => 'Avg Angle',
                                    ] as $sortKey => $label)
                                        <th class="px-4 py-3">
                                            <a href="{{ $sortUrl($sortKey) }}" class="inline-flex items-center gap-1 hover:text-gray-900">
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
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->goals) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->goal_rate) }}{{ $row->goal_rate === null ? '' : '%' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->avg_distance) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->avg_angle, 1) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="px-4 py-8 text-center text-sm text-gray-500">No aggregate rows match these filters.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    @elseif($activeTab === 'buckets')
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-4 py-3">Distance</th>
                                    <th class="px-4 py-3">Angle</th>
                                    <th class="px-4 py-3">Strength</th>
                                    <th class="px-4 py-3">Attempts</th>
                                    <th class="px-4 py-3">SOG</th>
                                    <th class="px-4 py-3">Goals</th>
                                    <th class="px-4 py-3">Goal Rate</th>
                                    <th class="px-4 py-3">Avg Dist</th>
                                    <th class="px-4 py-3">Avg Angle</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @forelse($bucketRows as $row)
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900">{{ $row->distance_bucket ?? 'N/A' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->angle_bucket ?? 'N/A' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row->strength_bucket ?? 'N/A' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->attempts) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->shots_on_goal) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($row->goals) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ ((int) $row->attempts) > 0 ? $formatDecimal(((int) $row->goals / (int) $row->attempts) * 100) . '%' : 'N/A' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->avg_distance) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatDecimal($row->avg_angle, 1) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="9" class="px-4 py-8 text-center text-sm text-gray-500">No bucket rows match these filters.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    @else
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-4 py-3">Check</th>
                                    <th class="px-4 py-3">Rows</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @foreach([
                                    'Total attempts' => $qaRows->attempts ?? 0,
                                    'Missing geometry' => $qaRows->missing_geometry ?? 0,
                                    'Missing team' => $qaRows->missing_team ?? 0,
                                    'Missing shooter' => $qaRows->missing_shooter ?? 0,
                                    'Missing result' => $qaRows->missing_result ?? 0,
                                    'Missing distance bucket' => $qaRows->missing_distance_bucket ?? 0,
                                    'Missing angle bucket' => $qaRows->missing_angle_bucket ?? 0,
                                    'Goals not marked SOG' => $qaRows->goals_not_sog ?? 0,
                                ] as $label => $value)
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900">{{ $label }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $formatNumber($value) }}</td>
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
