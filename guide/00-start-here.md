# Start Here — OGAMI ERP in 5 Minutes

## What is OGAMI ERP?

OGAMI ERP is a one-company management system for Philippine Ogami
Corporation, a plastic-parts factory in Dasmariñas, Cavite with 200+
employees. It connects three everyday flows in one place: taking customer
orders and delivering them (Order to Cash), buying materials and paying
suppliers (Procure to Pay), and managing people from hiring to final pay
(Hire to Retire). Quality checks are built into every step, because the
company is IATF 16949 certified and supplies Toyota, Nissan, Honda,
Suzuki, and Yamaha.

## How to open it

1. Open your browser (Chrome recommended).
2. Go to **http://localhost**.
3. You land on the sign-in page (`/sign-in`).
4. Type any demo email from Guide 01 (for example `hr@ogami.test`).
5. Type the password: `password` (same for ALL demo accounts).
6. Click the **Sign in** button.
7. You arrive at your role dashboard (`/dashboard`).
   It redirects automatically to the dashboard made for your role.

## If something goes wrong at sign-in

1. Wrong email or password? You see an error under the form. Try again.
2. Five wrong tries locks the account for 15 minutes (security rule).
3. Session expired? Sign in again.
4. Forgot-password link is on the sign-in page (`/forgot-password`).

## The 5-minute tour (do these in order)

Do this while signed in as `admin@ogami.test` (sees everything):

1. **Minute 1 — Dashboard.** Open `/dashboard`.
   Numbers, alerts, and shortcuts for your role live here.
2. **Minute 2 — Approval Queue.** Open `/approvals`.
   This is the board of everything waiting for a signature.
   See Guide 02 for details.
3. **Minute 3 — People.** Open `/hr/employees`.
   Click **Add employee** to see the hiring form (you do not need
   to save it). See Guide 13.
4. **Minute 4 — Time and leave.** Open `/hr/attendance` (Daily Time
   Records), then `/hr/leaves` (Leave requests). See Guides 14 and 15.
5. **Minute 5 — Search anything.** Click the **Search…** box at the top
   of the screen (or press Ctrl+K / Cmd+K). Type "employee", "order",
   "vendor", "item", or "NCR". See Guide 02.

## Where everything lives (the left sidebar)

The sidebar groups pages by department. Main groups:

- **Overview** — Dashboard, Action Center, KPI Scorecard,
  Business Chain Tracker, Approval Queue, Notifications.
- **Sales & CRM** — Sales Orders, Customers, Products,
  Price Agreements, Customer Complaints, Returns (RMA).
- **Production** — Production Work Orders, Production Schedule (Gantt),
  Production Routings, Factory Floor.
- **Production Planning (MRP)** — MRP Plans, Bill of Materials,
  Production Machines, Production Molds.
- **Procurement** — Procurement Chain, Purchase Orders,
  Purchase Requests, Supplier RFQs, Approved Suppliers.
- **Warehouse** — Inventory Items, Goods Receipts (GRN),
  Material Issues, Quarantine Holds (MRB), Stock Levels,
  Stock Adjustments, Warehouse Map, Transfers, Order Picking.
- **Supply Chain** — Outbound Deliveries, My Deliveries (driver),
  Inbound Shipments, Delivery Fleet.
- **Quality** — Inspection Specifications, Quality Inspections,
  Nonconformance Reports (NCRs), Lot Traceability.
- **Finance** — Finance Dashboard, Budgeting, Invoices, Bills,
  Vendors, Income Statement, Balance Sheet.
- **Human Resources** — Employees, Departments, Attendance & DTR,
  Overtime, Leave Management, Payroll Processing,
  Payroll Adjustments, Statutory Exports.
- **Maintenance** — Maintenance Work Orders, Maintenance Schedules.
- **Assets** — Fixed Assets.
- **Administration** — User Accounts, Roles & Permissions,
  Audit Logs, Settings, Active Sessions, Portal Access,
  Backup & Restore, Government Contribution Tables.

> You only see the groups your account is allowed to open.
> The admin account sees all of them.

## Demo records to click on (already in the database)

- Purchase request `PR-DEMO-BUDGET` — mid-approval, waiting on the VP.
- Purchase request `PR-DEMO-CONVERT` — for the procure-to-pay demo.
- RFQ `RFQ-202610-0001` — "DEMO Resin RFQ", open with two submitted
  quotes (₱950 / ₱1,020). Note: the number is system-generated; if you
  re-seed later, look for the RFQ whose title starts with `DEMO`.
- Budgets: `Production Operating Budget`, `Finance & Admin Budget`,
  `Maintenance Budget` (all active), `DEMO Draft Budget — submit me`
  (draft), `DEMO Submitted Budget — approve me` (submitted),
  and one pending transfer `DEMO transfer — approve or reject me.`
- Return `RMA-DEMO-SUP-READY`, bill `BILL-DEMO-SUP-001`.
- Complaint `CC-DEMO-001`, clearance `CLR-DEMO-001`,
  quarantine hold `MRB-DEMO-001`.

## Next guides

- Guide 01 — every demo account and login.
- Guide 02 — dashboards, approvals, search, notifications.
- Guide 13 — HR (employees, departments, positions, separation).
- Guide 14 — Attendance (shifts, DTR, biometric import, overtime).
- Guide 15 — Leave (types, request flow, approvals).
