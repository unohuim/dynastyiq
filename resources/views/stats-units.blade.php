{{-- Modern cards view with player chips --}}
<x-app-layout>
    <div x-data="statsUnits()" class="max-w-7xl mx-auto px-4 py-8 space-y-6">
        <header class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <h1 class="text-3xl font-bold tracking-tight">Units • Game Summaries</h1>
                <p class="text-sm text-gray-500">Default sort: <span class="font-medium">GF</span> desc. Pick any metric.</p>
            </div>

            <form method="get" class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs uppercase text-gray-500 mb-1">Sort</label>
                    <select name="sort" x-model="sort" class="px-3 py-2 rounded-xl border">
                        @foreach($sortable as $key)
                            <option value="{{ $key }}">{{ strtoupper(str_replace('_',' ',$key)) }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs uppercase text-gray-500 mb-1">Dir</label>
                    <select name="dir" x-model="dir" class="px-3 py-2 rounded-xl border">
                        <option value="desc">Desc</option>
                        <option value="asc">Asc</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs uppercase text-gray-500 mb-1">Team</label>
                    <input name="team" value="{{ $team }}" placeholder="EDM" class="px-3 py-2 rounded-xl border w-28">
                </div>

                <div>
                    <label class="block text-xs uppercase text-gray-500 mb-1">Game</label>
                    <input name="game" value="{{ $game }}" placeholder="2024030416" class="px-3 py-2 rounded-xl border w-40">
                </div>

                <div>
                    <label class="block text-xs uppercase text-gray-500 mb-1">Per Page</label>
                    <select name="per_page" class="px-3 py-2 rounded-xl border">
                        @foreach([30,60,90,150] as $n)
                            <option value="{{ $n }}" @selected($perPage==$n)>{{ $n }}</option>
                        @endforeach
                    </select>
                </div>

                <button class="px-4 py-2 rounded-xl bg-gray-900 text-white font-semibold hover:opacity-90">Apply</button>
            </form>
        </header>

        {{-- Cards grid --}}
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($units as $row)
                @php
                    $toiM = intdiv((int)$row->toi, 60);
                    $toiS = str_pad((string)((int)$row->toi % 60), 2, '0', STR_PAD_LEFT);
                    $players = $row->player_names ? explode(' · ', $row->player_names) : [];
                @endphp

                <article class="rounded-2xl border shadow-sm hover:shadow-md transition overflow-hidden bg-white">
                    {{-- Top bar --}}
                    <div class="flex items-center justify-between px-4 py-3 bg-gray-50">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center justify-center h-8 w-8 rounded-full bg-gray-900 text-white font-bold">
                                {{ strtoupper(substr($row->team_abbrev,0,1)) }}
                            </span>
                            <div>
                                <div class="text-sm text-gray-500 uppercase">{{ $row->team_abbrev }} • Game {{ $row->nhl_game_id }}</div>
                                <div class="font-semibold">Unit #{{ $row->unit_id }}</div>
                            </div>
                        </div>

                        <div class="text-right">
                            <div class="text-xs text-gray-500">TOI</div>
                            <div class="font-semibold tabular-nums">{{ $toiM }}:{{ $toiS }}</div>
                        </div>
                    </div>

                    {{-- Players --}}
                    <div class="px-4 pt-4 pb-2 flex flex-wrap gap-2">
                        @forelse($players as $p)
                            <span class="px-2.5 py-1 rounded-full text-xs bg-gray-100">{{ $p }}</span>
                        @empty
                            <span class="px-2.5 py-1 rounded-full text-xs bg-gray-100">No players</span>
                        @endforelse
                    </div>

                    {{-- Stat pills --}}
                    <div class="px-4 pb-4 grid grid-cols-3 gap-3">
                        <div class="rounded-xl border p-3">
                            <div class="text-xs text-gray-500">GF / GA</div>
                            <div class="text-lg font-bold tabular-nums">{{ $row->gf }}<span class="text-gray-400"> / </span>{{ $row->ga }}</div>
                        </div>
                        <div class="rounded-xl border p-3">
                            <div class="text-xs text-gray-500">SF / SA</div>
                            <div class="text-lg font-bold tabular-nums">{{ $row->sf }}<span class="text-gray-400"> / </span>{{ $row->sa }}</div>
                        </div>
                        <div class="rounded-xl border p-3">
                            <div class="text-xs text-gray-500">OZS / NZS / DZS</div>
                            <div class="text-lg font-bold tabular-nums">{{ $row->ozs }}<span class="text-gray-400">/</span>{{ $row->nzs }}<span class="text-gray-400">/</span>{{ $row->dzs }}</div>
                        </div>

                        <div class="rounded-xl border p-3">
                            <div class="text-xs text-gray-500">SATF / Sata</div>
                            <div class="text-lg font-bold tabular-nums">{{ $row->satf }}<span class="text-gray-400"> / </span>{{ $row->sata }}</div>
                        </div>
                        <div class="rounded-xl border p-3">
                            <div class="text-xs text-gray-500">FF / FA</div>
                            <div class="text-lg font-bold tabular-nums">{{ $row->ff }}<span class="text-gray-400"> / </span>{{ $row->fa }}</div>
                        </div>
                        <div class="rounded-xl border p-3">
                            <div class="text-xs text-gray-500">FOW / FOL</div>
                            <div class="text-lg font-bold tabular-nums">{{ $row->fow }}<span class="text-gray-400"> / </span>{{ $row->fol }}</div>
                        </div>
                    </div>

                    {{-- Footer mini-bar with quick stats --}}
                    <div class="px-4 py-3 bg-gray-50 flex items-center justify-between text-xs">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center gap-1"><span class="text-gray-500">Shifts</span><span class="font-semibold tabular-nums">{{ $row->shifts }}</span></span>
                            <span class="inline-flex items-center gap-1"><span class="text-gray-500">Hits</span><span class="font-semibold tabular-nums">{{ $row->hf }}<span class="text-gray-400">/</span>{{ $row->ha }}</span></span>
                            <span class="inline-flex items-center gap-1"><span class="text-gray-500">Blocks</span><span class="font-semibold tabular-nums">{{ $row->bf }}<span class="text-gray-400">/</span>{{ $row->ba }}</span></span>
                        </div>
                        <a href="{{ request()->fullUrlWithQuery(['sort'=>$sort,'dir'=>$dir]) }}" class="text-gray-400 hover:text-gray-700">↻</a>
                    </div>
                </article>
            @endforeach
        </div>

        <div>
            {{ $units->links() }}
        </div>
    </div>

    <script>
    function statsUnits() {
        return {
            sort: @json($sort),
            dir:  @json($dir),
        }
    }
    </script>
</x-app-layout>
