# WO scheduling and attendance audit — 2026-10-06

Scope: automatic and manual crew selection, proposed production timing, confirmation, Start/Resume, worker changes, assigned-worker recording, and the HR attendance/shift/leave connection. This is an audit and recommendation, not an implementation of the findings.

## Domain distinction

Missing attendance is absence of evidence. It does not establish that a person is absent. The shift may not have started, biometric punches may not have been imported, or the attendance record may need correction. A recorded `absent` or `on_leave` status is an explicit blocking record. A clock-out is evidence that the recorded working session ended. These states must not share one label or silently override one another.

For example, at 10:00 a worker assigned to 14:00–22:00 can be planned for a 15:00–17:00 WO without a punch-in yet. That worker cannot cover a 12:00–13:00 WO. Planning the 15:00 job should not require the worker to be physically present at 10:00. Start must recheck presence at actual execution time.

## Existing safeguards confirmed

- The shared `workerBlocker()` checks the selected interval against working days, one continuous HR shift, approved leave, recorded absence, clock-out before the interval, other WO reservations and unresolved runtime responsibility.
- Auto suggestions and manual selections use the same eligibility rules. Approval revalidates them; it does not trust a previously displayed selection.
- Start and Resume recheck current presence. Recorded absence, approved leave and clock-out block execution. A manager's physical-presence confirmation cannot override these blockers.
- Overnight attendance is read using the originating shift work date. Existing regression coverage proves a Monday 03:06 job can use Sunday's 22:00 night-shift attendance when Sunday is a configured working day.
- Worker and attendance/shift mutation paths use employee locks. Retain this serialization and transaction-level checks.

## Findings

| Priority | Finding and evidence | Recommendation |
| --- | --- | --- |
| High | Missing-attendance workers remain eligible and can be retained or chosen ahead of a present eligible worker. `suggest()` sorts workload and preserves an eligible current crew without a presence preference. A probe reproduced a missing-attendance recorder retained despite an eligible present replacement. | For an immediate slot, prefer workers with a verified current punch-in. Clearly label missing attendance as unverified. Offer replacement before Start; reserve physical confirmation for an explicitly identified manager exception. For a shift that has not started, retain provisional eligibility rather than incorrectly marking the worker absent. Do not automatically change an approved crew. |
| High | `assertCanRecord()` invokes `assertReadyForStart()` with its default `starting=false`, skipping presence and remaining-shift checks. A designated employee passed recording authorization after clock-out. Equipment, lifecycle and material checks elsewhere do not repair this authorization gap. | Recheck the recorder's current attendance and permitted recording scope. Late reporting of real previously produced goods needs an explicit management path with a reason; do not blindly reject historical output or pretend it was produced after clock-out. Apply the rule to both standard and repair output. |
| Medium | `presence()` requires `time_in >= shift start`. A valid 07:50 punch for an 08:00 shift was classified unknown at 08:00. Attendance computation accepts the punch. This causes unnecessary physical-confirmation exceptions. | Recognize a valid early punch belonging to the same shift/work date. Keep the scheduled Start and shift boundaries unchanged; arriving early does not authorize early production. Reject mismatched or implausibly stale sessions. |
| Medium | `workerOptions()` attaches `presence(employee, now())` although `can_assign` checks the chosen future interval. A worker eligible for a later 14:00 slot carried a blocked current-presence value at 08:00. Suggested `members` also omit presence context. | Expose schedule eligibility separately from current presence. Use labels such as “Shift starts at 14:00,” “Present now,” “Attendance unverified,” and “Absent for selected shift.” Never claim future physical presence based on a prediction. |
| Medium | `workerBlocker()` rejects a clock-out only when it is at or before WO start. A recorded clock-out inside the proposed interval was accepted, although the worker cannot cover the whole recorded interval. | Validate any known attendance end against the required WO interval. Distinguish an actual ended session from future planned availability, and recheck at Start. |
| Medium | Production reads exclude soft-deleted attendance. Archiving an absent attendance row changes its result from blocked to unknown/assignable. HR's create/leave flows explicitly require restoration of archived rows instead. | Treat archived attendance as requiring HR review rather than as a missing punch. Do not revive attendance or overwrite payroll data from the production screen. |
| Medium | Production leave checks use date ranges and ignore `half_day_period`. Leave supports AM/PM requests and deliberately preserves existing attendance for half-day leave. Production therefore blocks the entire day, including its otherwise available half. Overnight leave-date interpretation also needs one explicit policy. | Respect the approved leave period using HR's defined shift/work-date convention. Never invent clock times for night-shift “AM/PM” leave. Until that convention is defined, show a precise HR-review explanation and retain the conservative block. |
| Medium | Attendance and shift changes after approval are caught on the next check or Start, but there is no production-specific early warning from those HR changes. Readiness stays on screen until refetch; elapsed time can invalidate the slot. The upcoming-reminder command currently notifies without reviewing crew eligibility. | Reuse the upcoming reminder to flag affected approved WOs to PPC/Production before start. Refresh the open Start review after relevant data changes or a short bounded interval, clearing any old presence confirmation. Recheck again transactionally when Start is submitted. |

## Recommended minimal flow

1. The selected WO interval determines which employees qualify. Show the actual shift start/end and selected work date beside crew candidates.
2. Remove candidates with recorded absence, approved leave covering the interval, incompatible shifts, clock-out or overlapping responsibility. Keep unavailable workers visible only when explaining why they cannot be selected.
3. If the selected shift is active now, prefer employees with verified attendance. An unverified employee remains clearly provisional; scheduling approval is not proof of physical presence.
4. If the shift has not started or the WO is on another day, missing attendance is expected. Allow planning from HR availability and recheck near execution.
5. On Start, recheck schedule, crew, attendance and production prerequisites. Recorded absence cannot be overridden. For missing punches, provide Change workers or an explicit manager confirmation that the worker is physically present. Do not change HR attendance through that checkbox.
6. When availability changes, notify PPC/Production to review the affected WO. Propose replacements; do not silently replace the recorder or approved crew.
7. Enforce current recorder authorization while preserving a controlled way to report goods actually produced earlier.

Suggested UI copy: “No attendance recorded. Verify this worker is physically present, or change workers.” Before an upcoming shift: “Attendance will be checked at Start.” Replace the scheduling form's broad “Assigned workers are available” with “Crew fits this schedule; attendance checked at Start” when presence is not verified.

No automatic “absent” status should be written just because a punch is missing. HR owns absence and attendance correction. A production presence confirmation records an operational check only; it does not create a punch, alter payroll or grant broad permissions.

## Evidence

- Existing targeted regressions: 5 tests, 15 assertions passed, covering approved leave after assignment, overnight shift coverage, unknown-presence attestation, explicit absence and clock-out.
- A temporary isolated-database probe reproduced early-punch misclassification, clock-out within a proposed interval, archived absence becoming unknown, missing attendance preferred over a present replacement, a valid later-shift candidate carrying blocked current-presence metadata, and recorder authorization after clock-out. The probe was removed after collecting results; it is not a permanent test that asserts defective behavior is correct.
- No production behavior or live business records were changed during this audit.

Source locations: `api/app/Modules/Production/Services/WorkOrderAssignmentService.php` (`presence`, `workerBlocker`, `workerOptions`, `suggest`, `assertReadyForStart`, `assertCanRecord`); `api/app/Modules/Production/Services/WorkOrderService.php` (`executionReadiness`, `start`, `resume`); `api/app/Modules/MRP/Services/CapacityPlanningService.php` (`workerCandidateStarts`); `api/app/Modules/Attendance/Services/DTRComputationService.php`; `api/app/Modules/Leave/Services/LeaveRequestService.php` (`markAttendance`); `spa/src/pages/production/work-orders/CrewPanel.tsx`; `spa/src/pages/production/work-orders/StartProductionDialog.tsx`.
