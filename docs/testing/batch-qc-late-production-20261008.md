# Batch QC and late production reporting verification

Approved scope: aggregate defective quantity and problem entry; required size readings separately; compact startup samples; reviewed reports of production physically completed earlier. No new dependencies or scheduler changes.

Status: Implemented, verified, and enabled for new inspections. Existing business evidence remains unchanged.

## Delivered behavior

- New counted inspections derive PASS/FAIL from actual checked quantity, unique defective count, requirement problems, and required numeric readings. No per-piece or per-attribute verdict controls are required.
- New outgoing batches require full appearance/attribute coverage. Dimensions retain their pinned sample; a five-piece dimensional sample does not certify 200 pieces by itself.
- Material lots use an affected-lot confirmation and retain the original material quantity and unit. Kilograms are never presented as pieces.
- A counted FAIL releases zero pieces automatically. The existing NCR workflow requires full affected-source sorting and independent recovery approval. Explicit conforming, repair, scrap, and unresolved quantities remain subject to conservation and release guards.
- Startup uses one “Samples ready for QC” action with its pinned quantity and physical confirmation. It counts actual sample pieces once; required manager explanation and warehouse evidence remain enforced.
- Former assigned recorders can submit additional unrecorded output with actual production start/finish, original source reference, counts, defects, and reason. Submission and rejection change neither production totals nor inventory.
- Independent production management reviews historical crew, attendance, execution, and equipment evidence. Missing history requires a reconciliation note; conflicting history blocks posting. Closed work orders require correction review rather than automatic posting.
- Approval stores actual plant production times separately from entry/review times. It posts once without extending today’s schedule or acquiring current equipment. A repeated approval returns the same output.
- Bulk production before its required startup release stays held, with an explicit process-deviation NCR and full affected-batch QC. It does not create a fictional startup PASS or available finished-goods stock. Independently approved sorting classifies the existing physical pieces without incrementing production or mold usage again.

## Verification

All fixtures and role sessions used disposable databases, not live business evidence.

| Check | Result |
| --- | --- |
| Counted capture, historical held recovery, certificate integrity, batch recovery, startup quality and startup ledger | 88 tests, 373 assertions passed |
| Legacy quick results, measurements, specification boundaries, return handoff, operation samples and startup receipt eligibility | 65 tests, 369 assertions passed |
| Output idempotency, finished-goods receipts, good target, outgoing checklist, NCR disposition races and quarantine | 59 tests, 286 assertions passed |
| Historical reporting, ended assignment, attendance/shift conflict, sample-only approval, full hold, material handover chronology, target/reject accounting, permissions and retries | 15 tests, 135 assertions passed |
| Eight focused QC/worker/manager frontend files | 58 tests passed |
| Crew, rework, date formatting and retained legacy inspection forms | 68 tests passed |
| Late-entry, worker and manager detail frontend checks, including both history pagers | 29 tests passed |
| Held-output manager record regression | 1 test, 25 assertions passed |
| TypeScript and production build | Passed |
| Scoped frontend lint | Passed |
| PHP formatting, root scope | 19 files passed |
| Browser desktop/mobile design scan | Passed; visual inspection and confirmation completed |

The final history pagination and build checks passed. Manager and recorder lists use bounded pages and scroll areas. These groups overlap with earlier runs; their counts are not summed as a repository-wide total.

## Browser evidence

[Role-based results](evidence/counted-role-playwright-20261008/results.json) cover real manager, warehouse, recorder, support, and QC sign-in, worker punches, start creating only a draft, actual warehouse handover, five sample pieces on mobile, and aggregate zero-defect QC.

[Normal output proof](evidence/counted-role-playwright-20261008/normal-output/results.json) confirms a valid support-worker payload and submission key are rejected with the designated-recorder message. The recorder can then post normal output after quality release; production increases by one and raw stock is not issued again.

[Dimensional failure proof](evidence/counted-role-playwright-20261008/dimensions/results.json) checks all 200 pieces through aggregate entry while retaining five required dimensional readings. Omitted readings prevent submission; an out-of-limit reading and one declared defective piece produce FAIL with zero accepted quantity. SQL verifies all five readings persisted, including one failed reading.

[Historical role and concurrency proof](evidence/counted-role-playwright-20261008/late-production/results.json) covers a former recorder’s completed work, independent browser approval, rejection, and simultaneous duplicate approval through two PHP processes against PostgreSQL. Both approvals returned the same output. [Ledger verification](evidence/counted-role-playwright-20261008/late-production/verification.json) proves three reports, two outputs, 15 physical held pieces, zero finished-goods stock or movements, unchanged idle equipment ownership, and mold usage increasing from 25 to 40 once. Persisted plant output intervals match the submitted intervals.

[Material-lot browser proof](evidence/counted-role-playwright-20261008/material-lot/results.json) verifies a 200.500 kg source, no piece-count field, one affected-lot confirmation, required problem text, and derived FAIL.

Mobile draft recovery restores counts, problem text, and readings but clears physical coverage confirmation. That confirmation must be made again. Desktop and 390-pixel mobile captures have no horizontal overflow.

An unrelated container restart interrupted an isolated test server and one verification run. The isolated server was restored and affected checks were rerun. An initial browser harness expected rejected pieces to be posted; its expectation was corrected and the actual conserved ledger was independently verified. These were verification-harness failures, not suppressed application errors.

## Schema and rollout

The three additive migrations add counted inspection evidence, immutable late-report proposals, and held/historical output fields. An empty-schema down/up round trip passed. Each migration refuses destructive rollback once its evidence exists. Application rollback must preserve the additive schema and records.

Counted capture was enabled in the live mounted application on October 8, 2026 after the focused backend and browser checks. Configuration was cleared and verified. Live evidence remained eight existing inspections, zero counted inspections, zero late-report records, and zero held outputs; no business fixtures were created for testing.

Existing submitted and terminal inspection evidence keeps its original interpretation. No automatic conversion or invented historical PASS records is performed. New forms are selected by the pinned capture contract, not by changing old results.

The last browser refresh exposed an implicit relationship load in the historical QC summary. The shared model now evaluates historical evidence without implicit loading. The held-output manager GET regression passed (1 test, 25 assertions). A follow-up 14-test/64-assertion quality check passed, and the manager record returned HTTP 200 with approved held history, readable recorder names, and no mobile horizontal overflow.

## Limits

- Aggregate entry reduces paperwork, not physical inspection work. Full appearance coverage still requires checking the pieces; required dimensional sampling still requires real readings.
- Reports cover only additional unrecorded pieces. They cannot include already recorded startup samples or overlap existing production intervals. Ambiguous history needs management reconciliation.
- A missing attendance record is not an absence. Missing historical evidence can be reconciled with a documented manager note; explicit conflicting records cannot be bypassed.
- A batch produced before required startup QC requires independent process recovery even when its current product inspection finds zero defects.
- Existing repair, scrap, replacement, quarantine, and manual finished-goods receipt recovery are reused. A missing item/location setup can still require the existing manual receipt recovery path.
- Historical list/detail compatibility payloads still include the recorder’s own report rows; those embedded responses are not separately paginated. The report-entry/review histories themselves are paginated and bounded.
- The full repository suite and sustained load testing were not run. Concurrency proof covers simultaneous duplicate approval, not every possible independent stock or HR edit.
