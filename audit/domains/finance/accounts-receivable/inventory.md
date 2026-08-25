# M028 — Accounts Receivable inventory

Audit date: 2026-08-24  
Release target: 📋 Plan Ready  
Audit mode: discovery, hardening, and polish review with local evidence only

## Purpose and boundary

M028 covers the internal accounts-receivable workflow and the customer-facing AR boundary:

- customer master lifecycle, credit exposure, and statements of account;
- manual/prebill and delivery-backed invoices, finalization, cancellation, and collections;
- AR aging, CSV export, and scheduled dunning;
- customer credit notes and applications;
- official-receipt integration;
- customer-portal invoice, PDF, and statement views.

The audit does not implement changes in the chart-of-accounts, journal-ledger, customer-product-pricing, sales-order, delivery, or portal-authentication modules. Those modules were inspected as read-only dependencies where their behavior affects AR control integrity.

## Dependency exception

The live registry has no strict topological starting point among the remaining Not Started modules: M028 depends on chart-of-accounts-periods, journal-ledger, customer-product-pricing, sales-orders, and deliveries-proof, while those areas participate in a finance/CRM/supply-chain dependency cycle. M028 was claimed as the next justified Tier 2 exception after registry refresh. Dependency code was read-only context; no dependency module was modified.

## Surface inventory

| Surface | Primary evidence | Audit coverage |
|---|---|---|
| API routes and authorization | `api/app/Modules/Accounting/routes.php`; `RolePermissionSeeder.php` | Customer, invoice, collection, credit-note, statement, and export permissions |
| AR application service | `api/app/Modules/Accounting/Services/InvoiceService.php` | Draft/update, delivery gate, GL posting, cancellation, collections, aging |
| Customer and statement services | `CustomerService.php`; `StatementOfAccountService.php` | Soft-delete/restore, credit exposure, current and historical statements |
| Credit-note and receipt boundary | `CreditNoteService.php`; `OfficialReceiptService.php` | Finalization, application, correction lifecycle, OR integration |
| Journal boundary | `JournalEntryService.php`; `AccountingPeriodService.php` | Posting, reversal date/period, automated-source controls |
| Persistence | migrations `0047`–`0050`, `0207`, `0208`, `0268`, and `2026_08_13_*` | Foreign keys, status checks, source links, idempotency, receipt uniqueness |
| Internal API/UI | accounting resources, requests, controllers, and SPA AR/customer/invoice/credit-note pages | Account filters, customer balances, report/export controls, loading/empty states |
| Customer portal | `api/app/Modules/B2B/routes.php`; `CustomerPortalService.php`; `CustomerPortalController.php`; portal invoice pages | Tenant scoping, status visibility, resource/PDF data boundary |
| Background processing | `ArDunningService.php`; `api/routes/console.php` | Overdue selection, locking, tier dedupe, queue boundary, schedule |
| Verification | `tests/Feature/Accounting/*`, delivery handoff, and B2B portal tests | 65 targeted tests, 280 assertions passed |

## Observed workflow

1. A customer, delivery handoff, or internal user creates an invoice draft. Standard invoices are expected to carry a confirmed delivery and matching delivery lines; an explicitly approved prebill lifecycle bypasses that delivery gate.
2. Finalization locks the invoice, checks the posting period, reserves a number, posts AR/revenue/VAT through the journal service, and advances the linked sales order to `invoiced`.
3. A collection locks the invoice, checks the current balance, creates a cash/AR journal entry, and transitions the invoice through partial or paid.
4. AR aging and customer statements calculate current balances and expose an as-of date. Dunning runs daily against overdue finalized/partial invoices.
5. Finalized customer credit notes post a reversing journal and can be applied to an invoice. Official receipts have a service and migration, but collection recording does not call that service.
6. The customer portal scopes records by authenticated customer ID, but invoice list/detail/PDF paths reuse the internal invoice resource and do not exclude draft or cancelled invoices.

## Verification completed

The following Docker-backed focused suite passed on 2026-08-24:

    InvoiceCollectionTest.php
    InvoiceDraftNumberingTest.php
    InvoiceBirFieldsTest.php
    CreditNoteTest.php
    CreditNoteDoubleFinalizeRaceTest.php
    AgingReportTest.php
    RunArDunningCommandTest.php
    AutoInvoiceOnDeliveryConfirmTest.php
    CustomerPortalServiceTest.php
    CustomerPortalAuthTest.php
    PortalTokenCrossGuardTest.php

Result: 65 passed, 280 assertions, 47.75 seconds.

The tests cover normal invoice/collection/credit-note flows, stale finalize and credit-note locks, current aging buckets, dunning selection and retries, delivery handoff idempotency, portal authentication, and tenant scoping. They do not cover the negative, historical, status-boundary, receipt, resource-redaction, or stale-update cases recorded in the report.

## Evidence gaps

- No browser/e2e run was available for the internal accounting and customer-portal screens.
- No production database snapshot or deployed permission matrix was available; migrations and seeders were reviewed locally.
- No historical AR/statement fixture reconciles invoice and collection events at multiple as-of dates.
- No adversarial/concurrency fixture verifies stale draft updates, duplicate collections, account classification, party mismatches, target-invoice status, credit-note voiding, or receipt uniqueness.
- No full CI, deployed rollback, or production queue/worker drill was run in this audit session.
