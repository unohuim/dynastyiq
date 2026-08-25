@php
    $formatMinutes = fn ($seconds) => $seconds === null ? '-' : number_format(((float) $seconds) / 60, 2);
    $formatNumber = fn ($value, int $decimals = 1) => $value === null ? '-' : number_format((float) $value, $decimals);
    $formatHours = fn ($value) => $value === null ? '-' : number_format((float) $value, 1);
    $sortUrl = function (string $key) use ($run, $profileType, $sort, $direction, $search): string {
        return route('admin.nhl-sat-models.toi-projections', [
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
                <p class="mt-1 text-sm text-gray-500">Projected TOI/GP and S3 season TOI from SAT model training seasons.</p>
            </div>
            <form method="POST" action="{{ route('admin.nhl-sat-models.toi-projections.build', $run) }}" data-sat-model-toi-build-form data-sat-model-reload-on-success>
                @csrf
                <button type="submit" class="inline-flex items-center rounded-md bg-gray-950 px-3 py-2 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-gray-800">
                    Build TOI
                </button>
            </form>
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-4">
            <div class="border border-gray-200 bg-white px-3 py-2">
                <div class="text-[11px] font-medium uppercase text-gray-500">Training Seasons</div>
                <div class="mt-1 text-sm font-semibold text-gray-950">{{ collect($run->train_season_ids ?? [])->implode(', ') }}</div>
            </div>
            <div class="border border-gray-200 bg-white px-3 py-2">
                <div class="text-[11px] font-medium uppercase text-gray-500">Target Season</div>
                <div class="mt-1 text-sm font-semibold text-gray-950">{{ $run->target_season_id ?? '-' }}</div>
            </div>
            <div class="border border-gray-200 bg-white px-3 py-2">
                <div class="text-[11px] font-medium uppercase text-gray-500">Entities</div>
                <div class="mt-1 text-sm font-semibold text-gray-950">{{ number_format((int) collect($summary)->sum('entities')) }}</div>
            </div>
            <div class="border border-gray-200 bg-white px-3 py-2">
                <div class="text-[11px] font-medium uppercase text-gray-500">Projected TOI Hours</div>
                <div class="mt-1 text-sm font-semibold text-gray-950">{{ $formatHours(((float) collect($summary)->sum('projected_toi_seconds')) / 3600) }}</div>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap gap-2">
            @foreach($profileTypes as $type => $typeLabel)
                @php
                    $typeSummary = $summary->get($type);
                @endphp
                <a
                    href="{{ route('admin.nhl-sat-models.toi-projections', ['run' => $run, 'profile_type' => $type, 'sort' => $sort, 'direction' => $direction, 'q' => $search !== '' ? $search : null]) }}"
                    class="inline-flex items-center gap-2 rounded-md border px-3 py-2 text-xs font-semibold transition-colors {{ $profileType === $type ? 'border-gray-950 bg-gray-950 text-white' : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 hover:text-gray-950' }}"
                >
                    <span>{{ $typeLabel }}</span>
                    <span class="{{ $profileType === $type ? 'text-gray-300' : 'text-gray-400' }}">{{ number_format((int) ($typeSummary->entities ?? 0)) }}</span>
                </a>
            @endforeach
        </div>

        <form method="GET" action="{{ route('admin.nhl-sat-models.toi-projections', $run) }}" class="mb-4 flex flex-wrap items-center gap-3 border border-gray-200 bg-white px-3 py-2">
            <input type="hidden" name="profile_type" value="{{ $profileType }}">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">
            <input
                type="search"
                name="q"
                value="{{ $search }}"
                placeholder="Search TOI projections"
                class="h-8 w-full max-w-xs rounded-md border-gray-300 text-xs text-gray-950 shadow-sm focus:border-gray-950 focus:ring-gray-950"
            >
            <button type="submit" class="inline-flex h-8 items-center rounded-md border border-gray-300 bg-white px-3 text-xs font-semibold text-gray-700 transition-colors hover:border-gray-400 hover:text-gray-950">
                Search
            </button>
            <span class="text-xs text-gray-400">S1 TOI/GP is derived from training totals minus S2. Projected TOI uses training average TOI/GP plus S1-to-S2 role and age adjustments.</span>
        </form>

        <div class="border border-gray-200 bg-white">
            @if($projections->count() === 0)
                <div class="px-5 py-12 text-center">
                    <div class="text-sm font-semibold text-gray-950">No TOI projections yet</div>
                    <div class="mt-1 text-sm text-gray-500">Build TOI after profiles exist.</div>
                </div>
            @else
                <table class="w-full table-fixed divide-y divide-gray-200 text-[10px]">
                    <thead class="bg-gray-50 text-left font-semibold uppercase text-gray-500">
                        <tr>
                            <th class="w-[14%] px-1.5 py-2"><a href="{{ $sortUrl('entity') }}">Entity {{ $sortArrow('entity') }}</a></th>
                            <th class="w-[4%] px-1.5 py-2"><a href="{{ $sortUrl('position') }}">Pos {{ $sortArrow('position') }}</a></th>
                            <th class="w-[4%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('age') }}">Age {{ $sortArrow('age') }}</a></th>
                            <th class="w-[7%] px-1.5 py-2">S1 Role</th>
                            <th class="w-[7%] px-1.5 py-2">S2 Role</th>
                            <th class="w-[7%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('prior_toi_per_game_seconds') }}">S1 TOI/GP {{ $sortArrow('prior_toi_per_game_seconds') }}</a></th>
                            <th class="w-[7%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('latest_toi_per_game_seconds') }}">S2 TOI/GP {{ $sortArrow('latest_toi_per_game_seconds') }}</a></th>
                            <th class="w-[7%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('train_toi_per_game_seconds') }}">Train TOI/GP {{ $sortArrow('train_toi_per_game_seconds') }}</a></th>
                            <th class="w-[7%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('projected_games') }}">Proj GP {{ $sortArrow('projected_games') }}</a></th>
                            <th class="w-[7%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('projected_toi_per_game_seconds') }}">Proj TOI/GP {{ $sortArrow('projected_toi_per_game_seconds') }}</a></th>
                            <th class="w-[7%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('projected_toi_hours') }}">Proj TOI Hrs {{ $sortArrow('projected_toi_hours') }}</a></th>
                            <th class="w-[7%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('role_adjustment_seconds_per_game') }}">Role Adj {{ $sortArrow('role_adjustment_seconds_per_game') }}</a></th>
                            <th class="w-[7%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('age_adjustment_seconds_per_game') }}">Age Adj {{ $sortArrow('age_adjustment_seconds_per_game') }}</a></th>
                            <th class="w-[6%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('confidence_score') }}">Conf {{ $sortArrow('confidence_score') }}</a></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white text-gray-700">
                        @foreach($projections as $projection)
                            <tr class="hover:bg-gray-50">
                                <td class="px-1.5 py-2 align-top">
                                    <div class="truncate font-semibold text-gray-950">{{ $projection->entity_name ?? $projection->entity_key }}</div>
                                </td>
                                <td class="px-1.5 py-2 align-top">{{ $projection->position ?? '-' }}</td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">{{ $formatNumber($projection->age_years, 1) }}</td>
                                <td class="truncate px-1.5 py-2 align-top">{{ $projection->source_role_bucket ?? '-' }}</td>
                                <td class="truncate px-1.5 py-2 align-top">{{ $projection->target_role_bucket ?? '-' }}</td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">{{ $formatMinutes($projection->s1_toi_per_game_seconds ?? null) }}</td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">{{ $formatMinutes($projection->s2_toi_per_game_seconds ?? null) }}</td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">{{ $formatMinutes($projection->profile_train_toi_per_game_seconds ?? null) }}</td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">{{ $formatNumber($projection->projected_games, 1) }}</td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums font-semibold text-gray-950">{{ $formatMinutes($projection->projected_toi_per_game_seconds) }}</td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">{{ $formatHours($projection->projected_toi_hours) }}</td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">{{ $formatMinutes($projection->role_adjustment_seconds_per_game) }}</td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">{{ $formatMinutes($projection->age_adjustment_seconds_per_game) }}</td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">{{ $projection->confidence_score === null ? '-' : number_format(((float) $projection->confidence_score) * 100, 0) . '%' }}</td>
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
