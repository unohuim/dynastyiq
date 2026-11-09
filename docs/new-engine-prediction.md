# New game prediction engine: calculation order and baselines

Started November 2, 2026.

This working guide records the ordered stages of the custom engine and the
accuracy evidence available for them. The shared default calculator is
`custom_ev_pp_v3`, used by API, admin, previews, evaluations, the matchup simulator
and `nhl:custom-predict`. Earlier measured research cohorts below remain historical
evidence; they are not measurements of this rollout.

Canonical rules and evidence remain in:

- [NhlNextGameEvaluation.yaml](architecture/stats/NhlNextGameEvaluation.yaml)
- [NhlCustomGamePrediction.yaml](architecture/stats/NhlCustomGamePrediction.yaml)
- [Pregame builder audit](pregame-builder.md)

## High-level calculation order

1. **Select the model and freeze the game's inputs.** Resolve that model's Train
   seasons, retained SAT buckets, pregame cutoff, expected lineups and starting
   goalies. Keep home and away inputs separate.
2. **Load pregame history and context for both sides.** Include player, team and
   opponent histories, source coverage and available league comparisons. Resolve
   the upcoming team-season quarter independently for each team.
3. **Predict each team's PP opportunities.** Allocate the initial game baseline
   using normalized Train opportunity shares, then use 25% of that team baseline
   plus 75% of the opponent's pregame L10 PP opportunities allowed/game.
4. **Convert opportunities into team PP time.** Multiply each team's adjusted
   opportunities by the frozen Train minutes/opportunity reference. Sum the two
   team estimates for total game PP time. Measure opportunity accuracy separately
   from the resulting time accuracy.
5. **Calculate PK and remaining EV time.** Each team's PK time is its opponent's
   PP time. For the regulation-time baseline, each team's EV time is 60 minutes
   minus its own PP and PK minutes. PP, PK and EV time are linked quantities;
   PK is not a third independent time prediction.
6. **Allocate each team's EV and PP time among its players.** Predict player
   usage shares separately by strength, then convert those shares into minutes.
   Keep total team time separate from the allocation among players.
7. **Predict each player's offensive SAT/60 and bucket distribution.** Use that
   player's appropriate historical windows and retained model buckets.
8. **Apply opposing defense and the agreed production context.** Adjust the
   relevant player buckets and strength lanes before calculating final volume.
   Apply the adopted L5 team-win% multiplier to EV SAT in every quarter,
   including quarter-boundary matchups; missing either team's sample is neutral.
9. **Convert player rates and minutes into SAT/game.** Convert each player and
   bucket separately before summing team totals.
10. **Convert SAT into SOG and baseline goals.** Use player-specific bucket
    SOG/SAT from **L20 at EV** and **L5 at PP**. Convert resulting SOG using
    player-bucket **LSeason SH% at EV** and **season-to-date SH% at PP**.
    Preserve separate EV and PP scoring components. PK is a team clock, not
    a short-handed scoring forecast.
11. **Retain the player-bucket goals at the goalie stage.** The goalie multiplier
    is **1.0**. Apply no additional SV%, GSAx or possession-based goal adjustment.
    The November 5 decision below supersedes the former LSeason goalie multiplier.
12. **Resolve final PP goals through the agreed PP allocation pathway.** Scale
    the conditional initial team estimates to the separate 75/25 game total.
    Replace intermediate bucket PP goals with this allocation and retain their
    relative weights; do not add both estimates.
13. **Sum team scores and the game total.** Combine each team's final EV and PP
    goals, then combine the two teams for total game goals.
14. **Calculate winner probabilities and any agreed final adjustment.** Record
    any probability adjustment separately from changes to projected goals.
15. **Calculate confidence separately from win probability.** Preserve its input
    evidence and state any qualification rules explicitly.
16. **Save the prediction and its input history.** Preserve subsequent prediction
    revisions and compare against results only after the game is completed.

The shared calculator uses the documented L10 allocation baseline. The older winner
replay's missing EV season-gap/PDO finishing math is historical evidence, not an
active adjustment. Source rules distinguish adopted formulas, research
candidates and measured results.

## Adopted L5 team-record adjustment

Adopted November 9, 2026, for all quarters and quarter-boundary games. Read each
team's saved pregame L5 win percentage and sample count. The production rule is
owned by [NhlCustomGamePrediction.yaml](architecture/stats/NhlCustomGamePrediction.yaml).
With `d = (home L5 win% - away L5 win%) / 100`, multiply home EV bucket SAT by
`1 + 0.50*d` and away EV bucket SAT by `1 - 0.50*d`. Existing bucket conversion
probabilities carry that volume through to SOG and EV goals. Both sides are
neutral if either L5 sample is unavailable; a measured zero win% remains valid.
No new history read, quarter calculation or model fit is added. PP, player
minutes and the existing defensive/schedule calculations retain their roles.

The supporting Sep2026 2025–26 experiment changed 676/1,290 correct winners
(52.40%) to 697/1,290 (54.03%), adding 21 correct picks. Its 22 source-integrity
exclusions were identical. This was a full-Test-selected exploratory result,
not a new execution of the edited engine or a guaranteed future win rate. The
setting was adopted despite that limitation. The earlier-period-selected Train
alternative did not add winners later and is not the adopted rule.

Full evidence, quarter breakdown and next-factor status:
[pregame-factor-win-impact.md](analysis/pregame-factor-win-impact.md).

## Executable custom replay runner

`NhlCustomGamePredictionEngine` implements the EV + PP calculation independently
of retired engine/stack scoring formulas. `NhlCustomGameForecast` connects existing
prediction entry points to this calculator. `NhlCustomGamePredictionInputs` streams a model's
own Train and Test results in scheduled order, freezes both sides' inputs, then
advances history. `NhlCustomGameDefense` fits only Train evidence;
`NhlCustomGamePredictionReplay` calculates results, reports exclusions, and can
save a new experiment in the existing custom tables. No provider requests,
imports, context rebuilds, queue jobs or legacy predictor calls are made.

The Test replay coefficient horizon is **2022–23 through 2024–25**. Other
Train selections require their own approved calibration for measured replay
claims. The explicit requested-date SAT_V2 exception below records transferred
coefficients rather than implying recalibration. The model supplies its Test season and retained
Train-confidence >= 0.50 bucket mapping. A complete model-owned pregame build is
required, with same-game/cutoff player-to-team context links. The historical
command uses retrospectively known participants; it does not predict lineups.

Operator commands, to run after code review:

```sh
php -d memory_limit=1024M artisan nhl:custom-predict --model=1 --compare-schedule
php -d memory_limit=1024M artisan nhl:custom-predict --model=1 --from=2025-10-01 --to=2025-10-31 --json
php -d memory_limit=1024M artisan nhl:custom-predict --model=1 --compare-schedule --save --json
php -d memory_limit=1024M artisan nhl:custom-predict --model=2 --date=2026-11-05 --json
```

Historical Test replay without `--date` or `--save` only reads sources. `--save` creates new runs and freezes team
and player inputs/results in the existing custom tables; it never overwrites a
preceding experiment. `--compare-schedule` calculates both variants from the
same inputs and uses identical eligible games. Invalid or missing required inputs
produce explicit exclusions in both variants. Partial failures retain their run
status/error rather than pretending the season was predicted successfully.

Requested-date mode (`--date`) predicts the scheduled games regardless of
their state and requires no target shot statistics or final score. It uses the
chosen model's own retained Train buckets and historical seasons, verified
anticipated lineups or the existing projected-roster fallback, and results
strictly before the earlier of request time and scheduled start. Target results
never enter their own statistical history. Finished games use actual participant
identities; upcoming/live games use the anticipated-lineup pathway. Incremental
updates replace previously anticipated context with actual participants after
completion. The roster change does not introduce target statistics into context. Operational requests require saved, matching model/game context for both teams,
selected skaters and starters, including committed comparisons. Missing context
queues the existing pregame pathway for those games. Personal windows also
include completed intervening and current-season games. Requested-date mode uses
the shared operational payload/cache pathway, queues preparation on a cold date,
and automatically saves successful prediction revisions. It prints preparing
rows while dependencies are pending. It cannot be combined with replay range,
schedule comparison or save options. Actual accuracy comparison remains separate.

Shared preparation checkpoints Train collection, bucket fitting, prior-result
pages and individual targets on projections. Requests do not replay whole seasons
synchronously. Lineup, starter, configuration and completed-source publication
changes invalidate pending work before it is published. Ready inputs have
per-game cache entries and a small date manifest. This changes input readiness,
not EV/PP formulas, travel coefficients, coverage rules or measured win rates.
Source freshness compares actual game and summary values rather than polling
or update timestamps. Per-game summary digests are cached and rehashed in bounded
pages only when their source metadata changes. Completed derived-fact import
publication and processed PBP revisions remain invalidators so bucket/strength corrections are picked up.
Routine boxscore scheduling excludes both FINAL and OFF, and unchanged goalie
shutout/decision refreshes preserve their stored timestamps.

Update Pregame now also selects today's unfinished regular-season games in the
model's Test season, or its own prediction season when Test is unset. It uses
the Toronto date and frozen anticipated participants, leaving Train snapshots
outside update scope. Explicit prediction requests can prepare other dates.

The approved SAT_V2 requested-date run uses that model's 2023-24 through
2025-26 Train seasons for its 2026-27 games. The agreed coefficients retain
their original earlier calibration; model metadata explicitly records their
reference seasons and `coefficients_transferred`. No new calibration or
accuracy is claimed by transferring them. Current lineup selection on a past
date is not a historical lineup backtest.

The active schedule rule is **B2B plus exactly fourth-in-seven**: forward SAT
multiplier **0.98**, defenseman SAT multiplier **0.97**, before conversion. A
qualifying away team additionally gives home win probability **+0.0433712599**,
with clamping and complementary away probability. No other schedule or venue
multiplier is introduced. Coefficients and source definitions are frozen per run.

The first explicit defensive normalization alternates multiplicative team
offense/defense rate updates on Train bucket attempts and unioned strength-clock
exposure, using the recorded 150-minute neutral prior. Iteration and convergence
bounds are in `config/nhl-custom-prediction.php`. Recent allowance divides
observed attempts allowed by exposure weighted by frozen Train opponent attack
strength; player historical references use matching exposure and source-rate
contributions. This implements the documented ratio equation deterministically;
it does **not** claim to recover the unavailable earlier fitted matrix or its
win percentage.

Approved defensive fallback: use the intended recent team window, then that
team's Train, then the league's Train average for the **same retained bucket
and strength**. PP defense continues to start at its adopted team Train window.
An empty or incomplete recent window, or no matching-strength exposure, falls
back as a window; it does not splice in older games. A complete window with
positive exposure and zero attempts keeps that observed zero in the existing
150-minute-prior calculation. It does not trigger fallback. League allowance
is **1.0 relative to the frozen league bucket rate**; a missing matching league
bucket within a supported strength uses neutral allowance 1.0. A wholly unsupported
strength remains unavailable; another strength is never substituted.
This applies both to the upcoming opponent and to historical opponent references.
Player inputs and calculated bucket outputs record `defense_allowance_source`
and the rate-weighted `defense_reference_sources` shares. Other integrity and
PP-allocation checks remain in force.

Final PP allocation follows the documented conditional team split and 75/25
game-total rule, using retained-bucket PP goals/game history consistently with
the custom goal target. Standard opponent PK success uses official PP totals
and opportunities; absent stored PP-goal totals can be measured from prior raw
PP goal events when shot facts are available. Initial allocation denominators,
personal L20 PP history use the documented Train fallbacks when missing.
Weighted PP bucket evidence must still reconcile with the final allocation.
Final team PP goals replace the intermediate player-bucket PP sum; redistribute
by the original projected bucket-goal weights, or SOG weights when all goal
weights are zero. A positive allocation without weights, or above projected PP
SOG, is an explicit reconciliation failure rather than a silent cap.

Winner probabilities use independent Poisson team goal distributions, splitting
regulation ties **50/50**, then applying the approved schedule probability delta.
Winner comparisons use official final results including OT/SO. Goal comparisons
use retained-bucket regulation EV + PP actuals. JSON and saved rows retain both
views. No short-handed goals or qualification cutoff are included. Successful
predictions now return the restored existing internal input-confidence score,
separate from win probability and input completeness. Outcome calibration is
not claimed and does not prevent publishing internal confidence.

### Restored internal confidence

The existing `NhlGamePredictionPayload::confidenceScore` and
`teamInputConfidence` calculations are reused unchanged. Team confidence remains
70% skater and 30% goalie input confidence; skater values are weighted by player
goal contributions through the existing 99% contribution selection. Average
the two teams, round and bound to 1–100. Existing missing-value defaults remain.
No legacy presentation boost, win-record blend or confidence qualification gate
is reintroduced.

Preparation reads confidence from the same model's stored rate/profile evidence
and selected starter's exact EV bucket samples. It does not rerun annual scoring
or goalie performance calculations. Confidence consumes the new engine's
already-calculated goal/attempt weights and never changes goals, win probability
or the pick. Earlier immutable predictions can retain null confidence.

Details and authored regression coverage:
[internal-confidence-restoration-review.md](analysis/internal-confidence-restoration-review.md).

This implementation establishes an executable definition for a **new measured
baseline**. None of the historical 56.40%, 53.92% or 37.57%-p20 results below is
claimed as its measured performance. Tests and Test-season replay have not been
run as part of writing this implementation.

### Default rollout: all requested games

The former observed-history 90% admission gate is removed. The configured target
is **100% completed inputs after fallback**. Missing personal rate/usage and
conversion evidence uses matching retained Train league evidence; missing team
opportunity and PP history uses team Train then league Train as appropriate.
Measured zeros remain zeros. Observed coverage remains a separate diagnostic and
never withholds an otherwise valid requested game.

Existing API/manual prediction routes, engine and stack previews, engine
evaluations, the matchup simulator and date command use the same calculator.
No legacy stack, weight inflation, goal dilution, confidence boost or gap gate
changes its outcome. Default model selection prefers eligible SAT_V2. All
scheduled game states are accepted without requiring target results.

Prepared-date inputs are shared in a bounded cache with algorithm/source/lineup
fingerprints and a preparation lock. Concurrent cold requests retry. The public
response cache has a new namespace; earlier saved revisions remain immutable.
Full calculation inputs are archived privately with new revisions. Confidence
remains null until calibrated, while scores and probabilities remain available.
Some legacy annual/assist roster fields are optional because this calculator
does not forecast them. Missing identities or corrupt model evidence still
produce explicit errors rather than fabricated players or legacy scoring.

No migration, prediction rerun or test execution accompanies this rollout. No
new season win percentage is claimed.

### Implementation and test review

Runner files:

- `app/Services/NhlCustomGameForecast.php`
- `app/Services/NhlCustomGamePredictionEngine.php`
- `app/Services/NhlCustomGamePredictionInputs.php`
- `app/Services/NhlCustomGameDefense.php`
- `app/Services/NhlCustomGamePredictionReplay.php`
- `app/Console/Commands/ReplayNhlCustomGamePredictionsCommand.php`
- `config/nhl-custom-prediction.php`
- `bootstrap/app.php` registers the new command.
- `scripts/replay-custom-pregame.php` retains its historical audit behavior and
  points operators to the executable command.
- `tests/Feature/NhlCustomGamePredictionTest.php`

Architecture, source rules, enum documentation and the derived inventory are
updated with this implementation. No new migration is introduced.

The minimum coverage matrix below describes **authored tests, not executed
results**:

| Area | Authored coverage | Representative test |
|---|---|---|
| A: validation | COVERED | `rejects a reversed replay range`; `rejects a Test season that overlaps the frozen Train horizon` |
| B: normalization and calculation | COVERED | `allocates five skaters worth of each predicted strength clock`; `preserves the separately predicted seventy five twenty five PP game total` |
| C: persistence | COVERED | `saves both team snapshots and linked player inputs in one isolated run`; `creates a new run for a repeated game without replacing the prior forecast` |
| D: command/report contract | COVERED | `exposes the actual custom replay summary through the registered command` |
| E: HTTP read contract | Authored | Existing authenticated partner route, markets, nullable confidence, filled inputs and immutable revision evidence in `NhlGamePredictionsMarketProbabilityApiTest.php` |
| F: events/queues | Authored | Manual date/range admission, all scheduled states, existing job dispatch, retry/history contracts in `NhlOperationalGamePredictionsTest.php` |
| G: frontend | Copy only | Existing controls retained; manual page labels the default pregame engine and simulator labels EV + PP goals. No new interactive state. |
| Cutoff and source windows | COVERED | `builds both sides before advancing history and excludes target and later outcomes from every window` |
| Operational failure | COVERED | `marks an interrupted saved run failed while retaining already recorded game history` |
| Defensive fallback | COVERED | `falls back to team Train when any recent defensive game is incomplete`; `falls back to league Train when recent and team defensive history are unavailable` |
| Defensive zeros and exposure | COVERED | `retains observed zero attempts in a recent defensive window with exposure`; `falls back rather than treating missing defensive clock evidence as zero`; `uses PK exposure for a defensive window facing PP attempts` |
| Bucket isolation and provenance | COVERED | `uses neutral league defense for an unseen bucket without borrowing another strength`; `retains defensive fallback provenance in calculated player buckets`; chronological builder test includes an incomplete historical team-clock variant |
| Requested-date prediction | COVERED | `predicts requested games in every state without target results or saved context` (FUT/LIVE/FINAL/OFF); `reports an unavailable requested lineup without inventing target participants` |
| Requested-date command | COVERED | `prints requested-date predictions through the registered custom command`; `rejects requested-date prediction combined with replay-only options`; invalid/empty date coverage |

Implementation requirements, pending execution review:

1. **Authored:** model-owned Train/Test inputs and frozen reliable bucket
   mapping; shared default calculator, versioned new predictions and untouched
   historical records.
2. **SATISFIED in code:** team clocks, full-lineup allocation, defensive ratio,
   conversion, neutral goalie stage and replacing final PP allocation.
3. **SATISFIED in code:** approved exact-fourth B2B adjustments and no
   short-handed scoring lane.
4. **SATISFIED in code:** explicit save/new-run history, linked team/player
   snapshots, paired schedule comparison and visible exclusions/failures.
5. **SATISFIED in code:** official winner accuracy and the three specified
   goal-error measures with distinct team/game units.
6. **Execution pending:** the unit/feature suite and the real PostgreSQL raw
   clock/fact reader regression coverage has not been run. The first read-only
   production-data replay before the defensive fallback change evaluated only
   1 of 1,312 games and excluded 1,171 for defensive allowance/reference gaps;
   its single loss is not a season accuracy estimate. No post-fix season replay
   has been run.
7. **SATISFIED in code:** defensive recent/team Train/league Train fallback,
   observed-zero preservation, matching bucket/strength and source provenance.

Endpoint × verb × authorization/scope matrix (routes and permissions unchanged):

| Surface | Verb | Existing boundary | Authored regression coverage |
|---|---|---|---|
| Partner game prediction | GET | Authenticated partner and required API scope | Missing auth/scope; authorized payload/cache/history |
| Admin manual date/range run | POST | Existing super-admin authorization | Denial, date/range validation, all-state admission and dispatch |
| Engine/stack preview | Existing preview verbs | Existing admin authorization | Shared default output; empty stack; no legacy boosts |
| Matchup simulator | Existing admin page request | Existing admin authorization | Shared scheduled-game calculation and team order |
| Season-statistics API | GET | Existing auth/scope | Unchanged validation and period delegation |

Requirements checklist: observed completeness is diagnostic; effective inputs
reach 100% through fallbacks; every scheduled state is admitted; shared calculator
owns all new outcomes; legacy records persist; private evidence is not public;
confidence is nullable; no new route, dependency or migration. Frontend checklist:
copy/labels only, existing request and loading controls unchanged.

Per-file authored `it(...)` counts (datasets may expand executed cases):

| File under `tests/Feature` | Tests |
|---|---:|
| `NhlCustomGamePredictionTest.php` | 79 |
| `NhlGamePredictionsMarketProbabilityApiTest.php` | 47 |
| `NhlOperationalGamePredictionsTest.php` | 32 |
| `NhlSatEnginesTest.php` | 130 |
| `NhlSatPredictionServicesTest.php` | 58 |
| `NhlSeasonStatsApiTest.php` | 20 |

These are authored assertions, not passing results. Tests were not run. The
shared-date cache and unknown scheduled-start cases are included in the custom
suite; API tests exercise the real formatter/calculator with deterministic
prepared-input boundaries. Existing legacy helper tests retain their historical
scope. Source-data PostgreSQL replay remains an operator verification step.

Human-run validation commands:

```sh
php artisan test --filter=NhlCustomGamePredictionTest
php -d memory_limit=1024M artisan nhl:custom-predict --model=1 --compare-schedule
```

The first run is needed to validate actual source availability, exclusions and
performance; no historical percentage substitutes for its output.

## Adopted opportunity adjustment — November 2, 2026

The developing custom engine now adopts the following opportunity rule. This
records the agreed algorithm; it is not an implementation in the app's legacy
engines or a newly executed full winner replay.

For team A against team B:

1. Calculate each team's historical Train share: its own PP opportunities divided
   by all PP opportunities in those historical games.
2. Normalize A's and B's shares against each other and allocate the **5.9** initial
   game-opportunity baseline to produce each team's baseline opportunities.
3. **Adjusted A opportunities = 0.25 × baseline A opportunities + 0.75 × B's
   L10 PK opportunities/game.** B's PK opportunities mean the PP opportunities
   allowed to B's opponents, not B's own attacking PP opportunities.
4. Apply the same calculation independently for B, using A's L10 PK history.
5. Convert each adjusted estimate to PP minutes using the same pooled Train
   duration reference as the benchmark. Derive PK from opponent PP, then EV
   from the remaining regulation time.

L10 contains completed prior regular-season appearances across season
boundaries and stops before the target game. Missing L10 history retains the
team's baseline estimate; unavailable history is not zero. The replay did not
invent Train shares for teams without that history. The two adjusted estimates
need not sum to 5.9; do not normalize them back to that initial baseline.

### Measured accuracy impact

Production data, 2025–26 Test: identical **2,430 team-games / 1,215 games**.
The rule was selected from 141 configurations using 2024–25 within Train,
before evaluating Test. Histories were reconstructed from stored game PP counts;
this does not populate the builder's missing PP-opportunity windows.

| Metric and method | Prediction | Actual | err% | p10 | p20 |
|---|---:|---:|---:|---:|---:|
| PP opportunities/team — baseline | 2.95 | 2.87 | 2.85% | 27.28% | **27.53%** |
| PP opportunities/team — adopted | 2.90 | 2.87 | 1.02% | 15.60% | **29.55%** |
| PP minutes/team — baseline | 4.83 | 4.67 | 3.26% | 10.66% | **30.78%** |
| PP minutes/team — adopted | 4.74 | 4.67 | 1.44% | 14.49% | **28.81%** |

- Opportunity p20 increases **2.02 percentage points**, from **669 to 718**
  team-games. There are 174 previously outside-p20 results rescued and 125
  previously inside-p20 results lost, for a net gain of 49.
- Opportunity p10 decreases **11.69 percentage points**.
- PP-time p20 decreases **1.98 percentage points**, from **748 to 700**
  team-games. This tradeoff was disclosed before adoption.
- Derived EV-time p20 decreases from **99.01% (2,406/2,430)** to
  **98.93% (2,404/2,430)**.
- PK-time accuracy mirrors opposing PP time on this paired population; it is not
  an independently fitted prediction.
- No new game-total opportunity p20 or overall winner win% was established for
  this adjustment. Do not carry the old game-opportunity or winner percentages
  forward as measurements of the adjusted algorithm.

## Measured PP/PK/EV time baseline

The November 2 production rerun used Sep2026's Train seasons (2022–23 through
2024–25) and evaluated 2025–26 Test games individually.

The time baseline was:

- **5.9 combined PP opportunities per game**.
- **1.636706 minutes per opportunity**, calculated from pooled Train PP clock
  time and corresponding PP opportunities on valid source games.
- Therefore **9.656566 total game PP minutes**.
- Allocate those minutes using the two teams' normalized Train PP-opportunity
  shares. Each historical share is the team's PP opportunities divided by the
  combined opportunities in its historical games.
- Each team's predicted PK minutes equal its opponent's predicted PP minutes.
- Predicted regulation EV time is **50.343434 minutes per team**.

Actual strength time was reconstructed from the union of stored unit-shift
intervals, clipped to regulation. This avoids summing player minutes or counting
simultaneous forward and defense units twice.

### PP opportunity accuracy before the time conversion

The November 2 production opportunity rerun uses the same **1,215 games / 2,430
team-games** as the time baseline below, so the two stages can be compared on
identical observations. The opportunity results do not imply that opportunities
are missing in the excluded games; this is the shared time-baseline cohort.
Actual opportunities come from the stored game-level PP opportunity fields.

| PP opportunities | Prediction | Actual | err% | p10 | p20 |
|---|---:|---:|---:|---:|---:|
| Per team-game | 2.95 | 2.87 | 2.85% | 27.28% | **27.53%** |
| Combined per game | 5.90 | 5.73 | 2.85% | 16.38% | **48.15%** |

- Team p20: **669/2,430**; team p10: **663/2,430**.
- Game p20: **585/1,215**; game p10: **199/1,215**.
- Total predicted opportunities: **7,168.5**; total actual opportunities:
  **6,964**. Each game is counted once in those totals.
- The per-team prediction is allocated using pregame Train shares, not an equal
  split. Its 2.95 average follows from pooling both sides of each game.
- Use the same prediction-denominator error definitions as the time tables.
  These percentages measure opportunity accuracy, not winner accuracy.

### PP, PK and EV time accuracy

| Metric | Prediction, min/team | Actual, min/team | err% | p10 | p20 |
|---|---:|---:|---:|---:|---:|
| EV time | 50.34 | 50.66 | 0.63% | 82.39% | **99.01%** |
| PP time | 4.83 | 4.67 | 3.26% | 10.66% | **30.78%** |
| PK time | 4.83 | 4.67 | 3.26% | 10.66% | **30.78%** |

Coverage: **2,430 team-games in 1,215 of 1,312 Test games**. Sixteen games had
incomplete strength-clock coverage; another 81 lacked the required Train
opportunity-share history. No replacement history was invented for those games.
The duration reference used 3,614 Train games with valid strength clocks.

- EV p20: **2,406/2,430** team-games.
- PP p20: **748/2,430** team-games.
- PK mirrors the opposing team's PP across this paired population; identical
  PP/PK scores are not independent confirmations of accuracy.

Here err% is the absolute difference between summed prediction and summed actual,
divided by summed prediction. p10/p20 count individual team-games whose absolute
error divided by prediction is strictly below 10%/20%. These are time-accuracy
measures, not winner-pick success rates.

EV's high p20 does not establish accurate PP time: the same missed minutes are a
much smaller percentage of approximately 50 EV minutes than of approximately
five PP minutes.

### Context experiments following this rerun

The same-cohort comparison screened 301 history/duration configurations using
Train, LSeason, L5, L10 and L20 for team and opponent histories. Selection used
2024–25 within Train before evaluating 2025–26 Test.

The selected alternative blended half the baseline with half an opportunity
estimate using 25% own-team Train PP opportunities/game and 75% opponent Train
PK opportunities/game, converted with the Train duration per opportunity.

| PP time method | Prediction, min/team | Actual, min/team | err% | p10 | p20 |
|---|---:|---:|---:|---:|---:|---:|
| Baseline | 4.83 | 4.67 | 3.26% | 10.66% | **30.78%** |
| Train-selected alternative | 4.80 | 4.67 | 2.78% | 10.82% | **30.62%** |

The alternative put 744 rather than 748 team-games within 20%. Separate
conditional venue, rest, schedule-density, possession and form adjustments did
not beat the baseline in Train validation. Those time-targeted alternatives were
not adopted. The later opportunity-targeted rule above was adopted separately,
with its measured reduction in PP-time p20 explicitly recorded.

## Step 6: initial player-allocation baseline

Established November 2 on production, using Sep2026's 2025–26 Test season.
**L10 is the initial measured baseline**, not a claim that its window choice is
optimal or already implemented in the application.

For each player and strength, take historical L10 TOI seconds divided by its
appearance count. Divide that value by the sum for the same game's skater lineup
to obtain the projected share. Normalize independently for EV, PP and PK.
Zero historical exposure remains zero; missing history is not imputed.

Convert shares into predicted minutes using the adopted team clocks above and
initial skater-time budgets of **5 × EV clock, 5 × PP clock, and 4 × PK clock**.
These are budget assumptions, not observed target-game skater counts. Share
accuracy is measured independently of those multipliers and team-clock errors.

Actual regulation player exposure is the union of unit-shift intervals linked
through canonical `nhl_unit_players` memberships and clipped to 3,600 seconds.
Do not substitute the optional `nhl_unit_shift_players` position-enrichment rows
for canonical membership. Actual share divides each player's exposure by the
same lineup's total exposure at that strength.

### Allocation share accuracy

| Strength | Player-game comparisons | Share p10 | Share p20 |
|---|---:|---:|---:|
| EV | 40,444 | 52.37% (21,180) | **82.01% (33,168)** |
| PP | 37,642 | 13.09% (4,927) | **24.38% (9,177)** |
| PK | 33,788 | 12.26% (4,144) | **23.77% (8,030)** |

Share and actual normalize to the same total; their pooled mean difference is
not an informative allocation score. The table measures individual shares.
The pooled err% convention remains available for final player minutes below.

### Final player-minute accuracy

These results include both allocation errors and upstream team-time errors.

| Strength | Prediction, min/player | Actual, min/player | err% | p10 | p20 |
|---|---:|---:|---:|---:|---:|
| EV | 14.03 | 14.07 | 0.22% | 46.26% | **77.01%** |
| PP | 1.39 | 1.37 | 1.14% | 7.04% | **13.83%** |
| PK | 1.23 | 1.20 | 2.26% | 7.53% | **14.73%** |

Minute comparisons: EV **40,444** (31,146 within p20), PP **38,392** (5,308),
PK **34,494** (5,080). Prediction denominators and strict thresholds apply.

### Coverage and interpretation

- This uses retrospectively known completed-game skater lineups, not a test of
  anticipating injuries, participants or PP units before puck drop.
- The upstream time cohort contains 2,430 team-games. L10 is complete for
  **2,247 EV/PP lineups** and **2,228 PK lineups**. Another 183 EV/PP and 202 PK
  lineups are unavailable because a member lacks complete history/exposure or
  the lineup has no usable historical strength-time denominator. No member is
  silently dropped from a lineup's share denominator.
- Predicted-zero/actual-zero player-strength observations are counted separately
  and excluded from accuracy denominators: **2,052 PP** and **5,608 PK**. A
  predicted-zero/actual-positive observation is counted as a miss.
- Share accuracy excludes lineups with zero actual exposure at that strength;
  final-minute accuracy still counts their positive predicted minutes as misses.
- Train, LSeason, L5, L20 and equal L5/L10 shares were also inspected. Their
  eligible populations vary, so their unpaired percentages do not establish a
  window improvement.
- The equal L5/L10 share blend has the same eligible population as L10 here.
  Its share p20 is EV **82.37%**, PP **25.09%**, PK **23.89%**. Its minute p20 is
  EV **77.25%**, PP **14.01%**, PK **14.88%**. These are descriptive Test results,
  not a Train-selected replacement for the initial L10 baseline.

### Possession/PDO allocation experiment

November 2 production follow-up kept the L10 usage baseline and its eligible
lineups fixed. Each strength separately screened 121 configurations: unchanged
usage plus combinations of player on-ice Corsi/PDO coefficients from
`[-2, -1, 0, 1, 2]`, using L5, L10, L20, LSeason or Train context. Coefficients
were selected by allocation-share p20 on 2024–25 within Train, then frozen for
2025–26 Test. No Test outcome selected an adjustment or its recipients.

The trial multiplies L10 usage weights by
`exp(corsi_coefficient × (CF% − 50)/100 + pdo_coefficient × (PDO − 100)/100)`,
caps that multiplier at 0.5–2, then renormalizes the lineup. Missing context
features are neutral. These are player-specific on-ice metrics; a common team
multiplier alone would cancel when normalizing lineup shares.

Train selected unchanged usage for EV and PK. For PP, it selected Train Corsi
with coefficient −1 and PDO coefficient zero. That PP adjustment added only
20 within-p20 allocations on 39,129 Train comparisons and failed on Test.

| Test allocation | Baseline p10 | Selected p10 | Baseline p20 | Selected p20 |
|---|---:|---:|---:|---:|
| EV share | 52.37% | 52.37% | 82.01% | 82.01% |
| PP share | 13.09% | 12.46% | 24.38% | 23.62% |
| PK share | 12.26% | 12.26% | 23.77% | 23.77% |

PP share p20 falls from **9,177/37,642** to **8,892/37,642**: 802 previously
inaccurate allocations improved, while 1,087 previously accurate allocations
were lost. Final PP-minute p20 also falls from **5,308/38,392 (13.83%)** to
**5,211/38,392 (13.57%)**. Minute err% remains 1.14% because normalization leaves
the total predicted PP skater time unchanged; minute p10 changes from 7.04% to
7.01%.

No possession/PDO adjustment from this experiment is adopted. Keep the initial
L10 allocation baseline. This result does not rule out other formulations or
establish a general absence of possession/PDO relationships.

## Step 7: offensive player SAT/60 and bucket distribution baseline

Established November 2 from production for Sep2026's 2025–26 Test season.
This evaluates the offensive rate stage before opposing defense, finishing or
goalie adjustments. It does not multiply by predicted player minutes or claim
SAT/game accuracy for the combined engine.

### Frozen evidence and prediction

- SAT model **4**, matching Sep2026's 2022–23 through 2024–25 Train seasons.
- **93 level-one buckets**, retaining Train confidence **>= 0.50**. The selected
  model uses shot type and distance; it does not add an angle factor here.
- The identical retained keys apply to Train, recent history and target Actual.
  Exclude shootout and empty-net attempts; retain matching full-strength
  exposure, including normal overtime. Rates are not regulation-only totals.
- Each player/bucket/strength rate is **50% Train SAT/60 + 50% L10 SAT/60**.
  Pool retained attempts and exposure within each window before calculating its
  rate. Sum the bucket forecasts for the player's total retained SAT/60.
- L10 contains the player's preceding appearances across season and team
  boundaries, including appearances with no time at the selected strength.
  Forecast both sides before using the target game's results as later history.
- Train accumulates valid historical appearances from the frozen Train seasons.
  A recent window with missing source facts, unclassified strength or retained
  attempts without exposure is unavailable. Missing history is not zero.

### Individual player SAT/60 accuracy

| Strength | Prediction SAT/60 | Actual SAT/60 | err% | p10 | p20 |
|---|---:|---:|---:|---:|---:|
| EV | 11.71 | 11.45 | 2.26% | 11.89% | **23.32%** |
| PP | 20.88 | 20.48 | 1.92% | 4.20% | **8.11%** |
| PK | 3.69 | 3.51 | 4.68% | 0.04% | **0.06%** |

These are player rates, not team SAT/60 or sums of player rates. Displayed pooled
rates use total retained attempts divided by total player exposure. For the
forecast's pooled rate, apply each predicted rate to that same measured exposure
solely to standardize the rate comparison, then total and divide. This is not a
prediction of SAT/game using future TOI. Overall err% compares those pooled
predicted and actual attempt totals, using predicted totals as denominator.
Player p10/p20 compare each predicted rate with that player's actual rate using
prediction denominators and strict thresholds.

| Strength | Player-game comparisons | Within p10 | Within p20 | Missing actual exposure | Missing history | Both rates zero, separate |
|---|---:|---:|---:|---:|---:|---:|
| EV | 44,559 | 5,299 | 10,392 | 0 | 2,670 | 1 |
| PP | 27,067 | 1,137 | 2,196 | 17,441 | 2,191 | 531 |
| PK | 24,803 | 9 | 16 | 19,106 | 1,704 | 1,617 |

The underlying population is **47,230 skater appearances** across all Test games.
A strength rate requires positive actual exposure. Predicted-zero/actual-positive
rates count as misses; both-zero cases are recorded separately. This differs
from Step 6's team-clock/history cohort, so its percentages are not a direct
comparison with Step 6's minute accuracy.

### Train-only baseline versus pregame L10

November 2 production replay compared **100% Train** with **50% Train + 50%
pregame L10** against each next game's player SAT/60 in the 2025–26 Test season.
Both methods use the same retained buckets, source-validity rules and matched
baseline cohort: **44,559 EV** and **27,067 PP player-game comparisons**. Train
remains frozen to the model's Train seasons; L10 updates from preceding player
appearances before each target game. No age rules are applied.

| Strength | Source | Prediction SAT/60 | Actual SAT/60 | err% | p10 | p20 |
|---|---|---:|---:|---:|---:|---:|
| EV | Train only | 11.91 | 11.45 | 3.89% | 11.70% | 23.22% |
| EV | 50% Train + 50% L10 | 11.71 | 11.45 | 2.26% | 11.89% | **23.32%** |
| PP | Train only | 21.23 | 20.48 | 3.55% | 3.97% | 7.95% |
| PP | 50% Train + 50% L10 | 20.88 | 20.48 | 1.92% | 4.20% | **8.11%** |

Use the pooled-total err% and individual player-game p10/p20 definitions above;
do not average individual percentage errors. EV p20 increases from **10,345 to
10,392 hits**: **47 additional hits**, or **0.1055 percentage points**. PP p20
increases from **2,151 to 2,196 hits**: **45 additional hits**, or **0.1663
percentage points**.

Adding L10 improves aggregate agreement more than individual-game accuracy.
This isolates the impact of recent player history in the offensive rate stage;
it does not measure additional possession, PDO, travel, opponent-defense or
goalie adjustments, bucket-split accuracy, or a change in game-winner accuracy.
The comparison supports retaining the existing 50% Train / 50% L10 baseline;
it does not introduce a new engine formula.

### Individual bucket split diagnostic

For each player, strength and target game, divide a predicted bucket rate by the
sum of predicted retained bucket rates. Compare that share with the same
bucket's share of the player's actual retained attempts. Use only games with at
least one actual retained attempt; include buckets present in either prediction
or Actual. Both-absent buckets do not inflate accuracy. A newly observed bucket
with zero predicted share is a miss.

| Strength | Active player-game-bucket comparisons | Split p10 | Split p20 | Newly observed buckets |
|---|---:|---:|---:|---:|
| EV | 2,307,972 | 0.0037% (86) | 0.0081% (188) | 6,748 |
| PP | 434,087 | 0.0041% (18) | 0.0101% (44) | 2,792 |
| PK | 34,351 | 0.0378% (13) | 0.0815% (28) | 1,449 |

This diagnostic has a different unit from player SAT/60. Sparse next-game
attempts make observed shares jump between zero and large fractions, while a
historical distribution spreads expected attempts among many buckets. The very
low relative-error hit rates must remain visible, but do not interpret them as
the percentage of SAT volume predicted correctly or as winner accuracy. No
alternative split-scoring metric or replacement distribution formula is adopted
by recording this baseline.

### Step 7 age-group comparison

November 2 production rerun kept the same 50% Train / 50% L10 formula, 93
retained buckets and scoring rules. Group age is completed years between the
player's stored DOB and the target scheduled game date, not current age. All
47,230 source skater appearances had a usable DOB. A birthday may move the same
player into a different age group later in the season.

| Age | Strength | Player-game comparisons | Prediction SAT/60 | Actual SAT/60 | err% | p10 | p20 |
|---|---|---:|---:|---:|---:|---:|---:|
| <24 | EV | 6,946 | 11.80 | 11.79 | 0.02% | 12.05% | 23.48% |
| <24 | PP | 4,578 | 20.24 | 20.13 | 0.54% | 3.78% | 7.82% |
| <24 | PK | 2,563 | 4.34 | 3.51 | 19.12% | 0.04% | 0.08% |
| 24–29 | EV | 20,628 | 11.79 | 11.58 | 1.75% | 12.10% | 23.63% |
| 24–29 | PP | 11,791 | 21.38 | 21.22 | 0.74% | 4.55% | 8.80% |
| 24–29 | PK | 11,368 | 3.63 | 3.69 | 1.68% | 0.04% | 0.06% |
| 30–34 | EV | 13,514 | 11.51 | 11.07 | 3.80% | 11.54% | 22.84% |
| 30–34 | PP | 8,267 | 20.38 | 19.67 | 3.51% | 3.81% | 7.19% |
| 30–34 | PK | 8,869 | 3.71 | 3.41 | 7.91% | 0.03% | 0.07% |
| 35+ | EV | 3,471 | 11.87 | 11.42 | 3.76% | 11.70% | 23.05% |
| 35+ | PP | 2,431 | 21.23 | 20.12 | 5.24% | 4.65% | 8.47% |
| 35+ | PK | 2,003 | 3.27 | 2.97 | 9.10% | 0.00% | 0.05% |

The rates and error calculations follow the exposure-weighted Step 7 definitions
above. Player-level p10/p20 do not score rounded displayed values. Age grouping
does not change forecasts or establish an age-specific formula improvement.

EV p20 differs by less than one percentage point across these age groups. The
largest pooled calibration discrepancy is under-24 PK, with overpredicted rates;
older-player EV and PP rates also overpredict in aggregate. These observations
are candidates for later Train-selected calibration, not approved deductions.

Coverage differs materially by age. The source appearance counts are <24:
8,859; 24–29: 21,308; 30–34: 13,586; 35+: 3,477. Under-24 EV has 1,912 missing
history comparisons, versus 680 at 24–29, 72 at 30–34 and six at 35+. Keep those
exclusions visible; the youngest results do not represent all debuting players.

The strict per-bucket split diagnostic was also stratified: EV p20 is 0.0119%,
0.0081%, 0.0070%, 0.0071%; PP p20 is 0.0119%, 0.0098%, 0.0071%, 0.0174%; PK
p20 is 0.1871%, 0.0633%, 0.0780%, 0.1410%, in ascending age-group order. The
sparse-share limitations described above still apply.

## Steps 8–9: offensive SAT/game and input completeness

Recorded November 3 from production replays of Sep2026's 2025–26 Test season.
These results combine the Step 7 player-bucket rate forecast with Step 6's
predicted player minutes and the adopted team clocks. They **exclude Step 8's
defense and production-context adjustments**. The documented opposing-defense
formula exists, but its earlier opponent-strength estimates have not been
reproduced in these replays. Do not describe this as defense-adjusted accuracy.

For each player, strength and retained bucket, predicted attempts equal bucket
SAT/60 × predicted player seconds / 3,600. Sum players into each team, then sum
both teams into the game total. Actual uses the same 93 retained buckets,
regulation periods only, excluding empty nets and shootouts. Keep EV and PP
separate. Lineups are retrospectively known skaters, not anticipated-lineup
accuracy. Every level uses full-lineup Actual, not just players with forecasts.

### Complete-input baseline

| Strength | Level | Comparisons | Prediction SAT/game | Actual SAT/game | err% | p10 | p20 |
|---|---|---:|---:|---:|---:|---:|---:|
| EV | Player | 4,606 | 2.70 | 2.55 | 5.30% | 11.42% | 23.43% |
| EV | Team | 256 | 48.49 | 45.93 | 5.30% | 36.72% | 64.06% |
| EV | Game | 128 | 96.99 | 91.85 | 5.30% | 49.22% | 85.16% |
| PP | Player | 3,314 | 0.52 | 0.49 | 6.05% | 2.66% | 5.04% |
| PP | Team | 208 | 8.33 | 7.83 | 6.05% | 16.83% | 24.52% |
| PP | Game | 104 | 16.66 | 15.65 | 6.05% | 15.38% | 27.88% |

### Input completeness and league fallback experiment

Input completeness counts skaters across both lineups with valid L10 usage and,
when that usage is positive, a valid 50% Train / 50% L10 bucket SAT/60 forecast.
A measured zero usage is valid and requires no positive-exposure SAT rate.
Classify the game before filling any missing input. Thresholds are cumulative;
90% means at least 90% of players are complete, not 90% prediction accuracy.

The upstream time/share rules admit 1,215 of 1,312 Test games. Unmatched actual
shooters exclude another 29 EV and 6 PP games from this coverage experiment;
these are integrity exclusions, not invented missing-player production.

Two fallbacks were compared on identical games at each threshold:

- **League:** fill only an unavailable SAT rate or usage weight with its pooled,
  matching-strength Train league reference. Pool retained bucket counts and
  exposure before division. Train player SAT/60 is **11.7334318585 EV** and
  **20.8032812194 PP**; Train usage seconds/appearance is **846.4154541635 EV**
  and **80.2866668742 PP**, including zero-strength appearances in usage averages.
- **Graded:** retain every complete rate/usage input. For unavailable inputs,
  blend available valid player history with the same league reference using
  player weight min(valid appearances / 10, 1). Prefer valid observations from
  the preceding ten appearances; if unavailable, use Train evidence. Recent SAT
  rates count positive-exposure appearances for reliability; usage counts all
  valid appearances. Otherwise use league alone. This ten-appearance ramp is an
  exploratory fixed setting, not a coefficient selected from Test outcomes.

After filling usage, normalize across the full lineup under the existing skater
minute budgets. Retain all players' Actual contributions. Filling missing usage
can change the normalization of other players; a 100%-complete game's forecasts
remain exactly unchanged. Fallback bucket rates still derive from retained
buckets, not unfiltered all-attempt rates.

The following errors measure **total-game SAT**, separately for EV and PP:

| Minimum input completeness | Fallback | EV games | EV err% | EV p10 | EV p20 | PP games | PP err% | PP p10 | PP p20 |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|
| 100% | None needed | 128 | 5.30% | 49.22% | 85.16% | 104 | 6.05% | 15.38% | 27.88% |
| 95% | League | 433 | 2.95% | 48.50% | 85.68% | 341 | 2.10% | 16.13% | 29.03% |
| 95% | Graded | 433 | 2.97% | 49.42% | 85.45% | 341 | 1.96% | 15.54% | 28.45% |
| 90% | League | 1,032 | 2.78% | 52.33% | 86.05% | 959 | 3.59% | 14.60% | 30.66% |
| 90% | Graded | 1,032 | 2.70% | 53.20% | 85.17% | 959 | 3.22% | 14.29% | 30.66% |
| 85% | League | 1,173 | 2.73% | 52.69% | 86.10% | 1,178 | 4.54% | 14.52% | 30.90% |
| 85% | Graded | 1,173 | 2.59% | 53.20% | 85.17% | 1,178 | 4.05% | 14.35% | 31.15% |
| 80% | League | 1,183 | 2.70% | 52.75% | 86.14% | 1,207 | 4.53% | 14.58% | 30.99% |
| 80% | Graded | 1,183 | 2.55% | 53.09% | 85.21% | 1,207 | 4.03% | 14.42% | 31.32% |

**Historical research cohort (superseded as a runtime admission gate): at least 90% input
completeness, with League fallback.** This includes **1,032 EV games** and
**959 PP games**. At 90%, league fallback gives EV p20 **888/1,032 (86.05%)**,
versus graded **879/1,032 (85.17%)**; PP p20 ties at **294/959 (30.66%)**.
Grading improves the PP pooled error from 3.59% to 3.22% but does not improve
its p20. Changing the threshold also changes the cohort; differences between
thresholds are not evidence that filling missing history improves the same
games. No production engine, stack or saved forecast has been changed.

## Step 10: offensive SOG and baseline-goal conversion

November 3 production replay keeps the Step 9 **90% input-complete cohort with
League fallback**, its player SAT bucket rates and predicted minutes fixed:
**1,032 EV games** and **959 PP games**. No opposing-defense, venue/travel,
PDO, goalie or separate team PP-goal allocation adjustment is applied. This
establishes the offensive conversion baseline, not a full-engine winner test
or a replacement for the agreed later PP-goal rule.

### Adopted SOG conversion sources — November 5, 2026

Use each player's **L20 bucket SOG/SAT for EV** and **L5 bucket SOG/SAT for PP**
in the developing custom engine's prediction rules. Compute each ratio from
that player's pooled retained bucket counts in the chosen window, using only
appearances before the target game and continuing across season/team changes.
Missing source windows or personal bucket denominators use the same bucket and
strength's frozen Train league SOG/SAT. A measured zero SOG/SAT stays zero.

Apply these ratios to the existing player-bucket SAT predictions, then sum the
resulting SOG into teams and games. The authoritative rule is documented in
`NhlNextGameEvaluation.yaml`; no legacy or production prediction code changes
are implied by this research-engine decision.

The measured total-game SOG impacts on identical cohorts are:

| Strength | Source | Prediction/game | Actual/game | err% | p10 | p20 |
|---|---|---:|---:|---:|---:|---:|
| EV | Train comparison | 48.36 | 44.57 | 7.83% | 39.44% | 70.93% |
| EV | **Adopted L20** | 46.60 | 44.57 | 4.35% | 41.76% | **74.03%** |
| PP | Train comparison | 8.67 | 8.09 | 6.66% | 15.02% | 26.17% |
| PP | **Adopted L5** | 8.57 | 8.09 | 5.55% | 13.87% | **27.63%** |

EV gains **32 p20 hits**, from 732 to 764 of 1,032 games. PP gains **14 p20
hits**, from 251 to 265 of 959, while its p10 declines. These choices were
observed on Test and adopted by the human; they are not Train-selected source
choices or evidence of improved winner accuracy.

This decision selects SOG/SAT sources only. Subsequent goals must be recomputed
from the new SOG using the separately governed finishing and PP-goal pathways.
The November 3 Train-conversion goal results below remain historical
comparisons. The November 5 SH% follow-up below establishes a new baseline
with these adopted SOG sources and resolves the missing Train goals/SOG case
through an explicit final league fallback.

### Formula and source handling

For every retained player bucket and strength:

- Predicted SOG = predicted SAT × player bucket SOG / player bucket SAT.
- Predicted baseline goals = predicted bucket SOG × player bucket goals /
  player bucket SOG.
- Compute source conversion ratios from pooled raw counts within that player's
  selected window. Do not average game or player percentages. Sum bucket
  predictions into players, teams and games before calculating displayed ratios.
- The initial conversion baseline uses **Train** for both ratios. Compare **L5,
  L10, L20 and LSeason** without changing Step 9's SAT forecast or TOI. These are
  conversion-window experiments, not five independently rebuilt SAT forecasts.
- A missing personal bucket denominator uses the **same bucket's frozen Train
  league ratio** at the same strength. Unavailable windows also use those bucket
  fallbacks. Do not merge buckets or add below-50%-confidence attempts. This
  November 3 pass did not use an all-bucket conversion percentage; the November 5
  SH% follow-up below adds an explicit last fallback only when the Train bucket
  also has no SOG. Therefore a recent-window forecast is
  not a direct copy of that window's aggregate shooting percentage.
- A Train league bucket with measured SAT but zero SOG has observed SOG/SAT of
  zero. When projected SOG is zero, expected goals are zero without inventing a
  goals/SOG percentage. A required nonzero-SOG conversion without a supported
  denominator remains unavailable.
- Actual includes the same retained regulation buckets and excludes empty nets
  and shootouts. Goal totals here are **retained-bucket goals**, not official
  unfiltered final scores. Errors use prediction denominators and strict p10/p20
  thresholds, with both-zero player cases reported separately.

### Train conversion baseline at each level

| Strength | Metric | Level | Comparisons | Prediction | Actual | err% | p10 | p20 |
|---|---|---|---:|---:|---:|---:|---:|---:|
| EV | SOG/GP | Player | 37,143 | 1.34 | 1.24 | 7.83% | 8.71% | 17.53% |
| EV | SOG/GP | Team | 2,064 | 24.18 | 22.29 | 7.83% | 29.26% | 56.20% |
| EV | SOG/GP | Game | 1,032 | 48.36 | 44.57 | 7.83% | 39.44% | 70.93% |
| EV | G/GP | Player | 37,035 | 0.13 | 0.12 | 6.04% | 0.00% | 0.00% |
| EV | G/GP | Team | 2,064 | 2.25 | 2.12 | 6.04% | 11.68% | 26.60% |
| EV | G/GP | Game | 1,032 | 4.51 | 4.24 | 6.04% | 16.09% | 33.14% |
| PP | SOG/GP | Player | 29,665 | 0.28 | 0.26 | 6.66% | 0.67% | 1.42% |
| PP | SOG/GP | Team | 1,918 | 4.34 | 4.05 | 6.66% | 10.58% | 22.16% |
| PP | SOG/GP | Game | 959 | 8.67 | 8.09 | 6.66% | 15.02% | 26.17% |
| PP | G/GP | Player | 24,609 | 0.05 | 0.05 | 10.21% | 0.00% | 0.00% |
| PP | G/GP | Team | 1,918 | 0.65 | 0.59 | 10.21% | 2.24% | 4.12% |
| PP | G/GP | Game | 959 | 1.31 | 1.17 | 10.21% | 7.30% | 17.41% |

### Changing both conversion windows together

The source below supplies both SOG/SAT and goals/SOG. All rows measure the
same total-game EV or PP cohort; these are descriptive Test comparisons, not
Train-selected replacement rules.

| Strength | Metric | Conversion source | Prediction/game | Actual/game | err% | p10 | p20 |
|---|---|---|---:|---:|---:|---:|---:|
| EV | SOG/GP | Train | 48.36 | 44.57 | 7.83% | 39.44% | 70.93% |
| EV | SOG/GP | L5 | 47.38 | 44.57 | 5.93% | 41.57% | 72.67% |
| EV | SOG/GP | L10 | 46.78 | 44.57 | 4.72% | 41.57% | 72.97% |
| EV | SOG/GP | L20 | 46.60 | 44.57 | 4.35% | 41.76% | 74.03% |
| EV | SOG/GP | LSeason | 46.92 | 44.57 | 5.01% | 41.86% | 72.77% |
| EV | G/GP | Train | 4.51 | 4.24 | 6.04% | 16.09% | 33.14% |
| EV | G/GP | L5 | 4.48 | 4.24 | 5.52% | 14.63% | 30.72% |
| EV | G/GP | L10 | 4.46 | 4.24 | 5.05% | 15.79% | 30.23% |
| EV | G/GP | L20 | 4.49 | 4.24 | 5.55% | 15.89% | 30.04% |
| EV | G/GP | LSeason | 4.41 | 4.24 | 4.02% | 15.50% | 31.88% |
| PP | SOG/GP | Train | 8.67 | 8.09 | 6.66% | 15.02% | 26.17% |
| PP | SOG/GP | L5 | 8.57 | 8.09 | 5.55% | 13.87% | 27.63% |
| PP | SOG/GP | L10 | 8.47 | 8.09 | 4.43% | 13.97% | 27.11% |
| PP | SOG/GP | L20 | 8.44 | 8.09 | 4.15% | 13.97% | 26.38% |
| PP | SOG/GP | LSeason | 8.54 | 8.09 | 5.28% | 14.49% | 26.80% |
| PP | G/GP | Train | 1.31 | 1.17 | 10.21% | 7.30% | 17.41% |
| PP | G/GP | L5 | 1.26 | 1.17 | 6.81% | 9.28% | 19.81% |
| PP | G/GP | L10 | 1.25 | 1.17 | 5.84% | 10.32% | 19.71% |
| PP | G/GP | L20 | 1.26 | 1.17 | 6.56% | 9.80% | 20.02% |
| PP | G/GP | LSeason | 1.30 | 1.17 | 9.53% | 6.88% | 17.83% |

### Shooting-percentage isolation: hold SOG conversion at Train

Only the goals/SOG source changes in this comparison. It does not attribute
changes caused by recent SOG/SAT to shooting percentage.

| Strength | Goals/SOG source | Predicted goals/game | Actual goals/game | err% | p10 | p20 |
|---|---|---:|---:|---:|---:|---:|
| EV | Train | 4.51 | 4.24 | 6.04% | 16.09% | 33.14% |
| EV | L5 | 4.55 | 4.24 | 6.91% | 15.89% | 32.66% |
| EV | L10 | 4.60 | 4.24 | 7.89% | 15.99% | 31.78% |
| EV | L20 | 4.65 | 4.24 | 8.96% | 15.12% | 30.91% |
| EV | LSeason | 4.59 | 4.24 | 7.61% | 16.09% | 33.14% |
| PP | Train | 1.31 | 1.17 | 10.21% | 7.30% | 17.41% |
| PP | L5 | 1.27 | 1.17 | 7.68% | 7.82% | 19.60% |
| PP | L10 | 1.27 | 1.17 | 7.68% | 8.76% | 20.13% |
| PP | L20 | 1.29 | 1.17 | 8.70% | 9.80% | 19.60% |
| PP | LSeason | 1.33 | 1.17 | 11.65% | 6.15% | 16.37% |

On this Test cohort, L20 has the highest EV SOG p20, **764/1,032 (74.03%)**
versus Train **732/1,032 (70.93%)**. PP SOG p20 is highest with L5,
**265/959 (27.63%)**, versus Train **251/959 (26.17%)**. Recent shooting
percentages do not beat Train EV-goal p20 when SOG conversion is held fixed;
LSeason ties Train at **342/1,032 (33.14%)**. For PP with Train SOG fixed,
L10 goals/SOG gives **193/959 (20.13%)**, versus Train **167/959 (17.41%)**.
These comparisons do not measure the final reconciled PP-goal pathway.

### Earlier conversion gap, resolved in the SH% follow-up

An additional attempt to hold Train goals/SOG fixed while changing SOG/SAT
encountered an EV retained bucket with zero Train league SOG but nonzero recent
SOG. Its Train goals/SOG is undefined. That earlier replay stopped rather than
inventing a percentage or dropping affected games. The November 5 follow-up
below resolves this case with a frozen Train league SH% at the same strength.
Use those follow-up measurements for the adopted SOG configuration. The older
coupled-window and Train-SOG isolation tables retain their original scope.

## Adopted SH% sources and updated goal baseline — November 5, 2026

The human adopted **player-bucket LSeason goals/SOG at EV** and **player-bucket
season-to-date goals/SOG at PP**, following the production comparison below.
These operate after the adopted EV L20 / PP L5 SOG/SAT conversion. They are the
developing custom engine's baseline-goal sources. The goalie stage preserves
these goals unchanged; the final team/game PP-goal allocation then replaces the
intermediate PP sum as described above.

### Exact calculation and missing evidence

For each player, retained bucket and strength, calculate SH% as total historical
goals divided by total historical SOG, then multiply that bucket's predicted
SOG by its SH%. LSeason is the immediately preceding season. PP season-to-date
uses only earlier appearances in the selected model's target season, including
previous teams. Each game's outcomes enter history only after its forecasts.
No current-quarter correction, PDO multiplier or additional finishing factor
was adopted with these sources.

Use the following fallback order only when the required SOG denominator is
unavailable or zero:

1. The player's selected bucket/window SH%.
2. Frozen Train league SH% for the same retained bucket and strength.
3. If the Train bucket also has zero SOG and projected SOG is positive, frozen
   Train league SH% pooled across retained buckets at the same strength.

A positive SOG denominator with zero goals is a measured zero SH%, not missing
history. Zero predicted SOG produces zero expected goals without needing an
SH% denominator. Missing even the strength-level Train SOG reference remains
unavailable. Source windows with missing required facts/exposure are unavailable;
missing appearances are not treated as zero production. At PP season start,
empty season-to-date history enters the league fallback chain above.

For this replay, the final strength-level fallback is **8.9757835411% EV** and
**14.2206134256% PP**, computed from the model's pooled retained Train counts.
It resolves the earlier zero-Train-SOG bucket gap. In the new Train-SH baseline,
that final fallback covers only **0.002594% of projected EV SOG** and
**0.009504% of projected PP SOG**; it does not replace supported personal or
bucket evidence.

### New Train-SH baseline with the adopted SOG sources

The replay verified that SOG totals remain exactly the prior EV L20 / PP L5
forecasts: **48,091.784019752 EV SOG** and **8,217.823552020 PP SOG** across
**1,032 EV games** and **959 PP games**. The 90%-input-complete cohorts,
Train league fallback for missing SAT/usage, predicted minutes and full-lineup
Actual are fixed across SH% candidates. This differs from the older baseline
that also used Train for SOG/SAT.

| Strength | Level | Comparisons | Predicted goals/game | Actual goals/game | err% | p10 | p20 |
|---|---|---:|---:|---:|---:|---:|---:|
| EV | Player | 37,049 | 0.1211 | 0.1180 | 2.55% | 0.00% | 0.00% |
| EV | Team | 2,064 | 2.17 | 2.12 | 2.55% | 14.24% | 26.50% |
| EV | Game | 1,032 | 4.35 | 4.24 | 2.55% | 15.99% | 32.27% |
| PP | Player | 28,191 | 0.0441 | 0.0399 | 9.40% | 0.00% | 0.00% |
| PP | Team | 1,918 | 0.65 | 0.59 | 9.40% | 1.72% | 3.81% |
| PP | Game | 959 | 1.30 | 1.17 | 9.40% | 6.99% | 17.83% |

Player p10/p20 compare fractional expected goals with individual observed game
counts. Both-zero cases are separate: **101 EV** and **6,332 PP** for the Train
baseline. These player counts are not the team/game denominators.

### Adopted sources versus this baseline

These rows measure both teams' combined goals at the indicated strength:

| Strength | SH% source | Prediction/game | Actual/game | err% | p10 | p20 |
|---|---|---:|---:|---:|---:|---:|
| EV | Train baseline | 4.35 | 4.24 | 2.55% | 15.99% | 32.27% |
| EV | **Adopted LSeason** | 4.40 | 4.24 | 3.72% | 16.38% | 32.66% |
| PP | Train baseline | 1.30 | 1.17 | 9.40% | 6.99% | 17.83% |
| PP | **Adopted Season to date** | 1.26 | 1.17 | 7.13% | 10.01% | 20.75% |

EV LSeason adds **four p20 hits**, from **333 to 337 of 1,032 games**, while
pooled err% worsens from **2.55% to 3.72%**. PP season-to-date adds **28 p20
hits**, from **171 to 199 of 959 games**, improving all three error measures.
Current-quarter PP also reached 199 p20 hits, but had lower p10 and worse pooled
error than season-to-date; no quarter-based SH% adjustment was adopted.

These are Test-observed source choices adopted by the human, not coefficients
selected within Train. Results remain **retained-bucket regulation goals before
opposing defense, venue/travel, PDO, goalie effects and final PP-goal allocation**.
They are not official-score or winner-accuracy measurements. Fractional expected
goals are not rounded for scoring; pooled err% uses unrounded summed predictions
as denominator, and game p10/p20 use strict individual-game thresholds.

### Complete pregame SH% comparison

SAT, TOI and adopted SOG are held fixed throughout. Blends are equal-weight
means of the two bucket probabilities after applying the same fallback rules.
Current Q in this diagnostic follows each player's prior Test appearance count:
1–20, 21–40, 41–60, then 61 onward. It is not the team's quarter or a forecast
using the quarter's eventual results. These comparison candidates do not create
additional engine adjustments beyond the two adopted source choices.

| Strength | SH% source | Prediction/game | Actual/game | err% | p10 | p20 |
|---|---|---:|---:|---:|---:|---:|
| EV | Train | 4.35 | 4.24 | 2.55% | 15.99% | 32.27% |
| EV | Previous | 4.29 | 4.24 | 1.24% | 16.57% | 30.72% |
| EV | L5 | 4.39 | 4.24 | 3.37% | 15.70% | 29.94% |
| EV | L10 | 4.44 | 4.24 | 4.54% | 15.79% | 29.94% |
| EV | L20 | 4.49 | 4.24 | 5.55% | 15.89% | 30.04% |
| EV | LSeason | 4.40 | 4.24 | 3.72% | 16.38% | 32.66% |
| EV | Season to date | 4.48 | 4.24 | 5.49% | 16.09% | 32.17% |
| EV | Current Q | 4.41 | 4.24 | 3.92% | 15.70% | 30.81% |
| EV | Train/L5 | 4.37 | 4.24 | 2.97% | 16.67% | 31.69% |
| EV | Train/L20 | 4.42 | 4.24 | 4.08% | 15.99% | 32.27% |
| PP | Train | 1.30 | 1.17 | 9.40% | 6.99% | 17.83% |
| PP | Previous | 1.26 | 1.17 | 6.98% | 8.13% | 19.92% |
| PP | L5 | 1.26 | 1.17 | 6.81% | 9.28% | 19.81% |
| PP | L10 | 1.26 | 1.17 | 6.87% | 8.97% | 19.40% |
| PP | L20 | 1.27 | 1.17 | 7.73% | 9.91% | 19.92% |
| PP | LSeason | 1.32 | 1.17 | 10.98% | 5.63% | 15.85% |
| PP | Season to date | 1.26 | 1.17 | 7.13% | 10.01% | 20.75% |
| PP | Current Q | 1.27 | 1.17 | 7.32% | 9.38% | 20.75% |
| PP | Train/L5 | 1.28 | 1.17 | 8.12% | 7.51% | 18.35% |
| PP | Train/L20 | 1.28 | 1.17 | 8.57% | 7.40% | 19.50% |

## Adopted goalie-stage decision — November 5, 2026

Retain the player-bucket goal baseline: EV uses each player's LSeason bucket
goals/SOG; PP uses their current-season-to-date bucket goals/SOG. Multiply each
player's predicted bucket SOG by that probability, sum buckets and players into
each team, then combine the teams. Retain the existing missing-history fallback
order. The goalie multiplier is **1.0**; no direct SV%, GSAx, recent-possession
or league-relative possession adjustment is active at this stage. This replaces
the former LSeason faced-goalie square-root multiplier in the developing custom
engine's rules. Starting-goalie identity and context remain available evidence.

This decision leaves upstream SAT, SOG, TOI and opposing-defense calculations
unchanged. It does not change the separately documented final PP-goal allocation,
introduce a PK finishing source, or establish a new winner baseline. The
executable runner above implements these stages; the evidence in this section
still describes the earlier isolated goalie-stage comparison.

### Combined EV + PP game-goal comparison

The production analysis used Sep2026 model 1, its 2022–23 through 2024–25 Train
seasons and 2025–26 Test season. It retained the same 93 level-one SAT buckets
with frozen Train confidence >= 0.50, excluding empty nets and shootouts.
The fixed SOG handoff and 90%-input-complete EV/PP cohorts intersect in **905
games**. These results measure both teams' combined retained-bucket regulation
EV + PP goals, before the separate final PP-goal allocation. They are not all
official game goals or winner accuracy.

Goalie candidates used the actual opposing starter's prior Train, LSeason or
L20 bucket history, with history updated after each game. SV% candidates used
the observed goals/SOG probability. GSAx candidates subtracted the starter's
historical bucket GSAx/SOG from the frozen Train bucket model xGA/SOG, clipping
probabilities to [0, 1]. Missing personal bucket history used frozen Train league
evidence; supported personal rates were not shrunk. No candidate changed SOG.

| Method | Prediction/game | Actual/game | err% | p10 | p20 |
|---|---:|---:|---:|---:|---:|
| **Retained player-bucket baseline** | **5.68** | **5.43** | **4.49%** | **18.12%** | **37.57%** |
| Starter Train SV% | 5.51 | 5.43 | 1.47% | 19.78% | 35.91% |
| Starter LSeason SV% | 5.58 | 5.43 | 2.75% | 18.90% | 36.35% |
| Starter L20 SV% | 5.77 | 5.43 | 5.90% | 19.78% | 36.69% |
| Starter Train GSAx | 5.56 | 5.43 | 2.35% | 20.33% | 35.47% |
| Starter LSeason GSAx | 5.68 | 5.43 | 4.54% | 19.23% | 37.24% |
| Starter L20 GSAx | 6.17 | 5.43 | 12.02% | 18.67% | 36.80% |

The retained baseline summed **5,140.780717686158 predicted goals versus 4,910
actual**. Its p10 is **164/905** and p20 is **340/905**. None of the tested direct
goalie methods improved combined-game p20. A closer season total alone does not
establish better individual-game predictions.

### Starter-specific context and possession research

A separate analysis attributed actual goals to the starter, excluding relief
goalies. Its unit is a **starter-game**, not a team-game or a combined game.
The same 905 joint-cohort games supply 1,810 starter-games. On **874 later
starter-games in 437 games from January 3, 2026 onward**, the baseline has
**246 p20 hits (28.15%)**. This denominator and actual-goal attribution differ
from the 905-game result above and must not be pooled with it.

The opponent's L5 Corsi above its same-window league reference was associated
with more goals allowed: later starters allowed **2.76 versus 2.52** below that
reference; earlier results were **2.76 versus 2.53**. Own-team above-reference
L10 Corsi was associated with fewer goals allowed, **2.54 versus 2.76** later.
These are descriptive relationships, not adopted adjustments. League references
pooled prior raw counts from the 32 Test-season team identities; a team without
prior history was unavailable. Missing travel kilometres, unreliable road-day
values and unaudited stored ranks were excluded.

The opponent-possession experiment held SOG and bucket composition fixed and
used `adjusted probability = clamp(probability * exp(beta * delta / 10), 0, 1)`,
where delta is opponent L5 Corsi minus the pregame L5 league reference, in
percentage points. Coefficients were fitted before January 3. The small EV-only
rate-fit coefficient was 0.09; the stronger EV + PP coefficient selected for
early p20 was 0.39. Results below measure total starter EV + PP goals in both
cases, even when only EV probabilities were adjusted.

| Method | Prediction/start | Actual/start | err% | p10 | p20 |
|---|---:|---:|---:|---:|---:|
| **Retained baseline** | **2.86** | **2.65** | **7.55%** | **15.10%** | **28.15%** |
| Small EV possession adjustment | 2.87 | 2.65 | 7.87% | 15.45% | 28.60% |
| Stronger early-p20-selected adjustment | 2.95 | 2.65 | 10.40% | 14.76% | 27.46% |

The small adjustment added only **four** p20 hits (250 versus 246), while the
stronger one lost **six** (240 versus 246). Adding possession to starter Train
GSAx improved later p20 by only **one** hit (240 versus 239, 27.46% versus
27.35%). None is adopted. The broader context comparisons likewise do not
authorize new multipliers. The possession signal had already been examined in
this Test season before the coefficient experiment, so this is exploratory
later-period evidence, not a wholly untouched validation season.

For both analyses, err% is `100 * abs(sum(prediction) - sum(actual)) /
sum(prediction)`. P10/P20 count individual comparisons with absolute error divided
by that comparison's prediction strictly below 10%/20%. Use unrounded values;
do not average individual percentage errors. Historical starters and lineups
were identified retrospectively; this does not measure their pregame selection.

## Historical winner baseline

The canonical custom-engine winner configuration documents **740 correct picks
out of 1,312 games: 56.40%**, compared with its earlier baseline of
724/1,312 (55.18%). These are all-game figures with no qualification cutoff.

This is a **previously documented result**, not a reproduced November 2 winner
replay. The newly measured time baselines above must not be represented as a
fresh measurement of that combined engine's win rate. It also predates the
neutral-goalie decision above; **56.40% is not the measured win rate of the
currently adopted goal baseline**.

The documented winner replay also did not integrate the separately agreed
team-level PP-goal allocation. Its exact EV season-gap and PDO finishing math
was not preserved sufficiently to reproduce the replay. These limitations must
remain visible when comparing later versions.

## Baselines still needed

Record matching-cohort results for any changes to the initial allocation
baseline, and err%, p10 and p20 for adjusted SAT/game, SOG production, final team
goals and game totals. Step 7 above measures offensive rates before defense and
predicted-minute conversion. Do not copy raw-history experiments or figures from
another engine into these stages. Record the precise formula, source windows,
coverage and measurement date with each new result.
