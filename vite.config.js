import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

const devServerHost = process.env.VITE_DEV_SERVER_HOST ?? '0.0.0.0';
const devServerPort = Number(process.env.VITE_DEV_SERVER_PORT ?? 5173);
const hmrHost = process.env.VITE_HMR_HOST ?? 'dynastyiq.test';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/pages/stats-page.js'],
            refresh: true,
        }),
    ],
    server: {
        host: devServerHost,
        port: devServerPort,
        strictPort: true,
        hmr: {
            host: hmrHost,
        },
    },
});
