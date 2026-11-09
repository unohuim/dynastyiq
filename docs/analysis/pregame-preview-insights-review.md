# Pregame preview explanations and insights

Approved first version: Why this pick and Matchup insights on public previews,
using saved data only. No migration, scoring changes, provider imports or
prediction reruns are part of this work.

## Implementation and ownership

`NhlPublicGamePage` projects analysis inside the existing deferred matchup
section. First paint still reads only the lightweight overview. The displayed
prediction exposes its revision ID; the browser pins its matchup request to that
revision. Explicit IDs are constrained to a successful canonical revision for
the same game. Older revisions retain their original output and provenance.

Why this pick shows saved team scoring/SOG and three positive projected goal
contributors per side, sorted by total goals, with EV/PP split and share of the
saved team projection. Period names and blend weights come from saved evidence;
no current engine settings are used to invent missing provenance. PP sources
identify player goals/GP and subsequent team allocation, rather than claiming
intermediate bucket shooting conversion directly sets final PP goals.

Matchup insights use committed same-model context and only players present in
the displayed forecast roster. Team, opponent and player/team-parent identities
must match. Cutoffs cannot exceed request time or scheduled start. Context may
have refreshed independently of the saved prediction; its cutoff is displayed
separately from prediction time. Insights are explicitly descriptive.

Source selection and ranking are canonical in
`docs/architecture/ui/NhlPublicGamePage.yaml`. Available team metrics include
GF/GP, GA/GP, SAT/GP, Corsi% and SH%. Player candidates include SAT/GP, SOG/GP,
G/GP, PTS/GP, SAT/60, SH% and saved PP G/GP/SOG/GP. Team PP/PK windows absent
from saved context are not reconstructed. Overall context statistics describe
their stored history; they are not relabelled as the engine's retained EV bucket
projections.

The five-minute analysis cache is keyed by revision plus committed context
update/run/count tokens. PostgreSQL projects compact roster fields and distinct
source labels inside the database; PHP never loads complete private bucket trees
on that path. Context reads select the required windows and forecast players.
No raw historical results are queried for insight selection. Existing matchup
form/starting-goalie reads keep their separate behavior.

## Changed files

- `app/Services/NhlPublicGamePage.php`
- `app/Http/Controllers/NhlGamesController.php`
- `resources/js/pages/Games/Show.vue`
- `resources/js/pages/Games/game-panels.js`
- `tests/Feature/NhlPublicGamePageTest.php`
- `resources/js/pages/games.test.js`
- `docs/architecture/ui/NhlPublicGamePage.yaml`
- `docs/UI_DESIGN.md`
- `docs/ENUMS.md`
- `docs/ARCHITECTURE_INVENTORY.md` (public game page entry only)
- This review document.

## Authored coverage matrix

Coverage below describes written tests; execution remains outstanding.

| Contract | Status | Named coverage |
|---|---|---|
| Public read and privacy | COVERED | allows guests and authenticated viewers to read curated saved contributors |
| Source periods and weights | COVERED | reads actual saved source periods and blend weights; does not guess source periods when a historical revision lacks provenance |
| Top three and EV/PP split | COVERED | ranks the top three contributions and keeps EV and PP separately |
| Thresholds and counts | COVERED | rejects short samples and differences below the approved threshold; accepts exactly five games and exactly twenty five percent difference |
| Opponent windows and percentage units | COVERED | includes opponent history and labels the sample explicitly; formats team head to head percentage facts as percentages |
| Ranking and balancing | COVERED | balances six distinct facts across the teams and includes numeric comparisons; deduplicates repeated windows for the same player metric |
| Context/model/roster boundaries | COVERED | does not use other models or uncommitted context; omits player history outside the displayed prediction roster; excludes contexts whose source cutoff is still in the future |
| Missing/zero evidence | COVERED | does not turn a zero Train baseline into an infinite percentage; shows unavailable analysis when no saved canonical prediction exists |
| Revision pinning | COVERED | pins explanations to the displayed revision when a newer prediction arrives; rejects malformed revision selections; does not substitute another revision when a requested saved revision is absent; does not expose another games revision through a pinned explanation request; does not publish a request variant as the canonical pregame explanation |
| Read-only/first paint | COVERED | keeps the first paint free of analysis and does not generate a prediction |
| Cache refresh | COVERED | refreshes cached facts when committed context is updated |
| Frontend rendering | COVERED | shows saved contributions and historical insights with values periods and sample sizes; shows honest missing analysis states and never guesses player source periods |
| Frontend lifecycle/allowlist | COVERED | keeps preview explanations hidden after the game becomes live; merges curated analysis only through the matchup panel without changing public odds; pins the deferred matchup explanation to the displayed prediction revision |

## Requirements checklist

1. Two distinct preview sections: SATISFIED in implementation.
2. Three projected contributors per team with EV/PP/share/source periods: SATISFIED
   when saved evidence exists; missing provenance is shown as unavailable.
3. Saved recent/opponent context compared with Train, five-game minimum and
   25% departure: SATISFIED.
4. Up to six distinct balanced facts with numbers, periods, units and GP:
   SATISFIED; fewer facts remain valid when evidence is insufficient.
5. Deferred cached projection without new predictions or private JSON exposure:
   SATISFIED.
6. Documentation/regression coverage: SATISFIED as authored; not executed.
7. No migration or new abstraction/dependency/global browser state: SATISFIED.

## Endpoint/authentication scope

| Endpoint | Verb | Scope | Coverage |
|---|---|---|---|
| `/games/{game}` | GET | Public, no new editing authority | Existing guest/ordinary-viewer cases; first-paint case |
| `/games/{game}/details?section=matchup` | GET | Public for guests and authenticated viewers | allows guests and authenticated viewers to read curated saved contributors (both dataset cases) |
| Same, revision selection | GET | Same-game successful canonical revision only | pinned/malformed/absent revision cases |

These are global public NHL facts; no tenant/organization ownership filter or
new mutation is introduced. Existing lineup-management authorization remains
unchanged.

## Frontend checklist and test counts

- Deferred loading and local errors/retry retain their existing tests.
- Analysis allowlisting cannot replace scores or odds.
- Preview/live transitions hide analysis; timers and cancellation remain intact.
- Numeric values, periods, GP and unavailable provenance have rendering tests.
- Fixed clocks, provider/queue fakes, array cache, and existing per-test helpers
  retain deterministic behavior. No new global test functions are introduced.
- Full suites remain in their existing files.

| File | it declarations |
|---|---:|
| `tests/Feature/NhlPublicGamePageTest.php` | 71 |
| `resources/js/pages/games.test.js` | 119 |

Only whitespace review with `git diff --check` was run. PHP/JS suites, PostgreSQL
projection execution and browser visual review remain for human verification;
no tests, CI, scripts or operational jobs were run.
