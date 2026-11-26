# Season run W–L–OTL display review

## Files changed

- `app/Services/NhlSeasonGameEvaluation.php`
- `resources/js/pages/Admin/Evaluations/SeasonGames.vue`
- `tests/Feature/NhlSeasonGameEvaluationTest.php`
- `resources/js/pages/Admin/Evaluations/SeasonGames.test.js`
- `docs/architecture/stats/NhlSeasonGameEvaluations.yaml`
- `docs/testing/season-run-record-review.md`

## Minimum coverage matrix

COVERED indicates authored/retained assertions, not tests executed or passed.

| Dimension | Status | Evidence |
|---|---|---|
| A validation/auth | COVERED | Existing guest/non-admin endpoint matrices and cross-model history isolation unchanged. |
| B normalization | COVERED | REG/OT/SO, official outcome-field precedence, whitespace/case normalization, period fallback and unknown-ending default covered. |
| C persistence | COVERED | Admit run by POST, save picked results, assert four DB rows, read HTTP summary and verify no saved prediction fields changed. |
| D payload | COVERED | Additive admin summary regulation/overtime loss fields; existing total losses and win percentage retain their meanings. Public API untouched. |
| E read | COVERED | Action-driven admitted-run HTTP test checks 1–1–2 with 25% Win, proving OTL remains in the denominator. |
| F asynchronous | COVERED (unchanged) | Existing polling and admission tests retained. No new job, event or recalculation. |
| G frontend | COVERED | Placement immediately under large Win%, unchanged percentage and denominator, reactive record update preserving the DOM region. |

## Requirements checklist

1. SATISFIED: W–L–OTL appears directly below the selected run's large Win percentage.
2. SATISFIED: W counts all correct picks; L counts incorrect picks in regulation; OTL counts incorrect picks in OT/SO.
3. SATISFIED: Existing `losses` remains all incorrect picks. The Win% expression and correct/picked denominator are unchanged.
4. SATISFIED: No-picks and exclusions do not enter the record; correct OT/SO picks remain wins.
5. SATISFIED: Read stored official ending metadata using the same precedence/default as the public game page. Saved predictions, actual scores, winner flags, engine rules and algorithms are unchanged.
6. SATISFIED: Existing automatic updates refresh the record in place. No migration or prediction rerun required.

## Endpoint × verb × authorization/scope

No route or authorization changes.

| Endpoint / verb | Guest | Non-admin | Authorized / ownership |
|---|---|---|---|
| Season games GET | `denies guests all season evaluation endpoints` | `denies non-admins all season evaluation endpoints` | `shows the split record through the admitted runs page while preserving its saved picks and denominator`; `prevents selecting another models run` |
| Season runs POST | Same existing guest dataset | Same existing non-admin dataset | New action-driven split-record test begins with authorized admission |
| Resume POST / game detail GET | Existing guest dataset | Existing non-admin dataset | Existing eligibility and run/game ownership tests unchanged |

These remain global super-admin model-owned resources; no new tenant scope.

## Frontend component checklist

- Existing fixed clock, fake timers and mocked HTTP/Inertia retained.
- Record is the next sibling immediately beneath the large percentage.
- 1 win, 1 regulation loss and 2 OTL displays 25%, with 1/4 correct/picked.
- Reactive changes update the same record/percentage DOM nodes.
- Existing quiet polling, preserved expanded details and scroll behavior untouched.

## Per-file test declaration counts

| File | `it(...)` declarations |
|---|---:|
| `tests/Feature/NhlSeasonGameEvaluationTest.php` | 52 |
| `resources/js/pages/Admin/Evaluations/SeasonGames.test.js` | 40 |

Four PHP declarations added (13 cases including datasets), plus three component tests. Both files exceed the repository minimum.

## Checks actually run

- Scoped implementation, regression and documentation diff reviewed.
- `git diff --check` passed.
- Declaration counts inspected with `rg`.
- No tests, lint, CI, builds, migrations, imports, workers or production operations run.

Human-run checks (not executed by Codex):

```sh
php artisan test tests/Feature/NhlSeasonGameEvaluationTest.php
npx vitest run resources/js/pages/Admin/Evaluations/SeasonGames.test.js
```

No migration required. No prediction rerun required.
