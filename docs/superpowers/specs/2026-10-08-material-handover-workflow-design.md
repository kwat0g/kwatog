# Material handover workflow design

Status: approved and implemented. Verification: `docs/testing/material-handover-workflow-20261008.md`. No live business handover records were fabricated. Additive exception-evidence columns were migrated.

## Outcome

Give Warehouse one obvious place to confirm materials delivered to Production, grouped by work order, and give Production clear readiness information. Preserve automatic stock issuance on WO Start. Require recorded handover for normal output recording, with a narrow, audited exception for material already physically received when Warehouse has not yet saved confirmation.

## Current behavior and evidence

- `WorkOrderService::start()` changes the WO to In Progress, records actual start, issues the remaining reserved materials, and creates the in-process QC draft within the existing transaction. It does not require a material handover.
- `WorkOrderOutputService::record()` and `WoOperationService::recordOutput()` check production status, equipment, crew, QC, and material coverage. Neither currently requires physical handover evidence.
- `WorkOrderResource` counts issued movements without handover, excluding fully returned quantities. The WO page displays this as a warning linking to Stock Movements.
- `MaterialHandoverController` already supports individual and grouped handovers. It rechecks the current assigned recipient, source quantity, returns, and WO/slip status, and records receipt without another stock movement. Group writes are atomic and exact retries return existing records.
- `MaterialHandoverButton` already defaults the eligible output recorder and calls the grouped endpoint when sources are supplied.
- `/inventory/picking` currently loads issued manual Material Issue Slips only. Automatic issues created directly by WO Start have no slip and therefore do not appear in that list. Their receipt action is currently buried in expanded Stock Movement rows.
- The existing receipt is a Warehouse attestation naming the recipient. It is not an independent employee signature or proof collected from another employee login.

## Selected placement

Use the existing `/inventory/picking` route and replace the navigation label **Order Picking** with **Material Handover**. Extend this surface to include both automatic WO issues and manual slips; do not create another overlapping warehouse page.

The work-order Materials panel provides a compact status and a link to that WO's handover task. Production users without Warehouse permissions see the status and instructions, not a confirmation action. Stock Movements retains the receipt history and a contextual link to the new primary workflow. Material Issues remains the place to create or inspect manual issue slips.

### Warehouse page

- Default filter: **Pending handover**. Other filters: **Handed over**, **Needs review**, and **All**.
- One row per WO. Unlinked manual slips appear as separate slip rows. Never group unrelated slips or work orders together.
- Compact columns: WO/slip, material line count, handover status, latest issue time, and **Review handover**.
- The whole row opens its material details, including by keyboard. Buttons and links do not accidentally toggle the row.
- Page and page-size controls count complete WO/slip groups. Expanding a group shows all its material sources, even when a search matched only one material. Label the matching material rather than silently omitting the other sources.
- Search WO number, slip number, material code, or material name. A bounded scrolling detail area keeps large material lists from making the entire page excessively long.
- Each material source shows code/name, original location, original lot, issued quantity, returned quantity, remaining quantity, unit, and receipt status. Do not recompute a suggested source after stock has already been issued. Never sum quantities across different units.
- One **Confirm handover** action selects all pending, eligible source lines by default. Warehouse can deselect lines that have not physically arrived. Each selected source is confirmed for its complete remaining quantity; quantities are read-only.
- Prefill **Received by** with the current output recorder only when that employee is still an eligible assigned Production worker. Warehouse can choose another eligible assigned worker. The action never submits merely because a receiver was prefilled.
- Confirmation text: **Confirm the selected materials were received by this worker.** Button: **Confirm handover**. One click records the receipt; no extra confirmation dialog is needed.
- After success, refresh the task, pending count, WO status display, and relevant material views. Preserve expanded details and filters. A later supplemental issue makes the same WO task pending again without altering previous receipts.
- Use the existing shared table, modal, searchable selection, chips, loading indicators, spacing, and design tokens. Show Asia/Manila dates with a 12-hour time format.

### WO record and output form

- Materials panel status: **Material handover pending: 3 lines.** After completion: **Materials handed over.** A mixed receipt state says **2 of 3 lines handed over**.
- Authorized Warehouse users get **Review handover** linked to `/inventory/picking?work_order_id=<HashID>`. Production users get **Ask Warehouse to confirm material handover.** Do not require Warehouse access to the full production record.
- Show recorded receiver, Warehouse recorder, and time in the expanded material history. A change of crew does not rewrite an earlier recipient.
- Pending handover warning in the output form: **Warehouse handover is pending. Ask Warehouse to confirm before recording output.** Keep entered counts and defects if this state changes during submission.
- Stock Movement history uses **Pending handover**, **Handed over**, or **Fully returned**, with a link to review the associated task. Fully returned sources must not display an actionable handover warning.

## Process rules

| Action | Proposed material handover rule |
| --- | --- |
| Confirm WO and reserve materials | Allowed; reservation is allocation, not physical receipt. |
| Start WO | Allowed under existing schedule, equipment, attendance, specification, and material checks. Start posts automatic issues and creates the handover task. |
| Setup and existing operation lifecycle | Keep existing checks. This change does not make setup possible when QC or another current rule blocks it. |
| Record normal WO output | Require all positive, unreturned issued source quantities for that WO to have handover evidence. |
| Record normal operation output | Apply the same shared handover check; do not leave an alternate bypass. |
| Record output with the controlled exception | Allow only the authorized manager path below; keep the pending Warehouse task. |
| Complete WO | Require pending handovers to be resolved. An output exception does not complete the material receipt workflow. |
| QC inspection or approval | Preserve existing rules. Handover does not approve QC; QC does not confirm material delivery. |
| Supplemental material issue | Add a pending source under the same WO; require its handover before the next normal output write. |
| Unused material return | Preserve actual Warehouse return confirmation and the original receipt history. |

Starting a WO is a recorded execution transition, not proof that materials were physically delivered. Staff must physically have the required material before running production. The proposed software gate is at output recording and WO completion; this scope does not introduce a new gate on every operation start or resume.

Do not block Start on a receipt record that can only be created by Start itself. Moving all material issuance before Start would be a separate inventory and production lifecycle change and is not required for this design.

### Controlled output exception

Show this option only to an active user who already has both `production.wo.record` and `production.work_orders.lifecycle`, and who passes the existing output/crew authorization checks. The checkbox defaults to unchecked:

**Materials were received; Warehouse confirmation is pending.**

Require a specific reason of at most 500 characters. Example: **Warehouse delivered the resin and bags to Manuel; confirmation is waiting for Carlos.** This is acknowledgement of the missing record, not permission to claim output made without material.

- Request fields: `material_handover_acknowledged` (boolean) and `material_handover_reason` (string).
- Persist a structured `material_handover_exception` snapshot on the saved WO output or operation output log: manager ID, server timestamp, reason, and pending source IDs/remaining quantities observed under the WO lock.
- Use additive nullable JSON fields on `work_order_outputs` and `production_logs` for that snapshot. Do not create a separate approval subsystem or change stock quantities. The existing audit trail must also identify the actual manager, including operation output where `operator_id` alone does not identify the approver.
- Never derive acknowledgement from a generic `recording_reason`. These reasons serve different purposes.
- The exception applies only to the specific output submission. It does not approve future issues, become a permanent WO waiver, clear pending receipts, or bypass attendance, QC, stock coverage, equipment ownership, quantity limits, or defect validation.
- Employee/self-service, Warehouse-only, HR, and Sales accounts cannot obtain this exception by crafting an API request.
- Exact output retries keep their existing idempotency behavior. Include the new explicit acknowledgement and reason in the fingerprint when used, without invalidating legacy exact retries. A changed payload under the same key remains a conflict.

## Grouping, authority, and concurrency

Add a narrow read interface under the existing material handover controller:

- `GET /inventory/material-handovers`: grouped, paginated task summaries; accepts `status`, `search`, `work_order_id`, `page`, and `per_page`.
- `GET /inventory/material-handovers/sources`: authoritative full group detail selected by exactly one of `work_order_id` or unlinked `material_issue_id`. Reject both, neither, malformed IDs, and unknown IDs. Return the material sources and eligible receivers.
- Reuse `POST /inventory/material-handovers/group` for confirmation. Preserve existing individual endpoints for compatibility.

Read routes require `inventory.picking.view`; confirmation retains `inventory.issue.create`. Production receives the minimal receipt summary through its existing authorized WO resource rather than accessing unrestricted Warehouse details. Keep route ordering unambiguous and never expose raw database IDs.

Reuse `StockMovement::forWorkOrder()` for automatic/manual source membership. Add one shared pending-handover query that excludes non-issues, existing receipts, invalid source context, and fully returned quantities. The grouped queue, WO summary, output guard, and completion guard must use the same calculation.

The group detail comes from all database sources for that WO/slip, not the current page of Stock Movements. Detail exposes the exact pending source IDs and decimal quantities that the confirmation submits. Server-side group confirmation retains its current maximum of 100 selected source lines; larger groups use explicitly selected batches rather than silently truncating.

Maintain the existing lock order: WO, manual slip where applicable, source movement. Output and receipt writes serialize on the same WO lock. A stale return balance, changed recipient, cancelled slip, terminal WO, or differently recorded receipt aborts the selected group transaction. Show **Materials or recipient changed. Reload handover details.** without losing context. No partial group commit on error; intentional subset selection is separate from a failed transaction.

## Historical records and exceptions

- First-time receipt confirmation remains limited to Confirmed, In Progress, or Paused WOs and issued manual slips. Never retrospectively fabricate a physical handover timestamp on Completed, Closed, or Cancelled records.
- Terminal records with missing evidence appear under **Needs review**, with **Historical handover missing** and read-only material detail. No current confirmation button or output gate is applied to an already completed record.
- Existing active WOs are not retroactively reset, paused, or rescheduled. Their next normal output write follows the new rule; the narrow manager exception handles physically received materials still awaiting Warehouse confirmation.
- Exact replay of an already saved output or handover must still succeed after a WO becomes terminal, without creating another write.
- Missing receipt evidence never means a material return physically occurred. This change must not automatically restock materials or rewrite cancellation/return history.
- One receipt per issue source remains the supported model. Selecting separate source lines is supported; partial quantity handover of one source, independent recipient signatures, and backdated receipt correction are separate features.

## Visibility and notifications

The queue's default Pending filter and the WO warning provide the initial actionable notice. The page shows a pending group count and latest issue time, allowing Warehouse to identify work awaiting confirmation. Update existing links to lead to this queue.

Do not add recurring emails, popup loops, timers that reset an open form, or a new alert scheduler in this implementation. A dedicated in-app reminder can be added later if Warehouse still misses tasks after the placement is improved. Existing QC and production notifications are unaffected.

## Implementation sequence and file ownership

1. **Shared source calculation and complete queue.** Extend `StockMovement.php` with the shared pending query; extend `MaterialHandoverController.php` and inventory routes for grouped summaries/detail. Reuse current grouped receipt writes. Update `stock.ts` and `types/inventory.ts` with the read contracts. Prove whole-group pagination, automatic/manual grouping, returns, and permissions before UI work.
2. **Warehouse surface and connected links.** Extend `warehouse/picking.tsx` to show WO/slip tasks and all source detail. Enhance `MaterialHandoverButton.tsx` to review selected material lines and an eligible prefilled recipient. Update `Sidebar.tsx`, its permission tests, route labels, and existing material-issue links. Preserve `/inventory/picking` and legacy `material_issue_id` links. Update `inventory/movements/index.tsx` to show truthful history and task navigation. Update `production/work-orders/detail.tsx` to link its Materials status to the task.
3. **Normal output and completion checks.** Reuse the shared source calculation in `WorkOrderOutputService::record()`, `WoOperationService::recordOutput()`, and `WorkOrderService::complete()`, under their existing transaction locks and after exact replay resolution. Expose the minimal status through `WorkOrderResource.php`. Do not repurpose the unrelated child-WO `production_readiness` contract or the QC blocker field.
4. **Per-output exception and form behavior.** Add the two request fields in `RecordOutputRequest.php` and operation-output validation. Add nullable exception snapshots with one reversible migration; expose them in existing output/log resources. Update `RecordOutputData`, `workOrdersApi`, `woOperationsApi`, the manager output form, operation output dialog, and self-service output form/status. Managers can explicitly acknowledge pending receipt; ordinary employees see a blocking notice. No automatic acknowledgement or receipt write.
5. **Compatibility and proof.** Run focused backend/frontend checks, then isolated real-role Playwright scenarios. Update the testing handoff with behavior, evidence, and manual steps. Do not execute the plan, migrate the live database, confirm live handovers, or commit unrelated shared-workspace changes until the design is approved.

The placement and queue can be deployed first. Enable the output/completion rule only after the queue is accessible and managers have the controlled exception, so there is no rollout window with an unexplained blocker.

## Acceptance and test matrix

| Scenario | Required result |
| --- | --- |
| Start a reserved WO as Production manager | Start succeeds under existing checks; stock deducted exactly once; automatic issues appear as one pending task; QC draft behavior unchanged. |
| Manual slip and automatic issue belong to the same WO | One WO task includes both sources. |
| Two WOs and an unlinked slip | Separate groups; confirmation cannot mix contexts. |
| Material search or `per_page=1` | Complete matching groups; source detail never omits other materials. |
| Open confirmation | All eligible pending lines visible, receiver prefilled when eligible, no write until explicit click. |
| Confirm selected lines as Warehouse | Atomic receipt writes; exact quantities/receiver/time/recorder; no stock, cost, reservation, or output change. Unselected lines remain pending. |
| Retry successful confirmation | Existing receipts returned; no duplicates or second deduction. |
| Return or crew change while confirmation is open | Submission rejected or revalidated safely; group rollback; readable reload action; entered selection preserved for review. |
| Fully returned issue | No pending count or normal receipt action; historical source visible. |
| Ordinary output while handover pending | WO and operation endpoints reject; no totals, finished-goods receipt, mold counts, or logs committed. UI retains counts and defects. |
| Confirm all relevant sources, then record output | Existing QC and other guards still apply; otherwise normal output succeeds. |
| Manager submits unchecked acknowledgement or blank reason | No exception; no output saved. |
| Manager explicitly acknowledges with reason | Output succeeds only if all other checks pass; audit snapshot saved; Warehouse pending task remains. |
| Employee, Warehouse-only, HR, Sales, or inactive user crafts exception fields | Cannot bypass authorization or the handover guard. |
| Extra materials issued after an earlier handover | Same WO task becomes pending; next normal output blocked until resolved. |
| Complete WO with pending handover | Blocked with one short message; earlier output exception does not erase missing receipts. |
| Completed/Closed/Cancelled historical WO | Read-only Needs review entry; no fabricated current receipt; saved exact retries still resolve. |
| Output and handover submitted concurrently | Same WO lock gives a consistent result; neither path creates duplicate stock movements. |
| Desktop and 390px mobile | Keyboard access, readable source quantities, bounded page length, clear loading/error states, and no page overflow. |

Extend `WarehouseReservationHandoverTest.php` and `WarehouseMovementReferenceTest.php`. Add focused production handover-gate tests and extend existing output idempotency, operation-output, and completion tests. Update `MaterialHandoverButton.test.tsx`, `picking.test.tsx`, `Sidebar.permissions.test.tsx`, `record-output.test.tsx`, and related API/form tests.

Use the existing isolated real-API/SPA Playwright approach from `docs/testing/attendance-role-playwright-20261008.md`, with Warehouse, Production manager, assigned employee, and unauthorized roles. Disposable fixtures must contain real issue sources and current crew. Assertions compare stock movement counts and quantities before/after handover and output rejection. Do not fabricate receipt or QC evidence in the live company database.

## Explicit boundaries and remaining risks

- No changes to scheduling, the two-hour WO slot, attendance policy, stock issuance timing, outgoing QC, or quantity/defect validation.
- The known trial-piece/QC issue is separate: current process QC can block setup/output before any trial pieces exist. This plan does not claim to solve that or make the full WO production flow usable by itself. That needs its own approved trial-run design.
- Recorded handover remains Warehouse attestation. A manager exception is also an attestation and cannot independently prove physical material receipt. Both must be visible as different evidence.
- Reversing the UI and guards preserves receipt/output history. If the nullable snapshot migration is rolled back, its exception evidence would be lost; retain the additive columns during an application rollback once records exist.
