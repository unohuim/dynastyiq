<!-- <x-app-layout>



{{-- resources/views/stats-units.blade.php (replace cards grid include block) --}}
@include('partials._unit-cards', [
    'units'   => $units,
    'sortKey' => $sort ?? 'gf',
])

<div class="mt-6">
    {{ $units->links() }}
</div>
</x-app-layout>  -->


{{-- resources/views/stats-units.blade.php --}}
<x-app-layout>
    @php
        $sortable = [
            'gf'   => 'Goals For %',
            'sf'   => 'Shots For %',
            'hf'   => 'Hits For %',
            'satf' => 'Shot Attempts For %',
            'fow'  => 'Faceoffs Won %',
        ];
    @endphp

    <div class="px-4 py-6 max-w-7xl mx-auto">
        <header class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">Units • Game Summaries</h1>
                <p class="text-sm text-gray-500">Default sort: GF desc. Filter by position.</p>
            </div>

            <form id="filters" method="get" class="flex flex-wrap gap-3 items-end">
                <div>
                    <label class="block text-xs uppercase text-gray-500 mb-1">Sort</label>
                    <select name="sort" class="border rounded-md px-3 py-2">
                        @foreach($sortable as $k => $label)
                            <option value="{{ $k }}" @selected(($sort ?? 'gf') === $k)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs uppercase text-gray-500 mb-1">Dir</label>
                    <select name="dir" class="border rounded-md px-3 py-2">
                        <option value="desc" @selected(($dir ?? 'desc')==='desc')>Desc</option>
                        <option value="asc"  @selected(($dir ?? 'desc')==='asc')>Asc</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs uppercase text-gray-500 mb-1">Position</label>
                    @php $posSel = $pos ?? ['F']; @endphp
                    <div class="flex items-center gap-3">
                        @foreach(['F','D','G'] as $p)
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" name="pos[]" value="{{ $p }}" @checked(in_array($p,$posSel,true))>
                                <span class="text-sm">{{ $p }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div>
                    <label class="block text-xs uppercase text-gray-500 mb-1">Team</label>
                    <input name="team" value="{{ $team ?? '' }}" placeholder="e.g. EDM" class="border rounded-md px-3 py-2" />
                </div>

                <div>
                    <label class="block text-xs uppercase text-gray-500 mb-1">Game ID</label>
                    <input name="game" value="{{ $game ?? '' }}" placeholder="2024030416" class="border rounded-md px-3 py-2" />
                </div>

                <div>
                    <label class="block text-xs uppercase text-gray-500 mb-1">Per Page</label>
                    <select name="per_page" class="border rounded-md px-3 py-2">
                        @foreach([25,50,100,200] as $n)
                            <option value="{{ $n }}" @selected(($perPage ?? 30)===$n)>{{ $n }}</option>
                        @endforeach
                    </select>
                </div>

                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-black text-white px-4 py-2 font-semibold hover:opacity-90">
                    Apply
                </button>
            </form>
        </header>

        @include('partials._unit-cards', [
            'units'   => $units,
            'sortKey' => $sort ?? 'gf',
        ])

        <div class="mt-6">
            {{ $units->links() }}
        </div>
    </div>
</x-app-layout>
