# Build Predictions review

## Approved repair

Profile building could report completion despite unfinished season snapshots. Existing /60 rows were then mistaken for new results. The repair scopes profile jobs and callbacks to one build, discovers entities in queued pages, and blocks /60 until profiles have verifiably succeeded.

The repository retry defaults were 90 seconds while some supported jobs allow 3,600 seconds. The new database, Redis, and Beanstalkd reservation floor is 3,900 seconds, including when environment overrides are smaller. The default Horizon supervisor timeout is 3,660 seconds. Worker counts are fixed at 9 default, 1 Fantrax, and 1 lineups. Production's previously effective cached configuration was not inspected.

## Behavior and limits

- New profile builds cover **offensive skaters only**, including aggregate training profiles and their required season snapshots. Existing broad batches must drain/cancel and be replaced with a fresh build; old non-offensive jobs fail explicitly rather than running expensive unwanted profile SQL or silently counting as completed.
- Discovery queries run before the short progress transaction, with ownership rechecked afterward. Continuations are submitted first so they do not sit behind their entire page of entity jobs. PostgreSQL progress/lifecycle locks use FOR NO KEY UPDATE, compatible with the foreign-key KEY SHARE locks held by concurrent entity inserts. This removes a lock-upgrade deadlock hazard introduced by the earlier transaction change; the supplied production payload contained no exception, so its actual failure cause remains unconfirmed.
- Both standalone Profiles and combined Build Predictions receive a profile build ID.
- A parent dispatch checkpoint prevents duplicate batches. One initialization transaction clears the previous training and snapshot outputs.
- Each loader discovers at most 100 entity keys from one type/season partition and queues those children plus a continuation. Inserts contain at most 100 jobs. Queued counters grow during discovery; they are not a final denominator until discovery finishes.
- Page checkpoints prevent repeated submission. Entity rows and their completion receipts commit together. A retry that already committed does not rebuild the entity or increment progress again, including zero-row results.
- Receipts contain page identity hashes and compact 100-position completion strings in existing run metrics. Their size grows with pages; no complete run-sized job list, entity hash map, or profile dataset is retained in the loader. This is not a claim of a measured production memory bound.
- Completion requires a successful uncancelled batch, finished discovery, nonzero training entities, and matching training/snapshot counts. Generic stability runs only after those checks.
- Failure leaves the profile completion timestamp null. Old or duplicate profile callbacks cannot convert failure into success or update a newer build.
- Standalone Profiles stops after profiles. Build Predictions retains Profiles → /60 → TOI/GP.
- Standalone /60 requires successful identified profiles, matching counters, actual profile rows, and no later SAT/SOG evaluation. The readiness check is repeated while reserving the run.
- Legacy profile payloads without IDs are obsolete and return without writing. Do not retry an old failed loader as the recovery procedure.
- Redis publication and the SQL transaction are separate stores. Uncertain publication fails the build; this change does not promise automatic recovery after every process crash. Child receipts suppress duplicate writes, and incomplete counts/discovery cannot certify success.

Canonical behavior: [NhlModelRuns.yaml](../architecture/stats/NhlModelRuns.yaml). Stored state vocabulary: [ENUMS.md](../ENUMS.md#sat-profile-build-state).

## Coverage matrix

“Authored” means assertions exist; none were executed.

| Requirement | Coverage | Representative test |
| --- | --- | --- |
| Same-model combined action and HTTP progress | Authored | starts profiles on the same model and exposes the running stage through HTTP |
| Action authorization and overlap | Authored | blocks competing manual profile and evaluation actions during the combined build |
| Ordered advancement and stop on failure | Authored | advances successful profiles to rates without completing the run; records TOI failure instead of successful completion |
| Bounded real database queue inserts | Authored | queues a full page in bounded inserts and preserves the pending continuation |
| Keyset continuation and season identities | Authored | continues with the same cursor and then moves to the next season partition |
| Initialization and dispatch idempotence | Authored | does not clear or enqueue a profile page twice; does not dispatch a second batch when a profile parent is redelivered |
| Cancelled/stale loader | Authored | does not prepare or queue work for a cancelled profile loader; does not prepare or queue work for a stale profile loader |
| Partial queue failure and safe error rendering | Authored | cancels partial loading and retains only bounded SQL diagnostics |
| Snapshot/discovery completion gates | Authored | does not turn an incomplete snapshot batch into successful profiles; requires discovery to finish even when all discovered children have finished |
| Real batch pending lifecycle | Authored | keeps the batch pending until both the loader and its entities succeed |
| Transactional entity output and progress | Authored | commits profile rows and counts once when an entity is redelivered; rolls back an entity write when its builder fails before progress is committed |
| Stale/legacy entity protection | Authored | ignores entity jobs from superseded builds; does not allow legacy unidentified jobs to overwrite a current profile build |
| Standalone profile state and no auto-advance | Authored | starts standalone profiles with a build identity and visible progress; finishes standalone profiles without automatically starting rates |
| Failure cannot become successful completion | Authored | preserves the first failure when a completion callback follows it |
| /60 prerequisite including old rows | Authored | rejects rates when rows exist but profile snapshots are unfinished; rejects rates from legacy completion timestamps without a successful build certificate |
| /60 acceptance and evaluation freshness | Authored | accepts rates after verified profile and snapshot completion; requires new profiles when evaluation was rerun after their build |
| Queue timeout relationship and worker counts | Authored | keeps queue retry reservations above the maximum supported job timeout |
| Cross-stage obsolete failure | Authored | ignores old rate and TOI failures while a standalone profile build owns the model |
| Standalone generation ownership | Authored | does not let an older standalone profile callback complete a replacement build |
| Zero-row and snapshot counter idempotence | Authored | counts a zero-row snapshot only once and separately from its training entity |
| Compact receipts retain distinct entities | Authored | tracks distinct entities in compact page slots without double counting redelivery |
| Offensive-only discovery, snapshots, obsolete jobs | Authored | discovers only offensive skaters across training and required season snapshots; rejects obsolete non-offensive jobs before building their profiles |
| Discovery does not hold progress transaction | Authored | runs entity discovery outside the model progress transaction |
| Ownership changes during discovery | Authored | rechecks build ownership after unlocked discovery before clearing or submitting work |
| PostgreSQL lock contract | Authored | uses a PostgreSQL progress lock compatible with concurrent profile foreign-key checks |

The tests exercise page orchestration with a mocked discovery service. Production PostgreSQL pagination performance and multi-worker contention remain human operational validation, not asserted measurements.

Lock compatibility reference: [PostgreSQL row-level locks](https://www.postgresql.org/docs/current/explicit-locking.html#LOCKING-ROWS). FOR NO KEY UPDATE retains exclusive progress-update protection while allowing foreign-key key-share locks; it does not eliminate every possible database deadlock.

## Requirements checklist

- Source changes cover reservation expiry, paginated loading, profile build identity, completion gates, and /60 readiness.
- PHP tests use Pest, strict types, a frozen clock, fake broadcasts/normal dispatch, and blocked external HTTP.
- Two tests use the isolated database queue and batch repository, without starting a worker.
- No schema migration, dependency change, environment-file edit, or prediction-formula change.
- No CI, tests, imports, queues, migrations, or rebuilds were run.
- Static diff/whitespace review is not runtime validation.
- Full test source is in [NhlBuildPredictionsTest.php](../../tests/Feature/NhlBuildPredictionsTest.php).
- Human runtime validation and final acceptance remain outstanding.

## Endpoint × verb authorization/scope

| Endpoint | Verb | Guest test | Ordinary-user test | Super-admin test |
| --- | --- | --- | --- | --- |
| /admin/nhl-sat-models/{run}/predictions/build | POST | blocks guests from starting prediction builds | blocks ordinary users from starting prediction builds | starts profiles on the same model and exposes the running stage through HTTP |
| /admin/nhl-sat-models/{run}/profiles/build | POST | blocks guests from standalone profile builds | blocks ordinary users from standalone profile builds | starts standalone profiles with a build identity and visible progress |
| /admin/nhl-sat-models/{run}/rate-projections/build | POST | blocks guests from rate builds | blocks ordinary users from rate builds | accepts rates after verified profile and snapshot completion |
| /admin/nhl-sat-models | GET | blocks guests from viewing prediction build progress | blocks ordinary users from viewing prediction build progress | exposes persisted current stage counts in refreshed row HTML |

SAT model administration is global and super-admin-only; organization ownership does not apply. Existing middleware and CSRF behavior remain unchanged. The platform-seeded prerequisite is mocked; authentication middleware remains active.

## Frontend checklist and test count

The existing Blade status cell now renders standalone profile progress, discovery state, and escaped failure text. Existing live-region semantics and menu motion are retained. No JavaScript changed. HTTP/broadcast assertions cover combined progress, standalone progress, and failure escaping.

One modified test file: **66 it(...) declarations** in NhlBuildPredictionsTest.php. No new test files. Existing test declarations were retained or adapted to the new loader contract; additional regression cases cover the incident. The PostgreSQL lock test checks emitted SQL and transactional progress, not a measured concurrent-worker run. Performance has not been benchmarked; no claim is made that the build will return to 40 minutes.

Human-run validation command:

~~~sh
php artisan test tests/Feature/NhlBuildPredictionsTest.php
~~~

## Production deployment and recovery

These are human-run instructions. Pushing code alone does not update a cached queue configuration or an already-running worker.

1. Before deploying, stop starting new model builds. Allow in-flight profile jobs to drain and confirm Horizon has no active work for this model. Cancel any remaining failed profile batch through the existing operational tooling; do not clear unrelated queues or retry the old loader. This matters because already-running workers still execute the old code.
2. Deploy the reviewed files using the existing deployment process.
3. Rebuild production configuration, then gracefully terminate Horizon so its process manager starts workers with the new code/configuration:

~~~sh
php artisan config:cache
php artisan horizon:terminate
~~~

4. Confirm the process manager restarts Horizon, the default supervisor uses timeout 3660, and the effective Redis retry_after is at least 3900. Its graceful-stop allowance must exceed the longest supported 3600-second job; that process-manager setting lives outside the repository and was not changed here. If this installation also has plain database queue workers, restart those through their existing process manager as well.
5. For the failed run from this incident, start a **fresh Build Profiles** after old work has drained. Watch both training and snapshot counts; the total grows during discovery. Wait for successful completion.
6. Then run **Build /60**, followed by **Build TOI/GP**. Alternatively, a fresh **Build Predictions** performs all three stages in order. Do not start both workflows.
7. Verify the selected run's profile build status is complete, profile/snapshot counters match, and the new /60 timestamps are newer than the successful profile rebuild. The old September 24 /60 rows do not prove the September 30 build succeeded.

Existing models without an identified successful profile build must rebuild profiles before a new standalone /60 action. This conservative gate deliberately rejects the misleading legacy completion state reported in the incident.

Laravel documents [retry_after and worker timeouts](https://laravel.com/docs/12.x/queues#job-expirations-and-timeouts), [Horizon timeout ordering](https://laravel.com/docs/12.x/horizon#job-timeout), and [graceful Horizon deployment](https://laravel.com/docs/12.x/horizon#deploying-horizon).

Awaiting human review.
