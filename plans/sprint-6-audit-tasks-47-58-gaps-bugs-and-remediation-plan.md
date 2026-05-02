# Sprint 6 (Tasks 47–58) — Audit Report and Remediation Plan

> Branch context: `main` (commit history shows the 12 Sprint 6 commits per [`plans/SPRINT-6-STATUS.md`](plans/SPRINT-6-STATUS.md:11)).
> Sources of truth used for the audit: [`CLAUDE.md`](CLAUDE.md:1), [`docs/PATTERNS.md`](docs/PATTERNS.md:1), [`docs/DESIGN-SYSTEM.md`](docs/DESIGN-SYSTEM.md:1), [`docs/TASKS.md`](docs/TASKS.md:177), and the original sprint plan [`plans/ogami-erp-sprint-6-order-to-cash-part-1-crm-mrp-production-tasks-47-58.md`](plans/ogami-erp-sprint-6-order-to-cash-part-1-crm-mrp-production-tasks-47-58.md:1).

---

## 0. Audit verdict

Sprint 6 ships the **happy-path skeleton** of all 12 tasks — migrations, models, services, controllers, routes, frontend pages and Reverb wiring are all there. The system can demo CRM → MRP → Production end-to-end. However it has **eight functional bugs** (mostly in the integration with Sprint 5 inventory and the chain-cancellation paths), **fifteen documented but unimplemented sub-features** (mostly listeners, events, FormRequests, and detail pages), and **two design-system / hash-id violations**. Tests for the sprint were skipped entirely (status doc admits this).

Severity legend used below: **P0** — silently corrupts data or blocks a chain. **P1** — chain still works but rules in [`CLAUDE.md`](CLAUDE.md:507) are violated. **P2** — surface polish, missing FormRequest, missing detail page, etc.

---

## 1. Backend — bugs (must fix)

### 1.1 [P0] Stock reservations / issues / releases are not wired into the WO lifecycle

[`WorkOrderService::confirm()`](api/app/Modules/Production/Services/WorkOrderService.php:167), [`start()`](api/app/Modules/Production/Services/WorkOrderService.php:193), and [`cancel()`](api/app/Modules/Production/Services/WorkOrderService.php:318) all carry `// TODO Sprint 5` comments where the integration with [`StockMovementService`](api/app/Modules/Inventory/Services/StockMovementService.php:1) is supposed to live. Net effect:

- `confirm` does not call `StockMovementService::reserve()` — two confirmed WOs can over-allocate the same on-hand stock.
- `start` does not issue materials via `StockMovementService::issueFromReservation()` — `work_order_materials.actual_quantity_issued` stays 0 and no `material_issue_slip` is created.
- `cancel` does not release the reservation — cancelled WOs leave inventory permanently locked.

This is the central operational bug: the Procure→Pay → Production data flow is broken. Plan §0 Decision 5 explicitly mandated these calls.

**Fix:** wire the three methods (idempotent at the WO level so re-running is safe), inside the existing `DB::transaction()` blocks, with `lockForUpdate` on the relevant `stock_levels` rows. Add `Feature/WorkOrderConfirmReservesMaterialsTest.php` and `WorkOrderStartIssuesMaterialsTest.php`.

### 1.2 [P0] Cancelling a confirmed Sales Order does not cascade

[`SalesOrderService::cancel()`](api/app/Modules/CRM/Services/SalesOrderService.php:227) flips `status='cancelled'` and writes a note, but its `// TODO Sprint 6 Task 52` comment betrays the missing cascade: linked `mrp_plans` aren't superseded, draft/confirmed `work_orders` aren't cancelled, and reservations aren't released.

**Fix:** inside the cancel transaction, mark the active `MrpPlan` as `cancelled`, iterate `$so->workOrders` and call `WorkOrderService::cancel()` (must succeed once 1.1 lands), and delegate reservation release through the same path. Cover with `Feature/SalesOrderCancelCascadeTest.php`.

### 1.3 [P0] Frontend leaks raw integer FK in `WorkOrderOutputResource`

[`WorkOrderOutputResource::toArray()`](api/app/Modules/Production/Resources/WorkOrderOutputResource.php:16) emits `'work_order_id' => $this->work_order_id` — the raw integer PK. This violates [`CLAUDE.md`](CLAUDE.md:124) URL-ID Obfuscation rule and the [`docs/PATTERNS.md`](docs/PATTERNS.md:443) Resource pattern. Trivially exploitable for ID enumeration on the outputs endpoint.

**Fix:** drop the field (the parent `WorkOrder` is always known by the caller) or replace with `'work_order' => $this->whenLoaded('workOrder', fn () => ['id' => $this->workOrder->hash_id, 'wo_number' => $this->workOrder->wo_number])`.

### 1.4 [P1] MRP engine does not lock `stock_levels` on read

[`MrpEngineService::runForSalesOrder()`](api/app/Modules/MRP/Services/MrpEngineService.php:124) reads `on_hand` and `reserved` with plain `sum()` queries. Plan §0 mandated `lockForUpdate()` on the per-item rows so two concurrent SO confirmations can't race the same available stock. At thesis scale this rarely fires, but the contract is wrong.

**Fix:** wrap the two `StockLevel` aggregates in a `lockForUpdate()` chain ordered by `(item_id, location_id)`. Add `Feature/ConcurrentMrpRunTest.php` to demonstrate.

### 1.5 [P1] MRP engine clamps lead time to 14 days

[`MrpEngineService::effectiveLeadTime()`](api/app/Modules/MRP/Services/MrpEngineService.php:285) returns `max(14, max($supplierLT, $itemLT))`. The plan called for `max($supplierLT, $itemLT)` with **no minimum** — falling back to 14 days only when both are zero. As written, an item with a 3-day rush supplier still gets ordered 14 days early, which inflates `is_urgent` flagging and creates noisy auto-PRs.

**Fix:** change to `max($supplierLT, $itemLT) ?: 14`. Add `Unit/MrpDateMathTest.php` covering the three branches.

### 1.6 [P1] Mold shot increment is not row-locked

[`WorkOrderOutputService::record()`](api/app/Modules/Production/Services/WorkOrderOutputService.php:117) holds a `lockForUpdate` on the work order but reads the mold via plain `Mold::find()` before delegating to `MoldService::incrementShots()`. Two simultaneous output recordings on the same mold can lose a shot. Plan §0 specifies the mold row must be locked inside the same transaction.

**Fix:** call `Mold::lockForUpdate()->find($wo->mold_id)` and pass the locked instance to `MoldService::incrementShots()`. Cover with `Unit/MoldShotIncrementConcurrencyTest.php` using `Concurrency::run()`.

### 1.7 [P1] Broadcast events for the dashboard are missing

Per the plan and [`plans/SPRINT-6-STATUS.md`](plans/SPRINT-6-STATUS.md:97) the following events must broadcast on `production.dashboard`:

| Event | Status |
|---|---|
| [`WorkOrderOutputRecorded`](api/app/Modules/Production/Events/WorkOrderOutputRecorded.php:1) | exists ✓ |
| [`MachineStatusChanged`](api/app/Modules/MRP/Events/MachineStatusChanged.php:1) | exists ✓ |
| `WorkOrderStatusChanged` | **missing** |
| `MachineBreakdownDetected` | missing (status doc mentions it implicitly) |
| `MoldShotLimitNearing` | **missing** (referenced in plan §0 Decision 6) |
| `MoldShotLimitReached` | **missing** |
| `MrpPlanGenerated` | **missing** (engine never dispatches) |
| `SalesOrderConfirmed` | **missing** |

The dashboard subscribes to `.machine.status_changed` and `.output.recorded` only ([`spa/src/pages/production/dashboard.tsx`](spa/src/pages/production/dashboard.tsx:31)). MRP-plan creation, mold near-limit alerts, and SO-confirmed pulses simply never reach the UI.

**Fix:** create the six event classes implementing `ShouldBroadcast`, dispatch them from the corresponding services ([`MrpEngineService`](api/app/Modules/MRP/Services/MrpEngineService.php:1), [`WorkOrderService`](api/app/Modules/Production/Services/WorkOrderService.php:1) lifecycle methods, [`MoldService::incrementShots`](api/app/Modules/MRP/Services/MoldService.php:1), [`SalesOrderService::confirm`](api/app/Modules/CRM/Services/SalesOrderService.php:203)), and add the matching `useEcho` listeners in [`spa/src/pages/production/dashboard.tsx`](spa/src/pages/production/dashboard.tsx:1).

### 1.8 [P1] Breakdown listener does not notify maintenance / PPC

[`HandleMachineBreakdown::handleEnteringBreakdown()`](api/app/Modules/Production/Listeners/HandleMachineBreakdown.php:55) pauses the running WO but the notification block is a `// TODO`. Plan Task 56 mandated `NotificationService` notifications to Maintenance Head and PPC Head with a candidate-machine list. As written, breakdown is silent: dashboard's [`BreakdownAlertCard`](spa/src/components/production/BreakdownAlertCard.tsx:1) shows a chip but no in-app notification or email is queued.

**Fix:** call `NotificationService::send()` for both recipients, supplying the candidate-machine query already documented in the inline comment. Add `Feature/MachineBreakdownNotifiesTest.php`.

---

## 2. Backend — missing pieces (low-risk gaps)

| # | Missing | Where it should live | Impact |
|---|---|---|---|
| 2.1 | `MoldHistoryService` | `app/Modules/MRP/Services/MoldHistoryService.php` | No append-only writer for `mold_history`; rows get inserted ad-hoc (zero rows in practice) |
| 2.2 | `Listeners/InvalidateProductionDashboardCache` | per Sprint plan §Task 58 | Dashboard endpoint claims a 30s Redis cache (status doc); without invalidation listener the cache will be stale for up to 30s after any mutation |
| 2.3 | `Jobs/ProcessMrpRunJob` | per Sprint plan §Task 52 | Async fallback for runs >5s; documented escape hatch absent |
| 2.4 | `DefectTypeController` + `DefectTypeResource` + admin route | per Sprint plan §Task 51 | Defect master-data is hard-seeded; can't be edited from the UI |
| 2.5 | `OeeResultResource` | per Sprint plan §Task 57 | `OeeService::calculate()` returns raw arrays; controller returns them through an ad-hoc shape — fine but inconsistent |
| 2.6 | `RescheduleModal.tsx` (frontend) and the corresponding "Suggest alternative machine" payload from `HandleMachineBreakdown` | spa + listener | Reassign-on-breakdown flow has no UI |
| 2.7 | `WorkOrderMaterialResource`, `WorkOrderDefectResource`, `MachineDowntimeResource`, `ProductionScheduleResource` | per Sprint plan §Task 51 | Currently inlined inside `WorkOrderResource`; OK functionally, but breaks the "one Resource per model" PATTERNS rule |
| 2.8 | Typed FormRequests for the lifecycle endpoints | `Production/Requests/{Update,Start,Complete,List}WorkOrderRequest.php`, `MRP/Requests/{UpdateBom,RerunMrpPlan}Request.php`, `MRP/Requests/{Reorder,Reassign}ScheduleRequest.php`, `CRM/Requests/{ListProducts,ListSalesOrders}Request.php` | Index controllers currently take raw `Request`; permission re-check via `authorize()` is therefore not happening on these endpoints — middleware is the only gate |
| 2.9 | `Events/SalesOrderConfirmed` (CRM module) | event broadcast | Dashboard cannot react in real-time to SO confirms |
| 2.10 | Tests for the entire sprint (24 files listed in plan §4) | `api/tests/...` | Status doc explicitly defers; no Sprint 6 unit/feature tests exist |

---

## 3. Frontend — missing pages, broken links, and deviations

### 3.1 [P0] Broken navigation links

The following links exist in shipped pages but the routes are not registered in [`spa/src/App.tsx`](spa/src/App.tsx:474):

| Link | Source | Target route | Status |
|---|---|---|---|
| `/mrp/machines/:id` | [`production/dashboard.tsx`](spa/src/pages/production/dashboard.tsx:150), [`production/work-orders/detail.tsx`](spa/src/pages/production/work-orders/detail.tsx:222) | machine detail | route + page missing |
| `/mrp/molds/:id` | [`production/work-orders/detail.tsx`](spa/src/pages/production/work-orders/detail.tsx:223) | mold detail | route + page missing |
| `/crm/sales-orders/:id/edit` | implied by detail page guard | SO edit | route + page missing |
| `/production/work-orders/create` | spec says supervisor sometimes creates manually | WO create | route + page missing |

Clicking these in the running app yields the 404 page.

**Fix:** add the four pages plus `App.tsx` route entries with the correct `PermissionGuard`/`ModuleGuard` wrappers per [`docs/PATTERNS.md`](docs/PATTERNS.md:1634) Section 21.

### 3.2 [P1] Missing detail-page right panel components

[`docs/PATTERNS.md`](docs/PATTERNS.md:1) Section 11 and the Sprint plan call for `<LinkedRecords>` and `<ActivityStream>` on every chain detail page. Reality:

- [`spa/src/pages/crm/sales-orders/detail.tsx`](spa/src/pages/crm/sales-orders/detail.tsx:1) — only `<ChainHeader>` is wired; no LinkedRecords (MRP plan, WOs, deliveries, invoice) and no ActivityStream.
- [`spa/src/pages/production/work-orders/detail.tsx`](spa/src/pages/production/work-orders/detail.tsx:1) — only `<ChainHeader>` is wired; right panel absent.

**Fix:** populate the right-panel container per the design system §Detail page layout. Both components already exist under [`spa/src/components/chain/`](spa/src/components/chain/index.ts:1) — only the page body needs to consume them.

### 3.3 [P1] Customer detail page never grew the new tabs

Plan §Task 47 called for two new tabs ("Price Agreements", "Sales Orders") on [`spa/src/pages/accounting/customers/detail.tsx`](spa/src/pages/accounting/customers/detail.tsx:1). Search for those strings returns zero matches. Customers are now fully isolated from the CRM data their details should expose.

**Fix:** add a `<TabList>` to the customer detail page consuming `priceAgreementsApi.forCustomer(customer.id)` and a paginated `salesOrdersApi.list({ customer_id })`.

### 3.4 [P2] Missing creation pages

| Page | Status |
|---|---|
| `pages/mrp/boms/create.tsx` | absent — BOMs are seed-only; UI cannot build a new one |
| `pages/crm/price-agreements/create.tsx` | absent — agreements are seed-only |
| `pages/mrp/machines/create.tsx` / `edit.tsx` | absent (plan calls for inline edit only — acceptable but no detail page) |
| `pages/mrp/molds/create.tsx` / `edit.tsx` | absent |
| `pages/production/work-orders/edit.tsx` | absent |

For thesis demo readiness the BOM and Price Agreement create flows are the most critical — the SO confirmation hard-blocks if no agreement exists for a (customer, product, date), and a PPC Head cannot publish a BOM for a new product through the UI.

### 3.5 [P2] Sidebar and routing minor gaps

- Sidebar ([`spa/src/components/layout/Sidebar.tsx`](spa/src/components/layout/Sidebar.tsx:122)) ships nav for the new Sprint 6 sections — verified ✓.
- The MRP module sidebar lists `MRP plans` first but [`App.tsx`](spa/src/App.tsx:476) also redirects `/mrp` → `/mrp/plans`. Consistent ✓.
- However the production module redirects `/production` → `/production/dashboard` ([`spa/src/App.tsx`](spa/src/App.tsx:497)), which requires `production.dashboard.view`; users with only `production.work_orders.view` see the access-denied page. Should redirect to `/production/work-orders` for those users (or use a permission-aware redirect helper).

---

## 4. Permissions — drift and dead entries

[`api/database/seeders/RolePermissionSeeder.php`](api/database/seeders/RolePermissionSeeder.php:145) was extended for Sprint 6 but the slug names drifted from the plan:

| Plan slug | Shipped slug | Notes |
|---|---|---|
| `production.work_orders.{start, pause, resume, complete, close, cancel}` | collapsed into `production.work_orders.lifecycle` | acceptable simplification, but should be documented in [`docs/SCHEMA.md`](docs/SCHEMA.md:1) or a permissions matrix |
| `production.outputs.record` | `production.wo.record` | renamed; consistent with route + seeder |
| `production.work_orders.{create, delete}` | `production.wo.create` | similar rename |
| `production.work_orders.confirm` | `production.wo.confirm` | similar rename |
| `mrp.plans.run` | `mrp.plans.run` | exists ✓; a duplicate `mrp.run` slug is also seeded but unused — dead |
| `production.machines.transition_status` | `production.machines.transition` | renamed; consistent |
| `production.schedule.reorder`, `production.schedule.reassign` | folded into `mrp.schedule` | scheduler routes use `mrp.schedule` for reorder/reassign |

None of these are bugs; they just create churn for any future contributor who reads the plan. Two action items:

1. Remove the dead `mrp.run` permission row (no route uses it).
2. Add a "Sprint 6 permission catalogue" subsection to [`docs/SCHEMA.md`](docs/SCHEMA.md:1) (or a new `docs/PERMISSIONS.md`) listing every Sprint 6 slug with a one-line role description, so the renames are documented.

---

## 5. Documentation gaps to close

Per Sprint plan §8 the following docs were supposed to be updated alongside Sprint 6 — checking what actually shipped:

| File | Required change | Status |
|---|---|---|
| [`docs/SCHEMA.md`](docs/SCHEMA.md:1) | append `mrp_plans` table; document SO/WO/machine/mold reconciliations | not verified — likely absent (no `mrp_plans` mention surfaced in earlier searches) |
| [`docs/TASKS.md`](docs/TASKS.md:1) | renumber Sprint 7 migrations to start at 0087 (was 0078) | not done — Sprint 7 tasks still cite their original numbers |
| [`.env.example`](docker/nginx/default.conf:1) | add `VITE_REVERB_*` and server-side Reverb keys | needs verification |
| [`docs/PATTERNS.md`](docs/PATTERNS.md:1) | no change required this sprint | n/a |

---

## 6. Migration-numbering note (resolved differently than plan)

Plan called for migrations 0062–0079 starting from Sprint 5's stated end (0061). Reality: Sprint 5 actually ended at 0068, so Sprint 6 migrations occupy **0069 → 0086**. The deferred FK pattern is preserved correctly — see [`api/database/migrations/0080_create_work_orders_table.php`](api/database/migrations/0080_create_work_orders_table.php:63) (adds `machines.current_work_order_id` FK) and [`api/database/migrations/0086_create_mrp_plans_table.php`](api/database/migrations/0086_create_mrp_plans_table.php:47) (adds the three deferred FKs to `sales_orders`, `work_orders`, `purchase_requests`). No corruption risk; the plan's number expectation is just stale.

The required Sprint 7 renumbering (Sprint 7 must now start at 0087) is the only documentation fallout.

---

## 7. Compliance against [`CLAUDE.md`](CLAUDE.md:507) "NEVER violate" rules

| Rule | Sprint 6 status |
|---|---|
| Every model gets `HasHashId` | ✓ all twelve new models use the trait |
| Every API Resource returns `hash_id`, never raw integer `id` | ❌ violated by [`WorkOrderOutputResource`](api/app/Modules/Production/Resources/WorkOrderOutputResource.php:16) — see §1.3 |
| Every financial operation wrapped in `DB::transaction()` | ✓ verified in `MrpEngineService`, `WorkOrderService`, `WorkOrderOutputService`, `SalesOrderService` |
| Every list page handles 5 states | ✓ verified for `crm/products`, `crm/price-agreements`, `crm/sales-orders`, `mrp/boms`, `mrp/machines`, `mrp/molds`, `mrp/plans`, `production/work-orders`, `production/dashboard`, `production/schedule` (loading skeleton + error empty-state + empty + data + `placeholderData` for stale) |
| Every form has Zod schema, disabled-while-pending, server-side error mapping, cancel button | ✓ verified for `crm/products/form.tsx`, `crm/sales-orders/create.tsx`, `production/work-orders/record-output.tsx` |
| Every mutation has toast + invalidateQueries | ✓ verified |
| Every page is `lazy()` loaded | ✓ all Sprint 6 pages in [`App.tsx`](spa/src/App.tsx:111) |
| Every route wrapped in `AuthGuard + ModuleGuard + PermissionGuard` | ✓ |
| Numbers always `font-mono tabular-nums` | ✓ verified across detail/list/dashboard pages |
| Status fields use `<Chip>` with semantic variant | ✓ verified |
| Never use Bearer tokens / localStorage for auth | ✓ Echo client uses `withCredentials: true` and `/api/v1/broadcasting/auth` cookie auth |

The only `CLAUDE.md` "NEVER violate" rule actually broken in Sprint 6 is the hash-id leak in §1.3.

---

## 8. Remediation execution plan (suggested order)

The fixes split cleanly into two PRs.

### PR A — "Sprint 6 hardening: data integrity + chain cancellation"  (P0 + critical P1)

Order matters: 8.1 must land before 8.2, and 8.5 must land before the events in 8.6 are dispatched, otherwise tests will hold open transactions.

1. **§1.3 hash-id leak** — drop / replace `work_order_id` in [`WorkOrderOutputResource`](api/app/Modules/Production/Resources/WorkOrderOutputResource.php:16). Add a regression test `Feature/WorkOrderOutputResourceTest.php`.
2. **§1.1 wire reservations** — confirm/start/cancel of `WorkOrderService` call into `StockMovementService::reserve()`, `::issueFromReservation()`, `::releaseReservation()`. Lock `stock_levels` rows. Tests: `Feature/WorkOrderConfirmReservesMaterialsTest.php`, `Feature/WorkOrderStartIssuesMaterialsTest.php`, `Feature/WorkOrderCancelReleasesMaterialsTest.php`.
3. **§1.2 SO cancel cascade** — supersede `MrpPlan`, cancel linked planned/confirmed WOs, release reservations through 1.1's plumbing. Test: `Feature/SalesOrderCancelCascadeTest.php`.
4. **§1.4 lock `stock_levels` in MrpEngine** — `lockForUpdate()` ordered by `(item_id)`. Test: `Feature/ConcurrentMrpRunTest.php`.
5. **§1.5 fix lead-time floor** — `max($supplierLT, $itemLT) ?: 14`. Test: `Unit/MrpDateMathTest.php`.
6. **§1.6 lock mold row** — replace `Mold::find()` with `Mold::lockForUpdate()->find()` inside the transaction. Test: `Unit/MoldShotIncrementConcurrencyTest.php`.
7. **§3.1 broken nav links** — add the four missing pages + routes (machine detail, mold detail, SO edit, WO create). At minimum stub pages that show `<EmptyState icon="construction" title="Coming soon" />` so production never 404s during demo.

### PR B — "Sprint 6 dashboard fidelity: events, listeners, panels, tabs"  (remaining P1 + P2)

1. **§1.7 dashboard events** — create `WorkOrderStatusChanged`, `MachineBreakdownDetected`, `MoldShotLimitNearing`, `MoldShotLimitReached`, `MrpPlanGenerated`, `SalesOrderConfirmed` (six classes implementing `ShouldBroadcast`). Dispatch from the right services. Subscribe in [`production/dashboard.tsx`](spa/src/pages/production/dashboard.tsx:1) and the WO/SO detail pages.
2. **§1.8 breakdown notifications** — wire `NotificationService::send()` calls in [`HandleMachineBreakdown`](api/app/Modules/Production/Listeners/HandleMachineBreakdown.php:72) for Maintenance Head and PPC Head with the candidate-machine snapshot.
3. **§3.2 right panels** — populate `<LinkedRecords>` and `<ActivityStream>` on SO and WO detail pages.
4. **§3.3 customer tabs** — extend [`accounting/customers/detail.tsx`](spa/src/pages/accounting/customers/detail.tsx:1) with Price Agreements + Sales Orders tabs.
5. **§3.4 missing creation pages** — at least `mrp/boms/create.tsx` and `crm/price-agreements/create.tsx`.
6. **§2.x backend missing pieces** — `MoldHistoryService`, `InvalidateProductionDashboardCache` listener, `ProcessMrpRunJob`, `DefectTypeController`, `OeeResultResource`. Add the missing typed FormRequests in §2.8 so server-side validation runs the same path on every endpoint.
7. **Cosmetics** — remove dead `mrp.run` permission, add the permission-matrix doc, fix `/production` redirect to be permission-aware.

### PR C — "Sprint 6 tests"  (was always going to be a separate PR per status doc §How to take this from here)

Implement the 24 tests enumerated in plan §4. Highest value first: `MrpEngineTest`, `WorkOrderLifecycleTest`, `OeeMathTest`, `SalesOrderConfirmTest`, `RecordOutputTest` (idempotency replay, defect-sum guard, mold shot increment), `CapacityPlanningTest`.

---

## 9. Risk and demo readiness

For the upcoming defense:

- **Demo will run today** if the operator never cancels an SO or a WO, never relies on stock reservations being correct, never clicks a "machine detail" link, and never tries to add a price agreement from the UI. All of these break the demo silently or visibly.
- **Demo will run safely after PR A**. Reservation correctness is the operational story of Sprint 6's chain — fixing §1.1 / §1.2 makes the chain demonstrable end-to-end.
- **Demo will be polished after PR B**. Without it the dashboard works for output recording (the most-watched event) but feels stale for SO confirms, MRP runs, and breakdowns.
- **PR C is for the thesis defense panel** — they will ask "what's your test coverage". A green PHPUnit run with the 24 tests is the answer.

---

## 10. Files inventory (everything PR A + PR B will touch)

### PR A — backend
- [`api/app/Modules/Production/Resources/WorkOrderOutputResource.php`](api/app/Modules/Production/Resources/WorkOrderOutputResource.php:1) — modify
- [`api/app/Modules/Production/Services/WorkOrderService.php`](api/app/Modules/Production/Services/WorkOrderService.php:167) — modify (confirm/start/cancel)
- [`api/app/Modules/CRM/Services/SalesOrderService.php`](api/app/Modules/CRM/Services/SalesOrderService.php:227) — modify (cancel cascade)
- [`api/app/Modules/MRP/Services/MrpEngineService.php`](api/app/Modules/MRP/Services/MrpEngineService.php:1) — modify (lock + lead-time)
- [`api/app/Modules/Production/Services/WorkOrderOutputService.php`](api/app/Modules/Production/Services/WorkOrderOutputService.php:117) — modify (lock mold)
- 5 new test files under [`api/tests/`](api/tests:1)

### PR A — frontend
- `spa/src/pages/mrp/machines/detail.tsx` — create
- `spa/src/pages/mrp/molds/detail.tsx` — create
- `spa/src/pages/crm/sales-orders/edit.tsx` — create
- `spa/src/pages/production/work-orders/create.tsx` — create
- [`spa/src/App.tsx`](spa/src/App.tsx:451) — modify (4 new routes)

### PR B — backend
- 6 new event classes under `api/app/Modules/{CRM,MRP,Production}/Events/`
- Dispatches inside [`MrpEngineService`](api/app/Modules/MRP/Services/MrpEngineService.php:1), [`WorkOrderService`](api/app/Modules/Production/Services/WorkOrderService.php:1), [`MoldService`](api/app/Modules/MRP/Services/MoldService.php:1), [`SalesOrderService`](api/app/Modules/CRM/Services/SalesOrderService.php:1)
- [`api/app/Modules/Production/Listeners/HandleMachineBreakdown.php`](api/app/Modules/Production/Listeners/HandleMachineBreakdown.php:72) — wire NotificationService
- `api/app/Modules/MRP/Services/MoldHistoryService.php` — create
- `api/app/Modules/Production/Listeners/InvalidateProductionDashboardCache.php` — create
- `api/app/Modules/MRP/Jobs/ProcessMrpRunJob.php` — create
- `api/app/Modules/Production/Controllers/DefectTypeController.php` + `Resources/DefectTypeResource.php` + route line — create
- `api/app/Modules/Production/Resources/OeeResultResource.php` + 4 inline-resource extractions — create
- 8 missing FormRequests across CRM/MRP/Production — create
- [`api/database/seeders/RolePermissionSeeder.php`](api/database/seeders/RolePermissionSeeder.php:1) — remove dead `mrp.run` slug

### PR B — frontend
- [`spa/src/pages/crm/sales-orders/detail.tsx`](spa/src/pages/crm/sales-orders/detail.tsx:1) — add LinkedRecords + ActivityStream
- [`spa/src/pages/production/work-orders/detail.tsx`](spa/src/pages/production/work-orders/detail.tsx:1) — add LinkedRecords + ActivityStream
- [`spa/src/pages/accounting/customers/detail.tsx`](spa/src/pages/accounting/customers/detail.tsx:1) — add Price Agreements + Sales Orders tabs
- `spa/src/pages/mrp/boms/create.tsx` — create
- `spa/src/pages/crm/price-agreements/create.tsx` — create
- [`spa/src/pages/production/dashboard.tsx`](spa/src/pages/production/dashboard.tsx:1) — add 4 more `useEcho` subscriptions
- `spa/src/components/production/RescheduleModal.tsx` — create
- [`spa/src/App.tsx`](spa/src/App.tsx:497) — permission-aware redirect for `/production`

### Documentation
- [`docs/SCHEMA.md`](docs/SCHEMA.md:1) — append `mrp_plans` schema and Sprint 6 reconciliations
- [`docs/TASKS.md`](docs/TASKS.md:218) — renumber Sprint 7 migrations to start at 0087
- new: `docs/PERMISSIONS.md` — Sprint 1–6 permission matrix

---

## 11. Out of scope for the audit (deliberately deferred)

- Anything Sprint 7+ (Quality, Deliveries, Invoicing chain).
- Replacing `frappe-gantt` — works fine, just thinly tested.
- Hardening the OEE math beyond the documented edge cases — unit tests will cover what the panel asks about.
- Customizable dashboards / saved views — explicitly cut scope per [`CLAUDE.md`](CLAUDE.md:73).

---

## 12. Quick checklist before declaring "Sprint 6 done for real"

- [ ] §1.1 reservations land on confirm; integration test green
- [ ] §1.2 SO cancel cascades; integration test green
- [ ] §1.3 hash-id leak fixed; resource test green
- [ ] §1.4 stock_levels locked in MRP run
- [ ] §1.5 lead-time floor removed
- [ ] §1.6 mold lockForUpdate inside output transaction
- [ ] §1.7 six event classes shipped + dispatched + subscribed
- [ ] §1.8 breakdown notifications fire to maintenance + PPC heads
- [ ] §3.1 four broken-link routes register and render
- [ ] §3.2 LinkedRecords + ActivityStream on SO and WO detail
- [ ] §3.3 customer detail Price Agreements + Sales Orders tabs
- [ ] §3.4 BOM create + Price Agreement create flows present
- [ ] §2 backend missing pieces (MoldHistoryService, cache listener, defect-type CRUD, FormRequests) all land
- [ ] PR C tests (24 files) green; coverage of the new services ≥ 80%
- [ ] [`docs/SCHEMA.md`](docs/SCHEMA.md:1) and [`docs/TASKS.md`](docs/TASKS.md:1) updated
- [ ] `make fresh && make seed` end-to-end success unchanged
