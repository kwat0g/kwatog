# Quality PASS/FAIL Transition Implementation Plan

> **For agentic workers:** Use `superpowers:executing-plans` to implement this plan task by task. Work inline by default. Do not dispatch agents or stage unrelated workspace changes. Steps use checkbox syntax for tracking.

**Goal:** Make new inspections in all five stages use one PASS/FAIL decision per checklist check, with problem input on FAIL, while preserving trustworthy stock release, review, production closure and historical evidence.

**Architecture:** Reuse inspection specifications, item/supplier quality plans, inspection rows, NCR/MRB disposition and existing events. Add an explicit evidence distinction and immutable written criteria; do not replace the QC module or introduce another workflow engine. Publish checklist revisions, capture their results atomically and retain the existing numeric contract for historical records.

**Tech Stack:** Laravel/PHP, PostgreSQL, React/TypeScript, React Query, existing Zod/forms and Atelier components, PHPUnit, Vitest and headless Playwright. No new dependencies.

**Spec:** [Inspection specifications and PASS/FAIL audit](../../testing/quality-pass-fail-audit-20261006.md), including findings Q1–Q11. The policy choices below resolve its recommendations into an implementation contract.

**Implementation status (2026-10-06):** Tasks 1–11 delivered and reviewed. Verification suggestions were consolidated into existing suites and the existing real-backend browser harness. The original detailed checkboxes remain a design checklist, not a claim that every suggested standalone test file or stress scenario was created. [Implementation evidence](../../testing/quality-pass-fail-implementation-20261006.md) records actual checks, limitations, retained artifacts and rollout.

| Task | Status | Evidence |
|---|---|---|
| 1–2: Schema and authoring contract | Complete | Migration, definition and item-plan regression suites. |
| 3–4: Creation and atomic capture | Complete | Five-stage browser transactions; source ownership, stale edits, unique defects and HTTP boundary tests. |
| 5–6: Warehouse, WO and returns | Complete | Incoming MRB/stock regression, canceled-QC gate, WO QC/rework and return handoff suites. |
| 7: Certificates and capability | Complete | Legacy certificate integrity suite; downloaded/rendered checklist PDF; SPC exclusion. |
| 8–9: Authoring and inspection interface | Complete | 32 component checks, three headless browser checks, mobile/theme screenshots, token/contrast checks and production build. |
| 10: Legacy replacement | Complete | History, retry identity, known-failure rejection and RMA-link regression checks. |
| 11: Verification and rollout | Complete | 201 backend tests/843 assertions, final 21-test boundary run, real role contexts, reviewed focused diff and compatible feature enablement. |


## Global constraints

- All five stages: `incoming`, `in_process`, `outgoing`, `customer_return`, `supplier_return`.
- No nominal/minimum/maximum or numerical measurement input in any new daily QC stage.
- One result per check rather than per physical piece; unanswered checks cannot pass.
- Critical failures remain blocking; failed stock cannot be released by cancellation.
- Counts/UoM and sampled/full coverage remain truthful; certificates print the evidence actually recorded; old records stay reconstructable.
- Preserve source/party ownership, result authors, terminal immutability, immutable revisions, unique output inspection identity, durable events and current maker/checker policy.
- Preserve the record flow on top, details above the checklist, linked records and activity. Do not remove important source or disposition information.
- Reuse the existing theme and semantic colors. QC decision buttons must be at least 44px high and 44px wide; no page-level horizontal overflow at 390px or 1440px.
- Preserve existing dirty workspace changes. No bulk database reset, live demo transactions, password changes, unrelated refactors, staging or commits as part of this plan.
- Implementation completed on 2026-10-06. See the linked implementation evidence for verification scope and rollout status.

## Product decisions

### Specification and inspection experience

| Surface | New behavior |
|---|---|
| Product specification | Check name, written acceptance criterion/reference, Critical toggle and optional revision notes. |
| Item/supplier quality plan | Same check fields; keep supplier scope, effective dates and sampling configuration in the plan, outside daily capture. |
| Daily inspection | Read-only source, quantity/UoM, lot/batch, checklist revision and planned coverage. Each check shows criterion and PASS/FAIL. |
| Failed check | Reveal a required problem description, maximum 500 characters. Preserve description when navigating among checks. |
| Entire sample passed | Confirm actual coverage, then submit. No separate zero, nominal, measurement or batch PASS entry. |
| Defective pieces | After FAIL, answer “Any defective pieces?” with Yes/No. Yes reveals the unique count once; No records zero for document/reference failures. Count the same piece once even when several checks fail. |
| Document/reference failure | Allow zero defective pieces. The failed check still makes the inspection FAIL. |
| Long checklist | Keep local search and Needs attention. Offer “PASS remaining checks” only with an explicit performance attestation; show the number it will answer and never overwrite FAIL. |
| Review | Retain the existing independent review requirement where applicable. Show check evidence and source context; no numeric fields for checklist evidence. |
| Historical records | Read-only measurement evidence and historical certificates remain available. No silent conversion or removal of old limits. |

Names such as “Diameter” are insufficient criteria. A valid example is “Fits the approved GO/NO-GO gauge according to drawing WB-001 revision B.” Do not invent that drawing, gauge or approval when converting real specifications. New checks may leave the internal dimensional/visual/functional classification unset; preserve the classification when revising existing checks. Do not label every new check Visual.

### Verdict, sampling and stock disposition

New checklist evidence uses a conservative, explicit rule: any failed check, physical defect or critical defect produces inspection FAIL. Existing sampling policy may determine the planned sample size, but positive legacy Ac values must not turn a submitted FAIL into PASS. Pin new checklist acceptance as zero observed defects (`accept_count = 0`, `reject_count = 1`); describe coverage plainly, without claiming that this is the unchanged legacy AQL acceptance plan. Historical measurement inspections retain their pinned acceptance rules.

FAIL and approved stock disposition are separate facts. Reviewed full sorting may release proven good quantities through the existing NCR/MRB workflow without rewriting the original inspection to PASS. A sample containing one defective piece does not establish that the other 99 pieces are conforming.

| Stage | Source and coverage | Passing outcome | Failing outcome |
|---|---|---|---|
| Incoming | Exact GRN line; item/supplier plan or existing default checklist. Piece goods use planned sampling; bulk goods use a lot decision in their receipt UoM. | Existing independent review precedes stock acceptance. | Hold receipt stock; existing NCR/MRB determines sorting, concession, rework or supplier return. |
| In-process | WO/operation; existing configured sample size. | Satisfy that process-quality gate. | Notify production through existing events; block closure while required failure/recovery remains open. |
| Outgoing | Exact WO output batch; existing planned sample size. | Independent review, existing held-stock release and subsequent delivery eligibility. | Hold affected output; existing inline rework/sorting process applies. No automatic new WO merely because capture changed. |
| Customer return | RMA product and received quantity; retain existing return coverage policy. | Permit the applicable reviewed return disposition, not automatic credit/restock. | Retain return hold and reviewed disposition. Protected delivery-attempt returns still require independent review. |
| Supplier return | RMA/source receipt and product/item; retain current explicit exemptions. | Continue applicable supplier-return handling. | Continue reviewed handling and source-owned stock/finance adjustment; no automatic supplier credit. |

Keep `Inspection::requiresMakerChecker()` policy unchanged: incoming GRN inspections; outgoing output/WO/delivery inspections; protected customer returns connected to a delivery-attempt outcome. Ordinary returns and in-process inspections must not gain an invented approval role.

### Quantities without measurement work

- Piece source: `source_quantity` is the authoritative source quantity, `sampling_basis = piece`, and current integer sample/count fields represent pieces. Actual coverage defaults to the planned sample but must be attested. “Inspected more?” reveals an optional count bounded by source quantity.
- Bulk or uncertain unit source: retain exact base quantity and base UoM, set `sampling_basis = lot`, and use `batch_quantity = sample_size = 1` as one inspected lot. Label that as a lot, never one piece or one kg. Existing plan metadata is retained, but AQL piece counts are not inferred from kg, liters, bags or conversion ratios.
- For incoming stock, only canonical base `PCS` is automatically treated as pieces. Other UoM codes use lot handling until an existing authoritative piece-count classification is demonstrated. Do not invent a UoM synonym catalog.
- In lot handling, a physical failure means one affected lot; document-only failure may have zero physically defective lots. Neither count is subtracted from received kg/liters. Partial acceptance uses existing MRB quantities in the source base UoM.
- No generic packaging conversion feature or additional quantity ledger. Reuse `GrnItem::quantity_received`, `Item::unit_of_measure`, existing conversions and MRB accepted quantities.

## Data and API contract

All existing records default to `capture_method = measurement`. New checklist revisions/plans/inspections explicitly use `capture_method = checklist`. This differs from `inspection_mode`, which only controls row shape.

| Owner | Additive fields / change | Purpose |
|---|---|---|
| `inspection_spec_revisions`, `item_quality_plans`, `inspections` | `capture_method` constrained to `measurement` or `checklist`; default `measurement` | Pin evidence semantics; do not infer them from missing numeric values. |
| `inspection_spec_items`, `inspection_measurements` | Nullable `acceptance_criterion`, maximum 500 characters; make `parameter_type` nullable | Immutable governing instruction; new checks need no misleading classification. Numeric fields remain for history. |
| `inspections` | Nullable `source_quantity decimal(15,3)`, `source_uom_code varchar(20)`, `sampling_basis` (`piece`/`lot`) | Retain source quantity and distinguish count units. Required at the service boundary for new checklist inspections. |
| `inspections` | `result_version` nonnegative integer, default 0 | Reject stale result edits under the existing row lock. Do not rely on second-resolution timestamps. |
| `inspections` | Nullable unique `replaces_inspection_id`, self foreign key with restrictive deletion | Trace controlled replacement and make replacement retries idempotent. |

Keep current numeric window/type constraints valid for legacy rows. Extend their allowed null classification only; do not globally remove integrity constraints. Add `acceptance_criterion` to the immutable spec-item attributes. Snapshot it into inspection rows separately from defect notes.

Extend existing `POST /quality/inspections/{inspection}/batch-result`; no additional result endpoint:

```ts
type ChecklistSubmission = {
  expected_result_version: number;
  items: Array<{
    id: string; // Existing measurement-row hash ID; one row per check.
    result: 'passed' | 'failed';
    problem_description?: string;
  }>;
  coverage_confirmed: true;
  inspected_quantity?: number; // Omitted means the attested planned coverage.
  physical_defects_confirmed?: boolean; // Required after any FAIL/batch defect report.
  sample_defect_count?: number; // Required for confirmed physical piece defects.
  problem_description?: string; // Batch-only physical defect outside named checks.
};
```

Derive verdict and criticality on the server. For checklist capture, reject client-supplied `result`, `critical_defect`, `defects_removed_confirmed`, nominal, tolerance and measured-value fields. A FAIL never requires the inspector to repeat a description already present on a failed check. Aggregate those descriptions for NCR evidence. A batch-only defect with all named checks passed requires its own description and confirmation of physical defects. For pieces, Yes requires a positive unique count; No permits only zero/omitted count. For lots, Yes derives one affected lot and No derives zero physically affected lots; neither asks the inspector to count kilograms. All-PASS, no defect report and coverage attestation derive zero without an additional question.

The existing draft `item-results` endpoint remains optional for “Save draft,” with `expected_result_version` required for new checklist records. One submit request contains the complete checklist and coverage. Reviewer requests also include `expected_result_version` for checklist records. Preserve the legacy request contracts for measurement records.

Every successful checklist draft, submit, review or cancellation increments `result_version` exactly once. A stale request returns a field-mapped 422: `This inspection changed. Reload before submitting.` Terminal or awaiting-review records cannot be overwritten. After an uncertain network response, fetch the authoritative record; do not blindly retry a terminal mutation.

## Implementation map

| Responsibility | Existing files to reuse |
|---|---|
| Evidence shape / snapshots | Quality models, resources and `spa/src/types/quality.ts`; inventory plan types in `spa/src/types/inventory.ts`. |
| Specification validation and revisions | `UpsertInspectionSpecRequest`, `InspectionSpecService`, `ItemQualityPlanController`, `ItemQualityPlanService`. |
| Creation / result / review | `InspectionService`, existing requests and `InspectionController`; no second result service. |
| Warehouse release | `GrnService`, `QualityStockHoldService`, `NcrService`; retain existing MRB ownership. |
| Production and returns | `WorkOrderService`, `ReturnRequestService`, existing trigger/reinspection listeners and source factories. |
| Certificates / historical capability | `CoCService`, `pdf/coc.blade.php`, existing `SpcService`. |
| Authoring UI | Product specification editor and item quality-plan page. |
| Capture UI | `InspectionChecklistPanel`, inspection create/detail and existing authenticated API client. |
| Proof | Existing PHPUnit/Vitest suites; separate real-backend Playwright configuration, because the default Playwright setup mocks APIs. |

Read complete touched files and their callers before implementation. This map assigns responsibilities; it does not authorize splitting unrelated large services.

## Task 1: Expand the schema and expose explicit evidence metadata

**Files:** Create `api/database/migrations/2026_10_06_200000_add_checklist_evidence_contract.php` and `api/config/inspection.php`. Modify Quality models `InspectionSpecRevision.php`, `InspectionSpecItem.php`, `InspectionMeasurement.php`, `ItemQualityPlan.php`, `Inspection.php`; resources `InspectionSpecResource.php`, `InspectionSpecRevisionResource.php`, `InspectionMeasurementResource.php`, `InspectionResource.php`; existing Quality options controllers; `spa/src/types/quality.ts`, `spa/src/types/inventory.ts`, `api/.env.example`. Test: new `api/tests/Feature/Quality/ChecklistEvidenceMigrationTest.php`.

**Interfaces:** Produce the fields in the contract table and resource fields with identical names. Decimal quantities and legacy decimal evidence are strings or null in TypeScript, matching current serialization. Nullable classification must serialize as null, not an empty string. Options expose `checklist_capture_enabled` to prevent a paused editor from offering incompatible publication. `Inspection::usesChecklistCapture(): bool` compares the explicit field, not numeric values.

- [ ] Write a migration preservation test with existing numeric spec/revision, rows, decisions and certificate path. After migration, assert numeric values/results unchanged, default capture is measurement, new metadata round-trips, invalid capture/sampling values fail constraints, and new null classification is valid only with valid remaining field shape.
- [ ] Run the new test with the dedicated configuration; confirm failure before implementation.
- [ ] Add columns and casts. Preserve existing constraints and revision lineage; constrain capture values and nonnegative versions. Extend nullable classification constraints without inventing a fourth enum classification.

```php
// api/config/inspection.php
return ['checklist_capture_enabled' => (bool) env('QC_CHECKLIST_CAPTURE_ENABLED', false)];

// Inspection model; legacy default is deliberately not checklist.
public function usesChecklistCapture(): bool
{
    return $this->capture_method === 'checklist';
}
```

- [ ] Keep checklist creation/publishing paused by default until downstream gates are ready. When paused, continue servicing existing checklist records with compatible code; do not coerce them into measurement evidence.
- [ ] Run the migration test and existing `InspectionSpecBoundaryTest`/`InspectionMeasurementContractTest`. Review the focused diff and verify additive schema rollback on an isolated disposable database before any checklist data exists.

## Task 2: Publish written product and supplier-plan checklist revisions

**Files:** Modify `api/app/Modules/Quality/Requests/UpsertInspectionSpecRequest.php`, `Services/InspectionSpecService.php`, `Controllers/InspectionSpecController.php`, `Controllers/ItemQualityPlanController.php`, `Services/ItemQualityPlanService.php`. Create `api/app/Modules/Quality/Support/ChecklistDefinition.php` and `Requests/StoreItemQualityPlanRequest.php`. Tests: `InspectionSpecBoundaryTest.php`, `ItemQualityPlanTest.php`.

**Interfaces:** Preserve `upsertForProduct(int $productId, array $items, int $userId, ?string $notes = null)` compatibility by adding optional final `string $captureMethod = 'measurement'`. Controller new authoring sends `checklist` explicitly. Reuse `ItemQualityPlanService::createRevision(Item $item, array $data, User $user)`. `ChecklistDefinition::validate(array $items): array` returns normalized check definitions or throws field-mapped validation errors; both request paths and direct service writers call it.

- [ ] Add tests for nonblank check and criterion, normalized duplicate names, 500-character criterion limit, empty checklist, prohibited numeric fields, immutable criteria and preserved prior revision. Add a supplier-specific revision test that does not deactivate unrelated supplier plans or overlapping effective windows silently. Start shared validation proof with this runnable unit test in `api/tests/Unit/Quality/ChecklistDefinitionTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Quality;

use App\Modules\Quality\Support\ChecklistDefinition;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ChecklistDefinitionTest extends TestCase
{
    public function test_same_check_cannot_appear_twice_with_different_spacing(): void
    {
        $this->expectException(ValidationException::class);
        ChecklistDefinition::validate([
            ['parameter_name' => ' Surface   condition ',
             'acceptance_criterion' => 'No cracks or contamination.', 'is_critical' => false],
            ['parameter_name' => 'Surface condition',
             'acceptance_criterion' => 'Compare with the approved sample.', 'is_critical' => false],
        ]);
    }
}
```
- [ ] Run those new cases; confirm failure.
- [ ] Centralize only the shared definition validation, not a general validation framework. Retain existing numeric validation for historical measurement revisions. Product payload uses `items`; supplier-plan request maps errors to `parameters`.

```php
$name = preg_replace('/\s+/u', ' ', trim((string) ($row['parameter_name'] ?? '')));
$criterion = trim((string) ($row['acceptance_criterion'] ?? ''));
// Use the request's existing normalized duplicate-name behavior for both paths.
// Reject blank names/criteria, duplicate names, numeric keys and invalid boolean values.
$snapshot = [
    'parameter_name' => $name,
    'acceptance_criterion' => $criterion,
    'parameter_type' => $row['parameter_type'] ?? null,
    'nominal_value' => null,
    'tolerance_min' => null,
    'tolerance_max' => null,
    'unit_of_measure' => null,
    'is_critical' => (bool) ($row['is_critical'] ?? false),
];
```

- [ ] Preserve preexisting classification only through an explicit validated value; do not guess type from check names. Lock the owning product/item before revision allocation, including first publication, and publish metadata/items in the existing transaction. A future-effective supplier revision must retain current coverage until its own effective boundary; close only intersecting same-scope windows using actual dates.
- [ ] Run specification and plan tests. Review that no historical revision is updated or deleted and no new check receives an invented acceptance reference.

## Task 3: Create one checklist row per check for every source and stage

**Files:** Modify `api/app/Modules/Quality/Services/InspectionService.php`, `api/app/Modules/Quality/Enums/InspectionMode.php`, `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php` only where its source bridge needs explicit metadata. Tests: new `ChecklistStageCreationTest.php`; existing `IncomingQcTriggerTest.php`, `InProcessQcTriggerTest.php`, `OutgoingQcIdempotencyTest.php`, `QualityReworkReinspectionTest.php`, `ReturnInspectionHandoffTest.php`.

**Interfaces:** Retain `create(...)`, `createIncomingForItem(...)`, `createIncomingFromPlan(...)`, `createForOutput(...)` and current source factories. Their checklist branch snapshots criterion, capture, source quantity/UoM and sample basis. Product specs/quality plans determine evidence; default incoming checks use their existing full written instruction as criterion. A legacy numeric definition never becomes checklist merely because a global flag is enabled.

- [ ] Add a five-stage creation test: 100-piece source, five checks, five stored rows, all unanswered, correct source identity and revision. Include exact output idempotency, two products on a return, late revision publication and default incoming checklist.
- [ ] Add incoming `125.500 KG` fixture: snapshot `source_quantity = '125.500'`, `source_uom_code = 'KG'`, `sampling_basis = 'lot'`, integer batch/sample both 1. Do not generate 126 pieces. Include base `PCS` received through a configured box conversion and source quantity exceeding integer bounds; reject unsupported count size rather than truncate.
- [ ] Run creation tests, then add one checklist branch inside current scaffolding. Keep legacy per-unit/multi-reading scaffolding unchanged.

```php
if ($captureMethod === 'checklist') {
    $mode = InspectionMode::LotChecklist;
    $rows = array_map(static fn (array $check): array => [
        ...$check,
        'sample_index' => 1,
        'measured_value' => null,
        'is_pass' => null,
        'item_result' => null,
        'item_problem_description' => null,
    ], $approvedChecks);
    // Persist in the existing source-owned creation transaction.
}
```

- [ ] Snapshot criteria for all paths, including returns and reinspection. If an active checklist revision is missing criteria or the feature is paused, return a specific setup blocker; never create an empty approved checklist or fall back to automatic PASS. Keep existing explicit incoming eligibility/exemptions.
- [ ] For source-linked records, quantity, product/item, party, stage and lot come from the locked source, not caller replacements. Unsupported stage/source combinations fail validation. An allowed unlinked diagnostic inspection cannot authorize warehouse release or satisfy a different WO/output gate merely because its product matches.
- [ ] Run stage, trigger, idempotency and reinspection tests. Verify row count is proportional to checks, not source pieces, for checklist capture.

## Task 4: Submit complete checklist evidence atomically and reject stale edits

**Files:** Modify `InspectionService.php`, `Requests/RecordInspectionBatchResultRequest.php`, `Requests/RecordInspectionItemResultsRequest.php`, `Requests/RecordMeasurementsRequest.php`, `Requests/RecordLotResultRequest.php`, `Requests/ReviewInspectionRequest.php`, `Controllers/InspectionController.php`, `spa/src/api/quality/inspections.ts`, `spa/src/types/quality.ts`. Tests: new `ChecklistSubmissionTest.php`; existing `InspectionLifecycleConcurrencyTest.php`, `InspectionResultAuthorsTest.php`, `InspectionMakerCheckerPolicyTest.php`.

**Interfaces:** Extend existing `recordBatchResult(Inspection $inspection, array $data, User $by): Inspection`. Extract the existing row-ownership/unique-check update loop into private `applyItemResults(...)` inside the same service so draft and atomic submit share validation without nested public mutations. Keep measurement-record routes compatible. New checklist writes require `expected_result_version`.

- [ ] Add tests for incomplete checks, foreign/duplicate hash IDs, whitespace FAIL problem, invalid counts/coverage, missing critical decision and client attempts to force PASS. A rejection must leave rows, authors, inspection version and downstream events unchanged.
- [ ] Add five-stage no-numeric submission; one piece fails two checks but count remains 1; document-only FAIL has count 0; all check PASS plus batch-only physical defect produces FAIL; declared count exceeds inspected pieces fails; physical-defect Yes without a positive piece count fails. Test saved FAIL cannot be silently bulk-overwritten and a correction to PASS requires an explicit single-check edit before submission.
- [ ] Add stale edit/review tests and an uncertain-response test: second terminal submit produces no duplicate NCR, receipt, stock release or event; authoritative GET gives the first result.
- [ ] Verify the source still owns the inspected batch and has not been canceled, replaced or changed incompatibly since creation. A source-quantity mismatch is a visible blocker requiring source reconciliation, not a silently recalculated sample or automatic acceptance.
- [ ] Run failing cases. Implement one outer transaction: lock source as required by existing ownership order, lock inspection/rows, compare version, validate whole payload, apply decisions, derive verdict/criticality, record author and coverage, increment version, then use existing outcome/finalization.

```php
if ((int) $data['expected_result_version'] !== (int) $locked->result_version) {
    throw ValidationException::withMessages([
        'expected_result_version' => 'This inspection changed. Reload before submitting.',
    ]);
}
$failedChecks = $rows->where('item_result', 'failed');
$failed = $failedChecks->isNotEmpty() || $uniqueDefects > 0;
$target = $failed ? InspectionStatus::Failed : InspectionStatus::Passed;
$locked->result_version++;
```

- [ ] Do not feed checklist groups into the legacy per-sample defect counter: two failed checks do not imply two different pieces. Use the reported unique count, critical failed checks and exact source basis.
- [ ] Checklist measurement and legacy alternate-complete routes return a clear 422 directing clients to checklist submission. Review revalidates complete checklist, coverage, critical evidence and author separation; it cannot override FAIL to PASS. Existing calibration validity still applies when actual referenced equipment is selected; it does not demand numeric readings for checklist evidence.
- [ ] Run submission/concurrency/author/review tests plus original measurement suites. Review that a stale or invalid payload cannot partially save decisions.

## Task 5: Close cancellation and unit-related stock release bypasses

**Files:** Modify `api/app/Modules/Inventory/Services/GrnService.php`, `api/app/Modules/Quality/Services/NcrService.php`, `QualityStockHoldService.php` only where source-quantity interpretation requires it. Tests: `api/tests/Feature/Inventory/GrnQcGateTest.php`, `Quality/IncomingQcLineSettleTest.php`, `IncomingMrbDispositionTest.php`, `IncomingLotChecklistTest.php`, `InspectionBatchRecoveryTest.php`; new `ChecklistReceiptUnitsTest.php`.

**Interfaces:** Retain GRN accept/partial-accept/settlement APIs and existing MRB disposition. `assertIncomingInspectionCoverage`, `assertQcGate`, `settleIncomingQc` and fast receive must share the rule that canceled QC alone never authorizes accepted quantities. For checklist lot basis, source base quantity determines NCR/hold amount; lot counts do not determine base-unit deductions.

- [ ] Replace the regression that accepts a GRN after canceling its only QC with a rejection assertion. Cover canceled historical sibling plus valid checked replacement, rejection of the whole line with zero accepted quantity, missing line coverage, awaiting review, forged manual acceptance and mixed receipt lines.
- [ ] Add `125.500 KG` PASS/review/full acceptance, FAIL/full hold, MRB-approved `120.000 KG` partial acceptance and wrong-UoM disposition rejection. Assert one defective lot does not turn into a `1.000 KG` deduction. Include `100 PCS`, one failed piece and reviewed sorting releasing only proven good pieces.
- [ ] Run failing cases, then fix shared service gates; preserve logistics rejection semantics for rejected quantities. When no inspection has failed but only canceled inspections remain, settlement stays blocked rather than treating that as conformance.

```php
// Before accepting any positive QC-eligible line quantity:
// require final independently checked conforming QC, or existing approved MRB.
// Canceled records contribute history, not release authority.
if ($inspection->usesChecklistCapture() && $inspection->sampling_basis === 'lot') {
    $affectedBaseQuantity = (string) $line->quantity_received;
    // Read partial acceptance from reviewed MRB; never subtract sample_defect_count.
}
```

- [ ] Update existing fast receive to submit checks and coverage atomically. Decimal receipts use lot handling and remain review-gated; do not enable Warehouse users to author QC or approve their own QC. Remove obsolete fractional-piece blockers only when the source-safe lot path and tests cover them.
- [ ] Search all consumers of `accepted_quantity`, `affected_quantity` and `sample_defect_count` within QC/Inventory/ReturnManagement. For new lot evidence, use source quantity for stock/NCR, and count fields only for displayed lot/sample evidence. Reuse existing decimal arithmetic; never cast monetary/quantity values to float.
- [ ] Run receipt, MRB, held-stock and recovery suites. Verify stock and GL quantities match before/after repeated settlement.

## Task 6: Keep WO, delivery and return gates intact

**Files:** Review/modify only necessary branches in `api/app/Modules/Production/Services/WorkOrderService.php`, `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php`, existing QC/production listeners and `InspectionService.php`. Tests: `WorkOrderQcConnectionTest.php`, `WorkOrderInlineReworkTest.php`, `CreateDeliveryDraftOnQcPassTest.php`, `ReturnInspectionHandoffTest.php`, `CustomerReturnRestockOnDisposeTest.php`, `SupplierReturnLifecycleTest.php`.

**Interfaces:** Current `requestQualityClose` and return inspection/disposition gates remain authoritative. Checklist evidence changes capture, not source lifecycle ownership. Existing durable events continue carrying truthful terminal result/source identifiers.

- [ ] Add WO/output test: maker PASS alone does not release, checker PASS releases only the correct output, open required in-process QC/NCR blocks closure, approved recovery and completed accounting permit existing automatic closure.
- [ ] Add FAIL/rework/reinspection chain: failed output stays held, production receives existing actionable notification, fresh checklist reinspection uses an approved revision, and old failure stays historical. Repeat events without duplicate holds, outputs or replacement orders.
- [ ] Add return test: five check decisions for a 100-piece product; QC completion alone does not restock/credit; source product and received amount remain scoped; protected depot return requires independent checker.
- [ ] Run tests and adjust only evidence-dependent checks. Do not weaken quantities, required QC, NCR closure, receipts/GL or delivery allocation guards to make tests pass.

```php
$this->assertSame('failed', $failedInspection->fresh()->recorded_result);
$this->assertNotSame('closed', $workOrder->fresh()->status->value);
// Existing recovery service records reviewed sorting/rework before quality-close retry.
// No direct work-order status update from checklist UI or reviewer controller.
```

- [ ] Run focused production/delivery/return tests. Inspect notification links, destination role permissions and actionable failure state, not just event dispatch counts.

## Task 7: Issue truthful checklist certificates and preserve numerical history

**Files:** Modify `api/app/Modules/Quality/Services/CoCService.php`, `api/resources/views/pdf/coc.blade.php`, `spa/src/pages/quality/capability/index.tsx`, `spa/src/pages/quality/inspections/detail.tsx`. Touch `SpcService.php` only to explicitly exclude new checklist evidence from numerical calculation. Tests: `CoCEvidenceIntegrityTest.php`, `QualityAnalyticsBoundaryTest.php`, new `ChecklistCertificateTest.php`.

**Interfaces:** Existing certificate endpoint/storage identity remains. Checklist certificate eligibility requires outgoing PASS, required maker/checker, complete criteria/decisions, attested coverage and existing release eligibility. Extend the existing flat `buildPayload` array with `capture_method`, `checklist_results`, `source_quantity`, `source_uom_code` and `inspected_quantity`; Blade uses this payload, not an undefined inspection model variable. Legacy certificates retain their numeric tables and stored files.

- [ ] Add tests: complete critical checklist PASS with no numeric readings is eligible; unanswered/failed/unreviewed checklist is not; legacy critical missing reading remains ineligible; original stored certificate is not regenerated with different evidence.
- [ ] Run failing cases and branch evidence assertion/rendering by pinned capture method.

```blade
@if (($capture_method ?? 'measurement') === 'checklist')
    {{-- Render Check, Acceptance criterion/reference and Decision. --}}
    {{-- Include actual coverage, source quantity/UoM, revision, inspector/checker. --}}
@else
    {{-- Retain existing historical Nominal / Tolerance / Actual range table. --}}
@endif
```

- [ ] Say “Planned sample inspected” or “Entire batch inspected” only when supported by actual stored coverage. Do not certify full inspection of 100 pieces when only a sample was examined. Retain normal legal/company wording without inventing a certification for attribute evidence.
- [ ] Hide SPC on new checklist records and numeric capability choices for checklist-only current revisions. Keep historical numeric capability accessible where supported; show “Not applicable to PASS/FAIL checks” instead of fake zero/empty capability cards.
- [ ] Run certificate and analytics suites; visually inspect a new checklist PDF and an unchanged legacy PDF.

## Task 8: Simplify both authoring forms and contain historical tables

**Files:** Modify `spa/src/pages/quality/inspection-specs/editor.tsx`, `spa/src/pages/inventory/items/quality-plans.tsx`, related existing API types/callers. Tests: `inspection-specs/editor.test.tsx`; create `inventory/items/quality-plans.test.tsx`.

**Interfaces:** New authoring sends `capture_method: checklist`, check name/criterion/critical, optional existing classification and revision notes. Preserve product metadata, supplier scope, sampling configuration and read-only revision history.

- [ ] Write component tests verifying no Nominal/Min/Max/measurement-UoM inputs, required criterion errors, duplicated normalized names, empty plan metadata error recovery, server field-error mapping, unsaved changes and named revision controls.
- [ ] Run failing tests and replace the nine-column table with compact check/criterion/critical rows using existing controls; use stacked rows on mobile and a restrained table on desktop. Keep history in a contained scroll region; no page minimum width.

```tsx
<label htmlFor={`criterion-${rowId}`}>Acceptance criterion / reference</label>
<Textarea id={`criterion-${rowId}`} maxLength={500} />
{/* Keep check name, critical toggle and existing accessible removal control. */}
```

- [ ] Show a clear legacy conversion preview: old classification/limits remain read-only reference; author must enter a meaningful criterion before publishing. Never concatenate numbers into an invented approved method. New editor does not silently copy stale validation fields.
- [ ] Label revision selector and notes, show loading/retry/empty/error states, use stable row IDs and retain focus after add/remove. Disable publication until source metadata and valid definitions are available.
- [ ] Run authoring tests, typecheck and token checks. Inspect 390px/1440px, keyboard focus and at least one long criterion and 20-check specification.

Historical revision views may display stored Nominal/Min/Max evidence inside their own scroll region. The prohibition applies to new authoring and daily checklist input; it does not authorize hiding historical evidence needed to reconstruct a result.

## Task 9: Polish checklist capture, conditional defect input and clear readiness

**Files:** Modify `spa/src/pages/quality/inspections/components/InspectionChecklistPanel.tsx`, `detail.tsx`, `create.tsx`, `spa/src/components/ui/Button.tsx` only to make existing touch-size promise true; `spa/src/api/quality/inspections.ts`. Tests: existing checklist/detail tests and new authoring tests from Task 8.

**Interfaces:** Checklist branch uses `ChecklistSubmission` from Task 4. Keep the legacy evidence renderer for explicit historical records. Source and accepted-stock context remain visible; no second batch PASS/FAIL choice.

- [ ] Add tests for PASS no extra fields, FAIL reveals named problem, optional batch-only defect, physical unique count once, document-only zero, optional increased coverage and one network request on submit.
- [ ] Add tests for “2 checks unanswered,” missing failure detail, coverage not attested, server 422 preserving local input, newer server version warning, terminal refetch locking capture and network uncertainty recovery by GET. Do not replace dirty drafts silently; offer Reload server version and explicitly discard local input when chosen.
- [ ] Add bulk PASS tests: confirmation required, show exactly the currently unanswered checks, do not overwrite FAIL, and record no new server results until save/submit. Search/filter cannot change the full submitted checklist or silently omit hidden checks.
- [ ] Run failing cases, remove numeric-entry branch from new checklist capture and use source/coverage summaries plus conditional failure fields. Derive readiness from answered checks, descriptions, truthful count and coverage attestation.

```tsx
<Button size="touch" aria-pressed={decision === 'passed'}
  aria-label={`PASS ${check.parameter_name}`}>PASS</Button>
<Button size="touch" aria-pressed={decision === 'failed'}
  aria-label={`FAIL ${check.parameter_name}`}>FAIL</Button>
{decision === 'failed' && <Textarea aria-label={`Problem for ${check.parameter_name}`} />}
```

- [ ] Ensure `size="touch"` uses literal minimum 44px independent of office `--hit-min = 28px`. Check affected existing callers; preserve regular small desktop buttons. Use semantic danger text for validation, warnings for informational blockers, labels and selected state rather than color alone.
- [ ] Run targeted component suites, build/type checks and palette/token checks. Inspect mobile, desktop, dark and floor themes, keyboard operation and 200% zoom. Historical wide tables scroll within their card.

## Task 10: Offer controlled replacement of eligible legacy open inspections

**Files:** Modify `InspectionService.php`, `InspectionController.php`, `api/app/Modules/Quality/routes.php`, `InspectionResource.php`, inspection detail/API types. Create `Requests/ReplaceInspectionChecklistRequest.php`. Tests: new `ChecklistReplacementTest.php`, existing `CancelledInspectionSlotReuseTest.php`.

**Interfaces:** Add `POST /quality/inspections/{inspection}/replace-checklist`, same existing manage permission. Payload: `reason` (required nonblank, maximum 2000), `expected_result_version`. Produce new inspection with `replaces_inspection_id`, unchanged authoritative source, approved checklist revision and unanswered checks.

- [ ] Add tests: allowed legacy Draft/InProgress replacement with explicit reason; retained old numeric rows; atomic source/hold linkage; repeated request returns the same successor; no additional active inspection for an output. Deny terminal/awaiting-review records, known failed evidence/open NCR, released stock, cross-source input and missing approved criteria.
- [ ] Run failing cases. In one source-owned transaction, lock source and inspection, verify version/eligibility, cancel predecessor preserving its rows, create successor through the appropriate existing factory and retarget only unreleased holds as needed. Never temporarily free stock between cancellation and replacement.

```php
$existing = Inspection::query()->where('replaces_inspection_id', $inspection->id)->first();
if ($existing !== null) {
    return $this->show($existing); // Authorize source access before this lookup.
}
// No public cancel-then-create sequence: use one transaction and existing factories.
```

- [ ] UI shows “Replace with PASS/FAIL checklist” only when eligible, names current/new revision and confirms retained predecessor history. Known failures continue through existing NCR/reinspection; do not use replacement to erase failure or reopen closed production arbitrarily.
- [ ] Run replacement/slot-reuse/concurrency tests. Do not auto-replace existing records in a migration or scheduled job.

## Task 11: Verify with real role accounts, then stage the rollout

**Files:** Create `spa/e2e/quality-pass-fail-real.spec.ts`, `spa/playwright.quality-real.config.ts`, `docs/testing/quality-pass-fail-implementation-20261006.md`. Reuse the current isolated audit application and fixtures; update relevant documentation/roadmap only for proven delivered behavior. No live fixture seeding.

**Interfaces:** Separate real-backend Playwright configuration: no wildcard API mocks and no use of mock `loginAs` as transaction proof. Base URL must be the isolated application `http://localhost:18080`; fail if configured for the public deployment. Use distinct authenticated browser contexts for QC maker, independent checker, Warehouse, Production/PPC, employee and customer/supplier accounts. Use existing provisioning/fixture facilities; do not create a public seed endpoint.

- [ ] Write a real-backend test with a unique run prefix and transaction fixtures: create approved three-check definition, source 100 pieces, inspect three check decisions, submit sample coverage, independently review, verify source-owned stock outcome and closure/delivery readiness. Confirm the same actors are refused actions outside their permissions.
- [ ] Cover each of the five stages, one piece failing two checks, document-only failure, bulk-unit receipt, canceled QC, stale second session, late checklist revision, recovery/reinspection, historical numeric evidence and legacy certificate download.
- [ ] Keep mocked component E2E for layout/error recovery separately labeled. Real transactions must assert server state after reload, not merely a toast or intercepted payload.

```ts
import { defineConfig, devices } from '@playwright/test';
const baseURL = process.env.QC_E2E_BASE_URL;
if (baseURL !== 'http://localhost:18080') {
  throw new Error('QC transaction tests require the isolated audit application.');
}
export default defineConfig({
  testDir: './e2e', testMatch: 'quality-pass-fail-real.spec.ts', workers: 1,
  use: { baseURL, headless: true, trace: 'retain-on-failure' },
  projects: [{ name: 'quality-real-chromium', use: { ...devices['Desktop Chrome'] } }],
});
```

- [ ] Run backend checks serially against `ogami_test_pricing_20261004`; never against live `ogami` and never concurrently reset a shared test database. Confirm nonzero tests executed. Run frontend checks and headless real-backend tests; capture screenshots, trace paths and actual assertion counts.

```bash
docker exec ogami-api php artisan test --configuration=phpunit.pricing.20261004.xml --filter='Checklist|InspectionSpecBoundaryTest|InspectionMeasurementContractTest|InspectionMakerCheckerPolicyTest|GrnQcGateTest|IncomingMrbDispositionTest|WorkOrderQcConnectionTest|WorkOrderInlineReworkTest|CoCEvidenceIntegrityTest|ReturnInspectionHandoffTest'
docker exec -w /app ogami-spa npm run test:run -- src/pages/quality/inspection-specs/editor.test.tsx src/pages/quality/inspections/detail.test.tsx src/pages/quality/inspections/components/InspectionChecklistPanel.test.tsx src/pages/inventory/items/quality-plans.test.tsx src/styles/__tests__/palette-contrast.test.ts
docker exec -w /app ogami-spa npm run build
docker exec -w /app ogami-spa npm run audit:tokens
QC_E2E_BASE_URL=http://localhost:18080 npx playwright test --config=playwright.quality-real.config.ts
```

The final Playwright command runs from `spa/` on a host where the isolated audit application is reachable. API/SPA test commands use container working directories already configured in this workspace. If unrelated baseline gates fail, record exact preexisting errors and resolve task-related failures; never describe a failing build as passed.

- [ ] Compare fixture source stock, held stock, accepted quantities and relevant GL movements before/after retries and disposition. Verify role-owned notifications/link access without messaging external people.
- [ ] Complete the acceptance checklist below and review the entire focused implementation diff. Publish evidence and limitations. Deployment comes only after a concrete verified result and applicable existing deployment authorization; this plan does not schedule live writes.

## Rollout and rollback

1. Expand schema first; compatible application keeps checklist creation paused. Read/write legacy evidence unchanged.
2. Deploy compatible backend, certificates and release gates before enabling new UI. Keep new checklist contracts guarded at both request and service entry points.
3. Enable the feature in the isolated environment, publish approved criteria and run full acceptance. Do not convert numeric criteria automatically.
4. For an authorized deployment, enable new authoring/capture only when its compatible backend is present. Old cached clients sending legacy payloads to checklist records receive a clear 422 instructing reload; no silent downgrade.
5. Production definitions move by publishing reviewed checklist revisions, preserving history. Existing eligible open inspections move only through the explicit replacement action; known failures remain in recovery.
6. Rollback after checklist records exist means pausing new checklist creation with the compatible application. Continue reviewing/servicing created checklist records or hold them safely. Do not restore an older binary that cannot interpret checklist evidence/source units.
7. Do not drop additive columns after new data exists. Migration `down()` is only for an empty transition/disposable test database; guard against deletion of any checklist record/revision/plan/criterion/replacement evidence. Destructive contraction requires a separate explicit decision.

## Audit coverage and stop conditions

| Finding | Planned correction |
|---|---|
| Q1 Numeric authoring/capture/review mismatch | Tasks 1–4, 8–9. |
| Q2 Certificate/SPC mismatch | Task 7. |
| Q3 Return matrix | Tasks 3 and 6. |
| Q4 Canceled QC releases stock | Task 5, replacement safety in Task 10. |
| Q5 Submitted FAIL becomes PASS | Task 4; strict new verdict and separate reviewed disposition. |
| Q6 Lost governing instructions | Tasks 1–3, 8–9. |
| Q7 Second supplier-plan contract | Task 2 and Task 8. |
| Q8 Piece/base-UoM confusion | Tasks 3 and 5. |
| Q9 Partial submissions/stale drafts | Tasks 4 and 9. |
| Q10 Overflow/touch/accessible naming | Tasks 8–9 and visual proof in Task 11. |
| Q11 Historical evidence/migration | Tasks 1, 7 and 10; rollout/rollback above. |

- [ ] All five new stages submit without numeric measurements; three checks across 100 pieces require three decisions, not 300.
- [ ] Every new check has an approved written criterion and starts unanswered. No page-load, filter, bulk action or retry silently produces PASS.
- [ ] PASS path requires only check decisions and actual coverage attestation. FAIL reveals problem details; count entry appears only when physical count evidence is necessary.
- [ ] Multi-fault pieces count once. Document failures can count zero physical defects. Bulk lots retain exact base quantity/UoM and reviewed base-unit disposition.
- [ ] Any checklist failure retains inspection FAIL. Legacy acceptance rules remain pinned and separately interpreted.
- [ ] Maker cannot check their own evidence; denied roles cannot author results, review, release stock or navigate unauthorized linked actions.
- [ ] Canceled-only QC cannot accept stock. Valid replacement and whole-line logistics rejection still work without releasing unapproved stock.
- [ ] WO/return/delivery/stock/GL connections remain source-owned and retry-safe; original failure/history remains visible after approved recovery.
- [ ] Checklist certificates describe recorded checks/coverage, historical PDFs and numerical results remain reconstructable, and new records do not claim Cp/Cpk.
- [ ] All touched forms fit mobile/desktop, named fields and controls work by keyboard, decision targets are at least 44px, and empty/error states explain the next action.
- [ ] Required tests and real role-based transaction E2E pass with retained evidence. No remaining P1 release/evidence mismatch from the audited scope.

Stop after these conditions and implementation review are satisfied. No new statistical engine, generic workflow builder, per-piece scanning system, packaging model, automatic concession approval, automatic restock/credit or unrelated module redesign.
