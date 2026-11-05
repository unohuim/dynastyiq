# Pregame builder: captured metrics and required corrections

Production audit: November 1–2, 2026. Model: **Sep2026**, model ID **1**.

This is an evidence inventory and repair checklist, not a change to prediction
formulas. Architecture authority remains
[NhlPregameContextEvidence.yaml](architecture/stats/NhlPregameContextEvidence.yaml)
and [NhlNextGameEvaluation.yaml](architecture/stats/NhlNextGameEvaluation.yaml).
Migrations remain the schema authority.

## Audited population

- Train: 2022–23, 2023–24, 2024–25.
- Test: 2025–26 regular season.
- Completed context run: **12**, using `pregame_context_v2`.
- Test coverage: **1,312 games**, **2,624 team-game snapshots**, and
  **52,475 player-game snapshots**. The player total includes goalie records;
  it is not a skater-only population.
- The full four-season run reports 5,151 completed and 97 blocked games out of
  5,248. All Test games have team snapshots; the blocked games are outside Test.
- Older `pregame_context_v1` records also exist. Do not pool them with run 12
  or count them as additional observations of the same game.

“Completed” currently means the work was processed. It does **not** certify that
every requested metric is present or correct.

## What “usable” means here

A captured metric can support a retrospective relationship analysis when its
source history, exposure and denominator are available. Presence alone does not
establish predictive usefulness, a causal effect, or an improvement in winner
accuracy. Compare candidate windows on identical eligible observations.

All historical inputs must precede the target game. Missing history remains
unavailable, rather than zero. League comparison validity also depends on the
peer-population correction described below.

## Successfully captured team metrics

The builder stores the following team totals and rates, with each side kept
separate:

| Metric family | Captured values |
|---|---|
| Samples and record | Historical games, wins, win percentage |
| Scoring | Goals for, goals against, goals for/game, goals against/game |
| Shot attempts | SAT for, SAT against, SAT for/game, SAT against/game |
| Possession | Corsi percentage |
| Shots | SOG for, SOG against |
| Conversion | SOG/SAT percentage, shooting percentage |
| Prevention and finishing | Save percentage, PDO |

The team windows are Last Game, L5, L10, L20, LSeason, Train and season-to-date.
The builder also stores head-to-head windows against the upcoming opponent.
Those head-to-head values are **not** the opponent's own recent form. Read the
opponent's separate team snapshot for that comparison.

### Verified Test coverage

These are available entries out of **2,624 team-games**, not prediction P10/P20.

| Metric/window | Available | Missing |
|---|---:|---:|
| Last Game team scoring, SOG, SAT, Corsi, SH% and PDO | 2,623 | 1 |
| L5 team scoring, SOG, SAT, Corsi, SH% and PDO | 2,623 | 1 |
| L10 team scoring, SOG, SAT, Corsi, SH% and PDO | 2,623 | 1 |
| L20 team scoring, SOG, SAT, Corsi, SH% and PDO | 2,623 | 1 |
| LSeason team scoring, SOG, SAT, Corsi, SH% and PDO | 2,542 | 82 |
| Season-to-date team scoring, SOG, SAT, Corsi, SH% and PDO | 2,592 | 32 |
| Train team scoring, SOG, SH% and PDO | 2,542 | 82 |
| Train SAT and Corsi | 1,722 | 902 |

Recent team history is usable on its covered observations. LSeason and Train
comparisons require explicit missing-history handling; the available cohort is
smaller. Season-to-date is naturally unavailable before a team's first game.

## Successfully captured player metrics

The builder stores these player metric families:

| Scope | Captured values |
|---|---|
| All strengths | Games, TOI seconds, individual SAT, SOG, goals, assists, points, SAT/60, SOG/60, goals/60, SH% |
| EV, PP and PK | Individual SAT, SOG, goals, matching-strength TOI, per-60 rates and SH% |
| On-ice EV, PP and PK | Corsi for/against, Corsi percentage, Corsi for/against per 60, on-ice SH%, on-ice save%, PDO and IPP |
| Evidence coverage | Historical game counts, missing individual-source games and usable-game counts where applicable |

Player windows include Last Game, L5, L10, L20, LSeason, Train and season-to-date,
with venue and head-to-head variants where implemented. Player snapshots link
to their own team snapshot for that game.

The code supports player head-to-head L5/L10/L20 and season-to-date, plus Train
and LSeason head-to-head windows. Opposing players' own histories must still be
read from those players' records, not from the shooter's head-to-head fields.

Player source completeness varies. The audit verified a retrospective
all-strength comparison population of **47,230 skater-game results**; it did not
certify every player/window/strength combination. Individual source availability
and valid exposure must be checked before using an EV, PP or PK value.

TOI/game, SAT/game, SOG/game and goals/game can be derived from the corresponding
window totals and game count. That does not mean every derived measure, its
league average and its rank have been persisted. TOI share likewise needs a
matched lineup denominator; it is not interchangeable with TOI/game.

These general context totals must not replace the custom engine's separate
strength-specific, model-retained SAT bucket evidence.

## Successfully captured schedule context

| Field | Test coverage | Assessment |
|---|---:|---|
| Home/away | 2,624/2,624 | Available |
| B2B flag | 2,624/2,624 | Populated; check source-history availability before interpreting a false value |
| Third/fourth-in-seven and B2B-fourth flags | 2,624/2,624 | Populated; use their documented target-inclusive definitions |
| Recent four-day/seven-day game counts | Stored | Their exact boundaries must remain explicit |
| Own rest days | 2,623/2,624 | Available, but season-opening values can include the offseason |
| Opponent rest days | 2,623/2,624 | Same season-opening limitation |
| Days on road | 2,621/2,624 | Present but **requires correction** |
| Kilometres since previous game | 0/2,624 | **Unavailable** |
| Miles since previous game | Unavailable with the kilometre calculation | **Requires rebuilding** |
| Kilometres over the recent seven-day route | Unavailable with the coordinate inputs | **Requires rebuilding** |

## League averages, differences and ranks

Normalized metric tables contain league averages, signed and absolute
differences, percentage differences, league ranks and population sizes for
available values. These are not stored solely in the snapshots' `ranks` JSON.

Their presence was verified in production. However, one audited L20 team Corsi
reference had a **34-team population**. The peer selection includes historical
team identities, so this is not automatically a valid current 32-team league
comparison. Rankings and league averages require a population audit and rebuild
before being treated as current-league benchmarks.

## Items requiring correction or rebuilding

| Item | Verified cause or limitation | Required work |
|---|---|---|
| Travel kilometres/miles | Production `nhl_arena_locations` has **zero rows**. `travelMetrics()` returns null when coordinates are unavailable. A separate `NhlArenaLocationSeeder` exists; the builder does not populate or validate this prerequisite. | Populate reviewed, date-appropriate venue references, validate coverage, then rebuild affected travel metrics and comparisons. Account explicitly for historical team IDs and non-home-arena venues. |
| Days on road | `daysOnRoad()` walks backward through away games until a home game, without breaking at a season boundary or long gap. Twelve Test entries exceed 30 days; the maximum is **181**. | Define and enforce road-sequence breaks, then recalculate. Keep the result labelled as a schedule-derived proxy, not verified physical travel. |
| Missing road days | Utah ID 68 has no earlier home-game anchor for its away games on October 9, 11 and 13, 2025. | Handle the beginning of a team's history explicitly; do not silently borrow another team's history. |
| Opening-season rest | The previous regular-season game can be in April, producing roughly 175 rest days in October. | Distinguish offseason gaps from ordinary in-season rest before applying fatigue comparisons. |
| Train SAT/Corsi | `teamWindowMetrics()` withholds SAT-derived window values when any contributing historical game lacks the required attempt facts. Nine Train games currently have no shot facts at all; further team-level coverage gaps require checking. | Repair source coverage, identify all affected windows, and rebuild their metrics and league comparisons. Do not replace missing games with zero attempts. |
| Missing Train/LSeason history | 82 Test team-game entries lack these team histories. Team identities change across seasons. | Audit identity continuity and approve any historical mapping explicitly; preserve genuine missing history. |
| Quarter windows | Player `q1_to_date` through `q4_to_date` use personal appearance slices. | Assign the target quarter from the team's season-game number, then aggregate that player's appearances within those team-quarter boundaries. Rebuild quarter evidence. |
| League populations | Historical team IDs remain eligible in peer selection; a 34-team reference was observed. | Define the eligible league population at each cutoff, resolve identity continuity, and rebuild averages, deltas and ranks. Audit player populations separately. |
| Completion validation | Missing coordinates and null requested metrics do not prevent a completed run. | Add prerequisite and post-build coverage checks that distinguish usable completion from partial or unavailable evidence. |

## Requested metrics not yet implemented in this builder

These are omissions from the current builder's metric-producing functions, not
proof that the underlying results are absent from the database:

- Team PP opportunities, PP success and opportunity duration histories.
- Team PP opportunity share and PP TOI share histories.
- Player penalty, hit and block histories.
- Dedicated xGF/xGA histories and dedicated goalie GSAx histories.
- The complete requested family of per-game/per-60 measures and corresponding
  league comparisons for every metric; some rates are stored while others can
  only be derived from totals and exposure.

Add each approved metric from its canonical result source, with explicit window,
strength, coverage and cutoff semantics. Reuse existing valid snapshots and
rebuild affected values through scoped upserts rather than discarding the
entire context population.

## What this audit does not establish

- That all stored player values or all league comparisons are valid.
- That a statistically associated context factor improves the combined engine.
- That the custom prediction algorithm has been rerun or its former win rate
  reproduced.
- That every nullable metric represents a bug: absent history, zero denominators
  and genuinely unavailable source evidence must remain distinguishable.

No production data was changed during this audit.
