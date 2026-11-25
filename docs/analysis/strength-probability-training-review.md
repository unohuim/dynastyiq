# Strength-specific bucket probabilities

## Files changed/created

- `app/Services/NhlExpectedGoalsBackfiller.php`: Train-only combined and strength
  partitions, separate baselines/shrinkage, atomic bucket replacement, and matching
  strength in historical scoring.
- `app/Services/NhlShotAttemptAnalysisBuckets.php`: shared canonical shooter-side
  strength and single-row probability lookups within the existing bucket service.
- `app/Services/NhlShotAttemptModelScorer.php`: matching strength and score provenance.
- `app/Services/NhlSatModelEntityProfileBuilder.php`: one probability row per fact.
- `app/Services/NhlSatModelEntityRateProjectionBuilder.php`: matching strength for
  high-danger scoring.
- `app/Services/NhlEvaluationGameForecaster.php`: single-row matching for goalie xGA.
- `app/Services/NhlPregameContextBuilder.php` and
  `app/Services/NhlNextGameEvaluationService.php`: deduplicated shared bucket maps.
- `app/Http/Controllers/Admin/NhlModelRunController.php` and
  `app/Http/Controllers/Admin/NhlShotAttemptController.php`: stable inspection order,
  strength labels, and no multiplied player-profile counts from metadata joins.
- `resources/views/admin/nhl-sat-models/buckets.blade.php` and
  `resources/views/admin/nhl-shot-attempts/index.blade.php`: label the probability
  strength without introducing interactive state or altering the page layout.
- `tests/Feature/NhlStrengthProbabilityTrainingTest.php`: deterministic regression coverage.
- `docs/ENUMS.md`, `docs/architecture/stats/NhlExpectedGoalsModel.yaml`,
  `docs/architecture/stats/NhlModelRuns.yaml`, `docs/ARCHITECTURE_INVENTORY.md`
  and this review: durable contract and activation instructions.

## Behavior

Eval SAT still learns the chance of SOG from SAT. Eval SOG still learns the chance
of a goal from SOG. Their targets and source eligibility have not been interchanged.
Each now stores the combined partition plus a separate partition for every
observed canonical strength, using the same selected shot-shape definitions.
Factor selection remains Train-only and pooled, with strength removed from the
shot-shape candidate list because it is now an explicit partition.

Small samples retain their evidence and shrink toward their strength's league
baseline using the configured prior attempts. A missing shape tries same-strength
broader candidates/baseline before the combined shape/baseline. A wholly absent
strength falls back to the combined model; it is not invented as a zero-rate
sample. Unknown strength uses combined probabilities. Measured zero remains zero.
This is not a new empirical minimum-sample threshold or a claim of improved game
prediction accuracy.

All observed rows for the selected target are replaced transactionally. Other
models and target types remain intact. Existing combined-only models retain
lookup compatibility. The selected strength is saved with per-attempt provenance.
No automatic model rebuild, profile rebuild, cache refresh, prediction rerun,
live import or production data write is performed by this code change itself.

## Minimum coverage matrix

Authored coverage only; tests have not been executed.

| Category | Coverage | Named tests |
| --- | --- | --- |
| B/C: combined/strength samples and distinct target denominators | COVERED | builds combined and three strength SAT baselines; calculates EV/PP/PK SOG probability from their attempts only; uses only EV/PP/PK shots on goal in their goal probabilities |
| B/C: sample eligibility and Train isolation | COVERED | retains blocked attempts in SAT training; excludes blocked attempts from SOG goal training; retains unknown shot types at their actual strength; keeps Test outcomes out of every strength training sample; excludes empty net attempts; excludes shootouts |
| B/C: strength normalization and sparse evidence | COVERED | keeps unknown strengths combined; accepts lowercase canonical strength values; uses the valid strength bucket when primary strength is missing; smooths small PK buckets toward the PK baseline; does not fabricate a baseline for a strength with no eligible sample |
| C: replacement, isolation and provenance | COVERED | replaces prior buckets without duplicating rows when reevaluated; preserves the other target when SAT is reevaluated; records strength training provenance; does not write per attempt scores during evaluation; does not write model rows during a dry run |
| C: production lookup/fallback | COVERED | prefers an exact PK bucket; prefers a PK baseline over a combined exact bucket; combined legacy and baseline fallbacks; does not borrow a PP probability for a PK attempt; keeps a measured zero probability instead of falling back |
| C: production scorer/profile cardinality | COVERED | scores every attempt once using its own strength; builds profiles without multiplying attempts across probability strengths (also asserts combined bucket-map uniqueness) |
| A/E: protected action/read contracts | COVERED | guest and unprivileged action/read rejection tests; admin SAT/SOG evaluation to database to bucket-page tests |

## Numbered requirements

1. SATISFIED: Eval SAT writes separate strength SOG probabilities.
2. SATISFIED: Eval SOG writes separate strength goal probabilities.
3. SATISFIED: Train-only sampling and existing outcome/exclusion rules are retained.
4. SATISFIED: matching uses the attempt's shooter-side strength with transparent combined fallback.
5. SATISFIED: profiles and scoring do not multiply source attempts across probability rows.
6. SATISFIED: reevaluation replaces only the selected target, and evaluation has no hidden prediction/scoring work.
7. SATISFIED: admin inspection labels strength and coverage includes the public admin action-to-read path.
8. SATISFIED: tests and operational commands were not executed without authorization.

## Endpoint × verb auth/scope matrix

| Endpoint | Guest | Authenticated without permission | Superadmin | Organization scope |
| --- | --- | --- | --- | --- |
| POST `admin.nhl-sat-models.train` | blocks guests from evaluating probability buckets | blocks unprivileged users from evaluating probability buckets | allows an admin to evaluate SAT then read strength rows; allows an admin to evaluate SOG then read strength rows | N/A, existing global admin resource |
| GET `admin.nhl-sat-models.buckets` | blocks guests from reading admin probability buckets | blocks unprivileged users from reading admin probability buckets | same two action → database → HTTP read tests | N/A, existing global admin resource |

No route names, permissions, partner APIs or tenant scope changed.

## Frontend checklist

No JavaScript component or interactive state changes. Existing Blade tables gain
strength labels; admin HTTP read tests assert those labels. No browser test was run.

## Per-file test count and execution

`tests/Feature/NhlStrengthProbabilityTrainingTest.php`: **41 `it(...)` tests**.
Tests use Pest/strict types, frozen time, network prevention, fake jobs/events and
local fixture closures. PostgreSQL-only LATERAL/scorer/profile tests explicitly
skip under SQLite and must also be run against an isolated PostgreSQL test DB.
The default SQLite suite alone cannot verify those production SQL paths.

Performed: source/schema review, static call-site/SQL-binding review, test-count
inventory and `git diff --check`. Not run: tests, CI, lint, builds, migrations,
imports, queues, rebuilds, or production operations.

Human-run focused tests:

```sh
php artisan test tests/Feature/NhlStrengthProbabilityTrainingTest.php tests/Feature/NhlExpectedGoalsBucketStrengthTest.php tests/Feature/NhlUnknownShotTypeTest.php
```

## Deployment and rebuild order

No additional migration is introduced by this follow-up. **Migration required**
if the preceding storage change has not been applied:
`2026_11_25_000002_add_strength_to_nhl_expected_goals_model_buckets.php`.
Run before evaluating or scoring:

```sh
php artisan migrate --force
```

For the intended SAT model, run **Eval SAT**, then **Eval SOG**. Those actions
rebuild probability buckets only. Use the existing **Build Predictions** workflow
to rebuild profiles, /60 rates and TOI inputs in their existing dependency order
when those saved inputs must reflect the new probabilities. Rebuild goalie
profiles/rates through their existing actions as needed.

Existing scored attempts, pregame contexts and saved game-prediction revisions
are not retroactively updated by evaluation. Refresh dependent saved outputs
through their existing explicit workflows before assessing prediction impact.
Previously saved game revisions remain historical snapshots.
