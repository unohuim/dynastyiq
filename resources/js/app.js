import './bootstrap';
import AlpineImport from 'alpinejs';
import focus from '@alpinejs/focus';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

import './leagues-hub.js';
import './community-hub.js';
import './transactions.js';
import './analytics-tracker.js';
import './pages/stats-units.js';
import './admin/player-triage.js';
import './admin/nhl-shot-attempts.js';
import './admin/nhl-sat-models.js';
import './components/community-members-store';
import './components/draft-round-scrollbar';
import './pages/discord-bot-installed';
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

const inertiaRoot = document.getElementById('app');

if (inertiaRoot) {
    createInertiaApp({
        resolve: (name) => {
            const pages = import.meta.glob('./pages/**/*.vue', { eager: true });

            const page = pages[`./pages/${name}.vue`];
            if (!page) {
                throw new Error(`Inertia page not found: ${name}`);
            }

            return page.default;
        },
        setup({ el, App, props, plugin }) {
            createApp({ render: () => h(App, props) })
                .use(plugin)
                .mount(el);
        },
    });
}

import('./pages/stats-page.js');
