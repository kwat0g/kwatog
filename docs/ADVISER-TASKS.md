# OGAMI ERP — Adviser Feedback Tasks

> Source: Panel review notes on thesis title page, March 2026.
> These are MANDATORY requirements from your adviser. All 12 must be implemented before defense.
>
> **How to execute:**
> `claude "Read CLAUDE.md, docs/PATTERNS.md, docs/DESIGN-SYSTEM.md. Execute Task [CODE] from docs/ADVISER-TASKS.md completely."`

---

## ITEM 1 — PROOF OF DISBURSEMENT OF SALARY
### Task ADV1: Salary Disbursement Proof (Deposit Slip Attachment)

**What the adviser wants:**
After payroll is processed and bank file sent, there must be PROOF that salaries were actually disbursed — not just computed. This means attaching the bank deposit slip or transaction confirmation to the payroll period record.

**What to build:**

**Database:**
```sql
-- Migration: 0121_add_disbursement_proof_to_payroll_periods.php
ALTER TABLE payroll_periods ADD COLUMN disbursement_status VARCHAR(20) DEFAULT 'pending';
-- Values: pending, partially_disbursed, disbursed
ALTER TABLE payroll_periods ADD COLUMN disbursed_at TIMESTAMP NULL;
ALTER TABLE payroll_periods ADD COLUMN disbursed_by BIGINT NULL REFERENCES users(id);

-- New table: payroll_disbursement_proofs
CREATE TABLE payroll_disbursement_proofs (
    id BIGSERIAL PRIMARY KEY,
    payroll_period_id BIGINT NOT NULL REFERENCES payroll_periods(id),
    proof_type VARCHAR(30) NOT NULL, -- 'deposit_slip', 'bank_confirmation', 'transfer_receipt', 'other'
    file_name VARCHAR(200) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    bank_name VARCHAR(100),
    transaction_reference VARCHAR(100),
    disbursed_amount DECIMAL(15,2),
    disbursement_date DATE NOT NULL,
    uploaded_by BIGINT NOT NULL REFERENCES users(id),
    notes TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);
```

**Backend:**
- `POST /api/v1/payroll-periods/{id}/disbursement-proofs` — upload proof file (PDF, JPG, PNG max 10MB)
- `GET /api/v1/payroll-periods/{id}/disbursement-proofs` — list all proofs for period
- `DELETE /api/v1/payroll-periods/{id}/disbursement-proofs/{proof_id}` — remove proof (Finance only)
- `PATCH /api/v1/payroll-periods/{id}/mark-disbursed` — mark period as fully disbursed
- Store files in `storage/app/private/payroll-proofs/` (outside web root, served via controller)

**Workflow change — Payroll lifecycle now has 6 steps:**
```
Draft → Computed → HR Approved → Finance Confirmed → Finalized → Disbursed
                                                          ↑             ↑
                                                    (bank file     (deposit slip
                                                     generated)      uploaded)
```

**Frontend — Payroll Period detail page:**

Add new section below the employee table:

```
DISBURSEMENT PROOF                          [Upload Proof]
────────────────────────────────────────────────────────────
Status:  ○ Pending disbursement

No proof uploaded yet. After transferring salaries, upload
the bank deposit slip or transaction confirmation here.

[Upload Deposit Slip / Bank Confirmation]
```

After upload:
```
DISBURSEMENT PROOF                          [Upload Another]
────────────────────────────────────────────────────────────
Status:  ✅ Disbursed on Apr 15, 2026

┌─────────────────────────────────────────────────────────┐
│ 📄 BDO_TransferConfirmation_Apr15.pdf                   │
│ Bank: BDO Unibank · Ref: TXN20260415001                 │
│ Amount: ₱ 2,847,500.00 · Date: Apr 15, 2026            │
│ Uploaded by: Ana Reyes · 14:32                          │
│                              [View] [Download] [Delete] │
└─────────────────────────────────────────────────────────┘
```

**Self-service:** Employee can see on their payslip page:
```
Payment Status: ✅ Disbursed on Apr 15, 2026
```

**ChainHeader update:** Add "Disbursed" as the final step in the Hire-to-Retire payroll chain.

**Printable Payroll Register:** Add footer section:
```
DISBURSEMENT CERTIFICATION
I certify that the above payroll has been processed and disbursed.

Prepared by: ________________  Finance Officer: ________________
Date: ___________             Date: ___________
```

---

## ITEM 2 — SCM & MRP ARE SEPARATE MODULES
### Task ADV2: Sidebar Restructure — Clear Module Separation

**What the adviser wants:**
Supply Chain Management (SCM) and Material Requirements Planning (MRP) must be clearly separate modules in the navigation — not mixed together or under the same parent.

**Current sidebar (wrong grouping):**
```
OPERATIONS
  ├─ Sales Orders
  ├─ Production Orders
  ├─ MRP Plans          ← mixed with operations
  ├─ Inventory
  └─ Deliveries
```

**New sidebar (correct):**
```
SALES & CRM
  ├─ Dashboard
  ├─ Sales Orders
  ├─ Customers
  ├─ Price Agreements
  └─ Customer Complaints

PRODUCTION PLANNING (MRP)
  ├─ MRP Plans
  ├─ Capacity Planning (MRP II)
  ├─ Production Schedule (Gantt)
  ├─ Bill of Materials
  ├─ Machines
  └─ Molds

PRODUCTION
  ├─ Work Orders
  ├─ Output Recording
  ├─ Machine Status
  └─ OEE Report

SUPPLY CHAIN (SCM)
  ├─ Shipments & Import Docs
  ├─ Deliveries
  ├─ Fleet Management
  └─ Supplier Management

PROCUREMENT
  ├─ Purchase Requests
  ├─ Purchase Orders
  └─ Approved Suppliers

WAREHOUSE
  ├─ Inventory
  ├─ Warehouse Structure
  ├─ Stock Movements
  ├─ GRN (Receiving)
  ├─ Material Issuance
  └─ Stock Count

QUALITY CONTROL
  ├─ Inspection Specs
  ├─ Inspections
  ├─ NCR
  └─ Certificates of Conformance

FINANCE & ACCOUNTING
  ├─ General Ledger
  ├─ Accounts Payable
  ├─ Accounts Receivable
  ├─ Chart of Accounts
  ├─ Journal Entries
  └─ Financial Statements

BUDGETING
  ├─ Department Budgets
  ├─ Budget vs Actual
  └─ Budget Transfers

HUMAN RESOURCES
  ├─ Employees
  ├─ Departments
  ├─ Positions
  └─ Employee Directory

PAYROLL & BENEFITS
  ├─ Payroll Periods
  ├─ Attendance
  ├─ Leave Management
  ├─ Loans & Cash Advance
  └─ Government Reports

MAINTENANCE
  ├─ Maintenance Schedule
  ├─ Work Orders
  └─ Assets

ADMINISTRATION
  ├─ User Management
  ├─ Roles & Permissions
  ├─ System Settings
  └─ Audit Logs
```

**Implementation:**

Update `spa/src/components/layout/Sidebar.tsx`:
- Each section group has its own color accent dot (1px circle, matches module color from DESIGN-SYSTEM.md)
- Section headers styled: `text-2xs uppercase tracking-widest text-subtle font-medium`
- Active module section slightly more visible (not dramatically different — still monochrome)
- Collapsed rail: show section dividers as thin horizontal lines between icon groups

Update `spa/src/App.tsx` route structure to match new module organization.

Update page `<title>` tags and breadcrumbs to reflect new module names:
- "MRP Plans" → breadcrumb: "Production Planning > MRP Plans"
- "Deliveries" → breadcrumb: "Supply Chain > Deliveries"

---

## ITEM 3 — PRODUCTION NUMBER (BATCH NO., LOT NO.)
### Task ADV3: Batch & Lot Number Tracking System

**What the adviser wants:**
Every production run must generate a BATCH NUMBER and LOT NUMBER for traceability. This is a core IATF 16949 requirement — you must be able to trace a defective part back to its specific production batch, materials used, and machine operator.

**Batch vs Lot:**
- **Batch Number** = one production run (one Work Order run on one machine). Format: `BATCH-YYYYMMDD-NNNN`
- **Lot Number** = group of finished goods shipped together (one Delivery Note). Format: `LOT-YYYYMMDD-NNNN`

**Database:**
```sql
-- Migration: 0122_add_batch_lot_to_production.php

-- Add to work_orders table
ALTER TABLE work_orders ADD COLUMN batch_number VARCHAR(30) UNIQUE;

-- New table: production_batches
CREATE TABLE production_batches (
    id BIGSERIAL PRIMARY KEY,
    batch_number VARCHAR(30) UNIQUE NOT NULL,  -- BATCH-20260407-0001
    work_order_id BIGINT NOT NULL REFERENCES work_orders(id),
    product_id BIGINT NOT NULL REFERENCES products(id),
    machine_id BIGINT NOT NULL REFERENCES machines(id),
    mold_id BIGINT NOT NULL REFERENCES molds(id),
    quantity_produced INT NOT NULL,
    quantity_good INT NOT NULL,
    quantity_rejected INT NOT NULL DEFAULT 0,
    production_date DATE NOT NULL,
    shift VARCHAR(20) NOT NULL,
    operator_ids JSON,                          -- array of employee IDs
    material_lot_references JSON,               -- {item_id: lot_no, quantity_used}
    qc_inspection_id BIGINT REFERENCES inspections(id),
    qc_result VARCHAR(10),                      -- pass/fail/pending
    status VARCHAR(20) DEFAULT 'in_progress',  -- in_progress/passed/rejected/partial
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- New table: shipment_lots
CREATE TABLE shipment_lots (
    id BIGSERIAL PRIMARY KEY,
    lot_number VARCHAR(30) UNIQUE NOT NULL,    -- LOT-20260415-0001
    delivery_id BIGINT NOT NULL REFERENCES deliveries(id),
    customer_id BIGINT NOT NULL REFERENCES customers(id),
    batch_ids JSON NOT NULL,                    -- array of batch IDs included
    product_id BIGINT NOT NULL REFERENCES products(id),
    quantity INT NOT NULL,
    lot_date DATE NOT NULL,
    coc_path VARCHAR(500),
    created_at TIMESTAMP DEFAULT NOW()
);

-- Add to material GRN received items for incoming material lot tracking
ALTER TABLE goods_receipt_notes ADD COLUMN material_lot_number VARCHAR(50);
ALTER TABLE goods_receipt_notes ADD COLUMN supplier_lot_reference VARCHAR(100);
```

**Auto-generate batch number:** When Work Order starts (status → in_progress), auto-generate batch_number via DocumentSequenceService. Prefix: `BATCH`.

**Auto-generate lot number:** When Delivery is created from passed batches, auto-generate lot_number. Prefix: `LOT`.

**Traceability chain:**
```
Supplier Lot (incoming material) 
    → GRN material_lot_number 
    → Material Issue (which lot was issued to WO) 
    → Batch Number (production_batches) 
    → QC Inspection (linked to batch) 
    → Lot Number (shipment_lots, on delivery) 
    → Customer Shipment
    → CoC (includes batch + lot numbers)
```

**Frontend:**

Work Order detail page — new "Batch" section:
```
PRODUCTION BATCH
────────────────────────────────────────────
Batch No.:   BATCH-20260407-0001
Status:      ● QC Passed
Produced:    10,000 pcs good / 45 rejected
Machine:     IM-002 (150T)
Mold:        MOLD-WB001
Shift:       Day Shift · Apr 07, 2026
Operator(s): Manuel Cruz, Pedro Reyes
Materials:   Resin A · Lot: GRN-20260402 · 150kg
             Colorant Black · Lot: GRN-20260401 · 2kg
QC Result:   ✅ Passed · QC-202604-0018
```

Delivery detail page — lot section:
```
SHIPMENT LOT
────────────────────────────────────────────
Lot No.:    LOT-20260415-0001
Batches:    BATCH-20260407-0001 (10,000 pcs)
            BATCH-20260408-0002 (5,000 pcs)
Total:      15,000 pcs
CoC:        [View CoC] [Download]
```

**Traceability search:** `pages/quality/traceability.tsx`
- Search by batch number OR lot number OR material lot → shows full trace
- "Batch BATCH-20260407-0001 used materials from GRN-20260402 (Supplier Lot SL-TW-0234, Taiwan Plastics)"
- Critical for customer complaints and NCR investigation

**CoC update:** Certificate of Conformance now includes:
- Batch Number(s)
- Lot Number
- Material lot references (supplier lot numbers for traceability)

---

## ITEM 4 — RBAC (DYNAMIC) — OK
### Task ADV4: RBAC Confirmation + Enhancement

**Adviser confirmed Dynamic RBAC is acceptable.** This task ensures it is fully working and visually demonstrable.

**What to verify/enhance:**

1. **Role creation demo ready:** Admin can create a new role "Line Supervisor" from scratch, assign exactly these permissions: production.wo.view, production.output.record, production.machines.view, attendance.dtr.view — and a user with that role can ONLY do those things.

2. **Permission inheritance display:** On the permission matrix page, show a "Role comparison" view — side-by-side two roles showing which permissions differ.

3. **Role badge on every user:** In any table that shows users (approval chains, activity logs, etc.), show their role as a chip next to their name.

4. **Last modified audit:** Each role shows "Last modified by [admin] on [date]" — critical for compliance audit.

5. **Cannot delete role with active users:** If a role has users assigned, the Delete button shows tooltip "Cannot delete — 5 users assigned" instead of being disabled silently.

6. **System roles protected:** System Admin role cannot be edited or deleted (lock icon + tooltip).

---

## ITEM 5 — PROCUREMENT: MATERIAL REQUIREMENT & BILLING PROCESS
### Task ADV5: Procurement Module Rename + Billing Process Formalization

**What the adviser wants:**
The Procurement section should clearly show two sub-processes:
1. **Material Requirement** — the need identification and purchasing side (PR → PO)
2. **Billing Process** — the payment side (GRN → Bill → Payment)

These should be visually connected in the UI to show they are part of the same chain.

**Rename in sidebar:**
```
PROCUREMENT
  ├─ Material Requirements
  │   ├─ Purchase Requests        (material need identification)
  │   └─ Purchase Orders          (approved procurement)
  ├─ Receiving
  │   └─ Goods Receipt (GRN)      (warehouse receiving)
  └─ Billing
      ├─ Supplier Bills            (invoice from supplier)
      └─ Bill Payments             (payment to supplier)
```

**Procurement Chain page** (`pages/procurement/chain.tsx`):
A visual overview page showing the entire procurement chain with counts:

```
PROCUREMENT CHAIN OVERVIEW
────────────────────────────────────────────────────────────────────

Material Requirement          Receiving              Billing
──────────────────            ─────────              ──────────────
PRs Pending:    8    →   POs Sent:    5    →   Bills Unpaid:  12
PRs Approved:  12        POs Received: 3        Bills Overdue:  2
Draft POs:      3        Pending QC:   2        This Month: ₱ 485K

[View PRs] [View POs]    [View GRNs]            [View Bills] [Pay]
```

**PO detail page — billing section:**
After GRN is received and linked, show a "Billing" tab on the PO detail:
```
BILLING STATUS
────────────────────────────────────────────────────
GRN Received:    GRN-202604-0011 · Apr 08 · 500kg
3-Way Match:     ✅ Passed (within 5% tolerance)
Bill Status:     ⚠ Bill not yet received from supplier
                 [Create Bill]

Or if bill exists:
Bill:            BILL-202604-0018 · ₱ 62,500.00
Payment:         ○ Unpaid · Due Apr 22 (in 7 days)
                 [Record Payment]
```

---

## ITEM 6 — PROCESS AUTOMATION ESPECIALLY IN PURCHASE REQUEST
### Task ADV6: Purchase Request Automation Enhancement

**What the adviser wants:**
The PR process should be as automated as possible — less manual form filling, more system-driven request generation.

**Current PR creation triggers:**
- ✅ MRP shortage (already auto-creates draft PR)
- ✅ Low stock reorder point (already auto-creates draft PR)

**New automation to add:**

**1. Smart PR from MRP with pre-filled supplier:**
When MRP auto-creates a PR, automatically look up `approved_suppliers` for each item and pre-fill the preferred supplier on the PR line item. PR requester just reviews and submits — no manual supplier lookup.

**2. PR template system:**
For recurring purchases (office supplies, utilities, maintenance consumables), create reusable PR templates.

```
PR Templates
────────────────────────────────────────────────────────
Monthly Office Supplies        [Use Template] [Edit]
  → 5 items, Office Admin dept, ~₱ 8,500/month

Mold Maintenance Kit           [Use Template] [Edit]
  → 8 spare parts, Maintenance dept, ~₱ 15,000/use

Quarterly Resin Restock        [Use Template] [Edit]
  → 4 resins, Production dept, quarterly schedule
```

"Use Template" → pre-fills entire PR form. User just adjusts quantities and submits.

**3. One-click approval for small PRs:**
If PR total estimated value < ₱5,000 AND requestor is a dept head or above → auto-approve (skip manual approval chain). Policy configurable in admin settings.

**4. PR status visibility in sidebar badge:**
Show count of PRs awaiting YOUR approval in the sidebar badge next to "Purchase Requests".

**5. PR conversion to PO — one click:**
On the PR list, after final approval, show "Convert to PO" button directly in the table row. No need to navigate away — opens a modal with supplier selection and auto-filled line items.

**6. Bulk PR approval:**
Approver can select multiple PRs and approve all at once (with a single remarks field applied to all).

**7. PR urgency auto-escalation:**
PRs flagged as URGENT (from MRP when order_date ≤ today) automatically skip the Dept Head step and go directly to Manager → Officer → VP (compressed chain for urgent items).

**Database:**
```sql
-- Migration: 0123_add_pr_automation_fields.php
ALTER TABLE purchase_requests ADD COLUMN template_id BIGINT NULL;
ALTER TABLE purchase_requests ADD COLUMN is_auto_generated BOOLEAN DEFAULT FALSE;
ALTER TABLE purchase_requests ADD COLUMN auto_generated_reason VARCHAR(100);
ALTER TABLE purchase_requests ADD COLUMN is_urgent BOOLEAN DEFAULT FALSE;
ALTER TABLE purchase_requests ADD COLUMN urgency_reason VARCHAR(200);

CREATE TABLE purchase_request_templates (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    department_id BIGINT REFERENCES departments(id),
    items JSON NOT NULL,  -- [{item_id, description, quantity, unit, estimated_price}]
    notes TEXT,
    created_by BIGINT REFERENCES users(id),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
```

---

## ITEM 7 — PROOF OF DELIVERY
### Task ADV7: Delivery Proof System (Photo + Signature)

**What the adviser wants:**
Physical proof that goods were delivered and received by the customer. The system must capture and store the signed delivery receipt.

**What to build:**

**Database:**
```sql
-- Migration: 0124_enhance_delivery_proofs.php
ALTER TABLE deliveries ADD COLUMN proof_type VARCHAR(30);  -- 'photo', 'digital_signature', 'both'
ALTER TABLE deliveries ADD COLUMN receiver_name VARCHAR(200);
ALTER TABLE deliveries ADD COLUMN receiver_position VARCHAR(100);
ALTER TABLE deliveries ADD COLUMN received_at TIMESTAMP;
ALTER TABLE deliveries ADD COLUMN delivery_remarks TEXT;

CREATE TABLE delivery_proofs (
    id BIGSERIAL PRIMARY KEY,
    delivery_id BIGINT NOT NULL REFERENCES deliveries(id),
    proof_type VARCHAR(30) NOT NULL,  -- 'signed_dr', 'photo', 'customer_po_confirmation'
    file_name VARCHAR(200) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT,
    uploaded_by BIGINT NOT NULL REFERENCES users(id),
    upload_timestamp TIMESTAMP DEFAULT NOW(),
    notes TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);
```

**Backend:**
- `POST /api/v1/deliveries/{id}/proofs` — upload proof (multiple files allowed: photo of signed DR, customer confirmation email, etc.)
- `GET /api/v1/deliveries/{id}/proofs` — list all proofs
- `PATCH /api/v1/deliveries/{id}/confirm-with-proof` — mark delivered + attach proof simultaneously

**Delivery workflow with proof requirement:**

```
Scheduled → Loading → In Transit → DELIVERED (driver uploads) → Customer Confirmed
                                         ↑
                             REQUIRES at minimum 1 proof file
                             System blocks confirmation without proof
```

**Mobile-optimized upload for drivers:**
Driver uses phone → opens delivery in self-service portal → status "In Transit" → "Mark as Delivered" button:
```
Mark Delivery as Delivered
──────────────────────────────────────────
Customer Received By: [_________________]
Position:             [_________________]
Date & Time:          [Auto-filled: now]

Upload Signed Delivery Receipt:
┌─────────────────────────────────────────┐
│  📷 Take Photo  |  📁 Upload File       │
│                                         │
│  [Large tap target — 44px minimum]      │
└─────────────────────────────────────────┘

Delivery Remarks: [optional]

[Confirm Delivery]  ← disabled until photo uploaded
```

**Frontend — Delivery detail page:**
```
PROOF OF DELIVERY
──────────────────────────────────────────────────────────
Received by: Maria Santos (Purchasing, Toyota PH)
Date & Time: Apr 15, 2026 · 10:45 AM

┌──────────────────────────────────────────────────────┐
│  [thumbnail] signed_dr_april15.jpg                   │
│  Signed delivery receipt                             │
│  Uploaded by: Jose Driver · 10:47 AM                │
│                            [View Full] [Download]    │
└──────────────────────────────────────────────────────┘

[+ Upload Additional Proof]
```

**Invoice link:** Invoice PDF now includes "Delivery confirmed with proof of delivery on [date]" and the proof reference number.

**Dispute protection:** If customer disputes delivery, click "View Proof" → opens signed delivery receipt. This is legally defensible.

---

## ITEM 8 — WAREHOUSE MANAGEMENT SYSTEM
### Task ADV8: Full Warehouse Management Enhancement

**What the adviser wants:**
A proper Warehouse Management System (WMS) — not just stock levels, but full location management, bin tracking, movement history, picking, and physical count.

**What to build:**

**1. Enhanced Warehouse Structure:**
```
Warehouse
  └─ Zone A (Raw Materials)
      ├─ Row 1
      │   ├─ Rack A1-R1 (shelving unit)
      │   │   ├─ Bin A1-R1-B1  [Resin A · 250kg · Lot GRN-0012]
      │   │   ├─ Bin A1-R1-B2  [Resin B · 180kg · Lot GRN-0011]
      │   │   └─ Bin A1-R1-B3  [EMPTY]
      │   └─ Rack A1-R2
      └─ Row 2
  └─ Zone B (Staging/Production)
  └─ Zone C (Finished Goods)
  └─ Zone D (Spare Parts)
```

`pages/warehouse/map.tsx` — visual warehouse map:
- Grid layout of zones, rows, racks, bins
- Color coded: green (has stock), gray (empty), yellow (low), red (critical)
- Click any bin → popup showing: item, quantity, lot number, last movement date
- Print bin labels (QR code + bin code + current item)

**2. Bin-Level Stock Tracking:**
Current `stock_levels` only tracks item + location. Enhance to track per bin:
```sql
-- Migration: 0125_enhance_warehouse_bins.php
ALTER TABLE warehouse_locations ADD COLUMN capacity_kg DECIMAL(10,2);
ALTER TABLE warehouse_locations ADD COLUMN current_item_id BIGINT REFERENCES items(id);
ALTER TABLE warehouse_locations ADD COLUMN current_quantity DECIMAL(15,3) DEFAULT 0;
ALTER TABLE warehouse_locations ADD COLUMN current_lot_number VARCHAR(50);
ALTER TABLE warehouse_locations ADD COLUMN is_blocked BOOLEAN DEFAULT FALSE;
ALTER TABLE warehouse_locations ADD COLUMN blocked_reason VARCHAR(200);
```

**3. Picking List with Bin Directions:**
When Material Issue Slip is created for a Work Order, system generates picking list:
```
PICKING LIST — WO-202604-0007
──────────────────────────────────────────────────────
#  Item              Qty      Pick From    Lot
── ──────────────── ──────── ──────────── ──────────────
1  Resin A (ABS)    75.000kg Zone A       GRN-20260402
                              Bin A1-R1-B1 Lot SL-TW-0234
2  Black Colorant    2.000kg Zone A       GRN-20260401
                              Bin A1-R2-B3 Lot SL-CN-0891
3  Metal Insert     1000 pcs Zone A       GRN-20260403
                              Bin A2-R1-B1 Lot SL-JP-0112
──────────────────────────────────────────────────────
[Print Picking List]  [Confirm All Picked]
```

**4. Physical Stock Count:**
`pages/warehouse/stock-count.tsx`

Count session workflow:
1. Admin creates count session (full or cycle count by zone/category)
2. System freezes that zone for movements during count
3. Warehouse staff counts each bin and enters actual quantity
4. System compares: system qty vs counted qty → shows variance
5. If variance > 2%: requires supervisor sign-off before adjustment
6. Approved variances → auto-creates stock adjustment movement
7. Count session closed → movements unfrozen

**5. Transfer Orders:**
Move items between bins/zones with full documentation:
```sql
CREATE TABLE transfer_orders (
    id BIGSERIAL PRIMARY KEY,
    transfer_number VARCHAR(20) UNIQUE NOT NULL,
    from_location_id BIGINT REFERENCES warehouse_locations(id),
    to_location_id BIGINT REFERENCES warehouse_locations(id),
    item_id BIGINT REFERENCES items(id),
    quantity DECIMAL(15,3) NOT NULL,
    reason VARCHAR(200),
    status VARCHAR(20) DEFAULT 'pending',
    transferred_by BIGINT REFERENCES users(id),
    transferred_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);
```

**6. Receiving Dock Management:**
When a PO delivery arrives, Warehouse Staff uses a "Receiving" page:
1. Select expected PO from list
2. Enter actual quantities received per line item
3. Note any damages or discrepancies
4. Print receiving report
5. Trigger QC inspection automatically

**7. FIFO enforcement (optional per item):**
For items marked `use_fifo = true`, system always suggests picking from the oldest lot first. Critical for resins that degrade over time.

---

## ITEM 9 — BUDGETING SYSTEM (ALLOCATION)
### Task ADV9: Full Budgeting Module

**What the adviser wants:**
A complete budgeting system with department allocations, budget vs actual tracking, approval workflow, and variance alerts.

**Database:**
```sql
-- Migration: 0126_create_budgets_tables.php

CREATE TABLE fiscal_years (
    id BIGSERIAL PRIMARY KEY,
    year INT UNIQUE NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status VARCHAR(20) DEFAULT 'active',  -- draft/active/closed
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE budgets (
    id BIGSERIAL PRIMARY KEY,
    fiscal_year_id BIGINT NOT NULL REFERENCES fiscal_years(id),
    department_id BIGINT REFERENCES departments(id),     -- NULL = company-wide
    budget_type VARCHAR(30) NOT NULL,  -- 'department', 'project', 'capex', 'opex'
    name VARCHAR(200) NOT NULL,
    total_allocated DECIMAL(15,2) DEFAULT 0,
    total_spent DECIMAL(15,2) DEFAULT 0,
    total_committed DECIMAL(15,2) DEFAULT 0,  -- approved POs not yet billed
    status VARCHAR(20) DEFAULT 'draft',       -- draft/submitted/approved/active/closed
    submitted_by BIGINT REFERENCES users(id),
    submitted_at TIMESTAMP,
    approved_by BIGINT REFERENCES users(id),
    approved_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE budget_line_items (
    id BIGSERIAL PRIMARY KEY,
    budget_id BIGINT NOT NULL REFERENCES budgets(id),
    account_id BIGINT NOT NULL REFERENCES accounts(id),
    jan DECIMAL(15,2) DEFAULT 0,
    feb DECIMAL(15,2) DEFAULT 0,
    mar DECIMAL(15,2) DEFAULT 0,
    apr DECIMAL(15,2) DEFAULT 0,
    may DECIMAL(15,2) DEFAULT 0,
    jun DECIMAL(15,2) DEFAULT 0,
    jul DECIMAL(15,2) DEFAULT 0,
    aug DECIMAL(15,2) DEFAULT 0,
    sep DECIMAL(15,2) DEFAULT 0,
    oct DECIMAL(15,2) DEFAULT 0,
    nov DECIMAL(15,2) DEFAULT 0,
    dec DECIMAL(15,2) DEFAULT 0,
    annual_total DECIMAL(15,2) GENERATED ALWAYS AS (jan+feb+mar+apr+may+jun+jul+aug+sep+oct+nov+dec) STORED,
    actual_total DECIMAL(15,2) DEFAULT 0,
    variance DECIMAL(15,2) GENERATED ALWAYS AS (annual_total - actual_total) STORED
);

CREATE TABLE budget_transfers (
    id BIGSERIAL PRIMARY KEY,
    from_budget_line_id BIGINT NOT NULL REFERENCES budget_line_items(id),
    to_budget_line_id BIGINT NOT NULL REFERENCES budget_line_items(id),
    amount DECIMAL(15,2) NOT NULL,
    reason TEXT NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    requested_by BIGINT REFERENCES users(id),
    approved_by BIGINT REFERENCES users(id),
    approved_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE budget_revisions (
    id BIGSERIAL PRIMARY KEY,
    budget_id BIGINT NOT NULL REFERENCES budgets(id),
    revision_number INT NOT NULL,
    changes JSON NOT NULL,  -- [{line_item_id, old_amount, new_amount, reason}]
    reason TEXT NOT NULL,
    submitted_by BIGINT REFERENCES users(id),
    approved_by BIGINT REFERENCES users(id),
    status VARCHAR(20) DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT NOW()
);
```

**Budget Workflow:**
```
Dept Head prepares budget (line-by-line per GL account)
    ↓
Dept Head submits → Finance Officer reviews + consolidates
    ↓
Finance presents consolidated budget to VP
    ↓
VP approves → budgets become ACTIVE
    ↓
System monitors all financial transactions against budget
    ↓
80% consumed → warn Dept Head
95% consumed → warn Dept Head + Finance
100% consumed → Finance acknowledgment required
120% consumed → VP approval required for any new PO/PR
```

**Pages:**

`pages/budgeting/index.tsx` — Budget Overview:
```
FY 2026 Budget Overview              [Export] [Create Budget]
─────────────────────────────────────────────────────────────
COMPANY TOTAL
  Allocated:  ₱ 48,500,000    Spent: ₱ 22,340,000 (46%)
  Committed:  ₱ 8,200,000     Available: ₱ 17,960,000

BY DEPARTMENT                Allocated    Spent    %      Status
Production                   ₱ 18.5M    ₱ 9.2M   50%    ● On track
Finance & Admin              ₱ 5.2M     ₱ 3.1M   60%    ● On track
Purchasing                   ₱ 12.0M    ₱ 6.8M   57%    ● On track
HR                           ₱ 3.8M     ₱ 2.9M   76%    ⚠ Warning
Maintenance                  ₱ 4.5M     ₱ 4.4M   98%    🔴 Critical
```

`pages/budgeting/departments/[id].tsx` — Department Budget Detail:
- Monthly breakdown table (Jan–Dec) per GL account
- Actual vs budget per month with variance column
- Bar chart: budget vs actual by month
- Transactions against this budget (clickable list of JE, PO, Bills)
- "Request Budget Transfer" button

`pages/budgeting/budget-vs-actual.tsx` — consolidated P&L view comparing budget to actual.

**Budget enforcement in PRs and POs:**
When creating a PR or PO, system checks remaining budget for the department:
- Shows budget remaining inline on the form
- If over budget: warning banner (does not block submission, requires Finance acknowledgment)

---

## ITEM 10 — B2B PORTALS
### Task ADV10: Supplier Portal + Customer Portal

**What the adviser wants:**
External stakeholders (suppliers and customers) should have their own portal to interact with the ERP — reducing manual email communication.

### Task ADV10a: Supplier Portal

**Access:** Suppliers get login credentials (separate from internal users). Separate subdomain or path: `/portal/supplier`.

**What suppliers can do:**
1. **View their Purchase Orders** — see all POs from Ogami, download PO PDF
2. **Acknowledge PO** — supplier clicks "Acknowledge Receipt" → Ogami notified
3. **Update shipment status** — supplier enters: shipped date, carrier, tracking number, estimated arrival
4. **Upload shipping documents** — Commercial invoice, packing list, B/L (feeds directly into SCM module)
5. **Submit invoice** — supplier uploads their invoice → creates draft Bill in Ogami AP for Finance review
6. **View payment status** — see which invoices are paid, which are pending

**Database:**
```sql
CREATE TABLE supplier_portal_users (
    id BIGSERIAL PRIMARY KEY,
    vendor_id BIGINT NOT NULL REFERENCES vendors(id),
    name VARCHAR(200) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    last_login_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);
```

**Security note:** Supplier portal users are COMPLETELY SEPARATE from internal users. They use `auth:supplier_sanctum` guard, can ONLY see their own vendor's records, cannot access any internal data.

### Task ADV10b: Customer Portal

**Access:** Customer (Toyota, Nissan, etc.) gets login. Path: `/portal/customer`.

**What customers can do:**
1. **Submit delivery schedules** — instead of emailing Excel, customer enters their monthly delivery requirements directly into the system → auto-creates Sales Orders
2. **View order status** — see real-time status of all their orders with ChainHeader visualization
3. **Download invoices** — access all invoices, download PDF
4. **View delivery status** — see tracking, delivery confirmation
5. **Download CoC** — get Certificate of Conformance for any delivered batch
6. **Submit complaints** — customer enters complaint directly → creates Complaint + 8D record in CRM
7. **View payment history** — see collections, outstanding balance, statement of account

**Database:**
```sql
CREATE TABLE customer_portal_users (
    id BIGSERIAL PRIMARY KEY,
    customer_id BIGINT NOT NULL REFERENCES customers(id),
    name VARCHAR(200) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    last_login_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);
```

**Portal-specific pages:**
- `/portal/supplier/login` and `/portal/customer/login` — branded login pages
- Completely separate layouts from internal system
- Mobile-responsive (customers and suppliers use phones too)
- Ogami branding but simpler interface than internal system

---

## ITEM 11 — FORECASTING
### Task ADV11: Demand & Sales Forecasting

**What the adviser wants:**
The system should be able to forecast future demand based on historical data, helping with production planning and procurement.

**What to build:**

**Sales Forecasting:**

`pages/forecasting/demand.tsx`

Based on historical Sales Orders per customer per product, project next 3/6/12 months:

```
DEMAND FORECAST — Wiper Bushing WB-001
──────────────────────────────────────────────────────────────
Method: Moving Average (3-month)

Historical:              Forecast:
Jan: 25,000 pcs          May: ~28,000 pcs  (↑ 8%)
Feb: 28,000 pcs          Jun: ~27,000 pcs
Mar: 26,000 pcs          Jul: ~29,000 pcs
Apr: 30,000 pcs

Customer breakdown:
Toyota:   55%  ~15,400 pcs/month
Nissan:   30%  ~8,400 pcs/month
Honda:    15%  ~4,200 pcs/month
```

**Forecasting methods (configurable per product):**
1. **Simple Moving Average** — average of last N months (default N=3)
2. **Weighted Moving Average** — more recent months weighted higher
3. **Manual override** — PPC Head enters their own forecast (overrides calculation)

**Database:**
```sql
CREATE TABLE demand_forecasts (
    id BIGSERIAL PRIMARY KEY,
    product_id BIGINT NOT NULL REFERENCES products(id),
    customer_id BIGINT REFERENCES customers(id),  -- NULL = total demand
    forecast_month INT NOT NULL,
    forecast_year INT NOT NULL,
    method VARCHAR(30) NOT NULL,  -- 'moving_avg', 'weighted_avg', 'manual'
    forecasted_quantity DECIMAL(10,2) NOT NULL,
    confidence_level DECIMAL(5,2),  -- percentage
    actual_quantity DECIMAL(10,2),  -- filled in when month passes
    variance DECIMAL(10,2),         -- actual - forecasted
    created_by BIGINT REFERENCES users(id),
    created_at TIMESTAMP DEFAULT NOW(),
    UNIQUE (product_id, customer_id, forecast_month, forecast_year)
);
```

**Forecast → MRP integration:**
MRP engine can use forecast demand (in addition to confirmed SOs) for planning. Toggle: "Include forecast in MRP" per item.

**Inventory forecasting:**
Based on demand forecast + current stock + lead times → project stock-out dates:
```
Resin A (ABS)
  Current stock:    720 kg
  Forecasted usage: 300 kg/month (based on demand forecast)
  Lead time:        14 days (Japan)
  
  ⚠ Projected stock-out: June 12 (in 36 days)
  Recommended order date: May 29 (14 days before stock-out)
  Recommended quantity: 900 kg (3-month supply)
  [Create PR]
```

---

## ITEM 12 — RETURN POLICY
### Task ADV12: Return Management System (RMA)

**What the adviser wants:**
A formal return policy — covering both returns FROM customers (customer complaints + defective parts returned) and returns TO suppliers (failed incoming QC or wrong items received).

### ADV12a: Customer Return (Return Merchandise Authorization — RMA)

**Trigger:** Customer reports defective parts (already have complaints) OR customer returns delivery.

**Workflow:**
```
Customer submits complaint / calls for return
    ↓
CRM Officer creates RMA Request
    ↓
QC Manager approves (verifies it's a valid return)
    ↓
Delivery Return scheduled (driver picks up from customer)
    ↓
Parts received at QC zone (quarantine)
    ↓
QC inspects returned parts → categorizes: defective / damaged / wrong item / acceptable
    ↓
Disposition:
  All defective → Scrap + Credit Note (AR credit memo)
  Wrong item → Scrap old + replacement shipment + no charge
  Acceptable (customer error) → Return to FG zone + restock
    ↓
Credit Note issued (reduces AR balance)
    ↓
8D Report updated / closed
```

**Database:**
```sql
CREATE TABLE return_requests (
    id BIGSERIAL PRIMARY KEY,
    rma_number VARCHAR(20) UNIQUE NOT NULL,  -- RMA-YYYYMM-NNNN
    return_type VARCHAR(20) NOT NULL,        -- 'customer_return', 'supplier_return'
    reference_id BIGINT NOT NULL,            -- customer_complaint_id OR goods_receipt_note_id
    reference_type VARCHAR(50) NOT NULL,
    customer_id BIGINT REFERENCES customers(id),
    vendor_id BIGINT REFERENCES vendors(id),
    product_id BIGINT NOT NULL REFERENCES products(id),
    return_quantity INT NOT NULL,
    return_reason TEXT NOT NULL,
    status VARCHAR(30) DEFAULT 'pending',   -- pending/approved/in_transit/received/inspected/resolved
    disposition VARCHAR(30),                -- scrap/replace/restock/repair
    credit_note_id BIGINT REFERENCES invoices(id),  -- credit memo
    replacement_so_id BIGINT REFERENCES sales_orders(id),
    qc_inspection_id BIGINT REFERENCES inspections(id),
    resolved_at TIMESTAMP,
    created_by BIGINT REFERENCES users(id),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
```

### ADV12b: Supplier Return

**Trigger:** Incoming QC fails OR wrong items received.

**Workflow:**
```
GRN received → QC Inspection → FAIL
    ↓
QC creates NCR (already exists) + triggers return
    ↓
Purchasing contacts supplier
    ↓
Supplier Return Authorization created
    ↓
Items moved to quarantine zone (Zone C-03)
    ↓
Return shipment scheduled
    ↓
Items picked up / shipped back to supplier
    ↓
Replacement PO created OR credit from supplier
    ↓
GRN adjusted (reduce received quantity)
    ↓
AP Bill adjusted (reduce bill amount by returned value)
```

**Pages:**

`pages/returns/index.tsx` — Return Management overview:
```
RETURNS MANAGEMENT
─────────────────────────────────────────────────────
                    Customer Returns  │  Supplier Returns
Pending:                    2         │       3
In Transit:                 1         │       1
Resolved this month:        5         │       8

[View Customer Returns]    [View Supplier Returns]
```

`pages/returns/[id].tsx` — Return detail with ChainHeader:
```
RMA-202604-0003
Customer: Toyota Motor Philippines
Product: Relay Cover RC-001 · 150 pcs
Reason: Dimensional non-conformance (OD out of spec)
                                   [View Complaint] [View NCR]

RMA Raised → QC Approved → Pickup Scheduled → Received → Inspected → Credit Issued
    ✅            ✅              ✅              ✅          ○              ○
  Apr 08        Apr 09          Apr 10          Apr 12    Pending        Pending
```

---

## EXECUTION ORDER (mandatory adviser items)

Execute in this order — earlier tasks unlock later ones:

```
WEEK 1
  ADV2  → Sidebar restructure (visual clarity — easy, high impact)
  ADV3  → Batch/Lot number system (foundation for traceability)
  ADV4  → RBAC verification + enhancements

WEEK 2
  ADV1  → Proof of disbursement (payroll)
  ADV7  → Proof of delivery
  ADV5  → Procurement rename + billing process formalization
  ADV6  → PR automation enhancements

WEEK 3
  ADV8  → Warehouse Management System
  ADV12 → Return Policy (customer + supplier)

WEEK 4
  ADV9  → Budgeting System

WEEK 5
  ADV11 → Forecasting

WEEK 6
  ADV10 → B2B Portals (supplier + customer) ← biggest task, needs its own week
```

---

## TRACEABILITY MATRIX

After completing all ADV tasks, the system will support full IATF 16949 traceability:

```
Customer Complaint
    → Return Request (ADV12) 
    → Shipment Lot (ADV3)
    → Production Batch (ADV3)  
    → QC Outgoing Inspection
    → Work Order
    → Material Issue Slip
    → GRN + Material Lot
    → Supplier + PO
    
"Part returned by Toyota on May 10 was from Lot LOT-20260415-0001,
Batch BATCH-20260407-0001, produced on Apr 07 on Machine IM-002
using Resin A from GRN-20260402 (Supplier: Taiwan Plastics,
Supplier Lot: SL-TW-0234, received Apr 02, QC passed Apr 02)."
```

This single trace chain is the strongest IATF 16949 demonstration you can show your panel.
