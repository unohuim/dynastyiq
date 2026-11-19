# On-ice rebuild discovery readiness review

## Files changed

- `resources/js/admin/admin-hub.js`: derive discovery readiness and processing
  eligibility from stored lifecycle status; guard on-ice clicks by the existing
  terminal source/action rules and local request busy state.
- `resources/js/pages/Admin/Dashboard.vue`: gate the on-ice menu item with that
  eligibility check.
- `resources/js/admin/admin-hub.test.js`: add readiness, transition and click
  regressions; give existing completed-discovery fixtures explicit lifecycle data.
- `tests/Feature/NhlGameImportRunControlTest.php`: cover source status rejection and
  terminal-source publication through HTTP, database assertions and status reads.
- `docs/architecture/imports/NhlDiscoveryPipeline.yaml`: canonical readiness rule.
- `docs/ARCHITECTURE_INVENTORY.md`: derived discovery summary.
- `docs/analysis/on-ice-discovery-readiness-review.md`: this record.

A discovery with queued/running persisted status stays DISCOVERING, regardless of
how many games/stages have already been found. Completed discoveries with stage
rows show READY; an empty completed discovery shows COMPLETED. Lifecycle control
and failure labels retain priority. Existing Process-menu controls wait for a
completed or failed source. The on-ice-specific guard uses the backend's existing
completed/failed/cancelled discovery/process rule; it adds no new cancelled-run
menu or active-import bypass. The backend remains authoritative if state changes
between the displayed snapshot and a request.

No backend admission, job, queue, schema, worker, source data, lifecycle mutation,
processing algorithm or prediction changes. This change does not restart a
running discovery or force it to completion.

## Minimum coverage matrix

COVERED means authored coverage, not executed/passing tests.

| Requirement | Status | Test names |
| --- | --- | --- |
| Partial discovery never falsely READY | COVERED | keeps %s discoveries discovering even after games and stages are found |
| Stored lifecycle wins over inferred stage completion | COVERED | uses persisted lifecycle status rather than inferred completed coverage for readiness |
| Real completion transition enables actions | COVERED | makes a completed discovery ready and permits processing only after its authoritative update |
| Empty completion has truthful label and styling | COVERED | labels a completed empty discovery completed rather than discovering forever |
| Controls and failed source behavior remain intact | COVERED | preserves the %s discovery label over staged work; retains the failed label and permits an eligible failed discovery to be retried |
| Recheck local source eligibility at click | COVERED | blocks an on-ice click when the source becomes %s |
| Terminal action/status parity, unrelated source types denied | COVERED | recognizes terminal %s sources under the existing backend rules; does not offer an on-ice rebuild for %s sources |
| Missing identity/status fails closed | COVERED | fails closed when source identity or lifecycle status is absent |
| Busy scope and duplicate clicks | COVERED | prevents an on-ice submission while another processing scope is being queued for that row; prevents duplicate clicks while the selected rebuild is being queued |
| Vue binding and request feedback | COVERED | renders the targeted action in the discovery Process menu; acknowledges the accepted rebuild before refreshing the run list; reports request errors and releases the selected button |
| Server rejects active sources and dispatches no rebuild | COVERED | rejects active source discoveries even when their scheduled games make them appear ready |
| Accepted request → DB → HTTP read, original source preserved | COVERED | preserves terminal source runs when creating a separately readable on-ice rebuild |
| Existing overlapping rebuild and active game locks retained | COVERED | does not admit a duplicate active on-ice selection; rejects games currently running through the canonical import pipeline |
| Existing authorization | COVERED | denies guests the on-ice processing endpoint; denies non-admins the on-ice processing endpoint |

## Numbered requirements checklist

1. SATISFIED in code and authored coverage: READY requires persisted completion.
2. SATISFIED: discovery processing controls wait for eligible persisted status.
3. SATISFIED: on-ice visibility and click handler share the terminal-source guard.
4. SATISFIED: paused/cancelling/active protections and backend validation retained.
5. SATISFIED: frontend state transitions, DOM binding, request contract and backend
   status matrix covered deterministically.
6. SATISFIED: no production operations or migration required.
7. Validation pending human execution: tests/CI/build commands were not authorized.

## Endpoint × verb authorization/scope checklist

| Endpoint / verb | Access | Test name |
| --- | --- | --- |
| POST `/admin/nhl-game-imports/process-on-ice` | Guest 401 | denies guests the on-ice processing endpoint |
| POST `/admin/nhl-game-imports/process-on-ice` | Non-admin 403 | denies non-admins the on-ice processing endpoint |
| POST `/admin/nhl-game-imports/process-on-ice` | Super-admin, active source 422 | rejects active source discoveries even when their scheduled games make them appear ready |
| POST `/admin/nhl-game-imports/process-on-ice` | Super-admin, eligible source 202 | preserves terminal source runs when creating a separately readable on-ice rebuild |
| GET `/admin/nhl-game-imports/status` | Super-admin reads source and new scoped run | preserves terminal source runs when creating a separately readable on-ice rebuild |

This is global NHL administration; no organization/owner scope is introduced.
Existing endpoint permissions and validation are unchanged.

## Frontend component checklist

- COVERED: discovering → completed transition and action eligibility.
- COVERED: controlled/failed states, empty discovery and stored-status precedence.
- COVERED: Vue action visibility, exact request URL/run ID and scoped feedback.
- COVERED: busy/repeated clicks and response-error release.
- COVERED: deterministic module instances and mocked requests; no new global state.
- No new layout, animation, overlay, polling or loading region is introduced.

## Per-file test counts and checks

- `resources/js/admin/admin-hub.test.js`: 149 test declarations (143 direct
  `it(...)` plus six `it.each(...)`), including 11 new parameterized/direct
  declarations. The prior file contained 138 declarations.
- `tests/Feature/NhlGameImportRunControlTest.php`: 75 `it(...)` declarations,
  including two new parameterized tests covering five active statuses and six
  terminal action/status combinations. The prior file contained 73 declarations.
- Full cohesive test files remain in the repository for review.

Actually performed: source review, test-count inventory, `git diff --check`.
Not performed: automated tests, CI, lint, builds, migrations, imports, queue or
scheduler commands, production operations or discovery/rebuild reruns.
No migration required.

Human validation commands, not executed by Codex:

```sh
npm test -- --run resources/js/admin/admin-hub.test.js
php artisan test tests/Feature/NhlGameImportRunControlTest.php
```
