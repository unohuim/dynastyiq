# Prediction participant repair review

Approved scope: prevent a lineup-only missing-player dependency from needlessly
regenerating league reference populations. Preserve existing contexts and add the
missing participants at the original common cutoff. No prediction equations,
lineup selection rules, production data or schemas change.

## Files

- `app/Services/NhlCustomGameForecast.php`: expose exact context coverage while
  retaining the existing boolean readiness interface.
- `app/Services/NhlPregameContextOrchestrator.php`: exclude ready games, record
  missing participants and admit matching league references before job dispatch.
- `app/Services/NhlPregameLeagueResults.php`: retain finalized ranking groups in
  expiring cache, reuse completed matching populations and compare only this run's
  new contexts against the original average IDs.
- `config/nhl-pregame.php`: 24-hour manifest retention; peer groups live one hour
  longer. Existing transient SQL peer cleanup remains unchanged.
- `tests/Feature/NhlPregameParticipantRepairTest.php`: 30 deterministic Pest cases.
- `docs/architecture/stats/NhlPregameContextEvidence.yaml` and
  `docs/architecture/stats/NhlCustomGamePrediction.yaml`: canonical behavior.
- `docs/ENUMS.md`: private run-option field vocabulary.
- `docs/ARCHITECTURE_INVENTORY.md`: derived summary.
- This review document.

## Coverage matrix

Coverage means authored assertions, not an executed test result.

| Category | Status | Evidence |
| --- | --- | --- |
| A: Request validation | Not applicable | No request validation or routes changed. |
| B: Normalization/defaults | COVERED | Exact missing-player coverage, starter deduplication, unchanged-ready result. |
| C: Persistence | COVERED | Original average IDs, no average duplication, unchanged parent/player rows, zero/null/nonvalue handling, tied ranks and self exclusion. |
| D: API contract | Not applicable | No public payload fields or prediction equations changed. |
| E: Read contract | COVERED | Builder repair action → database preservation and parent assertions → forecast readiness reader, gated on committed comparisons. No HTTP read surface changed. |
| F: Queue contract | COVERED | Ready inputs queue nothing; warm references dispatch context work without a league job; partial references skip matched populations only. |
| G: Frontend | Not applicable | No JavaScript or UI changes. |

## Requirements

1. SATISFIED in authored coverage: identify missing participants independently
   from existing valid team and player history.
2. SATISFIED in authored coverage: preserve original rows and cutoffs and add the
   missing selected participant through the real context builder.
3. SATISFIED in authored coverage: restore prediction readiness only after the
   new participant's comparison work commits.
4. SATISFIED in authored coverage: reuse completed matching populations after
   transient SQL peers have been removed, without rewriting averages.
5. SATISFIED in authored coverage: reject changed results, cutoff, Train, model,
   appearance seasons, upcoming schedule and unfinished source runs.
6. SATISFIED in authored coverage: cache expiry, eviction and predeployment absence
   retain the normal reference-preparation path; explicit historical rebuilds
   are not silently converted to prediction repairs.
7. SATISFIED in authored coverage: reject corrupted peer values rather than
   publishing guessed comparisons. Bound metric reads to pages of 300 rows.
8. SATISFIED by scope review: no migration, queue routing, scoring, authorization
   or public-interface changes. Pause/Stop retains existing owner/claim fencing.

## Endpoint × verb authorization/scope

No endpoints, middleware or authorization were changed. This suite exercises the
existing internal services with fake queued dispatch; guest, permission and
organization HTTP matrices are not applicable to this internal change.

Model isolation maps to `does not reuse another models league population`.
Scope validity also maps to `does not reuse a population with different
appearance eligibility seasons` and `does not reuse matchup oriented populations
after an upcoming schedule change`.

## Frontend checklist

Not applicable: no components, state transitions, browser payloads or DOM changed.

## Test count and execution

`tests/Feature/NhlPregameParticipantRepairTest.php`: **30 `it(...)` cases**.
The clock is frozen, cache is isolated to the array store, jobs are faked and
stray HTTP requests are prohibited. No live provider or production data is used.

Tests, CI, lint, builds, application scripts, migrations and operational commands
were **not run**, per AGENTS.md. Verification performed: source/diff review and
`git diff --check`. Suggested human-run command:

```sh
php artisan test tests/Feature/NhlPregameParticipantRepairTest.php tests/Feature/NhlCustomGamePredictionTest.php
```

## Limits

References discarded before this change cannot be recovered from old averages
alone. One ordinary reference preparation is needed to populate the retained cache;
later matching participant repairs can reuse it. Expired or evicted references
also need preparation. Cache loss after admission fails visibly instead of
inventing ranks. No production rebuild, cancellation or worker operation is
included in this approval.

This removes avoidable league-reference regeneration on a warm matching repair.
It does not remove the separate bucket/input-history preparation observed after
context was ready, and no runtime improvement has been measured here.
