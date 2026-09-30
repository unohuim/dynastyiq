# Opening five days — prediction performance summary

Generated 2026-09-30 00:53:11 EDT. **37 games, 74 team performances, 1332 skater appearances.** Dates with games: 2025-10-07, 2025-10-08, 2025-10-09, 2025-10-11. **October 10 had no games.**

**Formula: 85% offensive SAT + 5% defensive SAT in directly matching buckets.** Historical personal conversion and the existing goalie response follow that calculation.

**Winner record: 19–18 (51.4%).** Always picking the home team would have produced 17/37 correct selections.

## Goals, SOG and SAT across the full sample

| Metric                   | Predicted total | Actual total | Delta | MAE/team | Mean signed error/team |
| ------------------------ | --------------- | ------------ | ----- | -------- | ---------------------- |
| Goals after goalie       |             216 |          231 |   -15 |    1.387 |                     -0 |
| SOG                      |            2099 |         2122 |   -23 |    5.235 |                     -0 |
| SAT                      |            4381 |         4424 |   -43 |    8.513 |                     -1 |
| xGA before goalie        |             216 |          231 |   -15 |    1.384 |                     -0 |
| Expected GA after goalie |             216 |          231 |   -15 |    1.387 |                     -0 |

Delta is prediction minus actual. MAE is mean absolute error per team. Actual hockey goals total **231**; official scoreboard goals total **234**, including 3 shootout standings goals. xGA before goalie skill is compared with realized GA, not an actual expected-goals measurement. Expected GA after goalie mirrors opposing final xGF.

## Results by game day

| Date                                            | Games | Record | Goals P / A |   SOG P / A |   SAT P / A | Goals MAE | SOG MAE | SAT MAE | Mean C% |
| ----------------------------------------------- | ----- | ------ | ----------- | ----------- | ----------- | --------- | ------- | ------- | ------- |
| [2025-10-07](2025-10-07/2025-10-07_analysis.md) |     3 |    2–1 |     17 / 13 |   169 / 160 |   357 / 355 |     1.070 |   4.913 |   7.365 |    73.7 |
| [2025-10-08](2025-10-08/2025-10-08_analysis.md) |     4 |    0–4 |     24 / 27 |   229 / 234 |   474 / 491 |     1.400 |   4.236 |   8.598 |    74.2 |
| [2025-10-09](2025-10-09/2025-10-09_analysis.md) |    14 |    8–6 |     81 / 82 |   794 / 813 | 1661 / 1633 |     1.470 |   5.169 |   7.553 |    73.0 |
| [2025-10-11](2025-10-11/2025-10-11_analysis.md) |    16 |    9–7 |    95 / 109 |   907 / 915 | 1889 / 1945 |     1.371 |   5.603 |   9.546 |    73.4 |
| ALL DAYS                                        |    37 |  19–18 |   216 / 231 | 2099 / 2122 | 4381 / 4424 |     1.387 |   5.235 |   8.513 |    73.4 |

## Strengths and weaknesses

- **Goals:** 216 predicted versus 231 actual (-15, -6.4%). MAE/team 1.387; 33/74 estimates high, 41/74 low. Closest: SEA vs ANA, 2025-10-09 (3 versus 3). Largest miss: ANA vs SJS, 2025-10-11 (3 versus 7).
- **SOG:** 2099 predicted versus 2122 actual (-23, -1.1%). MAE/team 5.235; 40/74 estimates high, 34/74 low. Closest: NSH vs CBJ, 2025-10-09 (32 versus 32). Largest miss: MIN vs CBJ, 2025-10-11 (31 versus 52).
- **SAT:** 4381 predicted versus 4424 actual (-43, -1.0%). MAE/team 8.513; 36/74 estimates high, 38/74 low. Closest: BOS vs CHI, 2025-10-09 (56 versus 56). Largest miss: ANA vs SEA, 2025-10-09 (58 versus 87).

The first three dates produce **10–11** across 21 games; the remaining dates produce **9–7** across 16 games. All predictions were recalculated using the rebuilt model for this experiment.

| Sample                   | Games | Record | Goals MAE/team | SOG MAE/team | SAT MAE/team |
| ------------------------ | ----- | ------ | -------------- | ------------ | ------------ |
| Original three game days |    21 |  10–11 |          1.400 |        4.955 |        7.725 |
| Added game days          |    16 |    9–7 |          1.371 |        5.603 |        9.546 |
| All days                 |    37 |  19–18 |          1.387 |        5.235 |        8.513 |

## Every game: scores and winners

Actual scores include shootout standings goals. Picks use unrounded expected goals.

| Game                                |       Date | Predicted score | Actual score | Pick | Winner |  Result |  C% |
| ----------------------------------- | ---------- | --------------- | ------------ | ---- | ------ | ------- | --- |
| [CHI–FLA](2025-10-07/2025020001.md) | 2025-10-07 |             2–3 |          2–3 |  FLA |    FLA | Correct |  76 |
| [PIT–NYR](2025-10-07/2025020002.md) | 2025-10-07 |             3–3 |          3–0 |  NYR |    PIT |    Miss |  70 |
| [COL–LAK](2025-10-07/2025020003.md) | 2025-10-07 |             3–3 |          4–1 |  COL |    COL | Correct |  75 |
| [MTL–TOR](2025-10-08/2025020004.md) | 2025-10-08 |             3–3 |          2–5 |  MTL |    TOR |    Miss |  76 |
| [BOS–WSH](2025-10-08/2025020005.md) | 2025-10-08 |             3–4 |          3–1 |  WSH |    BOS |    Miss |  74 |
| [CGY–EDM](2025-10-08/2025020006.md) | 2025-10-08 |             3–3 |          4–3 |  EDM |    CGY |    Miss |  71 |
| [LAK–VGK](2025-10-08/2025020007.md) | 2025-10-08 |             3–3 |          6–5 |  VGK |    LAK |    Miss |  76 |
| [CHI–BOS](2025-10-09/2025020008.md) | 2025-10-09 |             2–3 |          3–4 |  BOS |    BOS | Correct |  74 |
| [NYR–BUF](2025-10-09/2025020009.md) | 2025-10-09 |             3–3 |          4–0 |  BUF |    NYR |    Miss |  74 |
| [MTL–DET](2025-10-09/2025020010.md) | 2025-10-09 |             3–3 |          5–1 |  MTL |    MTL | Correct |  68 |
| [OTT–TBL](2025-10-09/2025020011.md) | 2025-10-09 |             2–3 |          5–4 |  TBL |    OTT |    Miss |  77 |
| [PHI–FLA](2025-10-09/2025020012.md) | 2025-10-09 |             3–3 |          1–2 |  FLA |    FLA | Correct |  75 |
| [NYI–PIT](2025-10-09/2025020013.md) | 2025-10-09 |             3–3 |          3–4 |  NYI |    PIT |    Miss |  68 |
| [NJD–CAR](2025-10-09/2025020014.md) | 2025-10-09 |             3–3 |          3–6 |  CAR |    CAR | Correct |  72 |
| [MIN–STL](2025-10-09/2025020015.md) | 2025-10-09 |             3–3 |          5–0 |  STL |    MIN |    Miss |  74 |
| [CBJ–NSH](2025-10-09/2025020016.md) | 2025-10-09 |             3–2 |          1–2 |  CBJ |    NSH |    Miss |  72 |
| [DAL–WPG](2025-10-09/2025020017.md) | 2025-10-09 |             3–3 |          5–4 |  WPG |    DAL |    Miss |  76 |
| [UTA–COL](2025-10-09/2025020018.md) | 2025-10-09 |             3–3 |          1–2 |  COL |    COL | Correct |  72 |
| [CGY–VAN](2025-10-09/2025020019.md) | 2025-10-09 |             2–3 |          1–5 |  VAN |    VAN | Correct |  72 |
| [VGK–SJS](2025-10-09/2025020020.md) | 2025-10-09 |             3–2 |          4–3 |  VGK |    VGK | Correct |  73 |
| [ANA–SEA](2025-10-09/2025020021.md) | 2025-10-09 |             3–3 |          1–3 |  SEA |    SEA | Correct |  75 |
| [LAK–WPG](2025-10-11/2025020022.md) | 2025-10-11 |             3–3 |          2–3 |  WPG |    WPG | Correct |  78 |
| [STL–CGY](2025-10-11/2025020023.md) | 2025-10-11 |             3–3 |          4–2 |  STL |    STL | Correct |  74 |
| [BUF–BOS](2025-10-11/2025020024.md) | 2025-10-11 |             3–2 |          1–3 |  BUF |    BOS |    Miss |  75 |
| [TOR–DET](2025-10-11/2025020025.md) | 2025-10-11 |             3–3 |          3–6 |  TOR |    DET |    Miss |  72 |
| [NJD–TBL](2025-10-11/2025020026.md) | 2025-10-11 |             3–3 |          5–3 |  TBL |    NJD |    Miss |  76 |
| [OTT–FLA](2025-10-11/2025020027.md) | 2025-10-11 |             3–3 |          2–6 |  FLA |    FLA | Correct |  77 |
| [WSH–NYI](2025-10-11/2025020028.md) | 2025-10-11 |             4–3 |          4–2 |  WSH |    WSH | Correct |  71 |
| [NYR–PIT](2025-10-11/2025020029.md) | 2025-10-11 |             3–3 |          6–1 |  PIT |    NYR |    Miss |  68 |
| [PHI–CAR](2025-10-11/2025020030.md) | 2025-10-11 |             2–3 |          3–4 |  CAR |    CAR | Correct |  73 |
| [MTL–CHI](2025-10-11/2025020031.md) | 2025-10-11 |             3–2 |          3–2 |  MTL |    MTL | Correct |  76 |
| [UTA–NSH](2025-10-11/2025020032.md) | 2025-10-11 |             3–3 |          3–2 |  UTA |    UTA | Correct |  73 |
| [CBJ–MIN](2025-10-11/2025020033.md) | 2025-10-11 |             3–3 |          7–4 |  CBJ |    CBJ | Correct |  74 |
| [DAL–COL](2025-10-11/2025020034.md) | 2025-10-11 |             3–3 |          5–4 |  COL |    DAL |    Miss |  73 |
| [VAN–EDM](2025-10-11/2025020035.md) | 2025-10-11 |             3–3 |          1–3 |  VAN |    EDM |    Miss |  68 |
| [ANA–SJS](2025-10-11/2025020036.md) | 2025-10-11 |             3–3 |          7–6 |  ANA |    ANA | Correct |  70 |
| [VGK–SEA](2025-10-11/2025020037.md) | 2025-10-11 |             3–3 |          1–2 |  VGK |    SEA |    Miss |  77 |

## Attempt mix and defensive contribution

| Component                              |  SAT |
| -------------------------------------- | ---- |
| Baseline offense                       | 4903 |
| 85% retained offense                   | 4168 |
| Matched defensive SAT before weighting | 4270 |
| 5% matched defense added               |  214 |
| Unmatched defensive SAT excluded       |    3 |
| Combined SAT before engine rounding    | 4381 |

Predicted SOG/SAT is **47.9%**; observed full-game SOG/SAT is **48.0%**. The training-profile filters retain **4393/4424 actual SAT** and **2109/2122 actual SOG**, giving **48.0%** within that scope. The headline results compare against all game attempts. Filtered attempts remain part of actual hockey performance; this scope difference must not be hidden by changing the target.

Weights multiply their respective SAT inputs directly, without rescaling the combined result. Unknown shot types remain included; unmatched defensive buckets are excluded. Close aggregate totals can still hide team-level errors.

## Baseline player performance

Player estimates precede the matchup weights and goalie response, so they do not sum to final team scores.

| Metric          | Predicted | Actual | Delta | MAE/player appearance |
| --------------- | --------- | ------ | ----- | --------------------- |
| TOI minutes     |     22347 |  21933 |  +415 |                 2.133 |
| Offensive SAT   |      4903 |   4424 |  +479 |                 1.652 |
| Offensive SOG   |      2350 |   2122 |  +228 |                 1.087 |
| Offensive goals |       242 |    231 |   +11 |                 0.281 |

**1281/1332** appearances use paired same-run predicted SAT and TOI; **51** use the volume fallback. Outcomes use personal historical bucket conversion, then pooled bucket fallback. Valid observed zeros remain zero. Repeated appearances are separate observations.

## Defense

Defense includes every skater. These player rows and the team composition are baseline inputs before the experimental team-bucket addition. Individual xSATa/xGA are overlapping on-ice exposures; summing them counts the same chance several times. The engine’s provisional team composition divides those sums by five. Missing personal defensive history or usable TOI uses same-run F3/D3 peer average TOI and defensive bucket rates. Peers are ranked within their training-season source team by historical TOI: forwards 7–9, defensemen 5–6. This is an inferred third-line cohort, not observed line combinations.

All **1332** skater appearances have defensive estimates; **51** use F3/D3 replacements. SATa MAE/player is **4.720**; xGA versus realized on-ice GA MAE/player is **0.742**.

**4424/4424** defending SAT events have at least one skater link; this does not guarantee a complete five-skater assignment. On-ice player totals overlap and cannot be treated as independent team opportunities.

## Goalies and game context

Expected goals before goalie skill: **216**; after: **216**. Net goalie adjustment: **+1 goals**; average absolute adjustment **0** per team. Exact goalie history covers **72.0%** of projected SAT. Missing exact buckets receive neutral skill.

The sample includes **13 empty-net goals**, **6 overtime decisions**, and **3 shootout decisions**. Relief appearances: DET 2025-10-09. Those phases are not separately forecast. Starter forecasts allocate the full game; game reconciliations include every goalie who played.

## Confidence

**Confidence:** C% comes directly from the shared prediction engine and is input confidence, not win probability or a confidence interval. Skater confidence uses the selected run’s stored rate-bucket confidence weighted by source attempts; missing projected pairs use personal training-profile evidence. Goalie confidence weights the engine’s sample-shrunk exact-bucket confidence by projected attempts faced; missing exact history contributes zero confidence. The existing aggregate calculation weights skaters covering 99% of projected team goals, then combines 70% skater confidence and 30% goalie confidence, and averages the two teams for game confidence. Defensive rows show personal defensive evidence confidence; F3/D3 replacement estimates have zero personal-evidence confidence. Team total rows show the overall team input score, not a separate defensive confidence model. Confidence is unchanged in reconciliation.

Game input confidence ranges from **68% to 78%**, averaging **73.4%**.

| Game input confidence | Games | Winner record |
| --------------------- | ----- | ------------- |
| 75% or higher         |    14 |           7–7 |
| Below 75%             |    23 |         12–11 |

## Method and limits

SAT, SOG and goals are displayed as whole numbers; calculations and winner picks retain full precision. Rounded scores can tie despite a selected winner, and rounded rows may not sum to rounded totals. Error statistics and confidence retain decimals. Model **Sep2026, run 1**; training seasons 2022–23 through 2024–25; target 2025–26. Every prediction is returned by `NhlGamePredictionPayload::build(game_id, [sat_model_run_id => 1, use_stored_boxscore => true])`, the same entry point used by live predictions. The report only formats the returned estimates and compares them with actuals. These are retrospective predictions using actual boxscore skaters and recorded starters, not archived pregame forecasts. Actual TOI and outcomes are used only for reconciliation. No 2025–26 observations or 2026–27 supporting projections enter the estimates. Each offensive bucket uses predicted SAT/60 multiplied by unchanged same-run TOI/GP; missing pairs use historical or pooled fallback. xSOG uses each player’s historical training-bucket SOG/SAT; xGF uses that player’s historical goals/SOG. Missing personal denominators use pooled bucket averages; trained bucket probabilities remain the final fallback when empirical averages are absent. Valid historical zeros are retained. Stored predicted SOG/60 and xG/60 are not consumed. Unknown shot types are included as their own bucket values. **Experiment: keep 85% of offensive SAT and add 5% of opposing defensive SAT only where the exact bucket key is already present in the offensive input.** There is no bucket doubling and no normalization back to the original offensive total. All offensive buckets are retained, including unknown shot types. Unmatched defensive keys contribute nothing. This experiment-only service binding reuses the shared engine’s conversion calculation, deriving attacking conversion from these historically converted offensive volumes; no defensive-only bucket volume is added. The existing goalie calculation then runs on the resulting opportunities. Where defensive evidence is completely missing, only 85% of offensive SAT remains. Player offense/defense tables remain baseline inputs before the team-level addition. Production defaults and stored model data are unchanged.

These distributions are compared on the same five calendar dates. This is a small retrospective sample with known participants, not an independent season-wide validation. All calculations use database reads; no model rebuilds, imports, migrations or automated tests were run.

Awaiting human review.
