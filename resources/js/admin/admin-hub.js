let importListenersRegistered = false;

export default function adminHub(options = {}) {
    const playersAvailable = Boolean(options.hasPlayers);

    return {
        activeTab: playersAvailable ? 'nhl' : 'fantrax',
        imports: options.imports ?? [],
        activeSource: playersAvailable ? 'nhl' : 'fantrax',
        hasPlayers: playersAvailable,

        streams: {},
        importsBusy: false,

        players: {
            items: [],
            page: 1,
            perPage: 25,
            total: 0,
            lastPage: 1,
            filter: '',
            toggle: {
                nhl: false,
                fantrax: true,
            },
            loading: false,
        },

        init() {
            this.registerImportListeners();
            this.setTab(this.activeTab);
        },

        /* -----------------------------
         * Tabs
         * --------------------------- */

        setTab(tab) {
            if (tab === 'nhl' && !this.hasPlayers) {
                return;
            }

            this.activeTab = tab;

            if (tab === 'nhl' || tab === 'fantrax') {
                this.activeSource = tab;
                this.players.page = 1;
            }

            if ((tab === 'nhl' && this.hasPlayers) || tab === 'fantrax') {
                this.loadPlayers();
            }
        },

        /* -----------------------------
         * Players
         * --------------------------- */

        async loadPlayers() {
            this.players.loading = true;

            try {
                const params = new URLSearchParams({
                    page: this.players.page,
                    per_page: this.players.perPage,
                    source: this.activeSource,
                });

                const toggle = this.players.toggle?.[this.activeSource];

                if (this.activeSource === 'nhl') {
                    params.set('all_players', toggle ? '1' : '0');
                } else {
                    params.set('nhl_matched', toggle ? '1' : '0');
                }

                if (this.players.filter.trim()) {
                    params.set('search', this.players.filter.trim());
                }

                const response = await fetch(`/admin/api/players?${params}`, {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    throw new Error(`Failed to load players (${response.status})`);
                }

                const payload = await response.json();
                const meta = payload.meta ?? {};

                this.players.items = payload.data ?? [];
                this.players.total = meta.total ?? 0;
                this.players.page = meta.current_page ?? 1;
                this.players.perPage = meta.per_page ?? this.players.perPage;
                this.players.lastPage =
                    meta.last_page ??
                    Math.max(1, Math.ceil(this.players.total / this.players.perPage));
            } catch (e) {
                console.error(e);
                this.players.items = [];
                this.players.total = 0;
                this.players.page = 1;
                this.players.lastPage = 1;
            } finally {
                this.players.loading = false;
            }
        },

        nextPage() {
            if (this.players.page < this.players.lastPage) {
                this.players.page++;
                this.loadPlayers();
            }
        },

        previousPage() {
            if (this.players.page > 1) {
                this.players.page--;
                this.loadPlayers();
            }
        },

        filterPlayers() {
            this.players.page = 1;
            this.loadPlayers();
        },

        /* -----------------------------
         * Imports / Streams
         * --------------------------- */

        registerImportListeners() {
            if (!window.Echo || importListenersRegistered) {
                return;
            }

            importListenersRegistered = true;

            window.Echo
                .private('admin.imports')
                .listen('.admin.import.output', (payload) => {
                    const key = payload.source;
                    this.ensureStream(key);

                    const stream = this.streams[key];
                    stream.open = true;

                    if (payload.message?.trim()) {
                        stream.messages.unshift({
                            message: payload.message,
                            status: payload.status,
                            timestamp: payload.timestamp,
                        });
                    }

                    if (payload.status === 'started') {
                        stream.running = true;
                        this.importsBusy = true;
                    }

                    if (payload.status === 'finished' || payload.status === 'failed') {
                        stream.running = false;
                        this.refreshImportMeta(key);
                        this.importsBusy = this.isAnyImportRunning();
                    }
                })
                .listen('.players.available', () => {
                    this.refreshPlayersAvailability();
                });
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
            return Object.values(this.streams).some((s) => s.running);
        },

        async startImport(key) {
            if (this.importsBusy) {
                return;
            }

            const config = this.imports.find((i) => i.key === key);
            if (!config?.run_url) {
                return;
            }

            this.importsBusy = true;
            this.ensureStream(key);

            const stream = this.streams[key];
            stream.messages = [];
            stream.open = true;
            stream.running = true;

            try {
                const response = await fetch(config.run_url, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute('content'),
                    },
                });

                if (!response.ok) {
                    throw new Error('Failed to start import');
                }
            } catch (e) {
                stream.messages.unshift({
                    message: e.message,
                    status: 'failed',
                    timestamp: new Date().toISOString(),
                });

                stream.running = false;
                this.importsBusy = this.isAnyImportRunning();
            }
        },

        refreshImportMeta(key) {
            const now = new Date().toISOString();

            this.imports = this.imports.map((item) =>
                item.key === key ? { ...item, last_run: now } : item
            );
        },

        async refreshPlayersAvailability() {
            try {
                const response = await fetch('/admin/api/players?per_page=1', {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    return;
                }

                const payload = await response.json();
                const total = payload.meta?.total ?? 0;
                const available = total > 0;

                const prev = this.hasPlayers;
                this.hasPlayers = available;

                if (available && (!prev || this.activeTab === 'nhl')) {
                    this.players.items = payload.data ?? [];
                    this.players.total = total;
                }

                if (!available && this.activeTab === 'nhl') {
                    this.activeTab = 'fantrax';
                    this.activeSource = 'fantrax';
                }
            } catch (e) {
                console.error(e);
            }
        },
    };
}
