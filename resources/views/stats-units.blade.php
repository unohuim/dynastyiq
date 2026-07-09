{{-- resources/views/stats-units.blade.php (FULL REPLACE) --}}
<x-app-layout>
    <style>
        [x-cloak]{display:none!important}
        /* account for bottom app nav + browser UI on mobile */
        @media (max-width: 640px){
            .safe-bottom{padding-bottom:calc(env(safe-area-inset-bottom,0px) + 88px)}
        }
        /* sticky header shadow only when stuck (Safari compatible simplification) */
        .sticky-header{position:sticky;top:0;z-index:30;backdrop-filter:saturate(180%) blur(6px)}
    </style>

    @php
        $sortable = [
            'sf'   => 'Shots For',          'sa'   => 'Shots Against',
            'gf'   => 'Goals For',          'ga'   => 'Goals Against',
            'satf' => 'Shot Attempts For',  'sata' => 'Shot Attempts Against',
            'hf'   => 'Hits For',           'ha'   => 'Hits Against',
            'bf'   => 'Blocks For',         'ba'   => 'Blocks Against',
            'fow'  => 'Faceoffs Won',       'fol'  => 'Faceoffs Lost',
            'penalties_f' => 'Penalties For','penalties_a' => 'Penalties Against',
            'pim_f' => 'PIM For',           'pim_a' => 'PIM Against',
            'ozs'  => 'Zone Starts O',      'dzs'  => 'Zone Starts D',
        ];
        $currentSort = $sort ?? request('sort','gf');
        $currentPos  = collect(request('pos', $pos ?? ['F']))->values()->all();
        $currentDisplay = in_array(($displayMode ?? request('display', 'counts')), ['counts', 'share'], true)
            ? ($displayMode ?? request('display', 'counts'))
            : 'counts';
        $filters = $filters ?? [];
        $filterBounds = $filterBounds ?? [
            'gp' => ['min' => 0, 'max' => 0],
            'shifts' => ['min' => 0, 'max' => 0],
            'toi' => ['min' => 0, 'max' => 0],
            'gf' => ['min' => 0, 'max' => 0],
            'sf' => ['min' => 0, 'max' => 0],
            'satf' => ['min' => 0, 'max' => 0],
        ];
        $filterDefaults = $filterDefaults ?? [];
        $gpMin = $filters['gp_min'] ?? null;
        $gpMax = $filters['gp_max'] ?? null;
        $shiftsMin = $filters['shifts_min'] ?? null;
        $shiftsMax = $filters['shifts_max'] ?? null;
        $toiMin = $filters['toi_min'] ?? null;
        $toiMax = $filters['toi_max'] ?? null;
        $gfMin = $filters['gf_min'] ?? null;
        $gfMax = $filters['gf_max'] ?? null;
        $sfMin = $filters['sf_min'] ?? null;
        $sfMax = $filters['sf_max'] ?? null;
        $satfMin = $filters['satf_min'] ?? null;
        $satfMax = $filters['satf_max'] ?? null;
        $gpRangeMin = (int) data_get($filterBounds, 'gp.min', 0);
        $gpRangeMax = max($gpRangeMin + 1, (int) data_get($filterBounds, 'gp.max', 0));
        $shiftsRangeMin = (int) data_get($filterBounds, 'shifts.min', 0);
        $shiftsRangeMax = max($shiftsRangeMin + 1, (int) data_get($filterBounds, 'shifts.max', 0));
        $toiRangeMin = (int) data_get($filterBounds, 'toi.min', 0);
        $toiRangeMax = max($toiRangeMin + 1, (int) data_get($filterBounds, 'toi.max', 0));
        $gfRangeMin = (int) data_get($filterBounds, 'gf.min', 0);
        $gfRangeMax = max($gfRangeMin + 1, (int) data_get($filterBounds, 'gf.max', 0));
        $sfRangeMin = (int) data_get($filterBounds, 'sf.min', 0);
        $sfRangeMax = max($sfRangeMin + 1, (int) data_get($filterBounds, 'sf.max', 0));
        $satfRangeMin = (int) data_get($filterBounds, 'satf.min', 0);
        $satfRangeMax = max($satfRangeMin + 1, (int) data_get($filterBounds, 'satf.max', 0));
        $gpDefaultMin = (int) data_get($filterDefaults, 'gp_min', $gpRangeMin);
        $shiftsDefaultMin = (int) data_get($filterDefaults, 'shifts_min', $shiftsRangeMin);
        $toiDefaultMin = (int) data_get($filterDefaults, 'toi_min', $toiRangeMin);
        $gfDefaultMin = (int) data_get($filterDefaults, 'gf_min', 0);
        $sfDefaultMin = (int) data_get($filterDefaults, 'sf_min', 0);
        $satfDefaultMin = (int) data_get($filterDefaults, 'satf_min', 0);
        $gpRangeConfig = [
            'key' => 'gp',
            'label' => 'Games played',
            'type' => 'dual',
            'min' => $gpRangeMin,
            'max' => $gpRangeMax,
            'step' => 1,
            'minValue' => $gpMin ?? $gpDefaultMin,
            'maxValue' => $gpMax ?? $gpRangeMax,
        ];
        $shiftsRangeConfig = [
            'key' => 'shifts',
            'label' => 'Shifts',
            'type' => 'dual',
            'min' => $shiftsRangeMin,
            'max' => $shiftsRangeMax,
            'step' => 1,
            'minValue' => $shiftsMin ?? $shiftsDefaultMin,
            'maxValue' => $shiftsMax ?? $shiftsRangeMax,
        ];
        $toiRangeConfig = [
            'key' => 'toi',
            'label' => 'Time on ice (min)',
            'type' => 'dual',
            'min' => $toiRangeMin,
            'max' => $toiRangeMax,
            'step' => 1,
            'minValue' => $toiMin ?? $toiDefaultMin,
            'maxValue' => $toiMax ?? $toiRangeMax,
        ];
        $gfRangeConfig = [
            'key' => 'gf',
            'label' => 'Goals for (count)',
            'type' => 'dual',
            'min' => $gfRangeMin,
            'max' => $gfRangeMax,
            'step' => 1,
            'minValue' => $gfMin ?? $gfDefaultMin,
            'maxValue' => $gfMax ?? $gfRangeMax,
        ];
        $sfRangeConfig = [
            'key' => 'sf',
            'label' => 'Shots for (count)',
            'type' => 'dual',
            'min' => $sfRangeMin,
            'max' => $sfRangeMax,
            'step' => 1,
            'minValue' => $sfMin ?? $sfDefaultMin,
            'maxValue' => $sfMax ?? $sfRangeMax,
        ];
        $satfRangeConfig = [
            'key' => 'satf',
            'label' => 'Shot attempts for (count)',
            'type' => 'dual',
            'min' => $satfRangeMin,
            'max' => $satfRangeMax,
            'step' => 1,
            'minValue' => $satfMin ?? $satfDefaultMin,
            'maxValue' => $satfMax ?? $satfRangeMax,
        ];
        $posOptions  = ['F','D','PP','PK']; // include special teams
        $seasonOptions = collect($availableSeasons ?? []);
        $gameTypeOptions = [
            1 => 'Pre',
            2 => 'Reg',
            3 => 'Post',
        ];
    @endphp

    <div
        class="px-4 safe-bottom max-w-7xl mx-auto"
        x-data="{
            pos:  @js($currentPos[0] ?? 'F'),
            sort: @js($currentSort),
            display: @js($currentDisplay),
            seasonId: @js($seasonId ?? ''),
            gameType: @js((string) ($gameType ?? 2)),
            filtersOpen:false,
            selectPos(p){
              this.pos = p;
              this.$nextTick(()=> $refs.submit.click());
            },
            selectGameType(v){
              this.gameType = String(v);
              this.$nextTick(()=> $refs.submit.click());
            },
            selectDisplay(v){
              if (this.display === v) return;
              this.display = v;
              this.$nextTick(()=> $refs.submit.click());
            }
        }"
    >
        <header class="sticky top-0 z-30 bg-white/90 supports-[backdrop-filter]:bg-white/60 backdrop-blur border-b border-gray-200">
            <div class="py-3 px-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">Line Combos</h1>
                    <p class="text-sm text-gray-500">{{ $seasonLabel }} &middot; {{ $gameTypeLabel }}</p>
                </div>

                {{-- Controls --}}
                <form method="GET" action="{{ url()->current() }}" id="stats-units-filter-form"
                      class="relative flex items-center gap-2"
                >
                    <input type="hidden" name="dir" value="desc">
                    <input type="hidden" name="pos[]" :value="pos">
                    <input type="hidden" name="sort" :value="sort">
                    <input type="hidden" name="game_type" :value="gameType">
                    <input type="hidden" name="display" :value="display">
                    <input type="hidden" name="gp_min" value="{{ $gpMin }}">
                    <input type="hidden" name="gp_max" value="{{ $gpMax }}">
                    <input type="hidden" name="shifts_min" value="{{ $shiftsMin }}">
                    <input type="hidden" name="shifts_max" value="{{ $shiftsMax }}">
                    <input type="hidden" name="toi_min" value="{{ $toiMin }}">
                    <input type="hidden" name="toi_max" value="{{ $toiMax }}">
                    <input type="hidden" name="gf_min" value="{{ $gfMin }}">
                    <input type="hidden" name="gf_max" value="{{ $gfMax }}">
                    <input type="hidden" name="sf_min" value="{{ $sfMin }}">
                    <input type="hidden" name="sf_max" value="{{ $sfMax }}">
                    <input type="hidden" name="satf_min" value="{{ $satfMin }}">
                    <input type="hidden" name="satf_max" value="{{ $satfMax }}">

                    <select
                        name="season_id"
                        x-model="seasonId"
                        @change="$refs.submit.click()"
                        class="h-9 rounded-lg border border-gray-200 bg-white px-3 pr-8 text-sm font-medium text-gray-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-200"
                    >
                        @foreach ($seasonOptions as $season)
                            @php
                                $label = preg_match('/^(\d{4})(\d{4})$/', (string) $season, $matches)
                                    ? $matches[1] . '-' . substr($matches[2], -2)
                                    : (string) $season;
                            @endphp
                            <option value="{{ $season }}">{{ $label }}</option>
                        @endforeach
                    </select>

                    @foreach ($gameTypeOptions as $value => $label)
                        <button type="button"
                                @click="selectGameType('{{ $value }}')"
                                :aria-pressed="gameType==='{{ $value }}'"
                                :class="gameType==='{{ $value }}'
                                        ? 'bg-slate-900 text-white'
                                        : 'bg-white text-gray-700 hover:bg-gray-50'"
                                class="px-3 py-1.5 rounded-lg text-sm font-medium border border-gray-200 shadow-sm">
                            {{ $label }}
                        </button>
                    @endforeach

                    @foreach ($posOptions as $p)
                        <button type="button"
                                @click="selectPos('{{ $p }}')"
                                :aria-pressed="pos==='{{ $p }}'"
                                :class="pos==='{{ $p }}'
                                        ? 'bg-indigo-600 text-white'
                                        : 'bg-white text-gray-700 hover:bg-gray-50'"
                                class="px-3 py-1.5 rounded-lg text-sm font-medium border border-gray-200 shadow-sm">
                            {{ $p }}
                        </button>
                    @endforeach

                    <label class="sr-only" for="stats-units-sort">Sort by</label>
                    <select
                        id="stats-units-sort"
                        x-model="sort"
                        @change="$refs.submit.click()"
                        class="h-9 rounded-lg border border-gray-200 bg-white px-3 pr-8 text-sm font-medium text-gray-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-200"
                    >
                        @foreach ($sortable as $k => $label)
                            <option value="{{ $k }}">{{ $label }}</option>
                        @endforeach
                    </select>

                    <div class="inline-flex rounded-lg border border-gray-200 bg-white p-0.5 shadow-sm">
                        <button type="button"
                                @click="selectDisplay('counts')"
                                :aria-pressed="display==='counts'"
                                :class="display==='counts'
                                        ? 'bg-slate-900 text-white'
                                        : 'text-gray-700 hover:bg-gray-50'"
                                class="rounded-md px-3 py-1.5 text-sm font-medium">
                            Counts
                        </button>
                        <button type="button"
                                @click="selectDisplay('share')"
                                :aria-pressed="display==='share'"
                                :class="display==='share'
                                        ? 'bg-slate-900 text-white'
                                        : 'text-gray-700 hover:bg-gray-50'"
                                class="rounded-md px-3 py-1.5 text-sm font-medium">
                            Share
                        </button>
                    </div>

                    <button
                        type="button"
                        @click="filtersOpen = true"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 bg-white shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                        aria-label="Open filters"
                        aria-controls="stats-units-filters-title"
                    >
                        <svg class="h-5 w-5 text-gray-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M7 12h10M10 18h4"/>
                        </svg>
                    </button>

                    <button type="submit" class="hidden" x-ref="submit">Apply</button>
                </form>

            </div>
        </header>

        <div
            x-cloak
            x-show="filtersOpen"
            class="fixed inset-0 z-[70]"
            aria-labelledby="stats-units-filters-title"
            role="dialog"
            aria-modal="true"
        >
            <div
                class="absolute inset-0 bg-black/40"
                @click="filtersOpen = false"
                aria-hidden="true"
            ></div>

            <div
                class="filters-drawer absolute inset-y-0 right-0 flex w-[92vw] max-w-[480px] flex-col bg-white shadow-2xl"
                data-stats-units-filters
                data-form-selector="#stats-units-filter-form"
            >
                <div class="flex shrink-0 items-start justify-between gap-4 border-b border-gray-200 px-5 py-4">
                    <div>
                        <h2 id="stats-units-filters-title" class="text-lg font-semibold text-gray-950">Filters</h2>
                        <p class="mt-1 text-sm text-gray-500">Narrow units by season aggregate volume.</p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-500 shadow-sm hover:bg-gray-50 hover:text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                        @click="filtersOpen = false"
                        aria-label="Close filters"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="min-h-0 flex-1 space-y-6 overflow-y-auto px-5 py-5">
                    <div
                        data-stats-units-range
                        data-filter-min-input="gp_min"
                        data-filter-max-input="gp_max"
                        data-filter-default-min="{{ $gpDefaultMin }}"
                        data-filter-default-max="{{ $gpRangeMax }}"
                        data-range-config='@json($gpRangeConfig)'
                    ></div>

                    <div
                        data-stats-units-range
                        data-filter-min-input="shifts_min"
                        data-filter-max-input="shifts_max"
                        data-filter-default-min="{{ $shiftsDefaultMin }}"
                        data-filter-default-max="{{ $shiftsRangeMax }}"
                        data-range-config='@json($shiftsRangeConfig)'
                    ></div>

                    <div
                        data-stats-units-range
                        data-filter-min-input="toi_min"
                        data-filter-max-input="toi_max"
                        data-filter-default-min="{{ $toiDefaultMin }}"
                        data-filter-default-max="{{ $toiRangeMax }}"
                        data-range-config='@json($toiRangeConfig)'
                    ></div>
                    <p class="text-xs leading-5 text-gray-500">TOI is filtered in total minutes.</p>

                    <div
                        data-stats-units-range
                        data-filter-min-input="gf_min"
                        data-filter-max-input="gf_max"
                        data-filter-default-min="{{ $gfDefaultMin }}"
                        data-filter-default-max="{{ $gfRangeMax }}"
                        data-range-config='@json($gfRangeConfig)'
                    ></div>

                    <div
                        data-stats-units-range
                        data-filter-min-input="sf_min"
                        data-filter-max-input="sf_max"
                        data-filter-default-min="{{ $sfDefaultMin }}"
                        data-filter-default-max="{{ $sfRangeMax }}"
                        data-range-config='@json($sfRangeConfig)'
                    ></div>

                    <div
                        data-stats-units-range
                        data-filter-min-input="satf_min"
                        data-filter-max-input="satf_max"
                        data-filter-default-min="{{ $satfDefaultMin }}"
                        data-filter-default-max="{{ $satfRangeMax }}"
                        data-range-config='@json($satfRangeConfig)'
                    ></div>
                </div>

                <div class="shrink-0 border-t border-gray-200 px-5 py-4">
                    <div class="flex items-center justify-between gap-3">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                            data-stats-units-filter-reset
                        >
                            Reset
                        </button>
                        <button
                            type="button"
                            class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                            data-stats-units-filter-apply
                        >
                            Apply
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="py-6">
            <p class="mb-3 text-sm font-medium text-gray-600">
                {{ number_format($units->total()) }} units
            </p>
            @include('partials._unit-cards', [
                'units'   => $units,
                'sortKey' => $currentSort,
                'seasonLabel' => $seasonLabel,
                'gameTypeLabel' => $gameTypeLabel,
                'displayMode' => $currentDisplay,
            ])
            <div class="mt-6">
                {{ $units->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
