# MLB Response Semantics

This directory records MLB Stats API endpoint usage notes and generated response-semantics scaffolds for DynastyIQ.

The MLB Stats API base is:

```text
https://statsapi.mlb.com/api/v1
```

The base path itself is not a browseable endpoint; call concrete endpoint paths such as `/schedule`, `/teams`, or `/game/{gamePk}/feed/live`.

These files document DynastyIQ's interpretation and review status, not provider-owned formal documentation. Generated endpoint files are scaffolds until a human captures sample payloads and fills in field semantics, parser contracts, normalized output, and source authority.

## Sources

- `toddrob99/MLB-StatsAPI` endpoint registry and wiki: `https://github.com/toddrob99/MLB-StatsAPI`
- `pseudo-r/Public-MLB-API` reference notes: `https://github.com/pseudo-r/Public-MLB-API`
- Direct observation of concrete `statsapi.mlb.com` endpoint behavior when available.

## Endpoint Files

- `endpoint-index.md`: Generated endpoint inventory with method, path, params, example URL, and breakdown file.
- `source-map.md`: MLB-specific data needs mapped to candidate endpoint sources.

### Awards And Draft

- `awards`: [awards.md](awards.md)
- `draft`: [draft.md](draft.md)

### Events

- `homeRunDerby`: [home-run-derby.md](home-run-derby.md)

### Game Detail

- `game`: [game.md](game.md)
- `game_boxscore`: [game-boxscore.md](game-boxscore.md)
- `game_color`: [game-color.md](game-color.md)
- `game_color_diff`: [game-color-diff.md](game-color-diff.md)
- `game_color_timestamps`: [game-color-timestamps.md](game-color-timestamps.md)
- `game_content`: [game-content.md](game-content.md)
- `game_contextMetrics`: [game-context-metrics.md](game-context-metrics.md)
- `game_diff`: [game-diff.md](game-diff.md)
- `game_linescore`: [game-linescore.md](game-linescore.md)
- `game_playByPlay`: [game-play-by-play.md](game-play-by-play.md)
- `game_timestamps`: [game-timestamps.md](game-timestamps.md)
- `game_uniforms`: [game-uniforms.md](game-uniforms.md)
- `game_winProbability`: [game-win-probability.md](game-win-probability.md)

### League Reference

- `conferences`: [conferences.md](conferences.md)
- `divisions`: [divisions.md](divisions.md)
- `league`: [league.md](league.md)
- `league_allStarBallot`: [league-all-star-ballot.md](league-all-star-ballot.md)
- `league_allStarFinalVote`: [league-all-star-final-vote.md](league-all-star-final-vote.md)
- `league_allStarWriteIns`: [league-all-star-write-ins.md](league-all-star-write-ins.md)

### Metadata Reference

- `meta`: [meta.md](meta.md)

### Operations And Context

- `attendance`: [attendance.md](attendance.md)
- `jobs`: [jobs.md](jobs.md)
- `jobs_datacasters`: [jobs-datacasters.md](jobs-datacasters.md)
- `jobs_officialScorers`: [jobs-official-scorers.md](jobs-official-scorers.md)
- `jobs_umpire_games`: [jobs-umpire-games.md](jobs-umpire-games.md)
- `jobs_umpires`: [jobs-umpires.md](jobs-umpires.md)

### People And Players

- `people`: [people.md](people.md)
- `people_changes`: [people-changes.md](people-changes.md)
- `people_freeAgents`: [people-free-agents.md](people-free-agents.md)
- `person`: [person.md](person.md)
- `person_stats`: [person-stats.md](person-stats.md)

### Schedule And Discovery

- `game_changes`: [game-changes.md](game-changes.md)
- `schedule`: [schedule.md](schedule.md)
- `schedule_postseason`: [schedule-postseason.md](schedule-postseason.md)
- `schedule_postseason_series`: [schedule-postseason-series.md](schedule-postseason-series.md)
- `schedule_postseason_tuneIn`: [schedule-postseason-tune-in.md](schedule-postseason-tune-in.md)
- `schedule_tied`: [schedule-tied.md](schedule-tied.md)

### Standings And Seasons

- `season`: [season.md](season.md)
- `seasons`: [seasons.md](seasons.md)
- `sports`: [sports.md](sports.md)
- `sports_players`: [sports-players.md](sports-players.md)
- `standings`: [standings.md](standings.md)

### Stats And Leaders

- `gamePace`: [game-pace.md](game-pace.md)
- `highLow`: [high-low.md](high-low.md)
- `stats`: [stats.md](stats.md)
- `stats_leaders`: [stats-leaders.md](stats-leaders.md)
- `stats_streaks`: [stats-streaks.md](stats-streaks.md)

### Teams And Rosters

- `team`: [team.md](team.md)
- `team_alumni`: [team-alumni.md](team-alumni.md)
- `team_coaches`: [team-coaches.md](team-coaches.md)
- `team_leaders`: [team-leaders.md](team-leaders.md)
- `team_personnel`: [team-personnel.md](team-personnel.md)
- `team_roster`: [team-roster.md](team-roster.md)
- `team_stats`: [team-stats.md](team-stats.md)
- `team_uniforms`: [team-uniforms.md](team-uniforms.md)
- `teams`: [teams.md](teams.md)
- `teams_affiliates`: [teams-affiliates.md](teams-affiliates.md)
- `teams_history`: [teams-history.md](teams-history.md)
- `teams_stats`: [teams-stats.md](teams-stats.md)

### Transactions

- `transactions`: [transactions.md](transactions.md)

### Venue Reference

- `venue`: [venue.md](venue.md)

