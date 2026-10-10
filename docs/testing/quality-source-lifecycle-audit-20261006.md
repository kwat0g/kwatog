# Quality inspection and source lifecycle audit — 6 October 2026

The findings below describe the original audit baseline. Approved fixes and final verification are recorded in [the implementation report](quality-source-lifecycle-implementation-20261006.md).

## Scope and outcome

Audited the current implementation of all five inspection stages, work-order completion and closure, defect disposition, repair and replacement ownership, incoming receipts, customer and supplier returns, stock release, delivery, MRP, notifications, permissions, and the corresponding frontend flows. This is an audit, not an implementation change.

**Production completion and quality closure are already separate.** A WO can become `completed` while outgoing QC is pending. It cannot normally become `closed` until its required QC, linked NCRs, output receipts, and accounting handoffs are settled. Repairable outgoing defects can be repaired on the original completed WO. A defect discovered after closure uses a separate linked repair WO; the original WO remains closed.

The earlier suggestion that every defective piece is replaced on the same WO, or that closure always waits for the original target quantity to be accepted, does **not** describe the current implementation. Scrap replacement uses MRP for sales-order production and NCR-created replacement WOs for other production. Original-WO closure does not wait for a replacement WO to finish.

## Current source flows

| Source/stage | Current behavior | What prevents premature release |
| --- | --- | --- |
| WO in-process | Starting production creates in-process QC. Recording output and completing the WO require an authorized process verdict. A failed process inspection needs independently approved recovery. | Process gate in `WorkOrder::assertProcessQualityReleased()`; also used by output and outgoing release. |
| WO outgoing | Completion stages a separate inspection for every positive-good output batch, including repair output. A failed final verdict automatically opens an NCR. | Independent QC review, output-specific provenance, accepted quantity, stock hold, and delivery revalidation. |
| Incoming GRN | QC is staged per received inventory-item line, using its active quality plan or fallback checks. Failed QC waits for MRB when configured. Good quantities can be accepted; rejected quantities open a supplier return. | Every eligible receipt line needs active, final, independently reviewed incoming QC. Cancelled QC cannot authorize acceptance. |
| Customer return | Physically received product lines get customer-return QC. Restock needs PASS; rework or scrap needs a final verdict. Restock/rework enters quarantine before independent full-quantity release. | RMA disposition checks and MRB stock-release checks. Ordinary return QC itself does not require a separate checker. |
| Supplier return | Product-linked lines get supplier-return QC. Return disposal uses the original GRN/PO/bill lineage and guards against reversing rejected goods twice. | Return source-allocation and physical/financial disposition guards. Inventory-item-only lines currently skip return QC. |
| Standalone inspection | Some stages allow a record without a physical source. It records diagnostic evidence, but cannot release output or settle a receipt through the source-bound release paths. | Outgoing inspection requires an exact output batch; incoming acceptance requires exact receipt-line coverage. Standalone status must not be presented as stock-release authority. |

Sources: `InspectionService.php:428`, `InspectionService.php:1641`, `WorkOrder.php:99`, `TriggerOutgoingQC.php:44`, `GrnService.php:1031`, `ReturnRequestService.php:2176`, `ReturnRequestService.php:3521`, and `QuarantineService.php:580`.

## Completion, closure, and a defective piece

### Before closure

`WorkOrderService::complete()` requires completed/skipped routing operations, process-quality release, at least one good output for ordinary production, and settled output receipts/ledger handoffs. It releases machine/mold capacity and retires the schedule. **It does not require outgoing QC to finish or good quantity to equal the target.** An existing test completes a WO targeting 100 after recording 50 good pieces to verify that equipment is released.

`close()` and automatic closure share `assertQualityGateSettled()`. Every positive-good output needs final outgoing coverage and independent approval or a resolved defect disposition. Unfinished inspections and open linked NCRs block closure. Stock receipt and accounting handoffs must also be resolved. Completion therefore means the production run ended; closure means that run's quality obligations were resolved.

For 100 produced pieces with one defective piece, approved sorting can release the verified 99. A repairable piece receives a scheduled repair on the same **completed** WO. Repair output receives fresh outgoing QC and does not increase original manufactured totals. NCR closure waits for repair completion and checked repair output. Final WO closure then occurs automatically.

If the piece must be scrapped, the source stock is reduced. Sales-order replacement belongs to MRP; stock-production replacement belongs to the NCR workflow. Both use another manufacturing WO rather than recording new manufacture as repair on the original completed order. The original WO can close while replacement production remains pending.

Sources: `WorkOrderService.php:625`, `WorkOrderService.php:709`, `WorkOrderService.php:752`, `WorkOrderService.php:1030`, `WorkOrderReworkService.php:115`, `NcrService.php:349`, `NcrService.php:667`, and `WorkOrderCloseQualityGateTest.php:327`.

### After closure

An NCR can report a new defect against an existing final outgoing inspection without reopening the original WO or rewriting its previous verdict. It revokes release of remaining original stock, accounts for pieces already shipped or consumed, and preserves earlier incidents. Repair receives a separate linked service WO. Stock and delivery checks use current QC/NCR release authority, not just `WO.status`.

If the affected pieces have already left the original stock, the system directs reporting against repaired output or a customer return. A closed WO cannot record ordinary additional output. Traceability includes a recall simulation, but that simulation is not an executed customer recall or automatic customer communication.

Sources: `NcrService.php:129`, `NcrService.php:452`, `Inspection.php:203`, `WorkOrderQcConnectionTest.php:146`, `WorkOrderQcConnectionTest.php:375`, `WorkOrderQcConnectionTest.php:445`, and `TraceabilityService.php:35`.

## Ranked gaps and risks

### 1. High — cancelling produced goods can strand stock and suppress replacement demand

**Reproduced.** A paused WO with 100 produced good pieces can be cancelled. Its outgoing release then becomes invalid because the WO is cancelled, but `MrpEngineService::remainingToProduce()` still counts its 100 good pieces and calculates zero remaining production. Stock becomes unreleasable while MRP sees no shortage. The cancellation service blocks linked repair cancellation but does not block ordinary production cancellation after output exists.

**Minimal correction:** prohibit ordinary cancellation after output or physical production effects exist. Finish the run and resolve QC/NCR instead. Make MRP's production-credit predicate agree with cancellation semantics; merely excluding cancelled WOs would leave the physical stock problem unresolved.

Evidence: `WorkOrderService.php:776`, `Inspection.php:203`, `MrpEngineService.php:1080`; disposable cancellation probe, 1 test / 3 assertions.

### 2. High — a late defect on accepted supplier material does not automatically hold stock

**Reproduced.** After a checked incoming PASS and GRN acceptance, creating an NCR for a newly discovered defective piece leaves the material's usable quantity unchanged and its quality-held quantity at zero. Automatic release revocation in `NcrService::create()` applies to outgoing WO output only. Incoming rework uses quarantine during pending-GRN settlement; a new incident after acceptance requires a separate manual hold.

**Minimal correction:** use the existing quarantine workflow for remaining affected receipt-lot stock when a late incoming defect is reported. Preserve already consumed quantities, identify downstream WOs through traceability, and expose a review action. Route supplier return through the accepted-stock path, which reverses receipt quantities exactly once; the pending-receipt NCR return helper assumes reversal already occurred and must not be reused blindly.

Evidence: `NcrService.php:129`, `NcrService.php:936`, `QuarantineService.php:375`, `GrnService.php:1240`, `StockLocationSummaryService.php:40`; disposable late-incoming probe, 1 test / 3 assertions.

### 3. High — PASS does not establish that all 100 pieces were inspected

**Confirmed in code and live configuration.** A 100-piece outgoing batch uses a 20-piece sample. Incoming plans can be full, fixed, or AQL; fallback incoming also samples. The checklist requires one PASS/FAIL per requirement and confirmation that the planned sample was checked. No critical-check override requires full-piece coverage. The simplified UI was implemented; the proposed full-inspection policy was not.

**Minimal correction:** distinguish sampled acceptance from full verification explicitly. If the approved policy requires every piece checked, set the required coverage to the batch quantity for applicable countable incoming/outgoing batches and enforce it on the server. Keep one checklist decision per requirement, not 100 repeated forms. Bulk materials must retain their actual unit and lot basis. Physical inspection cannot be automated by a PASS button, and even full inspection cannot guarantee detection of every latent defect.

Evidence: `InspectionService.php:183`, `InspectionService.php:309`, `InspectionService.php:565`, `InspectionService.php:1122`, `ChecklistDecisionPanel.tsx:146`, `AqlSampleSizeService.php:24`.

### 4. High — incoming Scrap follows supplier-return logistics

**Reproduced.** Choosing incoming `scrap` and keeping 99 of 100 produces a supplier-return RMA for one piece. GRN settlement treats both scrap and return-to-supplier as rejected receipt quantities and calls the same rejection/return workflow. This can conflict with the physical instruction to dispose locally.

**Minimal correction:** make disposition routing source-specific. Supplier return should create return logistics; local scrap should require recorded disposal and an explicit supplier credit/replacement decision. If local scrap is unsupported for unaccepted receipts, block that option rather than silently routing it as a return.

Evidence: `GrnService.php:1156`, `GrnService.php:1272`, `GrnService.php:933`; disposable incoming-scrap probe.

### 5. High — incoming MRB accepts fractional pieces

**Reproduced.** A 100 PCS receipt can accept `98.5` good pieces and create a supplier return for `1.5` PCS. Both controller and service accept three-decimal quantities; the service does not apply the countable-unit guard used by inspection capture. The frontend enforces integers for outgoing recovery only.

**Minimal correction:** reuse countable-unit validation for MRB good quantity and return quantity. Keep decimals for KG/L and other divisible units.

Evidence: `NcrController.php:100`, `NcrService.php:531`, `spa/src/pages/quality/ncrs/detail.tsx:145`; disposable fractional-PCS probe.

### 6. High — cancelled incoming QC cannot recover through the handoff retry

**Reproduced.** Cancelling the only incoming inspection and retrying the GRN handoff leaves zero active inspections but reports the handoff as `generated`. The listener's existence checks include cancelled rows; acceptance correctly requires active coverage and remains blocked.

**Minimal correction:** make both per-line and legacy existence checks ignore cancelled rows. Retry should create one active replacement and report success only when active coverage exists. Preserve cancelled evidence.

Evidence: `TriggerIncomingQC.php:63`, `TriggerIncomingQC.php:91`, `GrnService.php:660`, `GrnService.php:1580`; disposable cancellation/retry probe.

### 7. High — new checklist QC can be created on a closed WO but cannot finish

**Reproduced.** Outgoing creation and completion-event replay permit a closed source WO. Checklist submission/review rejects that same closed source. A legacy/imported closed WO without an active outgoing inspection can therefore acquire a draft that cannot be submitted. Existing final outgoing evidence remains usable for the supported late-NCR path.

**Minimal correction:** align creation and submission eligibility. Direct closed-WO creation should either be rejected with a clear late-defect route, or use an explicit recovery workflow preserving closed history. Do not remove the source guard indiscriminately.

Evidence: `InspectionService.php:450`, `InspectionService.php:1023`, `TriggerOutgoingQC.php:44`; disposable closed-WO checklist probe.

### 8. Medium — completion and replacement fulfillment remain easy to confuse

An ordinary WO can complete below target without an explicit shortfall decision. Closure does not require final accepted quantity to meet the target, nor a replacement WO to finish. RMA completion also does not mean a linked replacement PO has been received. These can be valid separate obligations, but a generic Completed/Closed label hides the remaining work.

**Minimal correction:** label production completion as “Production finished — awaiting QC” while blocked. Require an explicit short-completion reason when below target. Show source quantity, QC-approved quantity, rejected quantity, and replacement progress separately. Do not keep equipment occupied while waiting for QC or supplier redelivery. If business policy requires the original WO to stay open until replacement fulfillment, add that specific gate without merging production and fulfillment counters.

Evidence: `WorkOrderService.php:625`, `WorkOrderService.php:1030`, `NcrService.php:737`, `ReturnRequestService.php:3086`, `ReturnRequestService.php:2710`, and `spa/src/pages/production/work-orders/detail.tsx:494`.

### 9. Medium — return inspection coverage depends on Product linkage

Inventory-item-only/free-text return lines skip return-stage QC; both staging and disposition checks use `product_id`. Four live return lines have an inventory item but no product. This may be intentional for already rejected raw-material supplier returns, but it does not distinguish those from other physical returns needing quality evidence.

Ordinary customer/supplier return QC also lacks a mandatory independent checker. Customer good-stock release remains protected by an independent MRB reviewer, so this is not a universal unsafe-restocking bypass.

**Minimal correction:** explicitly reuse checked incoming evidence for already rejected supplier goods. For other physical inventory-item returns, use existing item quality plans. Exempt finance-only/no-goods cases explicitly. Apply independent QC review where the verdict authorizes financial or physical disposition, preserving MRB review.

Evidence: `ReturnRequestService.php:2176`, `ReturnRequestService.php:3521`, `Inspection.php:167`, `QuarantineService.php:580`, and `ReturnInspectionHandoffTest.php:193`.

### 10. Medium — mixed-quality returns cannot split one line's disposition

One returned line may take only one disposition. A return line containing 100 pieces cannot directly record 99 restocked and one scrapped. MRB release also handles the entire held quantity. Users may be pushed into treating the whole line as defective or creating additional records with confusing quantity ownership.

**Minimal correction:** permit a controlled quantity split at disposition, or an explicit pre-disposition line split preserving source allocation and total received quantity. Keep one physical quantity owner and prohibit duplicate credits or stock movements.

Evidence: `ReturnRequestService.php:2506`, `QuarantineService.php:622`.

### 11. Medium — in-process recovery can demand evidence for pieces not produced yet

WO-level in-process QC uses the WO target as its batch quantity. Recording output is blocked until process QC is released. Failed recovery requires sorting/reinspection of that entire target. A first-piece failure on a target of 100 can therefore require evidence for 100 pieces before any output can be recorded. This is a code-level workflow risk, not a reproduced shop-floor incident.

**Minimal correction:** separate process/setup correction from physical-piece sorting. Recovery should verify the corrected process and identify/dispose of the actually affected pieces; whole-batch outgoing verification remains the later gate.

Evidence: `InspectionService.php:533`, `WorkOrder.php:99`, `WorkOrderOutputService.php:266`, `NcrService.php:399`.

### 12. Medium — approved good pieces from failed QC lack the normal delivery automation

Approved sorting can make 99 pieces releasable while the inspection remains Failed. Manual delivery selection correctly includes them, but `CreateDeliveryDraftOnQcPass` accepts only a Passed event/current status. MRB recovery emits MRP replan events, not the same delivery-draft handoff. This leaves a manual next step after otherwise automated recovery.

**Minimal correction:** reuse delivery-draft generation from an idempotent approved-quantity event, using current release checks. Make the QC/NCR page show the approved quantity and the next delivery action meanwhile.

Evidence: `CreateDeliveryDraftOnQcPass.php:47`, `DeliveryService.php:185`, `NcrService.php:481`.

### 13. Medium — notification audience does not follow every source owner

Live failure notifications target Production Manager and QC Inspector. Supplier-return notifications target Purchasing only, and that notification happens on NCR closure. Inline-repair notices target the WO creator and Production Managers, not assigned workers directly. Warehouse, PPC, and supplier/customer contacts therefore do not necessarily receive the next action when relevant. The failure message also says “NCR may be required,” although final failures automatically open one.

**Minimal correction:** use existing notification infrastructure with source-specific internal owners and assigned repair recorder/crew. Link the actual NCR/GRN/repair action and say what is pending. External portal notifications should communicate approved business actions, not send internal defect details automatically.

Evidence: `NotifyOnInspectionFailed.php:35`, `WorkOrderReworkService.php:124`, `NcrService.php:1040`, `InspectionService.php:1657`.

### 14. Medium — closed legacy records and historical receipts need clearer evidence labels

Live history contains closed WOs without today's required active in-process evidence and closed WOs with open NCRs. These counts alone cannot establish whether a historical close was unsafe: late defects intentionally leave the original WO closed. The current quality-close blocker is calculated only for Completed WOs, so Closed does not communicate a later hold.

Accepted supplier returns also reduce the original GRN's received/accepted quantities. The movement ledger preserves physical effects, but a user reading only the GRN may confuse original receipt quantity with net remaining acceptance.

**Minimal correction:** show current quality holds on closed sources, label historical missing evidence, and link recovery work. Display original physical receipt and returned/net quantities distinctly. Do not fabricate inspection results or reopen every historical record automatically.

Evidence: `WorkOrderService.php:768`, `ReturnRequestService.php:2608`, and the read-only live observations below.

### 15. Low — held defects distributed across bins require manual consolidation

Outgoing scrap/repair requires all affected pieces to be available in one held lot bin. Split-bin stock produces a visible error requiring consolidation.

**Minimal correction:** first improve the error and show eligible bins. If this occurs frequently, let the existing movement service consume exact held quantities across bins in one transaction; do not choose or move good stock implicitly.

Evidence: `QualityStockHoldService.php:19`.

## Protections already present

- Exact output/receipt lineage and immutable specification/plan evidence protect source identity and interpretation.
- Checklist FAIL requires a problem description; physical defective pieces are counted uniquely rather than once per failed requirement. Numeric measurements are not required for new checklist capture.
- Independent outgoing and incoming review, optimistic result versions, and locked source checks protect against self-review, stale submissions, and changing source quantities.
- Failed stock stays held. Existing delivery reservations are revalidated before shipping; held finished goods cannot be issued as raw material.
- Repair output is separately traceable and conserves existing stock; repair rejection cannot fabricate good output.
- MRP owns sales-order scrap replacement, while NCR owns other replacement manufacture. Undecided failed QC remains a production commitment to avoid duplicate manufacture before disposition.
- Supplier rejection and return share a deduplicated return workflow and prevent double reversal. Replacement-PO creation short-closes the original obligation only under checked conditions.
- Return inspection setup failures and GRN handoff failures expose recoverable states rather than claiming the physical process finished.
- Route permissions separate inspection management, review, NCR management, production actions, and return actions. Service guards remain necessary because queued/system callers bypass page controls.
- Nominal/minimum/maximum are specification references. Checklist-only observations do not provide the actual measurement distribution required to calculate Cp/Cpk.

## Read-only live observations

Application bootstrap queries ran inside a PostgreSQL read-only transaction. No live records were changed.

| Observation | Result |
| --- | --- |
| New checklist capture flag | Enabled |
| Incoming failure MRB review | Enabled |
| Outgoing sample for 100 pieces | 20; acceptance 0 / rejection 1 |
| Closed WOs | 15 |
| Closed WOs below recorded good target | 0 |
| Closed WOs with unfinished linked QC | 0 |
| Positive-good outputs on closed WOs without final outgoing QC | 0 |
| Closed WOs without active in-process QC | 13 |
| Open NCRs linked to closed WOs | 5 |
| Existing QC records with a null sampling basis | 102 |
| QC records without an entity ID | 50 |
| Cancelled GRN-line QC records | 0 |
| Return lines with inventory item but no Product | 4 |

Counts include legacy/demo records. Null sampling basis is not proof of new-checklist malfunction; existing records retain legacy capture. Missing source IDs can represent diagnostics. The five open NCRs on closed WOs can represent supported late-defect handling. None of those histories were rewritten during this audit.

## Verification and limits

Existing regression run on the disposable database `ogami_test_pricing_20261004`: **97 tests, 492 assertions passed**. Covered `WorkOrderCloseQualityGateTest`, `WorkOrderQcConnectionTest`, `WorkOrderInlineReworkTest`, `WorkOrderProcessQualityGateTest`, `IncomingMrbDispositionTest`, `ReturnInspectionHandoffTest`, `SupplierReturnLifecycleTest`, and `ChecklistLifecycleTest`.

Six disposable audit probes passed **16 assertions documenting the current defects**, not desired acceptance criteria: fractional PCS, incoming scrap routing, cancelled incoming handoff retry, closed-WO checklist creation/submission mismatch, cancelled produced stock versus MRP credit, and missing automatic hold for a late incoming defect. These probes must be inverted into regression tests when fixes are implemented. They reuse existing fixtures and run only against the disposable database.

Evidence directory: `docs/testing/quality-source-lifecycle-audit-20261006/`. No browser E2E was run in this audit; frontend findings came from source inspection. No exhaustive concurrency or permission-matrix claim is made. Current source code and focused tests establish the findings; policy recommendations are identified separately.

## Suggested implementation order

1. Fix reproduced stock/quantity/recovery defects: cancellation after production, late incoming stock holds, fractional PCS, wrong Scrap routing, cancelled QC retry, and closed-source eligibility.
2. Implement the approved inspection coverage policy while retaining one PASS/FAIL per requirement and conditional problem input. Explicitly preserve bulk-material units and legacy evidence.
3. Clarify completion versus quality closure and replacement fulfillment; repair in-process recovery scope and physical-return coverage.
4. Add source-specific next actions and notifications, then reuse delivery automation for approved sorted quantities. Improve return disposition splitting and bin guidance only where required by the physical workflow.

Avoid a universal “all modules must be closed” dependency: it would lock equipment or receipts behind unrelated future replacement deliveries and CAPA effectiveness reviews. Gate each stock or financial action on its own authoritative evidence and expose outstanding linked obligations.
