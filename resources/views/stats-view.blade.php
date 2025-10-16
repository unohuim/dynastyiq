{{-- resources/views/stats-view.blade.php --}}
<x-app-layout>
  @php $mobileBreakpoint = config('viewports.mobile', 768); @endphp

  <style>
    [x-cloak]{display:none!important}
    .no-scrollbar::-webkit-scrollbar{display:none}
    .no-scrollbar{-ms-overflow-style:none;scrollbar-width:none}

    /* Drawer shell */
    .filters-drawer{position:fixed;inset-y:0 right:0;width:92vw;max-width:480px;background:#fff;display:flex;flex-direction:column;box-shadow:0 12px 30px rgba(0,0,0,.22)}
    .filters-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.4)}

    /* Dual-range (two overlapping range inputs) */
    .dual-slider{position:relative;height:16px;user-select:none;-webkit-user-select:none}
    .dual-slider .rail{position:absolute;inset-inline:0;top:50%;height:2px;border-radius:9999px;background:#e5e7eb;transform:translateY(-50%);pointer-events:none}
    .dual-slider .active{position:absolute;top:50%;height:2px;border-radius:9999px;background:#6366f1;transform:translateY(-50%);pointer-events:none}

    /* Inputs: thumbs receive events, tracks do not */
    .dual-slider input[type=range]{
      position:absolute;inset:0;width:100%;height:100%;
      appearance:none;-webkit-appearance:none;
      background:transparent;outline:none;
      pointer-events:none; /* events go to thumbs */
      touch-action:none;
    }
    .dual-slider input[type=range]::-webkit-slider-runnable-track{background:transparent;pointer-events:none}
    .dual-slider input[type=range]::-moz-range-track{background:transparent;border:0;pointer-events:none}

    /* Thumbs */
    .dual-slider input[type=range]::-webkit-slider-thumb{
      appearance:none;width:10px;height:10px;border-radius:9999px;
      background:#4f46e5;border:1px solid #fff;box-shadow:0 0 2px rgba(0,0,0,.25);
      pointer-events:auto;cursor:pointer;margin:0;position:relative;
    }
    .dual-slider input[type=range]::-moz-range-thumb{
      width:10px;height:10px;border-radius:9999px;background:#4f46e5;border:1px solid #fff;
      box-shadow:0 0 2px rgba(0,0,0,.25);pointer-events:auto;cursor:pointer
    }

    /* z-order helpers so both thumbs are draggable */
    .dual-slider input.min{z-index:30}
    .dual-slider input.max{z-index:40}

    /* Position chips */
    .pos-pill{height:34px;width:34px;border-radius:9999px;border:1px solid #e5e7eb;background:#fff;color:#111827;display:inline-flex;align-items:center;justify-content:center;font:600 11px/1 Inter,ui-sans-serif,system-ui;box-shadow:0 1px 1px rgba(0,0,0,.02)}
    .pos-pill.is-on{background:#4f46e5;border-color:#4f46e5;color:#fff;box-shadow:0 4px 10px rgba(79,70,229,.28)}
    .pos-pill:focus-visible{outline:2px solid #4f46e5;outline-offset:2px}

    /* Drawer text */
    .filters-drawer .text-sm{font-size:.85rem}
    .filters-drawer .control-label{font-size:11px;color:#6b7280;margin-bottom:.25rem}
    .filters-drawer .grid-tight{gap:.5rem}
    .filters-drawer .section-pad{padding:.75rem 1rem}
  </style>

  <div class="stats-view">
    <script>
      // initial payload + endpoints
      window.__stats = @json($payload);
      window.api = { stats: "{{ url('/api/stats') }}" };
      window.__connectedLeagues = @json($connectedLeagues);

      // ensure every row has row.stats{}
      function normalizeStatsPayload(payload){
        if(!payload || !Array.isArray(payload.data) || !Array.isArray(payload.headings)) return payload;
        const identity = new Set([
          'name','team','pos','pos_type','age',
          'contract_value','contract_last_year',
          'contract_value_num','contract_last_year_num',
          'head_shot_url','id','gp'
        ]);
        const statKeys = payload.headings.map(h=>h&&h.key).filter(k=>k && !identity.has(k));
        payload.data = payload.data.map(row=>{
          if(row && typeof row.stats==='object' && row.stats!==null) return row;
          const stats={};
          for(const k of statKeys){
            const n=row?.stats?.[k]; const f=row?.[k];
            if(n!==undefined) stats[k]=n; else if(f!==undefined) stats[k]=f;
          }
          return { ...row, stats };
        });
        return payload;
      }
      window.__stats = normalizeStatsPayload(window.__stats);
    </script>

    <div x-data="statsPage()" x-init="init()" @keydown.escape.window="isFilterOpen=false" class="max-w-7xl mx-auto">
      {{-- ================= MOBILE BAR ================= --}}
      <template x-if="isMobile">
        <div id="perspectivesBar" class="top-0 z-40">
          <div class="flex">
            <button type="button" class="searchbar-button-mobile" @click="isFilterOpen = true" aria-label="Open filters">
              <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" class="searchbar-svg-mobile" aria-hidden="true">
                <path d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75"/>
              </svg>
            </button>

            <div class="-mr-px grid grow grid-cols-1">
              {{-- Perspective select lives in the bar (not inside drawer) --}}
              <select x-model="perspective" @change="fetchPayload()"
                      class="col-start-1 row-start-1 block w-full -md bg-white py-1.5 pl-10 pr-3 text-base text-gray-900 outline outline-1 -outline-offset-1 outline-gray-300 focus:outline-2 focus:outline-indigo-600 sm:pl-9 sm:text-sm/6">
                <template x-for="p in availablePerspectives" :key="p.slug">
                  <option :value="p.slug" x-text="p.name"></option>
                </template>
              </select>
            </div>
          </div>
        </div>
      </template>

      {{-- =============== DESKTOP BAR =============== --}}
      <div class="hidden sm:block px-4">
        <div class="relative z-30 overflow-visible rounded-lg bg-white/80 backdrop-blur ring-1 ring-gray-200 shadow-md mb-3 mt-2">
          <div class="flex flex-wrap justify-between items-center gap-3 p-3">

            <!-- Perspective -->
            <div class="relative">
              <select x-model="perspective" @change="fetchPayload()"
                      class="h-10 pl-4 pr-9 rounded-full text-sm ring-1 ring-gray-200 bg-white focus:ring-2 focus:ring-indigo-500">
                <template x-for="p in availablePerspectives" :key="p.slug">
                  <option :value="p.slug" x-text="p.name"></option>
                </template>
              </select>
              <svg class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" viewBox="0 0 20 20" fill="currentColor"><path d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 10.94l3.71-3.71a.75.75 0 1 1 1.06 1.06l-4.24 4.24a.75.75 0 0 1-1.06 0L5.21 8.29a.75.75 0 0 1 .02-1.08z"/></svg>
            </div>

            <!-- Period (segmented) -->
            <div class="inline-flex rounded-full ring-1 ring-gray-200 overflow-hidden">
              <button type="button"
                      @click="period='season'; fetchPayload()"
                      :class="period==='season' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                      class="px-4 h-10 text-sm">Season</button>
              <button type="button"
                      @click="period='range'; fetchPayload()"
                      :class="period==='range' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                      class="px-4 h-10 text-sm">Range</button>
            </div>

            <!-- Season (when period = season) -->
            <template x-if="period==='season'">
              <div class="relative">
                <select x-model="season_id" @change="fetchPayload()"
                        class="h-10 pl-4 pr-9 rounded-full text-sm ring-1 ring-gray-200 bg-white focus:ring-2 focus:ring-indigo-500">
                  <template x-for="sid in availableSeasons" :key="sid">
                    <option :value="sid" x-text="sid"></option>
                  </template>
                </select>
                <svg class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" viewBox="0 0 20 20" fill="currentColor"><path d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 10.94l3.71-3.71a.75.75 0 1 1 1.06 1.06l-4.24 4.24a.75.75 0 0 1-1.06 0L5.21 8.29a.75.75 0 0 1 .02-1.08z"/></svg>
              </div>
            </template>

            <!-- Range dates (when period = range) -->
            <template x-if="period==='range'">
              <div class="flex items-center gap-2">
                <input type="date" x-model="from" @change="fetchPayload()"
                       class="h-10 px-4 rounded-full text-sm ring-1 ring-gray-200 bg-white focus:ring-2 focus:ring-indigo-500">
                <span class="text-xs text-gray-500">to</span>
                <input type="date" x-model="to" @change="fetchPayload()"
                       class="h-10 px-4 rounded-full text-sm ring-1 ring-gray-200 bg-white focus:ring-2 focus:ring-indigo-500">
              </div>
            </template>

            <!-- Slice (segmented) -->
            <template x-if="canSlice">
              <div class="inline-flex rounded-full ring-1 ring-gray-200 overflow-hidden">
                <button type="button"
                        @click="slice='total'; fetchPayload()"
                        :class="slice==='total' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                        class="px-4 h-10 text-sm">Total</button>
                <button type="button"
                        @click="slice='pgp'; fetchPayload()"
                        :class="slice==='pgp' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                        class="px-4 h-10 text-sm">P/GP</button>
                <button type="button"
                        @click="slice='p60'; fetchPayload()"
                        :class="slice==='p60' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                        class="px-4 h-10 text-sm">Per 60</button>
              </div>
            </template>

            <!-- Game Type -->
            <div class="relative">
              <select x-model="game_type" @change="fetchPayload()"
                      class="h-10 pl-4 pr-9 rounded-full text-sm ring-1 ring-gray-200 bg-white focus:ring-2 focus:ring-indigo-500">
                <template x-for="gt in availableGameTypes" :key="gt">
                  <option :value="String(gt)"
                          :selected="String(gt)===String(game_type)"
                          x-text="({1:'Preseason',2:'Regular Season',3:'Playoffs'})[String(gt)]"></option>
                </template>
              </select>
              <svg class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" viewBox="0 0 20 20" fill="currentColor"><path d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 10.94l3.71-3.71a.75.75 0 1 1 1.06 1.06l-4.24 4.24a.75.75 0 0 1-1.06 0L5.21 8.29a.75.75 0 0 1 .02-1.08z"/></svg>
            </div>



            <!-- League Availability -->
            @php $hasLeagues = !empty($connectedLeagues); @endphp

            @if ($hasLeagues)
                <div class="relative"
                    x-show="hasConnectedLeagues"
                    x-cloak
                    x-data="{
                        open:false,
                        label(val){
                            if(val==='all') return 'All Players';
                            if(val==='available') return 'Available';
                            const m = (window.__connectedLeagues||[])
                            .filter(Boolean)
                            .find(l => l && `league:${l.id}`===val);
                            return m ? (m.name ?? 'League') : 'All Players';
                        }
                    }">
                    <button type="button"
                            @click="open=!open"
                            :aria-expanded="open"
                            class="h-10 w-64 inline-flex items-center justify-between rounded-full bg-white px-4 text-sm ring-1 ring-gray-200 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <span x-text="label(leagueScope)"></span>
                        <svg class="ml-2 h-4 w-4 text-gray-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 10.94l3.71-3.71a.75.75 0 1 1 1.06 1.06l-4.24 4.24a.75.75 0 0 1-1.06 0L5.21 8.29a.75.75 0 0 1 .02-1.08z" clip-rule="evenodd"/></svg>
                    </button>

                    <div x-show="open" x-transition @click.outside="open=false"
                        class="absolute z-50 mt-1 w-64 max-h-60 overflow-auto rounded-md bg-white p-1 text-sm shadow-lg ring-1 ring-black/5">

                        <button type="button" @click="leagueScope='all'; fetchPayload(); open=false"
                                class="w-full select-none truncate rounded px-3 py-2 text-left text-gray-900 hover:bg-indigo-50">
                        All Players
                        </button>
                        <button type="button" @click="leagueScope='available'; fetchPayload(); open=false"
                                class="w-full select-none truncate rounded px-3 py-2 text-left text-gray-900 hover:bg-indigo-50">
                        Available
                        </button>

                        <div class="my-1 border-t border-gray-200"></div>
                        <div class="px-3 py-1.5 text-[11px] font-medium text-gray-500 uppercase tracking-wide">
                        League Availability
                        </div>

                        <template x-for="opt in (availableLeagues||[]).filter(o => String(o.value||'').startsWith('league:'))" :key="opt.value">
                        <button type="button"
                                @click="leagueScope=opt.value; fetchPayload(); open=false"
                                class="w-full select-none truncate rounded px-3 py-2 text-left text-gray-900 hover:bg-indigo-50"
                                x-text="opt.label"></button>
                        </template>
                    </div>
                </div>
            @endif




            <!-- Positions + Actions (desktop bar, single row) -->
            <div class="w-full flex items-center gap-2 pb-3">
                <template x-for="p in ['LW','C','RW']" :key="'pos-'+p">
                    <button type="button"
                            class="h-9 w-9 rounded-full text-[11px] font-semibold ring-1 ring-indigo-100 hover:ring-indigo-200 hover:bg-indigo-100 transition-colors"
                            :class="filters.pos.includes(p)
                            ? 'bg-indigo-600 text-white ring-indigo-600/30'
                            : 'bg-white text-gray-700 hover:bg-gray-50'"
                            @click="togglePos(p); fetchPayload()"
                            x-text="p"></button>
                </template>

                <button type="button"
                        class="h-9 w-9 rounded-full text-[11px] font-semibold ring-1 ring-indigo-100 hover:ring-indigo-200 hover:bg-indigo-100 transition-colors"
                        :class="filters.pos_type.includes('F') ? 'bg-indigo-600 text-white ring-indigo-600/30' : 'bg-white text-gray-700 hover:bg-gray-50'"
                        @click="togglePosType('F'); fetchPayload()">F</button>

                <button type="button"
                        class="h-9 w-9 rounded-full text-[11px] font-semibold ring-1 ring-indigo-100 hover:ring-indigo-200 hover:bg-indigo-100 transition-colors"
                        :class="filters.pos_type.includes('D') ? 'bg-indigo-600 text-white ring-indigo-600/30' : 'bg-white text-gray-700 hover:bg-gray-50'"
                        @click="togglePosType('D'); fetchPayload()">D</button>

                <button type="button"
                        class="h-9 w-9 rounded-full text-[11px] font-semibold ring-1 ring-indigo-100 hover:ring-indigo-200 hover:bg-indigo-100 transition-colors"
                        :class="filters.pos_type.includes('G') ? 'bg-indigo-600 text-white ring-indigo-600/30' : 'bg-white text-gray-700 hover:bg-gray-50'"
                        @click="togglePosType('G'); fetchPayload()">G</button>


                <!-- Actions on far right -->
                <div class="ml-auto flex items-center gap-2">
                    <button type="button"
                            @click="resetFilters()"
                            class="h-10 px-4 rounded-full text-sm ring-1 ring-gray-200 bg-white hover:bg-gray-50">
                    Reset
                    </button>
                    <button type="button"
                            @click="isFilterOpen = true"
                            class="h-10 px-4 rounded-full bg-indigo-600 text-white text-sm shadow hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    Filters
                    </button>
                </div>
            </div>


        </div>
      </div>



      {{-- Mount point for the list/table --}}
      <div id="stats-page" class="sm:px-4"></div>

      <!-- ======================= MOBILE DRAWER ======================= -->
      <template x-if="isMobile">
        <div x-cloak class="sm:hidden">
          <!-- Backdrop -->
          <div
            x-show="isFilterOpen"
            x-transition.opacity
            class="fixed inset-0 bg-black/40 z-40"
            @click="isFilterOpen=false">
          </div>

          <!-- Panel -->
          <aside
              x-show="true"
              :class="isFilterOpen ? 'translate-x-0' : 'translate-x-full'"
              class="fixed inset-y-0 right-0 w-[92vw] max-w-[480px] bg-white border-l shadow-xl z-50
                 transform transition-transform duration-300 ease-out will-change-transform
                 flex flex-col"
              x-trap.noscroll="isMobile && isFilterOpen"
              @click.stop
              aria-modal="true" role="dialog">

            <!-- Header -->
            <header class="px-4 py-3 border-b flex items-center justify-between">
              <h2 class="text-base font-semibold">Filters</h2>
              <div class="flex items-center gap-2">
                <button class="px-3 py-1.5 text-sm rounded border" @click="resetFilters()">Reset</button>
                <button class="px-3 py-1.5 text-sm rounded bg-indigo-600 text-white"
                        :disabled="isLoading"
                        @click="applyFilters(); isFilterOpen = false">
                  Apply
                </button>
                <button class="p-2 rounded-full hover:bg-gray-100" @click="isFilterOpen=false" aria-label="Close">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 0 1 1.414 0L10 8.586l4.293-4.293a1 1 0 1 1 1.414 1.414L11.414 10l4.293 4.293a1 1 0 0 1-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 0 1-1.414-1.414L8.586 10 4.293 5.707a1 1 0 0 1 0-1.414Z" clip-rule="evenodd"/>
                  </svg>
                </button>
              </div>
            </header>

            <!-- Sticky controls -->
            <div class="sticky top-0 z-10 bg-white border-b">
              <div class="px-4 pt-3 pb-1 border-b">
                <label class="block text-[11px] text-gray-500 mb-1">Positions</label>

                <div class="gap-2 pb-2">
                  <!-- LW, C, RW use pos[] -->
                  <div class="flex space-x-4">
                    <template x-for="p in ['LW','C','RW']" :key="'pos-'+p">
                      <span class="flex-row">
                        <button type="button"
                                class="h-8 w-8 rounded-full text-[11px] font-semibold ring-1 ring-gray-200 transition-colors"
                                :class="filters.pos.includes(p) ? 'bg-indigo-600 text-white ring-indigo-600/30' : 'bg-white text-gray-700 hover:bg-gray-50'"
                                @click="togglePos(p)"
                                x-text="p"></button>
                      </span>
                    </template>

                    <!-- F, D, G use pos_type[] -->
                    <span class="flex">
                      <button type="button"
                              class="h-8 w-8 rounded-full text-[11px] font-semibold ring-1 ring-gray-200 transition-colors"
                              :class="filters.pos_type.includes('F') ? 'bg-indigo-600 text-white ring-indigo-600/30' : 'bg-white text-gray-700 hover:bg-gray-50'"
                              @click="togglePosType('F')">F</button>
                    </span>
                    <span class="flex">
                      <button type="button"
                              class="h-8 w-8 rounded-full text-[11px] font-semibold ring-1 ring-gray-200 transition-colors"
                              :class="filters.pos_type.includes('D') ? 'bg-indigo-600 text-white ring-indigo-600/30' : 'bg-white text-gray-700 hover:bg-gray-50'"
                              @click="togglePosType('D')">D</button>
                    </span>
                    <span class="flex">
                      <button type="button"
                              class="h-8 w-8 rounded-full text-[11px] font-semibold ring-1 ring-gray-200 transition-colors"
                              :class="filters.pos_type.includes('G') ? 'bg-indigo-600 text-white ring-indigo-600/30' : 'bg-white text-gray-700 hover:bg-gray-50'"
                              @click="togglePosType('G')">G</button>
                    </span>
                  </div>
                </div>
              </div>

              <div class="px-4 pt-2 pb-3">
                <div class="grid grid-cols-1 min-[380px]:grid-cols-2 gap-2">
                  <!-- Period -->
                  <div>
                    <label class="block text-[11px] text-gray-500 mb-1">Period</label>
                    <select x-model="period" @change="fetchPayload()"
                            class="h-9 w-full px-3 pr-8 rounded-md border border-gray-200 text-sm bg-white focus:ring-2 focus:ring-indigo-500">
                      <option value="season">Season</option>
                      <option value="range">Range</option>
                    </select>
                  </div>

                  <!-- Season (period = season) -->
                  <div x-show="period==='season'" x-cloak>
                    <label class="block text-[11px] text-gray-500 mb-1">Season</label>
                    <select x-model="season_id" @change="fetchPayload()"
                            class="h-9 w-full px-3 pr-8 rounded-md border border-gray-200 text-sm bg-white focus:ring-2 focus:ring-indigo-500">
                      <template x-for="sid in availableSeasons" :key="sid">
                        <option :value="sid" x-text="sid"></option>
                      </template>
                    </select>
                  </div>

                  <!-- Start / End (period = range) -->
                  <div x-show="period==='range'" x-cloak>
                    <label class="block text-[11px] text-gray-500 mb-1">Start</label>
                    <input type="date" x-model="from" @change="fetchPayload()"
                           class="h-9 w-full px-3 rounded-md border border-gray-200 text-sm bg-white focus:ring-2 focus:ring-indigo-500">
                  </div>
                  <div x-show="period==='range'" x-cloak>
                    <label class="block text-[11px] text-gray-500 mb-1">End</label>
                    <input type="date" x-model="to" @change="fetchPayload()"
                           class="h-9 w-full px-3 rounded-md border border-gray-200 text-sm bg-white focus:ring-2 focus:ring-indigo-500">
                  </div>

                  <!-- Slice -->
                  <div x-show="canSlice" x-cloak>
                    <label class="block text-[11px] text-gray-500 mb-1">Slice</label>
                    <select x-model="slice" @change="fetchPayload()"
                            class="h-9 w-full px-3 pr-8 rounded-md border border-gray-200 text-sm bg-white focus:ring-2 focus:ring-indigo-500">
                      <option value="total">Total</option>
                      <option value="pgp">P/GP</option>
                      <option value="p60">Per 60</option>
                    </select>
                  </div>

                  <!-- Game Type -->
                  <div>
                    <label class="block text-[11px] text-gray-500 mb-1">Game Type</label>
                    <select x-model="game_type" @change="fetchPayload()"
                            class="h-9 w-full px-3 pr-8 rounded-md border border-gray-200 text-sm bg-white focus:ring-2 focus:ring-indigo-500">
                      <template x-for="gt in availableGameTypes" :key="gt">
                        <option :value="String(gt)"
                                :selected="String(gt) === String(game_type)"
                                x-text="({'1':'Preseason','2':'Regular','3':'Playoffs'})[String(gt)]">
                        </option>
                      </template>
                    </select>
                  </div>

                </div>
              </div>
            </div>

            <!-- Scroll body -->
            <div class="flex-1 overflow-y-auto px-4 py-4 space-y-6">

              <!-- Dynamic numeric sliders from schema (MOBILE) -->
              <template
                x-for="f in schemaArr.filter(s => ['number','int','float'].includes((s?.type||'').toLowerCase()) && s?.bounds && s?.key)"
                :key="'rng-'+f.key"
              >
                <div x-data="{ spec: f }" x-init="ensureNum(spec.key, safeBounds(spec))">
                  <div class="flex items-center justify-between mb-1.5">
                    <span class="text-sm font-medium" x-text="spec.label || spec.key"></span>
                    <span class="text-xs text-gray-500"
                          x-text="(() => { const B = safeBounds(spec); const v = filters.num[spec.key] || {}; return `${(v.min ?? B.min)} – ${(v.max ?? B.max)}` })()">
                    </span>
                  </div>

                  <div class="dual-slider">
                    <div class="rail"></div>
                    <div class="active"
                         :style="(() => {
                           const B = safeBounds(spec);
                           const v = filters.num[spec.key] || {};
                           const a = pctIn(v.min ?? B.min, B);
                           const b = pctIn(v.max ?? B.max, B);
                           return `left:${a}%; width:${Math.max(0, b-a)}%;`;
                         })()"></div>

                    <!-- MAX -->
                    <input type="range"
                           class="max absolute inset-0 w-full h-8 bg-transparent appearance-none touch-none"
                           :min="safeBounds(spec).min" :max="safeBounds(spec).max" :step="spec.step ?? 1"
                           :value="(filters.num[spec.key]?.max ?? safeBounds(spec).max)"
                           @pointerdown.stop="activeThumb=spec.key+'-max'"
                           @pointerup.window="activeThumb=null"
                           @input="setMax(spec, +$event.target.value)">

                    <!-- MIN -->
                    <input type="range"
                           class="min absolute inset-0 w-full h-8 bg-transparent appearance-none touch-none"
                           :min="safeBounds(spec).min" :max="safeBounds(spec).max" :step="spec.step ?? 1"
                           :value="(filters.num[spec.key]?.min ?? safeBounds(spec).min)"
                           @pointerdown.stop="activeThumb=spec.key+'-min'"
                           @pointerup.window="activeThumb=null"
                           @input="setMin(spec, +$event.target.value)">
                  </div>
                </div>
              </template>

            </div>

            <!-- Footer -->
            <footer class="px-4 py-3 border-t flex items-center gap-2">
              <button class="px-3 py-1.5 text-sm rounded border" @click="resetFilters()">Reset</button>
              <button class="px-3 py-1.5 text-sm rounded bg-indigo-600 text-white"
                    :disabled="isLoading"
                    @click="applyFilters(); isFilterOpen = false">
                Apply
              </button>
              <span class="ml-auto text-xs text-gray-500" x-show="isLoading">Updating…</span>
            </footer>
          </aside>
        </div>
        <!-- ===================== /DRAWER ===================== -->
      </template>

      {{-- ================= DESKTOP FILTERS DRAWER (SLIDERS ONLY) ================= --}}
      <template x-if="!isMobile">
        <div x-cloak class="hidden sm:block">
          <!-- Backdrop -->
          <div
            x-show="isFilterOpen"
            x-transition.opacity
            class="fixed inset-0 bg-black/40 z-40"
            @click="isFilterOpen=false">
          </div>

          <!-- Panel -->
          <aside
              x-show="true"
              :class="isFilterOpen ? 'translate-x-0' : 'translate-x-full'"
              class="fixed inset-y-0 right-0 w-[40vw] max-w-[560px] bg-white border-l shadow-xl z-50
                 transform transition-transform duration-300 ease-out will-change-transform
                 flex flex-col"
              x-trap.noscroll="!isMobile && isFilterOpen"
              @click.stop
              aria-modal="true" role="dialog">

            <!-- Header -->
            <header class="px-4 py-3 border-b flex items-center justify-between">
              <h2 class="text-base font-semibold">Filters</h2>
              <div class="flex items-center gap-2">
                <button class="px-3 py-1.5 text-sm rounded border" @click="resetFilters()">Reset</button>
                <button class="px-3 py-1.5 text-sm rounded bg-indigo-600 text-white" @click="applyFilters()">Apply</button>
                <button class="p-2 rounded-full hover:bg-gray-100" @click="isFilterOpen=false" aria-label="Close">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 0 1 1.414 0L10 8.586l4.293-4.293a1 1 0 1 1 1.414 1.414L11.414 10l4.293 4.293a1 1 0 0 1-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 0 1-1.414-1.414L8.586 10 4.293 5.707a1 1 0 0 1 0-1.414Z" clip-rule="evenodd"/>
                  </svg>
                </button>
              </div>
            </header>


            <!-- Scroll body: numeric sliders only -->
            <div class="flex-1 overflow-y-auto px-4 py-4 space-y-6">

                <div id="rs-1"></div>

              <!-- Important first (if present) -->
              <template x-for="key in ['gp','contract_value_num','contract_last_year_num']" :key="'imp-'+key">

                <template x-if="schemaArr.some(s => (s?.key || '') === key)">

                  <div
                    x-data="{ spec: schemaArr.find(s => (s?.key || '') === key) }"
                    x-init="ensureNum(spec.key, safeBounds(spec))"
                  >
                    <div class="flex items-center justify-between mb-1.5">
                      <span class="text-sm font-medium" x-text="spec?.label || spec?.key"></span>
                      <span class="text-xs text-gray-500"
                            x-text="(() => { const B = safeBounds(spec); const v = filters.num[spec.key] || {}; return `${(v.min ?? B.min)} – ${(v.max ?? B.max)}` })()"></span>
                    </div>
                    <div class="dual-slider">
                      <div class="rail"></div>
                      <div class="active"
                           :style="(() => {
                             const B = safeBounds(spec);
                             const v = filters.num[spec.key] || {};
                             const a = pctIn(v.min ?? B.min, B);
                             const b = pctIn(v.max ?? B.max, B);
                             return `left:${a}%; width:${Math.max(0, b-a)}%;`;
                           })()"></div>

                      <!-- MAX -->
                      <input type="range"
                             class="max absolute inset-0 w-full h-8 bg-transparent appearance-none touch-none"
                             :min="safeBounds(spec).min" :max="safeBounds(spec).max" :step="spec.step ?? 1"
                             :value="(filters.num[spec.key]?.max ?? safeBounds(spec).max)"
                             @pointerdown.stop="activeThumb=spec.key+'-max'"
                             @pointerup.window="activeThumb=null"
                             @input="setMax(spec, +$event.target.value)">

                      <!-- MIN -->
                      <input type="range"
                             class="min absolute inset-0 w-full h-8 bg-transparent appearance-none touch-none"
                             :min="safeBounds(spec).min" :max="safeBounds(spec).max" :step="spec.step ?? 1"
                             :value="(filters.num[spec.key]?.min ?? safeBounds(spec).min)"
                             @pointerdown.stop="activeThumb=spec.key+'-min'"
                             @pointerup.window="activeThumb=null"
                             @input="setMin(spec, +$event.target.value)">
                    </div>
                  </div>
                </template>
              </template>



              <!-- The rest -->
              <template
                x-for="f in schemaArr.filter(s =>
                  ['number','int','float'].includes((s?.type||'').toLowerCase())
                  && s?.bounds && s?.key
                  && !['gp','contract_value_num','contract_last_year_num'].includes(s.key)
                )"
                :key="'rng-'+f.key"
              >
                <div x-data="{ spec: f }" x-init="ensureNum(spec.key, safeBounds(spec))">
                  <div class="flex items-center justify-between mb-1.5">
                    <span class="text-sm font-medium" x-text="spec.label || spec.key"></span>
                    <span class="text-xs text-gray-500"
                          x-text="(() => { const B = safeBounds(spec); const v = filters.num[spec.key] || {}; return `${(v.min ?? B.min)} – ${(v.max ?? B.max)}` })()"></span>
                  </div>

                  <div class="dual-slider">
                    <div class="rail"></div>
                    <div class="active"
                         :style="(() => {
                           const B = safeBounds(spec);
                           const v = filters.num[spec.key] || {};
                           const a = pctIn(v.min ?? B.min, B);
                           const b = pctIn(v.max ?? B.max, B);
                           return `left:${a}%; width:${Math.max(0, b-a)}%;`;
                         })()"></div>

                    <!-- MAX -->
                    <input type="range"
                           class="max absolute inset-0 w-full h-8 bg-transparent appearance-none touch-none"
                           :min="safeBounds(spec).min" :max="safeBounds(spec).max" :step="spec.step ?? 1"
                           :value="(filters.num[spec.key]?.max ?? safeBounds(spec).max)"
                           @pointerdown.stop="activeThumb=spec.key+'-max'"
                           @pointerup.window="activeThumb=null"
                           @input="setMax(spec, +$event.target.value)">

                    <!-- MIN -->
                    <input type="range"
                           class="min absolute inset-0 w-full h-8 bg-transparent appearance-none touch-none"
                           :min="safeBounds(spec).min" :max="safeBounds(spec).max" :step="spec.step ?? 1"
                           :value="(filters.num[spec.key]?.min ?? safeBounds(spec).min)"
                           @pointerdown.stop="activeThumb=spec.key+'-min'"
                           @pointerup.window="activeThumb=null"
                           @input="setMin(spec, +$event.target.value)">
                  </div>

                </div>

              </template>



            </div>

            <!-- Footer -->
            <footer class="px-4 py-3 border-t flex items-center gap-2">
              <button class="px-3 py-1.5 text-sm rounded border" @click="resetFilters()">Reset</button>
              <button class="px-3 py-1.5 text-sm rounded bg-indigo-600 text-white" @click="applyFilters()">Apply</button>
              <span class="ml-auto text-xs text-gray-500" x-show="isLoading">Updating…</span>
            </footer>
          </aside>
        </div>
      </template>


    </div>
  </div>

  <script>
    function statsPage(){
      return {
        leagueScope: 'all',
        availableLeagues: [
            { value:'all',       label:'All Players' },
            { value:'available', label:'Available'   },
        ].concat(
            (window.__connectedLeagues || [])
                .filter(Boolean)
                .filter(l => l && l.id != null)
                .map(l => ({ value:`league:${l.id}`, label:String(l.name ?? '') }))
        ),


        /* layout */
        isMobile: window.innerWidth < {{ $mobileBreakpoint }} ,

        /* controller defaults */
        perspective: @js($selectedSlug),
        resource: 'players',
        period: 'season',
        slice: 'total',
        season_id: String(@js($payload['meta']['season'] ?? '')),
        game_type: String(@js($payload['meta']['game_type'] ?? '2')),

        /* options */
        availablePerspectives: @js($perspectives),
        availableSeasons: (@js($payload['meta']['availableSeasons'] ?? [])).map(String),
        availableGameTypes: (@js($payload['meta']['availableGameTypes'] ?? [2])).map(String),
        canSlice: Boolean(@js($payload['meta']['canSlice'] ?? true)),

        /* state */
        from:null, to:null, isLoading:false, activeThumb:null,

        /* drawer state */
        isFilterOpen:false,
        schema: [],
        appliedFilters: {},   // <-- keep the server echo to fix collapsed bounds
        filters:{ pos:[], pos_type:[], team:[], age:{min:null,max:null}, num:{} },


        get hasConnectedLeagues(){
            return Array.isArray(window.__connectedLeagues) && window.__connectedLeagues.length > 0;
        },

        availabilityFromScope(){
            if (this.leagueScope === 'all')       return 0; // all players
            if (this.leagueScope === 'available') return -1; // any of user's leagues
            if (String(this.leagueScope||'').startsWith('league:')) {
                return +String(this.leagueScope).split(':')[1]; // numeric league_id
            }
            return 0;
        },

        /* getters */
        get schemaArr(){ return Array.isArray(this.schema)? this.schema : [] },
        get numericFields(){
          return this.schemaArr
            .filter(x => ['number','int','float'].includes((x?.type||'').toLowerCase()))
            .filter(x => !['age','gp','contract_value_num','contract_last_year_num'].includes(x?.key));
        },

        /* helpers: bounds + percentages */
        isValidBounds(b){
          const min = +((b||{}).min), max = +((b||{}).max);
          return Number.isFinite(min) && Number.isFinite(max) && max > min;
        },
        safeBounds(spec){
          const b = (spec && spec.bounds) ? spec.bounds : {};
          if (this.isValidBounds(b)) return { min:+b.min, max:+b.max };

          const af = this.appliedFilters?.[spec?.key];
          if (af && af.min!=null && af.max!=null && (+af.max > +af.min)) {
            return { min:+af.min, max:+af.max };
          }

          const v = this.filters?.num?.[spec?.key];
          if (v && v.min!=null && v.max!=null && (+v.max > +v.min)) {
            return { min:+v.min, max:+v.max };
          }

          return { min:0, max:1 };
        },
        pctIn(value, bounds){
          const min = +bounds.min, max = +bounds.max;
          const x   = Math.min(Math.max(+value ?? min, min), max);
          return (max === min) ? 0 : ((x - min) * 100) / (max - min);
        },

        /* utilities */
        initNumObj(f){
          const k=f.key, B=this.safeBounds(f);
          if(!this.filters.num) this.filters.num={};
          if(!this.filters.num[k]) this.filters.num[k]={min:B.min,max:B.max};
          if(this.filters.num[k].min==null) this.filters.num[k].min=B.min;
          if(this.filters.num[k].max==null) this.filters.num[k].max=B.max;
        },
        setMin(f,val){this.initNumObj(f); const k=f.key, B=this.safeBounds(f); const curMax=+(this.filters.num[k].max ?? B.max); this.filters.num[k].min=Math.max(B.min, Math.min(val, curMax));},
        setMax(f,val){this.initNumObj(f); const k=f.key, B=this.safeBounds(f); const curMin=+(this.filters.num[k].min ?? B.min); this.filters.num[k].max=Math.min(B.max, Math.max(val, curMin));},
        ensureNum(keyOrSpec, bounds=null, forceSeed=false){
          const key = typeof keyOrSpec==='string' ? keyOrSpec : (keyOrSpec?.key ?? '');
          const B   = bounds ?? this.safeBounds(keyOrSpec);
          if(!this.filters.num) this.filters.num = {};
          const cur = this.filters.num[key];
          if(!cur || forceSeed){
            this.filters.num[key] = { min: +B.min, max: +B.max };
          }else{
            if(cur.min==null) cur.min = +B.min;
            if(cur.max==null) cur.max = +B.max;
          }
          return this.filters.num[key];
        },

        /* position pills */
        togglePos(p) {
          this.filters.pos_type = (this.filters.pos_type || []).filter(t => t !== 'G' && t !== 'D');
          this.filters.pos      = (this.filters.pos      || []).filter(v => v !== 'G');

          const i = this.filters.pos.indexOf(p);
          if (i > -1) this.filters.pos.splice(i, 1);
          else        this.filters.pos.push(p);
        },
        togglePosType(t) {
          const cur = this.filters.pos_type || [];

          if (t === 'G') {
            if (cur.includes('G')) {
              this.filters.pos_type = cur.filter(x => x !== 'G');
              this.filters.pos      = this.filters.pos.filter(v => v !== 'G');
            } else {
              this.filters.pos_type = ['G'];
              this.filters.pos      = ['G'];
            }
            return;
          }

          let next = cur.filter(x => x !== 'G');

          if (next.includes(t)) next = next.filter(x => x !== t);
          else                  next = [...next, t];

          this.filters.pos_type = next;
          this.filters.pos      = this.filters.pos.filter(v => v !== 'G');

          if (this.filters.pos_type.includes('D')) {
            this.filters.pos = [];
          }
        },

        resetFilters(){
          // discrete chips
          this.filters.pos = [];
          this.filters.pos_type = [];
          this.filters.team = [];

          // age -> from schema if present
          const age = this.schemaArr.find(s => s.key === 'age');
          const B   = age ? this.safeBounds(age) : {min:null,max:null};
          this.filters.age = { min: B.min, max: B.max };
          (this.filters.num ||= {});
          this.filters.num.age = { min: B.min, max: B.max };

          // numeric sliders
          this.filters.num = {};
          for (const f of this.numericFields) {
            const Fb = this.safeBounds(f);
            this.filters.num[f.key] = { min: Fb.min, max: Fb.max };
          }

          // safety for static controls not in schema
          this.filters.num.gp ??= { min: 0, max: 82 };
          const y = new Date().getFullYear();
          this.filters.num.contract_last_year_num ??= { min: y, max: y + 8 };
          this.filters.num.contract_value_num ??= { min: 0, max: 16 };
        },

        /* meta sync */
        init(){
          window.addEventListener('resize', ()=>{ this.isMobile = window.innerWidth < {{ $mobileBreakpoint }}; });


          window.addEventListener('statsUpdated', (e) => {
            const meta = e.detail?.json?.meta || {};

            // hold the server echo for fallback bounds
            this.appliedFilters =
              (meta.appliedFilters && typeof meta.appliedFilters === 'object')
                ? meta.appliedFilters
                : {};

            // meta -> local state
            if (Array.isArray(meta.availableSeasons))   this.availableSeasons   = meta.availableSeasons.map(String);
            if (Array.isArray(meta.availableGameTypes)) this.availableGameTypes = meta.availableGameTypes.map(String);
            if (meta.season    != null) this.season_id = String(meta.season);
            if (meta.game_type != null) this.game_type = String(meta.game_type);
            if (typeof meta.canSlice === 'boolean') this.canSlice = meta.canSlice;


            // reflect server echo back into the dropdown
            if (meta.availability != null) {
                const a = String(meta.availability);
                if (a === '0') {
                    this.leagueScope = 'all';
                } else if (a === '-1') {
                    this.leagueScope = 'available';
                } else if (a > 0) {

                    // league id (including 1)
                    const lid = meta.league_id ?? +a;
                    this.leagueScope = `league:${lid}`;
                }
            }

            // --- hydrate leagues from API if present ---
            const leaguesFromApi = e.detail?.json?.connectedLeagues;
            if (Array.isArray(leaguesFromApi) && leaguesFromApi.length) {
            window.__connectedLeagues = leaguesFromApi.filter(Boolean);
            }
            // (Re)build dropdown from the cached global; never drop to empty
            this.availableLeagues = [
            { value:'all',       label:'All Players' },
            { value:'available', label:'Available'   },
            ...((window.__connectedLeagues || [])
                .filter(l => l && l.id != null)
                .map(l => ({ value:`league:${l.id}`, label:String(l.name ?? '') })))
            ];




            // bring in schema; if any item has collapsed bounds, patch from appliedFilters
            const raw = Array.isArray(meta.filterSchema) ? meta.filterSchema : [];
            this.schema = raw.map(s => {
              const b = (s && s.bounds) ? s.bounds : null;
              if (b && this.isValidBounds(b)) return s;
              const af = this.appliedFilters?.[s?.key];
              if (af && af.min!=null && af.max!=null && (+af.max > +af.min)) {
                return { ...s, bounds: { min:+af.min, max:+af.max } };
              }
              return s;
            });

            // Ensure synthetic sliders exist
            this.ensureNum('gp', {min:0, max:82});
            this.ensureNum('contract_value_num', {min:0, max:16});
            this.ensureNum('contract_last_year_num', {
              min: new Date().getFullYear(),
              max: new Date().getFullYear() + 8
            });

            // Seed age to both places if present
            const ageSpec = this.schemaArr.find(s => s.key === 'age');
            if (ageSpec) {
              const B = this.safeBounds(ageSpec);
              this.filters.age = { min: B.min, max: B.max };
              (this.filters.num ||= {});
              this.filters.num.age = { min: B.min, max: B.max };
            }

            // Seed numeric buckets for all dynamic fields (respect appliedFilters if present)
            this.filters.num = this.filters.num || {};
            for (const f of this.numericFields) {
              const B  = this.safeBounds(f);
              const af = (this.appliedFilters?.[f.key] && typeof this.appliedFilters[f.key]==='object') ? this.appliedFilters[f.key] : {};
              this.filters.num[f.key] = {
                min: (af.min!=null ? +af.min : B.min),
                max: (af.max!=null ? +af.max : B.max)
              };
            }
          });

          // first paint
          window.dispatchEvent(new CustomEvent('statsUpdated',{detail:{json:window.__stats}}));
        },

        unlockPage(){
          requestAnimationFrame(() => {
            document.documentElement.style.overflow = '';
            document.body.style.overflow = '';
            document.querySelectorAll('[inert]').forEach(el => el.removeAttribute('inert'));
          });
        },

        /* networking */
        isDefaultRangeFor(f){
          const v = this.filters?.num?.[f.key];
          const B = this.safeBounds(f);
          return (!v || (v.min == null && v.max == null) ||
                  (+v.min === +B.min && +v.max === +B.max));
        },


        buildParams(){
          const p = new URLSearchParams();

          // basics
          p.append('perspective', this.perspective);
          p.append('resource', this.resource);
          p.append('period', this.period);
          p.append('slice', this.slice);
          if (this.period === 'season' && this.season_id) p.append('season_id', this.season_id);
          if (this.period === 'range') {
            if (this.from) p.append('from', this.from);
            if (this.to)   p.append('to',   this.to);
          }
          p.append('game_type', this.game_type);

          // chips
          for (const v of this.filters.pos)      p.append('pos[]', v);
          for (const v of this.filters.pos_type) p.append('pos_type[]', v);
          for (const v of (this.filters.team || [])) p.append('team[]', v);

          // age — prefer numeric bucket; fallback to top-level
          {
            const numAge = this.filters?.num?.age;
            if (numAge && (numAge.min != null || numAge.max != null)) {
              if (numAge.min != null) p.append('age_min', numAge.min);
              if (numAge.max != null) p.append('age_max', numAge.max);
            } else if (this.filters?.age && (this.filters.age.min != null || this.filters.age.max != null)) {
              if (this.filters.age.min != null) p.append('age_min', this.filters.age.min);
              if (this.filters.age.max != null) p.append('age_max', this.filters.age.max);
            }
          }

          // numeric from schema — send ONLY if not at default bounds
          for (const f of this.numericFields) {
            const v = this.filters?.num?.[f.key];
            if (!v || this.isDefaultRangeFor(f)) continue;
            if (v.min != null) p.append(`${f.key}_min`, v.min);
            if (v.max != null) p.append(`${f.key}_max`, v.max);
          }

          // explicit extras (not in schema)
          if (this.filters.num?.gp) {
            const {min, max} = this.filters.num.gp;
            if (min != null) p.append('gp_min', min);
            if (max != null) p.append('gp_max', max);
          }
          if (this.filters.num?.contract_value_num) {
            const {min, max} = this.filters.num.contract_value_num;
            if (min != null) p.append('contract_value_num_min', +min);
            if (max != null) p.append('contract_value_num_max', +max);
          }
          if (this.filters.num?.contract_last_year_num) {
            const {min, max} = this.filters.num.contract_last_year_num;
            if (min != null) p.append('contract_last_year_num_min', Math.round(+min));
            if (max != null) p.append('contract_last_year_num_max', Math.round(+max));
          }

            // availability / league constraint
            const avail = this.availabilityFromScope();
            p.append('availability', String(avail)); // 0 | 1 | league_id

          return p;
        },

        fetchPayload(pushUrl=true){
          if(this.isLoading) return; this.isLoading=true;
          document.body.style.cursor = 'progress';

          const params=this.buildParams();
          const url=`${window.api.stats}?${params.toString()}`;
          if(pushUrl) history.replaceState(null,'',`/stats?${params.toString()}`);
          fetch(url,{headers:{'Accept':'application/json'}})
            .then(r=>{ if(!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
            .then(json=>window.dispatchEvent(new CustomEvent('statsUpdated',{detail:{json:normalizeStatsPayload(json)}})))
            .catch(e=>console.error('[stats] fetch failed',e))
            .finally(() => {
              this.isLoading = false;
              this.isFilterOpen = false;
              document.body.style.cursor = 'default';
              this.unlockPage();
              window.dispatchEvent(new CustomEvent('api:in'));
            });
        },
        applyFilters(){ this.fetchPayload(true); },
      }
    }

    (() => {
    function mountRS() {
        const el = document.getElementById('rs-1');
        if (!el || el.dataset.rsMounted || !window.RangeSlider) return;
        new window.RangeSlider(el, {
        id: 'gpp',
        key: 'gpp',
        label: 'GPP',
        type: 'dual',
        min: 0,
        max: 84,
        minValue: 10,
        maxValue: 70
        });
        el.dataset.rsMounted = '1';

        console.debug('[RS] mounted');
    }

    // Ensure Alpine has stamped the <template x-if> before mounting
    document.addEventListener('alpine:initialized', mountRS);
    document.addEventListener('DOMContentLoaded', mountRS);
    new MutationObserver(mountRS).observe(document.body, { childList: true, subtree: true });

    console.debug('[RS] ready; RangeSlider?', !!window.RangeSlider);
    })();


  </script>
</x-app-layout>
