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
