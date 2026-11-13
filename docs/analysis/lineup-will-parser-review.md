# Full-name lineup parsing: Will

## Files changed

- `app/Services/NhlLineupTextParser.php`
- `tests/Feature/NhlLineupSubmissionsApiTest.php`
- `docs/architecture/imports/NhlAnticipatedLineups.yaml`
- This review document.

The prose filter previously rejected every reference containing the word `will`,
including Will Cuylle and Will Smith. Only a leading `Will ` before a letter is
excluded from that keyword check. The original reference still passes the existing
name-shape check and is returned unchanged for canonical identity resolution.
No player-name dictionary, identity bypass or roster-verification relaxation is
introduced. A syntactically name-shaped unknown still follows existing peer rules.

Both partner and super-admin manual submissions reach the same parser, resolver
and importer. Controllers, membership checks and persistence remain unchanged.

## Minimum coverage matrix

COVERED means authored assertions, not executed tests. Short identifiers below
refer to these exact new test names:

- W1: `retains complete forward cells containing a leading given name Will`
- W2: `continues rejecting prose and malformed references around Will`
- W3: `submits a full editor roster through the API or manual UI and reads the persisted identities`
- W4: `rejects malformed or ineligible editor rosters without replacing accepted lineup evidence`
- W5: `still rejects a known Will player from the wrong team`
- W6: `preserves existing unknown-player peer allowances for Will-shaped references`

| Contract | Status | Tests |
| --- | --- | --- |
| Full Cuylle/Smith rows, numbered comma-delimited editor rows and case variants | COVERED | W1 |
| Standalone/body will, repeated prose, other keywords and malformed cells | COVERED | W2 |
| Complete partner/API and super-admin manual submission → DB → authenticated HTTP read | COVERED | W3 |
| Original text, canonical identities, ordered slots and team assignments retained | COVERED | W3 |
| Twelve forwards/six defensemen, unique identities, no goalie in skater slots | COVERED | W4 |
| Failed submissions preserve accepted lineup and goalie evidence | COVERED | W4 |
| Known wrong-team Will players cannot become unresolved fallbacks | COVERED | W5 |
| Regular-season F3/no-peer rejection; preseason/F4/D3 peer allowances retained | COVERED | W4, W6 |
| Prose does not become a roster from scattered recognized mentions | COVERED | Existing `does not assemble lineup groups from player mentions in prose` in NhlAvailabilityApiTest |

## Requirements checklist

1. SATISFIED: accept full names containing the leading given name Will.
2. SATISFIED: retain other prose and malformed-roster rejection.
3. SATISFIED: cover complete API and manual UI submission paths.
4. SATISFIED: preserve identity, membership, count, duplicate, goalie and unknown-player rules.
5. SATISFIED: no scoring, predictions, dependencies, migrations or production data changes.
6. SATISFIED: no test execution, commit or push performed.

## Endpoint × verb authorization/scope checklist

| Endpoint / verb | Case | Test |
| --- | --- | --- |
| POST `/api/nhl-lineups` | Missing key 401; invalid/revoked/read-only key 403 | Existing `requires the dedicated live write key before any lineup processing` |
| Same | Write permission 201 with full-name roster | W3 |
| POST `/games/{game}/lineup` | Guest 401; ordinary user 403 | Existing `restricts manual lineup submissions to super admins` in NhlAvailabilityApiTest |
| Same | Super admin 200 with full-name roster | W3 |
| GET `/api/nhl/lineups/{game}` | Missing key 401; invalid/revoked/write-only key 403; read permission 200 | Existing `protects lineup reads with the existing read scope` |
| Same | Accepted API/manual submission is readable | W3 |
| Organization/owner scope | Not applicable | Global NHL evidence; existing API scopes and super-admin authorization retained |

## Frontend component checklist

Not applicable: no frontend changes. The manual HTTP submission path is covered
by W3 using the existing super-admin authorization.

## Per-file test count and execution

`tests/Feature/NhlLineupSubmissionsApiTest.php`: **61** `it(...)` declarations,
including six added declarations with **31** dataset cases. Existing declarations
are retained. No other test file was modified.

Checks performed: source-path inspection, static fixture/schema comparison, test
declaration counting and `git diff --check`. Tests and CI were **not run**, as
required by AGENTS.md without separate execution authorization.

Suggested human command:

```sh
php artisan test tests/Feature/NhlLineupSubmissionsApiTest.php tests/Feature/NhlAvailabilityApiTest.php
```

No migration is required. No production connection or data changes were made.
