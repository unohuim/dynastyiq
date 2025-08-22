// resources/js/echo.js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';     // ✅ required for Echo (Reverb uses Pusher protocol)
window.Pusher = Pusher;

window.Echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY,
  wsHost: import.meta.env.VITE_REVERB_HOST,
  wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
  wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
  forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
  enabledTransports: ['ws','wss'],
  authEndpoint: '/broadcasting/auth',
  withCredentials: true,
  auth: {
    headers: {
      'X-CSRF-TOKEN': document
        .querySelector('meta[name="csrf-token"]')
        .getAttribute('content'),
    },
  },
});

if (window.DIQ?.userId) {
  window.DIQ.userChannel = window.Echo.private(`user.${window.DIQ.userId}`);

  window.DIQ.userChannel.listen('.discord.connected', (e) => {
    console.log('Discord connected:', e);
    window.dispatchEvent(new CustomEvent('discord:connected', { detail: e }));
  });
}
