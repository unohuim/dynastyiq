import './bootstrap';
import AlpineImport from 'alpinejs';
import focus from '@alpinejs/focus';

// import { PlayerStatsPage } from './components/PlayerStatsPage/player-stats-page.js';
import { StatsPage } from './components/StatsPage/stats-page.js';
import './leagues-hub.js';
import './community-hub.js';
import './components/community-members-store';
import { registerToastStack } from './components/toast-stack';

// import "./components/RangeSlider/range-slider.css";
// import { RangeSlider } from "./components/RangeSlider/range-slider.js";
// window.RangeSlider = RangeSlider;

// Reuse a pre-loaded Alpine instance (e.g., from a CDN include) to avoid the
// "Detected multiple instances of Alpine running" warning. If none exists,
// fall back to the bundled version.
const Alpine = window.Alpine ?? AlpineImport;

// If Alpine was already started elsewhere (e.g., injected by another script),
// treat it as started so we don't call `Alpine.start()` twice.
if (!window.__alpineStarted && window.Alpine?.version) {
    window.__alpineStarted = true;
}

// Keep the flag in sync if some other script starts Alpine later on.
document.addEventListener(
    'alpine:initialized',
    () => {
        window.__alpineStarted = true;
    },
    { once: true }
);

// Ensure the Focus plugin is installed on whichever instance we end up using.
if (!Alpine.__hasFocusPlugin) {
    Alpine.plugin(focus);
    Alpine.__hasFocusPlugin = true;
}

window.Alpine = Alpine;

registerToastStack(Alpine);

// Only start Alpine once per page load.
if (!window.__alpineStarted) {
    Alpine.start();
    window.__alpineStarted = true;
}

window.adminInitialization = ({ initialized, endpoints }) => ({
    initialized,
    initializing: false,
    batchId: null,
    progress: 0,
    processed: 0,
    total: 0,
    failed: 0,
    pollHandle: null,
    statusLabel: 'Initializing... ',
    bootstrap() {
        this.toggleImports(false);
    },
    async startInitialization() {
        if (this.initializing || this.initialized) {
            return;
        }

        this.statusLabel = 'Starting...';
        this.initializing = true;
        this.toggleImports(true);
        window.dispatchEvent(new CustomEvent('admin:init-started'));

        try {
            const response = await fetch(endpoints.start, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                },
            });

            if (!response.ok) {
                throw new Error('Failed to start initialization.');
            }

            const data = await response.json();

            this.batchId = data.batch_id;
            this.total = data.total_jobs ?? 0;
            this.progress = 0;
            this.processed = 0;
            this.failed = 0;
            this.startPolling();
        } catch (error) {
            console.error(error);
            this.initializing = false;
            this.toggleImports(false);
        }
    },
    startPolling() {
        this.fetchStatus();

        this.stopPolling();
        this.pollHandle = window.setInterval(() => this.fetchStatus(), 2000);
    },
    async fetchStatus() {
        if (!this.batchId) {
            return;
        }

        try {
            const response = await fetch(`${endpoints.status}?batch_id=${this.batchId}`, {
                headers: {
                    Accept: 'application/json',
                },
            });

            if (response.status === 404) {
                this.stopPolling();
                this.initializing = false;
                this.toggleImports(false);
                return;
            }

            if (!response.ok) {
                throw new Error('Failed to load batch status.');
            }

            const data = await response.json();

            this.progress = data.progress ?? this.progress;
            this.processed = data.processed ?? this.processed;
            this.total = data.total ?? this.total;
            this.failed = data.failed ?? this.failed;
            this.statusLabel = data.finished ? 'Finished' : 'Initializing...';

            if (data.finished) {
                this.finish();
            }
        } catch (error) {
            console.error(error);
        }
    },
    finish() {
        this.initializing = false;
        this.initialized = true;
        this.stopPolling();
        this.toggleImports(false);
        window.dispatchEvent(new CustomEvent('admin:init-finished'));
    },
    stopPolling() {
        if (this.pollHandle) {
            clearInterval(this.pollHandle);
            this.pollHandle = null;
        }
    },
    toggleImports(active) {
        window.dispatchEvent(
            new CustomEvent('admin-batch:state', {
                detail: { active },
            })
        );
    },
});

const updateAdminImportButtons = (active) => {
    document.querySelectorAll('[data-admin-import-button]').forEach((button) => {
        button.toggleAttribute('disabled', active);
        button.classList.toggle('opacity-50', active);
        button.classList.toggle('cursor-not-allowed', active);
    });
};

window.addEventListener('admin-batch:state', (event) => {
    updateAdminImportButtons(Boolean(event.detail?.active));
});

updateAdminImportButtons(false);

window.adminHub = (config) => ({
    activeTab: 'players',
    imports: config.imports ?? [],
    initialization: config.initialization ?? { initialized: false, initializing: false },
    routes: config.routes ?? {},
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
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
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
});
