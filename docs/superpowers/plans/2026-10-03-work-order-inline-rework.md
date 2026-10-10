# Work Order Inline Rework Implementation Plan

> Execute in this session against the approved design. Preserve the shared workspace and existing changes; run database tests only against a dedicated test database.

**Goal:** Keep open-WO repair inside the source order and automatically close only fully resolved production and quality records.

**Architecture:** Reuse NCR sorting, WO outputs, inventory movements, existing lifecycle guards, and durable outbox events. Add nullable repair lineage and timestamps; keep closed-WO separate repair compatibility.

**Tech Stack:** Laravel/PHP, PostgreSQL, React/TypeScript, Vitest, Playwright Chromium.

**Spec:** `docs/superpowers/specs/2026-10-03-work-order-inline-rework-design.md`

## Global Constraints

- No database resets outside the dedicated test database.
- Preserve original failed QC and original production quantities.
- No duplicate inventory or BOM issue during repair.
- Independent checker and sorting approver controls remain enforced.
- No automatic equipment restart; no destructive historical migration.

## Task 1: Inline repair and stock invariants

Files: additive migration; `WorkOrderOutput`, `NonConformanceReport`; `NcrService`; `WorkOrderOutputService`; new `WorkOrderReworkService`; `MrpEngineService`; focused `WorkOrderInlineReworkTest`.

- [x] Write and run failing tests for same-order repair selection, closed-order fallback, one-piece repair inventory conservation, unchanged WO totals, quantity limits, explicit repair start, unfinished repair NCR gate, and MRP commitment.
- [x] Add nullable output NCR/source fields and NCR start/finish timestamps. Rollback refuses populated lineage.
- [x] For open source orders, set NCR repair WO to the source; preserve existing separate repair orders.
- [x] Start/finish rework explicitly with locks and equipment occupancy checks. Reuse output recording and stock receipts while excluding repair from original production/material totals.
- [x] Scope same-WO NCR repair evidence to its own outputs and conserve MRP fulfillment credit.
- [x] Run focused tests and closed-WO regression.

## Task 2: Automatic closure and notifications

Files: `WorkOrderService`; new quality closure event/listener; `InspectionService`; `NcrService`; event registrations; closure and notification tests.

- [x] Write failing tests for automatic closure on last QC/NCR resolution, stale/duplicate events, pending QC, missing receipts, active repair, unresolved failures, and actionable production notifications.
- [x] Reuse the locked manual close gate, strengthening release/receipt checks and excluding repair output from original quantity reconciliation.
- [x] Emit durable closure checks after final QC, NCR resolution, production completion, and receipt recovery. Preserve order status when blocked.
- [x] Record automatic close reason and notify production handlers, with manager fallback.
- [x] Expose closure blocker and rework progress in existing resources.
- [x] Run nearest backend suites.

## Task 3: Production and quality UI

Files: existing production routes/controller/API/types/detail; quality NCR resource/types/detail; small rework section component; component tests.

- [x] Add role-protected start, output, and finish rework endpoints using existing lifecycle/output permissions.
- [x] Show Awaiting QC, Rework required, Rework in progress, and repair QC waiting states without changing existing status values.
- [x] Provide production rework actions inside the WO and links to fresh QC, displaying remaining repair quantity and closure blocker.
- [x] Show same-WO repair relationship clearly in NCR detail. Keep supplier and closed-WO flows intact.
- [x] Run targeted component tests, lint, and build.

## Task 4: Complete proof and rollout

Files: dedicated backend tests, existing tagged browser fixture and Playwright spec, verification report.

- [x] Run production/quality/inventory/MRP regressions in isolation.
- [x] Apply additive migration after tests and restart queued workers with the changed code.
- [x] Run headless Playwright using real QC maker/checker, production, warehouse, and employee accounts for inline repair and automatic closure; preserve closed-WO repair proof.
- [x] Request focused independent review, address material findings, and report exact verification and limits.

## Verification result

- Production, Quality, MRP, and work-order notification suites: 567 tests and 2,039 assertions passed in the dedicated test database.
- Final inline repair, inventory GL/lot/reservation, closure/durable chain, and outbox checks: 66 tests and 237 assertions passed after immediate closure was added.
- Frontend: 28 focused tests passed, TypeScript and ESLint passed, production build passed, and the scoped design detector reported no findings.
- Real-role headless Chromium: all six scenarios passed, including same-WO repair, automatic closure, closed-WO fallback, PASS/FAIL requirements, permissions, and QC/operation gates.
- Both repaired 100-piece fixture lots conserved inventory at 200 total pieces. The original inline WO stayed at 100 produced and received one closure notification.
- Applied additive inline-repair migration and existing pending UOM snapshot migration. Restarted the queue worker; no live database refresh or truncate occurred.
- Independent review findings were resolved. No material findings remained in final read-only review.

Evidence: `docs/wo-qc-headless-e2e.md`. Final live fixture tag: `i100e`; original inline WO: `E2E-i100e-INLINE`.
