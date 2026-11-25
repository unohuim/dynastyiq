# Webhook inventory review

Approved scope: Vue/Inertia/Tailwind inventory at `/admin/webhooks` and persisted
subscription configuration. No notification delivery, prediction event wiring,
test deliveries, delivery attempts or retries. Menu label is Webhooks directly
after Admin Faceoffs in the admin account menu.

## Files

- `database/migrations/2026_11_25_000001_create_webhook_subscriptions_table.php`
- `app/Models/WebhookSubscription.php`
- `app/Http/Controllers/Admin/WebhookController.php`
- `routes/web.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `resources/js/layouts/AppLayout.vue`
- `resources/js/pages/Admin/Webhooks.vue`
- `resources/views/nav/partials/_right-account-drawer.blade.php` (navigation link only)
- `tests/Feature/AdminWebhooksTest.php`
- `resources/js/pages/Admin/Webhooks.test.js`
- `docs/architecture/integrations/WebhookSubscriptions.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/ENUMS.md`
- `docs/DB_SCHEMA.md`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- This review.

## Minimum coverage matrix

Coverage status refers to authored assertions; none of the suites were executed.

| Category | Status | Evidence |
| --- | --- | --- |
| A: Validation | COVERED | Name width, client existence, HTTPS, credential/fragment rejection, malformed URL types, supported unique events and booleans. |
| B: Defaults/normalization | COVERED | Disabled new-form default, supported selected event, server-generated secret and event labels. |
| C: Persistence | COVERED | CRUD, separate enabled switch, encrypted secret, immutable secret across edits, validation rollback and client reassignment. |
| D: Response contract | COVERED | Safe mutation fields, no secret/token in inventory, explicit no-store reveal and missing identities. |
| E: HTTP read contract | COVERED | POST → DB assertion → Inertia GET for the same subscription; edit/status/delete also followed by inventory reads. |
| F: Side effects | COVERED | Every backend case prohibits external HTTP and asserts no jobs dispatched. There is no notification producer. |
| G: Frontend | COVERED | Responsive renderers, local filters/search, create/edit/cancel, pending guards, validation failures, switches, secret reveal/hide/copy and confirmed deletion. |

## Requirements

1. SATISFIED in authored coverage: inventory is Vue/Inertia-owned under AppLayout,
   with desktop table and mobile cards showing the same configuration.
2. SATISFIED in authored coverage: CRUD, enabled setting, filters and subscriber
   selection persist through protected admin endpoints.
3. SATISFIED in authored coverage: existing super-admin access governs every
   operation, including secret reads; global configuration is not tenant-scoped.
4. SATISFIED in authored coverage: secrets are encrypted and server-generated;
   initial/mutation payloads and API-client options omit credentials.
5. SATISFIED in authored coverage: reveal is explicit and no-store; UI clears its
   revealed values when the editor closes or saves, and copy requires a user action.
6. SATISFIED in authored coverage: failed writes retain displayed inventory and
   explicit actions use existing shell toast feedback.
7. SATISFIED in authored coverage: configuration mutations do not contact the URL,
   dispatch work or fabricate delivery history.
8. SATISFIED by source review: menu placement is present in backend-owned Inertia
   navigation and the existing legacy menu link. The new page contains no Blade UI.

## Endpoint × verb authorization/scope

The tests `blocks guests on every inventory endpoint` and `blocks ordinary members
on every inventory endpoint` each cover all six routes below by dataset.

| Endpoint | Authorized super-admin test |
| --- | --- |
| GET `/admin/webhooks` | serves the Vue inventory with empty data to super admins |
| POST `/admin/webhooks` | creates inventory and exposes the same persisted configuration through an Inertia read |
| PUT `/admin/webhooks/{webhook}` | updates configuration and retains its existing secret |
| PATCH `/admin/webhooks/{webhook}/status` | enables configuration without changing its name endpoint events or secret |
| GET `/admin/webhooks/{webhook}/secret` | reveals a persisted secret only through the protected no-store endpoint |
| DELETE `/admin/webhooks/{webhook}` | deletes a subscription and removes it from the inventory |

Organization/owner restrictions are not applicable: these are explicitly global
super-admin resources. `allows a super admin to reassign a subscription to another
client` covers cross-client administration. Existing session, verification and
lifecycle middleware remain unchanged.

## Frontend checklist

- COVERED: state changes, pending duplicate-write prevention and persisted payloads.
- COVERED: responsive table/card renderers and empty/filter/no-client states.
- COVERED: secret reveal requires explicit request; copy failures report an error.
- COVERED: deletion needs confirmation and failed writes preserve visible data.
- COVERED: deterministic fake HTTP, mounted-app disposal and clipboard restoration.
- Source reviewed: existing Reka dialogs supply focus management; transitions use
  Tailwind utilities with reduced-motion support. No new application-global state.
- Not measured: browser visual comparison, viewport screenshots and performance.

## Test counts and execution

- `tests/Feature/AdminWebhooksTest.php`: **30 `it(...)` declarations**, with
  additional dataset expansions for endpoint authorization and URL validation.
- `resources/js/pages/Admin/Webhooks.test.js`: **27 `it(...)` declarations**.

Tests, CI, lint, builds, migrations and operational commands were **not run** under
the repository policy. Verification: source review and `git diff --check`.

Suggested human-run checks:

```sh
php artisan test tests/Feature/AdminWebhooksTest.php
npm test -- --run resources/js/pages/Admin/Webhooks.test.js
```

## Migration required

Before using the page, apply
`2026_11_25_000001_create_webhook_subscriptions_table.php`:

```sh
php artisan migrate --force
```

No migration, production data changes, worker operations or notification sends
were performed by this change.
