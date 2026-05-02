# Sprint 6 — Implementation Status

> Companion to [`plans/ogami-erp-sprint-6-order-to-cash-part-1-crm-mrp-production-tasks-47-58.md`](ogami-erp-sprint-6-order-to-cash-part-1-crm-mrp-production-tasks-47-58.md:1).

This branch (`feat/sprint-6-crm-mrp-foundation`) ships **all 12 tasks** of Sprint 6 — backend AND frontend. Tasks 47, 48 frontend was already in the original PR; Tasks 49–58 frontend is added in commits after the original 12 backend commits.

---

## ✅ Shipped in this PR

### One commit per task

| Task | Scope | Commit |
|---|---|---|
| **47** CRM Products + Price Agreements | Backend + frontend (list, detail, create, edit) | `d5058bc` |
| **48** Sales Orders + delivery schedules | Backend + frontend (list, detail, create) | `b58e91e` |
| **49** Bill of Materials | Backend + frontend (list, detail) | `f1dad93` + frontend follow-up |
| **50** Machines, Molds, Compatibility, History | Backend + frontend (machines list w/ inline status transitions, molds list w/ shot bars) | `b3cfc1b` + frontend follow-up |
| **51** Work Orders | Backend lifecycle + frontend (list, detail with lifecycle action buttons + ChainHeader, record-output form with live WebSocket) | `a658ca6` + frontend follow-up |
| **52** MRP Engine | Backend + frontend (plans list, plan detail with diagnostics table + linked records, re-run action) | `0ffab6e` + frontend follow-up |
| **53** MRP II Capacity Planning | Backend service + scheduler endpoints | `4708568` |
| **54** Gantt UI | frappe-gantt React wrapper + production schedule page (run/confirm/conflict resolution) | frontend follow-up |
| **55** Output recording (WebSocket) | Backend + Echo client + record-output form + dashboard subscriptions | `891df6d` + frontend follow-up |
| **56** Breakdown handling | MachineStatusChanged event + listener + BreakdownAlertCard | `3b1a7ac` + frontend follow-up |
| **57** OEE | OeeService + endpoint + OeeGauge component + per-machine row in dashboard | `bc178fe` + frontend follow-up |
| **58** Production dashboard | ProductionDashboardService + endpoint + full dashboard page (KPIs, stage breakdown, machine util, defect Pareto) | `54c2e26` + frontend follow-up |

### Migrations introduced (0069 → 0086)

```
0069_create_products_table.php
0070_create_product_price_agreements_table.php
0071_create_sales_orders_table.php
0072_create_sales_order_items_table.php
0073_create_bill_of_materials_table.php
0074_create_bom_items_table.php
0075_create_machines_table.php
0076_create_molds_table.php
0077_create_mold_machine_compatibility_table.php
0078_create_mold_history_table.php
0079_create_defect_types_table.php
0080_create_work_orders_table.php          (also adds machines.current_work_order_id FK)
0081_create_work_order_materials_table.php
0082_create_work_order_outputs_table.php
0083_create_work_order_defects_table.php
0084_create_machine_downtimes_table.php
0085_create_production_schedules_table.php
0086_create_mrp_plans_table.php            (adds deferred FKs:
                                            sales_orders.mrp_plan_id,
                                            work_orders.mrp_plan_id,
                                            purchase_requests.mrp_plan_id)
```

### Permissions added to `RolePermissionSeeder`

```
crm.products.{view,manage}
crm.price_agreements.{view,manage}
crm.sales_orders.{view,create,update,delete,confirm,cancel}
mrp.boms.view
mrp.machines.view
mrp.molds.view
mrp.plans.{view,run}
production.work_orders.{view,lifecycle}
production.machines.transition
production.schedule.{view,confirm}
production.dashboard.view
```

System Admin gets all via `*`.

### DocumentSequenceService prefixes added

`mrp_plan` → `MRP-YYYYMM-NNNN`. `sales_order` and `work_order` already existed from Sprint 1.

### Seeders added (in order, all idempotent)

| Seeder | Rows |
|---|---|
| CustomerSeeder | 5 OEMs |
| ProductSeeder | 8 finished-good products |
| PriceAgreementSeeder | 15 (customer × product × period) |
| BomSeeder | 8 BOMs |
| MachineSeeder | 12 injection molders |
| MoldSeeder | 15 molds |
| MoldCompatibilitySeeder | tonnage-band-driven mold ↔ machine links |
| DefectTypeSeeder | 11 injection-molding defect codes |

### Reverb / channel auth

`routes/channels.php` is registered in `bootstrap/app.php` and exposes three private channels:

- `production.dashboard` — `production.dashboard.view`
- `production.wo.{hashId}` — `production.work_orders.view`
- `production.machine.{hashId}` — `mrp.machines.view`

The `WorkOrderOutputRecorded` and `MachineStatusChanged` events both implement `ShouldBroadcast`. Reverb config (existing on main from Sprint 1 Task 2) drives the WebSocket transport.

### Cross-cutting checklist

- ✅ Every model uses `HasHashId` (+ `HasAuditLog` where appropriate); soft deletes on Product, Machine, Mold.
- ✅ Every mutating service method wrapped in `DB::transaction()`.
- ✅ Output recording, BOM versioning, mold shot increment, machine + mold start, MRP plan supersede all use `lockForUpdate`.
- ✅ Every controller action gated by `permission:` + `feature:` middleware AND `FormRequest::authorize()`.
- ✅ Every API Resource returns `hash_id`, never raw integer `id`.
- ✅ Money columns `decimal(15,2)`; quantities `decimal(10,2)`; BOM `quantity_per_unit` `decimal(10,4)`. No floats.
- ✅ All numbers in tables use `font-mono tabular-nums`. Status fields use `<Chip>` with semantic variant.
- ✅ Routes registered with `lazy()` import + AuthGuard + ModuleGuard + PermissionGuard.

### Idempotent flows

- `WorkOrderOutputService::record()` accepts `X-Idempotency-Key` header; duplicate keys within 24h replay the cached output.
- `MrpEngineService::runForSalesOrder()` is idempotent at the per-run level — re-running supersedes the prior plan instead of creating duplicates.
- `BomService::create()` deactivates the prior active BOM in the same transaction — re-creating a BOM is safe.
- `MachineService::transitionStatus()` uses an explicit ALLOWED matrix; illegal transitions throw 409.

---

## Frontend pages shipped

| Task | SPA pages / components |
|---|---|
| **49** BOMs | [`pages/mrp/boms/index.tsx`](spa/src/pages/mrp/boms/index.tsx), [`detail.tsx`](spa/src/pages/mrp/boms/detail.tsx) |
| **50** Machines + Molds | [`pages/mrp/machines/index.tsx`](spa/src/pages/mrp/machines/index.tsx) (with inline status transition select), [`pages/mrp/molds/index.tsx`](spa/src/pages/mrp/molds/index.tsx) (with shot-progress bar) |
| **51** Work Orders | [`pages/production/work-orders/index.tsx`](spa/src/pages/production/work-orders/index.tsx), [`detail.tsx`](spa/src/pages/production/work-orders/detail.tsx) (full lifecycle action buttons + ChainHeader + materials/outputs tabs), [`record-output.tsx`](spa/src/pages/production/work-orders/record-output.tsx) |
| **52** MRP Plans | [`pages/mrp/plans/index.tsx`](spa/src/pages/mrp/plans/index.tsx), [`detail.tsx`](spa/src/pages/mrp/plans/detail.tsx) (diagnostics table + linked records + Re-run action) |
| **53/54** Gantt | [`pages/production/schedule.tsx`](spa/src/pages/production/schedule.tsx) + [`components/production/GanttChart.tsx`](spa/src/components/production/GanttChart.tsx) — frappe-gantt React wrapper with dynamic import (graceful fallback if `npm install` skipped) |
| **55** Echo client | [`lib/echo.ts`](spa/src/lib/echo.ts), [`hooks/useEcho.ts`](spa/src/hooks/useEcho.ts), [`record-output.tsx`](spa/src/pages/production/work-orders/record-output.tsx) form (idempotency keys + live cumulative panel) |
| **56** Breakdown alerts | [`components/production/BreakdownAlertCard.tsx`](spa/src/components/production/BreakdownAlertCard.tsx) — rendered inside the dashboard alerts panel |
| **57** OEE gauge | [`components/production/OeeGauge.tsx`](spa/src/components/production/OeeGauge.tsx) — flat 4-bar (availability / performance / quality / OEE) |
| **58** Dashboard | [`pages/production/dashboard.tsx`](spa/src/pages/production/dashboard.tsx) — KPIs, chain stage breakdown, machine utilization with OEE gauges, defect Pareto, alerts; subscribes to `production.dashboard` channel |

`spa/package.json` now declares `frappe-gantt`, `laravel-echo`, `pusher-js`. CI runs `npm install` so the modules resolve. Locally the deps can be missing without breaking the rest of the app — the Gantt component dynamically imports `frappe-gantt` and renders a placeholder if not present.

`spa/src/App.tsx` and `Sidebar.tsx` are wired with all new routes under `/mrp/*` and `/production/*`, each gated by `AuthGuard` + `ModuleGuard` + `PermissionGuard`.

### Reverb env

Add to `.env` (SPA side) for production:
```
VITE_REVERB_APP_KEY=<your reverb app key>
VITE_REVERB_HOST=<reverb hostname>
VITE_REVERB_PORT=8080
VITE_REVERB_SCHEME=https
```
The Echo client falls back to `window.location.hostname` + port 8080 when env vars are absent — fine for local dev.

---

## ⚠️ Known gaps

- **No Sprint 6 unit / feature tests yet.** The plan §4 documents tests for every service; they're scheduled for the follow-up PR alongside the SPA pages (factories for WorkOrder, MrpPlan, ProductionSchedule are easier to write once the frontend exercises them). The 16 pre-existing PHPUnit failures on this branch match `main` exactly — they trace to a `User::factory()` not seeding `users.role_id` and are unrelated to Sprint 6.
- **`machines.current_work_order_id` and `molds.asset_id`.** The first is FK-constrained by Task 51's migration (0080); the second remains a plain bigint until Sprint 8 Task 70 introduces the `assets` table and adds the constraint.
- **Reverb broadcast in CI.** Events fire in tests with the sync driver; the Reverb daemon itself isn't booted by the test runner.
- **Auto-PR consolidation.** MrpEngineService creates one consolidated PR per run (decision logged in plan §0). The Purchasing officer can split it manually if vendors differ.

---

## Verification checklist

- [ ] `make fresh && make seed` succeeds end-to-end (CI runs this)
- [ ] PHPUnit baseline matches `main` exactly (16/73, all in pre-existing payroll tests — see PR comment)
- [ ] No raw integer IDs in API responses
- [ ] All routes protected by `feature:` + `permission:` middleware
- [ ] Money + quantity columns are `decimal`, never `float`
- [ ] Sidebar shows Products / Price agreements / Sales orders under "Operations" when `modules.crm=true`

---

## How to take this from here

The backend contract is frozen. Follow-up PRs land:

1. **Frontend for Tasks 49–53** (BOMs / machines / molds / MRP plans / scheduler endpoints) — straightforward CRUD pages following the Sprint 5 inventory pattern.
2. **Echo + Gantt PR** (Tasks 54 + 55) — npm installs `laravel-echo`, `pusher-js`, `frappe-gantt`, wires the dashboard subscriptions, builds the Gantt page.
3. **Dashboard + OEE UI PR** (Tasks 57 + 58) — `pages/production/dashboard.tsx`, `OeeGauge.tsx`, defect Pareto chart.
4. **Test PR** — unit tests for OeeService, MrpEngineService, BomExplode, WorkOrderLifecycle, etc.
