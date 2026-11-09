# Restore player PP G/GP and inspect API confidence

Approved scope: restore the existing L10-versus-Train player PP goals comparison,
remove the recently added strength points collection, and retain API Matchup.
The user also requested investigation of missing Gner8 prediction/confidence output.

## Files changed

- `app/Services/NhlPublicGamePage.php`
- `app/Services/NhlPregameContextBuilder.php`
- `tests/Feature/NhlPublicGamePageTest.php`
- `tests/Feature/NhlPregameStrengthMetricsTest.php`
- `resources/js/pages/games.test.js`
- `docs/UI_DESIGN.md`
- `docs/ENUMS.md`
- `docs/architecture/ui/NhlPublicGamePage.yaml`
- `docs/architecture/stats/NhlPregameContextEvidence.yaml`
- `docs/ARCHITECTURE_INVENTORY.md` (public game entry only)
- `docs/analysis/prediction-api-matchup-and-pp-points-review.md` (supersession notice)
- This review document.

Factors again reads personal PP goals and GP from L10 and Train, retaining the
relative percentage change and unavailable/zero rules. The Factors cache namespace
advances to avoid reusing the PP-points display payload. The builder and its tests
return to their pre-PP-points versions. No stored data is deleted or rewritten.
No context rebuild or migration is needed for this display rollback. Prediction
calculations and the new API Matchup addition are unchanged.

## Authored coverage matrix

| Contract | Written status | Named coverage |
|---|---|---|
| PP G/GP and correct L10/Train values | COVERED | shows saved historical rates without formula or fallback descriptions |
| Personal committed L10 strength context, zero baseline | COVERED | uses committed personal L10 strength context even when engine sources differ |
| Missing provenance remains unavailable | COVERED | does not guess source periods when a historical revision lacks provenance |
| Frontend PPG label and absence of PPP label | COVERED | shows red decreases and unavailable changes without inventing Train percentages |
| API Matchup remains available | COVERED (unchanged suite) | includes prior season form and the last ten cross-season meetings in API Matchup |

## Requirements checklist

1. SATISFIED in code: restore personal PP G/GP, L10 versus Train.
2. SATISFIED in code: remove the new points builder collection and its dedicated tests.
3. SATISFIED in code: preserve API Matchup and prediction calculations.
4. SATISFIED in code: update canonical descriptions and cache identity.
5. OUTSTANDING: identify Gner8's actual failing HTTP request/response before proposing an API behavior change.

## Endpoint × verb authorization/scope

No endpoint or authorization changes in this rollback. Existing prediction API
tests retain guest denial (`requires API authentication for new engine predictions`),
wrong-scope denial (`requires the NHL statistics scope for new engine predictions`)
and correct-scope allowance. Existing Factors tests cover guest and authenticated
reads (`allows guests and authenticated viewers to read curated saved contributors`)
and same-game revision scope. Organization/owner restrictions are not applicable
to these public game statistics. API Matchup behavior is retained.

## Frontend contract

Existing deterministic fake-timer/mocked-request coverage retains compact player
rows, direction/color, unavailable deltas and the PP G/GP label. No frontend runtime
behavior changes are required because the component renders the supplied metric.

## Per-file test declaration counts

- `tests/Feature/NhlPublicGamePageTest.php`: 92.
- `tests/Feature/NhlPregameStrengthMetricsTest.php`: 46.
- `resources/js/pages/games.test.js`: 124.

## Read-only production findings, 2026-11-09

Latest saved revisions 318, 320 and 319 for games 2026020283, 2026020284 and
2026020285 respectively have picks EDM, PHI and SJS and numeric confidence 73,
77 and 80. The ordinary API cache entries keyed by those game IDs contain the
same revisions, available predictions and confidence scores. Earlier revisions
had null confidence; the current entries inspected do not. No request handler,
forecast calculation, queue action or cache mutation was executed for this check.

Current successful API responses expose `prediction.confidence_score` and numeric
market confidence. HTTP 202 preparing responses intentionally contain no completed
prediction/confidence. These facts do not establish which HTTP response Gner8 is
receiving or how it is being consumed; the affected request remains necessary.

No tests/CI, migration, import, sync, rebuild or worker operation was run. Written
coverage has not been executed. `git diff --check` passed.
