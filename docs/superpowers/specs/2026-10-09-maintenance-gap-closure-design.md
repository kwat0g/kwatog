# Maintenance Gap Closure Design

**Date:** 2026-10-09  
**Source of truth:** Current Maintenance API, SPA, tests, and the live work-order walkthrough. Recheck live data before deployment.  
**Goal:** Make the existing role-based maintenance flow defensible in a thesis demonstration: an available technician works on released equipment, records findings and parts, proves recovery, and produces trustworthy downtime and net parts-cost measures.

## Current flow to preserve

Maintenance work orders already support machine and mold target filtering, production ownership and schedule checks, attendance-aware assignee suggestions, multiple structured work records, lot-backed part issues and returns, downtime ledgers, and role-specific repair verification. Existing orders retain their `verification_required` value; the `maintenance.closed_loop.enabled` setting affects only newly created orders. Historical completed and cancelled records remain unchanged.

## Decisions

1. **Use the last structured work result as the closure signal.** A record has `finding`, `action_taken`, and `result`. The latest such record, ordered by `id`, must have `result = passed` before a legacy order can Complete or a verified order can Submit Repair. A later `needs_follow_up` or `in_progress` revokes readiness. The service checks this inside its locked transaction. The detail API supplies a closure blocker to desktop and phone pages so both show the same reason. Lifecycle notes and old free-text logs never count as a passed work record.
2. **Keep independent verification on verified orders.** The assigned technician supplies diagnosis, repair action, a functional-test narrative naming the check, expected behavior, and observed behavior, plus a latest `passed` work result. A different authorized verifier accepts or rejects. Rejection clears submission, permits new work records and part corrections, and requires a new pass before resubmission. This uses the existing structured result; no second pass/fail field or invented machine test is needed. Legacy orders continue their direct-Complete path with the stronger work-result guard.
3. **Link issued parts to the work being performed.** Add nullable `work_log_id` to `spare_part_usage` for historical compatibility. New issues require the hash ID of a structured work record belonging to the same order. Every lot row in one issue group stores that ID. Display the linked finding/action, issuer, lot, gross quantity, returned quantity, net consumed quantity, and net cost. Old issues show “Work record not captured.”
4. **Reconcile parts before a terminal handoff.** Add nullable `parts_reconciled_at` and `parts_reconciled_by` to work orders. The assigned technician reviews all unreturned part quantities and attests that they were consumed or installed; unused quantities must first be returned to their source lots. If the assigned technician is unavailable during cancellation, a maintenance supervisor may perform a physical inspection, explain the reconciliation, and cancel with a reason. No parts issued, or all parts returned, is already reconciled. A new issue or return clears the attestation. Complete, Submit Repair, and Cancel require current reconciliation when net issued quantity is positive. Verification acceptance rechecks it. Stock moves only on issue and return; attestation does not alter inventory or cost. Cancelled work may retain consumed-part cost, with the attestation and reason in its audit trail.
5. **Enforce availability for every new assignment and Start.** Current legacy-order advisory handling is inconsistent with new verified orders. Apply the existing suggestion service to new assignments and Start for all active orders. Grandfather the current assignee on an old order until reassignment or Start; never rewrite historical assignments. Future attendance remains unknown until Start. A supervisor may attest only the existing `unverified` presence case with a reason; known absence, missing shift coverage, inactive employee, wrong role, and concurrent maintenance remain blocked. Production must explicitly release equipment before Start. No synthetic attendance or shifts are inserted to make a candidate appear available.
6. **Use honest metrics.** Show `response_minutes` as “Time to start,” because it is creation-to-start time. Show the existing `technician_repair_minutes` as “Start to repair submission,” not hands-on labor. Add an additive `maintenance_elapsed_minutes` for start-to-completion, submission, or current server time, marked provisional while active; preserve the old API field. Net `cost` remains **parts cost only**. MTTR counts only breakdowns resolved in the selected date range and uses each resolved event’s full duration; open faults get a separate count. Availability is labeled **calendar availability** because the denominator is wall-clock time, not scheduled production. Existing MTBF is labeled an estimate of calendar uptime per breakdown until machine running-hours data can support true MTBF.
7. **Make list figures match their scope.** List stat cards currently count only the paginated page. Their labels and help text must say “on this page.” Use a “Critical priority” card filtered to critical orders, matching the existing critical queue. Never present paginated parts cost as a fleet total.
8. **Remove redundant target-search delay.** The shared `SearchableSelect` already debounces search for 300 ms. Remove the second page-level 300 ms debounce from work-order and maintenance-schedule creation. Preserve server-side target eligibility checks; stale selected options cannot bypass them.

## Role and state contract

| Actor | Allowed contribution | Must be rejected |
| --- | --- | --- |
| Maintenance supervisor | Create, schedule, assign, independently verify preventive or mold repair, and cancel with inspection and reason when the assigned technician is unavailable | Verify own submitted repair; override known absence or production ownership |
| Maintenance technician | Start assigned order, record multiple work results, issue and return parts, reconcile parts, submit repair | Work on another technician’s order; Complete after latest non-passed result |
| Production manager | Release production resources and verify corrective machine recovery | Verify own repair; bypass part or work-result guard |
| Inventory role | Prepare real spare-part item, lot, location, and stock through existing inventory process | Issue maintenance parts as another actor without maintenance permission |

No administrator account is used in the role-based demonstration. A non-maintenance account must receive authorization errors for restricted operations.

## Data and rollout

Both new columns are additive and nullable; historical records remain readable and retain their original financial and verification data. New guards apply to future transitions on active legacy orders. Before enabling verified flow for new orders, audit current live users, their real linked employee records, roles, shifts, attendance, equipment ownership, and part stock. Work in an isolated runtime and test database because the current SPA source can be mounted into the live container. Deploy compatible API and migration before SPA; then verify with role accounts and a fresh test order. Turn on `maintenance.closed_loop.enabled` for future orders only after the role flow passes. Preserve a database backup and rollback path; never backfill fictitious attendance, cost, work records, or test results.

## Acceptance

- A latest `needs_follow_up` or `in_progress` blocks both legacy Complete and verified Submit Repair, even after an earlier pass; a later pass permits the transition.
- A new part issue identifies its actual work record and issuer; multi-lot issue and partial return preserve exact stock and net cost.
- An order with unreturned parts cannot Complete, Submit Repair, or Cancel until an authorized actor reconciles them; any later issue or return invalidates reconciliation.
- A known absent, off-shift, busy, or unqualified employee cannot be newly assigned or Start an order. Production ownership still blocks Start until Release resources.
- Role-specific verification rejects self-approval and preserves rejection/rework history. Completion restores equipment and closes only the appropriate downtime ledger.
- MWO-202610-0008 remains a legacy order; no historical status, cost, assignee, or verification mode is silently changed.
- Dashboard and detail labels state exactly which interval, cost, cohort, and page they measure. Desktop and phone expose equivalent completion blockers.
