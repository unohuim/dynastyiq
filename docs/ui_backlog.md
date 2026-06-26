# UI Standards Backlog

This backlog tracks known deviations from `docs/UI_DESIGN.md`.

Existing deviations are legacy debt. They are not automatic blockers for unrelated PRs, but new UI and materially touched UI should not add more violations.

---

## Enforcement Policy

- New UI must follow `docs/UI_DESIGN.md`.
- Touched UI should avoid adding new violations.
- Existing non-compliant UI may remain until intentionally migrated.
- Migration PRs should remove or reduce entries in this backlog.

---

## High Priority

### Move Executable Blade Scripts Into Page Modules

**Standard:** Blade templates must not include executable `<script>` tags. New interactive pages should use page modules.

**Known locations:**

- `resources/views/layouts/app.blade.php`
- `resources/views/layouts/community-hub.blade.php`
- `resources/views/stats-view.blade.php`
- `resources/views/stats-view_org.blade.php`
- `resources/views/player-stats-view.blade.php`
- `resources/views/fantrax/leagues.blade.php`
- `resources/views/communities/index.blade.php`
- `resources/views/communities/leagues/show.blade.php`
- `resources/views/communities/_desktop-discord-servers-list.blade.php`
- `resources/views/communities/_desktop-leagues.blade.php`
- `resources/views/components/new-league-modal.blade.php`
- `resources/views/livewire/player-stats-table.blade.php`
- `resources/views/livewire/player-stats-page.blade.php`
- `resources/views/nav/partials/_right-account-drawer-org-options.blade.php`
- `resources/views/nav/partials/_right-account-drawer-notifications.blade.php`

**Target state:**

- Blade emits markup plus JSON payloads only.
- Page-specific JS lives in `resources/js/pages/**`.
- Each page module exports `mount(rootEl, payload)`.

### Introduce Page Module Loader

**Standard:** Production-safe page loading should use `import.meta.glob("./pages/**/*.js")`.

**Current state:**

- There is no `resources/js/pages/**` structure.
- `resources/js/app.js` imports broad app modules directly and starts Alpine globally.

**Known locations:**

- `resources/js/app.js`
- `resources/js/admin/admin-hub.js`
- `resources/js/community-hub.js`
- `resources/js/leagues-hub.js`
- `resources/js/components/community-members-store.js`

**Target state:**

- `app.js` resolves a page module from a single `data-page` root.
- Page modules are registered before Alpine starts.

### Fix Alpine Boot Order

**Standard:** Alpine must start after page modules are registered and must start exactly once.

**Known location:**

- `resources/js/app.js`

**Current issue:**

- `Alpine.start()` runs globally before a page-module registration contract exists.
- Several modules also register through `document.addEventListener("alpine:init", ...)`.

**Target state:**

- Page module is resolved.
- `Alpine.data(...)` registrations happen.
- `Alpine.start()` runs once.

### Remove Page Logic From Global JavaScript State

**Standard:** Page state must be page-scoped. `window.Alpine` is allowed only as a migration bridge.

**Known locations:**

- `resources/views/layouts/app.blade.php` uses `window.DIQ`.
- `resources/js/app.js` assigns `window.adminHub`, `window.Alpine`, and `window.__alpineStarted`.
- `resources/js/bootstrap.js` assigns `window.axios`.
- `resources/js/echo.js` assigns `window.Pusher`, `window.Echo`, and `window.DIQ.userChannel`.
- `resources/views/stats-view.blade.php` uses `window.__stats`, `window.api`, and `window.__connectedLeagues`.
- `resources/views/player-stats-view.blade.php` uses `window.__playerStats` and `window.api`.
- `resources/views/components/new-league-modal.blade.php` assigns `window.dropdownSelect`.
- `resources/js/components/community-members-store.js` assigns `window.communityMembersHub`.

**Target state:**

- Page data is passed through JSON payloads.
- Page modules own state locally.
- Shared globals are limited to deliberate infrastructure bridges.

### Replace Full-Page Reloads After AJAX Mutations

**Standard:** UI should update immediately after create/edit/delete. Page refreshes to reflect state are forbidden for new interactive UI.

**Known locations:**

- `resources/views/communities/leagues/show.blade.php`
- `resources/views/communities/_desktop-leagues.blade.php`

**Current issue:**

- Successful AJAX mutations call `window.location.reload()`.

**Target state:**

- Mutations update page-local state from server JSON responses.
- Toasts and inline validation handle feedback.

---

## Medium Priority

### Move Inline Styles and `<style>` Blocks to Tailwind/Page Modules

**Standard:** New UI should use Tailwind utilities only; no inline styles or native CSS.

**Known locations:**

- `resources/views/stats-view.blade.php`
- `resources/views/stats-view_org.blade.php`
- `resources/views/stats-units.blade.php`
- `resources/views/fantrax/leagues.blade.php`
- `resources/views/partials/_stat-ring.blade.php`
- `resources/views/partials/_ring.blade.php`
- `resources/views/partials/_ring-set.blade.php`
- `resources/views/partials/_triangle-zones.blade.php`
- `resources/views/partials/_zone-donut.blade.php`
- `resources/views/profile/update-profile-information-form.blade.php`
- `resources/views/nav/main.blade.php`
- `resources/views/nav/partials/_right-account-drawer.blade.php`
- `resources/views/components/modal.blade.php`
- `resources/views/components/dropdown.blade.php`
- `resources/views/components/action-message.blade.php`
- `resources/views/components/banner.blade.php`
- `resources/views/admin/fresh-install.blade.php`

**Related files:**

- `resources/css/app.css`
- `resources/js/components/RangeSlider/range-slider.css`

**Target state:**

- Styling uses Tailwind utilities or approved shared components.
- Dynamic geometry-heavy UI should be isolated and justified.

### Reduce Sidebar-Based Layouts

**Standard:** Top horizontal navigation is preferred; persistent sidebars are discouraged unless approved.

**Known locations:**

- `resources/views/components/community-hub-layout.blade.php`
- `resources/views/components/leagues-hub-layout.blade.php`
- `resources/views/layouts/community-hub.blade.php`
- `resources/js/components/CommunityHubLayout.js`
- `resources/js/components/LeaguesHubLayout.js`

**Target state:**

- Keep workflow navigation top-level or page-local.
- If sidebar remains necessary, document the exception and keep it restrained.

### Refactor Navigation Toward DynastyIQ Domains

**Standard:** Top-level navigation should represent product workflows.

**Known location:**

- `resources/views/nav/main.blade.php`

**Current state:**

- Current top-level items include `Home`, `Stats`, `Leagues`, `Line Combos`, and `Communities`.
- Mobile uses a bottom nav plus left drawer.

**Target state:**

- Align navigation with approved DynastyIQ domains such as Stats, Leagues, Communities, Rankings, and Admin.
- Preserve route names, gates, active states, and backend-owned eligibility.

### Reduce Card-Heavy UI

**Standard:** Avoid card-heavy dashboards unless cards directly improve task completion.

**Known locations:**

- `resources/views/communities/_desktop.blade.php`
- `resources/views/communities/leagues/show.blade.php`
- `resources/views/leagues/_panel.blade.php`
- `resources/views/admin/imports.blade.php`
- `resources/views/admin/operational.blade.php`
- `resources/views/dashboard.blade.php`
- `resources/views/components/card-section.blade.php`

**Target state:**

- Use flatter sections, compact lists, and restrained dividers.
- Keep cards for repeated items, modals, and genuinely framed tools.

### Standardize Empty States

**Standard:** Empty states are required and should include absence, one primary action when allowed, and calm tone.

**Known locations needing review:**

- `resources/views/communities/index.blade.php`
- `resources/views/communities/_desktop-memberships.blade.php`
- `resources/views/communities/_desktop-leagues.blade.php`
- `resources/views/leagues.blade.php`
- `resources/views/admin/player-triage.blade.php`

**Target state:**

- Each list/index surface has a consistent empty state.
- Empty states do not become decorative marketing blocks.

---

## Lower Priority / Design Cleanup

### Replace Decorative Gradients and Glows in App Surfaces

**Standard:** Authenticated app UI should be calm and operational. Decorative gradients are discouraged.

**Known locations:**

- `resources/views/welcome.blade.php`
- `resources/views/nav/main.blade.php`
- `resources/views/fantrax/leagues.blade.php`

**Notes:**

- `welcome.blade.php` is a public marketing-style surface. It may remain more expressive if intentionally treated as marketing.
- Authenticated app surfaces should avoid this direction.

### Remove Arbitrary Hex Colors From New/Touched UI

**Standard:** Prefer Tailwind palette and semantic color usage.

**Known locations:**

- `resources/views/nav/main.blade.php`
- `resources/views/welcome.blade.php`

**Target state:**

- Use Tailwind palette tokens unless a brand exception is approved.

### Prefer Heroicons for New UI

**Standard:** Heroicons are preferred; outline style is preferred.

**Known locations needing review:**

- `resources/views/nav/main.blade.php`
- `resources/views/welcome.blade.php`
- `resources/views/communities/leagues/show.blade.php`
- `public/images/Discord-Symbol-White.svg`

**Target state:**

- Use Heroicons for generic UI actions.
- Keep provider/logo assets only where they represent actual brands.

### Fix HTML Attribute Quoting in Touched UI

**Standard:** HTML attributes should use double quotes in new/touched UI.

**Known locations:**

- `resources/views/components/community-hub-layout.blade.php`
- `resources/views/communities/_desktop-leagues.blade.php`
- `resources/views/stats-view.blade.php`
- `resources/views/fantrax/leagues.blade.php`

**Target state:**

- Double-quoted HTML attributes.
- Single-quoted JavaScript string literals inside Alpine expressions.

---

## Product-Specific Future Work

### Add `/learn/{slug}` Marketing Content Pattern

**Standard:** Public marketing pages may live under `/learn/{slug}` and use repo-managed markdown with YAML front matter.

**Current state:**

- No `resources/content/marketing/` structure is present.
- No `/learn/{slug}` route is present.

**Target state when needed:**

- Add route/controller for `/learn/{slug}`.
- Add repo-managed markdown content directory.
- Keep public content unauthenticated.
- Do not add database-backed CMS behavior without approval.

---

## Backlog Maintenance

When a migration PR fixes a deviation:

- Remove or narrow the matching backlog item.
- Reference the changed files.
- Update `docs/UI_DESIGN.md` if the standard itself changes.

**End of UI Standards Backlog**
