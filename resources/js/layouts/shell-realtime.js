import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/** Keep connection state component-owned without relying on global clients. */
export function connectShellRealtime({ userId, organizationId, csrf, refresh, notify }) {
    const key = import.meta.env.VITE_REVERB_APP_KEY;
    if (!key || !userId) return () => {};
    const client = new Pusher(key, {
        cluster: import.meta.env.VITE_REVERB_APP_CLUSTER ?? 'mt1',
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        channelAuthorization: {
            endpoint: '/broadcasting/auth', transport: 'ajax',
            headers: { 'X-CSRF-TOKEN': csrf },
        },
    });
    const echo = new Echo({ broadcaster: 'reverb', client });
    echo.private(`user.${userId}`)
        .listen('.discord.connected', refresh)
        .listen('.fantrax.draft.pick', event => {
            window.dispatchEvent(new CustomEvent('fantrax:draft-pick', { detail: event }));
            notify({ type: 'success', message: event?.message || 'A draft pick was made.' });
        })
        .listen('.league.logos.synced', event => {
            window.dispatchEvent(new CustomEvent('league:logos-synced', { detail: event }));
            refresh();
        });
    if (organizationId) echo.private(`org.${organizationId}`).listen('.org.settings.updated', refresh);
    return () => echo.disconnect();
}
