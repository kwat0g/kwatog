# Maintenance automation and recovery design

## Goal and operating context

Make Maintenance a traceable, role-based recovery process whose strongest thesis result is measured downtime, net spare-parts cost, and verified equipment recovery. Automation should remove repetitive selection and routing, while people retain control of physical work, stock movement, production release, and acceptance.

The demonstration target is 47.129.228.44. Treat its database as operational until confirmed otherwise. Never run DemoAccountSeeder there: ProductionAssertions rejects demo seeding outside local and testing. Manual verification uses no administrator account. Ewan's maintenance2@ogami.test account does not exist on that site. At least two real, active Maintenance Technician users with employee records, one Maintenance Supervisor, one Production Manager, and one Warehouse user must be available before the complete role-based demonstration. HR must give the technicians current shifts, working days, and attendance records. Provisioning is an operational prerequisite, not part of a demo-data seed.

## Existing behavior to preserve

- A machine breakdown already pauses production, opens downtime, creates a corrective maintenance order, and emits a breakdown notification. Production can explicitly release resources from a paused work order.
- Day, running-hour, and mold-shot schedules already generate preventive orders through the daily job. Duplicate generation is guarded.
- Maintenance start claims an unassigned open order for the technician or uses a supervisor assignment. Start refuses a machine still owned by production and refuses overlapping active maintenance. Issue and return record stock movement and net part cost; completion closes downtime and restores equipment.
- Production's worker assignment already evaluates HR shifts, working days, approved leave, punches, presence, and competing production work. Maintenance currently filters assignees only by active Maintenance Technician role and department.
- Work-order detail shows lifecycle, parts, logs, and summary values. Desktop offers no manual log action. In-progress downtime displays stored zero until closure. Cost is spare-parts cost, not full labor-inclusive maintenance cost.
- Current work order MWO-202610-0007 is in progress on the demonstration site. New gates must not strand it.

## Process and role boundaries

| Stage | System action | Responsible human | Server rule |
| --- | --- | --- | --- |
| Detect | Create one corrective order and link breakdown downtime; generate one order for a due preventive schedule | Production reports breakdown or supervisor maintains schedule | Idempotent trigger; no duplicate active order |
| Triage | Show production ownership, urgency, suggested technician, and blockers; notify supervisor | Maintenance Supervisor chooses technician and confirms timing | Recheck eligibility when saved |
| Handoff | Surface the linked paused production work order and its Release resources action | Production Manager explicitly releases machine and mold | Maintenance cannot start while current production ownership remains |
| Execute | Start actual downtime clock; show live elapsed time and suggested available stock | Assigned technician diagnoses, records work, confirms issue or return | Permission, ownership, equipment, stock, and idempotency guards remain authoritative |
| Submit | Require diagnosis, action taken, and functional test result | Assigned technician submits repair | No new parts or edits to submitted evidence until rejection |
| Verify | Notify verifier; show measured evidence | Production Manager accepts or rejects corrective machine recovery; Maintenance Supervisor verifies preventive machine or mold work | Technician cannot verify own work; rejection keeps equipment in Maintenance and downtime open |
| Close | Calculate actual downtime, net parts cost, response time, and responsible actors | Verifier accepts physical recovery | Acceptance atomically closes downtime, restores eligible equipment, and completes order; production resume remains a separate action |

For rollout, keep maintenance status In progress while a repair awaits verification. A separate submitted-at timestamp and latest submission fields represent that substage. Existing production and asset guards that check In progress therefore remain effective. Add a verification-required flag that defaults false for historical orders. Set it true for new orders only after deployment readiness checks and activation. Existing work, including MWO-202610-0007, stays on its current completion path. A technician cannot invoke the legacy completion endpoint for a verification-required order.

Record each submission and rejection in the existing maintenance log with actor, timestamp, and the evidence snapshot. Store the current diagnosis, repair action, test result, submitted-by/at, and final verified-by/at on the order. Rejection requires a reason, clears only the current pending marker, and preserves the log history. A repeat submission creates another immutable log entry. Part issue, return, manual log entries, and cancellation remain available during active execution; submitted work must first be rejected before any of those actions.

## Technician recommendation

An immediate-order suggestion uses the current time; a future-order suggestion uses a confirmed planned interval. Candidates must be active Maintenance employees with active Maintenance Technician accounts. For the proposed interval, require a current HR shift and working-day coverage, no approved leave or recorded absence, and no conflicting in-progress maintenance assignment. Immediate suggestions rank verified present workers first, then lower active workload, then employee number for stable ties. Unverified presence is shown separately and needs supervisor physical-presence attestation with a reason. Absent, clocked-out, off-shift, on-leave, inactive, and busy workers are shown with specific exclusion reasons.

Reuse the Production presence and HR shift rules, including night shifts and the difference between present, unverified, and blocked. Do not call the Production crew-suggestion endpoint as Maintenance: it filters Production workers and production schedules. Extract or call only genuinely shared presence calculations. The supervisor accepts a suggestion or chooses another eligible technician. For new verification-required orders, the assignment endpoint must recompute eligibility in a transaction and return a stale-suggestion error if attendance, leave, shift, or work ownership changed; Start rechecks current availability. Legacy orders retain the existing assignment/start contract and can display advisory suggestions. No candidate leaves a new order unassigned and visible in the supervisor queue. An existing self-claim path remains available for urgent repair and logs that the technician bypassed a supervisor assignment; new orders cannot bypass equipment, presence, or verification guards.

## Routing, reminders, and handover

On work-order creation, send one deduplicated in-app notice to active Maintenance Supervisors with a direct order link. On assignment, notify the selected technician. On repair submission, notify the verifier; on rejection, notify the assigned technician. Existing breakdown notification stays, but the supervisor should receive the order-specific notice. Use NotificationService's dedupe key and after-commit behavior. A critical order unassigned for 15 minutes produces one escalation to the supervisor and Production Manager; no repeat notification in the same unassigned episode. Assignment closes that episode. If no qualifying account exists, record an operational setup error rather than silently mark delivery successful.

For an in-progress order with an assigned technician's shift ending within 30 minutes, suggest a present replacement to the supervisor. Never automatically reassign an active repair. An in-progress reassignment requires a handover note; the log retains old and new assignee, actor, timestamp, and note. An approved overtime entry alone does not prove shift coverage.

## Production and maintenance readiness

Show machine status, current production work-order link, active production reservation, existing maintenance order, and required release action before Start. Do not filter busy machines out of order creation: a breakdown can legitimately create a request while production still owns the machine. The server retains its start guard. A planned preventive window must not overlap a confirmed production reservation or another confirmed maintenance window. Confirming a maintenance window must also participate in Production capacity checks so a subsequent production booking cannot silently consume that time.

Preventive schedules gain an estimated duration of 15–480 minutes, default 60 minutes for new schedules. Existing schedules remain valid with a migration default of 60 minutes. Suggest the earliest slot within the next seven days that avoids equipment reservations and is staffed by an eligible technician; the supervisor confirms or changes it. A confirmed window is a reservation, not an automatic machine stop. Recheck conflicts under the same equipment lock during confirmation and again at Start. Cancelling or completing releases the future reservation. Machine and mold windows follow the same target identity rules.

## Spare-parts and repeated-failure assistance

When a technician opens Add part, show frequently used parts for that target from completed maintenance history, ordered by recent frequency, plus usable quantity and an eligible location/lot. A suggestion is not a requirement or a prediction: if history is empty, show the ordinary searchable list. The technician confirms part, quantity, and physical issue. Existing stock and accounting checks run at issue, so stale availability never becomes a promised reservation. Show a shortage and the existing replenishment status where available. Do not auto-issue or create a second purchasing path.

Flag a machine after three distinct breakdown downtime incidents in a rolling 30-day window. Notify the Maintenance Supervisor once per threshold crossing and link its history and preventive schedule. The supervisor decides whether to change the schedule; the system must not infer a root cause from free-text descriptions or claim sensor-based predictive maintenance.

## Outcome and reporting

During repair, show live elapsed downtime from the linked open MachineDowntime start timestamp, labelled provisional. At verified close, use the ledger's closed duration. Response time is order creation to maintenance start; technician repair time is start to submission; total recovery time is breakdown downtime start to verified release where a breakdown exists. Net spare-parts cost equals issued cost minus returned cost. Label it Parts cost, never Total maintenance cost or savings. Show verifier, repair evidence, stock movements, machine status, and a link to Downtime analytics. Do not display an invented before/after improvement or ROI.

The supervisor queue should group Unassigned, Blocked by production, In progress, Awaiting verification, and Overdue preventive work. Desktop keeps the table and system layout; phones use cards on the same route. Server-provided available actions, not client-only checks, determine controls.

## Delivery sequence and proof

1. Staffing and routing: suggestion, eligibility recheck, notices, critical escalation, and shift handover. This works with the current lifecycle.
2. Readiness and verified recovery: production handoff visibility, mandatory repair evidence, independent verification, live/actual metrics, and compatibility for old orders.
3. Preventive window planning: estimated duration, shared capacity reservation, shift-aware slot suggestion, and conflict-safe confirmation.
4. Spare and reliability assistance: history/stock suggestions, shortage status, and repeat-breakdown flag.
5. Role-based browser and API proof, then a controlled deployment. No live database write, migration, seed, or deployment is implied by this design.

Each release needs focused API tests for role denial, time-zone/night-shift/leave/attendance boundaries, stale suggestions, concurrent assignments, production ownership, overlapping reservations, stock idempotency, rejection and resubmission, actual cost and downtime, and legacy work orders. Browser tests cover desktop and phone layouts and non-admin role handoffs. The final thesis walkthrough follows one corrective machine breakdown from production release to verified recovery, then shows one auto-generated preventive order and a due-window proposal. The demo uses only provisioned non-admin accounts and safe target/stock records.

## Deliberate exclusions

No automatic physical work, production release, stock issue, approval, machine restart, employee skill ranking without qualification data, fabricated labor cost, invented savings, or IoT/predictive claim without a real sensor feed.
