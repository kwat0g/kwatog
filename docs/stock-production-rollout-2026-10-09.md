# Stock production and stock-first fulfillment rollout

Implemented and rolled out on 2026-10-09 after user approval. New ordinary Sales Order lines use approved stock first and manufacture any shortage. Historical lines retain their previous policy. Work orders produced for inventory have explicit stock purpose and no Sales Order.

## Operating flow

1. Sign in as `ppc@ogami.test`. Open Production > Work orders > Produce for stock.
2. Choose product and whole-piece quantity. The form displays a 14-day planning target using Manila time; an optional target completion date is available under Planning options. Plan production saves one planned work order and opens its record page. Use Confirm on that record to review equipment, workers and schedule; workers and the preferred production day can be adjusted there. Confirm the production plan once availability and materials permit it. The target sets the planning deadline and purchasing due date; it does not guarantee production completion or bypass capacity and material checks.
3. Production performs the existing material handoff, actual start, trial/output and quality steps. QC independently releases the actual output. Finished goods retain their output, inspection, receipt, lot and location evidence.
4. Confirm a new ordinary Sales Order. Approved usable stock is held automatically; MRP creates work only for the remaining demand. PPC can use Review fulfillment on the linked MRP plan to choose fresh production before work or shipment assignment progresses.
5. Once all supply is approved, the existing delivery flow receives one shipment with real source lines. Order holds transfer to shipment holds without reserving twice. Dispatch issues physical stock once. Driver proof, customer receipt and payment verification retain their existing flow.

### Cancelling stock production before it starts

PPC can use **Cancel WO** on the stock work-order record while it is Planned or Confirmed and has no actual start or output. The modal requires a reason and shows the assigned equipment, schedule and workers. One confirmation releases unused material holds, active crew bookings and machine/mold capacity reservations, cancels untouched child work orders and automatic draft purchase requests, and retains all records as history. Saved planning dates and equipment references are historical data after cancellation, not active reservations.

Cancellation is atomic. Issued materials, started operations, committed child schedules or crew assignments, and progressed purchasing block the entire operation. Warehouse must reconcile issued materials; purchasing must resolve progressed requests before a new cancellation attempt. Stock work orders cannot be deleted to bypass these checks. PPC receives the narrow `production.wo.cancel_stock` permission; it does not receive authority to start production.

Code rollback can leave the additive permission migration in place. Its downgrade intentionally preserves grants that might have been customized. Cancellation audit records and ended bookings must never be erased to force rollback.

Cancellation verification: nine dedicated tests passed with 53 assertions; 41 form tests and Chromium checks at 390 px and 1440 px passed. The broader 72-test probe had one shipment-rebuilding failure, reproduced against the original cancellation service, so that suite is not claimed passing. Read-only live checks confirmed the PPC permission and stock detail; work orders, schedules, crews, material reservations, stock levels and purchase requests remained byte-for-byte unchanged. Backup and verification artifacts are under `/tmp/stock-cancel-20261009`.

## Evidence

- 18 stock/allocation/schema tests passed with 97 assertions, including stock-only and mixed supply, safe BOM child retirement, fresh-production choice, replay, holds, dispatch, revoked QC and migration preservation.
- Focused broader run: 122 of 123 tests passed. The remaining worker-output test uses legacy `good_count`, `reject_count` and `defects` fields instead of `produced_count`. The same test fails with the original WorkOrderService restored. Production validation was preserved.
- Earlier connected backend run: 117 tests passed with 590 assertions. Other older fixtures that fail current trial/finish-first validation were also reproduced against unchanged code; these are not evidence of a passing full repository suite.
- 56 connected UI tests passed across eight files. TypeScript/Vite build passed in the isolated working copy. Desktop 1440 px and mobile 390 px browser checks passed for live quantity feedback and page overflow.
- Separate-process allocation, MRP replay and dispatch tests passed against a dedicated disposable database: 10 pieces held once, one shipment, one physical issue, zero remaining on-hand/held balance. No live business fixture was created.
- Additive migration applied as batch 21. Read-only authenticated route checks passed for the PPC stock work-order list and fulfillment preview. Preview did not change reserved quantities. Application HTTP check returned 200.
- Exact pre/post comparison preserved all prior columns on SO-202610-0025, SO-202610-0026, DR-202610-0001, DR-202610-0002, INV-202610-0001, CASE-202610-0001, OR-202610-0001 and their Sales Order lines.

## Readiness blockers

Products without an active finished-good inventory mapping: WB-002, PC-002, RC-001, BB-001, BU-001, RC-002, WB-001.

Products without an active current inspection specification: RC-001, RC-002, BB-001, PC-002, BU-001.

The read-only check found no unclassified internal work orders, duplicate output receipts, reserved finished-good bins, duplicate product codes, or active work orders missing a current resource slot. Existing order output was not reclassified as stock. Eligibility still requires current QC/spec approval, usable physical lot/bin stock, resolved receipt posting, and expiry/hold checks.

## Recovery and limits

Database backup: `/tmp/stock-production-20261009/pre-rollout.dump`. Feature file backups and pre/post snapshots are under `/tmp/stock-production-20261009`. These temporary artifacts need normal backup retention if required beyond this workspace session.

Code rollback may preserve the additive schema. The migration refuses downgrade once stock work, allocation, policy or purchasing provenance exists; never erase that history to force rollback.

Forecasting, min/max replenishment, automatic overtime and a second production or quality pipeline were outside scope. Full end-to-end business completion was tested through service fixtures and existing connected suites, not by posting artificial orders or production to the live application. Desktop/mobile browser coverage was limited to stock-form validation; combined assignment behavior uses the existing tested review component.

## Approved safety follow-up

Implemented the eight reviewed gaps: atomic stock-tree cancellation; ownership-aware incoming/material budgets; whole-piece child explosion before raw purchasing; automatic retirement and rebuilding of invalid untouched deliveries during MRP; current bin/lot validation; safe SO cancellation before shipment assignment; complete allocation relationship loading; and disabled product options with setup reasons and server revalidation. Cancelled child records remain visible. Assigned shipments remain protected and raise a named in-app review notice. Incoming purchasing remains planning coverage and does not authorize material issue or production start.

Final dedicated-database gate: 80 tests, 425 assertions. UI: five tests, production build, Chromium checks at 390 px and 1440 px. Separate-process checks conserved purchasing budgets, stock allocation/dispatch, cancellation/assignment, and recovery release. Existing five delivery-lifecycle fixtures still fail at trial-piece setup against unchanged services; they are not claimed passing. No live business fixture or historical cleanup was posted.

The pre/post read-only snapshot includes original/replacement SOs and deliveries, the return case, paid invoice, collection, payment submission, posted journals and journal lines. These records remained byte-for-byte unchanged. No orphaned cancelled stock trees, invalid live allocations or invalid scheduled automatic shipments were found. Existing master-data setup blockers were left for their responsible teams.

Safety artifacts and scoped rollback backups: `/tmp/stock-safety-20261009`. Retain these temporary artifacts through the normal backup process when needed. The implementation plan records unexercised edge combinations and the intermediate discarded database collision; full repository tests and a fresh complete manufacturing-to-cash browser journey were not run.

Post-rollout checks returned 200 for PPC work-order form options and the stock work-order list under a read-only database transaction. Form options show PC-001 eligible and the existing missing-mapping/spec products disabled with reasons. Public application HTTP returned 200. Schema preservation passed two tests with nine assertions. File hashes match the reviewed isolated changes. Generated `spa/dist` assets needed ownership repair before rebuilding; no application source or business data was changed by that repair.

The deployed workspace production build passed after output ownership repair. Final public HTTP check returned 200. Machine-readable verification summary: `/tmp/stock-safety-20261009/verification.json`.
