# Audit: attendance — 2026-09-06

## Summary

The attendance module is structurally sound: pure-function DTR engine with a strong unit test
(29 cases), race-safe OT auto-detect with partial unique index, payroll-lock mutability guard,
per-row CSV error isolation without SQL leakage, HashIDs and string decimals throughout.
69 tests pass (`--filter='Attendance|DTRComputation'`). The two inherited flags were verified:
the night-differential premium really does ignore `day_type_rate` in payroll (AT-01, confirmed),
and OT authorization really does lean on a hardcoded role-slug ladder with triplicated
department checks (AT-08, confirmed). The most consequential attendance-owned findings: a
biometric re-import silently clobbers manual corrections (AT-02), rest day is hardcoded to
Sunday (AT-03), the raw-punch import path is fully built but unrouted (AT-04), and the 4-hour
OT cap exists only in the computation engine while requests/approvals can carry 8h (AT-05).
No Critical findings; hours fed to payroll are correct for the common paths but silently
wrong on several documented edge populations (rest-day, midnight-crossing holiday, stale
rows after late holiday/shift changes — AT-03/AT-07/AT-09).

## Findings

| ID | Category | Sev | Effort | Title | Location | Evidence/Repro |
|----|----------|-----|--------|-------|----------|----------------|
| AT-01 | Broken process (cross-module → payroll) | High | S | ND premium ignores `day_type_rate`; holiday/rest-day night shifts underpaid | api/app/Modules/Payroll/Services/PayrollCalculatorService.php:474 | `ndPay = ndHrs × hourly × 0.10` with no `× rate`; OT on the same row (line ~468) does multiply by `$rate`. DTR contract is fine (supplies both fields); payroll breaks it. |
| AT-02 | Broken process | High | M | Re-import silently overwrites manual corrections | api/app/Modules/Attendance/Services/DTRImportService.php:103-112,281-297 | `openDayRecord()` returns the existing row; import then sets `time_in/time_out`, `is_manual_entry=false` and recomputes. No conflict detection; only the payroll-period lock protects rows. |
| AT-03 | Risk | Medium | M | Rest day hardcoded to Sunday; no setting | api/app/Modules/Attendance/Services/DTRComputationService.php:62 | `$isRestDay = $a->is_rest_day \|\| dayOfWeek === SUNDAY`. No `attendance.rest_day_of_week` setting exists; import never sets `is_rest_day`. Wrong premium (1.00 vs 1.30) whenever the plant rest day is not Sunday or rotates. |
| AT-04 | Gap / Stuck process | Medium | S | Raw-punch import built + tested but unreachable | api/app/Modules/Attendance/Services/DTRImportService.php:141; api/app/Modules/Attendance/routes.php | `importRawPunches()` is referenced nowhere outside its own file and RawPunchImportTest; no route, no SPA call. Operators only get the paired-CSV path. |
| AT-05 | Broken process | Medium | M | 4h OT cap enforced only in computation; requests/approvals carry up to 8h; approved hours decorative | StoreOvertimeRequestRequest.php (max = `attendance.ot.admin_max_hours` = 8.0, migration 0316); DTRComputationService.php:214-220 (clamp to `attendance.ot.maximum_minutes` = 240); OvertimeService.php:95 (auto rows record uncapped hours) | Admin files 8h, approver approves 8h, payroll pays ≤4h from punches. Computation checks only `exists()` of an approved row — `hours_requested` never enters the math, so approval intent and pay can disagree silently. |
| AT-06 | Risk | Medium | S | No (employee_id, date) uniqueness for manual OT requests | api/database/migrations/0025_create_overtime_requests_table.php (index only); 2026_08_13 partial unique covers `is_auto_detected = TRUE` only | HR path + self-service (HR module) can both file for the same employee/day; multiple pending rows can all be approved. Pay is protected by presence-based compute, but records/approvals are misleading; autoDetect also skips when ANY row (incl. rejected) exists. |
| AT-07 | Risk (fragile edge) | Medium | M | Midnight-crossing shifts: holiday/rate attributed to START date only | DTRComputationService.php:60 (`holidays->forDate($date)` with row date = start date) | Night shift 18:00 Apr 8 → 06:00 Apr 9 (Good Friday): entire row pays at Apr 8 rates (1.00); hours actually worked on the holiday get no premium beyond ND. |
| AT-08 | Bad practice | Medium | M | Role-slug ladder + triplicated department scope for OT decisions | api/app/Modules/Attendance/Services/OvertimeDecisionPolicy.php:21-24; OvertimeService.php:175-188; AttendanceService.php:66-81; AttendanceController.php:88-116 | `isAllRecordActor()` hardcodes `['system_admin','hr_officer']`; the "own dept or self" scope is re-expressed in 4 places (policy, OT list, attendance list, show/cancel/authorizeView). Confirms permission-scoping audit flag; violates the project's one-row-scope-per-module rule. New all-record roles need code edits. |
| AT-09 | Risk | Medium | M | Resolved shift pinned onto rows; no recompute sweep → stale derived fields feed payroll | DTRComputationService.php:47-58,398-403; HolidayService.php (no propagation) | (a) `computeForRecord()` persists resolved `shift_id` (incl. default fallback); later assignment corrections never re-resolve on recompute. (b) Assignment gaps silently fall back to the default shift — no flag. (c) Holiday/shift created AFTER import leaves existing rows with `day_type_rate` 1.00 and wrong hours; nothing is scheduled to recompute (no attendance cron exists in api/routes/console.php). |
| AT-10 | Gap | Low | M | Holidays seeded for 2026 only; `is_recurring` stored but never expanded | api/database/seeders/HolidaySeeder.php; HolidayService.php (`forDate` matches literal dates) | From 2027-01-01 every holiday silently pays as an ordinary day unless someone re-enters ~20 rows/year. `is_recurring` is accepted by the form and never used. |
| AT-11 | Bad practice | Low | S | SPA sets `Content-Type: multipart/form-data` explicitly on import | spa/src/api/attendance/attendances.ts:47-53 | Exactly the pitfall CLAUDE.md forbids (strips boundary). Currently neutral: axios ^1.18 XHR adapter drops the header for browser FormData — but it breaks the day the adapter/client changes. |
| AT-12 | Gap | Low | S | Raw-punch re-import appends duplicate `punch:<flag>` remarks | DTRImportService.php:234-236 | Each replay of a `missing_out` day appends another " punch:missing_out" to remarks (latent — endpoint unrouted, AT-04). |

### Detail — High severity

**AT-01 — Night-differential underpaid on premium days (verified flag).** The DTR engine
correctly records `night_diff_hours` and `day_type_rate` independently. Payroll's
`aggregateAttendance()` layers the day-rate onto regular-hour premium and onto OT
(`otHrs × hourly × otPremium × rate`), but computes ND as `ndHrs × hourly × 0.10` with no
`rate` factor (PayrollCalculatorService.php:474). Concrete repro from the passing monster
test: regular-holiday + rest-day night shift, day_type_rate 2.60, 8.0 ND hours — ND is paid
`8 × hourly × 0.10` instead of being based on the 2.60-rated hourly rate. Population: all
night-shift workers on holiday/rest-day schedules; systematic, every such shift. Fix is a
one-line multiplication in payroll, but needs a product decision on whether ND premium
compounds on the day rate (DOLE practice: yes, ND is 10% of the applicable — holiday-rated —
hourly rate). Note: ND hours intentionally include the 30-min break ("per labor rule" per
DTRComputationServiceTest:294) — documented, not flagged.

**AT-02 — Import clobbers corrections.** `openDayRecord()` deliberately reuses the existing
employee-day row (correct for idempotent replays), but the same code path means a routine
weekly biometric drop overwrites any HR correction made since the last import — punch times,
and flips `is_manual_entry` back to false, erasing the provenance marker. The payroll-lock
guard blocks only locked periods. There is no "row has manual correction" refusal/warning and
no dry-run. Normal-use breakage: correct Monday's missing punch on Tuesday, re-drop the same
file Wednesday, correction gone (audit trail survives via HasAuditLog, pay does not).

### Notes on verified-not-broken

- Midnight-crossing punch pairing: `PunchSessionizer` 18h/1-day window with Carbon-3 sign fix
  is correct and tested; night-shift `time_out` advanced a day in compute(); inverted day-shift
  rows refused with an operator-readable message (no SQL leak, rowMessage() sanitizes).
- OT min 30 / max 240 enforced in computation for approved OT; extended-shift auto-OT capped
  at `auto_ot_hours`; extended-shift full 06:00–18:00 = 11.5h regular is the *tested, intended*
  semantics (tests:253-276) — the "auto-OT" is excess *past* 18:00, not hours 8–12. Spec
  ambiguity only, not flagged.
- Grace period, tardiness cap (480), half-day ratio, holiday-not-worked pay rules
  (regular 1.00 / special 0.00) all implemented and tested.
- Sanctum-only auth, HashIDs in all resources, decimals as strings, enums for status/type,
  `DB::transaction()` on all mutations, `FormRequest::authorize()` present (route middleware
  carries the permission gates; `AttendanceController` update/destroy rely on `attendance.edit`
  which only hr_officer/system_admin hold, so the absent row-scope there is not an escalation).
- Import center with mapping UI correctly absent (out of scope per project law).

## Cross-module flags

- **payroll**: AT-01 (fix at PayrollCalculatorService.php:474 or document ND as ordinary-day-only).
  Minor: tardiness/undertime deductions use flat `hourlyRate`, not the `day_type_rate`-rated
  hour, so undertime on a holiday is deducted cheap (same file, lines 480-489) — product call.
- **HR**: self-service OT (`SelfServiceController::applyOvertime`, HR/routes.php:266) creates
  via `OvertimeService::create()` with no same-day duplicate guard — inherits AT-06.
- **dashboard**: PS-02 (permission-scoping audit) already flags OT badge counts ignoring the
  dept row scope; AT-08 confirms the underlying scope is hand-rolled in 4 places, so any fix
  should extract one `OvertimeRowScope` first.
- **no attendance cron exists** in api/routes/console.php — if a scheduled DTR recompute is
  expected by other audits, it does not exist (relates to AT-09).

## What was NOT checked

- Leave module's `markAttendance()` write path (only its `withTrashed()` read contract noted).
- Payroll calculator beyond `aggregateAttendance()` (basic pay, gov tables out of unit).
- SPA e2e rendering of the 9 pages (static read only; all four list pages reference
  loading/error/empty/skeleton states; index.test.tsx exists and passes in suite).
- Biometric device timezone semantics — import assumes timestamps are already local; no
  conversion exists (by design, not verified against real device exports).
- Correctness of the 2026 PH holiday list vs official proclamations; OvertimeRequestFactory;
  self-service overtime SPA page; CSV performance near the 5 MB cap.

## Code-reading re-audit — 2026-09-14 (56e0d431)

### Scope and verdict

Read-only source re-audit of `api/app/Modules/Attendance/**`,
`spa/src/pages/attendance/**`, `spa/src/api/attendance/**`,
`spa/src/types/attendance.ts`, the attendance migrations/seed data, payroll and
HR self-service consumers, attendance routes, SPA routes, and directly relevant
tests. The requested project docs and the prior attendance audit were read in
full. Current commit verified as `56e0d431e41d74d422ad81684ff50e0b2b49960e`.

AT-01 and AT-02 are resolved by current source inspection. AT-03 through AT-12
remain materially unresolved. Six new findings are recorded below. The most
serious current defect is the normal paired-CSV/manual-entry path losing
overnight overtime for night-shift workers; the DTR arithmetic is correct only
because it temporarily advances the punch, while the persisted punch used by
auto-detection remains on the start date.

### Prior finding status

| ID | Status at current commit | Severity | Effort | Current evidence / reproduction |
|----|--------------------------|----------|--------|----------------------------------|
| AT-01 | Resolved in source; runtime not rerun | High | S | `api/app/Modules/Payroll/Services/PayrollCalculatorService.php:477-483` now multiplies ND by `day_type_rate`; the regression matrix remains at `api/tests/Feature/Payroll/PayrollCalculatorServiceTest.php:812-858`, including rate `2.60`. |
| AT-02 | Resolved in source and directly covered | High | M | `api/app/Modules/Attendance/Services/DTRImportService.php:374-387` skips any changed manual row and permits only an exact replay; paired and raw paths both call it at lines 108 and 257. `api/tests/Feature/Attendance/ImportManualCorrectionGuardTest.php:90-249` covers overwrite, fill, exact replay, and both import paths. |
| AT-03 | Confirmed unresolved | Medium | M | `api/app/Modules/Attendance/Services/DTRComputationService.php:60-63` still derives rest day from an explicit flag or Sunday. There is no configured plant rest-day calendar, and the import path does not set the flag. A non-Sunday fixed or rotating rest day therefore receives ordinary-day treatment. |
| AT-04 | Confirmed unresolved | Medium | S | `DTRImportService::importRawPunches()` remains at `api/app/Modules/Attendance/Services/DTRImportService.php:166` with no route or controller call; `api/app/Modules/Attendance/routes.php:40` exposes only the paired import. Existing raw-punch tests invoke the service directly, so the implemented path is still unreachable to operators. |
| AT-05 | Confirmed unresolved | Medium | M | `api/app/Modules/Attendance/Requests/StoreOvertimeRequestRequest.php:28-37` exposes the admin maximum of 8 hours; `DTRComputationService.php:214-220` caps paid approved OT at 240 minutes, while `OvertimeService.php:95-115` can persist an uncapped auto-detected request. Approval records 8 hours but payroll uses approved-row presence plus punch-derived, capped hours, so approval intent and pay remain divergent. |
| AT-06 | Confirmed unresolved | Medium | S | `api/database/migrations/2026_08_13_100000_add_auto_overtime_source_unique.php:29-49` protects only `is_auto_detected = true`. `OvertimeService::create()` at `api/app/Modules/Attendance/Services/OvertimeService.php:193-203` has no employee/date duplicate guard or manual uniqueness backstop; separate pending manual requests can still be filed and approved for one day. |
| AT-07 | Confirmed unresolved | Medium | M | `api/app/Modules/Attendance/Services/DTRComputationService.php:60-62` looks up one holiday using the row start date, and `compute()` applies that one holiday to the full interval. A 18:00-to-06:00 row crossing into a holiday still assigns the whole row the start-date rate. |
| AT-08 | Confirmed unresolved | Medium | M | `api/app/Modules/Attendance/Services/OvertimeDecisionPolicy.php:21-24` still hardcodes `system_admin` and `hr_officer`; department visibility is re-expressed in `AttendanceService.php:64-80`, `OvertimeService.php:173-187`, `AttendanceController.php:81-106`, and `OvertimeController.php:45-61,96-113`. Adding a legitimate all-record role or changing row policy still requires multiple code edits. |
| AT-09 | Confirmed unresolved | Medium | M | `DTRComputationService.php:47-58` pins a resolved shift but does not re-resolve a previously pinned row. `ShiftAssignmentService.php:61-72` can return null for a soft-deleted assigned shift; DTR then falls back to the default while retaining the stale `shift_id`. Holiday/assignment changes still have no attendance recompute sweep, and `api/routes/console.php` has no attendance recompute schedule. |
| AT-10 | Confirmed unresolved | Low | M | `api/database/seeders/HolidaySeeder.php:14-41` seeds only 2026 and sets `is_recurring` false. `HolidayService::forDate()` at `api/app/Modules/Attendance/Services/HolidayService.php:78-104` performs literal year/date lookup and never expands recurring rows. A future year has no holiday pay rules unless manually populated. |
| AT-11 | Confirmed unresolved | Low | S | `spa/src/api/attendance/attendances.ts:50-55` still sets `Content-Type: multipart/form-data` manually, contrary to the project client rule. The browser may currently repair the boundary, but the request is adapter-dependent and can fail when the client/adapter changes. |
| AT-12 | Confirmed unresolved | Low | S | `api/app/Modules/Attendance/Services/DTRImportService.php:264-266` always appends `punch:<flag>` to existing remarks. Replaying a raw-punch file for a flagged day therefore accumulates duplicate remarks; the raw endpoint is currently unrouted, but the defect remains in the implemented service. |

### New findings

#### AR-01 - Night-shift overtime disappears on paired/manual overnight punches

**Category:** Broken process
**Severity:** High
**Effort:** M
**Location:** `api/app/Modules/Attendance/Requests/StoreAttendanceRequest.php:53-56`; `api/app/Modules/Attendance/Requests/UpdateAttendanceRequest.php:37-42`; `api/app/Modules/Attendance/Services/DTRImportService.php:99-103`; `api/app/Modules/Attendance/Services/DTRComputationService.php:176-182`; `api/app/Modules/Attendance/Services/OvertimeService.php:81-90`

**Evidence/reproduction:** For a 18:00-06:00 night shift on attendance date
`2026-06-15`, submit `time_in=18:00` and `time_out=07:00` through the manual
form or paired CSV. The request/import code persists both timestamps on
`2026-06-15`. `DTRComputationService::compute()` temporarily adds one day to
the local `$timeOut` at lines 179-182, so the stored DTR hours look correct,
but it does not write that advanced timestamp back to the model. The subsequent
`AttendanceService::create()`/import path calls auto-detection after save; the
detector builds shift end as `2026-06-16 06:00` and compares it to the stored
`2026-06-15 07:00`, producing zero extra minutes. No auto OT request is created;
absent a separate manual OT approval, the row remains at zero OT and payroll
misses the hour. A manually approved request can trigger a later recompute, but
auto-detection itself cannot discover the overnight excess. The raw-punch path
can avoid this only when it is used, and AT-04 shows that path is not routed.

#### AR-02 - OT decision notifications link to a nonexistent SPA route

**Category:** Stuck process
**Severity:** Medium
**Effort:** S
**Location:** `api/app/Modules/Attendance/Listeners/NotifyOnOvertimeDecided.php:26-34`; `spa/src/routes/selfServiceRoutes.tsx:29-33`

**Evidence/reproduction:** Every approved or rejected OT decision sends
`/self-service/overtime/{hash_id}` as `link_to`, but the SPA registers only
`/self-service/overtime`, with no `:id` route. Clicking the notification takes
the employee to the catch-all/404 instead of the request. Cancellation is also
published through `OvertimeRequestDecided(..., false)` at
`api/app/Modules/Attendance/Services/OvertimeService.php:342-351`, so a
withdrawal is presented to the employee as a rejected decision by the listener
rather than as a cancellation.

#### AR-03 - Bulk attendance responses expose sequential integer IDs

**Category:** Bad practice
**Severity:** Medium
**Effort:** S
**Location:** `api/app/Modules/Attendance/Controllers/ShiftController.php:66-75`; `api/app/Modules/Attendance/Services/ShiftAssignmentService.php:21-40`; `api/app/Modules/Attendance/Controllers/OvertimeController.php:133-158`; `api/app/Modules/Attendance/Services/OvertimeService.php:249-274`; `spa/src/api/attendance/shifts.ts:16-19`; `spa/src/api/attendance/overtime.ts:40-48`

**Evidence/reproduction:** A successful bulk shift assignment returns
`shift_id` and `department_id` from the service's raw integer result. A partial
bulk OT approval returns each failed request's raw integer `id`; the SPA type
also declares that field as `number`. These are API responses, not only
internal service values, and violate the project HashID contract. The leak does
not itself authorize access, but it exposes sequential primary keys and makes
the attendance API inconsistent with the hashed IDs used by the same endpoints'
request payloads and resources.

#### AR-04 - Configurable shift grace periods are not manageable in the SPA

**Category:** Gap
**Severity:** Medium
**Effort:** S
**Location:** `api/app/Modules/Attendance/Requests/StoreShiftRequest.php:23-35`; `api/app/Modules/Attendance/Requests/UpdateShiftRequest.php:24-37`; `api/app/Modules/Attendance/Resources/ShiftResource.php:17-25`; `spa/src/types/attendance.ts:3-30`; `spa/src/pages/attendance/shifts/index.tsx:284-307`

**Evidence/reproduction:** The backend accepts, stores, and returns
`grace_minutes`, and DTR computation consumes it at
`api/app/Modules/Attendance/Services/DTRComputationService.php:77-83`. The
attendance `Shift` and create/update DTO types omit the field, and the shift
modal has no grace-period input. An operator cannot configure the labor rule
from the supported shift-management page; new shifts use the backend default,
and existing configured grace values cannot be changed from the UI.

#### AR-05 - Philippine-local date defaults are derived from UTC

**Category:** Broken process
**Severity:** Medium
**Effort:** S
**Location:** `spa/src/pages/attendance/index.tsx:36-40`; `spa/src/pages/attendance/shifts/assign.tsx:40-46`

**Evidence/reproduction:** Both pages use `new Date().toISOString()` to seed
calendar dates. In the plant's Philippine timezone, between local midnight and
08:00 the ISO date is still the previous UTC date. The attendance page then
defaults its list range and manual-DTR date to yesterday, while bulk shift
assignment defaults its effective date to yesterday. The code can also remain
stale after a long-lived page crosses local midnight. The overtime form already
has a separate local-date helper, demonstrating that the attendance surfaces do
not share one safe date primitive.

#### AR-06 - Department-head OT list advertises a create action that its route denies

**Category:** Stuck process
**Severity:** Medium
**Effort:** S
**Location:** `spa/src/pages/attendance/overtime/index.tsx:203-205`; `spa/src/routes/hrRoutes.tsx:125-130`; `api/app/Modules/Attendance/routes.php:43-44`; `api/database/seeders/RolePermissionSeeder.php:873-882`

**Evidence/reproduction:** The department-head role has
`attendance.ot.approve` but not `attendance.edit` or
`attendance.ot.create`. That role passes the SPA guard for
`/hr/attendance/overtime`, so the list always renders `New OT request`, but
clicking it enters `/hr/attendance/overtime/create`, whose guard requires
`attendance.edit`. The corresponding POST endpoint independently requires
`attendance.ot.create`. The operator is shown an action that terminates at a
403/permission screen; the button must be permission-derived from the actual
create route or the role must be intentionally granted the create capability.

### Clean areas observed

- Attendance, shift, holiday, and overtime resources expose HashIDs rather than integer primary keys, and attendance/overtime numeric decimal fields are serialized as strings.
- The attendance mutation paths and import day writes use `DB::transaction()`, and `AttendanceDateMutabilityGuard` locks overlapping payroll periods before writes.
- Archived attendance rows are deliberately not resurrected by import; the current source returns an actionable business message instead of publishing SQL internals.
- Manual-correction protection now covers overwrite, field fill, removal, exact replay, paired CSV, and raw-punch paths.
- The pure DTR engine has broad existing unit coverage for holiday/rest-day rates, night bands, cross-midnight arithmetic, tardiness, undertime, and OT caps; this was inspected but not rerun.
- Active holiday-date uniqueness, one-default-shift uniqueness, and auto-OT source uniqueness have database backstops, with source-level tests for their main service paths.
- The attendance list/import/overtime/shift/holiday pages statically include loading, error, empty, and stale-data handling; no browser rendering claim is made here.

### Verification limits

No tests, Docker commands, Artisan commands, browser runs, migrations, or live
database checks were run, per request. Findings are based on source, route,
schema/migration, seed, and existing-test reading at the stated commit. Queue
delivery, timezone behavior in a real browser, database concurrency, current
permissions after seeding, and payroll results were not runtime-verified. The
existing test files were inspected but their prior reported results were not
independently reproduced.

### No code change

No application code, migration, test, registry, roadmap, or pre-existing audit
content was modified. Only this dated section was appended to
`audit/attendance.md`.
