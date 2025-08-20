{{-- resources/views/stats-units.blade.php --}}
<x-app-layout>
    @php
        // Labels for static (non-sortable) columns
        $staticCols = [
            'game_date'    => 'Date',
            'home'         => 'Home',
            'away'         => 'Away',
            'team_abbrev'  => 'Team',
            'unit_type'    => 'Unit',
            'player_names' => 'Players',
        ];

        // Labels for sortable metrics (keep keys in sync with controller’s $sortable)
        $metricLabels = [
            'gf'=>'GF','ga'=>'GA','ev_gf'=>'EV GF','pp_gf'=>'PP GF','pk_gf'=>'PK GF',
            'ev_ga'=>'EV GA','pp_ga'=>'PP GA','pk_ga'=>'PK GA',
            'sf'=>'SF','sa'=>'SA','ev_sf'=>'EV SF','pp_sf'=>'PP SF','pk_sf'=>'PK SF',
            'ev_sa'=>'EV SA','pp_sa'=>'PP SA','pk_sa'=>'PK SA',
            'satf'=>'SATF','sata'=>'SATA','ff'=>'FF','fa'=>'FA',
            'bf'=>'BF','ba'=>'BA','hf'=>'HF','ha'=>'HA',
            'fow'=>'FOW','fol'=>'FOL','fot'=>'FOT',
            'ozs'=>'OZS','nzs'=>'NZS','dzs'=>'DZS',
            'shifts'=>'Shifts','toi'=>'TOI (s)',
            'pim_f'=>'PIM For','pim_a'=>'PIM Ag','penalties_f'=>'Pens For','penalties_a'=>'Pens Ag',
        ];

        // Full table column set (static first, then metrics)
        $columns = $staticCols + $metricLabels;

        // Sort dropdown options = metrics only
        $sortableLabels = $metricLabels;

        // Formatting helpers
        $fmtToi = function ($seconds) {
            $s = (int)($seconds ?? 0);
            $m = intdiv($s, 60);
            $r = $s % 60;
            return sprintf('%d:%02d', $m, $r);
        };
    @endphp

    <div x-data="statsUnits()" class="px-4 py-6 max-w-7xl mx-auto">
        <header class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">Unit Game Summaries</h1>
                <p class="text-sm text-gray-500">Sortable by any metric. Default: GF desc.</p>
            </div>

            <form id="filters" method="get" class="flex flex-wrap gap-3 items-end">
                <div>
                    <label class="block text-xs uppercase text-gray-500 mb-1">Sort</label>
                    <select name="sort" x-model="sort" class="border rounded-md px-3 py-2">
                        @foreach($sortableLabels as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs uppercase text-gray-500 mb-1">Direction</label>
                    <select name="dir" x-model="dir" class="border rounded-md px-3 py-2">
                        <option value="desc">Desc</option>
                        <option value="asc">Asc</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs uppercase text-gray-500 mb-1">Team</label>
                    <input name="team" value="{{ $team }}" placeholder="e.g. EDM" class="border rounded-md px-3 py-2" />
                </div>

                <div>
                    <label class="block text-xs uppercase text-gray-500 mb-1">Game ID</label>
                    <input name="game" value="{{ $game }}" placeholder="2024030416" class="border rounded-md px-3 py-2" />
                </div>

                <div class="flex items-center gap-3">
                    <div>
                        <label class="block text-xs uppercase text-gray-500 mb-1">Pos</label>
                        <label class="inline-flex items-center gap-2 mr-2">
                            <input type="checkbox" name="pos[]" value="F" @checked(in_array('F',$pos))> <span class="text-sm">F</span>
                        </label>
                        <label class="inline-flex items-center gap-2 mr-2">
                            <input type="checkbox" name="pos[]" value="D" @checked(in_array('D',$pos))> <span class="text-sm">D</span>
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="pos[]" value="G" @checked(in_array('G',$pos))> <span class="text-sm">G</span>
                        </label>
                    </div>
                </div>

                <div>
                    <label class="block text-xs uppercase text-gray-500 mb-1">Per Page</label>
                    <select name="per_page" class="border rounded-md px-3 py-2">
                        @foreach([25,50,100,200] as $n)
                            <option value="{{ $n }}" @selected($perPage==$n)>{{ $n }}</option>
                        @endforeach
                    </select>
                </div>

                <button type="submit" class="inline-flex items-center gap-2 rounded-lg border px-4 py-2 font-medium hover:bg-gray-50">
                    Apply
                </button>

                <button type="button" @click="randomizeSort()" class="inline-flex items-center gap-2 rounded-lg bg-black text-white px-4 py-2 font-semibold hover:opacity-90">
                    Surprise Me
                </button>
            </form>
        </header>

        <div class="overflow-x-auto rounded-2xl border shadow-sm">
            <div class="min-w-[1200px]">
                <!-- header -->
                <div class="grid bg-gray-50 text-xs font-semibold uppercase tracking-wide sticky top-0 z-10"
                     style="grid-template-columns: repeat({{ count($columns) }}, minmax(100px,1fr));">
                    @foreach($columns as $key => $label)
                        @php $isSortable = array_key_exists($key, $sortableLabels); @endphp
                        <div class="px-3 py-3 border-r last:border-r-0 flex items-center gap-2">
                            <span>{{ $label }}</span>
                            @if($isSortable)
                                <a href="{{ request()->fullUrlWithQuery(['sort'=>$key,'dir'=>$dir==='asc'?'desc':'asc']) }}"
                                   class="text-gray-400 hover:text-gray-800" title="Sort by {{ $label }}">↕</a>
                            @endif
                        </div>
                    @endforeach
                </div>

                <!-- rows -->
                @foreach($units as $row)
                    <div class="grid odd:bg-white even:bg-gray-50 hover:bg-amber-50 transition-colors"
                         style="grid-template-columns: repeat({{ count($columns) }}, minmax(100px,1fr));">
                        @foreach(array_keys($columns) as $key)
                            <div class="px-3 py-2 border-t border-r last:border-r-0 text-sm tabular-nums @if($key==='player_names') whitespace-normal break-words @else whitespace-nowrap @endif">
                                @php $val = $row->{$key} ?? null; @endphp

                                @switch($key)
                                    @case('game_date')
                                        {{ \Illuminate\Support\Str::of((string)$val)->substr(0, 10) }}
                                        @break

                                    @case('player_names')
                                        <span class="font-medium">{{ $val }}</span>
                                        @break

                                    @case('toi')
                                        {{ $fmtToi($val) }}
                                        @break

                                    @default
                                        @if(is_numeric($val))
                                            {{ number_format((float)$val, 0, '.', '') }}
                                        @else
                                            {{ $val }}
                                        @endif
                                @endswitch
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mt-6">
            {{ $units->links() }}
        </div>
    </div>

    <script>
    function statsUnits() {
        return {
            sort: @json($sort),
            dir:  @json($dir),
            randomizeSort() {
                const cols = @json(array_keys($sortableLabels));
                const randomCol = cols[Math.floor(Math.random()*cols.length)];
                const nextDir = (Math.random() > 0.5) ? 'asc' : 'desc';
                const url = new URL(window.location.href);
                url.searchParams.set('sort', randomCol);
                url.searchParams.set('dir', nextDir);
                window.location = url.toString();
            }
        }
    }
    </script>
</x-app-layout>
