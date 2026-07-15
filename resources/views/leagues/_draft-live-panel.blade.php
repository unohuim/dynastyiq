@php
    $teamGradients = [
        'ANA' => 'linear-gradient(to bottom, #FF6F00, #000000)',
        'ARI' => 'linear-gradient(to bottom, #8C2633, #000000)',
        'BOS' => 'linear-gradient(to bottom, #FFB81C, #000000)',
        'BUF' => 'linear-gradient(to bottom, #002654, #FDBB2F)',
        'CGY' => 'linear-gradient(to bottom, #C8102E, #F1BE48)',
        'CAR' => 'linear-gradient(to bottom, #CC0000, #000000)',
        'CHI' => 'linear-gradient(to bottom, #CF0A2C, #000000)',
        'COL' => 'linear-gradient(to bottom, #6F263D, #236192)',
        'CBJ' => 'linear-gradient(to bottom, #002654, #A6A6A6)',
        'DAL' => 'linear-gradient(to bottom, #006847, #000000)',
        'DET' => 'linear-gradient(to bottom, #CE1126, #FFFFFF)',
        'EDM' => 'linear-gradient(to bottom, #FF4C00, #041E42)',
        'FLA' => 'linear-gradient(to bottom, #041E42, #C8102E)',
        'LAK' => 'linear-gradient(to bottom, #A2AAAD, #000000)',
        'MIN' => 'linear-gradient(to bottom, #154734, #A6192E)',
        'MTL' => 'linear-gradient(to bottom, #AF1E2D, #192168)',
        'NSH' => 'linear-gradient(to bottom, #FFB81C, #041E42)',
        'NJD' => 'linear-gradient(to bottom, #CE1126, #000000)',
        'NYI' => 'linear-gradient(to bottom, #00539B, #F47D30)',
        'NYR' => 'linear-gradient(to bottom, #0038A8, #CE1126)',
        'OTT' => 'linear-gradient(to bottom, #E31837, #000000)',
        'PHI' => 'linear-gradient(to bottom, #FA4616, #000000)',
        'PIT' => 'linear-gradient(to bottom, #FFB81C, #000000)',
        'SEA' => 'linear-gradient(to bottom, #001628, #99D9D9)',
        'SJS' => 'linear-gradient(to bottom, #006D75, #000000)',
        'STL' => 'linear-gradient(to bottom, #002F87, #FDB827)',
        'TBL' => 'linear-gradient(to bottom, #002868, #00529B)',
        'TOR' => 'linear-gradient(to bottom, #00205B, #003E7E)',
        'VAN' => 'linear-gradient(to bottom, #00205B, #00843D)',
        'VGK' => 'linear-gradient(to bottom, #B4975A, #333F48)',
        'WSH' => 'linear-gradient(to bottom, #C8102E, #041E42)',
        'WPG' => 'linear-gradient(to bottom, #041E42, #7B303D)',
    ];
    $fallbackTeamGradient = 'linear-gradient(to bottom, #e5e7eb, #9ca3af)';
    $draftRounds = collect($draftRounds ?? []);
@endphp

<div class="h-full min-h-0">
    @if (! empty($drafting['error_text']))
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            {{ $drafting['error_text'] }}
        </div>
    @elseif (empty($drafting['rows']))
        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-6 text-sm text-slate-600">
            {{ $drafting['empty_text'] ?? 'No drafted players yet.' }}
        </div>
    @else
        <div class="flex h-full min-h-0 flex-col gap-3 overflow-hidden p-4">
            <div
                class="relative shrink-0"
                x-init="$nextTick(() => updateRoundScrollAffordance())"
                x-on:resize.window.debounce.150ms="updateRoundScrollAffordance()"
            >
                <div
                    x-ref="roundTabsScroller"
                    x-on:scroll.debounce.50ms="updateRoundScrollAffordance()"
                    class="flex flex-nowrap items-center gap-2 overflow-x-auto pb-1"
                >
                    @foreach ($draftRounds as $roundIndex => $round)
                        <button
                            type="button"
                            x-on:click="setActiveRound({{ $roundIndex }})"
                            class="inline-flex h-8 shrink-0 items-center gap-2 rounded-lg border px-3 text-xs font-semibold transition-colors"
                            :class="activeRound === {{ $roundIndex }} ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50'"
                        >
                            <span>{{ $round['label'] }}</span>
                            <span class="rounded-full bg-white/20 px-1.5 text-[10px]">{{ $round['count'] }}</span>
                        </button>
                    @endforeach
                </div>

                <div
                    x-cloak
                    x-show="roundScrollCanLeft"
                    x-transition.opacity.duration.150ms
                    class="pointer-events-none absolute left-0 top-1/2 flex -translate-y-1/2 items-center bg-gradient-to-r from-white via-white to-transparent py-2 pr-6"
                    aria-hidden="true"
                >
                    <span class="flex h-7 w-7 items-center justify-center rounded-full border border-slate-300 bg-white text-slate-700 shadow-md ring-1 ring-slate-900/5">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M12.79 15.23a.75.75 0 0 1-1.06.02l-4.5-4.25a.75.75 0 0 1 0-1.1l4.5-4.25a.75.75 0 1 1 1.04 1.08L8.85 10l3.92 3.69a.75.75 0 0 1 .02 1.06Z" clip-rule="evenodd" />
                        </svg>
                    </span>
                </div>

                <div
                    x-cloak
                    x-show="roundScrollCanRight"
                    x-transition.opacity.duration.150ms
                    class="pointer-events-none absolute right-0 top-1/2 flex -translate-y-1/2 items-center bg-gradient-to-l from-white via-white to-transparent py-2 pl-6"
                    aria-hidden="true"
                >
                    <span class="flex h-7 w-7 items-center justify-center rounded-full border border-slate-300 bg-white text-slate-700 shadow-md ring-1 ring-slate-900/5">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M7.21 4.77a.75.75 0 0 1 1.06-.02l4.5 4.25a.75.75 0 0 1 0 1.1l-4.5 4.25a.75.75 0 1 1-1.04-1.08L11.15 10 7.23 6.31a.75.75 0 0 1-.02-1.06Z" clip-rule="evenodd" />
                        </svg>
                    </span>
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-hidden">
                @foreach ($draftRounds as $roundIndex => $round)
                    <ol
                        x-cloak
                        x-show="activeRound === {{ $roundIndex }}"
                        x-transition.opacity.duration.150ms
                        x-ref="roundScroller{{ $roundIndex }}"
                        data-draft-round-index="{{ $roundIndex }}"
                        class="h-full scroll-pb-6 divide-y divide-slate-100 overflow-y-auto rounded-xl border border-slate-200"
                    >
                        @foreach ($round['rows'] as $row)
                            @php
                                $teamAbbrev = strtoupper((string) ($row['team_abbrev'] ?? ''));
                                $teamBadgeBackground = $teamGradients[$teamAbbrev] ?? $fallbackTeamGradient;
                                $pickInRound = $row['pick_in_round'] ?? $row['pick'] ?? null;
                                $overallPick = $row['overall_pick'] ?? $row['pick'] ?? null;
                                $hasDraftedPlayer = (bool) ($row['is_picked'] ?? ! empty($row['fantrax_player_id']) || ! empty($row['player_id']));
                                $isNextPick = ! empty($row['is_next_pick']);
                                $isGoalie = strtoupper((string) ($row['position'] ?? '')) === 'G';
                                $statColumns = $isGoalie
                                    ? ['gp' => 'GP', 'wins' => 'W', 'sv_pct' => 'SV%']
                                    : ['gp' => 'GP', 'g' => 'G', 'a' => 'A', 'pts' => 'PTS'];
                            @endphp

                            <li @if ($isNextPick) data-next-pick="true" @endif class="grid grid-cols-[3.25rem_minmax(0,1.25fr)_4.5rem_minmax(8rem,0.75fr)_minmax(0,1.1fr)] items-center gap-2 bg-white px-4 py-3">
                                <div class="flex flex-col items-center justify-center tabular-nums">
                                    <div class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-300 bg-white text-xs font-semibold text-slate-700">
                                        {{ $pickInRound ?? '-' }}
                                    </div>
                                    <div class="mt-1 text-[10px] font-medium text-slate-400">
                                        {{ $overallPick ? '#' . $overallPick : '' }}
                                    </div>
                                </div>

                                <div class="flex min-w-0 items-center gap-3">
                                    @if ($hasDraftedPlayer)
                                        <div x-show="showAvatars" class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-slate-100 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                                            @if (! empty($row['avatar_url']))
                                                <img src="{{ $row['avatar_url'] }}" alt="" class="h-full w-full object-cover" loading="lazy">
                                            @else
                                                {{ collect(explode(' ', $row['player_name'] ?? ''))->filter()->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') ?: '?' }}
                                            @endif
                                        </div>
                                    @endif

                                    <div class="min-w-0">
                                        @if ($hasDraftedPlayer)
                                            <div class="truncate text-sm font-semibold text-slate-900">
                                                {{ $row['player_name'] }}
                                            </div>
                                            <div class="mt-0.5 flex min-w-0 items-center gap-1.5 text-xs text-slate-500">
                                                @if (! empty($row['position']))
                                                    <span class="shrink-0">{{ $row['position'] }}</span>
                                                @endif
                                                @if (! empty($row['position']) && ! empty($row['league_abbrev']))
                                                    <span class="shrink-0 text-slate-300">/</span>
                                                @endif
                                                @if (! empty($row['league_abbrev']))
                                                    <span class="truncate">{{ $row['league_abbrev'] }}</span>
                                                @endif
                                            </div>
                                        @elseif ($isNextPick)
                                            <div class="space-y-2">
                                                <div class="h-3 w-24 animate-pulse rounded-full bg-slate-200"></div>
                                                <div class="h-2 w-20 animate-pulse rounded-full bg-slate-100"></div>
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex justify-center" x-show="showTeamBadges">
                                    @if ($hasDraftedPlayer)
                                        <span
                                            class="inline-flex h-7 min-w-14 items-center justify-center rounded-md px-3 text-xs font-semibold tracking-wide text-white shadow-sm"
                                            style="background: {{ $teamBadgeBackground }};"
                                        >
                                            {{ $teamAbbrev !== '' ? $teamAbbrev : '-' }}
                                        </span>
                                    @endif
                                </div>

                                <div class="grid gap-2 text-right tabular-nums {{ $isGoalie ? 'grid-cols-3' : 'grid-cols-4' }}">
                                    @if ($hasDraftedPlayer)
                                        @foreach ($statColumns as $statKey => $label)
                                            <div>
                                                <div class="text-[9px] font-semibold uppercase tracking-wide text-slate-400">{{ $label }}</div>
                                                <div class="text-xs font-semibold text-slate-900">
                                                    @if ($statKey === 'sv_pct' && data_get($row, 'stats.' . $statKey) !== null)
                                                        {{ number_format((float) data_get($row, 'stats.' . $statKey), 3) }}
                                                    @else
                                                        {{ data_get($row, 'stats.' . $statKey) ?? '-' }}
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    @endif
                                </div>

                                <div class="flex min-w-0 items-center justify-end gap-2">
                                    @if ($isNextPick)
                                        <span class="inline-flex h-5 shrink-0 items-center rounded-full bg-orange-100 px-2 text-[11px] font-semibold text-orange-700 ring-1 ring-orange-200">
                                            OTC
                                        </span>
                                    @endif

                                    <div class="min-w-0 text-right">
                                        <div class="truncate text-xs font-semibold text-slate-700">
                                            {{ $row['team_name'] }}
                                        </div>
                                        <div class="text-[10px] uppercase tracking-wide text-slate-400">Drafted by</div>
                                    </div>

                                    @if (! empty($row['team_avatar_url']))
                                        <img src="{{ $row['team_avatar_url'] }}" alt="" class="h-8 w-8 shrink-0 rounded-full object-cover ring-1 ring-slate-200" loading="lazy">
                                    @else
                                        <div class="h-8 w-8 shrink-0 rounded-full bg-slate-100 ring-1 ring-slate-200"></div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endforeach
            </div>
        </div>
    @endif
</div>
