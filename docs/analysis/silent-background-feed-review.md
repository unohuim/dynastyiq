# Silent background feed updates

Approved scope: remove automatic feed-health notices and document that background
updates/reconnections stay silent without replacing content or moving the layout.

## Changed files

- `resources/js/layouts/MobileScoreboard.vue`
- `resources/js/pages/Games/Index.vue`
- `resources/js/pages/Games/Show.vue`
- `resources/js/layouts/scoreboard.test.js`
- `resources/js/pages/games.test.js`
- `resources/js/pages/Games/LiveRefresh.test.js`
- `docs/UI_DESIGN.md`
- `docs/UI_MOTION.md`
- `docs/architecture/imports/NhlLiveGameFeed.yaml`
- `docs/architecture/ui/NhlPublicGamePage.yaml`
- `docs/ARCHITECTURE_INVENTORY.md` (public game entry only)
- This review document.

## Implementation

The canonical rule lives in UI_DESIGN.md, Silent background updates. UI_MOTION.md
and the feed/game architecture refer to it. Automatic polling, refreshing,
reconnecting, retrying and revalidation cannot introduce health notifications,
loading transitions, overlays, announcements or layout movement. Existing content,
focus, scroll and interaction state remain; new facts update existing regions.
Internal freshness and health evidence remains accurate and recovery continues.
Initial empty loads and explicit user actions retain scoped loading/error feedback.

The scoreboard no longer renders the composable's health warning in its status
dropdown. Its schedule-range error feedback and Retry remain for requested data.
The games list no longer inserts its warning row, and the game page no longer
renders its floating warning overlay. All three retain their existing subscriptions
and recovery logic. The shared composable's warning/freshness refs remain internal
diagnostic state; no product consumer renders them. No timers, providers, queues,
imports, routes, permissions or prediction calculations change. No migration.

## Authored minimum coverage matrix

Written coverage is distinguished from executed verification; suites were not run.

| Behavior | Status | Named coverage |
|---|---|---|
| Scoreboard health changes create no notice/DOM change | COVERED | keeps feed delays disconnections and recovery silent without changing the header |
| Games list retains content during health changes | COVERED | keeps the games list unchanged when background feed health changes |
| Game detail retains content through loss, stale timeout, fetch failure and recovery | COVERED | recovers the live game silently while keeping its content and layout mounted |
| Internal health stays observable without rendering it | COVERED | tracks connection interruptions internally while preserving visible content; tracks snapshot errors internally and keeps the mounted consumer alive |
| Recovery and source freshness unchanged | COVERED | resynchronizes after Reverb reconnects; marks an expired live stream as delayed and attempts recovery; does not apply explicitly stale server snapshots |
| Requested range failures retain Retry | COVERED | retries the failed week without replacing or refetching the existing slate; offers retry after a failed schedule request |
| Cleanup and no provider calls | COVERED | stops reconnect polling after the component unmounts; reads only our shared endpoint and never an NHL endpoint |

## Requirements checklist

1. SATISFIED in code: automatic feed health notices removed from all three consumers.
2. SATISFIED in code: background recovery remains active and existing content remains.
3. SATISFIED in docs: canonical global silent-update rule and architecture references.
4. SATISFIED in code: initial/requested data errors remain scoped and actionable.
5. SATISFIED in written tests: unchanged DOM/content and silent recovery assertions.

## Endpoint × verb authorization/scope

Not applicable: no endpoint, authentication, authorization or data-scope behavior
changes. Existing public shared-snapshot reads remain; no new requests or channels.

## Frontend component checklist

- Scoreboard tests compare the existing header markup and tile identity through
  delayed, disconnected, unavailable and recovered health states.
- List tests compare full existing markup and card identity after a warning changes.
- Detail tests retain header/content identity through disconnected/stale/error states
  and verify recovery requests continue without notice text appearing.
- Composable tests preserve private health/freshness assertions and silent content.
- Existing user-requested range loading/Retry and subscription disposal tests remain.
- Fake timers/fixed dates, mocked fetch/router/feed, no external network calls,
  component-local state and no new global application state.

## Per-file test declaration counts

- `resources/js/layouts/scoreboard.test.js`: 59 `it(...)` declarations.
- `resources/js/pages/games.test.js`: 125 `it(...)` declarations.
- `resources/js/pages/Games/LiveRefresh.test.js`: 24 `it(...)` declarations.

No automated tests, CI, browser scripts or operational commands were run.
`git diff --check` passed. Browser layout and executed-suite verification remain
for human review; no claim of test execution or measured pixel stability is made.
