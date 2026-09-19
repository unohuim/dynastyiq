# RotoWire NHL Integration Reference

## Source and Scope

This document records publicly observable RotoWire NHL feed and page-support behavior reviewed on
September 18, 2026. RotoWire has not been integrated into DynastyIQ. The starting-goalie JSON route
is an undocumented page-support endpoint rather than a published API contract.

Before production ingestion, persistence, display, or republication, confirm that the intended use
complies with RotoWire's current terms, licensing requirements, attribution requirements, and
automated-access policies.

## Candidate Sources

| Source | Method | URL | Intended Use |
| --- | --- | --- | --- |
| NHL player news RSS | `GET` | `https://www.rotowire.com/rss/news.php?sport=NHL` | Incremental player-status evidence. |
| Projected goalies JSON | `GET` | `https://www.rotowire.com/hockey/tables/projected-goalies.php?date=YYYY-MM-DD` | Date-specific expected or confirmed starting goalies. |
| NHL starting goalies page | `GET` | `https://www.rotowire.com/hockey/starting-goalies.php` | Human-facing source context for projected-goalie data. |
| NHL injury report | `GET` | `https://www.rotowire.com/hockey/injury-report.php` | Human-facing current injury report and possible snapshot-reconciliation source. |
| NHL injury news | `GET` | `https://www.rotowire.com/hockey/news.php?view=injuries` | Human-facing injury-filtered news. |

## NHL Player News RSS

### Request

```http
GET https://www.rotowire.com/rss/news.php?sport=NHL
Accept: application/rss+xml, application/xml
```

The observed response returned HTTP `200`, content type `application/xml`, RSS version `2.0`, and a
channel TTL of 10 minutes.

### Observed Item Shape

```xml
<item>
  <guid>nhl593277</guid>
  <title>Seth Jarvis: Ahead of schedule in recovery</title>
  <link>https://www.rotowire.com//hockey/player/seth-jarvis-6202</link>
  <description>Jarvis (shoulder) was on the ice for practice Thursday...</description>
  <pubDate>Thu, 17 Sep 2026 4:06:00 PM PDT</pubDate>
</item>
```

### Observed Semantics

| Field | Meaning | Integration Guidance |
| --- | --- | --- |
| `guid` | Provider news-event identifier, such as `nhl593277`. | Candidate idempotency key within the RotoWire provider scope. |
| `title` | Player display name followed by a short update headline. | Parsing aid, not canonical player identity by itself. |
| `link` | RotoWire player URL containing a player slug and RotoWire player ID. | Preserve as provider evidence and identity-resolution input. |
| `description` | Short status report that may include body part, practice status, availability, or return language. | Preserve raw text; normalize only through explicit classification rules. |
| `pubDate` | Provider publication timestamp with timezone. | Normalize to UTC while preserving the source value. |

Observed descriptions included injury body parts and phrases indicating return to practice, healthy
status, readiness for training camp, and continuing recovery.

### Feed Limitations

- The reviewed response contained only five recent items; it is not a complete current-injury snapshot.
- Missing polling intervals may result in permanently missed events.
- The feed mixes injuries, transactions, lineup changes, availability updates, and other player news.
- Free text must not be treated as a reliable structured return date without explicit parsing and confidence rules.
- A player mentioned as healthy should create a new status event rather than delete prior injury history.

### Candidate Ingestion Contract

- Poll no more frequently than permitted by provider terms and caching guidance.
- Deduplicate by provider plus `guid`.
- Preserve the raw item, link, publication time, fetch time, and parser version.
- Resolve players through a provider identity when available; otherwise use explicit multi-signal identity resolution.
- Store normalized event classification separately from raw provider text.
- Treat inferred body part, availability, expected return, and event type as nullable, confidence-bearing values.
- Reconcile current status against a separately fetched injury snapshot when an approved snapshot source exists.

## Projected Starting Goalies

### Request

```http
GET https://www.rotowire.com/hockey/tables/projected-goalies.php?date=2026-09-19
Accept: application/json
```

The `date` parameter uses `YYYY-MM-DD`. The reviewed response returned HTTP `200`, content type
`application/json`, and one object per scheduled matchup.

### Observed Matchup Shape

```json
{
  "gamedate": "7:00 PM",
  "hometeam": "STL",
  "homelogo": "https://assets.rotowire.com/images/teamlogo/hockey/100STL.png?v=5",
  "homePlayer": "Joel Hofer",
  "homePlayerFN": "Joel",
  "homePlayerLN": "Hofer",
  "homePlayerID": "5945",
  "homePlayerURL": "/hockey/player/joel-hofer-5945",
  "homeStatus": "Expected",
  "visitteam": "DAL",
  "visitlogo": "https://assets.rotowire.com/images/teamlogo/hockey/100DAL.png?v=5",
  "visitPlayer": "Jake Oettinger",
  "visitPlayerFN": "Jake",
  "visitPlayerLN": "Oettinger",
  "visitPlayerID": "5514",
  "visitPlayerURL": "/hockey/player/jake-oettinger-5514",
  "visitStatus": "Expected"
}
```

### Observed Status Values

| Value | Provider Page Meaning | Candidate DynastyIQ Meaning |
| --- | --- | --- |
| `Confirmed` | The team or a reliable source has confirmed the goalie will start. | Confirmed third-party starter evidence. |
| `Expected` | RotoWire projects the goalie to start without confirmation. | Projected third-party starter evidence. |

Unknown values must be preserved and must not be coerced to `Expected` or `Confirmed`.

### Identity and Team Mapping

- RotoWire player IDs and URL slugs are provider-specific identifiers, not NHL IDs.
- Store RotoWire identities separately from canonical DynastyIQ player IDs.
- Prefer an existing provider identity; otherwise resolve with slug, normalized name, team, position, and other approved evidence.
- Observed RotoWire team abbreviations differ from NHL abbreviations in some cases:
  - `MON` corresponds to NHL `MTL`.
  - `LOS` corresponds to NHL `LAK`.
  - `LAS` corresponds to NHL `VGK`.
- Team mappings must be explicit and auditable; do not silently infer unknown abbreviations.

### Candidate Source Precedence

If this source is approved for prediction use, the proposed precedence is:

1. NHL boxscore goalie with `starter = true`, when available.
2. RotoWire `Confirmed` starter.
3. RotoWire `Expected` starter.
4. DynastyIQ goalie performance or workload projection fallback.

This precedence is a candidate design only. It is not current DynastyIQ architecture until approved
and documented in the applicable canonical architecture files.

### Reliability Requirements

- Match provider rows to a specific NHL game using date, mapped teams, and schedule evidence.
- Preserve fetch time and source status with every observation.
- Do not carry an expected or confirmed starter into a different game merely because the team matches.
- Treat an empty array, missing matchup, malformed response, request failure, or unresolved player as unavailable provider evidence.
- Do not let provider failure block the existing DynastyIQ projection fallback.
- Recheck close to game time only at a frequency allowed by provider terms.

## Error and Change Handling

No formal RotoWire error schema was documented for these sources. Consumers must handle:

- Non-`200` responses.
- HTML returned from a JSON or RSS request.
- Empty or malformed payloads.
- New RSS fields or goalie status values.
- Team-code changes.
- Page-support endpoints moving or disappearing without notice.
- Blocking, throttling, or access-policy changes.

Observed behavior that conflicts with this document must be reviewed before parser or domain behavior
is changed.
