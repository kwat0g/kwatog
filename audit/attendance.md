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
