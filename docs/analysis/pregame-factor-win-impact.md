# Sep2026 pregame factors: next-game winner impact

## Protocol

Model 1, Sep2026: Train 2022–23 through 2024–25; Test 2025–26 regular
season. Run the current custom EV/PP engine unchanged once, then compare one
additional factor at a time against that identical baseline. Official final
winners include OT/SO. Do not reuse historical winner percentages from other
engine configurations.

Use only values already stored in the model-owned pregame context before each
target game. Read both teams separately. A missing feature is neutral for that
game and its coverage is reported; a factor absent from context is not tested.
Presence alone does not make a known-invalid field usable. No raw-result scan
or model fitting is added to a per-game factor calculation.

The baseline preparation itself still requires the engine's additional bucket
and defensive history. That one-time research pass is distinct from the cheap
factor adjustment. Keep an auditable compressed baseline-input artifact on the
production server to avoid repeating that pass for subsequent factors. No
prediction revisions, application caches or model definitions are written by
this experiment.

All variants start from the original baseline. No factors are combined or
adopted into production automatically. Full-Test screening is exploratory.
Choose one variant using games before January 1, 2026, and separately report its
performance from January 1 onward without selecting again on that later period.
Both periods remain within Test, so this is not validation on another season.

## Test 1 — team win percentage

Measured November 9, 2026, on production at code commit
`ddaec79ea955f5fed0b1ce9f30b6bdd58d7f693c`, using `custom_ev_pp_v2` and the
model's 93 retained Train-confidence-at-least-50% buckets. This is the existing
chronological Test replay with actual historical participant identities, not a
measurement of anticipated-lineup selection. It keeps the current neutral
goalie stage, final PP allocation and exact-fourth-in-seven B2B schedule rule.

The replay processed all 1,312 Test games and produced 1,290 predictions
(98.32%). Its existing target-source/clock/shooter integrity guard rejected
22 games. No probability or confidence cutoff was applied. Every factor variant
uses the same 1,290 games; unavailable win% leaves that game's baseline intact.

Baseline: **676/1,290 correct, 52.40%**. This is a fresh measurement of this
specific model, engine and source cohort, not an inherited 56%/58% result.

Stored source: `nhl_team_game_pregame_contexts.metrics`, window `win_pct`.
The model has 2,624 current-version Test team snapshots for 1,312 games; individual
window availability is checked separately rather than inferred from row count.

Compare Train, LSeason, last game, L5, L10, L20 and season-to-date. Fix the
following grid before evaluating the variants: weight 0, 0.05, 0.10, 0.15, 0.20,
0.30 and 0.50.

For a window with both teams' win percentages available:

```text
d = (home win% - away win%) / 100
home EV multiplier = 1 + weight × d
away EV multiplier = 1 - weight × d
```

This is equivalent to multiplying every player's EV bucket SAT prediction by
that team's multiplier after the existing defense/schedule calculation. SOG and
EV goals scale linearly because their conversion probabilities stay fixed.
Keep PP goals, player minutes, defensive factors, goalie treatment and existing
schedule-probability adjustment unchanged. Recompute Poisson win probabilities
from the adjusted EV goals plus unchanged PP goals. The grid includes the
unchanged baseline; a stronger adjustment is not assumed to be better.

If either side's window value is missing, leave both teams unchanged. Validate
sample games and numeric win percentages; never use future wins. A zero win
percentage with positive games is valid history. A missing window is not a
zero-percent team.

### Full-Test screen

Each row shows the best weight for that window **selected on full Test**. These
are exploratory maxima, not independently validated gains. Change is percentage
points; the numerator is correct official winner picks.

| Factor | Window | Weight | Stored in pregame? | Available in predicted games | Baseline win% | Adjusted win% | Change | Correct picks |
|---|---|---:|---|---:|---:|---:|---:|---:|
| Team win% | Train | 0.30 | Yes | 1,209/1,290 | 52.40% | 53.80% | +1.40 | 694/1,290 |
| Team win% | LSeason | 0.30 | Yes | 1,209/1,290 | 52.40% | 53.02% | +0.62 | 684/1,290 |
| Team win% | Last Game | 0.10 | Yes | 1,289/1,290 | 52.40% | 52.48% | +0.08 | 677/1,290 |
| Team win% | L5 | 0.50 | Yes | 1,289/1,290 | 52.40% | **54.03%** | **+1.63** | **697/1,290** |
| Team win% | L10 | 0.20 | Yes | 1,289/1,290 | 52.40% | 53.18% | +0.78 | 686/1,290 |
| Team win% | L20 | 0.50 | Yes | 1,289/1,290 | 52.40% | 53.49% | +1.09 | 690/1,290 |
| Team win% | Season to date | 0.20 | Yes | 1,271/1,290 | 52.40% | 53.02% | +0.62 | 684/1,290 |

The strongest full-Test result is L5 weight 0.50. For example, a 60% home L5
record against a 40% away record gives home EV SAT/SOG/goals +10% and away -10%,
with PP and the existing schedule probability delta held fixed. It changes
307 picks: 164 baseline losses become wins and 143 baseline wins become losses,
giving **21 additional correct picks** overall. This is not a 10-point addition
to the home team's win probability.

For L5 weights 0.05, 0.10, 0.15, 0.20, 0.30 and 0.50, full-Test correct picks
are respectively 679, 679, 683, 688, 682 and 697, versus baseline 676. This is
not a consistently increasing dose response. The best result is at the largest
predeclared weight; no extrapolation or larger-weight search was performed.

### Earlier-selection/later-check result

Choose the setting with the most correct picks before January 1, breaking ties
by smaller weight and then the declared window order. This chooses **Train win%
at weight 0.20**, not the full-Test-best L5 setting.

| Period | Games | Baseline wins | Adjusted wins | Baseline win% | Adjusted win% | Change |
|---|---:|---:|---:|---:|---:|---:|
| Before January 1, selection | 618 | 313 | 329 | 50.65% | 53.24% | +2.59 |
| January 1 onward, frozen check | 672 | 363 | 363 | 54.02% | 54.02% | 0.00 |
| Full Test, same frozen setting | 1,290 | 676 | 692 | 52.40% | 53.64% | +1.24 |

The selected early gain did not add correct picks later. The exploratory L5
weight-0.50 setting adds four early and seventeen later correct picks, but that
setting was identified using the full season. Keep it as a candidate, not a
confirmed independent improvement. This test neither proves win% has no
relationship nor establishes that this particular adjustment will generalize.
No engine change was adopted at the initial screening stage; the later human
approval below adopts the L5 weight-0.50 variant.

### Availability and calculation cost

Across all 1,312 scheduled Test games, both teams have valid recent windows in
1,311 games, Train/LSeason in 1,230, and season-to-date in 1,292. None of the
audited team snapshot cutoffs exceeds the target's scheduled start. Use sample
counts to distinguish a genuine zero win% from missing history. No new table,
context build, or historical query is needed to apply this candidate. Its
per-game work is two saved-field reads, two multipliers and the existing
Poisson probability calculation. Team periods must match on both sides.

The baseline preparation took 526 seconds. Its reusable compressed research
artifact is approximately 231 MB on production, outside the application DB and
cache. Subsequent factors can reuse it without another full historical replay.
The first analysis reader retained too much decoded input and reached its
1 GB memory limit; it was corrected to retain only compact team fields and the
factor analysis then ran with a 512 MB limit. No application worker/configuration
was changed.

Research artifacts on production:

- `/tmp/diq-sep2026-pregame-factor-baseline-20261109.jsonl.gz`
- `/tmp/diq-sep2026-team-win-factor-20261109.php`
- `/tmp/diq-sep2026-team-win-factor-20261109.json` (all 49 trials)

Baseline SHA-256:
`da76e29f623858b972ab345a42aa2243514c5c35a58a25631d595215479fe8b0`.
These are temporary research files, not permanent application storage; preserve
or regenerate them explicitly if a later server cleanup removes them.

The 22 excluded game IDs are 2025020009, 2025020031, 2025020033, 2025020057,
2025020116, 2025020171, 2025020287, 2025020320, 2025020423, 2025020434,
2025020470, 2025020584, 2025020594, 2025020646, 2025020739, 2025020751,
2025020760, 2025021003, 2025021093, 2025021163, 2025021206 and 2025021280.
All share the replay's explicit error: target source facts, regulation clocks
or shooter identities are incomplete. This is not a claim that these games lack
team win% context. Do not silently count them as losses or as successful picks.

### Quarter follow-up — the same team-win% factor

Requested November 9 after the full-season result. Assign each team's quarter
from its saved `season_to_date.games + 1`: games 1–20, 21–40, 41–60 and 61–82.
This uses team games, not player appearances or calendar quarters. No new source
statistical aggregation is required. Where the two teams are in different
quarters, put the game in a separate boundary group. Each game counts once.

Hold the full-Test L5 weight 0.50 fixed across all four quarters first:

| Metric | Q1 | Q2 | Q3 | Q4 |
|---|---:|---:|---:|---:|
| Games, both teams in that quarter | 304 | 297 | 302 | 340 |
| Baseline correct picks | 148 | 155 | 165 | 179 |
| L5-adjusted correct picks | 154 | 156 | 170 | 190 |
| Baseline win% | 48.68% | 52.19% | 54.64% | 52.65% |
| L5-adjusted win% | 50.66% | 52.53% | 56.29% | 55.88% |
| Change, percentage points | +1.97 | +0.34 | +1.66 | +3.24 |
| Additional correct picks | +6 | +1 | +5 | +11 |

L5 improves each same-quarter group, most in Q4 and least in Q2. The 47 boundary
games change from 29/47 (61.70%) to 27/47 (57.45%), two fewer correct picks.
The four same-quarter groups add 23 picks; boundary games lose two, reconciling
to the previously reported 21 additional correct picks across all 1,290 games.
L5 is available for 303/304 Q1 games and every other scored group game; missing
history retains the baseline.

An additional **within-quarter exploratory screen of the same predeclared
win%-window/weight grid** gives these maxima. These settings were selected from
their own quarter's Test outcomes; they are not independently validated rules.

| Metric | Q1 | Q2 | Q3 | Q4 |
|---|---|---|---|---|
| Best screened win% window | Train | Train | L5 | L5 |
| Weight | 0.50 | 0.15 | 0.20 | 0.50 |
| Correct picks | 160/304 | 163/297 | 172/302 | 190/340 |
| Adjusted win% | 52.63% | 54.88% | 56.95% | 55.88% |

This suggests checking longer-term team strength early and recent team form
later. It does not establish quarter-specific SAT/60, SOG/SAT or SH% source
choices: this is still the team-win% adjustment experiment only. No other
pregame factor was tested during this quarter analysis.

Production research files:

- `/tmp/diq-sep2026-win-factor-quarters-20261109.php`
- `/tmp/diq-sep2026-win-factor-quarters-20261109.json`
- `/tmp/diq-sep2026-pregame-factor-team-frame-20261109.json`

The last file retains compact team inputs from the verified compressed baseline
so later team-factor checks need not repeatedly decode the player/bucket data.
It records the same source SHA-256 and completed-run footer.

## Adopted implementation — November 9

The human approved L5 weight 0.50 in **all quarters**, including the 47
quarter-boundary games. The current calculator version is `custom_ev_pp_v3`;
the baseline artifact and comparisons above remain the earlier-version research
evidence. This is an adopted formula with exploratory supporting measurements,
not a claim that the edited executable has been rerun or verified.

Implementation reads the existing saved team metrics already included in the
input. It multiplies every EV player bucket's volume by the stated team factor,
before unchanged SOG/SAT and SH% conversion. This implements the screened
team-EV-goal scaling through its constituent players and buckets. PP allocation,
opportunities, minutes, defensive factors, confidence and the separate B2B rule
retain their existing calculations. Missing/invalid L5 history on either side
keeps both factors at one; positive sample counts with zero win% are valid.
Short nonempty L5 samples remain usable, matching the screen. No quarter
selection or historical-result read is added.

The algorithm definition includes the weight. Engine diagnostics and private
revision evidence include source win percentages, sample sizes, availability
and multipliers. Older revisions remain immutable. Existing configuration
fingerprints distinguish new response/prepared-input variants; no pregame
context version or schema change is required.

### Implementation coverage

Authored coverage below is not a claim of executed or passing tests.

| Category | Coverage |
|---|---|
| A validation | `keeps both teams neutral when either saved L5 sample is missing or invalid` covers both teams, missing history, empty/fractional samples and invalid rates |
| B calculation | `scales every EV bucket using the saved L5 record gap while preserving PP and minutes`; `accepts observed zero win percentages and nonempty opening-season samples`; `leaves equal L5 records neutral and reverses the adjustment when records reverse` |
| C history/cache | `invalidates earlier algorithm cache entries while retaining the previous immutable revision` |
| D/E API lifecycle | `publishes L5 adjusted scores and probabilities while archiving its source evidence privately` flows API request to revision storage to returned scores/probability and private evidence |
| F shared pathway | Existing default-engine/preview/simulator assertions retain their behavior and use the new version; schedule independence is covered separately |
| G frontend | Not applicable: no UI change |

Quarter cases cover team games 1, 20, 21, 40, 41, 60, 61 and 82, plus unmatched
quarter-boundary teams. `keeps the L5 layer independent of the schedule switch
and preserves the separate travel delta` covers schedule enabled and disabled.

Endpoint/auth scope remains unchanged:

| Endpoint | Verb | Auth/scope | Named coverage |
|---|---|---|---|
| `/api/nhl-game-predictions` | GET | Missing API authentication denied | `requires API authentication for new engine predictions` |
| Same | GET | Missing NHL statistics scope denied | `requires the NHL statistics scope for new engine predictions` |
| Same | GET | Valid scoped client | New API lifecycle/history cases above |

No new endpoint, organization ownership rule or frontend component is added.
Existing admin super-admin and preview authorization matrices are unchanged.

Requirements checklist: saved-field input only; weight 0.50; all quarters;
neutral missing-history behavior; valid measured zeros; EV bucket propagation;
unchanged PP/minutes; separate schedule delta; versioned immutable evidence;
documentation and deterministic regression coverage. Runtime verification is
outstanding: no tests, CI, migration, import, sync or production prediction
command was run during implementation.

Per-file authored `it` counts after the change:

| Test file | Declarations |
|---|---:|
| `tests/Feature/NhlCustomGamePredictionTest.php` | 97 |
| `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php` | 55 |
| `tests/Feature/NhlSatPredictionServicesTest.php` | 58 |

Dataset cases expand these counts. Full suites remain at the listed paths.
Only `git diff --check` was used for static whitespace verification. After
review the human can run:

```sh
php artisan test tests/Feature/NhlCustomGamePredictionTest.php tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php tests/Feature/NhlSatPredictionServicesTest.php
```

## Remaining factors

Next planned factor: recent team shooting percentage. Not started. Report Test 1 before proceeding to another factor. Each future
factor receives its own availability audit and before/after comparison. This
document does not authorize repairs or rebuilding missing context fields.
