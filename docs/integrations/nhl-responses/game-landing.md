# NHL Game Landing Response

## Endpoint

```text
https://api-web.nhle.com/v1/gamecenter/{gameId}/landing
```

Current DynastyIQ consumers:

- No primary importer currently depends on this response.

## Purpose

This endpoint is part of the NHL gamecenter family and is documented by the external NHL API reference. It is a candidate source for richer game detail, media/content context, and cross-checking game state, but DynastyIQ currently treats PBP and boxscore as the authoritative import endpoints.

## Observations For DynastyIQ

Game landing should not be introduced as a silent replacement for PBP or boxscore. If used, it should be documented with observed samples first and mapped to a narrow product need such as richer game presentation, recap content, or provider-state diagnostics.

## Candidate Field Areas

| Area | Expected Shape | Potential Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- |
| Game identity/state | object | Game id, season, type, state. | Future diagnostics or display fallback. | Import eligibility without PBP verification. |
| Team context | object | Home/away team and score. | Rich game display. | Boxscore validation. |
| Summary/content | object/array | Recap or content modules. | Future game detail UI. | Stat imports. |
| Three stars/highlights | object/array | Presentation data. | Future UI. | Player stat trust. |

## Parser Contract

- Add this endpoint to code only after a real sample is documented.
- Do not use game landing as a replacement for `/play-by-play` event rows.
- Do not use game landing as a replacement for `/boxscore` official player totals.
- Treat content/media fields as presentation-only unless a future architecture rule says otherwise.

## Open Verification Questions

- Which fields overlap with PBP and boxscore, and are they always identical after final?
- Does this endpoint expose a more reliable final game-end boundary than PBP?
- Which content fields are stable enough for user-facing game pages?
