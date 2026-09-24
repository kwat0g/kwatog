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

In the **Supplier RFQs** panel, observe:

```text
Empty panel (no active RFQ yet)
```

Click:

```text
Start RFQ
```

You are directed to the one-page RFQ form:

```text
/purchasing/rfqs/create?purchase_request={pr_hash_id}
```

### Step 5: RFQ Form — Details and Lines

On the RFQ form:

**Details section:**

- Confirm the `Title` is prefilled as `RFQ for PR-<number>: <first line item> +N more`.
- Confirm the `Instructions` field is prefilled (if any were set at PR creation).
- Set the `Quotation deadline` to approximately 15 minutes ahead (for manual testing).
  Use 3 working days at 17:00 for realistic scenarios.

**Lines section:**

- Each PR line is listed with its quantity, unit, and available balance (what is not yet on a PO).
- Set an optional `Required delivery date` for any line (YYYY-MM-DD).
- Set an optional `Specification` for any line (free text, shared with suppliers).

For the test scenario:

```text
Line: 500 kg of RM-001
Required delivery date: (set to 7 days from now, optional)
Specification: (leave blank or add "ASTM standards")
```

### Step 6: RFQ Form — Invite Suppliers

**Suppliers section:**

View the supplier list showing every active vendor:

- Supplier name
- Chip: `Qualified` or `Not qualified`
- Reach: `Portal`, `Email only`, or `No contact`

For this test, invite three suppliers:

1. **Supplier A** (qualified, portal account):
   - Click to select.
   - No exception reason required.

2. **Supplier B** (qualified, portal account):
   - Click to select.
   - No exception reason required.

3. **Supplier C** (not qualified, no portal account):
   - Click to select.
   - A field `Exception reason` appears.
   - Enter: `Direct negotiation with trusted supplier`.

Expected state:

```text
Invited suppliers: 3
- Supplier A: Qualified, Portal
- Supplier B: Qualified, Portal
- Supplier C: Not qualified, No contact (exception allowed)
```

### Step 7: Save Draft

Click:

```text
Save draft
```

Expected result:

```text
RFQ: Draft
You are redirected to the RFQ detail page
```

At the detail page, verify:

- Status shows `Draft`.
- A stepper shows: `Draft` → `Open for quotes` → `Closed` → `Compare & Award` → `Awarded`.
- **Supplier RFQs** panel on the PR now shows this RFQ as `Draft`.

### Step 8: Edit and Test Draft Save

On the RFQ detail:

Click:

```text
Edit
```

Make changes:

- Change the title to `RFQ for RM-001 Resin — Updated`.
- Add or remove a supplier.

Click:

```text
Save changes
```

Expected result:

```text
Changes saved. RFQ remains Draft.
```

Verify the changes persisted when you re-open the edit form.

### Step 9: Upload Requirement Document

While the RFQ is in `Draft` status:

- Find the **Requirement document** section on the RFQ detail.
- Choose a PDF or image file.
- Click:

```text
Upload requirement
```

Expected result:

- The document appears under `RFQ documents`.
- It is stored privately.
- The document is shared with every invited supplier (both portal and email/no-contact).

### Step 10: Publish the RFQ

On the RFQ detail page, click:

```text
Publish
```

Confirm in the dialog:

```text
Publish
```

Expected result:

```text
RFQ: Draft → Open
Stepper now highlights: Open for quotes
Invited suppliers receive email notification with portal link and deadline
```

## 4. Supplier Quotation (Supplier A)

Sign in as `portal@supp.test` (Supplier A). The dashboard shows an **RFQs to quote** card; open it, or go to
`Supplier RFQs`, and open the RFQ. The invitation status becomes `viewed`.

Check the RFQ page shows, per line: description, item code, the quantity needed, the need-by date and the
specification. Click `Prepare quotation`.

### The quotation form

The form has four parts:

1. **Your prices** — one row per line with three inputs:
   - `Unit price (₱ per kg)`: `120.00`
   - `Quantity`: already filled with the full requested quantity (lower it only for a partial offer)
   - `Can deliver by`: a date 7 days from today (optional)
   - `Can't supply this item` switches the line to "not quoting"; `Quote this item` switches it back.
2. **VAT and freight** — `Your prices are`: `VAT exclusive — add VAT on top`; `Freight to Ogami`: `500.00`.
3. **Documents** — `Quotation PDF (required)` and `Certificate of analysis (optional)`.
4. **More details (optional)**, folded: `Quote valid until` (pre-set to 30 days from today), `Payment terms`, `Notes`.

Expected: the total line reads goods ₱60,000.00 · freight ₱500.00 · VAT ₱7,260.00, total ₱67,760.00 (for 500 kg),
and the button stays disabled with a short list of what is missing until a price and the PDF are present.

### Save Draft Test (Supplier A)

1. Click `Save draft`. Expected toast: "Draft saved. Ogami cannot see it until you submit."
2. Reload the page. Expected: prices, quantity, date, VAT and freight are all still filled in.
3. As the buyer, confirm the RFQ still shows `0/3 responded` and no prices — drafts are private.

### Submit Quotation (Supplier A)

Attach the PDF if not already attached and click `Submit quotation`.

Expected:

```text
Toast: Quotation submitted.
RFQ page: Your quotation — status submitted, total delivered cost, VAT treatment, valid until
Buttons: Update quotation, Withdraw quotation
```

### Update and Withdraw (Supplier A)

1. `Update quotation` → change the price to `118.00` → `Update quotation`. It stays one quotation (no versions).
2. `Withdraw quotation` on the RFQ page → confirm. Expected: status back to `draft`; the buyer's count drops by one.
3. `Prepare quotation` → `Submit quotation` again before the deadline.

## 4b. Supplier Quotation (Supplier B)

Sign in as Supplier B and submit a quotation the same way with a lower unit price but higher freight, e.g.
`Unit price 115.00`, `Freight 4,000.00`. This makes the comparison show freight changing the ranking.

## 5. Before RFQ Closure — Manual Quote for Supplier C

Use the Purchasing profile:

```text
purchasing@ogami.test
```

Open the RFQ detail page.

Observe:

```text
Status: Open
Deadline: <deadline>
Supplier invitations: Supplier A (submitted), Supplier B (submitted), Supplier C (invited)
Responded: 2/3
```

Since Supplier C has no portal account, manually enter their quotation.

### Manual Quote Entry

On the RFQ detail, find:

```text
Enter quote manually
```

Click it.

A modal opens to select a supplier and upload their quotation PDF.

Fill:

- `Supplier`: Select `Supplier C`.
- Upload a quotation PDF.

Click:

```text
Next
```

The form has the same layout as the supplier portal form. Suppliers that already submitted in the portal are
greyed out ("— submitted in the portal"): their own quotation is never replaced by a manual entry.

- `Invited supplier`: Supplier C.
- Line: `Unit price`: `121.00`; `Quantity`: `450` (less than requested); `Can deliver by`: a date.
- `Their prices are`: `No VAT — not VAT-registered`; `Freight to Ogami`: `600.00`.
- `Quotation (PDF or photo, required)`: attach the scan.
- Under `More details (optional)`: `Payment terms`: `Net 15`; `Notes`: `Quoted via phone call`.

Click:

```text
Save quotation
```

Expected:

```text
Supplier C quote recorded as Submitted.
Invitation status: viewed → submitted.
RFQ now shows: Responded 3/3
"Close now" button becomes available.
```

### Sealed Pricing Check

Before closing, verify that:

- Finance profile cannot see supplier prices on the RFQ detail.
- QC profile cannot see prices or award controls.

Use:

```text
finance@ogami.test
```

Open the RFQ detail page (before closure).

Expected:

```text
RFQ requirements and deadline visible.
Quotations: NOT visible or shown as "sealed".
Award section: Not accessible.
```

Use:

```text
qc@ogami.test
```

Expected:

```text
RFQ requirements visible (for review context).
Quotations: NOT visible.
Award controls: Not accessible.
```

## 6. Close the RFQ

As Purchasing, on the RFQ detail (with all 3 suppliers having submitted):

Click:

```text
Close now
```

A confirmation dialog appears:

```text
"Close this RFQ? Suppliers will no longer be able to submit or edit quotations."
```

Confirm:

```text
Close
```

Expected result:

```text
RFQ: Open → Closed
Stepper highlights: Closed
Quotations are now visible to Finance and other authorized roles.
Status message: "RFQ is ready to award."
In-app notification: "RFQ <number> is ready to award"
```

### Automatic Closure (at deadline)

If you do not click "Close now", the scheduler will close the RFQ when the deadline passes:

```bash
docker compose exec -T api php artisan purchasing:close-due-rfqs
```

Expected:

```text
RFQ: Open → Closed (after deadline)
```

### No Submitted Quotes Scenario

If the RFQ reaches its deadline with no submitted quotes:

```text
RFQ: Open → Cancelled (auto-closed by scheduler)
Reason recorded: "No valid quotations received"
PR: Sourcing pending (remains approved, ready for Direct PO or new RFQ)
```

Check the queue for closure jobs:

```bash
docker compose logs -f queue
```

## 7. Compare and Award

As Purchasing, open:

```text
/purchasing/rfqs/{rfq_hash_id}/compare
```

### Comparison Display

The page shows supplier quotations side by side:

**Supplier Card (per supplier):**

- Supplier name
- Total delivered cost (goods + freight, VAT per treatment)
- VAT treatment: `Exclusive`, `Inclusive`, or `None`
- Freight amount
- Quote valid until (date; "Expired" chip if past deadline)
- Payment terms
- Quality history: pass rate %, NCR rate %, on-time delivery rate %
- Links to quotation PDF and CoA/datasheet downloads (if attached)

**Per Line Response:**

For each RFQ line, all supplier responses appear:

- Unit price
- Offered quantity (equal to or less than requested)
- Delivered cost per unit (unit price + share of freight and VAT)
- Line total (offered qty × delivered cost per unit)
- Deliver-by date with an On time / Late chip
- Proposed delivery date (with "On time" or "Late" chip vs required date)
- "Lowest cost" chip on the recommended supplier's line (ranked excluding VAT, which Ogami recovers as input VAT)

**Award Options:**

Per supplier and line, a radio button is available to select the winner.
One additional radio: "Don't award" (to leave the line unresolved).

### Award Scenario — All Lines to One Supplier (Supplier A)

1. For each line, select the radio for **Supplier A**.

2. Verify the totals:

   - Goods (500 kg × 120.00): 60,000.00
   - Freight: 500.00 (Supplier A's freight shared among lines)
   - VAT exclusive: (60,000 + 500) × 12% = 7,260.00
   - Total delivered cost: 67,760.00

3. In the **Award reason** field, enter:

   ```text
   Supplier A offers competitive pricing and can deliver on time.
   ```

4. Click:

   ```text
   Award & create draft POs
   ```

Expected result:

```text
RFQ: Closed → Awarded
Stepper highlights: Awarded
Notification: "RFQ awarded successfully."
One draft PO is generated for Supplier A with 500 kg at 120.00/unit.
```

### Award Scenario — Partial Award (Supplier A for part, remainder)

Alternatively, if Supplier A offered only 400 kg:

1. Select **Supplier A** for the 400 kg available.
2. Select **"Don't award"** for the remaining 100 kg.
3. Enter award reason: `Supplier A can provide 400 kg at competitive terms. Remaining 100 kg to be sourced separately.`
4. Click **Award & create draft POs**.

Expected result:

```text
RFQ: Closed → Awarded
Awarded quantity: 400 kg (Supplier A)
Remaining quantity: 100 kg (unawarded)
Note on RFQ detail: "100 kg remainder available on the PR"
PR Supplier RFQs panel shows: "100 kg unresolved" and offers:
  - "Start RFQ" (for the remaining 100 kg)
  - "Convert to PO" (Direct PO for the remainder)
```

### Checking Expired Quotes

If a supplier's `Quote valid until` date is in the past:

- Their line shows an "Expired" chip.
- The radio button for that supplier is disabled.
- Award attempts against an expired quote are rejected.

Expected:

```text
API error: "Quote has expired and cannot be awarded."
```

### Read-Only Access for Finance and QC

Log in as Finance or QC and open the comparison:

```text
finance@ogami.test or qc@ogami.test
```

Open:

```text
/purchasing/rfqs/{rfq_hash_id}/compare
```

Expected:

```text
Comparison is visible (read-only, no radios or award button).
Finance can review commercial terms but not award.
QC can see quality history and documents but not prices or award controls.
```

### Second Award Attempt (Forbidden)

After awarding, if you attempt to open the comparison again and select different suppliers:

Click:

```text
Award & create draft POs
```

Expected:

```text
API error: "This RFQ has already been awarded."
```

## 8. Partial Award and Remainder Handling

After awarding an RFQ where not all quantities were fulfilled (or some lines were left "Don't award"), the system tracks the remainder.

### Remainder on the RFQ Detail

Return to the RFQ detail page:

```text
/purchasing/rfqs/{rfq_hash_id}
```

The page shows:

- **Awarded quantities:** per line, the quantity granted to the winning supplier.
- **Remaining quantities:** per line, the unawarded or partially-awarded quantity.
- **Note:** "100 kg remainder available on the source PR. Start a new RFQ or convert to direct PO."

### Remainder on the PR Detail

Navigate back to the source PR:

```text
/purchasing/purchase-requests/{pr_hash_id}
```

In the **Supplier RFQs** panel:

```text
Active RFQ: <RFQ number> (Awarded)
Remainder: 100 kg RM-001
Actions: [Start RFQ] [Convert to PO]
```

### Option 1: Convert Remainder to Direct PO

Click:

```text
Convert to PO
```

A form opens to create a draft PO for the remaining quantity.

Fill:

- Supplier (select from approved vendors for this item)
- Unit price (if known; or leave blank for manual fill)
- Other commercial terms

Click:

```text
Create PO
```

Expected:

```text
Draft PO created for 100 kg RM-001 with a different supplier or manual terms.
```

### Option 2: Start a New RFQ for the Remainder

Click:

```text
Start RFQ
```

The RFQ form opens with the remainder as the line quantity:

```text
Line: 100 kg RM-001 (remaining quantity)
```

Create and publish the new RFQ as in steps 5–10.

Expected:

```text
New RFQ created for 100 kg only.
Original RFQ remains Awarded (not re-opened).
Both RFQs link back to the same PR.
```

### Cancellation Path

If an RFQ is cancelled before or after awarding (via **Cancel RFQ** button on the detail):

1. Provide a cancellation reason.
2. Click **Cancel**.

Expected:

```text
RFQ: Closed → Cancelled (or Draft/Open → Cancelled)
PR: Sourcing pending, note: "RFQ <number> cancelled: <reason>"
All original quantities return to the PR as unresolved.
Buyer can start a new RFQ or convert to Direct PO.
```

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

## 10. Defective Raw Material Scenario

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

## 11. Supplier Return/RMA Flow

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

## 12. Short or Missing Shipment Scenario

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

## 13. No-Quotation Scenario

To test an RFQ that receives no quotation:

1. Create and publish an RFQ with a deadline a few minutes ahead.
2. Do not submit any supplier quotation.
3. Wait for the deadline; the scheduler closes it within a minute.

Expected result:

```text
RFQ: Open -> Cancelled ("No quotations were received before the deadline.")
PR: Sourcing pending -> Approved / Not started, with a note in its Supplier RFQs panel
```

Then:

1. Return to the approved PR.
2. Both actions are offered:

```text
Convert to PO      (Direct PO)
Start RFQ          (a new sourcing event)
```

The cancelled RFQ does not block a new RFQ.

## 14. Supplier Withdrawal Scenario

Use:

```text
portal@supp.test
```

1. Open the supplier RFQ and submit a quotation.
2. Open `Update quotation`.
3. Click `Withdraw quotation` and confirm.

Expected result:

```text
Quote: Submitted -> Draft
Invitation: Submitted -> Viewed
Buyer RFQ detail: responded count drops by one; "Close now" disappears
```

A withdrawn (draft) quote is never shown on the comparison and cannot be awarded.
Submitting it again before the deadline puts it back into evaluation.

## 15. Negative RBAC Checks

Perform these deliberately:

| Account | Attempt | Expected |
|---|---|---|
| `finance@ogami.test` | Open RFQ comparison | Allowed |
| `finance@ogami.test` | Award RFQ | Award button unavailable |
| `qc@ogami.test` | Open comparison after close | Allowed, read-only (no award controls) |
| `qc@ogami.test` | See supplier prices before close | Not visible (sealed for everyone) |
| `warehouse@ogami.test` | Open `/purchasing/rfqs` | Hidden or not found |
| `employee@ogami.test` | Open `/purchasing/rfqs` | Hidden or not found |
| `vp@ogami.test` | Award RFQ | Not allowed |
| `vp@ogami.test` | Approve high-value PO | Allowed |
| `admin@ogami.test` | Award an RFQ | Refused (IT role, not a buyer) |
| Supplier A | Open Supplier B RFQ hash ID | 404/not found |
| Supplier A | Download Supplier B quotation | 404/not found |
| Supplier portal | Open internal `/purchasing/rfqs` route | Not allowed |
| VP | Approve before Finance | Forbidden; approval state unchanged |

## 16. Final Checklist

Record these results for the test run:

- RFQ draft created from approved PR.
- One-page RFQ form (Details, Lines, Suppliers).
- Title prefilled with PR number and first line.
- Required delivery date and specification optional per line.
- Qualified supplier invitation accepted.
- Non-qualified invitation requires an exception reason.
- Draft RFQ can be edited before publish.
- Requirement document shared with all invited suppliers.
- Supplier can save and resume a draft quotation.
- Formal quotation PDF required at submission.
- Certificate of analysis uploaded with the quote.
- Prices hidden before closure (sealed bidding).
- RFQ closes via "Close now" when all suppliers have submitted.
- RFQ closes automatically at deadline (scheduler).
- RFQ cancelled automatically if no quotes by deadline.
- Manual quote entry for suppliers without portal accounts.
- One winner selected per RFQ line (one radio per supplier, not split).
- Partial award allowed (supplier offers less than requested).
- Remainder returned to PR with "Start RFQ" or "Convert to PO" options.
- One draft PO per awarded supplier.
- RFQ/award/PO traceability preserved (links on PO detail).
- Finance can view comparison but cannot award.
- QC can view the comparison (after close) read-only, with no award controls.
- Expired quotes cannot be selected for award.
- Award reason required.
- Second award attempt is refused.
- Finance approval works.
- VP approval works at ₱50,000 or higher.
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
