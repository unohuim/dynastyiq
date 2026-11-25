# Pregame comparison timeout recovery

## Files changed/created

- `app/Services/NhlPregameLeagueResults.php`
- `app/Jobs/BuildNhlPregameContextDayBatchJob.php`
- `config/nhl-pregame.php`
- `tests/Feature/NhlPregameComparisonPagesTest.php`
- `tests/Feature/NhlModelPregameBuildTest.php`
- `docs/ENUMS.md`
- `docs/architecture/stats/NhlPregameContextEvidence.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/analysis/pregame-comparison-pages-review.md`

## Resulting behavior

Production diagnosis found repeated five-minute timeouts in retained-reference
comparisons while checking worker ownership for each metric. Each metric invoked
the cancellation callback, which performed two database reads and a Redis read.
The examined repair had 2,738 metrics, and the whole comparison transaction was
rolled back after timeout. Prediction refreshes continued waiting on this work.

The retained-reference loop no longer calls that callback for each metric. The
checkpointed day worker uses the new page method, checking permission at page
admission and before committing its writes. The existing worker's claim/save
fences remain in place. Work is bounded by
`nhl-pregame.comparison_metric_page_size` (default 300, clamped to 1–1,000).

Comparison rows and the cursor are saved together in the existing game work row's
`readiness` JSON. This is control metadata, not new storage of statistical inputs.
New deliveries resume after the committed metric ID. Losing permission before
commit discards only that page; earlier pages and the existing context snapshot
remain. Game completion markers are saved only after both populations finish,
and comparison timing accumulates instead of replacing previous page time.

Existing referenced averages, source-owned peer cache checks, one-peer-group
memory limit, ranking formulas and source metric/sample values remain unchanged.
Non-reference populations retain their existing set-based SQL path, one entity
type at a time. Older callers of the full comparison method remain compatible,
also without per-metric database cancellation reads. Existing runs without a
progress marker start at their first unfinished comparison page.

No queue job was moved, cancelled or restarted. No production data/cache writes,
prediction reruns, imports or worker operations were performed. This patch does
not suppress the API's injury-eligibility checks or send invalid saved lineups.

## Finalized minimum coverage matrix

Coverage means authored assertions, not passing test execution.

| Contract | Coverage | Named test |
| --- | --- | --- |
| B/C: bounded page and stable progress | COVERED | limits a delivery to the configured metric page; saves the last committed metric identity |
| C: retry continuity and immutable prior work | COVERED | resumes from a saved cursor with a fresh service instance; does not rewrite a previously committed comparison page |
| C: terminal completion/idempotency | COVERED | returns complete only after the final comparison page; keeps repeated completed deliveries idempotent |
| C: constant permission-check count | COVERED | checks permission at page boundaries instead of per metric (100 metrics, two checks) |
| C/F: cancellation/ownership fencing | COVERED | rejects stopped work before comparison writes; rolls back a page if ownership is lost before commit; preserves earlier pages when a later page loses its claim |
| B/C: malformed state rejected | COVERED | fails visibly on an unknown checkpoint format; rejects a negative metric cursor; rejects duplicate completed populations |
| C: lost/corrupt source evidence | COVERED | does not advance progress if retained peers are missing; does not commit a page with a corrupt peer checksum; keeps prior pages when a reference disappears |
| C: unchanged statistical meaning | COVERED | preserves metric values and sample exposure; retains descending competition rank for ties; recalculates a changed player rank against the original peers; keeps undefined percentage differences null for a zero league average |
| C: existing metadata/snapshots | COVERED | preserves unrelated readiness details while checkpointing; leaves snapshot completion evidence intact while comparing |
| C: mixed population isolation | COVERED | handles a mixed referenced and nonreferenced population without replaying both |
| F: resumable day-worker continuation and timing | COVERED | continues incomplete comparison pages without marking the game complete (existing day-worker suite) |

## Numbered requirements

1. SATISFIED: remove the per-metric database cancellation callback.
2. SATISFIED: retain page admission and precommit checks plus the worker's existing claim/save fences.
3. SATISFIED: checkpoint comparison pages durably without a migration or new table.
4. SATISFIED: resume committed progress and preserve earlier pages across retries.
5. SATISFIED: keep original metric values, samples, ranking semantics and comparison scope.
6. SATISFIED: leave the game unfinished until all comparison populations are done.
7. SATISFIED: document the control-metadata vocabulary and continued operation of existing runs.
8. SATISFIED: keep the approval scoped to repository changes and push, without production intervention.

## Endpoint × verb authorization/scope checklist

Not applicable: no endpoint, request payload, policy or permission changes. Tests
exercise the internal service and existing queue worker. Existing model build,
pause, resume and stop endpoint coverage remains in the modified build suite.

## Frontend component checklist

Not applicable: no frontend or interactive-state changes.

## Per-file test count and actual checks

- `tests/Feature/NhlPregameComparisonPagesTest.php`: **24 `it(...)` tests**.
- `tests/Feature/NhlModelPregameBuildTest.php`: **107 `it(...)` tests**; mocks now
  use the page method and assert its completion signal.

Tests use Pest/strict types, frozen time, isolated array cache and deterministic
fixtures; no real network or worker execution is used. Queue work is faked in the
existing day-worker suite.

Performed: source/schema inspection, static transaction/cursor review, test count
inventory and `git diff --check`.

Not run: tests, CI, lint, migrations, scripts, imports, queue/scheduler commands,
prediction runs or production write/recovery actions.

Suggested human-run command:

```sh
php artisan test tests/Feature/NhlPregameComparisonPagesTest.php tests/Feature/NhlModelPregameBuildTest.php tests/Feature/NhlPregameParticipantRepairTest.php
```

No migration required. Normal deployment supplies the changed worker code to new
deliveries. A run already marked failed still needs the existing Resume workflow;
this patch does not silently restart failed production runs.
