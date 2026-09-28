# Line combination filters — review notes

Implementation branch: `codex/stats-units-game-filters`.

Tests, CI, builds, and browser checks have **not been run**. Coverage below means
test cases have been authored, not that execution has passed. A static diff review
and `git diff --check` were performed.

## Files changed or created

- `app/Http/Controllers/StatsUnitsController.php`
- `resources/views/stats-units.blade.php`
- `resources/views/partials/_stats-units-content.blade.php`
- `resources/js/pages/stats-units.js`
- `tests/Feature/StatsUnitsFiltersTest.php`
- `resources/js/pages/stats-units.test.js`
- `docs/architecture/stats/NhlStrengthOnIceStats.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/ENUMS.md`
- `docs/testing/stats-units-filters-review.md`

## Minimum coverage matrix

| Category | Authored coverage | Evidence |
| --- | --- | --- |
| A: Validation | COVERED | `rejects malformed dates rather than querying an unintended scope`; `rejects malformed game player and grouping inputs` |
| B: Defaults and normalization | COVERED | `defaults to summed combinations across the selected games`; `clears a selected game that is outside the requested date`; `clears a stale player selection when the player is absent from the scope` |
| C: Persistence/read contract | COVERED | `reads imported four-shift performance as one combination for the game` invokes the existing summary action, asserts persisted totals, then reads the filtered HTTP report. No new persistence behavior is introduced. |
| D: JSON response | COVERED | `returns the filtered report fragment for authenticated JSON requests` |
| E: HTTP reads | COVERED | Summed/per-game grouping, same-date separate games, strength-row aggregation, date/game/player scope, team/type/season scope, sorting, pagination, small samples, and empty results |
| F: Events/queues/broadcasts | N/A | This report introduces no asynchronous backend work or writes. |
| G: Frontend | COVERED | Filter serialization, scope transitions, clearing, fetching, loading, rollback, stale responses, history, pagination, and disposal in `stats-units.test.js` |

## Requirements checklist

1. SATISFIED in implementation: Sum appears between title and team, checked by default.
2. SATISFIED in implementation: checked Sum combines each unit across selected games; unchecked Sum groups by unit and game, never by individual shift.
3. SATISFIED in implementation: date, game, and player controls occupy a new filter row.
4. SATISFIED in implementation: empty date field displays a calendar icon and uses the existing full-field native date picker.
5. SATISFIED in implementation: clearing the date clears the selected game; date changes refresh available games.
6. SATISFIED in implementation: All Games imposes no game constraint; a specific game adds a constraint.
7. SATISFIED in implementation: player choices reflect the selected scope, and selection retains complete combinations containing that player.
8. SATISFIED in implementation: counts/share presentation remains independent of grouping; sorting and pagination retain the selected constraints.
9. SATISFIED in implementation: changing scope resets volume thresholds; small samples have no default minimum volume.
10. SATISFIED in implementation: both grouping modes use existing persisted summaries, with no schema changes or reimports.

Runtime verification remains outstanding for every requirement. In particular,
native calendar behavior, responsive layout, Alpine initialization after fragment
replacement, and keyboard interaction need browser review.

## Endpoint × verb authorization/scope matrix

| Endpoint | Case | Test |
| --- | --- | --- |
| GET `/stats/units` HTML | Guest blocked | `redirects guests away from the line combination page` |
| GET `/stats/units` JSON | Guest blocked | `rejects guest JSON reads of line combinations` |
| GET `/stats/units` HTML | Authenticated user without admin role allowed | `allows an authenticated user without an admin role to read combinations` |
| GET `/stats/units` JSON | Authenticated user allowed | `returns the filtered report fragment for authenticated JSON requests` |
| Both representations | Permission-specific denial | N/A: no additional permission gate on this existing route. |
| Both representations | Organization/owner scope | N/A: NHL combinations are global hockey data, not tenant-owned rows. |

Existing route middleware and route name are unchanged.

## Frontend component checklist

- Authored: checked/unchecked serialization and dependent filter resets.
- Authored: date clearing removes the game constraint while retaining other scope.
- Authored: AJAX request headers, returned markup, URL updates, loading state.
- Authored: failed requests restore controls/results and expose an error.
- Authored: older responses cannot overwrite the latest selection.
- Authored: pagination, empty-state reset, history navigation, subsequent selections.
- Authored: duplicate mount prevention and event-listener disposal.
- Deterministic: mocked fetch, controlled promises, no external network or timers.
- Native date picker: existing shared component reused; markup covered by Pest,
  actual browser behavior remains unverified.

## Per-file test counts

| File | `it(...)` cases | Execution |
| --- | ---: | --- |
| `tests/Feature/StatsUnitsFiltersTest.php` | 29 | Not run |
| `resources/js/pages/stats-units.test.js` | 27 | Not run |

## Suggested human verification

```sh
php artisan test tests/Feature/StatsUnitsFiltersTest.php tests/Feature/NhlStrengthOnIceStatsTest.php
npm test -- --run resources/js/pages/stats-units.test.js
```

Review `/stats/units` in a browser with rebuilt frontend assets, including a
same-date multi-game selection, short-TOI combinations, clearing the date, and
the unchecked-Sum view. No commands above have been executed by Codex.

Awaiting human review
