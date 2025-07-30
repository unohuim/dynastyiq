<x-app-layout>


    <div class="space-y-4">

        {{-- Expose initial payload and API path --}}
        <script>
            console.log('🔧 Initializing API path:');
            window.__playerStats = @json($payload);
            window.api = {
                playerStats: "{{ route('api.player-stats') }}"
            };
        </script>


        {{-- Alpine state & fetch logic --}}
        <div
          x-data="{
            selectedPerspectiveId: @js($selectedPerspectiveId),
            season: @js($season),
            availablePerspectives: @js($perspectives),
            availableRankings: @js($availableRankings),
            availableSeasons: @js($availableSeasons),

            init() {
                const {
                    selectedPerspectiveId,
                    availablePerspectives,
                    availableRankings,
                    availableSeasons
                } = this;

              console.log('📝 Alpine state:', {
                selectedPerspectiveId,
                availablePerspectives,
                availableRankings,
                availableSeasons
              });
              this.fetchPayload();
            },

            fetchPayload() {
              console.log('⚡ fetchPayload', this.selectedPerspectiveId, this.season);

              // build query params
              const params = new URLSearchParams({
                season: this.season,
              });

              
              
              params.append('perspectiveId', this.selectedPerspectiveId);
              

              const url = `${window.api.playerStats}?${params.toString()}`;
              console.log('🔗 Fetching URL:', url);

              fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(res => {
                  if (!res.ok) throw new Error(`Status ${res.status}`);
                  return res.json();
                })
                .then(data => {
                  console.log('🎯 Payload from API:', data);
                  window.dispatchEvent(new CustomEvent('playerStatsUpdated', {
                    detail: { json: data }
                  }));
                })
                .catch(err => console.error('❌ Error loading player stats:', err));
            }

          }"
          x-init="init()"
        >
        


            {{-- Perspective Selector --}}
            <div class="flex items-center space-x-2">
              <label for="perspective" class="font-semibold text-sm">Perspective:</label>
              <select
                id="perspective"
                x-model="selectedPerspectiveId"
                @change="fetchPayload()"
                class="border px-2 py-1 rounded"
              >
                <template x-for="persp in availablePerspectives" :key="persp.id">
                  <option :value="persp.id" x-text="persp.name"></option>
                </template>
              </select>
            </div>




            

        </div>

        {{-- JS-driven stats table --}}
        <div id="player-stats-page" class="mt-6"></div>
    </div>
</x-app-layout>