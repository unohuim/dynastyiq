<div
    class="py-12"
>
    <div
        class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6"
        x-data="adminHub()"
        x-init="
            imports = @js($imports);
            initialization.initialized = {{ $initialized ? 'true' : 'false' }};
            init();
        "
        x-cloak
    >
        <div class="bg-white shadow rounded-lg">
            <div class="border-b px-6 pt-4 flex items-center space-x-4">
                <button
                    type="button"
                    class="pb-3 text-sm font-semibold border-b-2"
                    @click="setTab('players')"
                    :class="activeTab === 'players' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                >
                    Players
                </button>
                <button
                    type="button"
                    class="pb-3 text-sm font-semibold border-b-2"
                    @click="setTab('pbp')"
                    :class="activeTab === 'pbp' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                >
                    PBP
                </button>
                <button
                    type="button"
                    class="pb-3 text-sm font-semibold border-b-2"
                    @click="setTab('imports')"
                    :class="activeTab === 'imports' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                >
                    Data Imports
                </button>
            </div>

            <div class="p-6">
                <div x-show="activeTab === 'players'" x-cloak class="space-y-4">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                        <div>
                            <div class="text-lg font-semibold text-gray-800">Players</div>
                            <p class="text-sm text-gray-600">Read-only view with future action hooks.</p>
                        </div>
                        <div class="text-sm text-gray-700 flex items-center space-x-2">
                            <span>{{ $unmatchedPlayersCount }} unmatched players</span>
                            <span class="text-gray-400">•</span>
                            <a href="{{ route('admin.player-triage') }}" class="text-indigo-600 font-semibold">Player Triage</a>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <input
                            type="text"
                            class="w-full md:w-80 rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                            placeholder="Filter by name"
                            x-model.debounce.400ms="players.filter"
                            x-on:input="loadPlayers(1)"
                        />
                    </div>

                    <div class="overflow-x-auto border border-gray-200 rounded-lg">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600">Name</th>
                                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600">Position</th>
                                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600">Team</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <tr x-show="players.loading">
                                    <td class="px-4 py-4 text-sm text-gray-500" colspan="4">Loading players...</td>
                                </tr>
                                <template x-if="!players.loading && players.items.length === 0">
                                    <tr>
                                        <td class="px-4 py-4 text-sm text-gray-500" colspan="4">No players found.</td>
                                    </tr>
                                </template>
                                <template x-for="player in players.items" :key="player.id">
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-sm font-medium text-gray-800" x-text="player.full_name"></td>
                                        <td class="px-4 py-3 text-sm text-gray-600" x-text="player.position || '—'"></td>
                                        <td class="px-4 py-3 text-sm text-gray-600" x-text="player.team_abbrev || '—'"></td>
                                        <td class="px-4 py-3 text-sm text-gray-400 text-right">Actions</td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex items-center justify-between text-sm text-gray-700">
                        <div>
                            Page <span x-text="players.page"></span>
                            of
                            <span x-text="Math.max(1, Math.ceil(players.total / players.perPage))"></span>
                            — <span x-text="players.total"></span> players
                        </div>
                        <div class="space-x-2">
                            <x-secondary-button type="button" x-on:click="previousPage()" x-bind:disabled="players.page <= 1">Previous</x-secondary-button>
                            <x-secondary-button type="button" x-on:click="nextPage()" x-bind:disabled="players.page >= Math.ceil(players.total / players.perPage)">Next</x-secondary-button>
                        </div>
                    </div>
                </div>

                <div x-show="activeTab === 'pbp'" x-cloak class="space-y-3">
                    <div class="text-lg font-semibold text-gray-800">Play-by-Play</div>
                    <p class="text-sm text-gray-600">Coming soon.</p>
                </div>

                <div x-show="activeTab === 'imports'" x-cloak class="space-y-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-lg font-semibold text-gray-800">Data Imports</div>
                            <p class="text-sm text-gray-600">Run imports without leaving the page. Output streams in real time.</p>
                        </div>
                        <div class="text-xs text-gray-600" x-show="importsBusy">Imports running...</div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @foreach($imports as $import)
                            <div class="border rounded-lg p-4 space-y-3">
                                <div class="flex items-start justify-between">
                                    <div>
                                        <div class="font-semibold text-gray-800">{{ $import['label'] }}</div>
                                        <div class="text-sm text-gray-600">Last run: {{ $import['last_run'] ?? 'N/A' }}</div>
                                    </div>
                                    <x-primary-button
                                        type="button"
                                        x-on:click="startImport('{{ $import['key'] }}')"
                                        x-bind:disabled="importsBusy || initialization.initializing || !initialization.initialized || (streams['{{ $import['key'] }}']?.running ?? false)"
                                    >
                                        Run Now
                                    </x-primary-button>
                                </div>
                                <div class="space-y-2">
                                    <button
                                        type="button"
                                        class="text-sm text-indigo-600 font-semibold"
                                        x-on:click="toggleStream('{{ $import['key'] }}')"
                                    >
                                        <span x-text="streams['{{ $import['key'] }}']?.open ? 'Hide Output' : 'Show Output'"></span>
                                    </button>
                                    <div
                                        class="bg-black text-green-200 font-mono text-xs rounded-md p-3 h-40 overflow-y-auto"
                                        x-show="streams['{{ $import['key'] }}']?.open"
                                    >
                                        <template x-if="(streams['{{ $import['key'] }}']?.messages?.length ?? 0) === 0">
                                            <div class="text-gray-400">Awaiting output...</div>
                                        </template>
                                        <template x-for="(entry, idx) in streams['{{ $import['key'] }}']?.messages" :key="idx">
                                            <div class="whitespace-pre-wrap" x-text="`[${entry.status}] ${entry.message}`"></div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <x-card-section title="Scheduler" title-class="text-lg font-semibold" is-accordian="true">
                <div class="space-y-2">
                    @foreach($events as $event)
                        <div class="border rounded-lg p-4">
                            <div class="font-semibold">{{ $event['command'] }}</div>
                            <div class="text-sm text-gray-600">Cron: {{ $event['expression'] }}</div>
                            <div class="text-sm text-gray-600">Last run: {{ $event['last'] ?? 'Unknown' }}</div>
                        </div>
                    @endforeach
                </div>
            </x-card-section>
        </div>
    </div>
</div>
