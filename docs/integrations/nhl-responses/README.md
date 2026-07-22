# NHL Response Semantics

This directory records observed NHL response fields and response shapes that affect DynastyIQ behavior.

The external endpoint inventory is tracked by the unofficial NHL API reference at:

```text
https://github.com/Zmalski/NHL-API-Reference
```

That reference is useful for endpoint discovery. These files document DynastyIQ's interpretation of provider payloads: field meaning, parser contracts, normalization rules, source authority, product observations, and open verification questions.

When DynastyIQ consumes an NHL endpoint and a new meaningful response data point is discovered, update the matching endpoint file with:

- Sample response source, sanitized when copied from a real game or player.
- Field path and observed type or shape.
- Observed values.
- Semantic meaning.
- Parser contract and normalization rules sufficient to implement the endpoint without re-reading raw samples.
- Expected normalized output when the endpoint feeds first-party services.
- DynastyIQ usage.
- Observations for product, admin import, validation, troubleshooting, or user-facing features.
- What the field must not be used for.
- Schema, import, validation, or UI implications.
- Open verification questions.

Raw response samples may live in `docs/api_responses/` or validation troubleshooting output, but these files explain what those responses mean for product behavior.

## Endpoint Generation

Use `php artisan nhl:api` to index endpoints from the NHL API reference, fetch runnable sample responses from the documented cURL examples, and create response breakdown scaffolds.

Useful options:

- `--list`: index discovered endpoints without fetching samples.
- `--endpoint=<slug-or-path-fragment>`: run one endpoint.

Generated sample, breakdown, and index files are overwritten on each run.
Generated breakdown files are unreviewed scaffolds until a human fills in DynastyIQ-specific meaning, usage, parser contracts, and opportunities.

## Endpoint Files

- `endpoint-index.md`: Generated endpoint inventory with sample and breakdown file associations.
- `source-map.md`: NHL-specific data needs mapped to authoritative endpoint sources.
- `player-landing.md`: Player identity, profile, draft, current-team, and season-total metadata.
- `player-landing-edge.md`: NHL Edge availability and leader data associated with player landing pages.
- `edge-skater-detail.md`: Player-specific NHL Edge percentile, league-average, speed, distance, shot-location, and zone-time detail data.
- `edge-skater-comparison.md`: Player-specific NHL Edge skating, shot-speed, shot-location, and zone-time comparison data.
- `game-play-by-play.md`: Game identity, clock/state, teams, score, and event stream.
- `game-boxscore.md`: Official player game stat totals used as validation and reconciliation targets.
- `game-landing.md`: Game content/detail endpoint inventory and candidate context source.
- `shift-charts.md`: NHL Stats shiftchart rows used for raw shift imports and TOI/shift derivation.
- `schedule.md`: Daily score/schedule discovery feeds.
- `standings.md`: Current standings feed and team-reference context.

## Planned Endpoint Files

- `roster.md`
- `prospects.md`
- `draft-picks.md`
- `stats-rest-players.md`
- `stats-rest-teams.md`
