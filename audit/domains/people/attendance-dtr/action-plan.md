# M018 — Attendance & DTR action plan

Status: 📋 Plan Ready
Audit date: 2026-08-27
Overall recommendation: separate-recommended

## Ordered actions

| Order | Finding | Scope | Session recommendation | Deliverable / acceptance evidence |
|---:|---|---|---|---|
| 1 | M018-F10 payroll membership/scope-drift fence | large | separate-recommended | Locked attendance writes consult immutable payroll employee membership/claims; moved-out and moved-in scoped employees are covered by transaction-safe tests. |
| 2 | M018-F11 bulk OT unexpected-error disclosure | small | separate-recommended | Expected business failures remain actionable; unexpected failures are logged and returned with a stable safe reason; JSON never contains raw exception text. |
| 3 | M018-F09 authorization/lifecycle/concurrency/browser coverage | large | separate-recommended | Cross-department OT denial, locked writes, restore, shift assignment, holiday, contention, correction UI, and authenticated browser tests run in CI. |
| 4 | M018-F15 recurring holiday semantics | medium | separate-recommended | Recurring holidays apply across years with defined leap-day behavior and cache/DTR regression coverage. |
| 5 | M018-F08 raw-punch product decision | medium | separate-recommended | Raw import is either exposed with an explicit tested contract and UI, or marked internal with product copy/tests no longer implying support. |
| 6 | M018-F12 archived/inactive shift assignment validation | small | separate-recommended | Assignment writes reject trashed shifts and follow a documented inactive-shift rule; single and bulk tests pass. |
| 7 | M018-F13 nullable correction fields | small | same-session-ok | SPA/API send explicit nulls when clearing shift, time-in, or time-out; correction tests prove persisted values are cleared. |
| 8 | M018-F14 strict attendance date input | small | same-session-ok | Create input requires/normalizes `Y-m-d`; datetime-shaped values return validation errors rather than parse failures. |
| 9 | M018-F16 cancellation notification wording/type | small | same-session-ok | Cancelled OT produces cancellation copy/type; approved and rejected notifications remain distinct. |
| 10 | M018-F17 zero-minute auto-OT guard | small | same-session-ok | Exact shift-end attendance never creates a zero-hour OT request, even when the configured threshold is zero. |

## Gate application

The plan has four `same-session-ok` actions and six `separate-recommended` actions. The total plan is not small and the same-session-ok actions are not a majority, so the gate is **Plan Ready**: do not modify production code in this audit session.

## Verification sequence for the next session

1. Add the payroll-membership and bulk-error tests before implementation; preserve the existing locked-row transaction fence.
2. Decide raw-punch and recurring-holiday contracts with the owning product/payroll stakeholders, then implement their selected behavior.
3. Add the negative API, PostgreSQL contention, and authenticated browser coverage listed in F09.
4. Apply the small correction/date/notification/threshold changes and run focused backend tests using a unique `DB_DATABASE`, SPA typecheck/lint/unit checks, and the browser smoke path.
5. Commit explicit M018-owned files, recheck the generated registry is untouched, and release M018 only after all deferred findings have evidence.
