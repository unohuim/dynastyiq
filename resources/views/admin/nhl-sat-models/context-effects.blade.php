<x-app-layout>
    @php
        $sortUrl = function (string $column) use ($sort, $direction, $selectedSeasonId, $selectedMetric, $selectedFactor): string {
            return route('admin.nhl-sat-models.context.effects', [
                'season_id' => $selectedSeasonId,
                'metric' => $selectedMetric,
                'factor' => $selectedFactor,
                'sort' => $column,
                'direction' => $sort === $column && $direction === 'desc' ? 'asc' : 'desc',
            ]);
        };
        $sortMark = function (string $column) use ($sort, $direction): string {
            return $sort === $column ? ($direction === 'desc' ? '↓' : '↑') : '↕';
        };
    @endphp

    <div class="min-h-screen bg-gray-50 py-6">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 text-xs font-medium text-gray-500">
                        <a href="{{ route('admin.nhl-sat-models.index') }}" class="transition-colors hover:text-gray-950">SAT Models</a>
                        <span>/</span>
                        <span>Pregame Impacts</span>
                    </div>
                    <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-950">Pregame Impacts</h1>
                    <p class="mt-1 max-w-3xl text-sm text-gray-600">Compare actual next-game EV results across groups created only from evidence available before each game. This is analysis, not a prediction adjustment.</p>
                </div>
                <a href="{{ route('admin.nhl-sat-models.index') }}" class="inline-flex min-h-10 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50">SAT Models</a>
            </div>

            <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <form method="GET" action="{{ route('admin.nhl-sat-models.context.effects') }}" class="flex flex-wrap items-end gap-3">
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold text-gray-700">Season</span>
                        <select name="season_id" class="min-h-10 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="all" @selected($selectedSeasonId === 'all')>All</option>
                            @foreach($seasonIds as $seasonId)
                                <option value="{{ $seasonId }}" @selected($selectedSeasonId === $seasonId)>{{ $seasonId }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold text-gray-700">Actual outcome</span>
                        <select name="metric" class="min-h-10 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach($metrics as $value => $label)
                                <option value="{{ $value }}" @selected($selectedMetric === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold text-gray-700">Pregame factor</span>
                        <select name="factor" class="min-h-10 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach($factors as $value => $label)
                                <option value="{{ $value }}" @selected($selectedFactor === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-gray-950 px-4 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-gray-800">Analyze</button>
                </form>
            </section>

            <section class="mt-6 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-5 py-4">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-950">{{ $factors[$selectedFactor] }}</h2>
                        <p class="mt-1 text-sm text-gray-500">Baseline: {{ $baseline['actual_average'] === null ? 'No EV outcome samples' : number_format($baseline['actual_average'], 2) . ' across ' . number_format($baseline['sample_size']) . ' player-games' }}</p>
                    </div>
                    <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">{{ $metrics[$selectedMetric] }}</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                        <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-5 py-3"><a href="{{ $sortUrl('group') }}" class="inline-flex items-center gap-1 transition-colors hover:text-gray-950">Context group <span aria-hidden="true">{{ $sortMark('group') }}</span></a></th>
                                <th class="px-5 py-3 text-right"><a href="{{ $sortUrl('sample_size') }}" class="inline-flex items-center gap-1 transition-colors hover:text-gray-950">Player-games <span aria-hidden="true">{{ $sortMark('sample_size') }}</span></a></th>
                                <th class="px-5 py-3 text-right"><a href="{{ $sortUrl('actual_average') }}" class="inline-flex items-center gap-1 transition-colors hover:text-gray-950">Actual average <span aria-hidden="true">{{ $sortMark('actual_average') }}</span></a></th>
                                <th class="px-5 py-3 text-right"><a href="{{ $sortUrl('delta_from_baseline') }}" class="inline-flex items-center gap-1 transition-colors hover:text-gray-950">vs baseline <span aria-hidden="true">{{ $sortMark('delta_from_baseline') }}</span></a></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($rows as $row)
                                <tr>
                                    <td class="px-5 py-3 font-medium text-gray-950">{{ $row['group'] }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums text-gray-700">{{ number_format($row['sample_size']) }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums text-gray-900">{{ number_format($row['actual_average'], 2) }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums {{ $row['delta_from_baseline'] > 0 ? 'text-emerald-700' : ($row['delta_from_baseline'] < 0 ? 'text-red-700' : 'text-gray-600') }}">{{ $row['delta_from_baseline'] > 0 ? '+' : '' }}{{ number_format($row['delta_from_baseline'], 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-5 py-10 text-center text-sm text-gray-500">No context rows with a matching EV outcome are available for this season.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
