# M033 — Sales Orders Action Plan

Date: 2026-08-27
Source: current-source discovery, hardening, and polish passes in `audit-report.md`
Execution mode: one claimed module, unique test database `ogami_test_m033_agent_c`

The inherited 2026-08-25/26 findings were rechecked against the current source.
Their contained fixes are already implemented and verified. The additions below
are cross-surface or cross-module work and stay explicitly deferred.

## 1. Preserve clearing of nullable draft fields — DONE

- Findings: F-016
- Size: **small**
- Session recommendation: **same-session-ok**
- Change the update service to distinguish an omitted optional field from an
  explicit `null`, and send explicit nulls from the edit form for cleared
  delivery terms, incoterm, and notes.
- Add route coverage proving all three fields can be cleared on a draft.

## 2. Validate and bound sales-order list filters — DONE

- Findings: F-017
- Size: **medium**
- Session recommendation: **same-session-ok**
- Add a sales-order list FormRequest for status, dates, pagination, search, and
  archive visibility; reject malformed input with 422 before it reaches the
  query builder.
- Keep a service-side lower bound on `per_page` for non-HTTP callers.
- Add route coverage for invalid dates, invalid status, and invalid page size.

## 3. Keep edit date constraints visible in the browser — DONE

- Findings: F-018
- Size: **small**
- Session recommendation: **same-session-ok**
- Set the edit delivery-date input minimum to the current order date, matching
  create and the server-side invariant. Retain backend validation as the
  authority.

## 4. Close the queued-MRP cancellation race — DEFERRED

- Finding: F-005
- Size: **large**
- Session recommendation: **separate-recommended**
- Coordinate with MRP to re-lock and re-read the sales order inside
  `MrpEngineService::runForSalesOrder()` immediately before creating a plan or
  work orders, then add a deterministic cancellation-interleaving test.
- Do not modify MRP or job files during an M033 session.

## 5. Reconcile cancelled-order chain semantics — DEFERRED

- Finding: F-008
- Size: **large**
- Session recommendation: **separate-recommended**
- Choose a canonical cancelled state, update shared `ChainDefinitions` and all
  consumers together, and keep the M033 projection aligned.
- Preserve the completed historical timestamp backfill; validate it against
  production audit history as part of the shared-chain change.
- Do not modify shared chain infrastructure during this session.

## 6. Decide recovery and cancellation policy — OPEN DECISION

- Findings: F-010, F-011
- Size: **small** for the eventual contract change; **medium** if a new SPA
  recovery surface is approved
- Session recommendation: **separate-recommended**
- Decide whether a cancellation reason is mandatory and whether restoring a
  draft with inactive customer/product references is allowed.
- Decide whether `system_admin` needs an archived-order list and restore action
  in the SPA, or whether the API is intentionally the recovery surface.
- After the decision, update request validation, service invariants, UI,
  permissions, and tests as one contract change.

## 7. Add real browser coverage for the M033 surfaces — DEFERRED

- Finding: F-019
- Size: **large**
- Session recommendation: **separate-recommended**
- Add browser coverage for create, edit, optional-field clearing, cancel,
  archive/restore if approved, portal filtering/pagination, and the confirmation
  failure/retry path. The current mock E2E spec covers only the list/confirm
  slice of sales orders and then switches to invoices.

## 8. Revisit exact list total aggregation — DEFERRED POLISH

- Finding: F-020
- Size: **small**
- Session recommendation: **separate-recommended**
- Decide whether the list KPI must be exact over serialized decimal money. If so,
  use a decimal/string aggregation helper instead of JavaScript `Number`; the
  persisted order totals are already BCMath-backed.

## 9. Remove PHPUnit data-provider deprecations — DONE

- Finding: F-021
- Size: **small**
- Session recommendation: **same-session-ok**
- Use PHPUnit attributes for the two status-transition data providers so the
  focused suite remains warning-free on the PHPUnit 12 migration path.

## Status

Contained items 1–3 and 9 are inherited implemented fixes and were reverified.
Items 4–5 remain blocked by module boundaries, item 6 needs a product/operations
decision, and items 7–8 are deferred follow-up work. The batch additions below
are not same-session fixes. Final module status is `📋 Plan Ready`.

## Batch2-agent-c additions

### 10. Give customer-portal sales orders a dedicated safe contract — DEFERRED

- Findings: F-022, F-023
- Size: **large**
- Session recommendation: **separate-recommended**
- Add customer-safe summary/detail resources with the fields declared by
  `PortalSoSummary`, `PortalSoDetail`, and `PortalSoItem`; map nested internal
  item data to the portal shape and keep internal workflow, notes, and linked
  operational fields out of customer responses.
- Update the B2B order controller to use those resources for dashboard/list/detail
  order payloads, then add API assertions for the exact allowlist and item shape.
- Update the portal types/rendering only after the API contract is explicit, and
  add a detail-page regression for product fields and totals.

### 11. Reconcile cancelled outgoing-QC projection — DEFERRED

- Finding: F-024
- Size: **medium**
- Session recommendation: **separate-recommended**
- Choose whether a cancelled latest inspection is skipped, rejected, or signals
  reinspection; implement that explicit state in the M033 chain projection and
  add a cancelled-inspection regression alongside the existing four QC states.
- Coordinate the choice with Quality and portal chain consumers before changing
  the shared presentation contract.

### 12. Use the actual MRP generation timestamp in the chain — DEFERRED

- Finding: F-025
- Size: **medium**
- Session recommendation: **separate-recommended**
- Keep the queued/active MRP date explicit and use `mrp_plans.generated_at`
  once a plan exists instead of presenting `confirmed_at` as the planning date.
- Add chain tests for queued and generated plans, coordinating with MRP's plan
  lifecycle contract.

The gate outcome for this batch is no source change: all four additions are
separate-recommended and the aggregate scope is not small.
