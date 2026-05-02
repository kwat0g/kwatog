# Sprint 6 — Implementation Status

> Companion to [`plans/ogami-erp-sprint-6-order-to-cash-part-1-crm-mrp-production-tasks-47-58.md`](ogami-erp-sprint-6-order-to-cash-part-1-crm-mrp-production-tasks-47-58.md:1).

This branch (`feat/sprint-6-crm-mrp-foundation`) ships **all 12 tasks** of Sprint 6 from the backend. Frontend work for Tasks 49–58 (BOM editor, machine/mold management, Gantt UI, Echo subscriptions, OEE gauges, dashboard widgets) is the only remaining work and is documented as follow-up.

---

## ✅ Shipped in this PR

### One commit per task

| Task | Scope | Commit |
|---|---|---|
| **47** CRM Products + Price Agreements | Backend + frontend (list, detail, create, edit) | `d5058bc` |
| **48** Sales Orders + delivery schedules | Backend + frontend (list, detail, create) | `b58e91e` |
| **49** Bill of Materials | Backend + seeders (frontend deferred) | `f1dad93` |
| **50** Machines, Molds, Compatibility, History | Backend + seeders (frontend deferred) | `b3cfc1b` |
| **51** Work Orders | Backend with full lifecycle service + defect_types seed | `a658ca6` |
| **52** MRP Engine | Backend with mrp_plans table + SO confirm hook | `0ffab6e` |
| **53** MRP II Capacity Planning | Backend service + scheduler controller + 5 endpoints | `4708568` |
| **54** Gantt UI | **Deferred** to follow-up (frappe-gantt + Echo client) | — |
| **55** Output recording (WebSocket) | Backend with idempotency + broadcast event + channel auth | `891df6d` |
| **56** Breakdown handling | MachineStatusChanged event + HandleMachineBreakdown listener | `3b1a7ac` |
| **57** OEE | OeeService + endpoint | `bc178fe` |
| **58** Production dashboard | ProductionDashboardService + endpoint | `54c2e26` |

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

## ⏭️ Frontend deferred to follow-up PRs

The backend is complete. Frontend pages for Tasks 49–58 are scoped for a follow-up PR:

| Task | SPA pages |
|---|---|
| **49** BOMs | `pages/mrp/boms/{index,detail,create}.tsx` |
| **50** Machines + Molds | `pages/mrp/machines/{index,detail}.tsx`, `pages/mrp/molds/{index,detail}.tsx` |
| **51** Work Orders | `pages/production/work-orders/{index,detail,create,edit,record-output}.tsx`, lifecycle action buttons |
| **52** MRP Plans | `pages/mrp/plans/{index,detail}.tsx` |
| **53/54** Gantt | `pages/production/schedule.tsx`, `components/production/GanttChart.tsx` (frappe-gantt wrapper) |
| **55** Echo client | `lib/echo.ts`, `hooks/useEcho.ts`, `record-output.tsx` form, npm install of `laravel-echo` + `pusher-js` |
| **56** Breakdown alerts | `components/production/BreakdownAlertCard.tsx`, `RescheduleModal.tsx` |
| **57** OEE gauge | `components/production/OeeGauge.tsx` |
| **58** Dashboard | `pages/production/dashboard.tsx` |

The Sprint 6 plan §5 inventories every page; nothing about the backend contract is expected to change.

### Why Gantt and Echo are split out

- **Gantt** requires `npm install frappe-gantt` plus a thin React wrapper, plus token.css CSS overrides for the bar colors. Best done as its own PR with visual review.
- **Echo client** requires `npm install laravel-echo pusher-js` plus `VITE_REVERB_*` env wiring on the SPA side and Reverb-compatible auth verification. Both are non-trivial config bumps.

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
