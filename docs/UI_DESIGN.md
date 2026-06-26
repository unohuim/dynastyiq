# UI_DESIGN.md - Canonical UI Direction & Constraints

This document defines the **authoritative UI design rules** for this repository.

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
3. PR-specific notes

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

- Use Laravel's default Blade layout patterns as the baseline.
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

---

## Technology Constraints

### Allowed

- Blade
- Alpine.js
- Native JavaScript
- Tailwind CSS utilities
- AJAX / fetch-based interactions

### Disallowed Without Approval

- SPA frameworks such as React or Vue
- Client-side routing
- Global JavaScript state for page logic
- Native CSS files for new UI
- Inline styles for new UI
- UI libraries such as Flowbite or Headless UI

### Page Module Contract

- Blade templates must not include executable `<script>` tags.
- Blade templates may include a single JSON payload script when needed.
- New interactive pages should use page modules.
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
- Modals are appropriate for confirmations and short forms.
- Never stack modals.
- Forms must preserve entered values after validation errors.

### Tables & Lists

- Use clean, flat lists.
- Avoid heavy borders.
- Use subtle dividers only when necessary.
- Row click must not mean edit; use explicit actions.
- Row action menus should use a vertical `...` pattern on the far right unless there is only one obvious action.
- Menus must not be clipped by parent section/card shells.
- Long secondary values may truncate on small screens when needed to preserve layout stability.

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
- New or migrated interactive pages should follow the page-module contract.
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
