# M018 — Attendance & DTR action plan

Status: 🔁 Needs Re-audit  
Audit date: 2026-08-24  
Overall recommendation: separate-recommended

## Ordered work

| Priority | Finding | Scope | Session recommendation | Deliverable / acceptance evidence |
|---|---|---|---|---|
| P0 | M018-F01 OT decision authorization | medium | separate-recommended | Approve, reject, and bulk approve enforce system-admin/HR versus same-department policy inside the service transaction; cross-department negative API tests pass. |
| P0 | M018-F02 locked-payroll attendance mutability | medium | separate-recommended | Manual, paired-import, raw-import, delete, and restore paths share one finalized/disbursed/voided guard; locked-period tests prove no attendance or derived OT changes. |
| P1 | M018-F09 authorization/lifecycle regression suite | medium | separate-recommended | PostgreSQL feature suite runs; cross-department, locked-period, restore, assignment-overlap, holiday-conflict, and concurrency cases are executable in CI. |
| P1 | M018-F03 archive restore and holiday cache | small | same-session after P0 controls | All soft-deleted resources bind with `withTrashed`; restore invalidates holiday-year cache; archive→restore tests pass. |
| P1 | M018-F04 attendance correction workflow | medium | separate-recommended | HR can inspect, add, edit, archive, and restore a DTR through a guarded SPA flow with audit context and locked-period errors. |
| P1 | M018-F05 shift assignment overlap invariant | medium | separate-recommended | New assignments truncate or reject every intersecting interval; database/application invariant and concurrent writes are tested. |
| P1 | M018-F06 holiday-date invariant | small-to-medium | separate-recommended | Same-date holiday behavior is explicitly defined, validated, deterministically cached, and covered for regular/special conflicts. |
| P2 | M018-F07 merged shift-time validation | small | same-session after P0 controls | Partial shift updates cannot create equal start/end values; DTR rejects invalid schedules. |
| P2 | M018-F08 raw-punch product path | small-to-medium | separate-recommended if contractual | Raw import is either explicitly exposed and tested end-to-end or clearly marked internal and removed from product claims. |
| P2 | OT error-detail polish | small | same-session after backend hardening | Server validation/authorization reasons are surfaced in HR OT action toasts. |

## Suggested implementation sequence

1. Write the policy tests first: department-head same-department success, cross-department approve/reject/bulk denial, HR/system-admin all-record behavior, and self-approval denial.
2. Introduce a shared attendance-date mutability guard that uses the payroll period's authoritative locked semantics, and apply it to every attendance write path before DTR recomputation or OT detection.
3. Repair soft-delete route binding and service-based restore, including holiday cache invalidation. Add archive/restore API tests before exposing the UI action.
4. Build the correction workflow around the same guard and audit trail. Keep employee/date immutable on edit; use explicit shift and time validation; show locked-period and server validation messages.
5. Fix assignment interval handling and holiday-date policy, then add database/application invariants and concurrency tests.
6. Decide whether raw-punch import is a supported product capability. If yes, add an explicit endpoint/mode and browser path; if not, keep it internal and document the paired format honestly.
7. Run the full backend feature suite, PHP lint, SPA typecheck/lint/unit/build, authenticated browser tests, and a production-like payroll/attendance smoke test before verification.

## Audit-session decision

This plan was resumed in a dedicated session and the backend controls, restore paths, correction workflow, assignment/holiday invariants, merged-time validation, and OT error reporting were implemented. The raw-punch product-surface decision and environment-dependent PostgreSQL/browser verification remain open, so the module is released as Needs Re-audit rather than Verified.
