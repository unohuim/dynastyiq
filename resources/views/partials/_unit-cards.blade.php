<div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
@foreach($units as $row)
    @php
        $toiM = intdiv((int)$row->toi, 60);
        $toiS = str_pad((string)((int)$row->toi % 60), 2, '0', STR_PAD_LEFT);
        $players = $row->player_names ? explode(' · ', $row->player_names) : [];
        $date = $row->game_date ? \Illuminate\Support\Carbon::parse($row->game_date)->format('M j, Y') : null;
    @endphp

    <article class="rounded-2xl border shadow-sm hover:shadow-md transition overflow-hidden bg-white">
        {{-- Header --}}
        <div class="flex w-full px-4 py-1 justify-between items-center bg-green-100 text-sm font-semibold">
            <div class="">{{ $row->away }} <span class="text-gray-400">vs</span> {{ $row->home }}</div>
            <div class="">@if($date)<span class="text-gray-500 text-xxs font-normal">{{ $date }}</span>@endif</div>
        </div>  

        <div class="flex items-center justify-between px-4 py-1 bg-gray-50">
            <div class="space-y-0.5">
                
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center justify-center h-6 px-2 rounded-full text-xs border">
                        {{ $row->team_abbrev }}
                    </span>
                    <span class="inline-flex items-center justify-center h-6 px-2 rounded-full text-xs bg-gray-900 text-white">
                        {{ $row->unit_type }}
                    </span>
                </div>
            </div>
            <div class="text-right">
                <div class="text-xs text-gray-500">TOI</div>
                <div class="font-semibold tabular-nums">{{ $toiM }}:{{ $toiS }}</div>
            </div>
        </div>

        {{-- Players (no Unit #) --}}
        <div class="px-4 pt-2 pb-2 flex justify-between flex-wrap gap-2">
            @forelse($players as $p)
                <span class="px-2.5 py-1 rounded-md text-xs bg-blue-100">{{ $p }}</span>
            @empty
                <span class="px-2.5 py-1 rounded-md text-xs bg-gray-100">No players</span>
            @endforelse
        </div>

        {{-- Stat pills (unchanged) --}}
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
                <div class="text-lg font-bold tabular-nums">{{ $row->ozs }}/{{ $row->nzs }}/{{ $row->dzs }}</div>
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

        <div class="px-4 py-3 bg-gray-50 flex items-center justify-between text-xs">
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center gap-1"><span class="text-gray-500">Shifts</span><span class="font-semibold tabular-nums">{{ $row->shifts }}</span></span>
                <span class="inline-flex items-center gap-1"><span class="text-gray-500">Hits</span><span class="font-semibold tabular-nums">{{ $row->hf }}<span class="text-gray-400">/</span>{{ $row->ha }}</span></span>
                <span class="inline-flex items-center gap-1"><span class="text-gray-500">Blocks</span><span class="font-semibold tabular-nums">{{ $row->bf }}<span class="text-gray-400">/</span>{{ $row->ba }}</span></span>
                <span class="inline-flex items-center gap-1"><span class="text-gray-500">PIM</span><span class="font-semibold tabular-nums">{{ $row->pim_f }}<span class="text-gray-400">/</span>{{ $row->pim_a }}</span></span>
            </div>
            <span class="text-gray-300">•</span>
        </div>
    </article>
@endforeach
</div>
