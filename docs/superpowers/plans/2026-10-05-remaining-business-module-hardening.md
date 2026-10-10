# Remaining Business Module Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Respect the user's agent limit: one GPT-6.1-Sol agent unless a second agent actually uses the user-approved other model. Do not spawn additional reviewers implicitly.

**Goal:** Correct the remaining audited integrity defects, improve next-action visibility, and add bounded automation without bypassing approvals or physical stock evidence.

**Architecture:** Keep business invariants in the existing owning services and requests. Reuse PostgreSQL locks, unique operation identities, shared warehouse availability, outbox events, notification delivery, and current UI components. Each slice below can be implemented and reviewed independently; policy-dependent slices remain explicit decisions.

**Tech Stack:** Laravel/PHP 8.3, PostgreSQL, React/TypeScript, Tailwind, PHPUnit, Vitest, Playwright.

**Spec:** [Remaining business modules deep audit](../../testing/remaining-business-modules-deep-audit-20261005.md). Read its evidence boundaries before implementation.

## Approved first-pass completion: 2026-10-06

The approved first pass implements F1, F2, F3, M3, M5, S1 and X1. [Results, exact checks and remaining work](../../testing/major-integrity-hardening-20261006.md) are authoritative for this pass. Detailed checkboxes below remain the larger plan, including checks and features that were not part of this pass; they must not be read as a claim that every task was implemented.

- [x] F1: correct section-oriented contra signs without altering trial balance.
- [x] F2: require an operation UUID for HTTP credit applications, compare replay payloads and serialize matching identities.
- [x] F3: reject disposal before posted depreciation at request and final execution.
- [x] M3: preserve equipment faults and unresolved downtime on maintenance cancellation.
- [x] M5: require an operation UUID for HTTP spare issues and preserve one stock/cost contribution on replay.
- [x] S1: generate explicit internal drafts using recorded receipt quantities where available. Official shipment-manifest policy remains deferred.
- [x] X1: reauthorize export owners at generation and queued send, pause unauthorized schedules and suppress stale artifacts.

Implementation reuses one additive migration for the new identity/snapshot fields, PostgreSQL transaction advisory locks instead of exception-driven duplicate recovery, and the existing browser-compatible request-key helper. M6 multi-lot spare usage, the eighteen lower-priority findings, additional layout polish and broader automation remain planned.

## Global Constraints

- Audit complete. Only the seven fixes in the completion block above are implemented by the subsequent approved first pass; the rest of this plan remains proposed work.
- Preserve existing uncommitted user work. Do not stage unrelated files or rewrite the dirty workspace.
- Do not change live business records, run production cron jobs, send live messages, or repair posted financial history as part of implementation verification.
- Preserve maker/checker, source-owned journal reversal, period locks, immutable posted entries, role/party scoping, QC holds, lot/expiry gates, and physical proof requirements.
- Use existing framework/components; no new dependencies, generic workflow engine, duplicated scheduler, or competing approval dashboard.
- Use normal prose in product copy and documentation. Continue current Atelier tokens and shared table/form/dialog patterns.
- Operations affecting money or stock require stable per-intent identity, payload conflict detection, atomic state changes, and database uniqueness.
- Per-user retries reuse the operation key. A new key represents a genuinely new intent. Historical rows must not be guessed to be duplicates.
- Database probes must use `phpunit.pricing.20261004.xml` and the dedicated `ogami_test_pricing_20261004` database. Never run the default destructive test configuration against the deployment database.
- F6, F7, technician qualifications, and official shipment-manifest policy require explicit product/accounting decisions before those dependent behavior changes. Other confirmed narrow defects do not depend on those decisions.

## Verification setup and evidence reuse

The audit's [probe file](../../testing/evidence/remaining-modules-20261005/RemainingModulesAuditProbeTest.php) contains concrete fixtures/helpers and runnable reproduction tests. **Do not ship its assertions that expect defects.** Extract each relevant fixture into its owning regression test and replace observed-defect assertions with the desired invariants below. Keep its database-name guard for any standalone copied probe.

Current verified commands:

```bash
docker cp docs/testing/evidence/remaining-modules-20261005/RemainingModulesAuditProbeTest.php ogami-api:/tmp/RemainingModulesAuditProbeTest.php
docker exec ogami-api php -d memory_limit=-1 vendor/bin/phpunit -c phpunit.pricing.20261004.xml /tmp/RemainingModulesAuditProbeTest.php
```

The above proves reproduction only. For each task, run its new test **before** the change, confirm the failure is the specified invariant, implement the narrow correction, then rerun that task and relevant existing safeguards with the same forced database configuration. New future test paths are marked Create below.

Run frontend checks from `spa`:

```bash
npx vitest run src/styles/__tests__/palette-contrast.test.ts
npx tsc --noEmit
```

Use the role-account sign-in/session setup from the [browser script](../../testing/evidence/remaining-modules-20261005/remaining-modules-browser.cjs) against the isolated application. Real transaction E2E scenarios need their own disposable fixtures; the audit's read-only visit script is not a transaction test.

## Pack 1: financial and equipment integrity

### Task 1: present contra balances correctly (F1)

**Files:** Modify `api/app/Modules/Accounting/Services/Statements/BalanceSheetService.php`; extend `api/tests/Feature/Accounting/StatementServicesTest.php` and `api/tests/Feature/Accounting/FinancialStatementBoundaryTest.php`.

**Interface:** Existing `BalanceSheetService::generate()` response remains unchanged. Only section-oriented signs change; no change to trial balance or account normal balances.

- [ ] Extract the `journal()` fixture and contra-asset probe into the existing statement test. Assert desired values:

```php
$statement = app(BalanceSheetService::class)->generate(Carbon::parse('2026-10-05'));
self::assertSame('900.00', $statement['total_assets']);
self::assertSame('900.00', $statement['total_liabilities_equity']);
self::assertTrue($statement['balanced']);
```

- [ ] Run `docker exec ogami-api php vendor/bin/phpunit -c phpunit.pricing.20261004.xml tests/Feature/Accounting/StatementServicesTest.php`; confirm old assets 1100 fails.
- [ ] Replace the section balance calculation in `generate()`:

```php
$bal = $r->type === 'asset'
    ? Money::sub((string) $r->debit_total, (string) $r->credit_total)
    : Money::sub((string) $r->credit_total, (string) $r->debit_total);
```

- [ ] Add debit-normal contra-equity and dated reversal cases. Verify trial-balance debit/credit equality stays unchanged, service consumers retain negative contra presentation, and cached reports invalidate through existing observers.
- [ ] Review only the task diff and test output before integrating it with other packs.

### Task 2: reject unsupported backdated asset disposal (F3)

**Files:** Modify `api/app/Modules/Assets/Services/AssetService.php`; extend `api/tests/Feature/Assets/AssetDisposalApprovalWorkflowTest.php`, `AssetDisposalMonthDepreciationTest.php`, and `AssetDisposeDoublePostingRaceTest.php` in the same test directory.

**Interface:** Existing disposal request/approval/service entry points remain. A private date/history guard raises the existing business-rule exception and runs both when requested and immediately before final disposal, under the asset lock.

- [ ] Copy the full-approval probe fixture, replacing its expected disposal success with rejection before any disposal journal, accumulated balance, or status changes. Use the existing tests' ordinary finance requester/checker and executive approval actors.
- [ ] Compare disposal month with the latest posted asset depreciation period:

```php
$latest = $locked->depreciations()
    ->whereNotNull('journal_entry_id')
    ->orderByDesc('period_year')->orderByDesc('period_month')->first();
$requestedMonth = $disposedDate->format('Y-m');
if ($latest !== null && $requestedMonth < sprintf('%04d-%02d', $latest->period_year, $latest->period_month)) {
    throw new BusinessRuleException('Disposal date precedes posted depreciation. Finance must review a correction before disposal.');
}
```

`$locked` and `$disposedDate` are the locked asset and parsed request date in each existing transaction; implement one private helper to avoid diverging checks. Existing incomplete journal identity checks remain.

- [ ] Test partial and fully depreciated assets, same/latest month disposal, locked accounting month, and new depreciation posted between request and final approval. Rejected final execution must preserve earlier approvals, leave the final approval step pending after transaction rollback, and keep the asset undisposed.
- [ ] Run the three listed asset classes under the forced configuration and review preserved maker/checker/source-journal guards.
- [ ] Do not add automatic reversal/backdated correction support to this slice.

### Task 3: prevent duplicate partial credit application (F2)

**Files:** Modify `api/app/Modules/Accounting/Requests/ApplyCreditNoteRequest.php`, `Services/CreditNoteService.php`, `Models/CreditNoteApplication.php`; create `api/database/migrations/2026_10_06_000001_add_credit_application_operation_identity.php`; modify `spa/src/api/accounting/credit-notes.ts`, `spa/src/pages/accounting/credit-notes/detail.tsx` and `spa/src/types/accounting.ts` (`ApplyCreditNoteData`); create `api/tests/Feature/Accounting/CreditApplicationReplayTest.php`.

**Interface:** Add UUID `operation_key` to new application requests. Persist it on `credit_note_applications`; a database unique constraint owns identity. Existing historical rows remain nullable. Reject key reuse with a different credit, target, actor/party scope, or amount through field-specific validation. Authorized identical replay returns the existing application.

- [ ] Extract the F2 invoice/credit fixture into the new test. Two same-key partial calls must satisfy:

```php
self::assertSame('900.00', $invoice->fresh()->balance);
self::assertSame('900.00', $credit->fresh()->balance);
self::assertSame(1, $credit->applications()->count());
```

- [ ] Add migration with nullable UUID and unique index; do not backfill guessed identities. Require key for newly submitted API operations after backend/frontend are deployed together. Audit other internal callers before making the service parameter mandatory.
- [ ] Under the existing credit/target locks, check for a stored operation **before** remaining-balance guards. Verify entire payload before returning it. Insert new identity within the same transaction as balances/application, and handle a PostgreSQL `23505` unique-key race only after rolling back the failed transaction or its savepoint, then reload and compare the committed winner. Do not query inside an aborted transaction. Never catch all database errors as duplicate success.
- [ ] Keep a `crypto.randomUUID()` key for each dialog intent and preserve it through network retries; replace it only after success or explicit new/canceled intent. Disabled submit state supplements server protection.
- [ ] Test customer invoice and supplier bill targets, same-key changed amount/target, same key on a different credit note, unauthorized replay, distinct valid intents, and two concurrent same-key requests. Run the new class plus `CreditNoteTest.php` and `CreditNoteDoubleFinalizeRaceTest.php`.
- [ ] Review migration rollback preservation: rolling back must not remove applications or balances. If deployment rolls back the UI, keep the new identity column rather than discarding historical retry evidence.

### Task 4: cancel maintenance without releasing unresolved faults (M3)

**Files:** Modify `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php`, `Models/MaintenanceWorkOrder.php`; create `api/database/migrations/2026_10_06_000002_add_maintenance_equipment_state_snapshot.php`; extend `api/tests/Feature/Maintenance/MaintenanceOperationalSafetyTest.php` and `MaintenanceWorkOrderMachineDowntimeTest.php`.

**Interface:** Capture the existing equipment condition before changing it to Maintenance. Cancel restores only a known serviceable pre-state with no remaining fault/owner/sibling condition. For old rows without a trustworthy snapshot, cancellation must not infer Idle from missing evidence.

- [ ] Extract the Breakdown/start/cancel probe, change expected status to Breakdown, and assert unresolved downtime is not reported repaired.
- [ ] Persist a small nullable pre-maintenance status snapshot on the WO at start. Do not build an equipment-state framework. Preserve current machine/mold/linked-asset locks and production ownership checks.
- [ ] In `cancel()`, restore captured fault status or keep unavailable when status is unknown. Close downtime only if cancellation genuinely ends a serviceable administrative maintenance hold; repair completion follows the existing evidence/completion path.
- [ ] Add tests for Idle preventive cancellation, Breakdown cancellation, sibling maintenance, current production owner, overdue-shot mold, and legacy snapshot-null rows. Verify linked asset status is not advertised Active while equipment remains faulted.
- [ ] Run listed classes and `MoldStatusRestoreOnCompleteTest.php`; review migration defaults and field exposure for historical rows.

### Task 5: make spare issue replay-safe and multi-lot (M5, M6; A3)

**Files:** Modify `api/app/Modules/Maintenance/Services/SparePartUsageService.php`, `Models/SparePartUsage.php`, `Requests/RecordSparePartUsageRequest.php`; create `api/database/migrations/2026_10_06_000003_add_spare_usage_operation_and_movement_links.php`; modify `spa/src/api/maintenance/workOrders.ts`, `spa/src/pages/maintenance/work-orders/detail.tsx`; create `api/tests/Feature/Maintenance/SparePartIssueReplayAndLotsTest.php`.

**Interface:** One usage represents one authorized physical issue intent. Add operation UUID and movement children; preserve legacy `stock_movement_id` compatibility. Reuse `StockMovementService::issueAcrossLots(StockMovementInput $input): Collection`. Each child retains exact quantity, cost, source location and lot identity; usage cost is their sum.

- [ ] Copy M5/M6 fixtures into desired-behavior tests: replayed quantity-2 issue leaves stock **8**, one usage and one WO cost contribution; issue **8** from lots **5+5** succeeds with exact movement total 8.
- [ ] Add nullable unique operation key and usage-to-movement links, preserving all old usages. Require new client operation keys and perform payload verification/replay before stock sufficiency checks under the existing WO/item locks.
- [ ] Replace the single movement call with the existing shared split-issue API:

```php
$movements = $this->stockMovements->issueAcrossLots(new StockMovementInput(
    type: StockMovementType::MaterialIssue,
    itemId: $item->id,
    fromLocationId: (int) $data['location_id'],
    toLocationId: null,
    quantity: $qty,
    unitCost: null,
    referenceType: 'maintenance_work_order',
    referenceId: $lockedWo->id,
    remarks: 'Spare-part issue for '.$lockedWo->mwo_number,
    createdBy: $by->id,
));
```

- [ ] Sum actual movement costs using Money/BCMath, write usage/children, and update WO cost atomically. Never use a floated cost or fabricate one lot for multiple movements. Render original source lots in usage history.
- [ ] Test same-key changed quantity/item/location, concurrent retry, QC-held/expired/blocked stock, count freeze, whole-piece UoM, insufficient total rollback, and repeated legitimate independent issues. Run the new class, `SparePartUsageLostCostRaceTest.php`, and `Operations/MaintenanceConsumptionAccountingTest.php`.
- [ ] Prepare suggested usable lots automatically; keep the physical issue confirmation with the authorized user.

### Task 6: authorize background exports throughout their lifecycle (X1; A10)

**Files:** Modify `api/app/Common/Services/Export/ExportRunner.php`, `api/app/Console/Commands/RunDueScheduledExports.php`, `api/app/Common/Mail/ScheduledExportMail.php`; extend `api/tests/Feature/Infrastructure/ScheduledExportExecutionTest.php`.

**Interface:** Existing export builder validates a fresh active actor, module and column permissions, and current row scope. Queued sending revalidates the owner and the persisted module/column/filter context before exposing an artifact. Explicit console system paths remain separate from user-owned schedules.

- [ ] Convert the ordinary inactive-exporter probe to expect a controlled authorization failure. Add disablement and role/column/scope revocation between generation, enqueue, and actual send.
- [ ] Reload scheduled owner from the database immediately before generation; check `is_active` before `can()`. Do not accept a stale loaded User merely because permission cache still allows export.
- [ ] Carry schedule/owner and authorization context in the queued delivery identity. At send time reload owner and verify module/columns/filters/scope; if scope changed after artifact creation, cancel the stale delivery and create a fresh permitted run rather than mailing old broader data. Verify the framework's send hook runs before transport; do not implement a check in an unused method.
- [ ] Mark the run/delivery blocked with an actionable reason, preserve retry/lease/artifact cleanup behavior, and avoid infinite retries of permanent revocation. Never fall back to a null/system actor for a deleted owner.
- [ ] Execute the actual queued send through a test-only transport; `Mail::fake()` alone bypasses the send hook and cannot prove this invariant. Assert zero transport sends for revoked owners and one send for permitted owner. Test missing owner, unsupported module, restricted columns, valid scope, artifact cleanup, and parallel lease claims. Run the existing scheduled export class and review no new live mail path was introduced.

## Pack 2: shipment and advisory correctness

### Task 7: establish actual shipment quantities before official document generation (S1; A4)

**Files:** Modify `api/app/Modules/SupplyChain/Services/ImpexDocumentService.php`, `Controllers/ImpexDocumentController.php`, shipment request/resource/model as needed; extend `api/tests/Feature/SupplyChain/ImpexDocumentTest.php`; modify `spa/src/pages/supply-chain/shipments/detail.tsx` and existing shipment API/types.

**Policy decision:** Before adding a manifest table, decide who confirms shipment contents and whether pre-receipt documents are supplier-provided or company-issued drafts. Recommended: supplier commercial invoice stays authoritative; internal generated PO-intent document stays Draft until a shipment-specific manifest is confirmed. This decision is independent of fixing the current misleading full-PO output.

- [ ] Use the S1 renderer fixture: PO100/received10 must not render100 as confirmed shipment content. Tests assert row quantities and extended totals from the shipment source, including two shipments from one PO and omitted PO lines.
- [ ] For received shipments, map exact linked GRN `quantity_received` with PO line identity, rather than `quantity_accepted`; rejected received goods were still received. Handle multiple linked receipts explicitly and avoid duplicate line accumulation.
- [ ] For pre-receipt generation without confirmed contents, either block official generation with 422 or produce a visibly watermarked Draft showing PO intent. Do not silently pretend a PO is a shipment manifest.
- [ ] If confirmed pre-receipt manifests are approved, add only shipment-item records with PO-line identity and quantity under a shipment lock, plus compatible nullable provenance for historical shipments. Freeze/revision-audit after document confirmation/clearance. Define partial and over-shipment policy at the request boundary.
- [ ] Verify renderer payload, final PDF extracted text, supplier-uploaded originals untouched, quantities/amounts/currency, archived/missing source rows, and unchanged document-vault authorization. Use fake storage and a disposable browser fixture; never exercise live vault-generating GET routes.
- [ ] Show PO, manifested, received and accepted quantities as distinct values with one mismatch action. Do not automatically approve customs or finance documents.

### Task 8: align container validation and lock order (S2, S3, S4)

**Files:** Modify `api/app/Modules/SupplyChain/Controllers/ContainerController.php`, `Services/ContainerService.php`, `Services/ShipmentService.php`; create `api/tests/Feature/SupplyChain/ContainerIntegrityTest.php`.

**Interface:** Omitted update fields preserve values; provided required identifiers/enums cannot be null. Weight comparison uses the effective updated record. All container mutations lock Shipment before Container, including archive/restore.

- [ ] Convert S2/S3 probes: null identifier returns 422 and keeps old identifier; net20/gross10 returns `net_weight_kg` validation error and writes nothing. Add gross-only reduction below existing net, net-only increase, equal/unknown weights, array/scientific/precision inputs.
- [ ] Use `sometimes|required|string` for supplied required identifiers. Keep nullable optional fields. Check both effective weights only when both are known, with validated fixed-decimal comparison:

```php
$gross = array_key_exists('gross_weight_kg', $data) ? $data['gross_weight_kg'] : $locked->gross_weight_kg;
$net = array_key_exists('net_weight_kg', $data) ? $data['net_weight_kg'] : $locked->net_weight_kg;
if ($gross !== null && $net !== null && bccomp((string) $net, (string) $gross, 2) > 0) {
    throw ValidationException::withMessages(['net_weight_kg' => 'Net weight cannot exceed gross weight.']);
}
```

Explicit null clears an optional weight; omitted keys retain the persisted value.

- [ ] Obtain the parent id, lock Shipment, then lock/reload Container and verify its parent is still that Shipment. Apply same order in update/delete/restore and review document mutation inversions while touching the owning service.
- [ ] Add two-connection edit-versus-clearance and child-edit-versus-parent-edit tests. Require controlled final state, no mutation after clearance, and no circular lock wait. Keep existing numeric limits.
- [ ] Run new container tests and existing Impex document tests; inspect queries/locks rather than relying only on sequential success.

### Task 9: report real usable availability and honest supplier metrics (X3, V1; A5, A11)

This task contains two independent service changes; review each diff/test independently.

**Files:** Modify `api/app/Modules/Forecasting/Services/StockOutProjectionService.php`, `ForecastMrpService.php`; create `api/tests/Feature/Forecasting/AdvisoryUsableAvailabilityTest.php`. Modify `api/app/Modules/Purchasing/Services/SupplierPerformanceService.php`, `api/app/Modules/Purchasing/Controllers/SupplierPerformanceController.php` and `spa/src/types/supplierPerformance.ts`, `spa/src/pages/purchasing/suppliers/performance.tsx`; create `api/tests/Feature/Purchasing/SupplierPriceVarianceEvidenceTest.php`.

**Interfaces:** Advisory results retain raw on-hand, reserved, and usable quantities separately. Reuse `StockLocationSummaryService::availabilityByItem(Collection $stockLevels): Collection`. Supplier price variance uses actual comparable same-item/UoM/currency cost evidence or null, not receipt quantity shortfall.

- [ ] Convert X3 blocked-bin probe so `available` is `0.000`; assert forecast net shortage includes excluded stock. Add held, expired, reserved, multiple-bin, and usable stock cases, ensuring no double subtraction of holds or reservations.
- [ ] Replace each raw aggregate availability calculation with the existing summary's per-item usable quantity and expose the excluded quantities/source freshness. Confirm advisory code does not bypass the execution-level inventory service.
- [ ] Add supplier fixtures with unchanged price and a partial receipt: price variance must be zero or unavailable, never the receipt shortfall percentage. Add actual different cost evidence, missing costs, mixed UoM/currency and zero denominators.
- [ ] Compute a same-basis actual cost variance only from comparable evidence. For missing data, return null and normalize composite score over available components; do not score unavailable evidence as perfect or failing. Rename preserved receipt-shortfall data accurately and explain denominator/period on the page.
- [ ] Run each new class and existing `ForecastMrpDoubleCountTest.php`, `ForecastManualOverrideGuardTest.php`, and `tests/Feature/Purchasing/SupplierScorecardInvariantsTest.php`. Supplier refresh uses existing period upsert/outbox/command; coalesce vendor-period recomputes and do not change purchasing eligibility merely from an unreliable metric.

## Pack 3: maintenance workflow, forms, and focused UI polish

### Task 10: preserve PM anchors, recheck generation, and expose due context (M1, M2, M7, M8; A1)

**Files:** Modify `api/app/Modules/Maintenance/Services/MaintenanceScheduleService.php`, `MaintenanceWorkOrderService.php`, `Resources/MaintenanceScheduleResource.php`, `Controllers/MaintenanceWorkOrderController.php`, `routes.php`; modify `spa/src/pages/maintenance/schedules/create.tsx`, `edit.tsx`, `index.tsx`, `detail.tsx` and `spa/src/api/maintenance/schedules.ts`; extend `api/tests/Feature/Maintenance/MaintenanceScheduleRecomputeRaceTest.php`, `PreventiveMaintenanceDurableHandoffTest.php` and `api/tests/Feature/Operations/MaintenanceLookupSafetyTest.php`.

**Interface:** Unperformed calendar schedules use a stable created/explicit anchor. Mutation reloads current schedule. Generation owns fresh active/due/open-WO checks. Minimal equipment lookup supports schedule managers without broader MRP grants. Resource supplies actual due/progress context through batch-loaded targets.

- [ ] Change M1 assertion to November4 after description edit and keep the audited stale-completion guard case passing. Test interval change, activation change, explicit anchor change, completion and legacy dates.
- [ ] Preserve `next_due_at` for non-scheduling edits. On intentional schedule changes, calculate from `last_performed_at`, existing explicit anchor, or persisted creation time; never refresh an unperformed schedule's anchor with a spelling edit.
- [ ] In generation, lock fresh schedule/target and recheck active, due/threshold and open-WO identity before creating. Test stale disabled/not-yet-due hints and concurrent materialization; no duplicate or ineligible WO/outbox event.
- [ ] Authorize the minimal equipment lookup when user can create maintenance WOs **or** manage maintenance schedules; return only target fields needed for the form and valid target options. Update both create/edit pages to use it, preserving debounce and server eligibility guards.
- [ ] Batch target loading and expose hours/shots current, baseline, interval/threshold, remaining/due flag and open-WO link. No target queries per row. Calendar cards retain explicit due date and overdue state.
- [ ] Run the listed tests plus `MaintenanceOperationalSafetyTest.php`. Browser-check the narrow schedule-manager role, employee-denied role, empty/error/due/threshold cases and readable phone layout.

### Task 11: support a small technician handover (M4; A2)

**Files:** Modify `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php`, `Controllers/MaintenanceWorkOrderController.php`, `Requests/AssignMaintenanceWorkOrderRequest.php`, existing maintenance WO state/log models as needed; modify `spa/src/pages/maintenance/work-orders/detail.tsx` and `spa/src/api/maintenance/workOrders.ts`; create `api/tests/Feature/Maintenance/MaintenanceAssigneeHandoverTest.php`.

**Policy decision:** Define eligible technician roles/departments, active account requirement, and certification requirements. Recommended default suggestions: active Maintenance/Mold technicians with eligible accounts and relevant training where explicitly required. Do not hardcode “any employee” or grant broad maintenance access automatically.

- [ ] Copy the M4 fixture; assignment followed by authorized assignee replacement must retain the WO status and log old/new actor/time. Completed/canceled WO rejects changes.
- [ ] Separate initial state transition from reassignment of an already Assigned/InProgress WO. Lock and compare current assignee/version to reject stale edits. Recheck chosen employee eligibility on save, not just in dropdown.
- [ ] Add minimal Change technician dialog: current technician, debounced eligible chooser, confirmation; require a reason for a running-job handover, not every initial assignment. Preserve history and notify current/new assignee with durable entity/assignment-version dedupe.
- [ ] Test department/account/training policy, inactive employee, stale update, unauthorized user, no-op replacement, initial assignment, running handover and completed/canceled freeze. Use notification fakes.
- [ ] Keep employee record access constrained to existing permissions or existing scoped assignment mechanisms; no new temporary role injection in this maintenance slice.

### Task 12: fix asset request errors and insurance visibility (F4, F5)

**Files:** Modify `api/app/Modules/Assets/Requests/StoreAssetRequest.php`, `UpdateAssetRequest.php`, `Services/AssetService.php`; modify `spa/src/pages/assets/edit.tsx`, `detail.tsx`, and `spa/src/types/assets.ts`; extend `api/tests/Feature/Assets/AssetSalvageValueBoundTest.php` and `spa/src/pages/assets/detail.test.tsx`.

**Interface:** Existing money precision rules remain; after-validation comparisons execute only on valid scalar decimal inputs. Allow updates only to the four existing insurance fields through current `assets.update` permission; preserve all immutable acquisition/depreciation fields.

- [ ] Change F4 create probe to field-specific 422; add update salvage `1e3`, arrays, blank optional salvage, legitimate zero, and decimal upper-bound tests. `insured_value` and acquisition money use `decimal(15,2)`: reject values above `9999999999999.99` on create/update before persistence; existing create rules lack this bound.
- [ ] Before cast/comparison, skip when compared fields already have errors or non-scalar/non-decimal values. Continue rejecting scientific notation in the rule; do not make BCMath accept arbitrary input globally.
- [ ] Add existing insurance policy/provider/expiry/value fields to guarded update rules/whitelist, edit labels and detail rows. Test renewal after depreciation history exists without permitting cost/life/history mutation.
- [ ] Validate expiry/date/value consistently with create, show unavailable insurance as a quiet empty state, and retain accessible field errors. Run backend salvage tests and frontend asset tests; no separate reusable insurance framework.

### Task 13: preserve key information across page widths (U1, U2)

**Files:** Modify `spa/src/pages/accounting/balance-sheet.tsx`, `spa/src/pages/supply-chain/shipments/detail.tsx`; create `spa/src/pages/accounting/balance-sheet.test.tsx`; extend existing shipment page tests or create `spa/src/pages/supply-chain/shipments/detail.test.tsx` if absent. Reuse the audit's UI follow-up script as a measurement baseline.

**Interface:** No backend/financial response changes. Existing record flow, actions, linked records and total values remain.

- [ ] Change balance-sheet table from `min-w-[520px]` to a fluid two-column layout. Let account labels wrap; keep amount cells right-aligned and nowrap. Collapse to fewer cards per row when width cannot show complete amounts.
- [ ] Contain shipment progress flow horizontally within its own accessible scroll region or use a compact stage list on phones. Keep current stage/next action visible and contain cost tables separately; add `min-w-0` only where measured flex/grid children need shrinking.
- [ ] Verify at 390px, 768px and 1440px in light/dark: body width equals viewport, all financial total/amount cells visible without individual horizontal scrolling, shipment steps reachable by keyboard, and page actions do not overlap. Use bounding-box checks, not snapshots of Tailwind class strings.
- [ ] Check labels, tab order, escape/close behavior, 200% zoom and empty/error states. Preserve mobile table scrolling where a genuinely wide table requires it; avoid changing every table because these two pages fail.
- [ ] Run new component tests, palette contrast tests and TypeScript. Inspect browser screenshots at the changed widths; stop once measured defects pass.

## Pack 4: reliable handoffs and bounded automation

### Task 14: make customer-return notifications durable (X2; A6)

**Files:** Modify `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php`; create `api/app/Modules/ReturnManagement/Events/CustomerReturnCreated.php`, `Listeners/NotifyOnCustomerReturnCreated.php`; register through `api/app/Providers/AppServiceProvider.php`; create `api/tests/Feature/ReturnManagement/CustomerReturnNotificationHandoffTest.php`.

**Interface:** Persist a return-created notification event within the return creation transaction through existing `OutboxService::record()`. Its identity is return creation, not every retry. Subscriber resolves active permitted recipients and records delivered/blocked status through existing outbox delivery facilities.

- [ ] Inject a notification failure after successful creation and assert return persists with a pending/failed retryable event. Restore delivery, retry, assert one effective recipient notification. Test empty audience then later eligible audience.
- [ ] Replace swallowed best-effort callback with durable event; the listener must not mark delivery complete when no eligible channel/recipient exists. Preserve customer submission success and avoid throwing a post-commit error that encourages duplicate return creation.
- [ ] Test transaction rollback produces no event, duplicate event replay does not duplicate notifications, customer-party scope remains intact, and stock/credit state is unchanged. Use fakes, never live notifications.
- [ ] Show Awaiting review and internal owning-role link through existing action/notification views; do not build another queue.

### Task 15: expose actionable exception worklists and staffing disruptions (A7, A8, A9)

**Files:** Reuse `api/app/Modules/Attendance/Services/ShiftService.php`, `api/app/Modules/Leave/Listeners/NotifyOnLeaveApproved.php`, `api/app/Modules/HR/Services/SeparationService.php`, `api/app/Modules/Production/Services/WorkOrderAssignmentService.php`, current dashboard services, `api/app/Common/Services/NotificationService.php` and outbox. Create `api/app/Modules/Production/Services/WorkOrderStaffingReviewService.php` only if no existing owning service provides the affected-WO query. Create `api/tests/Feature/Production/StaffingDisruptionHandoffTest.php`; extend the affected dashboard/source-handoff tests already present.

**Interface:** Read affected nonterminal assignments and produce one actionable review intent per WO and upstream change. No automatic change to a running crew, scheduled production, account roles, money, or stock.

- [ ] Query upcoming assignments by affected employee/shift/date range on committed approved leave, calendar/shift changes and deactivation. Notify PPC with specific reason, current slot and linked WO, deduplicated by change/WO. Existing start/confirmation eligibility remains authoritative.
- [ ] Add tests for approved versus pending leave, nonoverlapping shifts, already terminal WO, duplicate upstream events, active running handover, absent recipient, and permission-scoped links. Approved leave causing a blocked start must already be visible to PPC before the attempt.
- [ ] Add bounded existing-dashboard worklists for missing accepted-GRN bill drafts, delivered orders awaiting invoice review, failed accounting source handoffs, blocked dunning, depreciation blockers and insurance renewals. Show owner, document, age, failure reason and next action. Reuse existing failed-source/run metadata.
- [ ] For asset reconciliation, show differences between register acquisition/accumulated depreciation and relevant GL, including future posted depreciation after disposal. Do not auto-reverse or invent corrections. Record manual review outcomes if an existing reconciliation feature supports them.
- [ ] Test all lists with ordinary role scopes and empty/blocked/success states; keep approver actions in the existing approval queue. Avoid a second generalized event/worklist platform.

### Task 16: resolve lifecycle/accounting policies before automation (F6, F7)

**Files:** Create `docs/superpowers/specs/2026-10-05-finance-asset-lifecycle-decisions.md` to record the two accepted policies; then modify `api/app/Modules/Assets/Services/AssetService.php` and `api/app/Modules/Accounting/Services/FiscalYearService.php` only for the approved policy. Create `api/tests/Feature/Assets/ZeroCostAssetLifecycleTest.php` and `api/tests/Feature/Accounting/FiscalClosingPrerequisiteTest.php`.

- [ ] Present F6 decision as a concrete lifecycle: zero-cost asset creation, approved no-proceeds register disposal, audit reason, no fake journal, unchanged financial totals. Alternative is rejecting zero-cost acquisitions; do not silently forbid already valid historical assets. Require user/accounting acceptance before changing the policy.
- [ ] Present F7 decision as mandatory reviewed year-end close/reconciliation, rather than posting automatic retained earnings. Specify opening balances, closing journal identity, period/fiscal boundary, reversal policy, and prevention of double-counted synthetic income. The prior-year profit probe must be tested both without and with a valid closing transfer.
- [ ] Implement only approved decisions. Tests require coherent zero-cost lifecycle and balance equation across rollover with the chosen closing rules. If close prerequisites fail, show the exact missing review/document and leave the fiscal year open.
- [ ] Insurance renewal reminders depend on Task12 and owner/recipient policy. Financial reconciliation reminders depend on Tasks1/2; no automatic historical repair is authorized by this plan.

## Final acceptance, review and rollout

- [ ] Every tracked finding has a desired-behavior test or explicit accepted policy disposition. Mapping: F1 Task1; F2 Task3; F3 Task2; F4/F5 Task12; F6/F7 Task16; M1/M2/M7/M8 Task10; M3 Task4; M4 Task11; M5/M6 Task5; S1 Task7; S2/S3/S4 Task8; V1/X3 Task9; X1 Task6; X2 Task14; U1/U2 Task13. A1–A11 are mapped in the task titles and Tasks15/16.
- [ ] Review each changed service/request/migration for role scoping, lock order, rollback, retry identity and historical preservation. Independently review each contributor's code, not only their summary. Restrict agents to the user's stated model/count limits.
- [ ] Run all changed owning-module tests and the existing four safeguard classes, then meaningful role-based transaction E2E scenarios on disposable fixtures: maintenance failure/cancel, multi-lot issue retry, partial shipment document, credit retry, revoked export, disposal date race, reliable return alert, and PPC staffing disruption.
- [ ] Verify customer/supplier/ordinary employee roles cannot perform internal warehouse, finance or maintenance decisions. Verify narrow schedule-manager access works without MRP permissions and no arbitrary account-role elevation is introduced.
- [ ] Verify source/run statuses distinguish succeeded, blocked and failed; retries do not duplicate WO, stock movement, credit, journal, notification or export intent.
- [ ] Run frontend TypeScript, changed component tests, palette checks and headless 390px/768px/1440px light/dark inspection for affected pages. Do not claim full WCAG or statutory accounting/payroll certification from these checks.
- [ ] Document compatibility deployment order for operation-key APIs and migrations. Ship schema first, updated clients/services together, verify caller compatibility, and keep financial history intact on rollback. Do not retroactively deduplicate records automatically.
- [ ] Prepare a scoped diff and validation summary for deployment review. No broad production data repair, live mail run, bank action, customs submission or destructive restore is part of this acceptance gate.

Stop when each approved slice meets its acceptance criteria and all required checks pass. Further feature ideas go into the existing backlog rather than extending this hardening work indefinitely.
