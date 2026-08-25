# M052 — Production Routings Action Plan

Status: `📋 Plan Ready`  
Prepared: 2026-08-25  
Implementation changes this session: none

Items are ordered by data-safety and dependency impact. `same-session-ok` means the item is contained enough for a focused follow-up; `separate-recommended` means it touches financial calculations, state/version invariants, RBAC, cross-module effects, or a large/high-risk change.

1. **Enforce exactly one active routing per product and make version allocation concurrency-safe.** Add a business-level activation policy, lock the product/version allocation inside the transaction, deactivate all other active versions when creating/duplicating/activating, and return a typed conflict response for races. Add an explicit database defense where supported.
   - Scope: `large`
   - Session: `separate-recommended` — state invariant and concurrent writes.
   - Evidence: H-01, H-05.
   - Done when: concurrent requests cannot produce ambiguous active state; all downstream consumers resolve one intentional active version; conflict responses are client-safe.

2. **Preserve operation provenance for generated work orders.** Replace destructive in-place operation replacement with immutable routing versions or reject edits to operations already referenced by work. Define the behavior for existing work orders, inactive versions, and copied operation snapshots.
   - Scope: `large`
   - Session: `separate-recommended` — historical traceability and cross-module schema behavior.
   - Evidence: H-02.
   - Done when: historical work-order operations retain a stable routing-operation reference or an auditable immutable snapshot, and no update silently nulls provenance.

3. **Add service-level and request-level routing-definition validation.** Require active/non-deleted products, machines, and molds; enforce mold-product ownership and machine/mold compatibility; validate usable resource statuses; require distinct positive operation sequences; and define decimal precision/maxima for time and rate fields.
   - Scope: `large`
   - Session: `separate-recommended` — resource state and money-like rate validation cross module boundaries.
   - Evidence: H-03, H-04.
   - Done when: direct service callers receive the same domain rules as HTTP callers, invalid assignments cannot become active routings, and persisted decimals match the documented precision.

4. **Define and implement routing-change propagation to MRP.** Decide whether routing changes immediately replan, enqueue an idempotent replan, or mark dependent plans stale. Use a durable transaction/outbox boundary and cover BOM-cost recalculation plus plan/capacity invalidation.
   - Scope: `large`
   - Session: `separate-recommended` — cross-module side effects.
   - Evidence: H-06.
   - Done when: a committed routing change has observable, retry-safe downstream handling and no silently stale dependent plan.

5. **Make routing history and lifecycle auditable.** Decide whether routing and operation records use audit logging plus immutable deactivation, or a supported soft-delete/archive lifecycle. Add typed activation/deactivation rules and make historical versions inspectable.
   - Scope: `medium`
   - Session: `separate-recommended` — lifecycle and traceability policy.
   - Evidence: H-07.
   - Done when: who/when/what changed is queryable and archive/deactivation cannot bypass the active-version rule.

6. **Resolve the role matrix and add routing-specific authorization coverage.** Decide whether `production_manager` is view-only as documented or may manage routings. Align `RolePermissionSeeder`, frontend action visibility, route guards, and browser/API tests for `system_admin`, `production_manager`, and `ppc_head`.
   - Scope: `medium`
   - Session: `separate-recommended` — RBAC policy affects multiple surfaces.
   - Evidence: H-08, F-02.
   - Done when: seeded permissions, documented role boundaries, backend gates, and visible actions agree; view-only users can inspect without seeing edit actions.

7. **Provide a real view/detail experience and gate manage actions in the SPA.** Let `/:id` use view permission for a read-only detail state, add a separate manage/edit path or mode, and conditionally render New, Duplicate, Save, and destructive actions based on permissions.
   - Scope: `medium`
   - Session: `separate-recommended` — coupled to the RBAC decision.
   - Evidence: F-02.
   - Done when: a view-only user can open a routing detail page, while only manage users can mutate it and the UI does not advertise denied actions.

8. **Repair list filtering and historical-version discovery.** Make the frontend/backend use one search parameter for product part number/name, add the active-state filter, and keep filter state/pagination consistent with the shared `FilterBar` contract.
   - Scope: `small`
   - Session: `same-session-ok`.
   - Evidence: F-01, F-06.
   - Done when: entered product text changes results and inactive/active versions can be intentionally filtered.

9. **Complete the editor surface and large-dataset behavior.** Add the operation description control, use searchable/paginated lookup dialogs or comboboxes, expose loading/error/empty states, and replace the clipped wide table with horizontal scrolling or a responsive layout. Verify keyboard/focus behavior at narrow widths.
   - Scope: `medium`
   - Session: `same-session-ok` for UI-only work after the permission/detail decision.
   - Evidence: F-03, F-04, F-05.
   - Done when: all persisted fields are editable, all master records are reachable, failures are actionable, and the editor remains usable at the design-system target widths.

10. **Add a dedicated routing test matrix before implementation is marked verified.** Cover CRUD authorization, version/active invariants, duplicate/concurrent allocation, operation sequence validation, resource compatibility/status, historical work-order provenance, BOM/MRP propagation, resources, and view/manage UI behavior.
    - Scope: `large`
    - Session: `separate-recommended` — required safety net for the preceding changes.
    - Evidence: D-02 and all hardening findings.
    - Done when: focused backend tests and browser/API role tests run in a working test environment and the relevant failure cases are asserted.

## Handoff order

Start with items 1–3 and their tests, then item 4. Resolve item 6 before item 7 and the final UI pass. Items 8–9 are safe contained work once the permission/detail contract is settled. Do not mark this module Verified until item 10 covers the chosen lifecycle and propagation behavior.
