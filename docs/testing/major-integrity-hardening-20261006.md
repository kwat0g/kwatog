# Priority business-module integrity fixes

Date: 2026-10-06. Status: the seven approved priority fixes are implemented and verified. The eighteen lower-priority audit findings, additional layout polish and broader automation remain planned in the [implementation plan](../superpowers/plans/2026-10-05-remaining-business-module-hardening.md).

## Resulting behavior

| Finding | Change | Important boundary |
|---|---|---|
| F1: balance-sheet contra signs | Credit-normal contra assets reduce assets; debit-normal contra equity reduces equity. Section totals use the section's orientation. | Trial-balance normal balances and posted entries are unchanged. |
| F2: duplicate partial credit application | New HTTP applications require an operation UUID. Identical authorized retries return the original application without changing balances twice. Reusing a key with a different credit, target, amount or actor returns validation errors. | A new key is a new intent. Historical applications are not guessed to be duplicates. |
| F3: backdated disposal | Disposal before the latest posted depreciation month is rejected when requested and again at final execution. New depreciation between approvals cannot bypass the guard. | No automatic correction or reversal of posted history. Rejected final execution leaves the asset undisposed and the final approval pending. |
| M3: cancellation releasing faulty equipment | Starting maintenance captures the prior equipment condition. Canceling an unrepaired machine retains its fault and open downtime; replacement maintenance can claim that downtime. Known serviceable administrative holds can release only when ownership, sibling and fault checks allow it. | Legacy unknown conditions remain unavailable. Cancellation does not count as performed maintenance or free production-owned equipment. |
| M5: duplicate spare issue | New HTTP spare issues require an operation UUID. Identical authorized retries return the original usage, movement and cost contribution, even if the maintenance WO has since completed. Changed payload or actor is rejected. | Physical issue confirmation remains required. M6 multi-lot usage is a separate planned slice. |
| S1: shipment PDF order quantities | Generated packing lists and commercial invoices are explicitly internal drafts. Non-draft shipment receipts supply quantities and line value where present; otherwise the draft explicitly represents PO intent. Receipt base quantities are converted to purchase units before applying PO unit prices. | Supplier-issued documents remain required for clearance. Draft merchandise value excludes taxes; no guessed shipment manifest or official supplier invoice is created. |
| X1: stale export authorization | Export generation reloads active owners and current grants. Queued delivery rechecks the current schedule, module, columns, filters and authorization context. Revocation, disablement or changed scope prevents sending, removes the stale artifact and pauses the schedule with an error. | Transient infrastructure failures remain retryable. Explicit ownerless system mail remains supported; deleted user schedules never become system sends. |

Credit and spare issue identities use nullable unique UUID columns and PostgreSQL transaction advisory locks before business-row locks. UUID case is normalized before locking. The UI preserves each key through failed retries and uses the existing browser-compatible UUID helper, including on the current HTTP deployment. Existing maker/checker, account/party scoping, accounting-period, stock and production-ownership gates remain in force.

## Verification

| Check | Result | Evidence and limit |
|---|---|---|
| Backend regression and existing safeguards | **103 tests, 470 assertions passed** | [Final output](evidence/major-integrity-20261006/final-suite.txt). Finance, Assets, Maintenance, shipment PDF generation and export lifecycle suites ran serially against `ogami_test_pricing_20261004`. |
| Frontend regression checks | **104 tests passed across four files** | [Final output](evidence/major-integrity-20261006/final-frontend.txt). Credit retry UI, asset detail, request-key fallback and palette contrast. |
| TypeScript | **Passed** | `npx tsc --noEmit`; [captured output](evidence/major-integrity-20261006/typecheck.txt) is empty because the command succeeded without diagnostics. |
| Headless Chromium retry | **Passed** | [Results](evidence/major-integrity-20261006/browser-retry.json), [script](evidence/major-integrity-20261006/credit-retry-browser.cjs) and [screenshot](evidence/major-integrity-20261006/browser-credit-retry.png). A Finance session retried two intercepted failing application requests with the same operation key and no page exceptions. Business requests were fixtures, not live transactions. |
| Style and whitespace | **Passed** | Laravel Pint on the changed PHP files and scoped `git diff --check`. [Final style check](evidence/major-integrity-20261006/style-verification.txt). |
| Additive deployment schema | **Applied and verified** | [Migration output](evidence/major-integrity-20261006/migration-application.txt) and [schema check](evidence/major-integrity-20261006/schema-verification.txt). Existing business rows were not backfilled or repaired. |

The backend command uses `docker exec ogami-api php -d memory_limit=-1 vendor/bin/phpunit -c phpunit.pricing.20261004.xml` with these classes:

```text
tests/Feature/Operations/MajorIntegrityRegressionTest.php
tests/Feature/Accounting/StatementServicesTest.php
tests/Feature/Accounting/FinancialStatementBoundaryTest.php
tests/Feature/Accounting/CreditNoteTest.php
tests/Feature/Accounting/CreditNoteDoubleFinalizeRaceTest.php
tests/Feature/Assets/AssetDisposalApprovalWorkflowTest.php
tests/Feature/Assets/AssetDisposalMonthDepreciationTest.php
tests/Feature/Maintenance/MaintenanceOperationalSafetyTest.php
tests/Feature/Maintenance/MaintenanceWorkOrderMachineDowntimeTest.php
tests/Feature/Maintenance/SparePartUsageLostCostRaceTest.php
tests/Feature/Maintenance/MoldStatusRestoreOnCompleteTest.php
tests/Feature/SupplyChain/ImpexDocumentTest.php
tests/Feature/Infrastructure/ScheduledExportAuthorizationTest.php
tests/Feature/Infrastructure/ScheduledExportExecutionTest.php
```

One GPT-6.1-Sol agent implemented and verified the export slice, then independently reviewed the other changed logic. Root reviewed the agent's code and actual queued-send tests, normalized operation keys after review, and ran the combined final suite. The mold downtime fixtures were corrected to respect the existing production-ownership rejection rather than weakening that guard.

These checks prove the listed server invariants and UI retry behavior. They do not certify historical ledgers, real bank/customs integrations, simultaneous-request load, physical warehouse handover or a full B2B transaction E2E run. Earlier failing probe/intermediate outputs in the evidence directory are retained history; the final outputs linked above are the completion evidence.

## Deployment and remaining work

The additive migration is `2026_10_06_000001_add_major_integrity_operation_identity`. Historical identity and pre-maintenance status values remain null. New HTTP credit/spare submissions without an operation key return validation errors before writes; reload older open pages to load the matching frontend. Internal service callers retain optional keys for compatibility.

If reverting application code, retain these new columns and their recorded identity evidence. Do not run the migration's `down()` against populated business data as an application rollback: it removes the new retry/snapshot metadata. Posted financial history and stock quantities are not repaired automatically by this change.

No live business transactions, production cron commands or live messages were used for verification. The additive schema migration was the intentional deployment database change. No unrelated user changes were staged or committed.

Next planned work starts with the narrower calendar, validation, multi-lot, lock-order and advisory availability defects; then the measured responsive layouts and bounded automation. F6/F7, technician eligibility and official shipment-manifest policy retain their explicit decision boundaries in the larger plan.
