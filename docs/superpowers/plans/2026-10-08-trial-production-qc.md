# Startup Trial Production and QC Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans task-by-task. User approved the design and implementation on 2026-10-08.

**Goal:** Record real startup sample pieces before in-process QC, then classify them once and release normal production only after approved QC or recovery.

**Architecture:** Reuse WorkOrderOutput as physical ledger with nullable trial inspection, immutable trial quantity and settlement time. Extend existing output service for trial capture, evidence checks and settlement; use existing QC/NCR decisions and inventory handoff. Preserve normal production gates and legacy terminal evidence.

**Tech Stack:** Laravel, PostgreSQL, React, TanStack Query, existing house forms, PHPUnit/Vitest/Playwright.

**Spec:** docs/superpowers/specs/2026-10-08-trial-production-qc-design.md

## Global Constraints

- Trial count equals pinned sample size; one batch per current root startup inspection, no new sample default.
- Start issues material once. Trial needs physical handover or existing explicit authorized manager exception.
- Capture changes physical produced quantity and mold shots once, with zero good/reject and no finished-goods receipt before disposition.
- PASS or independently approved recovery settles original rows; good plus scrap equals actual trial quantity. Reject classification requires actual defect types/counts.
- Use source-first WO, inspection, output, equipment/inventory locks. Exact durable retries precede mutable guards.
- No retroactive business quantity/stock/shot mutations. New inspections require trial; safe untouched active draft may be marked by migration. Terminal evidence remains legacy.
- Ordinary setup permitted while trial eligible; normal production, output, completion/skip and shipment remain gated. Routing reconciliation excludes startup trials.
- No dependencies, recurring notifications, generalized trial engine or per-operation trial routing.
- Shared dirty workspace authorized throughout prior work. No reset, cleanup, unrelated edits or commits; use disposable isolated test databases per worker. No live business fixtures.

## Task 1: Physical trial ledger and settlement

**Owner:** Ledger worker. Files: additive migration; WorkOrderOutput and Inspection models; WorkOrderOutputService; WorkOrderMaterialUsageService; WorkOrderOutputResource; focused WorkOrderStartupTrialTest.

**Interfaces produced:**
```php
WorkOrderOutputService::recordTrial(WorkOrder $wo, array $data, int $recordedBy, string $key): WorkOrderOutput;
// data: trial_quantity, crew_id, recording_reason?, material_handover_acknowledged?, material_handover_reason?
WorkOrderOutputService::lockTrialSource(Inspection $inspection): void; // called within transaction BEFORE inspection lock
WorkOrderOutputService::assertTrialEvidence(Inspection $inspection, ?int $quantity = null, bool $final = false): void;
WorkOrderOutputService::settleTrial(Inspection $inspection, User $by, int $good, array $defects = []): void;
WorkOrderOutputService::trialState(WorkOrder $wo): ?array;
// {inspection_id, sample_size, recorded_quantity, settled, state: awaiting_samples|awaiting_qc|failed|released, captured_at, output_id}
```

- [x] Add meaningful failing tests for count/role/handover/equipment, immutable replay, pending receipt prohibition, PASS classification and recovery classification. Run isolated PHPUnit and confirm expected red before implementation.
- [x] Add startup_trial_required default false, nullable output trial_inspection_id unique, trial_quantity and trial_settled_at. Mark only active root-WO untouched draft inspections with actual_start, zero output/results as trial-required. Do not change any historical quantities.
- [x] Implement recordTrial in output service using existing crew, material coverage and mold services. Require correct count, active unfinished required startup QC, owned equipment and present crew, normal root WO, immutable reason/idempotency fingerprint. Persist pending evidence and physical units once. Return exact retries after terminal transitions.
- [x] Implement source lock, actual evidence check and settlement once. PASS good is whole sample. Recovery good and defect-classified scrap sum actual sample. Retain held pieces until disposition. Existing FG receipt/recovery handoff receives classified good once; retry must refuse unsettled trial. Include pending units in material coverage.
- [x] Add physical total accessor and output resource trial fields; prevent original trial quantity/source edits after capture. Run focused suite, adjacent output/receipt/idempotency suite and Pint. Report exact contracts and findings; no commits.

## Task 2: QC evidence boundaries and NCR recovery

**Owner:** Quality worker. Files: InspectionService, TriggerInProcessQC, NcrService/NcrController/NcrResource, InspectionResource, WorkOrder quality predicate; focused startup QC and recovery tests.

**Consumes:** Task 1 service interfaces, model columns. Controller recovery fields: `sorting_evidence.trial_scrapped_quantity` integer and `sorting_evidence.trial_defects` [{defect_type_id: HashID, count: integer}]. Existing mrb_accepted_quantity remains actual kept-good, whole units for trials.

- [x] Write failing tests for every result path before capture, wrong declared coverage, cancelled/replaced inspection with captured samples, and actual-quantity recovery. Run isolated PHPUnit red.
- [x] Mark newly created root-WO startup inspection trial-required. Preserve operation and repair QC. Align lockTrialSource before inspection locks across measurement/lot/checklist/batch/item/complete/review/cancel/replace paths. Call shared evidence guard before evidence writes and final outcomes.
- [x] Final PASS calls settleTrial synchronously, whole sample good. FAIL opens NCR covering actual sample, no manufactured scrap or good. Safe cancellation/replacement refuses existing trial pieces.
- [x] Extend existing independent process recovery for trial only: full sample verification, explicit kept-good and scrap/defect sum, forbid maker-checker conflict and changed settled decisions. Settle trial inside disposition transaction, preserve original FAIL evidence. WorkOrder process gate requires approved recovery plus settled trial, allows partial good after confirmed scrap. Use actual trial quantity in both modes.
- [x] Expose Inspection startup_trial_required plus trial {required_quantity,recorded_quantity,settled,captured_at,output_id}; NCR startup_trial flag and actual recovery quantity. Run quality/recovery adjacent tests and Pint, report exact contracts.

## Task 3: Recorder and QC UI

**Owner:** UI worker. Files: Production/Quality types and API, trial form component reused manager/self-service, WO detail/record-output/self-service pages, QC result panels/detail, NCR recovery page/API. Existing output forms preserve submitted key on unknown response.

**HTTP produced by integration owner:** POST `/production/work-orders/{id}/trial-outputs` permission production.wo.record and POST `/production/my-work-orders/{id}/trial-outputs` assigned access. Header X-Idempotency-Key. Request above. GET WO and self-service detail `startup_trial` Task1 trialState. QC resource `startup_trial_required`, `startup_trial` Task2. Existing output receipt endpoints resolve original trial capture by key.

- [x] Write failing UI tests for prefilled pinned sample count, unchecked confirmation, pending save replay, waiting-for-samples QC and trial recovery count/defect payload.
- [x] Reuse house components for one compact TrialProductionPanel on manager and assigned recorder pages; count defaults pinned exact size, confirmation explicit, current crew shown. Avoid normal output submitting a trial flag. Unknown outcome freezes exact payload/key and resolves via receipt check.
- [x] Show next step once on WO: handover, sample capture, QC, recovery, ready. Pending trial output history shows actual sample count and Awaiting QC. Size checks remain numerical, others PASS/FAIL.
- [x] Disable QC result entry with one notice before captured full sample. Extend NCR recovery form with actual kept-good and explicit scrap/defects, independently approved recovery; do not apply changes to non-trial recoveries.
- [x] Run focused Vitest, ESLint, build and detector; report files, evidence and limits. No commits.

## Task 4: Routes, setup, inventory and integrated proof

**Owner:** Root. Files: TrialOutputRequest, production controllers/routes/resources, WoOperationService, StockMovementService and any manual FG receipt boundary; focused integration/routing/inventory tests, testing report.

- [x] Before implementation, write failing route/permission, setup allowance, operation no-pieces-QC and pending inventory receipt tests. Use disposable database. Confirm red cause.
- [x] Add dedicated trial request and both role paths; no generic flag in ordinary output. Expose startup_trial and physical pending counts in manager/assigned detail and receipt summaries.
- [x] Allow setup start/end under state, owned equipment, crew attendance, full coverage and confirmed handover while startup trial current unfinished. Other operation guards unchanged. If root QC failed, setup remains blocked. Ordinary operation QC requires positive actual integral qty_completed, no target fallback. Final reconciliation excludes startup trial units.
- [x] Manual and automatic inventory receipt plus outgoing inspection creation independently reject unsettled trials, including direct service callers and parent-WO receipt routes.
- [x] Run all focused workers' suites and nearby production/QC/receipt/movement suites; review changed contracts and fix actual gaps once. No whole-repo audit.
- [x] Isolated Playwright manager/Warehouse/recorder/QC/reviewer flow: Start, no samples QC block, handover, trial, normal-output block, PASS or FAIL/recovery, ordinary output. Assert sample count/shots/receipt each once; desktop/mobile together. Save report and screenshots. Apply additive live migration only after working UI and backend verified; no live fabricated QC/counts.
- [x] Final narrow review, PHP/TS build/lint and acceptance proof; update design/plan status, preserve unrelated workspace changes and stop.

## Rulings

- Execution continues after approved design without another method-choice question. Independent owners share exact contracts above; tightly connected mutations stay within each owner.
- Whole pinned sample is captured once instead of incremental partial capture. This is the approved spec's explicit data rule and makes cap/replay/accounting smaller and clearer.
- Legacy final QC remains historical; untouched active draft eligibility is strict and no result author/measurement result or physical output may exist before marking.

Integration evidence: `docs/testing/startup-trial-qc-20261008.md`. Review follow-ups fixed smaller declared sample coverage, prepared-draft backfill, paused-startup resume, operation checklist source quantity, and lazy-loaded output history.
