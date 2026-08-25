# M049 — BOM / MRP Planning Audit Report

Audit session: 2026-08-24 UTC  
Final session status: 📋 Plan Ready  
Scope: BOM authoring/versioning/costing, BOM explosion, MRP plans, shortage netting, automatic/manual MRP runs, and their direct plan-created PR/WO records.

The adjacent capacity-scheduling, machine, and mold surfaces were read only for boundary context and were not audited or changed; those belong to M050.

## Discovery and verification

- Backend surface: `api/app/Modules/MRP/routes.php:20-29,64-83`, BOM services/resources, MRP engine/run services, migrations, and MRP feature tests.
- Frontend surface: `spa/src/routes/mrpRoutes.tsx:22-59`, BOM list/detail/create/edit, and MRP plan list/detail pages.
- Relevant dependencies read only: sales orders, inventory items/stock, production routing costs, purchase requests, work-order draft creation, RBAC, outbox, design-system, and roadmap policy.
- Focused container verification: `docker compose exec -T api php artisan test tests/Feature/MRP` — **67 passed, 184 assertions**.
- The same command from the host failed before assertions because the host PHP process could not resolve Docker hostname `db`; the containerized result is the authoritative run.

The existing suite has good positive coverage for BOM costing/UOM conversion, routing cost refresh, cycle detection, multilevel explosion, netting, quarantine/scrap exclusion, rerun reconciliation, automation scoping, and subassembly work orders. It does not cover the negative and contract cases listed below.

## Verified strengths

- BOM creation/versioning and costing are transaction-wrapped in `api/app/Modules/MRP/Services/BomService.php:84-110` and `api/app/Modules/MRP/Services/BomCostingService.php:28-34`.
- Costing uses the shared Money/BCMath path for the main snapshot and supports UOM conversion, nested BOM roll-up, routing rates, and batch-allocated setup time; the focused costing tests pass.
- MRP reruns reconcile draft auto-PRs and planned WOs rather than duplicating progressed records; `MrpRerunSafetyTest` passes.
- Stock netting excludes quarantine/scrap and accounts for reservations, in-transit supply, open PRs, and shared allocation; the demand-integrity tests pass.
- Backend route middleware exists for BOM view/manage, plan view/rerun, and run view/trigger. The role assignment and some detail-page controls do not consistently match those boundaries.

## Findings

### Broken

#### M049-B01 — Standard MRP work orders can be created without a BOM/material plan

Evidence: `api/app/Modules/MRP/Services/MrpEngineService.php:128-141` records a missing-BOM warning and skips explosion, but `:368-455` still creates a root WO for every remaining SO line. The call at `:444-454` omits `work_order_class`, `exception_reason`, and `material_plan_source`. `api/app/Modules/Production/Services/WorkOrderService.php:169-185` defaults the WO to `standard`, leaves the material source null, and intentionally permits the no-BOM path. This conflicts with the no-BOM policy in `docs/SYSTEM-IMPROVEMENT-ROADMAP-2026-08-13.md:267-269`, which permits no-BOM production only for an explicit service, non-stock, or prototype class with an authorized reason and visible exception.

Impact: an ordinary stock-producing SO can produce a planned standard WO with no effective material plan while the MRP plan only displays a warning. The downstream production queue can therefore contain uncosted/unmaterialized work.

#### M049-B02 — BOM/component lifecycle drift can silently under-plan or plan inactive material

Evidence: costing rejects a missing or inactive component in `api/app/Modules/MRP/Services/BomCostingService.php:53-65`, but the explosion path in `api/app/Modules/MRP/Services/BomService.php:488-503` still accumulates a BOM row even when its related item is absent. MRP netting then uses `Item::find()` and silently continues on null at `api/app/Modules/MRP/Services/MrpEngineService.php:216-219`; it does not reject an inactive item. A soft-deleted component can disappear from shortage calculation, while an inactive component can still become a shortage/PR.

Impact: the costing and planning paths disagree about whether the BOM is valid. A deleted component can be omitted from demand; an inactive component can drive purchasing despite the BOM no longer being valid for authoring.

#### M049-B03 — BOM-change replanning is not atomic with the BOM mutation

Evidence: `BomService::create()` commits its transaction at `api/app/Modules/MRP/Services/BomService.php:84-110`, then calls `requestAutomaticReplan()` at `:112`. That method records the event at `:164-175`. `OutboxService::record()` only joins an existing transaction and otherwise opens its own transaction at `api/app/Common/Services/OutboxService.php:59-61`.

Impact: a BOM version can commit successfully while the replan outbox insert fails or the process dies between the two commits. The active BOM and affected SO plans can then be inconsistent until a manual run occurs.

#### M049-B04 — Planning API responses expose raw integer foreign keys

Evidence: `api/app/Modules/MRP/Resources/MrpPlanResource.php:29-48` passes `diagnostics` and `cost_summary` through unchanged and returns `work_orders.product_id` directly. `MrpRunResource` also returns raw `summary` and `error_message` at `api/app/Modules/MRP/Resources/MrpRunResource.php:24-36`; the engine stores raw `so_id` values and exception text at `api/app/Modules/MRP/Services/MrpEngineService.php:590-607`. This violates the project contract in `CLAUDE.md:133-155` and `:568-575` that API resources never expose integer IDs.

Impact: planning responses leak internal identifiers and break the HashID API contract for diagnostics, linked work orders, and run history. The frontend types currently mirror the leak rather than correcting it.

#### M049-B05 — Production-manager MRP view access does not match the documented role boundary

Evidence: the role matrix documents production-manager as “MRP+quality view-only” in `docs/AUTO-BROWSER-TESTS.md:112-120`. The seeded role grants `mrp.view`, `mrp.schedule`, and `mrp.boms.view` but not `mrp.plans.view` or `mrp.runs.view` at `api/database/seeders/RolePermissionSeeder.php:530-538`. The plan routes require `mrp.plans.view` at `api/app/Modules/MRP/routes.php:64-70`, and `/mrp` redirects to `/mrp/plans` with the same permission guard at `spa/src/routes/mrpRoutes.tsx:25-29,55-58`.

Impact: a production manager can be sent to the MRP module but cannot review plans or run history, contrary to the role definition. This is an RBAC contract failure, not merely a frontend hiding issue.

### Missing

#### M049-M01 — Database invariant for one active BOM per product is missing

Evidence: the BOM migration creates only a `(product_id, is_active)` index and `(product_id, version)` uniqueness at `api/database/migrations/0073_create_bill_of_materials_table.php:18-27`. `BomService::create()` attempts to enforce one active row through application locking at `api/app/Modules/MRP/Services/BomService.php:84-97`, but there is no partial unique index equivalent to the MRP-plan guard in `api/database/migrations/2026_08_15_121000_guard_mrp_plan_versions.php:18-25`.

Impact: concurrent writers, imports, seeders, or direct model writes can create two active BOMs for one product. Planning/costing then selects whichever active row the query happens to return.

#### M049-M02 — Item standard-cost changes have no automatic BOM recost/replan path

Evidence: `ItemService::update()` only updates the item in `api/app/Modules/Inventory/Services/ItemService.php:98-113`; `standard_cost` is an editable field in `api/app/Modules/Inventory/Requests/UpdateItemRequest.php:39`. The application event wiring covers sales-order confirmation, stock movement, and explicit BOM replans at `api/app/Providers/AppServiceProvider.php:289-294`, while the BOM snapshot is computed from item standard cost in `api/app/Modules/MRP/Services/BomCostingService.php:78-106`.

Impact: an active BOM’s frozen total and a plan’s production-cost summary can remain stale after a component cost edit. The focused test proves manual recosting works, but there is no automatic freshness or affected-SO replan behavior.

### Incomplete

#### M049-I01 — MRP still uses float round-trip for monetary values

Evidence: the shared contract says “Never use float for money” in `api/app/Common/Support/Money.php:7-11`. MRP converts standard cost to float at `api/app/Modules/MRP/Services/MrpEngineService.php:246-248,267` and rounds the float before persisting the PR estimated price at `:330-341`. The main cost summary uses BCMath, so the implementation is inconsistent rather than uniformly unsafe.

Impact: binary floating-point conversion can produce edge-case rounding differences in diagnostics and auto-generated purchase-request prices. Financial values should remain decimal strings through the final Money/DB precision boundary.

#### M049-I02 — A run with failed sales orders is still marked `completed`

Evidence: per-SO exceptions are caught and recorded at `api/app/Modules/MRP/Services/MrpEngineService.php:565-608`, but the outer completion update always writes `MrpRunStatus::Completed` at `:612-620`. `MrpRunStatus` has no partial state (`running`, `completed`, `failed` only) in `api/app/Modules/MRP/Enums/MrpRunStatus.php:7-16`.

Impact: operators and integrations cannot distinguish “all evaluated successfully” from “some SOs failed and were skipped.” The summary contains an error, but the top-level lifecycle state is misleading and recovery is not explicit.

#### M049-I03 — Run errors are not redacted or paired with recovery guidance

Evidence: raw exception messages are stored in the per-SO summary and catastrophic `error_message` at `api/app/Modules/MRP/Services/MrpEngineService.php:597-607,622-628`, then returned by `MrpRunResource` at `api/app/Modules/MRP/Resources/MrpRunResource.php:31-36`. The roadmap requires an error class and operator-visible recovery instruction, with redacted error messages, at `docs/SYSTEM-IMPROVEMENT-ROADMAP-2026-08-13.md:564-581`.

Impact: SQL/domain internals can be shown to planning users, while the run history does not consistently tell them whether to correct data, retry, or escalate.

#### M049-I04 — Generated plan records lose the initiating actor and run context

Evidence: the manual controller passes the authenticated user into the run at `api/app/Modules/MRP/Controllers/MrpRunController.php:42-49`, and `MrpRun` stores it at `api/app/Modules/MRP/Services/MrpEngineService.php:540-547`. However, `runForSalesOrder()` accepts no actor or run identifier at `:69-78`; generated plans use the sales-order creator at `:197-203`, auto-PRs use the same creator at `:306-315`, and root WOs do so at `:444-454`. Automatic runs can therefore leave generated records attributed to the SO creator or no human actor, rather than the initiating user/system actor and originating run.

Impact: audit reviewers cannot reliably answer who initiated a manual recovery, which run produced a PR/WO, or which named system actor performed an automatic run. This falls short of the actor, correlation/run, and queued-work requirements in `docs/SYSTEM-IMPROVEMENT-ROADMAP-2026-08-13.md:564-580`.

### Polish

#### M049-P01 — BOM detail renders manage actions to view-only users

Evidence: `spa/src/pages/mrp/boms/detail.tsx:1-12` does not load `usePermission`, yet `:90-104` always renders recalculate, edit, restore, and archive controls. The route itself is correctly view-gated while edit is manage-gated at `spa/src/routes/mrpRoutes.tsx:28-35`.

Impact: view-only users see controls that predictably return 403s, creating avoidable error paths and an inconsistent permission experience.

#### M049-P02 — Plan re-run mutation has no local error toast

Evidence: `spa/src/pages/mrp/plans/detail.tsx:26-39` defines success handling for re-run but no `onError` handler. The project mutation pattern requires success and failure feedback in `CLAUDE.md:573-575`; the plans list already implements both paths at `spa/src/pages/mrp/plans/index.tsx:66-81`.

#### M049-P03 — A detail page violates the opaque-surface/table polish rules

Evidence: `MrpPlanDetailPage` uses translucent semantic backgrounds (`bg-danger-bg/5`, `bg-info-bg/5`, `bg-success-bg/5`) at `spa/src/pages/mrp/plans/detail.tsx:95-107`, and its warning row uses `colSpan={10}` while the table declares nine columns at `:132-151`. The design system requires opaque surfaces at `docs/DESIGN-SYSTEM.md:15-21,100-102`.

#### M049-P04 — BOM costing comment is stale after batch-size support was added

Evidence: `api/app/Modules/MRP/Services/BomCostingService.php:19-24` says setup time is intentionally excluded because no batch size is available, while the implementation allocates setup time using `cost_batch_size` at `:173-182` and the costing contract documents that behavior in `docs/testing/bom-costing.tdd.md:12-18`.

Impact: future maintainers may “fix” correct batch allocation or misread the cost basis during review.

## Open policy decision

The recalculate endpoint can recost an inactive, non-deleted BOM version (`api/app/Modules/MRP/Services/BomService.php:127-130`; route `api/app/Modules/MRP/routes.php:26`). Decide whether historical BOM cost snapshots are mutable for repair or immutable for audit/history. If immutable is the policy, restrict recosting to the active version and add a regression test.

## Test gaps to close with the action plan

Add focused coverage for: no-BOM standard WO rejection/exception classification; inactive and soft-deleted component handling; concurrent active-BOM uniqueness; BOM/outbox rollback atomicity; initiating actor/run propagation; HashID resource serialization; partial-run status and redacted recovery; production-manager plan/run access; and item-cost-change recost/replan behavior.
