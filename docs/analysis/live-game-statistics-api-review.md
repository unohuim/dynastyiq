# Partner live game statistics API

Approved endpoint: `GET /api/nhl-game-live?nhl_game_id=...`, using the existing
`nhl-stats:read` partner scope. Requested team facts are SOG, SAT, hits, blocks,
PIM and xG; goalie facts are identity/name/team, SV, SA, SV% and GSAx. Include
score, clock, period, game state and separate source/advanced-processing provenance.
No migration required. Existing shared live producer and scoring calculations
remain authoritative; HTTP reads never poll NHL or trigger work.

## Files changed

- `app/Http/Controllers/Api/NhlGameLiveController.php`
- `routes/api.php`
- `app/Services/NhlLiveGameFeed.php`
- `tests/Feature/NhlGameLiveApiTest.php`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/architecture/imports/NhlLiveGameFeed.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- This review document.

The shared snapshot now exposes pending/version metadata when a processing row
exists before its first metrics result. Ready metrics and scoring are unchanged.
The controller curates only requested statistics, preserves measured zeros/negative
GSAx, excludes unused bench goalies, includes replacements, and makes SV% null
without a valid positive SA denominator. SV% uses the 0–100 scale rounded to two
decimals. Missing expected values stay null; pending data retains its previous
committed values and timestamp. Final evidence remains terminal, while active
snapshots older than twenty seconds are stale. Known games without snapshots
return HTTP 200 unavailable; unknown games return 404. Reads are no-store.

## Finalized minimum coverage matrix

COVERED means authored assertions, not executed or passing tests.

| Category | Status | Named tests |
| --- | --- | --- |
| A validation | COVERED | requires a live game identifier; rejects invalid game identifiers; returns not found for an unknown game |
| B normalization/units/zero | COVERED | returns goalie names identities teams saves shots and percentage units; preserves measured zero team statistics and expectations; does not invent save percentages for inconsistent save counts |
| C/E action → DB → HTTP | COVERED | reuses one shared provider poll through persistence and authenticated HTTP reads |
| D team and goalie contract | COVERED | returns both teams requested statistics from the shared snapshot; returns goalie names identities teams saves shots and percentage units |
| Missing data | COVERED | returns an unavailable envelope without preparing missing live evidence; keeps missing team statistics null rather than substituting zero; keeps unscored expected metrics unavailable without running a calculation |
| Retained provider count compatibility | COVERED | reads combined goalie save shots fields from the same retained provider observation |
| Goalie participation | COVERED | includes each goalie who played after a replacement; does not include bench goalies or skaters in goalie statistics; keeps save percentage unavailable without shots faced |
| Negative expected performance | COVERED | preserves negative goalie saves above expected |
| Independent advanced metadata | COVERED | exposes advanced processing versions separately from live observation time; reports pending advanced processing before the first statistics result exists |
| Freshness/finality/corrections | COVERED | marks older live evidence stale without fetching replacement evidence; keeps retained final evidence terminal rather than stale; returns no store responses with increasing observed sequence values |
| Per-game scope/private output | COVERED | isolates metrics to the requested game; does not expose raw provider blobs prediction inputs or full player tables |
| F no new provider/queue work | COVERED | returns an unavailable envelope without preparing missing live evidence; reuses one shared provider poll through persistence and authenticated HTTP reads |
| G frontend | N/A | No frontend changes |

## Requirements checklist

1. SATISFIED in code/assertions: existing scoped API authentication and game validation.
2. SATISFIED in code/assertions: all requested team/goalie fields with explicit units.
3. SATISFIED in code/assertions: reuse shared observations and committed advanced values.
4. SATISFIED in code/assertions: missing/zero/negative values retain their meanings.
5. SATISFIED in code/assertions: independent freshness, pending and processing provenance.
6. SATISFIED in code/assertions: replacements included, unused bench entries excluded.
7. SATISFIED in code/assertions: no provider calls, prediction preparation or scoring on reads.
8. SATISFIED in docs: full request/response, units, availability and update contract documented.

## Endpoint × verb authorization/scope matrix

| Endpoint / verb | Case | Named test |
| --- | --- | --- |
| GET `/api/nhl-game-live` | Guest 401 | requires a partner token for live game statistics |
| Same | Wrong scope 403 | requires the NHL statistics read scope |
| Same | Revoked token 403 | rejects revoked partner tokens |
| Same | Scoped client 200 | returns both teams requested statistics from the shared snapshot |
| Same | Other game's snapshot rejected | isolates metrics to the requested game |
| Organization/ownership | N/A | Global NHL observed facts; existing partner read permission |

Frontend component checklist: N/A; no frontend files changed.
Per-file test count: `tests/Feature/NhlGameLiveApiTest.php` has **27** declarations
before dataset expansion, exceeding twenty. Existing live-feed tests are untouched.
Tests and CI were not run under repository policy. Static `git diff --check` passed.
Human verification commands:

```sh
php artisan test tests/Feature/NhlGameLiveApiTest.php
php artisan test tests/Feature/NhlLiveGameFeedTest.php
```

The separately approved pending Horizon change raises the default queue maximum
from 10 to 20, retaining the minimum of 5 and all other supervisors/settings.
It is included in the requested push through `config/horizon.php` and
`docs/architecture/application/LaravelApplicationShell.yaml`. No production
workers are manually started/stopped by this API implementation.
