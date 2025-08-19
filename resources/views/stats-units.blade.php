{{-- resources/views/stats-units.blade.php --}}
<x-app-layout>
    <div x-data class="max-w-7xl mx-auto px-4 py-8 space-y-6">
        <header class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <h1 class="text-3xl font-bold tracking-tight">Units • Game Summaries</h1>
                <p class="text-sm text-gray-500">Default sort: <span class="font-medium">GF</span> desc. Filter by position.</p>
            </div>

            <form x-ref="filters" method="get" class="flex flex-wrap items-end gap-3">
                {{-- Sort --}}
                <div>
                    <label class="block text-xs uppercase text-gray-500 mb-1">Sort</label>
                    <select name="sort" class="px-3 py-2 rounded-xl border">
                        @foreach($sortable as $key)
                            <option value="{{ $key }}" @selected($sort===$key)>{{ strtoupper(str_replace('_',' ',$key)) }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs uppercase text-gray-500 mb-1">Dir</label>
                    <select name="dir" class="px-3 py-2 rounded-xl border">
                        <option value="desc" @selected($dir==='desc')>Desc</option>
                        <option value="asc"  @selected($dir==='asc')>Asc</option>
                    </select>
                </div>

                {{-- Position type (F/D/G) --}}
                <div class="min-w-[220px]">
                    <label class="block text-xs uppercase text-gray-500 mb-1">Position</label>
                    <div class="flex gap-2">
                        @php $sel = $pos ?? ['F']; @endphp
                        <label class="inline-flex items-center gap-2 px-3 py-2 rounded-xl border cursor-pointer
                                       @if(in_array('F',$sel)) bg-gray-900 text-white border-gray-900 @else hover:bg-gray-50 @endif">
                            <input type="checkbox" name="pos[]" value="F" class="sr-only"
                                   @checked(in_array('F',$sel))
                                   @change="$refs.filters.submit()">
                            <span>F</span>
                        </label>
                        <label class="inline-flex items-center gap-2 px-3 py-2 rounded-xl border cursor-pointer
                                       @if(in_array('D',$sel)) bg-gray-900 text-white border-gray-900 @else hover:bg-gray-50 @endif">
                            <input type="checkbox" name="pos[]" value="D" class="sr-only"
                                   @checked(in_array('D',$sel))
                                   @change="$refs.filters.submit()">
                            <span>D</span>
                        </label>
                        <label class="inline-flex items-center gap-2 px-3 py-2 rounded-xl border cursor-pointer
                                       @if(in_array('G',$sel)) bg-gray-900 text-white border-gray-900 @else hover:bg-gray-50 @endif">
                            <input type="checkbox" name="pos[]" value="G" class="sr-only"
                                   @checked(in_array('G',$sel))
                                   @change="$refs.filters.submit()">
                            <span>G</span>
                        </label>
                    </div>
                </div>

                {{-- Team / Game / Per page --}}
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

        @includeIf('partials._unit-cards', ['units' => $units])
        <div>{{ $units->links() }}</div>
    </div>
</x-app-layout>
