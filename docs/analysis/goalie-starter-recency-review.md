# Goalie starter selection: newest valid report

Approved November 9, 2026. Before puck drop, select the latest eligible expected
or confirmed report for the exact game/team by server-recorded `fetched_at`,
then observation ID, descending. Manual UI/API G1 submissions and goalie-picker
decisions participate in the same order as RotoWire, public-lineup and NHL
observations. Confirmed status is retained as evidence, not a reason to select
an older report. Unknown reports cannot hide earlier valid reports. External
publication dates cannot determine recency.

Canonical policy is owned by
[NhlPlayerAvailability.yaml](../architecture/imports/NhlPlayerAvailability.yaml).
Manual skater-lineup selection, attribution, date/verification guards,
split-squad rejection, source history and live NHL presentation retain their
existing rules. Explicit request overrides remain first before puck drop; the
durable NHL starter lock stays first afterward. Without an eligible observation,
the existing stored-official/season/workload fallback chain remains available.

The prediction dressed roster also aligns its G1 to a selected current provider
observation, rather than only replacing G1 for manual/NHL sources. If the chosen
starter was listed as G2, swap the old G1 into G2. Original lineup observations
are never rewritten. This does not invent a new backup when one is unavailable.

## Production finding motivating the change

Game 2026020283, NYR at EDM: observation 148524 came from gner8's November 7
lineup submission (lineup observation 1125), listing Tristan Jarry G1 and Matt
Tomkins G2. Its `provider=manual` resulted from the lineup importer, rather than
a separate goalie-picker action. Later November 9 RotoWire observations named
Devon Levi confirmed. The old manual-first ranking selected Jarry regardless
of those newer observations. Under the new pregame policy the newer eligible
Levi report wins. This finding is not a claim that production has been deployed
or that existing saved predictions have been rewritten.

## Cache and schema

The input preparation policy version changes to invalidate cached old-policy
response/prepared-input fingerprints. Existing configuration fingerprints
already cover that version, so no cache flush or new cache mechanism is added.
Context participant fingerprints will require matching the newly selected
starter where the identity changes. Actual scoring remains independently
versioned; the adopted neutral goalie multiplier is unchanged. No migration,
source-data deletion, worker restart or operational data change was performed.

The separate approved three-hour stale-response retention change is independent
of this policy. Its invalidation rules still prevent an obsolete policy from
satisfying a current fingerprint.

## Authored coverage matrix

Coverage below means authored assertions, not executed or passing tests.

| Category | Coverage |
|---|---|
| A validity/scope | Existing date, verified-current-lineup, game/team and split-squad cases remain; newer unknown reports cannot suppress a valid starter |
| B order | Saturday manual versus Monday expected/confirmed; newer manual versus confirmed; status changes within a provider; same-time ID ties; publication timestamp cannot forge recency |
| C immutable evidence | `replaces a Saturday manual decision with Mondays provider report without rewriting history`; `lets a newer provider starter supersede a submitted G1 without replacing the manual skater lineup` |
| D/E API lifecycle | Partner POST lineup stores G1; later provider evidence is persisted; GET game lineup exposes the new starter while retaining the accepted manual lineup |
| F queue/cache boundary | No queue assignment changes; versioned configuration participates in existing response and prepared-input fingerprints |
| G frontend | No component change; existing picker and public selected-starter fields consume the shared selector |

Additional focused coverage: `retains explicit request overrides before puck
drop`; `preserves the locked NHL starter after puck drop over newer reports and
request overrides`; `aligns the dressed G1 with a newer provider starter and
preserves the other goalie`. Existing contrary status/source-priority cases
were updated to the approved recency policy.

## Requirements checklist

- Latest eligible report wins across sources before puck drop.
- Server observation time and ID control ordering; older confirmation does not override newer expectation.
- Manual submitter attribution remains intact.
- Unknown reports, stale linked lineups and split-squad conflicts retain validity guards.
- Prediction dressed G1 follows selected provider evidence.
- Locked NHL starters remain authoritative; explicit pregame request overrides remain supported.
- Docs and policy cache version match the change; no new table or migration.
- Tests/CI and production prediction/import/sync commands were not run.

## Endpoint and authorization scope

| Endpoint | Verb | Scope | Named coverage |
|---|---|---|---|
| `/api/nhl-lineups` | POST | Existing scoped partner authorization | Existing scope/attribution cases; new provider-supersession lifecycle case |
| `/api/nhl/lineups/{game}` | GET | Existing NHL statistics read scope | New provider-supersession lifecycle case |
| `/api/nhl-starting-goalies` | GET | Existing NHL statistics read scope | New Saturday/Monday public read case |
| `/games/{game}/goalies`, `/games/{game}/starting-goalie` | GET, POST | Session super-admin only | Existing `protects both picker endpoints`; existing picker target/player rejection cases |

No route, token scope, organization ownership, UI editing control or frontend
state transition changes are introduced.

## Per-file authored test counts

| File | it declarations, including nested suites |
|---|---:|
| `tests/Feature/NhlAvailabilityApiTest.php` | 181 |
| `tests/Feature/NhlLineupSubmissionsApiTest.php` | 46 |
| `tests/Feature/NhlSatPredictionServicesTest.php` | 59 |

Dataset cases expand these counts. Full suites remain in the repository. Only
static `git diff --check` verification was performed. The human may run:

```sh
php artisan test tests/Feature/NhlAvailabilityApiTest.php tests/Feature/NhlLineupSubmissionsApiTest.php tests/Feature/NhlSatPredictionServicesTest.php
```
