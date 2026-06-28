let importListenersRegistered = false;

export default function adminHub(options = {}) {
    const nhlAvailable = Boolean(options.hasPlayers);
    const fantraxAvailable = Boolean(options.hasFantrax);

    const initialTab = 'triage';
    const initialSource = nhlAvailable ? 'nhl' : fantraxAvailable ? 'fantrax' : 'nhl';

    return {
        activeTab: initialTab,
        imports: options.imports ?? [],
        activeSource: initialSource,
        hasPlayers: nhlAvailable,
        hasFantrax: fantraxAvailable,

        streams: {},
        progressPollers: {},

        roster: {
            nhl: {
                items: [],
                page: 1,
                perPage: 25,
                total: 0,
                lastPage: 1,
                filter: '',
                toggle: false,
                loading: false,
            },
            fantrax: {
                items: [],
                page: 1,
                perPage: 25,
                total: 0,
                lastPage: 1,
                filter: '',
                toggle: true,
                loading: false,
            },
        },

        init() {
            this.initializeImportStreams();
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

            if (tab === 'fantrax' && !this.hasFantrax) {
                return;
            }

            this.activeTab = tab;

            if (tab === 'nhl' || tab === 'fantrax') {
                this.activeSource = tab;
            }

            if (
                (tab === 'nhl' && this.hasPlayers) ||
                (tab === 'fantrax' && this.hasFantrax)
            ) {
                this.loadPlayers();
            }
        },

        /* -----------------------------
         * Players
         * --------------------------- */

        async loadPlayers() {
            const source = this.activeSource;
            const state = this.roster[source];

            if (
                (source === 'nhl' && !this.hasPlayers) ||
                (source === 'fantrax' && !this.hasFantrax)
            ) {
                state.items = [];
                state.total = 0;
                state.page = 1;
                state.lastPage = 1;

                return;
            }

            state.loading = true;

            try {
                const params = new URLSearchParams({
                    page: state.page,
                    per_page: state.perPage,
                    source,
                });

                const toggle = state.toggle;

                if (source === 'nhl') {
                    params.set('all_players', toggle ? '1' : '0');
                } else {
                    params.set('nhl_matched', toggle ? '1' : '0');
                }

                if (state.filter.trim()) {
                    params.set('search', state.filter.trim());
                }

                const response = await fetch(`/admin/api/players?${params}`, {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    throw new Error(`Failed to load players (${response.status})`);
                }

                const payload = await response.json();
                const meta = payload.meta ?? {};

                state.items = payload.data ?? [];
                state.total = meta.total ?? 0;
                state.page = meta.current_page ?? state.page;
                state.perPage = meta.per_page ?? state.perPage;
                state.lastPage =
                    meta.last_page ?? Math.max(1, Math.ceil(state.total / state.perPage));
            } catch (e) {
                console.error(e);
                state.items = [];
                state.total = 0;
                state.page = 1;
                state.lastPage = 1;
            } finally {
                state.loading = false;
            }
        },

        nextPage() {
            const state = this.roster[this.activeSource];

            if (state.page < state.lastPage) {
                state.page++;
                this.loadPlayers();
            }
        },

        previousPage() {
            const state = this.roster[this.activeSource];

            if (state.page > 1) {
                state.page--;
                this.loadPlayers();
            }
        },

        filterPlayers() {
            const state = this.roster[this.activeSource];
            state.page = 1;
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
                        this.scheduleImportProgressPoll(key);
                    }

                    if (payload.status === 'progress') {
                        stream.running = true;
                        this.refreshImportProgress(key);
                    }

                    if (payload.status === 'finished' || payload.status === 'failed') {
                        this.refreshImportProgress(key);
                    }
                })
                .listen('.players.available', (payload) => {
                    this.handlePlayersAvailable(payload?.source);
                });
        },

        ensureStream(key) {
            if (!this.streams[key]) {
                this.streams[key] = {
                    messages: [],
                    open: false,
                    running: false,
                    importRun: null,
                    progress: null,
                };
            }
        },

        initializeImportStreams() {
            this.imports.forEach((item) => {
                this.ensureStream(item.key);
                this.streams[item.key].progress = item.progress ?? null;
                this.streams[item.key].running = item.status === 'working';
                this.streams[item.key].importRun = {
                    status: item.status,
                    started_at: item.started_at,
                    finished_at: item.finished_at,
                    duration_seconds: item.duration_seconds,
                };

                if (this.streams[item.key].running) {
                    this.scheduleImportProgressPoll(item.key);
                }
            });
        },

        toggleStream(key) {
            this.ensureStream(key);
            this.streams[key].open = !this.streams[key].open;
        },

        async startImport(key) {
            const config = this.imports.find((i) => i.key === key);
            if (!config?.run_url) {
                return;
            }

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

                const payload = await response.json();
                this.applyImportRun(key, payload.import_run);
                this.scheduleImportProgressPoll(key);
            } catch (e) {
                stream.messages.unshift({
                    message: e.message,
                    status: 'failed',
                    timestamp: new Date().toISOString(),
                });

                stream.running = false;
            }
        },

        async refreshImportProgress(key, shouldContinue = true) {
            const config = this.imports.find((i) => i.key === key);
            if (!config?.status_url) {
                return;
            }

            try {
                const response = await fetch(config.status_url, {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    throw new Error('Failed to refresh import progress');
                }

                const payload = await response.json();
                const importRun = payload.import_run ?? null;
                this.applyImportRun(key, importRun);

                if (importRun?.status === 'working') {
                    this.scheduleImportProgressPoll(key);
                    return;
                }

                if (importRun?.status === 'completed' || importRun?.status === 'failed') {
                    this.streams[key].running = false;
                    this.stopImportProgressPoll(key);
                    return;
                }

                if (shouldContinue && this.streams[key]?.running) {
                    this.scheduleImportProgressPoll(key);
                }
            } catch (e) {
                console.error(e);

                if (shouldContinue && this.streams[key]?.running) {
                    this.scheduleImportProgressPoll(key, 5000);
                }
            }
        },

        scheduleImportProgressPoll(key, delay = 2000) {
            this.stopImportProgressPoll(key);

            this.progressPollers[key] = setTimeout(() => {
                this.refreshImportProgress(key);
            }, delay);
        },

        stopImportProgressPoll(key) {
            if (!this.progressPollers[key]) {
                return;
            }

            clearTimeout(this.progressPollers[key]);
            delete this.progressPollers[key];
        },

        applyImportRun(key, importRun) {
            if (!importRun) {
                return;
            }

            this.ensureStream(key);

            const progress = importRun.progress ?? null;
            this.streams[key].importRun = importRun;
            this.streams[key].progress = progress;
            this.streams[key].running = importRun.status === 'working';

            this.imports = this.imports.map((item) =>
                item.key === key
                    ? {
                          ...item,
                          status: importRun.status,
                          duration_seconds: importRun.duration_seconds,
                          started_at: importRun.started_at,
                          finished_at: importRun.finished_at,
                          last_run:
                              importRun.finished_at ??
                              importRun.started_at ??
                              item.last_run,
                          progress,
                      }
                    : item
            );
        },

        importProgress(key) {
            return this.streams[key]?.progress ?? null;
        },

        shouldShowImportProgress(key) {
            const progress = this.importProgress(key);

            return Boolean(progress) || this.streams[key]?.running === true;
        },

        importProgressPercentage(key) {
            const progress = this.importProgress(key);

            if (progress?.dynamic_total) {
                const status = this.streams[key]?.importRun?.status
                    ?? this.imports.find((importConfig) => importConfig.key === key)?.status;
                const processed = Number(progress.processed_records) || 0;
                const estimate = this.importProgressEstimatedTotal(key);

                if (status === 'completed') {
                    return 100;
                }

                if (processed <= 0 || estimate <= 0) {
                    return this.streams[key]?.running ? 8 : 0;
                }

                return Math.min(92, Math.max(8, Math.floor((processed / estimate) * 100)));
            }

            if (progress?.percentage === null || progress?.percentage === undefined) {
                return this.streams[key]?.running ? 8 : 0;
            }

            return Math.max(0, Math.min(100, Number(progress.percentage) || 0));
        },

        importProgressText(key) {
            const progress = this.importProgress(key);
            const processed = progress?.processed_records ?? 0;
            const total = progress?.total_records;

            if (progress?.dynamic_total) {
                const status = this.streams[key]?.importRun?.status
                    ?? this.imports.find((importConfig) => importConfig.key === key)?.status;
                const estimate = this.importProgressEstimatedTotal(key);

                if (status === 'completed' && total) {
                    return `${this.formatNumber(processed)} / ${this.formatNumber(total)} records`;
                }

                if (estimate) {
                    return `${this.formatNumber(processed)} / ~${this.formatNumber(estimate)} records`;
                }

                return `${this.formatNumber(processed)} records processed`;
            }

            if (total) {
                return `${this.formatNumber(processed)} / ~${this.formatNumber(total)} records`;
            }

            return `${this.formatNumber(processed)} records processed`;
        },

        importProgressEstimatedTotal(key) {
            const progress = this.importProgress(key);
            const discovered = Number(progress?.total_records) || 0;
            const processed = Number(progress?.processed_records) || 0;
            const status = this.streams[key]?.importRun?.status
                ?? this.imports.find((importConfig) => importConfig.key === key)?.status;

            if (status === 'completed') {
                return Math.max(discovered, processed);
            }

            if (key === 'nhl') {
                return Math.max(processed, 2650);
            }

            return processed;
        },

        importProgressDetailText(key) {
            const progress = this.importProgress(key);

            if (!progress) {
                return 'Preparing import...';
            }

            const successful = progress.successful_records ?? 0;
            const failed = progress.failed_records ?? 0;
            const skipped = progress.skipped_records ?? 0;
            const elapsed = this.importElapsedText(key);
            const elapsedText = elapsed ? ` · elapsed ${elapsed}` : '';

            return `${this.formatNumber(successful)} imported, ${this.formatNumber(failed)} failed, ${this.formatNumber(skipped)} skipped${elapsedText}`;
        },

        formatNumber(value) {
            return new Intl.NumberFormat().format(Number(value) || 0);
        },

        formatLastRun(key) {
            const item = this.imports.find((importConfig) => importConfig.key === key);
            const date = this.parseDate(item?.last_run);

            if (!date) {
                return 'N/A';
            }

            return this.formatSocialDate(date);
        },

        importElapsedText(key) {
            const importRun = this.streams[key]?.importRun
                ?? this.imports.find((importConfig) => importConfig.key === key);

            if (!importRun) {
                return null;
            }

            const explicitDuration = Number(importRun.duration_seconds);
            if (explicitDuration > 0) {
                return this.formatDuration(explicitDuration);
            }

            const startedAt = this.parseDate(importRun.started_at);
            if (!startedAt) {
                return null;
            }

            return this.formatDuration((Date.now() - startedAt.getTime()) / 1000);
        },

        parseDate(value) {
            if (!value) {
                return null;
            }

            const normalized = typeof value === 'string' && value.includes(' ') && !value.includes('T')
                ? value.replace(' ', 'T')
                : value;
            const date = new Date(normalized);

            return Number.isNaN(date.getTime()) ? null : date;
        },

        formatSocialDate(date) {
            const seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));

            if (seconds < 60) {
                return 'just now';
            }

            if (seconds < 3600) {
                return `${Math.floor(seconds / 60)}m ago`;
            }

            if (seconds < 86400) {
                return `${Math.floor(seconds / 3600)}h ago`;
            }

            if (seconds < 172800) {
                return `yesterday at ${this.formatClockTime(date)}`;
            }

            return `${date.toLocaleDateString(undefined, {
                month: 'short',
                day: 'numeric',
            })} at ${this.formatClockTime(date)}`;
        },

        formatClockTime(date) {
            return date.toLocaleTimeString(undefined, {
                hour: 'numeric',
                minute: '2-digit',
            });
        },

        formatDuration(value) {
            const totalSeconds = Math.max(0, Math.floor(Number(value) || 0));
            const hours = Math.floor(totalSeconds / 3600);
            const minutes = Math.floor((totalSeconds % 3600) / 60);
            const seconds = totalSeconds % 60;

            if (hours > 0) {
                return `${hours}h ${String(minutes).padStart(2, '0')}m`;
            }

            if (minutes > 0) {
                return `${minutes}m ${String(seconds).padStart(2, '0')}s`;
            }

            return `${seconds}s`;
        },

        refreshImportMeta(key) {
            const importRun = this.streams[key]?.importRun;
            const lastRun = importRun?.finished_at ?? importRun?.started_at ?? null;

            this.imports = this.imports.map((item) =>
                item.key === key && lastRun ? { ...item, last_run: lastRun } : item
            );
        },

        handlePlayersAvailable(source) {
            if (!source) {
                return;
            }

            const wasNhlAvailable = this.hasPlayers;
            const wasFantraxAvailable = this.hasFantrax;

            if (source === 'nhl') {
                this.hasPlayers = true;
            }

            if (source === 'fantrax') {
                this.hasFantrax = true;
            }

            const tabUnavailable =
                (this.activeTab === 'nhl' && !this.hasPlayers) ||
                (this.activeTab === 'fantrax' && !this.hasFantrax);

            if (tabUnavailable) {
                this.activeTab = 'triage';
                this.activeSource = source;
                this.roster[source].page = 1;
            }

            const isPlayersTab = this.activeTab === 'nhl' || this.activeTab === 'fantrax';

            if (
                isPlayersTab &&
                ((source === 'nhl' && !wasNhlAvailable) ||
                    (source === 'fantrax' && !wasFantraxAvailable) ||
                    tabUnavailable)
            ) {
                if (this.activeSource !== source) {
                    this.activeSource = source;
                    this.roster[source].page = 1;
                }

                this.loadPlayers();
            }
        },
    };
}
