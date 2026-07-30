# 2025-26 Shot Attempts Model Analysis

Source CSV: `data/shot_attempts_model_input_20252026.csv`

Sample:

- Rows: 124,447
- Train rows: 99,732
- Test rows: 24,715
- Target: `is_goal`
- Sample filter: classified shot types only, non-empty-net, non-shootout

First-pass dependency-free logistic model:

- Test log loss: 0.22855
- Test AUC: 0.7164

Strong controlled effects:

- Distance is dominant. One standard deviation farther from the net, 24.42 feet, had odds ratio 0.312.
- Center-lane shots are much more dangerous than side-lane shots. Left-side odds ratio was 0.584 and right-side odds ratio was 0.511 versus center.
- Power play increased controlled odds, odds ratio 1.365.
- Delayed penalty as previous event was the strongest event-context signal, odds ratio 2.286.
- Snap and slap shots became positive after controlling for distance and side, odds ratios 1.573 and 1.590 versus wrist.
- Off-wing side-lane attempts had positive controlled offsets, but side-lane penalties remained large, so off-wing should be treated as an interaction feature rather than a standalone danger label.

Model caveats:

- Raw rebound attempts scored more often than non-rebounds, but the isolated rebound coefficient turned negative after controlling for distance, side, and previous-event context. This means rebound value is being absorbed by location/timing/context and should be modeled as interactions, not only as a single global flag.
- The top prediction decile was overconfident: predicted 19.87 percent, actual 15.39 percent. Calibration smoothing is needed before production xG use.

Highest observed high-volume combinations:

| Goal % | Attempts | Goals | Context |
| ---: | ---: | ---: | --- |
| 17.47 | 435 | 76 | 5-10 ft wrist rebound, PP, center |
| 17.05 | 516 | 88 | 15-20 ft snap, EV, center |
| 16.89 | 1,758 | 297 | 5-10 ft wrist, EV, center |
| 16.38 | 635 | 104 | 5-10 ft snap, EV, center |
| 16.32 | 429 | 70 | 25-30 ft snap, EV, center |
| 16.31 | 1,183 | 193 | 10-15 ft wrist, EV, center |
| 16.28 | 639 | 104 | 10-15 ft snap, EV, center |
| 16.00 | 500 | 80 | 5-10 ft deflection, EV, center |

Recommended first xG feature set:

- `shot_distance`
- `abs_shot_angle`
- `shot_type_bucket`
- `shot_side`
- `strength_bucket`
- `previous_event_type`
- `previous_event_seconds_delta`
- `rebound_bucket` as interaction with distance and previous event
- `rush_bucket` as interaction with rebound and distance
- `is_off_wing_attempt` as interaction with shot type, side, and strength

