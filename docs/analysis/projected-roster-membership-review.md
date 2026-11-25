# Projected roster membership correction

## Files changed

- `app/Services/NhlGameLineupProjectionBuilder.php`
- `app/Services/NhlCustomGamePredictionInputs.php`
- `app/Services/NhlCustomGameForecast.php`
- `config/nhl-custom-prediction.php`
- `tests/Feature/NhlLineupInjurySafetyTest.php`
- `docs/architecture/stats/NhlPredictionInputServices.yaml`
- `docs/architecture/stats/NhlCustomGamePrediction.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/analysis/projected-roster-membership-review.md`

## Minimum coverage matrix

Coverage means assertions are authored, not that tests have executed successfully.

| ID | Contract | Coverage | Test |
| --- | --- | --- | --- |
| A1 | A genuinely incomplete eligible roster still fails | COVERED | `rejects an incomplete fallback instead of publishing a shortened roster` |
| A2 | Injury exclusion still precedes selecting a full roster | COVERED | `fills the sixth defensive slot from current membership through calculation and partner reads` |
| A3 | Goalies remain outside skater slots | COVERED | `excludes a canonical goalie even when its projection calls it a defenseman` |
| B1 | Current membership controls selection despite stale projection team | COVERED | `selects a current team member whose projection still names the former team` |
| B2 | Departed players cannot remain on the former team | COVERED | `excludes a departed player despite the projection retaining their former team` |
| B3 | Missing personal projections do not erase current identities | COVERED | `retains a healthy replacement without a personal projection and leaves statistics unknown`; `can select current identities when the entire team has no season TOI projections` |
| B4 | Statistical joins respect requested season/version | COVERED | `attaches statistical projections only from the requested season and version` (two datasets) |
| B5 | A measured zero ranks ahead of missing TOI and stays zero | COVERED | `ranks measured zero opportunity above missing opportunity without inventing a value` |
| B6 | Empty explicit selection cannot become a whole-team read | COVERED | `keeps an explicitly empty roster from becoming an unrestricted current roster` |
| C1 | Prediction reads do not repair or overwrite annual projection provenance | COVERED | `selects a current team member whose projection still names the former team`; `fills the sixth defensive slot from current membership through calculation and partner reads` |
| C2 | Previous-game fallback cannot restore departed players | COVERED | `does not reintroduce departed players through the previous-game lineup fallback` |
| C3 | Actual historical identities survive later trades/injuries | COVERED | `preserves explicitly selected historical identities after their current team changes`; `retains actual historical participants even when they are now injured and on another team` |
| D1/E1 | Submission → retained observation → corrected participant resolution → HTTP lineup, all with the same IDs | COVERED | `fills the sixth defensive slot from current membership through calculation and partner reads` |
| F1 | Current membership changes invalidate pending preparation | COVERED | `invalidates prepared upcoming inputs after a current team membership change` |
| F2 | Statistical team provenance and name heartbeats do not masquerade as membership changes | COVERED | `does not let a projection team snapshot change current membership or preparation identity`; `does not invalidate prepared rosters for a player-name heartbeat update` |
| F3 | Historical preparation ignores later membership updates | COVERED | `keeps current membership changes out of historical boxscore preparation identity` |
| G | Frontend component behavior | N/A | No frontend changes |

## Requirements checklist

1. SATISFIED: `players.team_abbrev` supplies current roster membership.
2. SATISFIED: Optional projections join by canonical NHL player ID, target season and version; saved projection team fields cannot assign current membership.
3. SATISFIED: Eligible skaters without projections retain identities and nullable statistical fields. Projected TOI ranks before missing values, with deterministic NHL-ID ties.
4. SATISFIED: Injury exclusions and twelve-forward/six-defense completeness remain enforced.
5. SATISFIED: Nonhistorical previous-game fallbacks are checked against current membership. Historical actual identities remain independent of later transfers.
6. SATISFIED: Upcoming preparation fingerprints include compact current membership/position evidence for the scheduled teams, without player-name or heartbeat timestamps.
7. SATISFIED: Input pipeline advances to v10 so pre-fix prepared inputs are not reused after deployment. Scoring coefficients and algorithm version are unchanged.
8. SATISFIED: Shared preview and calculation selection remain on the existing builder and preserve payload keys.
9. SATISFIED: Canonical architecture, derived inventory and API descriptions document the corrected ownership boundary.
10. SATISFIED: No migration, production data repair, projection rebuild or deployment operation is part of this code change.

## Endpoint × verb authorization/scope checklist

No route, permission or organization-scope policy changes. NHL reference data is global.

| Surface | Guest/wrong scope denied | Authorized path | Team/identity scope |
| --- | --- | --- | --- |
| POST `/api/nhl-lineups` | `keeps guest and wrong-scope API access blocked` | `accepts healthy replacements then reads exactly the accepted players through HTTP` | Existing identity resolution and manual team validation are retained; no new bypass |
| GET `/api/nhl/lineups/{game}` | `keeps guest and wrong-scope API access blocked` | `fills the sixth defensive slot from current membership through calculation and partner reads` | `excludes a departed player despite the projection retaining their former team` |
| GET `/api/nhl/lineups?date=…` | `keeps guest and wrong-scope API access blocked` | `exposes the same healthy replacement through every partner lineup read` | `returns independent corrected rosters when both accepted teams contain injured skaters` |
| GET `/api/nhl-anticipated-lineups` | `keeps guest and wrong-scope API access blocked` | `exposes the same healthy replacement through every partner lineup read` | `returns independent corrected rosters when both accepted teams contain injured skaters` |
| GET `/api/nhl-game-predictions` | `keeps guest and wrong-scope API access blocked` | `shows the corrected calculation lineup in both prediction API and public analysis` | Frozen revision selection remains unchanged |
| POST `/games/{game}/lineup` | `preserves guest and ordinary-member restrictions on manual lineup and goalie writes` | `applies the same injury refusal to a super-admin manual lineup submission` | Existing resolver/team-membership validation remains in force |

## Frontend component checklist

Not applicable: no frontend files, interaction state or layout changes.

## Per-file test count

| File | `it(...)` declarations | Changes |
| --- | ---: | --- |
| `tests/Feature/NhlLineupInjurySafetyTest.php` | 65 | 16 added; one existing incomplete-roster fixture corrected to remove membership instead of statistical evidence |

The suite remains deterministic: frozen time, fake buses, array cache and prevented external HTTP. Existing authorization datasets remain intact.

## Verification actually performed

- Reviewed selectors, preparation fingerprints, migrations, existing regression fixtures and governing docs.
- Counted test declarations and inspected the patch with Git.
- `git diff --check` passed.
- No tests, lint, CI, build, imports, migrations, queue commands, prediction runs or production writes were executed for this code change.

The human can run:

```bash
php artisan test tests/Feature/NhlLineupInjurySafetyTest.php tests/Feature/NhlAvailabilityApiTest.php tests/Feature/NhlCustomGamePredictionTest.php tests/Feature/NhlSatPredictionServicesTest.php
```

No database migration is required. A new prediction attempt after deployment will use the corrected roster boundary; existing failed attempts and published revisions are not rewritten by this patch.
