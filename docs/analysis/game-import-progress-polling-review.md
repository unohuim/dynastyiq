# Game import progress recovery

## Files changed

- `resources/js/admin/admin-hub.js`
- `resources/js/admin/admin-hub.test.js`
- `docs/architecture/admin/ImportBroadcastStream.yaml`
- This review document.

Progress reads retain the existing five-second polling interval. A 15-second
deadline now covers fetching and decoding each response. Failed reads schedule
another attempt even before the initial active-run state is known. Background
failures retain displayed data and do not introduce loading or error messages.
Only successful responses update progress; late timed-out responses cannot
overwrite a newer read. Foreground status errors clear on recovery without
clearing unrelated manual-action errors. Existing queued-page coalescing remains.

## Minimum coverage matrix

COVERED means authored assertions, not executed tests.

| Contract | Status | Test |
| --- | --- | --- |
| Retry quietly; advance real-shaped Season Sync progress | COVERED | retries failed background reads quietly and advances retained season sync progress |
| Failed HTTP response retains progress and retries | COVERED | preserves progress and schedules recovery after an HTTP error |
| Invalid JSON and run collection retain data | COVERED | does not replace retained progress with malformed JSON; rejects an invalid run collection without losing the last successful read |
| Initial failure before active runs are known recovers | COVERED | recovers an initial failure even before active runs are known and clears its own error |
| Recovery preserves unrelated action error | COVERED | does not clear a newer manual action error when a failed status read recovers |
| Stalled fetch and late response | COVERED | releases a stalled fetch and ignores its late response after newer progress arrives |
| Stalled response decoding | COVERED | also bounds stalled response body parsing |
| Terminal response ends polling and clears deadline | COVERED | stops polling after a successful terminal read and clears the request deadline |
| Inactive tab stops recurring retries | COVERED | does not schedule recovery after the user leaves game imports |
| Latest queued page survives a timeout | COVERED | drains the latest queued page after a timeout without overlapping live requests |

## Requirements checklist

1. SATISFIED: failed and stalled progress reads recover without a page refresh.
2. SATISFIED: background retries preserve mounted data and existing feedback quietly.
3. SATISFIED: deadlines cover response decoding; late replies do not mutate progress.
4. SATISFIED: single-flight reads, latest queued page and active-tab polling remain.
5. SATISFIED: deterministic regression coverage uses fake timers and mocked requests.
6. SATISFIED: no migration, dependency, endpoint, authorization, calculation or worker changes.

## Endpoint × verb authorization/scope checklist

Not applicable: no endpoint, verb, authorization or persistence contract changed.
The existing authenticated admin status GET is still the authoritative source;
the frontend adds an AbortSignal without changing its URL or Accept header.
Existing exact request assertions retain those checks. No backend writes or new
externally surfaced stored fields require a new request → DB → HTTP test.

## Frontend component contract checklist

- State: busy/loading, retained progress, error ownership and retry state asserted.
- Payload: retained runs and cache-completion values asserted; invalid data rejected.
- Lifecycle: request deadline, cancellation, queued-page handoff, terminal reads and
  inactive-tab behavior asserted with controlled timers.
- Feedback: background failures do not introduce errors or loading state.
- Determinism: no external requests, live jobs, randomness or wall-clock waits.
- Templates and layout are unchanged.

## Per-file test count

`resources/js/admin/admin-hub.test.js`: 126 `it(...)` declarations, including
11 new recovery cases. Existing status-request expectations now include the
AbortSignal while retaining their URL and Accept-header assertions.

## Verification

Tests and CI were not run, as required by AGENTS.md without separate instruction.
Human command: `npm test -- --run resources/js/admin/admin-hub.test.js`.
The scoped diff was reviewed and `git diff --check` passed.
No production commands or data changes are part of this implementation.
