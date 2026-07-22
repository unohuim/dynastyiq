# NHL API Index

Source reference: https://github.com/Zmalski/NHL-API-Reference

This file mirrors the source-reference endpoint format and adds DynastyIQ file targets for each endpoint.

## Get Game Log

Endpoint: /v1/player/{player}/game-log/{season}/{game-type}
Method: GET
Description: Retrieve the game log for a specific player, season, and game type.
Parameters: player, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/player/8478402/game-log/20232024/2"
Raw JSON: docs/api_responses/samples/nhlApi-player-by-player-game-log-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/player-by-player-game-log-by-season-by-game-type.md
Local Status: curl-example

## Get Specific Player Info

Endpoint: /v1/player/{player}/landing
Method: GET
Description: Retrieve information for a specific player.
Parameters: player
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/player/8478402/landing"
Raw JSON: docs/api_responses/samples/nhlApi-player-by-player-landing.txt
Usage File: docs/integrations/nhl-responses/player-by-player-landing.md
Local Status: curl-example

## Get Game Log As of Now

Endpoint: /v1/player/{player}/game-log/now
Method: GET
Description: Retrieve the game log for a specific player as of the current moment.
Parameters: player
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/player/8478402/game-log/now"
Raw JSON: docs/api_responses/samples/nhlApi-player-by-player-game-log-now.txt
Usage File: docs/integrations/nhl-responses/player-by-player-game-log-now.md
Local Status: curl-example

## Get Current Skater Stats Leaders

Endpoint: /v1/skater-stats-leaders/current
Method: GET
Description: Retrieve current skater stats leaders.
Parameters: categories, limit
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/skater-stats-leaders/current?categories=goals&limit=5"
Raw JSON: docs/api_responses/samples/nhlApi-skater-stats-leaders-current.txt
Usage File: docs/integrations/nhl-responses/skater-stats-leaders-current.md
Local Status: curl-example

## Get Skater Stats Leaders for a Specific Season and Game Type

Endpoint: /v1/skater-stats-leaders/{season}/{game-type}
Method: GET
Description: Retrieve skater stats leaders for a specific season and game type.
Parameters: season, game-type, categories, limit
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/skater-stats-leaders/20222023/2?categories=assists&limit=3"
Raw JSON: docs/api_responses/samples/nhlApi-skater-stats-leaders-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/skater-stats-leaders-by-season-by-game-type.md
Local Status: curl-example

## Get Current Goalie Stats Leaders

Endpoint: /v1/goalie-stats-leaders/current
Method: GET
Description: Retrieve current goalie stats leaders.
Parameters: categories, limit
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/goalie-stats-leaders/current?categories=wins&limit=5"
Raw JSON: docs/api_responses/samples/nhlApi-goalie-stats-leaders-current.txt
Usage File: docs/integrations/nhl-responses/goalie-stats-leaders-current.md
Local Status: curl-example

## Get Goalie Stats Leaders by Season

Endpoint: /v1/goalie-stats-leaders/{season}/{game-type}
Method: GET
Description: Retrieve goalie stats leaders for a specific season and game type.
Parameters: season, game-type, categories, limit
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/goalie-stats-leaders/20232024/2?categories=wins&limit=3"
Raw JSON: docs/api_responses/samples/nhlApi-goalie-stats-leaders-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/goalie-stats-leaders-by-season-by-game-type.md
Local Status: curl-example

## Get Players

Endpoint: /v1/player-spotlight
Method: GET
Description: Retrieve information about players in the "spotlight".
Parameters: None documented.
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/player-spotlight"
Raw JSON: docs/api_responses/samples/nhlApi-player-spotlight.txt
Usage File: docs/integrations/nhl-responses/player-spotlight.md
Local Status: curl-example

## Get Standings

Endpoint: /v1/standings/now
Method: GET
Description: Retrieve the standings as of the current moment.
Parameters: None documented.
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/standings/now"
Raw JSON: docs/api_responses/samples/nhlApi-standings-now.txt
Usage File: docs/integrations/nhl-responses/standings-now.md
Local Status: curl-example

## Get Standings by Date

Endpoint: /v1/standings/{date}
Method: GET
Description: Retrieve the standings for a specific date.
Parameters: date
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/standings/2023-11-10"
Raw JSON: docs/api_responses/samples/nhlApi-standings-by-date.txt
Usage File: docs/integrations/nhl-responses/standings-by-date.md
Local Status: curl-example

## Get Standings information for each Season

Endpoint: /v1/standings-season
Method: GET
Description: Retrieves information for each season's standings
Parameters: None documented.
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/standings-season"
Raw JSON: docs/api_responses/samples/nhlApi-standings-season.txt
Usage File: docs/integrations/nhl-responses/standings-season.md
Local Status: curl-example

## Get Club Stats Now

Endpoint: /v1/club-stats/{team}/now
Method: GET
Description: Retrieve current statistics for a specific club.
Parameters: team
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/club-stats/TOR/now"
Raw JSON: docs/api_responses/samples/nhlApi-club-stats-by-team-now.txt
Usage File: docs/integrations/nhl-responses/club-stats-by-team-now.md
Local Status: curl-example

## Get Club Stats for the Season for a Team

Endpoint: /v1/club-stats-season/{team}
Method: GET
Description: Returns an overview of the stats for each season for a specific club. Seems to only indicate the gametypes played in each season.
Parameters: team
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/club-stats-season/TOR"
Raw JSON: docs/api_responses/samples/nhlApi-club-stats-season-by-team.txt
Usage File: docs/integrations/nhl-responses/club-stats-season-by-team.md
Local Status: curl-example

## Get Club Stats by Season and Game Type

Endpoint: /v1/club-stats/{team}/{season}/{game-type}
Method: GET
Description: Retrieve the stats for a specific team, season, and game type.
Parameters: team, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/club-stats/TOR/20232024/2"
Raw JSON: docs/api_responses/samples/nhlApi-club-stats-by-team-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/club-stats-by-team-by-season-by-game-type.md
Local Status: curl-example

## Get Team Scoreboard

Endpoint: /v1/scoreboard/{team}/now
Method: GET
Description: Retrieve the scoreboard for a specific team as of the current moment.
Parameters: team
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/scoreboard/TOR/now"
Raw JSON: docs/api_responses/samples/nhlApi-scoreboard-by-team-now.txt
Usage File: docs/integrations/nhl-responses/scoreboard-by-team-now.md
Local Status: curl-example

## Get Team Roster As of Now

Endpoint: /v1/roster/{team}/current
Method: GET
Description: Retrieve the roster for a specific team as of the current moment.
Parameters: team
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/roster/TOR/current"
Raw JSON: docs/api_responses/samples/nhlApi-roster-by-team-current.txt
Usage File: docs/integrations/nhl-responses/roster-by-team-current.md
Local Status: curl-example

## Get Team Roster by Season

Endpoint: /v1/roster/{team}/{season}
Method: GET
Description: Retrieve the roster for a specific team and season.
Parameters: team, season
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/roster/TOR/20232024"
Raw JSON: docs/api_responses/samples/nhlApi-roster-by-team-by-season.txt
Usage File: docs/integrations/nhl-responses/roster-by-team-by-season.md
Local Status: curl-example

## Get Roster Season for Team

Endpoint: /v1/roster-season/{team}
Method: GET
Description: Seems to just return a list of all of the seasons that the team played.
Parameters: team
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/roster-season/TOR"
Raw JSON: docs/api_responses/samples/nhlApi-roster-season-by-team.txt
Usage File: docs/integrations/nhl-responses/roster-season-by-team.md
Local Status: curl-example

## Get Team Prospects

Endpoint: /v1/prospects/{team}
Method: GET
Description: Retrieve prospects for a specific team.
Parameters: team
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/prospects/TOR"
Raw JSON: docs/api_responses/samples/nhlApi-prospects-by-team.txt
Usage File: docs/integrations/nhl-responses/prospects-by-team.md
Local Status: curl-example

## Get Team Season Schedule As of Now

Endpoint: /v1/club-schedule-season/{team}/now
Method: GET
Description: Retrieve the season schedule for a specific team as of the current moment.
Parameters: team
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/club-schedule-season/TOR/now"
Raw JSON: docs/api_responses/samples/nhlApi-club-schedule-season-by-team-now.txt
Usage File: docs/integrations/nhl-responses/club-schedule-season-by-team-now.md
Local Status: curl-example

## Get Team Season Schedule

Endpoint: /v1/club-schedule-season/{team}/{season}
Method: GET
Description: Retrieve the season schedule for a specific team and season.
Parameters: team, season
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/club-schedule-season/TOR/20232024"
Raw JSON: docs/api_responses/samples/nhlApi-club-schedule-season-by-team-by-season.txt
Usage File: docs/integrations/nhl-responses/club-schedule-season-by-team-by-season.md
Local Status: curl-example

## Get Month Schedule As of Now

Endpoint: /v1/club-schedule/{team}/month/now
Method: GET
Description: Retrieve the monthly schedule for a specific team as of the current moment.
Parameters: team
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/club-schedule/TOR/month/now"
Raw JSON: docs/api_responses/samples/nhlApi-club-schedule-by-team-month-now.txt
Usage File: docs/integrations/nhl-responses/club-schedule-by-team-month-now.md
Local Status: curl-example

## Get Month Schedule

Endpoint: /v1/club-schedule/{team}/month/{month}
Method: GET
Description: Retrieve the monthly schedule for a specific team and month.
Parameters: team, month
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/club-schedule/TOR/month/2023-11"
Raw JSON: docs/api_responses/samples/nhlApi-club-schedule-by-team-month-by-month.txt
Usage File: docs/integrations/nhl-responses/club-schedule-by-team-month-by-month.md
Local Status: curl-example

## Get Week Schedule

Endpoint: /v1/club-schedule/{team}/week/{date}
Method: GET
Description: Retrieve the weekly schedule for a specific team and date.
Parameters: team, date
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/club-schedule/TOR/week/2023-11-10"
Raw JSON: docs/api_responses/samples/nhlApi-club-schedule-by-team-week-by-date.txt
Usage File: docs/integrations/nhl-responses/club-schedule-by-team-week-by-date.md
Local Status: curl-example

## Get Week Schedule As of Now

Endpoint: /v1/club-schedule/{team}/week/now
Method: GET
Description: Retrieve the weekly schedule for a specific team as of the current moment.
Parameters: team
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/club-schedule/TOR/week/now"
Raw JSON: docs/api_responses/samples/nhlApi-club-schedule-by-team-week-now.txt
Usage File: docs/integrations/nhl-responses/club-schedule-by-team-week-now.md
Local Status: curl-example

## Get Current Schedule

Endpoint: /v1/schedule/now
Method: GET
Description: Retrieve the current schedule.
Parameters: None documented.
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/schedule/now"
Raw JSON: docs/api_responses/samples/nhlApi-schedule-now.txt
Usage File: docs/integrations/nhl-responses/schedule-now.md
Local Status: curl-example

## Get Schedule by Date

Endpoint: /v1/schedule/{date}
Method: GET
Description: Retrieve the schedule for a specific date.
Parameters: date
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/schedule/2023-11-10"
Raw JSON: docs/api_responses/samples/nhlApi-schedule-by-date.txt
Usage File: docs/integrations/nhl-responses/schedule-by-date.md
Local Status: curl-example

## Get Schedule Calendar As of Now

Endpoint: /v1/schedule-calendar/now
Method: GET
Description: Retrieve the schedule calendar as of the current moment.
Parameters: None documented.
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/schedule-calendar/now"
Raw JSON: docs/api_responses/samples/nhlApi-schedule-calendar-now.txt
Usage File: docs/integrations/nhl-responses/schedule-calendar-now.md
Local Status: curl-example

## Get Schedule Calendar for a Specific Date

Endpoint: /v1/schedule-calendar/{date}
Method: GET
Description: Retrieve the schedule calendar for a specific date.
Parameters: date
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/schedule-calendar/2023-11-10"
Raw JSON: docs/api_responses/samples/nhlApi-schedule-calendar-by-date.txt
Usage File: docs/integrations/nhl-responses/schedule-calendar-by-date.md
Local Status: curl-example

## Get Daily Scores As of Now

Endpoint: /v1/score/now
Method: GET
Description: Retrieve daily scores as of the current moment.
Parameters: None documented.
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/score/now"
Raw JSON: docs/api_responses/samples/nhlApi-score-now.txt
Usage File: docs/integrations/nhl-responses/score-now.md
Local Status: curl-example

## Get Daily Scores by Date

Endpoint: /v1/score/{date}
Method: GET
Description: Retrieve daily scores for a specific date.
Parameters: date
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/score/2023-11-10"
Raw JSON: docs/api_responses/samples/nhlApi-score-by-date.txt
Usage File: docs/integrations/nhl-responses/score-by-date.md
Local Status: curl-example

## Get Scoreboard

Endpoint: /v1/scoreboard/now
Method: GET
Description: Retrieve the overall scoreboard as of the current moment.
Parameters: None documented.
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/scoreboard/now"
Raw JSON: docs/api_responses/samples/nhlApi-scoreboard-now.txt
Usage File: docs/integrations/nhl-responses/scoreboard-now.md
Local Status: curl-example

## Get Streams

Endpoint: /v1/where-to-watch
Method: GET
Description: Retrieve information about streaming options.
Parameters: include
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/where-to-watch"
Raw JSON: docs/api_responses/samples/nhlApi-where-to-watch.txt
Usage File: docs/integrations/nhl-responses/where-to-watch.md
Local Status: curl-example

## Get Play By Play

Endpoint: /v1/gamecenter/{game-id}/play-by-play
Method: GET
Description: Retrieve play-by-play information for a specific game.
Parameters: game-id
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/gamecenter/2023020204/play-by-play"
Raw JSON: docs/api_responses/samples/nhlApi-gamecenter-by-game-id-play-by-play.txt
Usage File: docs/integrations/nhl-responses/gamecenter-by-game-id-play-by-play.md
Local Status: curl-example

## Get Landing

Endpoint: /v1/gamecenter/{game-id}/landing
Method: GET
Description: Retrieve landing information for a specific game.
Parameters: game-id
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/gamecenter/2023020204/landing"
Raw JSON: docs/api_responses/samples/nhlApi-gamecenter-by-game-id-landing.txt
Usage File: docs/integrations/nhl-responses/gamecenter-by-game-id-landing.md
Local Status: curl-example

## Get Boxscore

Endpoint: /v1/gamecenter/{game-id}/boxscore
Method: GET
Description: Retrieve boxscore information for a specific game.
Parameters: game-id
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/gamecenter/2023020204/boxscore"
Raw JSON: docs/api_responses/samples/nhlApi-gamecenter-by-game-id-boxscore.txt
Usage File: docs/integrations/nhl-responses/gamecenter-by-game-id-boxscore.md
Local Status: curl-example

## Get Game Story

Endpoint: /v1/wsc/game-story/{game-id}
Method: GET
Description: Retrieve game story information for a specific game.
Parameters: game-id
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/wsc/game-story/2023020204"
Raw JSON: docs/api_responses/samples/nhlApi-wsc-game-story-by-game-id.txt
Usage File: docs/integrations/nhl-responses/wsc-game-story-by-game-id.md
Local Status: curl-example

## Get TV Schedule for a Specific Date

Endpoint: /v1/network/tv-schedule/{date}
Method: GET
Description: Retrieve the TV schedule for a specific date.
Parameters: date
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/network/tv-schedule/2023-11-10"
Raw JSON: docs/api_responses/samples/nhlApi-network-tv-schedule-by-date.txt
Usage File: docs/integrations/nhl-responses/network-tv-schedule-by-date.md
Local Status: curl-example

## Get Current TV Schedule

Endpoint: /v1/network/tv-schedule/now
Method: GET
Description: Retrieve the current TV schedule.
Parameters: None documented.
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/network/tv-schedule/now"
Raw JSON: docs/api_responses/samples/nhlApi-network-tv-schedule-now.txt
Usage File: docs/integrations/nhl-responses/network-tv-schedule-now.md
Local Status: curl-example

## Get Partner Game Odds

Endpoint: /v1/partner-game/{country-code}/now
Method: GET
Description: Retrieve odds for games in a specific country as of the current moment.
Parameters: country-code
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/partner-game/US/now"
Raw JSON: docs/api_responses/samples/nhlApi-partner-game-by-country-code-now.txt
Usage File: docs/integrations/nhl-responses/partner-game-by-country-code-now.md
Local Status: curl-example

## Playoff Series Carousel

Endpoint: /v1/playoff-series/carousel/{season}/
Method: GET
Description: Retrieve an overview of each playoff series.
Parameters: season
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/playoff-series/carousel/20232024/"
Raw JSON: docs/api_responses/samples/nhlApi-playoff-series-carousel-by-season.txt
Usage File: docs/integrations/nhl-responses/playoff-series-carousel-by-season.md
Local Status: curl-example

## Get Playoff Series Schedule

Endpoint: /v1/schedule/playoff-series/{season}/{series_letter}/
Method: GET
Description: Retrieve the schedule for a specific playoff series.
Parameters: season, series_letter
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/schedule/playoff-series/20232024/a"
Raw JSON: docs/api_responses/samples/nhlApi-schedule-playoff-series-by-season-by-series-letter.txt
Usage File: docs/integrations/nhl-responses/schedule-playoff-series-by-season-by-series-letter.md
Local Status: curl-example

## Get Playoff Bracket

Endpoint: /v1/playoff-bracket/{year}
Method: GET
Description: Retrieve the current bracket for a specific year playoffs.
Parameters: year
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/playoff-bracket/2022"
Raw JSON: docs/api_responses/samples/nhlApi-playoff-bracket-by-year.txt
Usage File: docs/integrations/nhl-responses/playoff-bracket-by-year.md
Local Status: curl-example

## Get Seasons

Endpoint: /v1/season
Method: GET
Description: Retrieve a list of all season IDs past and present in the NHL.
Parameters: None documented.
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/season"
Raw JSON: docs/api_responses/samples/nhlApi-season.txt
Usage File: docs/integrations/nhl-responses/season.md
Local Status: curl-example

## Get Draft Rankings

Endpoint: /v1/draft/rankings/now
Method: GET
Description: Retrieve a list of all draft prospects by category of prospect as of the current moment.
Parameters: None documented.
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/draft/rankings/now"
Raw JSON: docs/api_responses/samples/nhlApi-draft-rankings-now.txt
Usage File: docs/integrations/nhl-responses/draft-rankings-now.md
Local Status: curl-example

## Get Draft Rankings by Date

Endpoint: /v1/draft/rankings/{season}/{prospect_category}
Method: GET
Description: Retrieve a list of all draft prospects by category of prospect for a specific season.
Parameters: season, prospect_category
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/draft/rankings/2023/1"
Raw JSON: docs/api_responses/samples/nhlApi-draft-rankings-by-season-by-prospect-category.txt
Usage File: docs/integrations/nhl-responses/draft-rankings-by-season-by-prospect-category.md
Local Status: curl-example

## Get Draft Tracker Now

Endpoint: /v1/draft-tracker/picks/now
Method: GET
Description: Retrieve current draft tracker information with the most recent draft picks.
Parameters: None documented.
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/draft-tracker/picks/now"
Raw JSON: docs/api_responses/samples/nhlApi-draft-tracker-picks-now.txt
Usage File: docs/integrations/nhl-responses/draft-tracker-picks-now.md
Local Status: curl-example

## Get Draft Picks Now

Endpoint: /v1/draft/picks/now
Method: GET
Description: Retrieve the most recent draft picks information.
Parameters: None documented.
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/draft/picks/now"
Raw JSON: docs/api_responses/samples/nhlApi-draft-picks-now.txt
Usage File: docs/integrations/nhl-responses/draft-picks-now.md
Local Status: curl-example

## Get Draft Picks

Endpoint: /v1/draft/picks/{season}/{round}
Method: GET
Description: Retrieve a list of draft picks for a specific season.
Parameters: season, round
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/draft/picks/2023/all"
Raw JSON: docs/api_responses/samples/nhlApi-draft-picks-by-season-by-round.txt
Usage File: docs/integrations/nhl-responses/draft-picks-by-season-by-round.md
Local Status: curl-example

## Get Meta Information

Endpoint: /v1/meta
Method: GET
Description: Retrieve meta information.
Parameters: players, teams, seasonStates
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/meta?players=8478402&teams=EDM,TOR"
Raw JSON: docs/api_responses/samples/nhlApi-meta.txt
Usage File: docs/integrations/nhl-responses/meta.md
Local Status: curl-example

## Get Game Information

Endpoint: /v1/meta/game/{game-id}
Method: GET
Description: Retrieve information for a specific game.
Parameters: game-id
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/meta/game/2023020204"
Raw JSON: docs/api_responses/samples/nhlApi-meta-game-by-game-id.txt
Usage File: docs/integrations/nhl-responses/meta-game-by-game-id.md
Local Status: curl-example

## Get Location

Endpoint: /v1/location
Method: GET
Description: Returns country code that the webserver thinks the user is in.
Parameters: None documented.
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/location"
Raw JSON: docs/api_responses/samples/nhlApi-location.txt
Usage File: docs/integrations/nhl-responses/location.md
Local Status: curl-example

## Get Playoff Series Metadata

Endpoint: /v1/meta/playoff-series/{year}/{series_letter}
Method: GET
Description: Retrieve metadata for a specific playoff series.
Parameters: year, series_letter
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/meta/playoff-series/2023/a"
Raw JSON: docs/api_responses/samples/nhlApi-meta-playoff-series-by-year-by-series-letter.txt
Usage File: docs/integrations/nhl-responses/meta-playoff-series-by-year-by-series-letter.md
Local Status: curl-example

## Get Postal Code Information

Endpoint: /v1/postal-lookup/{postalCode}
Method: GET
Description: Retrieves information based on a postal code.
Parameters: postalCode
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/postal-lookup/90210"
Raw JSON: docs/api_responses/samples/nhlApi-postal-lookup-by-postalcode.txt
Usage File: docs/integrations/nhl-responses/postal-lookup-by-postalcode.md
Local Status: curl-example

## Get Goal Replay

Endpoint: /v1/ppt-replay/goal/{game-id}/{event-number}
Method: GET
Description: Retrieves goal replay information for a specific game and event.
Parameters: game-id, event-number
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/ppt-replay/goal/2023020204/12"
Raw JSON: docs/api_responses/samples/nhlApi-ppt-replay-goal-by-game-id-by-event-number.txt
Usage File: docs/integrations/nhl-responses/ppt-replay-goal-by-game-id-by-event-number.md
Local Status: curl-example

## Get Play Replay

Endpoint: /v1/ppt-replay/{game-id}/{event-number}
Method: GET
Description: Retrieves replay information for a specific game and event.
Parameters: game-id, event-number
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/ppt-replay/2023020204/12"
Raw JSON: docs/api_responses/samples/nhlApi-ppt-replay-by-game-id-by-event-number.txt
Usage File: docs/integrations/nhl-responses/ppt-replay-by-game-id-by-event-number.md
Local Status: curl-example

## Get Game Right Rail Content

Endpoint: /v1/gamecenter/{game-id}/right-rail
Method: GET
Description: Retrieves sidebar content for the game center view.
Parameters: game-id
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/gamecenter/2023020204/right-rail"
Raw JSON: docs/api_responses/samples/nhlApi-gamecenter-by-game-id-right-rail.txt
Usage File: docs/integrations/nhl-responses/gamecenter-by-game-id-right-rail.md
Local Status: curl-example

## Get WSC Play By Play

Endpoint: /v1/wsc/play-by-play/{game-id}
Method: GET
Description: Retrieves WSC play-by-play information for a specific game.
Parameters: game-id
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/wsc/play-by-play/2023020204"
Raw JSON: docs/api_responses/samples/nhlApi-wsc-play-by-play-by-game-id.txt
Usage File: docs/integrations/nhl-responses/wsc-play-by-play-by-game-id.md
Local Status: curl-example

## Get OpenAPI Specification

Endpoint: /model/v1/openapi.json
Method: GET
Description: Retrieve the OpenAPI specification. Seems to return 404 currently.
Parameters: None documented.
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/model/v1/openapi.json"
Raw JSON: docs/api_responses/samples/nhlApi-model-v1-openapi-json.txt
Usage File: docs/integrations/nhl-responses/model-v1-openapi-json.md
Local Status: curl-example

## Team Details

Endpoint: /v1/edge/team-detail/{team-id}/{season}/{game-type}; /v1/edge/team-detail/{team-id}/now
Method: GET
Description: Retrieve team-based ranking for NHL Edge data
Parameters: team-id, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/team-detail/9/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-team-detail-by-team-id-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-team-detail-by-team-id-by-season-by-game-type.md
Local Status: curl-example

## Team Landing

Endpoint: /v1/edge/team-detail/{season}/{game-type}; /v1/edge/team-detail/now
Method: GET
Description: Retrieve the leading team for each NHL Edge data point
Parameters: season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/team-landing/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-team-landing-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-team-landing-by-season-by-game-type.md
Local Status: curl-example

## Team Comparison

Endpoint: /v1/edge/team-comparison/{team-id}/{season}/{game-type}; /v1/edge/team-comparison/{team-id}/now
Method: GET
Description: General information and comparison to league average for NHL Edge datapoints. Includes shots by location and shooting percentage by location.
Parameters: team-id, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/team-comparison/9/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-team-comparison-by-team-id-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-team-comparison-by-team-id-by-season-by-game-type.md
Local Status: curl-example

## Team Skating Distance - Top 10

Endpoint: /v1/edge/team-skating-distance-top-10/{positions}/{strength}/{sort-by}/{season}{game-type}; /v1/edge/team-skating-distance-top-10/{positions}/{strength}/{sort-by}/now
Method: GET
Description: Retrieve team-based ranking for NHL Edge data - TODO
Parameters: position, strength, sort-by, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/team-skating-distance-top-10/F/pp/{sort-by}/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-team-skating-distance-top-10-by-positions-by-strength-by-sort-by-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-team-skating-distance-top-10-by-positions-by-strength-by-sort-by-by-season-by-game-type.md
Local Status: needs-runnable-curl-example

## Team Skating Distance - Detail

Endpoint: /v1/edge/team-skating-distance-detail/{team-id}/{season}/{game-type}; /v1/edge/team-skating-distance-detail/{team-id}/now
Method: GET
Description: Skating distance details for all situations and positions, both in last 10 games and in the season.
Parameters: team-id, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/team-skating-distance-detail/9/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-team-skating-distance-detail-by-team-id-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-team-skating-distance-detail-by-team-id-by-season-by-game-type.md
Local Status: curl-example

## Team Skating Speed - Top 10

Endpoint: /v1/edge/team-skating-speed-top-10/{position}/{sort-by}/{season}/{game-type}; /v1/edge/team-skating-speed-top-10/{positions}/{sort-by}/now
Method: GET
Description: Retrieve team-based ranking for NHL Edge data - TODO
Parameters: position, sort-by, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/team-skating-speed-top-10/F/max/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-team-skating-speed-top-10-by-position-by-sort-by-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-team-skating-speed-top-10-by-position-by-sort-by-by-season-by-game-type.md
Local Status: curl-example

## Team Skating Speed - Detail

Endpoint: /v1/edge/team-skating-speed-detail/{team-id}/{season}/{game-type}; /v1/edge/team-skating-speed-detail/{team-id}/now
Method: GET
Description: Zone time details by situation.
Parameters: team-id, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/team-skating-speed-detail/9/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-team-skating-speed-detail-by-team-id-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-team-skating-speed-detail-by-team-id-by-season-by-game-type.md
Local Status: curl-example

## Team Zone Time - Top 10

Endpoint: /v1/edge/team-zone-time-top-10/{strength}/{sort-by}/{season}/{game-type}; /v1/edge/team-zone-time-top-10/{strength}/{sort-by}/now
Method: GET
Description: Top 10 teams by specified zone time
Parameters: strength, sort-by, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/team-zone-time-top-10/es/offensive/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-team-zone-time-top-10-by-strength-by-sort-by-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-team-zone-time-top-10-by-strength-by-sort-by-by-season-by-game-type.md
Local Status: curl-example

## Team Zone Time - Details

Endpoint: /v1/edge/team-zone-time-details/{team-id}/{season}/{game-type}; /v1/edge/team-zone-time-details/{team-id}/now
Method: GET
Description: Zone time details by situation.
Parameters: team-id, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/team-zone-time-details/9/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-team-zone-time-details-by-team-id-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-team-zone-time-details-by-team-id-by-season-by-game-type.md
Local Status: curl-example

## Team Shot Speed - Top 10

Endpoint: /v1/edge/team-shot-speed-top-10/{positions}/{sort-by}/{season}/{game-type}; /v1/edge/team-shot-speed-top-10/{positions}/{sort-by}/now
Method: GET
Description: Retrieve team-based ranking for NHL Edge data - TODO
Parameters: position, sort-by, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/team-shot-speed-top-10/F/{sort-by}/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-team-shot-speed-top-10-by-positions-by-sort-by-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-team-shot-speed-top-10-by-positions-by-sort-by-by-season-by-game-type.md
Local Status: needs-runnable-curl-example

## Team Shot Speed - Detail

Endpoint: /v1/edge/team-shot-speed-detail/{team-id}/{season}/{game-type}; /v1/edge/team-shot-speed-detail/{team-id}/now
Method: GET
Description: Top 10 Shots by speed, shot speed details by position
Parameters: team-id, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/team-shot-speed-detail/9/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-team-shot-speed-detail-by-team-id-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-team-shot-speed-detail-by-team-id-by-season-by-game-type.md
Local Status: curl-example

## Team Shot Location - Top 10

Endpoint: /v1/edge/team-shot-location-top-10/{position}/{category}/{sort-by}/{season}/{game-type}; /v1/edge/team-shot-location-top-10/{position}/{category}/{sort-by}/now
Method: GET
Description: Retrieve team-based ranking for NHL Edge data - TODO
Parameters: position, category, sort-by, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/team-shot-location-top-10/F/{category}/{sort-by}/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-team-shot-location-top-10-by-position-by-category-by-sort-by-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-team-shot-location-top-10-by-position-by-category-by-sort-by-by-season-by-game-type.md
Local Status: needs-runnable-curl-example

## Team Shot Location - Detail

Endpoint: /v1/edge/team-shot-location-detail/{team-id}/{season}/{game-type}; /v1/edge/team-shot-location-detail/{team-id}/now
Method: GET
Description: Shot count by all locations and positions
Parameters: team-id, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/team-shot-location-detail/9/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-team-shot-location-detail-by-team-id-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-team-shot-location-detail-by-team-id-by-season-by-game-type.md
Local Status: curl-example

## Skater Detail

Endpoint: /v1/edge/skater-detail/{player-id}/{season}/{game-type}; /v1/edge/skater-detail/{player-id}/now
Method: GET
Description: Retrieve player rankings for NHL Edge data.
Parameters: player-id, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/skater-detail/8482116/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-skater-detail-by-player-id-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-skater-detail-by-player-id-by-season-by-game-type.md
Local Status: curl-example

## Skater Landing

Endpoint: /v1/edge/skater-landing/{season}/{game-type}; /v1/edge/skater-landing/now
Method: GET
Description: Retrieve leading player for NHL Edge data.
Parameters: season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/skater-landing/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-skater-landing-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-skater-landing-by-season-by-game-type.md
Local Status: curl-example

## Skater Comparison

Endpoint: /v1/edge/skater-comparison/{player-id}/{season}/{game-type}; /v1/edge/skater-comparison/{player-id}/now
Method: GET
Description: Retrieve NHL Edge data for the specified player.
Parameters: player-id, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/skater-comparison/8482116/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-skater-comparison-by-player-id-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-skater-comparison-by-player-id-by-season-by-game-type.md
Local Status: curl-example

## Skater Distance - Top 10

Endpoint: /v1/edge/skater-distance-top-10/{positions}/{strength}/{sort-by}/{season}/{game-type}; /v1/edge/skater-distance-top-10/{positions}/{strength}/{sort-by}/now
Method: GET
Description: Retrieve top 10 skaters in skating distance based on the provided filters
Parameters: position, strength, sort-by, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/skater-distance-top-10/D/all/total/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-skater-distance-top-10-by-positions-by-strength-by-sort-by-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-skater-distance-top-10-by-positions-by-strength-by-sort-by-by-season-by-game-type.md
Local Status: curl-example

## Skater Skating Distance - Detail

Endpoint: /v1/edge/skater-skating-distance-detail/{player-id}/{season}/{game-type}; /v1/edge/skater-skating-distance-detail/{player-id}/now
Method: GET
Description: Shot count by all locations and positions
Parameters: player-id, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/skater-skating-distance-detail/8482116/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-skater-skating-distance-detail-by-player-id-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-skater-skating-distance-detail-by-player-id-by-season-by-game-type.md
Local Status: curl-example

## Skater Speed - Top 10

Endpoint: /v1/edge/skater-speed-top-10/{positions}/{sort-by}/{season}/{game-type}; /v1/edge/skater-speed-top-10/{positions}/{sort-by}/now
Method: GET
Description: Retrieve 10 fastest skaters based on the provided filters.
Parameters: position, sort-by, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/skater-speed-top-10/F/max/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-skater-speed-top-10-by-positions-by-sort-by-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-skater-speed-top-10-by-positions-by-sort-by-by-season-by-game-type.md
Local Status: curl-example

## Skater Skating Speed - Detail

Endpoint: /v1/edge/skater-skating-speed-detail/{player-id}/{season}/{game-type}; /v1/edge/skater-skating-speed-detail/{player-id}/now
Method: GET
Description: Retrieve top 10 skating speeds for the provided player.
Parameters: player-id, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/skater-skating-speed-detail/8482116/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-skater-skating-speed-detail-by-player-id-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-skater-skating-speed-detail-by-player-id-by-season-by-game-type.md
Local Status: curl-example

## Skater Zone Time - Top 10

Endpoint: /v1/edge/skater-zone-time-top-10/{positions}/{strength}/{sort-by}/{season}/{game-type}; /v1/edge/skater-zone-time-top-10/{positions}/{strength}/{sort-by}/now
Method: GET
Description: Retrieve 10 fastest skaters based on the provided filters.
Parameters: position, strength, sort-by, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/skater-zone-time-top-10/F/all/offensive/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-skater-zone-time-top-10-by-positions-by-strength-by-sort-by-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-skater-zone-time-top-10-by-positions-by-strength-by-sort-by-by-season-by-game-type.md
Local Status: curl-example

## Skater Zone Time - Detail

Endpoint: /v1/edge/skater-zone-time/{player-id}/{season}/{game-type}; /v1/edge/skater-zone-time/{player-id}/now
Method: GET
Description: Zone time details by situation. Includes zone starts.
Parameters: player-id, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/skater-zone-time/8482116/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-skater-zone-time-by-player-id-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-skater-zone-time-by-player-id-by-season-by-game-type.md
Local Status: curl-example

## Skater Shot Speed - Top 10

Endpoint: /v1/edge/skater-shot-speed-top-10/{positions}/{sort-by}/{season}/{game-type}; /v1/edge/skater-shot-speed-top-10/{positions}/{sort-by}/now
Method: GET
Description: Retrieve 10 players with the fastest shot based on the provided filters.
Parameters: position, sort-by, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/skater-shot-speed-top-10/F/max/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-skater-shot-speed-top-10-by-positions-by-sort-by-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-skater-shot-speed-top-10-by-positions-by-sort-by-by-season-by-game-type.md
Local Status: curl-example

## Skater Shot Speed - Detail

Endpoint: /v1/edge/skater-shot-speed-detail/{player-id}/{season}/{game-type}; /v1/edge/skater-shot-speed-detail/{player-id}/now
Method: GET
Description: Provides the 10 hardest shots for a specified player.
Parameters: player-id, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/skater-shot-speed-detail/8482116/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-skater-shot-speed-detail-by-player-id-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-skater-shot-speed-detail-by-player-id-by-season-by-game-type.md
Local Status: curl-example

## Skater Shot Location - Top 10

Endpoint: /v1/edge/skater-shot-location-top-10/{position}/{category}/{sort-by}/{season}/{game-type}; /v1/edge/skater-shot-location-top-10/{position}/{category}/{sort-by}/now
Method: GET
Description: Presumably top 10 skaters based on the specified filters. -TODO
Parameters: position, category, sort-by, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/skater-shot-location-top-10/F/{category}/{sort-by}/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-skater-shot-location-top-10-by-position-by-category-by-sort-by-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-skater-shot-location-top-10-by-position-by-category-by-sort-by-by-season-by-game-type.md
Local Status: needs-runnable-curl-example

## Skater Shot Location - Detail

Endpoint: /v1/edge/skater-shot-location-detail/{player-id}/{season}/{game-type}; /v1/edge/skater-shot-location-detail/{player-id}/now
Method: GET
Description: Provides information on shot location
Parameters: player-id, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/skater-shot-location-detail/8482116/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-skater-shot-location-detail-by-player-id-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-skater-shot-location-detail-by-player-id-by-season-by-game-type.md
Local Status: curl-example

## CAT - Skater Detail

Endpoint: /v1/cat/edge/skater-detail/{player-id}/{season}/{game-type}; /v1/cat/edge/skater-detail/{player-id}/now
Method: GET
Description: Provides information on top shot speed, skating speed/distance, shots on goal summary/details and zone time details.
Parameters: player-id, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/cat/edge/skater-detail/8482116/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-cat-edge-skater-detail-by-player-id-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/cat-edge-skater-detail-by-player-id-by-season-by-game-type.md
Local Status: curl-example

## Goalie Detail

Endpoint: /v1/edge/goalie-detail/{player-id}/{season}/{game-type}; /v1/edge/goalie-detail/{player-id}/now
Method: GET
Description: Retrieve goalie rankings for NHL Edge data.
Parameters: player-id, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/goalie-detail/8476999/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-goalie-detail-by-player-id-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-goalie-detail-by-player-id-by-season-by-game-type.md
Local Status: curl-example

## Goalie Landing

Endpoint: /v1/edge/goalie-landing/{season}/{game-type}; /v1/edge/goalie-landing/now
Method: GET
Description: Retrieve leading goalie for NHL Edge data.
Parameters: season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/goalie-landing/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-goalie-landing-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-goalie-landing-by-season-by-game-type.md
Local Status: curl-example

## Goalie Comparison

Endpoint: /v1/edge/goalie-comparison/{player-id}/{season}/{game-type}; /v1/edge/goalie-comparison/{player-id}/now
Method: GET
Description: Retrieve NHL Edge data for the specified player.
Parameters: player-id, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/goalie-comparison/8476999/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-goalie-comparison-by-player-id-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-goalie-comparison-by-player-id-by-season-by-game-type.md
Local Status: curl-example

## Goalie 5v5 - Top 10

Endpoint: /v1/edge/goalie-5v5-top-10/{sort-by}/{season}/{game-type}; /v1/edge/goalie-5v5-top-10/{sort-by}/now
Method: GET
Description: Top 10 goalies based on the specified filters.
Parameters: sort-by, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/goalie-5v5-top-10/shots/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-goalie-5v5-top-10-by-sort-by-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-goalie-5v5-top-10-by-sort-by-by-season-by-game-type.md
Local Status: curl-example

## Goalie 5v5 - Detail

Endpoint: /v1/edge/goalie-5v5-detail/{player-id}/{season}/{game-type}; /v1/edge/goalie-5v5-detail/{player-id}/now
Method: GET
Description: 5v5 save percentage details for the specified player.
Parameters: player-id, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/goalie-5v5-detail/8476999/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-goalie-5v5-detail-by-player-id-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-goalie-5v5-detail-by-player-id-by-season-by-game-type.md
Local Status: curl-example

## Goalie Shot Location - Top 10

Endpoint: /v1/edge/goalie-shot-location-top-10/{category}/{sort-by}/{season}/{game-type}; /v1/edge/goalie-shot-location-top-10/{category}/{sort-by}/now
Method: GET
Description: Presumably top 10 goalies based on the specified filters. -TODO
Parameters: category, sort-by, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/goalie-shot-location-top-10/{category}/{sort-by}/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-goalie-shot-location-top-10-by-category-by-sort-by-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-goalie-shot-location-top-10-by-category-by-sort-by-by-season-by-game-type.md
Local Status: needs-runnable-curl-example

## Goalie Shot Location - Detail

Endpoint: /v1/edge/goalie-shot-location-detail/{player-id}/{season}/{game-type}; /v1/edge/goalie-shot-location-detail/{player-id}/now
Method: GET
Description: Goalie shot location details for the specified player.
Parameters: player-id, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/goalie-shot-location-detail/8476999/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-goalie-shot-location-detail-by-player-id-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-goalie-shot-location-detail-by-player-id-by-season-by-game-type.md
Local Status: curl-example

## Goalie Save Percentage - Top 10

Endpoint: /v1/edge/goalie-edge-save-pctg-top-10/{sort-by}/{season}/{game-type}; /v1/edge/goalie-edge-save-pctg-top-10/{sort-by}/now
Method: GET
Description: Unknown. -TODO
Parameters: sort-by, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/goalie-edge-save-pctg-top-10/{sort-by}/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-goalie-edge-save-pctg-top-10-by-sort-by-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-goalie-edge-save-pctg-top-10-by-sort-by-by-season-by-game-type.md
Local Status: needs-runnable-curl-example

## Goalie Save Percentage - Detail

Endpoint: /v1/edge/goalie-save-percentage-detail/{player-id}/{season}/{game-type}; /v1/edge/goalie-save-percentage-detail/{player-id}/now
Method: GET
Description: Goalie save percentage details for the specified player.
Parameters: player-id, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/edge/goalie-save-percentage-detail/8476999/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-edge-goalie-save-percentage-detail-by-player-id-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/edge-goalie-save-percentage-detail-by-player-id-by-season-by-game-type.md
Local Status: curl-example

## CAT - Goalie Detail

Endpoint: /v1/cat/edge/goalie-detail/{player-id}/{season}/{game-type}; /v1/cat/edge/goalie-detail/{player-id}/now
Method: GET
Description: Provides information on GAA, games above .900, goal differential per 60, goal support average, point percentage, shot location summary/details.
Parameters: player-id, season, game-type
Response: JSON format
Example using cURL:
curl -X GET "https://api-web.nhle.com/v1/cat/edge/skater-detail/8482116/20242025/2"
Raw JSON: docs/api_responses/samples/nhlApi-cat-edge-goalie-detail-by-player-id-by-season-by-game-type.txt
Usage File: docs/integrations/nhl-responses/cat-edge-goalie-detail-by-player-id-by-season-by-game-type.md
Local Status: curl-example

## Get Player Information

Endpoint: /{lang}/players
Method: GET
Description: Retrieve basic player information. Responses limited to 5 results.
Parameters: lang, include, exclude, cayenneExp, sort, dir, start, limit
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/en/players?limit=3&sort=lastName&dir=asc&cayenneExp=currentTeamId=7"
Raw JSON: docs/api_responses/samples/nhlApi-by-lang-players.txt
Usage File: docs/integrations/nhl-responses/by-lang-players.md
Local Status: curl-example

## Get Skater Leaders

Endpoint: /{lang}/leaders/skaters/{attribute}
Method: GET
Description: Retrieve skater leaders for a specific attribute.
Parameters: lang, attribute
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/en/leaders/skaters/points"
Raw JSON: docs/api_responses/samples/nhlApi-by-lang-leaders-skaters-by-attribute.txt
Usage File: docs/integrations/nhl-responses/by-lang-leaders-skaters-by-attribute.md
Local Status: curl-example

## Get Skater Milestones

Endpoint: /{lang}/milestones/skaters
Method: GET
Description: Retrieve skater milestones.
Parameters: lang
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/en/milestones/skaters"
Raw JSON: docs/api_responses/samples/nhlApi-by-lang-milestones-skaters.txt
Usage File: docs/integrations/nhl-responses/by-lang-milestones-skaters.md
Local Status: curl-example

## Get Skater Information

Endpoint: /{lang}/skater
Method: GET
Description: Retrieve skater information.
Parameters: lang
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/en/skater"
Raw JSON: docs/api_responses/samples/nhlApi-by-lang-skater.txt
Usage File: docs/integrations/nhl-responses/by-lang-skater.md
Local Status: curl-example

## Get Skater Stats

Endpoint: /{lang}/skater/{report}
Method: GET
Description: Retrieve skater stats for a specific report.
Parameters: lang, report, isAggregate, isGame, factCayenneExp, include, exclude, cayenneExp, sort, dir, start, limit
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/en/skater/summary?limit=72&start=17&sort=points&cayenneExp=seasonId=20232024"
Raw JSON: docs/api_responses/samples/nhlApi-by-lang-skater-by-report.txt
Usage File: docs/integrations/nhl-responses/by-lang-skater-by-report.md
Local Status: curl-example

## Get Goalie Leaders

Endpoint: /{lang}/leaders/goalies/{attribute}
Method: GET
Description: Retrieve goalie leaders for a specific attribute.
Parameters: lang, attribute
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/en/leaders/goalies/gaa"
Raw JSON: docs/api_responses/samples/nhlApi-by-lang-leaders-goalies-by-attribute.txt
Usage File: docs/integrations/nhl-responses/by-lang-leaders-goalies-by-attribute.md
Local Status: curl-example

## Get Goalie Stats

Endpoint: /{lang}/goalie/{report}
Method: GET
Description: Retrieve goalie stats for a specific report.
Parameters: lang, report, isAggregate, isGame, factCayenneExp, include, exclude, cayenneExp, sort, dir, start, limit
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/en/goalie/summary?limit=72&start=15&sort=wins&cayenneExp=seasonId=20232024"
Raw JSON: docs/api_responses/samples/nhlApi-by-lang-goalie-by-report.txt
Usage File: docs/integrations/nhl-responses/by-lang-goalie-by-report.md
Local Status: curl-example

## Get Goalie Milestones

Endpoint: /{lang}/milestones/goalies
Method: GET
Description: Retrieve goalie milestones.
Parameters: lang
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/en/milestones/goalies"
Raw JSON: docs/api_responses/samples/nhlApi-by-lang-milestones-goalies.txt
Usage File: docs/integrations/nhl-responses/by-lang-milestones-goalies.md
Local Status: curl-example

## Get Draft Information

Endpoint: /{lang}/draft
Method: GET
Description: Retrieve draft information.
Parameters: lang
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/en/draft"
Raw JSON: docs/api_responses/samples/nhlApi-by-lang-draft.txt
Usage File: docs/integrations/nhl-responses/by-lang-draft.md
Local Status: curl-example

## Get Team Information

Endpoint: /{lang}/team
Method: GET
Description: Retrieve list of all teams.
Parameters: lang
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/en/team"
Raw JSON: docs/api_responses/samples/nhlApi-by-lang-team.txt
Usage File: docs/integrations/nhl-responses/by-lang-team.md
Local Status: curl-example

## Get Team By ID

Endpoint: /{lang}/team/id/{id}
Method: GET
Description: Retrieve information for a specific team by ID.
Parameters: lang, id
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/en/team/id/10"
Raw JSON: docs/api_responses/samples/nhlApi-by-lang-team-id-by-id.txt
Usage File: docs/integrations/nhl-responses/by-lang-team-id-by-id.md
Local Status: curl-example

## Get Team Stats

Endpoint: /{lang}/team/{report}
Method: GET
Description: Retrieve team stats for a specific report.
Parameters: lang, report, isAggregate, isGame, factCayenneExp, include, exclude, cayenneExp, sort, dir, start, limit
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/en/team/summary?sort=shotsForPerGame&cayenneExp=seasonId=20232024%20and%20gameTypeId=2"
Raw JSON: docs/api_responses/samples/nhlApi-by-lang-team-by-report.txt
Usage File: docs/integrations/nhl-responses/by-lang-team-by-report.md
Local Status: curl-example

## Get Franchise Information

Endpoint: /{lang}/franchise
Method: GET
Description: Retrieve list of all franchises.
Parameters: lang
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/en/franchise"
Raw JSON: docs/api_responses/samples/nhlApi-by-lang-franchise.txt
Usage File: docs/integrations/nhl-responses/by-lang-franchise.md
Local Status: curl-example

## Get Component Season

Endpoint: /{lang}/componentSeason
Method: GET
Description: Retrieve component season information.
Parameters: None documented.
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/en/componentSeason"
Raw JSON: docs/api_responses/samples/nhlApi-by-lang-componentseason.txt
Usage File: docs/integrations/nhl-responses/by-lang-componentseason.md
Local Status: curl-example

## Get Season

Endpoint: /{lang}/season
Method: GET
Description: Retrieve season information.
Parameters: None documented.
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/en/season"
Raw JSON: docs/api_responses/samples/nhlApi-by-lang-season.txt
Usage File: docs/integrations/nhl-responses/by-lang-season.md
Local Status: curl-example

## Get Game Information

Endpoint: /{lang}/game
Method: GET
Description: Retrieve game information.
Parameters: None documented.
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/en/game"
Raw JSON: docs/api_responses/samples/nhlApi-by-lang-game.txt
Usage File: docs/integrations/nhl-responses/by-lang-game.md
Local Status: curl-example

## Get Game Metadata

Endpoint: /{lang}/game/meta
Method: GET
Description: Retrieve metadata for game.
Parameters: lang
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/en/game/meta"
Raw JSON: docs/api_responses/samples/nhlApi-by-lang-game-meta.txt
Usage File: docs/integrations/nhl-responses/by-lang-game-meta.md
Local Status: curl-example

## Get Configuration

Endpoint: /{lang}/config
Method: GET
Description: Retrieve configuration information.
Parameters: lang
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/en/config"
Raw JSON: docs/api_responses/samples/nhlApi-by-lang-config.txt
Usage File: docs/integrations/nhl-responses/by-lang-config.md
Local Status: curl-example

## Ping the Server

Endpoint: /ping
Method: GET
Description: Ping the server to check connectivity.
Parameters: None documented.
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/ping"
Raw JSON: docs/api_responses/samples/nhlApi-ping.txt
Usage File: docs/integrations/nhl-responses/ping.md
Local Status: curl-example

## Get Country Information

Endpoint: /{lang}/country
Method: GET
Description: Retrieve country information. Returns list of all countries with a hockey presence.
Parameters: lang
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/en/country"
Raw JSON: docs/api_responses/samples/nhlApi-by-lang-country.txt
Usage File: docs/integrations/nhl-responses/by-lang-country.md
Local Status: curl-example

## Get Shift Charts

Endpoint: /{lang}/shiftcharts?cayenneExp=gameId={game_id}
Method: GET
Description: Retrieve shift charts for a specific game.
Parameters: lang, game-id
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/en/shiftcharts?cayenneExp=gameId=2021020001"
Raw JSON: docs/api_responses/samples/nhlApi-by-lang-shiftcharts.txt
Usage File: docs/integrations/nhl-responses/by-lang-shiftcharts.md
Local Status: curl-example

## Get Glossary

Endpoint: /{lang}/glossary
Method: GET
Description: Retrieve the glossary for a specific language.
Parameters: lang
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/en/glossary"
Raw JSON: docs/api_responses/samples/nhlApi-by-lang-glossary.txt
Usage File: docs/integrations/nhl-responses/by-lang-glossary.md
Local Status: curl-example

## Get Content Module

Endpoint: /{lang}/content/module/{templateKey}
Method: GET
Description: Retrieve content module information for a specific template.
Parameters: lang, templateKey
Response: JSON format
Example using cURL:
curl -X GET "https://api.nhle.com/stats/rest/en/content/module/overview"
Raw JSON: docs/api_responses/samples/nhlApi-by-lang-content-module-by-templatekey.txt
Usage File: docs/integrations/nhl-responses/by-lang-content-module-by-templatekey.md
Local Status: curl-example
