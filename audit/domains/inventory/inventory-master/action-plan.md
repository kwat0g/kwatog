# M039 — Inventory Master action plan

Status: `✅ Verified`  
Audit date: 2026-08-24  
Overall recommendation: `separate-recommended`

Implementation session: 2026-08-25

## Ordered work

| Priority | Finding | Scope | Session recommendation | Deliverable / acceptance evidence |
|---|---|---|---|---|
| P1 | M039-F01 authoritative bin projection | large | separate-recommended | Map, bin detail, stock ledger, and picking agree after receipt, issue, transfer, and lot changes; one documented source of truth. |
| P1 | M039-F02 lock ordering and retry | medium | separate-recommended | Opposite-direction concurrent transfer test completes without deadlock-induced partial state; lock order is deterministic. |
| P1 | M039-F03 material-issue cancel idempotency | medium | separate-recommended | Stale/concurrent cancellation produces at most one reversal and one cancelled transition. |
| P1 | M039-F04 adjustment approval valuation | medium | separate-recommended | WAC semantics are explicit; pending approval after a WAC change has correct movement value and audit trail. |
| P2 | M039-F05 stock-count write guards | medium | separate-recommended | Record/approve cannot mutate a completed or cancelled session; concurrent completion test passes. |
| P2 | M039-F06 lot/UOM/QC HTTP contract | medium | separate-recommended | Supported service fields are validated, persisted, returned, and editable through the canonical API/UI—or explicitly removed. |
| P2 | M039-F07 warehouse permission alignment | small | separate-recommended | Route and Form Request use the same permission; least-privilege custom-role tests pass. |
| P2 | M039-F08 negative-stock policy | medium | separate-recommended | Setting is removed or enforced centrally with explicit authorization, audit, valuation, and tests. |
| P3 | M039-F09 frontend contract alignment | small | same-session-ok after semantic fixes | `lock_version`, reason code, and final lot/UOM fields are represented in SPA request/response types and fixtures. |

## Suggested implementation sequence

1. Freeze the authoritative-state decision for warehouse bins and produce a reconciliation query for existing data.
2. Harden movement locking and cancellation/approval/count state transitions with concurrency tests before changing UI contracts.
3. Reconcile the lot/UOM/QC contract across Form Requests, controllers, services, resources, SPA API types, and forms.
4. Align permissions and settle the negative-stock setting.
5. Run migration/deployment smoke checks, browser/API flows, the full Inventory feature suite, and a live-data reconciliation.

## Audit-session result

The existing plan was executed in this dedicated follow-up session. All nine findings have an implementation, focused regression coverage where applicable, and a passing Inventory feature-suite verification. The workspace-wide SPA typecheck remains blocked by an unrelated syntax error in the already-modified Purchasing page (`spa/src/pages/purchasing/purchase-requests/detail.tsx:125`); no out-of-scope file was changed.
