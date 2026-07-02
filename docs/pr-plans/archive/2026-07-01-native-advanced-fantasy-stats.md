---
pr_id: 9
pr_name: pr9
status: Archived
created: 2026-07-01
last_updated: 2026-07-01
---

# Native Advanced Fantasy Stats

## Source

Extracted from `pr11` external advanced hockey stats planning so DynastyIQ can first expose all valuable native fantasy stats that can be derived from existing NHL play-by-play, boxscore, shift, unit, game-summary, season-summary, and strength-summary data.

## Objective

Add native skater, goalie, and on-ice fantasy metrics that DynastyIQ can provide without outside models or licensed providers, then expose those metrics through cleaner seeded perspectives and perspective-driven position controls.

## Decision Direction

This PR should happen before external provider work.

The native stat layer should be improved first because:

- It does not require MoneyPuck, Evolving-Hockey, or other third-party model licensing.
- It can expose immediate fantasy value from data already imported by DynastyIQ.
- It creates better internal stat and perspective contracts before external model outputs are layered in later.

## Out Of Scope

- MoneyPuck integration.
- Evolving-Hockey integration.
- xG, ixG, xGF, xGA, GSAx, GAR, xGAR, RAPM, WAR, SPAR, projections, or any outside-model output.
- Building a DynastyIQ-owned expected-goals model.
- Removing legacy stat fields.
- Replacing the existing NHL game import pipeline.
- Running CI, tests, imports, migrations, seeders, queues, schedulers, or operational commands.

## Existing Data Available

The current NHL import and aggregation pipeline already imports or derives the raw inputs needed for this PR:

- Play-by-play stores strength, situation code, period, event timing, score state, event owner team, shot type, shooter, scorer, assists, goalie in net, blocker, hitter, hittee, faceoff winner/loser, committed/drawn penalty players, coordinates, shot distance, shot angle, and raw provider metadata.
- Boxscore import stores official skater and goalie totals including shots, hits, blocks, faceoffs, PP/SH points, giveaways, takeaways, goalie shots against, saves, goals against, strength saves/shots against, TOI, and shifts.
- Shift imports store player TOI and shifts and feed unit/shift-derived on-ice summaries.
- Strength summaries already store player-level individual goals, assists, points, on-ice GF/GA/SF/SA/SATF/SATA/FF/FA, zone starts, penalties, hits, blocks, TOI, shifts, and IPP by strength.

Expected PBP import changes:

- No PBP import changes are expected for the native metrics in this PR.
- The PBP importer already persists the raw event fields required for IPP, on-ice percentages, goalie save rates, fantasy composites, and most native split/rate stats.
- Implementation should audit summary and rollup logic before exposing newly visible fields, especially shorthanded points, primary/secondary assists, and goalie decision rules.

## No Legacy Field Removal

This PR must be additive.

Rules:

- Do not remove existing skater or goalie columns.
- Do not break saved/custom perspectives that reference current stat keys.
- Keep existing public payload keys working.
- Add clearer aliases or new derived keys where useful.
- Document confusing legacy naming instead of removing it.

Known confusing legacy naming to account for:

- `sog_p` means shooting percentage, not shots-on-goal percentage.
- `sat` is individual shot attempts, while `cf`/`ca` should represent on-ice Corsi-style for/against.
- `stats` is legacy/prospect/provider-season data, while `nhl_season_stats` is NHL game-derived season data.

## Database Additions

Add fields only where game-level auditability, season sorting/filtering, or expensive repeat derivation justifies persistence.

Potential `nhl_game_summaries` additions for goalie game auditability:

- `goalie_started` boolean default false.
- `goalie_decision` nullable enum-like string, with values documented in `docs/ENUMS.md` before use.
- `quality_start` boolean default false.
- `really_bad_start` boolean default false.
- `sv_pct` decimal or integer-basis representation.
- `gaa` decimal.

Potential `nhl_season_stats` additions for goalie season sorting/filtering:

- `wins`
- `losses`
- `ot_losses`
- `starts`
- `relief_appearances`
- `quality_starts`
- `really_bad_starts`
- `quality_start_percentage`
- `sv_pct`
- `gaa`
- `ev_sv_pct`
- `pp_sv_pct`
- `pk_sv_pct`

Avoid persisted columns for cheap skater or on-ice percentages/composites unless filtering/sorting performance requires them.

## Native Skater Metrics

Expose or derive the following from existing game summaries, season summaries, and strength summaries:

- `ipp`
- `individual_g`
- `individual_a`
- `individual_pts`
- `a1`
- `a2`
- `a1_per_60`
- `a2_per_60`
- `evpts`
- `ppp`
- `pkp`
- `sog_per_gp`
- `sog_per_60`
- `sat_per_gp`
- `sat_per_60`
- `hits_per_gp`
- `hits_per_60`
- `blocks_per_gp`
- `blocks_per_60`
- `shots_plus_blocks`
- `hits_plus_blocks`
- `fow`
- `fol`
- `fot`
- `fow_percentage`
- `fow_per_gp`
- `gv`
- `tk`
- `tkvgv`
- `penalties_taken`
- `penalties_drawn`
- `penalty_differential`

Penalty drawn/taken fields should be included only if summary rules can derive them reliably from `committed_by_player_id` and `drawn_by_player_id`.

## Native On-Ice Metrics

Expose or derive the following from `nhl_player_game_strength_summaries`:

- `gf`
- `ga`
- `gf_pct`
- `sf`
- `sa`
- `sf_pct`
- `cf`
- `ca`
- `cf_pct`
- `ff`
- `fa`
- `ff_pct`
- `gf_per_60`
- `ga_per_60`
- `sf_per_60`
- `sa_per_60`
- `cf_per_60`
- `ca_per_60`
- `ff_per_60`
- `fa_per_60`
- `pdo`
- `on_ice_shooting_percentage`
- `on_ice_save_percentage`
- `ozs`
- `dzs`
- `ozs_pct`
- `dzs_pct`

Mapping rules:

- `cf = satf`
- `ca = sata`
- `cf_pct = cf / (cf + ca)`
- `ff_pct = ff / (ff + fa)`
- `sf_pct = sf / (sf + sa)`
- `gf_pct = gf / (gf + ga)`
- `on_ice_shooting_percentage = gf / sf`
- `on_ice_save_percentage = 1 - (ga / sa)`
- `pdo = on_ice_shooting_percentage + on_ice_save_percentage`
- `ozs_pct = ozs / (ozs + dzs)`
- `dzs_pct = dzs / (ozs + dzs)`

## Native Goalie Metrics

Expose or derive the following from game summaries, boxscore data, game state, and season rollups:

- `sv_pct`
- `gaa`
- `wins`
- `losses`
- `ot_losses`
- `starts`
- `relief_appearances`
- `quality_starts`
- `really_bad_starts`
- `quality_start_percentage`
- `saves_per_gp`
- `shots_against_per_gp`
- `ga_per_gp`
- `ev_sv_pct`
- `pp_sv_pct`
- `pk_sv_pct`
- `so`
- `shosv`

Goalie derivation rules:

- `sv_pct = sv / sa`
- `gaa = ga * 3600 / toi`
- `quality_start = goalie_started && (sv_pct >= 0.917 || (sa <= 20 && sv_pct >= 0.885))`
- `really_bad_start = goalie_started && sv_pct < 0.850`
- `quality_start_percentage = quality_starts / starts`
- `ev_sv_pct = evsv / evsa`
- `pp_sv_pct = ppsv / ppsa`
- `pk_sv_pct = pksv / pksa`

Goalie decision logic should be specified and tested before implementation. It should use final game result, goalie TOI, goals allowed while goalie was in net, and NHL overtime/shootout result context where available.

## Perspective Changes

Do not overload existing simple perspectives.

Keep existing seeded perspectives:

- `Skaters`
- `Goalies`
- `nhl.com`
- `Standard Yahoo`
- `Prospects`

Add new seeded/default perspectives:

- `Skaters - Fantasy`
- `Skaters - Advanced`
- `Goalies - Fantasy`
- `Goalies - Splits`, optional if scope remains manageable.

Suggested `Skaters - Fantasy` columns:

- `g`
- `a`
- `pts`
- `ppp`
- `sog`
- `h`
- `b`
- `plus_minus`
- `pim`
- `shots_plus_blocks`
- `hits_plus_blocks`
- `sog_per_gp`
- `hits_per_gp`
- `blocks_per_gp`

Suggested `Skaters - Advanced` columns:

- `ipp`
- `individual_g`
- `individual_a`
- `individual_pts`
- `gf`
- `ga`
- `gf_pct`
- `cf`
- `ca`
- `cf_pct`
- `ff`
- `fa`
- `ff_pct`
- `sf`
- `sa`
- `sf_pct`
- `pdo`
- `on_ice_shooting_percentage`
- `on_ice_save_percentage`
- `ozs_pct`
- `dzs_pct`

Suggested `Goalies - Fantasy` columns:

- `wins`
- `losses`
- `ot_losses`
- `starts`
- `gp`
- `sv`
- `sa`
- `ga`
- `sv_pct`
- `gaa`
- `so`
- `quality_starts`
- `really_bad_starts`
- `quality_start_percentage`
- `saves_per_gp`
- `shots_against_per_gp`

Suggested `Goalies - Splits` columns:

- `evsv`
- `evsa`
- `ev_sv_pct`
- `ppsv`
- `ppsa`
- `pp_sv_pct`
- `pksv`
- `pksa`
- `pk_sv_pct`
- `shosv`
- `toi`
- `ga_per_gp`

## Perspective-Driven Position Controls

Stats remain player-backed and filterable by position, but default perspectives should control which position buttons are visible.

Rules:

- Server-side filters remain authoritative.
- UI position buttons are presentation controls only.
- Perspective settings should declare visible position controls instead of hard-coding by perspective name.

Skater perspectives:

- Must be locked to non-goalies.
- Must hide `G`.
- Must expose `F`, `C`, `LW`, `RW`, and `D`.
- `F` filters all forward positions.

Goalie perspectives:

- Must be locked to goalies.
- Must show no position buttons because there is no useful goalie sub-position toggle.

Unrestricted player perspectives:

- May show all supported position controls, including `G`, unless their settings restrict them.

Example perspective setting shape:

```json
{
  "ui": {
    "positionButtons": ["F", "C", "LW", "RW", "D"]
  }
}
```

Goalie perspective setting:

```json
{
  "ui": {
    "positionButtons": []
  }
}
```

## Stats Payload Integration

The main stats payload should support native advanced and on-ice stat keys so users do not need to leave the main stats experience for player on-ice metrics.

Requirements:

- Add readable aliases such as `cf`, `ca`, `cf_pct`, `sv_pct`, `gaa`, and `ipp`.
- Preserve existing keys for backward compatibility.
- Keep existing saved/custom perspectives from breaking.
- Ensure position control configuration is included in the payload for frontend rendering.
- Ensure locked perspective filters are enforced on the server.

## Architecture And Documentation

Potential documentation updates for the implementation PR:

- Update `docs/ENUMS.md` if `goalie_decision` introduces enum-like values such as `W`, `L`, `OTL`, and `ND`.
- Add or update architecture YAML if this work formalizes a native advanced fantasy stats derivation/read-model abstraction.
- Update `docs/ARCHITECTURE_INVENTORY.md` only as a derived inventory summary after canonical architecture docs exist.
- Keep database schema documentation descriptive; migrations remain the schema source of truth.

## Testing Expectations For Future Implementation

Future implementation must follow `docs/testing/testing-standards.yaml`.

Expected Pest coverage:

- Migration fields and model casts for new goalie columns.
- Goalie game derivations: starts, decisions, SV%, GAA, quality starts, and really bad starts.
- Goalie season rollups and percentages.
- Native skater derived fields and aliases.
- Native on-ice percentage and rate calculations.
- Stats payload includes new metric keys.
- Perspective locked filters for skaters and goalies.
- Perspective-driven position button payload.
- Backward compatibility for existing seeded perspective keys.

Expected JavaScript/component coverage if frontend position rendering changes:

- Skater perspective shows `F`, `C`, `LW`, `RW`, `D`, and not `G`.
- Goalie perspective shows no position buttons.
- Unrestricted perspective can show the default control set.

## Suggested Implementation Split

If the implementation is too large for one reviewable PR, split it into:

- Native stat derivation, migrations, and rollups.
- Perspective seeding, stats payload exposure, and position-button UI.

## Human Approval Gates

- Explicit approval is required before adding migrations.
- Explicit approval is required before editing import, summary, rollup, stats controller, frontend, or seeder files.
- Explicit approval is required before adding architecture YAML or enum documentation.
