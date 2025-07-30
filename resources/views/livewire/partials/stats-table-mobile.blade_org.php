<div x-data="{ sortOpen: false }" class="relative pb-20">
  {{-- 📋 Player List --}}
  <div class="divide-y divide-gray-200 border-t border-b text-sm text-gray-800">
    @forelse ($stats as $stat)
      <div class="relative flex px-3 py-2 items-stretch">
        {{-- ➕ Expand/Collapse Toggle --}}
        @if($stat->isMulti)
          <button
            wire:click="toggleExpand({{ $stat->player_id }})"
            class="absolute left-0 top-1/2 -translate-y-1/2 -ml-4 text-gray-400 text-xl leading-none z-10"
          >
            @if(in_array($stat->player_id, $expanded)) − @else + @endif
          </button>
        @endif

        {{-- 🏒 Rotated Team --}}
        <div class="flex-shrink-0 w-6 mr-3 flex items-center justify-center">
          <div class="transform -rotate-90 text-[13px] font-bold uppercase tracking-wider text-gray-700 h-full flex items-center justify-center whitespace-nowrap">
            {{ $stat->nhl_team_abbrev }}
          </div>
        </div>

        {{-- 📊 Player Card --}}
        <div class="flex-1">
          {{-- Top Row --}}
          <div class="flex justify-between items-start">
            <div class="flex items-center space-x-1 text-base font-medium">
              <span class="uppercase tracking-tight">{{ $stat->player->pos_type }}</span>
              <span class="text-gray-700">•</span>
              <span class="truncate">{{ $stat->player->first_name }} {{ $stat->player->last_name }}</span>
            </div>
            <div class="text-xs text-gray-500 whitespace-nowrap text-right leading-snug">
              @switch($sortField)
                @case('PTS') {{ $stat->PTS }} PTS @break
                @case('avgPTSpGP') {{ $stat->avgPTSpGP }} P/GP @break
                @case('G') {{ $stat->G }} G @break
                @case('A') {{ $stat->A }} A @break
                @case('age') Age: {{ $stat->player->age() }} @break
              @endswitch
            </div>
          </div>

          {{-- Bottom Row --}}
          <div class="mt-0.5 text-xs text-gray-500 leading-tight truncate">
            {{ explode(',', $stat->league_abbrev)[0] ?? '' }}
            @unless($sortField === 'age') • Age: {{ $stat->player->age() }} @endunless
            @unless($sortField === 'PTS') • PTS: {{ $stat->PTS }} @endunless
            @unless($sortField === 'avgPTSpGP') • P/GP: @float($stat->avgPTSpGP) @endunless
            @unless($sortField === 'G' || $sortField === 'A') • G/A: {{ $stat->G }}/{{ $stat->A }} @endunless
            @unless($isProspect || $sortField === 'SOG') • SOG: {{ $stat->SOG }} @endunless
            @unless($isProspect || $sortField === 'shooting_percentage') • SH%: @percent($stat->shooting_percentage) @endunless
          </div>

          {{-- Split Stats --}}
          @if($stat->isMulti && in_array($stat->player_id, $expanded))
            <div class="mt-2 pt-2 border-t border-gray-100 text-xs text-gray-500 leading-snug">
              <div class="mb-1">Split Stats:</div>
              @foreach($stat->details as $detail)
                <div class="mb-0.5">
                  <div>{{ $detail->team_name }}</div>
                  <div class="text-[11px]">
                    GP: {{ $detail->GP }} |
                    G: {{ $detail->G }} |
                    A: {{ $detail->A }} |
                    PTS: {{ $detail->PTS }} |
                    P/GP: @float($detail->avgPTSpGP)
                  </div>
                </div>
              @endforeach
            </div>
          @endif
        </div>
      </div>
    @empty
      <div class="text-center py-4 text-gray-400 text-sm">No players found.</div>
    @endforelse
  </div>

  {{-- 🎯 FAB: Sort Button --}}
  <button
    x-on:click="sortOpen = true"
    class="fixed bottom-6 right-6 z-20 bg-transparent text-blue-300 border border-blue-300 rounded-full shadow p-5 hover:border-blue-200 hover:text-blue-200 transition"
    aria-label="Sort"
  >
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
         stroke-width="1.5" stroke="currentColor" class="size-6">
      <path stroke-linecap="round" stroke-linejoin="round"
            d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
    </svg>
  </button>

  {{-- ⬆️ Bottom Sheet Sort Panel --}}
<div
  x-show="sortOpen"
  x-cloak
  x-transition
  x-data="{ selectedSort: @entangle('sortField') }"
  class="fixed bottom-0 left-0 right-0 z-30 bg-white border-t rounded-t-xl p-4 shadow-2xl"
>
  <div class="flex justify-between items-center mb-2">
    <h2 class="text-base font-semibold">Sort players</h2>
    <button x-on:click="sortOpen = false" class="text-gray-400 hover:text-gray-600 text-sm">Close</button>
  </div>

  <label for="sortField" class="block text-sm font-medium text-gray-700 mb-1">Sort by</label>
  <select
    id="sortField"
    x-model="selectedSort"
    wire:model="sortField"
    class="block w-full rounded-md border-gray-300 shadow-sm text-sm mb-3 focus:ring-indigo-500 focus:border-indigo-500"
  >
    <option value="PTS">PTS</option>
    <option value="avgPTSpGP">PTS/GP</option>
    <option value="G">Goals</option>
    <option value="A">Assists</option>
    <option value="age">Age</option>
  </select>

  <button
    type="button"
    x-on:click="$wire.sortBy(selectedSort)"
    class="w-full inline-flex justify-center items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
  >
    Toggle Direction
    <svg class="ml-1 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none"
         viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="{{ $sortDirection === 'asc' ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7' }}" />
    </svg>
  </button>
</div>

</div>
