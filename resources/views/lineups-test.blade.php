<x-app-layout :livewire="false">
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-900">Lineups test</h1>
    </x-slot>

    <div data-page="lineups-test" data-payload="lineups-test-payload" x-data="highlightlyLineupsTest"
        class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">NHL games</h2>
                <p class="mt-1 text-sm text-gray-600">Highlightly lineups · Dates and times in Toronto</p>
            </div>
            <div class="flex items-end gap-2">
                <button type="button" aria-label="Previous date" @click="changeDay(-1)"
                    class="flex h-10 w-10 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-700 transition-colors duration-150 hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-indigo-500">←</button>
                <x-ui.date-field id="lineups-test-date" label="Game date" model="date" :value="$payload['date']" @change="loadGames()" class="h-10" />
                <button type="button" aria-label="Next date" @click="changeDay(1)"
                    class="flex h-10 w-10 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-700 transition-colors duration-150 hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-indigo-500">→</button>
            </div>
        </div>

        <section aria-label="Games for selected date" :aria-busy="gamesLoading" class="rounded-md bg-white px-4 py-4 sm:px-6">
            <p x-show="gamesLoading" role="status" class="py-4 text-sm text-gray-500">Loading games…</p>
            <div x-cloak x-show="gamesError" role="alert" class="py-4 text-sm text-red-700">
                <p x-text="gamesError"></p>
                <button type="button" @click="loadGames()" class="mt-2 font-medium underline">Try again</button>
            </div>
            <div x-cloak x-show="gamesLoaded && games.length === 0" class="py-6 text-sm text-gray-600">
                <p>No NHL games found for this date.</p>
                <button type="button" @click="changeDay(1)" class="mt-2 font-medium text-indigo-700 underline">View next day</button>
            </div>
            <ul class="divide-y divide-gray-100">
                <template x-for="game in games" :key="game.id">
                    <li>
                        <button type="button" @click="selectGame(game)" :aria-pressed="selectedGame?.id === game.id"
                            :class="selectedGame?.id === game.id ? 'bg-indigo-50' : 'hover:bg-gray-50'"
                            class="flex w-full flex-wrap items-center justify-between gap-3 rounded-md px-3 py-4 text-left transition-colors duration-150 focus-visible:ring-2 focus-visible:ring-indigo-500">
                            <span>
                                <span class="block font-medium text-gray-900" x-text="teamName(game.awayTeam) + ' at ' + teamName(game.homeTeam)"></span>
                                <span class="mt-1 block text-sm text-gray-500" x-text="gameTime(game) + (game.state?.description ? ' · ' + game.state.description : '')"></span>
                            </span>
                            <span class="text-sm font-medium text-indigo-700">View lineups →</span>
                        </button>
                    </li>
                </template>
            </ul>
        </section>

        <section x-cloak x-show="selectedGame" aria-label="Selected game lineups" :aria-busy="lineupsLoading"
            x-transition:enter="transition-opacity duration-200 ease-out motion-reduce:transition-none"
            x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            class="rounded-md bg-white px-4 py-5 sm:px-6">
            <h2 class="text-lg font-semibold text-gray-900" x-text="selectedGame ? teamName(selectedGame.awayTeam) + ' at ' + teamName(selectedGame.homeTeam) : ''"></h2>
            <p class="mt-1 text-sm text-gray-500">Lineups may become available a few hours before puck drop.</p>
            <p x-show="lineupsLoading" role="status" class="mt-4 text-sm text-gray-500">Loading both team lineups…</p>
            <div x-show="lineupsError" role="alert" class="mt-4 text-sm text-red-700">
                <p x-text="lineupsError"></p>
                <button type="button" @click="selectGame(selectedGame)" class="mt-2 font-medium underline">Try again</button>
            </div>
            <div x-show="lineups && !lineupsLoading" class="mt-6 grid gap-8 md:grid-cols-2">
                <template x-for="side in ['away', 'home']" :key="side">
                    <section>
                        <h3 class="font-semibold text-gray-900" x-text="teamName(selectedGame?.[side + 'Team'])"></h3>
                        <p class="mt-1 text-xs uppercase tracking-wide text-gray-500" x-text="side"></p>
                        <p x-show="players(side).length === 0" class="mt-4 text-sm text-gray-500">Lineup not available yet.</p>
                        <ul class="mt-3 divide-y divide-gray-100">
                            <template x-for="(player, index) in players(side)" :key="side + '-' + index">
                                <li class="flex items-center gap-3 py-3 text-sm">
                                    <span class="w-8 shrink-0 text-gray-500" x-text="player.jersey != null ? '#' + player.jersey : '—'"></span>
                                    <span class="min-w-0 flex-1 font-medium text-gray-900" x-text="player.player || 'Player unavailable'"></span>
                                    <span class="text-gray-500" x-text="player.positionAbbreviation || player.position || '—'"></span>
                                    <span x-show="player.isScratched === true" class="text-xs font-medium text-amber-700">Scratched</span>
                                </li>
                            </template>
                        </ul>
                    </section>
                </template>
                <button type="button" @click="selectGame(selectedGame)" class="justify-self-start text-sm font-medium text-indigo-700 underline">Refresh lineups</button>
            </div>
        </section>
    </div>
    <script type="application/json" id="lineups-test-payload">{!! json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
</x-app-layout>
