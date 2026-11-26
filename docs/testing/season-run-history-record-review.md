# Run History W–L–OTL review

## Files changed

- `app/Http/Controllers/Admin/NhlSeasonGameEvaluationController.php`
- `app/Services/NhlSeasonGameEvaluation.php`
- `resources/js/pages/Admin/Evaluations/SeasonGames.vue`
- `tests/Feature/NhlSeasonGameEvaluationTest.php`
- `resources/js/pages/Admin/Evaluations/SeasonGames.test.js`
- `docs/architecture/stats/NhlSeasonGameEvaluations.yaml`
- `docs/testing/season-run-history-record-review.md`

## Minimum coverage matrix

COVERED means authored or retained assertions; tests were not run.

| Dimension | Status | Evidence |
|---|---|---|
| A validation/auth | COVERED | Existing guest/non-admin endpoint datasets and cross-model history ownership tests retained. |
| B normalization | COVERED | Same ending precedence and defaults as the main record; empty runs, regulation/unknown endings and per-run counts covered. Existing OT/SO cases retain shared-rule coverage. |
| C persistence | COVERED | Admit a run through POST, save independent results for two runs, assert DB count, then inspect HTTP run history and selected summary. No schema change. |
| D payload | COVERED | Additive regulation/overtime loss counts per history row; existing Win% and total incorrect picks unchanged. Public API unchanged. |
| E HTTP reads | COVERED | Action-driven history/selected-summary read; unknown and empty-history HTTP cases. |
| F asynchronous | COVERED (unchanged) | Existing silent polling retained; component test changes history props and verifies in-place update. No new job/event. |
| G frontend | COVERED | Placement beneath each percentage, 10px history versus 12px main record, independent cards and stable reactive DOM checked. |

## Requirements checklist

1. SATISFIED: Every Run History card displays its own W–L–OTL beneath Win%.
2. SATISFIED: History text is 10px; main record remains 12px.
3. SATISFIED: Shared overtime-loss classification preserves the existing main record, original total losses and both Win% formulas.
4. SATISFIED: Batch lookup covers the paginated history; no full accuracy-summary calculation per card.
5. SATISFIED: Remove the card's redundant two-part W/L text while retaining completed/total game counts; display the requested three-part record in its new position.
6. SATISFIED: No prediction reruns, scoring changes, migrations or production operations.

## Endpoint × verb × authorization/scope

| Endpoint / verb | Guest | Non-admin | Authorized / ownership |
|---|---|---|---|
| Season games GET | `denies guests all season evaluation endpoints` | `denies non-admins all season evaluation endpoints` | `shows each history runs own W L OTL split without changing their win percentages`; `prevents selecting another models run` |
| Season runs POST | Same existing guest dataset | Same existing non-admin dataset | New action-driven history test begins with authorized run admission |
| Resume POST and game-detail GET | Existing guest dataset | Existing non-admin dataset | Existing run/game ownership and resume tests unchanged |

No new organization, owner, permission or API scope is introduced.

## Frontend component checklist

- Fixed time and mocked HTTP/Inertia; no external requests.
- Independent records for two cards; existing percentages unchanged.
- Record follows its card's percentage and uses the smaller font class.
- Automatic history updates preserve the same record node.
- Existing dark styling, scroll, focus and polling behavior retained.

## Per-file test declaration counts

| File | `it(...)` declarations |
|---|---:|
| `tests/Feature/NhlSeasonGameEvaluationTest.php` | 55 |
| `resources/js/pages/Admin/Evaluations/SeasonGames.test.js` | 43 |

Three PHP declarations (four cases) and three component tests added. Both files exceed the minimum.

## Checks actually run

- Scoped source, tests and documentation diff reviewed.
- `git diff --check` passed; declaration counts inspected with `rg`.
- No tests, lint, CI, builds, migrations, queue or production commands executed.

Human-run checks (not executed by Codex):

```sh
php artisan test tests/Feature/NhlSeasonGameEvaluationTest.php
npx vitest run resources/js/pages/Admin/Evaluations/SeasonGames.test.js
```

No migration or prediction rerun required.


## Approved follow-up: numbers only

Files touched in this follow-up are `resources/js/pages/Admin/Evaluations/SeasonGames.vue`,
`resources/js/pages/Admin/Evaluations/SeasonGames.test.js`,
`docs/architecture/stats/NhlSeasonGameEvaluations.yaml`, and this review.
Remove the visible W–L–OTL legend in both locations; retain only the dash-separated
numbers. Existing DOM assertions now require the exact numeric text for the main
and history records. Font sizes, accessible record names, percentages, counts,
authorization and backend behavior are unchanged.

Coverage B/G and the numbers-only requirement are COVERED/SATISFIED by authored
exact-text assertions; other matrix and auth cells remain unchanged/not affected.
The sole modified test file retains 43 `it(...)` declarations. Diff inspection,
declaration counting and `git diff --check` were performed; no tests, CI, builds,
migrations or production operations were run. No migration required.
