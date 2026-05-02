# Sprint 6 — Order to Cash (Part 1: CRM + MRP + Production) (Tasks 47–58)

> Stands up the operational layer of **Chain 1 — Order to Cash**: products, customer price agreements, sales orders, BOMs, machines, molds, work orders, the MRP planning engine, the MRP II capacity scheduler, the Gantt UI, real-time production output via WebSocket, machine-breakdown rescheduling, and OEE. Quality (Sprint 7) and deliveries / invoicing (Sprint 7+4) clip onto the chain we build here. Every screen mirrors [`docs/PATTERNS.md`](docs/PATTERNS.md:1) section-for-section; every column matches [`docs/SCHEMA.md`](docs/SCHEMA.md:254); every demo row comes from [`docs/SEEDS.md`](docs/SEEDS.md:1).

---

## 0. Scope, dependencies, and ground rules

### Inbound dependencies (must already exist from Sprints 1–5)

- **Sprint 1 foundation** — [`HasHashId`](api/app/Common/Traits/HasHashId.php:1), [`HasAuditLog`](api/app/Common/Traits/HasAuditLog.php:1), [`HasApprovalWorkflow`](api/app/Common/Traits/HasApprovalWorkflow.php:1), [`DocumentSequenceService`](api/app/Common/Services/DocumentSequenceService.php:1), [`ApprovalService`](api/app/Common/Services/ApprovalService.php:1), [`NotificationService`](api/app/Common/Services/NotificationService.php:1), [`SettingsService`](api/app/Common/Services/SettingsService.php:1), [`tokens.css`](spa/src/styles/tokens.css:1), [`DataTable`](spa/src/components/ui/DataTable.tsx:1), [`Chip`](spa/src/components/ui/Chip.tsx:1), [`PageHeader`](spa/src/components/layout/PageHeader.tsx:1), [`FilterBar`](spa/src/components/ui/FilterBar.tsx:1), [`ChainHeader`](spa/src/components/chain/ChainHeader.tsx:1), [`StageBreakdown`](spa/src/components/chain/StageBreakdown.tsx:1), [`LinkedRecords`](spa/src/components/chain/LinkedRecords.tsx:1), the three guards.
- **Sprint 1 Task 12** — feature toggles `modules.crm=true`, `modules.mrp=true`, `modules.production=true` flipped on for Semester 2.
- **Sprint 1 Task 11** — workflow definitions seeded. Sprint 6 does NOT add new workflow types; sales orders are not approved (CRM Officer issues them and they auto-confirm), and work orders are not approved (PPC Head schedules them via the Gantt confirmation flow).
- **Sprint 2 Task 13** — `departments` table (used for created-by audit trails on sales orders).
- **Sprint 4 Task 31** — `accounts` table. Sprint 6 does NOT post to GL — production is operational; revenue posts via Sprint 4 invoice (after Sprint 7 delivery).
- **Sprint 4 Task 34** — `customers` table exists and is referenced by both [`product_price_agreements`](docs/SCHEMA.md:307) and [`sales_orders`](docs/SCHEMA.md:310). Sprint 6 enriches the customer detail page with a "Price Agreements" tab and a "Sales Orders" tab — does NOT re-define the customer model.
- **Sprint 5 Task 39** — `items` table exists (BOM line items reference raw materials).
- **Sprint 5 Task 41** — [`StockMovementService`](api/app/Modules/Inventory/Services/StockMovementService.php:1), `material_reservations`, `material_issue_slips`. Work-order confirmation reserves materials; work-order start issues them.
- **Sprint 5 Task 42** — `purchase_requests` exists with `priority` column (added in Sprint 5 schema reconciliation). MRP engine creates draft PRs.
- **Sprint 5 Task 45** — low-stock auto-PR listener. Sprint 6's MRP engine reuses that PR creation pathway with a different `is_auto_generated` source-tag (`mrp` vs `low_stock`).

### Outbound consumers (what Sprints 7–8 will call into)

- **Sprint 7 Task 59 (inspection specs)** — keyed off `products.id`. Specs editor lives there; Sprint 6 must not pre-empt that route.
- **Sprint 7 Task 60 (3-stage inspections)** — `inspections.inspected_entity_type` will reference `work_orders` (in-process) and the future `deliveries` table (outgoing). Sprint 6 leaves a clean polymorphic anchor: work orders expose a stable hash_id and `inspected_entity_type='work_order'` is the agreed string.
- **Sprint 7 Task 61 (NCR)** — auto-creates a `replacement_wo_id` work order on `disposition=scrap`. Work order create API must accept a `parent_ncr_id` reference (additive nullable column — see schema reconciliation).
- **Sprint 7 Task 62 (CoC)** — generated per delivery from a batch; the batch is derived from `work_order_outputs`. Output recording (Task 55) must keep `work_order_outputs.batch_code` populated.
- **Sprint 7 Task 65 / 66 (deliveries)** — `deliveries.sales_order_id` and `delivery_items.work_order_id` must FK back into Sprint 6 tables. Migrations from Sprint 7 will land later; Sprint 6 simply makes its FKs deletable (`onDelete restrict`) so Sprint 7 can attach without changing prior tables.
- **Sprint 7 Task 68 (complaints + 8D)** — references `sales_orders` and `products`; `customer_complaints.replacement_wo_id` FKs to `work_orders`. Same contract as NCR.
- **Sprint 8 Task 69 (maintenance)** — `mold_history` and `machine_downtimes` are written in Sprint 6 but read by maintenance scheduling in Sprint 8. Field shapes are frozen here.
- **Sprint 8 Task 70 (assets)** — `molds.asset_id` and `machines` will be linked to assets in Sprint 8; nullable now.
- **Sprint 8 Task 78 (Reverb / Echo)** — Sprint 6 emits the first WebSocket events. Sprint 8 consolidates the broadcaster config; Sprint 6 must register channels under the documented names: `production.wo.{hash_id}`, `production.machine.{hash_id}`, `production.dashboard`.

### Cross-cutting guarantees (verify on every file)

- ✅ Every model with a routable URL uses [`HasHashId`](api/app/Common/Traits/HasHashId.php:1). WebSocket channels also key on hash_id, never raw integers.
- ✅ Every mutating service method wrapped in `DB::transaction()`. Material reservation, output recording, and MRP-engine runs all hold row locks (`lockForUpdate`) on contested rows (`stock_levels`, `molds.current_shot_count`, `machines.current_work_order_id`).
- ✅ Every controller action gated by `permission:crm.*`, `permission:mrp.*`, or `permission:production.*` middleware AND `FormRequest::authorize()`. Server-side enforcement is mandatory; frontend guards are UX only.
- ✅ Every list page renders all 5 mandatory states (loading skeleton, error+retry, empty, data, stale via `placeholderData`).
- ✅ Every monetary value rendered with `font-mono tabular-nums`; status with `<Chip variant=…>`; canvas stays grayscale.
- ✅ Routes registered with lazy import + [`AuthGuard`](spa/src/components/guards/AuthGuard.tsx:1) + [`ModuleGuard`](spa/src/components/guards/ModuleGuard.tsx:1) + [`PermissionGuard`](spa/src/components/guards/PermissionGuard.tsx:1).
- ✅ SO / WO / MRP plan numbering goes through [`DocumentSequenceService`](api/app/Common/Services/DocumentSequenceService.php:1): `SO-YYYYMM-NNNN`, `WO-YYYYMM-NNNN`, `MRP-YYYYMM-NNNN`.
- ✅ Pricing pulls always go through [`PriceAgreementService::resolve(customer, product, date)`](api/app/Modules/CRM/Services/PriceAgreementService.php:1) — never inline.
- ✅ All money decimal(15,2). All quantities decimal(10,2) on documents, integer on production counts (per [`docs/SCHEMA.md`](docs/SCHEMA.md:257) — `quantity_target` is `decimal(10,0)`). WAC and unit costs stay 4-decimal but Sprint 6 never writes WAC; it only reads via Sprint 5 services.
- ✅ Every route has both a `permission:` middleware AND a `feature:` middleware (`feature:crm`, `feature:mrp`, `feature:production`).

### Schema reconciliation resolved up front

| Issue | Resolution |
|---|---|
| [`docs/SCHEMA.md`](docs/SCHEMA.md:310) `sales_orders.status` enum lacks `draft`. CRM officers need to save partial SOs before confirming. | Extend enum to `draft / confirmed / in_production / partially_delivered / delivered / invoiced / cancelled`. SO created from the form lands as `draft`; `POST /sales-orders/{id}/confirm` flips it to `confirmed` and triggers MRP. Document in migration. |
| [`docs/SCHEMA.md`](docs/SCHEMA.md:310) `sales_orders` lacks `delivery_terms`, `payment_terms_days`, `notes`. The PDF (Sprint 7 invoice) and CRM customer chain visualization need them. | Add `payment_terms_days` (int default 30), `delivery_terms` (string 50 nullable), `notes` (text nullable). Additive. |
| [`docs/SCHEMA.md`](docs/SCHEMA.md:310) `sales_orders` lacks `mrp_plan_id`. The "MRP run" produces a plan record we need to link back to. | New table `mrp_plans` introduced (Task 52); SO gets nullable `mrp_plan_id` (FK mrp_plans). Additive. |
| `mrp_plans` is **not in [`docs/SCHEMA.md`](docs/SCHEMA.md:279)** but [`CLAUDE.md`](CLAUDE.md:64) and [`docs/PATTERNS.md`](docs/PATTERNS.md:1) repeatedly reference an "MRP Plan" entity (`MRP-202604-0008`). | New table introduced this sprint — see Section 4 Task 52. Schema documented in this plan; SCHEMA.md gets an addendum committed alongside the migration. |
| [`docs/SCHEMA.md`](docs/SCHEMA.md:257) `work_orders.status` lacks `cancelled`. NCR/complaint flows in Sprint 7 may cancel WOs that haven't started. | Extend enum to `planned / confirmed / in_progress / paused / completed / closed / cancelled`. Document in migration. |
| [`docs/SCHEMA.md`](docs/SCHEMA.md:257) `work_orders` has no `parent_wo_id` or `parent_ncr_id`. NCR replacement WOs need linkage; rework WOs need to point to the failing WO. | Add `parent_wo_id` (FK work_orders nullable, onDelete restrict), `parent_ncr_id` (bigint nullable — leaves the FK to be added by Sprint 7 once `non_conformance_reports` exists; document in migration). Additive. |
| [`docs/SCHEMA.md`](docs/SCHEMA.md:257) `work_orders` has no `mrp_plan_id`. We need the back-link to know the planning context. | Add `mrp_plan_id` (FK mrp_plans nullable). Additive. |
| [`docs/SCHEMA.md`](docs/SCHEMA.md:263) `work_order_outputs` has no `batch_code`. Outgoing CoC (Sprint 7) needs traceability per recording session. | Add `batch_code` (string 30 nullable). Generated as `{wo_number}-B{NN}` per output. Additive. |
| [`docs/SCHEMA.md`](docs/SCHEMA.md:291) `machines` enum `running/idle/maintenance/breakdown` is missing `offline` (off-shift, not actually broken). | Extend to `running / idle / maintenance / breakdown / offline`. `offline` is set by the shift planner on weekends/non-shift hours. Document. |
| [`docs/SCHEMA.md`](docs/SCHEMA.md:294) `molds.status` is `string 20` with no documented values. | Constrain via PHP enum `MoldStatus`: `available / in_use / maintenance / retired`. No DB CHECK. |
| [`docs/SCHEMA.md`](docs/SCHEMA.md:275) `production_schedules` lacks `status`. We need to know when a schedule line is `pending / confirmed / superseded` (e.g., when a breakdown reroutes WOs). | Add `status` (string 20 default `pending`). Values: `pending / confirmed / superseded / executed`. Additive. |
| [`docs/SCHEMA.md`](docs/SCHEMA.md:272) `machine_downtimes.duration_minutes` is null until close. We also need `is_planned` for OEE math (planned downtime excluded from "available time"). | Already implicit via `category` (`planned_maintenance / changeover` are planned). Document the rule in [`OeeService`](api/app/Modules/Production/Services/OeeService.php:1) instead of adding a column. |
| `defect_types` table per [`docs/SCHEMA.md`](docs/SCHEMA.md:269) — Sprint 6 owns the seed (Task 51). | Seed 11 defect codes from [`docs/SEEDS.md`](docs/SEEDS.md:1) section "Defect Types". |
| Pricing on SO line items: do we cache the price on `sales_order_items.unit_price` or recompute every time? | **Cache.** `unit_price` is captured at SO creation from the active price agreement and never re-pulled. This protects the customer from price changes after order confirmation. Resolution service runs ONCE per line at creation. |
| `sales_order_items.delivery_date` per line vs. `sales_orders.date` (order date) — the chain plan implies one SO can have staggered deliveries. | Confirm: each SO line carries its own `delivery_date`. MRP engine uses `delivery_date - lead_time` to compute order-by date for materials. |
| Cross-shift WO output: when an output is recorded that crosses midnight, which date does it count for? | **By recording timestamp**, not by shift. Dashboard "Today Output" filters on `recorded_at::date = current_date_in_company_tz`. Document this in the OEE service and the dashboard query. |

### Production math, rounding, and concurrency rules

- **MRP gross-to-net:**
  ```
  gross_required[material] = Σ over SO lines: bom.qty_per_unit * (1 + waste_factor) * so_line.qty
  on_hand                  = Σ stock_levels.quantity over all locations
  reserved                 = Σ stock_levels.reserved_quantity (for OTHER active WOs)
  in_transit               = Σ purchase_order_items.(quantity - quantity_received) for approved/sent POs
  net_required             = max(0, gross_required - on_hand + reserved - in_transit)
  order_by_date            = so_line.delivery_date - lead_time_days - safety_buffer_days(=2)
  ```
  Quantities in 3 decimals (HALF UP). Negative net rounds to 0. Fractional units rounded UP to next whole unit only at PR creation time (you cannot order half a kilo of resin on most suppliers — confirm per item.unit_of_measure; default round to 0.001).
- **MRP II capacity:**
  ```
  cycle_time      = mold.cycle_time_seconds
  output_per_hour = mold.output_rate_per_hour                  (cached, must reconcile with cycle_time)
  setup_minutes   = mold.setup_time_minutes
  duration_hours  = (qty / output_per_hour) + (setup_minutes / 60)
  ```
  The scheduler picks (machine, mold) pairs subject to:
  1. `mold_machine_compatibility(mold, machine)` row exists
  2. `mold.current_shot_count + qty <= mold.max_shots_before_maintenance` (else: choose a different mold OR schedule maintenance first)
  3. `machine.status IN (idle)` for the slot OR existing `production_schedules` row free
  4. Shift availability via `machine.available_hours_per_day` * working days
  Conflicts logged to a `scheduling_conflicts` JSON return (not persisted) so the PPC head can resolve in-UI.
- **OEE** (per machine, per period — typically per shift or per day):
  ```
  available_time = scheduled_minutes - planned_downtime_minutes
  run_time       = available_time - unplanned_downtime_minutes
  good_count     = Σ work_order_outputs.good_count in period
  reject_count   = Σ work_order_outputs.reject_count in period
  ideal_cycle    = average(mold.cycle_time_seconds across WOs in period, weighted by output count)

  availability   = run_time / available_time
  performance    = ((good_count + reject_count) * ideal_cycle / 60) / run_time
  quality        = good_count / max(1, good_count + reject_count)
  oee            = availability * performance * quality
  ```
  All four reported as decimal(5,4) — render as percentage with one decimal in UI (`87.4%`). Cap each at 1.0; if performance computes > 1 (cycle assumption stale), clamp to 1.0 and emit a warning into `oee.diagnostics` JSON. OEE numbers below 0.85 chip-warning, below 0.70 chip-danger.
- **Concurrency**:
  - Output recording locks `work_orders` row + the related `mold` row (shot count increment must be atomic under high-frequency input).
  - MRP run locks the whole `mrp_plans` row for the SO; only one MRP run per SO at a time.
  - Material reservation locks `stock_levels` rows ordered by `(item_id, location_id)`.
  - Machine status transitions use a small state machine; the controller validates legal transitions (idle→running, running→idle/breakdown/maintenance, etc.). Illegal transition returns 409 Conflict.

### Decision log (made up front so we don't relitigate during build)

1. **Pricing snapshot at SO creation: YES.** `sales_order_items.unit_price` is computed from the active price agreement at SO creation and frozen. If no agreement exists for that customer+product+date, the form blocks save with an inline error directing the user to create a price agreement first. `products.standard_cost` is NEVER used as a customer price.
2. **Auto-confirm SO: NO.** SO is `draft` on save. Explicit `Confirm Order` button (server-side `POST /sales-orders/{id}/confirm`) flips status to `confirmed`, triggers MRP run, and creates draft work orders. This separates data entry (CRM officer) from operational commitment (CRM officer + dept head).
3. **Auto-trigger MRP on SO confirmation: YES.** MRP engine runs synchronously inside the confirmation transaction — for thesis-scale data (≤ 8 lines, ≤ 50 BOM items each), this completes in under 1 second. Fallback to queue if the run exceeds 5 seconds (`ProcessMrpRunJob`).
4. **Auto-create draft Work Orders from MRP: YES — one per SO line.** Each SO line becomes one draft WO in `planned` state. PPC Head reviews the Gantt and `confirms` them as a batch (status → `confirmed`, materials reserved, machine/mold locked).
5. **Material reservation lifecycle:** `planned` WO has NO reservation. `confirmed` WO reserves materials (via [`StockMovementService::reserve()`](api/app/Modules/Inventory/Services/StockMovementService.php:1) — Sprint 5). `in_progress` WO issues materials (via material issue slip — Sprint 5). `paused` WO retains reservations but releases its machine slot. `cancelled` WO releases reservations.
6. **Mold shot count: increment per output, not per shift.** Every `work_order_outputs.good_count + reject_count` increments `molds.current_shot_count` and `molds.lifetime_total_shots` atomically. At 80% of `max_shots_before_maintenance`, fire `MoldShotLimitNearingEvent` (Sprint 8 Task 78 listens; Sprint 6 broadcasts the event). At 100%, mold cannot start a new WO (status flips to `maintenance` automatically).
7. **Gantt scheduler library: `frappe-gantt`** as instructed in [`docs/TASKS.md`](docs/TASKS.md:199). Lightweight, MIT, no dependencies. Wrap in a thin React component because frappe-gantt is vanilla JS.
8. **WebSocket channels are private channels.** Use Laravel Reverb's private channel auth — only authorized users (production.dashboard.view permission) can subscribe. Sprint 6 wires `BroadcastServiceProvider` channel routes; Sprint 8 polishes the auth UX.
9. **Production output dedupe.** Supervisors might double-tap submit. Output endpoint requires an idempotency key (UUID generated client-side, sent via header `X-Idempotency-Key`). Server stores the last 24h of keys in Redis (`output:idem:{key}` TTL 86400) and returns 200 with the original payload on duplicate.
10. **MRP plan immutability.** Once an `mrp_plans` row is created, it is append-only. Re-running MRP for the same SO creates a new plan version (`version` increments) and supersedes the previous one (`status='superseded'`). This preserves audit trail. Stale auto-PRs from a superseded plan stay as draft and the Purchasing officer can discard them.
11. **Demo data realism.** Seeds add 5 customers + 8 products + 15 price agreements + 8 BOMs + 12 machines + 15 molds + 11 defect types. SOs/WOs are seeded by `DemoDataSeeder` (Sprint 8 Task 80) — Sprint 6 ships only **structural** seeds.
12. **Scheduler conflict resolution UX.** When the capacity planner can't fit a WO, it returns the WO with a `conflicts` array; the Gantt UI shows the WO as a red striped bar in a "Unscheduled" swimlane. PPC head drags it manually OR clicks "Force Schedule" which extends the planning horizon. No magic auto-resolution.

---

## 1. Permission catalogue (extend `RolePermissionSeeder`)

A subset already exists from Sprint 1 Task 10. Sprint 6 adds the rest **before** wiring controllers. Edit [`RolePermissionSeeder`](api/database/seeders/RolePermissionSeeder.php:1):

```
crm.products.view
crm.products.manage
crm.price_agreements.view
crm.price_agreements.manage
crm.sales_orders.view
crm.sales_orders.create
crm.sales_orders.update            (only when status=draft)
crm.sales_orders.delete            (only when status=draft)
crm.sales_orders.confirm           (draft → confirmed; triggers MRP)
crm.sales_orders.cancel            (any status before delivered)

mrp.boms.view
mrp.boms.manage                    (create/update/version)
mrp.machines.view
mrp.machines.manage
mrp.machines.transition_status     (idle ↔ running ↔ maintenance ↔ breakdown ↔ offline)
mrp.molds.view
mrp.molds.manage
mrp.plans.view
mrp.plans.run                      (force re-run of MRP for a SO)

production.work_orders.view
production.work_orders.create
production.work_orders.update      (only when status=planned)
production.work_orders.delete      (only when status=planned)
production.work_orders.confirm     (planned → confirmed; reserves materials)
production.work_orders.start       (confirmed → in_progress; locks machine + mold)
production.work_orders.pause       (in_progress → paused; logs downtime)
production.work_orders.resume      (paused → in_progress)
production.work_orders.complete    (in_progress → completed)
production.work_orders.close       (completed → closed; finalizes scrap rate, releases mold)
production.work_orders.cancel
production.outputs.record          (record good/reject count + defects)
production.schedule.view
production.schedule.confirm        (PPC head confirms a generated schedule batch)
production.schedule.reorder        (drag in Gantt to change priority_order)
production.schedule.reassign       (move WO to a different machine in Gantt)
production.dashboard.view
```

**Role grants:**

- **System Admin:** all
- **CRM Officer:** all `crm.*`; `mrp.plans.view`, `production.work_orders.view` (read-only on chain)
- **PPC Head:** `mrp.*` (all), `production.work_orders.{view,confirm,update,cancel}`, `production.schedule.*`, `production.dashboard.view`, `crm.sales_orders.view`
- **Production Manager:** `production.*` (all), `mrp.molds.view`, `mrp.machines.view`, `mrp.machines.transition_status`, `mrp.boms.view`, `mrp.plans.view`, `crm.sales_orders.view`
- **Production Supervisor (NEW role — add to seeder if not present):** `production.work_orders.{view,start,pause,resume,complete}`, `production.outputs.record`, `production.dashboard.view`
- **Maintenance Tech:** `mrp.machines.view`, `mrp.machines.transition_status`, `mrp.molds.view`, `production.work_orders.view` (cross-cutting; full maintenance perms come Sprint 8)
- **Department Head:** read on everything in own department's scope (filtered server-side via row-level scope)
- **Employee (self-service):** none of the Sprint 6 perms

If `Production Supervisor` role does not exist, add it in this sprint's seeder migration (idempotent — `Role::firstOrCreate`).

---

## 2. Workflow definitions touched

Sprint 6 does not introduce any approval workflows. Document this explicitly in the [`WorkflowDefinitionSeeder`](api/database/seeders/WorkflowDefinitionSeeder.php:1) header so future readers don't go hunting:

```php
// Sprint 6 (CRM/MRP/Production): no new workflow types.
// Sales Order confirmation, Work Order confirmation, and PPC schedule confirmation
// are single-actor permission-gated transitions, not multi-step approval workflows.
```

---

## 3. Document sequences touched

[`DocumentSequenceService`](api/app/Common/Services/DocumentSequenceService.php:1) gains three new prefixes (these are simply registered by use; no new code):

| Type | Format | Where generated |
|---|---|---|
| `sales_order` | `SO-YYYYMM-NNNN` | [`SalesOrderService::create()`](api/app/Modules/CRM/Services/SalesOrderService.php:1) |
| `work_order` | `WO-YYYYMM-NNNN` | [`WorkOrderService::createDraft()`](api/app/Modules/Production/Services/WorkOrderService.php:1) |
| `mrp_plan` | `MRP-YYYYMM-NNNN` | [`MrpEngineService::runForSalesOrder()`](api/app/Modules/MRP/Services/MrpEngineService.php:1) |

Verify the generator emits monotonically increasing numbers under contention by hitting `POST /sales-orders` 50× in parallel in [`api/tests/Feature/DocumentSequenceConcurrencyTest.php`](api/tests/Feature/DocumentSequenceConcurrencyTest.php:1) (existing test from Sprint 1; add cases for `sales_order` and `work_order`).

---

## 4. Per-task plan

Tasks build in strict dependency order. Each task ends in a green-bar test run + a single git commit.

### Task 47 — CRM Products + Price Agreements

**Goal:** Establish the product catalog and the customer-specific pricing matrix. This is the master data the rest of the chain consumes.

**Schema.** Two migrations.

`api/database/migrations/0062_create_products_table.php`
- Per [`docs/SCHEMA.md`](docs/SCHEMA.md:282): `id, part_number (string 30 unique), name (string 200), description (text nullable), unit_of_measure (string 20 default 'pcs'), standard_cost (decimal 15,2 default 0), is_active (bool default true), timestamps, soft delete`.
- Indexes: `is_active` (filter), `part_number` (already unique).

`api/database/migrations/0063_create_product_price_agreements_table.php`
- Per [`docs/SCHEMA.md`](docs/SCHEMA.md:307): `id, product_id (FK products onDelete restrict), customer_id (FK customers onDelete restrict), price (decimal 15,2), effective_from (date), effective_to (date), timestamps, INDEX (product_id, customer_id, effective_from)`.
- Add a partial uniqueness rule **in service** (not DB): no two overlapping windows for the same `(customer, product)`. Validation handled in [`PriceAgreementService::create()`](api/app/Modules/CRM/Services/PriceAgreementService.php:1).

**Enums.** None.

**Models.**
- [`api/app/Modules/CRM/Models/Product.php`](api/app/Modules/CRM/Models/Product.php:1) — `HasHashId, HasAuditLog, SoftDeletes`. Casts: `standard_cost decimal:2, is_active boolean`. Relations: `priceAgreements() HasMany`, `bom() HasOne` (Task 49), `salesOrderItems() HasMany` (Task 48), `inspectionSpec() HasOne` (Sprint 7).
- [`api/app/Modules/CRM/Models/PriceAgreement.php`](api/app/Modules/CRM/Models/PriceAgreement.php:1) — `HasHashId, HasAuditLog`. Casts: `price decimal:2, effective_from date, effective_to date`. Relations: `product()`, `customer()`. Scope: `scopeActiveOn(Builder, Carbon $date)`.

**Services.**
- [`api/app/Modules/CRM/Services/ProductService.php`](api/app/Modules/CRM/Services/ProductService.php:1) — standard CRUD via [`docs/PATTERNS.md`](docs/PATTERNS.md:212) Section 3. List supports `search` (part_number/name), `is_active`, `has_bom` (joinExists).
- [`api/app/Modules/CRM/Services/PriceAgreementService.php`](api/app/Modules/CRM/Services/PriceAgreementService.php:1) — methods:
  - `create(array $data): PriceAgreement` — validates no overlap, wraps in transaction.
  - `update(PriceAgreement $a, array $data): PriceAgreement` — same overlap check excluding self.
  - `resolve(int $customerId, int $productId, Carbon $date): ?PriceAgreement` — returns active agreement on date or null. **This is the single allowed pricing entry point in the codebase.** SO line creation calls it; if null, throw `NoPriceAgreementException` (renders 422 with `errors: { product_id: 'No active price agreement for this customer.' }`).
  - `listForCustomer(int $customerId): Collection` — eager loads product, ordered by part_number.

**Form Requests.**
- `StoreProductRequest`, `UpdateProductRequest`, `ListProductsRequest`.
- `StorePriceAgreementRequest`, `UpdatePriceAgreementRequest`. Rules per [`docs/PATTERNS.md`](docs/PATTERNS.md:340) Section 4: `effective_from before effective_to`, `price min:0`, FK `exists` validation.

**API Resources.**
- `ProductResource` — returns hash_id, part_number, name, unit_of_measure, standard_cost (string for decimal), is_active, has_bom (computed), has_inspection_spec (computed from `whenLoaded`), timestamps.
- `PriceAgreementResource` — hash_id, product (resource), customer (resource), price, effective_from, effective_to, is_currently_active (computed).

**Controllers.**
- `ProductController` — full resource controller per [`docs/PATTERNS.md`](docs/PATTERNS.md:506) Section 6. Routes prefix `products`, middleware `auth:sanctum,feature:crm`.
- `PriceAgreementController` — full resource controller. Routes prefix `price-agreements`. Add nested `GET /customers/{customer}/price-agreements` for the customer detail tab.

**Routes.** [`api/app/Modules/CRM/routes.php`](api/app/Modules/CRM/routes.php:1) — replace existing scaffold from Sprint 4:

```php
Route::middleware(['auth:sanctum', 'feature:crm'])->group(function () {
    Route::apiResource('products', ProductController::class)
        ->middleware([
            'index'   => 'permission:crm.products.view',
            'show'    => 'permission:crm.products.view',
            'store'   => 'permission:crm.products.manage',
            'update'  => 'permission:crm.products.manage',
            'destroy' => 'permission:crm.products.manage',
        ]);

    Route::apiResource('price-agreements', PriceAgreementController::class)
        ->middleware([/* permission:crm.price_agreements.* */]);
    Route::get('customers/{customer}/price-agreements', [PriceAgreementController::class, 'forCustomer'])
        ->middleware('permission:crm.price_agreements.view');
});
```

**Seeds.** [`ProductSeeder`](api/database/seeders/ProductSeeder.php:1) — 8 products from [`docs/SEEDS.md`](docs/SEEDS.md:1) "Products" section (wiper bushings, pivot caps, relay covers etc. for Toyota/Nissan/Honda/Suzuki/Yamaha). [`PriceAgreementSeeder`](api/database/seeders/PriceAgreementSeeder.php:1) — 15 agreements per SEEDS.md, varying `effective_from` to demonstrate the date-resolved pricing.

**Frontend types.** Append to [`spa/src/types/index.ts`](spa/src/types/index.ts:1):

```typescript
export interface Product {
  id: string;
  part_number: string;
  name: string;
  description: string | null;
  unit_of_measure: string;
  standard_cost: string;
  is_active: boolean;
  has_bom: boolean;
  has_inspection_spec: boolean;
  created_at: string;
  updated_at: string;
}

export interface PriceAgreement {
  id: string;
  product: Product;
  customer: Customer;
  price: string;
  effective_from: string;
  effective_to: string;
  is_currently_active: boolean;
}
```

**Frontend API.** [`spa/src/api/products.ts`](spa/src/api/products.ts:1) and [`spa/src/api/priceAgreements.ts`](spa/src/api/priceAgreements.ts:1) — follow [`docs/PATTERNS.md`](docs/PATTERNS.md:617) Section 8 verbatim. PriceAgreements API also exposes `forCustomer(customerId: string)`.

**Pages.**
- [`spa/src/pages/crm/products/index.tsx`](spa/src/pages/crm/products/index.tsx:1) — list. Columns: part_number (mono, link), name, unit_of_measure, standard_cost (mono right-aligned, ₱), has_bom (chip success/neutral), has_inspection_spec (chip), is_active. Filters: is_active, has_bom. ALL 5 states.
- [`spa/src/pages/crm/products/create.tsx`](spa/src/pages/crm/products/create.tsx:1) and `edit.tsx` — minimal form (part_number, name, description, UOM dropdown, standard_cost). Zod schema mirrors backend.
- [`spa/src/pages/crm/products/[id]/detail.tsx`](spa/src/pages/crm/products/[id]/detail.tsx:1) — tabs: Overview, BOM (placeholder until Task 49 fills it), Price Agreements (per-customer), Inspection Spec (placeholder until Sprint 7), Active Sales Orders (Task 48 populates).
- Customer detail (existing in Sprint 4) gains a new tab: enrich [`spa/src/pages/accounting/customers/detail.tsx`](spa/src/pages/accounting/customers/detail.tsx:1) by adding a `<TabList>` with the existing tab plus "Price Agreements" calling the new API. Don't duplicate the page; add the tab.

**Routes.** Append to [`spa/src/App.tsx`](spa/src/App.tsx:1) under a new `/crm/*` block guarded by `feature:crm`. All four routes lazy-loaded + AuthGuard + ModuleGuard + PermissionGuard with `crm.products.view`.

**Tests.**
- [`api/tests/Unit/PriceAgreementResolveTest.php`](api/tests/Unit/PriceAgreementResolveTest.php:1) — covers: no agreement → null; one active → returns it; two non-overlapping → correct one for date; overlap (illegal data) → returns most recent `effective_from`; expired → null.
- [`api/tests/Feature/ProductCrudTest.php`](api/tests/Feature/ProductCrudTest.php:1) — full CRUD with permission boundaries.

**Commit:** `feat: task 47 — CRM products and price agreements`

---

### Task 48 — Sales Orders + delivery schedules

**Goal:** Capture customer orders with per-line delivery dates. SO is the entry point of Chain 1.

**Schema.** Two migrations.

`api/database/migrations/0064_create_sales_orders_table.php`
- Per [`docs/SCHEMA.md`](docs/SCHEMA.md:310) + reconciliation (Section 0): `id, so_number (string 20 unique), customer_id (FK customers onDelete restrict), date (date), subtotal (decimal 15,2), vat_amount (decimal 15,2), total_amount (decimal 15,2), status (string 20 default 'draft' — see enum), payment_terms_days (int default 30), delivery_terms (string 50 nullable), notes (text nullable), mrp_plan_id (FK mrp_plans nullable — Task 52 will create the table; use a deferred FK pattern: no FK constraint here, validate at app level), created_by (FK users), timestamps`.
- Indexes: `customer_id, status, date`.

`api/database/migrations/0065_create_sales_order_items_table.php`
- Per [`docs/SCHEMA.md`](docs/SCHEMA.md:313): `id, sales_order_id (FK sales_orders onDelete cascade), product_id (FK products onDelete restrict), quantity (decimal 10,2), unit_price (decimal 15,2), total (decimal 15,2), quantity_delivered (decimal 10,2 default 0), delivery_date (date)`.
- Index: `delivery_date` (used heavily in MRP + Gantt).

**Enum.** [`api/app/Modules/CRM/Enums/SalesOrderStatus.php`](api/app/Modules/CRM/Enums/SalesOrderStatus.php:1) — `Draft, Confirmed, InProduction, PartiallyDelivered, Delivered, Invoiced, Cancelled`.

**Model.**
- [`SalesOrder`](api/app/Modules/CRM/Models/SalesOrder.php:1) — `HasHashId, HasAuditLog`. Casts: subtotal/vat_amount/total_amount decimal:2, status enum, date date. Relations: `customer()`, `creator()`, `items() HasMany`, `mrpPlan() BelongsTo` (Task 52), `workOrders() HasMany through MRP plan`, `deliveries() HasMany` (Sprint 7), `invoice() HasOne` (Sprint 4 invoice gets sales_order_id).
- [`SalesOrderItem`](api/app/Modules/CRM/Models/SalesOrderItem.php:1) — Casts: quantity decimal:2, unit_price decimal:2, total decimal:2, delivery_date date. Relations: `salesOrder()`, `product()`. Computed: `getRemainingQuantityAttribute()`.

**Service.** [`SalesOrderService`](api/app/Modules/CRM/Services/SalesOrderService.php:1):

```
create(array $data): SalesOrder
  - in transaction:
    - generate so_number via DocumentSequenceService('sales_order')
    - resolve unit_price for each line via PriceAgreementService::resolve(customer, product, line.delivery_date)
      → throw if any line has no agreement
    - compute totals (line totals → subtotal → 12% VAT → grand total)
    - create SO + lines
  - returns SO with eager loaded customer, creator, items.product

confirm(SalesOrder $so): SalesOrder
  - guard: status must be 'draft'
  - in transaction:
    - flip status to 'confirmed'
    - dispatch MrpEngineService::runForSalesOrder($so) synchronously
      (job fallback path: if returns Promise/Pending marker → leave SO status as 'confirmed' and let job complete)
    - on success: status stays 'confirmed' (advances to 'in_production' only when first WO starts)
    - on MRP failure: rollback transaction, return original SO with status=draft and an error
  - emits SalesOrderConfirmedEvent (broadcast on production.dashboard channel for live alerts)

cancel(SalesOrder $so, string $reason): SalesOrder
  - guard: status NOT IN (delivered, invoiced)
  - in transaction:
    - flip status to 'cancelled'
    - cancel any 'planned' or 'confirmed' (not yet started) work orders linked via mrp_plan
    - release reservations (delegate to WorkOrderService::cancel)
    - mark mrp_plan as 'cancelled'
  - returns SO

list(array $filters): LengthAwarePaginator   // standard pagination per PATTERNS section 3

show(SalesOrder $so): SalesOrder
  - eager loads: customer, creator, items.product, mrpPlan, workOrders.machine, workOrders.mold, deliveries (if any)
```

**Form Requests.**
- `StoreSalesOrderRequest` — `customer_id exists`, `date required date`, `items array min:1`, per-item `product_id exists`, `quantity decimal:0,2 min:0.01`, `delivery_date required date after_or_equal:today`.
- `UpdateSalesOrderRequest` — same; only allowed when status=draft (also enforced in controller).
- `ListSalesOrdersRequest` — `search, customer_id, status, date_from, date_to, sort, direction, page, per_page`.

**API Resources.**
- `SalesOrderResource` — hash_id, so_number, customer (resource), date, subtotal, vat_amount, total_amount, status, payment_terms_days, delivery_terms, notes, item_count (computed), items (whenLoaded), mrp_plan (whenLoaded), work_orders (whenLoaded), creator (resource), timestamps. **Always returns hash_id, never raw id.**
- `SalesOrderItemResource` — hash_id, product (resource), quantity, unit_price, total, quantity_delivered, remaining_quantity (computed), delivery_date.

**Controller.** `SalesOrderController` — standard CRUD + extra endpoints:

```
GET    /sales-orders                       index   permission:crm.sales_orders.view
POST   /sales-orders                       store   permission:crm.sales_orders.create
GET    /sales-orders/{so}                  show    permission:crm.sales_orders.view
PUT    /sales-orders/{so}                  update  permission:crm.sales_orders.update     (only if draft)
DELETE /sales-orders/{so}                  destroy permission:crm.sales_orders.delete     (only if draft)
POST   /sales-orders/{so}/confirm          confirm permission:crm.sales_orders.confirm
POST   /sales-orders/{so}/cancel           cancel  permission:crm.sales_orders.cancel
GET    /sales-orders/{so}/chain            chain   permission:crm.sales_orders.view       (returns chain visualization payload)
```

The `chain` endpoint returns the data shape for [`<ChainHeader>`](spa/src/components/chain/ChainHeader.tsx:1) — an array of `{key, label, date, state}` reflecting where the SO sits in Chain 1. Computed dynamically:

```
order_entered    → from so.created_at                  state: 'done' if status >= confirmed else 'active'
mrp_planned      → from mrp_plan.created_at             state derives from plan.status
in_production    → from earliest wo.actual_start        state: 'active' if any WO in_progress
qc_in_process    → from any inspection (Sprint 7)       state: 'pending' until Sprint 7 wires it
qc_outgoing      → from outgoing inspection             state: 'pending'
delivered        → from delivery.actual_delivery_date   state: 'pending'
invoiced         → from invoice.date                    state: 'pending'
```

For now (Sprint 6), QC and delivery steps render as `pending` placeholders. Sprint 7 fills them.

**Routes.** Add to [`api/app/Modules/CRM/routes.php`](api/app/Modules/CRM/routes.php:1) under the same `feature:crm` group.

**Frontend types.** Append `SalesOrder`, `SalesOrderItem`, `CreateSalesOrderData` (with `items: { product_id, quantity, delivery_date }[]`), chain step types.

**Frontend API.** [`spa/src/api/salesOrders.ts`](spa/src/api/salesOrders.ts:1) — list, show, create, update, delete, confirm, cancel, chain.

**Pages.**
- [`spa/src/pages/crm/sales-orders/index.tsx`](spa/src/pages/crm/sales-orders/index.tsx:1) — DataTable. Columns: so_number (mono link), customer.name, date (mono), item_count (mono), total_amount (mono ₱ right-aligned), status (chip — variant map: draft→neutral, confirmed→info, in_production→info, partially_delivered→warning, delivered→success, invoiced→success, cancelled→danger). Filters: customer, status, date range. ALL 5 states. Action button "Add Sales Order" → `/crm/sales-orders/create`.
- [`spa/src/pages/crm/sales-orders/create.tsx`](spa/src/pages/crm/sales-orders/create.tsx:1) — multi-section form per [`docs/PATTERNS.md`](docs/PATTERNS.md:1085) Section 12:
  1. Customer picker (searchable Select pulling from customers API). On select: prefetch active price agreements.
  2. Date + payment terms.
  3. Line items table editor (add row, pick product → auto-fill unit_price from agreement OR show inline error "no agreement" with link to create agreement → set quantity → set delivery_date). Running subtotal/VAT/total in mono numbers at bottom.
  4. Delivery terms + notes.
  5. Action bar: Cancel | Save as Draft | Save & Confirm (calls create then confirm).
- [`spa/src/pages/crm/sales-orders/detail.tsx`](spa/src/pages/crm/sales-orders/detail.tsx:1) — header with SO number + status chip + action buttons (Confirm if draft / Cancel if not delivered / Print if confirmed+). [`<ChainHeader>`](spa/src/components/chain/ChainHeader.tsx:1) below the title fed by `chain` endpoint. Body: 2/3 main + 1/3 right panel.
  - Main: tabs — Overview (totals, dates, terms), Line Items (per-line table with delivery_date and computed quantity_delivered progress bar), Production (linked work orders from MRP plan), Activity (HasAuditLog log).
  - Right panel: [`<LinkedRecords>`](spa/src/components/chain/LinkedRecords.tsx:1) groups — MRP Plan, Work Orders, QC Inspections (placeholder), Deliveries (placeholder), Invoice (placeholder).
- [`spa/src/pages/crm/sales-orders/edit.tsx`](spa/src/pages/crm/sales-orders/edit.tsx:1) — only enabled if status=draft (server enforces; client also gates).

**Tests.**
- [`api/tests/Feature/SalesOrderCreationTest.php`](api/tests/Feature/SalesOrderCreationTest.php:1) — happy path; no-agreement-error path; permission boundaries; concurrent so_number generation (10 parallel POSTs return distinct numbers).
- [`api/tests/Feature/SalesOrderConfirmTest.php`](api/tests/Feature/SalesOrderConfirmTest.php:1) — draft→confirmed flips status, creates MRP plan, creates draft WOs (post-Task 52, this becomes a real assertion; in Task 48's commit, the MRP-related assertion is `markTestSkipped('Task 52 implements MRP')`).

**Commit:** `feat: task 48 — sales orders with line-level delivery schedules`

---

### Task 49 — Bill of Materials

**Goal:** A versioned BOM per product specifying raw material requirements per finished unit.

**Schema.** Two migrations.

`api/database/migrations/0066_create_bill_of_materials_table.php`
- Per [`docs/SCHEMA.md`](docs/SCHEMA.md:285): `id, product_id (FK products UNIQUE), version (int default 1), is_active (bool default true), timestamps`.
- The UNIQUE constraint on `product_id` with versioning is reconciled: only ONE active BOM per product at a time. Old versions stay in the table with `is_active=false`. Add `is_active` index.

`api/database/migrations/0067_create_bom_items_table.php`
- Per [`docs/SCHEMA.md`](docs/SCHEMA.md:288): `id, bom_id (FK bill_of_materials onDelete cascade), item_id (FK items onDelete restrict), quantity_per_unit (decimal 10,4), unit (string 20), waste_factor (decimal 5,2 default 0), sort_order (int default 0)`.
- Note: SCHEMA does not list `sort_order`; add it for stable display ordering. Document in migration.

**Enums.** None.

**Models.**
- [`Bom`](api/app/Modules/MRP/Models/Bom.php:1) (table name `bill_of_materials`) — `HasHashId, HasAuditLog`. Relations: `product()`, `items() HasMany BomItem ordered by sort_order`. Scope: `scopeActive`.
- [`BomItem`](api/app/Modules/MRP/Models/BomItem.php:1) — Casts: quantity_per_unit decimal:4, waste_factor decimal:2. Relations: `bom()`, `item()`. Computed: `getEffectiveQuantityAttribute(): float` returns `quantity_per_unit * (1 + waste_factor / 100)`.

**Service.** [`BomService`](api/app/Modules/MRP/Services/BomService.php:1):

```
create(int $productId, array $itemRows): Bom
  - in transaction with lockForUpdate on existing active BOM (if any):
    - if active BOM exists, mark it inactive (don't delete — preserve history)
    - new BOM with version = (prev?->version ?? 0) + 1
    - insert items
  - returns BOM eager-loaded with items.item

update(Bom $bom, array $itemRows): Bom
  - allowed only when no active WOs reference this BOM's product
  - replaces items in a transaction (delete + insert) — preserves bom_id, increments version, marks old version inactive
  - reuses create() logic by archiving + creating new

show(Bom $bom): Bom

listForProduct(int $productId): Collection  // active first, then versions desc

explode(int $productId, float $finishedQuantity): array
  // returns [{ item_id, item_code, item_name, gross_quantity }]
  // gross = qty_per_unit * (1 + waste) * finishedQuantity
  // uses ACTIVE BOM only; throws if none active
  // this is the public method MRP engine (Task 52) uses
```

**Form Requests.** `StoreBomRequest`, `UpdateBomRequest` — items array min:1, each `item_id exists:items`, `quantity_per_unit decimal:0,4 min:0.0001`, `waste_factor decimal:0,2 min:0 max:50`.

**API Resources.**
- `BomResource` — hash_id, product (resource), version, is_active, items (whenLoaded), timestamps.
- `BomItemResource` — hash_id, item (lite resource: hash_id + code + name + UOM), quantity_per_unit, unit, waste_factor, effective_quantity, sort_order.

**Controller.** `BomController` — standard. Extra endpoint `GET /products/{product}/bom` to fetch the active BOM by product hash_id.

**Routes.** Add to [`api/app/Modules/MRP/routes.php`](api/app/Modules/MRP/routes.php:1) (new file). Prefix `boms`. Permissions `mrp.boms.{view,manage}`.

**Seeds.** [`BomSeeder`](api/database/seeders/BomSeeder.php:1) — 8 BOMs per [`docs/SEEDS.md`](docs/SEEDS.md:1), one per demo product, each with 3–5 raw material lines from the `items` table seeded in Sprint 5.

**Frontend types.** `Bom`, `BomItem`, `CreateBomData`.

**Frontend API.** [`spa/src/api/boms.ts`](spa/src/api/boms.ts:1) — list, show, create, update, delete, forProduct.

**Pages.**
- [`spa/src/pages/mrp/boms/index.tsx`](spa/src/pages/mrp/boms/index.tsx:1) — list. Columns: product.part_number (mono), product.name, version (mono), item_count (mono), is_active (chip), updated_at (mono date). Filter: is_active, product. ALL 5 states.
- [`spa/src/pages/mrp/boms/create.tsx`](spa/src/pages/mrp/boms/create.tsx:1) — pick product → row editor for items (add row, item picker, quantity_per_unit, unit auto-pulled from item, waste_factor). Live preview: "1 unit of {product} requires {expanded list}". Confirms overwrite of existing active BOM with a Modal.
- [`spa/src/pages/mrp/boms/detail.tsx`](spa/src/pages/mrp/boms/detail.tsx:1) — read-only view of all versions for a product. Active version expanded; older versions collapsed. Edit button → /create flow with pre-fill.
- The CRM product detail page (Task 47) "BOM" tab now renders the active BOM via `boms.forProduct(product.id)`.

**Tests.**
- [`api/tests/Unit/BomExplodeTest.php`](api/tests/Unit/BomExplodeTest.php:1) — covers waste factor math, no-active-BOM error, version preservation.
- [`api/tests/Feature/BomVersioningTest.php`](api/tests/Feature/BomVersioningTest.php:1) — creating a new BOM deactivates the previous; listForProduct returns active first.

**Commit:** `feat: task 49 — bill of materials with versioning`

---

### Task 50 — Machines + Molds

**Goal:** The physical resources the production scheduler will allocate.

**Schema.** Four migrations.

`api/database/migrations/0068_create_machines_table.php`
- Per [`docs/SCHEMA.md`](docs/SCHEMA.md:291) + reconciliation: `id, machine_code (string 20 unique), name (string 100), tonnage (int nullable), machine_type (string 50 default 'injection_molder'), operators_required (decimal 3,1 default 1.0), available_hours_per_day (decimal 4,1 default 16.0), status (string 20 default 'idle'), current_work_order_id (FK work_orders nullable — deferred FK; Task 51 creates the table, add FK in Task 51's migration), timestamps, soft delete`.
- Index: `status`.

`api/database/migrations/0069_create_molds_table.php`
- Per [`docs/SCHEMA.md`](docs/SCHEMA.md:294): `id, mold_code (string 20 unique), name (string 100), product_id (FK products onDelete restrict), cavity_count (int), cycle_time_seconds (int), output_rate_per_hour (int), setup_time_minutes (int default 90), current_shot_count (int default 0), max_shots_before_maintenance (int), lifetime_total_shots (int default 0), lifetime_max_shots (int), status (string 20 default 'available'), location (string 50 nullable), asset_id (FK assets nullable — deferred; Sprint 8 Task 70 creates assets, add FK then), timestamps, soft delete`.
- Indexes: `status, product_id`.
- Constraint: `output_rate_per_hour = floor(3600 / cycle_time_seconds * cavity_count)` validated at app level (`MoldService::create/update` recomputes if not supplied).

`api/database/migrations/0070_create_mold_machine_compatibility_table.php`
- Per [`docs/SCHEMA.md`](docs/SCHEMA.md:297): `mold_id (FK molds onDelete cascade), machine_id (FK machines onDelete cascade), PRIMARY KEY (mold_id, machine_id)`.
- This is the many-to-many pivot.

`api/database/migrations/0071_create_mold_history_table.php`
- Per [`docs/SCHEMA.md`](docs/SCHEMA.md:300): `id, mold_id (FK molds onDelete cascade), event_type (string 30), description (text nullable), cost (decimal 15,2 nullable), performed_by (string 100 nullable), event_date (date), shot_count_at_event (int), created_at`.
- Event types (PHP enum): `created, maintenance_scheduled, maintenance_started, maintenance_completed, shot_limit_reached, retired, repaired`.

**Enums.**
- [`MachineStatus`](api/app/Modules/MRP/Enums/MachineStatus.php:1): `Running, Idle, Maintenance, Breakdown, Offline`.
- [`MoldStatus`](api/app/Modules/MRP/Enums/MoldStatus.php:1): `Available, InUse, Maintenance, Retired`.
- [`MoldEventType`](api/app/Modules/MRP/Enums/MoldEventType.php:1): per above.

**Models.**
- [`Machine`](api/app/Modules/MRP/Models/Machine.php:1) — `HasHashId, HasAuditLog, SoftDeletes`. Casts: status enum, operators_required decimal:1, available_hours_per_day decimal:1. Relations: `currentWorkOrder() BelongsTo`, `compatibleMolds() BelongsToMany`, `workOrders() HasMany`, `downtimes() HasMany MachineDowntime` (Task 51), `productionSchedules() HasMany` (Task 51).
- [`Mold`](api/app/Modules/MRP/Models/Mold.php:1) — `HasHashId, HasAuditLog, SoftDeletes`. Casts: status enum. Relations: `product()`, `compatibleMachines() BelongsToMany`, `history() HasMany`, `asset() BelongsTo` (Sprint 8). Computed: `getShotPercentageAttribute()` returns `current_shot_count / max_shots_before_maintenance * 100`. Method: `incrementShots(int $count): void` — atomic update with lock (used by output recording).
- [`MoldHistory`](api/app/Modules/MRP/Models/MoldHistory.php:1) — Casts: event_type enum, event_date date, cost decimal:2.

**Services.**
- [`MachineService`](api/app/Modules/MRP/Services/MachineService.php:1) — CRUD plus `transitionStatus(Machine $m, MachineStatus $to, ?string $reason): Machine`. Validates legal transitions:
  ```
  idle      → running, maintenance, breakdown, offline
  running   → idle, breakdown, maintenance
  breakdown → maintenance, idle (after repair)
  maintenance → idle
  offline   → idle
  ```
  Illegal transitions throw `IllegalStatusTransitionException` (renders 409).
  Side effects of `running → breakdown` are deferred to Task 56's listener; this service just persists state and dispatches `MachineStatusChangedEvent`.
- [`MoldService`](api/app/Modules/MRP/Services/MoldService.php:1) — CRUD plus:
  - `incrementShots(Mold $m, int $count, int $workOrderId): void` — atomic with `lockForUpdate`. If new shot count crosses 80% threshold, fire `MoldShotLimitNearingEvent`. If reaches 100%, also flip status to `maintenance` and fire `MoldShotLimitReachedEvent`.
  - `resetShotCount(Mold $m): void` — used by maintenance (Sprint 8) but also documented as: archives `current_shot_count` to history with `event_type=maintenance_completed`, sets `current_shot_count=0`, increments `lifetime_total_shots` not (lifetime is cumulative; current is per-cycle).
- [`MoldHistoryService`](api/app/Modules/MRP/Services/MoldHistoryService.php:1) — append-only writer.

**Form Requests.** `StoreMachineRequest`, `UpdateMachineRequest`, `TransitionMachineStatusRequest`, `StoreMoldRequest`, `UpdateMoldRequest`, plus `AssignMoldCompatibilityRequest` (mold_id + machine_ids array).

**API Resources.**
- `MachineResource` — hash_id, machine_code, name, tonnage, machine_type, operators_required, available_hours_per_day, status, current_work_order (lite resource whenLoaded), compatible_molds (lite collection whenLoaded), is_available_now (computed: status=idle), timestamps.
- `MoldResource` — hash_id, mold_code, name, product (lite resource), cavity_count, cycle_time_seconds, output_rate_per_hour, setup_time_minutes, current_shot_count, max_shots_before_maintenance, shot_percentage (computed), lifetime_total_shots, lifetime_max_shots, status, location, compatible_machines (lite collection whenLoaded), nearing_limit (computed: shot_percentage >= 80), timestamps.
- `MoldHistoryResource`.

**Controller.** `MachineController`, `MoldController`. Extra endpoints:
- `PATCH /machines/{machine}/transition-status` — body `{ to, reason? }`.
- `POST /molds/{mold}/compatibility` — body `{ machine_ids: [...] }`. Replaces compatibility set.
- `GET /molds/{mold}/history`.
- `GET /molds/by-product/{product}` — used by WO creation form.

**Routes.** Add to [`api/app/Modules/MRP/routes.php`](api/app/Modules/MRP/routes.php:1).

**Seeds.** [`MachineSeeder`](api/database/seeders/MachineSeeder.php:1) — 12 machines from [`docs/SEEDS.md`](docs/SEEDS.md:1) (varying tonnage, all `idle` initially). [`MoldSeeder`](api/database/seeders/MoldSeeder.php:1) — 15 molds, each linked to one of the 8 products (some products have 2 molds for redundancy). [`MoldCompatibilitySeeder`](api/database/seeders/MoldCompatibilitySeeder.php:1) — pivot rows so every mold has 1–3 compatible machines per SEEDS.md.

**Frontend types.** `Machine`, `Mold`, `MoldHistoryEntry`.

**Frontend API.** [`spa/src/api/machines.ts`](spa/src/api/machines.ts:1), [`spa/src/api/molds.ts`](spa/src/api/molds.ts:1).

**Pages.**
- [`spa/src/pages/mrp/machines/index.tsx`](spa/src/pages/mrp/machines/index.tsx:1) — DataTable. Columns: machine_code (mono link), name, tonnage (mono right-aligned, suffix " T"), machine_type, operators_required (mono), available_hours_per_day (mono), status (chip — running→success, idle→neutral, maintenance→info, breakdown→danger, offline→neutral). Action button "Add Machine". Per-row action menu: "Change Status" → opens dialog with allowed transitions only.
- [`spa/src/pages/mrp/machines/detail.tsx`](spa/src/pages/mrp/machines/detail.tsx:1) — current status + compatible molds list + downtime history (Task 51 wires the table) + recent work orders.
- [`spa/src/pages/mrp/molds/index.tsx`](spa/src/pages/mrp/molds/index.tsx:1) — DataTable. Columns: mold_code (mono link), name, product (link), cavity_count (mono), output_rate_per_hour (mono), shot_count_progress (a custom cell — 4px progress bar with `current_shot_count / max_shots_before_maintenance` filled with color: emerald < 60%, amber 60–80%, red > 80%; mono "{current} / {max}" below). Filter: product, status, nearing_limit (≥80%). ALL 5 states.
- [`spa/src/pages/mrp/molds/detail.tsx`](spa/src/pages/mrp/molds/detail.tsx:1) — overview + compatibility manager (multi-select machines; persists via API) + history table.

**Tests.**
- [`api/tests/Unit/MachineStatusTransitionTest.php`](api/tests/Unit/MachineStatusTransitionTest.php:1) — every legal/illegal pair.
- [`api/tests/Unit/MoldShotIncrementTest.php`](api/tests/Unit/MoldShotIncrementTest.php:1) — under/at/over threshold; concurrent increments via `Concurrency::run`.

**Commit:** `feat: task 50 — machines, molds, and mold-machine compatibility`

---

### Task 51 — Work Orders

**Goal:** The atomic unit of production work. Lifecycle: planned → confirmed → in_progress → (paused?) → completed → closed.

**Schema.** Six migrations.

`api/database/migrations/0072_create_work_orders_table.php`
- Per [`docs/SCHEMA.md`](docs/SCHEMA.md:257) + reconciliation:
  ```
  id, wo_number (string 20 unique),
  product_id (FK products restrict),
  sales_order_id (FK sales_orders nullable),
  sales_order_item_id (FK sales_order_items nullable — added so we know which line),
  mrp_plan_id (FK — added; FK constraint added in Task 52 migration after table exists),
  parent_wo_id (FK self nullable),
  parent_ncr_id (bigint nullable — FK added in Sprint 7),
  machine_id (FK machines nullable),
  mold_id (FK molds nullable),
  quantity_target (decimal 10,0),
  quantity_produced (decimal 10,0 default 0),
  quantity_good (decimal 10,0 default 0),
  quantity_rejected (decimal 10,0 default 0),
  scrap_rate (decimal 5,2 default 0),     // computed on close: rejected / produced * 100
  planned_start (datetime),
  planned_end (datetime),
  actual_start (datetime nullable),
  actual_end (datetime nullable),
  status (string 20 default 'planned'),
  pause_reason (string 200 nullable),
  priority (int default 0),
  created_by (FK users),
  timestamps
  ```
  Indexes: `status, sales_order_id, machine_id, mrp_plan_id, planned_start`.
- Add the deferred FK on `machines.current_work_order_id → work_orders.id` here (the migration is split: Task 50 created machines without FK; Task 51 adds it via `Schema::table('machines', ...)`).

`api/database/migrations/0073_create_work_order_materials_table.php`
- Per [`docs/SCHEMA.md`](docs/SCHEMA.md:260): `id, work_order_id (FK cascade), item_id (FK items restrict), bom_quantity (decimal 15,3), actual_quantity_issued (decimal 15,3 default 0), variance (decimal 15,3 default 0)`.

`api/database/migrations/0074_create_work_order_outputs_table.php`
- Per [`docs/SCHEMA.md`](docs/SCHEMA.md:263) + reconciliation: `id, work_order_id (FK cascade), recorded_by (FK users), recorded_at (timestamp), good_count (int), reject_count (int), shift (string 20 nullable), batch_code (string 30 nullable), remarks (text nullable)`.
- Index: `work_order_id, recorded_at`.

`api/database/migrations/0075_create_work_order_defects_table.php`
- Per [`docs/SCHEMA.md`](docs/SCHEMA.md:266): `id, output_id (FK work_order_outputs cascade), defect_type_id (FK defect_types restrict), count (int)`.

`api/database/migrations/0076_create_defect_types_table.php`
- Per [`docs/SCHEMA.md`](docs/SCHEMA.md:269): `id, code (string 10 unique), name (string 100), description (text nullable), is_active (bool default true)`.
- Migration must run BEFORE 0075.

`api/database/migrations/0077_create_machine_downtimes_table.php`
- Per [`docs/SCHEMA.md`](docs/SCHEMA.md:272): `id, machine_id (FK), work_order_id (FK nullable), start_time (timestamp), end_time (timestamp nullable), duration_minutes (int nullable), category (string 30), description (text nullable), maintenance_order_id (FK nullable — Sprint 8 adds), timestamps`.
- Index: `machine_id, start_time`.

`api/database/migrations/0078_create_production_schedules_table.php` *(numbering: yes 0078, even though Sprint 7 begins with 0078 in [`docs/TASKS.md`](docs/TASKS.md:218); we shift Sprint 7 numbering by 1 — document in PR description and update [`docs/TASKS.md`](docs/TASKS.md:1) inline accordingly)*
- Per [`docs/SCHEMA.md`](docs/SCHEMA.md:275) + reconciliation: `id, work_order_id (FK), machine_id (FK), mold_id (FK), scheduled_start (datetime), scheduled_end (datetime), priority_order (int), status (string 20 default 'pending'), is_confirmed (bool default false), confirmed_by (FK users nullable), confirmed_at (timestamp nullable), timestamps`.
- Index: `machine_id, scheduled_start`.

> **Migration numbering note.** Sprint 5 left off at 0061. Sprint 6 Task 47 starts 0062. Sprint 6 ends at 0078 (production_schedules). [`docs/TASKS.md`](docs/TASKS.md:218) currently says Sprint 7 starts at 0078 (`inspection_specs`). Renumber Sprint 7 migrations to start at 0079 in this sprint's PR (search-and-replace in TASKS.md). Document the renumbering in the PR body.

**Enums.**
- [`WorkOrderStatus`](api/app/Modules/Production/Enums/WorkOrderStatus.php:1): `Planned, Confirmed, InProgress, Paused, Completed, Closed, Cancelled`.
- [`MachineDowntimeCategory`](api/app/Modules/Production/Enums/MachineDowntimeCategory.php:1): `Breakdown, Changeover, MaterialShortage, NoOrder, PlannedMaintenance`.
- [`ProductionScheduleStatus`](api/app/Modules/Production/Enums/ProductionScheduleStatus.php:1): `Pending, Confirmed, Superseded, Executed`.

**Models.**
- [`WorkOrder`](api/app/Modules/Production/Models/WorkOrder.php:1) — `HasHashId, HasAuditLog`. Casts as you'd expect. Relations: product, salesOrder, salesOrderItem, mrpPlan (Task 52), parentWo, machine, mold, materials, outputs, defects (hasManyThrough), schedules. Computed: `getProgressPercentageAttribute(): float` returns `min(100, quantity_produced / quantity_target * 100)`. Computed: `getEstimatedDurationHoursAttribute()` returns `(quantity_target / mold.output_rate_per_hour) + (mold.setup_time_minutes / 60)`.
- [`WorkOrderMaterial`](api/app/Modules/Production/Models/WorkOrderMaterial.php:1) — Casts: bom_quantity decimal:3, actual_quantity_issued decimal:3, variance decimal:3.
- [`WorkOrderOutput`](api/app/Modules/Production/Models/WorkOrderOutput.php:1) — Casts: recorded_at datetime. Computed: `getTotalCountAttribute()` = good + reject. Relations: workOrder, recorder (user), defects.
- [`WorkOrderDefect`](api/app/Modules/Production/Models/WorkOrderDefect.php:1).
- [`DefectType`](api/app/Modules/Production/Models/DefectType.php:1) — `HasHashId`.
- [`MachineDowntime`](api/app/Modules/Production/Models/MachineDowntime.php:1) — Casts: start_time datetime, end_time datetime, category enum. Computed: `getDurationMinutesAttribute()` = end - start if end set; else live computed.
- [`ProductionSchedule`](api/app/Modules/Production/Models/ProductionSchedule.php:1) — Casts: scheduled_start datetime, scheduled_end datetime, status enum, is_confirmed boolean.

**Services.** [`WorkOrderService`](api/app/Modules/Production/Services/WorkOrderService.php:1) is the core. Detailed methods:

```
createDraft(array $data): WorkOrder
  // called by MRP engine (Task 52) AND manually by PPC
  // in transaction:
  //   - generate wo_number via DocumentSequenceService('work_order')
  //   - resolve BOM via BomService::explode → create work_order_materials rows
  //   - status = 'planned', no machine/mold yet (scheduler picks)
  //   - link sales_order_item_id if from MRP
  // returns WO eager loaded

confirm(WorkOrder $wo): WorkOrder
  // guards: status=planned; machine_id and mold_id assigned (else throw)
  // in transaction:
  //   - reserve materials via StockMovementService::reserve(item, qty, wo) for each work_order_material
  //   - status → 'confirmed'
  //   - schedule slot moves to 'confirmed' status
  //   - update mold.status if not already 'available'? No — mold remains available until 'start'
  // returns WO

start(WorkOrder $wo): WorkOrder
  // guards: status=confirmed; machine.status=idle; mold.status=available
  // in transaction with lock on machine + mold rows:
  //   - actual_start = now
  //   - status → 'in_progress'
  //   - machine.status → 'running', current_work_order_id = wo.id
  //   - mold.status → 'in_use'
  //   - issue materials: for each work_order_material call StockMovementService::issueFromReservation(...)
  //     this creates a material_issue_slip (Sprint 5) and updates actual_quantity_issued
  // emits WorkOrderStartedEvent (broadcasts on production.dashboard)

pause(WorkOrder $wo, string $reason, MachineDowntimeCategory $category): WorkOrder
  // guards: status=in_progress
  // in transaction:
  //   - status → 'paused', pause_reason = reason
  //   - machine.status → 'idle' (releases the slot), current_work_order_id = null
  //   - create machine_downtimes row (open: end_time null)
  //   - reservations remain
  // emits WorkOrderPausedEvent

resume(WorkOrder $wo): WorkOrder
  // guards: status=paused; machine still available
  // close the open machine_downtimes row (end_time = now, duration_minutes computed)
  // status → 'in_progress', machine.status → 'running'

complete(WorkOrder $wo): WorkOrder
  // guards: status=in_progress; quantity_produced >= quantity_target * 0.95 (configurable threshold; under that requires explicit short-completion remark)
  // in transaction:
  //   - status → 'completed', actual_end = now
  //   - machine.status → 'idle', current_work_order_id = null
  //   - mold.status → 'available' (unless shot limit reached, then 'maintenance')
  //   - compute scrap_rate = quantity_rejected / quantity_produced * 100
  //   - any unused reserved material is released back to stock_levels.reserved_quantity

close(WorkOrder $wo): WorkOrder
  // guards: status=completed
  // status → 'closed' (immutable). Used by Sprint 8 reporting as the trigger to finalize.

cancel(WorkOrder $wo, string $reason): WorkOrder
  // guards: status NOT IN (in_progress, completed, closed)
  // releases reservations (StockMovementService::releaseReservation)
  // closes any pending production_schedules row → status='superseded'
  // status → 'cancelled'

list(array $filters): paginator   // standard
show(WorkOrder $wo): WorkOrder    // eager loads everything for detail page
```

[`WorkOrderOutputService`](api/app/Modules/Production/Services/WorkOrderOutputService.php:1) — implemented in Task 55 (only stub here returning `markAsImplementedInTask55()`).

**Form Requests.** `StoreWorkOrderRequest`, `UpdateWorkOrderRequest`, `ConfirmWorkOrderRequest`, `StartWorkOrderRequest`, `PauseWorkOrderRequest` (reason + category), `CompleteWorkOrderRequest`, `CancelWorkOrderRequest`, `ListWorkOrdersRequest`.

**API Resources.**
- `WorkOrderResource` — hash_id, wo_number, product (lite), sales_order (lite, hash_id only), machine (lite), mold (lite), quantity_target, quantity_produced, quantity_good, quantity_rejected, progress_percentage, scrap_rate, planned_start/end, actual_start/end, status, pause_reason, priority, materials (whenLoaded), recent_outputs (whenLoaded; last 10), creator, timestamps. Detail variant adds chain visualization steps.
- `WorkOrderMaterialResource`, `WorkOrderOutputResource`, `WorkOrderDefectResource`, `MachineDowntimeResource`, `ProductionScheduleResource`, `DefectTypeResource`.

**Controller.** `WorkOrderController` — full CRUD + lifecycle endpoints:
```
GET    /work-orders                        index
POST   /work-orders                        store
GET    /work-orders/{wo}                   show
PUT    /work-orders/{wo}                   update         (only when planned)
DELETE /work-orders/{wo}                   destroy        (only when planned)
POST   /work-orders/{wo}/confirm           confirm
POST   /work-orders/{wo}/start             start
POST   /work-orders/{wo}/pause             pause
POST   /work-orders/{wo}/resume            resume
POST   /work-orders/{wo}/complete          complete
POST   /work-orders/{wo}/close             close
POST   /work-orders/{wo}/cancel            cancel
GET    /work-orders/{wo}/chain             chain          (visualization payload)
```

`DefectTypeController` for the small CRUD on defect_types (admin-only — `production.defect_types.manage` permission, scoped to System Admin). Routes `defect-types`.

**Routes.** New file [`api/app/Modules/Production/routes.php`](api/app/Modules/Production/routes.php:1). Auto-loaded via `ModuleServiceProvider` from Sprint 1.

**Seeds.** [`DefectTypeSeeder`](api/database/seeders/DefectTypeSeeder.php:1) — 11 defect types from [`docs/SEEDS.md`](docs/SEEDS.md:1) (`SHRT, FLSH, BURN, DIM, COLOR, CRACK, WARP, BUBBLE, INC, MISMATCH, OTHER`).

**Frontend types.** `WorkOrder`, `WorkOrderMaterial`, `WorkOrderOutput`, `WorkOrderDefect`, `DefectType`, `MachineDowntime`, `ProductionSchedule`. Plus action payload types for each lifecycle endpoint.

**Frontend API.** [`spa/src/api/workOrders.ts`](spa/src/api/workOrders.ts:1) — list, show, create, update, delete, confirm, start, pause, resume, complete, close, cancel, chain. [`spa/src/api/defectTypes.ts`](spa/src/api/defectTypes.ts:1).

**Pages.**
- [`spa/src/pages/production/work-orders/index.tsx`](spa/src/pages/production/work-orders/index.tsx:1) — DataTable. Columns: wo_number (mono link), product.name, sales_order.so_number (mono link if exists), machine.machine_code (mono), mold.mold_code (mono), quantity_target (mono), progress_percentage (4px bar + mono percentage), planned_start (mono date), status (chip — planned→neutral, confirmed→info, in_progress→info, paused→warning, completed→success, closed→success, cancelled→danger). Filters: status, machine, sales_order, date range. Kanban toggle (5 columns: Planned / Confirmed / In Progress / Paused / Completed). ALL 5 states.
- [`spa/src/pages/production/work-orders/create.tsx`](spa/src/pages/production/work-orders/create.tsx:1) — manual WO creation (rare; most WOs created by MRP). Pick product → BOM expansion preview → pick quantity → optional machine/mold pre-assignment.
- [`spa/src/pages/production/work-orders/detail.tsx`](spa/src/pages/production/work-orders/detail.tsx:1) — header with WO number + status chip + lifecycle action buttons (rendered conditionally on status). [`<ChainHeader>`](spa/src/components/chain/ChainHeader.tsx:1) below. Tabs:
  - Overview: product, machine/mold, dates, progress. Live progress bar updates via WebSocket subscription (Task 55).
  - Materials: BOM list with bom_quantity / actual_quantity_issued / variance.
  - Outputs: chronological list of `work_order_outputs` with good/reject/defects breakdown. (Filled in Task 55.)
  - Downtimes: machine downtime entries while this WO was running.
  - Activity: HasAuditLog feed.
  - Right panel: LinkedRecords (Sales Order, MRP Plan, Inspections, Maintenance Orders).
- [`spa/src/pages/production/work-orders/edit.tsx`](spa/src/pages/production/work-orders/edit.tsx:1) — only when status=planned.

**Tests.**
- [`api/tests/Unit/WorkOrderLifecycleTest.php`](api/tests/Unit/WorkOrderLifecycleTest.php:1) — every legal/illegal transition.
- [`api/tests/Feature/WorkOrderConfirmReservesMaterialsTest.php`](api/tests/Feature/WorkOrderConfirmReservesMaterialsTest.php:1) — confirm increments `stock_levels.reserved_quantity`.
- [`api/tests/Feature/WorkOrderStartIssuesMaterialsTest.php`](api/tests/Feature/WorkOrderStartIssuesMaterialsTest.php:1) — start creates material issue slip.

**Commit:** `feat: task 51 — work orders with full lifecycle, materials, outputs, downtimes, schedules`

---

### Task 52 — MRP engine

**Goal:** On SO confirmation, compute net material requirements, draft purchase requests for shortages, and draft work orders for SO lines.

**Schema.** One new table.

`api/database/migrations/0079_create_mrp_plans_table.php` *(after the renumbering note above)*

```
id,
mrp_plan_no (string 20 unique),                    // MRP-YYYYMM-NNNN
sales_order_id (FK sales_orders),
version (int default 1),
status (string 20 default 'active'),               // active / superseded / cancelled
generated_by (FK users),
total_lines (int),
shortages_found (int),
auto_pr_count (int),
draft_wo_count (int),
diagnostics (json nullable),                        // detailed math
generated_at (timestamp),
created_at, updated_at
```
Index: `sales_order_id, status`.

Then add the deferred FK `sales_orders.mrp_plan_id → mrp_plans.id` and `work_orders.mrp_plan_id → mrp_plans.id` via `Schema::table` in this migration.

**Enum.** [`MrpPlanStatus`](api/app/Modules/MRP/Enums/MrpPlanStatus.php:1): `Active, Superseded, Cancelled`.

**Models.**
- [`MrpPlan`](api/app/Modules/MRP/Models/MrpPlan.php:1) — `HasHashId, HasAuditLog`. Casts: status enum, diagnostics array. Relations: salesOrder, generator (user), workOrders (HasMany work_orders.mrp_plan_id), purchaseRequests (HasMany purchase_requests.mrp_plan_id — see below).

**Schema reconciliation for PR linkage.** [`docs/SCHEMA.md`](docs/SCHEMA.md:218) `purchase_requests` has no `mrp_plan_id`. We added a Sprint 5 schema reconciliation; here we add a similarly small follow-up: in `0079_create_mrp_plans_table.php` ALSO `Schema::table('purchase_requests', fn($t) => $t->foreignId('mrp_plan_id')->nullable()->constrained('mrp_plans'))`. Document.

**Service.** [`MrpEngineService`](api/app/Modules/MRP/Services/MrpEngineService.php:1) — the centerpiece:

```
runForSalesOrder(SalesOrder $so): MrpPlan
  // in transaction with lock on prior active mrp_plan for this SO:
  //
  // 1. supersede existing active plan (if any) → status='superseded'
  //
  // 2. allocate new MrpPlan row, version = prev?->version + 1 ?? 1
  //
  // 3. for each so.items as line:
  //      lookup BOM via BomService::explode(line.product_id, line.quantity)
  //      → returns array of (item, gross_quantity)
  //      record gross requirements per item across all lines (sum them)
  //
  // 4. for each unique item required:
  //      on_hand     = StockLevelService::totalAvailable(item)
  //      reserved    = sum of stock_levels.reserved_quantity for OTHER active WOs
  //      in_transit  = sum of (po_item.quantity - po_item.quantity_received)
  //                    for POs in (approved, sent, partially_received)
  //      net = max(0, gross - on_hand + reserved - in_transit)
  //
  //      if net > 0:
  //        - approved supplier lookup (fall back to "preferred" or first)
  //        - lead_time = approved_suppliers.lead_time_days || items.lead_time_days
  //        - earliest_need_date = min over so.items.delivery_date that consume this material
  //        - order_by_date = earliest_need_date - lead_time - 2 days safety
  //        - priority = (order_by_date <= today) ? 'urgent' : 'normal'
  //        - call PurchaseRequestService::createDraft(
  //            requested_by: so.created_by,
  //            department_id: so.creator.department_id,
  //            reason: "MRP shortfall for {so.so_number}",
  //            items: [{ item_id, quantity: net, estimated_unit_price }],
  //            is_auto_generated: true,
  //            mrp_plan_id: plan.id,
  //            priority: $priority,
  //          )
  //        - increment plan.auto_pr_count
  //
  //      record line in plan.diagnostics:
  //        { item_id, gross, on_hand, reserved, in_transit, net, action: 'pr_created'|'sufficient' }
  //
  // 5. for each so.item:
  //      WorkOrderService::createDraft({
  //        product_id, sales_order_id: so.id, sales_order_item_id: line.id,
  //        mrp_plan_id: plan.id, quantity_target: line.quantity,
  //        planned_start: line.delivery_date - 2 days, planned_end: line.delivery_date - 1 day,
  //        priority: line.delivery_date - now < 7 days ? 100 : 50, created_by: so.created_by
  //      })
  //      increment plan.draft_wo_count
  //
  // 6. plan.totals saved, status='active'
  //
  // 7. emit MrpPlanGeneratedEvent (broadcast on production.dashboard)
  //
  // returns plan eager loaded with sales_order, work_orders, purchase_requests

rerun(MrpPlan $plan): MrpPlan
  // sugar over runForSalesOrder($plan->salesOrder)
  // requires permission mrp.plans.run

show(MrpPlan $plan): MrpPlan
  // eager loads everything for the detail page
```

The 5-second-or-async fallback is a queueable wrapper in [`ProcessMrpRunJob`](api/app/Modules/MRP/Jobs/ProcessMrpRunJob.php:1); `SalesOrderService::confirm()` measures wall time and dispatches the job if the synchronous run isn't done by 5s. Unlikely at thesis scale but documented.

**Form Request.** `RerunMrpPlanRequest` (just authorize).

**API Resource.** `MrpPlanResource` — hash_id, mrp_plan_no, sales_order (lite), version, status, total_lines, shortages_found, auto_pr_count, draft_wo_count, diagnostics, generator (user lite), generated_at, work_orders (whenLoaded), purchase_requests (whenLoaded), timestamps.

**Controller.** `MrpPlanController`:
```
GET   /mrp-plans                      index   permission:mrp.plans.view
GET   /mrp-plans/{plan}               show    permission:mrp.plans.view
POST  /mrp-plans/{plan}/rerun         rerun   permission:mrp.plans.run
GET   /sales-orders/{so}/mrp-plan     forSo   permission:mrp.plans.view (returns active plan)
```

**Routes.** Add to [`api/app/Modules/MRP/routes.php`](api/app/Modules/MRP/routes.php:1).

**Frontend types.** `MrpPlan`, `MrpPlanDiagnosticEntry`.

**Frontend API.** [`spa/src/api/mrpPlans.ts`](spa/src/api/mrpPlans.ts:1).

**Pages.**
- [`spa/src/pages/mrp/plans/index.tsx`](spa/src/pages/mrp/plans/index.tsx:1) — list. Columns: mrp_plan_no (mono link), sales_order.so_number (mono link), version (mono), shortages_found (mono — chip warning if > 0), auto_pr_count (mono), draft_wo_count (mono), status (chip), generated_at (mono date). Filters: sales_order, status. ALL 5 states.
- [`spa/src/pages/mrp/plans/detail.tsx`](spa/src/pages/mrp/plans/detail.tsx:1) — header (plan number, SO link, status, generated by, generated at, "Rerun" button gated by permission). Body two columns:
  - Left: diagnostics table — per material row showing gross / on_hand / reserved / in_transit / net / action with chip (sufficient→success, pr_created→info).
  - Right: LinkedRecords — Work Orders (drafts), Purchase Requests (auto-generated, badge "AUTO"), Sales Order.
- The SalesOrder detail page (Task 48) "Production" tab is now wired: lists draft WOs from this plan with Confirm-batch action.

**Tests.**
- [`api/tests/Feature/MrpEngineTest.php`](api/tests/Feature/MrpEngineTest.php:1) — sufficient-stock path (no PR), shortage path (PR created with correct urgency), partial coverage path, multi-product SO, supersession on re-run.
- [`api/tests/Unit/MrpDateMathTest.php`](api/tests/Unit/MrpDateMathTest.php:1) — order_by_date computation with various lead times and delivery dates.

**Commit:** `feat: task 52 — MRP engine with auto PR and draft WO generation`

---

### Task 53 — MRP II Capacity Planning

**Goal:** Allocate work orders to (machine, mold) pairs across time slots, respecting compatibility, capacity, and priority.

**Schema.** No new tables (uses `production_schedules` from Task 51). Optionally a `scheduling_runs` table to log each scheduler invocation — defer to Sprint 8 unless time permits.

**Service.** [`CapacityPlanningService`](api/app/Modules/MRP/Services/CapacityPlanningService.php:1):

```
schedule(Collection $workOrders, Carbon $horizonStart, Carbon $horizonEnd): array
  // input: WorkOrders in 'planned' state (from MRP); output:
  //   {
  //     scheduled: ProductionSchedule[]   // candidate rows (status='pending' until confirmed)
  //     conflicts: { wo_id, reasons[] }[] // WOs that couldn't be placed
  //   }
  //
  // algorithm (priority-first greedy with backtracking):
  //
  // 1. sort WOs by priority desc, then planned_start asc
  //
  // 2. for each WO:
  //     a. compatible_molds = molds where product_id = wo.product_id AND status IN (available, in_use)
  //        AND current_shot_count + wo.quantity_target <= max_shots_before_maintenance
  //     b. if no compatible mold: conflict → 'no_mold_with_capacity'
  //     c. for each compatible_mold:
  //          compatible_machines = mold.compatibleMachines where status IN (idle, running, maintenance)
  //          for each compatible_machine:
  //            duration_hours = (qty / mold.output_per_hour) + (mold.setup_minutes / 60)
  //            find earliest gap in machine's existing schedule (within horizon)
  //              respecting machine.available_hours_per_day per day
  //            if gap fits AND end <= wo.planned_end (or extend with notice):
  //              tentatively place this (mold, machine, slot)
  //              break
  //     d. if any placement worked → record ProductionSchedule (status=pending, is_confirmed=false)
  //     e. else → conflict → 'no_capacity_in_horizon'
  //
  // returns the array; does NOT persist — caller (controller) persists pending rows in a transaction
  // after PPC head reviews

confirm(array $scheduleHashIds, int $confirmedBy): Collection
  // in transaction:
  //   for each schedule:
  //     supersede any other 'pending' schedule for the same WO
  //     status='confirmed', is_confirmed=true, confirmed_by, confirmed_at
  //     update WO.machine_id, WO.mold_id (NOW the WO has its assigned resources)
  //     update WO.status='confirmed' (which triggers reservations via WorkOrderService::confirm)
  //   return collection

reorder(int $workOrderId, int $newPriorityOrder): void
  // updates the WO's pending production_schedule.priority_order
  // Gantt drag handler calls this

reassign(int $workOrderId, int $newMachineId, int $newMoldId): void
  // pending only — reassigns to a new machine/mold pair
  // re-runs slot search for that single WO

snapshot(Carbon $from, Carbon $to): array
  // returns the Gantt data shape: { machines: [{id, name, schedules: [{wo_id, start, end, status, label}]}] }
```

**Form Requests.** `RunSchedulerRequest` (work_order_ids array OR auto-pull all planned), `ConfirmScheduleRequest` (schedule_ids array), `ReorderScheduleRequest` (schedule_id, new_priority), `ReassignScheduleRequest` (schedule_id, machine_id, mold_id).

**API Resource.** `ProductionScheduleResource` already exists from Task 51 — extend to include `wo` (lite resource) and `mold` (lite).

**Controller.** [`SchedulerController`](api/app/Modules/Production/Controllers/SchedulerController.php:1):
```
POST   /scheduler/run               run         permission:production.schedule.confirm  (preview only — returns proposal)
POST   /scheduler/confirm           confirm     permission:production.schedule.confirm  (persists)
PATCH  /scheduler/{schedule}/reorder reorder    permission:production.schedule.reorder
PATCH  /scheduler/{schedule}/reassign reassign  permission:production.schedule.reassign
GET    /scheduler/snapshot          snapshot    permission:production.schedule.view     (returns Gantt data)
```

**Routes.** Add to [`api/app/Modules/Production/routes.php`](api/app/Modules/Production/routes.php:1).

**Frontend types.** `SchedulerProposal`, `SchedulerConflict`, `GanttSnapshot`, `GanttRow`, `GanttBar`.

**Frontend API.** [`spa/src/api/scheduler.ts`](spa/src/api/scheduler.ts:1).

**Pages.** None new — Task 54 is the Gantt UI which uses these endpoints.

**Tests.**
- [`api/tests/Feature/CapacityPlanningTest.php`](api/tests/Feature/CapacityPlanningTest.php:1) — single WO fits cleanly; two competing WOs prioritized correctly; mold-shot-limit blocks; no-compatible-machine produces conflict; horizon-too-short produces conflict.
- [`api/tests/Unit/SchedulerSlotFinderTest.php`](api/tests/Unit/SchedulerSlotFinderTest.php:1) — gap detection in a populated schedule.

**Commit:** `feat: task 53 — MRP II capacity planning service with conflict detection`

---

### Task 54 — Production Gantt chart

**Goal:** SAP-style dense Gantt with machines on Y, time on X, drag-to-reorder, click-to-detail.

**Frontend dependency.** Add `frappe-gantt` to [`spa/package.json`](spa/package.json:1):

```json
"frappe-gantt": "^0.6.1"
```

The library is vanilla JS / no React wrapper. Wrap it.

**Wrapper component.** [`spa/src/components/production/GanttChart.tsx`](spa/src/components/production/GanttChart.tsx:1):

```tsx
interface Props {
  rows: GanttRow[];                   // machines (Y axis)
  bars: GanttBar[];                   // schedule bars
  onBarClick(barId: string): void;    // → navigate to WO detail
  onBarMove(barId: string, newStart: Date, newRowId: string): void;  // drag handler
  density: 'compact' | 'normal';
  viewMode: 'Day' | 'Week' | 'Month';
}
```

Internally:
- Mounts a `<div>` ref, calls `new Gantt(div, tasks, options)` in a `useEffect`.
- Maps our `GanttBar[]` to frappe-gantt's `Task[]` shape (id, name, start, end, progress, dependencies, custom_class).
- Uses Tailwind-set CSS variables to override frappe-gantt's default colors so they read from [`tokens.css`](spa/src/styles/tokens.css:1):
  ```
  .gantt .bar.success { fill: var(--success); }
  .gantt .bar.info    { fill: var(--accent); }
  .gantt .bar.warning { fill: var(--warning); }
  .gantt .bar.danger  { fill: var(--danger); }
  ```
  Status → custom_class map: planned→neutral (gray), confirmed→info, in_progress→info running stripe, paused→warning, completed→success, cancelled→danger striped.
- Density 'compact' = 28px row height; 'normal' = 36px.
- Drag end fires `onBarMove`. The page calls `scheduler.reassign(...)` or `scheduler.reorder(...)` based on whether the row changed.
- Time-cursor line ("now") rendered as overlay 1px indigo vertical line.

**Page.** [`spa/src/pages/production/schedule.tsx`](spa/src/pages/production/schedule.tsx:1):

```
PageHeader: title "Production Schedule"
  actions: density toggle (compact/normal), view mode (Day/Week/Month), date range picker, "Run Scheduler" button (calls /scheduler/run), "Confirm Selected" button (when bars selected, calls /scheduler/confirm)

Body:
  - Top: legend strip showing status color key with chips
  - Middle: <GanttChart> filling the rest of the viewport
  - Right side panel (collapsible): selected bar info — WO summary, materials status, conflicts (if any), buttons to reassign/reorder/confirm

Loading: full-page skeleton (gray rows with animated shimmer)
Empty:   <EmptyState> "No work orders scheduled. Run scheduler to begin."
Error:   <EmptyState> with retry
Stale:   keep previous Gantt rendered while refetching (placeholderData)
```

`useQuery` polls `/scheduler/snapshot` every 30s while open AND subscribes to the `production.dashboard` WebSocket channel (Task 55) to invalidate on schedule changes.

A separate "Unscheduled" swimlane at the top renders WOs from the proposal that came back as conflicts. Each shows a red striped bar with reason tooltip and a "Resolve" button that opens a modal to manually pick a machine/mold (calls `/scheduler/reassign`).

**Tests.** Vitest component test with mocked frappe-gantt (the lib is hard to test directly — test the data mapping in isolation):
- [`spa/src/components/production/GanttChart.test.tsx`](spa/src/components/production/GanttChart.test.tsx:1) — bar mapping, status→class mapping, click/drag callback wiring.

**Routes.** Add to [`spa/src/App.tsx`](spa/src/App.tsx:1) under `/production/schedule` with `production.schedule.view`.

**Commit:** `feat: task 54 — production Gantt chart with drag-to-reschedule`

---

### Task 55 — Production output recording (WebSocket)

**Goal:** Real-time recording of work order output. Updates dashboard live across connected clients.

**Backend dependency.** Confirm Reverb is configured from Sprint 1 Task 2. Add channel routes.

**Channel routes.** [`api/routes/channels.php`](api/routes/channels.php:1):

```php
use App\Models\User;

// Scoped to anyone with production.dashboard.view; private channel
Broadcast::channel('production.dashboard', fn(User $user) => $user->can('production.dashboard.view'));

// Per-WO channel; same gate plus WO existence check (resolved by HashID)
Broadcast::channel('production.wo.{hashId}', function (User $user, string $hashId) {
    if (!$user->can('production.work_orders.view')) return false;
    $id = app('hashids')->decode($hashId);
    return !empty($id);   // existence check is in WorkOrder::find ; channel ack only needs auth
});

// Per-machine channel (status updates)
Broadcast::channel('production.machine.{hashId}', fn(User $user, string $hashId) =>
    $user->can('mrp.machines.view')
);
```

**Events.**
- [`WorkOrderOutputRecorded`](api/app/Modules/Production/Events/WorkOrderOutputRecorded.php:1) — `implements ShouldBroadcastNow`. Broadcasts on `production.wo.{hashId}` AND `production.dashboard`. Payload: `{ wo_id, output_id, good_count, reject_count, total_quantity_produced, total_quantity_good, total_quantity_rejected, scrap_rate, recorded_at }`.
- [`WorkOrderStatusChanged`](api/app/Modules/Production/Events/WorkOrderStatusChanged.php:1) — payload: `{ wo_id, from, to, reason? }`.
- [`MachineStatusChanged`](api/app/Modules/MRP/Events/MachineStatusChanged.php:1) — payload: `{ machine_id, from, to, reason? }`.
- [`MoldShotLimitNearing`](api/app/Modules/MRP/Events/MoldShotLimitNearing.php:1), [`MoldShotLimitReached`](api/app/Modules/MRP/Events/MoldShotLimitReached.php:1).
- [`MrpPlanGenerated`](api/app/Modules/MRP/Events/MrpPlanGenerated.php:1).
- [`SalesOrderConfirmed`](api/app/Modules/CRM/Events/SalesOrderConfirmed.php:1).

**Service.** [`WorkOrderOutputService`](api/app/Modules/Production/Services/WorkOrderOutputService.php:1) — fully implemented:

```
record(WorkOrder $wo, array $data, string $idempotencyKey): WorkOrderOutput
  // 1. check Redis: if key exists, return previously-cached payload (200 dedupe)
  // 2. guard: wo.status='in_progress'
  // 3. in transaction with lock on wo + mold:
  //    a. validate good_count, reject_count >= 0, sum > 0
  //    b. validate sum of defect counts <= reject_count
  //    c. create work_order_outputs row (recorded_by, recorded_at=now, batch_code = "{wo.wo_number}-B{seq}")
  //    d. for each defect: create work_order_defects row
  //    e. update wo.quantity_produced += total, wo.quantity_good += good, wo.quantity_rejected += reject
  //       recompute wo.scrap_rate
  //    f. mold.incrementShots(total, wo.id) → may fire MoldShotLimitNearing/Reached events
  //    g. if wo.quantity_produced >= wo.quantity_target: post a notification to PPC ("WO complete eligible") but don't auto-complete
  // 4. broadcast WorkOrderOutputRecorded
  // 5. cache in Redis with 24h TTL: production:idem:{key} → output payload
  // 6. return output

list(WorkOrder $wo): Collection   // chronological, eager-loaded with defects.defectType
```

**Form Request.** `RecordOutputRequest`:
- `good_count integer min:0`
- `reject_count integer min:0`
- (`good_count + reject_count`) custom rule: `> 0`
- `shift string nullable`
- `remarks string nullable max:500`
- `defects array` — each `{ defect_type_id exists, count integer min:1 }`
- `defects.*.count` sum must `<= reject_count` (custom rule)
- Authorize: `permission:production.outputs.record`

**API Resource.** `WorkOrderOutputResource` exists from Task 51 — extend to include `defects` whenLoaded with their type and count, and `cumulative_after` (computed snapshot of WO totals at this row's recorded_at — useful for the timeline chart).

**Controller.** Add to `WorkOrderController`:
```
POST  /work-orders/{wo}/outputs       recordOutput     permission:production.outputs.record
                                                       (idempotency key in X-Idempotency-Key header)
GET   /work-orders/{wo}/outputs       listOutputs      permission:production.work_orders.view
```

**Frontend dependency.** Add `laravel-echo` and `pusher-js` (Reverb is pusher-protocol-compatible) to [`spa/package.json`](spa/package.json:1):

```json
"laravel-echo": "^1.16.1",
"pusher-js": "^8.4.0"
```

**Frontend echo setup.** [`spa/src/lib/echo.ts`](spa/src/lib/echo.ts:1):

```typescript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

export const echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY,
  wsHost: import.meta.env.VITE_REVERB_HOST,
  wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
  wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
  forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
  enabledTransports: ['ws', 'wss'],
  authEndpoint: '/api/v1/broadcasting/auth',
  withCredentials: true,                  // MANDATORY — cookie-based auth
});
```

Mount at app boot via [`spa/src/main.tsx`](spa/src/main.tsx:1) (import-side-effect).

**Echo hook.** [`spa/src/hooks/useEcho.ts`](spa/src/hooks/useEcho.ts:1) — generic subscription hook:

```typescript
export function useEcho<T>(channel: string, event: string, handler: (payload: T) => void) {
  useEffect(() => {
    const sub = echo.private(channel).listen(event, handler);
    return () => { echo.leave(channel); };
  }, [channel, event]);
}
```

**Page.** [`spa/src/pages/production/work-orders/record-output.tsx`](spa/src/pages/production/work-orders/record-output.tsx:1):

```
Header: WO summary card (number, product, target, current produced/good/reject)
Form (React Hook Form + Zod):
  - Good Count (large numeric input, mono font, min 0)
  - Reject Count (large numeric input, mono font, min 0)
  - Per defect type: count input, only enabled if Reject Count > 0; rolling sum check
  - Shift (select: Day / Night / Office)
  - Remarks
  - Submit button — generates UUID idempotency key, posts to /work-orders/{wo}/outputs with X-Idempotency-Key header
Live cumulative panel (right side): subscribes to production.wo.{hashId}, updates totals in real-time
Recent outputs list at bottom: most recent 10, chronological desc, with defect breakdown
```

**Page enhancement.** [`spa/src/pages/production/work-orders/detail.tsx`](spa/src/pages/production/work-orders/detail.tsx:1) — Outputs tab now subscribes to `production.wo.{hashId}` and refetches the outputs list on event.

**Dashboard subscription.** Plant Manager dashboard (Task 58) subscribes to `production.dashboard` and uses TanStack Query's `setQueryData` to update KPIs without refetch.

**Tests.**
- [`api/tests/Feature/RecordOutputTest.php`](api/tests/Feature/RecordOutputTest.php:1) — happy path, idempotency replay, defect-sum-exceeds-reject error, status guard, mold shot increment.
- [`api/tests/Feature/BroadcastWorkOrderOutputTest.php`](api/tests/Feature/BroadcastWorkOrderOutputTest.php:1) — assert event was dispatched (use Bus::fake or Event::fake).

**Commit:** `feat: task 55 — production output recording with WebSocket broadcast`

---

### Task 56 — Machine breakdown handling

**Goal:** Coordinated response when a machine goes down — pause WOs, log downtime, suggest alternatives, notify maintenance.

**Listener.** [`HandleMachineBreakdown`](api/app/Modules/Production/Listeners/HandleMachineBreakdown.php:1) — listens for `MachineStatusChanged` where `to=breakdown`:

```
handle(MachineStatusChanged $e):
  in transaction:
    - load machine with currentWorkOrder
    - if currentWorkOrder exists:
        WorkOrderService::pause($wo, $reason='Machine breakdown', category=Breakdown)
    - find compatible alternatives:
        candidates = Machine::query()
          ->where('status', 'idle')
          ->whereHas('compatibleMolds', fn($q) => $q->where('id', $wo->mold_id))
          ->get();
    - notify Maintenance Head via NotificationService (in_app + email)
    - notify PPC Head with the list of candidates and a "Reschedule" action link
    - emit MachineBreakdownDetectedEvent (broadcast on production.dashboard)
```

The notification action link points to a Frontend modal where the PPC head picks an alternative machine and clicks Confirm — this calls `/scheduler/reassign` for the paused WO.

**Listener.** [`HandleMachineRestored`](api/app/Modules/Production/Listeners/HandleMachineRestored.php:1) — listens for `MachineStatusChanged` where `from IN (breakdown, maintenance) AND to IN (idle, running)`:

```
handle(...):
  - close any open machine_downtimes row for this machine (end_time = now, duration_minutes computed)
  - if there are paused WOs that were on this machine and have not been reassigned, surface them as "Resume Available" in the WO list (a small chip on the row)
```

**Service additions.** Document in [`MachineService::transitionStatus`](api/app/Modules/MRP/Services/MachineService.php:1) that the listeners are auto-registered via [`EventServiceProvider`](api/app/Providers/EventServiceProvider.php:1). Add the bindings.

**Frontend.**
- [`spa/src/components/production/BreakdownAlertCard.tsx`](spa/src/components/production/BreakdownAlertCard.tsx:1) — small alert cell rendered on the dashboard when a `MachineBreakdownDetectedEvent` arrives. Shows machine name, paused WO, action: "View Suggestions". Clicking opens [`spa/src/components/production/RescheduleModal.tsx`](spa/src/components/production/RescheduleModal.tsx:1) — list of candidate machines with current load, "Reassign" buttons.
- Notifications page (Sprint 1 Task 77 — exists) gets the new notification types automatically; no UI changes needed.

**Tests.**
- [`api/tests/Feature/MachineBreakdownFlowTest.php`](api/tests/Feature/MachineBreakdownFlowTest.php:1) — running WO + breakdown → WO paused, downtime opened, notifications fired, candidates returned.
- [`api/tests/Feature/MachineRestoredFlowTest.php`](api/tests/Feature/MachineRestoredFlowTest.php:1) — breakdown → idle → downtime closed.

**Commit:** `feat: task 56 — machine breakdown handling with auto-pause and rescheduling`

---

### Task 57 — OEE calculation

**Goal:** Industry-standard OEE metric per machine per period.

**Service.** [`OeeService`](api/app/Modules/Production/Services/OeeService.php:1):

```
calculate(Machine $m, Carbon $from, Carbon $to): array
  // returns { availability, performance, quality, oee, diagnostics }
  // values are 0..1 floats; format-as-percentage at presentation layer

  $scheduledMinutes = $this->scheduledMinutes($m, $from, $to);   // sum of available_hours_per_day * working_days, minutes
  $plannedDowntime  = MachineDowntime::where('machine_id', $m->id)
                       ->whereIn('category', ['planned_maintenance', 'changeover'])
                       ->whereBetween('start_time', [$from, $to])
                       ->sum('duration_minutes');
  $unplannedDowntime= MachineDowntime::where('machine_id', $m->id)
                       ->whereIn('category', ['breakdown', 'material_shortage', 'no_order'])
                       ->whereBetween('start_time', [$from, $to])
                       ->sum('duration_minutes');

  $availableTime    = max(0, $scheduledMinutes - $plannedDowntime);
  $runTime          = max(0, $availableTime - $unplannedDowntime);

  $outputs = WorkOrderOutput::whereHas('workOrder', fn($q) => $q->where('machine_id', $m->id))
              ->whereBetween('recorded_at', [$from, $to])
              ->with('workOrder.mold')->get();
  $good   = $outputs->sum('good_count');
  $reject = $outputs->sum('reject_count');
  $totalCount = $good + $reject;

  $idealCycle = $outputs->isEmpty()
    ? 0
    : $outputs->avg(fn($o) => $o->workOrder->mold->cycle_time_seconds ?? 0);

  $availability = $availableTime > 0 ? $runTime / $availableTime : 0;
  $performance  = $runTime > 0
    ? min(1, ($totalCount * $idealCycle / 60) / $runTime)
    : 0;
  $quality      = $totalCount > 0 ? $good / $totalCount : 0;
  $oee          = $availability * $performance * $quality;

  return compact('availability','performance','quality','oee') + [
    'diagnostics' => [
      'scheduled_minutes' => $scheduledMinutes,
      'planned_downtime'  => $plannedDowntime,
      'unplanned_downtime'=> $unplannedDowntime,
      'available_time'    => $availableTime,
      'run_time'          => $runTime,
      'good_count'        => $good,
      'reject_count'      => $reject,
      'ideal_cycle_seconds' => $idealCycle,
      'performance_capped' => $performance >= 1,
    ],
  ];

calculateForAllMachines(Carbon $from, Carbon $to): Collection   // bulk version

calculateForToday(Machine $m): array        // sugar
calculateForCurrentShift(Machine $m): array // sugar — computes from shift table

dailySummary(Carbon $date): array           // dashboard payload — every machine, today
```

**Daily aggregation cache.** Optional: nightly job [`AggregateDailyOeeJob`](api/app/Modules/Production/Jobs/AggregateDailyOeeJob.php:1) writes to a `daily_machine_oee` cache table (NEW — see decision below). Skip in Sprint 6 — recompute at request time, cache 5 minutes in Redis. Note for Sprint 8 Task 82: "if dashboard slow, materialize this".

**Form Request.** None for the read-only endpoint.

**API Resource.** `OeeResultResource` — availability, performance, quality, oee, diagnostics, machine (lite), period_from, period_to.

**Controller.** [`OeeController`](api/app/Modules/Production/Controllers/OeeController.php:1):
```
GET  /oee/machine/{machine}      forMachine    permission:production.dashboard.view
                                               query: from, to (defaults: today 00:00 to now)
GET  /oee/today                  todayAll      permission:production.dashboard.view
                                               returns array for all active machines
```

**Routes.** Add to [`api/app/Modules/Production/routes.php`](api/app/Modules/Production/routes.php:1).

**Frontend types.** `OeeResult`.

**Frontend API.** [`spa/src/api/oee.ts`](spa/src/api/oee.ts:1).

**Pages.**
- Machine detail page (Task 50) gains an OEE panel — small card with the four metrics rendered as horizontal progress bars (availability/performance/quality, then a larger "OEE" composite). Period selector: Today / This Week / This Month / Custom.
- The dashboard (Task 58) renders the bulk OEE table.

**Component.** [`spa/src/components/production/OeeGauge.tsx`](spa/src/components/production/OeeGauge.tsx:1) — minimal gauge: 4 stacked horizontal bars with percentage labels in mono. Color: ≥0.85 emerald, 0.70–0.85 amber, <0.70 red. No fancy SVG dial — flat bars per design system "no shadows" rule.

**Tests.**
- [`api/tests/Unit/OeeMathTest.php`](api/tests/Unit/OeeMathTest.php:1) — golden numbers: planned 480 min, planned dt 30, unplanned dt 60, total output 1200, ideal cycle 18s → expected availability/performance/quality match hand-computed values.
- [`api/tests/Unit/OeePerformanceCapTest.php`](api/tests/Unit/OeePerformanceCapTest.php:1) — clamps performance > 1 to 1 with `performance_capped=true`.
- [`api/tests/Unit/OeeDivByZeroTest.php`](api/tests/Unit/OeeDivByZeroTest.php:1) — zero scheduled / zero output → returns zeros, not NaN.

**Commit:** `feat: task 57 — OEE calculation service with availability/performance/quality decomposition`

---

### Task 58 — Production dashboard

**Goal:** The Plant Manager view of Chain 1, exactly matching the [`docs/DESIGN-SYSTEM.md`](docs/DESIGN-SYSTEM.md:691) mockup.

**Endpoint.** [`ProductionDashboardController`](api/app/Modules/Production/Controllers/DashboardController.php:1):

```
GET /production/dashboard       // permission:production.dashboard.view

  returns {
    kpis: {
      today_output_total: int,                  // sum of work_order_outputs.good_count + reject_count today
      today_output_good:  int,                  // sum of good only
      active_work_orders: int,                  // status in (in_progress, paused)
      machines_running:   int,
      machines_idle:      int,
      machines_breakdown: int,
      avg_oee_today:      float,                // avg over running machines
    },
    chain_stage_breakdown: [                    // for StageBreakdown component
      { label, count, percent, color }
      // labels: Order Entered, MRP Planned, In Production, QC Pending, Ready to Ship, Delivered Unpaid, At Risk
    ],
    machine_utilization: [                      // for the table
      { machine_id, code, name, status, current_wo, oee, availability, performance, quality }
    ],
    alerts: [                                   // for the alerts panel
      { type: 'breakdown'|'mold_limit'|'material_shortage', severity, message, link, time }
    ],
    defect_pareto: [                            // for the bar chart
      { defect_code, defect_name, count, percent }
    ],
  }
```

Build by composing existing services (no new business logic):
- KPIs: trivial counters with Eloquent `count()`.
- Chain breakdown: query SOs grouped by their derived chain stage. Stage derivation lives in [`SalesOrderService::deriveChainStage(SalesOrder $so): string`](api/app/Modules/CRM/Services/SalesOrderService.php:1) — small switch on `(status, has_active_inspection, has_delivery, etc.)`.
- Machine util: `OeeService::calculateForAllMachines(today)` joined with machine info.
- Alerts: pull last 24h of breakdown events, mold-near-limit events, low-stock items (Sprint 5 listener).
- Defect Pareto: aggregate `work_order_defects` joined to `defect_types` over the selected period.

Cache the whole payload in Redis for 30 seconds (key: `dashboard:production`) — invalidated on any of: WO status change, output recorded, machine status change. Cache invalidation handled by an event listener [`InvalidateProductionDashboardCache`](api/app/Modules/Production/Listeners/InvalidateProductionDashboardCache.php:1) listening to all six events.

**Page.** [`spa/src/pages/production/dashboard.tsx`](spa/src/pages/production/dashboard.tsx:1):

Layout per [`docs/DESIGN-SYSTEM.md`](docs/DESIGN-SYSTEM.md:691):

```
PageHeader: "Production"
  actions: time-range selector (Today / This Week / This Month), Export CSV

Row 1: 4 KPI cards (StatCard component)
  - Today Output ({today_output_good} / {today_output_total})         // mono, ₱-style formatting (no peso) with delta
  - Active Work Orders ({active_work_orders})                         // mono
  - Machine Utilization ({running}/{total}) with mini status pills
  - OEE Today ({avg_oee_today * 100 | round 1}%)                     // mono

Row 2: 2-column grid
  - Left (2/3): Active Orders by Chain Stage <StageBreakdown>
  - Right (1/3): Alerts panel <Panel> with list — breakdown red dot, mold-near-limit amber, material short red

Row 3: 2-column grid
  - Left (1/2): Machine Utilization <Panel> with <DataTable>
                columns: machine_code (mono), name, status (chip), current_wo (mono link),
                         availability/performance/quality/oee (mono with mini bars)
  - Right (1/2): QC Defect Pareto <Panel> with bar chart (recharts)
                 indigo bars, count + percent on right, top 10 defects
```

Subscribes to `production.dashboard` channel via `useEcho`. On any event, invalidates the dashboard query (TanStack `queryClient.invalidateQueries(['dashboard:production'])`). Polling fallback: 60s background refetch in case WS disconnects.

ALL 5 mandatory states present:
- LOADING: per-section skeletons (KPI cards, breakdown bars, table rows)
- ERROR: top-level <EmptyState> with retry
- EMPTY: not really applicable — there are always machines; if all 0s, show "No production activity today" inside each panel
- DATA: as designed above
- STALE: subtle 0.5 opacity on the changed sections during refetch via `placeholderData`

**Tests.** API: [`api/tests/Feature/ProductionDashboardEndpointTest.php`](api/tests/Feature/ProductionDashboardEndpointTest.php:1) — fixture loaded, response shape asserted, cache hit on second call. Frontend: smoke render test with mocked API.

**Routes.**
- API: add to [`api/app/Modules/Production/routes.php`](api/app/Modules/Production/routes.php:1).
- SPA: add to [`spa/src/App.tsx`](spa/src/App.tsx:1), `/production/dashboard`, gated by `production.dashboard.view`.

**Commit:** `feat: task 58 — production dashboard with chain stage breakdown, OEE, and live updates`

---

## 5. Files to create or modify (full inventory)

### Backend — migrations (in order)

```
api/database/migrations/0062_create_products_table.php                                           [create]
api/database/migrations/0063_create_product_price_agreements_table.php                           [create]
api/database/migrations/0064_create_sales_orders_table.php                                       [create]
api/database/migrations/0065_create_sales_order_items_table.php                                  [create]
api/database/migrations/0066_create_bill_of_materials_table.php                                  [create]
api/database/migrations/0067_create_bom_items_table.php                                          [create]
api/database/migrations/0068_create_machines_table.php                                           [create]
api/database/migrations/0069_create_molds_table.php                                              [create]
api/database/migrations/0070_create_mold_machine_compatibility_table.php                         [create]
api/database/migrations/0071_create_mold_history_table.php                                       [create]
api/database/migrations/0072_create_work_orders_table.php                                        [create]
api/database/migrations/0073_create_work_order_materials_table.php                               [create]
api/database/migrations/0074_create_work_order_outputs_table.php                                 [create]
api/database/migrations/0075_create_work_order_defects_table.php                                 [create]
api/database/migrations/0076_create_defect_types_table.php                                       [create — runs BEFORE 0075 in order]
api/database/migrations/0077_create_machine_downtimes_table.php                                  [create]
api/database/migrations/0078_create_production_schedules_table.php                               [create]
api/database/migrations/0079_create_mrp_plans_table.php                                          [create + adds deferred FKs]
```

(Note: 0076 must run before 0075 — Laravel runs migrations in filename order, so adjust filenames as needed: rename 0075 to 0076 and current 0076 to 0075. Best plan: **swap the filenames** so defect_types is 0075 and work_order_defects is 0076. Update this plan reflexively.)

### Backend — modules (per file)

**CRM module** (`api/app/Modules/CRM/`)
```
Enums/SalesOrderStatus.php                                                                       [create]
Models/Product.php                                                                               [create]
Models/PriceAgreement.php                                                                        [create]
Models/SalesOrder.php                                                                            [create]
Models/SalesOrderItem.php                                                                        [create]
Services/ProductService.php                                                                      [create]
Services/PriceAgreementService.php                                                               [create]
Services/SalesOrderService.php                                                                   [create]
Requests/StoreProductRequest.php                                                                 [create]
Requests/UpdateProductRequest.php                                                                [create]
Requests/ListProductsRequest.php                                                                 [create]
Requests/StorePriceAgreementRequest.php                                                          [create]
Requests/UpdatePriceAgreementRequest.php                                                         [create]
Requests/StoreSalesOrderRequest.php                                                              [create]
Requests/UpdateSalesOrderRequest.php                                                             [create]
Requests/ListSalesOrdersRequest.php                                                              [create]
Resources/ProductResource.php                                                                    [create]
Resources/PriceAgreementResource.php                                                             [create]
Resources/SalesOrderResource.php                                                                 [create]
Resources/SalesOrderItemResource.php                                                             [create]
Controllers/ProductController.php                                                                [create]
Controllers/PriceAgreementController.php                                                         [create]
Controllers/SalesOrderController.php                                                             [create]
Events/SalesOrderConfirmed.php                                                                   [create]
Exceptions/NoPriceAgreementException.php                                                         [create]
routes.php                                                                                       [overwrite — replace Sprint 4 scaffold]
```

**MRP module** (`api/app/Modules/MRP/`)
```
Enums/MachineStatus.php                                                                          [create]
Enums/MoldStatus.php                                                                             [create]
Enums/MoldEventType.php                                                                          [create]
Enums/MrpPlanStatus.php                                                                          [create]
Models/Bom.php                                                                                   [create]
Models/BomItem.php                                                                               [create]
Models/Machine.php                                                                               [create]
Models/Mold.php                                                                                  [create]
Models/MoldHistory.php                                                                           [create]
Models/MrpPlan.php                                                                               [create]
Services/BomService.php                                                                          [create]
Services/MachineService.php                                                                      [create]
Services/MoldService.php                                                                         [create]
Services/MoldHistoryService.php                                                                  [create]
Services/MrpEngineService.php                                                                    [create]
Services/CapacityPlanningService.php                                                             [create]
Jobs/ProcessMrpRunJob.php                                                                        [create]
Requests/StoreBomRequest.php                                                                     [create]
Requests/UpdateBomRequest.php                                                                    [create]
Requests/StoreMachineRequest.php                                                                 [create]
Requests/UpdateMachineRequest.php                                                                [create]
Requests/TransitionMachineStatusRequest.php                                                      [create]
Requests/StoreMoldRequest.php                                                                    [create]
Requests/UpdateMoldRequest.php                                                                   [create]
Requests/AssignMoldCompatibilityRequest.php                                                      [create]
Requests/RerunMrpPlanRequest.php                                                                 [create]
Resources/BomResource.php                                                                        [create]
Resources/BomItemResource.php                                                                    [create]
Resources/MachineResource.php                                                                    [create]
Resources/MoldResource.php                                                                       [create]
Resources/MoldHistoryResource.php                                                                [create]
Resources/MrpPlanResource.php                                                                    [create]
Controllers/BomController.php                                                                    [create]
Controllers/MachineController.php                                                                [create]
Controllers/MoldController.php                                                                   [create]
Controllers/MrpPlanController.php                                                                [create]
Events/MachineStatusChanged.php                                                                  [create]
Events/MoldShotLimitNearing.php                                                                  [create]
Events/MoldShotLimitReached.php                                                                  [create]
Events/MrpPlanGenerated.php                                                                      [create]
Exceptions/IllegalStatusTransitionException.php                                                  [create]
routes.php                                                                                       [create]
```

**Production module** (`api/app/Modules/Production/`)
```
Enums/WorkOrderStatus.php                                                                        [create]
Enums/MachineDowntimeCategory.php                                                                [create]
Enums/ProductionScheduleStatus.php                                                               [create]
Models/WorkOrder.php                                                                             [create]
Models/WorkOrderMaterial.php                                                                     [create]
Models/WorkOrderOutput.php                                                                       [create]
Models/WorkOrderDefect.php                                                                       [create]
Models/DefectType.php                                                                            [create]
Models/MachineDowntime.php                                                                       [create]
Models/ProductionSchedule.php                                                                    [create]
Services/WorkOrderService.php                                                                    [create]
Services/WorkOrderOutputService.php                                                              [create]
Services/OeeService.php                                                                          [create]
Requests/StoreWorkOrderRequest.php                                                               [create]
Requests/UpdateWorkOrderRequest.php                                                              [create]
Requests/ConfirmWorkOrderRequest.php                                                             [create]
Requests/StartWorkOrderRequest.php                                                               [create]
Requests/PauseWorkOrderRequest.php                                                               [create]
Requests/CompleteWorkOrderRequest.php                                                            [create]
Requests/CancelWorkOrderRequest.php                                                              [create]
Requests/ListWorkOrdersRequest.php                                                               [create]
Requests/RecordOutputRequest.php                                                                 [create]
Requests/RunSchedulerRequest.php                                                                 [create]
Requests/ConfirmScheduleRequest.php                                                              [create]
Requests/ReorderScheduleRequest.php                                                              [create]
Requests/ReassignScheduleRequest.php                                                             [create]
Resources/WorkOrderResource.php                                                                  [create]
Resources/WorkOrderMaterialResource.php                                                          [create]
Resources/WorkOrderOutputResource.php                                                            [create]
Resources/WorkOrderDefectResource.php                                                            [create]
Resources/DefectTypeResource.php                                                                 [create]
Resources/MachineDowntimeResource.php                                                            [create]
Resources/ProductionScheduleResource.php                                                         [create]
Resources/OeeResultResource.php                                                                  [create]
Controllers/WorkOrderController.php                                                              [create]
Controllers/DefectTypeController.php                                                             [create]
Controllers/SchedulerController.php                                                              [create]
Controllers/OeeController.php                                                                    [create]
Controllers/DashboardController.php                                                              [create]
Events/WorkOrderOutputRecorded.php                                                               [create]
Events/WorkOrderStatusChanged.php                                                                [create]
Events/MachineBreakdownDetected.php                                                              [create]
Listeners/HandleMachineBreakdown.php                                                             [create]
Listeners/HandleMachineRestored.php                                                              [create]
Listeners/InvalidateProductionDashboardCache.php                                                 [create]
routes.php                                                                                       [create]
```

**App-level**
```
api/app/Providers/EventServiceProvider.php                                                       [modify — register listeners]
api/routes/channels.php                                                                          [modify — add 3 channel routes]
api/database/seeders/RolePermissionSeeder.php                                                    [modify — add ~30 permissions]
api/database/seeders/ProductSeeder.php                                                           [create]
api/database/seeders/PriceAgreementSeeder.php                                                    [create]
api/database/seeders/BomSeeder.php                                                               [create]
api/database/seeders/MachineSeeder.php                                                           [create]
api/database/seeders/MoldSeeder.php                                                              [create]
api/database/seeders/MoldCompatibilitySeeder.php                                                 [create]
api/database/seeders/DefectTypeSeeder.php                                                        [create]
api/database/seeders/DatabaseSeeder.php                                                          [modify — add new seeders in order]
```

### Backend — tests
```
api/tests/Unit/PriceAgreementResolveTest.php                                                     [create]
api/tests/Feature/ProductCrudTest.php                                                            [create]
api/tests/Feature/SalesOrderCreationTest.php                                                     [create]
api/tests/Feature/SalesOrderConfirmTest.php                                                      [create]
api/tests/Unit/BomExplodeTest.php                                                                [create]
api/tests/Feature/BomVersioningTest.php                                                          [create]
api/tests/Unit/MachineStatusTransitionTest.php                                                   [create]
api/tests/Unit/MoldShotIncrementTest.php                                                         [create]
api/tests/Unit/WorkOrderLifecycleTest.php                                                        [create]
api/tests/Feature/WorkOrderConfirmReservesMaterialsTest.php                                      [create]
api/tests/Feature/WorkOrderStartIssuesMaterialsTest.php                                          [create]
api/tests/Feature/MrpEngineTest.php                                                              [create]
api/tests/Unit/MrpDateMathTest.php                                                               [create]
api/tests/Feature/CapacityPlanningTest.php                                                       [create]
api/tests/Unit/SchedulerSlotFinderTest.php                                                       [create]
api/tests/Feature/RecordOutputTest.php                                                           [create]
api/tests/Feature/BroadcastWorkOrderOutputTest.php                                               [create]
api/tests/Feature/MachineBreakdownFlowTest.php                                                   [create]
api/tests/Feature/MachineRestoredFlowTest.php                                                    [create]
api/tests/Unit/OeeMathTest.php                                                                   [create]
api/tests/Unit/OeePerformanceCapTest.php                                                         [create]
api/tests/Unit/OeeDivByZeroTest.php                                                              [create]
api/tests/Feature/ProductionDashboardEndpointTest.php                                            [create]
api/tests/Feature/DocumentSequenceConcurrencyTest.php                                            [modify — add SO/WO cases]
```

### Frontend — types and API
```
spa/src/types/crm.ts                                                                             [create]
spa/src/types/mrp.ts                                                                             [create]
spa/src/types/production.ts                                                                      [create]
spa/src/types/index.ts                                                                           [modify — re-export the three]
spa/src/api/products.ts                                                                          [create]
spa/src/api/priceAgreements.ts                                                                   [create]
spa/src/api/salesOrders.ts                                                                       [create]
spa/src/api/boms.ts                                                                              [create]
spa/src/api/machines.ts                                                                          [create]
spa/src/api/molds.ts                                                                             [create]
spa/src/api/mrpPlans.ts                                                                          [create]
spa/src/api/workOrders.ts                                                                        [create]
spa/src/api/defectTypes.ts                                                                       [create]
spa/src/api/scheduler.ts                                                                         [create]
spa/src/api/oee.ts                                                                               [create]
spa/src/api/productionDashboard.ts                                                               [create]
spa/src/lib/echo.ts                                                                              [create]
spa/src/hooks/useEcho.ts                                                                         [create]
spa/src/main.tsx                                                                                 [modify — import echo for side-effect]
spa/package.json                                                                                 [modify — add frappe-gantt, laravel-echo, pusher-js]
```

### Frontend — components
```
spa/src/components/production/GanttChart.tsx                                                     [create]
spa/src/components/production/OeeGauge.tsx                                                       [create]
spa/src/components/production/BreakdownAlertCard.tsx                                             [create]
spa/src/components/production/RescheduleModal.tsx                                                [create]
spa/src/components/production/MoldShotProgress.tsx                                               [create — used in molds list]
spa/src/components/production/index.ts                                                           [create — barrel export]
spa/src/components/production/GanttChart.test.tsx                                                [create]
```

### Frontend — pages
```
spa/src/pages/crm/products/index.tsx                                                             [create]
spa/src/pages/crm/products/create.tsx                                                            [create]
spa/src/pages/crm/products/edit.tsx                                                              [create]
spa/src/pages/crm/products/detail.tsx                                                            [create]
spa/src/pages/crm/sales-orders/index.tsx                                                         [create]
spa/src/pages/crm/sales-orders/create.tsx                                                        [create]
spa/src/pages/crm/sales-orders/edit.tsx                                                          [create]
spa/src/pages/crm/sales-orders/detail.tsx                                                        [create]
spa/src/pages/accounting/customers/detail.tsx                                                    [modify — add Price Agreements + Sales Orders tabs]
spa/src/pages/mrp/boms/index.tsx                                                                 [create]
spa/src/pages/mrp/boms/create.tsx                                                                [create]
spa/src/pages/mrp/boms/detail.tsx                                                                [create]
spa/src/pages/mrp/machines/index.tsx                                                             [create]
spa/src/pages/mrp/machines/detail.tsx                                                            [create]
spa/src/pages/mrp/molds/index.tsx                                                                [create]
spa/src/pages/mrp/molds/detail.tsx                                                               [create]
spa/src/pages/mrp/plans/index.tsx                                                                [create]
spa/src/pages/mrp/plans/detail.tsx                                                               [create]
spa/src/pages/production/work-orders/index.tsx                                                   [create]
spa/src/pages/production/work-orders/create.tsx                                                  [create]
spa/src/pages/production/work-orders/edit.tsx                                                    [create]
spa/src/pages/production/work-orders/detail.tsx                                                  [create]
spa/src/pages/production/work-orders/record-output.tsx                                           [create]
spa/src/pages/production/schedule.tsx                                                            [create]
spa/src/pages/production/dashboard.tsx                                                           [create]
spa/src/App.tsx                                                                                  [modify — register all new routes]
spa/src/components/layout/Sidebar.tsx                                                            [modify — add CRM, MRP, Production sections]
```

### Docs
```
docs/SCHEMA.md                                                                                   [modify — append mrp_plans table; document SO/WO/machine/mold reconciliations]
docs/TASKS.md                                                                                    [modify — renumber Sprint 7 migrations starting at 0080]
.env.example                                                                                     [modify — add VITE_REVERB_* and REVERB_* keys if missing]
```

---

## 6. Order of execution within the sprint

```
Day 1 — Task 47   CRM products + price agreements
Day 2 — Task 48   Sales orders (skipping MRP-related assertions in tests)
Day 3 — Task 49   BOMs
Day 4 — Task 50   Machines + molds + compatibility
Day 5 — Task 51   Work orders (huge task — six migrations + service)
Day 6 — Task 52   MRP engine — un-skip the SO confirm test
Day 7 — Task 53   Capacity planning service (no UI yet)
Day 8 — Task 54   Gantt UI (uses Task 53 endpoints)
Day 9 — Task 55   WebSocket output recording (Echo + Reverb wiring)
Day 10 — Task 56  Breakdown handling
Day 11 — Task 57  OEE service
Day 12 — Task 58  Production dashboard
```

Each task gets its own commit. Each commit is reviewable in isolation. Merge to main in PRs grouping 2–3 tasks (e.g., 47–48 = "CRM core", 49–51 = "production master data + WO", 52–54 = "MRP and scheduling", 55–58 = "real-time + dashboard").

---

## 7. Risks and how we handle them

| Risk | Mitigation |
|---|---|
| **MRP engine slow on large SOs.** | Wrapped in async-fallback (Task 52). At thesis scale (≤8 lines, ≤10 BOM items each) this is non-issue, but the queue path is documented and tested via timeout simulation. |
| **WebSocket flake in dev.** | Pages always carry a 60s polling refetch as fallback. Demo doesn't depend on real-time alone. |
| **Gantt library brittleness.** | frappe-gantt is small enough that we own a thin React wrapper; if it regresses, we can swap to `react-gantt-task` or a custom SVG implementation in 1–2 days. The shape of `GanttSnapshot` is library-agnostic. |
| **Mold shot count race condition under load.** | `lockForUpdate` on the molds row inside the output transaction; covered by unit test. |
| **Concurrent SO confirmations triggering MRP races on the same item.** | MRP engine reads `stock_levels` with `lockForUpdate` (matches Sprint 5 reservation semantics). PR creation is idempotent enough — two PRs for the same item are an audit-able mistake, not data corruption. |
| **Migration numbering collision with Sprint 7 / 8 plans.** | Renumber Sprint 7 in this PR (search-and-replace in [`docs/TASKS.md`](docs/TASKS.md:1)); Sprint 8 plans haven't been written yet. |
| **OEE numbers look wrong to the panel during defense.** | Diagnostics field exposes every input; the dashboard cards have a "?" tooltip showing the formula and the inputs. Auditable. |
| **CRM officer enters SO with no price agreement.** | Hard block: form errors out with a deep link to "Create Price Agreement for {customer}". Consistent with Decision 1. |
| **Auto-PRs from MRP cluttering Purchasing inbox.** | Consolidation is manual (Decision 2 in Sprint 5). Purchasing UI shows "AUTO" chip on `is_auto_generated=true` rows so they can bulk-action. |
| **Sprint 6 introduces Reverb broadcasting for the first time. Production HTTPS cookies + WSS handshake can mismatch.** | Use Reverb's `same_site=lax` cookie pass-through. Document required Nginx upgrade headers in [`docker/nginx/default.conf`](docker/nginx/default.conf:1) (already exists from Task 1; verify upgrade headers `Connection: upgrade` + `Upgrade: $http_upgrade` are present). |

---

## 8. Sprint exit checklist (run before merging the final PR)

Backend
- [ ] `php artisan migrate:fresh --seed` succeeds end-to-end with no errors
- [ ] Every new model has [`HasHashId`](api/app/Common/Traits/HasHashId.php:1)
- [ ] Every new mutating service method wrapped in `DB::transaction()`
- [ ] Every new controller route has both `permission:` and `feature:` middleware
- [ ] Every new API Resource returns `hash_id`, never raw integer `id`
- [ ] No raw integer IDs leak into JSON responses (search the Resources directory for `'id' => $this->id`)
- [ ] Reverb starts (`php artisan reverb:start`) and channels authenticate via cookie
- [ ] All Sprint 6 PHPUnit tests green; coverage of services ≥ 80%
- [ ] Decimal handling: grep for `(float)` casts in services — there should be ZERO on monetary or quantity values

Frontend
- [ ] Every new page renders all 5 mandatory states (loading, error, empty, data, stale)
- [ ] Every new form has Zod schema, disabled-while-pending submit, server-side error mapping, cancel button, success toast
- [ ] Every new route registered with lazy import + AuthGuard + ModuleGuard + PermissionGuard
- [ ] Every number/ID/date in tables uses `font-mono tabular-nums`
- [ ] Every status uses `<Chip>` with the documented variant mapping; no inline color
- [ ] Canvas remains grayscale: grep new `.tsx` files for any `bg-blue|bg-green|bg-red|text-blue|text-green|text-red` outside chip variants
- [ ] Echo subscriptions have cleanup in `useEffect` return
- [ ] Build (`vite build`) clean — no TypeScript errors

Docs
- [ ] [`docs/SCHEMA.md`](docs/SCHEMA.md:1) updated with `mrp_plans` and column additions documented in Section 0
- [ ] [`docs/TASKS.md`](docs/TASKS.md:1) Sprint 7 migration numbers shifted by 1
- [ ] This plan file lives at `plans/ogami-erp-sprint-6-order-to-cash-part-1-tasks-47-58.md`

Demo readiness
- [ ] CRM officer creates an SO → confirms → MRP plan created → draft WOs visible in `/production/work-orders` filtered by status=planned
- [ ] PPC head opens `/production/schedule`, runs scheduler, drags a bar, confirms → WOs flip to `confirmed`
- [ ] Production supervisor starts a WO, records output (twice in a row to test idempotency), the dashboard updates without manual refresh
- [ ] Plant manager flips a machine to `breakdown` → notification fires, paused WO appears, candidate alternatives shown, reassignment works
- [ ] OEE numbers visible on the dashboard match `OeeService::dailySummary` (cross-checked against unit-test fixtures)

When all boxes pass, commit `chore: sprint 6 complete` and tag `sprint-6-done`. Sprint 7 begins with Quality (incoming/in-process/outgoing) clipping onto the chain we just built.
