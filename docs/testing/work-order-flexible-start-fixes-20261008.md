# Work-order flexible-start fixes

Date: 2026-10-08 UTC. Scope: the approved follow-up to the flexible-start audit, covering attendance, timing, run extension, recording access, schedule consistency, and production interface recovery.

## Result

- Planning retains a minimum two-hour reservation. The estimated production duration still comes from remaining quantity, mold output rate, and setup time; it is not an arbitrary two-hour completion requirement.
- Starts within the existing 15-minute tolerance use the original reservation when remaining work fits. Later starts require the delay acknowledgement. They retain the approved end when work fits, avoiding unnecessary capacity and shift extensions.
- Early execution requires verified attendance and available equipment and crew. Late acknowledgement does not override absence, shifts, conflicting resources, QC, material coverage, or production deadlines.
- Attendance failures now identify the worker/HR recovery path instead of automatically classifying the problem as a scheduling failure. Operation setup, start, and resume also check current operator attendance. Management reporting of earlier operation output after an attendance change requires a reason recorded in the production log.
- Running WOs expose **Extend run** and an expired-reservation warning. Management reviews a fixed proposed finish; submission checks the current crew and reservation versions, machine and mold ownership, resource overlaps, rate-based duration, daily reservation capacity, shifts, attendance, material coverage, dependencies, and process QC. Failed checks roll back the entire extension.
- An extension preserves the original reservation's timing and creates a new active reservation. It does not restart production or silently extend temporary recorder access. Access remains subject to the current reservation, designated recorder, active account, shift, and attendance rules.
- An extension past the production deadline requires a separate management checkbox and delivery-impact reason. A late-start acknowledgement alone never grants deadline approval. Approval evidence is bound to the exact reservation, deadline, finish, and approving actor.
- Current standard-WO Start readiness is shared with the Schedule list. Running reservations that have ended show a specific Extend run warning. Repair and historical reservation contexts retain their existing rules. WO confirmation remains removed from Production Schedule.
- The existing alert sweep still raises one deduplicated review alert for an expired confirmed reservation. It directs management to Start readiness rather than requiring rescheduling for every late start.
- Immutable scheduling and execution events appear in the WO Activity card, with expandable timing and approval details. The API exposes an allowlist of event fields rather than raw employee or reservation identifiers.
- Ordinary readiness polling preserves confirmations when domain inputs are unchanged and clears them when crew, schedule, attendance, blockers, mode, or deadline changes. Extension preview polling is disabled while the manager reviews its finish; submission remains authoritative.
- Modal actions and the shared modal close control have at least 44-pixel touch targets. Existing controls, semantic colors, 12-hour production date formatting, and keyboard focus behavior are reused.

## Verification

The focused backend gate passes **118 tests with 578 assertions**, covering execution readiness, resource reservations and changes, attendance, operation output concurrency, capacity scheduling, and alert recovery. Frontend regression tests pass **51 cases** across Start/Resume/Extend, assignment, WO details, and output recording. A subsequent 13-case dialog check passes after extracting a hook dependency for lint. Production build and targeted frontend ESLint pass. Production PHP formatting and syntax checks pass. The shared alert service has a pre-existing Pint failure; the same failure is reproduced with the three-line WO alert addition removed. Unrelated alert-service formatting was preserved.

Browser verification uses the actual frontend and an authenticated Production manager session, with synthetic work-order/readiness responses and intercepted business mutations. No confirmed live WO was available to that account during this run. This verifies rendering and request wiring, not successful execution against a live business record. It checks unchanged-poll confirmation persistence, changed-attendance invalidation, separate deadline approval, extension payload, Activity history, 44-pixel controls, and a 390-pixel mobile viewport. No page errors or live WO mutation requests were forwarded.

Evidence: `docs/testing/evidence/wo-flexible-start-fixes-20261008/`. The browser screenshots show intercepted fixtures, not live attendance or approved business schedules.

Backend gate from the repository root:

```sh
docker exec ogami-api vendor/bin/phpunit --configuration=phpunit.pricing.20261004.xml tests/Feature/Production/WorkOrderExecutionReadinessTest.php tests/Feature/Production/ProductionResourceReservationTest.php tests/Feature/Production/WorkOrderAttendanceSafetyTest.php tests/Feature/Production/WoOperationOutputRaceTest.php tests/Feature/Production/ProductionResourceChangeTest.php tests/Feature/MRP/CapacityPlanningServiceTest.php tests/Feature/Alerts/AlertsHardeningTest.php
```

This configuration uses the isolated `ogami_test_pricing_20261004` database. Do not replace it with the application database.

## Manual acceptance

1. Open a confirmed WO as Production management and select Start. Verify a safe late start keeps the approved end when its remaining duration fits; acknowledge lateness only when required.
2. Mark an assigned operator absent or clocked out through an approved HR test scenario. Verify WO execution and operation execution reject that operator. Replace the worker or correct HR attendance; do not treat the presence checkbox as an absence override.
3. For a running test WO whose reservation has ended, verify the warning and recorder-access restriction. Select Extend run, review the proposed finish, and approve only when resources and crew can cover it.
4. Verify conflicting equipment or crew, changed reservation versions, missing QC/material coverage, and unapproved deadline overruns reject the extension without changing current reservations.
5. After a successful extension, check Activity for original and new timing and the approver. Verify designated recorder access returns only within the new reservation and attendance window.
6. When reporting output produced before a clock-out, use the management reporting reason. This records earlier production; it does not authorize an absent operator to run equipment.

## Unchanged scope and limits

Audit F10 (daily operating-hour policy versus reservation capacity) and F11 (routine versus safety-critical maintenance severity and outage policy) remain deferred. No new maintenance or historical-runtime blocks were added. Existing resource ownership, overlap, mold-life, QC, material, and reservation-capacity guards remain active.

No schema migration, employee-role grant, indefinite recording permission, automatic physical production stop, or live manual-test WO mutation was introduced. Physical-device gestures, exhaustive contrast/zoom checks, and the entire ERP suite were not tested. Continued physical production after a reservation ends still requires operational management; software does not stop the machine.
