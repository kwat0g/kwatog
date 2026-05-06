# Kwatog Skill Loader

This repo carries kwatog-specific (Ogami ERP) skills under [`.roo/skills/kwatog/`](../skills/kwatog/). They are concrete to **this** Laravel 11 + React 18 modular monolith and complement the generic [`.roo/skills/superpowers/`](../skills/superpowers/) discipline skills. Full catalog: [`.roo/skills/kwatog/INDEX.md`](../skills/kwatog/INDEX.md).

## Always

- **Starting any task in this repo** -> read [`.roo/skills/kwatog/overview.md`](../skills/kwatog/overview.md) before producing a plan or code. It is the orientation map for `api/`, `spa/`, `docs/`, conventions, and where canonical long-form docs (especially [`docs/PATTERNS.md`](../../docs/PATTERNS.md)) live.
- **Before claiming any code change is complete** -> read [`.roo/skills/kwatog/code-quality-gate.md`](../skills/kwatog/code-quality-gate.md). Run the gate commands. Report results in your completion message. For recurring quality-gate enforcement, switch to the `kwatog-quality-gate` custom mode (see [`.roomodes`](../../.roomodes)).

## Triggers

- **Adding or modifying any API endpoint under `api/app/Modules/`** -> read [`.roo/skills/kwatog/add-api-endpoint.md`](../skills/kwatog/add-api-endpoint.md). Follow the file order (Migration -> Model -> Service -> FormRequests -> Resource -> Controller -> Routes -> Permission seeding -> Tests). Cross-reference [`docs/PATTERNS.md`](../../docs/PATTERNS.md) sections 1-7.
- **Adding or modifying any SPA page under `spa/src/pages/`** -> read [`.roo/skills/kwatog/add-spa-page.md`](../skills/kwatog/add-spa-page.md). Follow Types -> API client -> List -> Detail -> Form (single `form.tsx` + thin `create.tsx`/`edit.tsx`) -> Routes -> Tests. Cross-reference [`docs/PATTERNS.md`](../../docs/PATTERNS.md) sections 8-21.
- **Writing any database migration** -> read [`.roo/skills/kwatog/add-database-migration.md`](../skills/kwatog/add-database-migration.md). Numerical-prefix `0NNN_<verb>_<table>_table.php`, FKs use `foreignId(...)->constrained(...)`, always implement `down()`, update [`docs/SCHEMA.md`](../../docs/SCHEMA.md) and seeders.
- **Adding, renaming, or gating any permission** -> read [`.roo/skills/kwatog/rbac-and-permissions.md`](../skills/kwatog/rbac-and-permissions.md). Permissions are `module.resource.action` and must be enforced at the route, FormRequest, and SPA UI. New permissions require seeding or production users get 403s.
- **Touching user input, auth, file upload, money, or PII** -> read [`.roo/skills/kwatog/security-review.md`](../skills/kwatog/security-review.md). Apply the Laravel + React threat-model checklist; the Philippine-context PII guidance (TIN, SSS, PhilHealth, PAG-IBIG) matters here.
- **Writing or reviewing any Eloquent list/detail query** -> read [`.roo/skills/kwatog/eloquent-performance.md`](../skills/kwatog/eloquent-performance.md). N+1 is the most common kwatog perf bug; eager-load every relation a Resource accesses; index every column you filter or sort by.
- **Wiring data fetching, mutations, or shared state to any SPA page** -> read [`.roo/skills/kwatog/spa-state-and-data-fetching.md`](../skills/kwatog/spa-state-and-data-fetching.md). Server data lives in react-query, UI state in zustand, URL state in router params, form state in react-hook-form. Mixing these causes most stale-data bugs.
- **Writing any test (PHPUnit or Vitest)** -> read [`.roo/skills/kwatog/testing-strategy.md`](../skills/kwatog/testing-strategy.md). The minimum-viable-test-set per endpoint includes the 403-without-permission case.
- **Before any commit, push, or PR** -> read [`.roo/skills/kwatog/commit-and-pr.md`](../skills/kwatog/commit-and-pr.md). Branch naming, conventional commits, PR body with gate results, target `kwat0g/kwatog`, wait for CI before claiming done.

## How to honor a trigger

1. Recognize the trigger condition in the user's task.
2. `read_file` the named skill before producing your plan or code.
3. Cross-reference the kwatog canonical docs the skill points at ([`docs/PATTERNS.md`](../../docs/PATTERNS.md), [`docs/SCHEMA.md`](../../docs/SCHEMA.md), [`docs/SEEDS.md`](../../docs/SEEDS.md), [`docs/DESIGN-SYSTEM.md`](../../docs/DESIGN-SYSTEM.md), [`docs/QA-MATRIX.md`](../../docs/QA-MATRIX.md), [`docs/DEPLOY.md`](../../docs/DEPLOY.md)) before writing.
4. Project rules in [`.roo/rules/`](.) and [`CLAUDE.md`](../../CLAUDE.md) override anything in a skill if they conflict; flag the conflict explicitly.

## Strongest enforcement

Switch to the matching custom mode for the strongest adherence:

- `kwatog-quality-gate` - enforces lint+typecheck+test before any "done" claim.
- Combine with `superpowers-tdd` (vendored) for new feature work and `superpowers-debug` (vendored) for bug investigation.
