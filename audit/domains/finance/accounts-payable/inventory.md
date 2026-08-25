# M027 — Accounts Payable inventory

Audit date: 2026-08-24  
Release target: 📋 Plan Ready  
Audit mode: discovery, hardening, and polish review with local evidence only

## Purpose and boundary

M027 covers the internal accounts-payable workflow and the supplier-facing invoice boundary:

- vendor master lifecycle and vendor balances;
- manual bills, purchase-order and goods-receipt provenance, three-way matching, draft/post/cancel lifecycle;
- bill payments and their journal entries;
- AP aging and CSV export;
- supplier-portal invoice submission, invoice detail, and statement-of-account views.

The audit does not implement changes in the accounting, purchasing, goods-receiving, journal, or supplier-portal modules. Those modules were inspected as dependencies where their behavior affects AP control integrity.

## Dependency exception

The live registry has no strict topological starting point among the remaining Not Started modules: M027 depends on chart-of-accounts-periods, journal-ledger, purchase-orders, and goods-receiving, while those areas participate in the same finance/procurement/manufacturing/quality dependency cycle. M027 was therefore claimed as the smallest justified Tier 2 exception after registry refresh. Dependencies were read-only context for this audit; no dependency module was modified.

## Surface inventory

| Surface | Primary evidence | Audit coverage |
|---|---|---|
| API routes and authorization | api/app/Modules/Accounting/routes.php; RolePermissionSeeder.php | Route middleware, bill/vendor/payment/aging permissions, export boundary |
| Bill application service | api/app/Modules/Accounting/Services/BillService.php | Creation, provenance, matching, posting, cancellation, payment, aging |
| Vendor application service | api/app/Modules/Accounting/Services/VendorService.php | Listing, filtering, soft-delete, restore boundary, open balance |
| Journal boundary | api/app/Modules/Accounting/Services/JournalEntryService.php; AccountingPeriodService.php | Posting, self-posting exemption, reversal period guard |
| Persistence | migrations 0043–0046, 0068, 0179, 2026_08_08_100001, 2026_08_13_120000, 2026_08_13_220000 | Keys, status checks, provenance, source-link uniqueness, payment lifecycle |
| Internal API/UI | BillResource.php; BillPaymentResource.php; accounting bills/vendors/AP-aging pages | Reviewer evidence, account selection, list/detail actions, loading and empty states |
| Supplier portal | B2B routes, SupplierPortalService.php, SupplierPortalController.php, portal invoice pages | Submission idempotency, scoping, response data boundary, review visibility |
| Verification | BillServiceTest.php; AutoCreateBillOnGrnAcceptedTest.php; AgingReportTest.php; BillItemIdTest.php; SupplierPortalServiceTest.php | 44 targeted tests, 171 assertions passed |

## Observed workflow

1. A bill can be entered manually or generated as a draft from an accepted goods receipt, including supplier-portal submission.
2. Bill creation normalizes monetary values, optionally matches a purchase order and accepted receipt, then either stays draft or posts AP/expense/VAT journal entries.
3. A posted bill moves through unpaid, partial, and paid states as payments create separate journal entries. Cancellation reverses a posted bill journal.
4. AP aging reads open bills and exposes an as-of date plus CSV output.
5. Vendors are soft-deleted and have a restore API; the internal list and supplier portal consume different views of the same bill resource.

## Verification completed

The following Docker-backed focused suite passed on 2026-08-24:

    BillServiceTest.php
    AutoCreateBillOnGrnAcceptedTest.php
    AgingReportTest.php
    BillItemIdTest.php
    SupplierPortalServiceTest.php

Result: 44 passed, 171 assertions, 45.33 seconds.

The tests cover normal bill creation/payment, accepted-GRN draft creation and listener idempotency, stale locks, canceled purchase orders, current aging buckets, CSV permission behavior, item IDs, supplier scoping, portal idempotency, and portal documents. They do not cover the financial-control gaps recorded in the report.

## Evidence gaps

- No browser/e2e run was available for the internal accounting and supplier-portal screens.
- No production database snapshot or deployed permission matrix was available; migration and seeder behavior were reviewed locally.
- No historical ledger fixture verifies AP aging at an as-of date across bills and payments.
- No adversarial/concurrency fixture verifies duplicate source billing, vendor mismatch, draft payment, account-class enforcement, or payment reversal.
