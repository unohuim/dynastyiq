# Local lineup discovery audits

When Laravel runs in the `local` environment, every post returned by an X anticipated-lineup search is written beneath a team directory:

```text
docs/troubleshooting/lineups/{TEAM_ABBREV}/x_post_{X_POST_ID}.md
```

Each file records the approval or decline reason, search and game context, complete post text, canonical player matches, parsed lineup slots, and raw X post JSON. Repeated searches overwrite the file for the same X post ID with its latest evaluation.

At the beginning of each local import, every generated `.md` file beneath this directory is deleted recursively. This `README.md` and the existing directory structure are preserved. The import then creates `import_YYYYMMDD_HHMMSS_UUUUUU.md`, listing the eligible game/team jobs, dates, opponents, window, team identifiers, and whether each job was dispatched or skipped, plus a fresh `search.md` for every eligible team. X discovery appends the exact query, request time, game context, returned-post count, and every returned post with its parser decision. A zero-result response is recorded explicitly.

Search attempts appear in execution order as the full abbreviation, first two abbreviation characters, and canonical nickname rotate through unquoted lineup wording variations. Identifier-only searches run last. A subsequent query is attempted when the preceding query is empty or all of its returned posts are declined; discovery stops only when a query produces an accepted lineup or the finite sequence is exhausted.

No audit Markdown files are written in testing, staging, or production environments.
