# Prediction forecast horizon and latest-context fallback

## Files changed or created

- `database/migrations/2026_11_11_000001_add_forecast_horizon_to_nhl_game_prediction_revisions.php`
- `app/Services/NhlCustomGameForecast.php`
- `app/Services/NhlCustomGamePredictionInputs.php`
- `app/Services/NhlGamePredictionHistory.php`
- `app/Services/NhlGamePredictionPayload.php`
- `app/Services/NhlGamePredictionResponseCache.php`
- `app/Services/NhlPregameContextBuilder.php`
- `config/nhl-custom-prediction.php`
- `tests/Feature/NhlCustomGamePredictionTest.php`
- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`
- `docs/architecture/stats/NhlCustomGamePrediction.yaml`
- `docs/architecture/stats/NhlGamePredictionHistory.yaml`
- `docs/architecture/stats/NhlGamePredictionResponseCache.yaml`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/DB_SCHEMA.md` (forecast-horizon addition only; preserve existing import-index changes)
- `docs/ENUMS.md`
- `docs/ARCHITECTURE_INVENTORY.md` (prediction-readiness addition only)
- This review document.

## Minimum coverage matrix

COVERED means deterministic assertions are authored, not that they have run.
No tests, migrations, imports, queue jobs or season operations were executed.

| Area | Status | Named coverage |
| --- | --- | --- |
| Request validation and scope | COVERED | `requires API authentication for new engine predictions`; `requires the NHL statistics scope for new engine predictions`; existing validation cases |
| Calendar normalization and signed horizon | COVERED | `stores calendar days away and calculated public confidence while retaining internal confidence` (today, tomorrow, week, past); `uses Toronto dates before UTC midnight rollover for forecast horizon`; `counts calendar days rather than elapsed hours across daylight saving` |
| Persistence and API read | COVERED | Horizon test performs authenticated HTTP request, checks revision columns, then checks cached HTTP read and revision identity |
| Approved public rating | COVERED | `calculates the adopted public confidence examples without a manufactured ceiling`; `uses the home selected probability and preserves public confidence for an even matchup`; `leaves public confidence unavailable when required confidence evidence or horizon is missing` |
| Immutable history | COVERED | `preserves the archived horizon when reading a revision on a later date`; `leaves historical revisions without horizon metadata explicitly unavailable` |
| Current exact-game guard | COVERED | `rejects queued preparation without saved lineup context before any raw replay`; existing same-cutoff and parent-link assertions |
| Future latest committed context | COVERED | `reuses committed context for future targets without building target context or reading target results` |
| Future missing snapshot | COVERED | `uses prior player history when future context is absent without weakening same-day requirements` |
| Source bucket cutoff | COVERED | `aligns future personal bucket counts to the reused snapshot cutoff rather than newer results` |
| Target schedule | COVERED | `derives future rest and density from intervening scheduled games instead of old context` |
| Future dependency admission | COVERED | `skips the pregame dependency queue when preparing a future date through the shared forecast boundary` |
| Uncommitted evidence | COVERED | `does not reuse uncommitted context rows for future forecasts` |

## Requirements checklist

1. SATISFIED: Add nullable signed `days_away` and nullable decimal `public_confidence_score` to immutable operational revisions through a new migration; no backfill.
2. SATISFIED: Freeze Toronto calendar horizon at revision publication; preserve it on cache/history reads and idempotent manual retries.
3. SATISFIED: Preserve internal confidence, probabilities and goal equations; public score uses the approved uncapped rating, rounded to two decimals: `50 + 1.1 × (P − 50) + 0.2 × (I − 55) − 2 × max(0, D)`. P is winning probability on a percentage scale, I is internal confidence, D is days away.
4. SATISFIED: Today/past-date context admission and historical Test validation remain exact-game scoped.
5. SATISFIED: Tomorrow/later bypasses target context jobs, uses same-model committed snapshots and keeps anticipated lineup/starter acquisition unchanged.
6. SATISFIED: Batch latest entity selection; require completed comparison work. Use small per-game commit markers for future cache freshness rather than scanning every context row on requests.
7. SATISFIED: Keep personal bucket samples aligned with saved context cutoffs; missing or previous-season snapshots use the existing chronological fallback ladder.
8. SATISFIED: Compute target schedule from scheduled games, including intervening unfinished games; discard source-opponent/venue windows and source rankings.
9. SATISFIED: Archive original source IDs, cutoffs and player-parent links in private input evidence; never rewrite saved context rows or past revisions.
10. SATISFIED: Refresh input identities at Toronto day rollover and committed context changes; preserve bounded queues/checkpoints and existing API preparing responses.
11. SATISFIED: Document migration, API fields, context modes and limits. No automatic daily prediction schedule is introduced. Public confidence is calculated by one shared existing-payload helper, persisted separately, and does not alter engine outcomes.

## Endpoint × verb authorization/scope checklist

| Endpoint / verb | Caller | Expected result | Test |
| --- | --- | --- | --- |
| `/api/nhl-game-predictions` GET | Guest | 401 | `requires API authentication for new engine predictions` |
| Same | Token without `nhl-stats:read` | 403 | `requires the NHL statistics scope for new engine predictions` |
| Same | Scoped token | 200 published horizon fields | `stores calendar days away and calculated public confidence while retaining internal confidence` |
| Same | Organization/owner scoping | Not applicable: global NHL read, no organization-owned mutation | Existing endpoint contract unchanged |

No new endpoint, authentication behavior or admin authorization change is introduced.

## Frontend component checklist

Not applicable: no frontend files or interactive behavior changed.

## Per-file test counts

- `tests/Feature/NhlCustomGamePredictionTest.php`: 107 `it(...)` declarations.
- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`: 67 `it(...)` declarations.

Both exceed the twenty-declaration minimum. Tests use fixed clocks, fixtures,
faked dispatch and blocked external HTTP. Runtime results remain unverified.

## Human-run commands

**Migration required:**
`2026_11_11_000001_add_forecast_horizon_to_nhl_game_prediction_revisions.php`.
Run before using the changed prediction publisher:

```sh
php artisan migrate
php artisan test tests/Feature/NhlCustomGamePredictionTest.php tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php
```

Only source review and `git diff --check` were performed here. Unrelated local
import-index changes, tests and metadata files remain untouched.
