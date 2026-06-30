# OGAMI ERP — Complete Process Flows & Manual Testing Guide

> Every feature in this ERP serves one of three end-to-end business chains.
> Quality (IATF 16949) is woven through Chains 1 and 2 at four inspection touchpoints.
> This document traces every step, every status transition, every auto-trigger,
> and every cross-module bridge so you know exactly how to test the full system.

---

## Table of Contents

1. [Chain 1 — Order to Cash (O2C)](#chain-1--order-to-cash-o2c)
2. [Chain 2 — Procure to Pay (P2P)](#chain-2--procure-to-pay-p2p)
3. [Chain 3 — Hire to Retire (H2R)](#chain-3--hire-to-retire-h2r)
4. [Quality System (IATF 16949)](#quality-system-iatf-16949)
5. [Maintenance System](#maintenance-system)
6. [Cross-Module Auto-Triggers (Event Map)](#cross-module-auto-triggers-event-map)
7. [Alternative Scenarios & Edge Cases](#alternative-scenarios--edge-cases)
8. [Recommended Testing Order](#recommended-testing-order)
9. [API Quick Reference](#api-quick-reference)

---

## Chain 1 — Order to Cash (O2C)

**The journey:** A customer places an order → we plan materials → we schedule production → we manufacture → we inspect quality → we deliver → we invoice → we collect payment.

```
┌──────────┐    ┌──────────┐    ┌───────────┐    ┌────────────┐    ┌─────────────┐
│ Sales    │───▶│ MRP Plan │───▶│ Capacity  │───▶│ Work Order │───▶│ In-Process  │
│ Order    │    │ (auto)   │    │ Scheduler │    │            │    │ QC (auto)   │
│          │    │          │    │           │    │            │    │             │
│ draft    │    │ Explodes │    │ Assigns   │    │ planned    │    │ draft       │
│ confirmed│    │ BOM      │    │ machines  │    │ confirmed  │    │ in_progress │
│          │    │          │    │ + molds   │    │ in_progress│    │ passed/     │
│          │    │ Creates: │    │           │    │ completed  │    │ failed      │
│          │    │ • PRs    │    │ Creates   │    │ closed     │    │             │
│          │    │   (if    │    │ draft WOs │    │            │    │ If failed → │
│          │    │   short) │    │           │    │            │    │ NCR created │
│          │    │ • WOs    │    │           │    │            │    │             │
└──────────┘    └──────────┘    └───────────┘    └────────────┘    └─────────────┘
                     │                                │
                     │ material shortage?              │ WO completed
                     ▼                                ▼
              ┌──────────────┐              ┌─────────────────┐
              │ Auto-creates │              │ Outgoing QC     │
              │ Purchase     │              │ auto-created     │
              │ Request      │              │ (AQL 0.65 L-II) │
              │ (Chain 2     │              │                 │
              │  bridge)     │              │ If passed →     │
              └──────────────┘              │ Delivery auto-  │
                                            │ drafted          │
                                            └─────────────────┘
                                                     │
                                                     ▼
┌──────────┐    ┌──────────┐    ┌───────────┐    ┌────────────┐
│Collection│◀───│ Invoice  │◀───│ Delivery  │◀───│ Outgoing   │
│          │    │ (auto-   │    │ Confirmed │    │ QC Passed  │
│ Records  │    │ drafted) │    │           │    │            │
│ payments │    │          │    │ scheduled │    │ + CoC      │
│ against  │    │ draft    │    │ loading   │    │ generated  │
│ invoice  │    │ finalized│    │ in_transit│    │            │
│          │    │ partial  │    │ delivered │    │            │
│          │    │ paid     │    │ confirmed │    │            │
└──────────┘    └──────────┘    └───────────┘    └────────────┘
```

### Step 1: Sales Order

**What it is:** A customer commits to buying specific products at agreed prices.

**Where in the app:** `/crm/sales-orders`

**API endpoints:**
- `POST /api/v1/crm/sales-orders` — create new SO
- `POST /api/v1/crm/sales-orders/{id}/confirm` — confirm (locks it)
- `POST /api/v1/crm/sales-orders/{id}/cancel` — cancel
- `GET /api/v1/crm/sales-orders/{id}/chain` — view full chain progress

**Status transitions:**
```
draft → confirmed → in_production → partially_delivered → delivered → invoiced
                                                                         ↓
                                                                    (cancelled)
```

**How to test:**
1. Login as a user with role `finance_officer` or `system_admin` (needs `crm.sales_orders.create`)
2. Navigate to `/crm/sales-orders`, click "New"
3. Select a **Customer** (must exist in Accounting → Customers first)
4. Add line items — select **Product** (from CRM Products), set quantity and unit price
5. Save → SO is in `draft` status, still editable
6. Click **Confirm** → status becomes `confirmed`, SO is locked
7. **What happens automatically:** `SalesOrderConfirmed` event fires → notifies relevant users

**Prerequisites you need first:**
- At least one Customer (`/accounting/customers` or `/crm/customers`)
- At least one Product (`/crm/products`)
- Price Agreement (optional but realistic) — `/crm/price-agreements`

**Optional sales pipeline before SO:**
- Lead → Qualify → Convert to Opportunity
- Opportunity → Win → Create Quote
- Quote → Send → Accept → Convert to SO
- Routes: `/crm/leads`, `/crm/opportunities`, `/crm/quotes`

---

### Step 2: MRP Plan (Auto-triggered)

**What it is:** Material Requirements Planning. The system explodes the Bill of Materials (BOM) for each product in the SO, checks what raw materials are in stock, and determines what's short.

**Where in the app:** `/mrp/plans`, `/mrp/runs`

**API endpoints:**
- `POST /api/v1/mrp/runs` — manually trigger MRP for all active SOs
- `GET /api/v1/mrp/sales-orders/{salesOrder}/mrp-plan` — view plan for specific SO
- `POST /api/v1/mrp/plans/{plan}/rerun` — re-run plan for one SO

**What MRP does automatically (inside `MrpEngineService::runForSalesOrder()`):**

1. **Loads the BOM** for each product in the SO
2. **Explodes BOM** — calculates total raw material quantities needed
3. **Checks current stock** — compares demand vs. available inventory
4. **For each material shortage:**
   - Calculates `net shortage = demand - available`
   - Determines `order_by` date based on lead time + 3-day safety buffer
   - Sets priority: `urgent` if order_by ≤ today, else `normal`
5. **Creates ONE consolidated draft Purchase Request** with all shortages as line items
   - PR is flagged `is_auto_generated = true`
   - PR reason: "Auto-generated from MRP plan {plan_no} for SO {so_number}"
   - Each PR line shows the item, quantity needed, estimated unit price, and purpose
6. **Creates draft Work Orders** — one per SO line item
   - WO target quantity = SO line quantity
   - Planned start = delivery date minus 2 days
   - Planned end = delivery date minus 1 day
   - Priority: 100 (high) if delivery within 7 days, else 50

**How to test:**
1. Make sure a BOM exists for the product (`/mrp/boms`)
2. Confirm the Sales Order (Step 1)
3. Trigger MRP: `POST /api/v1/mrp/runs` — or wait for daily cron at 06:00
4. Check the MRP plan: go to `/mrp/plans` or use the SO chain view
5. Verify: if raw materials were short, a draft PR should appear in `/purchasing/purchase-requests`
6. Verify: draft Work Orders should appear in `/production/work-orders`

**Prerequisites:**
- BOM for the product (`POST /api/v1/mrp/boms`)
- Items (raw materials) in inventory with stock levels
- Lead time set on items

**Key scenario — materials ARE sufficient:**
- MRP finds no shortages → no PR created
- WOs still created for production
- Chain proceeds directly to Step 4

**Key scenario — materials are NOT sufficient:**
- MRP creates a draft PR → this bridges to Chain 2 (Procure to Pay)
- Production cannot start until materials arrive
- After Chain 2 completes (materials received), production can begin

---

### Step 3: Capacity Scheduling (MRP II)

**What it is:** Assigns machines and molds to work orders based on capacity.

**Where in the app:** `/mrp/scheduler`

**API endpoints:**
- `POST /api/v1/mrp/scheduler/run` — run the scheduler
- `POST /api/v1/mrp/scheduler/confirm` — confirm schedule (creates WOs)
- `GET /api/v1/mrp/scheduler/snapshot` — view Gantt chart data
- `PATCH /api/v1/mrp/scheduler/{schedule}/reorder` — reorder priority
- `PATCH /api/v1/mrp/scheduler/{schedule}/reassign` — reassign machine

**How to test:**
1. After MRP creates draft WOs, go to `/mrp/scheduler`
2. Run scheduler — it assigns machines and molds to each WO
3. View the snapshot (Gantt view)
4. Optionally reorder or reassign
5. Confirm schedule → WOs move from draft to `planned`

**Prerequisites:**
- Machines registered (`/mrp/machines`)
- Molds registered with product compatibility (`/mrp/molds`)
- Machine-mold compatibility set up

---

### Step 4: Work Order Execution

**What it is:** The production floor manufactures the product. Operators start the WO, record output quantities, and complete it.

**Where in the app:** `/production/work-orders`

**API endpoints:**
- `POST /api/v1/production/work-orders` — create (or auto-created from MRP)
- `POST .../confirm` — confirm the WO
- `POST .../start` — begin production
- `POST .../pause` / `POST .../resume` — pause/resume
- `POST .../complete` — mark production done
- `POST .../close` — final close
- `POST .../outputs` — record output (good qty, reject qty, mold used)
- `GET .../chain` — view chain progress

**Status transitions:**
```
planned → confirmed → in_progress ⟷ paused → completed → closed
                                                              ↓
                                                         (cancelled)
```

**How to test:**
1. Go to `/production/work-orders`, find the WO created by MRP
2. Click **Confirm** → status = `confirmed`
3. Click **Start** → status = `in_progress`
   - **AUTO-TRIGGER:** `WorkOrderStatusChanged` event fires → **In-Process QC auto-created** (see Step 5)
4. Record output: enter good quantity, reject quantity, select mold
   - Mold shot count auto-increments (alerts at 80% of max shots)
   - Scrap rate calculated automatically
5. Click **Complete** → status = `completed`
   - **AUTO-TRIGGER:** `WorkOrderCompleted` event fires → **Outgoing QC auto-created** (see Step 6)

**What happens to SO:** When WO starts, SO status auto-updates to `in_production`

---

### Step 5: In-Process QC (Auto-triggered)

**What it is:** Periodic quality sampling during production. Checks that parts being made meet dimensional tolerances.

**Trigger:** Automatically created when Work Order status changes to `in_progress` (via `TriggerInProcessQC` listener).

**Where in the app:** `/quality/inspections` (filtered by stage = "In-process")

**API endpoints:**
- `GET /api/v1/quality/inspections` — list (filter by `stage=in_process`)
- `PATCH /api/v1/quality/inspections/{id}/measurements` — record measurements
- `POST /api/v1/quality/inspections/{id}/complete` — complete inspection

**Status transitions:**
```
draft → in_progress → passed | failed
                         ↓
                    (cancelled)
```

**How to test:**
1. After starting the WO, go to `/quality/inspections`
2. Find the auto-created in-process inspection (linked to the WO)
3. The inspection spec auto-loads measurement parameters from the product's spec
4. Record measurements — enter actual values for each dimension
5. Complete the inspection:
   - **Passed:** All measurements within tolerance, defects ≤ accept count → production continues
   - **Failed:** Critical dimension out of tolerance OR defects > accept count
     - **AUTO-TRIGGER:** NCR auto-created (`InspectionService::complete()` calls `NcrService::openFromInspectionFailure()`)
     - Production may need to pause, rework, or scrap

**Prerequisites:**
- Inspection Spec must exist for the product (`/quality/inspection-specs`)
- Spec defines parameters (dimensions), nominal values, upper/lower tolerances

---

### Step 6: Outgoing QC — AQL Sampling (Auto-triggered)

**What it is:** Final quality gate before goods can be delivered. Uses AQL (Acceptable Quality Level) 0.65, Inspection Level II statistical sampling.

**Trigger:** Automatically created when Work Order is completed (via `TriggerOutgoingQC` listener). Only triggers if the WO is linked to a Sales Order (not for internal/rework WOs).

**Where in the app:** `/quality/inspections` (filtered by stage = "Outgoing")

**API endpoints:**
- `GET /api/v1/quality/inspections/aql-preview` — preview sample size for a batch
- `POST /api/v1/quality/inspections` — create (or auto-created)
- `PATCH .../measurements` — record measurements
- `POST .../complete` — complete inspection
- `GET .../coc` — generate Certificate of Conformance PDF

**How to test:**
1. After WO completes, go to `/quality/inspections`
2. Find the auto-created outgoing inspection
3. Note: sample size is calculated from batch quantity using AQL tables
4. Record actual measurements for each critical dimension
5. Complete the inspection:
   - **Passed:**
     - **AUTO-TRIGGER:** Delivery auto-drafted (via `CreateDeliveryDraftOnQcPass` listener)
     - Certificate of Conformance (CoC) available for download
     - Warehouse/ImpEx notified to pick and dispatch
   - **Failed:**
     - NCR auto-created
     - **Delivery is blocked** — goods cannot ship until quality issue resolved
     - May need to rework (new WO) or scrap

---

### Step 7: Delivery

**What it is:** Physical shipment of finished goods to the customer.

**Trigger:** Auto-drafted when outgoing QC passes (via `CreateDeliveryDraftOnQcPass`). Can also be created manually.

**Where in the app:** `/supply-chain/deliveries`

**API endpoints:**
- `POST /api/v1/supply-chain/deliveries` — create
- `PATCH .../status` — advance status
- `POST .../receipt` — upload delivery receipt photo
- `POST .../proofs` — upload proof of delivery documents
- `POST .../confirm` — customer confirms receipt

**Status transitions:**
```
scheduled → loading → in_transit → delivered → confirmed
                                                    ↓
                                               (cancelled)
```

**How to test:**
1. Go to `/supply-chain/deliveries`, find the auto-drafted delivery
2. Assign a vehicle from the fleet (`/supply-chain/fleet`)
3. Advance through statuses:
   - `scheduled` → `loading` (warehouse picks and loads)
   - `loading` → `in_transit` (truck leaves factory)
   - `in_transit` → `delivered` (goods arrive at customer site)
4. Upload delivery receipt photo
5. Confirm delivery (`POST .../confirm`):
   - **AUTO-TRIGGERS (all in `DeliveryService::confirm()`):**
     - SO status auto-updates to `delivered` (or `partially_delivered` if not all items)
     - **Draft Invoice auto-created** for the SO
     - Certificate of Conformance auto-attached
     - `DeliveryConfirmed` event fires → Finance team notified
     - If auto-invoice fails, Finance gets a notification to create it manually

**Driver self-service:** Drivers can update delivery status and upload receipts via `/driver/deliveries` (separate mobile-friendly surface).

---

### Step 8: Invoice

**What it is:** Bill sent to the customer for the delivered goods.

**Trigger:** Auto-drafted when delivery is confirmed (inside `DeliveryService::confirm()`). Can also be created manually.

**Where in the app:** `/accounting/invoices`

**API endpoints:**
- `POST /api/v1/invoices` — create
- `PUT /api/v1/invoices/{id}` — edit (while draft)
- `PATCH .../finalize` — lock and post to GL
- `PATCH .../cancel` — cancel
- `POST .../collections` — record payment
- `GET .../pdf` — download PDF

**Status transitions:**
```
draft → finalized → partial → paid
                                ↓
                           (cancelled)
```

**How to test:**
1. Go to `/accounting/invoices`, find the auto-drafted invoice
2. Review/edit line items, amounts, VAT
3. Finalize (`PATCH .../finalize`):
   - Invoice is locked, no more edits
   - Journal Entry auto-posted to GL (Debit: Accounts Receivable, Credit: Revenue)
   - SO status auto-updates to `invoiced`
4. Download PDF to send to customer
5. Generate Statement of Account: `GET /api/v1/customers/{id}/statement-of-account`

---

### Step 9: Collection (Payment)

**What it is:** Recording customer payments against invoices.

**API endpoint:** `POST /api/v1/invoices/{invoice}/collections`

**How to test:**
1. Open the finalized invoice
2. Record a payment — specify amount, payment method, reference number
3. Partial payment → invoice status = `partial`
4. Full remaining balance paid → invoice status = `paid`
5. GL auto-posted: Debit Cash/Bank, Credit Accounts Receivable
6. **Chain 1 is now complete** — order fully fulfilled and paid

---

## Chain 2 — Procure to Pay (P2P)

**The journey:** We need raw materials → we request them → approval → we order from supplier → supplier ships → we receive and inspect → materials enter inventory → we get the bill → we pay.

```
┌──────────────┐    ┌────────────┐    ┌────────────┐    ┌───────────┐
│ Purchase     │───▶│ Purchase   │───▶│ Shipment   │───▶│ GRN       │
│ Request      │    │ Order      │    │ Tracking   │    │ (Receive) │
│              │    │            │    │            │    │           │
│ Sources:     │    │ draft      │    │ ordered    │    │ pending_qc│
│ • Manual     │    │ pending_   │    │ shipped    │    │ accepted  │
│ • MRP auto   │    │  approval  │    │ in_transit │    │ partial_  │
│ • Reorder    │    │ approved   │    │ customs    │    │  accepted │
│   point auto │    │ sent       │    │ cleared    │    │ rejected  │
│              │    │ partially_ │    │ received   │    │           │
│ draft        │    │  received  │    │            │    │           │
│ pending      │    │ received   │    │            │    │           │
│ approved     │    │ closed     │    │            │    │           │
│ rejected     │    │            │    │            │    │           │
│ converted    │    │            │    │            │    │           │
└──────────────┘    └────────────┘    └───────────┘    └───────────┘
       │                                                      │
       │ 3 sources                                            │ GRN created
       │                                                      ▼
  ┌────┴───────────────────┐                         ┌─────────────────┐
  │ 1. Manual (user files) │                         │ Incoming QC     │
  │ 2. MRP (material       │                         │ auto-created    │
  │    shortage from SO)   │                         │                 │
  │ 3. Reorder point       │                         │ If passed →     │
  │    (stock drops below  │                         │ GRN accepted,   │
  │    threshold → auto-PR │                         │ stock increased │
  │    or auto-PO for      │                         │                 │
  │    critical items)     │                         │ If failed →     │
  └────────────────────────┘                         │ GRN rejected,   │
                                                     │ NCR created     │
                                                     └─────────────────┘
                                                              │
                                                              ▼
                              ┌────────────┐    ┌─────────────────────┐
                              │ Bill       │───▶│ Bill Payment        │
                              │ Payment    │    │                     │
                              │            │    │ Records payment     │
                              │ unpaid     │    │ GL auto-posted      │
                              │ partial    │    │ 3-way match         │
                              │ paid       │    │ (PO vs GRN vs Bill) │
                              └────────────┘    └─────────────────────┘
```

### Step 1: Purchase Request (PR)

**What it is:** A formal request to buy materials. Can come from three sources.

**Where in the app:** `/purchasing/purchase-requests`

**Three ways a PR gets created:**

#### Source A — Manual (user creates)
1. Navigate to `/purchasing/purchase-requests`, click "New"
2. Select items, quantities, estimated prices, reason
3. Save as `draft`
4. Submit (`PATCH .../submit`) → status = `pending`

#### Source B — MRP auto-generation (material shortage)
- When MRP runs for a Sales Order and finds material shortages (see Chain 1, Step 2)
- Creates ONE consolidated draft PR with all shortage items
- Flagged as `is_auto_generated = true`
- Still needs manual submission and approval

#### Source C — Reorder point auto-generation (stock drops below threshold)
- `StockMovementCompleted` event → `CheckReorderPoint` listener → `AutoReplenishmentService`
- Triggers when any stock movement causes available quantity to drop below the item's `reorder_point`
- **For normal items:** Creates a draft PR automatically
- **For critical items with one preferred supplier:** Skips PR, creates auto-PO directly (routed to VP for approval)
- Idempotent: skips if an open PR for that item already exists
- Order quantity calculated based on reorder method:
  - Fixed Quantity: `max((reorder × 2) - available, reorder)`
  - Days of Supply: `avg_daily_consumption × lead_time_days × 1.2`
  - Rounds up to nearest MOQ (minimum order quantity) multiple
- Priority: `critical` if at/below safety stock, else `urgent`

**API endpoints:**
- `POST /api/v1/purchasing/purchase-requests` — create
- `PATCH .../submit` — submit for approval
- `PATCH .../approve` — approve
- `PATCH .../reject` — reject
- `POST .../bulk-approve` — approve multiple at once
- `POST .../convert` — convert approved PR to PO
- `GET .../pdf` — print PR with 4-tier signature block
- `GET .../pending-count` — count pending approvals (for badge)

**Status transitions:**
```
draft → pending → approved → converted
                → rejected
                                  ↓
                             (cancelled)
```

**How to test each source:**
1. **Manual:** Create PR → Submit → Approve → Convert to PO
2. **MRP:** Confirm an SO for a product that needs materials not in stock → run MRP → check `/purchasing/purchase-requests` for auto-PR
3. **Reorder:** Issue materials from inventory until stock drops below reorder point → check for auto-PR

---

### Step 2: Purchase Order (PO)

**What it is:** A formal order sent to a vendor/supplier.

**Where in the app:** `/purchasing/purchase-orders`

**Two ways a PO gets created:**

1. **Convert from approved PR:** `POST /api/v1/purchasing/purchase-requests/{pr}/convert` — auto-populates items from PR
2. **Create directly:** `POST /api/v1/purchasing/purchase-orders`

**API endpoints:**
- `POST /api/v1/purchasing/purchase-orders` — create
- `PATCH .../submit` — submit for approval
- `PATCH .../approve` — approve (fires `PurchaseOrderApproved` event → notifies)
- `PATCH .../reject` — reject
- `PATCH .../send` — mark as sent to vendor
- `PATCH .../close` — close PO
- `GET .../pdf` — download PO PDF

**Status transitions:**
```
draft → pending_approval → approved → sent → partially_received → received → closed
                         → rejected                                              ↓
                                                                            (cancelled)
```

**How to test:**
1. Convert an approved PR to PO (or create directly)
2. Submit → `pending_approval`
3. Approve → `approved`
4. Send to vendor → `sent` (download PDF to actually send)
5. Now waiting for goods to arrive

**Prerequisites:**
- Vendor/Supplier must exist (`/accounting/vendors`)
- Approved Supplier List (optional) — `/purchasing/approved-suppliers`

---

### Step 3: Shipment Tracking (for imported materials)

**What it is:** Tracks the physical journey of purchased materials from overseas suppliers. This is for international POs that go through shipping, customs, etc.

**Where in the app:** `/supply-chain/shipments`

**API endpoints:**
- `POST /api/v1/supply-chain/shipments` — create shipment linked to PO(s)
- `PATCH .../status` — advance status
- `POST .../documents` — upload import documents (Bill of Lading, Commercial Invoice, Packing List)
- `POST .../containers` — track containers
- `POST .../calculate-landed-cost` — calculate total landed cost
- `GET .../packing-list` — generate packing list PDF
- `GET .../commercial-invoice` — generate commercial invoice PDF

**Status transitions:**
```
ordered → shipped → in_transit → customs → cleared → received
                                                        ↓
                                                   (cancelled)
```

**How to test:**
1. Create shipment linked to the sent PO
2. Upload import documents (BL, CI, PL)
3. Add containers with details
4. Advance through each status
5. Calculate landed cost (freight, duties, insurance, etc.)
6. When `received`, proceed to GRN

**Note:** This step is optional for domestic purchases. Local suppliers deliver directly → skip to Step 4 (GRN).

---

### Step 4: Goods Receipt Note (GRN)

**What it is:** Recording what was actually received from the supplier. Compared against the PO.

**Where in the app:** `/inventory/grn`

**API endpoints:**
- `POST /api/v1/inventory/grn` — create GRN against a PO
- `POST /api/v1/inventory/receive-goods` — single-screen receiving (GRN + QC + inventory in one call)
- `PATCH .../accept` — accept goods into inventory
- `PATCH .../reject` — reject goods

**Status transitions (no `draft` — GRN starts at `pending_qc`):**
```
pending_qc → accepted | partial_accepted | rejected
```

**How to test:**
1. Create GRN — select the PO, enter quantities actually received per item
2. GRN starts at `pending_qc` — goods are quarantined, not yet in inventory
3. PO auto-updates: `sent` → `partially_received` (if partial) or `received` (if complete)
4. **AUTO-TRIGGER:** `GoodsReceiptNoteCreated` event fires:
   - → `TriggerIncomingQC` listener creates an incoming inspection automatically
   - → `NotifyOnGrnReceived` listener notifies relevant users

**Single-screen receiving (shortcut):**
- `POST /api/v1/inventory/receive-goods` — does GRN + QC + inventory acceptance in one API call
- Useful for domestic purchases with simple QC needs

---

### Step 5: Incoming QC (Auto-triggered)

**What it is:** Quality inspection of received raw materials. Checks resin certifications, moisture content, dimensional accuracy per IATF requirements.

**Trigger:** Automatically created when GRN is created (via `TriggerIncomingQC` listener).

**Where in the app:** `/quality/inspections` (filtered by stage = "Incoming")

**How to test:**
1. After creating the GRN, go to `/quality/inspections`
2. Find the auto-created incoming inspection (stage = `incoming`, linked to the GRN)
3. Record measurements for each parameter
4. Complete the inspection:
   - **Passed:**
     - GRN remains at `pending_qc` — you must now manually accept it
     - Proceed to Step 6
   - **Failed:**
     - **AUTO-TRIGGER:** NCR auto-created (from `InspectionService::complete()`)
     - **AUTO-TRIGGER:** GRN auto-rejected (via `RejectGRNOnQcFail` listener)
     - Stock is NOT added to inventory
     - NCR disposition decides next steps: `return_to_supplier` or `use_as_is`

---

### Step 6: Inventory Receipt (Accept GRN)

**What it is:** After incoming QC passes, goods are formally accepted into inventory.

**API endpoint:** `PATCH /api/v1/inventory/grn/{grn}/accept`

**How to test:**
1. After incoming QC passes, accept the GRN
2. **What happens automatically:**
   - Stock levels increase for each item
   - Weighted average cost recalculated: `new_avg = (old_total + new_total) / (old_qty + new_qty)`
   - Stock movements recorded
   - `StockMovementCompleted` event fires → `CheckReorderPoint` listener runs (evaluates other items)
3. Verify stock: `GET /api/v1/inventory/stock-levels`
4. View stock card: `GET /api/v1/inventory/items/{item}/stock-card`

**Material Issue (for production):**
Once materials are in inventory, they can be issued to Work Orders:
- `POST /api/v1/inventory/material-issues` — issue materials from warehouse to production floor
- This reduces available stock and links consumption to the WO

---

### Step 7: Vendor Bill

**What it is:** The supplier's invoice for the goods we received.

**Where in the app:** `/accounting/bills`

**API endpoints:**
- `POST /api/v1/bills` — create bill linked to PO
- `GET /api/v1/purchasing/three-way-match/{bill}` — 3-way match verification
- `PATCH .../cancel` — cancel bill
- `POST .../payments` — record payment
- `GET .../pdf` — download PDF

**Status transitions:**
```
unpaid → partial → paid
                    ↓
               (cancelled)
```

**How to test:**
1. Create bill — link to the PO, enter vendor invoice details
2. Run 3-way match: compares PO (what we ordered) vs GRN (what we received) vs Bill (what vendor charges)
   - Highlights discrepancies in quantities or prices
3. Bill starts as `unpaid`

---

### Step 8: Bill Payment

**API endpoint:** `POST /api/v1/bills/{bill}/payments`

**How to test:**
1. Record payment — specify amount, method, reference
2. Partial payment → `partial`
3. Full payment → `paid`
4. GL auto-posted: Debit Accounts Payable, Credit Cash/Bank
5. **Chain 2 is now complete** — materials received, vendor paid

---

## Chain 3 — Hire to Retire (H2R)

**The journey:** We hire an employee → manage their daily attendance → process leaves/overtime → compute payroll → generate payslips → handle separation.

```
┌───────────┐    ┌───────────┐    ┌───────────┐    ┌──────────────┐
│ Hire      │───▶│ Shift     │───▶│ Biometric │───▶│ DTR          │
│ Employee  │    │ Assignment│    │ CSV Import│    │ Computation  │
│           │    │           │    │           │    │              │
│ Creates   │    │ Bulk      │    │ Upload    │    │ Auto:        │
│ profile + │    │ assign    │    │ clock     │    │ • Hours      │
│ account + │    │ employees │    │ in/out    │    │ • Late mins  │
│ leave     │    │ to shifts │    │ records   │    │ • Undertime  │
│ balances  │    │           │    │           │    │ • Night diff │
│           │    │           │    │           │    │ • Auto OT    │
└───────────┘    └───────────┘    └───────────┘    └──────────────┘
                                                          │
                                                          ▼
┌───────────┐    ┌───────────┐                   ┌──────────────┐
│ Leave     │    │ Overtime  │                   │ Payroll      │
│ Requests  │    │ Requests  │──────────────────▶│ Computation  │
│           │    │           │                   │              │
│ pending_  │    │ pending   │    ┌──────────┐   │ Pulls:       │
│  dept     │    │ approved  │    │ Loans    │──▶│ • DTR        │
│ pending_  │    │ rejected  │    │          │   │ • Approved OT│
│  hr       │    │           │    │ pending  │   │ • Leaves     │
│ approved  │    │           │    │ active   │   │ • Loans      │
│ rejected  │    │           │    │ paid     │   │ • Gov deduct │
│           │    │           │    │          │   │ • Tax        │
└───────────┘    └───────────┘    └──────────┘   └──────────────┘
                                                          │
                                                          ▼
┌───────────┐    ┌───────────┐    ┌───────────┐    ┌──────────────┐
│ Final Pay │◀───│ Clearance │◀───│ Separation│◀───│ Payslip +    │
│           │    │           │    │           │    │ Bank File    │
│ Compute   │    │ pending   │    │ Initiates │    │              │
│ remaining │    │ in_prog   │    │ Employee  │    │ draft        │
│ balance + │    │ completed │    │ status →  │    │ processing   │
│ deductions│    │ finalized │    │ resigned/ │    │ approved     │
│           │    │           │    │ terminated│    │ finalized    │
│           │    │ Each dept │    │ retired   │    │ disbursed    │
│           │    │ signs off │    │           │    │              │
│           │    │           │    │ Clearance │    │ Auto:        │
│           │    │ Account   │    │ auto-     │    │ • Bank file  │
│           │    │ auto-     │    │ created   │    │ • Payslip    │
│           │    │ deactivated   │           │    │ • Email      │
└───────────┘    └───────────┘    └───────────┘    └──────────────┘
```

### Step 1: Hire Employee

**Where in the app:** `/hr/employees`

**API endpoints:**
- `POST /api/v1/hr/employees` — create employee
- `POST .../provision-account` — create system login account
- `POST /api/v1/hr/employees/bulk-provision-accounts` — bulk create accounts

**Employee statuses:**
```
active | on_leave | suspended | resigned | terminated | retired
```

**How to test:**
1. Create departments first (`/hr/departments`)
2. Create positions (`/hr/positions`)
3. Create employee: personal info, department, position, pay type (monthly/daily), salary, gov IDs
4. Auto-generates employee number (OGM-YYYY-NNNN)
5. **AUTO-TRIGGERS on `EmployeeCreated` event:**
   - `InitializeLeaveBalances` — creates leave balance records for all active leave types
   - `AutoProvisionUserOnEmployeeHire` — creates system user account automatically
6. Optionally provision account manually: sets up login credentials

---

### Step 2: Shift Assignment

**Where in the app:** `/hr/attendance/shifts`

**API endpoints:**
- `POST /api/v1/attendance/shifts` — create shift definition
- `POST /api/v1/attendance/shifts/bulk-assign` — assign employees to shifts

**How to test:**
1. Create shifts (e.g., Day: 6AM-3PM, Swing: 2PM-11PM, Night: 10PM-7AM)
2. Bulk assign employees to shifts for a date range
3. Extended shift (6AM-6PM) → system auto-detects OT beyond regular hours

---

### Step 3: Biometric CSV Import

**Where in the app:** `/hr/attendance/import`

**API endpoint:** `POST /api/v1/attendance/attendances/import`

**How to test:**
1. Prepare CSV file with biometric data (employee ID, datetime stamps)
2. Upload via `/hr/attendance/import`
3. System parses clock-in/clock-out pairs
4. DTR computation runs automatically for each attendance record

**Manual attendance:** `POST /api/v1/attendance/attendances` — for manual entry

---

### Step 4: DTR Computation (automatic)

**What it is:** Daily Time Record computation. Calculates actual hours, late, undertime, night differential, and auto-detected overtime.

**Service:** `DTRComputationService`

**What it computes:**
- Regular hours worked
- Late minutes (clock-in after shift start)
- Undertime (clock-out before shift end)
- Night differential hours (10PM-6AM, 10% premium)
- Auto-detected overtime (hours beyond scheduled shift, min 30min, max 4hrs)
- Holiday premiums (regular holiday, special holiday)

---

### Step 5: Leave Requests

**Where in the app:** `/hr/leaves`

**API endpoints:**
- `GET /api/v1/leaves/balances/me` — check my balance
- `POST /api/v1/leaves/requests` — file leave request
- `PATCH .../approve-dept` — department head approves
- `PATCH .../approve-hr` — HR approves
- `PATCH .../reject` — reject
- `PATCH .../cancel` — cancel
- `POST .../bulk-approve-dept` / `POST .../bulk-approve-hr` — bulk approve
- `GET /api/v1/leaves/calendar` — department calendar heatmap

**Status transitions (two-level approval):**
```
pending_dept → pending_hr → approved
             → rejected     → rejected
                                 ↓
                            (cancelled)
```

**How to test:**
1. Check leave balance first
2. File leave request — select type, dates, reason
3. **AUTO-TRIGGER:** `LeaveRequestSubmitted` event → dept head notified
4. Dept head approves → `pending_hr`
5. **AUTO-TRIGGER:** `LeaveRequestPendingHR` event → HR notified
6. HR approves → `approved`
7. **AUTO-TRIGGER:** `LeaveRequestApproved` event → employee notified
8. Leave balance auto-deducted
9. If rejected: `LeaveRequestRejected` event → employee notified

**Year-end processing:** `POST /api/v1/leaves/process-year-end` — carries over/expires unused balances

---

### Step 6: Overtime Requests

**Where in the app:** `/hr/attendance/overtime`

**API endpoints:**
- `POST /api/v1/attendance/overtime-requests` — file OT request
- `PATCH .../approve` — approve
- `PATCH .../reject` — reject
- `POST .../bulk-approve` — bulk approve

**Status transitions:**
```
pending → approved | rejected
```

**Rules:** Minimum 30 minutes, maximum 4 hours per request.

**Self-service:** Employees can file OT via `/hr/self-service/overtime`

---

### Step 7: Loans

**Where in the app:** `/hr/loans`

**API endpoints:**
- `POST /api/v1/loans` — create loan application
- `POST .../preview-amortization` — preview payment schedule
- `GET .../limits/{employee}` — check borrowing limits
- `PATCH .../approve` — approve
- `POST .../bulk-approve` — bulk approve

**Status transitions:**
```
pending → active → paid
        → rejected
                    ↓
               (cancelled)
```

**Rules:**
- Zero interest
- Max 1 month salary
- 1 company loan + 1 cash advance at a time
- Auto-deducted from payroll each period

---

### Step 8: Payroll Computation

**Where in the app:** `/payroll/periods`

**API endpoints:**
- `POST /api/v1/payroll-periods` — create period (or auto-created by cron)
- `POST .../compute` — compute payroll
- `PATCH .../approve` — approve
- `PATCH .../finalize` — finalize (locks)
- `GET .../variance` — compare to previous period
- `GET .../anomalies` — review flagged anomalies
- `PATCH .../mark-disbursed` — mark as paid out
- `POST .../force-unlock` — admin escape hatch for stuck periods

**Status transitions:**
```
draft → processing → approved → finalized → disbursed
                                                ↓
                                            (voided)
```

**How to test:**
1. Create payroll period (semi-monthly: 1st-15th or 16th-end)
   - Or wait for cron `payroll:auto-create-period` (runs on 14th and last day of month)
2. Compute payroll (`POST .../compute`):
   - **Pulls automatically:** DTR records, approved OT, approved leaves, active loan amortizations
   - **Calculates gross pay:** basic salary + OT premium + night diff + holiday premium
   - **Deducts (1st period only):** SSS, PhilHealth, Pag-IBIG contributions
   - **Deducts:** withholding tax (BIR tables)
   - **Deducts:** active loan payments
   - **Flags anomalies:** payroll spikes, missing DTR, unusual amounts
3. Review anomalies: `GET .../anomalies`
4. Approve (`PATCH .../approve`)
5. Finalize (`PATCH .../finalize`) — LOCKED, no more changes
   - **AUTO-TRIGGERS on `PayrollPeriodFinalized` event:**
     - `GenerateBankFileOnPayrollFinalized` — prepares bank upload file
     - `EmailPayslipPdfOnPayrollFinalized` — emails payslip PDFs
     - `NotifyEmployeesOnPayrollFinalized` — notifies employees

**Payroll corrections:** Never unlock finalized. Create adjustments for next period:
- `POST /api/v1/payroll-adjustments` → approve → applies to next computation

---

### Step 9: Payslip & Bank File

**API endpoints:**
- `GET /api/v1/payrolls/{payroll}/payslip` — individual payslip PDF
- `GET /api/v1/payroll-periods/{period}/bank-file/preview` — preview bank file
- `GET /api/v1/payroll-periods/{period}/bank-file` — download bank file (CSV for bank upload)

**How to test:**
1. Download payslip for any employee in the period
2. Preview bank file → check amounts and account numbers
3. Download bank file → upload to bank portal (outside ERP)
4. Mark disbursed: `PATCH .../mark-disbursed`
5. Upload disbursement proof: `POST .../disbursement-proofs`

**Statutory exports:**
- `GET /api/v1/payroll/statutory/1601c` — BIR Monthly Remittance
- `GET /api/v1/payroll/statutory/rf1` — PhilHealth RF-1
- `GET /api/v1/payroll/statutory/mcrf` — Pag-IBIG MCRF
- `GET /api/v1/payroll/bir-alphalist` — BIR 2316 Alphalist (annual)

---

### Step 10: 13th Month Pay

**API endpoint:** `POST /api/v1/payroll-periods/thirteenth-month`

**How to test:** Run at year-end. Computes 13th month pay for all eligible employees based on total basic salary earned during the year.

---

### Step 11: Employee Separation

**Where in the app:** `/hr/employees/{id}` → Separate

**API endpoints:**
- `PATCH /api/v1/hr/employees/{employee}/separate` — change status to resigned/terminated/retired
- `POST /api/v1/hr/employees/{employee}/separation` — initiate formal separation

**How to test:**
1. Separate employee — specify reason (resignation, termination, retirement)
2. Employee status changes
3. **AUTO-TRIGGER:** `SeparationInitiated` event → notifies HR, department head, IT
4. Clearance process auto-created

---

### Step 12: Clearance & Final Pay

**Where in the app:** `/hr/clearances`

**API endpoints:**
- `GET /api/v1/hr/clearances` — list clearances
- `PATCH .../items` — department signs off items
- `POST .../final-pay/compute` — compute final pay
- `PATCH .../finalize` — finalize clearance

**Status transitions:**
```
pending → in_progress → completed → finalized
                                        ↓
                                   (cancelled)
```

**How to test:**
1. View clearance — shows checklist items (IT equipment returned, admin clearance, etc.)
2. Each department signs their items (`PATCH .../items`)
3. When all items signed → `completed`
4. **AUTO-TRIGGER:** `ClearanceFullySigned` event → `DeactivateAccountOnClearanceComplete` listener → system account deactivated
5. Compute final pay → calculates remaining salary, unused leave cash-out, 13th month pro-rata, minus any outstanding loans
6. Finalize → `finalized`
7. **Chain 3 is now complete** — employee fully separated

---

## Quality System (IATF 16949)

Quality is not a separate chain — it's woven through Chains 1 and 2 at four inspection touchpoints plus the NCR feedback loop.

### Inspection Stages

| Stage | Chain | When | Trigger | What it gates |
|-------|-------|------|---------|---------------|
| `incoming` | P2P | After GRN, before inventory acceptance | `GoodsReceiptNoteCreated` event → `TriggerIncomingQC` | GRN acceptance. Fail → auto-reject GRN + create NCR |
| `in_process` | O2C | During production (WO in progress) | `WorkOrderStatusChanged` → `TriggerInProcessQC` | Production quality. Fail → NCR, may pause WO |
| `outgoing` | O2C | After WO completion, before delivery | `WorkOrderCompleted` → `TriggerOutgoingQC` | Delivery. Pass → auto-draft delivery. Fail → blocks shipping |
| `supplier_return` | P2P | Returning defective goods to supplier | Manual | Documents condition for vendor claim |
| `customer_return` | O2C | Customer returns goods | Manual (RMA flow) | Documents condition for credit/replacement |

### NCR (Non-Conformance Report) Lifecycle

**Sources:** `inspection_fail` (auto-created) | `customer_complaint` (manual)

**Dispositions:** `scrap` | `rework` | `use_as_is` | `return_to_supplier`

**Status flow:**
```
open → in_progress → closed
                       ↓
                  (cancelled)
```

**API endpoints:**
- `POST /api/v1/quality/ncrs` — create manually
- `POST .../actions` — add corrective/preventive action (CAPA)
- `PATCH .../disposition` — set disposition
- `PATCH .../actions/{action}/verify` — verify CAPA effectiveness
- `POST .../close` — close NCR
- `POST .../bulk-close` — bulk close

**Auto-triggers:**
- Recurring NCRs → `NcrRecurrenceLinked` event → `AutoSpawn8DOnNcrRecurrence` — auto-creates 8D investigation
- NCR escalation cron runs every 15 minutes

### Inspection Specs & SPC

**Setup (do this before testing QC):**
1. Create inspection specs per product: `POST /api/v1/quality/inspection-specs`
2. Define parameters: dimension name, nominal value, upper tolerance, lower tolerance, critical flag
3. SPC (Statistical Process Control) data: `GET .../spc` — trend analysis for a parameter

### Certificate of Conformance (CoC)

Auto-generated when outgoing inspection passes. Attached to delivery on confirm.
- `GET /api/v1/quality/inspections/{id}/coc` — download CoC PDF

### PPAP (Production Part Approval Process)

IATF-required process for new parts or changes:
- `POST /api/v1/quality/ppap` → submit → review → approve/reject
- Track 18 PPAP elements per submission

### Calibration Register

Track measurement equipment calibration:
- `POST /api/v1/quality/calibration` — register equipment
- `POST .../record` — record calibration event

### Quality Analytics

- Defect Pareto: `GET /api/v1/quality/analytics/defect-pareto`
- Pareto drill-down: `GET .../drill`
- COPQ trend: `GET /api/v1/quality/copq/trend`
- Traceability search: `GET /api/v1/quality/traceability/search`

---

## Maintenance System

### Preventive Maintenance

**Cron:** `maintenance:generate-preventive` runs daily at 02:00 — auto-creates maintenance work orders from schedules.

**API endpoints:**
- Schedules: `POST /api/v1/maintenance/schedules` — define recurring maintenance
- Work Orders: `POST /api/v1/maintenance/work-orders` — create

**MWO Status:**
```
open → assigned → in_progress → completed
                                     ↓
                                (cancelled)
```

### Corrective Maintenance (machine breakdown)

**Auto-trigger:** `MachineStatusChanged` event → `HandleMachineBreakdown` listener → auto-creates corrective MWO when a machine status changes to breakdown.

### Predictive Maintenance (condition-based)

- Record condition readings: `POST /api/v1/maintenance/condition-readings`
- System evaluates readings against thresholds
- If threshold breached → auto-creates corrective MWO via `PredictiveMaintenanceService::recordAndEvaluate()`

### Mold Shot Tracking

- Auto-incremented during WO output recording
- Alert at 80% of max shot count → preventive maintenance or mold replacement
- Mold history: `GET /api/v1/mrp/molds/{mold}/history`

### Downtime Analytics

- Summary: `GET /api/v1/maintenance/downtime-analytics/summary`
- Daily trend: `GET .../daily-trend`
- Top machines: `GET .../top-machines`
- Pareto: `GET .../pareto`

---

## Cross-Module Auto-Triggers (Event Map)

This is the complete map of events and what they automatically trigger. Understanding these is critical for testing — many things happen "by magic" behind the scenes.

### Chain 1 (O2C) Events

| Event | Listener | What Happens |
|-------|----------|--------------|
| `SalesOrderConfirmed` | `NotifyOnSalesOrderConfirmed` | Notifies production, PPC team |
| `WorkOrderStatusChanged` (→ in_progress) | `TriggerInProcessQC` | Auto-creates in-process inspection |
| `WorkOrderCompleted` | `TriggerOutgoingQC` | Auto-creates outgoing inspection |
| `WorkOrderCompleted` | `NotifyOnWorkOrderCompleted` | Notifies QC team |
| `InspectionPassed` (outgoing) | `CreateDeliveryDraftOnQcPass` | Auto-drafts delivery + notifies warehouse |
| `DeliveryConfirmed` | `NotifyFinanceOnDeliveryConfirmed` | Notifies finance to invoice |
| `DeliveryConfirmed` | (inside DeliveryService) | Auto-creates draft invoice, updates SO status |

### Chain 2 (P2P) Events

| Event | Listener | What Happens |
|-------|----------|--------------|
| `GoodsReceiptNoteCreated` | `TriggerIncomingQC` | Auto-creates incoming inspection |
| `GoodsReceiptNoteCreated` | `NotifyOnGrnReceived` | Notifies QC team |
| `InspectionFailed` (incoming) | `RejectGRNOnQcFail` | Auto-rejects GRN |
| `InspectionFailed` | `NotifyOnInspectionFailed` | Notifies QC manager |
| `PurchaseRequestApproved` | `NotifyOnPurchaseRequestApproved` | Notifies purchasing team |
| `PurchaseOrderApproved` | `NotifyOnPurchaseOrderApproved` | Notifies supplier coordinator |
| `StockMovementCompleted` | `CheckReorderPoint` | Auto-creates PR if below reorder point |
| `LowStockPrCreated` | `NotifyOnLowStockPrCreated` | Notifies purchasing of auto-PR |
| `SupplierPerformanceComputed` | `AlertOnSupplierDeterioration` | Alerts on supplier quality drop |

### Chain 3 (H2R) Events

| Event | Listener | What Happens |
|-------|----------|--------------|
| `EmployeeCreated` | `InitializeLeaveBalances` | Creates leave balance records |
| `EmployeeCreated` | `AutoProvisionUserOnEmployeeHire` | Creates system login account |
| `SeparationInitiated` | `NotifyOnSeparationInitiated` | Notifies HR, dept head, IT |
| `ClearanceFullySigned` | `DeactivateAccountOnClearanceComplete` | Deactivates system account |
| `PayrollPeriodFinalized` | `GenerateBankFileOnPayrollFinalized` | Prepares bank upload file |
| `PayrollPeriodFinalized` | `EmailPayslipPdfOnPayrollFinalized` | Emails payslip PDFs |
| `PayrollPeriodFinalized` | `NotifyEmployeesOnPayrollFinalized` | Notifies employees |
| `LeaveRequestSubmitted` | `NotifyOnLeaveSubmitted` | Notifies dept head |
| `LeaveRequestPendingHR` | `NotifyOnLeavePendingHR` | Notifies HR |
| `LeaveRequestApproved` | `NotifyOnLeaveApproved` | Notifies employee |
| `LeaveRequestRejected` | `NotifyOnLeaveRejected` | Notifies employee |

### Cross-Chain Events

| Event | Listener | What Happens |
|-------|----------|--------------|
| `MachineStatusChanged` | `HandleMachineBreakdown` | Auto-creates maintenance work order |
| `MachineBreakdownDetected` | `NotifyOnMachineBreakdown` | Notifies maintenance team |
| `NcrRecurrenceLinked` | `AutoSpawn8DOnNcrRecurrence` | Auto-creates 8D investigation |
| `CopqSnapshotComputed` | `AlertOnCopqSpike` | Alerts on cost of poor quality spike |

---

## Alternative Scenarios & Edge Cases

### Scenario 1: Materials Not in Stock (O2C → P2P Bridge)

```
SO confirmed → MRP runs → BOM exploded → material shortage found
→ Auto-creates draft PR with all shortages
→ PR submitted → approved → converted to PO
→ PO sent to supplier → goods shipped → GRN → incoming QC → inventory
→ NOW production can start (materials available)
→ Resume Chain 1 from Work Order execution
```

**How to test:** Create SO for product with BOM requiring materials not in stock. Confirm SO → run MRP → verify auto-PR created → complete Chain 2 → then complete Chain 1.

### Scenario 2: Stock Drops Below Reorder Point (Auto-Replenishment)

```
Material issued to production → stock level drops
→ StockMovementCompleted event fires
→ CheckReorderPoint listener evaluates
→ Available < reorder_point?
  → Normal item: Auto-creates draft PR
  → Critical item with 1 preferred supplier: Auto-creates PO directly (skips PR)
→ Notifies purchasing team
```

**How to test:** Set an item's reorder point to a value close to current stock. Issue materials until stock drops below reorder point. Check for auto-PR or auto-PO.

### Scenario 3: QC Fails at Incoming (Chain 2 Blockage)

```
GRN created → Incoming inspection auto-created → inspector records measurements
→ Inspection fails (material out of spec)
→ NCR auto-created (source: inspection_fail)
→ GRN auto-rejected (stock NOT added)
→ NCR disposition options:
  a) return_to_supplier → new PO needed, restart from Step 2
  b) use_as_is → accept with deviation, proceed cautiously
  c) scrap → material wasted, re-order needed
  d) rework → supplier reworks and re-ships
```

**How to test:** Create GRN → in incoming QC, enter out-of-tolerance measurements → complete as failed → verify NCR created and GRN rejected.

### Scenario 4: QC Fails at Outgoing (Chain 1 Blockage)

```
WO completed → Outgoing inspection auto-created → inspector measures
→ Inspection fails (defects > AQL accept count)
→ NCR auto-created
→ Delivery is NOT auto-drafted (blocked)
→ NCR disposition:
  a) rework → new WO created for rework → re-inspect
  b) scrap → material lost, new WO from scratch
  c) use_as_is → accept with customer approval, delivery proceeds
```

**How to test:** Complete a WO → in outgoing QC, fail the inspection → verify no delivery created → create NCR disposition → rework via new WO → re-inspect.

### Scenario 5: QC Fails at In-Process (Production Issue)

```
WO started → In-process inspection auto-created → operator samples parts
→ Inspection fails during production run
→ NCR auto-created
→ Production may need to:
  a) Pause WO → investigate root cause → fix → resume
  b) Adjust machine settings → re-sample
  c) Switch molds if mold issue
```

### Scenario 6: Partial Delivery

```
SO has 1000 units → Only 500 pass outgoing QC
→ Delivery created for 500 units
→ SO status = partially_delivered
→ Remaining 500 need another production run
→ Second WO → QC → Delivery → SO status = delivered
```

### Scenario 7: Customer Complaint → 8D Investigation

```
Customer files complaint → Complaint created in CRM
→ 8D methodology applied:
  D1: Team formed
  D2: Problem described
  D3: Containment actions
  D4: Root cause analysis
  D5: Corrective actions
  D6: Verified
  D7: Preventive actions
  D8: Team recognized
→ NCR created from complaint (source: customer_complaint)
→ CAPA effectiveness verified
→ Complaint resolved/closed
```

**Routes:** `/crm/complaints` → 8D workflow → close

### Scenario 8: Machine Breakdown During Production

```
Machine status changes to breakdown
→ MachineStatusChanged event
→ HandleMachineBreakdown listener → auto-creates corrective MWO
→ Maintenance tech assigned → repairs machine
→ Production WO paused during breakdown → resumed after repair
```

### Scenario 9: Mold at End of Life

```
WO output recorded → mold shot count incremented
→ Shot count reaches 80% of max → alert generated
→ Preventive maintenance scheduled
→ At 100% → mold decommissioned
→ New mold commissioned or compatible mold assigned
```

### Scenario 10: Payroll with Everything

```
Period: June 1-15, 2026
→ DTR shows: 10 regular days, 1 regular holiday, 1 late (15 min)
→ Approved OT: 3 hours on June 5
→ Approved Leave: 1 day sick leave
→ Active Loan: ₱2,000/period deduction
→ Night diff: 2 hours on June 10 (10PM-12AM)

Computation:
  Basic: (monthly_salary / 2) - late deduction + leave (no deduction if with balance)
  OT premium: hourly_rate × 1.25 × 3 hours
  Night diff: hourly_rate × 0.10 × 2 hours
  Holiday: daily_rate × 1.0 (additional 100%)
  Gross = sum of above
  
  Deductions (1st period only):
  - SSS contribution (from table)
  - PhilHealth (from table)
  - Pag-IBIG (from table)
  - Withholding tax (BIR table)
  - Loan: ₱2,000
  
  Net = Gross - Deductions
```

### Scenario 11: Auto-Invoice Failure

```
Delivery confirmed → system tries to auto-create invoice
→ Accounting module disabled or misconfigured
→ Auto-invoice fails (best-effort, never blocks delivery)
→ Finance team receives notification: "Auto-invoice could not be created. Please create manually."
→ Finance creates invoice manually
```

### Scenario 12: Return Management (RMA)

```
Customer reports defective product
→ Return Request created (RMA)
→ Goods shipped back
→ Customer Return inspection (stage: customer_return)
→ Disposition: replace, credit memo, or repair
→ NCR auto-linked
→ If replacing: new WO created → production → QC → delivery
→ Credit memo issued if applicable
```

**Routes:** `/return-management/...`

---

## Recommended Testing Order

### Phase 0: Master Data Setup (do this first)

1. **Company settings** — `/admin/settings`
2. **Roles & permissions** — verify seeded roles exist (`/admin/roles`)
3. **Departments** — create at least 3 (`/hr/departments`)
4. **Positions** — create at least 3 (`/hr/positions`)
5. **Chart of Accounts** — verify seeded COA (`/accounting/accounts`)
6. **Warehouses & zones** — create at least 1 (`/inventory/warehouses`)
7. **Item categories** — create hierarchy (`/inventory/item-categories`)
8. **Items (raw materials)** — create at least 5 with reorder points (`/inventory/items`)
9. **Stock** — add initial stock via adjustments (`/inventory/stock-adjustments`)
10. **Vendors** — create at least 2 (`/accounting/vendors`)
11. **Customers** — create at least 2 (`/accounting/customers` or `/crm/customers`)
12. **Products** — create at least 2 finished goods (`/crm/products`)
13. **BOMs** — create for each product (`/mrp/boms`)
14. **Machines** — create at least 2 (`/mrp/machines`)
15. **Molds** — create at least 2 with product compatibility (`/mrp/molds`)
16. **Inspection Specs** — create for each product (`/quality/inspection-specs`)
17. **Shifts** — create at least 2 (`/hr/attendance/shifts`)
18. **Holidays** — add current year holidays (`/hr/attendance/holidays`)
19. **Leave Types** — verify seeded types (`/hr/leaves/types`)

### Phase 1: Chain 3 (Hire to Retire) — simplest, no dependencies on other chains

1. Create 5+ employees
2. Assign shifts
3. Import biometric attendance (or create manual)
4. File and approve leave requests
5. File and approve OT requests
6. Create and approve loans
7. Create payroll period → compute → review anomalies → approve → finalize
8. Download payslip and bank file
9. Separate one employee → clearance → final pay

### Phase 2: Chain 2 (Procure to Pay) — needed before Chain 1

1. Create manual Purchase Request → submit → approve
2. Convert PR to Purchase Order → submit → approve → send
3. Create Shipment (optional, for imported goods)
4. Create GRN → verify incoming QC auto-created
5. Complete incoming QC (test both pass and fail)
6. Accept GRN → verify stock increased
7. Create Bill → 3-way match → record payment
8. Test reorder point auto-PR: issue materials until below threshold

### Phase 3: Chain 1 (Order to Cash) — longest, uses everything

1. Create Sales Order → confirm
2. Run MRP → verify auto-PR created (if materials short) and draft WOs created
3. Complete Chain 2 for the auto-PR (if applicable)
4. Confirm WO → start → verify in-process QC auto-created
5. Record output → complete WO → verify outgoing QC auto-created
6. Complete outgoing QC (pass) → verify delivery auto-drafted
7. Advance delivery through all statuses → confirm → verify invoice auto-drafted
8. Finalize invoice → record collection
9. Test outgoing QC failure → verify NCR and no delivery
10. Test the full chain view: `/crm/sales-orders/{id}/chain`

### Phase 4: Quality & Maintenance

1. Fail an incoming QC → verify GRN auto-rejected + NCR
2. Fail an outgoing QC → verify delivery blocked + NCR
3. Create NCR manually → add CAPA → verify effectiveness → close
4. Create customer complaint → 8D workflow → resolve
5. Create maintenance schedule → verify auto-generated MWOs
6. Record condition readings → test threshold breach → verify corrective MWO
7. Check mold shot counts approaching limit

### Phase 5: Edge Cases & Cross-Chain

1. Test auto-invoice failure recovery
2. Test partial delivery scenario
3. Test bulk operations (bulk approve PR, PO, leaves, OT)
4. Test the self-service portal as an employee
5. Test the driver delivery interface
6. Test the B2B supplier/customer portal
7. Check all PDF generation (PO, invoice, bill, payslip, CoC, JE, PR, financial statements)
8. Verify all dashboard widgets

---

## API Quick Reference

### Authentication

```
1. GET /sanctum/csrf-cookie (get XSRF token)
2. POST /api/v1/auth/login {email, password} (login)
3. All subsequent requests carry session cookie automatically
```

### Document Number Formats

| Document | Format | Example |
|----------|--------|---------|
| Employee | OGM-YYYY-NNNN | OGM-2026-0142 |
| Sales Order | SO-YYYYMM-NNNN | SO-202604-0003 |
| Purchase Request | PR-YYYYMM-NNNN | PR-202604-0015 |
| Purchase Order | PO-YYYYMM-NNNN | PO-202604-0015 |
| Work Order | WO-YYYYMM-NNNN | WO-202604-0006 |
| GRN | GRN-YYYYMM-NNNN | GRN-202604-0011 |
| Inspection | QC-YYYYMM-NNNN | QC-202604-0012 |
| NCR | NCR-YYYYMM-NNNN | NCR-202604-0002 |
| Delivery | DEL-YYYYMM-NNNN | DEL-202604-0008 |
| Invoice | INV-YYYYMM-NNNN | INV-202604-0008 |
| Journal Entry | JE-YYYYMM-NNNN | JE-202604-0032 |
| Leave Request | LR-YYYYMM-NNNN | LR-202604-0045 |

### Cron Jobs (automated processes)

| Schedule | Command | Purpose |
|----------|---------|---------|
| 06:00 daily | `mrp:run-daily` | MRP for all active SOs |
| Every 15 min | `alerts:run` | System alerts |
| 14th + last day 23:00 | `payroll:auto-create-period` | Auto-create payroll period |
| Every 6 hours | `approvals:run-escalations` | Escalate stale approvals |
| Daily | `purchasing:recompute-supplier-performance` | Supplier scoring |
| 02:00 daily | `maintenance:generate-preventive` | Generate preventive MWOs |
| 1st @ 03:00 | `assets:run-monthly-depreciation` | Asset depreciation |
| Every 15 min | `ncr:escalate` | Escalate overdue NCRs |
| 06:30 daily | `training:check-expiries` | Flag expired training certs |
| 1st @ 02:30 | `copq:snap-monthly` | Cost of Poor Quality snapshot |
| Every 15 min | `complaints:check-8d-slas` | Check 8D SLA deadlines |
| 06:45 daily | `docs:check-reviews` | Flag documents due for review |

### Test Accounts (seeded roles)

| Role Slug | Access |
|-----------|--------|
| `system_admin` | Everything |
| `hr_officer` | HR, Attendance, Leave, Payroll, Loans |
| `finance_officer` | Accounting, Invoices, Bills, Budgeting |
| `production_manager` | Production, MRP, Work Orders |
| `ppc_head` | MRP, Scheduling, Planning |
| `purchasing_officer` | Purchasing, PRs, POs |
| `warehouse_staff` | Inventory, GRN, Material Issues |
| `qc_inspector` | Quality, Inspections, NCRs |
| `maintenance_tech` | Maintenance, MWOs |
| `impex_officer` | Supply Chain, Shipments, Deliveries |
| `department_head` | Department-scoped approvals |
| `employee` | Self-service only |
| `driver` | Driver delivery surface |
