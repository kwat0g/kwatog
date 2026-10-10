# Sequential B2B acceptance — 6 October 2026

The fresh headless Chromium run `B2BSEQ2606D` completed all 17 checkpoints in order, with 347 successful checks and zero failed assertions. Each checkpoint throws on failure; later processes cannot start until the current checkpoint passes. This final run did not resume checkpoints or reuse existing business documents.

## Verified customer journey

The customer submitted an order for 100 pieces. Sales confirmed the customer-owned order without permission to edit its commercial lines. The response correctly reported one MRP plan and one proposed equipment schedule. PPC confirmed two regular Production employees, one output recorder, the machine and mold, and the exact 2 KG material reservation. Production management reviewed the slot into the workers' current HR shift before starting. Warehouse confirmed the actual material handover without issuing material twice.

In-process QC passed before the assigned employee recorded 100 pieces. Outgoing QC used PASS/FAIL for each requirement, with a problem description and one unique defective piece. An independent checker confirmed failure. The WO remained completed and open for quality resolution; no delivery was released from the unresolved failed batch.

Full-batch sorting established 99 good pieces and one repairable piece. The same WO handled the repair, with a separately reviewed repair slot and assigned recorder. Repair consumed the original defective piece and received a fresh one-piece lot without increasing the original production quantity or issuing the BOM again. Fresh independent QC passed the repaired piece. Corrective and preventive actions were recorded before NCR closure. The WO then closed automatically, and both employees lost temporary WO access.

The two approved lots produced shipments of 99 and 1 pieces. Dispatch assigned the driver and vehicle, reserved the approved lots, and advanced loading. The assigned driver recorded transit, delivery, and an uploaded receipt image. The customer confirmed receipt. Each shipment generated its draft invoice. Finance finalized and collected both invoices: PHP 1,386.00 and PHP 14.00, totaling PHP 1,400.00 including VAT. Only then did the SO close automatically.

## Verified supplier recovery

Purchasing created a PR for 100 raw components. Independent Finance approval automatically converted it to a PO. Purchasing submitted and sent the approved PO; the supplier accepted through its own portal. Sending automatically created a draft GRN. Warehouse finalized receipt of 100 components, which remained outside usable stock until incoming QC and material review.

Incoming QC recorded one defective piece using PASS/FAIL requirements and independent failure review. Full-batch sorting released exactly 99 good components and automatically created one supplier return with exact GRN and PO line provenance. Closing the NCR reused this return rather than creating another. No production WO was created for the supplier defect.

The return passed Department Head and Production Manager approval. Its already recorded custody was finalized without adding another component. The Quality handoff reused the original incoming evidence. Purchasing explicitly chose return-to-supplier and a replacement PO. The original PO short-closed; the replacement ordered exactly one component at the original PHP 10.00 unit price. Disposal and completion neither issued good stock again nor credited an unaccepted, unbilled component.

The supplier accepted the replacement PO. Its separate automatic GRN received one component and obtained fresh independent incoming QC approval. Final supplier stock was exactly 100 compliant components. The original failed inspection remained failed in history. Draft bills represented only accepted quantities: PHP 1,108.80 for the original 99 components and PHP 11.20 for the replacement.

## Bugs corrected during verification

1. `SalesOrderService::confirmWithChainResult()` counted a valid pending equipment proposal as needing manual scheduling because the WO's machine was intentionally unset before crew confirmation. The summary now reads the latest capacity-reserving production schedule, without confirming the WO.
2. `QualityStockHoldService` subtracted the defective piece consumed by repair from the independently approved 99-piece quantity. This falsely held one good piece and blocked its shipment reservation. Source-linked repair and NCR scrap consume rejected pieces and now preserve the separately approved good quantity. The repair regression proves 99 pieces remain reservable while the fresh unreleased repair lot remains held.

Browser selector, HTTP status, and resume assumptions were corrected against the actual application contracts. Application authorization and quality gates were not weakened to satisfy the browser runner.

## Proof and reproduction

```bash
O2CUI_RUN_ID=<unique-run-id> O2CUI_API_PORT=8243 O2CUI_SPA_PORT=5243 O2CUI_KEEP=1 node scripts/b2b-sequential-headless.cjs

docker exec -e QC_CHECKLIST_CAPTURE_ENABLED=false ogami-api php vendor/bin/phpunit -c phpunit.pricing.20261004.xml --filter 'SalesOrderChainBridgeTest|WorkOrderQcConnectionTest'

docker exec -e QC_CHECKLIST_CAPTURE_ENABLED=false ogami-api php vendor/bin/phpunit -c phpunit.pricing.20261004.xml --filter 'IncomingMrbDispositionTest|SupplierReturnSystemOpenTest|SupplierReturnLifecycleTest'
```

- Customer/production regressions: 35 tests, 161 assertions passed.
- Incoming/supplier regressions: 47 tests, 245 assertions passed.
- Combined focused proof: 82 tests, 406 assertions passed.
- PHP style verification passed for both changed services, both regression files, and both new prerequisite scripts. Both browser scripts passed `node --check`; the tracked changes passed `git diff --check`.
- Fresh journey: 17 checkpoints, 347 checks passed from 14:44:23 UTC to 14:52:46 UTC.
- Final database verification: 196 published outbox events, 45 completed listener runs, and zero unbalanced posted journals.
- Expected signed-out `/auth/user` 401 probes were recorded explicitly. There were zero unexplained API or console errors, zero 5xx responses, and zero browser exceptions.

[Recorded evidence summary](evidence/b2b-sequential-20261006/summary.json). Full assertion report and screenshots are retained in `/tmp/o2c-ui-chain-B2BSEQ2606D`; execution log is `/tmp/b2b-sequential-B2BSEQ2606D.log`.

## Scope and limits

All business documents were created through actual browser forms using separate customer, supplier, Sales, PPC, Production Manager, Warehouse, employee, QC maker, QC checker, dispatch, driver, Finance, and Department Head accounts. Fixtures created only disposable master data, accounts, shifts, and attendance prerequisites. Read-only API and SQL queries verified effects.

The browser database was `ogami_test_o2c_browser_o2cuib2bseq2606d`, with isolated storage. The live database was not modified by this task. The queue ran synchronously and email used the array driver; this proves application transitions and event handling, not production worker availability or external email delivery. WebSocket delivery was simulated by the existing harness. Supplier bill posting/payment, external carrier services, mobile layouts, and every possible exception path are outside this acceptance run. The earlier adjacent regression and QC scenario reports cover additional cases separately.

These fixes are present in the shared workspace. This task did not deploy or publish a frontend build.
