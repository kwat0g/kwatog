# Work order and QC connection repairs

The user approved the audit's fixes. Reuse inspections, NCR dispositions, work orders and the stock ledger. Keep batch PASS/FAIL and immutable original results. No new workflow engine or per-piece forms.

1. Add nullable operation/source lineage and confirmed recovery quantities. Keep historical rows; preserve cancelled inspections. Allow a new NCR after a previous incident closes while permitting only one active incident per inspection.
2. Hold a previously approved batch immediately when a linked defect is reported. Delivery, certificate and production consumption must respect the same release evidence. Require valid inspection coverage before WO closure and independent operation QC before completing required routing steps.
3. Keep failed output as a production commitment until its disposition establishes replacement demand. Sorting distinguishes held quantity from confirmed defective quantity. Create one source-bound repair WO during rework disposition, retain the original sales-order lineage, and keep the NCR open until repaired output passes outgoing QC.
4. Repair consumes the original held lot and produces the repaired lot once. Do not regenerate the original manufacturing BOM. Additional repair materials use the existing manual material plan. Scrap must reconcile the original lot.
5. Repair the per-output missing-inspection sweep and NCR detail context. Add source-prefilled defect reporting and visible repair progress using existing pages.
6. Prove late defects after WO closure, 99-good/1-repair, repeated incidents, queued missing QC, two operation gates, held material consumption, and stock conservation in an isolated test database. Run focused backend and frontend checks, then apply only the additive migration and build.

Rollback removes new fields only if no operation-specific inspections, repeated incidents or source-bound repairs or recovery evidence exist; otherwise refuse and preserve the audit trail. Existing unknown evidence is not backfilled.

Verification uses the isolated `ogami_test_quality_batch` database. The additive migration was applied to the application database without changing record counts (6 work orders, 11 inspections, 48 NCRs). The queue worker was restarted and confirmed running. The frontend build, targeted lint, and 18 checklist/detail tests passed. Document numbering now advances past imported work-order numbers using the existing persisted-number recovery mechanism.

Final regression passed: 361 quality/production/MRP/inventory tests (1,346 assertions), plus 64 focused numbering/QC/material-reservation tests (260 assertions). Static analysis passed for changed services; the frontend production build and targeted lint passed. Live inspection/NCR routes returned HTTP 200 and the migrated inspection service loaded successfully.
