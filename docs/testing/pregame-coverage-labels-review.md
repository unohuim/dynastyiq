# Pregame coverage diagnosis labels

## Exact files changed

- `app/Services/NhlSatPregameWorkspace.php`
- `resources/js/pages/Admin/SatModels/Index.vue`
- `tests/Feature/NhlSatWorkspaceTest.php`
- `resources/js/pages/Admin/SatModels/Index.test.js`
- `docs/architecture/admin/NhlSatWorkspace.yaml`
- `docs/ENUMS.md`
- `docs/UI_DESIGN.md`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/testing/pregame-coverage-labels-review.md`

## Minimum coverage matrix

Authored assertions only; tests have not been executed.

| Category | Status | Evidence |
| --- | --- | --- |
| A — validation | COVERED | Existing guest/member endpoint datasets and absent/ambiguous pilot tests remain. No new request fields or route. |
| B — normalization/defaults | COVERED | Model-defined Train/Test role, formatted season, missing count, overlapping membership and generic no-gap repair caption. |
| C — persistence | COVERED | Existing repair POST persists scoped run; new action-driven test prepares missing Train game and asserts its durable row. No storage change. |
| D — response contract | COVERED | Coverage scope/season label/missing count and explicit issue title; existing cached counts gain metadata without waiting for expiry. |
| E — HTTP after action | COVERED | `identifies missing Train context by role season and count after a repair action`: POST repair → DB assertion → partial GET scoped coverage and issue title. |
| F — queue contract | COVERED | Existing repair admission/dispatch tests remain; new metadata and issue reads dispatch nothing. Processing unchanged. |
| G — frontend | COVERED | Health diagnosis, covered Test distinction, scope-specific repair labels, all-season drawer, overlap counting and quiet refresh transitions. |

## Numbered requirements

1. SATISFIED — Missing coverage names Train or Test, the affected season and missing-game count.
2. SATISFIED — The warning appears beside Overall Health and in issue titles; covered Test seasons are shown independently.
3. SATISFIED — The repair drawer lists each Train/Test season and its observed team coverage.
4. SATISFIED — Repair captions summarize affected scopes/counts, including single games and overlapping membership; no-gap captions remain generic.
5. SATISFIED — Existing repair selection, source preflight, worker behavior, authorization and production prediction sources remain unchanged. Team coverage does not certify player/metric completeness.

## Endpoint × verb authorization/scope

| Endpoint | Guest | Ordinary member | Super admin | Scope |
| --- | --- | --- | --- | --- |
| GET /admin/nhl-sat-models | `blocks guests from every workspace endpoint` | `blocks ordinary members from every workspace endpoint` | `identifies missing Train context by role season and count after a repair action` | `keeps the selected pilot independent of the newest production model`; new scope metadata uses only that pilot's Train/Test membership. |
| POST /admin/nhl-sat-models/pregame/repair | Same existing guest dataset | Same existing member dataset | Existing `admits missing Train and Test repair through HTTP and reads its scoped run` | Existing `repairs the historical pilot even when a caller supplies a production model identity`. |

Organization/member ownership is not applicable to this global super-admin
workspace. No API or authorization change.

## Frontend checklist

- COVERED — Affected Train season next to health; covered Test shown separately.
- COVERED — Train-only, Test-only and combined repair captions.
- COVERED — Drawer lists complete and incomplete seasons without dispatching work.
- COVERED — Quiet updated props reconcile captions while retaining health DOM.
- COVERED — Overlapping membership counts one missing game once.
- Controlled timers/clock, mocked HTTP/router, no external requests.

## Per-file test counts

| File | it(...) declarations | Executed |
| --- | ---: | --- |
| tests/Feature/NhlSatWorkspaceTest.php | 59 | No |
| resources/js/pages/Admin/SatModels/Index.test.js | 62 | No |

Checks performed: source review, declaration counts and `git diff --check`.
Tests, CI, lint, asset builds, browser checks and production operations were not
run. No migration required.

Suggested human checks:

```bash
php artisan test tests/Feature/NhlSatWorkspaceTest.php
npm run test -- --run resources/js/pages/Admin/SatModels/Index.test.js
npm run build
```

Awaiting human review
