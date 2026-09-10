# MRP / MRP II Read-Only Audit

**Scope:** `api/app/Modules/MRP/**`; directly relevant Common/Providers and
`api/routes/console.php`; MRP SPA APIs/routes/pages plus the production schedule
consumer; relevant migrations, seeders, and MRP/production/inventory/CRM tests
and dependencies.

**Method:** source inspection only. No application code was modified, no
database-mutating tests or artisan commands were run, and no commit was made.

## Finding Categories

The fixed categories used below are: **BOM/versioning**, **material
planning/netting**, **capacity/scheduling**, **machine/mold lifecycle**,
**transaction/concurrency/idempotency**, **security/auth/HashIDs/enums**, and
**UI/UX/state/forms**.

## Findings

### MRP-001

- **Category:** Machine/mold lifecycle
- **Severity:** High
- **Location:** `api/app/Modules/MRP/Services/MoldService.php:112-139`; `api/app/Modules/Production/Services/WorkOrderService.php:810-823`
- **Roadmap status:** New current-worktree regression/gap. Not the roadmap-known F-016 policy decision.
- **Reproduction/evidence:** Create a mold with `lifetime_max_shots = 1,000` and `max_shots_before_maintenance = 100,000`. Record output for more than 1,000 shots, or confirm a work order after `lifetime_total_shots` is already at the lifetime ceiling. `incrementShots()` only increments `lifetime_total_shots`; it checks and auto-flips status against `max_shots_before_maintenance`, not `lifetime_max_shots`. The direct assignment gate in `WorkOrderService::assertAssignmentValid()` likewise checks only the maintenance ceiling. Thus a retired-by-life mold can continue producing indefinitely after periodic maintenance resets `current_shot_count`.
- **Impact:** The lifetime tooling limit is informational rather than enforced. Production can be scheduled against tooling that has exceeded its declared total rated life, undermining maintenance and IATF traceability.
- **Effort:** M
- **Cross-module note:** Production output calls `MoldService::incrementShots()` from `WorkOrderOutputService`; Maintenance resets only the cycle counter (`MaintenanceWorkOrderService.php:238-253`). The fix must preserve cycle resets while making lifetime total monotonic and blocking assignment/output at lifetime exhaustion.

### MRP-002

- **Category:** Machine/mold lifecycle
- **Severity:** High
- **Location:** `api/app/Modules/MRP/Requests/StoreMoldRequest.php:30-39`; `api/app/Modules/MRP/Requests/UpdateMoldRequest.php:28-40`; `api/app/Modules/MRP/Services/MoldService.php:66-86`
- **Roadmap status:** New current-worktree invariant gap.
- **Reproduction/evidence:** Update an existing mold so `max_shots_before_maintenance` is below its current `current_shot_count`, or so `lifetime_max_shots` is below `lifetime_total_shots`. Both update fields validate only `min:1`; neither request validates against the current row, and the service has no invariant check. A mold may therefore be saved already over a stated limit. The create and update paths also do not enforce a relationship between the cycle limit and lifetime limit.
- **Impact:** The UI can immediately show an over-limit mold with misleading remaining-life values, and persisted master data can violate the lifecycle invariant. This compounds MRP-001 and makes operator correction dependent on later scheduling checks.
- **Effort:** S
- **Cross-module note:** Capacity planning filters by `max_shots_before_maintenance` only (`CapacityPlanningService.php:614-623`), while Production and Maintenance consume the same counters. Enforce the invariant in the service and request with a clear 422 error, and add database checks where compatible.

### MRP-003

- **Category:** Security/auth/HashIDs/enums
- **Severity:** Medium
- **Location:** `api/app/Modules/MRP/Services/CapacityPlanningService.php:870-882`; `spa/src/types/mrp.ts:186-197`
- **Roadmap status:** New current-worktree contract violation.
- **Reproduction/evidence:** An authorized `POST /api/v1/mrp/scheduler/run` returns each proposed row through `scheduleSummary()`. `machine_id` and `mold_id` are emitted as raw integer primary keys at lines 876-877, while the same response emits the schedule and work-order IDs as hash IDs. The SPA type explicitly models these two fields as `number`. The scheduler reassign request expects hash IDs (`SchedulerController.php:61-64`), so the API exposes an inconsistent identifier contract.
- **Impact:** Integer IDs are disclosed in an API response despite the project-wide HashID rule. It also creates an unsafe client contract if the proposal is later used to drive reassign actions.
- **Effort:** S
- **Cross-module note:** `MachineResource`, `MoldResource`, Gantt snapshots, and broadcast events correctly use hash IDs. Normalize scheduler proposals and their TypeScript type, then pin with an API test.

### MRP-004

- **Category:** BOM/versioning
- **Severity:** Medium
- **Location:** `api/app/Modules/MRP/Services/MrpEngineService.php:220-236,476-486`; `api/app/Modules/Production/Models/WorkOrder.php:33-43,107-110`
- **Roadmap status:** New traceability gap; distinct from the roadmap-known F-016 no-BOM policy decision.
- **Reproduction/evidence:** Create BOM v1, confirm an SO, and allow MRP to create a planned WO. Create BOM v2 for the same product and rerun MRP. The new plan/WO is linked to the plan, but the WO stores only `material_plan_source = 'bom'`; there is no BOM ID or BOM version on `work_orders`, `work_order_materials`, or the MRP plan snapshot. The existing material rows are a quantity snapshot, but cannot identify which BOM version produced them.
- **Impact:** Historical production and material variance review cannot reliably prove the exact approved BOM revision used to create a WO, especially after revisions and reruns.
- **Effort:** M
- **Cross-module note:** Production output already snapshots material lines and lot references, and CRM SOs are the demand source. Add an immutable BOM/version reference at plan/WO creation without changing the existing revision semantics.

### MRP-005

- **Category:** Machine/mold lifecycle
- **Severity:** Medium
- **Location:** `api/app/Modules/MRP/Requests/StoreMoldRequest.php:30-39`; `api/app/Modules/MRP/Requests/UpdateMoldRequest.php:28-40`
- **Roadmap status:** New current-worktree validation gap.
- **Reproduction/evidence:** Submit a mold with a product ID that resolves to an inactive product. Both requests use only `exists:products,id`; unlike `StoreBomRequest.php:33-39`, they do not require `is_active = true` and `deleted_at IS NULL`. The service creates/updates the mold without a product lifecycle check.
- **Impact:** A mold can be assigned to an archived/inactive finished good. It may remain visible to capacity planning by product ID and create a tool/product master-data mismatch.
- **Effort:** S
- **Cross-module note:** Product is owned by CRM; MRP’s scheduler selects molds by `product_id`. Align mold validation with the BOM product rule, while preserving explicit historical records if the business later needs them.

### MRP-006

- **Category:** UI/UX/state/forms
- **Severity:** Low
- **Location:** `spa/src/pages/mrp/machines/edit.tsx:10-27`; `spa/src/pages/mrp/molds/edit.tsx:10-27`
- **Roadmap status:** New current-worktree UX gap.
- **Reproduction/evidence:** Make the machine or mold detail request fail after loading. The edit pages render a plain `Could not load ...` text block with no retry action, unlike the BOM and plan detail pages, which render `EmptyState` with `refetch()`. The pages also do not surface the error response or offer a recovery path.
- **Impact:** Operators can reach an unrecoverable edit screen and must navigate away or refresh the browser. This is particularly harmful for lifecycle corrections after a transient API failure.
- **Effort:** S
- **Cross-module note:** This is frontend-only and does not alter backend authorization. Use the established detail-page error pattern and invalidate the relevant list/detail queries after a successful mutation.

## Explicitly Excluded / Policy-Dependent

- **F-016 no-BOM production:** Not reported as a defect. The roadmap explicitly marks the policy as decision-required: standard stock-producing WOs should require an effective BOM/material plan, with an authorized exception only for explicitly classified service/non-stock/prototype work. Current MRP correctly records a `missing_bom` warning and skips standard WO creation (`MrpEngineService.php:149-163,400-403`). No policy was invented in this audit.
- The commented mold cost-trend route is intentionally hidden by scope documentation and has no SPA caller; it is not a finding.
- Lack of a separate MRP scheduler page under `spa/src/pages/mrp` is intentional: the scheduler API is consumed by the guarded Production schedule page (`spa/src/pages/production/schedule.tsx`).
- Global plant stock and approved PO in-transit netting was not called a defect. Whether in-transit supply is plant-wide or SO-pegged is a business allocation policy not established by this request; existing tests explicitly document plant-wide netting semantics.
- No report is made about the roadmap’s already-recorded controls for active BOM uniqueness, active MRP plan uniqueness, scheduler machine overlap, output idempotency, or stale-run reaping except where the mold lifecycle gaps demonstrate residual boundaries.

## Clean Checked Areas

- **BOM authoring validation:** Product and item HashIDs are decoded server-side; active products/items are required; duplicate component IDs, empty BOMs, missing items, inactive/trashed components, unit mismatches, and direct self-reference are guarded (`StoreBomRequest.php:22-53`, `BomService.php:192-241`, `BomComponentIntegrityService.php:20-67`).
- **BOM versioning mechanics:** Creating/editing a BOM creates a new version, locks the product’s prior versions, deactivates the prior active row, and preserves history (`BomService.php:75-114`). Migration `2026_08_26_000100_guard_one_active_bom_per_product.php` adds a partial unique index.
- **Multi-level BOM explosion:** Recursive cycle detection, configurable maximum depth, subassembly production trees, and raw-material aggregation are implemented (`BomService.php:259-372,385-479`).
- **BOM costing:** Costing uses decimal-string/BC math, rolls up nested BOM costs, records cost warnings, and refreshes stale component/routing costs (`BomCostingService.php:31-49,93-179`).
- **Material shortage handling:** MRP records diagnostics, excludes quarantine/scrap stock, subtracts reserved stock, ignores draft/cancelled POs, calculates lead time and safety buffer, rounds PR quantities upward, and reconciles eligible draft auto-PRs on rerun (`MrpEngineService.php:238-303,309-384,858-940`).
- **MRP run recovery:** Automatic jobs use `ShouldBeUnique` plus a plant-wide `WithoutOverlapping` fence; runs record partial/failure outcomes and stale runs are scheduled for reaping (`RunAutomaticMrpJob.php:24-74`, `console.php:39-51`, `MrpEngineService.php:623-700`).
- **Active plan/rerun protection:** Prior active plans are locked and superseded; migration `2026_08_15_121000_guard_mrp_plan_versions.php` enforces unique SO/version and one active plan per SO. Rerun tests cover PR/WO/subassembly deduplication.
- **Machine status transitions:** Statuses are enum-backed, transitions are allow-listed, and the row is re-read under lock before mutation (`MachineService.php:25-36,88-113`). Breakdown transitions supersede eligible future schedules (`Machine.php:77-99`).
- **Scheduler machine capacity:** Planned work orders are locked, resources are locked in deterministic order, machine windows use overlap checks, daily capacity and due-date overrun are rejected, and migration 0479 adds a database machine-time exclusion constraint (`CapacityPlanningService.php:63-179,482-713`, migration `0479_harden_capacity_plan_invariants.php:60-68`).
- **Scheduler confirmation:** Confirmation locks schedules/resources/WOs, validates current state and assignment, and commits WO confirmation plus schedule promotion in one transaction (`CapacityPlanningService.php:188-260`).
- **Route guards and request authorization:** MRP routes require Sanctum and the MRP feature, and write requests have server-side permission checks. SPA routes are lazy-loaded and nested under `ModuleGuard` plus permission guards (`api/app/Modules/MRP/routes.php:18-84`, `spa/src/routes/mrpRoutes.tsx:1-59`).
- **Frontend list/detail/form baseline:** MRP list pages have loading, error/retry, empty, data, and placeholder-data paths; forms use React Hook Form/Zod, disable pending submits, map 422 errors, show toast feedback, and invalidate queries. The edit-page retry exception is isolated to MRP machine/mold wrappers identified in MRP-006.
- **Token discipline:** Reviewed MRP pages use semantic token classes rather than raw color literals or `dark:` variants. Numeric table values generally use the project’s mono/tabular components.
