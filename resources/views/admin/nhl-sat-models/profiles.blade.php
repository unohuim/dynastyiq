@php
    $label = fn ($value) => str($value)->replace(['_', '-'], ' ')->title();
    $formatPct = fn ($value) => number_format(((float) $value) * 100, 1) . '%';
    $formatRate = fn ($value) => $value === null ? '-' : number_format((float) $value, 2);
    $sortUrl = function (string $key) use ($run, $profileType, $sort, $direction, $includeLongTail, $search): string {
        return route('admin.nhl-sat-models.profiles', [
            'run' => $run,
            'profile_type' => $profileType,
            'sort' => $key,
            'direction' => $sort === $key && $direction === 'desc' ? 'asc' : 'desc',
            'include_long_tail' => $includeLongTail ? 1 : null,
            'q' => $search !== '' ? $search : null,
        ]);
    };
    $sortArrow = fn (string $key): string => $sort === $key ? ($direction === 'desc' ? '↓' : '↑') : '↕';
    $grade = function ($value, $average): array {
        $value = (float) $value;
        $average = (float) $average;

        if ($average <= 0) {
            return ['Avg', 'text-gray-400'];
        }

        $ratio = $value / $average;

        if ($ratio >= 1.1) {
            return ['Above', 'text-emerald-700'];
        }

        if ($ratio <= 0.9) {
            return ['Below', 'text-rose-700'];
        }

        return ['Avg', 'text-gray-400'];
    };
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
                <p class="mt-1 text-sm text-gray-500">Entity SAT profiles for this model's training seasons.</p>
            </div>
            <form method="POST" action="{{ route('admin.nhl-sat-models.profiles.build', $run) }}" data-sat-model-profile-build-form data-sat-model-reload-on-success>
                @csrf
                <button type="submit" class="inline-flex items-center rounded-md bg-gray-950 px-3 py-2 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-gray-800">
                    Build Profiles
                </button>
            </form>
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <div class="border border-gray-200 bg-white px-3 py-2">
                <div class="text-[11px] font-medium uppercase text-gray-500">Training Seasons</div>
                <div class="mt-1 text-sm font-semibold text-gray-950">{{ collect($run->train_season_ids ?? [])->implode(', ') }}</div>
            </div>
            <div class="border border-gray-200 bg-white px-3 py-2">
                <div class="text-[11px] font-medium uppercase text-gray-500">Profile Rows</div>
                <div class="mt-1 text-sm font-semibold text-gray-950">{{ number_format((int) collect($summary)->sum('rows')) }}</div>
            </div>
            <div class="border border-gray-200 bg-white px-3 py-2">
                <div class="text-[11px] font-medium uppercase text-gray-500">Entities</div>
                <div class="mt-1 text-sm font-semibold text-gray-950">{{ number_format((int) collect($summary)->sum('entities')) }}</div>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap gap-2">
            @foreach($profileTypes as $type => $typeLabel)
                @php
                    $typeSummary = $summary->get($type);
                @endphp
                <a
                    href="{{ route('admin.nhl-sat-models.profiles', ['run' => $run, 'profile_type' => $type, 'sort' => $sort, 'direction' => $direction, 'include_long_tail' => $includeLongTail ? 1 : null, 'q' => $search !== '' ? $search : null]) }}"
                    class="inline-flex items-center gap-2 rounded-md border px-3 py-2 text-xs font-semibold transition-colors {{ $profileType === $type ? 'border-gray-950 bg-gray-950 text-white' : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 hover:text-gray-950' }}"
                >
                    <span>{{ $typeLabel }}</span>
                    <span class="{{ $profileType === $type ? 'text-gray-300' : 'text-gray-400' }}">{{ number_format((int) ($typeSummary->entities ?? 0)) }}</span>
                </a>
            @endforeach
        </div>

        <form method="GET" action="{{ route('admin.nhl-sat-models.profiles', $run) }}" class="mb-4 flex flex-wrap items-center gap-3 border border-gray-200 bg-white px-3 py-2">
            <input type="hidden" name="profile_type" value="{{ $profileType }}">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">
            <label class="inline-flex cursor-pointer items-center gap-2 text-xs font-semibold text-gray-700">
                <input
                    type="checkbox"
                    name="include_long_tail"
                    value="1"
                    @checked($includeLongTail)
                    onchange="this.form.submit()"
                    class="size-4 rounded border-gray-300 text-gray-950 focus:ring-gray-950"
                >
                <span>Long tail</span>
            </label>
            <input
                type="search"
                name="q"
                value="{{ $search }}"
                placeholder="Search profiles"
                class="h-8 w-full max-w-xs rounded-md border-gray-300 text-xs text-gray-950 shadow-sm focus:border-gray-950 focus:ring-gray-950"
            >
            <button type="submit" class="inline-flex h-8 items-center rounded-md border border-gray-300 bg-white px-3 text-xs font-semibold text-gray-700 transition-colors hover:border-gray-400 hover:text-gray-950">
                Search
            </button>
            <span class="text-xs text-gray-400">
                {{ $includeLongTail ? 'Showing all profile rows.' : 'Showing core tendencies: top 60% share, max 6 per entity, min 2 SAT.' }}
            </span>
        </form>

        <div class="border border-gray-200 bg-white">
            @if($profiles->count() === 0)
                <div class="px-5 py-12 text-center">
                    <div class="text-sm font-semibold text-gray-950">No profiles yet</div>
                    <div class="mt-1 text-sm text-gray-500">Build profiles after Eval SAT.</div>
                </div>
            @else
                <table class="w-full table-fixed divide-y divide-gray-200 text-[10px]">
                    <thead class="bg-gray-50 text-left font-semibold uppercase text-gray-500">
                        <tr>
                            <th class="w-[13%] px-1.5 py-2"><a href="{{ $sortUrl('entity') }}">Entity {{ $sortArrow('entity') }}</a></th>
                            <th class="w-[19%] px-1.5 py-2"><a href="{{ $sortUrl('bucket') }}">Profile {{ $sortArrow('bucket') }}</a></th>
                            <th class="w-[5%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('source_sat') }}">SAT {{ $sortArrow('source_sat') }}</a></th>
                            <th class="w-[6%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('source_xsat_per_60') }}">xSAT/60 {{ $sortArrow('source_xsat_per_60') }}</a></th>
                            <th class="w-[5%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('source_sog') }}">SOG {{ $sortArrow('source_sog') }}</a></th>
                            <th class="w-[4%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('source_goals') }}">G {{ $sortArrow('source_goals') }}</a></th>
                            <th class="w-[6%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('share') }}">Share {{ $sortArrow('share') }}</a></th>
                            <th class="w-[7%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('expected_sog') }}">xSOG {{ $sortArrow('expected_sog') }}</a></th>
                            <th class="w-[7%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('expected_goals') }}">xG {{ $sortArrow('expected_goals') }}</a></th>
                            <th class="w-[7%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('sat_probability') }}">SOG Prob {{ $sortArrow('sat_probability') }}</a></th>
                            <th class="w-[7%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('goal_probability') }}">G Prob {{ $sortArrow('goal_probability') }}</a></th>
                            <th class="w-[7%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('confidence_score') }}">Conf {{ $sortArrow('confidence_score') }}</a></th>
                            <th class="w-[7%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('shrinkage_weight') }}">Shrink {{ $sortArrow('shrinkage_weight') }}</a></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white text-gray-700">
                        @foreach($profiles as $profile)
                            @php
                                $dimensions = collect((array) json_decode((string) $profile->bucket_dimensions, true));
                                $bucketLabel = $dimensions
                                    ->reject(fn ($value, $key) => $key === 'baseline')
                                    ->map(fn ($value, $key) => $label($key) . ': ' . $label($value))
                                    ->implode(' · ');
                                [$satGrade, $satGradeClass] = $grade($profile->source_sat, $profileAverages->source_sat ?? 0);
                                [$sogGrade, $sogGradeClass] = $grade($profile->source_sog, $profileAverages->source_sog ?? 0);
                                [$goalGrade, $goalGradeClass] = $grade($profile->source_goals, $profileAverages->source_goals ?? 0);
                                [$shareGrade, $shareGradeClass] = $grade($profile->source_profile_share, $profileAverages->source_profile_share ?? 0);
                                [$xsatPer60Grade, $xsatPer60GradeClass] = $grade($profile->source_xsat_per_60 ?? 0, $profileAverages->source_xsat_per_60 ?? 0);
                                [$expectedSogGrade, $expectedSogGradeClass] = $grade($profile->expected_sog, $profileAverages->expected_sog ?? 0);
                                [$xsogPer60Grade, $xsogPer60GradeClass] = $grade($profile->source_xsog_per_60 ?? 0, $profileAverages->source_xsog_per_60 ?? 0);
                                [$expectedGoalsGrade, $expectedGoalsGradeClass] = $grade($profile->expected_goals, $profileAverages->expected_goals ?? 0);
                                [$xgPer60Grade, $xgPer60GradeClass] = $grade($profile->source_xg_per_60 ?? 0, $profileAverages->source_xg_per_60 ?? 0);
                                [$satProbGrade, $satProbGradeClass] = $grade($profile->sat_probability, $profileAverages->sat_probability ?? 0);
                                [$goalProbGrade, $goalProbGradeClass] = $grade($profile->goal_probability, $profileAverages->goal_probability ?? 0);
                                [$confGrade, $confGradeClass] = $grade($profile->confidence_score, $profileAverages->confidence_score ?? 0);
                                [$shrinkGrade, $shrinkGradeClass] = $grade($profile->shrinkage_weight ?? 0, $profileAverages->shrinkage_weight ?? 0);
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="px-1.5 py-2 align-top">
                                    <div class="truncate font-semibold text-gray-950">{{ $profile->entity_name ?? $profile->entity_key }}</div>
                                    <div class="truncate text-gray-400">{{ $profile->entity_role ?? $profile->profile_type }} · {{ $profile->team_context ?? '-' }}</div>
                                </td>
                                <td class="px-1.5 py-2 align-top">
                                    <div class="line-clamp-2 font-medium text-gray-950">{{ $bucketLabel !== '' ? $bucketLabel : $profile->matched_bucket_key }}</div>
                                    <div class="truncate text-gray-400">{{ $profile->matched_bucket_key }}</div>
                                </td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">
                                    <div>{{ number_format((int) $profile->source_sat) }}</div>
                                    <div class="text-[10px] font-medium {{ $satGradeClass }}">{{ $satGrade }}</div>
                                </td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">
                                    <div>{{ $formatRate($profile->source_xsat_per_60 ?? null) }}</div>
                                    <div class="text-[10px] font-medium {{ $xsatPer60GradeClass }}">{{ $xsatPer60Grade }}</div>
                                </td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">
                                    <div>{{ number_format((int) $profile->source_sog) }}</div>
                                    <div class="text-[10px] font-medium {{ $sogGradeClass }}">{{ $sogGrade }}</div>
                                </td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">
                                    <div>{{ number_format((int) $profile->source_goals) }}</div>
                                    <div class="text-[10px] font-medium {{ $goalGradeClass }}">{{ $goalGrade }}</div>
                                </td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">
                                    <div>{{ $formatPct($profile->source_profile_share) }}</div>
                                    <div class="text-[10px] font-medium {{ $shareGradeClass }}">{{ $shareGrade }}</div>
                                </td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">
                                    <div>{{ number_format((float) $profile->expected_sog, 1) }}</div>
                                    <div class="text-gray-400">{{ $formatRate($profile->source_xsog_per_60 ?? null) }}/60</div>
                                    <div class="text-[10px] font-medium {{ $expectedSogGradeClass }}">{{ $expectedSogGrade }}</div>
                                    <div class="text-[10px] font-medium {{ $xsogPer60GradeClass }}">{{ $xsogPer60Grade }}</div>
                                </td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">
                                    <div>{{ number_format((float) $profile->expected_goals, 1) }}</div>
                                    <div class="text-gray-400">{{ $formatRate($profile->source_xg_per_60 ?? null) }}/60</div>
                                    <div class="text-[10px] font-medium {{ $expectedGoalsGradeClass }}">{{ $expectedGoalsGrade }}</div>
                                    <div class="text-[10px] font-medium {{ $xgPer60GradeClass }}">{{ $xgPer60Grade }}</div>
                                </td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">
                                    <div>{{ $formatPct($profile->sat_probability) }}</div>
                                    <div class="text-[10px] font-medium {{ $satProbGradeClass }}">{{ $satProbGrade }}</div>
                                </td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">
                                    <div>{{ $formatPct($profile->goal_probability) }}</div>
                                    <div class="text-[10px] font-medium {{ $goalProbGradeClass }}">{{ $goalProbGrade }}</div>
                                </td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">
                                    <div>{{ $formatPct($profile->confidence_score) }}</div>
                                    <div class="text-[10px] font-medium {{ $confGradeClass }}">{{ $confGrade }}</div>
                                </td>
                                <td class="px-1.5 py-2 text-right align-top tabular-nums">
                                    <div>{{ $formatPct($profile->shrinkage_weight ?? 0) }}</div>
                                    <div class="text-[10px] font-medium {{ $shrinkGradeClass }}">{{ $shrinkGrade }}</div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="border-t border-gray-200 px-3 py-2">
                    {{ $profiles->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
