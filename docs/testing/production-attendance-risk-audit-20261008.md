# Production attendance and scheduling risk audit

Date: October 8, 2026. Scope: employee time clock, HR calendars, work-order crew selection, confirmation, Start/Resume, running production, and Start dialog recovery. This is an audit, not an implementation or a whole-ERP audit.

## Main conclusion

Clock-in, shift coverage, production eligibility, and payroll attendance are currently treated differently across modules. Some differences are valid safeguards, but the interface hides the distinction and two time-clock scenarios produce incorrect operational results. Preserve shift, leave, equipment, and deadline checks while correcting these connections.

There are six P1 findings and two P2 findings below. F5 and F8 include policy decisions; their observed behavior is confirmed, but the proposed rules are recommendations rather than existing requirements.

## Findings

### F1 — P1: Verified clock-ins are described as missing attendance before shift start

Locations: `api/app/Modules/Production/Services/WorkOrderAssignmentService.php:149`, `api/app/Modules/Production/Services/WorkOrderService.php:449`, `spa/src/pages/production/work-orders/StartProductionDialog.tsx:271`.

Live, read-only evidence: Manuel Cruz clocked in at 8:52:18 PM and Juan Dela Cruz at 8:52:33 PM on October 8, Manila time. Both attendance rows were present, matched their assigned shift, and had no clock-out. Their configured night shifts start at 10:00 PM. At 8:55 PM, production returned a shift-hours blocker, then displayed “Early start needs verified attendance.” The approved 10:00 PM crew check passed against the same records.

Impact: users repeatedly clock in, change valid workers, or ask HR to repair correct attendance. A physically present employee is not automatically authorized to work before their shift.

Recommendation: show clock-in evidence separately from eligibility. Use “Workers clocked in. Their shifts start at 10:00 PM.” for this case. Keep distinct reasons for missing punches, recorded absence, leave, clock-out, shift mismatch, and another WO. For actual missing attendance, show names and one clear recovery action. Provide a direct Change workers action where permission allows, rather than telling users to close the dialog and locate it.

Related polish: successful punches invalidate Attendance and Self-service queries, but not Production queries (`spa/src/hooks/useTimeClock.ts:176`). The Start dialog polls every 30 seconds. Refresh relevant same-session data after a punch and use a focused event or explicit refresh for another account; do not restart schedule suggestions while users read them. Keep backend validation authoritative.

### F2 — P1: Overnight shift rotation can prevent valid clock-out

Location: `api/app/Modules/Attendance/Services/PunchService.php:223`.

Reproduction: employee starts a 10:00 PM–6:00 AM night shift on October 6; HR assigns an 8:00 AM–4:00 PM day shift effective October 7. Clock-in at 10:00 PM succeeds. Clock-out at 6:00 AM on October 7 is rejected with `too_early_in`, leaving attendance open. The time clock resolves today's shift before examining the still-open session; yesterday's window reuses that new shift.

Impact: incorrect clock status, missing clock-outs, and additional HR/payroll correction work.

Recommendation: resolve an open session using its attendance work date and stored shift before today's assignment. Apply that same rule to status, punch windows, and earliest clock-out. Test assignment rotation, deleted or inactive shifts, and clock-out at midnight. Preserve closed-session history.

### F3 — P1: Accepted clock-in after clock-out remains blocked in Production

Locations: `api/app/Modules/Attendance/Services/PunchService.php:106`, `api/app/Modules/Production/Services/WorkOrderAssignmentService.php:167`.

Reproduction: 8:00 AM–4:00 PM employee clocks in at 8:00 AM, clocks out at the permitted 3:45 PM time, then clocks back in at 3:47 PM. The second clock-in is accepted, but the daily attendance row retains the earlier clock-out. Production still reports “Worker has clocked out.”

Impact: time clock and work-order access disagree despite an accepted return.

Recommendation: determine current presence from the latest valid session/punch, while retaining daily payroll evidence. Do not simply erase the earlier clock-out. Check manual attendance corrections, archived rows, leave, shift changes, and imported biometric punches before choosing which source is authoritative.

### F4 — P1: Worker selection repeatedly queries the same eligibility data

Location: `api/app/Modules/Production/Services/WorkOrderAssignmentService.php:299`.

Live read-only measurement for `workerOptions` on WO-202610-0003: 83 employees, 3,239 queries, approximately 4,920 ms of database time and 8,206 ms elapsed. This measured only worker options, not a complete schedule search. Hardware and concurrent load affect elapsed time; the query count demonstrates the repeated-query mechanism.

Impact: slow dropdowns and suggestions; concurrent planners amplify database load. A suggestion can also age while its checks run.

Recommendation: load employees, relevant assignments, attendance, leave, and other reservations in bounded batches, and reuse resolved intervals within one request. Reuse existing services rather than introduce another planner. Do not cache runtime attendance across requests without an invalidation rule. Preserve final locked checks on approval and Start.

### F5 — P1: Required crew member can clock out while production remains running

Locations: `api/app/Modules/Production/Services/WorkOrderAssignmentService.php:628`, `api/app/Modules/MRP/Services/CapacityPlanningService.php:515`, `api/app/Common/Services/AlertEngineService.php:460`, `api/routes/console.php:102`.

Reproduction: WO requires two workers. One non-recorder clocks out while the other remains present. The WO remains `in_progress`; the recorder's access check still passes. Output access checks the recorder's presence, not current attendance of every member. The schedule readiness service can detect the missing member, and the alert engine runs every 15 minutes. Therefore this is not an absence of all monitoring, but a delayed operational reaction.

Impact: a run may continue to appear fully staffed after staffing falls below the required count.

Recommendation: notify Production management promptly when a required member clocks out, goes on leave, or becomes unavailable. Add a staffing-review state or acknowledgment and replacement workflow. Decide whether new operations should be blocked while understaffed. Preserve explained reporting of output produced earlier. Do not automatically claim that a physical machine stopped because an attendance record changed.

### F6 — P1: HR shift edits can invalidate approved schedules without immediate impact review

Locations: `api/app/Modules/Attendance/Services/ShiftService.php:50`, `api/app/Modules/Attendance/Services/ShiftAssignmentService.php:116`.

Reproduction: a confirmed, ready WO covers 8:00–10:00 AM. HR changes the assigned shift start to 9:00 AM through ShiftService. The edit succeeds; the WO remains confirmed, but the next readiness check fails. Shift edits also recompute mutable attendance rows that reference the same shift.

Impact: Production discovers a changed commitment at Start; edits to shared shift definitions can affect many employees and mutable DTR records.

Recommendation: preview affected future and active WOs before changing shared shift hours or assignments. Prefer future-effective revisions for routine changes. Require review of active runs, preserve historical shift evidence, and notify the responsible owners. Keep current backend revalidation; do not silently reschedule confirmed WOs.

### F7 — P2: Default attendance shift does not provide a valid Production calendar

Locations: `api/app/Modules/Attendance/Services/PunchService.php:223`, `api/app/Modules/Attendance/Services/ShiftAssignmentService.php:81`, `api/app/Modules/Production/Services/WorkOrderAssignmentService.php:109`.

Reproduction: employee has no explicit HR shift assignment. Time clock uses an active default shift and saves present attendance. Production rejects the same employee because it requires an explicit assignment with configured working days. Attendance also falls back to Monday–Saturday when working days are absent; Production does not.

Impact: “Timed in successfully” can be followed by unexplained inability to join a WO.

Recommendation: retain strict production eligibility, but identify “HR work calendar missing” during account/worker setup and selection. A default payroll rule should not silently create production authorization. Expose missing calendar setup before scheduling.

### F8 — P2: Approved overtime does not establish Production shift coverage

Locations: `api/app/Modules/Production/Services/WorkOrderAssignmentService.php:101`, `api/app/Modules/Attendance/Services/PunchService.php:286`.

Reproduction: employee has approved two-hour overtime and an open attendance record. After the configured shift ends, Production reports the employee outside shift hours. Production shift intervals do not resolve overtime requests.

Impact: authorized overtime can still require separate HR scheduling work before a WO can use it. A late-start checkbox cannot override this coverage rule.

Recommendation: define whether approved overtime may authorize production. If supported, use explicit approved start/end times, working date, and attendance evidence; validate resources, rest days, leave, holidays, and deadlines again. An approved number of hours alone is not enough to infer a safe interval. Until then, clearly say that an HR shift adjustment is required.

## UI audit

The Start surface retains a coherent application design system. It has a labeled dialog, focus trap, 44-pixel close control and action buttons, status/error announcements, responsive width, design tokens, and a reduced-motion alternative for the loading spinner. The detector reported no primary findings for StartProductionDialog or CrewPanel. Its pass does not prove domain copy is accurate.

| Dimension | Provisional score | Evidence or limitation |
| --- | --- | --- |
| Accessibility | 3/4 | Shared dialog semantics and keyboard trap; full screen-reader verification not performed. |
| Performance | 1/4 | Worker selection performed 3,239 queries for 83 employees. |
| Responsive design | 3/4 | Current 390×844 Chromium error-recovery render fits without horizontal overflow; physical devices not tested. |
| Theming | 3/4 | Components use existing tokens; dark-theme contrast not measured in this audit. |
| Implementation integrity | 2/4 | Coherent components, but attendance evidence and production messages disagree. |
| Total | 12/20 | Provisional, scoped assessment; not a WCAG conformance claim. |

Prior current-session Start-warning evidence also covers 1440×1000 and 390×844. The new isolated browser probe verified that a background readiness failure hides Start, exposes Retry checks, and performs no mutation. An initial observation taken exactly as the refresh began was inconclusive; the saved final probe waits for the error state before asserting behavior.

Recommended UI work: `$impeccable clarify` for F1's reason-specific messages, `$impeccable optimize` for F4's connected selection path, `$impeccable harden` for attendance-change recovery, then `$impeccable polish`. These are recommendations; no UI changes were applied.

## Recommended implementation order

1. Correct F1's attendance/shift wording and recovery actions without relaxing any gate.
2. Repair F2 and F3 together at the time-clock and presence boundary; preserve payroll history.
3. Reduce F4's repeated queries and prove unchanged eligibility with query-count and scenario checks.
4. Add F6's HR impact review and F7's missing-calendar notice.
5. Agree on F5's running-crew response and F8's explicit overtime intervals before implementing those policy changes.

Automation should detect, explain, and notify changes. It should not silently replace workers, approve overtime, reschedule committed work, or claim physical production stopped.

## Verification and evidence

Seven isolated reproduction probes passed with 13 assertions. These assert current observed gaps; they do not prove those gaps are fixed. Eight existing safety tests passed with 54 assertions, including ordinary overnight clock-out, verified early starts, 15-minute grace, conflict/deadline enforcement, and invalid-punch rejection. Some production checks execute in both the base and inherited attendance-safety classes. Fifteen Start dialog tests passed.

The first probe run had a fixture error because the added crew member lacked the required employee snapshot. Correcting the temporary fixture produced the saved passing run. Product code was not edited to make probes pass.

Evidence is in `docs/testing/evidence/production-attendance-risk-audit-20261008/`: temporary probe source, passing logs, detector output, browser result, and error-state screenshot. Probes ran sequentially on dedicated database `ogami_test_earliest_planning`; they were never run against the live database. Live observations used a database read-only transaction followed by rollback. No live WO, attendance, account, shift, or notification was changed.

This audit did not run a concurrent Start/clock-out stress test, full payroll reconciliation, the entire backend suite, dark-theme contrast analysis, or physical-device/screen-reader tests. Runtime staffing and overtime recommendations require explicit business-policy choices. Existing guards and periodic alerts mitigate some risks but do not remove the confirmed gaps.
