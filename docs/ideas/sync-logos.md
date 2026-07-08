# Sync Logos

## Status

Hibernated from the league options UI.

## Problem

Commissioners want team logos from connected fantasy platforms to appear in DynastyIQ league lists and team displays.

Yahoo logos can be synced through the Yahoo API. Fantrax logos are harder because the useful logo URLs are visible in authenticated Fantrax browser payloads but have not been available reliably through the server-side Fantrax API payloads we already consume.

## What We Learned

- A single shared server-side Chromium profile does not fit multi-commissioner access.
- Production headless Chromium can run after installing Playwright browsers and Linux dependencies.
- A server Chromium profile must be logged into a Fantrax account that can access the target league.
- Commissioners may be members of different Fantrax leagues, so one shared server profile will fail for leagues outside that account.
- Headless production cannot show an interactive login screen to the commissioner.

## Candidate Future Paths

- Use direct provider APIs only when logo URLs are exposed.
- Build a browser extension that runs in the commissioner’s normal browser session and submits team/logo mappings to DynastyIQ.
- Explore a per-user browser-auth model only if there is a secure way for commissioners to authenticate an isolated browser session they can actually control.
- Revisit a bookmarklet or copied-script collector if a lower-friction extension is not viable.

## Current Decision

Remove the visible `Sync Team Logos` controls from league options for now. Keep the backend routes, services, events, and database fields in place so the feature can be revived after a better Fantrax collection approach is selected.
