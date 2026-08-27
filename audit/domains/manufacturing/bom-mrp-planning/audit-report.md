# M049 — BOM / MRP Planning Audit Report

Audit session: 2026-08-27 UTC
Claimed module: `manufacturing/bom-mrp-planning` (M049)
Final session status: 📋 Plan Ready
Scope: BOM authoring/versioning/costing, BOM explosion, MRP plans, shortage netting, automatic/manual MRP runs, and their direct plan-created PR/WO records.

The adjacent capacity-scheduling, machine, and mold surfaces were read only for boundary context and were not audited or changed; those belong to M050.

## Discovery evidence

- The fresh registry entry was read at `audit/00-MODULE-REGISTRY.md:22`; it was not regenerated or edited.
- `audit/scripts/claim-module.sh manufacturing bom-mrp-planning` returned `CLAIMED`. The preferred target was used; no fallback was needed.
- Before this session, the module had no implementation diff. `git status --short --branch` showed only the coordinator-owned modification to `audit/00-MODULE-REGISTRY.md`.
- Observed mtimes put the main MRP implementation at `2026-08-26 02:30:12`, the focused MRP fixture at `2026-08-27 06:59:56`, and the claim lock at `2026-08-27 19:18:24`; no module source changed after the claim.
- Read-only dependencies included CRM sales-order cancellation, inventory item updates, production routing costs, purchase requests, work-order draft creation, RBAC, outbox delivery, and the MRP SPA routes/pages.

## Verification evidence

- `docker compose exec -T -e DB_DATABASE=ogami_test_m049_agent_c api sh -lc 'cd /var/www && php artisan test tests/Feature/MRP'` — **67 passed, 184 assertions**.
- `docker compose exec -T -e DB_DATABASE=ogami_test_m049_agent_c api sh -lc 'cd /var/www && php artisan test tests/Feature/Auth/RoleResponsibilityAlignmentTest.php'` — **15 passed, 109 assertions**.
- `docker compose exec -T spa sh -lc 'cd /app && npm run typecheck'` — passed.
- `docker compose exec -T spa sh -lc 'cd /app && npm run audit:tokens'` — passed; 786 files checked.
- Targeted ESLint for MRP pages, the run-status panel, and MRP types — passed.
- `docker compose exec -T spa sh -lc 'cd /app && npm run test:run -- src/components/mrp/MrpRunStatusPanel.test.tsx'` — **2 passed**.
- `docker compose exec -T spa sh -lc 'cd /app && npm run build'` — passed; Vite emitted only the existing NotFound dynamic/static import warning.
- The unique test database confirms the active-BOM database guard: `bill_of_materials_one_active_per_product` is a partial unique index on `product_id` where `is_active = TRUE AND deleted_at IS NULL`.

All test commands used only `DB_DATABASE=ogami_test_m049_agent_c`.

## Current findings

### Broken

#### M049-B06 — MRP can commit work for a sales order cancelled during candidate selection

Evidence: `api/app/Modules/MRP/Services/MrpEngineService.php:607-613` reads the eligible sales orders once, outside the per-order transaction. The transaction in `:83-84` does not lock or re-read the sales order before creating the active plan at `:220-236`, auto-PR rows at `:332-342`, or root work orders at `:476-486`. The direct `runForSalesOrder()` entry point documents a confirmed-order precondition at `:69-79` but does not enforce it. Meanwhile, cancellation re-reads and locks the order at `api/app/Modules/CRM/Services/SalesOrderService.php:649-666`, then cancels the active plan and linked cancellable work orders at `:674-696`.

Impact: if cancellation commits after the batch candidate snapshot but before the MRP transaction creates its records, MRP can create a new active plan, draft PR, and planned WOs for a cancelled order after the cancellation cleanup has completed. Those commitments are orphaned from the cancellation flow. A direct service caller can also bypass the status precondition entirely.

Required decision: choose whether a cancelled order is skipped, recorded as a typed per-order failure, or rejected synchronously, then lock/re-read the order and enforce the selected policy in every entry path.

### Missing

#### M049-M02 — Item standard-cost changes still have no automatic affected-BOM recost/replan path

Evidence: `api/app/Modules/Inventory/Services/ItemService.php:98-113` updates an item without publishing a standard-cost-change event; `api/app/Modules/Inventory/Requests/UpdateItemRequest.php:39` permits `standard_cost` edits. The MRP event wiring at `api/app/Providers/AppServiceProvider.php:294-299` covers sales-order confirmation, stock movement, and explicit BOM replan requests, but not item cost changes. `BomCostingService::ensureFresh()` at `api/app/Modules/MRP/Services/BomCostingService.php:44-87` only repairs stale snapshots when a later planning call happens. BOM creation does request a replan inside its transaction at `api/app/Modules/MRP/Services/BomService.php:85-112`, but item updates do not enter that path.

Impact: active BOM snapshots and existing plan cost summaries can remain stale between the item edit and a later MRP run. The current opportunistic recost is not an automatic affected-sales-order replan contract.

#### M049-M03 — The hardening claims remain under-tested

Evidence: the current `api/tests/Feature/MRP` suite is positive-path coverage (67 tests) and has no focused assertions for the nine historical gap areas: no-BOM standard-WO policy, component lifecycle rejection, concurrent active-BOM uniqueness, BOM/outbox rollback, actor/run propagation, nested HashID serialization, partial/redacted recovery, production-manager plan/run access, or item-cost-change recost/replan. `api/tests/Feature/MRP/MrpNettingTest.php:484-523` freezes the BOM cost in its fixture rather than exercising the `ensureFreshBom()` recost path. There is also no cancellation-versus-MRP concurrency test for M049-B06.

Impact: the green suite proves ordinary planning behavior but does not pin the safety, concurrency, API-contract, or recovery claims that the prior audit relied on. Regressions in these paths can pass CI unnoticed.

### Incomplete

#### M049-I04 — Automatic runs preserve context metadata but can misattribute generated records

Evidence: automatic jobs accept a nullable initiator at `api/app/Modules/MRP/Jobs/RunAutomaticMrpJob.php:42-49` and pass it through at `:67-74`; `MrpAutomationService` forwards it at `api/app/Modules/MRP/Services/MrpAutomationService.php:28-34`. `MrpEngineService` correctly records run/reason/actor metadata at `:85-98`, but falls back to the sales-order creator for the mandatory maker field at `:85-86`. That fallback is written to `generated_by` on plans at `:221-235`, `requested_by` on auto-PRs at `:333-342`, and `created_by` on root WOs at `:476-486` and child WOs at `:802-813`.

Impact: an automatic system run can show `actor_type=system` while its mandatory maker fields identify the SO creator, who did not initiate the run. Manual runs carry the authenticated user from `api/app/Modules/MRP/Controllers/MrpRunController.php:42-49`, so the remaining defect is the system/queued attribution contract and its audit interpretation.

#### M049-I05 — Cost-snapshot mutability policy is not resolved

Evidence: the costing service describes the BOM values as frozen at `api/app/Modules/MRP/Services/BomCostingService.php:17-25`, but `ensureFreshBom()` mutates the BOM and its component cost snapshots through `recalculateBom()` at `:44-87` and `:170-179` whenever a dependency changed. The manage route permits recosting an arbitrary BOM version at `api/app/Modules/MRP/routes.php:21-27`; `api/app/Modules/MRP/Services/BomService.php:127-130` does not restrict `recalculate()` to the active version.

Impact: a historical, inactive BOM can be rewritten during planning or by a manage request. That may be acceptable as repair, but it is not yet an explicit audit/history policy and has no regression test. Decide whether historical snapshots are mutable; if they are immutable, restrict the operation or create a new revision rather than changing the old one.

### Polish

No new polish finding remains after the current frontend pass. The prior MRP UI findings are resolved: BOM actions are permission-gated at `spa/src/pages/mrp/boms/detail.tsx:15-16,22,92-107`; plan rerun has success and error feedback at `spa/src/pages/mrp/plans/detail.tsx:32-40`; the diagnostics table uses opaque surfaces, a nine-column span, and horizontal overflow at `spa/src/pages/mrp/plans/detail.tsx:96-108,129-151`; and the costing comment is current at `api/app/Modules/MRP/Services/BomCostingService.php:17-25`. Typecheck, lint, token audit, component test, and build all pass.

## Prior finding disposition

The original classifications are retained below so the re-audit is traceable. “Resolved” means the current implementation behavior was re-read and the focused checks passed; it does not mean every behavior has a new regression test.

| ID | Original classification | Current disposition and evidence |
|---|---|---|
| M049-B01 | Broken | Resolved. Missing-BOM demand is diagnosed and both explosion and standard-WO creation are blocked at `api/app/Modules/MRP/Services/MrpEngineService.php:149-166,400-403`. |
| M049-B02 | Broken | Resolved. Costing and planning share the typed lifecycle guard at `api/app/Modules/MRP/Services/BomComponentIntegrityService.php:20-66`. |
| M049-B03 | Broken | Resolved. BOM creation and replan outbox recording are inside one transaction at `api/app/Modules/MRP/Services/BomService.php:85-114`; the outbox joins an existing transaction at `api/app/Common/Services/OutboxService.php:59-74`. |
| M049-B04 | Broken | Resolved. Plan/run resources use `MrpPlanningResponseSerializer` and hash nested identifiers at `api/app/Modules/MRP/Resources/MrpPlanResource.php:35-63`, `MrpRunResource.php:20-41`, and `MrpPlanningResponseSerializer.php:74-113`. |
| M049-B05 | Broken | Resolved. Seeded production-manager permissions include read-only plan/run access at `api/database/seeders/RolePermissionSeeder.php:558-575`; the role alignment suite passed. |
| M049-M01 | Missing | Resolved. The guarded migration repairs duplicates and installs the partial unique index at `api/database/migrations/2026_08_26_000100_guard_one_active_bom_per_product.php:17-63`; the index was confirmed in the unique test database. |
| M049-M02 | Missing | Remains open as current Missing finding: no item standard-cost event or automatic affected-SO replan path. |
| M049-I01 | Incomplete | Resolved for monetary values. Main cost and PR price paths use decimal strings/BCMath/Money at `api/app/Modules/MRP/Services/MrpEngineService.php:264-275,358-367`; quantity calculations remain numeric quantities, not money. |
| M049-I02 | Incomplete | Resolved. Failed SOs increment counters and produce `partial` runs at `api/app/Modules/MRP/Services/MrpEngineService.php:655-688`; the enum and resource expose that state. |
| M049-I03 | Incomplete | Resolved for current API responses. `MrpErrorPolicy` supplies safe messages, codes, and recovery actions, and the resources sanitize legacy rows at `api/app/Modules/MRP/Resources/MrpPlanningResponseSerializer.php:16-36` and `MrpRunResource.php:15-41`. |
| M049-I04 | Incomplete | Partially resolved but remains open: run/context metadata is present, while automatic maker fields still fall back to the SO creator. See current finding above. |
| M049-P01 | Polish | Resolved. Manage controls are conditional on `mrp.boms.manage` at `spa/src/pages/mrp/boms/detail.tsx:15-16,22,92-107`. |
| M049-P02 | Polish | Resolved. Rerun success and error toasts are both present at `spa/src/pages/mrp/plans/detail.tsx:32-40`. |
| M049-P03 | Polish | Resolved. Opaque cards, the corrected `colSpan={9}`, and overflow wrapper are present at `spa/src/pages/mrp/plans/detail.tsx:96-108,129-151`. |
| M049-P04 | Polish | Resolved. The setup-time comment now documents cost-batch allocation at `api/app/Modules/MRP/Services/BomCostingService.php:17-25`. |

## Audit gate

The five current actions are all `separate-recommended`; the total scope includes large cross-module/concurrency work. This is not a majority `same-session-ok` small plan, so the gate requires **📋 Plan Ready**. No implementation fixes were applied and no `fix-log.md` entry was added.

## Release handoff

Deferred work is ordered in `action-plan.md`. The module must be re-audited after the cancellation policy, cost event/policy, actor attribution, and regression coverage are implemented. The generated registry remains coordinator-owned and will be regenerated after all cards finish.
