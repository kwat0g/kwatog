# Work-order crew assignment implementation

Approved staffing: minimum 1 by default, optional per-WO maximum, multiple workers, one recorder. HR supplies shifts; no separate access windows. Implementation completed in the shared workspace while preserving earlier unrelated changes.

1. Add crew and member tables, staffing constraints, immutable output snapshots, and assignment permission for PPC and production management.
2. Centralize assignment, eligibility, scoped reads, staffing, and recorder rules in `WorkOrderAssignmentService`.
3. Integrate shared rules with WO start/resume, operation start/resume/output, ordinary output, and approved NCR repair.
4. Add narrow employee APIs and own-receipt lookup. Preserve material, QC, stock receipt, target, repair, and closure invariants.
5. Add the WO crew panel, employee My work orders, HR shift display, manager attribution, and safe identical retries across employee, office, and repair forms.
6. Reuse existing notification channels for assignment and handover notices.
7. Verify role boundaries, multi-worker output, staffing bounds, inactive workers, handovers, replay, repair, materials, and closure with backend regressions and real headless Playwright accounts.

Actual frontend paths: `spa/src/pages/production/work-orders/CrewPanel.tsx` and `spa/src/pages/self-service/work-orders.tsx`. Existing production API client is reused. Actual browser test: `spa/e2e-real/wo-worker-assignment.spec.ts`.

Evidence: [verification report](../../testing/wo-worker-assignment-20261005.md).
