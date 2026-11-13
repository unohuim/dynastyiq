# Automatic goalie projection refresh after season sync

## Files changed

- `app/Listeners/QueueNhlGoalieProjectionRefresh.php`
- `app/Jobs/RefreshNhlGoalieProjectionsJob.php`
- `app/Services/NhlGoalieProjectionRefresh.php`
- `app/Providers/AppServiceProvider.php`
- `app/Jobs/SeasonSumJob.php`
- `app/Listeners/QueueNhlStatsDashboardRefresh.php`
- `app/Services/NhlGamePredictionPayload.php`
- `app/Services/NhlStartingGoalieSelector.php`
- `app/Services/NhlProjectedTeamMatchupSimulator.php`
- `tests/Feature/NhlGoalieProjectionRefreshTest.php`
- `docs/architecture/stats/NhlGoalieSeasonProjections.yaml`
- `docs/architecture/stats/NhlGoalieWorkloadProjections.yaml`
- `docs/architecture/stats/NhlStatsCacheRefresh.yaml`
- `docs/ENUMS.md`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- This review document.

The existing committed `NhlSeasonStatsSynced` event now admits an automatic refresh
of that target season's existing goalie configuration. It keeps the configuration's
source season, TOI version and optional SAT model. This is not a new goalie skill
formula or a switch to rolling current-season history.

One existing workload job per current goalie runs first. Only after every workload
child succeeds does the performance batch begin. Existing profile/model prerequisites
remain mandatory. Positive projected workloads must have usable performance rows
and compatible EV/all buckets before the version publishes. A season with no existing
configuration is skipped instead of creating a new model or training anything.

New versions are isolated by `sync-goalie-` names and published atomically. API,
simulator and starter reads exclude unpublished automatic versions, including explicit
version reads and workload fallback. Existing manual-version selection is retained.
Same-season refreshes serialize across both batches; their coordinator jobs defer on
contention, and existing batch identities prevent redeliveries rebuilding the version.

The originating run records goalie progress in its existing payload, keeping the
cache and aggregation fields intact. Completion waits for all applicable preparation.
Failures retain the previous usable projections and the first meaningful error.
Saved prediction revisions remain immutable; fresh prediction runs capture refreshed
ratings. This change does not rerun predictions or backfill old contribution fields.

## Minimum coverage matrix

COVERED means authored assertions, not executed tests.

| Contract | Status | Test names |
| --- | --- | --- |
| Existing event registration and post-commit listener, including no admin run | COVERED | registers the existing committed season event with the goalie refresh listener; queues a refresh after a sync without requiring an admin run |
| Preserve source/TOI/SAT configuration; skip unrelated seasons | COVERED | preserves the existing source season and TOI configuration; preserves an existing SAT model selection; does not create projection configurations for unrelated seasons |
| Pending state and duplicate admin delivery | COVERED | marks goalie preparation queued before the cache publisher can complete the sync; does not dispatch the same admin sync twice |
| Cross-stage lease, contention, expiration and safe ownership | COVERED | defers a concurrent season refresh without creating workload jobs; releases the season lease when workload preparation fails; does not release another refresh lease when failing stale work; rejects expired leases before performance preparation |
| Redelivery idempotency | COVERED | does not rebuild a version whose workload batch already exists; does not rebuild a version whose performance batch already exists |
| Workload → performance ordering, cancellation and queue routing | COVERED | queues only workload children before their batch succeeds; dispatches performance continuation only from a successful workload completion; rejects incomplete workload rows before performance preparation; queues performance jobs using the new workload version and unchanged settings |
| Missing prerequisites fail visibly and release the lease | COVERED | surfaces missing performance prerequisites without publishing or retaining the lease |
| Missing rows, missing buckets and unusable rates cannot publish | COVERED | refuses to publish missing goalie rows and retains the previous version; refuses to publish a goalie row without its compatible buckets; refuses to publish unusable goalie rates despite a completed child job |
| Atomic publication and selection guards | COVERED | publishes both stages together and releases the lease after complete coverage; makes both prediction and starter selectors ignore unfinished automatic versions; retains existing manual draft selection while hiding automatic drafts; makes published automatic versions selectable; does not select archived automatic versions |
| Independent cache/goalie completion and failure preservation | COVERED | holds season sync completion until both independent publishers are ready; does not complete a sync before aggregation commits; keeps cache publication from completing a pending or failed goalie refresh; keeps aggregation completion from bypassing pending goalie preparation; preserves other sync payload fields when surfacing a goalie failure; retains the original failure when batch cancellation is reported afterwards |
| Current team replaces stale projection team without rewriting old output | COVERED | rebuilds workload team assignment from the canonical player instead of old projections |
| HTTP submission → preparation DB status → HTTP admin read | COVERED | exposes a post-sync goalie preparation failure through the authorized admin status read |

## Requirements checklist

1. SATISFIED: listen to the existing committed season-sync event.
2. SATISFIED: workload children precede performance children on `projections`.
3. SATISFIED: reuse current canonical team assignments and configured historical inputs.
4. SATISFIED: publish complete automatic versions; preserve previous output on failure.
5. SATISFIED: serialize concurrent refreshes and avoid redelivery rebuilds.
6. SATISFIED: expose failures and wait for goalie preparation before reporting sync completion.
7. SATISFIED: preserve projection formulas, edge weights, prediction revisions and public field shapes.
8. SATISFIED: no migrations, production changes, operational commands, test execution or push.

## Endpoint × verb authorization/scope checklist

No endpoint or authorization rule was added or changed.

| Endpoint / verb | Case | Test |
| --- | --- | --- |
| POST `admin.nhl-game-imports.season-sync` | Guest 401; ordinary user 403 | retains guest and ordinary-user restrictions on goalie-related sync progress |
| Same | Super-admin acceptance and post-sync failure tracking | exposes a post-sync goalie preparation failure through the authorized admin status read |
| GET `admin.nhl-game-imports.status` | Guest 401; ordinary user 403 | retains guest and ordinary-user restrictions on goalie-related sync progress |
| Same | Super-admin reads retained failure from the HTTP-created run | exposes a post-sync goalie preparation failure through the authorized admin status read |
| Organization/owner scope | Not applicable | Existing global NHL imports remain super-admin-only |

## Frontend component checklist

Not applicable: no frontend files changed. The existing admin run status, payload
and error surfaces expose progress; no layout or loading indicator is added.

## Per-file test count and execution

`tests/Feature/NhlGoalieProjectionRefreshTest.php`: **35** `it(...)` declarations,
**46** cases after dataset expansion. Existing suites were not modified.

Checks performed: source/dependency tracing, fixture/schema and callback review,
declaration counting and `git diff --check`. Tests and CI were not run under AGENTS.md.

Suggested human checks:

```sh
php artisan test tests/Feature/NhlGoalieProjectionRefreshTest.php tests/Feature/NhlStatsDashboardTest.php tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php tests/Unit/ProjectionQueueRoutingTest.php
```

No migration is required. After deployment and worker reload, the next successful
season sync queues the refresh. That refresh still requires the existing projection
prerequisites. Subsequent prediction runs receive the new values; already saved
predictions keep their original payloads and timestamps.
