# CapWages API Reference

## Source and Scope

This reference was manually captured from the subscriber-only CapWages API documentation.
It documents the provider contract available to DynastyIQ but does not make every endpoint a
current application integration.

## Overview

The CapWages API provides player data including basic information, contract details, physical
attributes, and current team lineups.

The API is a standalone subscription available with or without a CapWages plan. After subscribing,
generate an API key from CapWages Settings. Keys are displayed only once when created and must be
stored securely.

## Base URL

```text
https://capwages.com/api/gateway/v1
```

## Authentication

Send the API key in the `Authorization` request header using the `ApiKey` scheme:

```http
Authorization: ApiKey YOUR_API_KEY
```

Never commit a CapWages API key to the repository.

## Available Endpoints

| Endpoint | Method | Description |
| --- | --- | --- |
| `/players` | `GET` | List players with pagination. |
| `/players/{slug}` | `GET` | Get detailed information for a specific player. |
| `/lineups/{teamSlug}` | `GET` | Get the current lineup for a team. |

## Response Envelope

All responses use JSON. Successful requests return HTTP `200 OK` with this general envelope:

```json
{
  "apiVersion": "1.0",
  "success": true,
  "data": {},
  "meta": {}
}
```

`meta` may contain pagination or provider freshness information such as `lastUpdated`.

## List Players

```http
GET /players
```

Returns a paginated list of players with basic identity, position, and current-team information.

### Query Parameters

| Parameter | Type | Default | Description |
| --- | --- | --- | --- |
| `page` | Integer | `1` | Page number. |
| `limit` | Integer | `25` | Players per page; maximum `100`. |

### Example Response

```json
{
  "apiVersion": "1.0",
  "success": true,
  "data": [
    {
      "slug": "connor-mcdavid",
      "name": "Connor McDavid",
      "note": "C",
      "pos": "C",
      "current_team_slug": "edmonton-oilers"
    }
  ],
  "meta": {
    "pagination": {
      "page": 1,
      "limit": 25,
      "total": 850,
      "totalPages": 34,
      "links": {
        "first": "/v1/players?page=1&limit=25",
        "prev": null,
        "next": "/v1/players?page=2&limit=25",
        "last": "/v1/players?page=34&limit=25"
      }
    },
    "lastUpdated": "2025-04-24T23:00:00.000Z"
  }
}
```

## Player Details

```http
GET /players/{slug}
```

Returns detailed information for a player identified by their unique CapWages slug.

### Path Parameters

| Parameter | Type | Description |
| --- | --- | --- |
| `slug` | String | Unique player identifier, such as `connor-mcdavid`. |

### Example Response

```json
{
  "apiVersion": "1.0",
  "success": true,
  "data": {
    "name": "Connor McDavid",
    "slug": "connor-mcdavid",
    "team": "Edmonton Oilers",
    "position": "C",
    "leagueStatus": "NHL",
    "acquisition": {
      "method": "Draft",
      "details": "2015 Round 1, #1 Overall",
      "year": 2015,
      "round": 1,
      "overallPick": 1,
      "draftTeam": "EDM"
    },
    "nhlId": 8478402,
    "jerseyNumber": 97,
    "personalInfo": {
      "birthDate": "1997-01-13",
      "birthPlace": "Richmond Hill, ON, CAN",
      "nationality": "CAN"
    },
    "physicalAttributes": {
      "hand": "Left",
      "height": {
        "imperial": "6'1\"",
        "metric": 185
      },
      "weight": {
        "imperial": "194 lbs",
        "metric": 88
      }
    },
    "ageLimits": {
      "entryLevelContractSigningAge": 18,
      "waiversEligibilityAge": 18
    },
    "contracts": [
      {
        "contractType": "Standard Contract (Extension)",
        "contractLength": "8 years",
        "contractValue": "100000000",
        "expiryStatus": "UFA",
        "signingTeam": "Edmonton Oilers",
        "signingDate": "2017-07-05",
        "signedBy": "Peter Chiarelli",
        "seasons": [
          {
            "season": "2024-25",
            "clause": "NMC",
            "capHit": 12500000,
            "aav": 12500000,
            "performanceBonuses": 0,
            "signingBonuses": 7000000,
            "baseSalary": 3000000,
            "totalSalary": 10000000,
            "minorsSalary": 10000000
          }
        ]
      }
    ]
  },
  "meta": {
    "lastUpdated": "2025-04-24T23:00:00.000Z"
  }
}
```

### Player Not Found

An unknown player slug returns HTTP `404`:

```json
{
  "apiVersion": "1.0",
  "success": false,
  "error": {
    "code": 404,
    "message": "Player not found"
  }
}
```

## Team Lineup

```http
GET /lineups/{teamSlug}
```

Returns the current team lineup with players ordered by line and position.

### Path Parameters

| Parameter | Type | Description |
| --- | --- | --- |
| `teamSlug` | String | Unique team identifier, such as `toronto-maple-leafs`. |

### Example Response

The documented lineup response uses API version `1.1`.

```json
{
  "apiVersion": "1.1",
  "success": true,
  "data": {
    "teamSlug": "toronto-maple-leafs",
    "players": [
      {
        "playerSlug": "auston-matthews",
        "line": "f1",
        "position": "c"
      },
      {
        "playerSlug": "mitch-marner",
        "line": "f1",
        "position": "rw"
      },
      {
        "playerSlug": "william-nylander",
        "line": "f1",
        "position": "lw"
      }
    ]
  },
  "meta": {
    "lastUpdated": "2026-03-02T..."
  }
}
```

The supplied documentation shows forward line identifiers such as `f1` and lowercase position
codes such as `c`, `rw`, and `lw`. Consumers must preserve unknown line and position values rather
than assuming the example is exhaustive.

### Lineup Not Found

When no lineup exists for the supplied team slug, the endpoint returns HTTP `404`:

```json
{
  "apiVersion": "1.1",
  "success": false,
  "error": {
    "code": 404,
    "message": "Lineup not found"
  }
}
```

## Code Examples

### cURL

```bash
# List players
curl -X GET "https://capwages.com/api/gateway/v1/players?page=1&limit=25" \
  -H "Authorization: ApiKey YOUR_API_KEY"

# Get team lineup
curl -X GET "https://capwages.com/api/gateway/v1/lineups/toronto-maple-leafs" \
  -H "Authorization: ApiKey YOUR_API_KEY"
```

### JavaScript

```js
async function getPlayers(page = 1, limit = 25) {
  const response = await fetch(
    `https://capwages.com/api/gateway/v1/players?page=${page}&limit=${limit}`,
    {
      headers: {
        Authorization: 'ApiKey YOUR_API_KEY',
      },
    },
  );

  if (!response.ok) {
    throw new Error('Failed to fetch players');
  }

  return response.json();
}

async function getPlayerDetails(slug) {
  const response = await fetch(
    `https://capwages.com/api/gateway/v1/players/${slug}`,
    {
      headers: {
        Authorization: 'ApiKey YOUR_API_KEY',
      },
    },
  );

  if (!response.ok) {
    if (response.status === 404) {
      throw new Error('Player not found');
    }

    throw new Error('Failed to fetch player details');
  }

  return response.json();
}

async function getTeamLineup(teamSlug) {
  const response = await fetch(
    `https://capwages.com/api/gateway/v1/lineups/${teamSlug}`,
    {
      headers: {
        Authorization: 'ApiKey YOUR_API_KEY',
      },
    },
  );

  if (!response.ok) {
    if (response.status === 404) {
      throw new Error('Lineup not found');
    }

    throw new Error('Failed to fetch lineup');
  }

  return response.json();
}
```

### Python

```python
import requests

BASE_URL = "https://capwages.com/api/gateway/v1"
HEADERS = {"Authorization": "ApiKey YOUR_API_KEY"}


def get_players(page=1, limit=25):
    response = requests.get(
        f"{BASE_URL}/players",
        headers=HEADERS,
        params={"page": page, "limit": limit},
    )
    response.raise_for_status()
    return response.json()


def get_player_details(slug):
    response = requests.get(
        f"{BASE_URL}/players/{slug}",
        headers=HEADERS,
    )
    response.raise_for_status()
    return response.json()


def get_team_lineup(team_slug):
    response = requests.get(
        f"{BASE_URL}/lineups/{team_slug}",
        headers=HEADERS,
    )
    response.raise_for_status()
    return response.json()
```

## DynastyIQ Integration Status

DynastyIQ currently configures and consumes the player list and player-detail endpoints. The team
lineup endpoint is documented here as an available provider contract but is not currently configured
or consumed by the application.

