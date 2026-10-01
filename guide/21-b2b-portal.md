# B2B Portals — Supplier & Customer Self-Service

**What this module does:** gives OUTSIDERS (suppliers and customers)
their own small website. They see only their own orders and invoices —
never Ogami's internal screens.

**Password for ALL demo accounts:** `password`

| Who | Email | Where they log in |
|---|---|---|
| Supplier outsider | `portal@supp.test` | Supplier portal (section 2) |
| Customer outsider | `portal@cust.test` | Customer portal (section 5) |
| Purchasing insider | `purchasing@ogami.test` | Internal pages (sends the RFQ) |
| Finance insider | `finance@ogami.test` | Internal pages (portal access list) |

**How portal login works:** go to `/sign-in`, type the portal email and
password, and the system drops you into the correct portal.

## 1. Log in as a supplier (outsider view)

1. Go to `/sign-in`.
2. Type `portal@supp.test` and password `password`.
3. Click **"Sign in"**.
4. You land on `/portal/supplier` — the supplier **Dashboard**.
5. Notice what is MISSING: no sidebar, no employees, no payroll,
   no journal entries. Outsiders cannot reach any of that.
6. The dashboard shows five cards: **RFQs to quote**
   (invitations waiting), **Open POs**, **Awaiting Delivery**,
   **Unpaid Invoices**, **Total Unpaid** (peso balance).
7. Below are **Recent Purchase Orders** and **Recent Invoices**
   tables with **View all** links.

## 2. What a supplier can open (their whole world)

1. `/portal/supplier` — Dashboard (the five cards above).
2. `/portal/supplier/rfqs` — **RFQ Invitations** (quote requests).
3. `/portal/supplier/purchase-orders` — their purchase orders only.
4. `/portal/supplier/purchase-orders/<id>` — one PO with
   **"Accept"** (confirm with **"Accept PO"**), decline, shipment,
   and document buttons.
5. `/portal/supplier/deliveries` — what they still have to deliver.
6. `/portal/supplier/invoices` — their bills and payment status.
7. `/portal/supplier/statement-of-account` — running balance.
8. `/portal/supplier/delivery-schedules` — agreed delivery dates.
9. `/portal/supplier/item-listings` — items they offer to Ogami.

## 3. Supplier quotes an RFQ (the core demo — follow exactly)

1. First, the insider must have sent something. Log in as
   `purchasing@ogami.test`, go to `/purchasing/rfqs`, and confirm
   there is an open RFQ. The seeded demo is titled
   `DEMO Resin RFQ — quotable, ready to award` (its number looks
   like `RFQ-202610-0001`; if you re-seeded, look for the title
   starting with `DEMO` — numbers are system-generated).
2. Log out. Go to `/sign-in`.
3. Log in as `portal@supp.test` (password `password`).
4. Go to `/portal/supplier/rfqs` (**RFQ Invitations**).
5. Find the `DEMO` title row (or search the RFQ number).
6. Click the row. You are at `/portal/supplier/rfqs/<id>`.
7. Read the **Invitation** panel: deadline and countdown.
8. Read the **Requirements** table: description, item code,
   quantity, required date.
9. Click **"Prepare quotation"** (or **"Continue draft"** if a
   draft exists, or **"Update quotation"** if re-quoting).
10. You are at `/portal/supplier/rfqs/<id>/quote`.
11. Per line, type the **Unit price (₱ per unit)**, the
    **Quantity**, and **Can deliver by** date.
12. If you cannot supply a line, click **"Can't supply this item"**
    (it flips to **"Quote this item"** to undo).
13. Under **VAT and freight**, set **Your prices are**
    (VAT exclusive / inclusive / No VAT) and
    **Freight to Ogami (₱, total)**.
14. Under **Documents**, attach the **Quotation PDF (required)**
    and optionally the **Certificate of analysis (optional)**.
15. Optionally open **More details (optional)** for
    **Quote valid until**, **Payment terms**, **Notes**.
16. Read the **Total delivered cost** box (goods + freight + VAT).
17. Click **"Save draft"** to keep working
    (`Draft saved. Ogami cannot see it until you submit.`)
    or click **"Submit quotation"** to send it
    (`Quotation submitted.`). Submitting needs a quoted item,
    a unit price, and the PDF — missing pieces are listed in
    yellow above the button.
18. Back on `/portal/supplier/rfqs/<id>`, the **Your quotation**
    panel shows the status chip, total delivered cost, and documents.
19. To retract before the deadline, click **"Withdraw quotation"**,
    confirm with **"Withdraw"**. It returns to draft.

## 4. What happens inside Ogami after the supplier submits

1. Log in as `purchasing@ogami.test`.
2. Open `/purchasing/rfqs/<id>/compare` (from the RFQ row, the
   compare action) to see all submitted quotes side by side.
3. Award the winner there.
4. The award becomes a purchase order visible to the supplier at
   `/portal/supplier/purchase-orders`.
5. Supplier sees the **Outcome** panel on their RFQ page:
   **Awarded to you** with quantities and prices, or
   **Not selected** (competitor prices are never shown).

## 5. Log in as a customer (outsider view)

1. Go to `/sign-in`.
2. Type `portal@cust.test` and password `password`.
3. Click **"Sign in"**.
4. You land on `/portal/customer` — the customer **Dashboard**.
5. Four cards: **Open Orders**, **Pending Deliveries**,
   **Open Invoices**, **Outstanding** (peso balance).
6. Below: **Recent Orders**, **Recent Invoices**,
   **Recent Deliveries**, **Recent Quality Complaints**.

## 6. What a customer can open (their whole world)

1. `/portal/customer` — Dashboard.
2. `/portal/customer/orders` — their orders; **Place order**
   action goes to `/portal/customer/orders/new`.
3. `/portal/customer/orders/<id>` — one order and its status.
4. `/portal/customer/deliveries` — shipments heading to them.
5. `/portal/customer/deliveries/<id>` — one delivery (confirm here).
6. `/portal/customer/invoices` — what they owe; each invoice at
   `/portal/customer/invoices/<id>`.
7. `/portal/customer/statement-of-account` — running balance.
8. `/portal/customer/complaints` — their quality complaints.
9. `/portal/customer/returns` — their returns; **create** at
   `/portal/customer/returns/new`; one return at
   `/portal/customer/returns/<id>`.
10. `/portal/customer/problems/new` — **Report a problem**
    about an order or delivery.

## 7. Customer confirms a delivery (the second core demo)

1. As `portal@cust.test`, go to `/portal/customer/deliveries`.
2. Click a row with status `Delivered`. You are at
   `/portal/customer/deliveries/<id>`.
3. Read the top: delivery number, status chip, scheduled vs
   delivered date, order number, driver, and the item table.
4. Open **Delivery Proofs** (signed receipt or photo) if present.
5. If it says `Awaiting our driver's proof of delivery`, STOP —
   the **"Confirm Receipt"** button appears only after the driver
   uploads proof. Pick another delivered row.
6. Click **"Confirm Receipt"**. A **Confirm receipt** form opens.
7. In **Received by** (required), type the receiver's name.
8. Optionally fill **Position** and **Remarks**.
9. Click **"Confirm receipt"** (or **"Cancel"** to abandon).
10. The toast says `Delivery confirmed.` The status chip flips to
    confirmed. Inside Ogami this triggers the draft invoice.
11. If the goods never came, click **"Shipment not arrived"**
    instead and follow the trace panel.
12. If goods arrived damaged, click **"Report a problem"**
    — while the report is open, receipt confirmation is paused.

## 8. Who manages portal accounts (insider admin)

1. Log in as `finance@ogami.test` (password `password`).
2. Go to `/accounting/portal-access` (sidebar: Administration →
   **Portal Access**).
3. This list shows which vendors and customers have logins.
   Issuing or revoking a login here is a Finance/IT action.

## 9. Quick demo script (6 minutes, in order)

1. `/sign-in` as `purchasing@ogami.test` → `/purchasing/rfqs` →
   show the open `DEMO` RFQ.
2. `/sign-in` as `portal@supp.test` → `/portal/supplier` →
   point at the five cards → `/portal/supplier/rfqs` → open the
   RFQ → **"Prepare quotation"** → fill one price → attach any PDF →
   **"Submit quotation"**.
3. `/sign-in` as `portal@cust.test` → `/portal/customer` →
   point at the four cards → `/portal/customer/deliveries` →
   open a delivered row → **"Confirm Receipt"** → type
   **Received by** → **"Confirm receipt"**.
4. Closing line: "Suppliers see only their RFQs, POs, and invoices.
   Customers see only their orders, deliveries, and invoices.
   Everything else in this ERP is invisible to them."
