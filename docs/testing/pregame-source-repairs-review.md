# Pregame source repair and single-game visibility review

Authored for human review; no tests, builds, migrations, imports, queues or
production operations were executed for this implementation.

## Files changed or created

- `app/Services/NhlPregameSourceRepairs.php`
- `app/Services/NhlPregameWorkstreams.php`
- `app/Services/NhlSatPregameWorkspace.php`
- `app/Services/NhlDiscoverGames.php`
- `app/Services/DiscoveredGameProcessing.php`
- `app/Services/NhlGameImportRunControl.php`
- `app/Http/Controllers/Admin/NhlGameImportController.php`
- `app/Http/Controllers/Admin/NhlSatWorkspaceController.php`
- `resources/js/admin/admin-hub.js`
- `resources/js/admin/admin-hub.test.js`
- `resources/js/pages/Admin/Dashboard.vue`
- `resources/js/pages/Admin/SatModels/Index.vue`
- `resources/js/pages/Admin/SatModels/Index.test.js`
- `tests/Feature/NhlPregameWorkstreamGoalsTest.php`
- `tests/Feature/NhlGameImportSummaryTest.php`
- `tests/Feature/NhlSatWorkspaceTest.php`
- `docs/architecture/stats/NhlPregameWorkstreamGoals.yaml`
- `docs/architecture/imports/NhlDiscoveryPipeline.yaml`
- `docs/architecture/imports/NhlImportOrchestrator.yaml`
- `docs/architecture/imports/NhlGameImportRunControl.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/ENUMS.md`
- `docs/UI_DESIGN.md`
- `docs/testing/pregame-source-repairs-review.md`

## Minimum coverage matrix

COVERED denotes authored assertions, not successful execution.

| Category | Coverage | Status |
| --- | --- | --- |
| A: validation | Existing super-admin route guards; invalid visibility flag; retry restricted to a failed game owned by the latest pilot repair | COVERED |
| B: normalization/defaults | UI unchecked default sends explicit false; unspecified status API remains inclusive; single date with several games remains visible | COVERED |
| C: persistence | One source child link per game, replay reuse, automatic Full opt-in, repaired source admission and original failure retention | COVERED |
| D: payload | Game/date/import ID/step/error rows; source imports are not completed context | COVERED |
| E: HTTP read | Repair request → stored child/link assertions → authorized workspace GET; visibility queries precede pagination and change counts | COVERED |
| F: jobs/control | Targeted discovery seeding, source-before-context gating, discovery-to-Full queued gap, parent Pause/Stop isolation | COVERED |
| G: frontend | Checkbox wiring and request flag, page reset, per-game drawer contents, reactive counts, retry payload without navigation | COVERED |

Source import completion is simulated by committed child metadata in feature
fixtures; these tests do not fetch NHL data or execute the full provider pipeline.
The targeting test mocks the provider method and uses the real progress repository.
Existing canonical Full import tests retain responsibility for stage arithmetic,
source writes and enrichment. No multi-server runtime or browser verification was
performed. Source review and `git diff --check` do not establish runtime success.

## Numbered requirements

1. SATISFIED in authored code: explicit pilot Fix creates one restricted discovery
   and automatic canonical Full import for each source-blocked game.
2. SATISFIED in authored code: discovery replay reuses linked children; other games
   on the same date are excluded from targeted discovery seeding.
3. SATISFIED in authored code: source imports settle before context/league tasks
   consume their historical results; Full enrichment and both team summaries are
   required. Finished discovery alone is not successful source repair.
4. SATISFIED in authored code: per-game progress stays in Pregame area Investigate
   drawers, including source counts, stages, matchup, date, errors and retry.
5. SATISFIED in authored code: a failed-game retry is scoped to the latest pilot
   run and accepted after that repair settles. Completed context outside the new
   selection is retained. Source invalidation retains its existing later-cutoff
   recalculation rules.
6. SATISFIED in authored code: parent controls affect owned children only; paused
   or stopped parents cannot start queued automatic Full processing.
7. SATISFIED in authored code: Recent Orchestrations defaults to hiding known
   singles in Vue; filtering occurs before pagination and the checkbox reveals
   them. Unknown counts remain visible and active hidden work still polls.
8. SATISFIED in authored code: no migration; links use existing operational JSON
   metadata. New imports are enabled only by an explicit admin repair, never by
   ordinary GETs or background source invalidation alone.
9. SATISFIED: no production imports or worker operations were started by Codex.

## Endpoint × verb authorization and scope

| Endpoint / verb | Guest denied | Ordinary member denied | Authorized and scoped |
| --- | --- | --- | --- |
| POST `/admin/nhl-sat-models/pregame/repair` | Existing `blocks guests from every workspace endpoint` | Existing `blocks ordinary members from every workspace endpoint` | `creates an automatically processed single game discovery and exposes its linked progress through HTTP`; `rejects retrying games outside the latest pilot repair`; existing missing/ambiguous pilot tests |
| GET `/admin/nhl-sat-models` | Existing workspace endpoint matrix | Existing workspace endpoint matrix | New repair action → child DB assertions → HTTP `source_repairs` assertions; existing production independence test |
| GET `/admin/nhl-game-imports/status` | `denies guests the saved import status endpoint` | `denies ordinary users the saved import status endpoint` | `hides a known single game when the recent list explicitly excludes singles`; `includes single game progress when the checkbox query is enabled`; `filters targeted repair discoveries before pagination without hiding unknown discoveries` |

These are global super-admin routes. Organization and row-owner authorization
are not applicable. Pilot identity and run ownership are the applicable data-scope
boundaries. No partner API or prediction equation changed.

## Frontend checklist

- COVERED: unchecked checkbox, explicit boolean query on every page and page-one
  reset on toggle, with existing single-flight/retry polling retained.
- COVERED: compact per-game rows inside the existing area drawer.
- COVERED: repaired counts update without closing the drawer or navigating away.
- COVERED: Retry sends the failed game identity to the existing repair endpoint.
- COVERED: deterministic clocks/network mocks, scoped component state and teardown
  are retained. Updated existing polling expectations include the explicit flag.

## Per-file test counts

| File | `it(...)` declarations | Execution |
| --- | ---: | --- |
| `tests/Feature/NhlPregameWorkstreamGoalsTest.php` | 58 | Not run |
| `tests/Feature/NhlGameImportSummaryTest.php` | 34 | Not run |
| `tests/Feature/NhlSatWorkspaceTest.php` | 59 | Not run |
| `resources/js/pages/Admin/SatModels/Index.test.js` | 86 | Not run |
| `resources/js/admin/admin-hub.test.js` | 146 | Not run |

## Human verification

```sh
php artisan test tests/Feature/NhlPregameWorkstreamGoalsTest.php tests/Feature/NhlGameImportSummaryTest.php tests/Feature/NhlSatWorkspaceTest.php tests/Feature/NhlGameImportRunControlTest.php
npm run test -- --run resources/js/admin/admin-hub.test.js resources/js/pages/Admin/SatModels/Index.test.js
npm run build
```

No new migration is required. After deployment, a new explicit Repair action
enables the source-repair workflow; existing in-flight runs are not silently
converted. Source imports use `games-processing`, and context/league work uses
`projections`. Existing import-stage ownership, Full enrichment and season-sync
behavior are retained. No production run was started as part of this change.
