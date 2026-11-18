# Requested markets from saved predictions

## Files changed

- `app/Http/Controllers/Api/NhlGamePredictionsController.php`
- `app/Services/NhlGamePredictionPayload.php`
- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`
- `docs/architecture/stats/NhlGamePredictionHistory.yaml`
- `docs/architecture/stats/NhlGamePredictionResponseCache.yaml`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/analysis/saved-prediction-market-pricing-review.md`

The prediction controller continues to return the latest healthy successful
revision before consulting preparation/cache freshness. Explicit market selectors
and lines now decorate that response through the existing payload service.
Pricing reads only saved scores, identities, algorithm provenance and probabilities;
it does not calculate a new game, change a pick, queue work or create a revision.
No market parameters retain the saved publication unchanged.

Requested filters, defaults, multiple lines and deduplication reuse the existing
pricing methods. Saved moneyline rows retain their probability/odds; if a custom
forecast originally published only line markets, missing moneyline rows use its
stored home/away winning probabilities. New rows retain the saved algorithm
version, even if current configuration differs. Existing public-confidence
mapping runs after pricing. Saved timestamps, horizon, Matchup and lineups remain
frozen. Historical saved predictions support the same read-only pricing.

Use saved unrounded selection goals, preserving existing legacy total
normalization. Only older payloads lacking unrounded goals fall back to their
saved displayed scores. Missing/invalid scoring or identity evidence returns
market validation rather than fabricated rates or preparation. Valid zeros remain
valid. Unavailable predictions and upcoming injury preparation retain their
existing behavior.

## Minimum coverage matrix

COVERED means authored and source-reviewed, not executed or passing.

| Requirement | Status | Test names |
| --- | --- | --- |
| First calculation → DB revision → requested lines via saved HTTP read; no rerun/writes | COVERED | prices requested lines from a saved moneyline prediction without recalculating or writing a revision |
| Explicit filters/defaults and multiple lines | COVERED | honors explicit market filters on an existing saved prediction; uses default lines when a saved read requests a market without its lines; deduplicates combined single and multiple lines while preserving existing spread sign normalization |
| Moneyline and schedule treatment preserved | COVERED | retains saved moneyline probabilities including the existing schedule adjustment; reconstructs missing moneyline rows using the saved custom winner probabilities |
| Confidence and saved algorithm identity | COVERED | uses the saved public confidence and algorithm provenance for newly priced markets; keeps missing public confidence null for added line markets |
| Historical saved reads and unavailable past games | COVERED | prices historical saved predictions without starting new work; does not fabricate markets or queue predictions for a historical game without a saved success |
| Existing push/formula behavior | COVERED | preserves existing line push behavior when pricing a saved prediction; existing market sum, line orientation and fair-odds tests retained |
| No selectors preserve original publication | COVERED | keeps requests without market selectors equal to the saved publication |
| Unrounded scores, legacy totals and true zeros | COVERED | uses unrounded saved selection goals rather than rounded displayed scores for requested totals; retains saved legacy total normalization when pricing requested totals; retains valid zero goals and falls back to displayed scores only when unrounded goals are absent |
| Missing/corrupt evidence is explicit | COVERED | refuses to fabricate requested line prices from saved predictions missing goal estimates; rejects unusable saved legacy total normalization without silently substituting another score |
| Forecast overrides stay separate; arrays remain unchanged | COVERED | ignores changed forecast overrides while honoring requested markets on a saved read; does not mutate the supplied prediction or invent markets for an unavailable forecast |
| API authorization | COVERED | keeps requested line pricing behind the existing API authorization boundary; existing guest and wrong-scope cases |

## Numbered requirements checklist

1. SATISFIED: honor requested puckline/total lines when a saved prediction exists.
2. SATISFIED: use frozen saved evidence and existing pricing functions without
   running an engine, fetching sources or modifying a saved revision/cache.
3. SATISFIED: preserve existing moneyline, scores, picks, confidence, provenance,
   Matchup, lineup, horizon, revision and calculation timestamp.
4. SATISFIED: preserve requested-filter/default/deduplication behavior and support
   historical saved reads without new work.
5. SATISFIED: preserve authorization, unavailable and injury-safety gates; refuse
   to invent usable market estimates when required evidence is unavailable.
6. SATISFIED: update API usage and canonical response/history boundaries.
7. SATISFIED: no schema, dependency, queue or pricing-equation change. Existing
   puckline-side normalization, overtime treatment and push-adjusted odds concerns
   remain outside this approved delivery fix.

## Endpoint × verb authorization/scope checklist

| Endpoint / verb | Access | Test name |
| --- | --- | --- |
| GET /api/nhl-game-predictions with lines | Guest denied (401) | keeps requested line pricing behind the existing API authorization boundary |
| GET /api/nhl-game-predictions with lines | Wrong scope denied (403) | keeps requested line pricing behind the existing API authorization boundary |
| GET /api/nhl-game-predictions with lines | nhl-stats:read allowed (200) | prices requested lines from a saved moneyline prediction without recalculating or writing a revision |
| GET /api/nhl-game-predictions with lines | Historical saved success allowed (200) | prices historical saved predictions without starting new work |
| GET /api/nhl-game-predictions with lines | Historical absence stays unavailable without work | does not fabricate markets or queue predictions for a historical game without a saved success |

These are global NHL resources. No organization/tenant/owner boundary is
introduced or changed. Existing request-validation and injury-safety suites
remain applicable; the injury gate precedes response market enrichment.

## Frontend component checklist

Not applicable: no frontend, layout, motion, timer or browser-state changes.

## Per-file test counts and checks

`tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`: 108 `it(...)`
declarations (88 existing, 20 added), with additional parameterized cases.
The full cohesive test file is retained in the repository.

Performed: source review, test-count inventory and `git diff --check`.
Not performed: automated tests, CI, lint, builds, migrations, imports, queue or
scheduler commands, production changes or prediction reruns.
No migration required. The human explicitly authorized implementation and push.
