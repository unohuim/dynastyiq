# Unknown shot types in SAT modeling

Unknown, null, and blank shot types now remain eligible through the existing evaluation and prediction pipeline. Existing factor selection, smoothing, bucket matching, and projection formulas are reused. The outcome still comes from `is_shot_on_goal` and `is_goal`; an unknown label does not turn a goal into a blocked attempt. Empty-net and shootout exclusions remain. Eval SOG requires an actual SOG and retains its separate `other` exclusion.

## Files changed

- `app/Services/NhlExpectedGoalsBackfiller.php`
- `app/Services/NhlShotAttemptModelScorer.php`
- `app/Services/NhlSatModelEntityProfileBuilder.php`
- `app/Services/NhlSatModelEntityRateProjectionBuilder.php`
- `app/Services/NhlSatModelEntityRateComparisonBuilder.php`
- `app/Http/Controllers/Admin/NhlModelRunController.php`
- `app/Http/Controllers/Admin/NhlShotAttemptController.php`
- `docs/architecture/stats/NhlExpectedGoalsModel.yaml`
- `docs/architecture/stats/NhlShotAttemptFacts.yaml`
- `docs/architecture/stats/NhlModelRuns.yaml`
- `docs/ENUMS.md`
- `docs/ARCHITECTURE_INVENTORY.md`
- `tests/Feature/NhlUnknownShotTypeTest.php` — full test source
- `docs/testing/unknown-shot-type-review.md`

Pre-existing changes in these files were preserved. No migrations, raw fact imports, dependencies, or stored model outputs were changed by this work.

## Minimum coverage matrix

COVERED means test assertions are authored, not that they have been executed.

| Requirement | Coverage | Tests |
| --- | --- | --- |
| SAT includes unknown, null, blank, and known types | COVERED | includes blocked unknown attempts in SAT eligibility; includes null and blank shot types in SAT eligibility; continues including known shot types beside unknown types |
| Existing empty-net/shootout limits | COVERED | continues excluding empty net attempts regardless of shot type; continues excluding shootouts regardless of shot type; retains empty net and shootout exclusions in model scoring |
| Training isolation | COVERED | does not include held out unknown attempts in training counts; keeps held out goals out of trained SAT probabilities |
| SOG includes unknown goals but not blocked SAT | COVERED | includes unknown goals in SOG evaluation without admitting blocked attempts; includes null and blank SOG types but preserves the separate other exclusion; trains SOG using unknown goals as goals rather than guessed blocks |
| Persisted evaluation metadata and actual labels | COVERED | trains SAT with unknown outcomes and records the actual inclusion policy |
| Both score storage paths include unknown attempts | COVERED | backfills unknown attempts through the existing probability buckets; scores unknown attempts without assigning them to a known shot type |
| Rebuild replaces old scores safely | COVERED | replaces legacy unknown exclusions without duplicating scored attempts; invalidates cached unknown exclusions when refreshing summary scores; refreshes cached probabilities after the same model is retrained |
| Profile discovery, identity, counts and normalization | COVERED | discovers entities whose only attempts have unknown shot types; preserves actual SAT SOG and goals within the unknown profile bucket; normalizes missing types into one profile bucket without losing attempts; keeps unknown and known shot type profiles distinct |
| Snapshots and projections | COVERED | builds unknown held out snapshots separately from training profiles; carries unknown bucket identity into rate projections |
| Comparison game denominators | COVERED | counts unknown only games in held out comparisons; includes unknown only games in Training Drift per game rates |
| Build request → persisted profile → HTTP read | COVERED | exposes unknown profiles after an authorized build request and persisted build |
| Predictive analysis read | COVERED | shows unknown attempts and their true outcomes in predictive analysis |
| Access controls | COVERED | blocks guests from profile build and inspection routes; blocks ordinary users from profile build and inspection routes; authorized read/build tests below |

## Numbered requirements

1. SATISFIED in source: unknown types participate in existing evaluation, scoring, profile discovery/builds, snapshots, rate projection source queries, comparisons, and predictive analysis.
2. SATISFIED in source: existing bucket-key normalization keeps missing shot types under `unknown`; no duplicate model or fallback system was introduced. As before, selected model factors determine bucket dimensions and existing fallback rules apply when an exact bucket is absent.
3. SATISFIED in source: actual outcome flags supply source SAT/SOG/goal counts; unknown does not imply blocked. SOG evaluation still requires `is_shot_on_goal = true`.
4. SATISFIED in source: held-out data remains separate and existing empty-net/shootout constraints remain.
5. SATISFIED in source: cached model scores are refreshed if they contain legacy unknown exclusions or predate the model's latest training timestamp.
6. SATISFIED in documentation: canonical modeling eligibility, enum metadata, and derived inventory describe the change and legacy stored values.
7. UNSATISFIED at runtime: tests and rebuilds have not been run. Actual count reconciliation, runtime, and post-rebuild forecast accuracy await human verification.

## Endpoint × verb authorization/scope

| Route | Verb | Guest blocked | Ordinary user blocked | Super-admin allowed |
| --- | --- | --- | --- | --- |
| `admin.nhl-sat-models.profiles.build` | POST | blocks guests from profile build and inspection routes | blocks ordinary users from profile build and inspection routes | exposes unknown profiles after an authorized build request and persisted build |
| `admin.nhl-sat-models.profiles` | GET | blocks guests from profile build and inspection routes | blocks ordinary users from profile build and inspection routes | exposes unknown profiles after an authorized build request and persisted build |
| `admin.nhl-sat-models.profiles.training-drift` | GET | blocks guests from profile build and inspection routes | blocks ordinary users from profile build and inspection routes | includes unknown only games in Training Drift per game rates |
| `admin.nhl-shot-attempts.index` (predictive tab) | GET | blocks guests from profile build and inspection routes | blocks ordinary users from profile build and inspection routes | shows unknown attempts and their true outcomes in predictive analysis |

SAT administration is global, not organization- or owner-scoped. Authorization and route names are unchanged. Lifecycle readiness is mocked; authentication/authorization remain enabled. Evaluation is exercised directly through the existing service; this change adds no endpoint.

## Frontend component checklist

Not applicable: no JavaScript, Blade, layout, or interactive behavior changes. HTTP assertions cover the affected server-rendered data.

## Test count and verification

`tests/Feature/NhlUnknownShotTypeTest.php`: **29 `it(...)` declarations**. Fixtures use a frozen clock, deterministic identifiers, blocked HTTP, and asserted/faked queue dispatch. No global helpers were introduced. Tests use the isolated test database and call builders directly; no workers or external providers are needed.

No tests, CI, imports, queues, or model rebuilds were executed. `git diff --check` passed; that is not runtime validation.

Human-run test command:

```sh
php artisan test tests/Feature/NhlUnknownShotTypeTest.php tests/Feature/NhlSatPredictionServicesTest.php tests/Feature/AdminControlPanelTriageTest.php
```

After human review and successful validation, rebuild the selected existing model through **Eval SAT → Eval SOG → Build Predictions → Compare /60**. Build Predictions performs Profiles → /60 → TOI/GP. Rebuilding Eval SOG includes unknown-type goals/SOG in its training sample. Rebuilding downstream outputs is required; changing code alone does not update stored rates or old Markdown reports. Raw shot facts already contain these attempts and do not need rebuilding solely for this eligibility change.

Awaiting human review.
