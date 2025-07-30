<div class="space-y-4">

    <script>
        window.__playerStats = @json($payload);
    </script>

    {{-- Alpine state and event handlers --}}
    <div
        x-data="{
            selectedPerspectiveId: '{{ $selectedPerspectiveId }}',
            season: '{{ $season }}',
            livewireId: @js($this->getId()),
            fetchAndDispatch() {
                console.log('⚡ fetchAndDispatch triggered', this.selectedPerspectiveId, this.season);

                Livewire.find(this.livewireId)
                    .call('generatePayload', this.selectedPerspectiveId, this.season)
                    .then(data => {
                        console.log('🎯 Inside .then, got Livewire data', data);

                        if (data) {
                            console.log('📤 ABOUT TO DISPATCH', data);
                            window.dispatchEvent(new CustomEvent('playerStatsUpdated', {
                                detail: { json: data }
                            }));
                        }
                    });
            }
        }"

    >
        <span x-init="console.log('Livewire ID:', @this.__instance.id)"></span>


        {{-- Perspective Selector --}}
        <div class="flex items-center space-x-2">
            <label for="perspective" class="font-semibold text-sm">Perspective:</label>
            <select
                id="perspective"
                x-model="selectedPerspectiveId"
                x-on:change="fetchAndDispatch()"
                class="border px-2 py-1 rounded"
            >
                @foreach ($perspectives as $perspective)
                    <option value="{{ $perspective['id'] }}">{{ $perspective['name'] }}</option>
                @endforeach
            </select>
        </div>

        {{-- Season Selector --}}
        @if (count($availableSeasons) > 1)
            <div class="flex items-center space-x-2">
                <label for="season" class="font-semibold text-sm">Season:</label>
                <select
                    id="season"
                    x-model="season"
                    x-on:change="fetchAndDispatch()"
                    class="border px-2 py-1 rounded"
                >
                    @foreach ($availableSeasons as $seasonId => $label)
                        <option value="{{ $seasonId }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @endif

    </div>

    {{-- Player Stats Page Component --}}
    <div id="player-stats-page" wire:ignore class="mt-6"></div>

</div>
