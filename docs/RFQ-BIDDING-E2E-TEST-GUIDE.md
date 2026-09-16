# Supplier RFQ Bidding E2E Test Guide

This guide tests the complete sealed supplier RFQ process using role-based accounts, from Purchase Request through supplier quotation, RFQ award, PO approval, receiving, incoming QC, and supplier return management.

## 1. Prepare Environment

Run:

```bash
docker compose up -d
docker compose ps
```

The database, API, SPA, queue, Redis, and scheduler-related services should be running.

For a disposable clean demo database only:

```bash
docker compose exec -T api php artisan migrate:fresh --seed
```

This is destructive. Do not run it against a database containing data you want to keep.

RFQ closure runs every minute through:

```bash
docker compose exec -T api php artisan purchasing:close-due-rfqs
```

Use the scheduled command automatically for a realistic test. Run the command manually only after the RFQ deadline has actually passed.

## 2. Role Accounts

All seeded accounts use:

```text
Password: password
```

Use separate browser profiles for each account. Internal and supplier sessions use HTTP-only cookies, so logging in as another account in the same profile can replace the current session.

| Account | Role | Use |
|---|---|---|
| `purchasing@ogami.test` | Purchasing Officer | Create PRs, create/publish/award RFQs, create/submit POs |
| `buyer2@ogami.test` | Purchasing Officer | Second buyer and segregation tests |
| `finance@ogami.test` | Finance Officer | Approve PRs and POs, evaluate commercial quotes |
| `finance2@ogami.test` | Finance Officer | Alternate Finance checker |
| `vp@ogami.test` | Vice President | Approve high-value PRs and POs |
| `qc@ogami.test` | QC Inspector | Review quality evidence and incoming inspections |
| `warehouse@ogami.test` | Warehouse Staff | Receive GRNs and handle warehouse return steps |
| `depthead@ogami.test` | Department Head | Supplier-return approval step |
| `production@ogami.test` | Production Manager | Supplier-return approval step |
| `portal@supp.test` | Supplier Portal User | Submit supplier quotations and confirm supplier actions |
| `employee@ogami.test` | Employee | Negative-access testing |
| `admin@ogami.test` | System Administrator | Technical administration only, not business approval |

## 3. Main Happy Path

### Step 1: Create Purchase Request

Use the Purchasing profile:

```text
purchasing@ogami.test
```

Open:

```text
/login
```

Navigate to:

```text
Purchasing -> Purchase Requests -> New Request
```

Fill the form:

- `Priority`: `Normal`
- `Department`: `Production`
- `Reason`: `Live RFQ resin sourcing`
- `Quantity`: `500`
- `Item`: `RM-001`
- `Estimated unit price`: `120.00`

Click:

```text
Submit for approval
```

A confirmation dialog appears. Click:

```text
Submit
```

Expected result:

```text
Purchase Request: Draft -> Pending
```

Record the PR detail URL.

### Step 2: Finance Approves the PR

Use a separate browser profile:

```text
finance@ogami.test
```

Open the PR detail URL.

Click:

```text
Approve
```

Confirm in the dialog:

```text
Approve
```

Because the example is high-value, the PR should require VP approval after Finance.

### Step 3: VP Approves the PR

Use another browser profile:

```text
vp@ogami.test
```

Open the same PR detail URL.

Click:

```text
Approve
```

Confirm:

```text
Approve
```

Expected result:

```text
Purchase Request: Pending -> Approved
PO conversion status: Sourcing pending
```

Do not click `Convert to PO`. For this test, choose RFQ sourcing instead.

### Step 4: Start the RFQ

Return to the Purchasing profile:

```text
purchasing@ogami.test
```

Refresh the approved PR detail page.

Click:

```text
Start RFQ
```

The four-step RFQ wizard opens at:

```text
/purchasing/rfqs/create?purchase_request={pr_hash_id}
```

### Step 5: RFQ Wizard, Requirements

On the `Requirements` step:

- Confirm the PR lines.
- Enter the `RFQ title`.
- Enter the sourcing instructions.
- Set a `Required delivery date` for the raw material if applicable.
- Confirm the quantity and unit.

Click:

```text
Next
```

Expected result:

- The PR lines are copied into the RFQ snapshot.
- The original PR remains the source record.
- The RFQ cannot silently change the PR quantity.

### Step 6: RFQ Wizard, Suppliers

On the `Invite suppliers` step:

- Select the qualified supplier.
- If selecting a supplier marked `Exception review required`, enter an `Exception reason`.
- Do not select the same supplier twice.

Click:

```text
Next
```

Expected result:

- Supplier invitation is recorded.
- Supplier prices are still sealed.
- An invalid or duplicate supplier selection should be rejected.

### Step 7: RFQ Wizard, Terms and Documents

On the `Terms and documents` step:

- Set the `Submission deadline`.
- Use a future deadline with enough time to test the supplier response.
- For manual testing, use 15 to 30 minutes.
- For a quick automated-style test, use a few minutes and keep the queue running.

Click:

```text
Next
```

### Step 8: Create the RFQ Draft

On `Review and publish`, verify:

- RFQ title
- Deadline
- Source PR
- Supplier count
- Estimated PR amount

Click:

```text
Create draft
```

Expected result:

```text
RFQ: Draft
PR: Sourcing pending
```

You are redirected to the RFQ detail page:

```text
/purchasing/rfqs/{rfq_hash_id}
```

### Step 9: Upload Requirement Documents

As Purchasing, on the RFQ detail page:

- Find `Requirement document`.
- Choose a PDF or image.
- Click:

```text
Upload requirement
```

Expected result:

- The document appears under `RFQ documents`.
- The file is stored privately.
- The supplier can access it only through its invited supplier portal route.

### Step 10: Publish the RFQ

On the RFQ detail page, click:

```text
Publish
```

Expected result:

```text
RFQ: Draft -> Open
```

The invited supplier should now see the event in the supplier portal.

## 4. Supplier Quotation

Use a separate browser profile:

```text
portal@supp.test
```

Open:

```text
/portal/supplier/login
```

Navigate to:

```text
Supplier Portal -> Supplier RFQs
```

Open the latest RFQ.

Click:

```text
Prepare quotation
```

### Commercial Response

Fill:

- `VAT inclusive`
- `VAT amount`
- `Freight`
- `Other charges`
- `Quote valid until`
- `Payment terms`
- `Formal quotation PDF`
- `Notes`

### Quality Evidence

Upload any applicable documents:

- `Resin datasheet`
- `Certificate of analysis`
- `Safety document`
- `Compliance document`

### Line Response

For each RFQ line:

- Leave `No quote` checked if the supplier cannot supply it.
- Uncheck `No quote` to provide a quotation.
- Fill `Offered quantity`.
- Fill `Unit price`.
- Fill `Lead time days`.
- Fill `Line VAT`.
- Fill `Line freight`.
- Fill `Proposed delivery`.

For the main scenario:

```text
Offered quantity: 500
Unit price: 120.00
Lead time: 7
```

### Save Draft Test

Click:

```text
Save draft
```

Then:

1. Navigate back to the RFQ detail page.
2. Close or reload the browser page.
3. Click `Prepare quotation` again.
4. Confirm the previous draft values are restored.

The system should reuse the current draft version instead of creating duplicate drafts.

### Submit Quotation

Click:

```text
Submit sealed quotation
```

Expected result:

```text
Supplier quotation: Draft -> Submitted
Invitation: Viewed/Invited -> Submitted
```

Try submitting without a formal quotation PDF. The server should reject it.

## 5. Before RFQ Closure

Use the Finance profile:

```text
finance@ogami.test
```

Open the RFQ detail page.

Before the deadline:

- Supplier prices must not be visible to ordinary internal users.
- Finance can access authorized commercial comparison only after closure.
- The RFQ detail should show prices as sealed while status is `Open`.

Use the QC profile:

```text
qc@ogami.test
```

Open the RFQ detail page.

Expected result:

- QC can see RFQ requirements.
- QC cannot see supplier prices.
- QC cannot access commercial award controls before closure.

## 6. Close the RFQ

Wait until the deadline passes.

The scheduler should run:

```bash
docker compose exec -T api php artisan purchasing:close-due-rfqs
```

Expected result with a valid submitted quote:

```text
RFQ: Open -> Closed
```

Expected result with no valid submitted quote:

```text
RFQ: Open -> No award
PR: Sourcing pending -> Approved / Not started
```

Check the queue:

```bash
docker compose logs -f queue
```

## 7. Quality Review After Closure

Use:

```text
qc@ogami.test
```

Open:

```text
/purchasing/rfqs/{rfq_hash_id}/compare
```

QC should see:

```text
Quality evidence review
Commercial fields redacted
```

For each supplier line:

1. Select a quality status:
   - `Compliant`
   - `Exception`
   - `Blocking`
2. Enter evidence notes.
3. Click:

```text
Save review
```

### Blocking Quality Scenario

Set one quote line to:

```text
Blocking
```

Then log in as Purchasing and open comparison.

Attempt to select the blocked supplier line.

Expected result:

- The line cannot be awarded.
- The API rejects any attempt to award it directly.
- Another compliant supplier or unresolved partial award is required.

## 8. Compare and Award

Use:

```text
purchasing@ogami.test
```

Open:

```text
/purchasing/rfqs/{rfq_hash_id}/compare
```

The comparison should show:

- Supplier names
- Quote versions
- Delivered cost
- Unit price
- Offered quantity
- Lead time
- Proposed delivery
- Compliance status
- Historical supplier performance
- Historical tier and score when available

### Single Supplier Award

1. Select the supplier allocation checkbox.
2. Confirm the award quantity.
3. Enter `Award reason`.
4. If only one supplier submitted a valid response, enter `Single-response justification`.
5. Click:

```text
Award selected lines
```

Expected result:

```text
RFQ: Closed -> Awarded
One draft PO is created for the supplier.
```

### Split Award Scenario

For one RFQ line requiring 500 units:

1. Select Supplier A.
2. Set award quantity to `250`.
3. Select Supplier B.
4. Set award quantity to `250`.
5. Confirm the total does not exceed 500.
6. Enter the award reason.
7. Click:

```text
Award selected lines
```

Expected result:

- RFQ becomes `Partially awarded` if any lines remain unresolved.
- One draft PO is created per awarded supplier.
- Each PO line retains RFQ award and quote-version traceability.

## 9. Generated PO Approval

After awarding, return to the RFQ detail page.

Find:

```text
Generated purchase orders
```

Click the PO number.

The PO should show:

- `Auto` indicator
- RFQ link
- Source PR link
- RFQ commercial values
- Awarded line quantity
- Awarded unit price
- RFQ award link
- Supplier quote version link

Use Purchasing:

```text
purchasing@ogami.test
```

Click:

```text
Submit
```

Confirm:

```text
Submit
```

Expected result:

```text
PO: Draft -> Pending approval
```

### Finance Approval

Use:

```text
finance@ogami.test
```

Open the PO.

Click:

```text
Approve
```

Confirm:

```text
Approve
```

For a PO under ₱50,000:

```text
PO: Pending approval -> Approved
```

For a PO at or above ₱50,000:

```text
PO: Pending approval -> VP pending
```

### VP Approval

Use:

```text
vp@ogami.test
```

Open the PO.

Click:

```text
Approve
```

Confirm:

```text
Approve
```

Expected result:

```text
PO: Pending approval -> Approved
```

`admin@ogami.test` must not be used as a Finance or VP business approver.

## 10. Supplier Reconfirmation Scenario

To test an expired winning quotation:

1. Create and award an RFQ with a past `Quote valid until` date.
2. Open the generated draft PO as Purchasing.
3. Click `Submit`.
4. Expected result:

```text
Supplier reconfirmation required before approval
```

5. Log in as:

```text
portal@supp.test
```

6. Open:

```text
Supplier Portal -> Purchase Orders
```

7. Open the affected PO.
8. Click:

```text
Reconfirm quoted terms
```

Expected result:

```text
Reconfirmation: Pending -> Confirmed
```

9. Return to Purchasing.
10. Submit the PO again.
11. Finance and VP can now approve it.

The supplier is confirming the original quantity, price, and terms. Changing commercial terms requires a new quote/revision and a new Purchasing award decision.

## 11. Defective Raw Material Scenario

Use the approved PO created by the RFQ.

### Warehouse Receiving

Use:

```text
warehouse@ogami.test
```

Navigate to:

```text
Inventory -> GRN
```

Click:

```text
New GRN
```

Select the RFQ-generated PO.

Enter:

- Actual received quantity
- Warehouse bin/location
- Supplier lot number
- COA or moisture remarks if applicable

Click:

```text
Finalize receiving
```

Expected result:

```text
GRN: Draft -> Pending QC
PO: Sent -> Partially received or Received
```

The material is not yet usable inventory.

### QC Incoming Inspection

Use:

```text
qc@ogami.test
```

Navigate to:

```text
/quality/inspections?stage=incoming
```

Open the inspection linked to the GRN.

Record:

- Moisture result
- Resin/COA evidence
- Visual result
- Dimensional result if applicable
- Any required quality measurements

Click:

```text
Complete inspection
```

### QC Pass

Expected result:

```text
Inspection: In progress -> Passed
GRN: Pending QC -> Accepted
```

Then verify:

- Stock increased.
- Weighted-average cost recalculated.
- Stock movement was recorded.
- Draft supplier bill was generated if the chain listener completed.

### QC Failure

To simulate defective resin:

- Enter a moisture value outside tolerance.
- Or mark a visual parameter as failed.
- Or enter a critical dimensional value outside tolerance.

Expected result:

```text
Inspection -> Failed
NCR -> Created
GRN -> Rejected
Stock -> Not added
```

The system opens a supplier-return RMA with:

- Vendor
- PO
- GRN
- PO line
- GRN line
- Item
- Quantity
- Lot
- Unit price
- Defect reason

## 12. Supplier Return/RMA Flow

Navigate to:

```text
/return-management
```

Open the supplier-return RMA generated from the defective GRN.

### RMA Approval

Use the RMA requester/Purchasing profile to click:

```text
Submit for Approval
```

Then use:

```text
depthead@ogami.test
```

Open the RMA and click:

```text
Approve
```

Then use:

```text
production@ogami.test
```

Open the RMA and click:

```text
Approve
```

Expected result:

```text
RMA: Draft -> Pending approval -> Approved
```

### RMA Receipt

Use:

```text
warehouse@ogami.test
```

Open the approved RMA.

Click:

```text
Record Receipt
```

Enter the actual returned quantity and confirm.

Expected result:

```text
RMA: Approved -> Received
```

### RMA Quality Handoff

Use Purchasing or QC.

Click:

```text
Stage Quality Handoff
```

Then complete the return inspection if required.

Expected result:

```text
RMA: Received -> Inspected
```

### Return to Supplier Disposition

Open the disposition screen.

For the defective line:

- Select disposition: `return_to_supplier`
- Select the warehouse source location
- Confirm the return quantity
- Optionally select `Create replacement PO`

Click:

```text
Dispose
```

Expected result:

- Returned quantity leaves inventory.
- Source PO/GRN quantities are reconciled.
- Supplier credit note is created and linked.
- Replacement PO is created if selected.
- RMA stores the replacement PO link.

### Complete RMA

Click:

```text
Complete RMA
```

Expected result:

```text
RMA: Disposed -> Completed
```

## 13. Short or Missing Shipment Scenario

This is not a return because the missing quantity was never physically received.

Example:

```text
PO quantity: 500
Received quantity: 450
```

Warehouse finalizes the GRN with `450`.

Expected result:

```text
PO: Partially received
Remaining PO quantity: 50
GRN: Pending QC
```

The remaining 50 units stay outstanding. Use the PO/supplier dispatch process for follow-up.

For a completely missing shipment:

- No GRN is created.
- PO remains open or overdue.
- Purchasing follows up with the supplier.
- Supplier response/dispatch status is reviewed.
- A supplier-return RMA should not be created for goods that never arrived.

## 14. No-Award Scenario

To test no valid response:

1. Create and publish an RFQ.
2. Do not submit any supplier quotation.
3. Wait for the deadline.
4. Let the scheduler close the RFQ.

Expected result:

```text
RFQ: Open -> No award
PR: Sourcing pending -> Approved / Not started
```

Then:

1. Return to the approved PR.
2. Click:

```text
Start RFQ
```

3. Create a new sourcing event.

The previous no-award RFQ should not block a new RFQ.

## 15. Supplier Withdrawal Scenario

Use:

```text
portal@supp.test
```

1. Open the supplier RFQ.
2. Submit a quotation.
3. Open `Revise quotation`.
4. Enter a withdrawal reason.
5. Click:

```text
Withdraw quotation
```

Expected result:

```text
Quote: Submitted -> Withdrawn
Invitation: Submitted -> Withdrawn
```

The withdrawn quote must not be awardable.

## 16. Negative RBAC Checks

Perform these deliberately:

| Account | Attempt | Expected |
|---|---|---|
| `finance@ogami.test` | Open RFQ comparison | Allowed |
| `finance@ogami.test` | Award RFQ | Award button unavailable |
| `qc@ogami.test` | Open comparison | Quality-only redacted view |
| `qc@ogami.test` | See supplier prices | Not visible |
| `warehouse@ogami.test` | Open `/purchasing/rfqs` | Hidden or not found |
| `employee@ogami.test` | Open `/purchasing/rfqs` | Hidden or not found |
| `vp@ogami.test` | Award RFQ | Not allowed |
| `vp@ogami.test` | Approve high-value PO | Allowed |
| `admin@ogami.test` | Approve RFQ/PO as business step | Not allowed |
| Supplier A | Open Supplier B RFQ hash ID | 404/not found |
| Supplier A | Download Supplier B quotation | 404/not found |
| Supplier portal | Open internal `/purchasing/rfqs` route | Not allowed |
| VP | Approve before Finance | Forbidden; approval state unchanged |

## 17. Final Checklist

Record these results for the test run:

- RFQ draft created from approved PR.
- Required delivery date preserved.
- Qualified supplier invitation accepted.
- Non-qualified invitation requires an exception reason.
- Supplier can save and resume a draft.
- Formal quotation PDF required at submission.
- Quality documents remain private.
- Prices hidden before closure.
- RFQ closes automatically.
- Finance can evaluate but cannot award.
- QC can review quality without seeing prices.
- Blocking QC exception prevents award.
- Lowest compliant recommendation is advisory only.
- Partial/split awards are supported.
- One draft PO per awarded supplier.
- RFQ/quote-version/award/PO traceability preserved.
- Finance approval works.
- VP approval works at ₱50,000 or higher.
- Expired quote blocks PO submission.
- Supplier reconfirmation unlocks PO submission.
- Short shipment remains a PO balance, not an RMA.
- Defective receipt creates NCR and supplier-return RMA.
- Supplier RMA creates credit note and optional replacement PO.
- Cross-tenant supplier access is blocked.

Check the queue for failed RFQ lifecycle jobs:

```bash
docker compose exec -T api php artisan queue:failed
```

Check the RFQ scheduler:

```bash
docker compose exec -T api php artisan schedule:list
```

Run the RFQ browser suite with Chromium:

```bash
cd spa
PW_BROWSER=chromium node_modules/.bin/playwright test \
  e2e/rfq-bidding.spec.ts \
  e2e/live-rfq-bidding.spec.ts \
  --project=desktop-chromium
```
