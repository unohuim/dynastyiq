# Resumable Leaders cache preparation

## Files changed

- `app/Jobs/RefreshNhlStatsDashboardJob.php`
- `app/Listeners/QueueNhlStatsDashboardRefresh.php`
- `app/Services/NhlStatsDashboardPayload.php`
- `app/Services/ProspectEligibilityService.php`
- `tests/Feature/NhlStatsDashboardTest.php`
- `tests/Feature/ProspectEligibilityServiceTest.php`
- `docs/architecture/stats/NhlStatsCacheRefresh.yaml`
- `docs/ENUMS.md`
- This review document.

Queued Leaders refreshes retain one batch task and advance five-game pages for
on-ice counts, xGF, goalie-facing xGA and team attempt totals, followed by
25-player rookie pages and publication. Shared-cache checkpoints contain compact
aggregates, cursors, model selection and the eligibility cutoff. Each successful
page commits before the job releases for its next delivery. Retrying the same
refresh resumes its saved page; a failed publication resumes at assembly.

Current and legacy scoring reads are restricted inside the score union to the
page/model. Existing current-score precedence, SOG-trained expectations,
deduplication, pooled counts and missing-coverage semantics remain. Distinct
game counts can be added across pages because each game occurs in one page.
Rookie eligibility uses unchanged draft/age/career/window rules with grouped
career totals and activity reads after cheap candidate filtering.

The existing projections queue and 180-second job timeout remain. Generations
serialize through a renewable season owner and a page lock. Terminal failure
releases ownership through a separate short lock even when a timed-out worker
has left its long page lock behind. Completed delivery markers prevent duplicate
publication. A newer successful refresh supersedes an older failed checkpoint.
Large completed work is replaced with a small marker. Work expires after six
hours without renewed progress; retained public snapshots keep their existing
lifetime. Source-update events still queue follow-up refreshes; source data is
not held in a long database snapshot during preparation.

Tracked jobs preserve batch, rollup and goalie metadata when recording page
diagnostics. Batch release is not completion: Season Sync still waits for the
final Leaders publication. Previous snapshots remain usable during work or
failure. Unrelated reports, public response shapes and synchronous cache-miss
behavior are unchanged. No migration is required.

## Minimum coverage matrix

COVERED means authored assertions, not executed tests.

| Contract | Status | Tests |
| --- | --- | --- |
| Release unfinished batch member; retain previous snapshot | COVERED | retains the old dashboard while a Leaders delivery releases its unfinished batch task |
| Stable serialized refresh and parent identity | COVERED | preserves the refresh identity and originating run when queued work is serialized |
| Old queued jobs and failure callbacks agree on checkpoint identity | COVERED | recovers legacy refresh ownership from an independently reconstructed failure callback |
| Bounded game pages and process-independent resume | COVERED | bounds game pages and resumes from the saved cursor with a fresh service instance |
| Pooled results match existing formulas; duplicate event links do not inflate xGF | COVERED | matches existing Leaders values and deduplicates on-ice attempts across disjoint pages |
| Later missing scores propagate unavailable values | COVERED | retains incomplete xGF and goalie scoring when a later page lacks a score |
| Current scores retain precedence over legacy predictions | COVERED | uses current SAT scores ahead of legacy predictions within bounded pages |
| Read failure retains prior pages and cursor | COVERED | continues after a source exception without discarding previously committed stages |
| Separate generations serialize and completed delivery is idempotent | COVERED | serializes separate refresh generations until the current publisher completes; does not republish or recalculate a completed refresh on duplicate delivery |
| Diagnostics merge into parent without premature completion | COVERED | records page diagnostics without replacing goalie refresh or rollup progress; retains Leaders diagnostics when batch completion updates the parent run |
| Terminal failure preserves public data and releases ownership | COVERED | releases failed ownership and exposes the failing stage while retaining the dashboard; can release timed-out ownership while the abandoned page lock is still held |
| Waiting and cancellation do not publish or spin | COVERED | waits without busy retries when another refresh owns the season; releases its own preparation owner without publishing when the batch is cancelled |
| Older failed refresh cannot overwrite newer publication | COVERED | prevents an older failed refresh from replacing a newer successful snapshot |
| Failed public cache write retains old value and publication checkpoint | COVERED | does not publish a partial snapshot when cache publication fails and resumes at publication |
| Empty game population and missing model preserve nulls | COVERED | retains missing-model behavior and handles an empty completed-game population |
| Rookie page size and cross-page eligibility | COVERED | bounds rookie eligibility to twenty-five players and preserves eligibility across pages |
| Grouped eligibility preserves per-player scope and career boundaries | COVERED | groups career games across teams without counting playoffs or other leagues; scopes grouped totals and activity to each candidate independently; uses grouped stat reads rather than correlated totals for each player |
| Post-sync event owns preparation; DB fixtures → refresh action → HTTP read | COVERED | tracks the preparation batch on its originating season sync; publishes prepared mobile prospect leaders through the public dashboard response; repairs missing default-season metadata when the Leaders publisher completes |

## Requirements checklist

1. SATISFIED: queued Leaders work is divided into bounded stages.
2. SATISFIED: shared-cache checkpoints resume successful pages across deliveries.
3. SATISFIED: shot score populations are scoped by model/page and rookie totals are grouped.
4. SATISFIED: publication waits for complete assembly and retains the prior value on failure.
5. SATISFIED: season-sync completion still waits for final publication; failures retain stage diagnostics.
6. SATISFIED: regression coverage and canonical cache/phase documentation updated.
7. SATISFIED: no migration, production rerun, queue move, worker operation or test execution.

## Endpoint × verb authorization/scope checklist

No endpoint, verb, permission or public stored field changed. Existing public
scope and authorization tests remain in the dashboard suite:

| Surface | Scope | Tests |
| --- | --- | --- |
| GET /stats | Public guest | lands public stats on the leaders dashboard; publishes prepared mobile prospect leaders through the public dashboard response |
| GET /stats | Authenticated | allows authenticated users to read the same public leaders |
| Shared report preparation | Excludes private perspectives | never prepares private perspectives in the shared report cache |
| Cache-target validation | Rejects unsupported/private refresh targets | rejects private or unsupported report names as public cache refresh targets |

The post-sync event and worker entrypoints retain their existing authorized
dispatch path; this change adds no organization/member/owner scope.

## Frontend component contract checklist

Not applicable: no frontend file, layout or background messaging changed.

## Per-file test count

| File | `it(...)` declarations |
| --- | ---: |
| tests/Feature/NhlStatsDashboardTest.php | 161 |
| tests/Feature/ProspectEligibilityServiceTest.php | 28 |

Both suites retain their deterministic clocks, fake dispatch and prohibited
external-request boundaries. Twenty new staged-preparation cases and three
grouped-eligibility cases were added; existing direct worker tests now drive its
bounded releases to publication. The old fixed query-count expectation changes
from two correlated reads to four scoped/grouped reads for eligible populations.

## Verification and rollout

The scoped diff was reviewed and `git diff --check` passed. No tests or CI ran.
Suggested human command:

`php artisan test tests/Feature/NhlStatsDashboardTest.php tests/Feature/ProspectEligibilityServiceTest.php`

Deploy the same release to web and Horizon and reload workers through the normal
deployment process. Existing public cache keys are unchanged, so deployment does
not discard usable dashboards. A subsequent Season Sync or explicit stats-cache
refresh uses the staged publisher. Previously terminally failed runs do not
automatically become successful. Production recovery or reruns require their own
approved operational plan. Runtime improvement has not been benchmarked.
