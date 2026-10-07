# Punch Rules Hardening — Design

Date: 2026-10-07
Builds on: `docs/superpowers/specs/2026-10-06-time-in-out-design.md` (geofenced Time In/Out, already built).
Chain: 3 (Hire to Retire) — Attendance → DTR → Payroll.

## Goal

Close the business-rule gaps in self-service Time In/Out that let an employee earn pay or record attendance the shift rules do not support: early time-out, time-in long after the shift began, rest-day and holiday work without authorization, an ignored `working_days` configuration, and open rows nobody is told about.

Pay amounts, premium rates and the overtime approval process are unchanged.

## Findings this addresses (from the audit of the shipped feature)

1. OUT before shift end is accepted; only payroll's undertime deduction reacts. A 61-second IN/OUT is accepted and yields an Absent row that still carries ~8 h of undertime.
2. IN is accepted up to `punch_late_minutes` (240) after shift end; the row becomes a half-day or an absence, with nothing flagging it.
3. A self-service punch on a rest day or holiday earns the premium rate with no work authorization.
4. `EmployeeShiftAssignment.working_days` is stored and validated but never read: DTR hard-codes Sunday as the only rest day (so a Monday–Friday worker's Saturday and a Sunday–Thursday worker's Sunday are mis-classified).
5. A forgotten time-out is paid in full, and only HR is told, once.
6. Lunch/break punches are invisible (only first-in/last-out are stored).

Already correct and unchanged: unapproved overtime is not paid; auto-detected overtime stays Pending until approved; server time is authoritative; leave, manual-entry, removed-row and payroll-lock guards.

## Decisions (agreed)

1. **Early out:** refuse OUT earlier than `shift end − attendance.punch_early_out_grace_minutes` (default 15). The employee is told to ask HR to record an earlier exit (HR manual entry).
2. **Minimum work:** refuse OUT earlier than `attendance.punch_min_work_minutes` (default 30) after the **latest IN punch** (not the row's first time-in, so post-shift overtime cycles work).
3. **Late in:** refuse the **first IN of the day** later than `shift start + attendance.punch_late_in_cutoff_minutes` (default 240). Later IN cycles (post-shift overtime) and authorized non-working-day work are exempt.
4. **Rest days and holidays:** an IN on a non-working day or a holiday requires an **Approved `OvertimeRequest`** for that date for the employee. No new request flow; pay stays as today (premium rate).
5. **Working days:** a day is a working day per the employee's current assignment `working_days` (ISO weekdays 1–7). A null/missing value keeps today's behaviour: Monday–Saturday work, Sunday rest.
6. **Open rows:** the daily missing-time-out command also notifies each department head about their own department's open rows; payroll shows a non-blocking warning when a period contains open rows. Pay is not withheld automatically.
7. **Lunch/breaks:** no gap tracking. Decision 1 makes a mid-day OUT impossible, so one in/out pair per day follows naturally; post-shift overtime cycles still work.

## Dependency note (found while planning)

The `working_days` column migration (`2026_10_05_000010_add_working_days_to_employee_shift_assignments`), the model's `working_days` fillable/cast, `ShiftAssignmentService::configureWorkingDays`/`assertWorkingDays` and the shift-assignment routes are **uncommitted work in the working tree**, not in `HEAD`. This feature must not commit that work. `isWorkingDay()` is therefore written to degrade safely: a missing attribute, a null value, or an un-cast JSON string all fall back to the Sunday-rest default, so the committed code behaves exactly like today until the `working_days` work is committed. Tests that need a non-null `working_days` skip themselves when the column is absent.

## Out of scope

Absence deduction (needs HR policy), a missed-punch/correction request flow, device binding or selfie capture, withholding pay for open rows.

## Punch rules — `PunchService`

Rule order inside `punch()` (all throw `PunchRejectedException` inside the transaction, so a rejection writes no punch and no row):

1. existing: range/accuracy → lock employee → cooldown → direction → shift → window (`windowDate`) → row load/`guardRow` → `assertMutable`;
2. **new, IN only, after the anchor (`$anchor`, the shift-instance start date) is known and before the row is created:** authorization of non-working days;
3. **new, IN only, first IN of the day:** late-in cutoff;
4. **new, OUT only, after `out_before_in`:** minimum work, then early-out;
5. existing write path (`computeForRecord`, save, overtime auto-detect, punch row).

### Non-working-day authorization (IN)

- `nonWorking = !ShiftAssignmentService::isWorkingDay($employee, $anchor) || HolidayService::forDate($anchor) !== null`.
- When `nonWorking` and no `OvertimeRequest` exists for (employee, date = `$anchor`, status Approved) → reject `work_not_authorized`: "Rest-day and holiday work needs an approved overtime request. File one, then try again."
- The date checked is `$anchor`, not `$now`, so a night shift that began on a working day and crosses into a rest day is judged by its start date.
- Both holiday types (regular, special non-working) count. A holiday that is also a normal working day still requires authorization, because punching on it pays the holiday premium.
- `autoDetectFromAttendance` is unaffected: it skips dates that already have an overtime row, and an Approved request is such a row.

### Late-in cutoff (IN)

- Applies only when the day's row does not exist or has no `time_in` (first IN of the day) **and** the day is not authorized non-working work.
- Reject when `$now > shiftStart($anchor) + cutoff` → `too_late_in`: "It is too late to time in for this shift. Ask HR to record your attendance."
- Night shifts use the shift-instance start of `$anchor`.

### Minimum work and early-out (OUT)

- `latestIn` = `$last->punched_at` (the open IN punch).
- `minWorkAt = latestIn + min_work_minutes`.
- `earliestOutAt` = `shiftEnd(row date instance) − early_out_grace` for a normal working-day shift. For authorized non-working-day work only `minWorkAt` applies (shift hours do not bound authorized rest-day work).
- Reject when `$now < max(minWorkAt, earliestOutAt)`:
  - `min_work`: "Wait until {time} to time out (minimum {n} minutes of work)."
  - `too_early_out`: "You can time out from {time}. For an earlier exit, ask HR to record it."
- Times are formatted `g:i A` in the application time zone (Asia/Manila).
- Shared helper `PunchService::earliestOut(...)` is used by both `punch()` and `status()` so the card and the check never disagree.

### Shift-instance helper

`windowDate()` already builds a shift's start/end for a candidate day. Extract the construction into one private method returning `[start, end]` for a given day (end rolls to the next day for night shifts) and reuse it for the window, the late-in cutoff and the early-out time.

### New rejection codes

`work_not_authorized`, `too_late_in`, `min_work`, `too_early_out` (joining `too_soon`, `no_shift`, `outside_window`, `row_removed`, `out_before_in`, `low_accuracy`, `out_of_range`, `on_leave`, `manual_entry`).

### Status endpoint

`status()` additionally returns `earliest_out`: ISO-8601 string when the next action is `out`, else null. It is computed with the same helper. The response remains `{data: {...}}`.

## Working days — DTR and assignment service

- `ShiftAssignmentService::assignmentFor(Employee, CarbonInterface $date): ?EmployeeShiftAssignment` — the same date-range query `current()` uses (`effective_date <= d AND (end_date IS NULL OR end_date >= d)`, newest `effective_date` first); `current()` is rewritten on top of it so there is one query.
- `ShiftAssignmentService::isWorkingDay(Employee, CarbonInterface $date): bool` — `working_days` null or no assignment → `dayOfWeekIso !== 7` (Sunday rest); otherwise `in_array(dayOfWeekIso, working_days, true)`.
- `DTRComputationService::computeForRecord`, currently `$isRestDay = (bool) $a->is_rest_day || $date->dayOfWeek === Carbon::SUNDAY;`, becomes `$isRestDay = ($a->is_manual_entry && (bool) $a->is_rest_day) || ! $this->shiftAssignments->isWorkingDay($a->employee, $date);`.
  - HR's manual entries keep their explicit flag.
  - Non-manual rows no longer inherit a previously stored `is_rest_day`, so changing `working_days` takes effect on the next recompute (previously the stored flag was sticky).
- `compute()` and its inputs are unchanged, so `DTRComputationServiceTest` and the payroll fixtures do not change.
- This applies wherever `computeForRecord` runs: punches, CSV imports, HR manual entry, overtime approval recompute. That is intentional; it is the behaviour change for any employee whose `working_days` is set.

## Open rows — notifications and payroll warning

### Department-head notification

- `attendance:notify-missing-timeout` keeps its HR summary (key `attendance.missing_time_out:{date}`) and additionally groups yesterday's open rows by `employee.department_id`. For each department, active users with role slug `department_head` whose employee is in that department get one notification: "{n} employee(s) in {department} have no time out for {date}", link `/hr/attendance?missing_time_out=1`, dedupe key `attendance.missing_time_out:{date}:{userId}` so reruns do not duplicate and one head's notice cannot suppress another's.
- Departments with open rows but no active department head are reported in the command output; that alone is not a failure. The command still exits FAILURE when every attempted send throws, and when HR has no recipient (existing behaviour).
- Verify during implementation that the dedupe key's uniqueness on `notifications` is per row, not a global constant (a reused key is silently dropped by `insertOrIgnore`).

### Payroll warning

- New `PayrollAnomalyType::OpenAttendance` (`open_attendance`, label "Open attendance"). `payroll_anomaly_flags.flag_type` is `string(40)`, so no migration.
- `PayrollAnomalyService::detectForPayroll` flags a payroll when the employee has attendance rows in the period with `time_in` set, `time_out` null and `date < today`; `details` lists the dates.
- Finalize must not be blocked by it: exclude `open_attendance` from the unresolved-flag count in `PayrollPeriodService` (~l.1393) and `PayrollAnomalyService::unresolvedCount` (~l.278).
- SPA: add the type to the union in `spa/src/types/payroll.ts` and a `warning` chip entry in `spa/src/components/payroll/AnomalyReviewPanel.tsx`.

## Settings (migration `0572_seed_punch_rule_settings`)

Only settings are seeded, so the plain numeric prefix is correct (no dependency on a `2026_*` migration). Each key needs all three of: the migration, a `SettingsSeeder` row, and an `UpdateSettingRequest` rule (a missing piece makes `requiredInt` throw or the admin update fail).

| key | default | rule |
|---|---|---|
| `attendance.punch_early_out_grace_minutes` | 15 | integer 0–120 |
| `attendance.punch_min_work_minutes` | 30 | integer 0–240 |
| `attendance.punch_late_in_cutoff_minutes` | 240 | integer 30–720 |

## SPA

- `useTimeClock` `RECOVERY` map gains the four codes: `work_not_authorized` ("File an overtime request for today, then try again once it is approved."), `too_late_in`, `min_work`, `too_early_out` (all: "Ask HR to record it" or "wait until the time shown").
- `PunchStatus` type gains `earliest_out: string | null`.
- `TimeClockCard` shows "You can time out from {time}" under the state line when clocked in and `earliest_out` is in the future. The dashboard quick action is unchanged (its failure toast already includes the recovery hint).
- Settings page: the three new keys appear in the generic Attendance settings group; no picker change.

## Error handling and behaviour notes

- Every guard throws before any write, inside the existing transaction; nothing partial can persist.
- The 60-second cooldown and the 3-second client cooldown remain.
- An employee needing an earlier exit, a very late arrival, or rest-day work has a defined path: HR manual entry, or an approved overtime request for rest days/holidays.
- Behaviour change to announce: employees with `working_days` set will have rest-day detection follow it (including in CSV import and recompute).

## Testing

- `PunchService` (feature): early-out refused then allowed at the boundary; min-work refused (61-second in/out); late-in refused for the first IN but allowed for a post-shift second IN; non-working-day IN refused without an Approved overtime request and allowed with one (rest day by `working_days`, Sunday default, regular holiday, special holiday); a night shift starting on a working day and ending on a rest day is allowed; authorized rest-day OUT only needs min-work; `status().earliest_out` matches `punch()`.
- `ShiftAssignmentService`: `assignmentFor` date ranges; `isWorkingDay` for null, set, no assignment, and a JSON-string value.
- DTR: Mon–Fri employee on Saturday → rest day (was: normal day); Sun–Thu employee on Sunday → working day (was: rest day); null → Sunday rest, Monday–Saturday working; manual entry keeps its flag; recompute after a `working_days` change.
- Command: department-head fan-out grouped per department, per-user dedupe keys, department without a head, HR-only fallback, all-sends-fail exit code.
- Payroll: `OpenAttendance` flag created with dates, does not block finalize, other flags still block.
- SPA: card shows the earliest-out hint; recovery hints for the four new codes; `PayrollAnomaly` chip.
- Existing suites that must stay green: `PunchServiceTest`, `PunchEndpointTest`, `PunchCommandsTest`, `DTRComputationServiceTest`, `PairedCsvImportTest`, `RawPunchImportTest`, payroll anomaly/finalize tests.
- Run PHP tests on an isolated database, never the shared `ogami_test`.

## Review focus (inputs most likely to bite)

- Night shift that starts Friday and ends Saturday for a Mon–Fri worker (anchor = Friday → working day).
- Overtime request approved for a date after the punch was refused (retry must succeed without re-login).
- Employee with `working_days` set whose rows were computed under the old Sunday-sticky flag, after the engine change and a recompute.
- Post-shift overtime second cycle must not trip `too_late_in` or the early-out rule.
- Department with two department heads (each notified once) and one with none.
