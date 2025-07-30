<x-app-layout>
    <div class="py-6 px-4">
        {{-- Page Title and Description --}}
        @if($isProspect)
            <h1 class="text-2xl font-bold mb-2">Top Prospects {{ $defaultSeason }}</h1>
            <p class="mb-4">These players are flagged as prospects based on NHL API data.</p>
        @else
            <h1 class="text-2xl font-bold mb-4">All Players {{ $defaultSeason }}</h1>
        @endif

        {{-- Use Livewire component instead of Blade component --}}
        
        <livewire:player-stats-table
            :is-prospect="$isProspect"
            :default-season="$defaultSeason"
        />
    </div>
</x-app-layout>