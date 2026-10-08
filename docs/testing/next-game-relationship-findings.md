# Next-game research: Train-to-Test relationships

Research snapshot: 8 October 2026. Local DynastyIQ data, Sep2026 model #1.

This records the relationships explored so far—not approved production formulas. It preserves useful findings, weak findings, and comparisons that did not improve matters. No live prediction changes are specified by this document.

**Lineup-first invariant:** A team is only the collection of individual players in the selected game lineup. Every team input and estimate must be derived from those players. Never model historical team identity as an independent predictive entity or feed an independent team estimate back into players. League averages may be population references, not replacements for missing lineup members.

**Correction:** Section 11's independent team estimates violate this invariant and are withdrawn as evidence for the intended pathway. Section 12 records the replacement lineup-derived analysis and its coverage limits. Individual-player results are separate from that withdrawn team analysis.

**Further correction:** Any displayed comparison of an opening-lineup Train/pregame value with a changing full-season lineup was not composition-matched. Do not use those comparisons to judge Train versus Test. Section 13 replaces that descriptive table: every included Test lineup supplies the same player identities to every historical window. The formula study in section 12 and the descriptive rate comparisons in section 13 measure different things and must not share an unlabeled error column.

## Main findings

- **SAT/60:** Individual games vary widely. Longer blocks looked more representative of Train, but we have not established a universal regression formula.
- **EV ice time:** Across the broader sample, recent usage was closer to the next game's usage than three-season Train. Our original five players did not represent the whole population.
- **Age and position:** Recent usage remained useful across these groups. L10 versus L20 differences were generally too small to justify separate algorithms.
- **Momentum:** Recent increases/decreases had a modest directional relationship with the next game. Following the entire L5 movement was less useful than retaining a longer reference.
- **EV opportunity:** A player's share of the team's available EV time was more consistent than raw minutes. Accounting for actual game EV opportunity reduced the average difference from 1.91 to 1.64 minutes. This was a descriptive comparison, not a forecasting improvement.
- **Bucket filtering:** Retaining confidence ≥50% barely changed aggregate Build /60 results because the builder rescales retained buckets to its player-level target.

## Definitions and scope

| Term | Meaning here |
| --- | --- |
| Train | Pooled measured data from 2022–23, 2023–24 and 2024–25; not an equally weighted average of three season rates |
| Test | Actual 2025–26 results |
| L5 / L10 / L20 | Previous 5 / 10 / 20 regular-season player appearances, excluding the current game |
| Last season | 2024–25 |
| SAT/60 | Shot attempts divided by relevant ice-time seconds, multiplied by 3,600 |
| EV TOI/GP | Recorded EV minutes divided by measured appearances |
| Average difference | Mean absolute difference between a reference and actual Test usage; measured in minutes for TOI |

For example, a reference of 16 minutes and actual usage of 18 minutes contributes **2 minutes** to average difference. Actual usage of 14 minutes also contributes 2 minutes. This does not mean two percentage points or two missed shots.

Recent windows cross into the previous regular season at the start of Test. They update game by game in the sequential analyses below. The HTML overview tables instead show a fixed pre-G1 context alongside later results.

Stored EV classification is used; it can include empty-net situations and is not strictly a 5-on-5-only definition. PP and PK are not pooled into these EV analyses. The separate Build /60 cutoff comparison below is explicitly **all-strength**.

Sources include boxscore appearances, player strength summaries and shot-bucket facts. Missing strength records are not treated as zero. Recorded appearances reflect local coverage, not a guarantee of complete NHL history.

Detailed player tables:

- [EV SAT/60: Train, recent windows, individual games and 20-game blocks](games2025.htm)
- [EV TOI/GP: the same player/window layout](games2025-ev-toi.htm)

## 1. SAT/60: individual games versus longer blocks

The five-player view covers Forsberg, McDavid, Pastrnak, Coleman and Matthews. G1–G10 refer to each player's own appearances, not shared calendar dates.

Longer blocks smooth extreme individual games. Two examples from the report:

| Player | Train SAT/60 | Games 1–20 | 21–40 | 41–60 | 61–82 available | Test available |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Coleman | 15.81 | 15.56 | 14.44 | 16.08 | 15.73 (9 GP) | 15.41 (69 GP) |
| Matthews | 21.56 | 18.48 | 17.65 | 18.09 | — | 18.07 (60 GP) |

Coleman illustrates stable longer-block production close to Train. Matthews illustrates the other important possibility: a sustained level below Train. These observations support studying regression and changes in level, not assuming every player returns to Train.

Block SAT/60 is calculated from pooled attempts and pooled EV exposure, not the arithmetic mean of individual game rates. Positive EV exposure in zero-attempt games remains in the denominator. League SAT/60 is exposure-weighted, not the average of the five displayed players.

### Forsberg's zero was real

In game `2025020041`, 13 October 2025, Forsberg played:

- EV: **13:40, zero attempts**.
- PP: **6:02, four attempts**.
- Total: **19:42, four attempts**.

His zero EV SAT/60 was not a missed game. Removing that appearance would inflate his EV rate.

## 2. Team lineup SAT/60 and the H/L exercise

We examined the six teams from opening night: CHI, FLA, PIT, NYR, COL and LAK, over their first 20 Test games. That is 120 team-games and 2,160 lineup appearances.

The metric was the **sum of individual EV SAT/60 rates for the actual boxscore lineup**. It is a lineup index, not conventional team SAT/60. A short appearance with several attempts can contribute a large individual rate.

Actual boxscores supplied lineup identities. Train and recent inputs used only earlier games. This is conditional on knowing the lineup; it does not establish advance lineup availability.

For each game, H meant a higher index than the team's previous game; L meant lower. The comparison progressed one game at a time, updating recent context. G1 used the team's final previous regular-season game as the reference.

### Exploratory rule used

```text
anchor = 0.50 × lineup Train
       + 0.30 × lineup L20
       + 0.15 × lineup L10
       + 0.05 × lineup L5

current estimate = (5 × anchor + sum of earlier Test game indices)
                 / (5 + number of earlier Test games)

estimated first-20 average =
    (sum of earlier Test indices + remaining games × current estimate) / 20
```

“Remaining games” includes the current game. The actual eventual first-20 average was not an input. Missing Train used the league Train reference of 11.73 per player; missing recent rates used player Train, or that league reference when Train was unavailable.

These weights were exploratory choices, not learned coefficients. Test results had already been examined during the research, so this was not a blind validation.

| Team | Correct H/L | Correct % |
| --- | ---: | ---: |
| CHI | 14/20 | 70% |
| FLA | 16/20 | 80% |
| PIT | 18/20 | 90% |
| NYR | 16/20 | 80% |
| COL | 14/20 | 70% |
| LAK | 14/20 | 70% |
| **Total** | **92/120** | **76.7%** |

Other references on that sample:

| Reference | Correct | Correct % |
| --- | ---: | ---: |
| Train only | 91/120 | 75.8% |
| L20 only | 89/120 | 74.2% |
| Updated anchor blend without the season-average component | 94/120 | 78.3% |
| Fixed opening anchor | 93/120 | 77.5% |

The more elaborate rule did not beat every simpler reference. Differences were only a few games in a selected sample. This is evidence worth following, not a demonstrated best algorithm.

### Why Florida could look normal despite missing stars

The actual lineup already omitted absent players. A normal-looking index could still arise from unusually high individual game rates: Bennett went from Train 17.03 to 32.02; Kunin from 11.46 to 19.73 in 365 EV seconds; Greer from 12.42 to 18.00 in 400 EV seconds.

This does not measure the cost of missing talent. The absent-talent/IR investigation was shelved; no accuracy gain from that feature was established.

## 3. Build /60 bucket-confidence comparison

This was an **all-strength player-level Build /60 comparison**, not the EV game-by-game analysis. It used the same 815-player cohort and a manual calculation matching the builder, not a newly rebuilt model.

“Total error” here means:

```text
100 × sum of absolute differences between projected and actual player SAT/60
    / sum of actual player SAT/60
```

The player thresholds use each player's own relative difference. They are not percentages of correctly predicted games.

| Retained buckets | Total error | Players <10% error | Players <20% error |
| --- | ---: | ---: | ---: |
| All | 17.81% | 351/815 (43.07%) | 554/815 (67.98%) |
| Confidence ≥50% | 17.81% | 352/815 (43.19%) | 554/815 (67.98%) |
| Confidence >97% | 17.81% | 351/815 (43.07%) | 554/815 (67.98%) |

The builder rescales retained buckets to the same player-level SAT/60 target. Filtering primarily changes bucket distribution, not the total target. The one-player threshold difference is not meaningful evidence of improvement.

A separate bucket-level absolute-error calculation was 88.42%, 88.26% and 88.74%, respectively. That measures distribution differences across buckets and must not be substituted for the player-level percentages above.

The approved builder adjustment retains **≥50%**, excluding **<50%**, before scaling/output. Source profiles are not discarded by that adjustment. The comparison does not establish 50% as an optimal forecasting cutoff.

## 4. EV ice time: the original five players

All values below are decimal EV minutes per appearance: 15.50 means 15 minutes 30 seconds.

| Player | Train | Pre-G1 L10 | Pre-G1 L20 | Test G1–10 | Test available | Test GP |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Forsberg | 15.20 | 15.56 | 15.11 | 15.49 | 15.86 | 82 |
| McDavid | 17.86 | 17.16 | 18.53 | 18.32 | 18.34 | 82 |
| Pastrnak | 16.17 | 16.27 | 17.30 | 15.64 | 17.00 | 77 |
| Coleman | 14.31 | 14.36 | 14.36 | 13.73 | 13.89 | 69 |
| Matthews | 16.44 | 15.46 | 15.99 | 17.14 | 16.88 | 60 |

Train strength coverage was incomplete: Forsberg 201/208 recorded appearances, McDavid 203/216, Pastrnak 227/236, Coleman 216/227 and Matthews 205/218. Missing EV values were excluded, not replaced with zero. Bucket-confidence filtering does not apply to these ice-time totals.

### Rolling comparison across their 370 Test appearances

Unlike the fixed pre-G1 columns above, these references update before every appearance.

| Reference | Average absolute difference from actual EV minutes |
| --- | ---: |
| Train | 1.920 |
| L5 | 2.066 |
| L10 | 1.951 |
| L20 | 1.925 |

Train and L20 were practically tied in this selected sample. L5 varied more. Pastrnak showed a useful exception: after game 40, Train differed by 2.28 minutes, L10 by 1.89 and L20 by 1.81 across 37 appearances—consistent with recent usage tracking a sustained change.

## 5. Broader ice-time relationships by age

We expanded to **43,521 player-games from 759 players**. Eligibility required measured Test EV time, available Train and complete EV observations in the previous 20 appearances. Players without Train, including some newcomers, are not represented.

Age is age on game day. A player can cross an age boundary, so subgroup player counts do not sum to 759.

Every difference column below is **average absolute EV minutes**, not an error percentage.

| Age | Players | Player-games | Train | L10 | L20 | Half Train + half L20 |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| ≤23 | 133 | 6,364 | 2.254 | 1.890 | 1.911 | 1.983 |
| 24–32 | 537 | 29,529 | 2.172 | 1.930 | 1.937 | 1.974 |
| 33–37 | 128 | 6,893 | 2.035 | 1.845 | 1.839 | 1.865 |
| 38+ | 12 | 735 | 2.085 | 1.789 | 1.788 | 1.836 |

Recent usage was closer than Train in every age group. A 50/50 Train–L20 blend did not improve on recent usage alone. L10 and L20 were close; these results do not justify four different age formulas.

An equal-player-weight sensitivity check preserved the broad recent-versus-Train finding, but reversed L10 versus L20 ordering for ages 33–37. That reinforces how small the distinction between those windows was. The 38+ group is especially small.

## 6. Position and age together

Position came from that game's boxscore, not a player's current roster position.

| Position | Age | Player-games | Train difference | L10 difference | L20 difference |
| --- | --- | ---: | ---: | ---: | ---: |
| Defense | ≤23 | 1,628 | 2.659 | 2.174 | 2.235 |
| Defense | 24–32 | 10,459 | 2.424 | 2.154 | 2.170 |
| Defense | 33–37 | 2,326 | 2.303 | 2.109 | 2.093 |
| Defense | 38+ | 197 | 2.376 | 1.895 | 1.909 |
| Forward | ≤23 | 4,736 | 2.114 | 1.792 | 1.800 |
| Forward | 24–32 | 19,070 | 2.033 | 1.807 | 1.809 |
| Forward | 33–37 | 4,567 | 1.899 | 1.710 | 1.709 |
| Forward | 38+ | 538 | 1.979 | 1.750 | 1.744 |

Defensemen had larger absolute minute differences. Recent usage remained closer in every group. Even the largest L10–L20 difference here, for young defensemen, was only about 3.6 seconds. The oldest defense group contained just three players.

L10 was a convenient continuing research reference, not a proven uniquely correct window or an implemented production rule.

## 7. Momentum: does the direction carry forward?

Movement was defined as **last-five average EV minutes minus the preceding-five average**:

- Rising: movement ≥1 minute.
- Falling: movement ≤−1 minute.
- Stable: movement between those boundaries.

Same 43,521 player-games. Positive “Test minus L10” means the actual next appearance was above its pregame L10 reference.

| Group | Games | Average movement | Test minus L10 | Absolute difference: L10 | L5 | Half L10 + half L5 |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Rising | 11,189 | +2.03 min | +0.32 min | 1.925 | 1.966 | 1.892 |
| Falling | 11,595 | −2.03 min | −0.35 min | 1.959 | 2.040 | 1.950 |
| Stable | 20,737 | −0.01 min | −0.05 min | 1.871 | 1.877 | 1.870 |
| All | 43,521 | −0.02 min | −0.03 min | 1.908 | 1.943 | 1.897 |

Rising cases finished above L10 56.41% of the time; falling cases finished below it 55.38% of the time. Direction carried some information, but not the full size of the recent movement.

Switching to L5 for trending cases and retaining L10 otherwise increased the average absolute difference to 1.941 minutes. The half-L10/half-L5 comparison reduced it by only about **0.67 seconds** overall. This is too small, by itself, to justify added complexity.

## 8. Player usage versus available team EV time

A player's minutes depend on both his role and the EV time available in that game. We separated those two quantities.

```text
prior L10 EV share = sum of player's EV seconds in previous 10 appearances
                  / sum of his team's EV seconds in those appearances

opportunity-adjusted reference = prior L10 EV share × actual Test game team EV minutes
```

Example, for illustration only: a 30% recent share with 50 actual team EV minutes corresponds to 15 player minutes; the same share with 55 team EV minutes corresponds to 16.5 minutes without any change in role.

Team EV clock was the union of EV forward/defense unit intervals from `nhl_unit_shifts` and `nhl_units`. Overlapping intervals were counted once. Goalie intervals were not used, because a goalie also plays during PP/PK. This was not summed player TOI divided by five.

All 43,521 eligible player-games passed the additional clock checks. The supporting clock extraction contained 9,922 team-games, with EV time ranging from 2,101 to 3,900 seconds and averaging 51.10 minutes.

| Group | Player-games | Direct L10 minute difference | Opportunity-adjusted difference | EV-share difference |
| --- | ---: | ---: | ---: | ---: |
| All skaters | 43,521 | 1.908 min | 1.643 min | 3.212 percentage points |
| Defense | 14,610 | 2.145 min | 1.811 min | 3.539 percentage points |
| Forwards | 28,911 | 1.788 min | 1.558 min | 3.047 percentage points |

Accounting for actual EV opportunity reduced absolute minute differences by **13.9%** overall. This supports separating player role from game opportunity.

Crucially, the adjusted comparison uses the actual Test game's team EV clock. That is appropriate for understanding the relationship, but it is not information available before the game and is **not a 13.9% forecasting improvement**.

Pooled correlations also increased when comparing shares rather than minutes: all skaters 0.762→0.802; defense 0.633→0.688; forwards 0.682→0.727. Stable differences between players contribute to these correlations; they do not establish a causal or purely within-player relationship.

## 9. League references and interpretation

League EV TOI/GP was 14.11 minutes in Train and 14.29 in the last season. In the HTML Test blocks it rose from 13.51 to 14.16, 14.55 and 15.10 minutes.

That does not mean every player's role increased. Later appearance blocks contain a different population: players reaching 61–82 appearances differ from those appearing only a few times. League TOI is appearance-weighted; league SAT/60 is exposure-weighted. Neither is an equal average of the five example players.

## 10. What remains open

1. Check whether EV-share consistency varies by the same age groups; that follow-up has not been performed.
2. Separate within-player role changes from stable differences between players.
3. Revisit SAT/60 block relationships across a broader population, retaining genuine zero-attempt appearances.
4. If moving from relationships to forecasting, determine team EV opportunity using pregame information only, then assess the combined result separately.
5. Confirm interesting relationships on additional seasons before selecting production weights.

Travel, rest and absent talent have not yet demonstrated gains in this research. PP/PK relationships were not established by these EV checks. No separate age algorithm, momentum formula, or live next-game forecasting formula is approved by these findings.

This report records earlier local analyses; writing it does not rerun them, rebuild models, or certify the automated tests. The HTML files preserve the detailed player views; this document preserves the broader research findings and their boundaries.

## 11. Proposed shooting-rate formula and first empirical check

Added 8 October 2026. This section records a newly executed read-only SQL analysis, not an automated application test or production change.

**Superseded for teams:** The team estimates in this section applied the formula to historical team rates instead of first estimating each lineup player. Do not use the team figures or favorable team interpretation below to justify the lineup-first prediction pathway. They remain only as an audit of the mistake. In particular, the subsequently reported 2.96% average relative team error belongs to this invalid approach. See section 12.

### Hypothesis, not fitted weights

Target: **EV shots on goal per 100 attempts over the next 20 appearances** (team games for teams).

```text
estimate = recent league rate
         + 0.70 × (player/team Train rate − league Train rate)
         + 0.30 × (player/team L20 rate − recent league rate)
```

Illustration: league 47, usual player gap +8, recent gap +14 gives `47 + 0.70×8 + 0.30×14 = 56.8` SOG per 100 attempts. The 70/30 weights were proposed as a starting guess, not selected by prior fitting.

### How this check was run

- Same EV attempt universe and model-4 bucket-confidence ≥50% filter as the shooting-rate exploration; exclude empty-net and shootout attempts.
- Train pools S1–S3 counts. League Train pools the same seasons across all available skaters. Rates are ratios of summed counts, not averages of percentages.
- Evaluate immediately before Test appearance/game 1, 2 and 3. Targets are respectively appearances/games 1–20, 2–21 and 3–22. All inputs precede the target's starting game.
- L20 uses the entity's previous 20 measured appearances/games, crossing into previous seasons as needed. A recorded zero-attempt appearance remains in the window; a window with no attempts has no rate.
- To make the previously unspecified “recent league” term concrete, use pooled attempts from the 20 most recent NHL games starting more than six hours before the target game. This is a historical timing buffer, not a verified game-completion timestamp. This league window is shorter in calendar time than a player's 20 appearances; it is one operational definition, not an established optimal choice.
- Require at least 100 Train attempts, 20 prior measured appearances/games, 20 future measured appearances/games and nonzero prior/target attempts. Future availability determines inclusion only, not the estimate. This excludes short-season players and entities without Train under the same ID.
- Team rates pool actual historical lineup counts; they do not reconstruct a fixed prospective lineup. Team IDs are not stitched across franchise relocations.
- Missing EV summaries are excluded before windows are formed, so these are measured appearances. These results are not a guarantee of complete source coverage.

The first origin includes 579 players and 31 teams. The three origins overlap heavily and must not be treated as three independent validations. The season had already been explored before this hypothesis, so this is exploratory evidence rather than untouched validation.

### Results: average absolute difference from the next-20 rate

Units are **SOG per 100 attempts**, equivalently percentage points. An estimate of 55 against an actual rate of 50 contributes 5 points. Lower is closer.

“Adjusted Train” = recent league rate + (entity Train − league Train), with no L20 blend. “Raw Train” is the entity's unchanged Train rate.

| Group | Before game | Cases | 70/30 blend | Raw Train | Adjusted Train | L20 only |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Players | 1 | 579 | 6.003 | 6.110 | 5.916 | 7.700 |
| Players | 2 | 578 | 5.959 | 6.138 | 5.902 | 7.605 |
| Players | 3 | 574 | 6.084 | 6.157 | 6.005 | 7.796 |
| Teams | 1 | 31 | 1.449 | 2.854 | 1.607 | 1.669 |
| Teams | 2 | 31 | 1.334 | 2.851 | 1.410 | 1.709 |
| Teams | 3 | 31 | 1.299 | 3.028 | 1.320 | 1.646 |

League context must accompany these comparisons:

| League reference | SOG per 100 attempts |
| --- | ---: |
| S1 | 53.38 |
| S2 | 49.04 |
| S3 | 46.55 |
| Full Test, descriptive only | 46.76 |

Mean recent-league inputs across player cases were 47.41, 47.26 and 46.55 at the three origins; across team cases, 47.59, 47.43 and 46.58. These are means of the case-specific league inputs, not new pooled league season rates. The full Test league rate was not an input.

### Interpretation

- For players, **adjusted Train was slightly closer than the proposed blend at every origin**. The proposed 30% recent weighting did not earn its place in this check.
- For teams, **the blend was closer than all three references at every origin**, although its advantage over adjusted Train narrowed by game 3. This does not establish statistical significance or optimal weights.
- The blend's up/down agreement versus L20 was 70.5%, 67.6% and 71.8% for players; 77.4%, 80.6% and 64.5% for teams. Direction means the next-20 rate above/below pregame L20, not above/below the previous individual game.
- Algebraically, the blend is `0.70 × adjusted Train + 0.30 × L20`. Consequently, it has exactly the same up/down direction relative to L20 as adjusted Train alone. Directional success cannot validate the 70/30 weights; those weights only change the estimated magnitude.
- The league shift is material. Its cause and source-data consistency still need investigation; do not automatically interpret it as a league-wide skill change.

Next: retain the unsuccessful player result, inspect representative player/team estimates, and check additional non-overlapping origins before adopting or tuning a formula. No live formula was changed.

## 12. Corrected analysis: individuals first, lineups only

### Individual-player check

The player formulas never used an independent team estimate. Their relative-error results, using the original first-three-origins sample, are:

| Method | Average individual relative error | Players within 10% | Players within 20% |
| --- | ---: | ---: | ---: |
| Train only | 14.82% | 47.67% | 76.86% |
| League-adjusted Train | 13.82% | 48.01% | 79.62% |
| 70/30 blend | 13.87% | 47.67% | 77.89% |
| Last 20 only | 17.32% | 35.41% | 65.46% |

There are 579 players and 1,731 measured origin windows. Average the available origin errors for each player first, then give each player equal weight. “Within” thresholds refer to that player's average relative error. These are not league-total errors or percentage-point errors.

```text
individual relative error = abs(estimated rate − actual rate) / actual rate × 100
```

Example: an estimated rate of 50 and actual rate of 48 gives 4.17% relative error. Rates below are SOG per 100 attempts; only the error column is relative error.

| Player | Train | Pre-G1 L20 | 70/30 estimate | Actual G1–20 | Relative error |
| --- | ---: | ---: | ---: | ---: | ---: |
| Forsberg | 48.74 | 44.94 | 46.21 | 48.04 | 3.81% |
| McDavid | 59.39 | 52.56 | 56.29 | 54.88 | 2.58% |
| Pastrnak | 54.42 | 47.17 | 51.19 | 45.37 | 12.83% |
| Coleman | 52.95 | 46.15 | 49.86 | 60.00 | 16.90% |
| Matthews | 54.58 | 50.56 | 52.33 | 53.47 | 2.13% |

League references remain Train 49.23, last season 46.55 and descriptive full Test 46.76 SOG per 100 attempts. Full Test is never an estimate input.

### Replacement lineup-derived calculation

Every game starts with its actual boxscore skater lineup. Each member receives an individual estimate from information available before that game. No historical team rate enters any method.

For this exploratory rate check, each player's prior measured appearances provide a common attempt-volume weight across all four methods:

```text
player estimated attempts/game = attempts in previous up-to-20 measured appearances
                              / number of those appearances
player estimated SOG/game = estimated attempts/game × individual estimated SOG/SAT

lineup estimated attempts = sum of members' estimated attempts
lineup estimated SOG = sum of members' estimated SOG
lineup estimated SOG per 100 attempts = 100 × lineup estimated SOG / lineup estimated attempts
```

The volume weight is a simple pregame historical input for this analysis, not a validated SAT/60 or TOI forecast and not a production-model replacement. Actual future attempt volume does not weight the estimates. Observed lineup SOG/SAT is derived by summing the actual members' SOG and SAT. A team's identity only groups game results for display; it does not supply predictive history.

Pregame context is updated before each game, and the summed estimated/actual counts are evaluated over games 1–20, 2–21 and 3–22. Thus this is a sequence of pregame estimates summarized in 20-game windows, **not one frozen 20-game-ahead forecast**. Actual historical lineups are supplied for the check; advance lineup forecasting is not being evaluated.

### Coverage: no missing players silently discarded

Applying the earlier player-study requirement of 100 Train attempts plus 20 prior measured appearances to every lineup member left **zero fully covered 20-game windows**. That player-cohort filter cannot be treated as a complete lineup.

A second, explicitly broader comparison allows any positive measured Train attempts and 1–20 prior measured appearances with positive attempts. It does not invent Train for players with none. Require every boxscore skater with positive game TOI to have measured positive EV exposure and both individual rates. Reject a whole window if any member is unavailable in any included game.

Of 96 possible windows across 32 teams, **20 complete windows across seven teams** remain: CBJ, CHI, DAL, LAK, PHI, WPG and WSH. WPG has two complete origins; the others have three. These seven teams are a selected complete-history subset, not a league-wide validation.

All individual estimated rates contributing to these scored windows were within 0–100. Outside this scored subset, the unbounded league-adjustment formula can produce negative rates for extremely small histories. It must not be promoted to production without explicit bounds and missing/small-history handling; no such production changes were made here.

Florida is excluded because J. Devine (`8483433`) appears three times in the first 22 games without Train history. No independent Florida history, league replacement or dropped-player shortcut was used to fill that gap. Covering all lineups requires a separately established missing-history rule.

### Results on complete lineups

Compute each window's relative rate error, average the eligible windows within each team, then give each team equal weight. Percentages within thresholds refer to each team's average error.

| Method applied to individual members | Average relative lineup error | Teams within 10% | Teams within 20% |
| --- | ---: | ---: | ---: |
| Train only | 4.65% | 85.71% | 100% |
| League-adjusted Train | 3.75% | 100% | 100% |
| 70/30 blend | 3.07% | 100% | 100% |
| Last 20 only | 1.66% | 100% | 100% |

First-window examples (rates are SOG per 100 attempts; estimates aggregate the individual pregame estimates throughout games 1–20):

| Lineup collection | Train-based estimate | Adjusted-Train estimate | 70/30 estimate | L20 estimate | Actual G1–20 | 70/30 relative error |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| CBJ | 49.49 | 46.79 | 46.95 | 47.32 | 47.94 | 2.06% |
| CHI | 48.00 | 45.32 | 45.59 | 46.23 | 48.77 | 6.52% |
| DAL | 48.11 | 45.50 | 46.17 | 47.75 | 49.69 | 7.07% |
| LAK | 48.44 | 45.86 | 45.88 | 45.93 | 45.50 | 0.83% |
| PHI | 48.40 | 45.84 | 46.10 | 46.68 | 47.55 | 3.06% |
| WPG | 50.16 | 47.62 | 47.66 | 47.74 | 47.74 | 0.16% |
| WSH | 50.18 | 47.46 | 46.77 | 45.14 | 45.01 | 3.91% |

**Interpretation:** On the complete-lineup subset, summed individual L20 estimates were closer than the 70/30 blend. The old claim that the blend was best for teams does not survive as a claim about this pathway. The sample, history eligibility, volume weighting and update timing differ from section 11, so do not interpret changes between those sections as a controlled measurement of the lineup-first rule alone.

No live prediction code, model data, migrations or automated tests were changed or executed. Remaining limitation: an approved missing-history pathway is needed before this comparison can include all actual lineups.

## 13. Exact lineup matching for Train, pregame and Test columns

### Construction and checks

For **every Test game**, take all non-goalie boxscore players with positive recorded total ice time. Fetch each of those players' Train, last-season and prior-appearance windows. Recent windows exclude the current appearance; Train remains S1–S3 and last season remains S3. No opening-lineup snapshot is carried across subsequent games.

For each player and each window:

```text
SAT/60 = summed qualifying attempts × 3,600 / summed EV seconds
SOG/60 = summed qualifying shots on goal × 3,600 / summed EV seconds

lineup/window rate = 100 × sum of member SOG/60 / sum of member SAT/60
```

Test uses the same members' actual game SOG/60 and SAT/60. A measured zero-attempt appearance contributes zero attempts, not a missing-player exclusion. Missing exposure/history remains missing; no league or team rate is substituted.

This preserves the original sum-of-individual-/60 convention. It is not conventional team-clock SAT/60, and it is not the ratio of pooled raw season SOG and SAT. Those alternative weightings must not be mixed into this table.

Aggregate the component player rates across the same included Test lineup-games before calculating each displayed ratio. Thus Train and all recent columns describe the full matched set of appearances, not only pre-G1 context. Block columns follow original team game numbers, not renumbered surviving games.

Require complete data for **every member and every displayed reference** in a lineup-game. A missing player removes that whole game from all columns of this common-cohort comparison, not just from one side. This intentionally strict cohort is necessary to make the displayed comparisons directly aligned, but introduces selection limitations.

Identity and chronology checks for the displayed lineup collections found zero duplicate members and zero recent-window timestamps at or after the target game. There were no missing Test EV records in those collections. Missing Train/last-season/recent history is the reason games are excluded.

### Aligned results

Rates are SOG per 100 attempts. Each block cell shows `rate (included games)`. TEST is the matched subset, **not the full-season rate**. League average pools the same construction across all eligible NHL lineup-games, not just the displayed rows.

| Lineup | Included games | TRAIN | L5 | L10 | L20 | LSEASON | 1–20 | 21–40 | 41–60 | 61–82 | TEST | TRAIN → TEST change | LSEASON → TEST change |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | --- | --- | --- | --- | ---: | ---: | ---: |
| CBJ | 76/82 | 50.30 | 47.55 | 47.60 | 47.54 | 48.13 | 48.17 (19) | 46.87 (16) | 46.44 (19) | 48.05 (22) | 47.42 | −5.73% | −1.48% |
| CHI | 41/82 | 46.39 | 46.92 | 46.97 | 46.90 | 44.77 | 48.82 (20) | 47.67 (11) | 45.93 (8) | 42.31 (2) | 47.69 | +2.80% | +6.52% |
| DAL | 37/82 | 48.76 | 48.65 | 48.81 | 49.06 | 46.05 | 48.63 (6) | 50.33 (18) | 44.77 (9) | 45.72 (4) | 48.18 | −1.19% | +4.63% |
| FLA | 24/82 | 48.97 | 44.42 | 44.74 | 45.34 | 46.31 | 45.90 (19) | 54.23 (2) | 46.48 (1) | 42.75 (2) | 46.43 | −5.19% | +0.26% |
| LAK | 59/82 | 49.04 | 43.56 | 43.65 | 44.22 | 46.00 | 45.39 (20) | 42.60 (20) | 41.76 (19) | — (0) | 43.27 | −11.77% | −5.93% |
| PHI | 32/82 | 49.11 | 48.10 | 48.40 | 47.81 | 46.88 | 47.92 (20) | 50.29 (10) | 36.75 (2) | — (0) | 47.92 | −2.42% | +2.22% |
| WPG | 0/82 | — | — | — | — | — | — (0) | — (0) | — (0) | — (0) | — | — | — |
| WSH | 66/82 | 50.26 | 47.36 | 47.33 | 47.12 | 51.00 | 45.42 (20) | 48.00 (18) | 48.45 (20) | 45.77 (8) | 47.03 | −6.43% | −7.78% |
| League average | 648 lineup-games | 49.28 | 46.89 | 47.01 | 47.00 | 47.34 | 46.62 (261) | 47.57 (143) | 46.08 (129) | 46.84 (115) | 46.76 | −5.11% | −1.23% |

Both change columns are calculated **directly from the displayed rounded values**, using the original reference as denominator and retaining direction:

```text
TRAIN → TEST change = (TEST − TRAIN) / TRAIN × 100
LSEASON → TEST change = (TEST − LSEASON) / LSEASON × 100
```

For DAL: `(48.18 − 48.76) / 48.76 × 100 = −1.19%`. Test is lower than Train. This is not an average of hidden game-level errors or the error of the 70/30 formula. Earlier sections retain their explicitly labeled research metrics; they must not be substituted for this requested percentage-change convention.

The small block counts are material: Florida's 41–60 value represents one matched game, not a 20-game average. Do not infer block stability from such cells. WPG has no common-cohort games with every requested reference available; showing a full-history value would require dropping players or inventing history. Neither was done.

Full-season, all-lineup comparisons remain unavailable without an explicit missing-history policy. No fallback was assumed and no production code/data was changed.
