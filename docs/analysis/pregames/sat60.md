# EV SAT/60 — Train, pregame context and Test

Research snapshot: 8 October 2026. Local DynastyIQ data, Sep2026 research.

**Metric:** EV shot attempts per 60 minutes, retaining buckets with **confidence ≥50%**, including exactly 50%. Not all buckets.

**Train:** 2022–23 through 2024–25. **LSEASON:** 2024–25. **Test:** 2025–26 regular season.

## Performance

For every included Test game, the exact same players supply its Train, pregame and Test values. Recent windows stop before that game. Lineups are collections of player rates, never independently modeled team entities.

| Player / lineup | TRAIN | L5 | L10 | L20 | LSEASON | Q1 | Q2 | Q3 | Q4 | TEST | ET | ELS |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Forsberg | 21.84 | 19.41 | 19.30 | 19.35 | 21.15 | 19.27 | 20.63 | 19.40 | 20.77 | 20.04 | −8.24% | −5.25% |
| McDavid | 14.18 | 14.23 | 14.12 | 14.12 | 13.33 | 13.51 | 17.09 | 14.95 | 12.17 | 14.38 | +1.41% | +7.88% |
| Pastrnak | 22.02 | 18.42 | 18.66 | 18.81 | 20.44 | 20.72 | 19.52 | 18.84 | 14.51 | 18.55 | −15.76% | −9.25% |
| Coleman | 15.81 | 15.44 | 15.36 | 15.34 | 15.85 | 15.85 | 14.80 | 15.59 | 15.93 | 15.48 | −2.09% | −2.33% |
| Matthews | 21.56 | 17.99 | 17.90 | 17.92 | 19.46 | 18.44 | 17.57 | 17.57 | — | 17.86 | −17.16% | −8.22% |
| **League player average** | **11.90** | **11.48** | **11.50** | **11.54** | **11.86** | **11.22** | **11.41** | **11.60** | **11.65** | **11.43** | **−3.95%** | **−3.63%** |
| CBJ lineup | 214.22 | 214.08 | 215.16 | 217.03 | 214.53 | 223.00 | 213.76 | 218.23 | 200.89 | 213.46 | −0.35% | −0.50% |
| CHI lineup | 195.20 | 179.25 | 181.36 | 182.77 | 190.28 | 183.15 | 199.81 | 152.69 | 181.64 | 181.60 | −6.97% | −4.56% |
| DAL lineup | 208.07 | 175.74 | 178.02 | 179.06 | 195.15 | 168.54 | 178.19 | 180.71 | 182.10 | 177.66 | −14.62% | −8.96% |
| FLA lineup | 232.52 | 218.33 | 229.32 | 234.65 | 238.73 | 214.89 | 233.45 | 268.02 | 200.22 | 217.43 | −6.49% | −8.92% |
| LAK lineup | 221.29 | 221.60 | 221.22 | 220.07 | 219.51 | 220.22 | 219.88 | 222.72 | — | 220.91 | −0.17% | +0.64% |
| PHI lineup | 206.15 | 181.41 | 183.13 | 186.22 | 203.81 | 180.27 | 179.11 | 190.06 | — | 180.52 | −12.43% | −11.43% |
| WPG lineup | — | — | — | — | — | — | — | — | — | — | — | — |
| WSH lineup | 207.55 | 215.05 | 214.80 | 213.96 | 219.01 | 233.54 | 208.92 | 208.73 | 197.30 | 214.91 | +3.55% | −1.87% |
| **League lineup average** | **211.86** | **205.68** | **206.07** | **206.41** | **211.54** | **205.03** | **202.92** | **205.32** | **208.55** | **205.25** | **−3.12%** | **−2.97%** |

## Player error thresholds

These percentages cover **all eligible players**, not just the five named examples and not lineup totals. Each player counts once.

| Comparison | Eligible players | Players <10% error | Players <20% error |
| --- | ---: | ---: | ---: |
| ET | 789 | **40.43%** | **70.47%** |
| ELS | 787 | **41.42%** | **68.61%** |

Counts: ET has 319 players below 10% and 556 below 20%; ELS has 326 below 10% and 540 below 20%.

The matched player population contains 791 players and 44,064 appearances. Two players have zero TRAIN and four have zero LSEASON; their respective percentage changes are undefined and excluded from that threshold denominator. They are not silently assigned zero error.

## Standard columns and calculations

Use this performance-column order for subsequent pregame research reports:

**Name → TRAIN → L5 → L10 → L20 → LSEASON → Q1 → Q2 → Q3 → Q4 → TEST → ET → ELS**

- **TRAIN:** the selected player's measured S1–S3 rate. For a lineup, derive it from that Test game's exact players.
- **L5, L10, L20:** each player's preceding measured appearances, updated before every Test game and averaged over the included comparison games. These are not frozen opening-night inputs.
- **LSEASON:** that player's previous-season rate, assembled from the same Test lineup members.
- **Q1–Q4:** appearances/games 1–20, 21–40, 41–60 and 61–82. Player rows follow player appearance numbers; lineup rows follow their schedule game numbers. Missing games are not renumbered into earlier blocks.
- **TEST:** the average over all included Test appearances/games, not an unweighted average of Q1–Q4.
- **ET:** signed percentage change from the displayed TRAIN to the displayed TEST.
- **ELS:** signed percentage change from the displayed LSEASON to the displayed TEST.

```text
ET  = (TEST − TRAIN) / TRAIN × 100
ELS = (TEST − LSEASON) / LSEASON × 100
```

Positive means Test increased; negative means Test decreased. The original reference is the denominator. For Forsberg, `(20.04 − 21.84) / 21.84 × 100 = −8.24%`.

Use absolute ET/ELS when counting players below the strict `<10%` and `<20%` thresholds. Calculate from the same two-decimal rates used in the display; apply thresholds before rounding the resulting percentage change.

For future reports:

- Keep performance values in the performance columns and comparison percentages in ET/ELS; do not substitute hidden prediction errors into the displayed-value comparisons.
- Include both player-threshold columns: **Players <10% error** and **Players <20% error**, summarized separately for ET and ELS.
- Always show league average as its own row. When players and summed lineups share a table, use separate league player and league lineup rows; their scales differ.
- Do not append game counts in parentheses to performance cells. Preserve coverage separately so incomplete blocks remain identifiable.
- Preserve player/team rows and the established columns when changing formatting; do not silently change the metric, weighting or cohort.

## Measurement and coverage

Source attempts use model 4's level-1 shot-type/distance buckets with confidence at least 0.5. Restrict shot facts to EV, exclude shootouts and empty-net attempts, and retain measured zero-attempt appearances.

Historical player-window SAT/60 pools attempts and EV seconds within that window. Actual Test game SAT/60 uses that game's EV seconds. Reported Test blocks average those game-level rates. Lineup game values sum their members' rates before taking the average across games. This is a sum-of-player-rates index, not conventional team-clock SAT/60.

Earlier player reports pooled attempts and ice time across entire Test blocks. Their values can differ from the average-game-rate values saved here; do not treat those two calculations as interchangeable.

Every included player appearance must have Train, last-season, recent-window and actual EV data. Every included lineup game must meet that requirement for **every** member. Otherwise, exclude the entire lineup game from all comparison columns. No player is silently removed from one side, and no missing history is replaced with zero or independent team history.

Consequently, TEST here is the **matched subset**, not necessarily a complete 82-game season. Full-season conclusions are not justified by sparse blocks.

| Player / lineup | Q1 games | Q2 games | Q3 games | Q4 games | Total |
| --- | ---: | ---: | ---: | ---: | ---: |
| Forsberg | 20 | 20 | 20 | 22 | 82 |
| McDavid | 20 | 20 | 20 | 22 | 82 |
| Pastrnak | 20 | 20 | 20 | 17 | 77 |
| Coleman | 20 | 20 | 20 | 9 | 69 |
| Matthews | 20 | 20 | 20 | 0 | 60 |
| League player sample | 14,183 | 12,440 | 10,569 | 6,872 | 44,064 |
| CBJ lineup | 19 | 16 | 19 | 22 | 76 |
| CHI lineup | 20 | 11 | 8 | 2 | 41 |
| DAL lineup | 6 | 18 | 9 | 4 | 37 |
| FLA lineup | 19 | 2 | 1 | 2 | 24 |
| LAK lineup | 20 | 20 | 19 | 0 | 59 |
| PHI lineup | 20 | 10 | 2 | 0 | 32 |
| WPG lineup | 0 | 0 | 0 | 0 | 0 |
| WSH lineup | 20 | 18 | 20 | 8 | 66 |
| League lineup sample | 261 | 143 | 129 | 115 | 648 |

The league rows include all eligible players/lineups, not just the named examples. League player performance averages eligible player-game rates; player error thresholds instead give each eligible player one vote. WPG has no lineup-games with all requested references available. Florida's Q3 is one matched game, not evidence of a stable 20-game level.

Related research and prior calculation corrections: [Next-game relationship findings](../../testing/next-game-relationship-findings.md).
