# M019 — People / Leave Management action plan

Status: 📋 Plan Ready  
Audit date: 2026-08-24  
Overall recommendation: separate-recommended

## Ordered work

| Priority | Finding | Scope | Session recommendation | Deliverable / acceptance evidence |
|---|---|---|---|---|
| P0 | M019-F01 decision department scope | medium | separate-recommended | HR/system-admin all-record policy and same-department department-head policy apply inside single/bulk approve/reject transactions; cross-department API tests fail closed. |
| P0 | M019-F02 cancellation authorization | small-to-medium | separate-recommended | Owner-only self-service cancellation plus explicit HR/admin override; pending/approved cross-employee denial and audit actor tests pass. |
| P0 | M019-F03 locked-payroll attendance mutability | medium | separate-recommended | Leave approval/cancel uses the authoritative finalized/disbursed/voided date guard; no locked attendance/balance side effect occurs. |
| P1 | M019-F04 half-day and attendance rollback | medium | separate-recommended | AM/PM leave changes only the intended attendance representation; cancellation restores prior DTR lineage and never deletes unrelated punches. |
| P1 | M019-F05 rollover retry idempotency | small-to-medium | separate-recommended | Rerunning January rollover after target-year consumption preserves used/remaining and carried days; concurrent/retry tests pass. |
| P1 | M019-F06 cross-year balance allocation | small-to-medium | separate-recommended | Cross-year requests are rejected or split into year-specific balance allocations with approval/cancel symmetry. |
| P1 | M019-F07 required documents | medium | separate-recommended | Required types cannot submit/approve without a server-owned attachment; storage authorization, retention, and SPA upload tests pass. |
| P1 | M019-F08 active type/balance invariant | small-to-medium | separate-recommended | Inactive types and missing balance rows fail at submission with controlled copy; HR approval never turns a seeding gap into a 500. |
| P1 | M019-F09 archive restore | small | same-session after P0 controls | Soft-deleted leave types bind with `withTrashed`, restore through a service transaction, and have archive→restore API coverage. |
| P1 | M019-F10 hire-date proration | small-to-medium | separate-recommended | One authoritative balance initializer applies the intended mid-year proration exactly once and remains replay-safe. |
| P1 | M019-F11 regression suite | medium | separate-recommended | PostgreSQL API/integration tests and writable-cache SPA/browser tests cover all authorization, payroll, rollover, document, restore, and UI contracts. |

## Suggested implementation sequence

1. Write the policy tests for same-department and cross-department decisions, owner/override cancellation, and self-approval before changing services.
2. Add a shared leave decision/cancellation policy and authoritative row locks; apply it to single and bulk actions.
3. Reuse one attendance/payroll-date mutability guard and define the canonical half-day representation plus prior-attendance restoration model.
4. Repair rollover with a target-balance lock/preservation rule; test consumption between retries and missing-disposition recovery.
5. Define cross-year semantics, required attachment storage/authorization, active-type filtering, and missing-balance recovery.
6. Consolidate employee balance initialization so synchronous and queued paths cannot disagree on proration.
7. Repair soft-delete restore, then run the complete PostgreSQL feature suite, PHP lint, SPA typecheck/lint/unit/build, and authenticated browser smoke paths.

## Audit-session decision

No implementation fix is applied in this session. The findings are not predominantly small: they cross authorization policy, payroll immutability, attendance data lineage, rollover accounting, document storage, employee lifecycle, and API/SPA regression coverage. A same-session patch would leave the highest-risk controls unresolved.
