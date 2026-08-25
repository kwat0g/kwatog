# M037 — Purchase Orders Action Plan

Session: 2026-08-25  
Status: `🔁 Needs Re-audit`  
Plan posture: resumed an existing `📋 Plan Ready` plan after a required
current-state re-audit. The separate-session labels below do not defer work
again; they describe the risk and the required owner/verification boundary.

## Ordered fix items

| Order | Finding(s) | Fix item | Scope | Session recommendation | Current disposition / acceptance evidence |
|---:|---|---|---|---|---|
| 1 | F01 | Keep the auto-PO path on one valid lifecycle: registered `purchase_order` sequence, `PurchaseOrderStatus::PendingApproval`, durable `ApprovalService` records, idempotency, and one agreed approval route. | large | separate-recommended | Technical repair and focused test added in this session. Confirm canonical PO workflow versus the documented VP-only route, then run the test on an isolated PostgreSQL schema and prove one downstream handoff. |
| 2 | F05 | Preserve the locked draft update/delete boundary and add two-connection race coverage against submit/approve and PR reopening. | medium | separate-recommended | Current service locks/re-reads update, delete, and submit. Added PostgreSQL two-connection coverage at `api/tests/Feature/Purchasing/PurchaseOrderTwoConnectionRaceTest.php:44-175`; execution remains pending until the dedicated test database is reachable. |
| 3 | F02, F09, F10 | Maintain the supplier DTO/lifecycle contract, align capability flags with endpoint preconditions (including accepted GRN for invoices), and verify FormData uploads. | large | separate-recommended | DTO and multipart changes exist in the worktree; F09’s accepted-GRN capability mismatch remains in the B2B dependency and was not modified under this module claim. |
| 4 | F03 | Preserve PR-line source IDs through converter, create form, request, service, and response; reject a source line belonging to another PR/item. | medium | separate-recommended | Current form/service/resource paths carry and validate the hash-string provenance. Add isolated HTTP contract coverage. |
| 5 | F06 | Round-trip incoterm through validated create/update, internal detail, supplier contract, and PDF. | medium | separate-recommended | Current internal persistence, resource, form, detail, and PDF paths are present. Add isolated round-trip coverage. |
| 6 | F07 | Recalculate budget warning/acknowledgment whenever a locked draft amount changes and approve only against current financial state. | medium | separate-recommended | Current update path re-assesses and clears stale fields. Add upward/downward amount tests in the dedicated database. |
| 7 | F11 | Resolve maker/checker policy for `purchasing_officer`: role separation, explicit monitored exception, or another documented control. | large | separate-recommended | Deferred for human/RBAC decision; changing shared role seeders is outside this module scope. |
| 8 | F04 | Keep soft-deleted hash binding and service-only restore state/permission checks. | small | same-session-ok | Current route and service use `withTrashed()` and reject active rows. Add permission/state test. |
| 9 | F08 | Keep approval/rejection actions restricted to `pending_approval` and confirm whether rejection is represented as cancellation or a distinct state. | small | same-session-ok | Current service and SPA gate actions correctly. Business vocabulary and terminal-state test remain. |
| 10 | F12, F14 | Keep permission-aware cancel visibility and align trimmed bounded reason validation between API and UI. | small | same-session-ok | Current route/UI/request agree; add unauthorized and boundary-value coverage. |
| 11 | F13 | Keep internal PO and source-line IDs typed and serialized as hash strings. | small | same-session-ok | Current TypeScript and resource paths agree; add response-shape assertion. |
| 12 | F15 | Keep vendor, overdue, date, and PO-number filters and preserve the overdue predicate in queue links. | small | same-session-ok | Current service/UI paths support the filters; add URL/backend filter coverage. |
| 13 | F16 | Decide whether manual direct PO creation exists. If yes, implement its permission/approval/provenance contract; if no, update `PROCESS-FLOWS.md` and test language. | medium | separate-recommended | Deferred for a product decision because current docs and request/UI disagree. |

## Verification gate

Before `✅ Verified`:

- Run migrations and the focused Purchasing, Approval, Inventory auto-
  replenishment, B2B supplier, dispatch, budget, and PR-reopening suites in a
  dedicated PostgreSQL test database. The shared `ogami_test` schema was not
  usable in this session.
- Run the new `AutoPurchaseOrderServiceTest` and prove the PO is
  `pending_approval`, contains the expected current approval records, rejects
  `pending_vp`, and does not duplicate on retry.
- Add/execute two-connection race coverage for draft update/delete versus
  submit/approve.
- Add HTTP/contract coverage for supplier line fields, lifecycle capabilities,
  accepted-GRN invoice gating, multipart upload, source-line provenance,
  incoterm, restore, rejection validation, cancel permission, and filter URLs.
- Record decisions for the auto-PO approval route, direct-create policy, SoD
  role overlap, rejection naming, and accepted-supplier enforcement before
  changing any cross-module policy.
