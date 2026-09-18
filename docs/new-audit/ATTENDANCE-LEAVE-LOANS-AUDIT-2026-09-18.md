# Attendance + Leave + Loans Audit

Date: 2026-09-18
Scope: `api/app/Modules/{Attendance,Leave,Loans}`. DTR computation/import, OT, leave requests,
and loan amortization were partly covered by the H2R payroll trace; this adds full routes and new
findings. Claims are **[confirmed]** (file:line/grep) or **[assumption/unverified]**.

---

## 1. Attendance

1. **Raw-punch import is dead code.** `DTRImportService::importRawPunches()` and `PunchSessionizer`
   are only called by tests; the sole route calls the paired-CSV `import()`. The `direction` CSV
   column is parsed but never used. **[confirmed]**
2. **`overtime_request` workflow is dead config.** Seeded (`WorkflowSeeder:32-38`) but
   `OvertimeService` never calls `ApprovalService`; OT is decided by `OvertimeDecisionPolicy`. The
   drift test even lists it as an act-route chain, so it passes while unused.
3. **Voided payroll period permanently locks attendance.** `AttendanceDateMutabilityGuard` treats
   `Voided` as locked while its own message says "Void or correct the payroll period" — the
   recommended recovery is impossible without deleting the period.
4. **Rejected auto-detected OT blocks re-detection.** The existing-row check matches any status, so
   a rejected/cancelled auto-OT is never re-created even if punches change.
5. **Two OT max settings** (`attendance.ot.admin_max_hours` vs `request_max_hours`) with colliding
   SPA labels; `shiftAnchor()` ignores its `$isNight` parameter; `OvertimeRequest.status` is
   fillable (breaks the hardening convention); `HolidayService` re-queries the row per call.

## 2. Leave

6. **Year-end runs late by design.** Only the Jan 1–7 recovery schedule exists
   (`leave:process-year-end --previous-year` 00:00 + `hr:reset-leave-balances` 00:30); the job
   docblock's "Dec-31 23:00 run" does not exist. **[confirmed]**
7. **Cancelled pending leave leaves a live approval card.** `cancel()` sets `cancelled` without
   touching `approval_records`; `ApprovalService` has no withdraw, and the approval board filters
   only `action=pending,is_current` with no source-status predicate — the card persists and every
   approve 422s. No `LeaveRequestCancelled` event exists. **[confirmed]**
8. **`LeaveBalanceService::seedFor()` is dead** (only `seedProratedFor` is wired).
9. **Leave days exclude only Sunday** — public holidays are counted as leave days.
10. `LeaveHalfDayPeriod::FullDay = 'none'` is never produced by validation but any non-null value is
    treated as half-day; `ProcessYearEndLeave` doesn't record `days_carried` per type.

## 3. Loans

11. **`is_final_pay_deduction` is inert.** Payroll reads it to exclude reserved loans, but no
    production code ever sets it true; `FinalPayService` settles via `recordPayment` without
    reserving. The gate is a no-op. **[confirmed]**
12. **Cancelled pending loan leaves a live approval card** (same mechanism as leave).
13. **Government loans are unreachable but dangerous.** `sss_loan`/`pagibig_loan` exist in the enum
    and have settings, but no active workflow is seeded so `types()` filters them out and `request()`
    throws. `StoreLoanRequest` still accepts them; if a workflow is ever seeded they activate at
    10–10.5% interest — contradicting the "Loans: zero interest" rule. **[confirmed]**
14. **`government_reference_no` is dead** (fillable/migration, never set/read/exposed).
15. **A borrower cannot withdraw a pending loan** (module cancel needs `loans.write_off`; self-service
    has no cancel).
16. `pay_periods_*` counts monthly schedule rows while payroll deducts at half per semi-monthly
    period — tenure is right but the employee-facing name is misleading.

## 4. Cross-module / dead config

- Approved OT recomputes attendance; rejected/cancelled OT do not (and never contributed) — correct.
- Approved leave writes `on_leave` with a reversible snapshot and goes through the mutability guard
  (fail-closed on a payroll-locked day) — correct.
- Dead: `importRawPunches`/`PunchSessionizer`, `LeaveBalanceService::seedFor`, `overtime_request`
  workflow, `is_final_pay_deduction`, `government_reference_no`, `sss_loan`/`pagibig_loan`,
  `LeaveHalfDayPeriod::FullDay`, `shiftAnchor($isNight)`, CSV `direction`.

## 5. Assumptions

- A1. No test run; source/grep. A2. Government-loan interest intent from CLAUDE.md. A3. Holiday/Sunday
  leave-day counting treated against the attendance holiday module, not a business spec.
