@php
    $label = fn ($value) => str($value)->replace(['_', '-'], ' ')->title();
    $formatRate = fn ($value) => $value === null ? '-' : number_format((float) $value, 2);
    $formatPct = fn ($value) => $value === null ? '-' : number_format(((float) $value) * 100, 1) . '%';
    $formatDelta = fn ($value) => $value === null ? '-' : (((float) $value) >= 0 ? '+' : '') . number_format((float) $value, 2);
    $formatDeltaPct = fn ($value) => $value === null ? '-' : (((float) $value) >= 0 ? '+' : '') . number_format(((float) $value) * 100, 1) . '%';
    $deltaClass = fn ($value) => $value === null ? 'text-gray-400' : (((float) $value) >= 0 ? 'text-emerald-700' : 'text-rose-700');
    $sortUrl = function (string $key) use ($run, $profileType, $sort, $direction, $search): string {
        return route('admin.nhl-sat-models.rate-projections.compare.raw', [
            'run' => $run,
            'profile_type' => $profileType,
            'sort' => $key,
            'direction' => $sort === $key && $direction === 'desc' ? 'asc' : 'desc',
            'q' => $search !== '' ? $search : null,
        ]);
    };
    $sortArrow = fn (string $key): string => $sort === $key ? ($direction === 'desc' ? 'v' : '^') : '';
    $shareCell = function ($train, $test, $drift, $driftRate) use ($formatPct, $formatDeltaPct, $deltaClass): string {
        return sprintf(
            '<div class="space-y-0.5 tabular-nums"><div class="flex justify-between gap-2"><span class="text-gray-400">Train</span><span class="font-semibold text-gray-950">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Test</span><span class="font-semibold text-gray-700">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Drift</span><span class="font-semibold %s">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Drift %%</span><span class="font-semibold %s">%s</span></div></div>',
            e($formatPct($train)),
            e($formatPct($test)),
            e($deltaClass($drift)),
            e($formatDeltaPct($drift)),
            e($deltaClass($driftRate)),
            e($formatDeltaPct($driftRate)),
        );
    };
    $rateCell = function ($train, $projected, $test, $drift, $driftRate, $error, $errorRate) use ($formatRate, $formatDelta, $formatDeltaPct, $deltaClass): string {
        return sprintf(
            '<div class="space-y-0.5 tabular-nums"><div class="flex justify-between gap-2"><span class="text-gray-400">Train</span><span class="font-semibold text-gray-950">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Proj</span><span class="font-semibold text-gray-950">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Test</span><span class="font-semibold text-gray-700">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Drift</span><span class="font-semibold %s">%s / %s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Error</span><span class="font-semibold %s">%s / %s</span></div></div>',
            e($formatRate($train)),
            e($formatRate($projected)),
            e($formatRate($test)),
            e($deltaClass($drift)),
            e($formatDelta($drift)),
            e($formatDeltaPct($driftRate)),
            e($deltaClass($error)),
            e($formatDelta($error)),
            e($formatDeltaPct($errorRate)),
        );
    };
@endphp

<x-app-layout>
    <div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
                <a href="{{ route('admin.nhl-sat-models.index') }}" class="text-xs font-medium text-gray-500 transition-colors hover:text-gray-950">SAT Models</a>
                <h1 class="mt-1 text-xl font-semibold tracking-normal text-gray-950">{{ $run->name }}</h1>
                <p class="mt-1 text-sm text-gray-500">Raw bucket-level /60 comparison against the held-out test season.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.nhl-sat-models.rate-projections.compare.aggregates', $run) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 hover:text-gray-950">
                    Aggregate Compare /60
                </a>
                <a href="{{ route('admin.nhl-sat-models.rate-projections', $run) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 hover:text-gray-950">
                    View /60
                </a>
            </div>
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-4">
            <div class="border border-gray-200 bg-white px-3 py-2">
                <div class="text-[11px] font-medium uppercase text-gray-500">Training Seasons</div>
                <div class="mt-1 text-sm font-semibold text-gray-950">{{ collect($run->train_season_ids ?? [])->implode(', ') }}</div>
            </div>
            <div class="border border-gray-200 bg-white px-3 py-2">
                <div class="text-[11px] font-medium uppercase text-gray-500">Test Season</div>
                <div class="mt-1 text-sm font-semibold text-gray-950">{{ $run->target_season_id ?? 'None' }}</div>
            </div>
            <div class="border border-gray-200 bg-white px-3 py-2">
                <div class="text-[11px] font-medium uppercase text-gray-500">Projection Rows</div>
                <div class="mt-1 text-sm font-semibold text-gray-950">{{ number_format((int) collect($summary)->sum('rows')) }}</div>
            </div>
            <div class="border border-gray-200 bg-white px-3 py-2">
                <div class="text-[11px] font-medium uppercase text-gray-500">Exact Matches</div>
                <div class="mt-1 text-sm font-semibold text-gray-950">{{ number_format((int) collect($summary)->sum('matched_rows')) }}</div>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap gap-2">
            @foreach($profileTypes as $type => $typeLabel)
                @php
                    $typeSummary = $summary->get($type);
                @endphp
                <a
                    href="{{ route('admin.nhl-sat-models.rate-projections.compare.raw', ['run' => $run, 'profile_type' => $type, 'sort' => $sort, 'direction' => $direction, 'q' => $search !== '' ? $search : null]) }}"
                    class="inline-flex items-center gap-2 rounded-md border px-3 py-2 text-xs font-semibold transition-colors {{ $profileType === $type ? 'border-gray-950 bg-gray-950 text-white' : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 hover:text-gray-950' }}"
                >
                    <span>{{ $typeLabel }}</span>
                    <span class="{{ $profileType === $type ? 'text-gray-300' : 'text-gray-400' }}">{{ number_format((int) ($typeSummary->entities ?? 0)) }}</span>
                </a>
            @endforeach
        </div>

        <form method="GET" action="{{ route('admin.nhl-sat-models.rate-projections.compare.raw', $run) }}" class="mb-4 flex flex-wrap items-center gap-3 border border-gray-200 bg-white px-3 py-2">
            <input type="hidden" name="profile_type" value="{{ $profileType }}">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">
            <input
                type="search"
                name="q"
                value="{{ $search }}"
                placeholder="Search comparisons"
                class="h-8 w-full max-w-xs rounded-md border-gray-300 text-xs text-gray-950 shadow-sm focus:border-gray-950 focus:ring-gray-950"
            >
            <button type="submit" class="inline-flex h-8 items-center rounded-md border border-gray-300 bg-white px-3 text-xs font-semibold text-gray-700 transition-colors hover:border-gray-400 hover:text-gray-950">
                Search
            </button>
            <span class="text-xs text-gray-400">Drift is test minus train. Error is test minus projection. Other rows need held-out long-tail aggregation before exact comparison.</span>
        </form>

        <div class="border border-gray-200 bg-white">
            @if($comparisons->count() === 0)
                <div class="px-5 py-12 text-center">
                    <div class="text-sm font-semibold text-gray-950">No comparison rows yet</div>
                    <div class="mt-1 text-sm text-gray-500">Build /60 and build profiles with a test season first.</div>
                </div>
            @else
                <table class="w-full table-fixed divide-y divide-gray-200 text-[10px]">
                    <thead class="bg-gray-50 text-left font-semibold uppercase text-gray-500">
                        <tr>
                            <th class="w-[13%] px-1.5 py-2"><a href="{{ $sortUrl('entity') }}">Entity {{ $sortArrow('entity') }}</a></th>
                            <th class="w-[21%] px-1.5 py-2"><a href="{{ $sortUrl('bucket') }}">Profile {{ $sortArrow('bucket') }}</a></th>
                            <th class="w-[13%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('share_drift') }}">SAT Share {{ $sortArrow('share_drift') }}</a></th>
                            <th class="w-[17%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('projected_xsat_per_60') }}">xSAT/60 {{ $sortArrow('projected_xsat_per_60') }}</a></th>
                            <th class="w-[17%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('projected_xsog_per_60') }}">xSOG/60 {{ $sortArrow('projected_xsog_per_60') }}</a></th>
                            <th class="w-[14%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('projected_xg_per_60') }}">xG/60 {{ $sortArrow('projected_xg_per_60') }}</a></th>
                            <th class="w-[5%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('source_sat') }}">SAT {{ $sortArrow('source_sat') }}</a></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white text-gray-700">
                        @foreach($comparisons as $comparison)
                            @php
                                $dimensions = collect((array) json_decode((string) $comparison->bucket_dimensions, true));
                                $bucketLabel = $comparison->is_other_bucket
                                    ? 'Other'
                                    : $dimensions
                                        ->reject(fn ($value, $key) => $key === 'baseline')
                                        ->map(fn ($value, $key) => $label($key) . ': ' . $label($value))
                                        ->implode(' · ');
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="px-1.5 py-2 align-top">
                                    <div class="truncate font-semibold text-gray-950">{{ $comparison->entity_name ?? $comparison->entity_key }}</div>
                                    <div class="truncate text-gray-400">{{ $comparison->entity_role ?? $comparison->profile_type }} · {{ $comparison->team_context ?? '-' }}</div>
                                </td>
                                <td class="px-1.5 py-2 align-top">
                                    <div class="line-clamp-2 font-medium text-gray-950">{{ $bucketLabel !== '' ? $bucketLabel : $comparison->matched_bucket_key }}</div>
                                    <div class="truncate text-gray-400">{{ $comparison->matched_bucket_key }}</div>
                                </td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $shareCell($comparison->train_profile_share, $comparison->test_profile_share, $comparison->share_drift, $comparison->share_drift_rate) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $rateCell($comparison->train_xsat_per_60, $comparison->projected_xsat_per_60, $comparison->test_xsat_per_60, $comparison->xsat_drift, $comparison->xsat_drift_rate, $comparison->xsat_error, $comparison->xsat_error_rate) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $rateCell($comparison->train_xsog_per_60, $comparison->projected_xsog_per_60, $comparison->test_xsog_per_60, $comparison->xsog_drift, $comparison->xsog_drift_rate, $comparison->xsog_error, $comparison->xsog_error_rate) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $rateCell($comparison->train_xg_per_60, $comparison->projected_xg_per_60, $comparison->test_xg_per_60, $comparison->xg_drift, $comparison->xg_drift_rate, $comparison->xg_error, $comparison->xg_error_rate) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">
                                    <div class="font-semibold text-gray-950">{{ number_format((int) $comparison->train_sat) }}</div>
                                    <div class="text-gray-400">test {{ $comparison->test_sat === null ? '-' : number_format((int) $comparison->test_sat) }}</div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="border-t border-gray-200 px-3 py-2">
                    {{ $comparisons->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
