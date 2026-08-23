@php
    $label = fn ($value) => str($value)->replace(['_', '-'], ' ')->title();
    $formatCount = fn ($value) => $value === null ? '-' : number_format((int) $value);
    $formatRate = fn ($value) => $value === null ? '-' : number_format((float) $value, 2);
    $formatDelta = fn ($value) => $value === null ? '-' : (((float) $value) >= 0 ? '+' : '') . number_format((float) $value, 2);
    $formatPct = fn ($value) => $value === null ? '-' : (((float) $value) >= 0 ? '+' : '') . number_format(((float) $value) * 100, 1) . '%';
    $deltaClass = fn ($value) => $value === null ? 'text-gray-400' : (((float) $value) >= 0 ? 'text-emerald-700' : 'text-rose-700');
    $sortUrl = function (string $key) use ($run, $profileType, $sort, $direction, $search): string {
        return route('admin.nhl-sat-models.profiles.bucket-stability', [
            'run' => $run,
            'profile_type' => $profileType,
            'sort' => $key,
            'direction' => $sort === $key && $direction === 'desc' ? 'asc' : 'desc',
            'q' => $search !== '' ? $search : null,
        ]);
    };
    $exportUrl = route('admin.nhl-sat-models.profiles.bucket-stability.export', [
        'run' => $run,
        'profile_type' => $profileType,
        'q' => $search !== '' ? $search : null,
    ]);
    $sortArrow = fn (string $key): string => $sort === $key ? ($direction === 'desc' ? 'v' : '^') : '';
    $bucketLabel = function ($dimensions) use ($label): string {
        $decoded = is_string($dimensions) ? json_decode($dimensions, true) : $dimensions;

        if (! is_array($decoded)) {
            return 'Bucket';
        }

        return collect($decoded)
            ->map(fn ($value, $key): string => $label($key) . ': ' . $label((string) $value))
            ->implode(' · ');
    };
    $countCell = function ($train, $prior, $latest, $test) use ($formatCount): string {
        return sprintf(
            '<div class="space-y-0.5 tabular-nums"><div class="flex justify-between gap-1"><span class="text-gray-400">Train</span><span class="font-semibold text-gray-950">%s</span></div><div class="flex justify-between gap-1"><span class="text-gray-400">S1</span><span>%s</span></div><div class="flex justify-between gap-1"><span class="text-gray-400">S2</span><span>%s</span></div><div class="flex justify-between gap-1"><span class="text-gray-400">S3</span><span>%s</span></div></div>',
            e($formatCount($train)),
            e($formatCount($prior)),
            e($formatCount($latest)),
            e($formatCount($test)),
        );
    };
    $rateCell = function ($train, $prior, $latest, $test) use ($formatRate): string {
        return sprintf(
            '<div class="space-y-0.5 tabular-nums"><div class="flex justify-between gap-1"><span class="text-gray-400">Train</span><span class="font-semibold text-gray-950">%s</span></div><div class="flex justify-between gap-1"><span class="text-gray-400">S1</span><span>%s</span></div><div class="flex justify-between gap-1"><span class="text-gray-400">S2</span><span>%s</span></div><div class="flex justify-between gap-1"><span class="text-gray-400">S3</span><span>%s</span></div></div>',
            e($formatRate($train)),
            e($formatRate($prior)),
            e($formatRate($latest)),
            e($formatRate($test)),
        );
    };
    $deltaCell = function ($delta, $rate) use ($formatDelta, $formatPct, $deltaClass): string {
        return sprintf(
            '<div class="space-y-0.5 tabular-nums"><div class="font-semibold %s">%s</div><div class="%s">%s</div></div>',
            e($deltaClass($delta)),
            e($formatDelta($delta)),
            e($deltaClass($rate)),
            e($formatPct($rate)),
        );
    };
@endphp

<x-app-layout>
    <div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
                <a href="{{ route('admin.nhl-sat-models.index') }}" class="text-xs font-medium text-gray-500 transition-colors hover:text-gray-950">SAT Models</a>
                <h1 class="mt-1 text-xl font-semibold tracking-normal text-gray-950">{{ $run->name }}</h1>
                <p class="mt-1 text-sm text-gray-500">Generic SAT bucket stability across S1, S2, and test season.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ $exportUrl }}" class="inline-flex size-8 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-700 shadow-sm transition-colors hover:bg-gray-50 hover:text-gray-950" title="Download CSV" aria-label="Download CSV">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M10.75 2.75a.75.75 0 0 0-1.5 0v8.69L6.03 8.22a.75.75 0 0 0-1.06 1.06l4.5 4.5a.75.75 0 0 0 1.06 0l4.5-4.5a.75.75 0 1 0-1.06-1.06l-3.22 3.22V2.75Z" />
                        <path d="M3.5 13.75a.75.75 0 0 0-1.5 0v1.5A2.75 2.75 0 0 0 4.75 18h10.5A2.75 2.75 0 0 0 18 15.25v-1.5a.75.75 0 0 0-1.5 0v1.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-1.5Z" />
                    </svg>
                </a>
                <a href="{{ route('admin.nhl-sat-models.profiles.training-drift', $run) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 hover:text-gray-950">
                    Training Drift
                </a>
                <a href="{{ route('admin.nhl-sat-models.profiles', $run) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 hover:text-gray-950">
                    Profiles
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
                <div class="text-[11px] font-medium uppercase text-gray-500">Rows</div>
                <div class="mt-1 text-sm font-semibold text-gray-950">{{ number_format((int) collect($summary)->sum('rows')) }}</div>
            </div>
            <div class="border border-gray-200 bg-white px-3 py-2">
                <div class="text-[11px] font-medium uppercase text-gray-500">SAT</div>
                <div class="mt-1 text-sm font-semibold text-gray-950">{{ number_format((int) collect($summary)->sum('train_sat')) }}</div>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap gap-2">
            @foreach($profileTypes as $type => $typeLabel)
                @php($typeSummary = $summary->get($type))
                <a
                    href="{{ route('admin.nhl-sat-models.profiles.bucket-stability', ['run' => $run, 'profile_type' => $type, 'sort' => $sort, 'direction' => $direction, 'q' => $search !== '' ? $search : null]) }}"
                    class="inline-flex items-center gap-2 rounded-md border px-3 py-2 text-xs font-semibold transition-colors {{ $profileType === $type ? 'border-gray-950 bg-gray-950 text-white' : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 hover:text-gray-950' }}"
                >
                    <span>{{ $typeLabel }}</span>
                    <span class="{{ $profileType === $type ? 'text-gray-300' : 'text-gray-400' }}">{{ number_format((int) ($typeSummary->rows ?? 0)) }}</span>
                </a>
            @endforeach
        </div>

        <form method="GET" action="{{ route('admin.nhl-sat-models.profiles.bucket-stability', $run) }}" class="mb-4 flex flex-wrap items-center gap-3 border border-gray-200 bg-white px-3 py-2">
            <input type="hidden" name="profile_type" value="{{ $profileType }}">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">
            <input
                type="search"
                name="q"
                value="{{ $search }}"
                placeholder="Search bucket"
                class="h-8 w-full max-w-xs rounded-md border-gray-300 text-xs text-gray-950 shadow-sm focus:border-gray-950 focus:ring-gray-950"
            >
            <button type="submit" class="inline-flex h-8 items-center rounded-md border border-gray-300 bg-white px-3 text-xs font-semibold text-gray-700 transition-colors hover:border-gray-400 hover:text-gray-950">
                Search
            </button>
            <span class="text-xs text-gray-400">Train is aggregate training; S1/S2 are the two latest training-season snapshots; S3 is test.</span>
        </form>

        <div class="border border-gray-200 bg-white">
            @if($rows->count() === 0)
                <div class="px-5 py-12 text-center">
                    <div class="text-sm font-semibold text-gray-950">No bucket stability rows yet</div>
                    <div class="mt-1 text-sm text-gray-500">Build profiles after running the migration to create these rows.</div>
                </div>
            @else
                <table class="w-full table-fixed divide-y divide-gray-200 text-[10px]">
                    <thead class="bg-gray-50 text-left font-semibold uppercase text-gray-500">
                        <tr>
                            <th class="w-[24%] px-1.5 py-2"><a href="{{ $sortUrl('bucket') }}">Bucket {{ $sortArrow('bucket') }}</a></th>
                            <th class="w-[12%] px-1.5 py-2 text-right">Entities</th>
                            <th class="w-[12%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('train_sat') }}">SAT {{ $sortArrow('train_sat') }}</a></th>
                            <th class="w-[14%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('test_xsat_per_60') }}">xSAT/60 {{ $sortArrow('test_xsat_per_60') }}</a></th>
                            <th class="w-[11%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('s2_s1') }}">S2-S1 {{ $sortArrow('s2_s1') }}</a></th>
                            <th class="w-[11%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('s3_s2') }}">S3-S2 {{ $sortArrow('s3_s2') }}</a></th>
                            <th class="w-[11%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('s3_train') }}">S3-Train {{ $sortArrow('s3_train') }}</a></th>
                            <th class="w-[5%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('reversed') }}">Rev {{ $sortArrow('reversed') }}</a></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white text-gray-700">
                        @foreach($rows as $row)
                            <tr class="hover:bg-gray-50">
                                <td class="px-1.5 py-2 align-top">
                                    <div class="font-semibold text-gray-950">{{ $bucketLabel($row->bucket_dimensions) }}</div>
                                    <div class="mt-0.5 break-words text-gray-400">{{ $row->matched_bucket_key }}</div>
                                </td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $countCell($row->train_entity_count, $row->prior_entity_count, $row->latest_entity_count, $row->test_entity_count) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $countCell($row->train_sat, $row->prior_sat, $row->latest_sat, $row->test_sat) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $rateCell($row->train_xsat_per_60, $row->prior_xsat_per_60, $row->latest_xsat_per_60, $row->test_xsat_per_60) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $deltaCell($row->latest_minus_prior_xsat_per_60, $row->latest_minus_prior_xsat_rate) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $deltaCell($row->test_minus_latest_xsat_per_60, $row->test_minus_latest_xsat_rate) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $deltaCell($row->test_minus_train_xsat_per_60, $row->test_minus_train_xsat_rate) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">
                                    <span class="font-semibold {{ $row->reversed_after_latest ? 'text-amber-700' : 'text-gray-400' }}">{{ $row->reversed_after_latest ? 'Yes' : 'No' }}</span>
                                    <div class="mt-0.5 text-gray-400">{{ $label($row->latest_direction ?? '-') }} / {{ $label($row->test_direction ?? '-') }}</div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="mt-4">
            {{ $rows->links() }}
        </div>
    </div>
</x-app-layout>
