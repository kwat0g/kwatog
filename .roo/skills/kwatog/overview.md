---
name: kwatog-overview
description: Master orientation map for the kwatog (Ogami ERP) repo. Read this FIRST whenever starting work on this codebase to know which canonical doc covers your task before writing anything.
---

# Kwatog (Ogami ERP) Overview

Laravel 11 modular-monolith API + React 18 SPA. PHP 8.3, Node 20, Sanctum auth, RBAC permissions, MySQL/Postgres in prod and SQLite in PHPUnit, Tailwind + react-query + react-hook-form + zod in the SPA.

## Layout at a glance

```
api/                              Laravel 11 modular monolith
  app/Modules/<Module>/           one folder per business module:
    Controllers/                  thin, delegate to Service
    Models/                       Eloquent models
    Requests/                     FormRequests with authorize() + rules()
    Resources/                    API JsonResources (output shape)
    Services/                     all business logic lives here
    Enums/ Events/ Exceptions/ Jobs/
    routes.php                    auto-mounted by ModuleServiceProvider under /api/v1
  app/Common/                     cross-module helpers
  app/Http/Controllers/           cross-module / utility controllers
  database/migrations/            numerically prefixed (0NNN_*.php)
  database/seeders/
  routes/api.php                  cross-module / utility routes only
  tests/Feature/  tests/Unit/     PHPUnit
spa/                              React 18 + TS + Vite SPA
  src/pages/<module>/             one folder per business module:
    index.tsx                     list page
    detail.tsx                    detail page
    create.tsx, edit.tsx          forms (often share form.tsx)
  src/api/                        axios calls grouped by domain
  src/components/ui/              shared design-system primitives
  src/lib/                        helpers (formErrors, numberInput, formatNumber, ...)
  src/types/                      shared TypeScript types
  src/stores/                     zustand stores
docs/                             canonical long-form docs (see below)
plans/                            implementation plans (one per sprint or feature)
.roo/                             agent assets (skills, rules, this file)
```

## Canonical docs - read these BEFORE writing code in their domain

| Domain | Doc | When to read |
|---|---|---|
| Code patterns (controllers, services, requests, resources, pages, forms) | [`docs/PATTERNS.md`](../../../docs/PATTERNS.md) | **Always** before writing any controller/service/page/form. 21 numbered patterns. Copy them; do not improvise. |
| Database schema | [`docs/SCHEMA.md`](../../../docs/SCHEMA.md) | Before writing any migration or model. |
| Seeders / fixture data | [`docs/SEEDS.md`](../../../docs/SEEDS.md) | Before adding seed data or running `make fresh`. |
| Design system / UI tokens | [`docs/DESIGN-SYSTEM.md`](../../../docs/DESIGN-SYSTEM.md) | Before adding colors, spacing, typography, or new UI primitives. |
| User-facing manual | [`docs/USER-MANUAL.md`](../../../docs/USER-MANUAL.md) | When writing user-visible help, labels, or onboarding flows. |
| QA / regression matrix | [`docs/QA-MATRIX.md`](../../../docs/QA-MATRIX.md) | When the change touches a feature listed in the matrix. |
| Deploy procedure | [`docs/DEPLOY.md`](../../../docs/DEPLOY.md) | Before any deploy-related change ([`.github/workflows/deploy.yml`](../../../.github/workflows/deploy.yml), Docker prod files). |
| Onboarding | [`docs/GETTING-STARTED.md`](../../../docs/GETTING-STARTED.md) | If unsure how to run the stack locally. |
| Audit findings | [`docs/AUDIT_REPORT.md`](../../../docs/AUDIT_REPORT.md) | Before re-doing work that may already have been audited. |
| Backlog | [`docs/TASKS.md`](../../../docs/TASKS.md), [`docs/NEXT-STEPS.md`](../../../docs/NEXT-STEPS.md), [`docs/NEW-TASKS.md`](../../../docs/NEW-TASKS.md) | When picking up a new task. |

## Other kwatog skills - quick triggers

| If your task is... | Read |
|---|---|
| Anything that ends in "is it done" | [`code-quality-gate.md`](code-quality-gate.md) |
| Adding/modifying an API endpoint | [`add-api-endpoint.md`](add-api-endpoint.md) |
| Adding/modifying an SPA page | [`add-spa-page.md`](add-spa-page.md) |
| Writing a database migration | [`add-database-migration.md`](add-database-migration.md) |
| Touching auth, roles, or permissions | [`rbac-and-permissions.md`](rbac-and-permissions.md) |
| Eloquent query you suspect is slow / N+1 | [`eloquent-performance.md`](eloquent-performance.md) |
| SPA data fetching, caching, mutation | [`spa-state-and-data-fetching.md`](spa-state-and-data-fetching.md) |
| Writing tests | [`testing-strategy.md`](testing-strategy.md) |
| Committing, pushing, opening a PR | [`commit-and-pr.md`](commit-and-pr.md) |

Also see [`INDEX.md`](INDEX.md) and other vendors under [`.roo/skills/`](../) (superpowers TDD/debugging/etc., ruflo SPARC).

## How modules are auto-mounted

[`api/app/Providers/ModuleServiceProvider.php`](../../../api/app/Providers/ModuleServiceProvider.php) discovers each `app/Modules/<Module>/routes.php` and mounts it under `/api/v1`. **Adding a new module** therefore requires creating that folder structure plus the `routes.php` file - no manual route registration. Verify by hitting `GET /api/v1/health` and your new endpoint after `php artisan route:list | grep <module>`.

## Permissions - one rule

Permission strings are `module.resource.action`, e.g. `crm.products.view`, `crm.products.manage`, `accounting.invoices.approve`. They are enforced in three places:

1. Route middleware: `->middleware('permission:crm.products.view')`
2. FormRequest `authorize()`: `return $this->user()?->hasPermission('crm.products.manage') ?? false;`
3. SPA UI gating: `const { can } = usePermission(); ... can('crm.products.manage')`

If you add a new permission you **must** also seed it (see [`rbac-and-permissions.md`](rbac-and-permissions.md)) or production users will get 403s.

## Migrations - one rule

Migrations are **numerically prefixed**, not timestamped: `0NNN_create_<table>_table.php`. Find the next number with `ls api/database/migrations/ | tail -1`. Use `foreignId(...)->constrained(...)` for FKs. See [`add-database-migration.md`](add-database-migration.md) and [`docs/PATTERNS.md`](../../../docs/PATTERNS.md) section 1.

## Where things live - quick lookup

- New API endpoint: `api/app/Modules/<Module>/{Controllers,Requests,Resources,Services}/` + `routes.php`
- New page: `spa/src/pages/<module>/<page>.tsx` + `spa/src/api/<module>/...` + add to router
- New shared UI: `spa/src/components/ui/<Component>.tsx`
- New permission: seed it in `api/database/seeders/PermissionSeeder.php` (or the module-specific seeder)
- New env var: add to `.env.example` and `.env.production.example` AND document in [`docs/DEPLOY.md`](../../../docs/DEPLOY.md)
