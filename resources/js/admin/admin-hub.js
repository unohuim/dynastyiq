export default function adminHub(options = {}) {
    return {
        activeTab: 'players',
        imports: options.imports ?? [],
        initialization: { initialized: options.initialized, initializing: false },

        streams: {},
        importsBusy: false,

        allPlayers: [],
        players: {
            items: [],
            page: 1,
            perPage: 25,
            total: 0,
            filter: '',
            loading: false,
        },

        async init() {
            this.initialization.initialized = Boolean(this.initialization.initialized);
            this.bindBatchEvents();
            this.listenForImportEvents();

            window.addEventListener('admin:init-started', () => {
                this.initialization.initializing = true;
            });

            window.addEventListener('admin:init-finished', () => {
                this.initialization.initializing = false;
                this.initialization.initialized = true;
            });

            this.setTab('players');
        },

        setTab(tab) {
            this.activeTab = tab;

            if (tab === 'players') {
                return this.loadPlayers();
            }

            return undefined;
        },

        async loadPlayers() {
            this.players.loading = true;

            try {
                const response = await fetch('/admin/api/players', {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    throw new Error(`Failed to load players: ${response.status}`);
                }

                const data = await response.json();

                const incomingPlayers = data.players || [];
                this.allPlayers = incomingPlayers.map((player) => ({
                    ...player,
                    team_abbrev: player.team_abbrev ?? player.team,
                }));
                this.players.page = 1;
                this.applyPlayerFilter();

            } catch (error) {
                console.error(error);

                // Prevent stale results when request fails
                this.allPlayers    = [];
                this.players.items = [];
                this.players.total = 0;
                this.players.page  = 1;

            } finally {
                this.players.loading = false;
            }
        },

        nextPage() {
            const maxPage = Math.max(1, Math.ceil(this.players.total / this.players.perPage)) || 1;
            if (this.players.page < maxPage) {
                this.players.page += 1;
                this.applyPlayerFilter();
            }
        },

        previousPage() {
            if (this.players.page > 1) {
                this.players.page -= 1;
                this.applyPlayerFilter();
            }
        },

        filterPlayers() {
            this.players.page = 1;
            this.applyPlayerFilter();
        },

        applyPlayerFilter() {
            const q = (this.players.filter || '').toLowerCase();
            const filtered = q
                ? this.allPlayers.filter((p) => p.full_name.toLowerCase().includes(q))
                : this.allPlayers;

            this.players.total = filtered.length;

            const maxPage = Math.max(1, Math.ceil(filtered.length / this.players.perPage)) || 1;
            if (this.players.page > maxPage) {
                this.players.page = maxPage;
            }

            const start = (this.players.page - 1) * this.players.perPage;
            const end = start + this.players.perPage;
            this.players.items = filtered.slice(start, end);
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
                        'X-CSRF-TOKEN': document
                            .querySelector('meta[name="csrf-token"]')
                            .getAttribute('content'),
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
    };
}
