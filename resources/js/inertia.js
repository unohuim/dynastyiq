import axios from 'axios';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import AppLayout from './layouts/AppLayout.vue';
import './analytics-tracker';

// A separate entry point keeps legacy Alpine/Livewire bootstraps off Inertia pages.
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
const pages = import.meta.glob('./pages/**/*.vue');

createInertiaApp({
    async resolve(name) {
        const load = pages[`./pages/${name}.vue`];
        if (!load) throw new Error(`Inertia page not found: ${name}`);
        const page = (await load()).default;
        page.layout ??= AppLayout;
        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
});
