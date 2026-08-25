# M051 — Production Work Orders Audit Report

**Audit date:** 2026-08-25  
**Auditor:** Codex  
**Claim:** `manufacturing/production-work-orders`  
**Final status:** 📋 Plan Ready  
**Scope:** API module, persistence constraints, permissions/resources, SPA work-order flows, and the module's existing production tests.

## Session summary

This audit re-checked the module after the registry identified M051 as the only unlocked `Needs Re-audit` candidate. The implementation has several useful safeguards—transactional lifecycle mutations, row locks around the primary work order, durable output idempotency, server-side output totals, and hashed IDs in most resources—but the remaining issues cross lifecycle, inventory traceability, routing execution, and UI/API contract boundaries.

The issues are not a safe same-session patch set. Several require a product/policy decision (especially machine ownership, exception authorization, and reservation/lot semantics), and the fixes span services, migrations, resources, controllers, and the SPA. The module is therefore left **Plan Ready** with no production-code fixes in this session.

## Verification

- `docker compose run --rm api vendor/bin/phpunit tests/Feature/Production` — **PASS**, 35 tests / 131 assertions.
- `npm run typecheck` in `spa/` — **PASS**.
- `npm run test:run -- src/pages/responsive-detail-tables.test.ts` — **BLOCKED before collection** by `EACCES` opening `spa/node_modules/.vite-temp/vitest.config.ts.timestamp-…mjs`; the dependency directory is owned by `root:root` and was not modified.
- No production source or test files were changed during this audit.

## What is working well

- Work-order lifecycle mutations and output recording use database transactions and lock the primary work-order row (`api/app/Modules/Production/Services/WorkOrderService.php:149-202`, `WorkOrderOutputService.php:144-185`).
- Output idempotency is backed by a unique `(work_order_id, idempotency_key)` constraint (`api/database/migrations/0466_add_work_order_output_idempotency.php:17-23`).
- Standard work orders cannot start without a material plan, while explicitly classified non-standard work orders require a reason (`WorkOrderService.php:631-644`, `StoreWorkOrderRequest.php:33-46`).
- Most API resources use HashID values and decimal strings for quantities and money (`api/app/Modules/Production/Resources/WorkOrderResource.php:18-26`, `WorkOrderOutputResource.php:16-46`).

## Findings

### Broken

#### M051-B01 — Lifecycle transition policy is coupled to an HTTP exception

`WorkOrderService::assertTransition()` throws `IllegalLifecycleTransitionException`, which extends `HttpResponseException` (`api/app/Modules/Production/Services/WorkOrderService.php:623-628`, `api/app/Modules/Production/Exceptions/IllegalLifecycleTransitionException.php:10-17`). A domain/service mutation therefore owns response formatting and status code behavior. This makes non-HTTP callers, jobs, and future integrations depend on controller transport semantics.

**Impact:** lifecycle rules cannot be reused cleanly and exception handling can diverge between request paths.  
**Classification:** Broken.

#### M051-B02 — Starting a work order attributes material issue activity to the creator

`WorkOrderService::start()` passes the work-order creator (or `created_by`) to `issueReservedMaterials()` instead of the authenticated actor starting the order (`api/app/Modules/Production/Services/WorkOrderService.php:312-379`, especially `355-358`). The service does not receive an actor identifier from `WorkOrderController::start()` (`api/app/Modules/Production/Controllers/WorkOrderController.php:186-196`).

**Impact:** stock movement/audit attribution can be wrong, weakening traceability and accountability.  
**Classification:** Broken.

#### M051-B03 — Machine ownership is not enforced across pause, resume, and complete

`start()` accepts an already-running machine and overwrites its `current_work_order_id` (`WorkOrderService.php:327-343`). `pause()`, `resume()`, and `complete()` then update the locked machine without consistently verifying that the machine is currently assigned to this work order (`WorkOrderService.php:381-515`). `cancel()` contains such a check, showing the missing guard is inconsistent (`WorkOrderService.php:539-581`).

**Impact:** one work order can clear or replace another order's active machine assignment, producing incorrect execution state and downtime history.  
**Classification:** Broken.

#### M051-B04 — Manual receipt fallback returns stale output state

When receipt handoff fails, `recordOutput()` marks the persisted output as manual-required through a separately loaded row, then returns/events the original `$output` instance without refreshing it (`api/app/Modules/Production/Services/WorkOrderOutputService.php:247-284`, `markProductionReceiptManual()` at `362-376`).

**Impact:** the API response and SPA success path can report `not_started` even though the durable row is `manual_required`, hiding an operator action.  
**Classification:** Broken.

#### M051-B05 — Material-lot capture is not tied to the reserved/issued stock

`captureMaterialLotReferences()` selects the latest GRN item with a lot number for each material, regardless of reservation, location, or the actual issue (`api/app/Modules/Production/Services/WorkOrderService.php:283-310`). It records the BOM quantity as `quantity_used`. The inventory input contract has no lot field (`api/app/Modules/Inventory/Models/StockMovementInput.php:14-28`), while the issue service supports optional lot stamping (`api/app/Modules/Inventory/Services/MaterialIssueService.php:125-131`).

**Impact:** the work order can claim a lot and quantity that were never consumed, breaking the supplier-lot → issue → batch trace chain.  
**Classification:** Broken.

#### M051-B06 — Reserved-material issue silently becomes a no-op when reservations are absent

`issueReservedMaterials()` only loads rows in `ReservationStatus::Reserved`; when none exist, it creates no `MaterialIssue` stock movement and still allows the work order start path to continue (`api/app/Modules/Production/Services/WorkOrderService.php:914-960`). The historical system audit already identified this best-effort behavior as a production traceability risk (`docs/SYSTEM-AUDIT-FINDINGS-2026-08-13.md:355-370`).

**Impact:** production can start without a durable material issue even though the standard work-order path represents material consumption.  
**Classification:** Broken.

#### M051-B07 — Operation output can exceed the planned operation quantity

`WoOperationService::recordOutput()` accumulates completed and scrapped quantities with no check against `qty_planned` (`api/app/Modules/Production/Services/WoOperationService.php:197-224`). The controller only validates positive quantity and `scrap <= qty` (`api/app/Modules/Production/Controllers/WoOperationController.php:154-170`).

**Impact:** operation-level progress can exceed the work-order routing plan and feed invalid completion reporting.  
**Classification:** Broken.

#### M051-B08 — Defect rows are accepted when reject quantity is zero

`RecordOutputRequest` requires defects only when `reject_qty > 0` (`api/app/Modules/Production/Requests/RecordOutputRequest.php:25-55`), and the service checks the defect sum only inside the same condition (`WorkOrderOutputService.php:86-115`). Positive defect rows can therefore be persisted with zero rejected quantity.

**Impact:** defect totals and output totals can disagree, weakening QC and yield reporting.  
**Classification:** Broken.

#### M051-B09 — Skipping an operation bypasses the promised operation transition log

`WoOperationService` documents that every transition is logged, but `skipOperation()` updates status and notes without calling `log()` (`api/app/Modules/Production/Services/WoOperationService.php:20-25,266-274`).

**Impact:** a material routing decision has no corresponding operation history or actor/reason record.  
**Classification:** Broken.

### Missing / incomplete

#### M051-M01 — Routing operations are not generated in the work-order creation flow

`WoOperationService::generateFromRouting()` exists and creates operations from an active routing (`api/app/Modules/Production/Services/WoOperationService.php:42-73`), but source inspection found no application caller from `WorkOrderService::createDraft()` or its controller. A work order can therefore be created without the operations that the execution UI and operation API expect.

**Classification:** Missing.

#### M051-I01 — Exception authorization is presence-checked, not independently authorized

The request accepts `exception_authorized_by` as input and the migration only requires a non-null value for non-standard classes (`api/app/Modules/Production/Requests/StoreWorkOrderRequest.php:33-46`, `api/database/migrations/2026_08_13_210000_add_production_material_plan_contract.php:9-22`). There is no rule that the named user has the required authority, nor a server-side actor/approval workflow.

**Classification:** Incomplete; requires a policy decision for approver role and separation of duties.

#### M051-I02 — API resources expose raw internal IDs in traceability fields

`WorkOrderResource` exposes raw `exception_authorized_by` and child `product_id`, while `WorkOrderOutputResource` returns `material_lineage` without transforming its embedded IDs (`api/app/Modules/Production/Resources/WorkOrderResource.php:18-57`, `WorkOrderOutputResource.php:16-46`). This conflicts with the project HashID convention (`docs/PATTERNS.md:442-458`).

**Classification:** Incomplete.

#### M051-I03 — Restore bypasses the work-order service and explicit audit path

`WorkOrderController::restore()` directly calls `$workOrder->restore()` (`api/app/Modules/Production/Controllers/WorkOrderController.php:137-140`) rather than using a transactional service operation with authorization and a lifecycle/audit event.

**Classification:** Incomplete.

#### M051-I04 — Work-order class and exception data are not represented in the SPA create contract

The API request supports `standard`, `service`, `non_stock`, and `prototype`, but `spa/src/pages/production/work-orders/create.tsx:32-43,87-98` only submits the base fields. The page's own copy describes manual/sample/R&D work without exposing the server-side material-plan classification or reason.

**Classification:** Incomplete.

#### M051-I05 — Output idempotency is not stable across manual retries

The output page creates the idempotency key inside the mutation function using the current timestamp and random suffix (`spa/src/pages/production/work-orders/record-output.tsx:90-101`). A retry of the same operator submission is consequently a new business event rather than a replay of the original request.

**Classification:** Incomplete.

#### M051-I06 — Operation commands do not enforce the parent work-order lifecycle

Setup, start, pause/resume, record-output, complete, and skip methods validate operation-local state but do not require the parent work order to be `in_progress` (`api/app/Modules/Production/Services/WoOperationService.php:80-274`). The operation routes also expose a direct `{operation}` resource without a nested parent context (`api/app/Modules/Production/routes.php:83-95`).

**Classification:** Incomplete.

#### M051-I07 — Operation execution is not exposed from the work-order detail page

The detail page only fetches and renders a read-only operations table (`spa/src/pages/production/work-orders/detail.tsx:482-539`), despite the routing API exposing mutation methods (`spa/src/api/production/routings.ts:24-44`). There are no setup/start/pause/record/complete/skip controls or contextual mutation feedback.

**Classification:** Incomplete.

#### M051-I08 — Production permissions and UI capabilities are not aligned

The registry lists `system_admin`, `production_manager`, and `ppc_head` for M051. `ppc_head` receives create/confirm and view permissions but not lifecycle or output-record permissions (`api/database/seeders/RolePermissionSeeder.php:530-583`). The expected planner-versus-executor boundary should be documented and covered by authorization tests, rather than inferred from the page behavior.

**Classification:** Incomplete; confirm intended role policy before changing grants.

### Polish

#### M051-P01 — Operation table needs responsive and state-aware presentation

The operation table is a direct wide table without an overflow wrapper or explicit loading/empty/error treatment (`spa/src/pages/production/work-orders/detail.tsx:501-538`). The page already uses opaque semantic warning styling elsewhere (`:268-270`), while the design system requires opaque surfaces (`docs/DESIGN-SYSTEM.md:15,100-102`).

**Classification:** Polish.

## Standards and policy checks

- API identifier convention: several child/lineage fields still use raw integer IDs; normalize them through the existing HashID resource pattern.
- Domain boundary: replace HTTP-specific lifecycle exceptions with a domain/application exception mapped at the HTTP boundary.
- Traceability: material issue, lot, and actor must be one durable chain; inferred “latest GRN lot” references are not sufficient evidence.
- Authorization: the exception approver must be independently authorized and the actor performing lifecycle/material mutations must be explicit.
- UI contract: create and execution pages need to represent the API's material-plan and operation state, including stable idempotency and mutation feedback.

## Session disposition

No production-code fixes were made. The module is **Plan Ready** because the majority of findings require coordinated API/domain/inventory/UI changes or an explicit policy decision. The action plan records dependencies, scope, and a re-audit gate.
