{{-- resources/views/partials/_unit-cards.blade.php --}}
@php
    $fmtName = function ($full) {
        $full = (string) $full;
        if ($full === '') return $full;
        $parts = explode(' ', $full, 2);
        $first = $parts[0] ?? '';
        $last  = $parts[1] ?? '';
        $initial = $first !== '' ? mb_substr($first, 0, 1).'.' : '';
        return trim($initial.' '.$last);
    };
@endphp

{{-- PT-4 applied to the WHOLE COLLECTION (grid), not per card --}}
<div class="pt-4 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
@foreach($units as $row)
    @php
        $players = $row->player_names ? array_map($fmtName, explode(' · ', $row->player_names)) : [];

        $zonesFor = (int)($row->ozs ?? 0);
        $zonesAg  = (int)($row->dzs ?? 0);

        $shotsFor = (int)($row->sf ?? 0);
        $shotsAg  = (int)($row->sa ?? 0);

        $goalsFor = (int)($row->gf ?? 0);
        $goalsAg  = (int)($row->ga ?? 0);

        $satFor   = (int)($row->satf ?? 0);
        $satAg    = (int)($row->sata ?? 0);

        $fow      = (int)($row->fow ?? 0);
        $fol      = (int)($row->fol ?? 0);

        $blocksF  = (int)($row->bf ?? 0);
        $blocksA  = (int)($row->ba ?? 0);

        $toiM = intdiv((int)$row->toi, 60);
        $toiS = str_pad((string)((int)$row->toi % 60), 2, '0', STR_PAD_LEFT);
        $date = $row->game_date ? \Illuminate\Support\Carbon::parse($row->game_date)->format('M j, Y') : null;
    @endphp

    <article class="rounded-2xl border shadow-sm bg-white overflow-hidden">
        <div class="flex items-center justify-between bg-emerald-50/70 px-4 py-2 text-sm">
            <div class="font-semibold">{{ $row->away }} <span class="text-gray-400">vs</span> {{ $row->home }}</div>
            <div class="flex items-center gap-4">
                @if($date)<span class="text-gray-500">{{ $date }}</span>@endif
                <div class="text-right"><span class="text-gray-500">TOI</span> <span class="font-semibold tabular-nums">{{ $toiM }}:{{ $toiS }}</span></div>
            </div>
        </div>

        <div class="px-4 pt-2">
            <div class="flex items-center gap-2">
                <span class="inline-flex h-6 px-2 items-center justify-center rounded-full border text-xs">{{ $row->team_abbrev }}</span>
                <span class="inline-flex h-6 px-2 items-center justify-center rounded-full bg-gray-900 text-white text-xs">{{ $row->unit_type }}</span>
            </div>
        </div>


        <div class="py-3 px-4 pt-2">
            <div class="flex justify-between gap-2">
                @forelse($players as $p)
                    <span class="px-3 py-1 rounded-lg text-sm bg-blue-50 border border-blue-100">{{ $p }}</span>
                @empty
                    <span class="px-3 py-1 rounded-lg text-sm bg-gray-50 border">No players</span>
                @endforelse
            </div>
        </div>

        {{-- Rings (removed per-card pt-2; padding only at collection level) --}}
        <div class="mt-4 px-4">
            <div class="grid grid-cols-3 gap-x-6">
                <div class="flex justify-center">
                    @include('partials._ring', ['chip' => ['abbr'=>'Zones','for'=>$zonesFor,'ag'=>$zonesAg,'split'=>'O/D']])
                </div>
                <div class="flex justify-center">
                    @include('partials._ring', ['chip' => ['abbr'=>'Shots','for'=>$shotsFor,'ag'=>$shotsAg]])
                </div>
                <div class="flex justify-center">
                    @include('partials._ring', ['chip' => ['abbr'=>'Goals','for'=>$goalsFor,'ag'=>$goalsAg]])
                </div>

                <div class="flex justify-center">
                    @include('partials._ring', ['chip' => ['abbr'=>'Shot Att.','for'=>$satFor,'ag'=>$satAg]])
                </div>
                <div class="flex justify-center">
                    @include('partials._ring', ['chip' => ['abbr'=>'Faceoffs','for'=>$fow,'ag'=>$fol,'split'=>'W/L']])
                </div>
                <div class="flex justify-center">
                    @include('partials._ring', ['chip' => ['abbr'=>'Blocks','for'=>$blocksF,'ag'=>$blocksA]])
                </div>
            </div>
        </div>

        <div class="px-4 py-3 bg-gray-50 text-sm flex items-center justify-between">
            <div class="flex flex-wrap gap-x-6 gap-y-2">
                <span>Shifts <span class="font-semibold tabular-nums">{{ (int)($row->shifts ?? 0) }}</span></span>
                <span>Hits <span class="font-semibold tabular-nums">{{ (int)($row->hf ?? 0) }}</span>/<span class="tabular-nums">{{ (int)($row->ha ?? 0) }}</span></span>
                <span>Blocks <span class="font-semibold tabular-nums">{{ $blocksF }}</span>/<span class="tabular-nums">{{ $blocksA }}</span></span>
                <span>PIM <span class="font-semibold tabular-nums">{{ (int)($row->pim_f ?? 0) }}</span>/<span class="tabular-nums">{{ (int)($row->pim_a ?? 0) }}</span></span>
            </div>
            <span class="text-gray-300">•</span>
        </div>
    </article>
@endforeach
</div>
