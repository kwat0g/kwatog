# Batch QC and Late Production Reporting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans task-by-task after design approval. User approved implementation on October 8, 2026. The six core deliverables are implemented and verified. Counted capture is enabled for new inspections; final verification and rollout are recorded in the linked report.

**Goal:** Replace repetitive inspection decisions with counted batch evidence and permit truthful, reviewed reports of earlier physical production.

**Architecture:** Version the existing inspection capture contract and reuse its pinned specifications, source guards, review, NCR, and inventory release. Add a small historical production-report record that posts through the existing output ledger only after authorized review; keep live production safety separate from historical entry. Preserve the deployed startup ledger and add explicit held-output accounting for work produced before its required quality release.

**Tech Stack:** Existing Laravel, PostgreSQL, React, TanStack Query, house forms, PHPUnit, Vitest, and Playwright. No new dependencies.

**Spec:** docs/superpowers/specs/2026-10-08-batch-qc-late-production-design.md

## Global Constraints

- Defective quantity and problem description drive the result. There are no per-piece or per-attribute PASS/FAIL controls in the new capture mode.
- Required Size / Measurement readings remain mandatory in a separate section, with automatic tolerance evaluation.
- New counted inspections use zero-defect acceptance. Pin policy and coverage; do not silently change legacy AQL interpretation.
- Entry effort does not prove physical coverage. Keep full attribute screening, planned dimensional samples, and observed sample defects distinct.
- FAIL holds the affected source; release requires existing independently approved sorting/recovery. FAIL never automatically means scrap.
- Startup pieces count toward the target once. Preserve existing trial ledger, material coverage, source ownership, and stock conservation.
- All late reports require production-management approval in the first version. Historical times stay separate from actual entry/posting timestamps.
- Historical reports do not extend schedules, acquire current resources, invent attendance, bypass quality, or create stock before approval.
- Use additive schema and source-first locks; exact retries and unique report-output linkage prevent duplicates.
- Keep terminal/submitted legacy evidence unchanged. Use isolated test databases and role accounts; no live physical fixtures or unrelated dirty-workspace edits/commits.
- No per-piece tracking, new recovery/approval framework, scheduler changes, dependencies, or unrelated refactoring.

## File and ownership map

### Inspection contract and calculations

- `api/app/Modules/Quality/Models/Inspection.php`: versioned capture and pinned evidence/policy fields.
- `api/app/Modules/Quality/Services/InspectionService.php`: creation, counted submission, source validation, calculated outcomes, independent review, and no fabricated per-row PASS.
- `api/app/Modules/Quality/Requests/RecordInspectionBatchResultRequest.php`: validated counted payload selected by the pinned mode; keep legacy branches compatible.
- `api/app/Modules/Quality/Resources/InspectionResource.php`, quality controller/routes: expose coverage and calculated evidence through existing endpoints where sufficient.
- Existing inspection specification/item quality-plan request/service/editor: approved coverage defaults and dimensional sampling requirements only; preserve old revision meaning.
- New narrowly named additive inspection migration and focused `CountedBatchInspectionTest.php`.

### Holds, recovery, and output quantities

- `api/app/Modules/Quality/Services/NcrService.php`, `NcrController.php`, `NcrResource.php`: explicit observed-versus-sorted counts, full sorting, repair/scrap/release and independent approver.
- `api/app/Modules/Production/Models/WorkOrderOutput.php`, `WorkOrderOutputService.php`, `WorkOrderMaterialUsageService.php`, `WorkOrderService.php`: held physical quantity, sample overlap, once-only physical posting/classification and completion reconciliation.
- `api/app/Modules/Inventory/Services/StockMovementService.php` and existing MRB/quarantine paths: deny held output as available stock across automatic/manual/retry routes.
- Existing outgoing creation/listeners, sales-order shortage and repair services: retain batch lineage and shortages, with focused changes only if their tests show new accounting is required.

### Historical reporting

- New `api/app/Modules/Production/Models/LateProductionReport.php` and additive migration: immutable proposal, historical interval/crew, approval, fingerprint and unique output link.
- New narrow `LateProductionReportService.php`: proposal validation, historical evidence assessment, approval/rejection, and atomic once-only posting via output service.
- New report request/controller and production routes: active scoped recorder submission and management review permissions using existing permission identifiers.
- `WorkOrderAssignmentService.php` and `AssignedWorkOrderController.php`: historical crew access, true role versus current recording availability, shared blockers, historical report status.
- Existing schedule/activity/attendance services supply historical evidence; do not change current live `assertCanRecord()` to accept arbitrary client backdates.

### UI and API

- `spa/src/types/quality.ts`, `spa/src/api/quality/inspections.ts`, inspection detail and components under `spa/src/pages/quality/inspections/components/`: compact counted entry and legacy renderer.
- `spa/src/components/production/TrialProductionPanel.tsx`: one sample-ready confirmation with manager reason only when applicable.
- `spa/src/types/production.ts`, `production-crew.ts`, `spa/src/api/production/workOrders.ts`: historical report and held-output contracts.
- `spa/src/pages/self-service/work-orders.tsx`, manager WO detail/record-output, existing history/review panels: historical reporting and management review without blank blockers.
- `spa/src/pages/quality/ncrs/detail.tsx`: full-sort good/held/repair/scrap counts and next actions, preserving independent review.

## Execution record

The checklist below is the original proof backlog, not a claim that every listed edge case received a distinct browser test. Completed deliverables and the exact verification scope are recorded in `docs/testing/batch-qc-late-production-20261008.md`.

| Deliverable | Execution |
| --- | --- |
| 1. Counted capture contract | Implemented, backend and role-based browser proof passed; piece and material-lot contracts retain their units. |
| 2. Holds and recovery | Existing independently reviewed NCR sorting reused; 200 physical pieces recover as 197 good and 3 scrap without double counting. |
| 3. Compact inspection/startup UI | Aggregate entry, required dimensions, sample-ready action, bounded forms and draft recovery implemented; legacy renderer retained. |
| 4. Historical proposals and review | Former-recorder access, source interval, reconciliation notes, independent approve/reject and historical snapshots implemented. |
| 5. Before-release physical output | Exact sample reports reuse trial accounting; bulk reports stay held with full QC and process deviation until independent recovery. |
| 6. Proof and rollout | Disposable role accounts, PostgreSQL duplicate approval, migration rollback safeguards, scoped tests and live flag verification recorded in the report. |

No scheduler changes, per-piece tracking, dependencies, or automatic conversion of legacy evidence were added. The original backlog remains below for traceability; untested permutations are not marked complete.

## Task 1: Versioned counted-inspection submission

Deliverable: one aggregate result request with truthful coverage and required dimensions; legacy evidence still works.

- [ ] Read every caller of inspection creation, result submission, finalization, review, checklist replacement, and outgoing accepted-quantity calculation. Confirm actual schema constraints and house request/resource conventions.
- [ ] Write failing backend tests for count-only zero-defect PASS, positive-count FAIL with required description, requirement failure at zero defects, incomplete coverage, stale version/source, unique count bounds, critical dimension failure, and omitted size readings.
- [ ] Write compatibility witnesses for existing `InspectionQuickResultTest`, `InspectionMeasurementContractTest`, `InspectionSpecBoundaryTest`, and `ReturnInspectionHandoffTest`. Run them in a disposable database and retain the meaningful failure output.
- [ ] Add version/policy and distinct coverage fields. Introduce a `counted_batch` contract compatible with the existing schema's `inspection_mode` constraint; do not repurpose `per_unit` rows or silently broaden database enum values.
- [ ] Reuse existing result endpoint and source/version/review guards. Submit aggregate checks-completed evidence, unique defective count, required problem and dimensional readings. Derive PASS/FAIL on the server and persist the aggregate evidence without fabricated item results.
- [ ] Pin new zero-defect policy at creation. Use full visual/attribute coverage for new outgoing counted batches and preserve approved dimensional sampling separately. Incoming, operation, startup and return sources keep their approved basis and actual quantities.
- [ ] Make every result boundary and review enforce the same counted contract; no legacy endpoint may bypass it on a new counted inspection.
- [ ] Run focused tests plus sampling, calibration, maker/checker, result authors, lifecycle and outgoing suites. Run Pint on touched PHP. Review policy compatibility before moving on.

## Task 2: Exact holds and defect recovery

Deliverable: a failed counted batch cannot ship; full sorting releases only verified conforming pieces.

- [ ] Add failing tests for a sampled failure holding the whole unshipped source, zero automatic accepted quantity, full-sort 197/3 recovery from 200, two problems on one piece, prior production rejects, already released quantities, repair/reinspection, all scrap, and receipt/review retries.
- [ ] Trace NCR source quantity, recovery quantity, accepted quantity, quarantine, outgoing releases, repair output and sales-order replacement callers. Reuse their existing owners.
- [ ] Ensure sample observations never become an inferred full-batch defect total. New counted FAIL has no automatic release. Preserve legacy passed-AQL records under their pinned policy.
- [ ] Require full affected-source sorting evidence and explicit good-kept, repair, scrap and unresolved quantities. Their sum must conserve the affected source, with known defects accounted for once.
- [ ] Preserve original FAIL and independent reviewer restrictions. Reuse repair/return/scrap and shortage planning; no stock adjustments merely to make counters match.
- [ ] Guard stock and shipment eligibility in all paths, including manual receipts, replay, quarantine release and inspection cancellation/replacement.
- [ ] Run `InspectionBatchRecoveryTest`, `OutgoingQcLotChecklistTest`, `NcrSetDispositionRaceTest`, `QuarantineMrbTest`, replacement/repair and startup trial suites plus new counted recovery tests. Review output conservation and lock order.

## Task 3: Counted inspection and compact startup UI

Deliverable: bounded forms across all stages, no per-piece result entry, required readings preserved.

- [ ] Write failing Vitest tests for a 200-piece bounded form, blank initial defect count, required positive-count problem, zero-count requirement problem, no per-check PASS/FAIL buttons, mandatory dimensions, aggregate result preview, source/version conflicts, role review and mobile blockers.
- [ ] Extend types/API for the versioned contract. Reuse house components and existing draft recovery. Keep terminal and nonconverted legacy inspections on their original renderer.
- [ ] Replace repeated per-check choices with pinned reference checks, defective quantity, conditional problem, optional tags, one coverage confirmation and a separate dimensional section. Show actual coverage and calculated verdict distinctly.
- [ ] Reduce TrialProductionPanel to pinned sample count plus “Samples ready for QC.” Retain physical confirmation, required manager reason, material handover guard and exact uncertain-save retries. Remove hash IDs and disabled quantity input.
- [ ] Fix worker list/detail role and blocker consistency: assignment identity is not recording availability; startup state must not hide the access blocker. Show one action and owner.
- [ ] Update NCR recovery to explain observed sample defects versus full sorting and the resulting released/held/repair/scrap quantities.
- [ ] Run existing inspection components/detail, spec editor, NCR detail, manager record-output and worker page tests plus new counted tests; run ESLint and TypeScript/Vite build. Verify responsive layout and accessible labels.

## Task 4: Historical production proposals and management approval

Deliverable: a worker can report an earlier completed batch without extending or altering today's production schedule.

- [ ] Add failing tests for expired schedule, time-out, ended crew assignment, wrong employee, inactive user, future/reversed/pre-WO interval, source mismatch, missing historical evidence, conflicting attendance, completed/closed/cancelled WO, approval permissions and exact retry.
- [ ] Add the late-report record with proposed counts, actual production interval, historical crew/source, reason, approval state/actor/time, fingerprint and unique output linkage. Proposal/rejection has no production or stock effects.
- [ ] Add scoped historical access separately from live `assignedQuery()`/`assertCanRecord()`. Workers can access only WOs they actually worked on. A manager can review evidence through existing production permissions; no new broad HR visibility.
- [ ] Assess historical shift/punch, assignment and run/resource history for the actual interval. Display verified/missing/conflicting evidence; all first-version late reports require management approval.
- [ ] Implement a narrow authorized posting context from an approved persisted report. Reuse source/quantity/material/accounting guards without acquiring current equipment or demanding current presence. Preserve actual entry time and store the production interval separately.
- [ ] Block impossible/ambiguous material or historical overlaps with a named reconciliation action. Do not make manager approval a bypass flag.
- [ ] Post physical units, mold usage, classified output and existing QC/receipt handoffs once where allowed. Exact approval retry returns the existing output; changed actor/payload cannot reuse the proposal key. Current ownership is unchanged.
- [ ] Treat closed-WO reports as correction review and cancelled-WO reports as non-posting exceptions. Closed accounting periods retain actual posting-time controls. Pending reports display separately from recorded output.
- [ ] Add worker “Report earlier production” and history access; manager review shows quantities, actual interval, reason, evidence and approve/reject actions. Use 12-hour Asia/Manila display and frozen payload/key recovery.
- [ ] Run new `LateProductionReportTest`, crew/attendance, output target/idempotency/receipt, and completed-WO reconciliation suites; add corresponding worker/manager Vitest tests. Review permissions and lock order.

## Task 5: Historical output before required startup release

Deliverable: actual earlier pieces can be reported honestly while they stay unavailable for production/shipment release.

- [ ] Add failing tests for late sample-only capture, whole batch before startup release, sample selected from an already reported batch, already captured samples included in a whole-batch report, duplicate approval, paused/completed WO and failed QC.
- [ ] Add explicit held physical quantity and production interval to the existing output ledger only where necessary. Define physical conservation with legacy trial rows; do not overload `trial_quantity` to mean an unrestricted batch.
- [ ] For sample-only historical reports, use validated historical evidence to post through the deployed trial ledger once and permit QC entry. Do not require today's crew attendance for that history.
- [ ] For bulk production before startup release, post actual held pieces after manager approval, link a process-deviation NCR and full affected-batch QC, and keep normal production blocked until approved process recovery. Never synthesize a prior QC PASS.
- [ ] Prevent overlapping sample/bulk counting; link selected samples to their physical source. Ambiguous reports stay pending for reconciliation rather than choosing an invented count.
- [ ] Ensure held pieces affect physical/material/mold conservation once but cannot create available finished-goods stock. Classification/recovery cannot increment physical production or shots again.
- [ ] Update WO totals, output resources/history, material coverage and routing/WO completion reconciliation to distinguish physical, held, approved good and scrap. Ordinary live production gates remain unchanged.
- [ ] Run startup trial, operation startup, manual inventory receipt eligibility, material handover and historical reporting suites. Review quantities and recovery at all interfaces.

## Task 6: Role-based proof and safe rollout

Deliverable: reviewed implementation and reproducible verification report, ready for deployment within explicit authorization.

- [ ] Use disposable API database and isolated SPA/API for real Playwright role sessions. Never time-in real users, confirm live handover, or invent live production for a test.
- [ ] Exercise recorder sample capture, QC zero-defect PASS and dimension failure, normal production, outgoing full-count failure, full sorting, independent release, repair/scrap and sales-order shortage.
- [ ] Exercise schedule-expired/time-out historical proposal, ended-assignment visibility, manager approval/rejection, missing/conflicting historical evidence and worker role denial.
- [ ] Exercise all-200-before-startup reporting with a held batch and deviation, no fake sample capture, explicit QC recovery and no early stock release.
- [ ] Test mobile forms, draft restore, duplicate retry, stale result version, worker list/detail blockers and saved production/entry times. Run at least one simultaneous duplicate approval/capture test against PostgreSQL.
- [ ] Run focused backend suites, relevant frontend suites, touched-file format/lint, TypeScript/build, migration forward/backward compatibility and inventory conservation checks. Expand only for new failures or unresolved concerns.
- [ ] Write a verification report with exact counts, results, screenshots, skipped checks and migration risks. Inspect scoped diff and confirm no unrelated/live evidence modifications.
- [ ] Deploy backend guards and additive schema before new forms only after implementation is authorized. Retain schema/evidence on rollback; refuse destructive rollback once used. No automatic conversion of submitted or terminal legacy inspections.

## Review checkpoints and stop condition

Review the design before execution. Each task above has an independently testable deliverable; keep its focused red/green evidence and review its connected callers before proceeding. Do not spawn agents merely because this plan exists; follow current user/tool instructions on delegation and model availability.

The change is finished when all six deliverables pass their required proof and the report explains actual coverage, defect disposition, late-report approval and stock effects. Stop there; do not add scheduling options or unrelated cleanup.
