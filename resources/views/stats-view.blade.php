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

    /* Compact dual-range (thumbs-only interaction, centered on rail) */
    .dual-slider{position:relative;height:16px;user-select:none;-webkit-user-select:none}
    .dual-slider .rail{position:absolute;inset-inline:0;top:50%;height:2px;border-radius:9999px;background:#e5e7eb;transform:translateY(-50%);pointer-events:none}
    .dual-slider .active{position:absolute;top:50%;height:2px;border-radius:9999px;background:#6366f1;transform:translateY(-50%);pointer-events:none}
    .dual-slider input[type=range]{position:absolute;inset:0;width:100%;height:100%;appearance:none;-webkit-appearance:none;background:transparent;outline:none;pointer-events:none;touch-action:none}
    .dual-slider input[type=range]::-webkit-slider-runnable-track{background:transparent;pointer-events:none}
    .dual-slider input[type=range]::-moz-range-track{background:transparent;border:0;pointer-events:none}
    .dual-slider input[type=range]::-webkit-slider-thumb{appearance:none;width:10px;height:10px;border-radius:9999px;background:#4f46e5;border:1px solid #fff;box-shadow:0 0 2px rgba(0,0,0,.25);pointer-events:auto;cursor:pointer;margin:0}
    .dual-slider input[type=range]::-moz-range-thumb{width:10px;height:10px;border-radius:9999px;background:#4f46e5;border:1px solid #fff;box-shadow:0 0 2px rgba(0,0,0,.25);pointer-events:auto;cursor:pointer}

    /* Positions round pills */
    .pos-pill{height:34px;width:34px;border-radius:9999px;border:1px solid #e5e7eb;background:#fff;color:#111827;display:inline-flex;align-items:center;justify-content:center;font:600 11px/1 Inter,ui-sans-serif,system-ui;box-shadow:0 1px 1px rgba(0,0,0,.02)}
    .pos-pill.is-on{background:#4f46e5;border-color:#4f46e5;color:#fff;box-shadow:0 4px 10px rgba(79,70,229,.28)}
    .pos-pill:focus-visible{outline:2px solid #4f46e5;outline-offset:2px}

    /* Tighten drawer spacing + text */
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

      

      {{-- =============== DESKTOP BAR (revamped, modern) =============== --}}
        <div class="hidden sm:block px-4">
          <div class="rounded-lg bg-white/80 backdrop-blur ring-1 ring-gray-200 shadow-md mb-3 mt-2">
            <div class="flex flex-wrap items-center gap-3 p-3">

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




              <!-- Actions -->
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


            


            <!-- Positions (desktop bar) -->
            <div class="flex-1 items-center gap-2 pl-3 pb-3">
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

                <!-- Sticky controls (positions + basic selects) -->
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

                  <!-- ===== Contract & GP (synthetic sliders) ===== -->
                  

                  <!-- Dynamic numeric sliders from schema -->
                    <template
                      x-for="f in schemaArr.filter(s => ['number','int','float'].includes((s?.type||'').toLowerCase()) && s?.bounds && s?.key)"
                      :key="'rng-'+f.key"
                    >
                      <div x-data="{ spec: f }" x-init="ensureNum(spec.key, spec.bounds)">
                        <div class="flex items-center justify-between mb-1.5">
                          <span class="text-sm font-medium" x-text="spec.label || spec.key"></span>
                          <span class="text-xs text-gray-500"
                                x-text="`${(filters.num[spec.key]?.min ?? spec.bounds.min)} – ${(filters.num[spec.key]?.max ?? spec.bounds.max)}`">
                          </span>
                        </div>

                        <div class="dual-slider">
                          <div class="rail"></div>
                          <div class="active"
                               :style="(() => {
                                 const v = filters.num[spec.key] || {};
                                 const a = pct(v.min ?? spec.bounds.min, spec);
                                 const b = pct(v.max ?? spec.bounds.max, spec);
                                 return `left:${a}%; width:${Math.max(0, b-a)}%;`;
                               })()"></div>

                          <!-- MAX -->
                          <input type="range"
                                 class="absolute inset-0 w-full h-8 bg-transparent appearance-none touch-none z-30"
                                 :min="spec.bounds.min" :max="spec.bounds.max" :step="spec.step ?? 1"
                                 :value="(filters.num[spec.key]?.max ?? spec.bounds.max)"
                                 @pointerdown.stop="activeThumb=spec.key+'-max'"
                                 @pointerup.window="activeThumb=null"
                                 @input="setMax(spec, +$event.target.value)">

                          <!-- MIN -->
                          <input type="range"
                                 class="absolute inset-0 w-full h-8 bg-transparent appearance-none touch-none z-20"
                                 :min="spec.bounds.min" :max="spec.bounds.max" :step="spec.step ?? 1"
                                 :value="(filters.num[spec.key]?.min ?? spec.bounds.min)"
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

                  <!-- Important first (if present) -->
                  <template x-for="key in ['gp','contract_value_num','contract_last_year_num']" :key="'imp-'+key">
                    <div x-show="schemaArr.find(s => s.key===key)"
                         x-data="{ spec: schemaArr.find(s => s.key===key) }"
                         x-init="ensureNum(spec.key, spec.bounds)">
                      <div class="flex items-center justify-between mb-1.5">
                        <span class="text-sm font-medium" x-text="spec.label || spec.key"></span>
                        <span class="text-xs text-gray-500"
                              x-text="`${(filters.num[spec.key]?.min ?? spec.bounds.min)} – ${(filters.num[spec.key]?.max ?? spec.bounds.max)}`"></span>
                      </div>
                      <div class="dual-slider">
                        <div class="rail"></div>
                        <div class="active"
                             :style="(() => {
                               const v = filters.num[spec.key] || {};
                               const a = pct(v.min ?? spec.bounds.min, spec);
                               const b = pct(v.max ?? spec.bounds.max, spec);
                               return `left:${a}%; width:${Math.max(0, b-a)}%;`;
                             })()"></div>

                        <input type="range" class="absolute inset-0 w-full h-8 bg-transparent appearance-none touch-none z-30"
                               :min="spec.bounds.min" :max="spec.bounds.max" :step="spec.step ?? 1"
                               :value="(filters.num[spec.key]?.max ?? spec.bounds.max)"
                               @pointerdown.stop="activeThumb=spec.key+'-max'"
                               @pointerup.window="activeThumb=null"
                               @input="setMax(spec, +$event.target.value)">

                        <input type="range" class="absolute inset-0 w-full h-8 bg-transparent appearance-none touch-none z-20"
                               :min="spec.bounds.min" :max="spec.bounds.max" :step="spec.step ?? 1"
                               :value="(filters.num[spec.key]?.min ?? spec.bounds.min)"
                               @pointerdown.stop="activeThumb=spec.key+'-min'"
                               @pointerup.window="activeThumb=null"
                               @input="setMin(spec, +$event.target.value)">
                      </div>
                    </div>
                  </template>

                  <!-- The rest (excluding the three above) -->
                  <template
                    x-for="f in schemaArr.filter(s =>
                      ['number','int','float'].includes((s?.type||'').toLowerCase())
                      && s?.bounds && s?.key
                      && !['gp','contract_value_num','contract_last_year_num'].includes(s.key)
                    )"
                    :key="'rng-'+f.key"
                  >
                    <div x-data="{ spec: f }" x-init="ensureNum(spec.key, spec.bounds)">
                      <div class="flex items-center justify-between mb-1.5">
                        <span class="text-sm font-medium" x-text="spec.label || spec.key"></span>
                        <span class="text-xs text-gray-500"
                              x-text="`${(filters.num[spec.key]?.min ?? spec.bounds.min)} – ${(filters.num[spec.key]?.max ?? spec.bounds.max)}`"></span>
                      </div>

                      <div class="dual-slider">
                        <div class="rail"></div>
                        <div class="active"
                             :style="(() => {
                               const v = filters.num[spec.key] || {};
                               const a = pct(v.min ?? spec.bounds.min, spec);
                               const b = pct(v.max ?? spec.bounds.max, spec);
                               return `left:${a}%; width:${Math.max(0, b-a)}%;`;
                             })()"></div>

                        <input type="range" class="absolute inset-0 w-full h-8 bg-transparent appearance-none touch-none z-30"
                               :min="spec.bounds.min" :max="spec.bounds.max" :step="spec.step ?? 1"
                               :value="(filters.num[spec.key]?.max ?? spec.bounds.max)"
                               @pointerdown.stop="activeThumb=spec.key+'-max'"
                               @pointerup.window="activeThumb=null"
                               @input="setMax(spec, +$event.target.value)">

                        <input type="range" class="absolute inset-0 w-full h-8 bg-transparent appearance-none touch-none z-20"
                               :min="spec.bounds.min" :max="spec.bounds.max" :step="spec.step ?? 1"
                               :value="(filters.num[spec.key]?.min ?? spec.bounds.min)"
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
        /* layout */
        isMobile: window.innerWidth < {{ $mobileBreakpoint }},

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
        from:null, to:null, isLoading:false,

        /* drawer state */
        isFilterOpen:false,
        schema: [],
        filters:{ pos:[], pos_type:[], team:[], age:{min:null,max:null}, num:{} },

        /* getters */
        get schemaArr(){ return Array.isArray(this.schema)? this.schema : [] },
        get numericFields(){
          return this.schemaArr
            .filter(x => ['number','int','float'].includes((x?.type||'').toLowerCase()))
            .filter(x => !['age','gp','contract_value_num','contract_last_year_num'].includes(x?.key));
        },


        get filteredNumericSchema(){
          return this.schemaArr
            .filter(s => ['number','int','float'].includes(String((s && s.type) || '').toLowerCase()))
            .filter(s => s && s.bounds && s.key)
            .filter(s => !['gp','contract_value_num','contract_last_year_num'].includes(s.key));
        },


        /* utilities */
        initNumObj(f){
          const k=f.key, minB=Number(f?.bounds?.min ?? 0), maxB=Number(f?.bounds?.max ?? minB);
          if(!this.filters.num) this.filters.num={};
          if(!this.filters.num[k]) this.filters.num[k]={min:minB,max:maxB};
          if(this.filters.num[k].min==null) this.filters.num[k].min=minB;
          if(this.filters.num[k].max==null) this.filters.num[k].max=maxB;
        },
        pct(v,s){const min=+s.bounds.min, max=+s.bounds.max, x=Math.min(Math.max(+v??min,min),max); return (max===min)?0:((x-min)*100)/(max-min)},
        setMin(f,val){this.initNumObj(f); const k=f.key, minB=+f.bounds.min, maxB=+f.bounds.max, curMax=+(this.filters.num[k].max ?? maxB); this.filters.num[k].min=Math.max(minB, Math.min(val, curMax));},
        setMax(f,val){this.initNumObj(f); const k=f.key, minB=+f.bounds.min, maxB=+f.bounds.max, curMin=+(this.filters.num[k].min ?? minB); this.filters.num[k].max=Math.min(maxB, Math.max(val, curMin));},
        ensureNum(keyOrSpec, bounds=null, forceSeed=false){
          const key = typeof keyOrSpec==='string' ? keyOrSpec : (keyOrSpec?.key ?? '');
          const b   = bounds ?? (keyOrSpec?.bounds ?? {min:0,max:0});
          if(!this.filters.num) this.filters.num = {};
          const cur = this.filters.num[key];
          if(!cur || forceSeed){
            this.filters.num[key] = { min: +b.min, max: +b.max };
          }else{
            if(cur.min==null) cur.min = +b.min;
            if(cur.max==null) cur.max = +b.max;
          }
          return this.filters.num[key];
        },

        /* position pills */
        isPosOn(t){
          if(t==='F') return this.filters.pos_type.includes('F');
          if(t==='G') return this.filters.pos.includes('G') || this.filters.pos_type.includes('G');
          return this.filters.pos.includes(t);
        },
        onPosClick(t){
          if(t==='G'){
            // Goalie is exclusive
            if(this.isPosOn('G')){
              this.filters.pos = this.filters.pos.filter(v=>v!=='G');
              this.filters.pos_type = this.filters.pos_type.filter(v=>v!=='G');
            }else{
              this.filters.pos = ['G'];
              this.filters.pos_type = ['G'];
            }
            return;
          }

          // Any skater -> remove G if present
          this.filters.pos = this.filters.pos.filter(v=>v!=='G');
          this.filters.pos_type = this.filters.pos_type.filter(v=>v!=='G');

          if(t==='F'){
            const i=this.filters.pos_type.indexOf('F');
            if(i>-1) this.filters.pos_type.splice(i,1); else this.filters.pos_type.push('F');
            return;
          }

          const i=this.filters.pos.indexOf(t);
          if(i>-1) this.filters.pos.splice(i,1); else this.filters.pos.push(t);
        },

        resetFilters(){
          // discrete chips
          this.filters.pos = [];
          this.filters.pos_type = [];
          this.filters.team = [];

          // age → full range (if present)
          const age = this.schemaArr.find(s => s.key === 'age');
          this.filters.age = {
            min: age?.bounds?.min ?? null,
            max: age?.bounds?.max ?? null
          };


            const ageSpec = this.schemaArr.find(s => s.key === 'age');
            const ageMin  = ageSpec?.bounds?.min ?? null;
            const ageMax  = ageSpec?.bounds?.max ?? null;

            this.filters.age = { min: ageMin, max: ageMax };
            (this.filters.num ||= {});
            this.filters.num.age = { min: ageMin, max: ageMax };


          // all numeric sliders (includes virtuals like gp/contract_* if in schema)
          this.filters.num = {};
          for (const f of this.numericFields) {
            const min = Number(f?.bounds?.min ?? 0);
            const max = Number(f?.bounds?.max ?? min);
            this.filters.num[f.key] = { min, max };
          }

          // safety for static controls not in schema (if you still render them)
          this.filters.num.gp ??= { min: 0, max: 82 };
          const y = new Date().getFullYear();
          this.filters.num.contract_last_year_num ??= { min: y, max: y + 8 };
          this.filters.num.contract_value_num ??= { min: 0, max: 16 }; // $M
        },



        // --- POS BUTTONS (round chips) ---
        // Forward-position chips (LW / C / RW)
        togglePos(p) {
          // picking a forward position means we're not filtering defense or goalie
          this.filters.pos_type = (this.filters.pos_type || []).filter(t => t !== 'G' && t !== 'D');
          this.filters.pos      = (this.filters.pos      || []).filter(v => v !== 'G');

          const i = this.filters.pos.indexOf(p);
          if (i > -1) this.filters.pos.splice(i, 1);
          else        this.filters.pos.push(p);
        },

        // Pos type chips (F / D / G)
        togglePosType(t) {
          const cur = this.filters.pos_type || [];

          if (t === 'G') {
            // G is exclusive: turn it on alone; turn it off if already on
            if (cur.includes('G')) {
              this.filters.pos_type = cur.filter(x => x !== 'G');
              this.filters.pos      = this.filters.pos.filter(v => v !== 'G');
            } else {
              this.filters.pos_type = ['G'];
              this.filters.pos      = ['G']; // keeps query echo consistent with your controller
            }
            return;
          }

          // t is 'F' or 'D' (skaters). Any skater selection clears G.
          let next = cur.filter(x => x !== 'G');

          if (next.includes(t)) next = next.filter(x => x !== t);
          else                  next = [...next, t];

          this.filters.pos_type = next;
          this.filters.pos      = this.filters.pos.filter(v => v !== 'G');

          // If D is active, forward chips (LW/C/RW) shouldn't be active
          if (this.filters.pos_type.includes('D')) {
            this.filters.pos = [];
          }
        }
,


        /* meta sync */
        init(){
          window.addEventListener('resize', ()=>{ this.isMobile = window.innerWidth < {{ $mobileBreakpoint }}; });

          window.addEventListener('statsUpdated', (e) => {

            const meta = e.detail?.json?.meta || {};

            // ✅ define first
              const appliedFilters =
                (meta.appliedFilters && typeof meta.appliedFilters === 'object')
                  ? meta.appliedFilters
                  : {};
              

            //meta -> local state
            if (Array.isArray(meta.availableSeasons))   this.availableSeasons   = meta.availableSeasons.map(String);
            if (Array.isArray(meta.availableGameTypes)) this.availableGameTypes = meta.availableGameTypes.map(String);
            if (meta.season    != null) this.season_id = String(meta.season);
            if (meta.game_type != null) this.game_type = String(meta.game_type);
            if (typeof meta.canSlice === 'boolean') this.canSlice = meta.canSlice;

            this.schema = Array.isArray(meta.filterSchema) ? meta.filterSchema : [];
            this.ensureNum('gp', {min:0, max:82});
            this.ensureNum('contract_value_num', {min:0, max:16});
            this.ensureNum('contract_last_year_num', {
              min: new Date().getFullYear(),
              max: new Date().getFullYear() + 8
            });


            // Seed age from appliedFilters/bounds into BOTH places
            {
              const ageSpec = this.schemaArr.find(s => s.key === 'age');
              if (ageSpec?.bounds) {
                const minB = +ageSpec.bounds.min, maxB = +ageSpec.bounds.max;
                const cur  = (appliedFilters?.age && typeof appliedFilters.age === 'object') ? appliedFilters.age : {};
                const range = {
                  min: (cur.min != null ? +cur.min : minB),
                  max: (cur.max != null ? +cur.max : maxB),
                };
                this.filters.age = { ...range };
                (this.filters.num ||= {});
                this.filters.num.age = { ...range };
              }
            }

            // const appliedFilters = (meta.appliedFilters && typeof meta.appliedFilters==='object') ? meta.appliedFilters : {};

            this.filters.pos      = Array.isArray(meta.pos) ? meta.pos.slice() : [];
            this.filters.pos_type = Array.isArray(meta.pos_type) ? meta.pos_type.slice() : [];

            this.filters.num = this.filters.num || {};
            for (const f of this.numericFields) {
              const k=f.key, minB=+f.bounds.min, maxB=+f.bounds.max;
              const cur=(appliedFilters?.[k] && typeof appliedFilters[k]==='object') ? appliedFilters[k] : {};
              this.filters.num[k] = { min: (cur.min!=null?+cur.min:minB), max: (cur.max!=null?+cur.max:maxB) };
            }


          });

          // first paint
          window.dispatchEvent(new CustomEvent('statsUpdated',{detail:{json:window.__stats}}));
        },

        unlockPage(){
          // clear any stale body locks or inert attributes (belt-and-suspenders)
          requestAnimationFrame(() => {
            document.documentElement.style.overflow = '';
            document.body.style.overflow = '';
            document.querySelectorAll('[inert]').forEach(el => el.removeAttribute('inert'));
          });
        },

        /* networking */
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


            // age — use numeric bucket (what the slider updates) first; fallback to top-level
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
          


          // numeric from schema
          for (const f of this.numericFields) {
            const v = this.filters.num?.[f.key] || {};
            if (v.min != null) p.append(`${f.key}_min`, v.min);
            if (v.max != null) p.append(`${f.key}_max`, v.max);
          }


          // explicit extras (not in schema):
          // GP (as-is)
            if (this.filters.num?.gp) {
              const {min, max} = this.filters.num.gp;
              if (min != null) p.append('gp_min', min);
              if (max != null) p.append('gp_max', max);
            }

            // AAV in $M — send *_num keys directly
            if (this.filters.num?.contract_value_num) {
              const {min, max} = this.filters.num.contract_value_num;
              if (min != null) p.append('contract_value_num_min', +min);
              if (max != null) p.append('contract_value_num_max', +max);
            }

            // Contract last year — send *_num keys
            if (this.filters.num?.contract_last_year_num) {
              const {min, max} = this.filters.num.contract_last_year_num;
              if (min != null) p.append('contract_last_year_num_min', Math.round(+min));
              if (max != null) p.append('contract_last_year_num_max', Math.round(+max));
            }



          return p;
        },


        fetchPayload(pushUrl=true){
          if(this.isLoading) return; this.isLoading=true;
          document.body.style.cursor = 'progress';   // 👈 set cursor

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
              document.body.style.cursor = 'default';  // 👈 reset cursor
              this.unlockPage();
              window.dispatchEvent(new CustomEvent('api:in'));
            });
        },
        applyFilters(){ this.fetchPayload(true); },
      }
    }
  </script>
</x-app-layout>
