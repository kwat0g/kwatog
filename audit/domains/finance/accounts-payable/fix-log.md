# M027 — Accounts Payable fix log

Audit date: 2026-08-25  
Module status: ✅ Verified  
Implementation status: Production and test fixes applied; verification complete

## Audit actions

- Refreshed the module registry before selecting work.
- Documented the dependency-cycle exception in inventory.md; M027 was claimed because no remaining module satisfied a strict topological dependency order.
- Inspected the API, services, models, migrations, permissions, resources, internal SPA, supplier portal, recent changes, and release surface.
- Re-checked the AP surface after uncommitted accounting changes were found in the shared tree; preserved unrelated changes.
- Added and ran `AccountsPayableHardeningTest`: 6 passed, 18 assertions, on isolated `ogami_test_codex`.
- Re-ran the existing AP/GRN/3-way suites: 23 AP tests passed. The combined accounting run's only failure was an unrelated pre-existing AR CSV expectation mismatch.
- PHPStan passed for changed backend files; targeted SPA ESLint passed.

## Code changes

- F01 — `api/app/Modules/Accounting/Services/BillService.php:550`: payments now accept only Unpaid/Partial bills and require the matching posted bill journal under the bill lock. `api/database/migrations/2026_08_25_110000_harden_accounts_payable_controls.php:33` adds the durable payment lifecycle fields.
- F02 — `api/app/Modules/Accounting/Services/BillService.php:934` and `:1033`: bill lines require active Expense accounts; payment cash accounts require active Asset accounts in the cash/bank code range.
- F03 — `api/app/Modules/Accounting/Services/BillService.php:444`, `api/database/seeders/RolePermissionSeeder.php:173`, and `api/app/Modules/Accounting/Requests/StoreBillRequest.php:27`: blocking overrides moved to the draft-post checker boundary with a dedicated permission, reason, and maker/checker separation.
- F04 — `api/app/Modules/Accounting/Services/BillService.php:855` and `api/database/migrations/2026_08_25_110000_harden_accounts_payable_controls.php:55`: PO/GRN billing is serialized and one bill per accepted GRN is enforced; legacy duplicates fail migration for explicit reconciliation.
- F05 — `api/app/Modules/Accounting/Services/BillService.php:878` and `:906`: bill, PO, and GRN vendor identities are checked while the source rows are locked.
- F06 — `api/app/Modules/Accounting/Controllers/BillController.php:99` and `api/app/Modules/Accounting/Services/BillService.php:512`: cancellation accepts a validated reversal date/reason and persists cancellation timing; the existing journal reversal period guard remains authoritative.
- F07 — `api/app/Modules/Accounting/Services/BillService.php:763`: AP aging filters bills by as-of date and reconstructs posted payments through that date rather than using current balances.
- F08 — `api/app/Modules/Accounting/Controllers/FinancialStatementController.php:160` and `api/app/Modules/Accounting/routes.php:132`: AP aging has strict date validation and a dedicated export route/permission boundary.
- F09 — `api/app/Modules/Accounting/Resources/BillResource.php:31`: internal provenance, exception, and checker evidence is included in the internal resource and eager-loaded by `BillService::show`.
- F10 — `api/app/Modules/Accounting/Resources/SupplierBillResource.php:10`, `api/app/Modules/Accounting/Resources/SupplierBillItemResource.php:10`, and `api/app/Modules/B2B/Controllers/SupplierPortalController.php:297`: supplier responses use separate bill and item resources without internal match, exception, account, or journal data.
- F11 — `api/app/Modules/Accounting/Services/BillService.php:656`, `api/app/Modules/Accounting/routes.php:90`, and `api/app/Modules/Accounting/Enums/BillPaymentStatus.php:7`: posted payments can be voided through a permissioned journal reversal, preserving the original row and rebuilding bill balances.
- F12 — `api/app/Modules/Accounting/Services/VendorService.php:92` and `api/app/Modules/Accounting/routes.php:77`: vendor restore now binds trashed rows and uses a locked service boundary.
- F13 — `api/app/Modules/Accounting/Services/VendorService.php:25`: vendor lists include an open-balance aggregate.
- F14 — `api/app/Modules/Accounting/Resources/BillPaymentResource.php:25` and `api/app/Modules/Accounting/Services/BillService.php:95`: payment journal data is serialized from eager-loaded relations, removing the per-payment lookup.
- F15 — `spa/src/pages/portal/supplier/invoices/index.tsx:105`: supplier invoice empty-state copy now reflects the supplier's submitted invoices and AP review status.

## Remaining notes

- The source-allocation implementation deliberately codifies the existing one-accepted-GRN/one-bill workflow. Partial allocation across multiple bills remains a future product decision.
- Repository-wide SPA typecheck remains blocked by the unrelated malformed `spa/src/pages/crm/sales-orders/create.tsx`; `api/pint.json` is also an existing directory, so Pint could not load its config.

## Release note

All plan items are implemented and verified. The audit release script removed the lock and the registry was regenerated.
