# NHL Game Boxscore Response

## Endpoint

```text
https://api-web.nhle.com/v1/gamecenter/{gameId}/boxscore
```

Config key:

```text
nhl.boxscore
```

Current DynastyIQ consumers:

- `App\Services\ImportNhlBoxscore`
- `App\Services\SumNHLPlayByPlay`
- `App\Services\CompareNhlPbPBoxscore`
- `App\Services\ValidateNhlGameSummary`
- `App\Services\ImportNhlShifts`
- `App\Services\NhlPlusMinusCalculator`
- `App\Jobs\RefreshNhlGoalieDecisionJob`

## Purpose

This is the official player game total response. DynastyIQ stores boxscore rows as validation targets and uses selected official fields for documented reconciliation when PBP or shiftcharts are incomplete or provider-ambiguous.

## Observations For DynastyIQ

Boxscore is official for comparable player totals, but it is not the raw event source. It must validate or reconcile computed summaries rather than replace PBP-derived event stats wholesale.

For shiftchart mismatches, boxscore TOI and shifts become the summary values while the original shiftchart deltas remain auditable.

## Field Map

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `awayTeam`, `homeTeam` | object | Team id/abbrev/score context. | Game team identity in boxscore response. | Team id for stored boxscore rows. | Current team identity. |
| `playerByGameStats` | object | `awayTeam`, `homeTeam`. | Team-scoped player stat groups. | Source object for `nhl_boxscores`. | Event derivation by itself. |
| `playerByGameStats.*.forwards` | array | Skater rows. | Forward official game totals. | Stored skater boxscore rows. | Position eligibility beyond this game. |
| `playerByGameStats.*.defense` | array | Skater rows. | Defense official game totals. | Stored skater boxscore rows. | Position eligibility beyond this game. |
| `playerByGameStats.*.goalies` | array | Goalie rows. | Goalie official game totals. | Stored goalie boxscore rows and goalie decision refresh. | Goalie on-ice event proof. |
| `*.playerId` | integer | NHL player id. | Player identity for game row. | Natural key with game/team. | Canonical player creation without landing when avoidable. |
| `*.name.default` | string | Player display name. | Game-scoped display name. | Boxscore row context. | Canonical identity by itself. |
| `*.position` | string | `C`, `L`, `R`, `D`, `G`. | Game row position. | Skater vs goalie comparison map. | Fantasy eligibility. |
| `*.goals`, `*.assists`, `*.points` | integer | Counting totals. | Official scoring totals. | Validation targets. | Event timeline. |
| `*.plusMinus` | integer | Plus/minus. | Official plus/minus total. | Validation/reconciliation target after event-unit links. | Unit construction by itself. |
| `*.pim` | integer | Penalty minutes. | Official PIM total. | Validation target with match-penalty tolerance. | Raw penalty event list. |
| `*.toi` | string | `MM:SS`. | Official time on ice display. | Stored raw TOI and parsed seconds. | Shift interval timing. |
| `*.shifts` | integer | Shift count. | Official shift total. | Stored target for validation and shiftchart reconciliation. | Raw shift rows. |
| `*.sog`, `*.hits`, `*.blockedShots` | integer | Skater totals. | Official skater categories. | Validation targets. | Shot/event geometry. |
| `*.faceoffWins`, `*.faceoffLosses`, `*.faceoffWinningPctg` | number | Faceoff totals/percentage. | Official faceoff context. | Stored boxscore and validation where supported. | League-specific fantasy scoring by itself. |
| `*.powerPlayGoals`, `*.powerPlayAssists`, `*.shortHandedGoals`, `*.shortHandedAssists` | integer | Special-team point totals. | Official skater splits. | Stored boxscore, partial validation. | Strength event derivation without PBP. |
| `goalies[].goalsAgainst`, `saves`, `shotsAgainst` | integer | Goalie totals. | Official goalie-facing totals. | Goalie summary reconciliation and validation. | Shot locations or event sequence. |
| `goalies[].evenStrengthShotsAgainst`, `powerPlayShotsAgainst`, `shorthandedShotsAgainst` | string | `saves/shots`. | Strength split save/shot totals. | Parsed to save/shot split columns. | Goals-against split without companion fields. |
| `goalies[].evenStrengthGoalsAgainst`, `powerPlayGoalsAgainst`, `shorthandedGoalsAgainst` | integer | Goals against splits. | Official goalie GA split totals. | Stored and validated. | Event strength timeline. |

## Parser Contract

- Natural identity for stored rows is game id plus player id plus team id.
- Parse `toi` into seconds when present.
- Parse goalie strength shots-against strings as saves/shots.
- Treat boxscore as validation authority for comparable totals.
- Do not treat boxscore as proof of event order, shot coordinates, or unit-on-ice context.
- Match-penalty PIM differences may be tolerated only under documented PBP evidence rules.
- Official boxscore plus/minus is the final target when exact shift boundaries make linked PBP goal attribution ambiguous.
- Official boxscore TOI/shifts may be used in summaries only under documented tiny reconciliation, zero-appearance goalie reconciliation, or shiftchart-mismatch rules.

## Expected Normalized Output

- `nhl_boxscores` rows with official skater and goalie totals.
- Goalie game-summary decision and fantasy fields when refreshed from boxscore.
- Validation deltas when computed summaries disagree with boxscore targets.

## Open Verification Questions

- Are goalie decision fields consistently present across all supported game types?
- Are faceoff field names stable across historical seasons?
- Does boxscore ever include scratched/dressed-but-unused skaters in supported games?
