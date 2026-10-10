# Work-order flexible-start audit

Date: 2026-10-06 UTC. Scope: the recently added early start, 15-minute start tolerance, late-start acknowledgement, scheduling, crew attendance, output access, and related production interface. This is a focused audit, not a certification of every production or ERP feature.

Implementation follow-up, 2026-10-08: see [Work-order flexible-start fixes](work-order-flexible-start-fixes-20261008.md) for the approved attendance, timing, extension, history, and interface changes. Findings below describe the original audit behavior; F10 and F11 remain policy decisions.

No application fixes or live business mutations were made. Backend probes used the isolated `ogami_test_pricing_20261004` database. Browser checks used read-only live requests and intercepted readiness responses; the manual-test WO was not started, rescheduled, or edited.

## Findings and priorities

There are 11 findings: three P1 operational issues and eight P2 consistency, usability, or policy gaps. No P0 issue was established. The capacity and maintenance findings below require a policy decision; they are not demonstrated double-bookings or proven unsafe equipment use.

### F1 — P1: Late-start padding can block work that still fits the approved slot

Location: `api/app/Modules/Production/Services/ProductionResourceService.php:51`.

After 15 minutes, the execution window always requests a fresh reservation of at least 120 minutes. The same happens when an older exact-duration slot has insufficient remaining time. This combines lateness acknowledgement with capacity extension.

Reproduction: a 50-piece WO needs 40 minutes; its approved slot and crew shift end at 10:00 AM. At 8:16 AM, work would finish at 8:56 AM, but the new reservation ends at 10:16 AM. Readiness rejects the crew because that extended window exceeds its shift. An older exact-duration slot can encounter the same problem after one minute.

Recommendation: keep the two-hour planning default. Separate acknowledgement from extension: if remaining work fits the approved end, retain that end and require late acknowledgement when appropriate. Extend capacity only when needed, validating the estimated finish, practical buffer, resource conflicts, shift coverage, and deadline. Display estimated finish separately from reserved-until time.

Evidence: backend probes `test_audit_sixteen_minute_delay_extends_unnecessarily_beyond_worker_shift` and `test_audit_grace_near_slot_end_still_needs_late_acknowledgement`.

### F2 — P1: Operation setup accepts an operator whom HR marks absent

Locations: `api/app/Modules/Production/Services/WoOperationService.php:110` and `:574`.

Operation setup uses the crew eligibility check with `starting=false`. That verifies active crew membership and competing runtime responsibility, but not current attendance. A manager can start setup under a known-absent assigned operator while the parent WO is running and its QC gate is satisfied. Start, resume, and operation-output sibling paths deserve the same review.

Recommendation: reuse current attendance and runtime checks for operation setup/start/resume. Reject known absence and clock-out; retain the existing controlled physical-presence confirmation for genuinely missing attendance. Reporting earlier output should use a separate, audited management exception rather than weakening execution checks.

Evidence: `test_audit_operation_setup_accepts_known_absent_crew_member` passed after satisfying the existing in-process QC gate. This does not prove an employee can bypass route permissions; the defect is recording execution under an absent operator through an authorized management path.

### F3 — P1: Recorder access expires while production can remain in progress

Location: `api/app/Modules/Production/Services/WorkOrderAssignmentService.php:625`.

One minute after the reservation ends, a designated recorder with verified attendance cannot enter output even when the WO remains in progress. The management fallback requires an explanation for earlier output, but the worker has no direct overrun recovery flow.

Recommendation: preserve temporary-access restrictions. Add a visible overrun warning and a management action to extend the remaining run after rechecking resources, shifts, attendance, and deadline. Provide a clear worker action to request assistance. Do not silently grant indefinite recording access or automatically stop physical production.

Evidence: `test_audit_recorder_access_ends_at_reservation_even_if_production_is_running`.

### F4 — P2: Attendance failure is classified as a scheduling failure

Locations: `api/app/Modules/Production/Services/WorkOrderService.php:419` and `spa/src/pages/production/work-orders/StartProductionDialog.tsx:203`.

Known absence adds a presence blocker, then the generic exception handler adds a schedule blocker and sets `needs_reschedule=true`. The interface consequently offers rescheduling and suppresses its specific Change workers / HR correction guidance. The original absence warning remains visible; browser testing disproved the earlier suspicion that it disappears.

Recommendation: classify recovery actions by cause. Attendance or crew failure should offer Change workers or HR correction; time failure should offer Reschedule; maintenance and quality failures should identify their responsible module. Allow combined failures without repeating generic messages.

Evidence: `test_audit_known_absence_is_classified_as_reschedule_required` and the intercepted mobile browser scenario.

### F5 — P2: Unchanged polling clears checked acknowledgements

Locations: `spa/src/pages/production/work-orders/StartProductionDialog.tsx:46`, `:64`, and `:228`.

Checkbox state is tied to React Query's `dataUpdatedAt`. Every successful 30-second refresh makes a previously checked late-start or physical-presence confirmation unchecked, even when the crew, schedule, and attendance remain unchanged. Start can disable while the user is reading.

Recommendation: bind acknowledgement to meaningful crew, schedule, and attendance versions rather than the fetch timestamp. Reconfirm after relevant changes or a defined expiry; continue server-side validation at submission. If a confirmation is invalidated, briefly explain what changed.

Evidence: Chromium clock advanced 31 seconds, a fresh readiness request completed, and the late-start checkbox became unchecked with unchanged domain inputs.

### F6 — P2: A late start cannot reuse earlier deadline approval for a changed finish

Locations: `api/app/Modules/Production/Services/ProductionResourceService.php:53` and `:301`.

Paused work can receive an explicit late-production approval for a reviewed slot. After a 16-minute delay, the new execution finish differs from that approved finish and fails the exact-window deadline authorization check. The late-start acknowledgement does not replace deadline approval.

Recommendation: keep those approvals separate. Explain that the new finish needs renewed delivery-impact authorization and offer the existing deadline-review action with the proposed finish. Never silently transfer approval to an unreviewed end time.

Evidence: `test_audit_approved_late_deadline_is_lost_when_start_deviates`, with in-process QC satisfied; its only readiness blocker was deadline authorization.

### F7 — P2: Schedule readiness still uses older start rules

Locations: `api/app/Modules/MRP/Services/CapacityPlanningService.php:475` and `spa/src/pages/production/schedule.tsx:490`.

The WO Start endpoint permits a safe late-start preview, while schedule readiness still calls the older strict `assertExecutionSlot`. Its response reports an out-of-slot blocker and refers to Review production slot. The Schedule list renders `staffing.blockers`, not `execution_blockers`, so it can also omit the current runtime reason entirely.

Recommendation: use the same execution preflight for current standard-WO reservations across both surfaces. Show execution blockers where relevant and link to the WO Start action. Preserve the separate repair context and released-history presentation. Keep confirmation removed from the Schedule page.

Evidence: `test_audit_schedule_page_disagrees_with_safe_late_start`; list rendering verified in source.

### F8 — P2: Saved execution history is missing from the WO Activity card

Locations: `api/app/Modules/Production/Services/ProductionResourceService.php:95` and `spa/src/pages/production/work-orders/detail.tsx:1059`.

Early/late execution saves original and actual windows plus the approving actor in an immutable event. The WO Activity card constructs only creation, start, completion, and current pause entries. Users cannot see the early/late approval or scheduling history there; generic schedule labels do not clearly distinguish the approved plan from the actual capacity reservation.

Recommendation: show the existing execution-deviation event in Activity, with original timing, actual start, reserved-until time, approver, and acknowledgement. Keep a compact summary and expandable history. Reuse the saved event; no additional audit table is needed.

Evidence: source trace. No live deviation was created solely to test this presentation.

### F9 — P2: Production modal controls have small touch targets

Locations: `spa/src/components/ui/Button.tsx:20`, `spa/src/components/ui/Modal.tsx:188`, and StartProductionDialog button callers.

Live browser measurements found 32-pixel-high action buttons and an 18-pixel-high close button. These fall below the audit's 44-by-44 shop-floor touch target. This is not, by itself, a demonstrated WCAG AA failure; spacing and the applicable criterion also matter.

Recommendation: reuse the existing `touch` button size on narrow/shop-floor surfaces and give the modal close control a 44-by-44 target. Preserve visible keyboard focus, labels, and compact desktop layout.

Evidence: measured DOM rectangles; a 390-pixel-wide emulated Chromium viewport had no document-level horizontal overflow. Physical-device gestures, zoom, and a full contrast audit remain untested.

### F10 — P2: Daily capacity validation accounts for reservations, not completed runtime

Location: `api/app/Modules/Production/Services/ProductionResourceService.php:181`.

The daily-capacity query counts only active reservations and excludes the current WO context. A paused WO with an earlier two-hour allocation can receive another two-hour execution reservation on a machine configured for 2.5 hours per day. The probe establishes reservation accounting, not that the machine actually consumed all earlier allocated time.

Recommendation: define whether `available_hours_per_day` represents remaining bookable capacity or a total operating-hours limit. If it is a total limit, include completed runtime once, using actual execution intervals and pauses, while avoiding double-counting active reservations. Keep physical ownership and overlap protection unchanged.

Evidence: `test_audit_daily_capacity_for_resume_ignores_already_consumed_reservation`. No overlapping machine or mold reservation was demonstrated.

### F11 — P2: Calendar maintenance due dates are not part of execution-window checks

Location: `api/app/Modules/Production/Services/ProductionResourceService.php:107`.

The guard checks equipment status, ownership, compatibility, reservations, and mold-life limits. An active overdue calendar maintenance schedule does not change the execution-window result while equipment status remains available. A due date alone does not establish that production must be prohibited.

Recommendation: define maintenance severity first. Surface routine overdue maintenance as an actionable warning; block safety-critical overdue work and approved maintenance downtime according to explicit policy. Connect planned outage intervals to capacity checks, rather than blocking every overdue reminder indiscriminately.

Evidence: `test_audit_calendar_maintenance_due_does_not_block_execution_window`.

## Interface audit health

These scores apply only to the inspected Start/Reschedule surface. They are provisional, not a full WCAG, dark-mode, or performance certification.

| Dimension | Score | Evidence and limitation |
| --- | --- | --- |
| Accessibility | 3/4 | Labelled dialog, keyboard focus handling and controls; small close target. Contrast and zoom not exhaustively tested. |
| Performance | 3/4 | Bounded polling and no browser errors; repeated polling invalidates confirmations. No load profile performed. |
| Responsive design | 3/4 | Mobile modal fits a 390-pixel viewport; action targets need enlargement. Physical touch untested. |
| Theming | 3/4 | Existing semantic tokens reused; complete dark-mode contrast not verified. |
| Implementation integrity | 2/4 | Reused services and controls, but recovery classification and readiness rules diverge. |
| Total | 14/20 | Good interface foundation; address operational consistency before more visual polish. |

Implementation-integrity verdict: the interface is coherent and product-specific, but the flow does not yet pass operational consistency review. The bundled detector reported no findings in the named StartProductionDialog and CrewPanel files; source and browser inspection found the issues above independently.

## Working safeguards to retain

- Submission revalidates state inside transactions with WO, employee, machine, and mold locks; a readiness preview does not authorize execution by itself.
- Known absence blocks the main WO Start path. Early start requires verified attendance, rather than a missing-attendance acknowledgement.
- Crew limits, Production employee eligibility, one recorder, resource ownership, compatibility, mold life, reservations, QC and material gates remain in place.
- Original timing and execution-deviation evidence are saved. Use that data in the UI rather than duplicating it.
- Suggestions remain read-only until approved. Loading feedback respects reduced motion. Rescheduling stops readiness polling while the user reads suggestions.
- Upcoming reminders already exist and deduplicate notifications. They should reuse consistent readiness/recovery wording; no second reminder subsystem is necessary.

## Recommended implementation order

1. Fix F1 and F2 in shared execution/attendance checks, with regression tests for exact-duration slots, the 15-minute boundary, operator absence, and clock-out.
2. Implement F3's controlled overrun recovery. Keep access temporary and recheck conflicts before extending it.
3. Align F4, F6 and F7 recovery actions with the same preflight rules, retaining separate deadline authorization.
4. Resolve capacity and maintenance policy for F10 and F11 before adding new blocking behavior.
5. Apply `$impeccable harden` to F5's state handling, `$impeccable clarify` to readiness/history wording, `$impeccable adapt` to F9's touch targets, then `$impeccable polish` to the completed surface. Re-run `$impeccable audit` after fixes.

## Evidence and reproduction

Evidence directory: `docs/testing/evidence/wo-flexible-start-audit-20261006/`.

The final backend audit run passed 9 probes with 22 assertions. Passing here means the probes successfully reproduced the described current behavior; it does not mean the findings are fixed. Live/intercepted browser probes passed, recorded zero WO mutation requests, and verified unchanged live status, start, quantities, and batch number.

To repeat backend probes from the repository root:

```sh
docker cp docs/testing/evidence/wo-flexible-start-audit-20261006/WoStartAuditTest.php ogami-api:/tmp/WoStartAuditTest.php
docker exec ogami-api vendor/bin/phpunit --configuration=phpunit.pricing.20261004.xml --filter=test_audit /tmp/WoStartAuditTest.php
```

The harness extends the existing execution-readiness fixture and deliberately uses its isolated test configuration. Do not substitute the application's database. Browser evidence includes an intercepted absence scenario; that screenshot must not be interpreted as the live employee's actual attendance.
