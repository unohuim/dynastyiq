# NHL game-import cleanup reference indexes

## Files in this change

- `database/migrations/2026_10_27_000001_add_nhl_import_cleanup_reference_indexes.php` (existing applied-local migration, preserved unchanged; pending source-control inclusion)
- `tests/Unit/NhlImportPipelineFoundationTest.php`
- `docs/architecture/imports/NhlImportOrchestrator.yaml`
- `docs/DB_SCHEMA.md`
- `docs/ARCHITECTURE_INVENTORY.md`
- This review document.

## Approved scope and migration immutability

This change supports existing game-scoped cleanup before an explicitly requested
reimport. It adds lookup indexes only. Import logic, foreign-key actions, row
identities, source facts and progress checkpoints remain unchanged. No importer,
worker, queue, prediction or API behavior is modified.

The migration was already applied locally when checked during this task. It has
therefore not been edited. Production has not applied it. All seven references
are missing usable leading-column indexes in production, so all seven proposed
indexes remain justified. No further migration is introduced.

The migration's idempotency is by its own dedicated names. It does not discover
equivalent indexes under other names at runtime. A production catalog inspection
confirmed none exist for these references on 2026-11-11. Recheck the catalog
before deployment if other indexing work occurs meanwhile; do not silently
rewrite an already-applied migration to add different behavior.

## Production evidence captured on 2026-11-11

Only metadata SELECTs and EXPLAIN without ANALYZE were executed in read-only
transactions with statement timeouts. No migration, lookup execution, cleanup,
import or worker operation was run in production.

| Reference | Approximate rows from PostgreSQL statistics | Existing usable leading index | Baseline lookup plan |
| --- | ---: | --- | --- |
| event_unit_shifts.unit_shift_id | 10,396,198 | Missing | Parallel sequential scan |
| nhl_faceoff_facts.next_play_by_play_id | 180,873 | Missing | Parallel sequential scan |
| nhl_pbp_source_mismatches.play_by_play_id | 0 | Missing | Sequential scan |
| nhl_shot_attempt_model_scores.play_by_play_id | 1,146,071 | Missing | Parallel sequential scan |
| nhl_shot_attempt_predictions.play_by_play_id | 343,404 | Missing | Parallel sequential scan |
| nhl_shot_attempts_facts.previous_play_by_play_id | 735,042 | Missing | Parallel sequential scan |
| nhl_shot_attempts_facts.rush_sequence_origin_play_by_play_id | 735,042 | Missing | Parallel sequential scan |

These row counts are planner estimates, not exact COUNT results. The existing
unique event/unit index is `(event_id, unit_shift_id)`: its first column does not
support the missing unit-shift lookup. All seven local dedicated indexes were
read from the local PostgreSQL catalog and are valid, ready, ordinary btree
indexes on the intended single reference column. This local check is not an
end-to-end production performance measurement.

The underlying production baseline used unit_shift_id 8564523 and
play_by_play_id 6380393. EXPLAIN plans identify the scanning mechanism, not actual
execution times or a proven speedup. End-to-end cleanup timing remains unmeasured.

## Minimum coverage matrix

COVERED means written deterministic coverage; no tests were executed in this task.

| Requirement | Status | Named coverage |
| --- | --- | --- |
| All seven indexed columns and usable PostgreSQL catalog state | COVERED | `indexes every previously unindexed reference used during game cleanup` (seven table/column/name datasets; valid, ready, unfiltered, leading-column checks) |
| Concurrent DDL outside migration transactions | COVERED | `keeps concurrent cleanup index builds outside a migration transaction`; creation test checks concurrent/nonunique DDL |
| Game facts and completed checkpoints retained | COVERED | `adds only nonunique concurrent lookup indexes while preserving game facts and completed checkpoints` |
| Repeat successful installation | COVERED | `retains completed indexes when a successful cleanup index migration is retried` |
| Invalid leftover recovery | COVERED | `replaces only the invalid named leftover while keeping other completed indexes` |
| Partial failure followed by retry | COVERED | `resumes a partially installed index migration after an interrupted concurrent build` |
| Scoped rollback and preservation of other indexes/data | COVERED | `rolls back only the dedicated lookup indexes and preserves unrelated indexes and completed progress` |

PostgreSQL DDL behavior in the safety tests is modeled behind a temporary facade
mock, restored in finally, so concurrent index statements do not escape the
RefreshDatabase transaction boundary. Separate real schema/catalog assertions
check the indexes installed by the migration in the test database. Those modeled
safety tests do not claim to exercise live concurrent-DDL lock waits or prove a
wall-clock performance improvement.

## Numbered requirements checklist

1. SATISFIED: Audit migration definitions and production indexes; retain exactly the seven justified reference indexes.
2. SATISFIED: Preserve the already-applied local migration unchanged, including concurrent nonunique creation, IF NOT EXISTS retries and invalid named-build recovery.
3. SATISFIED: Add deterministic safety coverage for transaction mode, completed and partial retries, invalid leftovers, rollback scope and unchanged facts/checkpoints.
4. SATISFIED: Update canonical schema/architecture documentation and derived inventory within the approved scope.
5. SATISFIED: Supply human-run migration, test, catalog and before/after timing checks; capture read-only production baseline plans without deleting data.
6. SATISFIED: Preserve all importer algorithms and existing cleanup/cascade/SET NULL semantics.

## Endpoint × verb authorization/scope checklist

Not applicable: schema indexes only. No endpoints, authentication, tenant/owner
scope or user-visible payload contracts are changed. Existing import authorization
tests and operational controls are unaffected.

## Frontend component checklist

Not applicable: no frontend changes.

## Per-file test counts

- `tests/Unit/NhlImportPipelineFoundationTest.php`: 64 it declarations, including the seven-case index catalog dataset.

The file exceeds twenty declarations, retains strict_types, PSR-12, Pest, fixed
time and fake provider/queue boundaries. Complete test file contents are updated
on disk. No tests, CI, imports, queue jobs or migrations were run.

## Human-run deployment and verification

**Migration required:**
`2026_10_27_000001_add_nhl_import_cleanup_reference_indexes.php`.

On the intended application environment:

```sh
php artisan migrate --path=database/migrations/2026_10_27_000001_add_nhl_import_cleanup_reference_indexes.php
```

This is already applied locally, so a local migration run may report no work.
Production must apply it before receiving the lookup benefit. PostgreSQL's
concurrent builds can still wait for existing transactions and take time to build;
this migration does not pause workers or restart completed imports.

Human-run test command, against the dedicated test database:

```sh
php artisan test tests/Unit/NhlImportPipelineFoundationTest.php
```

Before and after migration, use the same connection/database and probe values
for read-only lookup timing. For example, run this SQL through the usual SQL client:

```sql
BEGIN READ ONLY;
SET LOCAL statement_timeout = '15s';
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF)
SELECT id FROM event_unit_shifts WHERE unit_shift_id = 8564523;
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF)
SELECT id FROM nhl_shot_attempt_model_scores WHERE play_by_play_id = 6380393;
ROLLBACK;
```

EXPLAIN ANALYZE here executes SELECTs only. No DELETE is included. Record
execution time, buffer reads/hits and scan nodes under comparable cache/workload
conditions. If the before query reaches the timeout, ROLLBACK the failed read-only
transaction, record that result, and repeat after migration. These measurements
verify the lookup improvement; actual cleanup wall time requires a separately
human-run reimport or isolated staging benchmark and must not be inferred from
planner cost estimates alone. Small/empty tables can legitimately retain a
sequential scan even with a usable index.

After migration, verify validity, readiness and the intended leading columns:

```sql
SELECT t.relname AS table_name, x.relname AS index_name,
       a.attname AS leading_column, i.indisvalid, i.indisready,
       i.indpred IS NULL AS unfiltered
FROM pg_index i
JOIN pg_class t ON t.oid = i.indrelid
JOIN pg_class x ON x.oid = i.indexrelid
JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = i.indkey[0]
WHERE x.relname IN (
  'ix_event_unit_shifts_unit_shift',
  'ix_nhl_faceoff_facts_next_pbp',
  'ix_nhl_pbp_mismatches_pbp',
  'ix_nhl_sat_model_scores_pbp',
  'ix_nhl_sat_predictions_pbp',
  'ix_nhl_saf_previous_pbp',
  'ix_nhl_saf_rush_origin_pbp'
)
ORDER BY x.relname;
```

Expected: seven rows, each valid/ready/unfiltered and on the mapped reference
column in the schema inventory. Preserve existing import stage progress; indexing
alone is not a reason to restart a whole discovery/day.

## Review limits

Only source/diff checks and read-only local/production catalog queries were run.
Tests and production before/after timings remain for human verification. Migration
immutability was honored; no extra migration or broader refactor was added.
