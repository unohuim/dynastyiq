export default function adminHub(options = {}) {
    const playersAvailable = Boolean(options.hasPlayers);

    return {
        activeTab: playersAvailable ? 'players' : 'imports',
        imports: options.imports ?? [],
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
            allPlayers: false,
            loading: false,
        },

        async init() {
            this.listenForImportEvents();
            this.setTab(this.activeTab);
        },

        setTab(tab) {
            if (tab === 'players' && !this.hasPlayers) {
                return;
            }

            this.activeTab = tab;

            if (tab === 'players' && this.hasPlayers) {
                return this.loadPlayers();
            }

            return undefined;
        },

        async loadPlayers() {
            this.players.loading = true;

            try {
                const params = new URLSearchParams({
                    page: this.players.page,
                    per_page: this.players.perPage,
                    all_players: this.players.allPlayers ? '1' : '0',
                });

                if (this.players.filter.trim()) {
                    params.set('search', this.players.filter.trim());
                }

                const response = await fetch(`/admin/api/players?${params.toString()}`, {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    throw new Error(`Failed to load players: ${response.status}`);
                }

                const payload = await response.json();
                const meta    = payload.meta ?? {};

                this.players.items    = payload.data || [];
                this.players.total    = meta.total ?? this.players.items.length;
                this.players.perPage  = meta.per_page ?? this.players.perPage;
                this.players.page     = meta.current_page ?? this.players.page;
                this.players.lastPage = meta.last_page ?? Math.max(1, Math.ceil(this.players.total / this.players.perPage));

            } catch (error) {
                console.error(error);

                this.players.items    = [];
                this.players.total    = 0;
                this.players.page     = 1;
                this.players.lastPage = 1;

            } finally {
                this.players.loading = false;
            }
        },

        nextPage() {
            if (this.players.page < this.players.lastPage) {
                this.players.page += 1;
                this.loadPlayers();
            }
        },

        previousPage() {
            if (this.players.page > 1) {
                this.players.page -= 1;
                this.loadPlayers();
            }
        },

        filterPlayers() {
            this.players.page = 1;
            this.loadPlayers();
        },

        listenForImportEvents() {
            if (!window.Echo) {
                return;
            }

            window.Echo.private('admin.imports')
                .listen(
                    '.admin.import.output',
                    (payload) => {
                        const key = payload.source;
                        this.ensureStream(key);

                        const stream = this.streams[key];
                        stream.open = true;

                        if (payload.message?.trim()) {
                            stream.messages = [
                                {
                                    message: payload.message,
                                    status: payload.status,
                                    timestamp: payload.timestamp,
                                },
                                ...stream.messages,
                            ];
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
                    }
                )
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

        async refreshPlayersAvailability() {
            try {
                const response = await fetch('/admin/api/players?per_page=1', {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    return;
                }

                const payload = await response.json();
                const total = payload.meta?.total ?? payload.data?.length ?? 0;
                const playersNowAvailable = total > 0;

                const previousState = this.hasPlayers;
                this.hasPlayers = playersNowAvailable;

                if (this.hasPlayers && (this.activeTab === 'players' || !previousState)) {
                    this.players.page = payload.meta?.current_page ?? 1;
                    this.players.perPage = payload.meta?.per_page ?? this.players.perPage;
                    this.players.lastPage = payload.meta?.last_page ?? this.players.lastPage;
                    this.players.total = total;
                    this.players.items = payload.data ?? [];
                }

                if (!previousState && !this.hasPlayers) {
                    this.activeTab = 'imports';
                }
            } catch (error) {
                console.error(error);
            }
        },
    };
}
