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

## Code-reading re-audit — 2026-09-14 (56e0d431)

**Method and status:** Read-only source re-audit at commit `56e0d431`. No tests,
Docker commands, migrations, or application commands were run. No application
code, migrations, tests, or registry files were changed. The pre-existing
`spa/playwright.config.ts` worktree modification was left untouched. All six
prior findings remain unresolved; MRP-003 has expanded evidence, and three new
findings are added below.

### Prior Finding Recheck

#### MRP-001 — Lifetime mold life is not an assignment/output gate

- **Taxonomy:** Risk
- **Severity:** High
- **Status:** Unresolved; unchanged from the prior audit.
- **Location:** `api/app/Modules/MRP/Services/MoldService.php:112-139`; `api/app/Modules/Production/Services/WorkOrderService.php:830-843`; `api/app/Modules/MRP/Services/CapacityPlanningService.php:614-623`
- **Evidence/reproduction:** Set `lifetime_max_shots` to `1,000` and `max_shots_before_maintenance` to `100,000`. `incrementShots()` increments `lifetime_total_shots` but only changes status at the maintenance ceiling. Scheduler placement and the shared assignment gate also compare only `current_shot_count + quantity_target` with `max_shots_before_maintenance`. After a cycle reset, or once the lifetime total reaches 1,000, the mold can still be scheduled and can keep recording output.
- **Impact:** The declared total tooling life is informational. Production can continue past the rated lifetime, undermining maintenance controls and IATF traceability.
- **Effort:** M

#### MRP-002 — Mold shot-limit invariants can be saved already violated

- **Taxonomy:** Gap
- **Severity:** High
- **Status:** Unresolved; unchanged from the prior audit.
- **Location:** `api/app/Modules/MRP/Requests/StoreMoldRequest.php:30-39`; `api/app/Modules/MRP/Requests/UpdateMoldRequest.php:28-40`; `api/app/Modules/MRP/Services/MoldService.php:66-86`
- **Evidence/reproduction:** Update a mold whose current counter is 10,000 with `max_shots_before_maintenance=1`, or whose lifetime total is 10,000 with `lifetime_max_shots=1`. The requests validate only `min:1`; the service calls `update()` without comparing the new limits to current counters or to each other. The row is persisted in an already-over-limit state.
- **Impact:** Master data can claim a limit below accumulated usage, producing misleading remaining-life displays and making later scheduler behavior depend on an invalid record.
- **Effort:** S

#### MRP-003 — Scheduler and derived MRP projections violate the HashID contract

- **Taxonomy:** Risk
- **Severity:** Medium
- **Status:** Unresolved; expanded in this re-audit.
- **Location:** `api/app/Modules/MRP/Services/CapacityPlanningService.php:870-882`; `spa/src/types/mrp.ts:187-197`; `api/app/Modules/MRP/Services/MrpAutomationService.php:58-61,82-115`; `api/app/Modules/MRP/Resources/MrpPlanningResponseSerializer.php:107-113`; `api/app/Common/Resources/AlertResource.php:25-42`
- **Evidence/reproduction:** `POST /api/v1/mrp/scheduler/run` still returns raw integer `machine_id` and `mold_id` from `scheduleSummary()`, while schedule and work-order IDs are hash strings and the SPA type models the two resource IDs as numbers. The current commit also stores that scheduler result under `MrpRun.summary`; the serializer's identifier allow-list omits `machine_id` and `mold_id`, so `GET /api/v1/mrp/runs` can repeat those raw IDs. Separately, MRP alert metadata stores `run_id => $run->id` and alert messages interpolate the raw run ID; `AlertResource` returns metadata without sanitizing it. `POST /api/v1/mrp/runs` followed by `GET /api/v1/alerts` exposes the same contract violation.
- **Impact:** Integer primary keys are disclosed through multiple authorized API surfaces and the SPA receives an inconsistent identifier type. This creates avoidable ID enumeration and unsafe reuse of proposal rows for hash-ID mutation endpoints.
- **Effort:** S
- **Cross-module note:** The alert serialization boundary is shared Common code; MRP is the producer and should be fixed together with the shared resource contract.

#### MRP-004 — MRP work orders do not retain the BOM revision used to plan them

- **Taxonomy:** Gap
- **Severity:** Medium
- **Status:** Unresolved; unchanged from the prior audit.
- **Location:** `api/app/Modules/MRP/Services/MrpEngineService.php:226-242,489-514`; `api/app/Modules/Production/Models/WorkOrder.php:33-43`; `api/database/migrations/0080_create_work_orders_table.php:27-59`
- **Evidence/reproduction:** Generate a planned work order from BOM v1, create BOM v2, and rerun MRP. The new plan and WO are linked to the MRP plan, and the WO stores only `material_plan_source` (`WorkOrderService.php:175-200`); neither the work-order schema/model nor the MRP plan payload stores `bom_id` or BOM version. The material rows are quantity snapshots without a BOM revision reference.
- **Impact:** Historical production and variance review cannot prove which approved BOM revision generated the material requirements after a BOM change or rerun.
- **Effort:** M
- **Cross-module note:** The durable reference belongs at the MRP/Production boundary; do not infer it from the currently active BOM during later reads.

#### MRP-005 — Mold product validation accepts inactive or trashed products

- **Taxonomy:** Gap
- **Severity:** Medium
- **Status:** Unresolved; unchanged from the prior audit.
- **Location:** `api/app/Modules/MRP/Requests/StoreMoldRequest.php:27-39`; `api/app/Modules/MRP/Requests/UpdateMoldRequest.php:26-40`; `api/app/Modules/MRP/Services/MoldService.php:66-86`
- **Evidence/reproduction:** Submit a product ID for an inactive or soft-deleted product. Mold create and update use only `exists:products,id`; unlike `StoreBomRequest.php:33-49`, neither request requires `is_active=true` and `deleted_at IS NULL`, and the service has no lifecycle check.
- **Impact:** A mold can be attached to an archived finished good and remain selectable by product-based capacity planning, leaving tooling and product master data inconsistent.
- **Effort:** S
- **Cross-module note:** Product is CRM-owned; MRP should enforce the same active-product rule already used for BOM authoring while preserving explicit historical records if required by policy.

#### MRP-006 — Machine and mold edit pages still have no retry state

- **Taxonomy:** Stuck process
- **Severity:** Low
- **Status:** Unresolved; unchanged from the prior audit.
- **Location:** `spa/src/pages/mrp/machines/edit.tsx:19-27`; `spa/src/pages/mrp/molds/edit.tsx:19-27`
- **Evidence/reproduction:** Make the detail request fail after the loading skeleton. Each edit wrapper renders only `Could not load ...` and provides no `refetch()` action, unlike the corresponding detail pages and BOM/plan edit wrappers.
- **Impact:** An operator reaching the edit route after a transient failure has no in-page recovery path and must navigate away or reload the browser.
- **Effort:** S

### New Findings

#### MRP-007 — Rerun can rewrite a work order after production confirmation wins the race

- **Taxonomy:** Risk
- **Severity:** High
- **Status:** New unresolved concurrency gap.
- **Location:** `api/app/Modules/MRP/Services/MrpEngineService.php:469-501,806-828`; `api/app/Modules/Production/Services/WorkOrderService.php:231-262`
- **Evidence/reproduction:** During an MRP rerun, `priorPlanned` and `priorChildren` select planned auto-generated WOs without `lockForUpdate()`. Pause after either query, confirm that WO through `WorkOrderService::confirm()` in another request, then let the rerun continue. Confirmation locks and changes the WO to `confirmed`, but the stale rerun model later `forceFill()`s `mrp_plan_id`, `quantity_target`, planned dates, and priority and saves without re-reading the authoritative status. The same pattern exists for subassembly children.
- **Impact:** A confirmed WO can have its target and planning dates rewritten after material reservation and production handoff, making the production plan, reservations, and MRP lineage disagree. This is the work-order analogue of the auto-PR race fixed by commit `56e0d431`.
- **Effort:** M
- **Cross-module note:** The fix must serialize MRP reconciliation with Production confirmation, recheck lifecycle state under the WO lock, and leave progressed WOs authoritative.

#### MRP-008 — Scheduler failure is not reflected in the MRP run state

- **Taxonomy:** Broken process
- **Severity:** Medium
- **Status:** New unresolved failure-path gap.
- **Location:** `api/app/Modules/MRP/Services/MrpAutomationService.php:34-65`; `api/app/Modules/MRP/Services/MrpEngineService.php:702-730`; `api/app/Modules/MRP/Services/CapacityPlanningService.php:424-439`
- **Evidence/reproduction:** Let demand planning complete, then make the persisted schedule-window scan encounter an invalid active window (`scheduled_start >= scheduled_end`), which `CapacityPlanningService::loadScheduleWindows()` throws for. `runForActiveSalesOrders()` has already marked the MRP run `completed` at lines 702-717; `MrpAutomationService::run()` invokes the scheduler without a catch or status correction. The exception exits before the scheduling summary is saved and before the scheduling alert is raised.
- **Impact:** Run history can say completed even though finite-capacity planning did not finish. The queue job may retry and rerun demand planning, while operators have no durable run-level indication that scheduling requires recovery.
- **Effort:** M

#### MRP-009 — Mold detail uses a hardcoded warning threshold instead of the configured policy

- **Taxonomy:** Broken process
- **Severity:** Medium
- **Status:** New unresolved UI/state contract gap.
- **Location:** `spa/src/pages/mrp/molds/detail.tsx:120-126`; `api/app/Modules/MRP/Controllers/MoldController.php:33-38`; `api/database/seeders/SettingsSeeder.php:149`
- **Evidence/reproduction:** The seeded/configurable `alerts.mold.warning_ratio` is `0.80`, and the molds options endpoint returns the live `warning_ratio_pct`. The mold detail page instead always passes `warningRatioPct={90}` to `MoldShotMeter`. A mold at 85% is therefore flagged as nearing the limit by backend/list behavior but shown as optimal on its detail page, with the PM prompt withheld.
- **Impact:** Operators can miss the configured preventive-maintenance threshold on the primary mold detail surface. The UI contradicts the alert engine and list filter for the same mold.
- **Effort:** S

### Clean Areas Rechecked

- **BOM authoring and explosion:** Active product/item validation, duplicate and empty-component checks, unit integrity, cycle/depth protection, multi-level production planning, and decimal BOM costing remain present (`StoreBomRequest.php:30-53`; `BomService.php:85-114,259-372`; `BomComponentIntegrityService.php:20-67`; `BomCostingService.php:31-179`). MRP-004 is specifically the missing historical revision reference, not a claim that BOM version creation is broken.
- **Material netting and auto-PR reconciliation:** Quarantine/scrap exclusion, reservations, in-transit policy, safety-stock handling, MOQ rounding, and draft auto-PR reconciliation remain implemented (`MrpEngineService.php:248-311,879-957`). The commit’s new row lock and submit-time defense cover the shortage-path auto-PR handoff (`MrpEngineService.php:332-385`; `PurchaseRequestService.php:309-362`); the residual concurrency finding is on work-order reuse, not this PR path.
- **MRP run recovery:** Per-sales-order failures, safe error codes, stale-run reaping, unique jobs, and the plant overlap fence remain present (`MrpEngineService.php:634-730`; `RunAutomaticMrpJob.php:24-74`; `api/routes/console.php:39-48`). MRP-008 is limited to the later scheduler handoff not updating the already-completed run.
- **Capacity scheduling core:** Planned-WO/resource locking, machine-window overlap checks, daily-capacity and due-date guards, mold-window checks, deterministic confirmation, and the database machine exclusion constraint remain present (`CapacityPlanningService.php:63-260,482-713`; `api/database/migrations/0479_harden_capacity_plan_invariants.php:30-68`). MRP-001 remains the separate lifetime-shot boundary not covered by those cycle-life checks.
- **Authorization and routing:** MRP routes retain Sanctum, feature, permission middleware; hash-ID request decoding and SPA lazy/module/permission guards remain in place (`api/app/Modules/MRP/routes.php:18-84`; `RunSchedulerRequest.php:11-30`; `ConfirmScheduleRequest.php:11-30`; `spa/src/routes/mrpRoutes.tsx:1-59`). MRP-003 is limited to response projection leakage, not route authorization.
- **Frontend state baseline:** MRP list/detail pages retain loading, error/retry, empty/data, placeholder, form validation, mutation feedback, and query invalidation patterns. The retry exception remains isolated to the two edit wrappers in MRP-006; MRP-009 is a separate configured-threshold mismatch.

**Report status:** This section is the only change made for this re-audit. No
application code was changed.
