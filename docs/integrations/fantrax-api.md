# Fantrax API Reference

Version: v1.2 (Beta)

## Introduction

The Fantrax REST API provides access to data for fantasy leagues on Fantrax.

The API is draft/beta documentation. Fantrax notes that the API has been built, and will continue to be built, based on user needs. A more complete document is expected in the future.

## API Usage

### General

The Fantrax API is a REST API. Requests are made with HTTP requests, and responses are returned in JSON format.

Extra request data can be sent either as query string parameters or as JSON in the body of a POST request.

Equivalent request examples:

```text
https://www.fantrax.com/fxea/general/getAdp?sport=MLB
```

```http
POST https://www.fantrax.com/fxea/general/getAdp
Content-Type: application/json

{"sport":"NFL"}
```

## Endpoints

### Retrieve Player IDs

Retrieves the Fantrax IDs that identify every player. Other Fantrax API calls use these IDs to refer to players.

URL:

```text
https://www.fantrax.com/fxea/general/getPlayerIds?sport=NFL
```

Request parameters:

None documented.

### Retrieve Player Info / ADP

Retrieves ADP (Average Draft Pick) information for all players in the specified sport, with optional filters.

URL:

```text
https://www.fantrax.com/fxea/general/getAdp
```

Request parameters:

| Parameter | Required | Description |
| --- | --- | --- |
| `sport` | Yes | One of `NFL`, `MLB`, `NHL`, `NBA`, `NCAAF`, `NCAAB`, `PGA`, `NASCAR`, `EPL`. |
| `position` | No | Standard position abbreviations, such as `QB` or `WR` for football. |
| `showAllPositions` | No | Whether to show the default position or all Fantrax positions for the player. Can be `true` or `false`. |
| `start` | No | Start offset. |
| `limit` | No | Result limit. |
| `order` | No | Player order. Can be `Name` or `ADP`; defaults to `ADP`. |

Request body examples:

```json
{"sport":"NFL"}
```

```json
{"sport":"NFL","position":"QB","start":1,"limit":5,"order":"NAME"}
```

Query string example:

```text
https://www.fantrax.com/fxea/general/getAdp?sport=NFL
```

### Retrieve League List

Retrieves the list of leagues, including the name and ID of each league, and the name(s) and ID(s) that the user owns in each league.

URL:

```text
https://www.fantrax.com/fxea/general/getLeagues
```

Request parameters:

| Parameter | Required | Description |
| --- | --- | --- |
| `userSecretId` | Yes | Secret ID shown on the Fantrax user profile screen. |

Request body example:

```json
{"userSecretId":"24pscnquxwekzngy"}
```

Query string example:

```text
https://www.fantrax.com/fxea/general/getLeagues?userSecretId=24pscnquxwekzngy
```

### Retrieve League Info

Retrieves information about a specific league. This includes team names and IDs, matchups, players in the pool with info, and many league configuration settings.

URL:

```text
https://www.fantrax.com/fxea/general/getLeagueInfo?leagueId=[League ID]
```

Request parameters:

None documented.

### Retrieve Draft Pick Info

Retrieves future and current draft picks in a specific league.

URL:

```text
https://www.fantrax.com/fxea/general/getDraftPicks?leagueId=[League ID]
```

Request parameters:

None documented.

### Retrieve Draft Results

Retrieves draft results for a specific league. Results can be retrieved live during a draft.

URL:

```text
https://www.fantrax.com/fxea/general/getDraftResults?leagueId=[League ID]
```

Request parameters:

None documented.

### Retrieve Team Rosters

Retrieves roster data for all teams, including salary and contract data if the league uses it, plus rostered players, statuses, and positions.

By default, rosters are retrieved for the upcoming/current period. The current period changes once the last game of the period starts. A specific period can also be requested.

URL:

```text
https://www.fantrax.com/fxea/general/getTeamRosters?leagueId=[League ID]&period=6
```

Request parameters:

| Parameter | Required | Description |
| --- | --- | --- |
| `period` | No | Lineup period for which rosters are returned. |

### Retrieve League Standings

Retrieves the current league standings. This includes basic standings data such as rank, points, W-L-T, games back, and win percentage.

Individual stats are not yet included, but Fantrax expects to include them in a future release.

URL:

```text
https://www.fantrax.com/fxea/general/getStandings?leagueId=[League ID]
```

Request parameters:

None documented.
