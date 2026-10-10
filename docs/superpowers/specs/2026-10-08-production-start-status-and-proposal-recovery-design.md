# Production start status and proposal recovery design

Status: implemented October 8, 2026. See [verification evidence](../../testing/production-start-status-20261008.md).

## Problem and evidence

Earliest scheduling correctly proposes an available slot near the current time. `WorkOrderResource::scheduleWarning()` nevertheless calls Planned and Confirmed orders overdue immediately after the proposed start. Execution already permits a 15-minute late-start grace, so the list and record can alarm users during an allowed start window. `NotifyUpcomingWorkOrders` currently labels starts missed after 5 minutes, creating a second inconsistent threshold.

A selected October 27 production day cannot fit before an October 16, 12:00 AM production deadline. This is a date constraint, not evidence of an employee shortage. The shared planner now returns the date-specific reason before equipment selection. The confirmation modal shows that reason once and suppresses cached equipment, quality and crew messages while the date is invalid. It preserves prior reservations and user choices. This correction is complete independently of the proposed work below.

## Intended behavior

| Work order and current reservation | Status | User action |
| --- | --- | --- |
| Planned, proposed slot still usable | Awaiting confirmation | Open Confirm to review equipment, date and crew. No start-overdue warning. |
| Planned, proposed slot has ended | Proposal passed | Open Confirm to request a fresh proposal. No Start instruction for Planned orders. |
| Confirmed, start is in the future | Existing future schedule display | Keep the approved booking. Authorized early Start still checks actual presence and resources. |
| Confirmed, current time is from scheduled start through start plus 15 minutes, inclusive, and slot has not ended | Start available | Click Start to check readiness. This label describes timing only. |
| Confirmed, more than 15 minutes after start and slot has not ended | Start overdue | Click Start to check the authorized late-start path or Reschedule. |
| Confirmed, slot has ended | Schedule passed | Click Start to check late execution or Reschedule. |
| Paused or In Progress, slot has ended | Existing schedule-passed warning | Resume or review remaining production time. |
| Completed, Closed, Cancelled, or no current reservation | No schedule status | Do not manufacture a timing warning. Existing production-deadline warnings remain separate. |

Expiry takes precedence over the 15-minute grace. At exactly 15 minutes a still-active Confirmed slot remains Start available; one second later it is overdue. Readiness gates may still reject a short slot, absent crew, blocked materials or missing quality setup at either point.

## Interface and display

Add optional `schedule_status` with the existing kind/message/scheduled_start/scheduled_end structure. Kinds are `awaiting_confirmation`, `proposal_expired`, `start_available`, `start_overdue`, and `schedule_passed`. Keep `schedule_warning` for warning kinds only: `proposal_expired`, `start_overdue`, `schedule_passed`. Classify once in the API resource and derive both fields from that result. Old clients retain the warning envelope; current clients show one status from `schedule_status ?? schedule_warning`.

Use neutral or informational chips for Awaiting confirmation and Start available. Only warning kinds feed Needs attention cards. Preserve the independent production-deadline and paused warnings when a neutral status is present. The record displays one status notice, using scheduled start for start statuses and scheduled finish for expired statuses. Keep Asia/Manila and 12-hour formatting.

Reuse `ProductionResourceService` for a shared `START_GRACE_MINUTES = 15` constant. The execution comparison, resource classification, and reminder threshold consume that constant. No new administrator setting is needed.

## Proposal recovery

Do not regenerate a proposal while a user reads, on window focus, reconnect, or because a clock advances. A timer may update a local expiry notice without sending a request. Date and worker-count edits retain the existing 300 ms refresh. A genuine expired proposal shows `Proposal passed. Find a new slot.` with one explicit `Find next slot` action that calls the existing POST generation endpoint with the reviewed date and worker count.

Confirmation already checks schedule/crew versions, full remaining production duration, worker eligibility, dependencies, equipment, and material reservation inside the transaction. Keep those guards. If time has run out while the modal is open, reject confirmation with a clear recovery message and preserve WO, crew, reservations, approved bookings, and preference. Never automatically apply a fresh slot after a failed confirmation. A newer proposal must be visible and reviewed first.

Retain manual worker and recorder selections across a successful refresh. Require replacement when the new interval makes a selected worker unavailable. Do not silently replace manual workers. Clear an obsolete submit error only when requesting a new reviewed proposal, not merely when time passes.

## Reminders

Keep the existing upcoming notification within 30 minutes and due notification at scheduled start. A missed-start reminder begins only after the same 15-minute grace, while the current approved slot remains active. Preserve deduplication, current crew and owner recipients, employee versus management links, inactive-account exclusions, and no automatic Start. Missing attendance is not recorded absence.

## Constraints and exclusions

- Keep existing roles and row scope; no new confirmation path in Production Schedule.
- Keep early Start with verified physical presence and existing resource checks.
- Keep authorized very-late acknowledgement and production-deadline safeguards; a status chip never grants permission.
- Keep the minimum two-hour reservation or longer production estimate plus buffer. Do not add an artificial waiting period just to hide warnings.
- Preserve exact production deadline semantics, including midnight deadlines. Do not change customer delivery promises.
- No live WO status, crew, attendance, material, quality specification, or reservation changes during implementation verification.
- No new dependencies, global scheduling optimizer, automatic overtime, silent specification repair, or automatic reassignment.

## Acceptance

A freshly generated Planned WO says Awaiting confirmation rather than Start overdue. A freshly confirmed active slot offers Start available through exactly 15 minutes. Table, record and reminders agree after that boundary. Real expiry and overdue production deadlines remain visible. Invalid preferred dates produce one short date warning; valid dates restore the existing review choices. Expired or stale confirmation never silently changes a booking or starts production.
