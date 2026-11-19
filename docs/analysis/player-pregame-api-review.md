# Revision-bound player pregame API review

## Files changed

- `app/Services/NhlGamePredictionHistory.php`: capture public player details and
  private curated page analysis at publication; bind saved responses to their row.
- `app/Services/NhlPublicGamePage.php`: share contributor/comparison/insight rules,
  read frozen page analysis, expose public numeric fields and safe legacy fallback.
- `tests/Feature/NhlPublicGamePageTest.php`: API/page regression coverage and
  intentional revision-publication updates to prior dynamic-context tests.
- `docs/integrations/DIQ-API-Usage-Doc.md`: field contract, units, provenance and sample.
- `docs/ENUMS.md`: stable public metric, unit and window keys.
- `docs/architecture/stats/NhlGamePredictionHistory.yaml`: publication invariant.
- `docs/architecture/stats/NhlGamePredictionResponseCache.yaml`: saved API invariant.
- `docs/architecture/ui/NhlPublicGamePage.yaml`: shared immutable analysis invariant.
- `docs/ARCHITECTURE_INVENTORY.md`: derived summary.
- `docs/analysis/player-pregame-api-review.md`: this review record.

No migration, scoring equation, lineup selection, authorization, dependency,
queue configuration or production data changes. Pre-existing unrelated working
changes are excluded from this delivery.

## Minimum coverage matrix

COVERED below means authored coverage, **not executed or passing tests**.
The full cohesive test source remains in `tests/Feature/NhlPublicGamePageTest.php`.

| Requirement | Status | Test name(s) |
| --- | --- | --- |
| Publication action → stored evidence → saved HTTP response | COVERED | serves the published player snapshot through the saved prediction API path |
| API/page contributor parity, L10 leaders plus projected scorers, EV/PP components | COVERED | matches API contributor ordering and projected components to the page revision; includes the leading L10 points player even outside the top three goal projections |
| Stable four metrics, numeric baseline/recent values, units and samples | COVERED | publishes stable comparison keys with numeric baselines units and samples |
| Signed deltas, real zeros, zero baseline null | COVERED | keeps player insight percentage changes signed while the page retains directional magnitude; keeps comparison zeros numeric and zero baselines unknown |
| Same selected player insight subset, not independent reranking | COVERED | exports only the player insights already selected for the public page |
| Actual frozen Train IDs and label | COVERED | freezes the actual baseline seasons independently of later model edits |
| Revision/time/context and engine cutoff identity | COVERED | returns publication and cutoff timestamps with the same revision identity; does not capture context with a source cutoff later than the engines input cutoff |
| Exact calculated lineup and old revision pinning | COVERED | pins old page contributors when a newer revision changes the prediction lineup; uses the returned noncanonical revision rather than the pages latest canonical revision |
| Exact model, parent, player context identity and source provenance | COVERED | ignores historical context from another model; requires the exact archived parent context identity when one was recorded; requires the exact archived player context identity when one was recorded; does not substitute a refreshed context for the sources actually used by the engine |
| Opponent, cutoff, completed publication boundaries | COVERED | excludes mismatched opponents and mismatched player cutoffs; rejects uncommitted contexts without blocking a saved prediction; does not capture context modified after publication time |
| Snapshot stable after context refresh and puck drop | COVERED | keeps the API snapshot unchanged after puck drop and context replacement; keeps published facts frozen until a new revision captures updated context |
| Missing and legacy context stays unknown, projections remain available, no rewrite | COVERED | keeps projected contributors with null comparisons when historical evidence is absent; reads legacy projections and archived comparisons without borrowing current context; keeps a legacy explicit null source cutoff unknown without blocking projections |
| No private bucket trees or algorithm leakage | COVERED | does not leak private inputs or algorithm evidence in the player block |
| Existing insight thresholds, ranking, windows and roster eligibility | COVERED | rejects short samples and differences below the approved threshold; accepts exactly five games and exactly twenty five percent difference; deduplicates repeated windows for the same player metric; includes opponent history and labels the sample explicitly; omits player history outside the displayed prediction roster |
| Existing cross-game and canonical selection validation | COVERED | does not expose another games revision through a pinned explanation request; does not publish a request variant as the canonical pregame explanation |
| Authentication and API scope | COVERED | requires the existing API token before serving player context; requires the existing NHL statistics scope before serving player context |

## Numbered requirements checklist

1. SATISFIED in implementation/coverage: additive `player_pregame` for saved and
   newly published successful responses, without new request parameters.
2. SATISFIED: shared page contributor/comparison/insight selection; no new ranking
   algorithm or substitution of pooled rates.
3. SATISFIED: exact returned revision, calculated lineup, model, historical Train
   and source-cutoff identity; snapshots survive subsequent refreshes/puck drop.
4. SATISFIED: canonical IDs, names, L10 GP/PTS per game, total/EV/PP projections.
5. SATISFIED: four numeric comparisons with units, signed deltas and samples;
   descriptive insights remain distinct from scoring inputs.
6. SATISFIED: older revisions retain archived projections/history, unknown fields
   and empty insights without current-context reconstruction or historical writes.
7. SATISFIED: missing player context does not queue work or prevent an otherwise
   valid prediction; existing injury-safety rules remain authoritative.
8. SATISFIED: API usage example and canonical architecture/key documentation.
9. SATISFIED: existing JSON storage; no migration or prediction-math change.
10. Validation pending human execution: automated checks were not authorized.

## Endpoint × verb authorization/scope checklist

| Endpoint / verb | Access | Test name |
| --- | --- | --- |
| GET `/api/nhl-game-predictions` | Guest denied, 401 | requires the existing API token before serving player context |
| GET `/api/nhl-game-predictions` | Token without `nhl-stats:read` denied, 403 | requires the existing NHL statistics scope before serving player context |
| GET `/api/nhl-game-predictions` | Existing scoped API client allowed, 200 | serves the published player snapshot through the saved prediction API path |
| GET `/games/{game}/details?section=factors` | Guests and authenticated viewers allowed | allows guests and authenticated viewers to read curated saved contributors |
| GET `/games/{game}/details?section=factors&prediction_revision_id=…` | Another game's revision excluded | does not expose another games revision through a pinned explanation request |

NHL games are shared public data. No organization, tenant or owner scope is added.
No endpoint method, validation rule or permission changes.

## Frontend component checklist

Not applicable: no Vue, DOM, motion, global state or frontend component changes.
The existing Factors rendering keeps its human labels and directional insight
magnitudes; numeric baseline fields are additive payload metadata.

## Per-file test counts and checks

`tests/Feature/NhlPublicGamePageTest.php`: **138** `it(...)` declarations
(113 existing, 25 added), plus parameterized cases. Existing context-mutation
cases now publish a new revision explicitly, and one regression asserts that
published facts remain frozen until that publication. The full file is retained
in the repository for review.

Actually performed: source and schema review, test-count inventory and
`git diff --check`.

Not performed: tests, CI, lint, builds, imports, migrations, queue/scheduler
commands, prediction runs or production changes. No migration required.

Human validation command, not executed by Codex:

```sh
php artisan test tests/Feature/NhlPublicGamePageTest.php tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php tests/Feature/NhlLineupInjurySafetyTest.php
```
