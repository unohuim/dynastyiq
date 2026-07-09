# Stats Controller Legacy Helper Cleanup

## Idea

Audit `StatsController` after the stats payload abstraction migration and identify legacy helper methods or inline behavior that can be removed, moved, or delegated to the newer stats services.

## Context

The stats abstraction work moved league payload construction, request context parsing, filter parsing, query filtering, schema generation, row assembly, ownership hydration, platform player-universe filtering, and synthetic league perspective construction behind named services.

Some older controller code remains because it may still support the main `/api/stats` payload path or other legacy stats views.

## Candidate Audit Targets

- Inline debug logging in the older `payload()` endpoint.
- Legacy formatter helpers such as `getStatValue()`, `deriveFromTotals()`, and `rateMaps()`.
- Thin controller wrapper methods that now mostly delegate to `StatsPayloadBuilder`.
- Any duplicated stats request parsing that can safely move to `StatsQueryContext` or `StatsFilterSet`.

## Safety Requirements

- Do not remove a helper until all call sites are proven.
- Preserve the existing public stats payload contract.
- Keep `/stats`, `/api/stats`, and league stats payload behavior stable.
- Add or update tests before deleting behavior.
- Treat this as a separate PR plan if the audit finds meaningful cleanup work.
