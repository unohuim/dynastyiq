# Horizon worker allocation

Each Horizon instance starts twenty workers:

| Queue | Workers | Work |
| --- | ---: | --- |
| `projections` | 12 | Expected-goal model builds; skater/goalie, staff and official profiles; player/goalie/TOI projections; SAT model profiles, /60 and comparisons; SAT Engine discovery/ranking; next-game evaluations; historical pregame-context builds |
| `default` | 5 | Regular imports, NHL schedule discovery, game import/fact-building pipelines, and other general jobs |
| `fantrax` | 2 | Fantrax imports and syncs |
| `lineups` | 1 | Anticipated lineups and live prediction cache refreshes |

Model jobs set their own queue; model batches also explicitly select `projections`
so batched children and later loader additions stay on that queue. Existing
progress checkpoints, overlap locks, dispatch pacing, and retries are unchanged.
New SAT Engine discovery runs queue every game in their current stage immediately;
there is no fixed-chain dependency between games. Horizon's available projections
workers control simultaneous execution. Ranking still runs across up to sixteen
independent pairs (one lane when only one pair exists). Pause and resume an older
automatic run to upgrade scheduling without discarding results. Worker configuration
is unchanged; later stages wait for the current stage to finish.
Interactive synchronous predictions are not converted to background jobs.

## Deploying the change

No migration is required. Deploy code and configuration together, rebuild cached
configuration using the normal deployment process, and gracefully restart Horizon:

```sh
php artisan config:cache
php artisan horizon:terminate
```

The server's process monitor must restart Horizon after it exits. These commands
are operational steps for the administrator, not run automatically by this change.
Keep one managed Horizon instance on the dedicated server if twenty total workers
is the target: a second instance/server starts another twenty.

Already queued jobs are not moved. Previously created batches retain their saved
queue options, so their children may continue on `default` until those builds
finish. New standalone continuations use the updated job constructors. Do not
clear or replay queues merely to relocate work.

Horizon processes Redis queues. Existing connection selection is unchanged; jobs
dispatched to a database connection still need that connection's worker and are
not consumed by Horizon. Verify the dedicated deployment uses Redis for the jobs
intended for Horizon.

The new projections supervisor keeps the existing heavy-work timeout of 3660
seconds, above the longest model job timeout (3600) and below the configured
reservation expiry (at least 3900). Default retains its previous timeout so old
model jobs can drain safely. Worker counts do not impose a CPU or RAM ceiling;
monitor host load, database contention, queue waits, and failed jobs after rollout.

## Focused verification

```sh
php artisan test tests/Unit/ProjectionQueueRoutingTest.php
php artisan test tests/Feature/AdminControlPanelTriageTest.php --filter='allocates twenty dedicated Horizon workers'
```

Routing coverage includes all 34 model job constructors and their serialized queue
selection, a dispatched parent/child projection batch, preserved default/provider
routing, the twenty-worker allocation, and the projection timeout/reservation order.
No HTTP authorization or frontend behavior is changed by this worker allocation.
