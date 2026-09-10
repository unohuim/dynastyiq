# MLB Data Source Map

This file maps MLB-specific data needs to candidate MLB Stats API endpoints DynastyIQ should review before implementation. Use this before wiring an MLB feature so code reads from the right provider payload and does not infer authority from a convenient but weaker endpoint.

Base URL:

```text
https://statsapi.mlb.com/api/v1
```

| MLB Need | Primary Candidate Source | Secondary / Fallback Source | Do Not Use | DynastyIQ Notes |
| --- | --- | --- | --- | --- |
| Game discovery by date/range | `/schedule?sportId=1&date={date}` or `/schedule?sportId=1&startDate={date}&endDate={date}` | `/game/changes?updatedSince={timestamp}&sportId=1` for update sweeps. | Standings or team pages. | Schedule is the candidate discovery feed; validate game type/status fields before import eligibility rules. |
| Live game state and full game payload | `/game/{gamePk}/feed/live` | `/game/{gamePk}/feed/live/diffPatch` plus `/timestamps` for incremental updates. | Boxscore alone. | Live feed is the candidate full state payload; sample before treating nested stats or event fields as canonical. |
| Official game totals | `/game/{gamePk}/boxscore` | `/game/{gamePk}/feed/live` boxscore sections after comparison. | Play-by-play alone. | Boxscore is the candidate validation target for player/team game totals, pending sample review. |
| Inning/score state | `/game/{gamePk}/linescore` | Live feed linescore section. | Schedule status alone. | Linescore is candidate scoreboard context, not player-stat authority by itself. |
| Event stream | `/game/{gamePk}/playByPlay` | Live feed play data after comparison. | Boxscore totals. | Use only after event type, runner state, RBI/error, and scoring semantics are documented. |
| Team identity | `/teams?sportId=1` and `/teams/{teamId}` | `/teams/history` for historical franchise/team changes. | Schedule team display strings alone. | Team ids are provider identity candidates; historical context needs explicit season handling. |
| Team roster | `/teams/{teamId}/roster?season={season}` | `/sports/1/players?season={season}` for broader player pool. | Boxscore participants as current roster. | Roster endpoint is the candidate current/historical roster source after roster type semantics are verified. |
| Player identity/profile | `/people?personIds={ids}` and `/people/{personId}` | `/sports/1/players?season={season}` for seasonal player pool. | Name matching alone. | People endpoints are candidate player identity authority; canonical matching still needs explicit provider-id rules. |
| Player/team aggregate stats | `/stats?stats={type}&group={group}&sportIds=1` | `/people/{personId}/stats`, `/teams/{teamId}/stats`, `/teams/stats`. | Summed schedule or standings data. | Stats endpoint parameters must be bounded and tested before product usage. |
| Stat leaders/streaks | `/stats/leaders` and `/stats/streaks` | `/stats` sorted queries. | UI tables as data source. | Useful for discovery and validation, not canonical projections without sample review. |
| Standings | `/standings?leagueId={leagueId}&season={season}` | `/league`, `/divisions`, `/seasons` for context. | Schedule win/loss snippets. | Standings are standings context, not roster or player-stat authority. |
| Transactions | `/transactions?sportId=1&date={date}` or date range | Team/player filtered transaction calls. | Roster differences alone. | Transaction semantics need provider event-type mapping before persistence. |
| Metadata values | `/gameTypes`, `/positions`, `/statGroups`, `/statTypes`, `/situationCodes`, `/rosterTypes`, `/eventTypes`, `/gameStatus`, `/pitchTypes`, `/pitchCodes` | `meta` registry types where applicable. | Hard-coded values copied from examples. | Reference endpoints should drive enum discovery during implementation, while canonical app enum values remain in `docs/ENUMS.md`. |
| Venue context | `/venues?venueIds={ids}` | Schedule/game venue snippets. | Team home venue assumptions. | Venue data is context unless an MLB feature explicitly needs stadium identity. |

## Cross-Endpoint Observations

- The API has no formal browseable root at `/api/v1`; concrete endpoint paths must be called.
- Several endpoints accept broad filters and default limits. Production usage should set explicit filters, limits, and date ranges.
- The public registries list endpoint shapes, but DynastyIQ should capture samples before persistence or validation logic depends on a field.
- MLB uses `sportId=1` for Major League Baseball in common schedule/team/player requests.
- Metadata endpoints should be reviewed before hard-coding game type, stat group, roster type, or event type values.
