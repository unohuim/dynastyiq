# DynastyIQ Evaluation Workspace — UI Design Prompt

Design a sleek, modern admin UI for **DynastyIQ’s NHL forecasting evaluations**. Provide a visual design or interactive mockup—not just design advice.

Our application uses Laravel, Vue/Inertia, and Tailwind. The existing page is `/admin/nhl-sat-models/next-game`. This is an analytical workspace: prioritize readable tables, clear progress, and easy comparisons over decorative dashboard cards.

## Core workflow

An **Evaluation is the required container** for all forecasts and comparisons. Multiple evaluations can belong to the same SAT model.

The model already defines its training seasons, projection season, and optional Test season. Don’t ask users to enter those again or introduce a separate live/test toggle.

**Start Evaluation:**

- Creates a named evaluation linked to the selected model.
- Calculates initial player quarter and season estimates.
- Does **not** automatically predict games.

Inside an evaluation, users select game days to forecast, compare completed results, and advance chronologically. Completed games update recent history and may revise future quarter and season estimates. Earlier forecasts must remain available exactly as originally made.

## Design these views

### 1. Evaluation index

Show evaluations with name, model, projected season, status, progress, and last activity. Include search and a prominent Start Evaluation action with a compact creation drawer.

### 2. Evaluation detail

Make the selected evaluation unmistakable. Show model, Train seasons, projected season, status, and current progress.

Suggest clear navigation between:

- **Outlook:** initial and revised player quarter/season estimates.
- **Games:** game days, games, saved forecasts, and actual results.
- **Comparisons:** performance of the saved forecasts.
- **History:** changes to estimates and what prompted them.

### 3. Initial outlook

After preparation, show the initial estimates before any games have been forecast. Provide a clear next action to select the first game day.

Use strength-neutral metric selectors:

- SAT/60: shot attempts per 60 minutes.
- TOI/GP: ice time per game.

Provide a separate strength selector: **EV, PP, PK, All**. Never silently combine strengths.

### 4. Game-day workspace

Group games by date, with expandable game rows showing the lineup’s players.

Before a game, show:

- Train and pregame context.
- Previous-game performance.
- Anticipated quarter average.
- Expected next-game value.
- Higher, lower, or unchanged versus the previous game.

After completion, show actual performance alongside the original forecast, directional correctness, and forecast error.

Use explicit actions such as **Predict selected day**, **Compare completed games**, and **Advance to next day**. Include Pause/Resume and clear queued, processing, awaiting-results, and failed states.

## Non-negotiable data presentation

Our familiar summary-table columns are:

**Name | Train | L5 | L10 | L20 | LSeason | Q1 | Q2 | Q3 | Q4 | Test | ET | ELS**

- Train means the model’s training data.
- L5/L10/L20 mean the previous player appearances.
- LSeason means the previous season.
- Q1–Q4 represent our approximately 20-game blocks. Their precise appearance/calendar boundaries are still being finalized.
- Clearly distinguish **anticipated quarter values** from **actual quarter values**.
- Test contains actual projected-season performance available so far; unfinished periods must not look complete.
- ET and ELS are signed percentage changes from Train and LSeason respectively, using the original reference as denominator.
- Keep forecast-versus-actual errors separately labeled.
- Show metric values in performance columns—not error percentages.
- Include percentages of players within 10% and 20% error where applicable, with the comparison clearly identified.
- **League average must appear as its own table row**, never as an aside.
- Team values must come from the players in that game’s lineup. Teams are collections of players, not independent forecast entities.
- Missing data must look different from a genuine zero.
- Mark sample size and partial-period coverage without cluttering every cell.

Use illustrative players: **Filip Forsberg, Connor McDavid, David Pastrnak, Blake Coleman, and Auston Matthews**. Label mock numbers as illustrative.

## Visual direction

Restrained, polished sports analytics—not a sportsbook. Strong typography, generous spacing, subtle borders, accessible contrast, and purposeful color. Use sticky table headers and name columns, sensible horizontal scrolling, and usable mobile layouts.

Avoid excessive cards, charts, jargon, and modal interruptions. Let the user inspect a quarter estimate, open a game, and understand how the estimate evolved without losing their place.

Please show the main screens and interactions, especially the transition from **initial estimates → first game-day forecast → completed-game comparison → revised outlook**.
