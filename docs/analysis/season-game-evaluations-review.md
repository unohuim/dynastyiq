# Model Test-season game evaluation review

## Files changed or created

- `app/Services/NhlSeasonGameEvaluation.php`: readiness, run admission/resume,
  bounded execution, frozen goalie detail, accuracy summaries and matched comparison.
- `app/Jobs/RunNhlSeasonGameEvaluationJob.php`: projections continuation, deadline,
  generation checks and terminal error handling.
- `app/Http/Controllers/Admin/NhlSeasonGameEvaluationController.php`: authenticated
  model/run/game-scoped history, admission, resume and detail endpoints.
- `app/Services/NhlCustomGamePredictionInputs.php`: bounded chronological replay
  reader, conservative same-day result availability and bounded personal state.
- `app/Services/NhlCustomGamePredictionReplay.php`: idempotent event publication
  through existing storage, clocks, player TOI and descriptive goalie snapshots.
- `app/Services/NhlCustomGameForecast.php`: narrow wrappers reusing the existing
  bounded typed/checksummed serializer in an isolated season-run cache namespace.
- `routes/web.php`: four super-admin routes inside the existing admin group.
- `resources/views/admin/nhl-sat-models/_model-row.blade.php`: prerequisite-gated
  Run Season Games and always-accessible Season Game Results under Predict.
- `resources/js/pages/Admin/Evaluations/SeasonGames.vue`: fresh graphite Vue page.
- `tests/Feature/NhlSeasonGameEvaluationTest.php`: full cohesive backend suite.
- `resources/js/pages/Admin/Evaluations/SeasonGames.test.js`: full cohesive UI suite.
- `docs/architecture/stats/NhlSeasonGameEvaluations.yaml`: canonical administrative
  workflow; the existing engine document owns equations.
- `docs/architecture/stats/NhlCustomGamePrediction.yaml`: reference to that workflow.
- `docs/ARCHITECTURE_INVENTORY.md`: derived bootstrap entry.
- `docs/ENUMS.md`: execution metadata and descriptive actual-source labels.
- `docs/UI_DESIGN.md`: new evaluation page presentation contract.
- `docs/analysis/season-game-evaluations-review.md`: this review record.

## Scope and retained behavior

The existing custom run, game, team and player tables are reused. No migration
required. No operational/API prediction revision is created or replaced by these
runs. No new scoring formula, coefficient or goalie impact is introduced.

Only supported model-owned completed regular-season Test games are admitted.
Readiness uses the existing bucket filter and coefficient-horizon check, plus
stored source coverage and linked committed contexts. It does not demand
standalone /60 or TOI projections, reject low personal volume or fit predictions
in HTTP. Missing/corrupt content inside otherwise-present sources can still
produce a visible exclusion/error at calculation time; coverage checks do not
certify every imported value. A source gap never becomes a fabricated prediction.

The reader fits only Train, forecasts before advancing history, and defers all
same-day outcomes until a later game date because source start times alone do
not establish completion before another pregame cutoff. The old command-only
loop is unchanged; this queued workflow has that stricter availability bound.
Personal state is restricted to the Test roster union, while all eligible Train
skaters remain in team/league pools. Only LSeason/Test season accumulators are
retained. These storage bounds do not change the calculator's equations.

Every execution freezes its definition and appends a new experiment. Source
commit/model/settings/code changes require a new run. Saved successful/excluded
games survive retries and missing checkpoints without overwrite; corrupt
transient checkpoints can be discarded only by explicit Resume. Resume fences
obsolete deliveries, uses the existing run identity and retains saved outputs.
Legacy command runs remain inspectable; they do not occupy this administrative
runner's admission slot. Legacy game evaluations are not deleted or retired in
this delivery; retirement remains subject to review of the replacement.

Win% uses saved official final winners including OT/SO and shows the picked-game
denominator. Predicted/selected, exclusions and no-picks stay visible. Goal errors
use matching retained regulation EV+PP actuals rather than silently substituting
final-score totals. Player TOI is imported strength TOI and may include overtime;
its label differs from the retained shot/goal scope. Goalies retain descriptive
saved on-ice percentages and actual official appearance values; a neutral goalie
baseline is explicit, and opponent EV+PP totals are not presented as a fitted
individual-goalie projection. Missing legacy fields remain unknown.

## Finalized minimum coverage matrix

COVERED means authored coverage, **not executed or passing tests**.

| ID | Requirement | Status | Named evidence |
| --- | --- | --- | --- |
| A1 | All endpoints require super-admin authorization | COVERED | denies guests all season evaluation endpoints; denies non-admins all season evaluation endpoints |
| A2 | Model/run/game scope and compare isolation | COVERED | prevents selecting another models run; prevents comparison or resume across model ownership; rejects details for a prediction outside the selected run |
| A3 | Required readiness and actionable missing sources | COVERED | blocks a draft model with an actionable reason; blocks a model without an explicit Test season; surfaces the readers bucket or coefficient prerequisite error; reports missing imported source coverage by game; blocks unfinished games and unknown start cutoffs |
| A4 | Committed parents/player/starter cutoffs | COVERED | requires committed context comparisons; requires player context to share its teams cutoff and parent; requires starting goalie context even when skater contexts exist |
| B1 | Explicit asynchronous admission and frozen membership | COVERED | admits a run freezes its definition and reads it through the new page; rejects synchronous execution rather than calculating in HTTP |
| B2 | No duplicate active run or history overwrite | COVERED | blocks a second active run while retaining prior history; creates a separate run after a completed baseline; keeps legacy command runs inspectable without blocking the new administrative runner |
| C1 | Publication action → frozen DB → HTTP results | COVERED | admits a run freezes its definition and reads it through the new page; publishes team player and goalie snapshots and reads them without replaying the engine |
| C2 | Idempotent game commits across checkpoint retries | COVERED | does not duplicate an excluded game when retrying the same committed checkpoint; leaves a saved successful prediction untouched on checkpoint retry |
| C3 | Resume preserves snapshots and current identity | COVERED | resumes failed runs without replacing committed games; refuses resuming a run under changed algorithm settings; refuses resuming completed or recently active runs; allows abandoned runs to resume after the worker timeout and lock grace |
| C4 | Lost/corrupt checkpoint and queue generation safety | COVERED | reuses bounded typed checkpoints for isolated season readers; can discard only a broken transient checkpoint during explicit resume; ignores obsolete queue deliveries after a new resume generation |
| D1 | Winner and no-pick denominators | COVERED | calculates win percentage from saved picks and keeps no-picks separate |
| D2 | Three canonical errors, pooled versus individual units | COVERED | keeps pooled goal error distinct from individual team and game threshold accuracy |
| D3 | Matched-run correction/spoiling and actual identity | COVERED | compares only matching picked outcomes and identifies corrected losses; does not compare games whose saved actual outcomes differ |
| E1 | Frozen reads and accessible old history | COVERED | serves frozen per-game results without updating current prediction revisions; returns an empty history without dispatching prediction work; makes older saved runs reachable through paginated history |
| E2 | Menu gating, without obsolete projection prerequisites | COVERED | keeps the season menu disabled until readiness succeeds while preserving access to results; passes readiness without requiring standalone rate or TOI projections |
| F1 | projections routing, after-commit publication, fixed deadline | COVERED | dispatches a run-owned generation through the projections job after admission; routes season execution to projections with a fixed deadline |
| F2 | Target/same-day results excluded from pregame history | COVERED | keeps same-day target outcomes out of one anothers historical inputs (simultaneous and later-start variants) |
| G1 | Admission, readiness, duplicate clicks and scoped errors | COVERED | disables Run Season Games with actionable readiness reasons; queues through the model-owned endpoint and selects the returned run; prevents repeated submissions while admission is pending; shows admission validation errors without removing saved results |
| G2 | History, summary, comparison and table contracts | COVERED | selects an existing run without requesting new predictions; shows win percentage and its actual picked-game denominator above the game list; preserves the three familiar goal accuracy columns and their comparison scope; limits comparison options to other runs for the same Test season; shows exactly which matching picks were corrected or spoiled |
| G3 | On-demand detail, EV/PP, goalie labels and missing values | COVERED | loads one games saved detail only when expanded; retains loaded details when a row is collapsed and expanded again; changes EV and PP detail without another server request; identifies the actual selected goalie while labeling the neutral baseline; keeps missing historical values unknown rather than replacing them with zeros |
| G4 | Resume, silent progress, hidden/unmounted lifecycle | COVERED | resumes the existing run rather than creating another run; polls only saved progress and never starts another prediction; does not poll completed runs or hidden tabs; clears its timer when leaving the page; disables admission when the active run is on another history page |

## Numbered requirements checklist

1. SATISFIED in implementation and authored coverage: fresh menu entry/page and
   clear prerequisites, with server-side admission verification.
2. SATISFIED: same current engine and model-owned Train/Test; no legacy stack or
   quarter-outlook calculation substituted.
3. SATISFIED: reuse existing tables and preserve versioned run/game snapshots.
4. SATISFIED: bounded queued work, typed checkpoints, idempotent commits and Resume.
5. SATISFIED: headline win record, picked/selected counts, three goal errors and
   explicit comparison scopes.
6. SATISFIED: paginated games/history and expandable teams, EV/PP players and starters.
7. SATISFIED: matched-run win changes and explicit unmatched/no-pick counts.
8. SATISFIED: existing history retained; no public/API forecast overwrite.
9. SATISFIED: canonical architecture, enum and UI documentation updated.
10. SATISFIED: no migration, dependency changes, production operations or new
    prediction formula.
11. Human validation outstanding: tests, builds, rendered-browser review and a
    production Test-season run have not been executed.

## Endpoint × verb auth/scope checklist

| Endpoint / verb | Guest | Signed in without super-admin | Super-admin and scoped record |
| --- | --- | --- | --- |
| GET model season-games | 401: denies guests all season evaluation endpoints | 403: denies non-admins all season evaluation endpoints | admits a run freezes its definition and reads it through the new page; model scope enforced |
| POST model season-games/runs | Same guest matrix | Same non-admin matrix | admits a run freezes its definition and reads it through the new page; readiness/active guards |
| POST model season-games/runs/{id}/resume | Same guest matrix | Same non-admin matrix | resumes failed runs without replacing committed games; cross-model 404 |
| GET model season-games/runs/{id}/games/{prediction} | Same guest matrix | Same non-admin matrix | publishes team player and goalie snapshots and reads them without replaying the engine; wrong-run prediction 404 |

These are global NHL administration resources. No organization/tenant/owner
permission is added; all new routes remain inside the established admin group.

## Frontend component checklist

- COVERED: readiness/active disable rules, explicit loading/error states and duplicates.
- COVERED: model/Train/Test identification, saved run selection, summaries and pagination.
- COVERED: same-season baseline selection and named changed-game outcomes.
- COVERED: lazy detail, caching/retry, EV/PP state and goalie/missing-value presentation.
- COVERED: deterministic timers, visibility checks and disposal; no new global state.
- IMPLEMENTED: graphite layout, caret/grid-height/opacity disclosure with reduced
  motion and inert collapsed content. Rendered/browser verification is not run.

## Per-file test count report

- `tests/Feature/NhlSeasonGameEvaluationTest.php`: **40** `it(...)` declarations,
  including additional parameterized authorization/source/status/temporal cases.
- `resources/js/pages/Admin/Evaluations/SeasonGames.test.js`: **29** `it(...)`
  declarations with deterministic Vue mounts, mocked requests and fake timers.
- Both new test files exceed the minimum twenty declarations and are retained as
  full cohesive source files in the repository.

## Checks actually performed and human validation

Performed: source/migration/architecture review, test-count inventory and
`git diff --check`. No automated tests, CI, lint, builds, migrations, imports,
queue/scheduler commands, worker changes, production writes or prediction runs.
No migration required. Production runtime and memory have not been measured.

Human validation commands, **not executed by Codex**:

```sh
npm test -- --run resources/js/pages/Admin/Evaluations/SeasonGames.test.js
php artisan test tests/Feature/NhlSeasonGameEvaluationTest.php tests/Feature/NhlCustomGamePredictionTest.php tests/Feature/NhlBuildPredictionsTest.php
npm run build
```

After deployment, open the model's Predict menu. Inspect Season Game Results for
any missing prerequisites, then use Run Season Games to create the baseline.
This code-change approval did not authorize an agent-triggered production run.
