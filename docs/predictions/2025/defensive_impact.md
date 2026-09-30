# Defensive impact — first three nights of 2025–26

**At the current 15% weight, defense changed two winner selections, helping one and hurting one. The overall record stayed 11–10.** Its average absolute effect was **0.070 expected goals per team**, while it reduced aggregate expected goals by **2.398**, SOG by **35.100**, and SAT by **49.620**, compared with offense only.

The defense-only experiment went **8–13**, despite producing an aggregate goal total much closer to actual. Its team goal MAE was worse. Close totals therefore did not translate into better game outcomes.

A key finding from the code trace: **defensive xSATa supplies the defensive contribution to the final score. Defensive xGA is not independently blended into that score.** The current SAT-model path changes bucket attempt volume, applies offensive conversion rates, and then applies goalie skill.

## Comparison setup

The existing **85% offense / 15% defense** outputs were reused without rerunning the baseline. Only two new sets of 21 predictions were generated:

- **Offense only:** 100% offensive bucket attempt volume, 0% defensive bucket attempt volume.
- **Defense only:** 0% offensive bucket attempt volume, 100% opposing defensive bucket attempt volume.

Both experiments called the shared `NhlGamePredictionPayload::build` entry point with **Sep2026, run 1**, trained on 2022–23 through 2024–25, against October 7–9, 2025. The only experimental override was the weight passed to the existing bucket-environment calculation, scoped to the local calculation process. No production engine files, default weights, configuration, or stored model data were changed.

For every prediction, the selected-model context, offensive roster inputs, defensive roster inputs and starting-goalie identities were compared with the saved baseline and matched. Offensive conversion and goalie-response formulas remained unchanged. Goalie input confidence can change naturally when the projected mix of attempts changes; it was not replaced with a constant.

“Defense only” means **defense supplies all attempt volume and bucket distribution**. It still uses the opposing offensive roster's conversion rates where available, training-bucket averages otherwise, and the same goalie response. It is not a standalone forecast made from defensive xGA alone.

## Winner records

| Scenario           | Oct 7 (3 games) | Oct 8 (4 games) | Oct 9 (14 games) | Overall W–L | Correct |
| ------------------ | --------------- | --------------- | ---------------- | ----------- | ------- |
| Current 85/15      |             3–0 |             1–3 |              7–7 |       11–10 |   52.4% |
| Offense only 100/0 |             3–0 |             0–4 |              8–6 |       11–10 |   52.4% |
| Defense only 0/100 |             1–2 |             2–2 |              5–9 |        8–13 |   38.1% |

Winner records use the official result, including overtime and shootouts. The selected winner comes from the engine's returned pick, before display rounding.

## Aggregate predictions and error

All totals cover **42 team performances**. Delta is prediction minus actual; MAE averages the absolute error across those 42 observations. Goals include regulation, overtime and empty-net goals but exclude the two shootout standings goals. Actual hockey goals total **122**; official scoreboard goals total **124**.

### Goals

| Scenario           | Predicted total | Actual total |   Delta | MAE / team |
| ------------------ | --------------- | ------------ | ------- | ---------- |
| Current 85/15      |         134.856 |          122 | +12.856 |      1.385 |
| Offense only 100/0 |         137.254 |          122 | +15.254 |      1.381 |
| Defense only 0/100 |         121.285 |          122 |  -0.715 |      1.441 |

### SOG

| Scenario           | Predicted total | Actual total |    Delta | MAE / team |
| ------------------ | --------------- | ------------ | -------- | ---------- |
| Current 85/15      |        1361.780 |         1207 | +154.780 |      5.678 |
| Offense only 100/0 |        1396.880 |         1207 | +189.880 |      5.989 |
| Defense only 0/100 |        1162.970 |         1207 |  -44.030 |      5.051 |

### SAT

| Scenario           | Predicted total | Actual total |    Delta | MAE / team |
| ------------------ | --------------- | ------------ | -------- | ---------- |
| Current 85/15      |        2027.980 |         2479 | -451.020 |     12.260 |
| Offense only 100/0 |        2077.600 |         2479 | -401.400 |     11.582 |
| Defense only 0/100 |        1746.750 |         2479 | -732.250 |     17.909 |

### xGA before goalie

| Scenario           | Predicted total | Actual total |   Delta | MAE / team |
| ------------------ | --------------- | ------------ | ------- | ---------- |
| Current 85/15      |         135.445 |          122 | +13.445 |      1.367 |
| Offense only 100/0 |         137.944 |          122 | +15.944 |      1.363 |
| Defense only 0/100 |         121.286 |          122 |  -0.714 |      1.420 |

Before-goalie xGA is the opponent's expected scoring before individual goalie skill, compared with realized goals against. Across all teams, the same predictions and outcomes appear on opposite sides of each game, so the table uses that equivalent aggregate pairing. This is not a comparison with measured “actual xGA.” After-goalie expected GA totals and MAE exactly mirror the Goals table.

The current 15% defensive contribution, relative to offense only:

- **Goals:** MAE changed from **1.381 to 1.385**, slightly worse (**+0.003** before display rounding). Total goal overprediction fell from **15.254 to 12.856**.
- **SOG:** MAE improved from **5.989 to 5.678** (**−0.311**), and total overprediction fell from **189.880 to 154.780**.
- **All-event SAT:** MAE worsened from **11.582 to 12.260** (**+0.677**), as already-low attempt totals fell further.

These changes are descriptive results from 21 games, not evidence that a particular weight is optimal.

## How much did adding 15% defense move each prediction?

This section compares **current 85/15 minus offense only 100/0**, measuring the contribution that is actually present in the saved predictions.

| Metric            | Total change | Mean change / team | Mean absolute change | Largest absolute change | Teams lower | Teams higher |
| ----------------- | ------------ | ------------------ | -------------------- | ----------------------- | ----------- | ------------ |
| SAT               |      -49.620 |             -1.181 |                1.181 |                   2.150 |          42 |            0 |
| SOG               |      -35.100 |             -0.836 |                0.836 |                   1.490 |          42 |            0 |
| xGA before goalie |       -2.499 |             -0.060 |                0.070 |                   0.153 |          36 |            6 |
| Goals             |       -2.398 |             -0.057 |                0.070 |                   0.150 |          34 |            8 |

SAT and SOG decreased for all 42 team performances. Expected goals nevertheless increased for eight teams because defense changes the **distribution of attempts across buckets**, not just the total number of attempts. Fewer total attempts can still produce more expected goals when the retained mix is more dangerous against the selected goalie.

The largest absolute final-goal change was **Carolina against New Jersey: −0.150 goals**. That magnitude is small, but small changes can reverse a winner selection when the original goal margin is very narrow.

### The two winner changes caused by the current defensive contribution

| Game       | Offense-only score (pick) | 85/15 score (pick) | Actual winner | Effect of adding defense |
| ---------- | ------------------------- | ------------------ | ------------- | ------------------------ |
| MTL at TOR |         3.477–3.415 (MTL) |  3.404–3.422 (TOR) |           TOR |                   Helped |
| MIN at STL |         3.324–3.275 (MIN) |  3.223–3.225 (STL) |           MIN |                     Hurt |

For Montreal–Toronto, defense corrected an offense-only Montreal pick to Toronto. For Minnesota–St. Louis, it changed a correct Minnesota pick to St. Louis. The latter baseline edge was only **0.0021 goals**. The two flips canceled out in the overall record.

## What happened in the defense-only experiment?

Defense only predicted **121.285 goals against 122 actual**, a net error of just **−0.715**. However, its **1.441-goal team MAE** was worse than both other scenarios, and its record fell to **8–13 (38.1%)**. Large positive and negative errors canceled in the aggregate.

Compared with the saved 85/15 baseline, five winner picks changed: one correction and four newly incorrect picks.

| Game       | 85/15 pick | Defense-only pick | Actual winner |           Change |
| ---------- | ---------- | ----------------- | ------------- | ---------------- |
| CHI at FLA |        FLA |               CHI |           FLA | Became incorrect |
| COL at LAK |        COL |               LAK |           COL | Became incorrect |
| LAK at VGK |        VGK |               LAK |           LAK |        Corrected |
| MTL at DET |        MTL |               DET |           MTL | Became incorrect |
| PHI at FLA |        FLA |               PHI |           FLA | Became incorrect |

Defense only improved SOG MAE but substantially worsened SAT MAE against all recorded attempts. The existing event-definition mismatch is important to that result.

### SAT measured against matching eligible events

The model-eligible actual subset excludes empty-net and unknown-shot-type attempts: **1,765 SAT**, compared with **2,479 full-game SAT**. The same predicted volumes are shown against this narrower target below.

| Scenario           | Predicted SAT | Eligible actual SAT |    Delta | MAE / team |
| ------------------ | ------------- | ------------------- | -------- | ---------- |
| Current 85/15      |      2027.980 |                1765 | +262.980 |      7.492 |
| Offense only 100/0 |      2077.600 |                1765 | +312.600 |      8.254 |
| Defense only 0/100 |      1746.750 |                1765 |  -18.250 |      6.271 |

On that matching subset, adding 15% defense improved SAT MAE from **8.254 to 7.492**, and defense only improved it further to **6.271**. Thus the defensive attempt input helped predict eligible-event volume here, even though it hurt the comparison against all SAT. This does not establish that a defense-only score model is better: its winner record and goal MAE were worse.

## Exactly where defensive xSATa and xGA enter

For a bucket `b`, the current model-backed scoring path is:

```text
Attempts[b] = w × Offensive xSAT[b] + (1 − w) × Opponent defensive xSATa[b]
SOG[b]      = Attempts[b] × Offensive bucket SOG/SAT
xG[b]       = SOG[b] × Offensive bucket goals/SOG
Goals[b]    = xG[b] adjusted by the selected goalie's bucket ability
```

Here `w` is 0.85, 1.00 or 0.00. Offensive conversion uses the selected roster's bucket evidence; buckets without offensive volume use training-bucket averages. The goalie adjustment uses the same historical exact-bucket ability and confidence shrinkage, with the existing bounds on expected goals.

**Defensive xSATa has two effects:** it changes total attempts and shifts their distribution among chance buckets. Those changes carry through offensive conversion and goalie response to final expected goals. The experiment measures those effects together; it does not separate volume from distribution.

**Defensive xGA has no independent direct weight in these final scores.** `NhlSatModelPredictionService::environment()` reads the defensive bucket's attempt volume, but derives adjusted SOG and xG using offensive conversion. It does not calculate `85% offensive xGF + 15% defensive xGA`.

Defensive xGA is still used in defensive summaries and the model's defensive-environment goalie reference values. Those reference values also feed goalie-edge outputs. However, the SAT-model branch of `applyEvGoalieAdjustments()` uses the already-calculated matchup bucket `projected_ga` for final scoring, rather than applying the separate season-reference ratio again. The conclusion about no direct xGA weight is a **code-path finding**, not a separate experiment that altered xGA. It applies to this SAT-model prediction path, not every legacy path in the application.

Relevant implementation:

- [Bucket environment and goalie response](../../../app/Services/NhlSatModelPredictionService.php)
- [Shared matchup simulation and final goalie adjustment](../../../app/Services/NhlProjectedTeamMatchupSimulator.php)
- [Shared prediction entry point and winner selection](../../../app/Services/NhlGamePredictionPayload.php)

## All 21 games

Scores are away–home expected goals, followed by the selected winner. Actual scores include shootout standings goals. Small display-rounding differences do not determine the engine's winner selection.

| Game                                          |       Saved 85/15 |      Offense only |      Defense only |    Actual |
| --------------------------------------------- | ----------------- | ----------------- | ----------------- | --------- |
| [CHI–FLA](2025-10-07/reconcile_2025020001.md) | 3.155–3.303 (FLA) | 3.089–3.384 (FLA) | 3.530–2.851 (CHI) | 2–3 (FLA) |
| [PIT–NYR](2025-10-07/reconcile_2025020002.md) | 3.179–3.105 (PIT) | 3.211–3.160 (PIT) | 2.998–2.790 (PIT) | 3–0 (PIT) |
| [COL–LAK](2025-10-07/reconcile_2025020003.md) | 3.455–3.157 (COL) | 3.583–3.195 (COL) | 2.727–2.945 (LAK) | 4–1 (COL) |
| [MTL–TOR](2025-10-08/reconcile_2025020004.md) | 3.404–3.422 (TOR) | 3.477–3.415 (MTL) | 2.992–3.461 (TOR) | 2–5 (TOR) |
| [BOS–WSH](2025-10-08/reconcile_2025020005.md) | 2.925–3.659 (WSH) | 2.967–3.748 (WSH) | 2.685–3.158 (WSH) | 3–1 (BOS) |
| [CGY–EDM](2025-10-08/reconcile_2025020006.md) | 2.783–3.540 (EDM) | 2.818–3.654 (EDM) | 2.587–2.897 (EDM) | 4–3 (CGY) |
| [LAK–VGK](2025-10-08/reconcile_2025020007.md) | 3.131–3.513 (VGK) | 3.149–3.605 (VGK) | 3.030–2.992 (LAK) | 6–5 (LAK) |
| [CHI–BOS](2025-10-09/reconcile_2025020008.md) | 3.176–2.919 (CHI) | 3.117–2.898 (CHI) | 3.515–3.038 (CHI) | 3–4 (BOS) |
| [NYR–BUF](2025-10-09/reconcile_2025020009.md) | 2.889–3.340 (BUF) | 2.910–3.368 (BUF) | 2.767–3.185 (BUF) | 4–0 (NYR) |
| [MTL–DET](2025-10-09/reconcile_2025020010.md) | 3.447–3.279 (MTL) | 3.518–3.278 (MTL) | 3.048–3.290 (DET) | 5–1 (MTL) |
| [OTT–TBL](2025-10-09/reconcile_2025020011.md) | 2.571–3.395 (TBL) | 2.674–3.510 (TBL) | 1.990–2.748 (TBL) | 5–4 (OTT) |
| [PHI–FLA](2025-10-09/reconcile_2025020012.md) | 2.889–3.324 (FLA) | 2.858–3.473 (FLA) | 3.064–2.481 (PHI) | 1–2 (FLA) |
| [NYI–PIT](2025-10-09/reconcile_2025020013.md) | 2.917–3.223 (PIT) | 3.013–3.255 (PIT) | 2.370–3.041 (PIT) | 3–4 (PIT) |
| [NJD–CAR](2025-10-09/reconcile_2025020014.md) | 3.275–3.184 (NJD) | 3.409–3.334 (NJD) | 2.517–2.331 (NJD) | 3–6 (CAR) |
| [MIN–STL](2025-10-09/reconcile_2025020015.md) | 3.223–3.225 (STL) | 3.324–3.275 (MIN) | 2.657–2.948 (STL) | 5–0 (MIN) |
| [CBJ–NSH](2025-10-09/reconcile_2025020016.md) | 3.335–3.112 (CBJ) | 3.441–3.184 (CBJ) | 2.729–2.704 (CBJ) | 1–2 (NSH) |
| [DAL–WPG](2025-10-09/reconcile_2025020017.md) | 3.370–3.539 (WPG) | 3.486–3.508 (WPG) | 2.711–3.715 (WPG) | 5–4 (DAL) |
| [UTA–COL](2025-10-09/reconcile_2025020018.md) | 3.511–3.522 (COL) | 3.612–3.618 (COL) | 2.931–2.977 (COL) | 1–2 (COL) |
| [CGY–VAN](2025-10-09/reconcile_2025020019.md) | 2.766–3.081 (VAN) | 2.819–3.143 (VAN) | 2.470–2.732 (VAN) | 1–5 (VAN) |
| [VGK–SJS](2025-10-09/reconcile_2025020020.md) | 3.519–2.849 (VGK) | 3.606–2.916 (VGK) | 3.030–2.467 (VGK) | 4–3 (VGK) |
| [ANA–SEA](2025-10-09/reconcile_2025020021.md) | 3.051–3.195 (SEA) | 3.127–3.130 (SEA) | 2.621–3.568 (SEA) | 1–3 (SEA) |

## Interpretation

The current defensive contribution was **modest in score magnitude but capable of changing close winner picks**. In this sample it improved SOG and eligible-SAT errors, reduced aggregate goal overprediction, and left the overall winner record unchanged. It did not improve per-team goal MAE. The extreme defense-only run produced a tempting near-perfect aggregate goal total but worse game selection and goal accuracy.

These results do not justify choosing a new production weight from this sample. They support evaluating defense with consistent SAT definitions and measuring both winner accuracy and per-team error, rather than judging it by aggregate goal totals alone. They also clarify that the current scoring contribution is based on **defensive attempt buckets**, not a separately blended defensive xGA estimate.

The same limitations as the original exercise remain: known boxscore participants and starters, a later-built retrospective model, 21 games, approximate five-skater defensive normalization, and no separate simulation of empty-net, overtime, shootout or relief-goalie phases. Actual goals against are not observed expected goals against, and score errors alone cannot establish causal defensive talent.

The saved baseline also agrees with the 85/15 interpolation of the two new endpoint runs to the engine's rounding precision (maximum differences below 0.01 SAT/SOG and 0.001 goals per team). No third baseline run was made. Database access was read-only and provider HTTP was prohibited. No imports, model builds, automated test suites, or production formula changes were performed.

Related analysis: [three-night summary](3gamessummary.md).

Awaiting human review.
