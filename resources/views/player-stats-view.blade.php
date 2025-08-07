<x-app-layout>

  @php
    $mobileBreakpoint = config('viewports.mobile');
  @endphp

    <div class="player-stats-view">

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
            isMobile: window.innerWidth < {{ $mobileBreakpoint }},
            selectedPerspectiveId: @js($selectedPerspectiveId),
            season: @js($season),
            availablePerspectives: @js($perspectives),
            availableRankings: @js($availableRankings),
            availableSeasons: @js($availableSeasons),
            isLoadingPerspectives: true,
            previousPerspectiveId: null,

            init() {
                window.addEventListener('resize', () => { 
                 isMobile = window.innerWidth < {{ $mobileBreakpoint }};
                });

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
              
              
              this.isLoadingPerspectives = false;
            },

            fetchPayload() {
              if(this.isLoadingPerspectives) return;
              if(this.previousPerspectiveId === this.selectedPerspectiveId) return;
              this.previousPerspectiveId = this.selectedPerspectiveId;
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
        

          <template x-if="isMobile">
            <div>
              {{-- Perspective Selector - Mobile --}}
              <div id="perspectivesBar" class="perspectivesbar-mobile">
                
                <div class=" flex">
                  <button type="button" class="perspectivesbar-button-mobile">
                    <svg viewBox="0 0 16 16" fill="currentColor" data-slot="icon" aria-hidden="true" class="-ml-0.5 size-4 text-gray-400">
                      <path d="M2 2.75A.75.75 0 0 1 2.75 2h9.5a.75.75 0 0 1 0 1.5h-9.5A.75.75 0 0 1 2 2.75ZM2 6.25a.75.75 0 0 1 .75-.75h5.5a.75.75 0 0 1 0 1.5h-5.5A.75.75 0 0 1 2 6.25Zm0 3.5A.75.75 0 0 1 2.75 9h3.5a.75.75 0 0 1 0 1.5h-3.5A.75.75 0 0 1 2 9.75ZM9.22 9.53a.75.75 0 0 1 0-1.06l2.25-2.25a.75.75 0 0 1 1.06 0l2.25 2.25a.75.75 0 0 1-1.06 1.06l-.97-.97v5.69a.75.75 0 0 1-1.5 0V8.56l-.97.97a.75.75 0 0 1-1.06 0Z" clip-rule="evenodd" fill-rule="evenodd" />
                    </svg>
                  
                  </button>
                  <div class="-mr-px grid grow grid-cols-1 focus-within:relative">
                    
                    <select
                      id="perspective"
                      x-model="selectedPerspectiveId"
                      @change="fetchPayload()"
                      class="col-start-1 row-start-1 block w-full -md bg-white py-1.5 pl-10 pr-3 text-base text-gray-900 outline outline-1 -outline-offset-1 outline-gray-300 placeholder:text-gray-400 focus:outline focus:outline-2 focus:-outline-offset-2 focus:outline-indigo-600 sm:pl-9 sm:text-sm/6"
                    >
                      <template x-for="persp in availablePerspectives" :key="persp.id">
                        <option :value="persp.id" x-text="persp.name"></option>
                      </template>
                    </select>
                  </div>

                  
                  
                </div>
              </div>
            </div>
          </template>
          <template x-if="!isMobile">
            <div>
              {{-- Perspective Selector - Deskop --}}
              <div id="perspectivesBar" class="perspectivesbar-desktop">
                
                <div class=" flex">
                  <button type="button" class="perspectivesbar-button-desktop">
                    <svg viewBox="0 0 16 16" fill="currentColor" data-slot="icon" aria-hidden="true" class="-ml-0.5 size-4 text-gray-400">
                      <path d="M2 2.75A.75.75 0 0 1 2.75 2h9.5a.75.75 0 0 1 0 1.5h-9.5A.75.75 0 0 1 2 2.75ZM2 6.25a.75.75 0 0 1 .75-.75h5.5a.75.75 0 0 1 0 1.5h-5.5A.75.75 0 0 1 2 6.25Zm0 3.5A.75.75 0 0 1 2.75 9h3.5a.75.75 0 0 1 0 1.5h-3.5A.75.75 0 0 1 2 9.75ZM9.22 9.53a.75.75 0 0 1 0-1.06l2.25-2.25a.75.75 0 0 1 1.06 0l2.25 2.25a.75.75 0 0 1-1.06 1.06l-.97-.97v5.69a.75.75 0 0 1-1.5 0V8.56l-.97.97a.75.75 0 0 1-1.06 0Z" clip-rule="evenodd" fill-rule="evenodd" />
                    </svg>
                  
                  </button>
                  <div class="-mr-px grid grow grid-cols-1 focus-within:relative">
                    
                    <select
                      id="perspective"
                      x-model="selectedPerspectiveId"
                      @change="fetchPayload()"
                      class="col-start-1 row-start-1 block w-full -md bg-white py-1.5 pl-10 pr-3 text-base text-gray-900 outline outline-1 -outline-offset-1 outline-gray-300 placeholder:text-gray-400 focus:outline focus:outline-2 focus:-outline-offset-2 focus:outline-indigo-600 sm:pl-9 sm:text-sm/6"
                    >
                      <template x-for="persp in availablePerspectives" :key="persp.id">
                        <option :value="persp.id" x-text="persp.name"></option>
                      </template>
                    </select>
                  </div>

                  
                  
                </div>
              </div>
            </div>
          </template>

            



        </div>

        {{-- JS-driven stats table --}}
        <div id="player-stats-page" class="player-stats-page"></div>
    </div>
</x-app-layout>