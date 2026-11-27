# Historical pilot Train quality and source targeting

Authored for human review. No tests, builds, migrations, imports, queues or
production operations were executed. The existing production run was not paused,
stopped, retried or converted by Codex.

## Files changed or created

- `app/Services/NhlPregameContextBuilder.php`
- `app/Services/NhlPregameContextOrchestrator.php`
- `app/Services/NhlPregameSourceRepairs.php`
- `app/Services/NhlPregameWorkstreams.php`
- `app/Services/NhlSatPregameWorkspace.php`
- `resources/js/pages/Admin/SatModels/Index.vue`
- `resources/js/pages/Admin/SatModels/Index.test.js`
- `tests/Feature/NhlPregameWorkstreamGoalsTest.php`
- `tests/Feature/NhlSatWorkspaceTest.php`
- `docs/architecture/stats/NhlPregameWorkstreamGoals.yaml`
- `docs/architecture/stats/NhlPregameContextEvidence.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/ENUMS.md`
- `docs/UI_DESIGN.md`
- `docs/testing/pregame-train-quality-review.md`

## Minimum coverage matrix

COVERED means assertions were authored, not that execution succeeded.

| Category | Authored evidence | Status |
| --- | --- | --- |
| A: validation | Existing guest/member repair/inspector guards, latest-run retry ownership and exact selection checks retained | COVERED |
| B: normalization | Canonical usable Train sample and missing count preserved; recent missing rate stays null; no new statistical smoothing | COVERED |
| C: persistence | Real player snapshot writes, atomic run-game quality metadata and per-source import links | COVERED |
| D: response | Rebuilt/assessed/blocked/unfinished progress and bounded incomplete-game/player list | COVERED |
| E: HTTP read | Repair request → one actual historical source child DB assertion → workspace GET; actual player task execution → quality metadata → workspace GET | COVERED |
| F: async work | Existing pipeline creates only the actual missing source game's child; dependent later games do not get source imports; Full completion rechecks facts | COVERED |
| G: frontend | Large blocked total cannot inflate rebuilt percentage; incomplete-game inspector action is scoped to that game; prior progress test updated | COVERED |

The Train sample fixture contains two historical appearances, one with missing
facts and one with valid EV exposure and one attempt. It expects usable games 1,
missing games 1, SAT 1, EV SAT/60 4, and unavailable Last 5 SAT/60. These assertions
exercise the real builder and preserve its existing arithmetic. Source imports
are queued/faked and their reported completion is simulated; external NHL feeds
and the complete provider pipeline are not executed. PostgreSQL lock concurrency
and browser rendering remain unexecuted verification.

## Requirements checklist

1. SATISFIED in authored code: preserve canonical complete-appearance Train,
   venue Train and opponent Train calculations and their sample evidence. They
   no longer reject an entire later game because some appearances are missing.
2. SATISFIED in authored code: recent unavailable metrics remain unavailable;
   other metrics persist. Affected player identities are recorded atomically
   with the task page and surfaced for quality inspection.
3. SATISFIED in authored code: new explicit pilot repairs audit actual completed
   source games in the model's seasons, including absent facts, absent player
   strength summaries and attempts without matching strength exposure. Reads
   use bounded game-ID batches rather than per-target history recalculation.
4. SATISFIED in authored code: underlying broken source games enter Full import
   selection. Their later dependent matchups are not themselves treated as
   broken imports. Explicit retries retain their exact game/date scope.
5. SATISFIED in authored code: reported Full completion is followed by a source
   evidence recheck; unresolved sources remain visible blockers.
6. SATISFIED in authored code: missing Train count alone does not select every
   otherwise valid snapshot for repair. Existing incomplete recent context and
   unfinished writes remain eligible; actual source changes retain established
   later-cutoff invalidation behavior.
7. SATISFIED in authored code: header rebuilt progress is separate from assessed,
   blocked, failed, unfinished and incomplete-recent-window counts. Saved
   snapshots do not certify every metric.
8. SATISFIED in authored code: historical pilot only; no production engine,
   numerical formula or existing statistical schema change. No migration.
9. SATISFIED: no operational changes or verification commands executed by Codex.

## Endpoint × verb authorization and scope

| Endpoint / verb | Guest denied | Ordinary user denied | Authorized / scoped evidence |
| --- | --- | --- | --- |
| POST `/admin/nhl-sat-models/pregame/repair` | `blocks guests from every workspace endpoint` | `blocks ordinary members from every workspace endpoint` | `imports the actual missing historical source game instead of its later dependent game`; existing latest-run retry ownership tests; exact scoped source audit test |
| GET `/admin/nhl-sat-models` | Existing workspace endpoint matrix | Existing workspace endpoint matrix | Actual repair/task writes → HTTP source and incomplete-quality payload assertions |
| GET `/admin/nhl-sat-models/pregame/inspect` | Existing workspace endpoint matrix | Existing workspace endpoint matrix | `returns only bounded current-version pilot metric rows`; frontend `opens the player inspector for a game with incomplete recent windows` |

Organization and row-owner authorization are not applicable to global super-admin
pilot maintenance. Pilot/model/run/game identity is the relevant scope boundary.
`does not audit production model sources through the historical pilot bridge`
guards the new audit. There are no new endpoints or partner API changes.

## Frontend component checklist

- COVERED: rebuilt percentage uses saved snapshots, independently of blocked totals.
- COVERED: assessed percentage and blocked/unfinished/failed counters remain explicit.
- COVERED: incomplete quality list retains game/date/player identities and bounded output.
- COVERED: inspection targets the selected game's player metrics and retains null/sample display.
- COVERED: deterministic clock and mocked requests, existing timer teardown and quiet refresh retained.

## Per-file declaration counts

| File | `it(...)` declarations | Execution |
| --- | ---: | --- |
| `tests/Feature/NhlPregameWorkstreamGoalsTest.php` | 66 | Not run |
| `tests/Feature/NhlSatWorkspaceTest.php` | 59 | Not run |
| `resources/js/pages/Admin/SatModels/Index.test.js` | 88 | Not run |

## Human verification and operation

```sh
php artisan test tests/Feature/NhlPregameWorkstreamGoalsTest.php tests/Feature/NhlSatWorkspaceTest.php tests/Feature/NhlPregameStrengthMetricsTest.php
npm run test -- --run resources/js/pages/Admin/SatModels/Index.test.js
npm run build
```

No migration is required. The source-audit opt-in applies to new explicit pilot
repairs. Existing in-flight runs retain their selected games and source settings;
already blocked tasks do not automatically become repaired through deployment.
Use the UI to pause/stop the older run and then request a fresh targeted Repair.
Committed valid snapshots remain available and existing missing-context selection
is reused. No run controls or source imports were executed by Codex.
