<x-app-layout>
    @php
        $mobileBreakpoint = config('viewports.mobile', 768);
    @endphp

    <style>
        [x-cloak]{display:none !important}
        /* small helper to hide OS scrollbars in the horizontal toolbar */
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>

    <div class="stats-view">
        <script>
            // ---- Initial payload + API base
            window.__stats = @json($payload);
            window.api = { stats: "{{ url('/api/stats') }}" };

            /**
             * Ensure each row has row.stats {...} for the mobile card’s bottom row.
             * We copy any “stat” column values (based on headings) into stats{}
             * while leaving identity columns (name/team/etc) as-is.
             */
            function normalizeStatsPayload(payload) {
                if (!payload || !Array.isArray(payload.data) || !Array.isArray(payload.headings)) return payload;

                // identity / non-stat keys that must not be moved into stats{}
                const identity = new Set([
                    'name','team','pos','pos_type','age',
                    'contract_value','contract_last_year','head_shot_url','id'
                ]);

                const statKeys = payload.headings
                    .map(h => h && h.key)
                    .filter(k => k && !identity.has(k));

                payload.data = payload.data.map(row => {
                    if (row && typeof row.stats === 'object' && row.stats !== null) return row;

                    const stats = {};
                    for (const k of statKeys) {
                        // prefer nested if present; else copy from flat key
                        const nested = row?.stats?.[k];
                        const flat   = row?.[k];
                        if (nested !== undefined) stats[k] = nested;
                        else if (flat !== undefined) stats[k] = flat;
                    }
                    return { ...row, stats };
                });

                return payload;
            }

            // normalize initial payload so mobile bottom row has data immediately
            window.__stats = normalizeStatsPayload(window.__stats);
        </script>

        <div
            x-data="() => ({
                // layout
                isMobile: window.innerWidth < {{ $mobileBreakpoint }},

                // controller-driven defaults
                perspective: @js($selectedSlug),  // slug
                resource: 'players',
                period: 'season',
                slice: 'total',

                // keep as strings to match <option> values
                season_id: String(@js($payload['meta']['season'] ?? '')),
                game_type: String(@js($payload['meta']['game_type'] ?? '2')),

                // options (normalize to strings)
                availablePerspectives: @js($perspectives),
                availableSeasons: (@js($payload['meta']['availableSeasons'] ?? [])).map(String),
                availableGameTypes: (@js($payload['meta']['availableGameTypes'] ?? [2])).map(String),

                // slice guard from perspective (true = show slice dropdown)
                canSlice: Boolean(@js($payload['meta']['canSlice'] ?? true)),

                // (range disabled for now, but retained for parity)
                from: null, to: null,

                isLoading: false,

                normalizeMeta() {
                    this.season_id = String(this.season_id ?? '');
                    this.game_type = String(this.game_type ?? '2');
                    this.availableSeasons   = (this.availableSeasons   ?? []).map(String);
                    this.availableGameTypes = (this.availableGameTypes ?? []).map(String);
                },

                init() {

                    this.normalizeMeta();

                    this.$nextTick(() => {
                      const setH = () => {
                        const h = this.$refs.controls?.offsetHeight || 0;
                        document.getElementById('stats-page')?.style.setProperty('--controls-h', h + 'px');
                      };
                      setH();
                      window.addEventListener('resize', setH);
                    });


                    // responsiveness
                    window.addEventListener('resize', () => {
                        this.isMobile = window.innerWidth < {{ $mobileBreakpoint }};
                    });

                    // keep Alpine options in sync with API
                    window.addEventListener('statsUpdated', (e) => {
                        const json = e.detail?.json || {};
                        const meta = json.meta || {};

                        if (Array.isArray(meta.availableSeasons))   this.availableSeasons   = meta.availableSeasons.map(String);
                        if (Array.isArray(meta.availableGameTypes)) this.availableGameTypes = meta.availableGameTypes.map(String);
                        if (meta.season    != null) this.season_id = String(meta.season);
                        if (meta.game_type != null) this.game_type = String(meta.game_type);
                        if (typeof meta.canSlice === 'boolean') this.canSlice = meta.canSlice;
                    });

                    // first paint for your JS component
                    window.dispatchEvent(new CustomEvent('statsUpdated', { detail: { json: window.__stats }}));
                },

                fetchPayload(pushUrl = true) {
                    if (this.isLoading) return;
                    this.isLoading = true;

                    const params = new URLSearchParams();
                    params.append('perspective', this.perspective);
                    params.append('resource', this.resource);
                    params.append('period',   this.period);
                    params.append('slice',    this.slice);

                    if (this.period === 'season' && this.season_id) {
                        params.append('season_id', this.season_id);
                    } else if (this.period === 'range') {
                        if (this.from) params.append('from', this.from);
                        if (this.to)   params.append('to',   this.to);
                    }

                    // string '1'|'2'|'3' (controller will coerce/ignore where needed)
                    params.append('game_type', this.game_type);

                    const url = `${window.api.stats}?${params.toString()}`;
                    if (pushUrl) history.replaceState(null, '', `/stats?${params.toString()}`);

                    fetch(url, { headers: { 'Accept': 'application/json' } })
                        .then(r => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
                        .then(json => {
                            // ensure mobile bottom row has stats blob
                            const normalized = normalizeStatsPayload(json);
                            window.dispatchEvent(new CustomEvent('statsUpdated', { detail: { json: normalized } }));
                        })
                        .catch(e => console.error('[stats] fetch failed', e))
                        .finally(() => this.isLoading = false);
                }
            })"
            x-init="init()"
            class="max-w-7xl mx-auto"
        >

            {{-- Mobile controls (styled, sticky, horizontal) --}}
            <template x-if="isMobile">
                <div x-ref="controls" x-cloak
                    class="px-3 py-2 sticky top-0 bg-white shadow-sm border-b z-40">
                    <div class="flex items-center gap-2 overflow-x-auto no-scrollbar">

                        {{-- Perspective --}}
                        <select x-model="perspective" @change="fetchPayload()"
                                class="block min-w-[9rem] bg-white py-1.5 pl-3 pr-8 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-indigo-500">
                            <template x-for="p in availablePerspectives" :key="p.slug">
                                <option :value="p.slug" x-text="p.name"></option>
                            </template>
                        </select>

                        {{-- Season --}}
                        <select x-model="season_id" @change="fetchPayload()"
                                class="block min-w-[7.5rem] bg-white py-1.5 pl-3 pr-8 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-indigo-500">
                            <template x-for="sid in availableSeasons" :key="sid">
                                <option :value="sid" x-text="sid"></option>
                            </template>
                        </select>

                        {{-- Slice (guarded by canSlice) --}}
                        <select x-show="canSlice" x-model="slice" @change="fetchPayload()"
                                class="block min-w-[6.5rem] bg-white py-1.5 pl-3 pr-8 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-indigo-500">
                            <option value="total">Total</option>
                            <option value="pgp">P/GP</option>
                            <option value="p60">Per 60</option>
                        </select>

                        {{-- Game Type --}}
                        <select x-model="game_type" @change="fetchPayload()"
                                class="block min-w-[9rem] bg-white py-1.5 pl-3 pr-8 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-indigo-500">
                            <template x-for="gt in availableGameTypes" :key="gt">
                                <option :value="String(gt)"
                                        :selected="String(gt) === String(game_type)"
                                        x-text="({'1':'Preseason','2':'Regular Season','3':'Playoffs'})[String(gt)]">
                                </option>
                            </template>
                        </select>

                        {{-- Filter / Sort --}}
                        <div class="ml-auto flex items-center gap-2">
                            @include('partials._stats-filter-button')
                            @include('partials._stats-sort-button')
                        </div>
                    </div>
                </div>
            </template> 



            {{-- Desktop controls --}}
            <div class="hidden sm:flex items-center justify-between px-4 py-3">
                <div class="flex items-center gap-2 w-full sm:w-auto">
                    {{-- Perspective --}}
                    <div class="relative">
                        <select
                            x-model="perspective"
                            @change="fetchPayload()"
                            class="block w-48 bg-white py-2 pl-3 pr-8 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-indigo-500"
                        >
                            <template x-for="p in availablePerspectives" :key="p.slug">
                                <option :value="p.slug" x-text="p.name"></option>
                            </template>
                        </select>
                    </div>

                    {{-- Period (kept for parity; season-only today) --}}
                    <div class="relative">
                        <select
                            x-model="period"
                            @change="fetchPayload()"
                            class="block w-36 bg-white py-2 pl-3 pr-8 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-indigo-500"
                        >
                            <option value="season">Season</option>
                            <option value="range">Range</option>
                        </select>
                    </div>

                    {{-- Season --}}
                    <div class="relative" x-show="period === 'season'">
                        <select
                            x-model="season_id"
                            @change="fetchPayload()"
                            class="block w-40 bg-white py-2 pl-3 pr-8 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-indigo-500"
                        >
                            <template x-for="sid in availableSeasons" :key="sid">
                                <option :value="sid" x-text="sid"></option>
                            </template>
                        </select>
                    </div>

                    {{-- Slice --}}
                    <div class="relative" x-show="canSlice">
                        <select
                            x-model="slice"
                            @change="fetchPayload()"
                            class="block w-36 bg-white py-2 pl-3 pr-8 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-indigo-500"
                        >
                            <option value="total">Total</option>
                            <option value="pgp">P/GP</option>
                            <option value="p60">Per 60</option>
                        </select>
                    </div>

                    {{-- Game Type --}}
                    <div class="relative">
                        <select
                            x-model="game_type"
                            @change="fetchPayload()"
                            class="block w-44 bg-white py-2 pl-3 pr-8 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-indigo-500"
                        >
                            <template x-for="gt in availableGameTypes" :key="gt">
                                <option
                                    :value="String(gt)"
                                    :selected="String(gt) === String(game_type)"
                                    x-text="({ '1':'Preseason', '2':'Regular Season', '3':'Playoffs' })[String(gt)]"
                                ></option>
                            </template>
                        </select>
                    </div>
                </div>

                <div class="flex items-center gap-2 justify-end">
                    @include('partials._stats-filter-button')
                    @include('partials._stats-sort-button')
                </div>
            </div>

            {{-- Mount point for your JS renderer (desktop grid OR mobile cards) --}}
            <div id="stats-page" class=" sm:px-4"></div>
        </div>
    </div>
</x-app-layout>
