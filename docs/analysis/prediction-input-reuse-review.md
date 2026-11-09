# Saved pregame aggregate reuse

Operational preparation now reads player EV/PP game counts, exposure seconds and
overall retained SAT/SOG/goal totals from the existing model/game/player context
snapshot. It no longer accumulates personal Train/season exposure and appearance
counts or recomputes those rolling-window totals for publication. Player L20 PP
goals/game also uses the saved numerator and appearance count with the existing
personal Train and league fallback order.

The snapshot must contain individual-strength metrics and all requested windows
at the target cutoff. Empty windows remain unavailable; recent windows with
missing appearances remain unavailable; usable Train samples remain supported.
Zero-strength appearances retain their game count and zero exposure. Missing or
malformed saved fields produce an explicit input error rather than silently
rebuilding the missing aggregate.

The additional calculations remain: individual bucket counts/conversions,
Train defensive fitting, exposure-weighted historical opponents, retained-bucket
team PP goals against and team/league opportunity and PK references. The team
snapshot already supplies its saved general metrics and schedule. Its generic
team totals cannot substitute for these unstored retained-bucket and special-teams
references. No team snapshot fields, raw facts or database tables change.

The bounded historical pass remains necessary for those additional inputs. This
change removes duplicated aggregates, not the pass itself, and makes no measured
runtime improvement claim. Transient standalone forecasting and chronological
Test replay retain their original aggregation path.

The preparation pipeline version changes to invalidate older cached input
manifests/checkpoints. The pregame context version and freshness definition are
unchanged, so this version change alone does not request another context build.
No scoring coefficient, queue, route, authorization, cutoff or migration changes.

## Coverage matrix

Coverage below means authored assertions, not executed or passing tests.

| Category | Coverage |
|---|---|
| A validation | `does not silently replay missing or invalid saved individual aggregates` covers missing version/window/field and negative exposure |
| B source selection | `reads saved player exposure and overall totals while preserving the separate bucket history`; `uses saved player PP goals per appearance for the L20 offense allocation` |
| C persistence/read contract | Existing context fixtures persist individual-strength metrics; `does not accumulate duplicated personal counts or exposure during operational history preparation` asserts the saved counts reach the output while raw bucket counts remain |
| D output contract | Tests assert usage, history totals, bucket conversion/reference values, PP offense and chosen source fields |
| E HTTP | Not changed; shared input service only, no new endpoint |
| F queued workflow | Existing `builds queued inputs from saved contexts in bounded pages without reading target outcomes` and retry/checkpoint tests remain |
| G frontend | Not applicable; no frontend edits |

## Requirements checklist

- Saved player counts, exposure and overall totals are reused for operational predictions.
- Personal scalar accumulation is disabled while additional bucket accumulation remains.
- PP offense uses the saved player goal numerator and game denominator.
- Tests cover usable Train samples, incomplete recent windows and zero PP exposure.
- Saved parent links/cutoffs and existing lineup selection remain enforced.
- Additional defensive, bucket and league fallback work remains.
- Historical replay retains its original aggregation behavior.
- No production action, migration, test or CI command was run.
- Output equivalence and runtime performance remain unverified until human-run tests.

## Authorization/scope and frontend checklists

No endpoint, verb, authorization, organization scope or frontend component
changes. Existing API/admin authorization matrices remain applicable; no new
matrix cells or frontend state transitions are introduced.

## Per-file test counts

`tests/Feature/NhlCustomGamePredictionTest.php`: 91 authored `it` declarations,
including six added declarations. Dataset cases expand the number of executions.
The full suite remains in the repository. Tests were not run. Static review uses
`git diff --check` only.

After review, the human may run:

```sh
php artisan test tests/Feature/NhlCustomGamePredictionTest.php
```
