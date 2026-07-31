@php
    $tabs = [
        'teams' => 'Teams',
        'players' => 'Players',
        'units' => 'Units',
        'games' => 'Games',
    ];
    $formatNumber = fn ($value) => number_format((int) $value);
    $formatDecimal = fn ($value, $places = 2) => $value === null ? 'N/A' : number_format((float) $value, $places);
    $tabUrl = fn ($tab) => route('admin.nhl-faceoffs.index', array_merge(request()->except(['page', 'sort', 'direction']), ['tab' => $tab]));
    $sortUrl = fn ($key, $tab = null) => route('admin.nhl-faceoffs.index', array_merge(
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
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-1">
            <h2 class="text-xl font-semibold leading-tight text-gray-900">NHL Faceoffs</h2>
            <p class="text-sm text-gray-600">Review faceoff volume, zone starts, winners, units, and post-draw advancement.</p>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            @unless($tableExists)
                <div class="mb-4 border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    The faceoff facts table is not available in this environment yet.
                </div>
            @endunless

            <div class="border border-gray-200 bg-white">
                <div class="border-b border-gray-200 px-5 py-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">Faceoff Facts</h3>
                            <p class="mt-1 text-sm text-gray-600">Use Game Imports Process -> Faceoffs to build or refresh this dataset.</p>
                        </div>
                        <a href="{{ route('admin.dashboard', ['tab' => 'game-imports']) }}" class="inline-flex min-h-9 items-center rounded-md border border-gray-300 px-3 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Game Imports
                        </a>
                    </div>
                </div>

                <div class="grid gap-3 border-b border-gray-200 px-5 py-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-9">
                    <div>
                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Faceoffs</div>
                        <div class="mt-1 text-lg font-semibold text-gray-900">{{ $formatNumber($summary['faceoffs']) }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Games</div>
                        <div class="mt-1 text-lg font-semibold text-gray-900">{{ $formatNumber($summary['games']) }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Advanced</div>
                        <div class="mt-1 text-lg font-semibold text-gray-900">{{ $formatNumber($summary['advanced']) }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Held</div>
                        <div class="mt-1 text-lg font-semibold text-gray-900">{{ $formatNumber($summary['held']) }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Retreated</div>
                        <div class="mt-1 text-lg font-semibold text-gray-900">{{ $formatNumber($summary['retreated']) }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Advanced Rate</div>
                        <div class="mt-1 text-lg font-semibold text-gray-900">{{ $summary['advanced_rate'] === null ? 'N/A' : $formatDecimal($summary['advanced_rate']) . '%' }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Held Rate</div>
                        <div class="mt-1 text-lg font-semibold text-gray-900">{{ $summary['held_rate'] === null ? 'N/A' : $formatDecimal($summary['held_rate']) . '%' }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Retreated Rate</div>
                        <div class="mt-1 text-lg font-semibold text-gray-900">{{ $summary['retreated_rate'] === null ? 'N/A' : $formatDecimal($summary['retreated_rate']) . '%' }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Missing Next</div>
                        <div class="mt-1 text-lg font-semibold text-gray-900">{{ $formatNumber($summary['missing_next_event']) }}</div>
                    </div>
                </div>

                <form method="GET" action="{{ route('admin.nhl-faceoffs.index') }}" class="border-b border-gray-200 px-5 py-4">
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
                            <span class="text-xs font-medium text-gray-600">Zone</span>
                            <select name="zone_bucket" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">All</option>
                                @foreach($options['zones'] as $option)
                                    <option value="{{ $option }}" @selected($filters['zone_bucket'] === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-600">Advancement</span>
                            <select name="advancement_bucket" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">All</option>
                                @foreach($options['advancements'] as $option)
                                    <option value="{{ $option }}" @selected($filters['advancement_bucket'] === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-600">Strength</span>
                            <select name="strength_bucket" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">All</option>
                                @foreach($options['strengths'] as $option)
                                    <option value="{{ $option }}" @selected($filters['strength_bucket'] === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                        </label>
                        @unless($activeTab === 'games')
                            <label class="block">
                                <span class="text-xs font-medium text-gray-600">Min Faceoffs</span>
                                <input type="number" name="min_faceoffs" min="1" value="{{ $filters['min_faceoffs'] }}" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </label>
                        @endunless
                    </div>
                    <div class="mt-4 flex flex-wrap justify-end gap-2">
                        <a href="{{ route('admin.nhl-faceoffs.index', ['tab' => $activeTab]) }}" class="inline-flex min-h-10 items-center rounded-md border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Clear</a>
                        <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Apply</button>
                    </div>
                </form>

                <div class="border-b border-gray-200 px-5">
                    <nav class="-mb-px flex flex-wrap gap-5" aria-label="Faceoff tabs">
                        @foreach($tabs as $tab => $label)
                            <a href="{{ $tabUrl($tab) }}" class="border-b-2 px-1 py-3 text-sm font-semibold {{ $activeTab === $tab ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </nav>
                </div>

                <div class="overflow-x-auto">
                    @if($activeTab === 'teams')
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    @foreach([
                                        'team_abbrev' => 'Team',
                                        'team_id' => 'Team ID',
                                        'faceoffs' => 'Faceoffs',
                                        'offensive_zone' => 'OZ',
                                        'neutral_zone' => 'NZ',
                                        'defensive_zone' => 'DZ',
                                        'advanced_rate' => 'Advanced %',
                                        'held_rate' => 'Held %',
                                        'retreated_rate' => 'Retreated %',
                                        'advanced' => 'Advanced',
                                        'held' => 'Held',
                                        'retreated' => 'Retreated',
                                    ] as $sortKey => $label)
                                        <th class="px-4 py-3">
                                            <a href="{{ $sortUrl($sortKey, 'teams') }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                <span>{{ $label }}</span>
                                                <span>{{ $sortArrow($sortKey) }}</span>
                                            </a>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @forelse($teamRows as $row)
                                    <tr>
                                        <td class="px-4 py-3 font-semibold text-gray-900">{{ $row->team_abbrev ?? 'N/A' }}</td>
                                        <td class="px-4 py-3 text-gray-600">{{ $row->team_id ?? 'N/A' }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->faceoffs) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->offensive_zone) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->neutral_zone) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->defensive_zone) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatDecimal($row->advanced_rate) }}%</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatDecimal($row->held_rate) }}%</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatDecimal($row->retreated_rate) }}%</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->advanced) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->held) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->retreated) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="12" class="px-4 py-8 text-center text-sm text-gray-500">No faceoff rows match these filters.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    @elseif($activeTab === 'players')
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    @foreach([
                                        'player_name' => 'Player',
                                        'player_id' => 'NHL ID',
                                        'team_abbrev' => 'Team',
                                        'faceoffs' => 'Faceoffs',
                                        'offensive_zone' => 'OZ',
                                        'neutral_zone' => 'NZ',
                                        'defensive_zone' => 'DZ',
                                        'advanced_rate' => 'Advanced %',
                                        'held_rate' => 'Held %',
                                        'retreated_rate' => 'Retreated %',
                                        'advanced' => 'Advanced',
                                        'held' => 'Held',
                                        'retreated' => 'Retreated',
                                    ] as $sortKey => $label)
                                        <th class="px-4 py-3">
                                            <a href="{{ $sortUrl($sortKey, 'players') }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                <span>{{ $label }}</span>
                                                <span>{{ $sortArrow($sortKey) }}</span>
                                            </a>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @forelse($playerRows as $row)
                                    <tr>
                                        <td class="px-4 py-3 font-semibold text-gray-900">{{ $row->player_name ?? 'N/A' }}</td>
                                        <td class="px-4 py-3 text-gray-600">{{ $row->player_id ?? 'N/A' }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $row->team_abbrev ?? 'N/A' }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->faceoffs) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->offensive_zone) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->neutral_zone) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->defensive_zone) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatDecimal($row->advanced_rate) }}%</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatDecimal($row->held_rate) }}%</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatDecimal($row->retreated_rate) }}%</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->advanced) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->held) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->retreated) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="13" class="px-4 py-8 text-center text-sm text-gray-500">No player rows match these filters.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    @elseif($activeTab === 'units')
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    @foreach([
                                        'unit_id' => 'Unit',
                                        'team_abbrev' => 'Team',
                                        'faceoffs' => 'Faceoffs',
                                        'offensive_zone' => 'OZ',
                                        'neutral_zone' => 'NZ',
                                        'defensive_zone' => 'DZ',
                                        'advanced_rate' => 'Advanced %',
                                        'held_rate' => 'Held %',
                                        'retreated_rate' => 'Retreated %',
                                        'advanced' => 'Advanced',
                                        'held' => 'Held',
                                        'retreated' => 'Retreated',
                                    ] as $sortKey => $label)
                                        <th class="px-4 py-3">
                                            <a href="{{ $sortUrl($sortKey, 'units') }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                <span>{{ $label }}</span>
                                                <span>{{ $sortArrow($sortKey) }}</span>
                                            </a>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @forelse($unitRows as $row)
                                    <tr>
                                        <td class="px-4 py-3 font-semibold text-gray-900">{{ $row->unit_id ?? 'N/A' }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $row->team_abbrev ?? 'N/A' }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->faceoffs) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->offensive_zone) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->neutral_zone) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->defensive_zone) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatDecimal($row->advanced_rate) }}%</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatDecimal($row->held_rate) }}%</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatDecimal($row->retreated_rate) }}%</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->advanced) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->held) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->retreated) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="12" class="px-4 py-8 text-center text-sm text-gray-500">No unit rows match these filters.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    @else
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    @foreach([
                                        'game_date' => 'Date',
                                        'nhl_game_id' => 'Game',
                                        'matchup' => 'Matchup',
                                        'faceoffs' => 'Faceoffs',
                                        'offensive_zone' => 'OZ',
                                        'neutral_zone' => 'NZ',
                                        'defensive_zone' => 'DZ',
                                        'advanced_rate' => 'Advanced %',
                                        'held_rate' => 'Held %',
                                        'retreated_rate' => 'Retreated %',
                                        'advanced' => 'Advanced',
                                        'held' => 'Held',
                                        'retreated' => 'Retreated',
                                        'missing_next_event' => 'Missing Next',
                                    ] as $sortKey => $label)
                                        <th class="px-4 py-3">
                                            <a href="{{ $sortUrl($sortKey, 'games') }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                                <span>{{ $label }}</span>
                                                <span>{{ $sortArrow($sortKey) }}</span>
                                            </a>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @forelse($gameRows as $row)
                                    <tr>
                                        <td class="px-4 py-3 text-gray-900">{{ $row->game_date ?? 'N/A' }}</td>
                                        <td class="px-4 py-3 font-semibold text-gray-900">{{ $row->nhl_game_id }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $row->away_team_abbrev ?? 'N/A' }} @ {{ $row->home_team_abbrev ?? 'N/A' }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->faceoffs) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->offensive_zone) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->neutral_zone) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->defensive_zone) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatDecimal($row->advanced_rate) }}%</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatDecimal($row->held_rate) }}%</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatDecimal($row->retreated_rate) }}%</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->advanced) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->held) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->retreated) }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ $formatNumber($row->missing_next_event) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="14" class="px-4 py-8 text-center text-sm text-gray-500">No game rows match these filters.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
