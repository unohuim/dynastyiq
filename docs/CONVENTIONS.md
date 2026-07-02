# Conventions

This document defines the **mandatory development conventions** for this repository.  
All human and AI-assisted contributions must comply.

These rules are contractual.

---

## Core Principles

- Prefer **clarity over cleverness**
- Prefer **explicit behavior over implicit magic**
- Prefer **small, reviewable changes**
- Match **existing patterns** before proposing new ones
- Architecture and rules are enforced through tests and documentation

---

## Project Structure

- Follow Laravel default structure unless explicitly documented
- Do not introduce new top-level directories without approval
- Configuration belongs in `config/`, not application logic
- Shared logic must be centralized
- Duplication is acceptable only when intentional and scoped

---

## PHP Conventions

- **PSR-12** formatting is mandatory
- PHPDoc is required for:
    - Classes
    - Public and protected methods
    - Complex private methods
    - Non-obvious parameters or return values
- Use strict typing where appropriate
- Avoid magic strings and numbers when meaning matters

---

## Database & Migrations

- Migrations must be **explicit and reversible**
- Never combine unrelated schema changes in a single migration
- Never modify migrations after they have been applied
- Seeders must be deterministic and safe to re-run

---

## Testing Conventions

- All new automated tests MUST be written using **Pest**.
- PHPUnit-style test classes MUST NOT be introduced for new tests.
- Existing PHPUnit tests may remain untouched unless explicitly refactored.
- If test framework usage is unclear, AI must stop and ask before proceeding.

- Tests are written **before or alongside** behavior changes
- Feature tests are preferred for user- or domain-facing behavior
- Tests must assert **intent**, not implementation details
- Avoid brittle tests that rely on ordering, timing, or side effects

- Every permission must have explicit allow/deny tests
- Tests must assert both positive and negative cases

### Test File Safety Rules

To avoid cross-test contamination and CI-only failures:

- **Do not declare global functions in test files.**  
  Pest loads all test files into a shared PHP runtime; global helper functions
  (`function foo() {}`) will collide across files.
    - Use `beforeEach()` closures (`$this->makeX = fn () => ...`) instead.
    - Alternatively, define helpers as local closures inside the test.

- **When asserting or throwing PHP built-in exceptions, use fully-qualified names**  
  (e.g. `\DomainException::class`) or a proper `use` import.
    - Avoid bare `DomainException::class` without qualification, which can lead to
      warnings like "use statement has no effect" or inconsistent behavior.

These rules are mandatory for all new tests.

---

## Frontend Conventions

- Blade is the primary templating system
- Alpine.js is used for lightweight interactivity
- No global JavaScript state unless explicitly approved
- Prefer progressive enhancement over JavaScript-first solutions

## Marketing Content Conventions

- Public marketing pages live in `resources/content/marketing/`.
- Marketing pages are repo-managed markdown files with YAML front matter.
- Marketing page slugs must be lowercase kebab-case and must match the front matter `slug`.
- Marketing pages are public content and must not require authentication.
- Marketing pages are not a CMS and must not introduce database-backed content editing.

---

## Naming & Readability

- Names must be descriptive and intention-revealing
- Avoid abbreviations unless widely understood
- Favor readability over brevity

---

## Dependency Management

- All dependencies must be explicitly declared
- `composer.lock` and `package-lock.json` are authoritative and must be committed
- Do not upgrade dependencies without justification and review

---

## Error Handling & Validation

- Fail fast when assumptions are violated
- Validate inputs at boundaries (controllers, requests, jobs)
- Do not silently swallow errors

---

## Documentation

- Update documentation when rules, workflows, or architecture change
- Inline comments explain **why**, not **what**
- README and docs files are part of the repository contract

---

## Deviations

- Deviations require explicit approval
- Temporary deviations must document intent and follow-up

---

## Architecture Inventory

Reusable abstractions and patterns are tracked centrally.

- All reusable backend or frontend abstractions **must** be recorded in  
  `docs/ARCHITECTURE_INVENTORY.md`
- Each entry must include:
    - Purpose
    - Location
    - Public interface
    - Example usage
- New abstractions require:
    - Justification in the PR
    - A same-PR inventory entry

---

## Security

- Treat all user input as untrusted
- Use Laravel's built-in security features
- Do not introduce security-sensitive changes without explicit review
- Secrets, credentials, and tokens must never be committed

---

## Performance

- Prefer correctness and clarity before optimization
- Watch for N+1 queries, redundant work, and unnecessary loops
- Performance optimizations require measurable justification
- Do not introduce caching, queues, or background jobs without approval

---

## Versioning & Compatibility

- Follow semantic versioning where applicable
- Avoid breaking changes without approval and documentation
- Database and API changes must consider backward compatibility
- Deprecations must be intentional and staged

---

## Styling & CSS

- **Tailwind CSS is the only permitted styling system**
- No native CSS files or inline styles
- All styling must use Tailwind utility classes
- Customizations require Tailwind config changes and approval

---

## Authorization & Roles

This application uses **global roles** and **domain-based permissions**.

### Principles

- Roles describe **job responsibility**, not UI access
- Authorization is enforced at **domain boundaries**
- Views are never a source of truth

### Role & Permission Model

- Users may have **multiple roles**
- Roles grant permissions via permission slugs
- Permissions are enforced using **Laravel Gates**
- `super-admin` bypasses all checks via `Gate::before`

### Authorization Rules

- No hard-coded role checks in controllers or views
- All access checks must use Gates or Policies
- Permission slugs are canonical and domain-oriented

### Naming Conventions

Permission slugs follow:

```text
{domain}-{resource}-{action}
```

Examples:

- `sales-customers-view`
- `inventory-products-manage`
- `purchasing-purchase-orders-create`

---

## Tenancy Requirements

- Single-database, tenant-scoped architecture
- All tenant-owned tables **must** include `tenant_id`
- Tenant scoping is enforced by default via model scope
- Global tables must be explicitly documented as global
- The first user created for a tenant is automatically assigned `admin`

## This rule is foundational and non-negotiable.

## Decimal Quantity Math (Inventory & Purchasing)

To avoid floating-point rounding errors, all quantity math in inventory and purchasing
domains must follow these rules:

- **All quantities are represented as strings**, never floats.
- **All arithmetic must use BCMath** (`bcadd`, `bcmul`, `bcdiv`, etc.).
- A **single canonical scale** must be used everywhere, matching
  `stock_moves.quantity` (e.g. scale = 6).
- **No implicit casting** to float at any point.
- **No alternative math libraries** (e.g. BigDecimal, Brick\Math) may be introduced
  without explicit approval.

These rules apply to:

- Stock moves
- Receiving logic
- Unit conversions
- Any inventory-affecting calculations
