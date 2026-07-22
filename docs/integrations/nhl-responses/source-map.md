# NHL Data Source Map

This file maps NHL-specific data needs to the NHL endpoint or response section DynastyIQ should use.

Use this before wiring an NHL feature so the code reads from the right provider payload and does not infer authority from a convenient but weaker endpoint.

The NHL API reference documents two primary families:

- `api-web.nhle.com`
- `api.nhle.com/stats/rest`

DynastyIQ currently uses both families. The `api-web` game endpoints are the primary source for game imports; the Stats REST shiftcharts endpoint is the primary source for raw shift rows.

| NHL Need | Primary Source | Secondary / Fallback Source | Do Not Use | DynastyIQ Notes |
| --- | --- | --- | --- | --- |
| Player identity by NHL id | `/v1/player/{playerId}/landing` | Existing canonical player row when landing is temporarily unavailable. | Name search alone | Landing is the validation payload for an NHL id. 404 means the provider cannot serve that player landing page and importers may skip narrow enrichment where documented. |
| Player display name | Player landing localized name fields | Boxscore or PBP event participant names during game-scoped imports. | Fantrax names | Persist canonical player names from landing when available. Game-scoped names are useful context but weaker identity authority. |
| Player current team | Player landing current team fields | Team roster endpoints when implemented. | Latest game boxscore team by itself | Boxscore team is game-specific and not current-team authority. |
| Player draft metadata | Player landing draft fields | NHL draft endpoints when implemented. | Fantrax draft assets | Used for prospect eligibility and identity enrichment. |
| Player season totals | Player landing `seasonTotals` | NHL stats endpoints after verification. | Game boxscores summed ad hoc | Landing season totals are used by player import today; future stats endpoints may become more authoritative after observation. |
| Game discovery by date | `/v1/score/{date}` | Schedule endpoints after verification. | Standings | Discovery should schedule supported game types only. |
| Game identity and state | `/v1/gamecenter/{gameId}/play-by-play` | `/v1/gamecenter/{gameId}/landing` when implemented. | Boxscore alone | PBP import creates or refreshes `nhl_games` identity/state fields. |
| Supported game type | PBP `gameType` | Daily score game type when discovery filters. | URL pattern | Unsupported game types must not enter the canonical import pipeline. |
| Official player game totals | `/v1/gamecenter/{gameId}/boxscore` `playerByGameStats` | None for validation. | PBP summaries | Boxscore is the validation target for comparable totals and official TOI/shifts in documented reconciliation cases. |
| PBP-derived event stats | `/v1/gamecenter/{gameId}/play-by-play` `plays` | None. | Boxscore rows | PBP is the source for event-derived summaries, shot context, penalty semantics, and unit-event linking. |
| Shift start/end rows | Stats REST `/shiftcharts?cayenneExp=gameId={gameId}` | None. | Boxscore TOI alone | Shiftcharts are raw row authority for on-ice intervals, but can disagree with official boxscore TOI/shifts. |
| Shift-derived TOI and shift counts | Shiftcharts after documented normalization/reconciliation | Boxscore only for documented reconciliation and shiftchart-mismatch fallback. | PBP events alone | When only TOI/shift deltas remain after all repairs, summaries use boxscore TOI/shifts and validation records `shiftchart-mismatch`. |
| Goal event plus/minus validation | PBP goals linked to unit shifts, then reconciled to boxscore plus/minus | Official boxscore plus/minus as final target | Shift rows without PBP goals | Exact goal-time boundaries can be provider-ambiguous. |
| Goalie-facing game totals | PBP where goalie is known, reconciled to boxscore goalie rows | Boxscore goalie rows | Shiftchart goalie shifts by themselves | PBP can omit goalie-in-net context; official boxscore goalie-facing totals are reconciliation targets. |
| Missing source preflight | Exact endpoint source check | Stored source statuses | Import-stage exceptions alone | Empty/unavailable PBP or boxscore blocks core pipeline. Empty/unavailable shiftcharts skip only shift-derived stages. |
| Team standings | `/v1/standings/now` | `/v1/standings/{date}` after verification. | Game boxscores | Standings are standings context, not roster or game stat authority. |

## Cross-Endpoint Observations

- PBP, boxscore, and shiftcharts are not perfectly redundant. Treat disagreements as provider evidence, not always parser bugs.
- Boxscore is official for validation totals. PBP and shiftcharts are processing sources.
- Shiftcharts can include duplicate, contained, malformed, or provider-only rows. Reconciliation must remain auditable.
- Player landing 404 can occur for player ids found in game payloads. Narrow skips are acceptable where documented; non-404 landing failures remain operational failures.
- Early overtime endings can make shiftchart rows extend beyond the actual game end; PBP is the proof source for early OT boundaries.
