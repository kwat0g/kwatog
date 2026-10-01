# Returns (RMA) — Faulty Goods Coming Back

**What this module does:** tracks faulty or wrong goods coming back —
from a customer to us, or from us to a supplier — from request to
refund or replacement, with Quality checking the goods in the middle.

**Password for ALL demo accounts:** `password`

| Who | Email | Job in returns |
|---|---|---|
| Customer Service | `customerservice@ogami.test` | Files customer returns |
| Purchasing Officer | `purchasing@ogami.test` | Files supplier returns, receives goods |
| Warehouse Staff | `warehouse@ogami.test` | Receives returned goods |
| QC Inspector | `qc@ogami.test` | Inspects the returned goods |
| Department Head | `depthead@ogami.test` | Approval step 1 |
| Production Manager | `production@ogami.test` | Approval step 2 (final) |
| Finance Officer | `finance@ogami.test` | Approves finance-only credits |

**Two kinds of return — learn the difference:**

- **Customer return:** a customer sends finished goods BACK TO US
  (example: Toyota returns 50 defective bushings).
- **Supplier return:** WE send bad raw material BACK TO THE SUPPLIER
  (example: we return 10 kg of off-spec resin).

## 1. Log in and open the RMA list

1. Go to `/sign-in`.
2. Log in as `customerservice@ogami.test` (password `password`).
3. In the left sidebar, open **Sales & CRM**.
4. Click **Returns (RMA)**. You are at `/return-management`.
5. The title is **Return Management (RMA)**.
6. Each row shows the **RMA #**, **Type** chip
   (customer vs supplier), **Source**, **Status** chip,
   reason, item count, and date.
7. Use the **Type** and **Status** dropdowns to filter.
8. Use the search box (`Search RMA number…`) to find one RMA.
9. Click any row to open its detail page (`/return-management/<id>`).
10. The **"Problem reports"** button goes to
    `/return-management/cases` (linked complaints — optional).

## 2. Create a customer return (Customer Service)

1. On `/return-management`, click **"New RMA"**.
2. You are at `/return-management/new`, titled
   **New Return Request**.
3. In **Type**, pick the customer-return option.
4. In **Return Date**, keep today (or pick the date goods came back).
5. In **Customer**, pick the customer (example: Toyota).
6. Leave **Finance-only credit** UNTICKED for a normal return
   (tick it only when no goods come back and Finance just owes
   a credit — then fill in **Finance-only reason**).
7. In **Reason Code**, pick the reason; in **Resolution**, pick what
   the customer should get.
8. Optionally fill **Description**, **Internal notes**,
   **Customer notes**.
9. Under **Items**, click **"Add Item"**.
10. In **Inventory item**, pick the returned product.
11. In **Source line**, pick the invoice, order, or delivery line
    it came from (this proves the customer really bought it).
12. Type the **Qty** and check the **Price (source)**.
13. Optionally set **Condition**, **Line reason**, **Serial number**.
14. Click **"Create Return Request"** (or **"Cancel"** to abandon).
15. You land on the new RMA detail page with status `Draft`.

## 3. Create a supplier return (Purchasing)

1. Log in as `purchasing@ogami.test` (password `password`).
2. Go to `/return-management` → click **"New RMA"**.
3. In **Type**, pick the supplier-return option.
4. In **Supplier**, pick the vendor.
5. Under **Items**, click **"Add Item"**.
6. In **Inventory item**, pick the purchased raw material.
7. In **Accepted GRN line**, pick the goods-receipt line that
   accepted it (this proves we received it from them).
8. Optionally pick a **Bill line**.
9. Type the **Qty**. Click **"Create Return Request"**.

## 4. The lifecycle — the 6 steps in order (memorize this)

1. `Draft` → click **"Submit for Approval"** (button **"Submit"**).
   Edits are locked until someone approves or rejects.
2. `Pending approval` → approvers click **"Approve"**
   (or **"Reject"** with a reason). Two steps: Department Head,
   then Production Manager. Finance-only credits need only
   Finance (`finance@ogami.test`) — one step.
3. `Approved` → warehouse clicks **"Record Receipt"**.
   Type how many units actually came back per line.
   Click **"Record Final Receipt"** (done) or
   **"Record Partial Receipt"** (more boxes still coming).
4. `Received` → QC clicks **"Stage Quality Handoff"**
   (confirm with **"Stage Handoff"**). This creates the Quality
   inspection records. Nothing moves forward until QC passes them.
5. `Inspected` → click **"Dispose Items"** and follow the form
   (restock, scrap, rework, or return to supplier). Disposing moves
   the stock and may stage a draft credit note.
6. Disposed → click **"Complete RMA"**, then **"Confirm Complete"**.
   Status becomes `Completed`. The case is closed.
7. At any early step, **"Cancel"** (confirm **"Yes, cancel RMA"**)
   kills a draft permanently.

## 5. Walk a return through the detail page

1. Open any RMA at `/return-management/<id>`.
2. The top shows the RMA number and the **Status** chip.
3. The buttons at the top right always match the current step —
   you only ever see the buttons you are allowed to press.
4. Read the **RMA Details** panel: type, status, return date,
   source links (sales order, invoice, PO, bill), reason.
5. Read the **Items** table: quantities, returned counts,
   condition, and the **Disposition** chip per line.
6. Read the **Timeline** panel: who created, approved, received,
   and completed it, with timestamps.
7. Read the **Approval chain** panel for the two approval steps.
8. Read the **Outcome** panel: Disposition, Credit Note link,
   Replacement PO link, Quality inspections.
9. If a draft is still editable, an **"Edit Draft"** button appears
   (goes to `/return-management/<id>/edit`, button **"Save Draft"**).
10. If Quality staging failed, a yellow warning appears with
    **"Retry Quality handoff"** — click it.

## 6. The money at the end (credit note)

1. After Dispose on a customer return, the system stages a DRAFT
   customer credit note from the returned lines.
2. A green box says **Credit note auto-created** with the number
   and peso amount, linking to `/accounting/credit-notes/<id>`.
3. As Finance, click **"Finalize"** and confirm with
   **"Finalize credit note"** to post it to the ledger.
4. A supplier return may instead show a **Replacement PO** link
   (`/purchasing/purchase-orders/<id>`) for the replacement buy.
5. Restocked goods show **Goods restocked into inventory** with the
   quantity and location; supplier goods show
   **Goods shipped back to supplier**.

## 7. Who presses which button (cheat sheet)

1. `customerservice@ogami.test` — **"New RMA"**, **"Submit for Approval"**,
   **"Dispose Items"**, **"Complete RMA"** on customer returns.
2. `purchasing@ogami.test` — **"New RMA"** (supplier),
   **"Record Receipt"**, **"Dispose Items"**, **"Complete RMA"**.
3. `warehouse@ogami.test` — **"Record Receipt"**
   (final or partial) on any return.
4. `qc@ogami.test` — **"Stage Quality Handoff"** and the
   inspection itself at `/quality/inspections`.
5. `depthead@ogami.test` then `production@ogami.test` —
   **"Approve"** / **"Reject"** (the two chain steps).
6. `finance@ogami.test` — **"Approve"** on finance-only credits,
   **"Finalize"** on the credit note.

## 8. Quick demo script (5 minutes, in order)

1. `/sign-in` as `customerservice@ogami.test` →
   `/return-management` → **"New RMA"** → fill a customer return →
   **"Create Return Request"** → **"Submit for Approval"**.
2. Log in as `depthead@ogami.test` → open it → **"Approve"**.
3. Log in as `production@ogami.test` → open it → **"Approve"**.
4. Log in as `warehouse@ogami.test` → **"Record Receipt"** →
   **"Record Final Receipt"**.
5. Log in as `qc@ogami.test` → **"Stage Quality Handoff"** →
   **"Stage Handoff"**.
6. Back as Customer Service → **"Dispose Items"** →
   **"Complete RMA"** → **"Confirm Complete"** → show `Completed`.
