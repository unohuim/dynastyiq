# NHL Edge Glossary

Source: https://www.nhl.com/nhl-edge/glossary

This file summarizes NHL Edge glossary terms for DynastyIQ analysis. Definitions are paraphrased for internal use and should not be treated as a replacement for the NHL source page.

## DynastyIQ Read

NHL Edge glossary terms mostly describe presentation and scouting metrics derived from player and puck tracking. They are useful for profile enrichment, comparison views, queue signals, and context around player usage.

They should not be treated as play-by-play, shift-chart, or boxscore authority unless an endpoint provides stable event/game identifiers and source precedence is documented.

## Goalie And Save Metrics

| Term | Meaning | DynastyIQ Use |
| --- | --- | --- |
| `5-on-5 Save %` | Goalie save percentage while both teams are at full strength with both goalies on the ice. | Compare goalie performance in neutral manpower situations. |
| `5-on-5 Save % in Close Situations` | 5-on-5 save percentage when the score is tied early or within one goal late. | Add pressure-context goalie analysis. |
| `5-on-5 Save % in Each of the Last 10 Games` | Recent 5-on-5 save percentage trend over a goalie's last 10 games. | Show short-term goalie form. |
| `5-on-5 Shots Against` | Shots on goal faced by a goalie at 5-on-5. | Volume context for save percentage. |
| `5-on-5 Shots Against Per 60` | 5-on-5 shots against normalized per 60 minutes. | Compare goalie workload rates. |
| `Goals Against` | Goals allowed by a goalie, excluding empty-net goals against the team. | Goalie summary context. |
| `High-Danger Goals Against` | Goals allowed from high-danger shot areas. | Identify quality-of-chance goalie risk. |
| `High-Danger Save %` | Save percentage on high-danger shots. | Compare goalie performance against dangerous chances. |
| `Percentage of Starts Over .900` | Share of goalie starts with save percentage above .900. | Consistency indicator for goalie starts. |
| `Save %` | Saves divided by shots on goal faced. | Basic goalie efficiency context. |
| `Save Locations` | Shot-on-goal locations faced by a goalie, used for saves, goals against, or save percentage views. | Visual goalie profile enrichment. |
| `Saves` | Shots on goal stopped by a goalie. | Volume context for save-based metrics. |
| `Starts Over .900` | Count of goalie starts with save percentage above .900. | Goalie consistency count. |

## Shot Metrics

| Term | Meaning | DynastyIQ Use |
| --- | --- | --- |
| `Average Shot Speed` | Average speed of a player's recorded shot attempts, including goals, saved shots, misses, posts/crossbars, and blocked shots. | Shot-profile enrichment. |
| `Goals` | Credit to the last scoring-team player to touch the puck before it enters the net. | Basic scoring context. |
| `Hardest Shot` | Maximum speed reached on any recorded shot attempt by a player. | Highlight trait for shot power. |
| `High-Danger Shots` | Shots from the close dangerous area in front of the goal. | Shot quality context. |
| `Long-Range Shots` | Shots from farther outside the high/mid danger areas but inside the offensive zone. | Shot-location profile context. |
| `Mid-Range Shots` | Shots from the middle-distance area between high-danger and long-range definitions. | Shot-location profile context. |
| `Shooting %` | Goals divided by shots on goal. | Finishing efficiency context. |
| `Shot Attempt Differential` | Team shot attempts for versus against per game, including shots on goal, misses, and blocks. | Team puck-possession/offense context. |
| `Shots Against` | Shots on goal faced by a goalie. | Goalie workload context. |
| `Shots on Goal` | Shots, tips, or deflections that enter the net or would have entered without a save. | Basic shot volume context. |
| `Shots on Goal Differential` | Team shots on goal for versus against per game. | Team ability to get shots through and suppress shots. |
| `Shot-Speed Range Totals` | Player shot counts grouped into speed bands. | Identify how often a player generates high-speed shots. |

## Skating Metrics

| Term | Meaning | DynastyIQ Use |
| --- | --- | --- |
| `Average Distance Skated Per 60 Mins` | Player skating distance normalized per 60 minutes of ice time. | Compare skating workload without TOI bias. |
| `Max Skating Speed` | Maximum sustained skating speed reached by a player. | Highlight speed and transition upside. |
| `Most Distance Skated - Game - Skater` | Highest distance skated by one player in a game during the current season. | Single-game workload highlight. |
| `Most Distance Skated - Game - Team` | Highest team skating distance in a game. | Team effort/work-rate context. |
| `Most Distance Skated - Period - Skater` | Highest distance skated by one player in a period during the current season. | Period workload highlight. |
| `Most Distance Skated - Period - Team` | Highest team skating distance in a period. | Team pressure/work-rate context. |
| `Skating Speed Bursts` | Counts of sustained skating bursts above speed thresholds. | Identify players who repeatedly reach dangerous speed ranges. |
| `Total Distance Skated - Skater` | Total current-season distance skated by a player while the clock is running. | Workload, role, and pace context. |
| `Total Distance Skated - Team` | Total current-season distance skated by a team while the clock is running. | Team pace/work-rate context. |

## Zone And Start Metrics

| Term | Meaning | DynastyIQ Use |
| --- | --- | --- |
| `Defensive Zone Starts` | Shifts beginning with a defensive-zone faceoff. | Usage context for difficult deployments. |
| `Neutral Zone Starts` | Shifts beginning with a neutral-zone faceoff. | Usage and deployment context. |
| `Offensive Zone Starts` | Shifts beginning with an offensive-zone faceoff. | Usage context for offensive deployment. |
| `Zone Time - Skater` | Share of clock-running time the puck is in each zone while the player is on the ice. | On-ice territorial context. |
| `Zone Time - Team` | Share of clock-running time the puck is in each zone for a team. | Team territorial context. |

## Shot Region Terms

| Term | Meaning | DynastyIQ Use |
| --- | --- | --- |
| `High-Danger` | Close-area shot region in front of the goal. | Dangerous chance context. |
| `Mid-Range` | Middle-distance shot region. | Shot-location context. |
| `Long-Range` | Farther offensive-zone shot region. | Low-danger shot context. |

## Import Notes

- Shot speed glossary language confirms NHL Edge can count recorded shot attempts, including misses, posts, crossbars, and blocks.
- The glossary does not prove public API access to every recorded shot attempt or every tracking event.
- Zone time and distance metrics depend on clock-running player/puck tracking, but captured endpoints expose summaries and highlights rather than shift rows.
- Zone starts are conceptually related to shifts, but captured Edge endpoints do not provide shift start/end records.
- Use Edge data as enrichment unless a payload includes stable `gameId`, `eventId`, `eventNumber`, `playId`, or `shiftId`.
