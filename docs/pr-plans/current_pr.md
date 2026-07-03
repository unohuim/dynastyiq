---
pr_id: 11
pr_name: pr11
status: Active
created: 2026-07-02
last_updated: 2026-07-02
---

# Fantrax Drafting Window On Community League Show

## Source

Human request to prioritize a desktop-only drafting window on `/communities/{id}/leagues/{id}` before the existing backlog PR.

This plan intentionally takes the lowest backlog PR ID. The prior backlog `pr11` external advanced hockey stats plan has been renumbered to `pr12` as a one-off human-approved scheduling exception because the drafting work needs to occur now.

## Objective

Add a desktop-only Fantrax drafting window to the community league show route so users can see the league draft date, current live draft status, and drafted players with the team that selected each player.

## Current DynastyIQ Context

The community league show route is defined at `/communities/{c_id}/leagues/{l_id}` and renders `resources/views/communities/leagues/show.blade.php` through `CommunityLeagues::show`.

The existing page already shows:

- A header with the league name.
- A Connections accordion/card section.
- A Teams accordion/card section when the league is connected to a platform.
- Fantrax connection and Discord server management modals.

The current view model contract is owned by:

- `app/Http/Controllers/CommunityLeagues.php`
- `app/ViewModels/LeagueShowViewModel.php`
- `app/DTO/LeagueShowDto.php`

The existing Fantrax sync architecture is documented in:

- `docs/architecture/integrations/FantraxLeagueSync.yaml`
- `docs/architecture/integrations/FantraxUserConnection.yaml`
- `docs/architecture/community/LeagueShowViewModel.yaml`
- `docs/architecture/ui/UIDesignAuthority.yaml`

## Fantrax Endpoint Direction

Use the existing Fantrax endpoint configuration in `config/apiurls.php`.

Primary endpoints:

- `league_info`: `/general/getLeagueInfo?leagueId={leagueId}`
  - Use for league and team metadata.
  - Use for draft date/settings/live-state data if exposed in the payload.
  - Existing `CommunityLeagues::show` already calls this endpoint to build the Teams list.

- `draft_results`: `/general/getDraftResults?leagueId={leagueId}`
  - Use as the primary source for drafted-player rows.
  - Normalize each `draftPicks` row into Fantrax player ID, drafting team ID/name, round/pick information, and local player/team display metadata when available.
  - Use top-level `draftDate` from this payload when present; observed Fantrax shape includes `draftDate` and `draftPicks`.

Supporting endpoints:

- `draft_picks`: `/general/getDraftPicks?leagueId={leagueId}`
  - Use `currentDraftPicks` to determine status when a draft date is present.
  - If the draft date is in the future, status is `Scheduled`.
  - If the draft date is in the past or present and `currentDraftPicks` has rows, status is `Live`.
  - If the draft date is in the past or present and `currentDraftPicks` is empty, status is `Complete`.

- `team_rosters`: `/general/getTeamRosters?leagueId={leagueId}`
  - Use only as a fallback or post-draft cross-check.
  - Do not treat current rosters as the authoritative draft-history source.

## Product Direction

On desktop only, restructure the league detail body so:

- Connections appears above Teams in the left column.
- A long right-side Drafting window sits beside both sections.
- The Drafting window title uses the human-readable draft date.
- Under the title, the status shows a dot indicator: green `Live`, blue `Scheduled`, or neutral `Complete`.
- The Drafting window lists drafted players and the team that drafted them.
- Drafted player rows include the local player avatar when available, player name, position, the most recent stats league abbreviation from `stats`, GP/G/A/PTS, drafting team name, and the drafting team's connected Discord user avatar when available.
- Drafted player rows are paginated by draft round on desktop.
- When a drafted player has multiple stat rows in the latest available stats season, use the row from the league where he played the most games.
- Draft announcement player-card stat rows should show the `stats.team_name` value for that season row, not the current NHL team abbreviation.
- Between player identity and stats, render the same NHL team badge treatment used by the Stats component when a stats team abbreviation is available.
- The Drafting window is not an accordion; a gear icon opens a local settings drawer.
- The draft list scrolls independently so the date/status header and round controls remain fixed.
- Pick display uses `pickInRound` inside a styled circle with the overall pick beneath it.
- Draft rows without a `playerId` represent unmade picks; player, badge, and stat areas remain blank.
- The next unmade pick renders an orange `OTC` indicator and the loading treatment in the player-detail area.
- The initial round page defaults to the round containing the next unmade pick; otherwise it defaults to the last round.
- The active round list scrolls to the next unmade pick when present, or the last visible pick otherwise.
- The desktop Teams and Drafting sections should dynamically fill the above-the-fold viewport area, stop roughly 40px above the viewport bottom, and scroll their own list contents.
- Draft settings include a Discord channel combobox for league-specific draft pick announcements.
- The Discord channel combobox preloads text channels from the selected connected Discord guild through the DIQ bot token, when available.
- The Discord channel combobox displays a channel count or non-sensitive preload message so empty lists can be diagnosed from the drawer.
- Discord draft-channel API calls use Discord's `Bot` authorization scheme rather than user OAuth/Bearer auth.
- If a typed Discord channel does not exist, the settings endpoint attempts to create it on the connected guild.
- Newly created Discord draft announcement channels should be placed under a text-channel category when one can be inferred from the guild channel list.
- Teams use connected Discord owner avatars when available and may use provider logo URLs when Fantrax exposes them.
- The first implementation does not need a mobile view.
- Fantrax draft payloads should be persistable per platform league so recurring polls can detect when an unmade pick becomes a made selection.
- Community league draft panel rendering should prefer persisted Fantrax draft payloads and fall back to API hydration only when no persisted draft state exists.
- API-hydrated draft panel payloads should dispatch a payload-backed persistence job immediately after render preparation.
- Recurring scheduled draft polling should only dispatch API-backed sync jobs for persisted draft states currently marked `live`; scheduled, complete, unknown, and missing draft states are hydrated from the community league page instead of background discovery.
- The first observed persisted draft payload is a baseline and should not emit pick-made side effects.

The Drafting window should behave like the existing page sections where practical, but new and materially touched UI must follow `docs/UI_DESIGN.md` and `docs/UI_MOTION.md`.

## Data Contract Direction

Add a `drafting` payload to the league show DTO rather than querying Fantrax directly from Blade.

Candidate normalized shape:

```php
[
    'available' => true,
    'title' => 'September 21, 2026',
    'draft_at' => '2026-09-21T19:00:00-04:00',
    'is_live' => false,
    'status_text' => 'Scheduled',
    'status_tone' => 'blue',
    'rows' => [
        [
            'player_name' => 'Player Name',
            'fantrax_player_id' => '12345',
            'player_id' => 1,
            'nhl_id' => 8478402,
            'position' => 'C',
            'league_abbrev' => 'OHL',
            'team_abbrev' => 'TOR',
            'avatar_url' => 'https://example.test/player.png',
            'stats' => [
                'gp' => 62,
                'g' => 21,
                'a' => 33,
                'pts' => 54,
            ],
            'team_id' => 'abc',
            'team_name' => 'Team Name',
            'team_avatar_url' => 'https://example.test/discord.png',
            'round' => 1,
            'pick' => 1,
            'pick_in_round' => 1,
            'overall_pick' => 1,
        ],
    ],
    'rounds' => [
        [
            'round' => 1,
            'label' => 'Round 1',
            'count' => 1,
            'rows' => [
                // Same normalized row shape as rows.
            ],
        ],
    ],
    'empty_text' => 'No drafted players yet.',
    'error_text' => null,
]
```

If Fantrax does not expose a draft date in `draft_results` or `league_info`, keep the window present with a fallback title such as `Draft` and no live badge.

## Implementation Outline

1. Inspect real or fixture Fantrax `league_info`, `draft_results`, and, if needed, `draft_picks` payload shape.
2. Add or extend a small Fantrax draft payload normalizer under the existing Fantrax league sync/view-model boundary.
3. Update `CommunityLeagues::show` to fetch draft payloads only for connected Fantrax leagues.
4. Add `drafting` to `LeagueShowViewModel` and `LeagueShowDto`.
5. Update `resources/views/communities/leagues/show.blade.php` desktop layout so Connections and Teams stack in the left column and Drafting occupies the right column.
6. Render drafted-player rows with clear empty and error states.
7. Keep the first PR desktop-only; do not add mobile drafting UI yet.

## Out Of Scope

- Mobile drafting window.
- Live polling, WebSockets, or auto-refresh.
- Persisting draft results to first-party tables unless needed for deterministic tests or rate-limit avoidance and explicitly approved.
- Changing Fantrax roster sync behavior.
- Replacing current Teams data source.
- Refactoring the broader community league page.
- Removing legacy inline scripts from the page unless required by this change.
- Running CI, tests, imports, migrations, seeders, queues, schedulers, bots, or operational commands.

## Testing Expectations

The implementation PR should be test-driven and follow `docs/testing/testing-standards.yaml`.

Expected coverage areas:

- Connected Fantrax league receives a normalized drafting payload.
- Unconnected or non-Fantrax league receives a stable unavailable/empty drafting payload.
- Fantrax draft result rows normalize player and drafting team labels.
- Missing draft date falls back without breaking render.
- Draft status is deterministic in tests by injecting or controlling the clock and `currentDraftPicks`.
- Draft endpoint failure produces an inline error/empty state without failing the whole league page.
- Draft results are segmented by round.
- Draft status derives from draft date and `draft_picks.currentDraftPicks`.
- Draft player stats use the latest season and highest-GP row within that season.
- Unmade draft picks with no `playerId` render without placeholder player or stat data.
- Next unmade pick loading state is deterministic.
- Initial draft round selection is deterministic.
- Draft notification channel preload from the connected Discord guild is deterministic.
- Draft notification channel preload failures expose a deterministic non-sensitive status/message.
- Draft notification channel settings persist on the organization_leagues pivot metadata.
- Fantrax draft state and pick rows persist latest provider payload data per platform league.
- Community league draft panel can render from persisted draft payloads without calling Fantrax draft endpoints.
- Missing persisted draft state falls back to Fantrax API and queues persistence using the same payload.
- Fantrax draft delta detection emits newly made picks only when a persisted unmade pick receives a Fantrax player id.
- Newly detected Fantrax draft picks broadcast a user-channel toast event for organization members.
- Newly detected Fantrax draft picks post a Discord message to the configured draft notification channel when one is selected.
- Discord draft pick announcement text follows `{discord_username} ({fantrax team name}) selects {first} {last} with pick {pick_number}. {discord_user} is now OTC.`, mentioning connected Discord users and falling back to text labels when not connected.
- Discord draft pick announcements send a generated broadcast-style player-card image as a direct file attachment when runtime image support is available.
- Discord draft pick announcement cards should show the drafting team's connected Discord avatar beside a truncated `{Fantrax team name} Selects` header.
- Discord draft pick announcement cards should follow the broadcast-card layout reference in `docs/designs/player_draft_card.png`.
- Discord draft pick announcements send the announcement text after the generated image message so Discord renders the text below the card.
- Discord draft pick announcements attach a temporary generated player-card image when runtime image support is available, and remove that temporary image after the Discord send attempt.
- Newly detected Fantrax draft pick announcements are idempotent per persisted draft pick row so duplicate queued listeners do not duplicate toast or Discord side effects.
- Authorization and organization scope for `/communities/{id}/leagues/{id}` remain unchanged.

## Resolved Payload Notes

- `docs/draft_results.txt` shows `draft_results` returning top-level `draftDate` and `draftPicks`.
- `draftPicks` rows include `round`, `pick`, `pickInRound`, `teamId`, `playerId`, and `time`.
- `docs/draft_pick_info.txt` is useful for future pick ownership, but this PR displays completed draft results only.
- `currentDraftPicks` from `docs/draft_pick_info.txt` is used for status only.
- When Fantrax omits `playerId` on a draft pick, DynastyIQ treats that row as an unmade pick.
- Fantrax team logo URLs are best-effort only; current observed local schema has no guaranteed fantasy team logo column.
- Fantrax player IDs are enriched from `fantrax_players` first and `player_external_identities` second.

## Human Approval Gates

- Explicit approval is required before adding persistence for draft results.
- Explicit approval is required before adding live polling or auto-refresh.
- Explicit approval is required before broadening this from desktop-only to mobile.
