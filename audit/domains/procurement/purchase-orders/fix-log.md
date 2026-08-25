# M037 — Purchase Orders Fix Log

Session: 2026-08-25  
Claimed module: `procurement / purchase-orders`  
Final status: `🔁 Needs Re-audit`

## Session work

### M037-F01 — critical auto-PO lifecycle

File: `api/app/Modules/Purchasing/Services/AutoPurchaseOrderService.php:44-180`

Before: the live path used the unregistered sequence key `po`, wrote the
non-enum/non-constraint state `pending_vp`, used float currency arithmetic, did
not create `ApprovalService` records, and notified configured VP roles even
though the canonical PO workflow starts with Purchasing/Finance.

After: the service locks the item and preferred supplier inside one transaction,
uses `PurchaseOrderStatus` values and the registered `purchase_order` sequence,
calculates subtotal/VAT/total with `Money`, submits the PO through
`ApprovalService`, and notifies the first canonical approval role. The path now
enters `pending_approval` and uses the existing PO approval records.

Verification: `php -l` passed. Added
`api/tests/Feature/Purchasing/AutoPurchaseOrderServiceTest.php:48-98` covering
the enum state, money total, approval records, rejection of `pending_vp`, and
retry idempotency. The test could not reach assertions because the shared
`ogami_test` PostgreSQL database had no `migrations` table.

Policy still pending: the process flow and Inventory caller promise a VP-routed
bypass (`docs/PROCESS-FLOWS.md:488-493`,
`api/app/Modules/Inventory/Services/AutoReplenishmentService.php:49-54`), while
the selected implementation uses the canonical workflow seeded at
`api/database/seeders/WorkflowSeeder.php:68-74`. This requires a product
decision and isolated verification.

### M037-F05 — two-connection lifecycle race coverage

File: `api/tests/Feature/Purchasing/PurchaseOrderTwoConnectionRaceTest.php:44-175`

Before: the action plan called for concurrency evidence, but the module only
had same-process stale-model coverage; that cannot prove a losing request waits
on the authoritative PostgreSQL row lock before running its state guard.

After: added three PostgreSQL/`pcntl` harness tests covering stale draft update
versus submit, stale delete versus submit with the converted source PR left
untouched, and stale approval versus a concurrent terminal transition. The
tests use an explicit held PO-row lock and a second database connection, then
assert the losing operation cannot mutate or reopen state.

Verification: `php -l` and `git diff --check` passed for the new test. The
focused PHPUnit run could not reach setup because the configured PostgreSQL
host `db` is not resolvable; no race assertion ran.

## Current findings re-checked

The following production changes were already present in the shared worktree
when this session claimed the module; they were re-checked and not rewritten:

- F02: supplier PO DTO and portal types align at
  `api/app/Modules/B2B/Resources/SupplierPurchaseOrderResource.php:48-62` and
  `spa/src/types/b2b.ts:46-59`.
- F03: PR-line IDs are carried and validated at
  `spa/src/pages/purchasing/purchase-orders/create.tsx:145-207` and
  `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:761-783`.
- F04/F05: restore, update, delete, and submit use trashed binding or locked
  re-reads at `api/app/Modules/Purchasing/routes.php:60-70` and
  `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:293-380,640-673`.
- F06/F07: incoterm and budget recalculation paths are present at
  `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:321-357` and
  `:326-337`.
- F08/F12/F14: lifecycle/permission/reason checks align at
  `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:423-520`,
  `api/app/Modules/Purchasing/Requests/RejectPurchaseOrderRequest.php:16-27`,
  and `spa/src/pages/purchasing/purchase-orders/detail.tsx:125-143,449-470`.
- F10: supplier FormData calls no longer set a manual multipart header at
  `spa/src/api/b2b/supplier.ts:165-185`.
- F13/F15: hash-string types and list/overdue filters align at
  `spa/src/types/purchasing.ts:126-203` and
  `spa/src/pages/purchasing/purchase-orders/index.tsx:146-207,315-321`.

## Scope correction

The supplier resource is a B2B dependency. An incidental capability edit was
backed out during this session so the claimed purchasing module did not modify
dependency code. The accepted-GRN capability mismatch remains logged as F09
and is explicitly deferred to a separately scoped B2B change.

## Verification performed

- Registry refreshed and M037 atomically claimed as the first eligible unlocked
  Tier-3 Plan Ready module after dependency ordering.
- `php -l api/app/Modules/Purchasing/Services/AutoPurchaseOrderService.php` —
  passed.
- `php -l api/tests/Feature/Purchasing/AutoPurchaseOrderServiceTest.php` —
  passed.
- `git diff --check` for the session implementation/test files — passed.
- `spa`: `npm run typecheck` — failed only on unrelated existing errors in
  `src/pages/assets/detail.tsx` (missing `qrcode` module and implicit `any`)
  and `src/pages/return-management/detail.tsx` (duplicate JSX attribute).
- Focused backend Purchasing/B2B run was not a valid product gate: the shared
  run reported failures across the selected classes and was interrupted; the
  isolated new test failed before assertions because the shared test schema was
  missing the `migrations` table.

## Deferred items and why

- F01 route choice and dedicated test verification: requires product decision
  and a usable isolated PostgreSQL schema.
- F09 supplier capability contract: cross-module B2B change, outside this claim.
- F11 purchasing-officer SoD overlap: requires human/RBAC policy decision and
  shared seeder changes.
- F16 direct-create contradiction: docs/API/UI policy decision required.
- HTTP, race, multipart, portal, and filter acceptance coverage: blocked by the
  shared test database and must run in the dedicated verification environment.

M037 therefore remains `🔁 Needs Re-audit`; it is not a fresh Plan Ready bounce.
