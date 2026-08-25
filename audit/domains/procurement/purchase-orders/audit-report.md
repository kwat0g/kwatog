# M037 — Purchase Orders Audit Report

Session: 2026-08-25  
Claimed module: `procurement / purchase-orders`  
Audit status at close: `🔁 Needs Re-audit`

## Why this was re-audited

The registry presented M037 as `📋 Plan Ready`, but the original report and
plan predated substantial uncommitted changes in the purchase-order service,
requests, routes, SPA, and supplier-facing DTO. The old `fix-log.md` recorded
no production fixes, so the existing findings could not be accepted without a
current evidence pass. This report supersedes the earlier discovery while
retaining its finding IDs for traceability.

## Scope and method

The audit is limited to the purchasing purchase-order module. Dependency code
was read only for contract and workflow context; no dependency module was
audited or changed. Evidence was checked in the backend service, requests,
resources, routes, lifecycle enum/constraint, the internal PO pages, and the
supplier-portal boundary. Findings are classified as `Broken`, `Missing`,
`Incomplete`, or `Polish`.

## Discovery

The live module contains:

- Internal CRUD and lifecycle routes in `api/app/Modules/Purchasing/routes.php:56-71`,
  including draft mutation, submit, approval, rejection, send, cancel, close,
  soft-delete restore, and PDF endpoints.
- The primary state and financial boundary in
  `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:142-206` and
  `:293-380`; money is calculated through `Money`, and lifecycle writes use
  service-only enum assignments.
- PR provenance validation in
  `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:740-787`, with
  hash-ID request resolution in
  `api/app/Modules/Purchasing/Requests/StorePurchaseOrderRequest.php:25-54`.
- A critical-shortage auto-PO path in
  `api/app/Modules/Purchasing/Services/AutoPurchaseOrderService.php:44-153`.
  It is live from the Inventory caller at
  `api/app/Modules/Inventory/Services/AutoReplenishmentService.php:49-64`.
- Internal list/create/detail surfaces in
  `spa/src/pages/purchasing/purchase-orders/index.tsx:146-250`,
  `spa/src/pages/purchasing/purchase-orders/create.tsx:99-207`, and
  `spa/src/pages/purchasing/purchase-orders/detail.tsx:125-191`.
- A supplier-facing DTO and status/action boundary read from the B2B module at
  `api/app/Modules/B2B/Resources/SupplierPurchaseOrderResource.php:11-62` and
  `api/app/Modules/B2B/Services/SupplierPortalService.php:49-189`.

No current route is an obvious stub. The main unresolved gaps are policy
decisions at module boundaries: the direct-create contract conflicts with the
current API/UI rule that every manual PO must originate from an approved PR,
and the critical auto-PO documentation still says “routed to VP” while the
canonical workflow has Purchasing, Finance, and thresholded VP steps.

## Findings and current disposition

### M037-F01 — Broken, P0: critical auto-PO used an impossible lifecycle state

The pre-session implementation generated the unknown sequence key `po`, wrote
`pending_vp` even though the enum and database constraint only accept
`pending_approval`, and did not create durable approval records. The current
implementation now uses the registered `purchase_order` sequence and canonical
enum at `api/app/Modules/Purchasing/Services/AutoPurchaseOrderService.php:101-135`,
inside a transaction with item/supplier locks at `:44-80`, and submits through
`ApprovalService` at `:135`. The enum and database contract are
`api/app/Modules/Purchasing/Enums/PurchaseOrderStatus.php:7-17` and
`api/database/migrations/2026_08_13_220000_add_remaining_lifecycle_status_checks.php:26`.

Technical repair: fixed in this session. A focused integration test was added
at `api/tests/Feature/Purchasing/AutoPurchaseOrderServiceTest.php:48-98`, but
the shared test database had no `migrations` table before assertions could run.

Open policy question: `PROCESS-FLOWS.md:488-493` and the Inventory caller
(`AutoReplenishmentService.php:49-54`) promise a VP-routed bypass, while
`api/database/seeders/WorkflowSeeder.php:68-74` defines the standard three-step
PO workflow. The canonical choice made in this session must be confirmed or
replaced consistently across workflow seed data, notifications, documentation,
and the test gate.

### M037-F02 — Broken, P0: supplier PO item contract mismatch

The current supplier DTO maps the portal contract explicitly at
`api/app/Modules/B2B/Resources/SupplierPurchaseOrderResource.php:48-62`, and
the portal types use the same string IDs and field names at
`spa/src/types/b2b.ts:46-59`. The controller returns that DTO for list/detail at
`api/app/Modules/B2B/Controllers/SupplierPortalController.php:76-99`.

Current disposition: fixed by pre-existing worktree changes. No B2B dependency
file was modified in this session.

### M037-F03 — Broken, P1: PR-line provenance was dropped on manual create

The internal create form carries `purchase_request_item_id` from the selected
PR at `spa/src/pages/purchasing/purchase-orders/create.tsx:145-155` and sends it
at `:189-207`. The service preserves and validates the source line and selected
item at `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:761-783`;
the converter also supplies the source line at `:264-279`.

Current disposition: fixed by pre-existing worktree changes. This needs the
blocked HTTP test gate before it can be marked verified.

### M037-F04 — Broken, P1: restore could not resolve soft-deleted POs

The route opts into trashed binding at
`api/app/Modules/Purchasing/routes.php:60-64`, and the service re-reads with
`withTrashed()` and locks before restoring at
`api/app/Modules/Purchasing/Services/PurchaseOrderService.php:661-673`.

Current disposition: fixed by pre-existing worktree changes.

### M037-F05 — Broken, P0: draft update/delete were stale/race unsafe

Both draft mutation paths now execute in `DB::transaction()` and lock/re-read
the authoritative PO before checking `Draft`: update at
`api/app/Modules/Purchasing/Services/PurchaseOrderService.php:293-305`, delete
at `:640-658`. Submit also locks before creating approval records at `:364-380`.

Current disposition: fixed in the current worktree. A real two-connection race
test remains part of the verification gate; the shared database prevented that
verification in this session.

### M037-F06 — Broken, P1: accepted incoterm was dropped

Incoterm is validated on create/update at
`api/app/Modules/Purchasing/Requests/StorePurchaseOrderRequest.php:35-46` and
`UpdatePurchaseOrderRequest.php:31-45`, persisted by the service at
`api/app/Modules/Purchasing/Services/PurchaseOrderService.php:181-196` and
`:326-337`, returned at
`api/app/Modules/Purchasing/Resources/PurchaseOrderResource.php:65-70`, and
rendered in the internal form/detail and PDF at
`spa/src/pages/purchasing/purchase-orders/create.tsx:317-322`,
`spa/src/pages/purchasing/purchase-orders/detail.tsx:179-190`, and
`api/resources/views/pdf/purchase-order.blade.php:27-33`.

Current disposition: fixed by pre-existing worktree changes; supplier/PDF
round-trip still needs an isolated contract test.

### M037-F07 — Incomplete, P1: budget state could remain stale after editing

The locked draft update recalculates the amount and budget assessment at
`api/app/Modules/Purchasing/Services/PurchaseOrderService.php:321-348`, and
clears warning/acknowledgment fields when no department is present at
`:345-357`. Approval still asserts/enforces the current PO budget before the
locked approval write at `:388-432`.

Current disposition: fixed in the current worktree. Upward/downward amount
coverage remains unverified because the focused suite could not initialize its
test schema.

### M037-F08 — Incomplete, P1: draft approval actions were exposed without records

The service now permits approval/rejection only for `PendingApproval` at
`api/app/Modules/Purchasing/Services/PurchaseOrderService.php:423-432` and
`:507-520`; the SPA exposes those actions only for that state at
`spa/src/pages/purchasing/purchase-orders/detail.tsx:125-143`.

Current disposition: fixed by pre-existing worktree changes. The terminal
rejection-as-cancelled policy remains documented in the UI at
`detail.tsx:449-470` and should be confirmed against the business vocabulary.

### M037-F09 — Incomplete, P1: supplier actions are only partly reflected by the DTO

The supplier service gates acknowledgement, shipment, and document operations
by status at `api/app/Modules/B2B/Services/SupplierPortalService.php:58-78`,
`:192-235`, and `:317-321`; invoice submission also requires an accepted GRN at
`:436-464`. However, the read-only supplier DTO still advertises
`can_submit_invoice` for all sent/receiving-stage POs without checking accepted
GRN state at `api/app/Modules/B2B/Resources/SupplierPurchaseOrderResource.php:32-37`.

Current disposition: endpoint hardening is present, but the capability
contract is incomplete. This is a cross-module B2B change and was deliberately
not modified under the purchasing-only scope. It requires a separate contract
decision/test.

### M037-F10 — Broken, P1: multipart uploads manually set the boundary header

The supplier API now passes `FormData` directly without a manually forced
`Content-Type` header at `spa/src/api/b2b/supplier.ts:165-185`.

Current disposition: fixed by pre-existing worktree changes. Browser upload
smoke coverage remains pending.

### M037-F11 — Incomplete, P1: purchasing officer role still overlaps maker/checker permissions

The high-severity conflict rule is seeded at
`api/database/seeders/SodConflictRuleSeeder.php:22-27`, while the
`purchasing_officer` role receives the full purchasing module, including both
PO create and approve, at `api/database/seeders/RolePermissionSeeder.php:616-621`.
`ApprovalService` blocks the same submitter from acting, but that is not the
same policy as removing the role-level conflict.

Current disposition: deferred for an explicit RBAC/SoD decision. Changing the
role seeder or shared authorization policy would exceed this module’s scope;
the plan remains `Needs Re-audit` until the organization chooses maker/checker
overlap versus role separation/exception monitoring.

### M037-F12 — Incomplete, P2: cancel visibility needed permission gating

The cancel route requires `purchasing.po.create` at
`api/app/Modules/Purchasing/routes.php:64-70`, and the internal detail page now
checks the same permission before rendering Cancel at
`spa/src/pages/purchasing/purchase-orders/detail.tsx:142-143`.

Current disposition: fixed by pre-existing worktree changes; permission UI
coverage remains unverified.

### M037-F13 — Incomplete, P2: internal PO IDs needed hash-string typing

The internal line type now declares string IDs at `spa/src/types/purchasing.ts:126-137`,
and the resource returns the hash-encoded source line at
`api/app/Modules/Purchasing/Resources/PurchaseOrderItemResource.php:13-20`.

Current disposition: fixed by pre-existing worktree changes.

### M037-F14 — Incomplete, P2: rejection reason validation was weaker than UI copy

The API now trims and enforces a 10–500 character reason at
`api/app/Modules/Purchasing/Requests/RejectPurchaseOrderRequest.php:16-27`; the
SPA uses the same ten-character minimum at
`spa/src/pages/purchasing/purchase-orders/detail.tsx:449-460`.

Current disposition: fixed by pre-existing worktree changes.

### M037-F15 — Polish, P2: list filters and overdue navigation were incomplete

The service supports vendor, overdue, date-range, and PO-number search filters
at `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:71-91`; the SPA
exposes those controls at `spa/src/pages/purchasing/purchase-orders/index.tsx:146-207`
and preserves the overdue predicate in the queue link at `:315-321`.

Current disposition: fixed by pre-existing worktree changes.

### M037-F16 — Missing/question: direct-create policy is contradictory

The process document says both “Convert from approved PR” and “Create directly”
are valid PO sources at `docs/PROCESS-FLOWS.md:539-549` and repeats “or create
directly” in its test procedure at `:566-568`. The live API requires
`purchase_request_id` at `api/app/Modules/Purchasing/Requests/StorePurchaseOrderRequest.php:35-42`,
and the UI requires an approved PR before rendering the form at
`spa/src/pages/purchasing/purchase-orders/create.tsx:104-109` and `:238-254`.

This is intentionally a question, not an assumed bug: decide whether the
process document is stale or the direct-create path is missing, then align the
request contract, UI, service exception for system-generated bypasses, and
acceptance tests.

## Polish pass

The internal list and detail pages use the design-system primitives (`PageHeader`,
`FilterBar`, `DataTable`, `Panel`, `Chip`, and `Button`) at
`spa/src/pages/purchasing/purchase-orders/index.tsx:183-250` and
`detail.tsx:179-259`. Amounts and identifiers use monospace values, lifecycle
states use text-bearing chips, and the list has an overflow-safe table wrapper.
This matches the Atelier requirements for dense tables, mono/tabular numbers,
and status text in `docs/DESIGN-SYSTEM.md:19-21`, `:225-241`, and `:435-444`.
The accessibility requirements for focus, labels, and table headings are stated
at `docs/DESIGN-SYSTEM.md:524-531`; no new purchase-order-specific polish
defect was found in the current diff.

The supplier DTO is intentionally smaller than the internal resource, which is
the correct boundary, but its capability field noted in F09 is a completeness
problem rather than a visual defect.

## Verification limits

- PHP syntax and targeted diff checks passed for the session changes.
- `spa`: `npm run typecheck` failed on unrelated existing errors in
  `spa/src/pages/assets/detail.tsx:6,67` and
  `spa/src/pages/return-management/detail.tsx:902`.
- The selected backend purchasing/B2B run could not be treated as product
  evidence: the shared run reported failures across the selected classes and
  was interrupted; the newly added focused test failed before assertions because
  the shared `ogami_test` database had no `migrations` table. Do not mark this
  module verified until the suite runs against a dedicated PostgreSQL test
  database.

## Open decisions before verification

1. Confirm the critical-shortage approval route: canonical PO workflow or a
   genuine VP-only workflow.
2. Decide whether manual direct PO creation is supported; reconcile
   `PROCESS-FLOWS.md`, the request/UI, and tests.
3. Resolve the purchasing-officer maker/checker permission overlap.
4. Align the supplier capability DTO with the accepted-GRN invoice gate in a
   separately scoped B2B change.

Until those decisions and the isolated verification gate are complete, M037
remains `🔁 Needs Re-audit`.
