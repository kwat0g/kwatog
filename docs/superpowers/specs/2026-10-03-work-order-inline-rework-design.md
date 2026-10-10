# Work order rework and automatic quality closure

The user approved keeping repairs inside an open work order, notifying production, reinspecting repaired pieces, and closing automatically once production and quality are resolved. Closed orders retain the existing separate repair-order path.

Production completion releases equipment and leaves the work order completed, displayed as Awaiting QC. Required quality evidence, inventory receipts, and active NCRs block closure. A failed result holds its batch and creates an NCR. Independent full sorting releases good pieces and confirms the repair quantity.

For an open source WO, the NCR links rework to that same order. A dedicated rework section provides explicit start, record output, and finish actions. Manual repair needs no equipment; machine repair requires an available compatible machine and mold. Production receives actionable notifications. Starting repair does not repeat the original BOM issue or reopen routing operations.

Repair outputs identify their source output and NCR. They consume original held stock and receive repaired stock atomically, preserving physical inventory and original production totals. Fresh independently checked QC covers each positive repaired output. Repeated repair records cannot exceed the confirmed quantity. NCR closure waits for finished repair and released repaired output. Rejected repair pieces are recorded as stock loss and feed existing replacement planning.

Automatic closure uses the same locked gate as manual closure, triggered by production completion, final QC approval, NCR resolution, and inventory receipt recovery. Pending or failed unresolved evidence never closes a WO. All positive outputs require checked release evidence and a valid stock handoff. Scrap must be physically reconciled and replacement demand handed to the existing replacement/MRP owner. The last completing action triggers closure; duplicate events are harmless. Automatic closure is audited and notifies production.

No historical records are rewritten, no existing separate repair orders are folded back, and no new workflow engine or dependencies are introduced. Incoming supplier QC behavior remains unchanged. Backend proof and real-role headless Playwright cover normal closure, 99 good/1 repair, queue retries, permissions, failed gates, and unchanged closed-order recovery.
