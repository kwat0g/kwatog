# B2B restart readiness

## Repaired blockers

Two named demo purchase requests prevented MRP from evaluating SO-202610-0027. Their RM-001 lines used `pcs`, although RM-001's inventory base unit is `kg` and no physical conversion is configured. `PR-DEMO-CONVERT` line 4 and `PR-DEMO-BUDGET` line 5 now use `kg`. The repair was restricted to exact demo identifiers, reasons, statuses, quantities, and unconverted lines. It preserved quantities, prices, and approval states and wrote audit entries. Before-values are saved at `/tmp/b2b-demo-pr-units-before.json`. Existing sales orders were not deleted or reconfirmed.

The demo seeder now takes each selected item's inventory unit. Purchase request creation, editing, submission, and approval validate catalog-item units through the existing conversion service. Invalid edits roll back without deleting existing lines. Valid alternate units remain supported. Deleted request lines no longer contribute open supply.

## Process visibility

Sales order confirmation distinguishes successful planning, pending automation, and a failed MRP run. The record's flow retains the latest failure for that order after a reload; a different order's failed run cannot mark this order as failed. A missing BOM remains an actionable planning blocker even when an incomplete material plan exists.

The flow displays the responsible role and next action for planning, production setup, QC holds, and delivery. Missing product inspection specifications are named before production starts. Navigation links respect the viewer's permissions. Active records refresh every ten seconds and expose a manual refresh action. Failed process reads show an error and recovery action instead of silently removing the flow.

MRP Plans shows affected orders and recovery instructions for partial runs. Recent failures remain available through native disclosure controls after a newer run succeeds. Failed run-status reads are explicit. MRP outcome notifications reach Sales and PPC once per run, with destinations appropriate to each role. Existing notification preferences remain respected.

The customer portal retains safe process state without exposing internal planning errors or internal routes. One passing output batch can no longer hide another failed or unfinished batch in the SO's QC stage. Resolved scrap/rework dispositions preserve their original QC history.

## Verification

- Purchasing and end-to-end backend gates: 60 tests, 779 assertions, covering material planning, quality gates, order-to-cash, procure-to-pay, and inline rework.
- MRP and CRM regression suite: 152 tests, 505 assertions.
- CRM, customer portal, and MRP focused suite: 56 tests, 242 assertions.
- Final changed-service regression suite: 40 tests, 145 assertions.
- Notification targeting and deduplication check: passed, including role-specific destinations.
- Frontend flow and MRP panel checks: five passed; customer detail regression checks passed. Focused lint and the final production build passed.
- Headless Chromium customer order-to-cash: 48 checks passed, zero server errors, zero page errors, and no login or session recovery. Separate contexts signed in as Customer Portal, Sales, PPC, Production, QC, Warehouse, ImpEx, Driver, Finance, and Customer Service. Business mutations used the real API under those browser sessions. A dedicated fresh test database was used and cleaned up after the successful run.
- The browser order produced 200 pieces across two output batches, ran in-process and outgoing QC, proved automatic WO closure after QC, dispatched both deliveries, uploaded driver proof, obtained customer receipt confirmation, finalized invoices, collected payment, and closed the SO.
- Live read-only browser verification: SO-202610-0027 retains its blocker at widths 1440 and 390, retains it after reload, and recovers from an injected process-read failure. PPC can load MRP Plans. The injected failure was a browser interception, not a server/database mutation.

The browser runner now starts from the customer portal and uses the real PostCSS configuration. It uses PASS/FAIL checklist decisions and batch coverage. Existing critical dimensional checks still require their configured measurement evidence; that safety rule was not bypassed.

## Remaining business setup

BU-001 has no product inspection specification. QC must enter the actual requirements before that product can proceed through production. No tolerances or approval evidence were invented. WB-001's legacy agreements remain below its configured minimum selling price; do not lower the minimum or fabricate prices to make a test pass. Start the manual restart with WB-002 while the missing business setup is resolved.

The successful browser flow proves the tested manufacturing and delivery path. Backend procure-to-pay gates also passed; this run did not perform a new headless supplier procurement cycle or claim coverage of every module. Existing SO-202610-0027 remains confirmed with its historical failed run until PPC performs a recovery MRP run. The repaired units remove that specific cause; subsequent genuine setup issues remain visible.
