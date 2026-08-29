# M018 — Attendance & DTR re-audit report

Audit date: 2026-08-27
Claim: people / attendance-dtr (M018)
Registry tier: 4
Status: 📋 Plan Ready
Session recommendation: separate-recommended

## Verdict

The previous hardening work is present in the current tree: OT decision policy, payroll-date mutability checks, soft-delete restore binding, the correction modal, assignment overlap handling, the active-holiday invariant, and merged shift-time validation are all implemented. Focused backend and SPA checks pass against the agent-owned database.

M018 is not ready for Verified status. The re-audit found a payroll-lock scope-drift gap, raw exception leakage from bulk OT approval, an unreachable raw-punch capability, and correction/holiday/shift edge cases. The findings span payroll integrity, API security, product behavior, and missing concurrency/browser evidence; the gate therefore requires a separate hardening session and no production-code fix was applied here.

## Evidence checked

- Refreshed `audit/00-MODULE-REGISTRY.md`, atomically claimed `people/attendance-dtr`, and verified the M018 lock, target docs, implementation, tests, current diff, and mtimes. The only pre-existing uncommitted change is the coordinator's generated registry timestamp; it was not edited.
- Reviewed Attendance routes/controllers/requests/resources/models/services, migrations, tests, SPA attendance/import/OT/shift/holiday/self-service surfaces, and relevant Payroll/HR/Auth dependencies for context only.
- Created and used only `DB_DATABASE=ogami_test_m018_agent_d`; no `ogami_test` database was used.
- Backend `tests/Feature/Attendance`: **33 tests, 113 assertions — OK**.
- Backend `tests/Unit/DTRComputationServiceTest.php` and `tests/Unit/AutoDetectOvertimeTest.php`: **32 tests, 60 assertions — OK**.
- `tests/Feature/Notifications/OvertimeNotificationTest.php`: **4 tests, 5 assertions — OK**.
- Focused PHP syntax checks: passed. `git diff --check` on M018 files: clean.
- SPA targeted ESLint for attendance sources with `--max-warnings 0`: passed. SPA `npm run typecheck`: passed. Vitest found no attendance-page test files, so it provided no page behavior coverage.
- No production-code changes were made during this session; no fix-log entry was required.

## Remediated prior findings

The earlier F01–F07 findings are not carried forward as current defects: the locked OT decision policy is in `api/app/Modules/Attendance/Services/OvertimeDecisionPolicy.php:21-58`; the shared attendance write fence is called by create/update/delete/restore/recompute and both import paths (`api/app/Modules/Attendance/Services/AttendanceDateMutabilityGuard.php:22-57`, `api/app/Modules/Attendance/Services/AttendanceService.php:92-156`, `api/app/Modules/Attendance/Services/DTRImportService.php:99-113,229-246`); restore routes opt into trashed binding (`api/app/Modules/Attendance/routes.php:18,30,39`); the correction UI is present (`spa/src/pages/attendance/index.tsx:60-137`); assignment replacement rejects future overlaps (`api/app/Modules/Attendance/Services/ShiftAssignmentService.php:75-113`); the active-date holiday index is present (`api/database/migrations/2026_08_25_210000_enforce_one_active_holiday_per_date.php:29-55`); and shift updates validate merged times (`api/app/Modules/Attendance/Services/ShiftService.php:39-58`). The focused tests above verify the available regression coverage, but do not replace the missing concurrency/browser checks below.

## Findings

### M018-F08 — Missing: raw-punch import has no reachable product path

Priority: **P2**
Scope: **medium**
Recommendation: **separate-recommended**

`DTRImportService` implements raw biometric event parsing and sessionization (`api/app/Modules/Attendance/Services/DTRImportService.php:125-262`), but the only HTTP import action calls the paired-row importer (`api/app/Modules/Attendance/Controllers/AttendanceController.php:74-78`). The SPA upload page documents and submits only `employee_no, date, time_in, time_out` (`spa/src/pages/attendance/import.tsx:44-49,79-102`). There is no request mode, route, or UI path for `importRawPunches()`.

Action: decide whether raw biometric support is contractual. If yes, expose an explicit format/mode with row-level results, payroll-lock behavior, and browser coverage; otherwise label the service internal and remove any product implication that raw events are supported.

### M018-F09 — Incomplete: high-risk authorization and lifecycle paths lack negative/concurrency coverage

Priority: **P1**
Scope: **large**
Recommendation: **separate-recommended**

The current focused suites execute successfully, but the repository still lacks executable coverage for cross-department approve/reject/bulk denial, employee-scope changes after payroll computation, all archive/restore flows, archived-shift assignment, and concurrent payroll/attendance writes. The SPA has no attendance correction/restore browser test, and Vitest reports no page tests for `spa/src/pages/attendance`.

Action: add focused negative feature tests and database contention tests, then run an authenticated browser smoke path covering attendance correction, restore, OT decisions, import errors, and locked-period messaging.

### M018-F10 — Broken: payroll mutability guard authorizes against live employee scope instead of frozen payroll membership

Priority: **P0**
Scope: **large**
Recommendation: **separate-recommended**

The guard locks overlapping periods and blocks finalized, disbursed, and voided periods, but decides applicability only from the employee's current department, employment type, and pay type (`api/app/Modules/Attendance/Services/AttendanceDateMutabilityGuard.php:31-46,61-86`). Payroll computes the scoped employee set using the same attributes as of the period end (`api/app/Modules/Payroll/Services/PayrollPeriodService.php:523-551`) and persists an employee/cycle claim inside the payroll transaction (`api/app/Modules/Payroll/Services/PayrollCalculatorService.php:291-303`; `api/app/Modules/Payroll/Models/PayrollCycleClaim.php:12-18`).

For a scoped period, if an employee is paid and the employee is later moved to another department, pay type, or employment type, the live-attribute check can return false even though a payroll row/claim for that employee and cycle exists. Attendance for the locked date can then be edited, deleted, restored, or recomputed without the intended payroll fence. This is a payroll-integrity bypass, not merely a stale-list issue.

Action: make the write fence consult immutable payroll membership/employee claims (or persist a period membership snapshot) in addition to the period lock; test a paid employee moved out of scope, a moved-in employee, and a company-wide period inside the same transaction fence.

### M018-F11 — Broken: bulk OT approval returns raw exception messages to the client

Priority: **P1**
Scope: **small**
Recommendation: **separate-recommended**

`bulkApprove()` catches every `Throwable` and places `$e->getMessage()` directly into the failed response (`api/app/Modules/Attendance/Services/OvertimeService.php:256-274`). The controller serializes those failure reasons without sanitization (`api/app/Modules/Attendance/Controllers/OvertimeController.php:141-158`). A database/query or unexpected runtime failure can therefore expose SQL, table names, or internal infrastructure details to the caller; only expected business-rule failures should become user-facing row reasons.

Action: distinguish expected domain/validation failures from unexpected exceptions, return a stable safe message/code for the latter, and log the exception with the request/OT ID. Add a regression test that injects an unexpected exception and asserts no raw message reaches JSON.

### M018-F12 — Incomplete: shift assignment accepts archived or inactive shift IDs

Priority: **P2**
Scope: **small**
Recommendation: **separate-recommended**

The assignment requests only decode the submitted hash (`api/app/Modules/Attendance/Requests/AssignEmployeeShiftRequest.php:18-33`; `api/app/Modules/Attendance/Requests/BulkAssignShiftRequest.php:18-37`), and the service passes the resulting integer straight into the assignment row (`api/app/Modules/Attendance/Services/ShiftAssignmentService.php:23-40,47-54,107-113`). `Shift` is soft-deletable (`api/app/Modules/Attendance/Models/Shift.php:14-16`), while `current()` resolves the assignment through a normal relation and can return null when the assigned shift is archived (`api/app/Modules/Attendance/Services/ShiftAssignmentService.php:61-72`).

A caller with a known old shift hash can create a valid-FK assignment to a trashed shift; DTR then loses the assigned shift and may fall back to another schedule. The same request path also permits a new assignment to an inactive shift without an explicit policy decision.

Action: resolve the shift inside the write transaction, reject trashed IDs, and define whether inactive shifts may be newly assigned. Add single/bulk tests for missing, trashed, inactive, and active shifts.

### M018-F13 — Incomplete: correction form cannot clear an existing punch or shift

Priority: **P1**
Scope: **small**
Recommendation: **same-session-ok**

The correction form maps empty controls to `undefined` for shift, time-in, and time-out (`spa/src/pages/attendance/index.tsx:99-117`). The API type also models those fields as optional strings rather than nullable values (`spa/src/api/attendance/attendances.ts:13-23`), while the backend explicitly supports `sometimes|nullable` for all three (`api/app/Modules/Attendance/Requests/UpdateAttendanceRequest.php:18-24`). JSON serialization omits `undefined`, so an edit that clears a bad punch or removes a shift sends no field and leaves the old value in place.

Action: model update fields as `string | null`, send null for an explicit clear, and add a UI/API regression test for clearing time-in, time-out, and shift.

### M018-F14 — Incomplete: attendance date validation accepts values that are then concatenated as dates

Priority: **P2**
Scope: **small**
Recommendation: **same-session-ok**

Create validation uses the broad `date` rule (`api/app/Modules/Attendance/Requests/StoreAttendanceRequest.php:18-28`), then concatenates the raw value with an `H:i` time (`api/app/Modules/Attendance/Requests/StoreAttendanceRequest.php:40-43`). A valid datetime string can pass the rule and produce an invalid compound value such as `2026-04-15 12:00:00 08:00:00`, causing a parse failure or ambiguous timestamp instead of a field validation response.

Action: require `date_format:Y-m-d` for attendance date inputs or normalize to a date before combining times; add a malformed/date-time input regression test.

### M018-F15 — Missing: recurring holidays are stored but ignored outside the source year

Priority: **P1**
Scope: **medium**
Recommendation: **separate-recommended**

The API and UI expose `is_recurring` and label it “Recurs annually” (`api/app/Modules/Attendance/Requests/StoreHolidayRequest.php:25-32`; `spa/src/pages/attendance/holidays/index.tsx:337-364`). DTR lookup loads only rows whose stored date is in the requested year and keys them by the exact date (`api/app/Modules/Attendance/Services/HolidayService.php:78-104`). A recurring holiday created for 2026 therefore does not affect the same month/day in 2027, so holiday pay/rates silently disappear after one year.

Action: define recurrence semantics, including leap-day behavior, then expand recurring records during lookup or materialize yearly instances. Add DTR tests across years and cache invalidation tests.

### M018-F16 — Polish: cancellation notification is presented as a rejection

Priority: **P2**
Scope: **small**
Recommendation: **same-session-ok**

Self-service and approver cancellation set the request to the rejected enum and emit `OvertimeRequestDecided(..., false)` (`api/app/Modules/Attendance/Services/OvertimeService.php:320-352`). The queued listener maps every false decision to “Rejected” and the `attendance.ot_rejected` notification type (`api/app/Modules/Attendance/Listeners/NotifyOnOvertimeDecided.php:16-34`). An employee who withdraws a request consequently receives a rejection notice rather than a cancellation/withdrawal notice.

Action: carry an explicit decision reason/type in the event or emit a cancellation event, then add notification assertions for approved, rejected, and cancelled requests.

### M018-F17 — Incomplete: zero-minute auto-OT threshold creates zero-hour requests

Priority: **P2**
Scope: **small**
Recommendation: **same-session-ok**

The auto-OT setting accepts a threshold of zero (`api/app/Modules/Attendance/Services/OvertimeService.php:56-60`). The detector only skips when `$extra < $threshold` and then rounds/creates the request (`api/app/Modules/Attendance/Services/OvertimeService.php:87-116`). With threshold `0`, an attendance ending exactly at shift end satisfies the condition and can create a `0.0`-hour pending OT request.

Action: require positive extra minutes before creating an OT row, or enforce a strictly positive setting and document the behavior; add the exact-shift-end regression test.

## Polish pass

- Attendance list and import have loading, error, empty, retry, and result states (`spa/src/pages/attendance/index.tsx:139-149`; `spa/src/pages/attendance/import.tsx:106-133`).
- HR OT action errors are generally reduced to safe generic copy (`spa/src/pages/attendance/overtime/index.tsx:55-74`), but F11 must first establish the backend's safe error contract.
- The HR OT list renders “New OT request” without a permission guard (`spa/src/pages/attendance/overtime/index.tsx:190-205`). Department-head users have approval visibility but not the create/edit permission in the seeded role map (`api/database/seeders/RolePermissionSeeder.php:89-100,670-688`), so the button can navigate them to an unauthorized form. This is low-risk UI polish and should be addressed with the OT permission work.

## Gate decision

No fix was applied. Only four of the ten ordered actions are `same-session-ok`, while the remaining actions require payroll/security/product decisions or larger regression work; the total scope is not small and the same-session-ok actions are not a majority.

## Next action

Keep M018 at 📋 Plan Ready. In a separate hardening session, first close the payroll membership fence and bulk-error disclosure, then decide the raw/recurring product contracts and add the negative/concurrency/browser evidence. The small correction/date/notification/threshold fixes can follow in the same or a later focused session after those contracts are settled.

---

# M018 — Attendance & DTR re-audit (2026-08-30)

Audit date: 2026-08-30
Claim: `audit/scripts/claim-module.sh people attendance-dtr` → **CLAIMED**
Registry tier: 4 · Prior status: 🔁 Needs Re-audit
Released as: 🔁 Needs Re-audit
Overall recommendation: **separate-recommended**

## Verdict

The 2026-08-27 plan is still substantially open. Nine of its ten items were
re-tested **by execution**; eight still reproduce, one (F13) is fixed and
committed as `7311f052`. This session additionally found eight defects the prior
audits missed, two of which change money — including a business rule from
CLAUDE.md that the engine does not implement at all and that an existing test
pins the wrong way round.

Three contained fixes were applied (one theme: an unhandled fault reaching the
client instead of an actionable message). Everything touching pay, state
machines, permissions or product contracts is deferred with a plan.

## What was measured, and how

- Private database `ogami_test_dtr` (`CREATE DATABASE ogami_test_dtr OWNER ogami`).
  `ogami_test` was never used. Only `db` and `redis` were running and neither was
  restarted; the API ran via `docker compose run --rm api`.
- A temporary probe suite was written that **passes when the defect is present**,
  so the run output is the evidence rather than a claim. It was deleted after the
  measurements were taken; every number below is quoted verbatim from its run.
- Live schema was inspected directly (`psql \d attendances`), and live
  `role_permissions` / `settings` rows were queried rather than inferred from
  seeder source.
- Final suite: **75 tests, 199 assertions, 0 failures** across
  `tests/Feature/Attendance`, `tests/Unit/DTRComputationServiceTest.php`,
  `tests/Unit/AutoDetectOvertimeTest.php` and
  `tests/Feature/Notifications/OvertimeNotificationTest.php`.
  PHPStan on `app/Modules/Attendance`: **[OK] No errors** (43 files).

### Not verified — stated plainly

- **No browser/Playwright run.** The SPA pass below is source-level only
  (file:line reads plus `npm run typecheck` / targeted ESLint). Pixel rendering,
  focus order and the correction modal's live behaviour remain unproven, as in
  the two prior sessions.
- **No two-connection contention test.** The row locks in
  `AttendanceDateMutabilityGuard`, `OvertimeService` and `ShiftAssignmentService`
  are still only exercised single-threaded. F09 stays open on that count.
- **The `auth:edge_device` / `EdgeSystemUserResolver` hazard could not be
  verified because it does not exist in this repo.** `config/auth.php:9-25`
  declares only `web`, `supplier_portal` and `customer_portal`; there is no
  `App\Modules\Edge` namespace and `grep -rln EdgeSystemUserResolver api/app`
  returns nothing. No attendance write path runs under a non-web guard, so the
  `HasAuditLog` FK hazard described in CLAUDE.md has no live surface in this
  module. This is a note for the coordinator: that CLAUDE.md section references
  classes that are absent from the tree.

## Status of the prior plan, re-tested

| Prior finding | Re-test | Evidence |
|---|---|---|
| **F10** payroll membership / scope drift | **REPRODUCES** | probe 8, below |
| **F11** bulk-OT raw exception disclosure | **REPRODUCES** | probe 9, below |
| **F12** archived / inactive shift assignable | **REPRODUCES** | probes 4, 4b |
| **F13** nullable correction fields | **FIXED** in `7311f052`; `spa/src/api/attendance/attendances.ts` models the three fields `string \| null`, `spa/src/pages/attendance/index.test.tsx` asserts the JSON carries all three keys as `null`. Not re-opened. |
| **F14** loose attendance date rule | **REPRODUCED, then FIXED this session** | probe 7 |
| **F15** recurring holidays ignored outside their year | **REPRODUCES** | probe 3 |
| **F16** cancellation presented as rejection | **REPRODUCES** (source-confirmed; deeper than wording — see below) |
| **F17** zero-minute auto-OT threshold | **Open but unreachable today.** `attendance.auto_ot_detect.threshold_minutes` is live-seeded to `30`, so the `$extra < $threshold` guard at `OvertimeService.php:91` cannot admit a zero-minute request unless an admin sets the setting to 0. Genuine guard gap, P3. |
| **F08** raw-punch product surface | **Still unreachable.** `importRawPunches()` has no route, no controller action and no SPA affordance; `spa/src/pages/attendance/import.tsx:46` documents only `employee_no, date, time_in, time_out` and `ImportAttendanceRequest.php:18-20` accepts no `mode`. Still a product decision. |
| **F09** negative / concurrency / browser coverage | **Partially closed.** Backend coverage now runs (75 green). Contention and browser evidence still absent. |

## Findings — new this session

### M018-F18 — Broken (P1): an archived attendance day breaks every re-write of that employee-day and publishes the SQL statement

**FIXED this session.**

`\d attendances` on the live database:

```text
"attendances_employee_id_date_unique" UNIQUE CONSTRAINT, btree (employee_id, date)
"attendances_deleted_at_index" btree (deleted_at)
```

The constraint is plain, **not** partial on `deleted_at IS NULL`, even though
`0444_add_soft_deletes_to_all_tables.php:26` added `deleted_at` to this table. An
archived row therefore still owns that employee-day, while the default Eloquent
scope hides it from every reader — so
`api/app/Modules/Attendance/Services/DTRImportService.php:101-106,231-236` (before
the fix) saw nothing, built a fresh row, and the INSERT died with SQLSTATE 23505.
Both per-row catches then put `$e->getMessage()` into the response.

```text
[PROBE 1] re-import after archive:
{"total":1,"imported":0,"skipped":1,"errors":[{"row":2,"message":
"SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique
constraint \"attendances_employee_id_date_unique\"\nDETAIL:  Key (employee_id, date)=
(1, 2026-04-15) already exists. (Connection: pgsql, Host: db, Port: 5432, Database:
ogami_test_dtr, SQL: insert into \"attendances\" (\"employee_id\", \"date\",
\"time_in\", \"time_out\", \"is_manual_entry\", \"shift_id\", \"regular_hours\", ...)
values (1, 2026-04-15 00:00:00, 2026-04-15 06:05:00, ...) returning \"id\")"}]}

[PROBE 1b] Illuminate\Database\UniqueConstraintViolationException: SQLSTATE[23505] ...
```

Two defects in one: the biometric re-import of any archived day fails with an
opaque `skipped`, and the client receives the whole INSERT statement with table,
column list, bound values, host, port and database name. The manual create path
(`AttendanceService.php:96` before the fix) raised
`UniqueConstraintViolationException`, unmapped, so a 500.

Archiving is an action the SPA exposes on the attendance list — added by M018-F04
in this same module — so this is an ordinary operator sequence. The repo already
treats this class of leak as a defect: commit `9fde7dfb` is titled *"a SQL fault
was reaching the browser as a 422, with the statement in it."* And the sibling
writer to this table already gets it right:
`api/app/Modules/Leave/Services/LeaveRequestService.php:513` reads it through
`Attendance::withTrashed()`.

### M018-F19 — Broken (P1, money): extended-shift auto-OT ignores both the 4-hour maximum and the 30-minute minimum

`api/app/Modules/Attendance/Services/DTRComputationService.php:210-213`:

```php
if ($shift['is_extended']) {
    $autoOtHours = $shift['auto_ot_hours'] ?? 0.0;
    $autoOtMin   = (int) round($autoOtHours * 60);
    $otMin = min($excess, $autoOtMin);
}
```

The cap is `auto_ot_hours` alone. It never consults
`attendance.ot.maximum_minutes` (live value **240**) or
`attendance.ot.minimum_minutes` (live value **30**) — both of which the sibling
`hasApprovedOt` branch at `:214-220` does honour.
`StoreShiftRequest.php:33` and `UpdateShiftRequest.php:35` allow `auto_ot_hours`
up to **8**.

```text
[PROBE 2]  extended auto_ot_hours=8 → overtime_hours=8
[PROBE 2b] extended 10-min excess   → overtime_hours=0.17
```

CLAUDE.md: *"OT: Min 30min, Max 4hrs."* Both bounds are bypassed on this path.
`overtime_hours` is the only OT pay driver
(`api/app/Modules/Payroll/Services/PayrollCalculatorService.php:463-470`), so both
figures go straight into pay. Money — deferred.

### M018-F20 — QUESTION for a human (money): the seeded 6AM–6PM extended shift produces ZERO overtime

CLAUDE.md states *"Extended shift (6AM–6PM) = auto-OT"*.
`api/database/seeders/ShiftSeeder.php:16` seeds exactly that shift:
`Extended Day, 06:00–18:00, break 30, is_extended=true, auto_ot_hours=4.0`.

`DTRComputationService.php:201-213` derives OT from `excess`, which is worked
minutes **outside** `[shiftStart, shiftEnd]`. For this shift `shiftEnd = 18:00`,
so an employee working exactly 06:00–18:00 has `excess = 0` and `otMin = 0`:
11.5 regular hours, 0 OT.

`api/tests/Unit/DTRComputationServiceTest.php:252-263` asserts precisely this —
under the name **`test_extended_shift_full_pays_auto_ot`**, which says the
opposite of what it asserts. The misnamed green test is why three prior audits
did not see this.

The pay consequence is not cosmetic. Basic pay is **flat per cutoff**, and
`regular_hours` only drives `days_worked` and the holiday premium
(`PayrollCalculatorService.php:437-460`); it is never paid hourly. So an employee
on the Extended Day shift who works 11.5 hours is paid **identically** to one who
works 7.5 hours on the Day Shift. The four extra hours earn nothing at all.

Two readings are possible and they disagree about pay, so this is **not decided
here**:

- **A — CLAUDE.md is the spec.** The 6AM–6PM shift should book the normal 8 hours
  as regular and auto-approve the balance as OT (≈3.5 h at 1.25×). Then the
  `is_extended` branch must measure excess against a *normal-day* length, not
  against `shift_end`, and the misnamed test is asserting a defect.
- **B — the code is the spec.** `is_extended` means "the scheduled day is
  genuinely 12 hours and is all regular time", `auto_ot_hours` caps only work
  past 18:00, and CLAUDE.md's one-liner is loose shorthand. Then the fix is to
  rename the test and correct CLAUDE.md.

Evidence needed: what Philippine Ogami actually pays someone rostered 6AM–6PM.
Under Philippine labour law, work beyond 8 hours a day is overtime regardless of
how the shift is labelled, which favours A — but that is a legal reading, not a
measurement of what this company does, and it changes every extended-shift
payslip. **A human must choose before any code moves.**

### M018-F21 — Broken (P2): the biometric import creates attendance for separated employees

`DTRImportService.php:72-75` (paired) and `:216-220` (raw) resolve the employee by
`employee_no` with no check of `employees.status`, `date_hired`, or
`clearances.separation_date`.

```text
[PROBE 5] separated-employee import: {"total":1,"imported":1,"skipped":0,"errors":[]}
```

The probe set `status='resigned'` and the row imported cleanly. A biometric export
containing a stale badge — or a badge reissued to a new hire — silently manufactures
attendance for someone who has left. Payroll consumes `attendances` for whoever is
in the scoped employee set, and `FinalPayService::lastSalaryProRated()` reads
`payroll.basic_pay` verbatim, so this has a path to money. Deferred: the correct
policy (refuse? import and flag? bound by `date_hired`…`separation_date`?) is an
HR decision.

### M018-F22 — Broken (P2): bulk-approve returns raw integer primary keys and confirms row existence

`api/app/Modules/Attendance/Services/OvertimeService.php:265,270` build
`['id' => $id, 'reason' => …]` from the **decoded integer**;
`OvertimeController.php:156` serializes it unchanged.

```text
[PROBE 6] failed rows:
[{"id":1,"reason":"Only pending overtime requests can be approved."},
 {"id":999999,"reason":"Not found."}]
```

Violates the project-wide rule that no API response exposes an integer id — and
the differing reason for a non-existent integer versus a real one is an existence
oracle over the whole table.

Same method, second defect: `OvertimeController.php:141-145` decodes the
submitted hashes and `->filter()`s out anything undecodable **silently**, so a
batch of five ids where two are malformed reports `"3 approved, 0 failed."` The
caller is never told two of its items were dropped — the same silent-partial-batch
class that commits `0189e571` and `70328ac3` already fixed elsewhere on this
endpoint.

### M018-F23 — Incomplete (P3): an arbitrary sort direction 500-ed the attendance list

**FIXED this session.**

`AttendanceService.php:83-87` whitelisted `sort` but not `direction`.

```text
[PROBE 10] InvalidArgumentException: Order direction must be "asc" or "desc".
```

Not injection — the value is not interpolated — but `GET
/api/v1/attendance/attendances?direction=x` was a 500 on a list endpoint.

**Root is upstream of this module:** the service template in
`docs/PATTERNS.md:262-268` contains the same `$sortDir = $filters['direction'] ??
'desc'` passed straight to `orderBy()`, so every service copied from it inherits
this. Reported to the coordinator; not fixed here (out of scope).

### M024-F24 — Incomplete (P3): the raw importer parses a `direction` column and then discards it

`DTRImportService.php:180,187` read `direction` and `PunchSessionizer.php:47`
carries it into the cleaned punch list — and `pairForEmployee()` (`:71-128`) never
reads it. Pairing is purely first-timestamp/last-timestamp. A device that
distinguishes IN from OUT has that information silently thrown away, so a file
whose first event of the day is a legitimate OUT books it as `time_in`.

### M018-F25 — Incomplete (P2): the approvable OT ceiling (8 h) is double the payable ceiling (4 h), silently

Three different caps govern one field:

| path | setting | live value |
|---|---|---|
| HR create (`StoreOvertimeRequestRequest.php:31`) and the SPA form via `OvertimeService::options():409` | `attendance.ot.admin_max_hours` | **8** |
| self-service create (`api/app/Modules/HR/Controllers/SelfServiceController.php:366`) | `attendance.ot.request_max_hours` | **4** |
| what the DTR actually pays (`DTRComputationService.php:216-218`) | `attendance.ot.maximum_minutes` | **240 (4 h)** |

So HR can create *and approve* an 8-hour overtime request, and payroll pays 4
hours, with no warning at any point. Either the create bound is wrong or the DTR
cap is — a policy call with a pay consequence. Deferred.

### M018-F26 — Incomplete (P2): there is no `Cancelled` overtime state, only rejection wearing its clothes

Broader than the prior F16 wording note. `OvertimeStatus` has exactly three cases
— `Pending`, `Approved`, `Rejected`
(`api/app/Modules/Attendance/Enums/OvertimeStatus.php:9-11`). `cancel()` writes
`Rejected` and emits `OvertimeRequestDecided(..., false)`
(`OvertimeService.php:342-351`), and
`NotifyOnOvertimeDecided.php:26-27` maps every false decision to `"Rejected"` and
`attendance.ot_rejected`.

Consequences: an employee who withdraws their own request is told *"Your OT
request … was Rejected"*; and because the enum has no fourth case, a list filtered
`status=rejected` (`OvertimeService.php:169`) returns withdrawals mixed in with
real refusals. Distinguishing them requires reading `cancelled_at`. Adding an
enum case changes a state machine and the `attendance.ot_rejected` notification
contract — deferred.

## Findings re-confirmed with fresh evidence

### M018-F10 — Broken (P0): the payroll write fence authorizes against LIVE employee attributes, so a transfer unlocks a paid day

`AttendanceDateMutabilityGuard.php:61-87` decides whether a period applies to an
employee from the employee's **current** `department_id`, `employment_type` and
`pay_type`. Payroll froze a different set: it computed scope as of the period end
(`PayrollPeriodService.php:523-551`) and persisted an employee/cycle claim inside
the payroll transaction (`PayrollCalculatorService.php:291-303`,
`PayrollCycleClaim.php:12-18`).

Reproduced end to end:

```text
[PROBE 8] in-scope edit correctly refused: Attendance for 2026-04-10 is locked by
          payroll period 2026-04-01–2026-04-15 (finalized). Void or correct the
          payroll period before changing this record.
[PROBE 8] AFTER TRANSFER the locked day was EDITABLE — payroll fence bypassed
```

Same employee, same finalized department-scoped period, same date. The only thing
that changed between the two attempts was `employees.department_id`. A transferred
employee's already-paid attendance becomes editable, deletable, restorable and
recomputable. This is a payroll-integrity bypass, not a stale-list nuisance, and
it applies to every scoped period. **P0, deferred — the fix must consult frozen
payroll membership, which is cross-module.**

### M018-F11 — Broken (P1): bulk-approve returns internal exception messages verbatim

`OvertimeService.php:269-271` catches every `Throwable` and puts
`$e->getMessage()` in the response. Proven with a non-business exception —
an inverted punch makes the DTR engine throw from inside `approve()`:

```text
[PROBE 9] failed: [{"id":1,"reason":"Time out (2026-04-15 06:00:00) must be after
                    time in (2026-04-15 14:00:00)."}]
```

That particular message is harmless; the point is that the filter admits anything.
A `QueryException` on the same path yields exactly what probe 1 showed for the
identical `catch (Throwable) → getMessage()` pattern in the import service: the
full SQL statement. Deferred — the fix must define a safe-reason contract that
`spa/src/pages/attendance/overtime/index.tsx` reads.

### M018-F12 — Incomplete (P2): archived and inactive shifts are assignable

`AssignEmployeeShiftRequest.php:26-33` and `BulkAssignShiftRequest.php:29-37` only
decode the hash; `ShiftAssignmentService.php:23-54,107-113` puts the integer into
the assignment row without resolving the `Shift`.

```text
[PROBE 4]  assignment to trashed shift accepted; current() = null
[PROBE 4b] inactive shift assigned; current() = Probe Off 4e9e is_active=false
```

A trashed shift produces a valid-FK assignment that `current()`
(`ShiftAssignmentService.php:61-72`, a normal relation) resolves to **null**, so
`DTRComputationService.php:48-53` silently falls back to the default shift — the
employee is computed against a schedule nobody assigned. An inactive shift is
accepted and used as-is.

The SPA makes this reachable rather than theoretical:
`spa/src/pages/attendance/shifts/assign.tsx:34-38` calls
`shiftsApi.list({ per_page: 100 })` with **no** `is_active` and no `trashed`
filter and maps every row into an `<option>` at `:89` — while
`spa/src/pages/attendance/index.tsx:209` does pass `{ is_active: true }` for its
shift select. Deferred (needs an explicit inactive-shift policy).

### M018-F15 — Missing (P1): `is_recurring` is stored, displayed, and never applied

`HolidayService.php:94-105` loads only rows whose stored date falls in the
requested year and keys them by the exact date. `is_recurring` appears in
`Models/Holiday.php:18,23`, both requests, and `HolidayResource.php:20` — and in
no date-resolution code anywhere.

```text
[PROBE 3] 2027-06-12 lookup for a recurring 2026 holiday: null
```

The SPA promises the opposite in three places:
`spa/src/pages/attendance/holidays/index.tsx:204` (a `Recurring: Yes` chip),
`:235` (`Annually`), `:364` (`<Switch label="Recurs annually">`). Holiday pay and
`day_type_rate` therefore vanish for every recurring holiday after one year.
Deferred — needs recurrence semantics including leap day, plus cache invalidation.

## Polish pass — SPA (source-level; no browser run)

Routes: all eight pages are `React.lazy` (`spa/src/routes/hrRoutes.tsx:22-29`) and
all sit under `ModuleGuard module="attendance"` (`:103`) with a `PermissionGuard`.

**Design system — two violations of the opaque-surface rule** (`docs/DESIGN-SYSTEM.md`
forbids translucency; Tailwind colours here are `color-mix(… <alpha-value> …)`,
`spa/tailwind.config.ts:22-33`, so a `/N` suffix really does composite alpha):
- `spa/src/pages/attendance/index.tsx:143` — `bg-danger/10`; should be `bg-danger-bg`.
- `spa/src/pages/attendance/holidays/index.tsx:309` — `bg-current opacity-70`.

No hardcoded hex, `rgb(`, or Tailwind default-palette classes in any of the eight
files. No `backdrop-blur`.

**Permission gating — one live dead end.**
`spa/src/pages/attendance/overtime/index.tsx:203-205` renders **"New OT request"
with no `can()` wrapper**, unlike every other action on that page (`:161`, `:190`,
`:262-269`, all gated on `attendance.ot.approve`). Live grants (queried, not
inferred):

```text
department_head | attendance.ot.approve
department_head | attendance.view
hr_officer      | attendance.edit, attendance.import, attendance.ot.approve,
                  attendance.ot.create, attendance.shifts.manage, attendance.holidays.manage
```

A department head reaches that list (its guard is `attendance.ot.approve`,
`hrRoutes.tsx:126`), clicks the button, and lands on a route guarded by
`attendance.edit` (`:130`) which they do not hold → 403. Separately, that route
guard and the API disagree: the route checks `attendance.edit` while
`POST /attendance/overtime-requests` requires `attendance.ot.create`
(`routes.php:44`). Both are held by `hr_officer`, so nothing breaks today, but the
guard does not name the permission it is guarding. `attendance.ot.create` is
referenced **nowhere** in the SPA.

All other in-page actions are correctly gated (attendance list `:309,:314,:319,:324,:352,:367`;
shifts `:117,:148,:192`; holidays `:100,:120,:237`; OT detail `:75-76,:93,:99`).
`import.tsx` and `shifts/assign.tsx` are ungated in-page but their whole routes
sit behind `attendance.import` / `attendance.shifts.manage`, matching the backend.

**Page states.** All four list pages implement loading / error+retry / empty /
data. **None implements the fifth (stale) state**: `grep -rn
"isFetching|isPlaceholderData|isRefetching" spa/src/pages/attendance/` returns
zero hits — only `placeholderData` (the no-flash half) is set
(`index.tsx:195`, `shifts/index.tsx:60`, `holidays/index.tsx:55`,
`overtime/index.tsx:45`). `docs/PATTERNS.md:1522-1546` requires a stale
affordance. Also `overtime/index.tsx:250` is the only empty state with no
`action` prop.

**Other polish**
- `spa/src/pages/attendance/import.tsx:20-28` — the import mutation has both
  toasts but **no `queryClient.invalidateQueries`** (the file imports only
  `useMutation`), so the attendance list stays stale after a successful import.
  Every other mutation in the module invalidates correctly.
- `spa/src/pages/attendance/shifts/index.tsx:40` — the Zod schema for
  `auto_ot_hours` is `z.string().optional().or(z.literal(''))`: no `coerce`, no
  numeric check, no `max`. `:260` then does `Number(...)`. Only the server's
  `max:8` stops anything — and per F19 that bound is itself wrong.
- `font-mono` without `tabular-nums`: `index.tsx:250`;
  `overtime/index.tsx:151,353`; `holidays/index.tsx:225`;
  `overtime/detail.tsx:214`; `import.tsx:122`. No mono at all on numeric content:
  `shifts/index.tsx:106,186` (`Auto-OT {n}h`), `import.tsx:69` (file size),
  `overtime/detail.tsx:141-147`, `holidays/index.tsx:307` (calendar days).
  `shifts/index.tsx:114` and `overtime/index.tsx:187` print `data.meta.total`
  without `formatInt`, which `index.tsx:306` does use.

## Fixes applied this session

Three, all one theme — a well-formed operator action reaching the client as an
unhandled fault. None changes a pay figure, a state machine, or a permission.
See `fix-log.md` for before/after and full verification output.

| Finding | Change |
|---|---|
| **F18** | `DTRImportService::openDayRecord()` (`:281-297`) reads the day `withTrashed()` under `lockForUpdate()` and refuses an archived day with a `BusinessRuleException` naming the remedy; `rowMessage()` (`:310-341`) replaces both bare `$e->getMessage()` calls, passing operator-actionable throws through and logging + masking everything else; `isDayUniqueViolation()` (`:348-353`) covers the residual concurrent-INSERT race. `AttendanceService::assertDayNotArchived()` (`:128-143`) turns the manual-create 500 into a 422. The archived row is **not** auto-restored — that stays an explicit HR decision. |
| **F23** | `AttendanceService::list()` (`:83-95`) lower-cases and whitelists `direction`, falling back to `desc`. |
| **F14** | `StoreAttendanceRequest` (`:28`, `:37-42`) requires `date_format:Y-m-d` with a message. |

New suite `api/tests/Feature/Attendance/AttendanceArchivedDayAndInputHardeningTest.php`
— 6 tests, 21 assertions, including an explicit assertion that the import message
contains neither `SQLSTATE` nor `insert into` nor the constraint name, and a
no-regression case proving an **unarchived** existing day still updates in place.

## Gate decision

Fifteen open items; three fixed. Of the twelve remaining, **nine are
`separate-recommended`** — F10 and F19 and F25 touch money, F26 changes a state
machine, F22 and the OT-button gap touch permissions/RBAC, F20 needs a human
policy decision, and F08/F15/F21 are product contracts. The
`same-session-ok` items are a clear minority, so the gate is **not** "fix now".

Per the escape hatch, the three items fixed are genuinely contained, share one
root cause and one theme, and were fixed together because splitting them would
have left the SQL-disclosure half of F18 open while its sibling paths were
touched. Everything gated was left alone.

Released as **🔁 Needs Re-audit** — not `📋 Plan Ready`, because production code
did change and a re-audit must confirm the three fixes in place alongside the
twelve open items.

## Questions needing a human decision

1. **M018-F20 — what does Philippine Ogami pay someone rostered 6AM–6PM?**
   Today: 11.5 regular hours, zero OT, and because basic pay is flat per cutoff
   that is the same money as a 7.5-hour day shift. Options A and B above disagree
   about every extended-shift payslip. Not decided here.
2. **M018-F25 — is the OT ceiling 4 hours or 8?** HR can approve 8; payroll pays
   4. One of the two numbers is wrong.
3. **M018-F21 — what should the importer do with a punch for a separated
   employee?** Refuse, import-and-flag, or bound by
   `date_hired`…`clearances.separation_date`?
4. **M018-F08 — what do the FCIE Dasmariñas biometric terminals actually
   export?** Still the deciding fact for whether `importRawPunches()` is exposed
   or documented as internal. Unchanged from the 2026-08-27 write-up.
5. **M018-F15 — recurrence semantics**, including 29 February.

## Note for the coordinator (outside this module, not touched)

- `docs/PATTERNS.md:262-268` — the canonical service template passes an
  unvalidated `direction` into `orderBy()`. Every service copied from it inherits
  the 500 fixed here as F23.
- CLAUDE.md's "HasAuditLog + custom guards" section prescribes
  `App\Modules\Edge\Services\EdgeSystemUserResolver`, which **does not exist** in
  the tree, and an `auth:edge_device` guard that is not in `config/auth.php`.
