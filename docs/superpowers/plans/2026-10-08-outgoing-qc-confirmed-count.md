# Outgoing QC Confirmed Count Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking. If delegated workers are requested, use superpowers:subagent-driven-development and the user-approved gpt-6-luna model.

**Goal:** Reduce outgoing WO QC entry to confirmed physical checks and defect counts while reconstructing the existing QC record page and preserving traceable release decisions.

**Architecture:** Introduce immutable counted-capture version 3 for confirmed checks in finish-first WOs. Reuse existing per-output inspections, grouped WO submission/review, defect rows and NCR release gates. Version 2 retains its original numeric evidence rule; targets are references only.

**Tech Stack:** Laravel, PostgreSQL, React, TypeScript, TanStack Query, existing application components, Vitest, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-08-outgoing-qc-confirmed-count-design.md`

## Global constraints

- Planning only until the user approves execution.
- Physical coverage is every produced piece, not a sample.
- Targets never become actual measured values.
- Keep independent review, critical restrictions, source lineage, optimistic versions and retry safety.
- Incoming, returns, startup and mandatory-operation evidence do not change.
- Preserve existing evidence; only an explicitly authorized untouched draft may be replaced.
- New dependency, customer-policy editor and measurement import are outside scope.
- No destructive Git operations, repository-wide staging or unrelated changes in the dirty workspace.
- No fabricated live production, inspection, segregation or measurement evidence for tests.
- Use 12-hour plant time and the existing QC page components.

## Task 1: Pin the new evidence method and preserve historical capture

**Files**

- Modify `api/config/inspection.php`, `api/app/Modules/Quality/Models/Inspection.php`, `api/app/Modules/Quality/Models/InspectionDefectivePiece.php`.
- Modify `api/app/Modules/Quality/Services/InspectionService.php` creation and queue-group predicates.
- Create additive migration `api/database/migrations/2026_10_09_000100_add_outgoing_defect_requirement_reference.php`.
- Modify `api/app/Modules/Quality/Resources/InspectionResource.php`, `spa/src/types/quality.ts`, `spa/src/types/quality-final.ts`.
- Test `api/tests/Feature/Quality/FullPieceWorkOrderInspectionTest.php` and new `api/tests/Feature/Quality/ConfirmedCountOutgoingInspectionTest.php`.

**Interfaces**

```php
// Existing full-piece ownership remains shared by both methods.
public function usesFullPieceCapture(): bool
{
    return in_array($this->counted_capture['version'] ?? null, [2, 3], true)
        && ($this->counted_capture['workflow'] ?? null) === 'finish_first_v1';
}
public function usesConfirmedChecks(): bool
{
    return $this->usesFullPieceCapture()
        && ($this->counted_capture['version'] ?? null) === 3
        && ($this->counted_capture['evidence_method'] ?? null) === 'confirmed_checks';
}
// Add nullable inspection_defective_pieces.requirement_id, referencing
// inspection_measurements.id with restrictOnDelete. Null means unclassified.
```

New capture contract:

```php
['version' => 3, 'workflow' => 'finish_first_v1',
 'evidence_method' => 'confirmed_checks',
 'required_quantity' => $quantity, 'result_policy' => 'zero_defects']
```

- [x] Write a test creating completed WO output with numeric and surface requirements. With the new switch enabled, assert version 3, unchanged pinned target/tolerance/revision, full quantity, and zero `InspectionPieceReading` rows.
- [x] Run that test in the isolated test database and confirm failure under the old creator.
- [x] Add `WO_OUTGOING_CONFIRMED_CHECKS_ENABLED`, default false. Pin version 3 in `createFullPieceOutputInspection()` only when enabled; use version 2 otherwise. Keep existing source eligibility, active-inspection unique-index idempotency and revision guards. Creation after replacement must exclude the cancelled original while retrying the same active replacement.
- [x] Add the nullable defect requirement reference without rewriting existing rows. Expand queue-group and outgoing-route predicates from version 2 to recognized versions 2 and 3. Do not accept arbitrary future versions.
- [x] Return evidence method and failed-requirement reference using hash IDs. Keep version-2 missing-reading calculations; version 3 exposes confirmation requirements without pretending numeric readings exist.
- [x] Prove switch-off creation and existing version-2 submit/read behavior remain unchanged. Verify grouped queue output includes version-3 WOs exactly once.

A creation regression should include these exact assertions after the existing completed-output fixture creates its inspection:

```php
$this->assertSame(3, $inspection->counted_capture['version']);
$this->assertSame('confirmed_checks', $inspection->counted_capture['evidence_method']);
$this->assertSame(500, $inspection->counted_capture['required_quantity']);
$this->assertSame(0, $inspection->pieceReadings()->count());
$this->assertSame('100.0000', $inspection->measurements()->where('parameter_name', 'Length')->value('nominal_value'));
```

## Task 2: Save defects and calculate outcomes from confirmed coverage

**Files**

- Modify `api/app/Modules/Quality/Requests/SaveWorkOrderFinalInspectionEvidenceRequest.php`, `SubmitWorkOrderFinalInspectionRequest.php`.
- Modify `api/app/Modules/Quality/Services/WorkOrderFinalInspectionService.php`, `InspectionService.php`.
- Modify `api/app/Modules/Quality/Resources/InspectionResource.php`, `spa/src/types/quality-final.ts`.
- Test new `api/tests/Feature/Quality/ConfirmedCountOutgoingInspectionTest.php` and existing `FullPieceWorkOrderInspectionTest.php`.

**Interfaces**

```ts
// Each version-3 defect carries a primary failed check, or null for Other.
type ConfirmedDefect = {
  piece_number: number;
  problem_description: string | null;
  requirement_id: string | null;
};
// Extend each submit batch with this required version-3 affirmation.
type ConfirmedBatchSubmission = {
  inspection_id: string;
  coverage_confirmed: true;
  inspected_quantity: number;
  checks_confirmed: true;
};
```

- [x] Test missing/false `checks_confirmed`, count above batch size, duplicate ordinals, blank descriptions, foreign requirement IDs, stale manifest and attempted explicit result override. Each must refuse without partial submission.
- [x] Extend request allow-lists for nullable hashed `requirement_id` and `checks_confirmed`; resolve requirement ownership in the service. Preserve legacy request compatibility.
- [x] Save all defect pages with the existing source locks and result version. A version-3 request containing numeric reading writes is rejected; no targets are copied into reading storage. For version 2, existing required actual readings remain mandatory.
- [x] Use `coverage_confirmed_at`, `inspected_quantity` and the result-author trail to record version-3 all-check confirmation only after both affirmations pass. Any evidence edit clears the confirmation as before.
- [x] Extend `assertFullPieceEvidence()` with a method-specific numeric branch. Common validation still checks source quantity, completed WO, pinned revision, defect identities/descriptions and coverage. Version 3 validates defect requirement ownership and never fabricates a numeric evaluation.
- [x] Calculate positive defects as FAIL. Critical failed references and unclassified failures require conservative critical handling. Preserve result locking and independent reviewer restrictions; review recomputes the method-specific verdict instead of trusting browser totals.
- [x] Test 500/0, 500/5, 500/500, maker restrictions, and atomic mixed-child confirmation/review.

## Task 3: Reconstruct the outgoing QC record page

**Files**

- Modify `spa/src/pages/quality/inspections/work-order-final.tsx`, `detail.tsx`.
- Modify `spa/src/pages/quality/inspections/components/WorkOrderFinalInspectionPanel.tsx` and its test.
- Modify `spa/src/api/quality/inspections.ts` only for the new request contracts.
- Add `spa/src/pages/quality/inspections/work-order-final.test.tsx` for routing/header/read-only state coverage.

**Interfaces**

```ts
const confirmedChecks = batch?.counted_capture?.version === 3
  && batch.counted_capture.evidence_method === 'confirmed_checks';
const unreportedDefectsQuantity = countValid ? quantity - defectCount : null;
// confirmedChecks renders requirements as references and requires checks_confirmed.
// Existing version-2 evidence remains under its original method, with real readings.
```

- [x] Add a failing UI test rendering version 3 with four dimensional targets and a surface criterion. Assert the targets appear, no per-piece measurement textboxes appear, PASS ALL leaves confirmation unchecked, and submission stays disabled without confirmation.
- [x] Build the existing-look summary, requirements, defect result and compact history sections described in the spec. Use real QC number/status/product and output-batch context, not a new standalone identity.
- [x] Replace the version-3 reading matrix and counters with reference targets/limits and one full-check confirmation. Provide shared problem/check application for selected defects, at most 50 rendered rows, Save draft and one primary Submit inspection action.
- [x] Keep original evidence-method data accessible for legacy records; hide only irrelevant AQL/sample/SPC fields for confirmed checks. Move order/production timing to optional source details and eliminate duplicate messages.
- [x] Invalidate physical confirmation when count, problem or affected check changes. Preserve user-scoped draft restore, saved progress, stale-result recovery and unknown-submission reconciliation. A method change must not restore a version-2 reading draft into version 3.
- [x] Render submitted defects, check confirmation, actor and time for review; display the NCR for failures and approved quantity separately from production-reported quantity. Show only permitted actions.
- [x] Verify opening from the QC queue, generic inspection URL and WO link reaches the same outgoing record presentation without creating an inspection. Keep zero counts unlabeled as accepted until review succeeds.

The zero-defect UI regression includes:

```tsx
fireEvent.click(screen.getByRole('button', { name: 'PASS ALL' }));
expect(screen.queryByRole('textbox', { name: 'Length · piece 1' })).not.toBeInTheDocument();
expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
fireEvent.click(screen.getByRole('checkbox', { name: /I checked every piece against all listed requirements/ }));
expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeEnabled();
```

## Task 4: Preserve stock, recovery and truthful certificates

**Files**

- Modify `api/app/Modules/Quality/Services/CoCService.php`, `api/resources/views/pdf/coc.blade.php`.
- Modify `api/app/Modules/Quality/Services/NcrService.php` only where critical/reference evidence requires adaptation.
- Modify `spa/src/pages/quality/ncrs/detail.tsx` only if evidence-method copy currently promises actual measurements.
- Test `api/tests/Feature/Quality/FinishFirstDefectRecoveryTest.php`, `FinishFirstCoCEvidenceTest.php`, `WorkOrderInlineReworkTest.php`.

**Interfaces**

```php
// Additional certificate payload for version 3:
['evidence_method' => 'confirmed_checks',
 'measurement_disclosure' => 'Numeric measurements were not recorded for this inspection.',
 'critical_measurements' => [],
 // requirements are specification references, not actual results/ranges
 'confirmed_requirements' => $requirements]
```

- [x] Test stock availability stays zero before independent QC approval and through failed batch review. Test segregation releases no more than 495 of 500 with five defects, and all-defective output releases zero.
- [x] Test critical/unclassified failures retain existing critical restrictions. Defects are never reclassified as passing merely by approving their review.
- [x] Adapt certificate validation by evidence method. Version 3 requires full confirmed coverage, zero defects, independent passing review and existing release eligibility. It shows reference targets and truthful disclosure; it has no actual numeric ranges, reading counts or AQL claims. Version 2 retains existing measurement validation.
- [x] Test a version-3 certificate contains the disclosure and targets but no invented actual measurements; failed/held inspection certificates refuse. Keep existing version-2 missing/contradictory-reading tests passing.
- [x] Exercise NCR retries, segregation, repair/reinspection and scrap/replacement conservation in isolated fixtures. Preserve existing output quantities, audit trails and idempotent stock/shortage owners; avoid changing production or inventory arithmetic without a demonstrated failure.

## Task 5: Controlled replacement, rollout and role-based proof

**Files**

- Modify `api/app/Modules/Quality/Services/WorkOrderFinalInspectionService.php`, `InspectionService.php`, `Controllers/WorkOrderFinalInspectionController.php`.
- Add the controlled replacement route in `api/app/Modules/Quality/routes.php` alongside the existing final-inspection endpoints.
- Create `api/app/Modules/Quality/Requests/ReplaceOutgoingEvidenceMethodRequest.php`.
- Test new `api/tests/Feature/Quality/OutgoingEvidenceMethodReplacementTest.php`.
- Create `spa/e2e/outgoing-confirmed-count.spec.ts` using isolated real-role accounts and fixtures.

**Interfaces**

```php
// Explicit authorized POST, never called by show():
public function replaceUntouchedWithConfirmedChecks(
    WorkOrder $wo, array $manifest, string $reason, User $by
): array;
// POST /quality/work-orders/{workOrder}/final-inspection/replace-method
// JSON: { manifest, reason }.
```

- [x] Test replacement refuses recorded readings, defects, result authors, coverage, submitted/terminal status, changed source/revision and missing QC permission. Assert original rows remain unchanged on refusal.
- [x] Implement atomic untouched-draft replacement under the established lock order. Cancel the original with its reason/audit entry, retain it, and create its replacement linked by `replaces_inspection_id`, same output and same pinned revision. An already replaced manifest resolves to that replacement on a retry, while stale unrelated manifests refuse.
- [x] Confirm rollback with no version-3 evidence returns new-creation switch to version 2. Once new evidence exists, disable new creation without erasing records; retain compatible readers and additive schema.
- [x] Deploy compatible backend/frontend first, then enable new creation. Re-read WO-202610-0004; perform its explicit replacement only if authorization and all untouched guards are satisfied. Report a blocking existing draft rather than clearing it.
- [x] Run isolated role-based Playwright cases: recorder output and late reporting remain usable; QC inspector enters zero and five defects; non-QC cannot write; maker cannot approve; independent QC reviewer approves; warehouse cannot use held stock and sees only approved quantities after segregation. Never fabricate evidence in live records.
- [x] Run focused backend tests in the isolated database, UI tests, build and changed-file lint. Capture desktop/mobile as one batch, correct concrete defects once, confirm at most once. Run the Impeccable detector once for the changed UI files.
- [x] Stop when acceptance cases pass and the new outgoing form has no required numeric entry for confirmed checks. Report remaining customer measurement requirements and the separate inverted WO finish timestamp; do not expand into unrelated cleanup.

## Verification commands

Use the existing isolated test container, refreshing only its dedicated test database. Copy changed backend files into `/tmp/kwatog-finish-first-20261008/api` before running its tests; never run RefreshDatabase against `ogami-api`.

```bash
docker exec -e DB_DATABASE=ogami_test_finish_first_core_20261008 -e WO_FINISH_FIRST_QC_ENABLED=false -e QC_COUNTED_BATCH_ENABLED=false -e QC_CHECKLIST_CAPTURE_ENABLED=false ogami-finish-first-20261008 php artisan test tests/Feature/Quality/ConfirmedCountOutgoingInspectionTest.php tests/Feature/Quality/OutgoingEvidenceMethodReplacementTest.php tests/Feature/Quality/FullPieceWorkOrderInspectionTest.php tests/Feature/Quality/FinishFirstDefectRecoveryTest.php tests/Feature/Quality/FinishFirstCoCEvidenceTest.php tests/Feature/Quality/WorkOrderInlineReworkTest.php
```

From `spa`:

```bash
npm run test:run -- src/pages/quality/inspections/components/WorkOrderFinalInspectionPanel.test.tsx src/pages/quality/inspections/work-order-final.test.tsx
npm run build
npx eslint src/pages/quality/inspections/work-order-final.tsx src/pages/quality/inspections/detail.tsx src/pages/quality/inspections/components/WorkOrderFinalInspectionPanel.tsx src/types/quality-final.ts src/types/quality.ts
```

## Self-review

Coverage: new capture/compatibility (Task 1); truthful evidence/critical checks (Task 2); reconstructed outgoing UI and draft safety (Task 3); stock/NCR/certificates (Task 4); controlled existing draft replacement and role proof (Task 5). No application code or live evidence changes are part of this planning delivery.

## Execution record

Approved implementation, focused verification, and live rollout completed. See `docs/testing/outgoing-confirmed-count-20261008.md` for exact passing evidence, fixture commands, rollout identities, and limitations. Direct version-3 backend coverage includes all-defective and mixed-child cases; browser cases cover zero and five defects. All five implementation tasks are delivered. Checked items indicate completed implementation or shared-path proof; the direct-case limits above remain explicit.
