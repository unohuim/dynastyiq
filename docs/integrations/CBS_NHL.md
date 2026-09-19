# CBS Sports NHL Integration Reference

## Source and Scope

This document records publicly observable CBS Sports NHL injury-page and RSS behavior reviewed on
September 18, 2026. CBS Sports has not been integrated into DynastyIQ. No documented free CBS injury
API was identified; the injury source is a public, server-rendered HTML page.

Before production ingestion, persistence, display, or republication, confirm that the intended use
complies with CBS Sports' current terms, licensing requirements, attribution requirements, robots
directives, and automated-access policies.

## Candidate Sources

| Source | Method | URL | Intended Use |
| --- | --- | --- | --- |
| NHL injuries page | `GET` | `https://www.cbssports.com/nhl/injuries/` | Current structured injury snapshot. |
| NHL headline RSS | `GET` | `https://www.cbssports.com/rss/headlines/nhl/` | General NHL articles; not a structured injury feed. |

## NHL Injuries Page

### Request

```http
GET https://www.cbssports.com/nhl/injuries/
Accept: text/html
```

The reviewed response returned HTTP `200`, content type `text/html`, and server-rendered team injury
tables. JavaScript execution was not required to observe the documented rows.

### Observed Table Shape

Each team section contains rows with these columns:

| Column | Example | Meaning |
| --- | --- | --- |
| Player | `Troy Terry` | Player display name. |
| Position | `RW` | NHL-style position abbreviation. |
| Updated | `Thu, Jun 18` | Provider update date without an explicit year. |
| Injury | `Hip` | Body part or broad injury description. |
| Injury Status | `Expected to be out until at least Nov 15` | Free-text availability or return guidance. |

Observed injury values included `Hip`, `Lower Body`, and `Undisclosed`. Observed status values
included:

- `Questionable for start of season`
- `Expected to be out until at least Oct 13`
- `Expected to be out until at least Oct 24`
- `Expected to be out until at least Nov 15`

These examples are not an exhaustive enum. New values must be preserved as raw text before any
normalization.

### Candidate Snapshot Contract

- Parse team context, player name, position, updated text, injury text, and injury-status text.
- Preserve the raw row or sufficient source evidence for parser review.
- Store provider fetch time separately from the provider's `Updated` value.
- Resolve the missing year in `Updated` only through an explicit date-resolution rule using fetch date and season context.
- Resolve players through team, normalized name, position, and approved provider-identity evidence.
- Persist unresolved rows rather than discarding them or linking them to a weak candidate.
- Treat the page as a current snapshot, not immutable event history.
- Compare successive snapshots to produce first-party status-change events only through an approved reconciliation rule.
- Never interpret disappearance from the page as healthy without considering fetch completeness, team movement, and parser success.

### Parsing Risks

- CBS does not publish a documented injury API contract for this page.
- HTML classes, nesting, labels, and team sections may change without notice.
- Player names are display text and do not provide an observed stable CBS player ID in the reviewed table shape.
- The `Updated` column omits the year.
- Injury and status values are free text.
- A successful HTTP response does not prove that all team tables parsed successfully.
- Parser monitoring must track total teams, total rows, missing headings, unresolved players, and abrupt count changes.

## NHL Headline RSS

### Request

```http
GET https://www.cbssports.com/rss/headlines/nhl/
Accept: application/rss+xml, application/xml
```

The reviewed response returned HTTP `200`, content type `text/xml`, RSS version `2.0`, and 36 general
NHL headline items.

### Observed Item Shape

```xml
<item>
  <title>General NHL article headline</title>
  <link>https://www.cbssports.com/nhl/news/example/</link>
  <description>Article summary.</description>
  <pubDate>Wed, 02 Sep 2026 16:43:19 +0000</pubDate>
  <dc:creator>Author Name</dc:creator>
  <guid isPermaLink="false">provider-guid</guid>
  <enclosure url="https://example.com/image.jpg" type="image/jpeg" />
</item>
```

### Suitability

The CBS NHL RSS feed is a general editorial headline feed. It does not expose the structured player,
team, body-part, updated-date, or injury-status fields present on the injuries page. It must not be
treated as a complete injury feed.

It may be retained as optional corroborating editorial evidence only if separately approved. The
injuries page is the stronger CBS source for a current structured injury snapshot.

## Candidate Use With Event Sources

If CBS ingestion is approved, its strongest role is snapshot reconciliation:

1. Ingest incremental player-status evidence from an approved event source.
2. Fetch and parse the CBS injury snapshot.
3. Preserve raw evidence from both providers.
4. Reconcile current status without deleting historical events.
5. Expose source, source timestamp, fetch timestamp, and confidence in user-facing status data.

This is a candidate design only. It is not current DynastyIQ architecture until approved and
documented in the applicable canonical architecture files.

## Error and Change Handling

Consumers must handle:

- Non-`200` responses.
- Bot challenges or consent pages returned with HTTP `200`.
- Missing or renamed table headings.
- Partial team sections.
- Empty pages during deployment or provider failure.
- New injury/status wording.
- Unresolved or duplicate player names.
- Terms, robots, or access-policy changes.

Observed behavior that conflicts with this document must be reviewed before parser or domain behavior
is changed.
