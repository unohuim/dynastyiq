# UI_DESIGN.md - Canonical UI Direction & Constraints

This document defines the **authoritative UI design rules** for this repository.

For the public root and authenticated dashboard, the approved visual reference is
`docs/designs/diq-dashboard-desktop-design.png`. Its editorial grid, image overlays,
panel arrangement, dark chrome and blue actions are explicitly approved for those
surfaces. Use the complete DynastyIQ logo asset rather than recreating the textual
brand in the reference. Responsive layouts retain the same content and existing
mobile navigation. Behavior and data ownership are defined in
`docs/architecture/ui/NhlDashboard.yaml`.

The homepage Last Night’s Top Lines section follows
`docs/designs/5v5-lineups-design.png`: solid blue selected tabs, team logos and
trio names in ranked rows, a blue selected-metric badge and inline secondary
statistics separated by subtle dividers. Retain its dashboard position and wrap
secondary statistics within narrow panels.

On mobile, featured stories occupy one compact 160-pixel-high slot with native
horizontal swiping through all fetched stories and a subtle proportional
scrollbar beneath it when content overflows. Preserve full images and the existing
three-card tablet/desktop layout. On mobile, Last Night’s Top Lines appears
immediately below Last Night’s Top Performers; tablet and desktop retain their
existing panel order.

For the /stats Leaders landing, `docs/designs/stats-leaders-design.png` is the
authoritative visual reference: dark navy surface, large player hero, yellow
category-navigation underline, cyan links, compact leader cards and tables. Replace
its Top Performers area with the paired goal-rate panels from
`docs/designs/teams-by-goals-section.png`. This approved dark statistics design is
specific to /stats; it does not recolour the homepage or unrelated application
surfaces. Category tables retain their existing interactions. On mobile, stack
panels without horizontal document overflow. Use real stored images and statistics,
with honest empty states when the required imports or eligibility are unavailable.
In the Leaders view, replace the reference's Last Night's Top Lines panel with
Smash Potatoes, using the same reusable tabbed leaderboard format as Advanced
Leaders. Rank skaters by the selected season's SOG, Hits, Blocks or PIMs totals;
show rank, Player, GP and the selected stat, rather than last-night lines.

On mobile below 640px, /stats removes the hero header across category views and
keeps the category tabs above the content. Skaters, Goalies and Prospects retain
the original compact vertical player list shown in `docs/designs/stats-mobile.png`,
with white 52px-high rows, dark text, team strips, 28px avatars, compact identity
details and a single inline statistics row, matching the reference spacing. Do
not add mobile rate-bar rows. The list spans the mobile viewport; desktop rows
retain their graphite styling and rate bars. Prospects opens
the existing Prospects report. Teams uses matching compact vertical rows with
team logos and season statistics; its desktop table remains unchanged. Horizontal
leader cards belong only to the Leaders tab. Leaders uses four compact horizontal
card rows headed All Skaters, All Goalies, All Teams and All Prospects. Exactly
three cards fit each row's visible width; remaining cards scroll horizontally
with native scrolling and a subtle scrollbar. Use one independently selected
metric leader per card, compact images, single-line truncated names, and factual
unavailable states. The desktop hero and leader panels keep their existing design.
Metric definitions and the non-NHL Prospects population belong to
`docs/architecture/stats/NhlStatsDashboard.yaml`.

The Skaters and Goalies filter bar follows the updated
`docs/designs/filter-bar.png`: one compact desktop row of Season Mode,
Season/Dates, Drafted, Game Type, Team and Slice. Use a dark navy
surface, flat selectors, muted labels, vertical dividers and blue selected Slice
segments. Preserve filter behavior and the mobile filter drawer, adapting widths
without document overflow.
The bar spans its container without outer padding or a decorative blue icon;
use the existing graphite shell colour for the component's surrounding space.
Skaters and Goalies use an approximately half-height hero with a single row of
Goals, Assists and PPP category leaders for Skaters; Goalies instead shows Quality
Starts, GSAx and EVSV%, ranked separately. Display GSAx with two decimals and EVSV%
as a percentage. Keep the full hero on the Leaders view.
The Goalies hero features the season Quality Starts leader with their own photo,
name, team, sweater number and QS%, GSAx, EVSV% and Saves. QS% is quality starts
divided by starts. Fill the goalie hero's width with its photo backdrop and frame
the clear goalie image at a suitable zoom level with the helmet and head visible
near the top; preserve image proportions. Rankings compare goalies
only; keep unavailable values honest and never fall back to a skater hero.
Their filter bar uses 9–10px text, tight vertical spacing and compact 20-pixel
controls.
On desktop, default to Search, Position and Report, with a right-aligned Filters
toggle and a filter icon before its label. Position exists only beside Search:
Skaters offers SKT, F, W, C and D; Goalies omits Position entirely. Report is visible
in the default row rather than duplicated among expanded filters.
Report options follow the active tab: Skaters excludes goalie reports and Goalies
includes only goalie reports, including their advanced and prospect variants.
The full filter row replaces that quick row with a 500ms right-to-left sliding
transition; keep the toggle visible and blue while expanded. Toggling back restores
the quick row and retains all selections. Hidden panels are inert and excluded
from accessibility navigation; reduced-motion preferences remove the animation.
Stats category navigation shows immediate graphite loading feedback on selection.
Keep category navigation usable while leader facts are loading, and load the
player table independently of hero/dashboard calculations. Use accessible dark
skeleton rows and explicit error messages with Retry for failed data groups.
When GSAx is missing, show the actual availability reason rather than a bare dash;
the goalie category card uses a compact reason with full detail on hover.

Stats player lists take visual inspiration from `docs/designs/players-list-dark.png`:
graphite rows, subtle alternating shades and separators, clear light text, larger
portraits and a blue hover highlight. Omit stars. Preserve existing columns and
sorting while adding a skater PTS/GP column. Place official team logos to the left
of abbreviations inside stats-table team badges, using 20px logos beside the 12px
abbreviation text. Omit the goalie position badge and its column/spacing beside
the avatar; retain Skaters' position badges. Show rate bars beneath goalie SV% and
GAA and skater PTS/GP, including compact equivalents in the mobile cards. SV% uses
the full 0–100% scale; PTS/GP uses the report's highest rate; GAA is inverted relative
to the report's highest GAA so longer means better. Keep actual numbers visible,
explain the scale on hover, and never fabricate a bar for missing data.

The default desktop dashboard overview should fit above the fold, with a compact
performers/signings/injuries row. Shorter viewports may scroll panel contents;
explicitly expanded content remains accessible through normal page scrolling.

Its purpose is to:

- Prevent UI drift across many PRs
- Constrain AI and human contributions
- Preserve a **minimal, modern, operational UI**
- Enable consistent, reusable UI components

This is **not a full design system**.
It is a **set of hard constraints and allowed patterns**.

If a UI decision is not allowed here, it must be explicitly approved.

---

## Authority

This document is authoritative for UI work.

In the event of conflict:

1. `docs/CONVENTIONS.md`
2. `docs/UI_DESIGN.md`
3. `docs/UI_MOTION.md`
4. PR-specific notes

### Enforcement Scope

- New UI surfaces must comply with this document.
- Materially touched UI must not introduce new violations.
- Existing non-compliant UI may remain during the staged migration.
- Existing deviations are tracked in `docs/ui_backlog.md`.
- A PR is not required to fix unrelated legacy UI debt unless the PR explicitly takes on that migration.

This policy belongs here because it defines how this UI authority is applied. If another document references UI rules, it should link back to this section rather than restating it.

---

## Core UI Philosophy

### Design Ethos

- **Minimalist**
- **Content-first**
- **Low visual noise**
- **Calm by default**
- **Progressive disclosure**

The UI should feel:

- Professional
- Quiet
- Modern
- Unopinionated
- Operational

It should not feel:

- Flashy
- Decorative
- Dashboard-heavy
- Marketing-heavy, except for approved public marketing pages
- Experimental

---

## Layout Rules

### Global Layout

- New and migrated Inertia pages use the shared Vue application layout. Legacy Blade layouts remain for unmigrated pages.
- Prefer white or light neutral backgrounds for authenticated application surfaces.
- Use generous whitespace.
- Avoid card-heavy dashboards unless the card model directly improves task completion.
- Avoid persistent sidebars for primary app navigation unless explicitly approved.
- Page sections should be flat and scannable.

### Navigation

- Top horizontal navigation is the preferred primary navigation model.
- App identity should be left-aligned.
- Top-level navigation should represent product workflows, not database tables.
- Active state must be subtle, such as underline, tone shift, or restrained background.
- No nested mega-menus initially.
- Desktop dropdowns may use floating panels.
- Mobile nested navigation should use accordion-style groups or simple panels, not desktop flyout behavior.
- Styling must remain calm, operational, and restrained.
- Navigation refactors must preserve route names, gates, `@can` checks, active states, and existing behavior.

### DynastyIQ Navigation Model

Top-level areas should reflect DynastyIQ product workflows. Current or target domains include:

- Stats
- Leagues
- Communities
- Rankings
- Admin

Authenticated user/account controls and integration controls should live in account/profile surfaces unless a page-specific workflow requires otherwise.

Navigation eligibility is backend-owned. Client-side updates may patch stale local navigation state after a successful AJAX mutation, but they must not become authorization authority.

### Toggles

- Binary toggle controls must persist immediately when changed.
- Toggles must not require a separate Save button for the toggled value.
- Failed toggle saves must restore the previous visual state and show a toast or inline error.

### Toasts

- Toast notifications must render above drawers, slide-overs, modals, dropdowns, and fixed app chrome.

---

## Technology Constraints

### Allowed

- Vue/Inertia for new interactive pages and the shared application shell
- Blade
- Alpine.js
- Native JavaScript
- Tailwind CSS utilities
- AJAX / fetch-based interactions

### Disallowed Without Approval

- Other SPA frameworks such as React
- Client-side routing
- Global JavaScript state for page logic
- Native CSS files for new UI
- Inline styles for new UI
- UI libraries such as Flowbite or Headless UI

### Page Module Contract

- New Vue/Inertia pages use `resources/js/inertia.js` and `resources/js/layouts/AppLayout.vue`; their Blade root contains only document metadata, assets and the Inertia mount, never navigation or interactive UI.
- The remaining Blade/Alpine page-module rules apply to maintenance of unmigrated pages, not new Vue/Inertia surfaces.
- Blade templates must not include executable `<script>` tags.
- Blade templates may include a single JSON payload script when needed.
- New interactive pages use Vue/Inertia; unmigrated pages retain their page modules.
- During the staged migration, existing Alpine directives in Blade may remain.
- Executable inline scripts in existing Blade files are legacy debt and should not be copied into new work.

---

## Interaction Patterns

### CRUD Philosophy

- AJAX-first for create, update, and delete interactions.
- Server remains source of truth.
- Optimistic UI is allowed but must reconcile errors.
- Full page reloads to reflect successful mutations are forbidden for new interactive UI.

### Modals & Panels

- Slide-overs are preferred for longer create/edit forms.
- Vue slide-overs must provide focus trapping/restoration, Escape dismissal and separately animated panel/overlay transitions. Legacy Blade slide-overs use `x-ui.slide-over`.
- Modals are appropriate for confirmations and short forms.
- Never stack modals.
- Forms must preserve entered values after validation errors.

### Date Inputs

- Reusable native date inputs should use the `x-ui.date-field` Blade component.
- Date fields should preserve browser-native calendar behavior instead of introducing custom calendar widgets by default.

### Tables & Lists

- Use clean, flat lists.
- Avoid heavy borders.
- Use subtle dividers only when necessary.
- Data tables created or materially touched must expose sortable headers for every presented column unless a column is explicitly non-data or sorting would mislead the user.
- Row click must not mean edit; use explicit actions.
- Row action menus should use a vertical `...` pattern on the far right unless there is only one obvious action.
- Menus must not be clipped by parent section/card shells.
- Long secondary values may truncate on small screens when needed to preserve layout stability.

### Motion

Interactive state changes, including accordions, inline drawers, caret rotations,
slide-overs, dropdowns, and async loading reveals, must follow `docs/UI_MOTION.md`.

---

## Empty States

Empty states are required for new index/list surfaces.

They must include:

- Clear statement of absence
- One primary next action when the user is allowed to act
- Calm tone
- No illustrations unless extremely subtle

---

## Feedback & Status

### Required Patterns

- Loading states for asynchronous actions
- Short-lived success toasts
- Inline validation errors
- Non-blocking error messages
- Tailwind motion for visible state changes must follow `docs/UI_MOTION.md`

### Disallowed

- Alert spam
- Blocking full-page loaders for passive initialization
- Silent failures

---

## Reusable Components Policy

Reusable UI components:

- Must be explicitly created, not copy-pasted ad hoc
- Must have a single clear responsibility
- Must be briefly documented in `docs/ARCHITECTURE_INVENTORY.md`

Expected shared components include:

- Dropdown action menu
- Modal
- Slide-over
- Toast
- Empty state
- Confirm dialog
- Loading overlay

---

## Visual Constraints

### Color

- Prefer the default Tailwind palette.
- Use color sparingly and semantically:
  - Red = destructive
  - Yellow = warning
  - Green = success
  - Blue/indigo = primary action
- Avoid arbitrary hex colors in new UI unless explicitly approved.
- Avoid decorative gradients in authenticated application UI.

### Typography

- Use default application typography.
- No custom fonts for new UI without approval.
- No decorative text.
- Text should be direct, short, and task-oriented.

### Shape & Elevation

- Keep border radii restrained.
- Use shadows sparingly.
- Avoid nested cards.
- Avoid decorative blobs, glows, and ornamental backgrounds in app surfaces.

---

## Icons

- Heroicons are preferred for new UI.
- Outline style is preferred.
- Icons must aid clarity, not decoration.
- Inline SVG is acceptable only when no suitable shared icon/component exists.

---

## Marketing Content Conventions

- Approved public marketing pages may live under `/learn/{slug}`.
- Public marketing pages should be repo-managed markdown files with YAML front matter when practical.
- Marketing page slugs must be lowercase kebab-case and must match front matter `slug`.
- Marketing pages are public content and must not require authentication.
- Marketing pages are not a CMS and must not introduce database-backed content editing without explicit approval.

---

## UI Quoting & Alpine Safety Rules

These rules prevent silent Alpine parsing failures and Blade-rendered JavaScript leakage into the UI.

### HTML Attribute Quoting

- All HTML attributes must use double quotes (`"`).
- Single-quoted HTML attributes are forbidden in new or touched UI.

Correct:

```html
<div x-data="{}"></div>
```

Incorrect:

```html
<div x-data='{}'></div>
```

### Alpine JavaScript String Quoting

Inside Alpine directives such as `x-data`, `x-init`, `x-on`, and `@click`:

- JavaScript string literals should use single quotes (`'`).
- Avoid double-quoted JavaScript strings inside HTML attributes.

### Blade + Alpine Interop

Blade helpers inside Alpine expressions must not introduce double quotes into the Alpine JavaScript context.

---

## UI Execution & Page Module Rules

Every new interactive page should follow this contract.

### Page Contract

Each interactive page should have:

- A single root element with:
  - `data-page="page-slug"`
  - `data-payload="payload-script-id"`
- A single `<script type="application/json">` payload block when initial data is required
- No executable `<script>` blocks in Blade templates

All page-specific UI logic should live in:

```text
resources/js/pages/**
```

### Page Module Contract

Each page module should:

- Export a `mount(rootEl, payload)` function
- Register any Alpine component inside `mount`
- Never assume Alpine has already started

### Alpine Boot Order

Alpine must not start until after page modules are registered.

Required guarantees:

- Page module is resolved
- `Alpine.data(...)` is registered
- `Alpine.start()` runs exactly once afterward

### Production-Safe Module Loading

Dynamic string imports are forbidden for page modules.

The page loader should use:

```js
import.meta.glob("./pages/**/*.js");
```

This ensures Vite production builds include all page modules.

---

## Alpine Safety Rules

### Optional-Chaining Assignment Is Forbidden

This is invalid JavaScript and will break builds:

```js
el?.textContent = value
```

Required pattern:

```js
const el = getElement()
if (el) {
  el.textContent = value
}
```

### Stable Error Object Shapes

Any error object referenced in Blade or Alpine like:

```html
x-text="errors.name[0]"
```

must always exist as an array, even when empty.

Forbidden:

```js
errors = {}
```

Required:

```js
errors = { name: [] }
```

For page-module and page-module-compatible interactive screens, 422 responses must be normalized into this shape.

### Defensive Alpine Expressions

Alpine expressions must be safe during:

- Initial render
- Empty payloads
- Validation failures
- Post-submit updates

If an expression can throw, it is invalid.

---

## Page-Local Reactivity Rules

- UI must update immediately after create/edit/delete.
- Page refreshes to reflect state are forbidden for new interactive UI.
- Server is source of truth; UI reconciles response data.
- State must be page-scoped.
- `window.Alpine` is permitted only as a compatibility bridge during migration.
- No page logic may depend on arbitrary globals.

---

## Non-Goals

This UI is not:

- A data visualization playground
- A design experiment
- A SPA
- A mobile-first app
- A marketing site except for approved public marketing pages such as `/learn/{slug}`

---

## Migration Status

The current implementation is in a staged migration state.

- Existing Breeze, Jetstream, Livewire, Blade/Alpine, inline scripts, and legacy layouts may remain until intentionally migrated.
- New or migrated interactive pages use Vue/Inertia and the shared Vue shell; legacy page modules remain supported for unmigrated pages.
- Existing deviations are tracked in `docs/ui_backlog.md`.
- PRs should shrink the backlog when practical, but unrelated UI debt should not block unrelated work.

---

## Change Discipline

Any new deviation from this document requires:

- Explicit PR note
- Clear justification
- Approval before implementation

If a UI element feels "cool", "flashy", or "impressive", it is probably wrong for this system.

Clarity, calmness, and restraint win.


Public season statistics read prepared snapshots. When a dashboard, trend snapshot
or table is not prepared, show a clear compact status and a keyboard-accessible
Check again action. Do not disguise unavailable preparation as zero statistics or
run expensive source calculations while a visitor waits. The admin season-sync
progress distinguishes source rollup from report-cache preparation and failures.
