# Material handover workflow verification — 2026-10-08

Implemented the approved material-handover workflow at `/inventory/picking`, now labeled **Material Handover**. Automatic and manual issue sources group by WO; unlinked slips remain separate. Warehouse reviews the original locations, lots, returned/remaining quantities and eligible recipient before confirming. Physical confirmation creates receipt evidence without another stock deduction.

Normal WO and operation output require confirmed handover. Authorized managers may explicitly acknowledge received material with a reason for one recording; the exception is saved with manager name, time and original pending-source quantities. It does not clear the Warehouse task or bypass QC, attendance, material coverage, quantity, receipt or completion checks. WO completion still requires resolved handover.

## Verification

- Production handover and adjacent safety checks: **112 distinct tests passed**; 146 executions include 34 inherited duplicates. Final dedicated handover suite: 13 tests, 68 assertions.
- Inventory queue/receipt/reference suite: **18 tests passed, 151 assertions**, including archived-material history. Empty All-filter and archived-item regressions passed together: **2 tests, 16 assertions**.
- Frontend queue, output, self-service, reservation, Sidebar and legacy receipt control: **52 tests passed**, including fully-returned history. Breadcrumb suite: **8 tests passed**.
- TypeScript/Vite production build, focused ESLint, PHP Pint, design detector and diff whitespace checks passed. Breadcrumb label now matches Material Handover. Handover timestamps use the existing explicit Asia/Manila formatter in 12-hour format. Final desktop/mobile history confirmation passed without overflow.

## Real-role browser evidence

[Results](evidence/handover-role-playwright-20261008/results.json): **10 scenarios passed; zero browser exceptions or API 500 responses**. Disposable database `ogami_test_handover_browser_20261008`; temporary API and SPA. Manager, Warehouse and assigned employees signed in through actual UI. Employees punched attendance through the GPS attendance button. QC completed the existing startup draft only to isolate handover behavior; this test does not claim to solve the trial/QC gap.

Verified Start deducted raw stock from 100 to 90 and cleared the 10-unit reservation. Ordinary employee output was rejected while receipt was pending. Manager saved five good pieces through the explicit exception UI; the task remained pending. Warehouse reviewed the grouped queue with the recorder prefilled and confirmed the exact original ten-unit source. Stock stayed 90, reservation stayed zero. Exact receipt replay stayed single. Employee output then succeeded, increasing good output from five to ten without another raw issue.

Warehouse, HR and Sales/employee permission boundaries remained enforced. Desktop source review and 390px mobile history were captured; no page-width overflow. The fixture intentionally lacked an eligible finished-goods location, so successful output correctly reported the existing `manual_required` receipt status instead of inventing a receipt. Finished-goods automatic receipt and retry behavior is covered by the adjacent backend suite.

- [Warehouse review, desktop](evidence/handover-role-playwright-20261008/warehouse-review-desktop.png)
- [Receipt history, desktop](evidence/handover-role-playwright-20261008/warehouse-history-desktop-final.png)
- [Receipt history, mobile](evidence/handover-role-playwright-20261008/warehouse-history-mobile-final.png)

## Manual walkthrough

1. As Production manager, Start a ready WO. Its issued materials appear as pending handover.
2. As Warehouse, open **Material Handover** and expand the WO row. Review all original sources and the recipient; confirm only physically received material.
3. As assigned recorder, record output after QC and handover are ready. If handover is pending, the page names Warehouse as the owner.
4. For a real manager exception, check the explicit receipt acknowledgement and enter its reason. Verify the output history retains the exception and the Warehouse task remains pending.
5. Issue extra material or return an unused source. Extra unconfirmed material blocks the next normal output; fully returned sources remain history without pretending they were physically handed over.
6. Complete the WO only after all required material receipt evidence and existing production checks are resolved.

## Limits

No live business receipt, output, attendance or stock was fabricated for browser testing. One additive live migration added nullable exception-evidence columns; retain them during application rollback. No commit of the shared workspace was made.

Startup trial/QC remains a separate known blocker. Proposed fix: [startup trial design](../superpowers/specs/2026-10-08-trial-production-qc-design.md). Trial samples must be recorded physically before QC can approve them; no trial behavior has been implemented in this handover change.
