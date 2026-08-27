# M033 — Sales Orders Audit Report

Date: 2026-08-27
Domain: `commercial`
Module: `sales-orders`
Registry ID: `M033`
Tier: 2
Roles: `system_admin`, `customer-portal`
Dependencies: `customer-product-pricing`, `auth-session`, `rbac`
Final status: `📋 Plan Ready`

## Audit context and evidence

The preferred card `commercial/sales-orders` was claimed atomically as M033. The
allowed fallback `finance/fixed-assets-depreciation` (M031) was not used. The generated registry entry was read at
`audit/00-MODULE-REGISTRY.md:3,10`; the coordinator-owned registry diff was
left untouched.

The discovery set included the inherited module docs (`docs/README.md`,
`docs/PATTERNS.md`, `docs/DESIGN-SYSTEM.md`, `docs/USER-MANUAL.md`,
`docs/PROCESS-FLOWS.md`, `docs/SYSTEM-MODULE-AUDIT-2026-08-13.md`, and
`docs/QA-MATRIX.md`), the CRM implementation and routes, the internal SPA,
the customer portal order surface, all M033-focused feature tests, the related
MRP and chain boundary code, the current git diff, and file mtimes.

At the discovery fixed point, HEAD was
`d9c8371e96bceae9dd464c6c949e3ef3776d472a`; the pre-existing implementation
mtimes included `SalesOrderService.php` at 2026-08-26 03:34:57 +0800,
`SalesOrderController.php`, `SalesOrderRouteCoverageTest.php`, and the CRM
edit page at 2026-08-26 02:30:12 +0800, the CRM list page at 2026-08-20
05:08:23 +0800, and the portal orders page at 2026-08-26 02:30:12 +0800.
Parallel agents changed unrelated paths during the audit; those paths were not
edited or staged here.

The inherited session's checks used the isolated database
`ogami_test_m033_agent_a`; this batch's backend checks used the isolated database
`ogami_test_m033_agent_c`. The shared `ogami_test` database was not used.

## Discovery pass

### Surface inventory

- The API exposes options, list, detail, chain, create, update, archive,
  restore, confirm, and cancel routes at
  `api/app/Modules/CRM/routes.php:50-62`, each with a named permission.
- The controller delegates list validation to
  `api/app/Modules/CRM/Controllers/SalesOrderController.php:23-26` and keeps
  confirmation's structured error response at lines 83-109.
- `SalesOrderService` owns draft writes, pricing, lifecycle timestamps,
  cancellation and restoration; `SalesOrderResource` serializes hash IDs,
  money strings, enum labels, and linked records at
  `api/app/Modules/CRM/Resources/SalesOrderResource.php:20-121`.
- Internal create/edit/detail/chain-result pages and the customer portal list
  and detail pages were inspected. The portal list now preserves paginator
  metadata at `spa/src/pages/portal/customer/orders/index.tsx:28-43,129-132`.

### Findings

#### F-016 — Incomplete, fixed this session: nullable draft fields could not be cleared

Before this session, the edit payload used omitted/undefined values for blank
delivery terms, incoterm, and notes, while the service's null-coalescing update
kept the old values. The final implementation sends explicit nulls at
`spa/src/pages/crm/sales-orders/edit.tsx:137-149`, and the service now uses
`array_key_exists` to distinguish omission from clearing at
`api/app/Modules/CRM/Services/SalesOrderService.php:446-462`.
The round-trip test proves all three fields become null at
`api/tests/Feature/CRM/SalesOrderRouteCoverageTest.php:65-75`.

#### F-017 — Broken, fixed this session: malformed list filters reached the query

The prior list action accepted raw query data, so an invalid date could reach
`whereDate` and a negative page size could reach the paginator. The new
`ListSalesOrderRequest` rejects invalid status/date/page input and bounds the
public filter surface at `api/app/Modules/CRM/Requests/ListSalesOrderRequest.php:11-29`;
the service retains a defensive `per_page` clamp at
`api/app/Modules/CRM/Services/SalesOrderService.php:263-295`. The route test
proves 422 responses before querying for invalid dates, status, and page size
at `api/tests/Feature/CRM/SalesOrderRouteCoverageTest.php:122-140`.

#### F-010 — Incomplete, open policy decision: cancellation reason

`CancelSalesOrderRequest` still permits an absent reason at
`api/app/Modules/CRM/Requests/CancelSalesOrderRequest.php:16-20`, and the
service appends an optional reason to notes at
`api/app/Modules/CRM/Services/SalesOrderService.php:662-666`. Whether every
customer-order cancellation requires structured justification is a
product/compliance decision; no contract change was guessed.

#### F-011 — Incomplete, open recovery-surface decision: archive/restore UX and invariants

The API restore route and permission are present at
`api/app/Modules/CRM/routes.php:58-60`, and the service restores under a row
lock at `api/app/Modules/CRM/Services/SalesOrderService.php:735-746`. The CRM
client exposes delete/restore at `spa/src/api/crm/salesOrders.ts:28-31`, but
the internal list has no archive visibility control at
`spa/src/pages/crm/sales-orders/index.tsx:28-43`, despite the reusable control
at `spa/src/components/ui/ArchiveFilter.tsx:13-40`. The owner must decide
whether API-only recovery is intentional, whether the SPA needs an archived
list, and whether restore must revalidate inactive references.

## Hardening pass

### Confirm/update/cancel invariants

Draft update and confirmation execute in transactions, lock the authoritative
sales-order row, lock referenced customer/products, and recheck active
references. Evidence is at
`api/app/Modules/CRM/Services/SalesOrderService.php:397-415` and
`api/app/Modules/CRM/Services/SalesOrderService.php:478-514`.
Cancellation locks the current order and checks downstream state before
changing status at `api/app/Modules/CRM/Services/SalesOrderService.php:649-680`.
The route-level permission and downstream guard coverage is at
`api/tests/Feature/CRM/SalesOrderRouteCoverageTest.php:209-265`.

#### F-005 — Broken, deferred outside M033: queued MRP can race cancellation

Confirmation dispatches automatic MRP after commit through
`api/app/Modules/MRP/Listeners/QueueMrpOnSalesOrderConfirmed.php:10-16`.
`MrpEngineService::runForSalesOrder()` locks/supersedes the prior plan at
`api/app/Modules/MRP/Services/MrpEngineService.php:84-107`, then loads the
sales order at lines 109-111 without re-locking and re-reading its current
status. A cancellation can therefore commit between confirmation and MRP's
creation of downstream plans/work orders. The fix belongs to MRP/job
coordination and was not made from this M033 card.

#### F-008 — Incomplete, deferred outside M033: cancelled chain semantics disagree

The M033 chain projection marks QC, MRP, production, delivery, and invoicing
as skipped for a cancelled order at
`api/app/Modules/CRM/Services/SalesOrderService.php:837-882`. The canonical
shared map instead maps `cancelled` to the final `closed` step at
`api/app/Common/Support/ChainDefinitions.php:25-51`, and the broadcaster uses
that map at `api/app/Common/Services/ChainBroadcaster.php:80`. This can make a
cancelled order appear complete in shared chain consumers while the local
chain says skipped. The historical timestamp backfill is already explicit at
`api/database/migrations/2026_08_26_030000_backfill_sales_order_lifecycle_timestamps.php:59-79`;
reconciling the canonical state and consumers is a shared-chain change, not an
M033-only fix.

#### F-012 — Broken, fixed before this session: forward lifecycle skips were rejected

The current forward-only table permits supported skips such as
`confirmed → delivered` and `partially_delivered → invoiced` at
`api/app/Modules/CRM/Services/SalesOrderService.php:69-77`. Both permitted and
refused transitions are now data-provider covered at
`api/tests/Feature/CRM/SalesOrderStatusTransitionsTest.php:129-169,177-190`.

## Polish pass

#### F-018 — Polish, fixed this session: edit date constraint was not visible

The edit form now sets each delivery-date input minimum to the watched order
date at `spa/src/pages/crm/sales-orders/edit.tsx:300-306`, matching the create
form and the authoritative service check at
`api/app/Modules/CRM/Services/SalesOrderService.php:209-217`.

#### F-019 — Missing, deferred: browser coverage does not cover the full M033 contract

The current mock E2E spec documents and exercises only the sales-order list and
confirm slice before moving to invoices at
`spa/e2e/sales-order-to-invoice.spec.ts:1-10,100-179`. It lacks real browser
coverage for create, edit/nullable clearing, cancel, recovery, portal filters
and pagination, and confirmation failure/retry. This is a large, separate
follow-up.

#### F-020 — Polish, deferred: list KPI aggregates serialized money with Number

The internal list sums API decimal strings with JavaScript `Number` at
`spa/src/pages/crm/sales-orders/index.tsx:50-52`. It is display-only and the
persisted totals are BCMath-backed, but exact decimal aggregation should be
decided and tested if the KPI is contractual.

#### F-021 — Polish, fixed this session: PHPUnit data-provider deprecations

The two transition providers use PHPUnit attributes at
`api/tests/Feature/CRM/SalesOrderStatusTransitionsTest.php:129,177`; the final
focused suite completed without PHPUnit deprecation warnings.

## Inherited findings reconciled

The prior M033 audit findings were rechecked rather than assumed complete:

- F-001 — Broken, resolved: the create-page confirmation failure path is
  syntactically valid and preserves a retryable draft at
  `spa/src/pages/crm/sales-orders/create.tsx:166-180,231-243`.
- F-002 — Incomplete, resolved: archive/restore is transactional, soft-delete
  aware, permissioned, and covered at
  `api/app/Modules/CRM/Services/SalesOrderService.php:735-746`,
  `api/app/Modules/CRM/routes.php:58-60`, and
  `api/tests/Feature/CRM/SalesOrderRouteCoverageTest.php:77-87`.
- F-003 — Missing, resolved: edit renders the incoterm selector at
  `spa/src/pages/crm/sales-orders/edit.tsx:253-259` and the API resource
  returns the field at `api/app/Modules/CRM/Resources/SalesOrderResource.php:38-41`.
- F-004 — Broken, resolved: reference rows are locked and active checks are
  repeated inside confirmation at
  `api/app/Modules/CRM/Services/SalesOrderService.php:484-507`, with customer
  and product race tests at
  `api/tests/Feature/CRM/SalesOrderRouteCoverageTest.php:142-196`.
- F-006 — Incomplete, resolved: the portal API returns a full paginator at
  `spa/src/api/b2b/customer.ts:20-21`, and the portal page sends page/status
  filters and renders metadata at
  `spa/src/pages/portal/customer/orders/index.tsx:28-43,129-132`.
- F-007 — Missing, resolved: edit lookup loading, empty, disabled, and retry
  states are present at `spa/src/pages/crm/sales-orders/edit.tsx:169-181,228-229`.
- F-009 — Missing, resolved: CRUD/restore, validation, permission, cancel
  guard, portal pagination, and no-generic-transition coverage is present at
  `api/tests/Feature/CRM/SalesOrderRouteCoverageTest.php:37-140,198-284`.
- F-013 — Incomplete, resolved: the no-BOM confirmation contract is explicit
  and asserts a warning rather than an impossible standard work order at
  `api/tests/Feature/CRM/SalesOrderChainBridgeTest.php:125-163`.
- F-014 — Broken, resolved: portal work-order enum values are normalized before
  labels are derived at `api/app/Modules/B2B/Services/CustomerPortalService.php:137-150`.
- F-015 — Missing, resolved: forward-skip and refused-transition regression
  coverage is present at
  `api/tests/Feature/CRM/SalesOrderStatusTransitionsTest.php:129-190`.

No issue was found in the documented transaction, active-reference, eager-load,
hash-ID, design-token, or permission patterns beyond the findings above.

## Verification

- Focused M033 backend suite on `ogami_test_m033_agent_a`: **60 tests,
  196 assertions passed**, exit 0, with no PHPUnit deprecation warnings.
- Customer-portal sales-order slice on the same database: **5 tests,
  10 assertions passed**.
- `php -l` passed for the modified CRM request, controller, service, and
  focused tests.
- Scoped ESLint passed with `--max-warnings 0` for the changed M033/portal
  TypeScript files.
- `docker compose exec -T api php artisan route:list --path=crm/sales-orders`
  showed the expected ten routes and no generic transition endpoint.
- Full SPA `npm run typecheck` remains non-green only for unrelated
  `spa/src/pages/assets/detail.tsx:6,67` (`qrcode` module and implicit `any`);
  it reported no M033 or portal diagnostics.
- The final tracked diff check and staged diff check were run before commit;
  no whitespace errors remained.

## Disposition

The contained M033 fixes (F-016, F-017, F-018, and F-021) are implemented and
verified. F-005 and F-008 require changes in MRP/shared chain ownership; F-010
and F-011 require product/operations decisions; F-019 and F-020 are deferred
follow-up work. This prior disposition is superseded by the batch re-audit below.

## Batch2-agent-c re-audit — 2026-08-27

The prior report, action plan, and fix log were inspected before trusting their
status. At this batch's fixed point, HEAD was
`fb9d308076224bd9081c4360a82f50d5e4e76024`; the current worktree contained the
coordinator's generated registry edit and an unrelated parallel-agent file,
with no M033 source diff. The M033 claim lock was held by this card throughout
the review. The fallback was not attempted.

### Discovery pass

The re-audit covered the CRM routes/controller/requests/service/resources, the
internal CRM SPA, the B2B order controller/service/resource boundary, customer
portal types and detail page, relevant MRP/QC chain code, focused tests, and the
documented order/chain contract. The CRM surface has the expected ten named
routes with route permissions at `api/app/Modules/CRM/routes.php:50-62`; the
customer portal order endpoints are separately scoped to the authenticated
customer at `api/app/Modules/B2B/Services/CustomerPortalService.php:108-159`.

#### F-022 — Broken: customer-portal sales-order item contract does not match the SPA

The B2B list and detail endpoints return the internal
`SalesOrderResource` at `api/app/Modules/B2B/Controllers/CustomerPortalController.php:70-93`.
Its item collection nests product fields under `product` and names the line
total `total` at `api/app/Modules/CRM/Resources/SalesOrderItemResource.php:14-27`.
The portal contract instead declares top-level `part_number`, `name`, and
`total_price` at `spa/src/types/b2b.ts:112-119`, and reads those missing keys in
`spa/src/pages/portal/customer/orders/detail.tsx:80-87`. A customer viewing a
detail therefore receives the wrong shape and can see empty part/description
cells and an invalid total display. The existing child-relation test only
checks relation IDs at `api/tests/Feature/B2B/CustomerPortalServiceTest.php:176-202`;
it does not assert the item payload shape.

#### F-023 — Broken: internal sales-order fields are exposed through customer-portal endpoints

The same internal resource emits workflow controls and internal fields such as
`next_statuses`, `is_editable`, `is_cancellable`, and `notes` at
`api/app/Modules/CRM/Resources/SalesOrderResource.php:31-44`; when relations are
loaded it also emits MRP, work-order, inspection, delivery, and invoice details
at `api/app/Modules/CRM/Resources/SalesOrderResource.php:59-116`. The B2B
dashboard and list wrap that resource at
`api/app/Modules/B2B/Controllers/CustomerPortalController.php:53-82`, while
detail explicitly loads child workflow relations at
`api/app/Modules/B2B/Services/CustomerPortalService.php:126-150`. The CRM form
labels notes as “Optional internal notes” at
`spa/src/pages/crm/sales-orders/create.tsx:379-386`. This violates the portal
safe-allowlist pattern already used by
`api/app/Modules/B2B/Resources/CustomerPortalInvoiceResource.php:10-18` and
requires a dedicated customer-safe order resource plus contract tests.

### Hardening pass

The transaction, row-lock, active-reference recheck, cancellation guard,
permission, hash-ID, and decimal-string paths were rechecked. Focused CRM
coverage passed, but the following state projection is inconsistent with the
owning QC state machine.

#### F-024 — Broken: cancelled outgoing inspection is projected as active forever

The QC enum documents `cancelled` as “voided before completion” and treats it as
terminal at `api/app/Modules/Quality/Enums/InspectionStatus.php:10-15,35-38`.
The M033 chain helper nevertheless documents cancelled inspections as
“in-flight” at `api/app/Modules/CRM/Services/SalesOrderService.php:893-898` and
returns `active` for every status other than passed or failed at
`api/app/Modules/CRM/Services/SalesOrderService.php:919-926`. After the latest
outgoing inspection is cancelled, the sales-order chain consequently reports
QC Outgoing as active instead of a terminal/skipped/reinspection state. The
focused chain tests cover pending, in-progress, passed, and failed only at
`api/tests/Feature/CRM/SalesOrderChainStageTest.php:35-113`, leaving this state
unprotected. The desired terminal presentation needs an explicit QC/product
decision, but the current projection is objectively incorrect for the declared
enum semantics.

### Polish pass

#### F-025 — Incomplete: MRP chain date is confirmation time, not planning time

The chain marks MRP as `active` while an order is confirmed but has no plan and
as `done` once `mrp_plan_id` exists at
`api/app/Modules/CRM/Services/SalesOrderService.php:848-854`. Regardless of
that state, the displayed “MRP Planned” date is always `confirmed_at` at
`api/app/Modules/CRM/Services/SalesOrderService.php:873-875`; the MRP plan has a
separate required `generated_at` timestamp at
`api/database/migrations/0086_create_mrp_plans_table.php:28-41`. A delayed or
queued plan therefore presents order confirmation as the planning date, which
is misleading in the chain history. The chain contract should choose an
explicit queued/null date and use the generated timestamp after planning.

### Verification for this batch

- Focused M033 CRM suite on `ogami_test_m033_agent_c`: **44 tests, 144
  assertions passed** (chain bridge/stage, route coverage, transitions, and
  lifecycle concurrency).
- Focused customer-portal sales-order slice on `ogami_test_m033_agent_c`:
  **7 tests, 14 assertions passed**.
- PHP syntax checks passed for the reviewed CRM/B2B implementation and focused
  test files.
- Scoped ESLint passed with `--max-warnings 0` for the reviewed M033 and portal
  TypeScript files.
- `docker compose exec -T api php artisan route:list --path=crm/sales-orders`
  showed the expected ten routes.
- `git diff --check` passed. No source fix was applied in this batch.

### Batch disposition

F-022 is a large portal contract/security change; F-023 is a large portal
allowlist change; F-024 is a medium cross-module QC-chain change; and F-025 is
a medium chain/MRP presentation change. Their majority is
`separate-recommended`, and the total scope is not small, so the gate permits
no same-session source fixes. Final status for this claim is `📋 Plan Ready`.
