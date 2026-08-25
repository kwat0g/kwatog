# Module Scan & Registry Builder

## Purpose
Run this ONCE (or whenever the module list needs a refresh) before any per-module
audit sessions begin. This prompt does NOT audit code quality, find bugs, or check
completeness. It ONLY inventories what modules exist and builds the tracking
backbone that every future session (including parallel ones) will read from and
write to.

Before running this, make sure `audit/scripts/claim-module.sh`,
`release-module.sh`, and `regenerate-registry.sh` exist and are executable
(`chmod +x audit/scripts/*.sh`). Paths are repo-relative - there is no `/audit`
at the filesystem root. This prompt scaffolds folders those scripts
depend on.

## Instructions to Claude

### Phase 1: Discover the module boundaries
Scan the codebase structurally - do not assume domain/module names from memory
or convention alone. Walk:
- `app/` (or wherever the DDD domain folders live) for backend domain/module boundaries
- `resources/js/` or the frontend source root for corresponding frontend modules/routes
- Route files (`routes/*.php`) to confirm what's actually wired up vs. what exists as dead code
- Migration files to cross-check which modules have real database backing vs. stubs

Produce a first-pass list of every domain and every module within each domain.
A "module" is a coherent unit of functionality a user would recognize (e.g.
"Payroll > Payslip Generation", not individual controller methods).

### Phase 2: Enrich each module with metadata
For every module found, determine:
- **Domain** and **module slug** (lowercase-kebab-case, used for folder names)
- **Roles involved**: cross-reference `AccessControlService` / permission
  seeders for which of the five RDBAC roles interact with this module
- **Depends on**: other module slugs this one reads from or writes to
- **Priority tier** (assign using this order, adjusted upward if many other
  modules depend on it):
  1. Core / foundational - Auth, RBAC, AccessControlService, base entities
  2. Money-critical - Payroll, Accounting, anything touching Money value object
  3. Operational core - Production, QC, Procurement, Inventory
  4. Everything else
- **Surface area**: small / medium / large

### Phase 3: Scaffold folders and status files (no shared table)
For every module, create:
```
/audit/domains/{domain-slug}/{module-slug}/status.md
/audit/domains/{domain-slug}/{module-slug}/audit-report.md
/audit/domains/{domain-slug}/{module-slug}/action-plan.md
/audit/domains/{domain-slug}/{module-slug}/fix-log.md
```

`status.md` is the single source of truth for that module and the ONLY file
any future session should write status changes to. Format (plain key: value,
one per line, easy for scripts to grep):

```
id: M001
domain: hr
module: employee-201-file
tier: 1
roles: Admin, Manager, Staff
depends_on: —
surface: M
status: 🔲 Not Started
last_session: —
```

Do NOT create a single shared registry table by hand - it will be generated.

`audit-report.md`, `action-plan.md`, `fix-log.md` can start as empty files
with a one-line placeholder comment.

### Phase 4: Generate the first registry view
Run `audit/scripts/regenerate-registry.sh`. This scans every `status.md` and
builds `audit/00-MODULE-REGISTRY.md` as a read-only generated view. Confirm
it ran and show the resulting table.

### Phase 5: Summary
End with: total modules found, breakdown by tier, any modules you weren't
confident about classifying (flag for the user to confirm), and the
recommended module ID to start with.

## Constraints
- This is inventory only - no bug-finding, no fix proposals.
- Every module you list should map to real files/routes you actually found.
- Never hand-edit `00-MODULE-REGISTRY.md` - it is always generated from the
  per-module `status.md` files.
