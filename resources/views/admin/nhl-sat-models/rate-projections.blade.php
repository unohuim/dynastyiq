@php
    $label = fn ($value) => str($value)->replace(['_', '-'], ' ')->title();
    $formatPct = fn ($value) => number_format(((float) $value) * 100, 1) . '%';
    $formatRate = fn ($value) => $value === null ? '-' : number_format((float) $value, 2);
    $formatMultiplier = fn ($value) => $value === null ? '-' : number_format((float) $value, 2) . 'x';
    $sortUrl = function (string $key) use ($run, $profileType, $sort, $direction, $search): string {
        return route('admin.nhl-sat-models.rate-projections', [
            'run' => $run,
            'profile_type' => $profileType,
            'sort' => $key,
            'direction' => $sort === $key && $direction === 'desc' ? 'asc' : 'desc',
            'q' => $search !== '' ? $search : null,
        ]);
    };
    $sortArrow = fn (string $key): string => $sort === $key ? ($direction === 'desc' ? '↓' : '↑') : '↕';
@endphp

<x-app-layout>
    <div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8" data-admin-sat-models>
        <div class="fixed inset-0 z-50 hidden items-center justify-center bg-gray-950/35 px-4 backdrop-blur-sm" data-sat-model-loading>
            <div class="w-full max-w-sm rounded-lg border border-gray-200 bg-white p-5 text-center shadow-xl">
                <div class="mx-auto size-8 animate-spin rounded-full border-2 border-gray-200 border-t-gray-950"></div>
                <div class="mt-4 text-sm font-semibold text-gray-950" data-sat-model-loading-title>Working...</div>
                <div class="mt-1 text-xs text-gray-500">Keep this tab open until it finishes.</div>
            </div>
        </div>

        <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
                <a href="{{ route('admin.nhl-sat-models.index') }}" class="text-xs font-medium text-gray-500 transition-colors hover:text-gray-950">SAT Models</a>
                <h1 class="mt-1 text-xl font-semibold tracking-normal text-gray-950">{{ $run->name }}</h1>
                <p class="mt-1 text-sm text-gray-500">Projected entity rates per 60 from SAT profiles.</p>
            </div>
            <form method="POST" action="{{ route('admin.nhl-sat-models.rate-projections.build', $run) }}" data-sat-model-rate-build-form data-sat-model-reload-on-success>
                @csrf
                <button type="submit" class="inline-flex items-center rounded-md bg-gray-950 px-3 py-2 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-gray-800">
                    Build /60
                </button>
            </form>
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-4">
            <div class="border border-gray-200 bg-white px-3 py-2">
                <div class="text-[11px] font-medium uppercase text-gray-500">Training Seasons</div>
                <div class="mt-1 text-sm font-semibold text-gray-950">{{ collect($run->train_season_ids ?? [])->implode(', ') }}</div>
            </div>
            <div class="border border-gray-200 bg-white px-3 py-2">
                <div class="text-[11px] font-medium uppercase text-gray-500">Rows</div>
                <div class="mt-1 text-sm font-semibold text-gray-950">{{ number_format((int) collect($summary)->sum('rows')) }}</div>
            </div>
            <div class="border border-gray-200 bg-white px-3 py-2">
                <div class="text-[11px] font-medium uppercase text-gray-500">Entities</div>
                <div class="mt-1 text-sm font-semibold text-gray-950">{{ number_format((int) collect($summary)->sum('entities')) }}</div>
            </div>
            <div class="border border-gray-200 bg-white px-3 py-2">
                <div class="text-[11px] font-medium uppercase text-gray-500">Projected xSAT/60</div>
                <div class="mt-1 text-sm font-semibold text-gray-950">{{ $formatRate(collect($summary)->sum('projected_xsat_per_60')) }}</div>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap gap-2">
            @foreach($profileTypes as $type => $typeLabel)
                @php
                    $typeSummary = $summary->get($type);
                @endphp
                <a
                    href="{{ route('admin.nhl-sat-models.rate-projections', ['run' => $run, 'profile_type' => $type, 'sort' => $sort, 'direction' => $direction, 'q' => $search !== '' ? $search : null]) }}"
                    class="inline-flex items-center gap-2 rounded-md border px-3 py-2 text-xs font-semibold transition-colors {{ $profileType === $type ? 'border-gray-950 bg-gray-950 text-white' : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 hover:text-gray-950' }}"
                >
                    <span>{{ $typeLabel }}</span>
                    <span class="{{ $profileType === $type ? 'text-gray-300' : 'text-gray-400' }}">{{ number_format((int) ($typeSummary->entities ?? 0)) }}</span>
                </a>
            @endforeach
        </div>

        <form method="GET" action="{{ route('admin.nhl-sat-models.rate-projections', $run) }}" class="mb-4 flex flex-wrap items-center gap-3 border border-gray-200 bg-white px-3 py-2">
            <input type="hidden" name="profile_type" value="{{ $profileType }}">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">
            <input
                type="search"
                name="q"
                value="{{ $search }}"
                placeholder="Search projections"
                class="h-8 w-full max-w-xs rounded-md border-gray-300 text-xs text-gray-950 shadow-sm focus:border-gray-950 focus:ring-gray-950"
            >
            <button type="submit" class="inline-flex h-8 items-center rounded-md border border-gray-300 bg-white px-3 text-xs font-semibold text-gray-700 transition-colors hover:border-gray-400 hover:text-gray-950">
                Search
            </button>
            <span class="text-xs text-gray-400">Buckets under 5 SAT per training season are grouped into Other.</span>
        </form>

        <div class="border border-gray-200 bg-white">
            @if($projections->count() === 0)
                <div class="px-5 py-12 text-center">
                    <div class="text-sm font-semibold text-gray-950">No /60 projections yet</div>
                    <div class="mt-1 text-sm text-gray-500">Build /60 after profiles exist.</div>
                </div>
            @else
                <table class="w-full table-fixed divide-y divide-gray-200 text-[10px]">
                    <thead class="bg-gray-50 text-left font-semibold uppercase text-gray-500">
                        <tr>
                            <th class="w-[15%] px-1.5 py-2"><a href="{{ $sortUrl('entity') }}">Entity {{ $sortArrow('entity') }}</a></th>
                            <th class="w-[22%] px-1.5 py-2"><a href="{{ $sortUrl('bucket') }}">Profile {{ $sortArrow('bucket') }}</a></th>
                            <th class="w-[6%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('source_sat') }}">SAT {{ $sortArrow('source_sat') }}</a></th>
                            <th class="w-[6%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('share') }}">Share {{ $sortArrow('share') }}</a></th>
                            <th class="w-[8%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('source_xsat_per_60') }}">Source xSAT/60 {{ $sortArrow('source_xsat_per_60') }}</a></th>
                            <th class="w-[8%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('projected_xsat_per_60') }}">Proj xSAT/60 {{ $sortArrow('projected_xsat_per_60') }}</a></th>
                            <th class="w-[8%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('projected_xsog_per_60') }}">Proj xSOG/60 {{ $sortArrow('projected_xsog_per_60') }}</a></th>
                            <th class="w-[8%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('projected_xg_per_60') }}">Proj xG/60 {{ $sortArrow('projected_xg_per_60') }}</a></th>
                            <th class="w-[7%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('overall_rate_multiplier') }}">Overall {{ $sortArrow('overall_rate_multiplier') }}</a></th>
                            <th class="w-[7%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('shrunk_tendency_multiplier') }}">Tendency {{ $sortArrow('shrunk_tendency_multiplier') }}</a></th>
                            <th class="w-[5%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('confidence_score') }}">Conf {{ $sortArrow('confidence_score') }}</a></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white text-gray-700">
                        @foreach($projections as $projection)
                            @php
                                $dimensions = collect((array) json_decode((string) $projection->bucket_dimensions, true));
                                $bucketLabel = $projection->is_other_bucket
                                    ? 'Other'
                                    : $dimensions
                                        ->reject(fn ($value, $key) => $key === 'baseline')
                                        ->map(fn ($value, $key) => $label($key) . ': ' . $label($value))
                                        ->implode(' · ');
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="px-1.5 py-2 align-top">
                                    <div class="truncate font-semibold text-gray-950">{{ $projection->entity_name ?? $projection->entity_key }}</div>
                                    <div class="truncate text-gray-400">{{ $projection->entity_role ?? $projection->profile_type }} · {{ $projection->team_context ?? '-' }}</div>
                                </td>
                                <td class="px-1.5 py-2 align-top">
                                    <div class="line-clamp-2 font-medium text-gray-950">{{ $bucketLabel !== '' ? $bucketLabel : $projection->matched_bucket_key }}</div>
                                    <div class="truncate text-gray-400">{{ $projection->matched_bucket_key }}</div>
                                </td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">{{ number_format((int) $projection->source_sat) }}</td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">{{ $formatPct($projection->source_profile_share) }}</td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">{{ $formatRate($projection->source_xsat_per_60) }}</td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums font-semibold text-gray-950">{{ $formatRate($projection->projected_xsat_per_60) }}</td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">{{ $formatRate($projection->projected_xsog_per_60) }}</td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">{{ $formatRate($projection->projected_xg_per_60) }}</td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">
                                    <div>{{ $formatMultiplier($projection->overall_rate_multiplier) }}</div>
                                    <div class="text-gray-400">peer {{ $formatRate($projection->peer_entity_xsat_per_60) }}</div>
                                </td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">
                                    <div>{{ $formatMultiplier($projection->shrunk_tendency_multiplier) }}</div>
                                    <div class="text-gray-400">raw {{ $formatMultiplier($projection->raw_tendency_multiplier) }}</div>
                                </td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">{{ $formatPct($projection->confidence_score) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="border-t border-gray-200 px-3 py-2">
                    {{ $projections->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
