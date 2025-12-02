import './bootstrap';
import AlpineImport from 'alpinejs';
import focus from '@alpinejs/focus';

// import { PlayerStatsPage } from './components/PlayerStatsPage/player-stats-page.js';
import { StatsPage } from './components/StatsPage/stats-page.js';
import './leagues-hub.js';
import './community-hub.js';
import './components/community-members-store';

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

// Only start Alpine once per page load.
if (!window.__alpineStarted) {
    Alpine.start();
    window.__alpineStarted = true;
}
