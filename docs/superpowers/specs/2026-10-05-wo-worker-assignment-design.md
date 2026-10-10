# Work-order crew assignment and employee output

Approved and implemented on 2026-10-05. This document replaces the earlier proposal for manual shift selection and explicit access windows.

## Staffing and ownership

Each WO has a minimum worker count, default 1, and an optional maximum. PPC selects multiple active employees, exactly one recorder with an active internal account, and an assignment reason before production starts. Production management handles live handovers. Assignment, start, resume, and new output enforce the same staffing bounds and employee eligibility.

Workers retain their existing roles. Current crew membership grants access to that WO's employee workspace. Support workers can view; only the designated recorder can submit. The manager can record on behalf with a reason. Company-wide production, inventory, QC, employee records, lifecycle controls, and financial details remain permission-controlled.

## HR shifts and access

Reuse each employee's date-effective HR shift, including the previous date's crossing-midnight shift where applicable. No shift selector, clock-based permission window, or administrator permission cleanup is required. Active employment, account, crew membership, and WO or repair state determine access. Overtime is not blocked by a shift end time.

Completed ordinary production, closed or cancelled WOs, finished repairs, removed crew members, and inactive workers cannot submit new output. Approved NCR repair has its own crew and recorder on the same Completed WO. A replacement WO needs its own crew.

## Attribution and safe recording

Persist crew revisions and employee snapshots. Output records retain crew identity and HR shifts, designated recorder, actual recorded-by user, and the manager's reason. A handover creates a new revision and cannot rewrite old evidence.

Shared output guards preserve whole counts, defect breakdown, current crew revision, material coverage, QC release, production targets, repair quantities, inventory receipts, and existing closure rules. Operations require a current crew operator; paused operations can resume with a replacement member after handover.

Employee, office, and repair forms freeze the exact submission and idempotency key after an uncertain save. Receipt lookup or identical retry resolves it. The original actor can resolve their own saved receipt after a handover or completion. A missing receipt is not proof that a delayed request did not commit.

## UI and notices

The WO detail shows a Production crew panel with staffing bounds, combined debounced worker selection, recorder selection, and assignment history. Employee Self-service provides My work orders and a compact output form. Repair displays its actual equipment, rather than the original production equipment. Existing private-user notifications announce assignments and handovers without broad production websocket access.

## Compatibility and verification

Additive PostgreSQL migrations preserve historical output and expand output shift names to HR's 50-character limit. Existing WOs require crew assignment before start, resume, or new output. Historical exact output replay remains supported. No scheduling optimizer, inferred quantity-to-headcount formula, or global employee role changes were added.

Evidence: [verification report](../../testing/wo-worker-assignment-20261005.md).
