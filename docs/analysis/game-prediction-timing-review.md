# Admin prediction Engine Time review

Approved scope: add Engine Time to the Game Predictions table with total elapsed
seconds and clean seconds/minutes formatting. Include queue, preparation and retry
waits; freeze completed work and preserve first worker starts. No migration is
required. No tests, CI, imports, migrations, queue workers or production jobs were
run.

## Changed files

- `app/Http/Controllers/Admin/NhlGamePredictionController.php`
- `app/Jobs/RunNhlGamePredictionJob.php`
- `resources/js/pages/Admin/GamePredictions.vue`
- `tests/Feature/NhlOperationalGamePredictionsTest.php`
- `resources/js/pages/Admin/game-predictions.test.js`
- `docs/architecture/stats/NhlGamePredictionHistory.yaml`
- `docs/ARCHITECTURE_INVENTORY.md` (prediction elapsed-time section only)
- This review document.

Unrelated local import-index/schema/test changes and Finder files remain excluded.

## Behavior and limits

Admin game rows add `engine_time_seconds`, a derived integer or null. The start is
the selected run's existing `created_at`, not a retry's start or a browser clock.
Pending work measures through one server timestamp captured for the response;
terminal work uses its existing `finished_at`. The duration includes queue wait,
pregame dependency wait, engine work, persistence and any recovery wait until the
work item is terminal. It is elapsed wall time, not isolated CPU execution time.
Historical second-resolution timestamp precision is retained.

The first worker `started_at` is set only if absent and is not reset on retries.
The table shows Engine Time beside status/history, using `12s`, `1m 25s`, `5m`,
and the existing five-second poll. It does not create another timer or provider
request. Terminal values remain fixed on later reads. Missing work or terminal
rows without a recorded finish show an em dash. Existing latest prediction and
revision-history behavior is preserved; a tooltip explains that the duration
belongs to the selected run. Disclosure and empty-state rows span all eleven
columns.

## Minimum coverage matrix

COVERED means authored assertions, not executed or passing tests.

| Category | Status | Named coverage |
|---|---|---|
| A validation/auth | COVERED | Existing `blocks guests on every operational endpoint`, `blocks ordinary users on every operational endpoint`, date validation and selected-run validation cases remain |
| B elapsed definition | COVERED | `measures engine time from queue admission before the first worker starts`; `uses each selected runs admission time rather than a later run or worker start` |
| C persistence/retries | COVERED | `preserves the first worker start and total waiting time across preparation retries`; existing revision/run persistence assertions remain |
| D response | COVERED | Admin GET assertions verify numeric `games.0.engine_time_seconds` and null when unavailable |
| E HTTP lifecycle | COVERED | `freezes completed engine time while retaining the duration after later reads` performs POST admission, terminal-state persistence and repeated GETs for completed/unavailable/failed states |
| F queue boundary | COVERED | Existing projections batch assertions plus preparation retry release assertions; no queue assignment or publication behavior changes |
| G frontend | COVERED | `shows an Engine Time column with total elapsed timing explained`; parameterized `formats %s seconds as %s`; `keeps unavailable timing blank for %s`; `updates engine time through the existing polling response`; `keeps revision disclosure rows aligned under the timing column` |

## Requirements checklist

1. SATISFIED in authored code: Engine Time appears in each game row.
2. SATISFIED in authored code: total duration includes admission-to-worker delay,
   preparation, retries and completion; retries do not overwrite first start.
3. SATISFIED in authored code: compact seconds/minutes formatting avoids 300s for
   a five-minute duration.
4. SATISFIED in authored code: current polling refreshes active time; terminal
   durations do not continue growing.
5. SATISFIED in authored code: timing uses the selected run, and absent or unknown
   legacy timing is not fabricated.
6. SATISFIED in authored code: existing schema, route authorization, prediction
   calculations, score/history selection and queue assignments remain.
7. UNVERIFIED: runtime tests and browser rendering; execution was not authorized.

## Endpoint x verb x authorization/scope

| Endpoint | Verb | Scope | Named coverage |
|---|---|---|---|
| `/admin/game-predictions` | GET | Guest denied | `blocks guests on every operational endpoint` GET dataset |
| Same | GET | Ordinary user denied | `blocks ordinary users on every operational endpoint` GET dataset |
| Same | GET | Super-admin allowed | New elapsed-time GET cases and `allows super admins to read stored schedule rows without calculating` |
| `/admin/game-predictions/runs` | POST | Guest denied | `blocks guests on every operational endpoint` POST dataset |
| Same | POST | Ordinary user denied | `blocks ordinary users on every operational endpoint` POST dataset |
| Same | POST | Super-admin allowed | New admission-to-elapsed and lifecycle tests plus `admits one date and persists the requesting admin and stack` |

The workflows are global super-admin operations; no organization scope or new
public/partner endpoint is introduced.

## Frontend component checklist

- Deterministic server-supplied seconds; no browser-clock calculation.
- Existing polling, cancellation, date navigation, pagination and unmount cleanup
  remain unchanged.
- Running and completed state transitions are asserted through mocked polls with
  fake timers and a fixed clock.
- Seconds, minute boundaries, exact minutes, unknown/malformed values and zero
  are covered.
- Header/cell scope explanation and revision row colspan are asserted.
- No new global JavaScript state, timers, motion or provider requests.

## Per-file test counts

| File | Authored it declarations |
|---|---:|
| `tests/Feature/NhlOperationalGamePredictionsTest.php` | 39 |
| `resources/js/pages/Admin/game-predictions.test.js` | 45 |

Dataset cases expand these counts. Both full suites remain available at the
listed repository paths and exceed the twenty-test minimum. Only
`git diff --check` was used for static whitespace verification. The human can
run the repository-approved Pest and frontend test commands scoped to these two
suites after review. No tests or CI commands were run by the agent.
