@php
    $formatRate = fn ($value) => $value === null ? '-' : number_format((float) $value, 2);
    $formatPct = fn ($value) => $value === null ? '-' : number_format(((float) $value) * 100, 1) . '%';
    $formatDelta = fn ($value) => $value === null ? '-' : (((float) $value) >= 0 ? '+' : '') . number_format((float) $value, 2);
    $formatDeltaPct = fn ($value) => $value === null ? '-' : (((float) $value) >= 0 ? '+' : '') . number_format(((float) $value) * 100, 1) . '%';
    $formatCount = fn ($value) => $value === null ? '-' : number_format((float) $value, 1);
    $formatToiGp = fn ($seconds) => $seconds === null ? '-' : number_format(((float) $seconds) / 60, 1);
    $formatSeasonHours = fn ($seconds, float $divisor = 1.0) => $seconds === null || $divisor <= 0 ? '-' : number_format((((float) $seconds) / $divisor) / 3600, 1);
    $formatHitRate = function ($count, $rate): string {
        if ($count === null || $rate === null) {
            return '-';
        }

        return number_format((int) $count) . ' / ' . number_format(((float) $rate) * 100, 1) . '%';
    };
    $perGame = fn ($count, $games) => (int) ($games ?? 0) > 0 ? ((float) $count) / (int) $games : null;
    $deltaClass = fn ($value) => $value === null ? 'text-gray-400' : (((float) $value) >= 0 ? 'text-emerald-700' : 'text-rose-700');
    $sortUrl = function (string $key) use ($run, $profileType, $sort, $direction, $search): string {
        return route('admin.nhl-sat-models.rate-projections.compare.aggregates', [
            'run' => $run,
            'profile_type' => $profileType,
            'sort' => $key,
            'direction' => $sort === $key && $direction === 'desc' ? 'asc' : 'desc',
            'q' => $search !== '' ? $search : null,
        ]);
    };
    $exportUrl = route('admin.nhl-sat-models.rate-projections.compare.aggregates.export', [
        'run' => $run,
        'profile_type' => $profileType,
        'sort' => $sort,
        'direction' => $direction,
        'q' => $search !== '' ? $search : null,
    ]);
    $sortArrow = fn (string $key): string => $sort === $key ? ($direction === 'desc' ? 'v' : '^') : '';
    $rateCell = function ($train, $last, $projected, $test, $drift, $driftRate, $error, $errorRate) use ($formatRate, $formatDelta, $formatDeltaPct, $deltaClass): string {
        return sprintf(
            '<div class="space-y-0.5 tabular-nums"><div class="flex justify-between gap-2"><span class="text-gray-400">Train</span><span class="font-semibold text-gray-950">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Last</span><span class="font-semibold text-gray-700">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Proj</span><span class="font-semibold text-gray-950">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Test</span><span class="font-semibold text-gray-700">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Drift</span><span class="font-semibold %s">%s / %s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Error</span><span class="font-semibold %s">%s / %s</span></div></div>',
            e($formatRate($train)),
            e($formatRate($last)),
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
    $hdsatCell = function ($row) use ($formatCount, $formatRate, $formatPct, $formatDelta, $formatDeltaPct, $deltaClass, $perGame): string {
        $trainGp = $row->train_eval_hdsat_per_gp ?? $perGame($row->train_hdsat ?? null, $row->train_games ?? null);
        $testGp = $row->test_eval_hdsat_per_gp ?? $perGame($row->test_hdsat ?? null, $row->test_games ?? null);
        $train60 = $row->train_eval_hdsat_per_60 ?? $row->train_hdsat_per_60 ?? null;
        $test60 = $row->test_eval_hdsat_per_60 ?? $row->test_hdsat_per_60 ?? null;
        $projectedGp = $row->projected_split_hdsat_per_gp ?? null;
        $projected60 = $row->projected_split_hdsat_per_60 ?? null;
        $trainShare = $row->train_eval_hdsat_sat_rate ?? null;
        $testShare = $row->test_eval_hdsat_sat_rate ?? null;

        return sprintf(
            '<div class="space-y-0.5 tabular-nums"><div class="flex justify-between gap-2"><span class="text-gray-400">Train/GP</span><span class="font-semibold text-gray-950">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Proj/GP</span><span class="font-semibold text-gray-950">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Test/GP</span><span class="font-semibold text-gray-700">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Train/60</span><span class="font-semibold text-gray-950">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Proj/60</span><span class="font-semibold text-gray-950">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Test/60</span><span class="font-semibold text-gray-700">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">HD%%</span><span class="font-semibold text-gray-700">%s / %s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Drift</span><span class="font-semibold %s">%s / %s</span></div></div>',
            e($formatCount($trainGp)),
            e($formatCount($projectedGp)),
            e($formatCount($testGp)),
            e($formatRate($train60)),
            e($formatRate($projected60)),
            e($formatRate($test60)),
            e($formatPct($trainShare)),
            e($formatPct($testShare)),
            e($deltaClass($row->hdsat_drift ?? null)),
            e($formatDelta($row->hdsat_drift ?? null)),
            e($formatDeltaPct($row->hdsat_drift_rate ?? null)),
        );
    };
    $statEvalCell = function ($row, string $metric) use ($formatCount, $formatRate, $perGame): string {
        $trainTotal = $row->{'train_eval_' . $metric} ?? $row->{'train_' . ($metric === 'goals' ? 'goals' : $metric)} ?? null;
        $testTotal = $row->{'test_eval_' . $metric} ?? $row->{'test_' . ($metric === 'goals' ? 'goals' : $metric)} ?? null;
        $trainGp = $row->{'train_eval_' . $metric . '_per_gp'} ?? $perGame($trainTotal, $row->train_games ?? null);
        $testGp = $row->{'test_eval_' . $metric . '_per_gp'} ?? $perGame($testTotal, $row->test_games ?? null);
        $train60 = $row->{'train_eval_' . $metric . '_per_60'} ?? null;
        $test60 = $row->{'test_eval_' . $metric . '_per_60'} ?? null;
        $projectedGp = $metric === 'sat' ? ($row->projected_split_sat_per_gp ?? null) : null;
        $projected60 = $metric === 'sat' ? ($row->projected_split_sat_per_60 ?? null) : null;

        return sprintf(
            '<div class="space-y-0.5 tabular-nums"><div class="flex justify-between gap-2"><span class="text-gray-400">Train</span><span class="font-semibold text-gray-950">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Test</span><span class="font-semibold text-gray-700">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Train/GP</span><span class="font-semibold text-gray-950">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Proj/GP</span><span class="font-semibold text-gray-950">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Test/GP</span><span class="font-semibold text-gray-700">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Train/60</span><span class="font-semibold text-gray-950">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Proj/60</span><span class="font-semibold text-gray-950">%s</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Test/60</span><span class="font-semibold text-gray-700">%s</span></div></div>',
            e($formatCount($trainTotal)),
            e($formatCount($testTotal)),
            e($formatCount($trainGp)),
            e($formatCount($projectedGp)),
            e($formatCount($testGp)),
            e($formatRate($train60)),
            e($formatRate($projected60)),
            e($formatRate($test60)),
        );
    };
    $toiCell = function ($row) use ($formatToiGp, $formatSeasonHours): string {
        $secondsPerGame = function ($seconds, $games): ?float {
            return $seconds !== null && (float) ($games ?? 0) > 0
                ? ((float) $seconds) / (float) $games
                : null;
        };
        $s1ToiSeconds = ($row->train_toi_seconds ?? null) === null || ($row->last_toi_seconds ?? null) === null
            ? null
            : max(0, (float) $row->train_toi_seconds - (float) $row->last_toi_seconds);
        $s1Games = ($row->train_games ?? null) === null || ($row->last_games ?? null) === null
            ? null
            : max(0, (float) $row->train_games - (float) $row->last_games);
        $s1ToiPerGameSeconds = ($row->s1_toi_per_game_seconds ?? null) ?? $secondsPerGame($s1ToiSeconds, $s1Games);
        $lastToiPerGameSeconds = ($row->last_toi_per_game_seconds ?? null) ?? $secondsPerGame($row->last_toi_seconds ?? null, $row->last_games ?? null);
        $testToiPerGameSeconds = ($row->test_toi_per_game_seconds ?? null) ?? $secondsPerGame($row->test_toi_seconds ?? null, $row->test_games ?? null);

        return sprintf(
            '<div class="space-y-0.5 tabular-nums"><div class="flex justify-between gap-2"><span class="text-gray-400">S1</span><span class="font-semibold text-gray-950">%s / %s h</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">S2</span><span class="font-semibold text-gray-700">%s / %s h</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">Proj</span><span class="font-semibold text-gray-950">%s / %s h</span></div><div class="flex justify-between gap-2"><span class="text-gray-400">S3</span><span class="font-semibold text-gray-700">%s / %s h</span></div></div>',
            e($formatToiGp($s1ToiPerGameSeconds)),
            e($formatSeasonHours($s1ToiSeconds)),
            e($formatToiGp($lastToiPerGameSeconds)),
            e($formatSeasonHours($row->last_toi_seconds ?? null)),
            e($formatToiGp($row->projected_toi_per_game_seconds ?? null)),
            e($formatSeasonHours($row->projected_toi_seconds ?? null)),
            e($formatToiGp($testToiPerGameSeconds)),
            e($formatSeasonHours($row->test_toi_seconds ?? null)),
        );
    };
@endphp

<x-app-layout>
    <div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
                <a href="{{ route('admin.nhl-sat-models.index') }}" class="text-xs font-medium text-gray-500 transition-colors hover:text-gray-950">SAT Models</a>
                <h1 class="mt-1 text-xl font-semibold tracking-normal text-gray-950">{{ $run->name }}</h1>
                <p class="mt-1 text-sm text-gray-500">Entity-level aggregate /60 comparison against the held-out test season.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ $exportUrl }}" class="inline-flex size-8 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-700 shadow-sm transition-colors hover:bg-gray-50 hover:text-gray-950" title="Download CSV" aria-label="Download CSV">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M10.75 2.75a.75.75 0 0 0-1.5 0v7.69L6.03 7.22a.75.75 0 0 0-1.06 1.06l4.5 4.5a.75.75 0 0 0 1.06 0l4.5-4.5a.75.75 0 0 0-1.06-1.06l-3.22 3.22V2.75Z" />
                        <path d="M4.5 14.75a.75.75 0 0 0 0 1.5h11a.75.75 0 0 0 0-1.5h-11Z" />
                    </svg>
                </a>
                <a href="{{ route('admin.nhl-sat-models.rate-projections.compare.raw', $run) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 hover:text-gray-950">
                    Raw Compare /60
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
                <div class="text-[11px] font-medium uppercase text-gray-500">Entities</div>
                <div class="mt-1 text-sm font-semibold text-gray-950">{{ number_format((int) collect($summary)->sum('entities')) }}</div>
            </div>
            <div class="border border-gray-200 bg-white px-3 py-2">
                <div class="text-[11px] font-medium uppercase text-gray-500">Bucket Rows</div>
                <div class="mt-1 text-sm font-semibold text-gray-950">{{ number_format((int) collect($summary)->sum('rows')) }}</div>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap gap-2">
            @foreach($profileTypes as $type => $typeLabel)
                @php
                    $typeSummary = $summary->get($type);
                @endphp
                <a
                    href="{{ route('admin.nhl-sat-models.rate-projections.compare.aggregates', ['run' => $run, 'profile_type' => $type, 'sort' => $sort, 'direction' => $direction, 'q' => $search !== '' ? $search : null]) }}"
                    class="inline-flex items-center gap-2 rounded-md border px-3 py-2 text-xs font-semibold transition-colors {{ $profileType === $type ? 'border-gray-950 bg-gray-950 text-white' : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 hover:text-gray-950' }}"
                >
                    <span>{{ $typeLabel }}</span>
                    <span class="{{ $profileType === $type ? 'text-gray-300' : 'text-gray-400' }}">{{ number_format((int) ($typeSummary->entities ?? 0)) }}</span>
                </a>
            @endforeach
        </div>

        <form method="GET" action="{{ route('admin.nhl-sat-models.rate-projections.compare.aggregates', $run) }}" class="mb-4 flex flex-wrap items-center gap-3 border border-gray-200 bg-white px-3 py-2">
            <input type="hidden" name="profile_type" value="{{ $profileType }}">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">
            <input
                type="search"
                name="q"
                value="{{ $search }}"
                placeholder="Search entities"
                class="h-8 w-full max-w-xs rounded-md border-gray-300 text-xs text-gray-950 shadow-sm focus:border-gray-950 focus:ring-gray-950"
            >
            <button type="submit" class="inline-flex h-8 items-center rounded-md border border-gray-300 bg-white px-3 text-xs font-semibold text-gray-700 transition-colors hover:border-gray-400 hover:text-gray-950">
                Search
            </button>
            <span class="text-xs text-gray-400">SAT/HDSAT/SOG/G are per game for train and test. TOI shows derived S1, S2, projected S3, and actual S3. Collection /60 values are per entity. Drift is test minus train. Error is test minus projection.</span>
        </form>

        @if($collectionRows->isNotEmpty())
            <div class="mb-4 border border-gray-200 bg-white">
                <div class="border-b border-gray-200 px-3 py-2">
                    <h2 class="text-sm font-semibold text-gray-950">Collection Summaries</h2>
                </div>
                <table class="w-full table-fixed divide-y divide-gray-200 text-[10px]">
                    <thead class="bg-gray-50 text-left font-semibold uppercase text-gray-500">
                        <tr>
                            <th class="w-[13%] px-1.5 py-2">Collection</th>
                            <th class="w-[11%] px-1.5 py-2 text-right">TOI/GP · Hrs</th>
                            <th class="w-[9%] px-1.5 py-2 text-right">SAT</th>
                            <th class="w-[10%] px-1.5 py-2 text-right">HDSAT</th>
                            <th class="w-[9%] px-1.5 py-2 text-right">SOG</th>
                            <th class="w-[7%] px-1.5 py-2 text-right">G</th>
                            <th class="w-[14%] px-1.5 py-2 text-right">xSAT/60/Entity</th>
                            <th class="w-[13%] px-1.5 py-2 text-right">xSOG/60/Entity</th>
                            <th class="w-[14%] px-1.5 py-2 text-right">xG/60/Entity</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white text-gray-700">
                        @foreach($collectionRows as $collectionRow)
                            <tr class="bg-gray-50">
                                <td class="px-1.5 py-2 align-top">
                                    <div class="truncate font-semibold text-gray-950">{{ $collectionRow->collection_label ?? 'Collection' }}</div>
                                    <div class="truncate text-gray-400">{{ $collectionRow->collection_context ?? ($profileTypes[$profileType] ?? $profileType) }}</div>
                                    <div class="mt-1 text-gray-400">{{ number_format((int) ($collectionRow->matched_bucket_rows ?? 0)) }} of {{ number_format((int) ($collectionRow->bucket_rows ?? 0)) }} buckets matched · {{ number_format((int) ($collectionRow->entities ?? 0)) }} entities</div>
                                    <div class="mt-1 text-gray-500">
                                        <span class="font-semibold text-gray-700">xSAT hit</span>
                                        &lt;3% {{ $formatHitRate($collectionRow->xsat_error_within_3_count ?? null, $collectionRow->xsat_error_within_3_rate ?? null) }}
                                        · &lt;5% {{ $formatHitRate($collectionRow->xsat_error_within_5_count ?? null, $collectionRow->xsat_error_within_5_rate ?? null) }}
                                        · &lt;10% {{ $formatHitRate($collectionRow->xsat_error_within_10_count ?? null, $collectionRow->xsat_error_within_10_rate ?? null) }}
                                    </div>
                                </td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $toiCell($collectionRow) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $statEvalCell($collectionRow, 'sat') !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $hdsatCell($collectionRow) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $statEvalCell($collectionRow, 'sog') !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $statEvalCell($collectionRow, 'goals') !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $rateCell($collectionRow->train_xsat_per_60, $collectionRow->last_xsat_per_60, $collectionRow->projected_xsat_per_60, $collectionRow->test_xsat_per_60, $collectionRow->xsat_drift, $collectionRow->xsat_drift_rate, $collectionRow->xsat_error, $collectionRow->xsat_error_rate) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $rateCell($collectionRow->train_xsog_per_60, $collectionRow->last_xsog_per_60, $collectionRow->projected_xsog_per_60, $collectionRow->test_xsog_per_60, $collectionRow->xsog_drift, $collectionRow->xsog_drift_rate, $collectionRow->xsog_error, $collectionRow->xsog_error_rate) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $rateCell($collectionRow->train_xg_per_60, $collectionRow->last_xg_per_60, $collectionRow->projected_xg_per_60, $collectionRow->test_xg_per_60, $collectionRow->xg_drift, $collectionRow->xg_drift_rate, $collectionRow->xg_error, $collectionRow->xg_error_rate) !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="border border-gray-200 bg-white">
            @if($aggregates->count() === 0)
                <div class="px-5 py-12 text-center">
                    <div class="text-sm font-semibold text-gray-950">No aggregate comparison yet</div>
                    <div class="mt-1 text-sm text-gray-500">Run Compare /60 after /60 projections and test profiles exist.</div>
                </div>
            @else
                <table class="w-full table-fixed divide-y divide-gray-200 text-[10px]">
                    <thead class="bg-gray-50 text-left font-semibold uppercase text-gray-500">
                        <tr>
                            <th class="w-[13%] px-1.5 py-2"><a href="{{ $sortUrl('entity') }}">Entity {{ $sortArrow('entity') }}</a></th>
                            <th class="w-[11%] px-1.5 py-2 text-right">TOI/GP · Hrs</th>
                            <th class="w-[9%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('test_eval_sat_per_60') }}">SAT {{ $sortArrow('test_eval_sat_per_60') }}</a></th>
                            <th class="w-[10%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('test_eval_hdsat_per_60') }}">HDSAT {{ $sortArrow('test_eval_hdsat_per_60') }}</a></th>
                            <th class="w-[9%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('test_eval_sog_per_60') }}">SOG {{ $sortArrow('test_eval_sog_per_60') }}</a></th>
                            <th class="w-[7%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('test_eval_goals_per_60') }}">G {{ $sortArrow('test_eval_goals_per_60') }}</a></th>
                            <th class="w-[14%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('test_xsat_per_60') }}">xSAT/60 {{ $sortArrow('test_xsat_per_60') }}</a></th>
                            <th class="w-[13%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('test_xsog_per_60') }}">xSOG/60 {{ $sortArrow('test_xsog_per_60') }}</a></th>
                            <th class="w-[14%] px-1.5 py-2 text-right"><a href="{{ $sortUrl('test_xg_per_60') }}">xG/60 {{ $sortArrow('test_xg_per_60') }}</a></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white text-gray-700">
                        @foreach($aggregates as $aggregate)
                            <tr class="hover:bg-gray-50">
                                <td class="px-1.5 py-2 align-top">
                                    <div class="truncate font-semibold text-gray-950">{{ $aggregate->entity_name ?? $aggregate->entity_key }}</div>
                                    <div class="truncate text-gray-400">{{ $aggregate->entity_role ?? $aggregate->profile_type }} · {{ $aggregate->team_context ?? '-' }}</div>
                                    @if(($aggregate->player_position ?? null) || ($aggregate->player_age ?? null))
                                        <div class="truncate text-gray-500">
                                            {{ $aggregate->player_position ?? '-' }}@if($aggregate->player_age ?? null) · Age {{ number_format((float) $aggregate->player_age, 0) }}@endif
                                        </div>
                                    @endif
                                    <div class="mt-1 text-gray-400">{{ number_format((int) $aggregate->matched_bucket_rows) }} of {{ number_format((int) $aggregate->bucket_rows) }} buckets matched</div>
                                </td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $toiCell($aggregate) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $statEvalCell($aggregate, 'sat') !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $hdsatCell($aggregate) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $statEvalCell($aggregate, 'sog') !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $statEvalCell($aggregate, 'goals') !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $rateCell($aggregate->train_xsat_per_60, $aggregate->last_xsat_per_60, $aggregate->projected_xsat_per_60, $aggregate->test_xsat_per_60, $aggregate->xsat_drift, $aggregate->xsat_drift_rate, $aggregate->xsat_error, $aggregate->xsat_error_rate) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $rateCell($aggregate->train_xsog_per_60, $aggregate->last_xsog_per_60, $aggregate->projected_xsog_per_60, $aggregate->test_xsog_per_60, $aggregate->xsog_drift, $aggregate->xsog_drift_rate, $aggregate->xsog_error, $aggregate->xsog_error_rate) !!}</td>
                                <td class="px-1.5 py-2 text-right align-top">{!! $rateCell($aggregate->train_xg_per_60, $aggregate->last_xg_per_60, $aggregate->projected_xg_per_60, $aggregate->test_xg_per_60, $aggregate->xg_drift, $aggregate->xg_drift_rate, $aggregate->xg_error, $aggregate->xg_error_rate) !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="border-t border-gray-200 px-3 py-2">
                    {{ $aggregates->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
