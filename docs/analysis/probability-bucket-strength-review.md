# Probability bucket strength storage

## Scope and files

The approved change adds a strength discriminator to probability-bucket storage.
Existing rows default to the combined sample without recalculating probabilities.
Eval SAT, Eval SOG, model scoring, profiles, predictions, API payloads and production
data are not changed. Separate-strength training and consumption require a later
approved implementation; the column alone does not activate them.

- `database/migrations/2026_11_25_000002_add_strength_to_nhl_expected_goals_model_buckets.php`
- `tests/Feature/NhlExpectedGoalsBucketStrengthTest.php`
- `docs/DB_SCHEMA.md`
- `docs/ENUMS.md`
- `docs/architecture/stats/NhlExpectedGoalsModel.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/analysis/probability-bucket-strength-review.md`

## Minimum coverage matrix

Coverage describes authored assertions, not executed/passing checks.

| Requirement | Coverage | Representative test |
| --- | --- | --- |
| C: existing writers retain combined samples | COVERED | defaults existing writer inserts to combined strength |
| C: supported strength rows coexist | COVERED | allows all strengths to coexist for one model and bucket |
| C: strength-scoped uniqueness | COVERED | rejects duplicate penalty kill buckets within one model; equivalent combined/EV/PP cases |
| C: model and bucket isolation | COVERED | allows the same bucket and strength in another model; allows different buckets at the same strength within one model |
| C: required canonical values | COVERED | rejects null strength instead of bypassing bucket uniqueness; unsupported, blank and uppercase cases |
| C: targeted writes | COVERED | upserts one strength without overwriting the other strengths |
| C: legacy migration data preservation | COVERED | preserves legacy evidence and identity when upgrading combined buckets |
| C: safe rollback | COVERED | restores the original combined key on safe rollback |
| C: reject destructive rollback | COVERED | refuses rollback before losing any strength specific probability |

## Numbered requirements

1. SATISFIED: add a non-null strength discriminator with a legacy-compatible default.
2. SATISFIED: replace model/bucket uniqueness with model/bucket/strength uniqueness.
3. SATISFIED: preserve existing sample counts, probabilities, identity and metadata.
4. SATISFIED: prevent invalid strength values and duplicate same-strength buckets.
5. SATISFIED: prevent rollback from silently discarding strength-specific evidence.
6. SATISFIED: document canonical values and schema/architecture boundaries.
7. SATISFIED: keep probability calculation and consumption changes outside this schema-only approval.

## Endpoint and frontend checklists

- Endpoint × verb × auth/scope: not applicable; no endpoint, authorization or public response changes.
- Frontend component contracts: not applicable; no frontend changes.
- HTTP end-to-end coverage: not applicable; strength is storage support only and is not surfaced through a new/changed endpoint.

## Test count and execution

`tests/Feature/NhlExpectedGoalsBucketStrengthTest.php`: **20 `it(...)` tests**.
Tests use Pest, strict types, database assertions, no network access, no global
helpers and no clock/randomness dependency. Constraint rejection uses nested
transactions so PostgreSQL remains usable after a rejected insert.

Performed: source/schema/documentation inspection, static test-count inventory,
and `git diff --check`. No tests, CI, lint, migrations, rebuilds, imports, queue
operations, or production writes were run.

## Human-run commands

**Migration required:**
`2026_11_25_000002_add_strength_to_nhl_expected_goals_model_buckets.php`.
Before storing separate-strength buckets, run:

```sh
php artisan migrate --force
```

Suggested focused test command, not executed:

```sh
php artisan test tests/Feature/NhlExpectedGoalsBucketStrengthTest.php
```

Rollback is allowed with combined rows only and refuses while separate-strength
rows exist. It does not automatically delete or merge those rows.
