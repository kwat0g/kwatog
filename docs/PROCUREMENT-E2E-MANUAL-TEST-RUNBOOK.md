# OGAMI ERP - Procurement E2E Manual Test Runbook

> Manual testing guide for the procurement process, from Sales Order and MRP demand through PR, PO, supplier handoff, GRN, incoming QC, inventory, billing, and payment.

## 1. Current Process

The current procurement chain has two demand origins:

1. Sales Order-driven procurement
2. Manual Purchase Request-driven procurement

The executable chain is:

```text
Sales Order
  -> MRP / material shortage
  -> Draft auto-generated PR
  -> PR submission
  -> Finance approval
  -> VP approval if amount >= PHP 50,000
  -> Approved PR
  -> Draft PO per supplier
  -> PO submission
  -> Finance approval
  -> VP approval if amount >= PHP 50,000
  -> Supplier dispatch / PO sent
  -> Draft expected GRN
  -> Warehouse receiving
  -> Incoming QC
  -> GRN accepted or rejected
  -> Inventory and weighted-average cost
  -> Draft supplier bill
  -> Three-way match
   -> Bill posting
   -> Payment request
   -> Finance checker approval
   -> VP final approval
   -> Payment journal posting
```

The live Playwright suite creates its own transactional records. Seeded data is
used only as master-data prerequisites such as customers, products, items,
vendors, warehouse bins, accounts, and RBAC users. It does not depend on seeded
PR, PO, GRN, bill, or payment records.

A manual PR enters at the `Draft auto-generated PR` stage and skips the Sales Order and MRP portion.

Primary implementation references:

- `docs/PROCESS-FLOWS.md:532-845`
- `api/app/Modules/MRP/Services/MrpEngineService.php`
- `api/app/Modules/Purchasing/Services/PurchaseRequestService.php`
- `api/app/Modules/Purchasing/Services/PurchaseOrderService.php`
- `api/app/Modules/Inventory/Services/GrnService.php`
- `api/app/Modules/Quality/Services/InspectionService.php`
- `api/app/Modules/Accounting/Services/BillService.php`

## 2. Resolved Pre-Test Finding

The initial live-test review found a blocker in the Sales Order-to-auto-PR path.

The MRP service creates an auto-generated PR with:

```text
requested_by = Sales Order creator
```

The department is resolved later from the requester's employee record. However, the seeded CRM user is:

```text
crm@ogami.test
role: sales_officer
employee: none
```

Relevant code:

- `api/app/Modules/MRP/Services/MrpEngineService.php:364-373`
- `api/app/Modules/Purchasing/Services/PurchaseRequestService.php:320-329`
- `api/database/seeders/DemoAccountSeeder.php:38`

The MRP service now resolves the automatic PR department from the initiating
employee where available and falls back to the PPC department for service-account
or otherwise department-less Sales Order creators. The live generated Sales
Order scenario verifies that the resulting auto-PR is submit-able.

Keep this as a regression check before the panel presentation. A failure to
resolve the department should now be treated as a regression, not bypassed.

## 3. RBAC Account Setup

All internal seeded accounts use:

```text
Password: password
```

Use separate browser profiles. Internal authentication uses one HTTP-only session cookie per origin. Logging in as another user in a second tab can replace the existing session.

| Account | Role | Main responsibility |
|---|---|---|
| `crm@ogami.test` | Sales Officer | Create and confirm Sales Orders |
| `customerservice@ogami.test` | Customer Service Officer | Maintain customers and handle complaints and returns |
| `ppc@ogami.test` | PPC Head | View MRP plans, run MRP, review shortages |
| `depthead@ogami.test` | Department Head | Create PRs for the user's own department |
| `purchasing@ogami.test` | Purchasing Officer | Create PRs, submit PRs, convert PRs to POs, submit and send POs |
| `buyer2@ogami.test` | Purchasing Officer | Second buyer for ownership and segregation tests |
| `finance@ogami.test` | Finance Officer | Approve PRs and POs, post bills, record payments |
| `finance2@ogami.test` | Finance Officer | Alternate Finance checker |
| `vp@ogami.test` | Vice President | Approve high-value PRs and POs |
| `warehouse@ogami.test` | Warehouse Staff | Finalize, accept, or reject GRNs |
| `qc@ogami.test` | QC Inspector | Record and complete incoming inspections |
| `impex@ogami.test` | ImpEx Officer | Imported shipment and customs flow |
| `portal@supp.test` | Supplier Portal User | Supplier acknowledgement, counterproposal, and shipment updates |
| `admin@ogami.test` | System Administrator | System configuration and recovery only |

### Current approval rules

- `department_head` may create PRs but is not currently an approval step.
- `purchasing_officer` may create and submit PRs and POs but is not currently an approval step.
- `finance_officer` is always the first approval step.
- `vice_president` is required when the amount is at least PHP 50,000.
- `system_admin` is not a business approver, even though it has wildcard permissions.
- A user cannot approve a record they submitted.
- The approval workflow is sequential. VP cannot approve before Finance.
- `buyer2@ogami.test` is useful for ownership tests but is not required by the current Finance -> VP chain.

Current workflow definition:

```text
Purchase Request:
  Finance -> VP when total >= PHP 50,000

Purchase Order:
  Finance -> VP when total >= PHP 50,000
```

Source: `api/database/seeders/WorkflowSeeder.php:58-86`

### Threshold matrix

| Amount | Finance | VP |
|---:|---|---|
| PHP 49,999.99 | Required | Skipped |
| PHP 50,000.00 | Required | Required |
| PHP 50,000.01 | Required | Required |

For PRs, the threshold uses the estimated PR total after supplier and price prefill.

For POs, the threshold uses the PO total, including VAT.

## 4. Environment Preparation

Use a disposable demo database for destructive scenarios.

Before testing with the role accounts below, set `SEED_DEMO_DATA=true` in the
local API environment and run `php artisan migrate:fresh --seed` (or
`php artisan db:seed`). Demo mode automatically seeds its required departments,
positions, catalog, and other reference data. Never enable this mode in
production.

The documented clean-demo sequence is:

```bash
docker compose up -d
```

The queue must be running for:

- Sales Order confirmation -> automatic MRP
- PR approval -> automatic PO conversion
- PO approval -> supplier dispatch preparation
- PO sent -> expected GRN
- GRN acceptance -> automatic supplier bill
- Stock movement -> MRP and reorder listeners

Monitor the worker during testing:

```bash
docker compose logs -f queue
```

The procurement chain overview is:

```text
/purchasing/chain
```

Its counts are cached for approximately 60 to 120 seconds. Refresh after the cache window before treating a count as incorrect.

### Seeded rehearsal records

| Record | Purpose |
|---|---|
| `PR-DEMO-CONVERT` | Approved PR ready for conversion to PO |
| `PR-DEMO-BUDGET` | High-value budget and VP approval rehearsal |
| `RMA-DEMO-SUP-READY` | Supplier-return rehearsal |

These are created by `GoldenPathDemoSeeder`.

## 5. Master Data Checklist

Before a live Sales Order test, verify:

- Active customer, such as Toyota Motor Philippines.
- Active finished product, such as `WB-001`.
- Active price agreement for the selected customer, product, and date.
- Active BOM for the product.
- Active raw-material items.
- Warehouse locations.
- Vendor records.
- Approved supplier or supplier listing for the required raw materials.
- Positive supplier price or item standard cost.
- Incoming quality plan or fallback incoming inspection behavior.
- An active accounting period.
- Correct chart of accounts, especially inventory, GRNI, VAT, AP, and cash or bank accounts.

Example product:

```text
WB-001 Wiper Bushing Standard
BOM:
  Resin A
  Black Colorant
  Standard Poly Bag
```

For a shortage test, the expected gross requirement is approximately:

```text
BOM quantity per unit x Sales Order quantity
```

The actual MRP shortage also considers:

- On-hand stock
- Reserved stock
- Safety stock
- Open PR quantities
- Open PO quantities
- Supplier lead time
- MOQ
- UOM conversion
- Waste factor

## 6. Test A: Sales Order to Procurement

### A1. Create and confirm the Sales Order

Account:

```text
crm@ogami.test
```

Navigate to:

```text
/crm/sales-orders/create
```

Create an order with:

- Customer: Toyota, Nissan, Honda, or another active customer
- Product: `WB-001`
- Quantity large enough to exceed available raw materials
- Delivery date after the order date
- Valid price agreement

Use **Save & Confirm**.

Expected result:

```text
Sales Order: draft -> confirmed
```

Verify:

- SO number is generated.
- Prices were resolved from the price agreement.
- VAT and total are calculated.
- The Sales Order has a ChainHeader.
- Confirmation does not fail credit-limit validation.
- An MRP job is queued after the transaction commits.

Important: confirmation does not immediately reserve inventory. Material reservation occurs later when a production schedule or Work Order is confirmed.

### A2. Review the MRP plan

Account:

```text
ppc@ogami.test
```

Navigate to:

```text
/mrp/plans
```

Open the plan linked to the Sales Order.

Expected result:

- MRP plan is `active`.
- The confirmed Sales Order is included as demand.
- Shortage lines show required materials.
- A planned Work Order is created for the finished product.
- One consolidated auto-generated draft PR is created for the shortage.
- A pending production schedule may be proposed.
- Materials are not yet reserved.

If the plan does not appear:

1. Wait for the queue worker.
2. Refresh the Sales Order and MRP plan page.
3. Check the queue logs.
4. Use the manual MRP run or rerun action as `ppc@ogami.test`.
5. Check the MRP diagnostics for `missing_bom`, stock, supplier, or data errors.

### A3. Review the auto-generated PR

Account:

```text
purchasing@ogami.test
```

Navigate to:

```text
/purchasing/purchase-requests
```

Filter for:

```text
Auto-generated: Yes
Status: Draft
```

Expected result:

- PR has an `AUTO` indicator.
- PR is linked to the MRP plan.
- PR reason references the SO and MRP plan.
- PR contains the shortage items.
- PR is still `draft`.
- Supplier and price may be populated only when the PR is submitted.

#### Verify automatic ownership

Try to submit the auto-generated PR.

Expected result after the ownership fix:

```text
The automatic purchase request has an owning department and can be submitted.
```

If the missing-department message occurs, record it as a regression. The live
Sales Order -> MRP -> auto-PR path is intended to be complete for the seeded CRM
account.

Do not silently bypass this during a panel demonstration. Fix the ownership
attribution before presenting the path.

### A4. Submit the PR

If the auto-PR has a valid owning department, submit it.

Expected result:

```text
PR: draft -> pending
```

The submission should:

- Resolve the owning department.
- Prefill the best supplier where possible.
- Prefill a positive price.
- Calculate the approval amount.
- Run budget assessment.
- Create Finance and VP approval records.
- Mark the VP record as `skipped` below PHP 50,000.
- Mark the VP record as `pending` at or above PHP 50,000.

Record:

```text
PR number
MRP plan number
SO number
Department
Supplier
Estimated total
Approval records
```

### A5. Finance approval

Account:

```text
finance@ogami.test
```

Use either:

```text
/approvals
```

or:

```text
/purchasing/purchase-requests/{id}
```

Approve the PR.

Expected result for a low-value PR:

```text
PR: pending -> approved
Finance approval: approved
VP approval: skipped
```

Expected result for a high-value PR:

```text
PR remains pending
Finance approval: approved
VP approval: still pending
```

### A6. VP approval

Account:

```text
vp@ogami.test
```

Approve only after Finance has approved.

Expected result:

```text
VP approval: approved
PR: pending -> approved
```

After final approval:

- `po_conversion_status` becomes `pending`.
- A `PurchaseRequestApproved` event is emitted.
- Automatic PO conversion is queued.
- Purchasing is notified.

### A7. Convert the approved PR to PO

Account:

```text
purchasing@ogami.test
```

Navigate to the approved PR and click **Convert to PO**.

Current SPA behavior:

- There is no standalone PO creation page.
- PO creation is reached through approved PR conversion.
- The conversion modal allows supplier selection per line.
- One PO is created per selected supplier.
- Lines without a supplier may remain unconverted.

Expected result:

```text
PR conversion status:
  pending -> converted
```

or:

```text
PR conversion status:
  pending -> partial
```

A partial result means some lines were converted and some remain unresolved.

If sourcing fails:

```text
PR status remains approved
PR conversion status becomes manual_required
```

Do not confuse `manual_required` with a rejected PR. It means the PR is approved but supplier, price, or automation data is incomplete.

### A8. Submit the PO

The Purchasing Officer submits the draft PO.

Expected result:

```text
PO: draft -> pending_approval
```

Verify:

- PO references the PR.
- PO references the correct vendor.
- PO line quantities came from the PR.
- Supplier price is authoritative.
- PO total and VAT are correct.
- PO approval timeline is created.

### A9. Approve the PO

Account sequence:

```text
finance@ogami.test
vp@ogami.test
```

For a PO below PHP 50,000:

```text
Finance approves
VP step is skipped
PO: pending_approval -> approved
```

For a PO at or above PHP 50,000:

```text
Finance approves
VP approves
PO: pending_approval -> approved
```

Purchasing cannot approve the PO even though that role has `purchasing.po.approve`; it is not a current workflow step.

### A10. Dispatch and send the PO

Account:

```text
purchasing@ogami.test
```

After approval, inspect the supplier dispatch status.

Possible dispatch outcomes:

```text
portal_available
manual_required
failed
```

For `manual_required`:

1. Download the PO PDF.
2. Simulate transmission through an approved supplier channel.
3. Use **Mark as Sent**.

Expected result:

```text
PO: approved -> sent
```

`sent` means OGAMI actually transmitted the PO. It does not mean the supplier accepted it.

### A11. Optional imported-material shipment

Account:

```text
impex@ogami.test
```

Navigate to:

```text
/supply-chain/shipments
```

Create a shipment linked to the PO.

Expected shipment sequence:

```text
ordered
-> shipped
-> in_transit
-> customs
-> cleared
-> received
```

Upload:

- Bill of Lading
- Commercial Invoice
- Packing List

Calculate landed cost if required.

Important:

- This shipment record does not create stock.
- It does not create a GRN.
- It does not create a bill.
- Actual receipt still belongs to Warehouse.

The supplier portal has a separate supplier shipment update. Do not confuse the two shipment models.

### A12. Expected GRN

Account:

```text
warehouse@ogami.test
```

Navigate to:

```text
/inventory/grn
```

After the PO becomes `sent`, the queue creates an expected draft GRN.

Expected result:

```text
GRN: draft
```

The expected draft should contain:

- PO lines
- Ordered quantities
- Zero received quantities
- No stock movement yet
- No inventory increase yet
- No final QC result yet

### A13. Record physical receiving

Open the draft GRN and use **Finalize Receiving**.

Enter:

- Actual received quantity
- Warehouse location or bin
- Receiving UOM if different
- Material lot
- Supplier lot
- Expiry date if relevant
- Moisture percentage
- COA path
- Remarks

Expected result:

```text
GRN: draft -> pending_qc
```

The PO changes to:

```text
sent -> partially_received
```

or:

```text
sent -> received
```

The PO physical receipt status does not mean the material is usable yet. Incoming QC still gates inventory release.

### A14. Incoming QC

Account:

```text
qc@ogami.test
```

Navigate to:

```text
/quality/inspections?stage=incoming
```

Open the inspection linked to the GRN.

Record:

- Dimensional measurements
- Visual pass/fail
- Functional pass/fail where applicable
- Moisture result
- Resin or COA verification evidence

Expected pass result:

```text
Inspection: draft/in_progress -> passed
GRN: pending_qc -> accepted or partial_accepted
```

Expected failure result:

```text
Inspection -> failed
NCR created
GRN -> rejected
No usable stock added
Supplier return process opened
```

If the GRN cannot be accepted while QC is pending, that is correct.

### A15. Verify inventory and WAC

Account:

```text
warehouse@ogami.test
```

Navigate to:

```text
/inventory/stock-levels
```

Open the item stock card.

Verify:

- Accepted quantity increased.
- Rejected quantity did not enter available stock.
- Stock movement references the GRN.
- Material lot and supplier lot are preserved.
- Weighted-average cost was recalculated.
- Accepted quantities, not merely physically received quantities, drove inventory valuation.

### A16. Review and post the bill

Account:

```text
finance@ogami.test
```

Navigate to:

```text
/accounting/bills
```

After GRN acceptance and queue processing, an automatic draft bill should exist.

Expected result:

```text
Bill: draft
```

Review:

- Vendor
- PO reference
- GRN reference
- Accepted quantity
- Unit price
- VAT
- Total
- Three-way match result

Post the bill only when the match is acceptable.

Expected result:

```text
Bill: draft -> unpaid
```

### A17. Record payment

Use the Finance account to prepare the payment request. The request does not
post cash or change the bill balance yet.

Enter:

- Payment amount
- Payment method
- Payment date
- Reference number
- Cash or bank asset account

The SPA sends a stable `Idempotency-Key` for the payment attempt. Retrying the
same submission reuses the key and must not create a duplicate payment.

Expected results:

```text
Payment request:
  pending_approval

Finance2 approves the Finance step.
VP approves the final step.

Partial payment after final approval:
  Bill: unpaid -> partial

Full payment:
  Bill: unpaid or partial -> paid
```

Expected payment journal:

```text
DR Accounts Payable
CR Cash or Bank
```

## 7. Test B: Manual PR to PO

This is the most reliable complete procurement demonstration because it does not depend on MRP queueing or Sales Order ownership.

### B1. Department-owned manual PR

Account:

```text
depthead@ogami.test
```

Navigate to:

```text
/purchasing/purchase-requests/create
```

Create a PR for the Production department.

Example:

```text
Item: RM-001
Quantity: 25 kg
Estimated price: PHP 120.00
Estimated total: PHP 3,000.00
```

Expected behavior:

- Department field is locked to the Department Head's department.
- The user can create only for their own department.
- PR starts as `draft`.
- Submit changes it to `pending`.
- Finance approves.
- VP is skipped.

### B2. Purchasing-created central PR

Account:

```text
purchasing@ogami.test
```

Create a PR for another department, such as Maintenance.

Expected behavior:

- Purchasing can select another department.
- Purchasing can submit the PR.
- Purchasing cannot approve the current Finance step.
- Finance must approve it.
- VP must approve it at or above PHP 50,000.

This is the cleanest demonstration that the buyer is the maker, not the financial approver.

### B3. Use the seeded rehearsal PRs

Navigate to:

```text
/purchasing/purchase-requests
```

Open `PR-DEMO-CONVERT` and show:

- Approved status
- Suggested vendor
- Convert to PO action
- One PO generated per vendor

Then open `PR-DEMO-BUDGET` and use `vp@ogami.test` to demonstrate the pending VP approval.

## 8. Scenario Matrix

### Demand and MRP scenarios

| ID | Scenario | Manual action | Expected result |
|---|---|---|---|
| D1 | No material shortage | Confirm SO for a product with sufficient stock | MRP plan has no shortage and no auto-PR |
| D2 | Normal shortage | Confirm SO for a product with insufficient raw materials | One consolidated draft auto-PR is created |
| D3 | Multiple shortage materials | Use a BOM with resin, colorant, and packaging | One PR contains multiple shortage lines |
| D4 | Existing open PR | Create a shortage, then rerun MRP | Existing draft auto-PR is reused, not duplicated |
| D5 | Approved PR during MRP rerun | Submit and approve the PR, then rerun MRP | Progressed PR is not overwritten or cancelled; the latest plan still links to that PR and its POs |
| D6 | Stock arrives after shortage | Increase stock through an accepted GRN, then wait for MRP replan | Obsolete draft auto-PR may be cancelled |
| D7 | No active BOM | Confirm SO for a product without a valid BOM | MRP warning shows missing BOM; no standard WO/shortage PR for that line |
| D8 | Partial SO delivery | Deliver part of an existing SO, then rerun MRP | MRP plans only the remaining quantity |
| D9 | Shared stock between two SOs | Create two demand orders competing for the same material | Stock allocation follows MRP priority and demand rules |
| D10 | SO cancelled after auto-PR | Create SO, generate draft auto-PR, cancel SO | Draft/pending auto-PR is cancelled; approved or converted PR needs Purchasing to unwind it |
| D11 | PO pending approval | Convert an MRP PR to a draft PO, then rerun MRP | No second PR; plan shows the pending PO commitment, not stock in transit |
| D12 | Receipt pending incoming QC | Receive against the PO without accepting QC, then rerun MRP | No second PR; plan shows a QC hold, and the material remains unavailable for WO issue |
| D13 | Material in production | Accept the receipt, confirm/start the SO work order, issue its material, then rerun MRP before delivery | No second PR or duplicate WO for material already committed to the SO |
| D14 | Partial good output | Record good and rejected output, then rerun MRP | Only the uncovered good-unit requirement produces a replacement WO/PR; completed good output is not planned twice |
| D15 | Two SOs with one linked PO | Order raw material for SO1, then run MRP for SO2 separately | SO2 does not claim SO1's still-open PO quantity |

### PR and PO approval scenarios

| ID | Scenario | Manual action | Expected result |
|---|---|---|---|
| R1 | Low-value PR | Submit PR below PHP 50,000 | Finance pending, VP skipped |
| R2 | Exact threshold PR | Submit PR exactly PHP 50,000 | Finance pending and VP pending |
| R3 | High-value PR | Submit PR above PHP 50,000 | Finance then VP required |
| R4 | VP out of order | VP attempts approval before Finance | Forbidden; PR remains pending |
| R5 | Wrong role | Department Head or Purchasing attempts Finance approval | Forbidden; approval record unchanged |
| R6 | System Admin approval | System Admin attempts Finance or VP approval | Forbidden; wildcard does not bypass workflow role |
| R7 | Self-approval | Submitter attempts to approve their own record | Forbidden |
| R8 | Finance rejection | Finance rejects a pending PR with remarks | PR becomes rejected; later approval steps become skipped |
| R9 | Department scope | Department Head selects another department | Forbidden or validation error |
| R10 | Budget warning | Submit against an exhausted or overdrawn department budget | Finance acknowledgement required before approval |
| R11 | Budget hard block | Enable budget enforcement mode `block` and submit over-budget PR | Submission or approval is blocked according to budget policy |
| R12 | Missing sourcing | Approve an item with no qualified vendor or price | PR remains approved with `manual_required` conversion status |
| R13 | Multi-vendor PR | Assign different PR lines to different vendors | One draft PO per vendor |
| R14 | Partial conversion | Convert only some PR lines | PR becomes partial; unresolved lines remain available |
| R15 | PO threshold after VAT | Use a subtotal below PHP 50,000 but total reaches threshold after VAT | PO requires VP approval |
| R16 | PO rejection | Finance rejects a pending PO | PO becomes cancelled; source PR may reopen if no other live PO exists |
| R17 | Vendor creator segregation | Finance creates a vendor, then tries approving a PO for that vendor | Vendor-creator SoD blocks Finance; another Finance account can approve |

### Supplier, receiving, and QC scenarios

| ID | Scenario | Manual action | Expected result |
|---|---|---|---|
| Q1 | Manual PO dispatch | PO approval produces `manual_required` dispatch | Download PDF, transmit externally, mark PO sent |
| Q2 | Supplier acknowledgement | Supplier portal acknowledges sent PO | PO becomes acknowledged |
| Q3 | Supplier counterproposal | Supplier proposes a different quantity or price | PO becomes supplier-proposed; Purchasing accepts or rejects |
| Q4 | Supplier decline | Supplier declines PO | PO becomes supplier-declined and requires Purchasing decision |
| Q5 | Imported shipment | ImpEx creates shipment and advances customs statuses | Shipment tracking changes, but no stock or GRN yet |
| Q6 | Partial physical receipt | Receive less than PO quantity | PO becomes partially received; GRN enters pending QC |
| Q7 | Partial QC acceptance | Accept only part of received quantity | GRN becomes partial_accepted; only accepted quantity enters stock |
| Q8 | Over-receipt | Receive more than ordered quantity | Server rejects over-receipt at default 0% tolerance |
| Q9 | Over-receipt with tolerance | Configure 1% tolerance and receive 100.2% | Receipt may pass tolerance |
| Q10 | Pending QC acceptance | Try to accept GRN while inspection is pending | Acceptance is blocked |
| Q11 | Incoming QC pass | Record all measurements within tolerance | Inspection passes and GRN is released |
| Q12 | Critical measurement failure | Enter a critical dimensional value outside tolerance | Inspection fails, NCR is created, GRN is rejected |
| Q13 | Visual failure | Mark a visual inspection parameter as failed | Inspection fails and stock is not released |
| Q14 | Missing COA | Receive material without COA evidence | Receipt can be recorded, but COA verification remains false |
| Q15 | Direct logistics rejection | Reject GRN from the GRN screen | GRN is rejected and PO receipt quantities are reversed |
| Q16 | QC retry | Simulate failed incoming QC handoff | Use Retry Incoming QC with Quality permission |

### Accounting scenarios

| ID | Scenario | Manual action | Expected result |
|---|---|---|---|
| A1 | Automatic draft bill | Accept GRN and wait for queue | One draft supplier bill is created |
| A2 | Three-way match pass | PO, accepted GRN, and bill agree | Bill may be posted |
| A3 | Price variance | Enter a bill price above tolerance | Match becomes blocked |
| A4 | Quantity above accepted GRN | Bill more than accepted quantity | Match blocks bill posting |
| A5 | Extra bill line | Add a line not present on PO | Match blocks posting |
| A6 | Authorized override | Finance records an override reason | Bill may post with retained override evidence |
| A7 | Partial bill payment | Pay less than bill balance | Bill becomes partial |
| A8 | Full bill payment | Pay remaining balance | Bill becomes paid |
| A9 | Void payment | Void a posted payment | Payment journal reverses and bill status recalculates |
| A10 | Missing accounting setup | Disable or misconfigure required GL account | GRN or bill handoff becomes a visible manual exception |
| A11 | Second accepted partial receipt | Accept remaining quantity after a draft partial bill exists | Existing draft bill is synchronized; supplemental billing after a posted partial bill remains a separate policy decision |
| A12 | Supplier portal invoice | Supplier submits invoice after GRN acceptance | Existing GRN bill is reused; supplier invoice document-number capture remains a future enhancement |

### Return Management scenarios

Return Management is part of the procurement and quality exception path, not a separate demonstration-only module.

| ID | Scenario | Manual action | Expected result |
|---|---|---|---|
| M1 | Supplier return approval | Create supplier RMA from the generated accepted GRN, submit it, then use Department Head and Production Manager accounts | `draft -> pending_approval -> approved` |
| M2 | Supplier return receipt | Warehouse records the approved returned quantity | RMA becomes `received` |
| M3 | Supplier return Quality handoff | Purchasing or QC stages the return inspection | RMA becomes `inspected`; Quality handoff is generated or explicitly not required for item-only lines |
| M4 | Return-to-supplier disposition | Select `return_to_supplier` and a warehouse source location | Stock movement leaves inventory and RMA records the moved quantity |
| M5 | Supplier credit and replacement | Enable replacement PO during supplier disposition | Supplier credit note and replacement PO are created and linked to the RMA |
| M6 | Complete supplier return | Complete the disposed RMA | RMA becomes `completed` |
| M7 | Finance-only customer return rejection | Create a finance-only customer return without physical goods and submit it | Department Head can reject it; RMA becomes `rejected` and no stock movement occurs |

The live E2E suite creates these RMAs from the same newly generated procurement
transaction. It does not use `RMA-DEMO-SUP-READY` or other seeded RMA records.

## 9. Negative RBAC Tests

Perform these deliberately and record the UI or HTTP result.

| User | Attempt | Expected |
|---|---|---|
| `warehouse@ogami.test` | Open Purchasing list | Hidden or 403 |
| `qc@ogami.test` | Approve PR | 403 |
| `depthead@ogami.test` | Approve current Finance step | Forbidden because Department Head is not a current step |
| `purchasing@ogami.test` | Approve own submitted PR | Forbidden |
| `purchasing@ogami.test` | Approve PO as buyer | Forbidden because buyer is not a current step |
| `finance@ogami.test` | Submit a PR | 403 because Finance lacks PR create |
| `vp@ogami.test` | Post or pay a bill | Usually unavailable because VP lacks normal bill posting and payment permissions |
| `admin@ogami.test` | Approve Finance step | Forbidden because System Admin is not the workflow role |
| `depthead@ogami.test` | Create PR for another department | Forbidden or validation error |
| `portal@supp.test` | View another vendor's PO | Empty or forbidden due portal tenancy |

The Approval Queue is useful for demonstrating tasks, but it does not itself perform approval. It links the user to the source PR or PO detail page.

## 10. Evidence to Capture

For every completed scenario, record:

```text
SO number
MRP plan number
MRP run number
PR number
PO number
Shipment number
GRN number
Inspection number
NCR number
Bill number
Payment reference
Journal Entry number
```

Verify these categories:

| Evidence | What to confirm |
|---|---|
| Ownership | Creator, requester, department, vendor |
| Status | Correct forward-only transition |
| Approval | Role, approver, timestamp, remarks, skipped steps |
| Provenance | PR -> PO -> GRN -> Bill links |
| Quantity | Ordered, received, accepted, billed, paid |
| Quality | Measurements, result, NCR, disposition |
| Inventory | Stock movement, lot, bin, WAC |
| Accounting | Balanced journal, account lines, posting date |
| Security | Unauthorized user cannot view or mutate |
| Idempotency | Repeated click or retry does not duplicate documents |

## 11. Remaining Implementation Risks

### Medium risk

1. **Posted partial-bill continuation**

   A draft bill is synchronized when a partial GRN later receives its remaining
   quantity. If Finance posts the first partial bill before the remainder is
   accepted, supplemental-bill policy still requires a separate business decision.

2. **Supplier portal invoice versus automatic bill**

   The portal now reuses the existing auto-created bill for the same accepted GRN
   instead of creating a duplicate. Supplier invoice document-number capture
   against an existing bill remains a future document-field enhancement.

3. **QC disposition scope**

   Single-screen receiving now rejects `use_under_concession` and `partial_accept`
   explicitly instead of silently fully accepting the GRN. Use the dedicated GRN
   partial-accept flow for partial quantities and the RMA/MRB workflows for
   concession or supplier-return decisions.

4. **Documentation drift**

     Some documents still describe Department Head or Purchasing approval steps, standalone PO creation, and PPC schedule confirmation. The current executable code uses Finance -> VP for PR and PO approval, conversion-based PO creation, and Finance2 -> VP for bill payment approval.

## 12. Recommended Panel Strategy

Use three separate demonstrations:

1. **Stable seeded procurement**

   Open `/purchasing/chain`, show `PR-DEMO-CONVERT`, convert it to PO, then show the PO, GRN, three-way match, and bill/payment evidence.

2. **Live manual PR**

   Use `depthead@ogami.test` or `purchasing@ogami.test`, submit a low-value PR, approve it with `finance@ogami.test`, convert it with Purchasing, and continue through PO, GRN, QC, bill, and payment.

3. **Live Sales Order/MRP connection**

   Use `crm@ogami.test`, confirm an SO with a controlled shortage, show the MRP plan and auto-generated PR, verify the PPC owning department, and continue through PR submission and approval.

The strongest panel explanation is:

> A Sales Order creates production demand. MRP explodes the BOM and nets available supply. Any shortage becomes a consolidated Purchase Request. Approval is separated from creation: Finance checks the spend, and the Vice President signs high-value requests. Purchasing converts the approved request into supplier-specific POs. Receiving does not release stock immediately; incoming QC must pass first. Only accepted quantities enter inventory and billing.
