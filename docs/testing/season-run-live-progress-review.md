# Season run live progress review

## Files changed

- `app/Services/NhlCustomGameForecast.php`
- `app/Http/Controllers/Admin/NhlSeasonGameEvaluationController.php`
- `resources/js/pages/Admin/Evaluations/SeasonGames.vue`
- `resources/js/pages/Admin/Evaluations/SeasonGames.test.js`
- `tests/Feature/NhlSeasonGameEvaluationTest.php`
- `docs/architecture/stats/NhlSeasonGameEvaluations.yaml`
- `docs/ENUMS.md`
- `docs/testing/season-run-live-progress-review.md`

## Minimum coverage matrix

COVERED means authored or retained assertions, not tests executed or passed.

| Dimension | Status | Evidence |
|---|---|---|
| A validation/auth | COVERED | Existing guest/non-admin endpoint matrices and cross-model run isolation retained. |
| B normalization | COVERED | History versus Test counts, defensive bucket units, missing legacy totals, terminal elapsed duration and compact elapsed formatting. |
| C persistence | COVERED | Accepted run DB assertion → actual bounded checkpoint publication → Inertia read; frozen identity assertion. No schema changes. |
| D payload | COVERED | Admin selected checkpoint stage/count/unit, ISO last activity and elapsed seconds checked through HTTP. Public API unchanged. |
| E read | COVERED | Action-driven HTTP test and subsequent checkpoint-derived read cases; no prediction rows created by polling. |
| F asynchronous | COVERED | Existing five-second poll retained; repeated callback scheduling, hidden/completed/unmounted behavior and no prediction submissions covered. |
| G frontend | COVERED | Reactive stage transition, stable DOM region, retained expanded rows, accessible progress values, quiet reload and time display assertions. |

## Requirements checklist

1. SATISFIED: Current stage identifies collection, fitting, historical preparation or Test prediction.
2. SATISFIED: Stage processed/total counts use games or buckets; Train games do not count as Test predictions. Old checkpoints without totals remain unknown.
3. SATISFIED: Last activity uses the latest checkpoint commit or run activity; elapsed runtime includes time since admission and freezes on terminal runs.
4. SATISFIED: Existing automatic five-second polling updates the existing region with preserved scroll/state and no global loading indicator. Expanded details remain mounted.
5. SATISFIED: No equations, Train evidence, roster selection, scoring versions, frozen run identity, worker settings or replay cursors changed. Existing active runs can adopt the added manifest fields on their next normal checkpoint write.
6. SATISFIED: Progress reads use only the compact manifest. No accumulated checkpoint hydration or new historical database calculation is added to page reads.
7. SATISFIED: Regression coverage and canonical stage/UI rules documented. No migration required.

## Endpoint × verb × authorization/scope checklist

Existing global super-admin routes retain their authorization and model/run ownership boundaries.

| Endpoint / verb | Guest | Non-admin | Authorized / scope |
|---|---|---|---|
| `/admin/nhl-sat-models/{model}/season-games` GET | `denies guests all season evaluation endpoints` | `denies non-admins all season evaluation endpoints` | `publishes historical preparation counts through the admitted runs automatic read path`; `prevents selecting another models run` |
| Same base `/runs` POST | Same guest dataset | Same non-admin dataset | `publishes historical preparation counts through the admitted runs automatic read path` |
| Same base `/runs/{run}/resume` POST | Same guest dataset | Same non-admin dataset | Existing resume eligibility and cross-model tests unchanged |
| Same base `/runs/{run}/games/{prediction}` GET | Same guest dataset | Same non-admin dataset | Existing saved-detail ownership tests unchanged |

No public API contract or organization-specific authorization is introduced.

## Frontend component checklist

- Controlled fake clock and timers; mocked Inertia/HTTP; no external requests.
- Assert reactive history → prediction stage change and progressbar values.
- Unknown legacy totals do not invent a percentage.
- Repeated polls suppress global progress UI and preserve state/scroll.
- Existing expanded game DOM and fetched detail survive progress changes.
- Existing hidden-tab/completed-run/unmount timer tests retained.
- Existing reduced-motion width transition retained; progress region reserves space.

## Per-file test declaration counts

| File | `it(...)` declarations |
|---|---:|
| `tests/Feature/NhlSeasonGameEvaluationTest.php` | 48 |
| `resources/js/pages/Admin/Evaluations/SeasonGames.test.js` | 37 |

Six PHP declarations (one with a two-case dataset) and eight component cases added. Both files exceed the repository minimum.

## Checks actually run

- Source, component, regression and documentation diff reviewed.
- `git diff --check` passed.
- Declaration counts inspected with `rg`.
- No tests, lint, CI, builds, imports, queues, migrations or production operations run.

Human-run checks (not run by Codex):

```sh
php artisan test tests/Feature/NhlSeasonGameEvaluationTest.php
npx vitest run resources/js/pages/Admin/Evaluations/SeasonGames.test.js
```

No migration required. No active production run has been paused, restarted or changed by this work.
