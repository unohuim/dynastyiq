# Data-first prediction context review

Approved scope: use usable exact context regardless of a failed owning run; if
missing, predict from the latest usable context with age-adjusted days_away, then
request scoped context building after publication. No production recovery, worker
operation, historical backfill or schema change is included.

## Files

- `app/Services/NhlCustomGameForecast.php`: remove pregame-run admission/waiting;
  invalidate prepared inputs when committed context changes and isolate old policy checkpoints.
- `app/Services/NhlCustomGamePredictionInputs.php`: exact/latest committed data
  selection, original source provenance, per-game cutoff bounds, age source and repair flag.
- `app/Services/NhlGamePredictionPayload.php`: shared adjusted horizon calculation
  and source-date/repair metadata in the published context block.
- `app/Services/NhlGamePredictionHistory.php`: retain the adjusted horizon,
  calculate public confidence from it and dispatch repair only after saving/commit.
- `app/Jobs/UpdateNhlPredictionPregameContextJob.php`: unique model/game follow-up
  on projections, reusing the existing scoped context builder.
- `config/nhl-custom-prediction.php`: input pipeline v11; scoring remains v4.
- `tests/Feature/NhlCustomGamePredictionTest.php`
- `tests/Feature/NhlDataFirstPredictionContextTest.php`
- `docs/architecture/stats/NhlCustomGamePrediction.yaml`
- `docs/architecture/stats/NhlGamePredictionHistory.yaml`
- `docs/architecture/stats/NhlGamePredictionResponseCache.yaml`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/ENUMS.md`
- `docs/ARCHITECTURE_INVENTORY.md`
- This review.

## Minimum coverage matrix

Statuses describe authored assertions, not executed tests.

| Category | Status | Evidence |
| --- | --- | --- |
| A: Validation | COVERED | Model/version/cutoff bounds, game/publication identity, missing selected players, API authentication/scope. |
| B: Defaults/selection | COVERED | Exact data survives owning-run failure; older snapshots and prior-result fallbacks support today; later-than-cutoff rows are rejected. |
| C: Persistence | COVERED | History writer stores age-adjusted days_away and confidence; reads retain the original revision horizon on later days. |
| D: API | COVERED | Real history publication -> DB assertion -> saved API read returns adjusted horizon and public confidence. |
| E: Reads | COVERED | Successful saved read remains HTTP 200; no forecast rerun or synchronous context generation. |
| F: Async effects | COVERED | Publication precedes repair; after-commit dispatch, model/game uniqueness, queue routing, active/paused build wait, future-day delay and repair failure isolation. |
| G: Frontend | Not applicable | No components, DOM or navigation changes. |

## Requirements

1. SATISFIED in code/authored assertions: no pregame job status is a prediction
   prerequisite, including obsolete failed dependencies in saved checkpoints.
2. SATISFIED: usable committed same-model/version exact context is preferred;
   missing data selects latest bounded context through the existing fallback ladder.
   Completed-game data markers remain distinct from the parent run status.
3. SATISFIED: every target retains its actual lineup/opponent/schedule and its own
   request/start cutoff. Missing identities, invalid model/Train or corrupt numeric
   inputs remain real errors; this change does not manufacture an eligible roster.
4. SATISFIED: new today/future horizons add nonnegative context age. Source date
   uses the oldest actually reused context/cutoff date; ignored prior-season
   personal snapshots do not add false age. With no snapshots, use the available
   chronological reader's latest prior-result date. Historical signed horizons and
   legacy payloads without source metadata keep their existing behavior.
5. SATISFIED: public confidence uses that adjusted horizon at publication; goals,
   win probability, internal confidence equations and past revisions are untouched.
6. SATISFIED: successful committed revisions alone may queue missing-context
   repair afterward. Requests coalesce by model/game; active/paused builds remain
   authoritative. Future-game repairs are delayed until their Toronto game day.
7. SATISFIED: the follow-up job checks publication identity, current coverage and
   source evidence, then calls the existing scoped builder. A race yields for a
   retry, and repair failures cannot undo the successful prediction.
8. SATISFIED by source review: new preparation namespace and pipeline version
   prevent reuse of older mandatory-job checkpoints. No migrations, production
   data writes, queue moves or worker operations were performed.

## Endpoint × verb auth/scope matrix

| Endpoint | Guest | Wrong scope | Authorized |
| --- | --- | --- | --- |
| GET /api/nhl-game-predictions | requires authentication to read a saved forecast with its adjusted context age | requires stats read scope to read the adjusted prediction horizon | serves the persisted adjusted horizon and public confidence through the saved API read |

The endpoint and middleware remain unchanged; this is global NHL data without an
organization/owner boundary. Job publication identity and unique model/game scope
are covered by missing successful revision and distinct repair identity tests.
Existing API/team/model separation tests remain in the surrounding suites.

## Frontend checklist

Not applicable: no frontend files or interactive state changed.

## Counts and verification

| Test file | it(...) declarations |
| --- | ---: |
| tests/Feature/NhlCustomGamePredictionTest.php | 144 |
| tests/Feature/NhlDataFirstPredictionContextTest.php | 27 |

Clocks are fixed, HTTP is prohibited and dispatch is faked. Existing fixture
contexts now include committed game markers; boundary mocks explicitly assert
that model context preparation is not called in prediction admission.

Source/diff review and `git diff --check` were performed. Tests, CI, lint, builds,
imports, migrations and production operations were not run under AGENTS.md.
Human-run validation remains outstanding:

```sh
php artisan test tests/Feature/NhlDataFirstPredictionContextTest.php tests/Feature/NhlCustomGamePredictionTest.php tests/Feature/NhlOperationalGamePredictionsTest.php tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php
```

No migration required. Deploying the code does not rewrite failed admin prediction
attempts or restart production runs. A newly requested calculation uses this policy.
