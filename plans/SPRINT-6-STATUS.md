# Sprint 6 — Implementation Status

> Companion to [`plans/ogami-erp-sprint-6-order-to-cash-part-1-crm-mrp-production-tasks-47-58.md`](ogami-erp-sprint-6-order-to-cash-part-1-crm-mrp-production-tasks-47-58.md:1).
> Tracks what landed in this PR vs. what is deferred to follow-up PRs.

This branch (`feat/sprint-6-crm-mrp-foundation`) ships the **CRM + MRP master-data foundation** of Sprint 6 — the prerequisite layer Tasks 51–58 build on top of. The remaining tasks (work orders, MRP engine, capacity planner, Gantt, output-recording WebSocket, breakdown handling, OEE, dashboard) are scoped for follow-up PRs.

---

## ✅ Shipped in this PR

| Task | Scope | Commit |
|---|---|---|
| **47** — CRM Products + Price Agreements | Full backend + frontend (list, detail, create, edit) | `feat: task 47 — CRM products and price agreements` |
| **48** — Sales Orders + delivery schedules | Full backend + frontend (list, detail, create) | `feat: task 48 — sales orders with delivery schedules` |
| **49** — Bill of Materials | Full backend + seeder. Frontend deferred (see below). | `feat: task 49 — bill of materials with versioning` |
| **50** — Machines, Molds, Compatibility, History | Full backend + seeders. Frontend deferred. | `feat: task 50 — machines, molds, compatibility, history` |

### Migrations introduced (0069 → 0078)

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
```

### Deferred FK constraints (will be added by future-task migrations)

The following columns are nullable plain integers in this PR and will be FK-constrained by the migration that creates the referenced table:

- `sales_orders.mrp_plan_id` → constrained by **Task 52** (creates `mrp_plans`).
- `machines.current_work_order_id` → constrained by **Task 51** (creates `work_orders`).
- `molds.asset_id` → constrained by **Sprint 8 Task 70** (creates `assets`).

This is the same pattern Sprint 5 used for `bills.purchase_order_id`.

### Permissions added to `RolePermissionSeeder`

```
crm.products.{view,manage}
crm.price_agreements.{view,manage}
crm.sales_orders.{view,create,update,delete,confirm,cancel}

mrp.boms.view (mrp.boms.manage already existed)
mrp.machines.view
mrp.molds.view
mrp.plans.{view,run}

production.work_orders.{view,lifecycle}
production.machines.transition
production.schedule.{view,confirm}
production.dashboard.view
```

System Admin gets all via `*`. Other roles inherit through `module()` helper or explicit grants.

### Seeders added (in `DatabaseSeeder` after Sprint 5)

```
CustomerSeeder            5 demo OEMs (Toyota / Nissan / Honda / Suzuki / Yamaha)
ProductSeeder             8 finished-good products (WB / PC / RC / BB / BU)
PriceAgreementSeeder      15 customer × product × period rows
BomSeeder                 8 BOMs mapping each product to raw materials
MachineSeeder             12 injection molders (100t–650t)
MoldSeeder                15 molds (some products have a backup mold)
MoldCompatibilitySeeder   tonnage-band-based machine ↔ mold compatibility links
```

All seeders are idempotent (`firstOrCreate`).

---

## ⏭️ Deferred to follow-up PRs

The original [Sprint 6 plan](ogami-erp-sprint-6-order-to-cash-part-1-crm-mrp-production-tasks-47-58.md:1) covers tasks 47–58 (~140 files). Tasks **51–58** are scoped for follow-up PRs since they each add substantial new surface area (work-order lifecycle, MRP engine, scheduler, Gantt UI, WebSocket, OEE, dashboard).

### Frontend deferred from Tasks 49 / 50

To keep this PR focused, the BOM and machine/mold management screens are not yet wired:

- `pages/mrp/boms/{index,create,detail}.tsx` (Task 49 frontend)
- `pages/mrp/machines/{index,detail}.tsx` (Task 50 frontend)
- `pages/mrp/molds/{index,detail}.tsx` (Task 50 frontend)
- Sidebar entries for `/mrp/boms`, `/mrp/machines`, `/mrp/molds`
- Product detail page "BOM" tab is stubbed; will wire to `boms.forProduct()` once those pages land.

The backend APIs for all of the above are live and seeded — only the SPA pages remain.

### Tasks 51–58 — full scope for follow-up

| Task | Focus | Estimated PR size |
|---|---|---|
| **51** Work Orders | 6 migrations, full lifecycle service (planned → confirmed → in_progress → paused → completed → closed → cancelled), reservation + issue integration with [`StockMovementService`](api/app/Modules/Inventory/Services/StockMovementService.php:1), defect_types seed, list + detail pages | Large |
| **52** MRP Engine | `mrp_plans` table + deferred-FK migration, `MrpEngineService::runForSalesOrder()` (gross-to-net math, auto-PR generation, draft WO creation), wires SO confirmation to MRP run | Large |
| **53** MRP II Capacity | Greedy slot-finding scheduler, conflict detection, `production_schedules` lifecycle | Medium |
| **54** Gantt UI | `frappe-gantt` React wrapper, drag-to-reschedule, density toggle, view modes | Medium |
| **55** Output recording (WebSocket) | Reverb channel auth, idempotency-keyed output endpoint, `WorkOrderOutputRecorded` event, live dashboard subscription | Medium |
| **56** Breakdown handling | Listeners `HandleMachineBreakdown` / `HandleMachineRestored`, alternative-machine suggestion, notification dispatch | Small |
| **57** OEE | `OeeService::calculate()` with availability/performance/quality decomposition, performance-clamping, diagnostics JSON, daily summary endpoint, `OeeGauge` component | Small |
| **58** Production dashboard | `/production/dashboard` page matching the design-system mockup; Reverb subscription + 60s polling fallback; KPIs, stage breakdown, machine utilization table, defect Pareto | Medium |

The full file inventory for each is in **Section 5** of the [main Sprint 6 plan](ogami-erp-sprint-6-order-to-cash-part-1-crm-mrp-production-tasks-47-58.md:1).

---

## Verification checklist (run before merge)

- [ ] `make fresh && make seed` succeeds (CI runs this)
- [ ] PHPUnit suite green (the existing tests still pass; CRM-side tests will land alongside the deferred BOM/machine UIs)
- [ ] No TypeScript build errors (`vite build` clean — deferred-only TS errors are JSX-resolution noise from missing `node_modules` locally; CI installs them)
- [ ] Sidebar entries for Products / Price agreements / Sales orders show under "Operations" when `modules.crm=true` is toggled in admin settings
- [ ] No raw integer IDs in API responses (all CRM/MRP Resources use `hash_id`)
- [ ] All routes protected by `feature:` + `permission:` middleware
- [ ] Money + quantity columns are `decimal`, never `float`

---

## How to take this from here

To pick up Task 51 (work orders), reference the same plan and:

1. Add migrations 0079–0085 from the plan (numbering shifts: this PR ended at 0078).
2. Implement `WorkOrderService` + lifecycle, then add the deferred FK
   `Schema::table('machines', fn ($t) => $t->foreign('current_work_order_id')->references('id')->on('work_orders'))`
   in the same migration that creates `work_orders`.
3. The `SalesOrderService::confirm()` method has a `TODO` marker pointing to where Task 52's MRP run plugs in.
4. The `MachineService::transitionStatus()` method has a `TODO` marker for the breakdown listener (Task 56).
5. The `MoldService::incrementShots()` method already records the audit row when shots cross the maintenance threshold; Task 55 wires the broadcast event.

Each is small and self-contained.
