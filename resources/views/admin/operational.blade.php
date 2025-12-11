<div
    class="py-12"
>
    <div
        class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6"
        x-data="{
            activeTab: 'players',
            imports: [],
            initialization: {
                initialized: {{ $initialized ? 'true' : 'false' }},
                initializing: false,
            },
            streams: {},
            importsBusy: false,
            players: {
                items: [],
                loading: false,
                page: 1,
                perPage: 25,
                total: 0,
                filter: '',
            },
            init() {
                const importsData = this.$root.dataset.imports;
                if (importsData) {
                    this.imports = JSON.parse(importsData);
                }

                this.bindBatchEvents();
                this.listenForImportEvents();
                this.initialization.initialized = Boolean(this.initialization.initialized);

                window.addEventListener('admin:init-started', () => {
                    this.initialization.initializing = true;
                });

                window.addEventListener('admin:init-finished', () => {
                    this.initialization.initializing = false;
                    this.initialization.initialized = true;
                });

                this.setTab(this.activeTab);
            },
            bindBatchEvents() {
                window.addEventListener('admin-batch:state', (event) => {
                    this.importsBusy = Boolean(event.detail?.active);
                });
            },
            listenForImportEvents() {
                if (!window.Echo) {
                    return;
                }

                window.Echo.private('admin.imports').listen(
                    '.admin.import.output',
                    (payload) => {
                        const key = payload.source;
                        this.ensureStream(key);

                        const stream = this.streams[key];
                        stream.open = true;
                        stream.messages.push({
                            message: payload.message,
                            status: payload.status,
                            timestamp: payload.timestamp,
                        });

                        if (payload.status === 'started') {
                            stream.running = true;
                            this.importsBusy = true;
                        }

                        if (payload.status === 'finished' || payload.status === 'failed') {
                            stream.running = false;
                            this.refreshImportMeta(key);
                            this.importsBusy = this.isAnyImportRunning();
                        }
                    }
                );
            },
            ensureStream(key) {
                if (!this.streams[key]) {
                    this.streams[key] = {
                        messages: [],
                        open: false,
                        running: false,
                    };
                }
            },
            toggleStream(key) {
                this.ensureStream(key);
                this.streams[key].open = !this.streams[key].open;
            },
            isAnyImportRunning() {
                return Object.values(this.streams).some((stream) => stream.running);
            },
            async startImport(key) {
                if (this.importsBusy) {
                    return;
                }

                const importConfig = this.imports.find((item) => item.key === key);
                if (!importConfig?.run_url) {
                    return;
                }

                this.importsBusy = true;
                this.ensureStream(key);
                this.streams[key].messages = [];
                this.streams[key].open = true;
                this.streams[key].running = true;

                try {
                    const response = await fetch(importConfig.run_url, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=\"csrf-token\"]').getAttribute('content'),
                        },
                    });

                    if (!response.ok) {
                        throw new Error('Unable to start import');
                    }
                } catch (error) {
                    this.streams[key].messages.push({
                        message: error.message,
                        status: 'failed',
                        timestamp: new Date().toISOString(),
                    });
                    this.streams[key].running = false;
                    this.importsBusy = this.isAnyImportRunning();
                }
            },
            refreshImportMeta(key) {
                const now = new Date();
                this.imports = this.imports.map((item) => {
                    if (item.key === key) {
                        return { ...item, last_run: now.toISOString() };
                    }
                    return item;
                });
            },
            async setTab(tab) {
                this.activeTab = tab;

                if (tab === 'players') {
                    await this.loadPlayers();
                }
            },
            async loadPlayers(page = null) {
                if (page) {
                    this.players.page = page;
                }

                this.players.loading = true;

                const params = new URLSearchParams({
                    section: 'players',
                    page: this.players.page,
                    per_page: this.players.perPage,
                    filter: this.players.filter,
                });

                try {
                    const response = await fetch(`${window.location.pathname}?${params.toString()}`, {
                        headers: {
                            Accept: 'application/json',
                        },
                    });

                    if (!response.ok) {
                        throw new Error('Failed to load players');
                    }

                    const data = await response.json();
                    this.players.items = data.data ?? [];
                    this.players.total = data.pagination?.total ?? 0;
                    this.players.page = data.pagination?.page ?? 1;
                    this.players.perPage = data.pagination?.per_page ?? this.players.perPage;
                } catch (error) {
                    console.error(error);
                } finally {
                    this.players.loading = false;
                }
            },
            nextPage() {
                const maxPage = Math.ceil(this.players.total / this.players.perPage) || 1;
                if (this.players.page < maxPage) {
                    this.loadPlayers(this.players.page + 1);
                }
            },
            previousPage() {
                if (this.players.page > 1) {
                    this.loadPlayers(this.players.page - 1);
                }
            },
        }"
        x-init="init()"
        x-cloak
        data-imports='@json($imports)'
    >
        <div class="bg-white shadow rounded-lg">
            <div class="border-b px-6 pt-4 flex items-center space-x-4">
                <button
                    type="button"
                    class="pb-3 text-sm font-semibold border-b-2"
                    :class="activeTab === 'players' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                    x-on:click="setTab('players')"
                >
                    Players
                </button>
                <button
                    type="button"
                    class="pb-3 text-sm font-semibold border-b-2"
                    :class="activeTab === 'pbp' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                    x-on:click="setTab('pbp')"
                >
                    PBP
                </button>
                <button
                    type="button"
                    class="pb-3 text-sm font-semibold border-b-2"
                    :class="activeTab === 'imports' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                    x-on:click="setTab('imports')"
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
