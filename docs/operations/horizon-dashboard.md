# Dedicated Horizon dashboard

The registered `App\Providers\HorizonServiceProvider` permits dashboard and
internal Horizon API requests only when the Laravel environment is `production`
and the request host is exactly `horizon-diq.on-forge.com`.

Laravel guests are permitted by `viewHorizon` on that host. No Discord login is
required. All other hosts and environments are denied, including `local`.
An existing Laravel login does not bypass the host/environment boundary.

## Required security boundary

**Nginx Basic Authentication is the primary access control.** It must protect the
entire dedicated site, including `/horizon/api/*` and any alternate ingress to
the same application. Laravel cannot independently verify that Basic Auth is
enabled or that credentials were checked. Checking the Host header is not proof
of authentication: a client can choose that header.

Do not expose an unprotected origin or proxy path that forwards the allowed host
to PHP. Trusted proxies must not accept client-supplied host overrides as trusted
metadata. Keep HTTPS and the existing Nginx credential configuration in place.
No Nginx configuration or credentials are changed by this code.

## Deployment verification

- Deploy the provider with the existing `bootstrap/providers.php` registration.
  No database migration, Redis, supervisor, or worker changes are required.
- The dedicated installation must resolve `APP_ENV=production`, including its
  deployed configuration cache. Use the existing deployment cache-refresh process.
- Without Basic Auth credentials, the public site must return Nginx's `401`.
- With valid Basic Auth credentials and no DIQ session, `/horizon` must load.
- On another DIQ host, `/horizon` must remain denied (or not routed).
- Keep Discord OAuth and main-application authentication unchanged.

Focused regression command (does not start workers or require Redis/database):

```sh
php artisan test tests/Unit/HorizonAuthorizationTest.php
```

These application tests verify Laravel's host/environment authorization. They
cannot verify the deployed Nginx protection; the external checks above remain
required on the dedicated server.
