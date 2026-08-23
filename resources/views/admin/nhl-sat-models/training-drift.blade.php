@php
    $label = fn ($value) => str($value)->replace(['_', '-'], ' ')->title();
    $formatCount = fn ($value) => $value === null ? '-' : number_format((int) $value);
    $formatAverageCount = fn ($value) => $value === null ? '-' : number_format((float) $value, 2);
    $formatRate = fn ($value) => $value === null ? '-' : number_format((float) $value, 2);
    $formatPct = fn ($value) => $value === null ? '-' : number_format(((float) $value) * 100, 1) . '%';
    $formatDeltaRate = fn ($value) => $value === null ? '-' : (((float) $value) >= 0 ? '+' : '') . number_format((float) $value, 2);
    $formatDeltaPct = fn ($value) => $value === null ? '-' : (((float) $value) >= 0 ? '+' : '') . number_format(((float) $value) * 100, 1) . '%';
    $deltaClass = fn ($value) => $value === null ? 'text-gray-400' : (((float) $value) >= 0 ? 'text-emerald-700' : 'text-rose-700');
    $sortUrl = function (string $key) use ($run, $profileType, $sort, $direction, $search): string {
        return route('admin.nhl-sat-models.profiles.training-drift', [
            'run' => $run,
            'profile_type' => $profileType,
            'sort' => $key,
            'direction' => $sort === $key && $direction === 'desc' ? 'asc' : 'desc',
            'q' => $search !== '' ? $search : null,
        ]);
    };
    $sortArrow = fn (string $key): string => $sort === $key ? ($direction === 'desc' ? 'v' : '^') : '';
    $countCell = function ($train, $latest) use ($formatAverageCount): string {
        return sprintf(
            '<div class="space-y-0.5 tabular-nums"><div class="flex justify-between gap-1"><span class="text-gray-400">Train</span><span class="font-semibold text-gray-950">%s</span></div><div class="flex justify-between gap-1"><span class="text-gray-400">Latest</span><span class="font-semibold text-gray-700">%s</span></div></div>',
            e($formatAverageCount($train)),
            e($formatAverageCount($latest)),
        );
    };
    $collectionCountCell = function ($train, $latest, $drift, $driftRate) use ($formatAverageCount, $formatDeltaPct, $deltaClass): string {
        $formatAverageCount = function ($value): string {
            if ($value === null) {
                return '-';
            }

            return number_format((float) $value, 2);
        };
        $formatDeltaCount = function ($value): string {
            if ($value === null) {
                return '-';
            }

            $value = (float) $value;
            $formatted = number_format($value, 2);

            return $value >= 0 ? '+' . $formatted : $formatted;
        };

        return sprintf(
            '<div class="space-y-0.5 tabular-nums"><div class="flex justify-between gap-1"><span class="text-gray-400">Train</span><span class="font-semibold text-gray-950">%s</span></div><div class="flex justify-between gap-1"><span class="text-gray-400">Latest</span><span class="font-semibold text-gray-700">%s</span></div><div class="flex justify-between gap-1"><span class="text-gray-400">Drift</span><span class="font-semibold %s">%s</span></div><div class="flex justify-between gap-1"><span class="text-gray-400">Drift %%</span><span class="font-semibold %s">%s</span></div></div>',
            e($formatAverageCount($train)),
            e($formatAverageCount($latest)),
            e($deltaClass($drift)),
            e($formatDeltaCount($drift)),
            e($deltaClass($driftRate)),
            e($formatDeltaPct($driftRate)),
        );
    };
    $shareCell = function ($train, $latest, $drift, $driftRate) use ($formatPct, $formatDeltaPct, $deltaClass): string {
        return sprintf(
            '<div class="space-y-0.5 tabular-nums"><div class="flex justify-between gap-1"><span class="text-gray-400">Train</span><span class="font-semibold text-gray-950">%s</span></div><div class="flex justify-between gap-1"><span class="text-gray-400">Latest</span><span class="font-semibold text-gray-700">%s</span></div><div class="flex justify-between gap-1"><span class="text-gray-400">Drift</span><span class="font-semibold %s">%s</span></div><div class="flex justify-between gap-1"><span class="text-gray-400">Drift %%</span><span class="font-semibold %s">%s</span></div></div>',
            e($formatPct($train)),
            e($formatPct($latest)),
            e($deltaClass($drift)),
            e($formatDeltaPct($drift)),
            e($deltaClass($driftRate)),
            e($formatDeltaPct($driftRate)),
        );
    };
    $rateCell = function ($train, $latest, $drift, $driftRate) use ($formatRate, $formatDeltaRate, $formatDeltaPct, $deltaClass): string {
        return sprintf(
            '<div class="space-y-0.5 tabular-nums"><div class="flex justify-between gap-1"><span class="text-gray-400">Train</span><span class="font-semibold text-gray-950">%s</span></div><div class="flex justify-between gap-1"><span class="text-gray-400">Latest</span><span class="font-semibold text-gray-700">%s</span></div><div class="flex justify-between gap-1"><span class="text-gray-400">Drift</span><span class="font-semibold %s">%s</span></div><div class="flex justify-between gap-1"><span class="text-gray-400">Drift %%</span><span class="font-semibold %s">%s</span></div></div>',
            e($formatRate($train)),
            e($formatRate($latest)),
            e($deltaClass($drift)),
            e($formatDeltaRate($drift)),
            e($deltaClass($driftRate)),
            e($formatDeltaPct($driftRate)),
        );
    };
@endphp

<x-app-layout>
    <div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
                <a href="{{ route('admin.nhl-sat-models.index') }}" class="text-xs font-medium text-gray-500 transition-colors hover:text-gray-950">SAT Models</a>
                <h1 class="mt-1 text-xl font-semibold tracking-normal text-gray-950">{{ $run->name }}</h1>
                <p class="mt-1 text-sm text-gray-500">Latest training-season profile drift against the aggregate training profile.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.nhl-sat-models.profiles', $run) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 hover:text-gray-950">
                    View Profiles
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
                <div class="text-[11px] font-medium uppercase text-gray-500">Latest Snapshot</div>
                <div class="mt-1 text-sm font-semibold text-gray-950">{{ $latestTrainingSeasonId ?? 'None' }}</div>
            </div>
            <div class="border border-gray-200 bg-white px-3 py-2">
                <div class="text-[11px] font-medium uppercase text-gray-500">Training Rows</div>
                <div class="mt-1 text-sm font-semibold text-gray-950">{{ number_format((int) collect($summary)->sum('rows')) }}</div>
            </div>
            <div class="border border-gray-200 bg-white px-3 py-2">
                <div class="text-[11px] font-medium uppercase text-gray-500">Matched Rows</div>
                <div class="mt-1 text-sm font-semibold text-gray-950">{{ number_format((int) collect($summary)->sum('matched_rows')) }}</div>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap gap-2">
            @foreach($profileTypes as $type => $typeLabel)
                @php
                    $typeSummary = $summary->get($type);
                @endphp
                <a
                    href="{{ route('admin.nhl-sat-models.profiles.training-drift', ['run' => $run, 'profile_type' => $type, 'sort' => $sort, 'direction' => $direction, 'q' => $search !== '' ? $search : null]) }}"
                    class="inline-flex items-center gap-2 rounded-md border px-3 py-2 text-xs font-semibold transition-colors {{ $profileType === $type ? 'border-gray-950 bg-gray-950 text-white' : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 hover:text-gray-950' }}"
                >
                    <span>{{ $typeLabel }}</span>
                    <span class="{{ $profileType === $type ? 'text-gray-300' : 'text-gray-400' }}">{{ number_format((int) ($typeSummary->entities ?? 0)) }}</span>
                </a>
            @endforeach
        </div>

        @if($collectionRows->isNotEmpty())
            <div class="mb-4 border border-gray-200 bg-white">
                <table class="w-full table-fixed divide-y divide-gray-200 text-[10px]">
                    <thead class="bg-gray-50 text-left font-semibold uppercase text-gray-500">
                        <tr>
                            <th class="w-[16%] px-1.5 py-2">Collection</th>
                            <th class="w-[10%] px-1.5 py-2 text-right">SAT/GP</th>
                            <th class="w-[10%] px-1.5 py-2 text-right">SOG/GP</th>
                            <th class="w-[8%] px-1.5 py-2 text-right">G/GP</th>
                            <th class="w-[18%] px-1.5 py-2 text-right">xSAT/60/Entity</th>
                            <th class="w-[19%] px-1.5 py-2 text-right">xSOG/60/Entity</th>
                            <th class="w-[19%] px-1.5 py-2 text-right">xG/60/Entity</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white text-gray-700">
                        @foreach($collectionRows as $collectionRow)
                            <tr class="hover:bg-gray-50">
                                <td class="px-1.5 py-2 align-top">
                                    <div class="font-semibold text-gray-950">{{ $collectionRow->collection_label }}</div>
                                    <div class="text-gray-400">{{ $collectionRow->collection_context }}</div>
                                    <div class="mt-1 text-gray-400">
                                        {{ number_format((int) ($collectionRow->matched_rows ?? 0)) }} of {{ number_format((int) ($collectionRow->rows ?? 0)) }} buckets matched - {{ number_format((int) ($collectionRow->entities ?? 0)) }} entities
                                    </div>
                                    <div class="text-gray-400">
                                        {{ number_format((int) ($collectionRow->train_games ?? 0)) }} train GP - {{ number_format((int) ($collectionRow->latest_games ?? 0)) }} latest GP
                                    </div>
                                </td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $collectionCountCell($collectionRow->train_sat ?? null, $collectionRow->latest_sat ?? null, $collectionRow->sat_drift ?? null, $collectionRow->sat_drift_rate ?? null) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $collectionCountCell($collectionRow->train_sog ?? null, $collectionRow->latest_sog ?? null, $collectionRow->sog_drift ?? null, $collectionRow->sog_drift_rate ?? null) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $collectionCountCell($collectionRow->train_goals ?? null, $collectionRow->latest_goals ?? null, $collectionRow->goals_drift ?? null, $collectionRow->goals_drift_rate ?? null) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $rateCell($collectionRow->train_xsat_per_60 ?? null, $collectionRow->latest_xsat_per_60 ?? null, $collectionRow->xsat_drift ?? null, $collectionRow->xsat_drift_rate ?? null) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $rateCell($collectionRow->train_xsog_per_60 ?? null, $collectionRow->latest_xsog_per_60 ?? null, $collectionRow->xsog_drift ?? null, $collectionRow->xsog_drift_rate ?? null) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $rateCell($collectionRow->train_xg_per_60 ?? null, $collectionRow->latest_xg_per_60 ?? null, $collectionRow->xg_drift ?? null, $collectionRow->xg_drift_rate ?? null) !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <form method="GET" action="{{ route('admin.nhl-sat-models.profiles.training-drift', $run) }}" class="mb-4 flex flex-wrap items-center gap-3 border border-gray-200 bg-white px-3 py-2">
            <input type="hidden" name="profile_type" value="{{ $profileType }}">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">
            <input
                type="search"
                name="q"
                value="{{ $search }}"
                placeholder="Search training drift"
                class="h-8 w-full max-w-xs rounded-md border-gray-300 text-xs text-gray-950 shadow-sm focus:border-gray-950 focus:ring-gray-950"
            >
            <button type="submit" class="inline-flex h-8 items-center rounded-md border border-gray-300 bg-white px-3 text-xs font-semibold text-gray-700 transition-colors hover:border-gray-400 hover:text-gray-950">
                Search
            </button>
            <span class="text-xs text-gray-400">Drift is latest training season minus aggregate training profile.</span>
        </form>

        <div class="border border-gray-200 bg-white">
            @if($drifts->count() === 0)
                <div class="px-5 py-12 text-center">
                    <div class="text-sm font-semibold text-gray-950">No training drift rows yet</div>
                    <div class="mt-1 text-sm text-gray-500">Build profiles to create aggregate profiles and training-season snapshots.</div>
                </div>
            @else
                <table class="w-full table-fixed divide-y divide-gray-200 text-[10px]">
                    <thead class="bg-gray-50 text-left font-semibold uppercase text-gray-500">
                        <tr>
                            <th class="w-[13%] px-1.5 py-2"><a href="{{ $sortUrl('entity') }}">Entity {{ $sortArrow('entity') }}</a></th>
                            <th class="w-[20%] px-1.5 py-2"><a href="{{ $sortUrl('matched_bucket_rows') }}">Buckets {{ $sortArrow('matched_bucket_rows') }}</a></th>
                            <th class="w-[7%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('train_sat') }}">SAT/GP {{ $sortArrow('train_sat') }}</a></th>
                            <th class="w-[7%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('train_sog') }}">SOG/GP {{ $sortArrow('train_sog') }}</a></th>
                            <th class="w-[6%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('train_goals') }}">G/GP {{ $sortArrow('train_goals') }}</a></th>
                            <th class="w-[11%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('share_drift') }}">Share {{ $sortArrow('share_drift') }}</a></th>
                            <th class="w-[12%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('xsat_drift_abs') }}">xSAT/60 {{ $sortArrow('xsat_drift_abs') }}</a></th>
                            <th class="w-[12%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('xsog_drift_abs') }}">xSOG/60 {{ $sortArrow('xsog_drift_abs') }}</a></th>
                            <th class="w-[12%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('xg_drift_abs') }}">xG/60 {{ $sortArrow('xg_drift_abs') }}</a></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white text-gray-700">
                        @foreach($drifts as $drift)
                            <tr class="hover:bg-gray-50">
                                <td class="px-1.5 py-2 align-top">
                                    <div class="truncate font-semibold text-gray-950">{{ $drift->entity_name ?? $drift->entity_key }}</div>
                                    <div class="truncate text-gray-400">{{ $drift->entity_role ?? $drift->profile_type }} - {{ $drift->team_context ?? '-' }}</div>
                                </td>
                                <td class="px-1.5 py-2 align-top">
                                    <div class="font-medium text-gray-950">{{ number_format((int) ($drift->matched_bucket_rows ?? 0)) }} of {{ number_format((int) ($drift->bucket_rows ?? 0)) }} matched</div>
                                    <div class="truncate text-gray-400">{{ $profileTypes[$drift->profile_type] ?? $label($drift->profile_type) }}</div>
                                    <div class="truncate text-gray-400">{{ number_format((int) ($drift->train_games ?? 0)) }} train GP - {{ number_format((int) ($drift->latest_games ?? 0)) }} latest GP</div>
                                </td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $countCell($drift->train_sat, $drift->latest_sat) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $countCell($drift->train_sog, $drift->latest_sog) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $countCell($drift->train_goals, $drift->latest_goals) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $shareCell($drift->train_profile_share, $drift->latest_profile_share, $drift->share_drift, $drift->share_drift_rate) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $rateCell($drift->train_xsat_per_60, $drift->latest_xsat_per_60, $drift->xsat_drift, $drift->xsat_drift_rate) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $rateCell($drift->train_xsog_per_60, $drift->latest_xsog_per_60, $drift->xsog_drift, $drift->xsog_drift_rate) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $rateCell($drift->train_xg_per_60, $drift->latest_xg_per_60, $drift->xg_drift, $drift->xg_drift_rate) !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="border-t border-gray-200 px-3 py-2">
                    {{ $drifts->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
