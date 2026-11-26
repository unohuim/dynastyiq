# Context lag and prediction notifications — review

Implementation is awaiting human review. No automated tests, lint, build, migration,
queue/scheduler commands, production changes or live notification requests were run.
Source review, declaration counts and `git diff --check` were performed.

## Files changed or created

- `app/Http/Controllers/Admin/WebhookController.php`
- `app/Jobs/DeliverPredictionWebhookJob.php`
- `app/Models/WebhookDelivery.php`
- `app/Models/WebhookSubscription.php`
- `app/Services/NhlGamePredictionHistory.php`
- `app/Services/NhlGamePredictionPayload.php`
- `app/Services/PredictionWebhookDelivery.php`
- `database/migrations/2026_11_26_000001_create_webhook_deliveries_table.php`
- `routes/console.php`
- `resources/js/pages/Admin/Webhooks.vue`
- `resources/js/pages/Admin/Webhooks.test.js`
- `tests/Feature/NhlDataFirstPredictionContextTest.php`
- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`
- `tests/Feature/PredictionWebhookDeliveryTest.php`
- `docs/architecture/integrations/PredictionWebhookDelivery.yaml`
- `docs/architecture/integrations/WebhookSubscriptions.yaml`
- `docs/architecture/stats/NhlGamePredictionHistory.yaml`
- `docs/architecture/stats/NhlGamePredictionResponseCache.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/DB_SCHEMA.md`
- `docs/ENUMS.md`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/testing/prediction-webhooks-context-lag-review.md`

## Minimum coverage matrix

COVERED means authored coverage, not an executed passing result.

| Category | Coverage | Evidence |
| --- | --- | --- |
| A: validation | COVERED | Existing AdminWebhooksTest URL/event/client validation; delivery tests reject private/mixed DNS and redirects |
| B: normalization | COVERED | Context lag counts completed games, max team gap, zero future calendar lag; Discord-native transport and signing serialization |
| C: persistence | COVERED | Durable outbox uniqueness, encrypted destination, retry/terminal state; lag/confidence revision storage |
| D: API contract | COVERED | Publication then saved API GET verifies corrected lag and unchanged revision; saved reads do not send |
| E: HTTP read | COVERED | Webhook POST → revision publication → delivery → DB assertion → admin GET checks safe latest delivery metadata |
| F: async contract | COVERED | After-commit dispatch on projections, duplicate jobs, delayed retry and abandoned-claim recovery |
| G: frontend | COVERED | Desktop/mobile delivery text, safe failure, no prior delivery, migration notice, silent polling/unmount |

## Requirements checklist

1. SATISFIED in source/coverage: returning-player age cannot inflate shared context lag.
2. SATISFIED in source/coverage: only same-model/season/version source cutoffs before the frozen cutoff establish coverage.
3. SATISFIED in source/coverage: completed games determine lag; maximum team gap is used; future calendar days alone add nothing.
4. SATISFIED in source/coverage: original revisions remain immutable; scoring, probability and internal confidence equations are unchanged.
5. SATISFIED in source/coverage: new successful canonical operational revisions atomically stage subscriber delivery; saved reads/overrides/unavailable predictions do not.
6. SATISFIED in source/coverage: Discord embeds and signed JSON use public fields; destinations are checked at delivery; request secrets stay private.
7. SATISFIED in source/coverage: bounded retries, stable identity and abandoned-claim recovery are independent of prediction execution.
8. SATISFIED in source/coverage: existing subscription UI reports latest delivery without resetting the page/editor.
9. SATISFIED in documentation: migration and deployment prerequisites are explicit; no historical backfill is automatic.

## Endpoint × verb authorization/scope

No new public route or permission is introduced. Subscription inventory is global,
so organization/tenant ownership is not applicable. All session endpoints retain
verified super-admin middleware. The existing tests below remain unchanged.

| Endpoint / verb | Guest | Ordinary member | Super admin |
| --- | --- | --- | --- |
| GET /admin/webhooks | blocks guests on every inventory endpoint | blocks ordinary members on every inventory endpoint | serves the Vue inventory with empty data to super admins; shows actual delivery status through the existing admin inventory without request secrets |
| POST /admin/webhooks | blocks guests on every inventory endpoint | blocks ordinary members on every inventory endpoint | creates inventory and exposes the same persisted configuration through an Inertia read |
| PUT /admin/webhooks/{id} | blocks guests on every inventory endpoint | blocks ordinary members on every inventory endpoint | updates configuration and retains its existing secret |
| PATCH /admin/webhooks/{id}/status | blocks guests on every inventory endpoint | blocks ordinary members on every inventory endpoint | enables configuration without changing its name endpoint events or secret; disables an enabled subscription |
| GET /admin/webhooks/{id}/secret | blocks guests on every inventory endpoint | blocks ordinary members on every inventory endpoint | reveals a persisted secret only through the protected no-store endpoint |
| DELETE /admin/webhooks/{id} | blocks guests on every inventory endpoint | blocks ordinary members on every inventory endpoint | deletes a subscription and removes it from the inventory |

For GET `/api/nhl-game-predictions`, existing tests `retains authentication and
scope restrictions for saved past predictions` and `requires the NHL statistics
scope for new engine predictions` cover API restrictions. New coverage
`does not restage or resend when a saved prediction is read` verifies the authorized
saved path. Outbound eligibility is tested by `does not stage revoked API clients`,
`does not stage clients without NHL statistics scope`, and the disabled/revoked
before-delivery tests. API tokens cannot administer subscription session routes.

## Frontend component checklist

- COVERED: latest delivery appears on desktop and mobile using explicit safe props.
- COVERED: pending/failed/absent delivery state leaves subscription controls usable.
- COVERED: migration notice does not hide inventory.
- COVERED: timer-controlled quiet partial reload preserves state and scroll; unmount stops polling.
- COVERED: existing CRUD/toast/editor/filter and secret handling tests remain in place.
- Deterministic fixtures use fake HTTP, mocked router, fixed clocks/timers and fake queues; no provider calls.

## Per-file test count

| File | Authored it(...) declarations | Execution |
| --- | ---: | --- |
| tests/Feature/NhlDataFirstPredictionContextTest.php | 37 | Not run |
| tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php | 135 | Not run |
| tests/Feature/PredictionWebhookDeliveryTest.php | 30 | Not run |
| resources/js/pages/Admin/Webhooks.test.js | 32 | Not run |
| tests/Feature/AdminWebhooksTest.php (unchanged compatibility coverage) | 30 | Not run |

Human verification commands:

```bash
php artisan test tests/Feature/NhlDataFirstPredictionContextTest.php tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php tests/Feature/PredictionWebhookDeliveryTest.php tests/Feature/AdminWebhooksTest.php
npm run test -- --run resources/js/pages/Admin/Webhooks.test.js
```

## Deployment handoff

**Migration required:** `2026_11_26_000001_create_webhook_deliveries_table.php`.
Before relying on notifications, the human must run:

```bash
php artisan migrate --force
```

Existing projections workers and the application scheduler process delivery and
recovery; use the normal deployment procedure to load the new code. Notification
storage missing during rollout does not prevent saved predictions or inventory
access. New successful revisions after migration create deliveries; previously
saved Sunday revisions and their old lag values are not rewritten or notified.
A fresh prediction is needed to observe corrected metadata and a new notification.
No production rerun or test message was authorized/performed by this change.

HTTP delivery is at least once. Stable event IDs allow generic receivers to
ignore repeats; an uncertain network response can still duplicate a Discord
message. Remote rejection and delivery failure preserve the saved forecast.
