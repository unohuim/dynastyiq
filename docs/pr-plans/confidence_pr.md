# Game Confidence And Win Probability

## Objective

Create a DynastyIQ confidence score for each game that uses the existing internal
data-confidence score as one input, while also accounting for whether the game
meets the prediction engine's requirements and for the strength of the projected
score spread.

Users must also receive the separate value they expect: each team's probability
of winning the game.

## Distinct Values

Every game prediction must keep these concepts separate:

- **Data Confidence**: the existing internal confidence in the prediction inputs.
- **Game Confidence**: the new DynastyIQ custom per-game confidence score.
- **Win Probability**: the model-derived chance that each team wins.

Data Confidence must not be presented as a team's likelihood of winning.
Game Confidence must not be presented as either team's likelihood of winning.

## Game Confidence Eligibility

Game Confidence is available only when the game qualifies for the prediction
engine. Qualification requires all of the following:

- A prediction is available.
- The game meets the prediction engine's required roster, skater/TOI, goalie,
  and game-type conditions.
- The existing internal Data Confidence falls within the Default engine's
  configured confidence window.
- The absolute underlying predicted-score spread strictly exceeds the Default
  engine's configured minimum score gap.

If a game fails any qualification requirement, it must not receive a Game
Confidence score. Win Probability likewise must not be presented without an
available prediction.

## Game Confidence Inputs

For a qualified game, Game Confidence is calculated from:

- Existing internal Data Confidence.
- The absolute predicted-score spread, so a larger projected edge contributes to
  stronger Game Confidence.
- Successful qualification against the prediction engine requirements.

The calculation must use the underlying projected scores, before display
rounding, when evaluating the minimum score gap.

## Presentation And API Behavior

- Present the custom score as **Game Confidence**.
- Present each side's model probability as **Win Probability**.
- Present existing evidence/input quality as **Data Confidence** only where
  supporting prediction context is useful.
- Preserve existing prediction results, market probabilities, and
  `pick_qualified` behavior.
- Build the new Game Confidence score on the same engine requirements as
  `pick_qualified`; it does not replace that existing behavior.

## Test Coverage

Add focused Pest coverage for:

- Games ineligible for the prediction engine.
- Data Confidence outside the Default engine confidence window.
- Predicted-score spreads at or below the configured minimum gap.
- Low and high qualifying score spreads.
- Clear separation of Game Confidence, Data Confidence, and Win Probability.

## Documentation

Update the prediction API contract and relevant architecture documentation with
the definitions, eligibility rules, and presentation boundaries above.

## Statistical Evidence Fallback

When the otherwise-selected player or goalie statistical input is absent and
would produce empty or synthetic zero-valued API statistics, use completed NHL
regular-season games as an absence-only fallback:

1. The most recent 20 games spanning the current and immediately preceding
   season.
2. The most recent 5 games when 20 are unavailable.
3. The most recent game when 5 are unavailable.
4. No statistical payload when no eligible game exists.

`GP` determines which sample tier is available. Genuine zero results inside a
selected sample remain zero. The fallback must expose its tier and sample
provenance, and must not replace valid annual/static prediction inputs or act as
a recent-form override.
