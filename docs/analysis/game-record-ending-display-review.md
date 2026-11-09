# Game-ending display correction

Approved scope: correct public season, last-ten and head-to-head W-L-OTL records
and Final / OT / SO labels. Pregame context, prediction inputs, scoring, stored
game results, imports and statistics pipelines are unchanged.

`NhlPublicGamePage` previously treated `ot_in_use` and `shootout_in_use` as
evidence of an overtime/shootout ending. The importers copy provider rule flags
into those fields; they are not the recorded ending type.

The display now prefers recognized `game_outcome.lastPeriodType`, then
`game_outcome.periodType`, then the recorded `period_type`. Case and whitespace
are normalized. An explicit outcome takes priority over a stale period
descriptor. Actual OT/SO losses populate OTL; regulation losses populate L.
Wins still depend exclusively on the final score. The final-status label uses
that same helper in both lightweight and prepared responses. No recognized ending
retains the standard-loss/generic-Final fallback; enabled rules do not establish
an overtime ending.

Public presentation cache identity changes so previously cached bad final labels
are not reused. No cache-flush command, database migration, reimport, pregame
rebuild or prediction rerun is required by this correction.

## Files

- `app/Services/NhlPublicGamePage.php`
- `tests/Feature/NhlPublicGamePageTest.php`
- `docs/architecture/ui/NhlPublicGamePage.yaml`
- This review document.

## Authored coverage matrix

Coverage describes written regression tests; no test command was run.

| Contract | Status | Named coverage |
|---|---|---|
| Season/L10/opponent regulation-loss classification | COVERED | keeps regulation losses out of OTL in season last ten and opponent records |
| Actual OT/SO despite disabled rule flags | COVERED | counts actual overtime and shootout losses even when rule flags are false |
| Last-ten and opponent cohort boundaries | COVERED | keeps the last ten and opponent windows separate from older season games |
| Period descriptor fallback | COVERED | falls back to the recorded period descriptor when the outcome is absent |
| Missing evidence | COVERED | does not infer an overtime loss from enabled rules when ending evidence is missing |
| First-paint/prepared labels | COVERED | uses outcome ending for both first paint and prepared final labels |
| Outcome fallback and normalization | COVERED | supports the stored outcome periodType fallback and normalizes casing |

The existing special-teams record fixture now supplies actual OT outcome evidence
rather than relying on an enabled rule flag.

## Requirements checklist

1. Use recorded REG/OT/SO ending rather than enabled-rule flags: SATISFIED in code.
2. Apply the same classification to season, L10 and opponent records: SATISFIED.
3. Correct both stored-page and first-paint final labels: SATISFIED.
4. Preserve final-score wins and all prediction/context calculations: SATISFIED.
5. Regression coverage and canonical documentation: SATISFIED as authored.
6. No schema change or operational command: SATISFIED.

## Endpoint/auth scope and frontend checklist

- GET `/games/{game}/details?section=matchup`: existing public guest/ordinary-user
  access retained; new season/L10/head-to-head case writes stored results and
  verifies the resulting record through HTTP.
- GET `/games/{game}`: public access and lineup-management authorization unchanged;
  final-label case writes outcome evidence then verifies both prepared reads and
  the Inertia overview through HTTP.
- NHL game results are global resources; no tenant/organization scope is added.
- No frontend component or interaction changes. Existing formatting consumes the
  corrected counts/status strings; no JavaScript tests need modification.

## Test count and verification

`tests/Feature/NhlPublicGamePageTest.php` contains **86 it declarations**, with
datasets expanding the new period cases. Full existing tests remain intact.
Fixtures use fixed clocks, existing faked providers/queues and array cache. No new
global helpers or nondeterministic inputs were introduced.

Only `git diff --check` was run. Test execution remains for the human:

```sh
php artisan test tests/Feature/NhlPublicGamePageTest.php
```
