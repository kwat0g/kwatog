# M019 — Leave Management Action Plan

Audit date: 2026-08-27
Owner: people/leave-management
Status: 📋 Plan Ready
Database for verification: ogami_test_m019_agent_a

## Gate decision

Do not implement in this session. Only one candidate is small and
same-session-ok (M019-F20); the remaining work is predominantly
separate-recommended medium/large work. The plan therefore fails the required
majority same-session-ok and small-total-scope gate. No production code changes
are authorized by this plan.

## Ordered actions

| Order | Finding | Action | Scope | Session | Acceptance evidence |
| --- | --- | --- | --- | --- | --- |
| 1 | M019-F10 | Agree ownership of hire-year proration with employee-master, then remove the synchronous full-balance race or make initialization idempotently apply the prorated result. | large | separate-recommended | A mid-year hire has exactly the documented prorated balance after synchronous and queued paths, with an idempotency regression test. |
| 2 | M019-F15 | Define year-end payroll rounding and convert the year-end job to the repository exact-money representation; add cent-boundary tests. | medium | separate-recommended | Encashment uses exact decimal strings, documented rounding, and matches Payroll/FinalPay for adversarial salary and day values. |
| 3 | M019-F13 | Fail closed when authoritative salary is absent or create an explicit recoverable pending disposition before balance mutation; cover monthly and semi-monthly employees. | large | separate-recommended | No-salary processing cannot erase days without a value or recovery record; the job is retry-safe and the focused regression suite proves it. |
| 4 | M019-F14 | Add max_carryover_days and conversion_rate to leave-type validation, persistence, and resource output; enforce one conversion-rate invariant instead of silently clamping operator input. | medium | separate-recommended | Management API create/update/read round-trips both policies, UI values survive reload, and year-end consumption matches the returned configuration. |
| 5 | M019-F17 | Centralize year validation for API, CLI, and UI and align the year-end copy with the actual eligible leave-type query. | small | separate-recommended | Invalid/non-supported years are rejected consistently, and UI copy is verified against the job's selection semantics. |
| 6 | M019-F16 | Specify and implement calendar semantics for active employees, unique employee/day counting, AM/PM weighting, and Sunday/holiday treatment. | large | separate-recommended | Calendar headcount/present/on-leave values agree for terminated employees, one half-day, paired half-days, duplicate requests, Sundays, and holidays. |
| 7 | M019-F21 | Reject leave ranges whose computed business-day total is zero, with a clear API/UI validation message. | small | separate-recommended | A Sunday-only submission cannot be created through API or SPA; valid business-day requests retain current behavior. |
| 8 | M019-F18 | Preserve historical leave-type identity with soft-deleted relation loading or immutable snapshots, and make SPA consumers null-safe during migration. | medium | separate-recommended | Archived balances and requests remain readable in HR and self-service pages, with authorization and archive regression coverage. |
| 9 | M019-F12 | Add an authorized document metadata/read/download action for reviewers without exposing private storage paths; render it on HR detail. | medium | separate-recommended | Authorized HR reviewers can inspect a required document, unauthorized users cannot, and missing documents remain explicit. |
| 10 | M019-F19 | Choose either a registered owner-scoped self-service detail route/API or a safe list link, then update approved/rejected notification links and test navigation. | medium | separate-recommended | Approved and rejected employee notifications land on a working authorized page; HR notification links remain green. |
| 11 | M019-F11 | Add focused regression tests for the contracts above and provision the missing Playwright Chromium/Firefox binaries before rerunning the selected browser suites. | medium | separate-recommended | Backend contract tests cover each fixed finding, and the 18 selected Playwright tests execute to application assertions on the unique test database/environment. |
| 12 | M019-F20 | Hide or disable Edit for archived leave types, or make the control restore before editing. | small | same-session-ok | Archived rows have no action that submits to the normal update route; active rows retain Edit behavior. |

## Verification constraints

- Use only DB_DATABASE=ogami_test_m019_agent_a for M019 tests.
- Keep tests focused on M019; do not modify dependency modules as part of this
  plan.
- Do not edit audit/00-MODULE-REGISTRY.md; the coordinator regenerates it after
  all cards finish.
- Commit explicit module-owned files before release when implementation is
  eventually authorized.

---

# Re-audit action plan — 2026-08-30

Owner: people/leave-management
Status: 🔁 Needs Re-audit
Database for verification: `ogami_test_leave` (dropped after the session)

## Closed since the 2026-08-27 plan

| was | now | by |
|---|---|---|
| order 5 — M019-F17 | **done** | `9e7a1b12`, `4b22e26e` |
| order 7 — M019-F21 | **done** in production code (introduced M019-F24) | `a2deee8c` |
| order 12 — M019-F20 | **done** | `f0c69a23` |

Orders 1, 2, 3, 4, 6, 8, 9, 10, 11 of that plan all still reproduce; see the
re-verification table in the audit report. They carry forward unchanged and are
renumbered below alongside the new findings.

## Gate decision

Not eligible for wholesale same-session implementation. Nine of the eleven open
items are `separate-recommended`: four move money (F22, F23, F26, and F15's
float-to-exact-money conversion), two change the approval state machine or RBAC
(F25, and F13's fail-closed decision), three change a response contract that the
SPA and payroll both read (F14, F18, F19), and three of the six open questions in
the report must be answered by a human before the code can be written at all.

Per Step 6's contained-item clause, **two items were fixed in this session** and
nothing else was touched:

- **M019-F24** — test-fixture determinism. Touches only three files under
  `api/tests/Feature/Leave/`. Changes no production code, no computed figure, no
  permission, no transition. Leaving it unfixed means the module cannot be
  verified at all on a Sunday, which blocks every other item on this list.
- **M019-F27 (SPA half only)** — six unguarded `leave_type` dereferences. Purely
  defensive `?.` plus an em-dash fallback, matching the pattern already used at
  `spa/src/pages/leaves/index.tsx:217`. Changes no figure and no contract; it
  stops a `TypeError` from taking down the filing form. The API-contract half is
  F18 and stays deferred — that is the part that decides what the response should
  say, and it is not a null-guard.

## Ordered actions

| Order | Finding | Action | Scope | Session | Acceptance evidence |
| --- | --- | --- | --- | --- | --- |
| 1 | **M019-F22** | Decide and implement what cancellation does in a year already dispositioned. `LeaveBalanceService::restore()` must not be able to recreate `remaining` from a `used` that year-end set to `total_credits` — either mark the (employee, type, year) settled and refuse, or claw the encashment back through payroll. | large | separate-recommended | An approved request cancelled after year-end cannot leave a non-zero `remaining` in a dispositioned year, and the encashment and the balance agree on the total value released. Cover both convertible and non-convertible types. |
| 2 | M019-F15 | Convert the year-end job to the repository exact-money representation (`App\Common\Support\Money`); document the rounding. Currently floats throughout `ProcessYearEndLeave.php:129-160,210-215`. | medium | separate-recommended | Encashment is exact decimal strings end to end, and cent-boundary cases agree with Payroll/FinalPay. |
| 3 | M019-F13 | Fail closed, or record a recoverable pending disposition, when `monthlyEquivalentSalary()` is null — instead of zeroing days for zero value. | large | separate-recommended | No-salary processing cannot erase days without a value or a recovery record; the job stays retry-safe. |
| 4 | **M019-F23** | Make the business-day rule holiday-aware, and stop `markAttendance()` overwriting `day_type_rate` on a holiday row. Requires question 1 answered first. | large | separate-recommended | A range spanning a declared holiday debits the agreed number of credits, and the holiday attendance row keeps its premium while the leave is approved. |
| 5 | **M019-F26** | State one `conversion_rate` invariant and enforce it in one place. Either validation refuses >1.0, or the year-end clamp is removed. Requires question 2 answered first. | small | separate-recommended | The stored rate is the rate that pays. A rate the system will not honour cannot be saved. |
| 6 | M019-F14 | Add `max_carryover_days` to leave-type validation, persistence and resource output. | medium | separate-recommended | Create/update/read round-trips the policy, the UI value survives a reload, and year-end consumption matches the returned configuration. |
| 7 | M019-F16 | Specify then implement calendar semantics: active population only, unique employee/day, fractional AM/PM weighting, Sunday and holiday treatment. Currently `present_count` reaches 0 with one separated employee and one half-day. | large | separate-recommended | Headcount/present/on-leave agree for separated employees, one half-day, paired half-days, duplicate requests, Sundays and holidays. |
| 8 | **M019-F25** | Give a vacant department-head seat an operator-reachable path: either a documented delegation UI, or a scoped HR escalation that ApprovalService will honour. | medium | separate-recommended | A `pending_dept` request in a department with no head can be decided (not merely cancelled) by an authorised operator through the UI, and the audit trail names the escalation. |
| 9 | M019-F18 | Preserve historical leave-type identity — immutable snapshot or soft-deleted relation loading — now that M019-F27 has made the consumers null-safe. | medium | separate-recommended | Archived balances and requests remain readable and labelled in HR and self-service, with archive regression coverage. |
| 10 | M019-F12 | Add an authorised document metadata/read action for reviewers without exposing private storage paths; render it on HR detail. Requires question 5 answered first. | medium | separate-recommended | An authorised reviewer can open a required document; an unauthorised one cannot; a missing document stays explicit. |
| 11 | M019-F19 | Either register an owner-scoped `/self-service/leaves/:id` route with a matching API read, or point the approved/rejected notification at the list. | medium | separate-recommended | Approved and rejected employee notifications land on a working authorised page; HR notification links stay green. |
| 12 | **M019-F28** (was F10) | Agree hire-year proration ownership with employee-master, then reconcile the two writers — including the `now()->year` vs `date_hired->year` mismatch that can produce two balance rows. | large | separate-recommended | A mid-year hire and a backdated hire each end with exactly one correctly-prorated row, proven idempotent across the synchronous and queued paths. |
| 13 | M019-F11 | Add contract tests for every item above, provision the Playwright Chromium/Firefox binaries, and rerun the selected browser suites. | medium | separate-recommended | Each fixed finding has a focused regression test, and the selected Playwright specs execute to application assertions. |
| 14 | **M019-F29** | Measure half-day requests against the business-day calendar too. Today `$days = 0.5` bypasses the zero-business-day guard, so a Sunday half-day is created and debits 0.5 credits while `markAttendance()` skips the date. | small | separate-recommended | A half-day dated on a non-working day is refused with the same copy as a full-day range; valid half-days keep current behaviour, including the AM/PM non-collision rule. |
| — | **M019-F24** | Make the Leave fixtures weekday-deterministic. | small | **same-session-ok — DONE** | `tests/Feature/Leave` passes on the project default clock on a Sunday. |
| — | **M019-F27** (SPA half) | Guard the six `leave_type` dereferences. | small | **same-session-ok — DONE** | Archiving a leave type cannot throw in the filing form or HR detail; Vitest + ESLint + typecheck green. |

## Verification constraints

- Use only `DB_DATABASE=ogami_test_leave` for M019 tests, and drop it afterwards.
  Never `ogami_test` — `RefreshDatabase` runs `migrate:fresh` and two suites
  destroy each other's schema.
- Payroll and Attendance are read-only for this module. M019-F22, F23 and F28 all
  have their consequence in another module; report there, do not edit there.
- Do not edit `audit/00-MODULE-REGISTRY.md`; the coordinator regenerates it.
- Commit with an explicit pathspec in a single `git commit -- <paths>` invocation.
  The working tree is shared with other audit sessions.
