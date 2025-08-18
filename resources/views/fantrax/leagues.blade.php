<x-app-layout>
    @php
        $items = $leagues->map(fn($l) => [
            'id'    => $l->id,
            'name'  => $l->league_name,
            'ext'   => $l->fantrax_league_id ?? null,
            'teams' => $l->teams_count ?? null,
        ])->values();
    @endphp

    <div x-data="leaguesReadonly({ items: @js($items) })" class="px-4 py-6 max-w-7xl mx-auto">
        <!-- Toolbar -->
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-5">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Fantrax Leagues</h1>
                <p class="text-sm text-gray-500" x-text="`${filtered.length} total`"></p>
            </div>
            <div class="flex gap-2 sm:items-center">
                <div class="relative w-full sm:w-72">
                    <input x-model.debounce.200ms="q" type="search" placeholder="Search leagues…"
                           class="w-full rounded-xl border-gray-300 pl-9 focus:border-indigo-500 focus:ring-indigo-500">
                    <svg class="w-5 h-5 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.9 14.32a8 8 0 111.414-1.414l3.387 3.387a1 1 0 01-1.414 1.414l-3.387-3.387zM14 8a6 6 0 11-12 0 6 6 0 0112 0z" clip-rule="evenodd"/></svg>
                </div>
                <div class="relative" x-data="{open:false}">
                    <button @click="open=!open" class="inline-flex items-center gap-2 rounded-xl border px-3 py-2 text-sm hover:bg-gray-50">
                        <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path d="M3 5h14a1 1 0 010 2H3a1 1 0 010-2zm4 4h10a1 1 0 010 2H7a1 1 0 010-2zm4 4h6a1 1 0 010 2h-6a1 1 0 010-2z"/></svg>
                        <span x-text="sortLabel()"></span>
                    </button>
                    <div x-cloak x-show="open" @click.away="open=false" class="absolute right-0 mt-2 w-44 rounded-xl border bg-white shadow-lg z-50">
                        <button @click="setSort('name','asc');open=false"  class="w-full px-4 py-2 text-left text-sm hover:bg-gray-50">Name A→Z</button>
                        <button @click="setSort('name','desc');open=false" class="w-full px-4 py-2 text-left text-sm hover:bg-gray-50">Name Z→A</button>
                        <button @click="setSort('created','desc');open=false" class="w-full px-4 py-2 text-left text-sm hover:bg-gray-50">Newest</button>
                        <button @click="setSort('created','asc');open=false"  class="w-full px-4 py-2 text-left text-sm hover:bg-gray-50">Oldest</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 overflow-visible">
            <template x-if="!filtered.length">
                <div class="col-span-full rounded-2xl border border-dashed p-10 text-center text-gray-500">
                    No leagues found.
                </div>
            </template>

            <template x-for="item in paged" :key="item.id">
                <div class="isolate relative rounded-2xl border bg-white/70 backdrop-blur px-4 py-4 shadow-sm hover:shadow transition overflow-visible">
                    <div class="flex items-start gap-4">
                        <!-- Avatar -->
                        <div class="flex-none w-12 h-12 rounded-xl bg-indigo-100 grid place-items-center text-indigo-700 font-semibold">
                            <span x-text="initials(item.name)"></span>
                        </div>

                        <!-- Main -->
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <a :href="routes.index(item.id)" class="truncate font-medium text-gray-900 hover:underline" x-text="item.name"></a>
                                <span class="text-[11px] inline-flex items-center gap-1 rounded-md bg-gray-100 px-1.5 py-0.5 text-gray-600">Fantrax</span>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                <template x-if="item.ext"><span>ID: <span x-text="item.ext"></span></span></template>
                                <template x-if="item.teams"><span>• Teams: <span x-text="item.teams"></span></span></template>
                            </div>

                            <!-- Icon-only quick actions -->
                            <div class="mt-3 flex flex-wrap gap-2">
                                <!-- Trades -->
                                <a :href="routes.index(item.id, 'trades')" class="group inline-flex items-center justify-center rounded-full border px-2.5 py-2 hover:bg-gray-50"
                                   title="Trades" aria-label="Trades">
                                    <svg class="w-4 h-4 text-gray-600 group-hover:text-gray-900" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M7 7h7a4 4 0 110 8H6l2.3 2.3a1 1 0 11-1.4 1.4L2.6 13l4.3-4.3a1 1 0 111.4 1.4L6 9h8a2 2 0 100-4H7a1 1 0 110-2z"/>
                                    </svg>
                                </a>
                                <!-- Rankings -->
                                <a :href="routes.index(item.id, 'rankings')" class="group inline-flex items-center justify-center rounded-full border px-2.5 py-2 hover:bg-gray-50"
                                   title="Rankings" aria-label="Rankings">
                                    <svg class="w-4 h-4 text-gray-600 group-hover:text-gray-900" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M5 3h4v18H5zM10 10h4v11h-4zM15 6h4v15h-4z"/>
                                    </svg>
                                </a>
                                <!-- Schedule -->
                                <a :href="routes.index(item.id, 'schedule')" class="group inline-flex items-center justify-center rounded-full border px-2.5 py-2 hover:bg-gray-50"
                                   title="Schedule" aria-label="Schedule">
                                    <svg class="w-4 h-4 text-gray-600 group-hover:text-gray-900" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M6 2a1 1 0 011 1v1h10V3a1 1 0 112 0v1h1a2 2 0 012 2v3H3V6a2 2 0 012-2h1V3a1 1 0 112 0v1zM3 20a2 2 0 002 2h14a2 2 0 002-2V10H3v10z"/>
                                    </svg>
                                </a>
                                <!-- Trends -->
                                <a :href="routes.index(item.id, 'trends')" class="group inline-flex items-center justify-center rounded-full border px-2.5 py-2 hover:bg-gray-50"
                                   title="Trends" aria-label="Trends">
                                    <svg class="w-4 h-4 text-gray-600 group-hover:text-gray-900" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M3 17l6-6 4 4 7-7v5h2V5h-8v2h4l-5 5-4-4-8 8z"/>
                                    </svg>
                                </a>
                            </div>
                        </div>



                        <!-- Kebab -->
						<div x-data="menu()" class="-mr-1">
						    <button x-ref="btn" @click="toggle"
						            class="p-2 rounded-lg hover:bg-gray-100 text-gray-500 hover:text-gray-700"
						            aria-label="Actions">
						        <svg class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor">
						            <path d="M10 3a1.5 1.5 0 110 3 1.5 1.5 0 010-3zm0 5.5a1.5 1.5 0 110 3 1.5 1.5 0 010-3zM11.5 16.5a1.5 1.5 0 10-3 0 1.5 1.5 0 003 0z"/>
						        </svg>
						    </button>

						    <!-- Portal the menu to <body> to avoid overflow/stacking issues -->
						    <template x-teleport="body">
						        <div x-show="open"
						             x-transition.opacity
						             @click.outside="close"
						             @keydown.escape.window="close"
						             :style="style"
						             class="fixed z-[9999] w-48 rounded-xl border bg-white shadow-xl overflow-hidden">
						            <a :href="routes.index(item.id,'open')" class="block px-4 py-2 text-sm hover:bg-gray-50">Open</a>
						            <a :href="routes.index(item.id,'rosters')" class="block px-4 py-2 text-sm hover:bg-gray-50">Rosters</a>
						            <a :href="routes.index(item.id,'matchups')" class="block px-4 py-2 text-sm hover:bg-gray-50">Matchups</a>
						        </div>
						    </template>
						</div>


                    </div>
                </div>
            </template>
        </div>

        <!-- Pagination -->
        <div class="mt-6 flex items-center justify-between text-sm text-gray-600" x-show="pages > 1">
            <div>Page <span x-text="page"></span> of <span x-text="pages"></span></div>
            <div class="flex items-center gap-2">
                <button @click="prev()" :disabled="page===1" class="px-3 py-1.5 rounded-lg border hover:bg-gray-50 disabled:opacity-40">Prev</button>
                <button @click="next()" :disabled="page===pages" class="px-3 py-1.5 rounded-lg border hover:bg-gray-50 disabled:opacity-40">Next</button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('leaguesReadonly', ({ items }) => ({
                all: items.map(i => ({ ...i, created: i.created_at || null })),
                q: '',
                sortKey: 'name',
                sortDir: 'asc',
                page: 1,
                perPage: 12,

                get filtered() {
                    const q = this.q.trim().toLowerCase();
                    let out = !q ? this.all : this.all.filter(i =>
                        i.name.toLowerCase().includes(q) ||
                        (i.ext ?? '').toString().toLowerCase().includes(q)
                    );
                    const dir = this.sortDir === 'asc' ? 1 : -1;
                    out.sort((a, b) => {
                        if (this.sortKey === 'created') return ((a.created ?? a.id) > (b.created ?? b.id) ? 1 : -1) * dir;
                        return a.name.localeCompare(b.name) * dir;
                    });
                    this.page = Math.min(this.page, Math.max(1, Math.ceil(out.length / this.perPage)));
                    return out;
                },
                get pages() { return Math.max(1, Math.ceil(this.filtered.length / this.perPage)); },
                get paged() { const s = (this.page - 1) * this.perPage; return this.filtered.slice(s, s + this.perPage); },

                setSort(k, d) { this.sortKey = k; this.sortDir = d; },
                sortLabel() {
                    if (this.sortKey==='name' && this.sortDir==='asc') return 'Name A→Z';
                    if (this.sortKey==='name' && this.sortDir==='desc') return 'Name Z→A';
                    if (this.sortKey==='created' && this.sortDir==='desc') return 'Newest';
                    if (this.sortKey==='created' && this.sortDir==='asc') return 'Oldest';
                    return 'Sort';
                },
                initials(n) { return n.split(' ').slice(0,2).map(s=>s[0]).join('').toUpperCase(); },
                prev(){ if(this.page>1) this.page--; },
                next(){ if(this.page<this.pages) this.page++; },

                routes: {
                    index: (id, action = null) => {
                        let base = "{{ route('fantrax.leagues.index') }}";
                        return action ? `${base}?league=${id}&action=${action}` : `${base}?league=${id}`;
                    },
                },
            }))


            Alpine.data('menu', () => ({
			    open: false,
			    style: '',
			    toggle() { this.open ? this.close() : this.openMenu(); },
			    openMenu() {
			      this.open = true;
			      this.$nextTick(() => this.place());
			    },
			    place() {
			      const btn = this.$refs.btn.getBoundingClientRect();
			      const gap = 8;
			      const menuW = 192; // w-48
			      const left = Math.min(window.innerWidth - menuW - gap, btn.right - menuW);
			      const top  = Math.min(window.innerHeight - gap, btn.bottom + gap);
			      this.style = `left:${left + window.scrollX}px; top:${top + window.scrollY}px;`;
			    },
			    close() { this.open = false; }
			}));
        })
    </script>
</x-app-layout>
