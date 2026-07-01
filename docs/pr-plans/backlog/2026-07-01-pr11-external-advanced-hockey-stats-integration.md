---
pr_id: 11
pr_name: pr11
status: Backlog
created: 2026-07-01
last_updated: 2026-07-01
---

# External Advanced Hockey Stats Integration

## Source

Fantasy hockey stats import review and follow-up discussion about whether DynastyIQ should build its own expected-goals model or use established external model outputs, including Evolving-Hockey and MoneyPuck.

## Objective

Evaluate and implement licensed external advanced hockey stats integrations so DynastyIQ can provide higher-quality fantasy, expected-goal, shot-quality, goalie, projection, and player-evaluation metrics without building an in-house expected-goals model.

## Current DynastyIQ Context

DynastyIQ already imports and derives many core NHL game stats from NHL play-by-play, boxscore, and shift sources:

- Skater goals, assists, points, primary assists, secondary assists, plus-minus, PIM, shots, missed shots, blocked attempts, shot attempts, hits, blocks, giveaways, takeaways, faceoffs, TOI, and shifts.
- Strength splits for EV, PP, and PK scoring and goalie-facing stats.
- Goalie saves, shots against, goals against, strength splits, and shutouts.
- Shift-derived on-ice summaries including GF, GA, SF, SA, SATF, SATA, FF, FA, hits, blocks, penalties, zone starts, TOI, and shifts.
- Player strength summaries that already include individual goals, assists, points, and IPP.

Known gaps from the review:

- IPP exists in strength summary storage/query code but is not surfaced through the main user-facing stat perspectives.
- xG, ixG, xGF, xGA, xGF%, GSAx, and related expected-goal stats are not stored or exposed.
- Goalie wins, losses, OT losses, quality starts, really-bad starts, GAA, save percentage, and goals-saved metrics are underprovided in the NHL game-derived stat path.
- High-danger chances, scoring chances, rebound/rush classifications, and shot-quality buckets are not implemented.
- On-ice percentage and rate stats such as CF%, FF%, SF%, GF%, PDO, on-ice SH%, and on-ice SV% are not fully surfaced.
- Fantasy convenience composites such as shots plus blocks, hits plus blocks, banger value, category z-scores, and projected fantasy points are not formalized.

## Decision Direction

Do not build a DynastyIQ-owned expected-goals model for this PR.

Reasoning:

- DynastyIQ can build a credible public-data xG model from NHL play-by-play, shot geometry, shift links, score state, strength state, shooter/goalie context, and event sequence features.
- However, richer providers can include stronger data and model context than public NHL play-by-play supports.
- Product-grade expected-goal and player-value metrics should come from a mature external model if licensing and integration terms are acceptable.

## Provider Recommendation

MoneyPuck and Evolving-Hockey should be treated as complementary providers, not interchangeable alternatives.

Recommended provider roles:

- MoneyPuck should be evaluated first for xG, shot quality, goalie xGA/GSAx, rebound/freeze/miss probabilities, flurry-adjusted xG, high-danger shots, and shot-level import feasibility.
- Evolving-Hockey should be evaluated for higher-level player valuation and projection outputs, including GAR, WAR, SPAR, xGAR, RAPM, fantasy projections, player projections, teammate/competition context, and premium player-evaluation models.

Implementation should support multiple provider-tagged metric families:

- `provider = moneypuck`
- `provider = evolving_hockey`

Provider outputs must remain separated unless the UI explicitly labels the source and metric family. DynastyIQ must not mix MoneyPuck xG with Evolving-Hockey xGAR, RAPM, or projection values as if they came from one model.

## MoneyPuck Report

MoneyPuck is the stronger immediate fit for expected-goals and shot-quality imports because it publishes downloadable shot-level datasets with model outputs and shot context.

Relevant public pages reviewed:

- `https://moneypuck.com/data.htm`
- `https://moneypuck.com/about.htm`
- `https://moneypuck.com/glossary.htm`

Data access findings:

- MoneyPuck publishes downloadable season, game-by-game, skater, goalie, line, team, player biography, and shot datasets.
- The data page states that listed data is free for non-commercial purposes and journalistic ad-hoc use with clear MoneyPuck credit.
- The data page says other purposes should inquire by email.
- The data page says non-approved scraping of the MoneyPuck website will be blocked and users should request approval before scraping data not listed on the page.
- Commercial DynastyIQ use therefore requires explicit permission or a commercial arrangement before production ingestion or redistribution.

Shot and xG findings:

- MoneyPuck publishes historical shot data from 2007-2008 onward.
- The shot data includes saved shots on goal, missed shots, and goals; blocked shots are not included in those shot datasets.
- The shot data contains 124 attributes per shot, including player and goalie, angles, distances, previous-event context, how long players had been on ice, xGoals, rebound probability, miss probability, and goalie freeze probability.
- MoneyPuck states that its shot coordinates and distances include data cleaning and arena-adjusted calculations.
- The data is distributed as CSV files inside zip files.

Model findings:

- MoneyPuck's expected-goals model predicts the probability of each shot becoming a goal.
- MoneyPuck says the xG model was built with gradient boosting on NHL regular-season and playoff shots from 2007-2008 through 2014-2015 with location data.
- Publicly listed xG model inputs include shot distance, time since previous event, shot type, speed from previous event, shot angle, previous event location/type, opponent skaters on ice, shot east-west/north-south location, man-advantage situation, power-play elapsed time, distance from previous event, and empty-net state.
- MoneyPuck also documents flurry-adjusted expected goals, shooting-talent-adjusted expected goals, expected rebounds, expected freezes, expected save percentage, goals saved above expected, save percentage above expected, and created expected goals.

DynastyIQ fit:

- MoneyPuck is the best first provider to evaluate for filling DynastyIQ's missing xG and shot-quality layer.
- MoneyPuck data appears more directly importable than Evolving-Hockey for shot-level expected-goal values because public CSV/zip datasets are already documented.
- MoneyPuck can potentially support skater ixG, on-ice xGF/xGA, xGF%, goalie xGA, GSAx, rebound/freeze/miss-derived indicators, high-danger/medium-danger/low-danger chances, and flurry-adjusted expected-goal views.
- Because MoneyPuck shot files do not include blocked shots, DynastyIQ should continue using its own NHL play-by-play-derived blocked-shot and Corsi/SAT data rather than expecting MoneyPuck shot files to replace all shot-attempt data.

## Evolving-Hockey Report

Evolving-Hockey is a sports statistics and model site with no direct NHL, team, or NHLPA affiliation. Its about page states that all data is unofficial and subject to change. Live game data is updated every two minutes starting at the top of the hour.

Relevant public pages reviewed:

- `https://evolving-hockey.com/about/`
- `https://evolving-hockey.com/subscribe/`
- `https://evolving-hockey.com/evolving-hockey-overview/`
- `https://evolving-hockey.com/terms-of-use/`
- `https://evolving-hockey.com/references/`
- `https://evolving-hockey.com/glossary/`
- `https://evolving-hockey.com/stats/fantasy_projections/`
- `https://evolving-hockey.com/stats/pbp_query/`

Model families and tools relevant to DynastyIQ:

- Global Expected Goals Model.
- GAR, WAR, and SPAR style player valuation.
- xGAR for expected value above replacement.
- Skater and team RAPM.
- Goalie GAR and xGAR.
- Penalty Goals and Shooting Goals.
- Fantasy projections for points and categories leagues.
- Player projections.
- PBP and shift query tools.
- Skater similarity, teammate tools, and quality of teammate/competition.
- Live game tools, shot charts, player cards, and team/skater visualizations.

Current model versions listed on Evolving-Hockey's about page at review time:

- Global Expected Goals Model: 2.0, released 2019-10-02.
- Goals Above Replacement / Statistical Plus-Minus for skaters: 2.0.1, released 2019-11-25.
- Expected Goals Above Replacement for skaters: 2.0, released 2020-11-13.
- Expected Goals Above Replacement for goalies: 2.0, released 2020-11-13.
- Regularized Adjusted Plus-Minus for skaters: 2.1, released 2020-10-13.
- Regularized Adjusted Plus-Minus for teams: 2.0, released 2019-10-02.
- Penalty Goals for skaters and goalies: 2.0.1, released 2019-11-25.

Subscription findings:

- Standard subscription includes proprietary metrics in table and visual form, including RAPM, GAR, xGAR, and most metrics xG is used for.
- Pro subscription includes Standard features plus projection models, PBP and shift query tools, and spreadsheet/CSV downloads for all tables.
- Pro is the minimum tier that appears useful for evaluating importable data shape because CSV downloads are specifically listed under Pro.

Terms and licensing findings:

- The terms prohibit bots or automatic methods to copy any part of the site.
- With the exception of permitted distribution, data, visualizations, and material obtained from Evolving-Hockey are for personal use.
- Apart from permitted distribution, users may not distribute, share, copy, upload, or otherwise make available to any third party any data found on the site.
- Permitted distribution allows statistical models created from data provided by the site to be publicly shared with attribution, but this does not clearly authorize DynastyIQ to redistribute Evolving-Hockey data or model outputs inside a commercial product.
- Commercial/product ingestion must not proceed without explicit written permission or a separate license/data-feed arrangement.

## Proposed Integration Shape

Treat MoneyPuck and Evolving-Hockey as external stats providers, not as replacements for DynastyIQ's NHL import pipeline.

Required integration principles:

- Do not scrape MoneyPuck or Evolving-Hockey.
- Do not use non-commercial, personal, or subscription exports in production without explicit permission.
- Do not relabel external model outputs as DynastyIQ-derived stats.
- Preserve provider attribution wherever Evolving-Hockey metrics are shown.
- Store provider name, imported timestamp, model family, and model version where available.
- Keep external model outputs separate from NHL play-by-play-derived stats so different model families are not accidentally mixed.
- Prefer imported provider totals and projections as read models; do not mutate canonical NHL game summaries to make them match provider outputs.

Potential storage approach:

- Add provider-scoped advanced stat tables or a normalized external stat snapshot table.
- Store source identity fields needed for reconciliation, such as season, game type, player identity, team, strength, and provider player identifier where available.
- Link to canonical `players` through existing player identity resolution patterns.
- Keep game-level, season-level, projection, and model-output data distinguishable.

Candidate metrics to prioritize:

- MoneyPuck-backed xG, xGF, xGA, xGF%, ixG, flurry-adjusted xG, created expected goals, expected rebounds, expected freezes, expected save percentage, GSAx, and danger buckets.
- Evolving-Hockey-backed GAR, WAR, SPAR, xGAR, RAPM, model components, fantasy projections, and player projections where license allows.
- Goalie projected and historical fantasy categories, including starts, wins, saves, save percentage, goals against, GAA, xGA, and GSAx.
- Over/under-performing fantasy and expected-stat indicators where license allows.

## Implementation Phases

1. Licensing and feasibility
   - Contact MoneyPuck to ask whether DynastyIQ can import, cache, and display MoneyPuck shot/model outputs to DynastyIQ users.
   - Contact Evolving-Hockey using their support/contact channel.
   - Ask whether DynastyIQ can import, cache, and display their model outputs to DynastyIQ users.
   - Ask both providers whether a commercial license, API, bulk export, or approved CSV workflow exists.
   - Confirm allowed attribution, refresh frequency, historical backfill, and redistribution boundaries.

2. Data evaluation
   - Use approved MoneyPuck samples or licensed downloads to inspect shot-level CSV fields and stable identifiers.
   - Use an approved Evolving-Hockey Pro subscription or sample feed to inspect CSV fields and stable identifiers.
   - Compare player/team identifiers against DynastyIQ player identity data.
   - Identify exact stat families available for skaters, goalies, teams, games, projections, and fantasy.

3. Architecture documentation
   - Add a canonical architecture YAML under `docs/architecture/integrations/` or `docs/architecture/stats/` before implementation if a durable provider integration is approved.
   - Update `docs/ARCHITECTURE_INVENTORY.md` as a derived summary after the architecture YAML is added.
   - Add any new enum-like provider names, metric families, or statuses to `docs/ENUMS.md` before use.

4. Import and storage
   - Implement a provider-scoped importer only after licensing is approved.
   - Preserve raw provider rows where allowed.
   - Persist normalized model outputs with provider and version metadata.
   - Use existing import-run/progress patterns where applicable.

5. Stats surfacing
   - Add new perspectives or stat columns for licensed provider metrics.
   - Label provider-backed columns clearly.
   - Avoid mixing Evolving-Hockey values with DynastyIQ-derived NHL summaries unless the UI makes the source explicit.

## Out Of Scope

- Building a DynastyIQ-owned expected-goals model.
- Scraping MoneyPuck or Evolving-Hockey.
- Using non-commercial, personal, or subscription data in production without permission.
- Replacing the existing NHL game import pipeline.
- Changing migrations, imports, UI, or stat controllers before licensing and architecture are approved.
- Auto-committing, auto-running tests, or running import/backfill commands.

## Testing Expectations For Future Implementation

The eventual implementation should be test-driven and follow `docs/testing/testing-standards.yaml`.

Expected coverage areas:

- Provider import authorization and configuration validation.
- CSV/feed parsing with deterministic local fixtures.
- Player identity reconciliation and unmatched/candidate handling.
- Provider/model version persistence.
- No accidental overwrites of DynastyIQ-derived NHL summaries.
- Stats API/UI payloads include provider attribution.
- Access control for any admin import controls.
- Failure handling for malformed rows, unknown players, and partial provider files.

## Open Questions

- Does MoneyPuck offer a commercial product/data license suitable for DynastyIQ?
- Does Evolving-Hockey offer a commercial product/data license suitable for DynastyIQ?
- Do either providers offer an API or approved scheduled export workflow, or only manual CSV downloads?
- Which identifiers are available in exports for stable player matching?
- Can DynastyIQ display raw model outputs to paying users, or only internal derived conclusions?
- Are MoneyPuck shot-level xG outputs redistributable under a commercial agreement?
- Are Evolving-Hockey fantasy projections redistributable under a commercial agreement?
- Can model version metadata be obtained directly from exported data, or must it be stored from documentation/release notes?

## Human Approval Gates

- Explicit approval is required before contacting MoneyPuck on behalf of DynastyIQ.
- Explicit approval is required before contacting Evolving-Hockey on behalf of DynastyIQ.
- Explicit approval is required before creating new architecture YAML.
- Explicit approval is required before adding migrations, importers, tests, UI columns, or provider-backed perspectives.
