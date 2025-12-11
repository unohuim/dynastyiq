import './bootstrap';
import AlpineImport from 'alpinejs';
import focus from '@alpinejs/focus';

// import { PlayerStatsPage } from './components/PlayerStatsPage/player-stats-page.js';
import { StatsPage } from './components/StatsPage/stats-page.js';
import './leagues-hub.js';
import './community-hub.js';
import './components/community-members-store';
import { registerToastStack } from './components/toast-stack';
import adminHub from './admin/admin-hub';
window.adminHub = adminHub;


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

window.adminHub = adminHub;
