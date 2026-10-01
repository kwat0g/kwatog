# CRM — Customers, Products, Prices, Sales Orders & Complaints

**Demo logins:** `crm@ogami.test` (Sales Officer) for orders ·
`customerservice@ogami.test` (Customer Service) for complaints ·
password for both: `password`

Log in at `/sign-in`, click **Sign in**.

## 1. The big picture (30 seconds)

Customers → Products (what we sell) → Price Agreements (customer-specific
prices) → Sales Orders (create → confirm starts the whole factory chain) →
Complaints + 8D (when something goes wrong).

## 2. Customers (as Sales — view only)

1. Open `/crm/customers` (**Customers** in the sidebar).
2. Browse and open customer records (`/crm/customers/:id`).
3. Log in as `customerservice@ogami.test` and click **New customer** to add
   one — `crm@ogami.test` cannot create customers.

## 3. Products (as Sales — view only)

1. Open `/crm/products` (**Products** in the sidebar).
2. Open a product (`/crm/products/:id`) to see specs and pricing.
3. Adding products needs manage permission — held by PPC, not Sales.

## 4. Price agreements (as Sales — view only)

1. Open `/crm/price-agreements` (**Price Agreements** in the sidebar).
2. These set customer-specific prices used when pricing a sales order.
3. Creating agreements needs manage permission — Customer Service holds it.

## 5. Sales orders: create → confirm (the main demo, as `crm@ogami.test`)

1. Open `/crm/sales-orders` (**Sales Orders** in the sidebar).
2. Click **New sales order** → `/crm/sales-orders/create`.
3. Pick the customer, add product lines with quantities, then choose:
   - **Save as draft**, or
   - **Save & confirm** to confirm immediately.
4. Back on the order detail (`/crm/sales-orders/:id`), a draft shows
   **Confirm order**. Click it.
5. The dialog lists what confirming will do automatically (including
   **Create Work Orders** for all lines). Click **Confirm & Start Chain**.
6. Success lands on a **Sales Order Confirmed** page — the factory chain
   (MRP → work orders) has started. This is Chain 1's ignition moment.

## 6. Complaints + 8D (switch to Customer Service)

`crm@ogami.test` holds **no** complaint permissions, so:

1. Log out, log in as `customerservice@ogami.test`.
2. Open `/crm/complaints` (**Customer Complaints** in the sidebar).
3. Click **New complaint** → page **File complaint**
   ("An NCR will be auto-created on submit."). Submit it.
4. Open the complaint (`/crm/complaints/:id`): tabs **Overview** · **8D report**
   · linked records.
5. Work through the eight 8D disciplines (they save as you go), then click
   **Finalise 8D**.
6. Click **Download 8D PDF** for the panel-ready report.
7. Resolve/close only unlocks after the 8D is finalised **and** the linked
   NCR is closed with a disposition (the page tells you exactly this).

## 7. What each login can and cannot do

- `crm@ogami.test`: creates/confirms/cancels sales orders; views customers,
  products, prices. No complaints, no master-data creation.
- `customerservice@ogami.test`: manages customers, prices, complaints, 8Ds,
  inquiries, and returns. No sales-order confirm (Sales owns that).

## 8. If something looks wrong

- **Confirm order** missing on a sales order? Only editable (draft) orders show
  it, and only with confirm permission.
- No work orders appeared after confirm? Check `/production/work-orders` and
  `/mrp/plans` — then see the Production and MRP guides.
- Complaints menu invisible as `crm@ogami.test`? Expected — switch accounts.
