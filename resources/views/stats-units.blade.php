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
    @endphp

    <div class="px-4 safe-bottom max-w-7xl mx-auto">
        <header class="sticky top-0 z-30 bg-white/90 supports-[backdrop-filter]:bg-white/60 backdrop-blur border-b border-gray-200">
            <div class="py-3 px-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">Line Combos</h1>
                    <p class="text-sm text-gray-500">per single games</p>
                </div>

                {{-- Controls --}}
                <form method="GET" action="{{ url()->current() }}"
                      x-data="{
                        posSel: @js($currentPos),
                        sort:   @js($currentSort),
                        open:false,
                        togglePos(p){
                          const i = this.posSel.indexOf(p);
                          i>-1 ? this.posSel.splice(i,1) : this.posSel.push(p);
                          this.$nextTick(()=> $refs.submit.click());
                        },
                        chooseSort(k){
                          this.sort = k; this.open=false;
                          this.$nextTick(()=> $refs.submit.click());
                        }
                      }"
                      class="relative flex items-center gap-2"
                >
                    <input type="hidden" name="dir" value="desc">
                    <template x-for="p in posSel" :key="p">
                        <input type="hidden" name="pos[]" :value="p">
                    </template>
                    <input type="hidden" name="sort" :value="sort">

                    @foreach (['F','D'] as $p)
                        <button type="button"
                                @click="togglePos('{{ $p }}')"
                                :class="posSel.includes('{{ $p }}')
                                        ? 'bg-indigo-600 text-white'
                                        : 'bg-white text-gray-700 hover:bg-gray-50'"
                                class="px-3 py-1.5 rounded-lg text-sm font-medium border border-gray-200 shadow-sm">
                            {{ $p }}
                        </button>
                    @endforeach

                    <div class="relative">
                        <button type="button"
                                @click="open=!open"
                                class="inline-flex items-center justify-center h-9 w-9 rounded-lg border border-gray-200 bg-white shadow-sm hover:bg-gray-50">
                            <svg class="h-5 w-5 text-gray-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M7 12h10M10 18h4"/>
                            </svg>
                        </button>

                        <div x-cloak x-show="open"
                             @click.outside="open=false" @keydown.escape.window="open=false"
                             class="absolute right-0 mt-2 w-56 rounded-xl border border-gray-200 bg-white shadow-lg ring-1 ring-black/5 z-10 overflow-hidden">
                            @foreach ($sortable as $k => $label)
                                <button type="button"
                                        @click="chooseSort('{{ $k }}')"
                                        class="w-full flex items-center justify-between px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                    <span>{{ $label }}</span>
                                    <span x-show="sort==='{{ $k }}'">
                                        <svg class="h-4 w-4 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                        </svg>
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <button type="submit" class="hidden" x-ref="submit">Apply</button>
                </form>
            </div>
        </header>


        <div class="py-6">
            @include('partials._unit-cards', [
                'units'   => $units,
                'sortKey' => $currentSort,
            ])
            <div class="mt-6">
                {{ $units->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
