<div
  
  class="w-full overflow-y-auto max-h-[90vh]"
>



  <!-- Loading State -->
  <div x-show="$store.playerStats.filteredAndSorted.length === 0" class="text-gray-500 text-sm p-4">
    Loading players…
  </div>

  <!-- Players Table -->
  <template x-if="$store.playerStats.filteredAndSorted.length > 0">
    <div class="min-w-full bg-white shadow rounded-lg overflow-hidden border border-gray-200">
      
      <!-- Header Row -->
      <div class="transition-all duration-300 ease-in-out will-change-transform grid grid-cols-9 text-xs font-semibold bg-gray-100 text-gray-700 px-4 py-2">
        <template x-for="header in [
          { label: 'Player', key: 'player_name' },
          { label: 'Age', key: 'age' },
          { label: 'Pos', key: 'pos_type' },
          { label: 'GP', key: 'GP' },
          { label: 'PTS', key: 'PTS' },
          { label: 'G', key: 'G' },
          { label: 'A', key: 'A' },
          { label: 'P/GP', key: 'avgPTSpGP' },
          { label: 'SH%', key: 'shooting_percentage' }
        ]" :key="header.key">
          <div
            class="cursor-pointer flex items-center gap-1"
            @click="$store.playerStats.toggleSort(header.key)"
          >
            <span x-text="header.label"></span>
            <template x-if="$store.playerStats.sortField === header.key">
              <span x-text="$store.playerStats.sortDirection === 'asc' ? '↑' : '↓'"></span>
            </template>
          </div>
        </template>
      </div>

      <!-- Player Rows -->
      <template x-for="player in $store.playerStats.filteredAndSorted" :key="player.player_id">
        <div class="grid grid-cols-9 border-t px-4 py-2 text-sm hover:bg-gray-50">
          
          <!-- Name -->
          <div class="flex flex-col">
            <span class="font-medium" x-text="player.player_name"></span>
          </div>

          <!-- Age -->
          <div :class="$store.playerStats.sortField === 'age' ? 'font-semibold text-gray-900' : 'text-sm text-gray-700'"
               x-text="player.age"></div>

          <!-- Pos -->
          <div :class="$store.playerStats.sortField === 'pos_type' ? 'font-semibold text-gray-900' : 'text-sm text-gray-700'"
               x-text="player.player.pos_type"></div>

          <!-- Stat Cells -->
          <template x-for="key in ['GP', 'PTS', 'G', 'A', 'avgPTSpGP', 'shooting_percentage']" :key="key">
            <div>
              <template x-if="$store.playerStats.sortField === key">
                <span class="font-semibold text-gray-900">
                  <template x-if="key === 'shooting_percentage'">
                    <span x-text="(player[key] * 100).toFixed(1) + '%'"></span>
                  </template>
                  <template x-if="key === 'avgPTSpGP'">
                    <span x-text="Number(player[key]).toFixed(2)"></span>
                  </template>
                  <template x-if="!['avgPTSpGP','shooting_percentage'].includes(key)">
                    <span x-text="player[key]"></span>
                  </template>
                </span>
              </template>

              <template x-if="$store.playerStats.sortField !== key">
                <span class="text-sm text-gray-700">
                  <template x-if="key === 'shooting_percentage'">
                    <span x-text="(player[key] * 100).toFixed(1) + '%'"></span>
                  </template>
                  <template x-if="key === 'avgPTSpGP'">
                    <span x-text="Number(player[key]).toFixed(2)"></span>
                  </template>
                  <template x-if="!['avgPTSpGP','shooting_percentage'].includes(key)">
                    <span x-text="player[key]"></span>
                  </template>
                </span>
              </template>
            </div>
          </template>
        </div>
      </template>
    </div>
  </template>

  <!-- 🧲 Always-visible Lazyload Sentinel -->
  <div x-ref="sentinel" class="h-6 bg-white"></div>
</div>
