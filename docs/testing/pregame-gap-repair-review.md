# Historical Pregame gap repair

## Exact files changed

- `app/Http/Controllers/Admin/NhlSatWorkspaceController.php`
- `app/Services/NhlPregameContextOrchestrator.php`
- `app/Services/NhlPregameLeagueResults.php`
- `app/Services/NhlSatPregameWorkspace.php`
- `routes/web.php`
- `resources/js/pages/Admin/SatModels/Index.vue`
- `resources/js/pages/Admin/SatModels/Index.test.js`
- `tests/Feature/NhlSatWorkspaceTest.php`
- `docs/architecture/admin/NhlSatWorkspace.yaml`
- `docs/architecture/stats/NhlPregameContextEvidence.yaml`
- `docs/ENUMS.md`
- `docs/UI_DESIGN.md`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/testing/pregame-gap-repair-review.md`

## Minimum coverage matrix

Coverage below means authored assertions, not tests observed passing.

| Category | Status | Evidence |
| --- | --- | --- |
| A — validation | COVERED | Existing guest/member dataset extended to repair POST; absent/ambiguous pilot rejects; active-run admission remains enforced. |
| B — normalization/defaults | COVERED | Repair seasons come from pilot Train/Test, caller production identity is ignored, ordinary updates stay Test-only. Source checks distinguish missing schedule and each side's summaries. |
| C — persistence | COVERED | Repair POST → scoped run/creator/options; prepare → durable pending/blocked game rows, skipped/pending day states and blocked counts. Healthy complete game excluded; earlier blocked run preserved on retry. |
| D — response | COVERED | Repair acknowledgement, repair URL, per-game blocker reason/date/ID, skipped-date count, bounded list/truncation and completion coverage refresh. |
| E — HTTP read after action | COVERED | `admits missing Train and Test repair through HTTP and reads its scoped run`; `records precise missing source reasons and reads them through HTTP`: action → DB assertions → partial workspace GET. |
| F — queue contract | COVERED | Existing Prepare job dispatched; blocked-only dates finish accounting without league/context delivery; mixed-date league population selection excludes blocked cutoffs. |
| G — frontend | COVERED | Primary repair endpoint; separate Test-only action; source-blocker rendering and truncation; skipped-date explanation; repair disabled during active maintenance. |

## Numbered requirements

1. SATISFIED — Repair checks missing context across Sep2026 Train and Test. It never substitutes production or accepts a caller-supplied model as authority.
2. SATISFIED — Reuse existing model/version completeness selection, durable work records, checkpoints, queues and active-run guards. Complete games outside selection are untouched; selected incomplete games use the existing consistent game refresh.
3. SATISFIED — Source preflight batches schedule identity/start and completed-game per-team player-summary presence checks. No history calculations or imported fact changes occur during this check.
4. SATISFIED — Missing sources leave explicit per-game blocked records. Entirely blocked dates skip calculations; mixed dates exclude blocked cutoffs. Later repairs reconsider these games after source imports.
5. SATISFIED — Separate regular Test/prediction-season Update from historical repair and confirmed full rebuild.
6. SATISFIED — Source-blocker list is bounded to 100, with truncation marked. Quiet polling and existing progress remain; coverage cache gets a new key at run completion.
7. SATISFIED — No schema/dependency change, prediction equation change, worker configuration change, production data write or repair execution during implementation.

## Endpoint × verb authorization/scope

| Endpoint | Guest | Ordinary member | Authorized super admin | Scope |
| --- | --- | --- | --- | --- |
| POST /admin/nhl-sat-models/pregame/repair | `blocks guests from every workspace endpoint` dataset | `blocks ordinary members from every workspace endpoint` dataset | `admits missing Train and Test repair through HTTP and reads its scoped run` | `repairs the historical pilot even when a caller supplies a production model identity`; `refuses repair when the historical pilot is missing`; `refuses repair when the historical pilot is ambiguous` |
| GET /admin/nhl-sat-models | Same existing guest dataset | Same existing member dataset | `records precise missing source reasons and reads them through HTTP` | Existing `keeps the selected pilot independent of the newest production model`; latest-run blocker query is constrained by the selected pilot's run. |

Global super-admin operation; organization/owner/member ownership scopes are not
applicable. Existing Test update, full build and control routes retain their
authorization and semantics. No partner API change.

## Frontend checklist

- COVERED — Explicit repair action sends the scoped repair request.
- COVERED — Regular Test-only action retains its existing endpoint.
- COVERED — Blocked dates/game IDs/reasons appear only in the existing repair panel.
- COVERED — Opening source details does not dispatch work.
- COVERED — Active maintenance disables repair; existing submission-error toast tests remain.
- COVERED — Skipped-date explanation and bounded-list label.
- Controlled clocks, mocked HTTP/router and no external network.
- No browser rendering, visual comparison, build or test execution performed.

## Per-file test counts

| File | it(...) declarations | Run |
| --- | ---: | --- |
| tests/Feature/NhlSatWorkspaceTest.php | 55 | No |
| resources/js/pages/Admin/SatModels/Index.test.js | 56 | No |

Checks actually run: source/migration inspection, test declaration counts and
`git diff --check`. Tests, CI, lint, builds, imports, queue commands and production
operations were not run. No migration is required.

Human verification commands:

```bash
php artisan test tests/Feature/NhlSatWorkspaceTest.php tests/Feature/NhlModelPregameBuildTest.php
npm run test -- --run resources/js/pages/Admin/SatModels/Index.test.js
npm run build
```

The repair does not import missing source facts or certify every historical
on-ice metric. Source readiness is evaluated at preparation time; sources that
arrive afterward are picked up by a later repair. Latest-run blocked details use
existing work rows. Stage completion for entirely source-blocked dates means
terminally skipped, not calculated, and is explicitly labelled. Coverage still
reports missing context and retains Needs attention until gaps are repaired.

Awaiting human review
