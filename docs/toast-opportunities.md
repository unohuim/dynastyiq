# Toast notification adoption opportunities

This document highlights spots in the app that already flash user-visible status messages, fire `alert()` dialogs, or perform silent background updates. They are good candidates to wire up to the new toast notification system so users get consistent feedback.

## Flash redirects and status banners
- **Discord server connect/attach flow** – errors such as failed authorization, invalid state, missing organization, or insufficient permissions, plus the final "server(s) connected" success message are flashed before redirecting back to communities. Surface each outcome with toasts when users return to the page.【F:app/Http/Controllers/Auth/DiscordServerCallbackController.php†L18-L148】
- **Fantrax integration connect/disconnect** – saving a secret key or disconnecting currently sets a `status` flash for the previous page; translate these flashes into toasts so users see immediate confirmation after the redirect.【F:app/Http/Controllers/FantraxUserController.php†L25-L88】
- **Patreon connect/disconnect** – both flows redirect to the communities index with `status` messages that should become toasts for clearer feedback about the organization’s Patreon link state.【F:app/Http/Controllers/PatreonConnectController.php†L106-L125】
- **Player ranking updates** – the ranking update path flashes a `success` message; show this as a toast near the ranking UI so users know their change stuck without scanning the page for banners.【F:app/Http/Controllers/PlayerRankingController.php†L93-L108】

## Auth screens still using inline banners
- **Login and password reset** – the login and forgot-password pages render inline `@session('status')` blocks; swap these for toasts so transient auth notices stay consistent with the rest of the app.【F:resources/views/auth/login.blade.php†L7-L45】【F:resources/views/auth/forgot-password.blade.php†L7-L32】
- **Email verification resend** – the verification view shows a green banner when a link is sent; emitting a toast here would align it with other flash feedback and keep the layout tidy.【F:resources/views/auth/verify-email.blade.php†L7-L44】
- **Legacy banner component** – the `resources/views/components/banner.blade.php` component listens for `banner-message` events and reads `flash.banner*` session data. Consider migrating these use cases to the toast stack or bridging the banner events into toast events to centralize transient messaging.【F:resources/views/components/banner.blade.php†L1-L48】

## Silent or alert-based community management flows
- **Community name updates** – the desktop community hub saves name changes via `fetch` and only shows a blocking `alert` on failure; add success/error toasts so users see immediate feedback without modals.【F:resources/views/communities/_desktop.blade.php†L128-L209】
- **League creation from the community hub** – creating a league via the desktop partial currently uses several `alert()` calls for validation errors or server failures and reloads silently on success; replace alerts with toasts (including success) to keep feedback consistent.【F:resources/views/communities/_desktop-leagues.blade.php†L126-L206】
- **League connections within the league detail view** – the Fantrax connection modal validates inputs with `alert()` and reloads without a success message; toast success/failure to avoid disruptive browser alerts.【F:resources/views/communities/leagues/show.blade.php†L150-L210】
- **Discord server selection for a league** – changing a league’s Discord server also relies on `alert()` for errors and otherwise reloads silently; emit toast confirmations for both success and failure paths.【F:resources/views/communities/leagues/show.blade.php†L213-L259】

## Silent preference updates
- **Notification preferences drawer** – toggling Discord DM/private-channel responses saves via `fetch` without visible success/error cues. Triggering toasts after saves (and on failures) would reassure users their preferences were stored.【F:resources/views/nav/partials/_right-account-drawer-notifications.blade.php†L26-L146】
