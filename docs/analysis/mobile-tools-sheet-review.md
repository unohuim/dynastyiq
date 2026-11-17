# Mobile Tools bottom sheet review

## Files changed or created

- `resources/js/layouts/AppLayout.vue`
- `resources/js/layouts/tools-sheet.test.js`
- `docs/UI_DESIGN.md`
- `docs/architecture/ui/InertiaAppShell.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/analysis/mobile-tools-sheet-review.md`

## Minimum coverage matrix

Coverage below is authored and source-reviewed, not executed.

| Contract | Coverage | Test names |
| --- | --- | --- |
| Guest access and dialog semantics | COVERED | offers the mobile Tools sheet to guests through the existing trigger |
| Half-height bottom positioning and persistent frame | COVERED | anchors the mobile sheet at the bottom at half the dynamic viewport height; uses rounded graphite framing and a visible highlighted boundary; keeps its frame visible when no tool content remains |
| Supplied links, descriptions and active state | COVERED | shows both backend-owned options with icons descriptions and preserved hrefs; preserves active-page semantics on a tool option |
| Immediate option-specific loading | COVERED | shows Schedule loading immediately while retaining the drawer; shows Lines loading without changing the other option label |
| Busy semantics and duplicate protection | COVERED | marks navigation busy and blocks repeated or alternate option taps |
| Success ownership and retained page | COVERED | closes the drawer only after a successful visit; retains the current page content while an explicit tool visit is pending |
| Network/HTTP/validation/exception feedback | COVERED | handles network failure locally and offers Retry; handles HTTP exceptions inside the sheet rather than replacing the page; clears loading after validation failure or an unsuccessful finished visit; catches a synchronous navigation failure without leaving the sheet stuck |
| Retry and stale callbacks | COVERED | retries the failed destination and restores its immediate loading state; ignores late callbacks from a dismissed attempt after reopening |
| Dismissal, cancellation and focus | COVERED | cancels only its own request when the user closes the sheet; cleans up an active navigation request when the layout unmounts; dismisses with Escape and restores focus to the Tools trigger |
| Modified links and reduced motion | COVERED | retains native modified-link behavior instead of starting an Inertia visit; respects reduced motion on loading and interactive controls |
| Existing account/member and desktop scope | COVERED | keeps member navigation as the existing full-height side drawer; keeps the desktop Tools dropdown independent of the mobile sheet |

## Requirements checklist

1. SATISFIED: mobile Tools opens from the bottom at half the dynamic viewport
   height, with rounded corners, graphite surface, visible border/ring and shadow.
2. SATISFIED: Schedule and Lines retain backend-owned destinations and gain icons,
   descriptions, chevrons and generous tap targets.
3. SATISFIED: explicit navigation immediately sets local loading state, preserves
   the drawer until success, rejects duplicate taps and offers local Retry.
4. SATISFIED: the existing Reka dialog retains modal/focus/Escape/outside-dismissal
   behavior; local navigation tokens are cancelled on dismissal/unmount, and
   stale callbacks cannot affect a later request.
5. SATISFIED: desktop Tools remains a dropdown; account/member navigation, routes,
   authorization, payloads and background-feed behavior remain unchanged.
6. SATISFIED: no schema/dependency changes, migration, production operations,
   tests, CI or builds were run by Codex.

## Endpoint × verb authorization/scope

Not applicable: no endpoint, verb, policy or authorization rule changed. Existing
server-owned `shell.tools` links remain public for guests and members. Tests cover
both guest Tools access and preserved member navigation; existing backend route
coverage remains authoritative.

## Frontend checklist

- Local Vue state; no new global state or timers.
- Fixed-height dialog and retained underlying content; no page-layout shift.
- Separate overlay opacity and panel translation transitions, including reduced motion.
- Existing accessible dialog and focus restoration retained.
- Explicit user-action loading, busy semantics, duplicate suppression and local retry.
- Inertia callbacks handle success, validation, HTTP, network and cancelled visits.
- Closing/unmounting cancels only the owned request; stale callbacks are ignored.
- Native anchor hrefs and modified-click behavior remain available.
- Mocked navigation/realtime/network with a fixed clock and controlled timers.

## Per-file test count and checks

- `resources/js/layouts/tools-sheet.test.js`: 24 `it(...)` declarations.

Performed: source/diff review and `git diff --check`.
Not performed: tests, CI, builds, lint, browser checks or production operations,
under the repository execution policy. No migration required.
