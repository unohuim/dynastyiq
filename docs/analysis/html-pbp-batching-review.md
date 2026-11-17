# HTML on-ice verification batching

Files changed or created:

- `app/Services/VerifyNhlHtmlPlayByPlay.php`
- `tests/Feature/NhlHtmlPbpBatchingTest.php`
- `docs/architecture/imports/NhlGameDataImportServices.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/analysis/html-pbp-batching-review.md`

The slow path repeated game/jersey/player queries per HTML event, read event/unit
membership per participant, and used existence checks plus individual position
updates. The change prepares one game-local context, resolves those comparisons
in memory and bulk upserts deduplicated position keys in chunks of 100. The chunk
bound also keeps parameter counts compatible with smaller test databases.

No global or service-instance cache is introduced. Original API event matching,
penalty-shot and period/game-end rules, exact-boundary treatment, TV/TH
arbitration and nonfatal report behavior retain their existing meaning. Repeated
position evidence uses the last nonempty match in report order. On an existing
position row, only position_code and updated_at change; its ID and created_at
remain intact. All writes still participate in the caller's transaction and
cancellation fence, including rollback on a later game-stage failure.

## Coverage matrix

Tests below are authored and manually reviewed, not executed.

| Requirement | Status | Test names |
| --- | --- | --- |
| Action → stored positions and validation read | COVERED | persists linked positions with one bulk write and approves matching evidence |
| Deduplication, natural-key identity and timestamps | COVERED | deduplicates repeated shift player updates and keeps the last nonempty HTML position; preserves row IDs and original creation timestamps when updating positions; remains idempotent when the same game is verified again; does not replace a known position with an empty later position |
| Player resolution and game/team scope | COVERED | resolves explicit NHL IDs without requiring jersey metadata; resolves identical jerseys separately for the two teams; does not invent player IDs for unresolved jerseys; does not create positions without a linked unit shift; does not retain another games player or event indexes in the same service instance; does not enrich shifts linked from another game |
| Existing event and boundary exceptions | COVERED | skips normal position enrichment for penalty-shot attempts; retains period-end on-ice mismatch suppression; retains exact-boundary mismatch suppression using preloaded shifts; retains TV TH arbitration using the same preloaded jersey identities |
| Read growth and bulk-write bounds | COVERED | keeps evidence reads bounded as repeated HTML events increase; writes many distinct natural keys in bounded bulk chunks |
| Rollback and canonical source protection | COVERED | preserves whole-game rollback around bulk enrichment; does not alter canonical API event fields during batching |
| Nonfatal source behavior | COVERED | keeps non-boundary disagreements nonblocking without falsely approving them; performs no position writes when the parsed report is empty; keeps provider failures nonfatal and leaves positions intact |

The 80-event fixture asserts at most eight evidence reads and one bulk position
write. The distinct-shift fixture asserts 120 saved keys with at most two bulk
writes. These assert query-count bounds rather than machine-dependent runtime.

## Requirements checklist

1. SATISFIED: load game-local evidence once and remove database queries from
   per-event identity, linked-player and boundary comparisons.
2. SATISFIED: deduplicate natural keys, preserve last nonempty report positions,
   then bulk upsert bounded chunks instead of per-player existence/update calls.
3. SATISFIED: preserve matching/arbitration behavior, canonical API facts, row
   identities, creation timestamps and whole-game rollback protection.
4. SATISFIED: preserve public service interface and existing call sites; no queue,
   worker-count, timeout, run-progress UI or production-data changes.
5. SATISFIED: regression coverage and canonical/derived documentation added.

## Authorization/scope matrix

No route, HTTP verb, permission or organization boundary changes. Endpoint
authorization coverage is not applicable to this service-only change. Existing
import/admin endpoint tests remain in `NhlGameValidationTest.php` and the scoped
rebuild/cancellation tests remain in `NhlStrengthOnIceStatsTest.php`.

Game scope is covered by does not retain another games player or event indexes
in the same service instance and does not enrich shifts linked from another game.
Team scope is covered by resolves identical jerseys separately for the two teams.
The end-to-end action here is `verify()` → database upserts → persisted position
and validation reads; no HTTP payload or frontend contract is changed.

## Frontend checklist and test count

Frontend component checklist: not applicable; no JavaScript or views changed.

`tests/Feature/NhlHtmlPbpBatchingTest.php` contains 22 `it(...)` declarations.
Fixtures use a fixed clock, local database records, mocked report locator/parser,
mocked troubleshooting exporter and fake HTTP. No real requests, randomness,
shared mutable cache or filesystem exports are required.

Only source/diff inspection and `git diff --check` were performed. Tests, builds,
CI, imports, worker operations and production changes were not run. Performance
improvement has not been benchmarked; production duration remains to be measured
after deployment. No migration required.
