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

<div class="pt-4 grid gap-6 [grid-template-columns:repeat(auto-fill,minmax(320px,1fr))]">
@foreach($units as $row)
    @php
        $players = $row->player_names ? array_map($fmtName, explode(' · ', $row->player_names)) : [];

        $ozs = (int)($row->ozs ?? 0);
        $dzs = (int)($row->dzs ?? 0);
        $nzs = (int)($row->nzs ?? 0);

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

        $hitsF    = (int)($row->hf ?? 0);
        $hitsA    = (int)($row->ha ?? 0);

        $pimF     = (int)($row->pim_f ?? 0);
        $pimA     = (int)($row->pim_a ?? 0);

        $pensF    = (int)($row->penalties_f ?? 0);
        $pensA    = (int)($row->penalties_a ?? 0);

        $toiM = intdiv((int)$row->toi, 60);
        $toiS = str_pad((string)((int)$row->toi % 60), 2, '0', STR_PAD_LEFT);
        $date = $row->game_date ? \Illuminate\Support\Carbon::parse($row->game_date)->format('M j, Y') : null;
    @endphp

    <article
        x-data="{
            activeSet: 0,
            _sx: 0, _sy: 0, _dx: 0, _dy: 0,
            onStart(e){ const t=(e.touches&&e.touches[0])||e; this._sx=t.clientX; this._sy=t.clientY; this._dx=0; this._dy=0; },
            onMove(e){ const t=(e.touches&&e.touches[0])||e; this._dx=t.clientX-this._sx; this._dy=t.clientY-this._sy; },
            onEnd(){
                const thresh = 40;
                if (Math.abs(this._dx) > thresh && Math.abs(this._dx) > Math.abs(this._dy)) {
                    this.activeSet = (this.activeSet + (this._dx < 0 ? 1 : -1) + 3) % 3;
                }
                this._dx=0; this._dy=0;
            }
        }"
        class="bg-white rounded-2xl border shadow-sm overflow-hidden flex flex-col w-full"
    >
        <header class="flex items-center justify-between bg-emerald-50/70 px-4 py-2 text-sm min-h-[42px]">
            @php        
                $awayScore = $row->away_team_score ?? null;
                $homeScore = $row->home_team_score ?? null;
                $periodType = strtoupper((string)($row->period_type ?? '')); // e.g., OT, SO, REG
                $showPeriod = $periodType !== '' && !in_array($periodType, ['REG', 'R']);
            @endphp

            <div class="font-semibold flex items-center gap-2">
                <span class="text-gray-400">{{ $row->away }}</span>
                @isset($awayScore)<span class="text-gray-900 font-bold">{{ $awayScore }}</span>@endisset
                <span class="text-gray-300">vs</span>
                <span class="text-gray-400">{{ $row->home }}</span>
                @isset($homeScore)<span class="text-gray-900 font-bold">{{ $homeScore }}</span>@endisset
                @if($showPeriod)
                    <span class="ml-2 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-200 text-gray-700">{{ $periodType }}</span>
                @endif
            </div>

            <div class="flex items-center gap-4">
                @if($date)<span class="text-gray-700 text-xs">{{ $date }}</span>@endif
            </div>
        </header>


        <div class="px-4 pt-2">
            <div class="flex items-center gap-2 min-h-[28px]">
                <span class="inline-flex h-6 px-2 items-center justify-center rounded-full border text-xs">{{ $row->team_abbrev }}</span>
                <span class="inline-flex h-6 px-2 items-center justify-center rounded-full bg-gray-900 text-white text-xs">{{ $row->unit_type }}</span>
            </div>
        </div>

        <div class="px-4 py-3">
            <div class="flex flex-wrap justify-between w-full gap-y-2 min-h-[40px]">
                @forelse($players as $p)
                    <span class="px-4 pt-2 rounded-lg text-sm bg-gray-50 border border-blue-100 whitespace-nowrap">{{ $p }}</span>
                @empty
                    <span class="px-3 py-1 rounded-lg text-sm bg-gray-50 border">No players</span>
                @endforelse
            </div>
        </div>

        {{-- Dot navigation --}}
        <div class="px-4 pb-3">
            <div class="flex items-center justify-center gap-3">
                <button type="button" @click="activeSet=0" :class="activeSet===0?'bg-blue-500':'bg-gray-300'"
                        class="w-3 h-3 rounded-full transition-colors" aria-label="Page 1"></button>
                <button type="button" @click="activeSet=1" :class="activeSet===1?'bg-blue-500':'bg-gray-300'"
                        class="w-3 h-3 rounded-full transition-colors" aria-label="Page 2"></button>
                <button type="button" @click="activeSet=2" :class="activeSet===2?'bg-blue-500':'bg-gray-300'"
                        class="w-3 h-3 rounded-full transition-colors" aria-label="Page 3"></button>
            </div>
        </div>

        {{-- Swipe zone (fixed panel height for consistency across pages & cards) --}}
        <div
            class="px-4 pb-2 select-none touch-pan-y min-h-[360px] sm:min-h-[380px] lg:min-h-[360px]"
            @touchstart.passive="onStart($event)"
            @touchmove.passive="onMove($event)"
            @touchend="onEnd()"
        >
            {{-- Page 1 --}}
            <div x-show="activeSet===0" x-cloak class="min-h-[inherit]">
                @include('partials._ring-set-page1', [
                    'satFor'=>$satFor,'satAg'=>$satAg,
                    'shotsFor'=>$shotsFor,'shotsAg'=>$shotsAg,
                    'goalsFor'=>$goalsFor,'goalsAg'=>$goalsAg,
                    'hitsF'=>$hitsF,'hitsA'=>$hitsA,
                    'blocksF'=>$blocksF,'blocksA'=>$blocksA,
                    'fow'=>$fow,'fol'=>$fol,
                ])
            </div>

            {{-- Page 2 (Zones triangle) --}}
            <div x-show="activeSet===1" x-cloak class="min-h-[inherit] grid place-items-center">
                @include('partials._triangle-zones', ['oz'=>$ozs,'dz'=>$dzs,'nz'=>$nzs])
            </div>

            {{-- Page 3 --}}
            <div x-show="activeSet===2" x-cloak class="min-h-[inherit]">
                @include('partials._ring-set-page3', [
                    'pimF'=>$pimF,'pimA'=>$pimA,
                    'pensF'=>$pensF,'pensA'=>$pensA,
                ])
            </div>
        </div>

        <footer class="mt-auto px-4 bg-gray-50 text-sm flex items-center justify-between h-12">
            <span>Shifts <span class="font-semibold tabular-nums">{{ (int)($row->shifts ?? 0) }}</span></span>
            <span><span class="text-gray-500">TOI</span> <span class="font-semibold tabular-nums">{{ $toiM }}:{{ $toiS }}</span></span>
        </footer>
    </article>
@endforeach
</div>
