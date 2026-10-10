# Finish-first work-order QC verification

Eligible newly created standard root/replacement WOs adopt `finish_first_v1` when `WO_FINISH_FIRST_QC_ENABLED=true`. Existing WOs retain their pinned workflow. Mandatory intermediate-QC routing remains on the existing process.

The recorder reports quantity made. Production finishes the run, then the existing outgoing-QC owner creates one inspection per output. The Quality queue presents one WO task. Each physical piece receives inspection. Version 2 requires full coverage confirmation and all required numeric readings. New confirmed-check version 3 uses defect counts plus full-check confirmation; nominal targets remain references and no numeric readings are fabricated. See `outgoing-confirmed-count-20261008.md` for its verification and rollout. Defect descriptions use distinct batch piece numbers; evidence saves cover at most 50 pieces. Independent review releases conforming quantities. FAIL preserves the original outcome; segregation approval and existing repair/scrap owners govern recovery.

Finished historical work remains reportable by its former recorder using real times. Independent Production approval posts once. Completed/closed corrections create only new output inspections, preserving previous terminal evidence and holds.

## Isolated checks

Workspace: `/tmp/kwatog-finish-first-20261008`. PostgreSQL databases use `ogami_test_finish_first_*_20261008`; no live fixture writes. PHP checks run in `ogami-finish-first-20261008` with an explicit disposable `DB_DATABASE`. Legacy runs explicitly disable `WO_FINISH_FIRST_QC_ENABLED`.

- Production completion, late reporting, historical recovery, maker/checker, and WO QC connection: 57 tests passed, 320 assertions.
- Full-piece evidence, policy/migration preservation, defect recovery, inline repair, counted legacy, calibration, and sweep: 60 tests passed, 273 assertions.
- Certificate full-piece coverage and actual ranges after WO closure: 3 tests passed, 22 assertions.
- Legacy handover, output retries, routing, outgoing creation, startup, certificate, return handoff, replacement, and reinspection: 109 tests passed initially; one outdated operation fixture failed. The fixture now includes actual canonical output and isolates operation QC from the separate startup-trial gate. Startup/certificate/sweep rerun: 38 tests passed, 87 assertions; all initial cases are covered by passing results.
- Recorder, management, QC detail/group, NCR, and historical report components: 64 tests passed across seven files. Targeted ESLint passed.
- Full SPA builds passed in the isolated snapshot and live workspace. The snapshot-only supplier import cleanup was excluded from integration.
- Real-browser role walkthrough passed against `http://localhost:18084`: Production starts; recorder reports 200; no QC before physical completion; Production finishes; QC uses PASS ALL plus explicit coverage; a separate QC reviewer approves; Warehouse stock changes from 200 quality-held to 200 usable and zero quality-held.
- Inline repair regression proves three repaired and two scrapped pieces, no outgoing QC before repair finishes, QC coverage for three repaired pieces only, and unchanged original manufactured quantity.

```bash
E2E_BASE_URL=http://localhost:18084 npx playwright test -c playwright.finish-first.config.ts
```

Backend commands ran in `ogami-finish-first-20261008`, always with a disposable `DB_DATABASE` plus `WO_FINISH_FIRST_QC_ENABLED=false`, `QC_COUNTED_CAPTURE_ENABLED=false`, and `QC_CHECKLIST_CAPTURE_ENABLED=false`. New-policy test cases explicitly enable the workflow through application configuration.

```text
php artisan test tests/Feature/Production/FinishFirstWorkOrderTest.php tests/Feature/Production/LateProductionReportTest.php tests/Feature/Quality/HistoricalHeldRecoveryTest.php tests/Feature/Quality/InspectionMakerCheckerPolicyTest.php tests/Feature/Quality/WorkOrderQcConnectionTest.php
php artisan test tests/Feature/Quality/FullPieceWorkOrderInspectionTest.php tests/Feature/Quality/FinishFirstQcPolicyTest.php tests/Feature/Quality/FinishFirstDefectRecoveryTest.php tests/Feature/Quality/WorkOrderInlineReworkTest.php tests/Feature/Quality/CountedBatchInspectionTest.php tests/Feature/Quality/InspectionCalibrationEvidenceTest.php tests/Feature/Quality/SweepMissingOutgoingQcTest.php
php artisan test tests/Feature/Quality/FinishFirstCoCEvidenceTest.php
php artisan test tests/Feature/Production/WorkOrderMaterialHandoverGateTest.php tests/Feature/Production/WorkOrderOutputIdempotencyKeyTest.php tests/Feature/Production/ProductionRoutingTest.php tests/Feature/Quality/OutgoingQcIdempotencyTest.php tests/Feature/Quality/SweepMissingOutgoingQcTest.php tests/Feature/Quality/InProcessQcTriggerTest.php tests/Feature/Quality/CoCEvidenceIntegrityTest.php tests/Feature/ReturnManagement/ReturnInspectionHandoffTest.php tests/Feature/Quality/ReplacementProductionOwnershipTest.php tests/Feature/Quality/QualityReworkReinspectionTest.php
php artisan test tests/Feature/Quality/InProcessQcTriggerTest.php tests/Feature/Quality/CoCEvidenceIntegrityTest.php tests/Feature/Quality/SweepMissingOutgoingQcTest.php
```

The browser fixture script refuses any database except `ogami_test_finish_first_e2e_20261008`. Role accounts and material evidence belong only to that disposable fixture. User-facing defective-piece walkthroughs remain for the later test session.

## Adoption and rollback

Apply the additive migration before loading compatible API/SPA code. Keep new adoption disabled until the compatible build and workers are running, then enable only new creation. Turning the switch off does not change pinned WOs. The migration refuses rollback if a pinned WO or piece evidence exists; retain compatible readers rather than deleting audit evidence.

Live adoption enabled after verification. Additive migration `2026_10_08_236000_add_finish_first_wo_qc` is applied (batch 10); four group routes are registered. Configuration and route caches were cleared, and the worker restart was verified by a new worker process. Read-only counts before and after activation: three legacy WOs, zero new-policy WOs. No existing WO was converted.

No serial-number scanning, machine vision, automatic defect diagnosis, scheduler redesign, or sample-policy changes for other inspection stages are included.
