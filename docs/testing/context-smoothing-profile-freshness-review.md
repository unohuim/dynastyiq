# Context smoothing and profile freshness review

## Files changed or created

- `app/Services/NhlExpectedGoalsBackfiller.php`
- `app/Services/NhlSatModelEntityProfileBuilder.php`
- `app/Models/NhlModelRun.php`
- `app/Jobs/BuildNhlSatModelEntityProfilesJob.php`
- `app/Jobs/BuildNhlSatModelEntityProfileForEntityJob.php`
- `app/Http/Controllers/Admin/NhlModelRunController.php`
- `resources/views/admin/nhl-sat-models/_model-row.blade.php`
- `docs/architecture/stats/NhlExpectedGoalsModel.yaml`
- `docs/architecture/stats/NhlModelRuns.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/ENUMS.md`
- `tests/Unit/NhlContextBucketSmoothingTest.php`
- `tests/Feature/NhlStrengthProbabilityTrainingTest.php`
- `tests/Feature/NhlBuildPredictionsTest.php`
- `docs/testing/context-smoothing-profile-freshness-review.md`

## Minimum coverage matrix

COVERED below describes authored assertions, not executed test results.

| Category | Coverage | Evidence |
|---|---|---|
| A: validation | COVERED | Existing duplicate/running-work guards; profile and rate authorization tests |
| B: normalization/defaults | COVERED | Context parent selection, absent preferred dimension, absent parent evidence, zero smoothing, optional SOG source |
| C: persistence | COVERED | Stable signature, same-second signature replacement, profile probability provenance, per-type invalidation and successful rebuild |
| D: API contract | COVERED | Existing admin JSON action responses; no public/partner API changes |
| E: HTTP read contract | COVERED | Evaluation POST → retained profiles and outdated DB flags → model index GET shows outdated types; existing POST → bucket GET and profile-build POST → model index GET |
| F: queue/event contract | COVERED | Frozen parent sources, changed-source child rejection, completion rejection, existing batch/replay/cancellation/generation tests |
| G: frontend | COVERED (not applicable) | Static Blade label only; no JavaScript state or transitions introduced |

## Requirements checklist

1. SAT smoothing borrows same-strength shot-type evidence: SATISFIED by unit parent and strength-isolation tests.
2. SOG smoothing borrows same-strength distance evidence: SATISFIED by distance-parent and cross-distance tests.
3. Only disjoint detailed samples form parents: SATISFIED by fallback-overlap and baseline tests.
4. Keep actual outcomes, prior count, confidence, shrinkage and eligibility: SATISFIED by unit invariants and existing feature denominator/exclusion tests.
5. Legacy non-run behavior remains unchanged: SATISFIED by legacy smoothing unit test and historical backfill feature coverage.
6. Profiles identify evaluation revisions: SATISFIED by real profile metadata assertions and optional-SOG source test.
7. Reevaluation marks existing profile types outdated without deletion: SATISFIED by POST → DB → index regression.
8. Only a successful selected-type rebuild clears outdated state: SATISFIED by selected-type success, failure, legacy and source-change regressions.
9. Evaluation metadata changes survive controller completion: SATISFIED by HTTP invalidation regression.
10. No PK goal engine change, no migration, no production writes: SATISFIED by scoped diff review.

## Endpoint × verb × auth/scope checklist

Names below are exact test declarations in the two feature suites.

| Endpoint / verb | Guest | Ordinary user | Super admin |
|---|---|---|---|
| `admin.nhl-sat-models.train` POST | blocks guests from evaluating probability buckets | blocks unprivileged users from evaluating probability buckets | allows an admin to evaluate SAT then read strength rows; allows an admin to evaluate SOG then read strength rows |
| `admin.nhl-sat-models.buckets` GET | blocks guests from reading admin probability buckets | blocks unprivileged users from reading admin probability buckets | allows an admin to evaluate SAT then read strength rows |
| `admin.nhl-sat-models.index` GET | blocks guests from viewing prediction build progress | blocks ordinary users from viewing prediction build progress | marks existing profiles outdated after evaluation and exposes that through HTTP |
| `admin.nhl-sat-models.profiles.build` POST | blocks guests from standalone profile builds | blocks ordinary users from standalone profile builds | starts standalone profiles with a build identity and visible progress; queues goalie SAT profiles independently from offensive skater profiles |
| `admin.nhl-sat-models.rate-projections.build` POST | blocks guests from rate builds | blocks ordinary users from rate builds | accepts rates after verified profile and snapshot completion |
| `admin.nhl-sat-models.predictions.build` POST | blocks guests from starting prediction builds | blocks ordinary users from starting prediction builds | starts profiles on the same model and exposes the running stage through HTTP |

Organization/owner/member scope: not applicable to these existing global super-admin routes. Authorization and route names are unchanged.

## Frontend checklist

- Static outdated-type text follows existing model-row styling.
- No new controls, global state, polling, animation or JavaScript component.
- HTTP action-driven index assertion covers the new label.

## Per-file test declaration counts

| File | `it(...)` declarations |
|---|---:|
| `tests/Unit/NhlContextBucketSmoothingTest.php` | 21 |
| `tests/Feature/NhlStrengthProbabilityTrainingTest.php` | 48 |
| `tests/Feature/NhlBuildPredictionsTest.php` | 87 |

Each modified/new test file exceeds the repository minimum of 20. Unit fixtures are pure arrays; feature fixtures freeze the clock, prevent stray HTTP, and fake queue/broadcast work except existing explicitly controlled batch tests.

## Checks actually run

- Read and reviewed the scoped source/test/documentation diff.
- `git diff --check`: passed.
- Test declaration counts inspected with `rg`.
- No PHP tests, lint, CI, migrations, imports, queue commands, or production operations run.

Human-run focused regression command (not executed by Codex):

```sh
php artisan test tests/Unit/NhlContextBucketSmoothingTest.php tests/Feature/NhlStrengthProbabilityTrainingTest.php tests/Feature/NhlBuildPredictionsTest.php
```

The profile/scoring integration assertions require the project's PostgreSQL test configuration. No winner-accuracy improvement is claimed by this probability-smoothing change without a separate approved season comparison.

## Applying the revised probabilities

No migration is required. After deployment, explicitly run Evaluate SAT, then Evaluate SOG on the desired model. Rebuild Profiles → Build Offensive Skaters and Profiles → Build Goalies to replace their stored probabilities and record current evaluation provenance. Existing profile outputs are retained and labeled outdated when reevaluated; no rebuild is silently started. Downstream stored outputs remain subject to their existing explicit rebuild workflow. PK goal prediction design remains a separate step.
