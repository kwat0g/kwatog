# Finish-First WO Quality Inspection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Use inline execution unless delegation is requested; if delegated, the user authorized `gpt-6-luna`.

**Goal:** Finish eligible WO production before creating QC, inspect every actual piece through efficient batch entry, and preserve defect recovery and late reporting.

**Architecture:** Version eligible new WOs and reuse the existing output ledger, per-output outgoing inspections, stock holds, independent review, NCR and repair/replacement owners. Present those inspections as one WO task; retain batch-level evidence and release. Reuse `completed` for physical completion and derive the quality state.

**Tech Stack:** Laravel/PHP, PostgreSQL, existing outbox/queues, React/TypeScript, TanStack Query, existing form and table components, PHPUnit, Vitest and Playwright. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-10-08-finish-first-wo-qc-design.md`

## Global Constraints

- Implementation approved and completed. User-facing defective-item walkthroughs remain a separate test session.
- New policy identifier: `finish_first_v1`; null remains legacy.
- Eligible policy: standard root and replacement production without approved intermediate `qc_required` routing.
- No new lifecycle enum, parent inspection entity, recovery engine or user-selectable QC bypass.
- All physical pieces and all required dimensions receive full coverage.
- Only PASS/FAIL outcomes; the server calculates them from evidence.
- Problem descriptions: 1–500 characters; each defective piece counts once.
- Evidence pages and rendered piece pages: maximum 50 pieces.
- Independent QC review and sorting approval remain required before release.
- Pending QC is a production commitment, never available stock.
- Late historical production remains reportable without extending the run.
- Render dates/times in Asia/Manila, 12-hour format.
- No destructive conversion, live test production, existing evidence deletion, dependency addition or unrelated cleanup.
- Preserve the shared dirty workspace; no wholesale staging, reset, clean or commit. During execution, stage only reviewed task-owned hunks if a commit is requested.

## File map and ownership

| Area | Existing files to extend | New files |
| --- | --- | --- |
| Policy and production | `api/app/Modules/Production/Models/WorkOrder.php`, `Services/WorkOrderService.php`, `Services/WorkOrderOutputService.php`, `Services/WorkOrderAssignmentService.php`, `Requests/RecordOutputRequest.php`, `Controllers/AssignedWorkOrderController.php`, `Controllers/WorkOrderController.php`, `Resources/WorkOrderResource.php`, `Resources/WorkOrderOutputResource.php`, `api/config/inspection.php` | `api/database/migrations/2026_10_08_236000_add_finish_first_wo_qc.php` |
| Creation paths | `api/app/Modules/MRP/Services/MrpEngineService.php`, `api/app/Modules/Quality/Services/NcrService.php`, `api/database/factories/WorkOrderFactory.php` | Policy tests |
| Final QC and group API | `api/app/Modules/Quality/Services/InspectionService.php`, `Models/Inspection.php`, `Resources/InspectionResource.php`, `Controllers/InspectionController.php`, `Requests/RecordInspectionBatchResultRequest.php`, `Requests/ReviewInspectionRequest.php`, `routes.php`, `Listeners/TriggerInProcessQC.php`, `Listeners/TriggerOutgoingQC.php`, `api/app/Console/Commands/SweepMissingOutgoingQc.php` | `Services/WorkOrderFinalInspectionService.php`, `Controllers/WorkOrderFinalInspectionController.php`, `Requests/SaveWorkOrderFinalInspectionEvidenceRequest.php`, `Requests/SubmitWorkOrderFinalInspectionRequest.php`, `Models/InspectionDefectivePiece.php`, `Models/InspectionPieceReading.php` under `api/app/Modules/Quality/` |
| Stock, close and recovery | `api/app/Modules/Quality/Services/QualityStockHoldService.php`, `Services/NcrService.php`, `Services/CoCService.php`, `Listeners/CreateDeliveryDraftOnQcPass.php`, `api/app/Modules/Production/Services/WorkOrderReworkService.php`, `api/app/Modules/Inventory/Services/StockMovementService.php`, `api/app/Modules/SupplyChain/Services/DeliveryStockService.php`, `Services/DeliveryService.php`, `api/app/Modules/CRM/Services/SalesOrderService.php`, `api/app/Modules/MRP/Services/MrpEngineService.php` | Recovery tests |
| Late reports | `api/app/Modules/Production/Services/LateProductionReportService.php`, `Services/WorkOrderOutputService.php`, `Requests/StoreLateProductionReportRequest.php`, `Models/LateProductionReport.php` | Late-workflow tests |
| Production UI | `spa/src/pages/production/work-orders/detail.tsx`, `record-output.tsx`, `ReworkPanel.tsx`, `StartProductionDialog.tsx`, `spa/src/pages/self-service/work-orders.tsx`, `spa/src/components/production/TrialProductionPanel.tsx`, `LateProductionReports.tsx`, `spa/src/api/production/workOrders.ts`, `spa/src/types/production.ts`, `production-crew.ts` | Production UI tests |
| QC UI | `spa/src/pages/quality/inspections/index.tsx`, `detail.tsx`, `components/CountedBatchInspectionPanel.tsx`, `spa/src/pages/quality/ncrs/detail.tsx`, `spa/src/api/quality/inspections.ts`, `spa/src/types/quality.ts` | `spa/src/pages/quality/inspections/work-order-final.tsx`, `components/WorkOrderFinalInspectionPanel.tsx`, corresponding tests, route registration in `spa/src/App.tsx` |

Only change stock/CRM/delivery callers if their legacy assumptions conflict with versioned full-output coverage. Reuse their shared release predicate instead of adding local bypasses. Routing and repair callers must be explicitly audited even where no edit is required.

## Task 1: Pin the workflow and preserve legacy data

**Files:** migration and policy/creation files from the file map. Create `api/tests/Feature/Quality/FinishFirstQcPolicyTest.php`.

**Interfaces:**

```php
// WorkOrder: no new user option; marker is selected by the creation owner.
public function usesFinishFirstQc(): bool;
// Exact marker: finish_first_v1. Null means legacy.
// Deployment setting: inspection.finish_first_wo_enabled
// Environment input: WO_FINISH_FIRST_QC_ENABLED, default false.
```

- [x] Add migration preserving every existing value. Add nullable `work_orders.qc_workflow`. Add `inspection_defective_pieces` with inspection foreign key, positive `piece_number`, description, author and timestamps; unique `(inspection_id, piece_number)`. Add `inspection_piece_readings` with inspection and pinned measurement-reference foreign keys, positive `piece_number`, decimal reading, evaluated boolean, author and timestamps; unique `(inspection_id, requirement_id, piece_number)`. Restrict deletion of referenced evidence.

```php
Schema::table('work_orders', function (Blueprint $table): void {
    $table->string('qc_workflow', 32)->nullable();
});
Schema::create('inspection_defective_pieces', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('inspection_id')->constrained('inspections')->restrictOnDelete();
    $table->unsignedInteger('piece_number');
    $table->string('problem_description', 500);
    $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
    $table->timestamps();
    $table->unique(['inspection_id', 'piece_number']);
});
Schema::create('inspection_piece_readings', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('inspection_id')->constrained('inspections')->restrictOnDelete();
    $table->foreignId('requirement_id')->constrained('inspection_measurements')->restrictOnDelete();
    $table->unsignedInteger('piece_number');
    $table->decimal('measured_value', 12, 4);
    $table->boolean('is_pass');
    $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
    $table->timestamps();
    $table->unique(['inspection_id', 'requirement_id', 'piece_number'], 'inspection_piece_reading_unique');
});
// The evidence tables use explicit source keys; never identify a piece by
// a row index in the current browser page.
```

- [x] Add policy tests before changing creation logic: disabled switch yields legacy; enabled eligible creation yields `finish_first_v1`; existing rows remain null; mandatory intermediate routing and repair/component creation remain legacy; MRP and NCR replacement creation apply identical eligibility.

```php
public function test_existing_work_order_is_not_reinterpreted(): void
{
    $wo = WorkOrder::factory()->create(['qc_workflow' => null]);
    config(['inspection.finish_first_wo_enabled' => true]);
    $this->assertFalse($wo->fresh()->usesFinishFirstQc());
}
```

- [x] Run the failing new-policy tests in a disposable database; implement marker selection in the common creation path and cover direct MRP/NCR writers. Do not set a database default that converts old callers accidentally.
- [x] Make the marker immutable and reject client edits. Revalidate approved routing compatibility before confirm/start; a newly introduced intermediate-QC requirement blocks confirmation/start rather than silently switching a started WO. Keep specification/calibration readiness validation even though startup inspection creation is removed. Escalate a destructive-test/full-coverage conflict to QC instead of weakening the published requirement.
- [x] Add rollback refusal when any new-policy WO or new piece evidence exists. Add migration tests for empty forward/backward migration and used-evidence refusal. Rollback of the activation switch stops only new adoption.
- [x] Run `php artisan test tests/Feature/Quality/FinishFirstQcPolicyTest.php` from `api/` with test-only database settings. Review migration diff before enabling any deployment setting.

## Task 2: Produce and finish without a startup-QC dependency

**Files:** production files, startup listener and resources from the file map; audit `api/app/Modules/Production/Services/WoOperationService.php`. Create `api/tests/Feature/Production/FinishFirstWorkOrderTest.php`; extend `WorkOrderMaterialHandoverGateTest.php`.

**Interfaces:**

```php
// New-policy body for existing record-output endpoint:
// produced_count: positive integer; crew_id and existing acknowledgments.
// Legacy body retains good_count/reject_count/defects exactly.
public function complete(
    WorkOrder $wo,
    ?string $shortCompletionReason = null,
    ?Carbon $productionFinishedAt = null,
): WorkOrder;
// Resource adds derived produced_quantity, qc_accepted_quantity,
// qc_defective_quantity, awaiting_qc_quantity and quality_state.
```

- [x] Write failing tests: Start creates no root startup inspection; record 100 then 100 creates no QC; produced total is 200; missing handover, wrong recorder, absent crew, expired live access and occupied equipment retain their existing failures. Existing trial-required WOs still require startup QC.
- [x] Branch the shared `assertProcessQualityReleased()` by pinned workflow only where no mandatory intermediate gate exists. Update every caller found in `WorkOrderService`, `WorkOrderOutputService`, `WoOperationService`, assignment resources and controllers. Do not make the helper return success for an unknown policy.

```php
if ($wo->usesFinishFirstQc()) {
    $good = (int) $data['produced_count'];
    $reject = 0;
    // Preserve source lineage, idempotency, crew, material and receipt checks.
}
```

- [x] Extend request validation so new-policy writes require positive `produced_count` and prohibit conflicting legacy classification fields. Reject forged policy markers and non-integer quantities at HTTP and service boundaries. Keep durable exact retries valid after state changes.
- [x] Update normal completion to use recorded physical totals, completed routing and existing material checks for this workflow. Permit ordinary pending-QC output; do not permit historical exception holds through an unrelated blanket bypass. Retain short-completion reason and positive-output requirements. Block pending late reports. Release equipment exactly once and emit the existing completion outbox event.
- [x] Support a real finish timestamp for already physically finished production: management authorization, timestamp not before actual start/latest accepted batch finish, not in the future, not outside reconciled execution evidence; audit both actual and recorded time. Do not revive ended schedules or seize equipment. Legacy callers retain their existing default timestamp behavior.
- [x] Update displayed quantity labels and derived values. Keep receipts traceable and pending stock held by the shared predicate; do not rename pending physical output as approved QC good stock.
- [x] Run `php artisan test tests/Feature/Production/FinishFirstWorkOrderTest.php tests/Feature/Production/WorkOrderMaterialHandoverGateTest.php tests/Feature/Production/WorkOrderOutputIdempotencyKeyTest.php` against disposable data. Assert resource release, output totals, mold usage, receipts and completion events, not only HTTP status.

## Task 3: Create final batch inspections and one WO task

**Files:** final-QC service/controller, request, listener, sweep command, routes, `InspectionService.php`, model/resource. Create `api/tests/Feature/Quality/FinishFirstInspectionCreationTest.php`.

**Interfaces:**

```php
final class WorkOrderFinalInspectionService
{
    public function ensureForCompletedWorkOrder(WorkOrder $wo, User $by): Collection;
    public function show(WorkOrder $wo, User $by): array;
    public function saveEvidence(WorkOrder $wo, array $data, User $by): array;
    public function submit(WorkOrder $wo, array $data, User $by): array;
    public function review(WorkOrder $wo, array $data, User $by): array;
}
```

Endpoints in the existing Quality permission group:

```text
GET   /quality/work-orders/{workOrder}/final-inspection
PATCH /quality/work-orders/{workOrder}/final-inspection/evidence
POST  /quality/work-orders/{workOrder}/final-inspection/submit
POST  /quality/work-orders/{workOrder}/final-inspection/review
```

Response shape:

```typescript
type FinalInspectionManifest = Array<{
  output_id: string;
  inspection_id: string;
  physical_quantity: number;
  result_version: number;
  specification_revision_id: string;
}>;
type WorkOrderFinalInspection = {
  work_order_id: string;
  manifest: FinalInspectionManifest;
  produced_quantity: number;
  defective_quantity: number;
  accepted_quantity: number;
  state: 'checking' | 'awaiting_review' | 'failed' | 'released';
  batches: Inspection[];
};
```

- [x] Write tests for creation only after physical completion, 100+100 creating two output-bound inspections but one WO task, all-reject outcome remaining inspectable, event replay/concurrent creator uniqueness, no manufacturing a QC batch from the target quantity, and missing specification leaving an observable retryable handoff with stock held.
- [x] Extend `InspectionService::createForOutput()`/creation checks: `finish_first_v1` source quantity is actual physical output; legacy source remains reported good quantity. Set `counted_capture.version = 2`, `workflow = finish_first_v1`, `required_quantity = physical_count`, `dimensional_quantity = physical_count`, `result_policy = zero_defects`. Branch before legacy AQL/sample scaffolding; seed pinned requirement references once per check and retain the full actual source quantity. Numeric per-piece readings use the new table. No fake sample ledger.
- [x] Implement `ensureForCompletedWorkOrder()` using source-first locks and existing output-stage uniqueness. Use it from completion listener, sweep recovery and approved post-completion late output. Refuse ordinary new QC before completion; closed correction creation requires the existing authorized correction evidence. Replays return the original inspections.
- [x] Implement read-only `show()` with permission and policy checks. The GET must not silently produce physical or QC evidence. Existing group members and pinned revisions form the manifest; held stock does not depend on GET being called.
- [x] Extend queue listing so new-policy child outgoing rows become one WO task, without hiding legacy rows or unresolved failed batch/NCR links. Status filters and counts use the same grouping, including mixed batch states. Do not merge return or operation inspections.
- [x] Run `php artisan test tests/Feature/Quality/FinishFirstInspectionCreationTest.php tests/Feature/Quality/OutgoingQcIdempotencyTest.php tests/Feature/Quality/SweepMissingOutgoingQcTest.php tests/Feature/Quality/InProcessQcTriggerTest.php`.

## Task 4: Full-piece evidence, PASS ALL and independent review

**Files:** piece models, final-QC service/requests/controller, `InspectionService.php`, `Inspection.php`, review/batch requests and resources. Create `api/tests/Feature/Quality/FullPieceWorkOrderInspectionTest.php`.

**Interfaces:** evidence saves use a complete manifest plus one bounded batch page; final submit reads the entire persisted evidence, not a client-provided outcome.

```json
{
  "manifest": [{"output_id":"output-hash","inspection_id":"batch-inspection-hash","physical_quantity":200,"result_version":0,"specification_revision_id":"revision-hash"}],
  "inspection_id": "batch-inspection-hash",
  "expected_result_version": 0,
  "defective_quantity": 2,
  "pieces": [
    {"piece_number": 7, "problem_description": "Short shot"},
    {"piece_number": 8, "problem_description": "Crack near mounting hole"}
  ],
  "readings": [
    {"piece_number": 7, "requirement_id": "pinned-check-hash", "measured_value": "12.4500"}
  ]
}
```

Use the authoritative manifest from Task 3's response for each real request. `pieces` and distinct reading piece ordinals are bounded to 50 per request. Full quantity can exceed 50. The `defective_quantity` is the final batch count; partial drafts may contain fewer described defects, but submission requires equality. Successful saves return the updated manifest/version; the client uses that response for the next page.

```json
{
  "manifest": [{"output_id":"output-hash","inspection_id":"batch-inspection-hash","physical_quantity":200,"result_version":1,"specification_revision_id":"revision-hash"}],
  "batches": [
    {"inspection_id": "batch-inspection-hash", "coverage_confirmed": true, "inspected_quantity": 200}
  ]
}
```

Review body carries the current manifest and remarks, not a user-selected PASS override. Each child review decision comes from that child's server-calculated submitted outcome.

- [x] Write failing tests covering zero defects plus explicit full confirmation, 5 descriptions for 5 defects, missing/duplicate/out-of-range ordinals, whitespace/overlong problems, overlapping pages, fractional/negative/excess quantities, forged requirement/source IDs, missing all-piece readings, multiple failed readings on one piece, stale manifest/result versions and unauthorized roles.
- [x] Implement page upsert for the current draft under locks. Pin units/tolerances through the referenced immutable check; parse readings using the existing fixed decimal rules and evaluate with existing measurement logic. Do not permit tolerance/result changes in requests. Record every contributing author for independence checks. Editing a confirmed draft clears coverage confirmation; submitted/terminal evidence cannot be overwritten.
- [x] On final submission, verify every batch quantity, all distinct piece/readings ranges, full dimension coverage, defective-list count and correspondence between out-of-tolerance pieces and defects. Reject zero-defect submission when any reading fails. Require a description for each identified defective piece; distinguish a missing requirement/calibration blocker from a physical defect count.

```php
$failedPieceNumbers = $readingFailures->pluck('piece_number')->unique();
if ($failedPieceNumbers->diff($defectivePieceNumbers)->isNotEmpty()) {
    throw new BusinessRuleException('Describe each piece with a failed size reading.');
}
$proposed = $defectivePieceNumbers->isEmpty() ? 'passed' : 'failed';
// Submit editable children in one transaction; finalized children stay read-only.
```

- [x] Add the version-2 branch to shared record/measurement/review boundaries so alternate single-batch endpoints cannot bypass full coverage or immutable evidence. Preserve version-1 counted and legacy contracts. Reuse existing `submitOutcome()` and `review()` logic for maker/checker, calibration, NCR and release events.
- [x] Implement group review atomically. Require active QC-review permission and reviewer independence from every inspector/evidence author of children being reviewed. Mixed PASS/FAIL children receive their corresponding derived decisions. The manifest includes finalized children, but submit targets only editable children and review targets only children awaiting review. Reject stale source membership or any insufficient child; release nothing partially from a failed transaction. Test adding a late child after existing children are finalized; never resubmit or rereview those final results.
- [x] Run `php artisan test tests/Feature/Quality/FullPieceWorkOrderInspectionTest.php tests/Feature/Quality/InspectionMakerCheckerPolicyTest.php tests/Feature/Quality/InspectionCalibrationEvidenceTest.php tests/Feature/Quality/CountedBatchInspectionTest.php`.

## Task 5: Hold, segregate, repair, scrap and replace without duplicate pieces

**Files:** stock/recovery callers in the map, shared release predicate, `WorkOrderService.php`, `WorkOrderReworkService.php`. Create `api/tests/Feature/Quality/FinishFirstDefectRecoveryTest.php`; extend replacement ownership and connection tests.

**Interfaces:** preserve `Inspection::canReleaseOutgoing()`, `hasResolvedOutgoingDisposition()`, `NcrService::setDisposition()`, and `WorkOrderReworkService::finish()`. Their versioned source is the entire physically produced batch, not a sample. QC accepted quantity remains separate from original output count.

- [ ] Write failing assertions for 200/5: before sorting approval available=0; after approved segregation accepted=195 and defective-held=5; after repair/reinspection accepted=200 with no added original produced pieces; after scrap plus replacement physical-new=205, accepted=200, scrap=5. Also test 200 defects, 0 conforming, and repair 3/scrap 2/replacement 2.
- [x] Verify pending/missing QC remains held through automatic/manual receipt, internal consumption, transfer, delivery reservation/dispatch, CoC generation and MRP stock availability. Keep physical receipt and GL owners; do not mutate original output source count to implement QC acceptance.
- [x] Allow existing full-count evidence to satisfy sorting coverage after explicit batch segregation. Save sorting performer, physical segregation reference and independent approver. Original failed inspection remains FAIL; do not require a second full inspection just to copy known defects into NCR. Refuse early conforming release before that approval.
- [x] Defer outgoing repair inspections for this workflow until `WorkOrderReworkService::finish()` has recorded the full confirmed repair quantity. Keep repair-source consumption and repaired-lot receipts atomic and distinct from new production. Never increment root manufactured quantity for inline repair; replacement rows remain new manufacture.
- [x] Audit MRP and NCR replacement ownership under full-source batch quantities. Pending disposition/authorized repair must remain commitments; scrap or unsuccessful repair produces only the actual shortage once. Keep originating sales-order item lineage and account for shipped/returned quantities before additional release.
- [x] Update close checks for versioned QC coverage: no startup inspection requirement; every physical output has resolved outgoing evidence; open required NCR actions, pending reports, unsettled stock/GL or missing correction QC prevent close. Defect-only disposal can resolve a source without pretending it has conforming stock; shortage remains visible.
- [x] Run `php artisan test tests/Feature/Quality/FinishFirstDefectRecoveryTest.php tests/Feature/Quality/ReplacementProductionOwnershipTest.php tests/Feature/Quality/WorkOrderQcConnectionTest.php tests/Feature/Quality/WorkOrderInlineReworkTest.php tests/Feature/Quality/QualityReworkReinspectionTest.php`. Verify transaction/replay conservation, not only a PASS result.

## Task 6: Finished production stays reportable after access expires

**Files:** late-report owners/resources/requests in the file map; final-QC service. Extend `api/tests/Feature/Production/LateProductionReportTest.php` with explicit new-policy fixtures; create `FinishFirstLateProductionTest.php` in the same directory.

**Interfaces:** retain `LateProductionReportService::propose()` and `approve()`, durable source reference, historical crew and timestamp contracts. New-policy forms use actual produced quantity without declaring it QC-approved. Management approval posts once and invokes final-QC creation only when physical production is completed.

- [x] Write tests for a former recorder after schedule expiry/time-out; approved report before completion joins eventual QC; approval after completion adds a new held child inspection; original submitted/approved batches retain their snapshots; a stale group submit/review rejects atomically; pending reports block close.
- [x] Branch `postLateReport()` for new policy so absence of startup QC is expected, not a process deviation or a trial sample. Preserve actual known attendance/resource conflicts, unresolved materials, management independence and historical evidence explanations. Record physical/mold effects and stock handoffs exactly once.
- [x] Preserve closed-WO correction authorization and allow final inspection of only the newly posted correction output. Never reopen/rewrite previous terminal inspection evidence or previously shipped stock. New correction stock stays held; further release accounts for delivered quantity. Cancelled sources stay blocked.
- [x] Reject duplicate source references, overlapping reports, future/pre-start intervals and inconsistent recorded totals. Current equipment occupancy or expired current attendance does not reject valid completed history. Management cannot use late recording to authorize new production retroactively.
- [x] Run `php artisan test tests/Feature/Production/FinishFirstLateProductionTest.php tests/Feature/Production/LateProductionReportTest.php tests/Feature/Quality/HistoricalHeldRecoveryTest.php`. Confirm legacy pre-startup deviations still create their established holds/NCRs.

## Task 7: Clear recorder, QC and recovery pages

**Files:** SPA files and new page/component from the map; register using the repository's existing route loader and permissions in `spa/src/App.tsx`. Add `WorkOrderFinalInspectionPanel.test.tsx`; extend existing list/detail/output/NCR tests.

**Interfaces:** API types from Tasks 2–4. Add `getWorkOrderFinalInspection`, `saveWorkOrderFinalEvidence`, `submitWorkOrderFinalInspection`, `reviewWorkOrderFinalInspection` to `spa/src/api/quality/inspections.ts`. Reuse house number inputs, checkbox, table paging, loading/error/empty states, draft autosave, unsaved changes guard and pending-submission protection.

- [x] Write component tests before changing screens: new-policy worker sees **Record production** with quantity made; no startup sample panel; legacy worker retains sample panel; historical reporting remains reachable after live access expires. One clear owner/action for each blocker, not duplicate notices.
- [x] Build one WO final-QC screen with total produced, checked progress, defective and conforming counts. Show output batches collapsed by default; require batch allocation only when multiple batches contain defects. At most 50 piece/readings rows per page; save progress on the server.
- [x] PASS ALL fills zero defects only after the user invokes it. Separate explicit **I checked all 200 pieces** confirmation remains unchecked initially and on draft restore; missing readings disable final submission. Defects open exact problem rows, with **Apply this problem to selected pieces**. Numeric failures identify piece/requirement and preserve typed values.

```typescript
const canSubmit = fullCoverageConfirmed
  && describedDefectivePieces === defectiveQuantity
  && missingRequiredReadings === 0
  && !saving
  && !pendingOutcome;
// Server evidence remains authoritative; disabled buttons are not validation.
```

- [x] Preserve drafts across paging, blocked responses and reloads. During unknown submit outcomes retain the original payload and prevent fresh submission; reconcile read state first. Do not discard typed descriptions when a background query refreshes. Remove stale-manifest confirmation and show **Production changed. Reload inspection totals.**
- [x] Display reviewed PASS/FAIL and one recovery action. Failed batch shows **5 defective · 195 conforming** and **Segregate defects for release approval**. Repair/scrap choices remain in existing NCR/rework pages, with explicit quantities and linked reinspection. No per-piece PASS/FAIL toggles.
- [x] Update WO list/detail quality labels and queue counts, recorder quantity labels, late-report forms and manager finish action. Current active/history duplicate-row polish is outside this plan unless needed to make historical reporting usable; do not redesign unrelated self-service navigation.
- [x] From `spa/`, run `npm run test:run -- src/pages/quality/inspections/components/WorkOrderFinalInspectionPanel.test.tsx src/pages/quality/inspections/detail.test.tsx src/pages/self-service/work-orders.test.tsx src/pages/production/work-orders/detail.test.tsx src/pages/production/work-orders/record-output.test.tsx src/pages/quality/ncrs/detail.test.tsx`. Then run `npm run build` and targeted lint for changed files.

## Task 8: Compatibility gate and later role-based defective trials

**Files:** create `spa/e2e/finish-first-wo-qc.spec.ts`, `docs/testing/finish-first-wo-qc-20261008.md`; use existing `spa/playwright.qc.config.ts`/isolated harness pattern. No live fixture writes.

- [x] Run the backend/SPA gates from Tasks 1–7 with disposable database settings and activation disabled, then enabled for new fixtures. Run legacy startup, outgoing, historical-held, routing, receiving/returns, certificate and replacement tests whose callers changed. Record exact commands and results; do not treat an empty filtered suite as proof.
- [x] Record the zero-defect role walkthrough: production manager confirms/starts, recorder posts all 200, manager finishes, QC inspector supplies all coverage/readings and PASS ALL, independent reviewer releases, Warehouse sees release-ready quantities. Assert no QC existed before physical completion.
- [x] Prepare these defective trials for the user's later test session; do not execute business defect fixtures in the current planning turn:

| Trial | Required expected behavior |
| --- | --- |
| 200 made, 5 defective | Five unique descriptions; FAIL; full batch held until segregation approval; then 195 release, 5 held. |
| Repair all 5 | Repair/reinspection after repair finishes; accepted fulfillment 200; original manufactured count unchanged. |
| Scrap all 5 | Five scrap movements accounted once; exactly five replacements, no duplicate NCR/MRP WO. |
| Repair 3, scrap 2 | Two replacements only; accepted fulfillment eventually 200. |
| All 200 defective | Zero release; no fake good stock; recovery remains possible. |
| One piece fails two dimensions | One defective piece, two reading failures, one problem row with both problems. |
| Two output batches, one fails | One WO task; each batch keeps its outcome, hold and lineage. |
| More than 50 pieces/defects | Paging and saved progress; all pages validated; no enormous browser form/request. |
| Late whole production | Former recorder submits real times; management approves; final QC follows finish without run extension. |
| Added late batch during QC | Stale submission rejected; approved earlier batch evidence unchanged; new batch held. |
| Closed correction | Authorized traceable new output/QC only; no rewrite of old delivery or accounting evidence. |
| Unknown save outcome/retry | Original receipt/result reconciled; no duplicate piece, inspection, receipt or release. |
| Wrong worker/self-review | Forbidden; no quantity, stock or result changes. |

- [x] Deployment sequence: additive migration, compatible API/SPA/worker build with switch off, restart compatible workers, validation, enable eligible new creation. Do not roll back to a pre-feature build after new-policy evidence exists; disable only new adoption and retain compatible readers. Document migration refusal and legacy/new preservation evidence.
- [x] Stop when the accepted workflow and conservation/role/compatibility gates pass. Exclude serial-number scanning, machine vision, sampling changes for other stages, scheduler redesign, automatic defect diagnosis and unrelated dashboard cleanup.

## Execution notes

Implementation and compatibility proof are recorded in `docs/testing/finish-first-wo-qc-20261008.md`. Related cases were consolidated into existing and new test files instead of duplicating every proposed test class. The real role walkthrough starts from a confirmed disposable WO. Automated assertions cover defective evidence, 195/5 segregation, all-defective zero release, mixed repair/scrap, legacy replacement ownership, and late terminal preservation. The full repair-five and scrap/replacement fulfillment walkthroughs remain for the later user test session; their combined scenario checkbox remains open. No live defect fixture was created.

The final compatibility audit found and fixed certificate support for version-two evidence and recovery-sweep protection for unfinished inline repair. Both use existing shared source/release validation.

## Planning self-review

- Requirements map: finish before QC and quantity labels (2, 3, 7); full pieces/readings and PASS ALL (4, 7); unique descriptions and bounded entry (1, 4, 7); independence/stock/recovery/replacement (4, 5); late finished work and source changes (2, 6); compatibility and mandatory routing (1, 2, 8); later defective testing (8).
- Shared release callers enumerated: inventory movement, QualityStockHoldService, delivery source/reservation, CoC, close, CRM and MRP. No new release bypass is authorized.
- Grouping preserves existing per-output unique inspections and NCR lineage; no new parent QC record or rewritten terminal source snapshots.
- Tests listed here are required during implementation, not evidence that implementation already exists.
