# SEP-4 Demo Guide — OGAMI ERP

This is the recommended presentation guide for demonstrating OGAMI ERP's
business processes, cross-module connections, and the twelve adviser items in
[`DEFENSE-TRACEABILITY.md`](DEFENSE-TRACEABILITY.md).

The live demo should tell one business story: a customer order becomes a
planned and manufactured product, passes quality gates, is delivered and
invoiced, while the purchasing, warehouse, finance, HR, and portal modules
support the same operation.

## 1. Where to start

Start at the **Dashboard**, then open **Business Chain Tracker** at
`/chains`. Use one confirmed sales order as the anchor record.

Do not start with a Work Order. A Work Order is the production-execution step;
the business starts with customer demand in a Sales Order.

```text
Dashboard
  → Business Chain Tracker / Sales Order
  → MRP and BOM
  → Purchase Request → Purchase Order → GRN, when material is short
  → Production Work Order
  → In-process QC
  → Outgoing QC and Certificate of Conformance
  → Warehouse Picking
  → Delivery and Proof of Delivery
  → Accounts Receivable Invoice and Collection
```

Quality and traceability cross the entire flow:

```text
Supplier lot → GRN → Material Issue → Production Batch
→ QC Inspection → Shipment Lot → Delivery → RMA / Credit Note
```

The **Business Chain Tracker** is the best visual starting point because it
shows the Order-to-Cash stepper, linked MRP plan, Work Orders, inspections,
deliveries, invoices, and plant bottlenecks in one place.

## 2. Accounts and roles

The demonstration does not need a superadmin account. The seeded password for
the internal demo accounts is `password`.

Because the application enforces separation of duties, no single seeded
non-admin account can perform every action in all three chains. Use separate
browser profiles or switch accounts at each handoff.

| Account | Role | Best use during the demo |
|---|---|---|
| `ppc@ogami.test` | PPC Head | MRP plans, BOMs, forecasting, production scheduling, Work Order planning |
| `production@ogami.test` | Production Manager | Work Order confirmation, production output, machine/production view |
| `qc@ogami.test` | QC Inspector | Inspection results, NCRs, inspection specifications, traceability |
| `purchasing@ogami.test` | Purchasing Officer | Purchase Requests, Purchase Orders, procurement chain, supplier handoff |
| `warehouse@ogami.test` | Warehouse Staff | GRN, inventory, warehouse map, stock count, material issues, picking |
| `impex@ogami.test` | ImpEx Officer | Shipments, import documents, delivery/fleet view |
| `driver@ogami.test` | Driver | Driver delivery list, delivery status, delivery receipt/photo flow |
| `finance@ogami.test` | Finance Officer | Invoices, bills, budgets, collections, payroll approval/finalization |
| `hr@ogami.test` | HR Officer | Employees, attendance, leave, loans, payroll creation/computation |
| `depthead@ogami.test` | Department Head | Department leave, overtime, loan, and Purchase Request approvals |
| `maintenance@ogami.test` | Maintenance Technician | Maintenance Work Orders and condition-based maintenance |
| `portal@supp.test` | Supplier portal user | Supplier-only POs, deliveries, invoices, and statement |
| `portal@cust.test` | Customer portal user | Customer-only orders, deliveries, invoices, complaints, and statement |

The two portal accounts also use `password`, but they sign in through their
separate portal login pages, not the internal ERP login.

### Minimum practical account pack

For a short demo, prepare these accounts first:

1. `ppc@ogami.test` — planning and MRP
2. `production@ogami.test` — Work Order execution
3. `qc@ogami.test` — quality and traceability
4. `purchasing@ogami.test` — procurement
5. `warehouse@ogami.test` — warehouse and picking
6. `finance@ogami.test` — accounting, budget, and payroll checking
7. `hr@ogami.test` — employee and payroll preparation
8. `driver@ogami.test` — delivery proof
9. `portal@supp.test` and `portal@cust.test` — portal isolation

Add `impex@ogami.test` for shipment/customs screens, `depthead@ogami.test` for
approval screens, and `maintenance@ogami.test` for the maintenance handoff.

### Important non-admin access note

The current seed intentionally gives CRM Sales Order access to the system
administrator. `crm@ogami.test` is also seeded as `system_admin`; it is not a
scoped CRM account. Therefore, a normal seeded account may receive a 403 when
opening `/chains` or `/crm/sales-orders`.

If the full central view is required without using an administrator during the
presentation, prepare a dedicated read-only **Demo Presenter** role before the
event. It should have only the read permissions needed for the walkthrough,
including:

```text
crm.sales_orders.view
crm.products.view
accounting.customers.view
mrp.view
mrp.plans.view
mrp.boms.view
mrp.machines.view
mrp.molds.view
production.work_orders.view
production.schedule.view
quality.view
quality.inspections.view
quality.specs.view
inventory.view
purchasing.view
supply_chain.view
supply_chain.deliveries.view
accounting.invoices.view
accounting.bills.view
budgeting.view
forecasting.view
return_management.view
dashboard.view_bottlenecks
```

Use a dedicated account assigned to that role. This is a read-only presenter
role, not a superadmin replacement. Specialist accounts should still perform
write actions so the maker-checker boundaries remain visible.

If this role is not prepared, begin with `ppc@ogami.test` at
`/dashboard/ppc`, then use the specialist accounts and direct routes below.
The MRP, production, quality, warehouse, procurement, and finance evidence is
still demonstrable, but the central Sales Order/Chain Tracker view will not be
available to that account.

## 3. Before the demo

If the demo database is already populated, run the read-only readiness check:

```bash
docker compose exec api php artisan demo:verify
cd spa && npm run test:defense
```

`demo:verify` should report `PASSED — 0 critical failures`. The defense smoke
walk checks the real showcase routes, seeded content, portals, failed API
responses, and browser console errors.

For a clean local demo database, use the canonical sequence from
[`DEMO-SCRIPT.md`](DEMO-SCRIPT.md). Warning: `migrate:fresh` deletes and
recreates the selected database, so use it only when resetting a disposable
demo environment:

```bash
docker compose up -d
docker compose stop queue
docker compose exec api php artisan migrate:fresh --seed
docker compose start queue
docker compose exec api php artisan db:seed --class=DefenseHeroSeeder
docker compose exec api php artisan demo:verify
cd spa && npm run test:defense
```

The seeded rehearsal records include:

- `PR-DEMO-CONVERT` — approved Purchase Request conversion
- `PR-DEMO-BUDGET` — budget warning and pending approval rehearsal
- `RMA-DEMO-SUP-READY` — supplier-return rehearsal record
- a disbursed payroll period with a Disbursement Proof
- supplier and customer portal accounts

Batch numbers are date-dependent. Before presenting the traceability section,
copy the live batch number from the first hero Work Order instead of relying on
the example batch number in the documentation.

## 4. Recommended 15–18 minute walkthrough

### 4.1 Opening — Dashboard, 1 minute

Open `/dashboard` or the appropriate role dashboard. Explain:

- OGAMI is a manufacturing ERP for a plastic-injection operation.
- The system connects Order-to-Cash, Procure-to-Pay, and Hire-to-Retire.
- Quality is woven into the chains rather than being a disconnected module.
- Dashboards expose live KPIs, order stages, alerts, and bottlenecks.

Recommended account: **Demo Presenter** if prepared; otherwise
`production@ogami.test` or `ppc@ogami.test`.

### 4.2 Cross-module hub — Business Chain Tracker, 2 minutes

Open `/chains` and search by sales order number or customer. Show:

1. The **Order-to-Cash chain** stepper.
2. The linked MRP Plan.
3. Linked Work Orders.
4. Quality inspections.
5. Deliveries and invoices.
6. The **Stuck across the plant** bottleneck panel.

Say:

> “This is the control view. The ERP is not presenting isolated screens; each
> document is linked to the next business responsibility.”

### 4.3 Order-to-Cash, 4 minutes

Use the Chain Tracker links where possible. Open or explain the following in
order:

| Step | Screen | What to show |
|---|---|---|
| 1 | `/crm/sales-orders` | Confirmed customer order and its customer/product lines |
| 2 | `/mrp/plans` | BOM explosion, available stock, shortage, draft PR, and draft WOs |
| 3 | `/mrp/scheduler` | Machine/mold assignment and Gantt capacity view, if time permits |
| 4 | `/production/work-orders/:id` | Batch number, material-lot references, machine, output, and rejects |
| 5 | `/quality/inspections` | In-process QC and outgoing AQL inspection |
| 6 | `/inventory/picking` | Warehouse fulfillment and picking |
| 7 | `/supply-chain/deliveries/:id` | Delivery status, receiver, signed delivery receipt/photo |
| 8 | `/accounting/invoices/:id` | Invoice produced from the confirmed delivery; collection if time permits |

The important automatic handoffs are:

```text
SO confirmed → MRP evaluates BOM and stock
MRP shortage → consolidated Purchase Request
MRP plan → draft Work Order
WO started → in-process QC
WO completed → outgoing QC
Outgoing QC passed → draft delivery and Certificate of Conformance
Delivery confirmed with proof → draft invoice and delivered SO
Invoice finalized → Accounts Receivable journal entry
Collection recorded → invoice paid and Order-to-Cash complete
```

Use `ppc@ogami.test` for planning, `production@ogami.test` for production
output, `qc@ogami.test` for inspection, `warehouse@ogami.test` for picking,
`driver@ogami.test` or `impex@ogami.test` for delivery, and `finance@ogami.test`
for invoicing and collection.

The seeded hero data is already prepared for inspection. Prefer opening the
records rather than changing the hero chain during the presentation.

### 4.4 Procure-to-Pay, 3 minutes

Open `/purchasing/chain` using `purchasing@ogami.test`. Show the relationship:

```text
Material shortage → Purchase Request → Approval → Purchase Order
→ Supplier Shipment → GRN → Incoming QC → Inventory
→ Vendor Bill → Payment → General Ledger
```

Then:

1. Open `PR-DEMO-CONVERT` and show its approved conversion path.
2. Open `PR-DEMO-BUDGET` and show the budget warning and approval status.
3. Open a PO detail and select the Billing area.
4. Show the received GRN and **3-way match passed** state.
5. Explain that Finance records the bill payment.

Use `warehouse@ogami.test` for GRN and inventory screens, `qc@ogami.test` for
incoming inspection, and `finance@ogami.test` for bill/payment evidence.

The current build keeps the PR Template source files and API support, but the
PR Template screen is scope-cut and not registered in the live SPA route. Use
the Purchase Requests page and the seeded PR records as the live ADV6 proof.

### 4.5 Hire-to-Retire, 2 minutes

Show the shorter people and payroll chain:

```text
Employee → Shift / Attendance → Leave / Overtime / Loan
→ Payroll Computation → Approval → Finalization
→ Payslip / Bank File → Disbursement Proof
→ Separation → Clearance → Final Pay
```

Open:

- `/hr/employees` with `hr@ogami.test`
- `/payroll/periods` with `hr@ogami.test` or `finance@ogami.test`
- a disbursed period detail and its **Disbursement Proof** section

Explain the role separation:

- HR creates and computes payroll.
- Finance approves and finalizes payroll.
- The finalization triggers the bank file, payslip, employee notification, and
  payroll-to-Accounting handoff.
- The disbursement proof records the bank confirmation, amount, and reference.

Use `depthead@ogami.test` if you want to show department leave/OT approvals.

### 4.6 Flagship IATF traceability, 3 minutes

Open **Lot Traceability** at `/quality/traceability` with `qc@ogami.test`.
Search the live batch number copied from the hero Work Order.

Walk through the genealogy:

```text
Customer complaint / RMA
  → Shipment Lot
  → Production Batch
  → Outgoing QC inspection
  → Work Order
  → Material Issue
  → GRN and material lot
  → Supplier and PO
```

Suggested narration:

> “This returned part is not just a number on a shipment. We can trace the
> shipment lot to the production batch, the Work Order, the inspection result,
> and the exact supplier material lot received through the GRN.”

Then open `/return-management` and show `RMA-DEMO-SUP-READY`. If demonstrating
the financial result, open `/accounting/credit-notes` with `finance@ogami.test`.
Do not execute a return disposition on the only rehearsal record unless the
database has been reseeded afterward.

### 4.7 Supporting proof, 2–3 minutes

Use one sentence per screen:

| Screen | Demonstration point |
|---|---|
| `/budgeting` | FY2026 allocation, spent percentage, and near-critical department |
| `/forecasting/demand` | Product demand forecast and the “Include forecast in MRP” decision |
| `/forecasting/stock-out` | Projected stock-out date and Create PR action |
| `/inventory/warehouse-map` | Bin-level warehouse visibility |
| `/inventory/stock-count` | Zone freeze, variance, and supervisor sign-off |
| `/inventory/transfer-orders` | Controlled movement between warehouse locations |
| `/portal/supplier/login` | Supplier sees only its own POs, deliveries, invoices, and statement |
| `/portal/customer/login` | Customer sees only its own orders, deliveries, invoices, and complaints |
| `/admin/roles` | Dynamic role/permission matrix, only if an approved role-manager account was prepared |

The Warehouse Scanner and Segregation of Duties pages were intentionally
removed from the sidebar. For the demo, use Warehouse Map/Stock Count/Picking
for warehouse evidence, and demonstrate SoD through the payroll maker-checker
flow and approval history instead of opening `/admin/sod`.

## 5. Defense Traceability evidence map

| Adviser item | Screen(s) | Account(s) |
|---|---|---|
| ADV1 — Salary disbursement | `/payroll/periods/:id` → Disbursement Proof | `finance@ogami.test`, `hr@ogami.test` |
| ADV2 — Separate MRP and SCM | Sidebar; `/mrp/*` and `/supply-chain/*` | `ppc@ogami.test`, `impex@ogami.test` |
| ADV3 — Batch and lot traceability | `/quality/traceability`, Work Order detail, Delivery detail | `qc@ogami.test`, `production@ogami.test`, `warehouse@ogami.test` |
| ADV4 — Dynamic RBAC | `/admin/roles`, permission matrix | Requires a prepared role-manager account with `admin.roles.manage`; seeded non-admin roles cannot open this screen |
| ADV5 — Procurement and billing | `/purchasing/chain`, PR/PO detail Billing | `purchasing@ogami.test`, `warehouse@ogami.test`, `finance@ogami.test` |
| ADV6 — Purchase Request automation | `/purchasing/purchase-requests`, seeded PR records, MRP shortage result | `purchasing@ogami.test`, `ppc@ogami.test` |
| ADV7 — Proof of delivery | `/supply-chain/deliveries/:id`, `/driver/:id` | `impex@ogami.test`, `warehouse@ogami.test`, `driver@ogami.test` |
| ADV8 — Warehouse Management | `/inventory/warehouse-map`, `/inventory/stock-count`, transfers, picking | `warehouse@ogami.test` |
| ADV9 — Budget allocation | `/budgeting`, `/budgeting/budget-vs-actual`, `PR-DEMO-BUDGET` | `finance@ogami.test`, `purchasing@ogami.test` |
| ADV10 — Supplier/customer portals | `/portal/supplier/login`, `/portal/customer/login` | `portal@supp.test`, `portal@cust.test` |
| ADV11 — Demand forecasting | `/forecasting/demand`, `/forecasting/stock-out` | `ppc@ogami.test`, `finance@ogami.test` |
| ADV12 — RMA and credit note | `/return-management`, `/accounting/credit-notes` | `purchasing@ogami.test`, `qc@ogami.test`, `finance@ogami.test` |

## 6. Cross-module handoffs to explain

These are the connections worth saying aloud while navigating:

| Trigger | Cross-module result |
|---|---|
| Sales Order confirmed | PPC and Production are notified; MRP can plan the demand |
| MRP finds a material shortage | A consolidated Purchase Request is created and linked to the order |
| Work Order starts | An in-process Quality Inspection is created automatically |
| Work Order completes | An outgoing Quality Inspection is created automatically |
| Outgoing inspection passes | Delivery is drafted and a Certificate of Conformance becomes available |
| GRN is created | Incoming QC is triggered before inventory acceptance |
| Incoming QC fails | The GRN is rejected and an NCR is created |
| Delivery is confirmed with proof | Sales Order status advances and a draft invoice is created |
| Invoice is finalized | Accounts Receivable and Revenue are posted to the General Ledger |
| Stock falls below reorder point | Purchasing receives an automatic replenishment PR |
| Payroll is finalized | Bank file, payslips, employee notifications, and payroll GL handoff are triggered |
| Machine breaks down | Maintenance receives a corrective Maintenance Work Order |
| Repeated NCR is linked | An 8D investigation can be spawned for corrective action |
| Return is disposed | Inventory/PO receipt impact and a first-class Credit Note can be created |

## 7. Account switching plan

Use separate Chrome profiles or private windows so each session remains
available:

1. **Presenter profile:** Demo Presenter role, if prepared; otherwise PPC Head.
2. **Planning profile:** `ppc@ogami.test`.
3. **Production profile:** `production@ogami.test`.
4. **Quality profile:** `qc@ogami.test`.
5. **Procurement/warehouse profile:** `purchasing@ogami.test` and
   `warehouse@ogami.test`.
6. **Finance/HR profile:** `finance@ogami.test` and `hr@ogami.test`.
7. **Portal windows:** supplier and customer portal accounts in separate
   private windows.

Do not rely on changing accounts in several tabs in the same browser profile:
internal authentication uses a shared HTTP-only session cookie for the origin,
so logging in as another internal user changes the existing session.

## 8. Likely questions

**“Where does the business process begin?”**

With a Sales Order. MRP translates demand into material and production plans;
Work Orders execute that plan.

**“How does procurement connect to production?”**

MRP checks the BOM against inventory. A shortage creates a consolidated PR,
which flows through PO, GRN, incoming QC, and inventory before production uses
the material.

**“How do you prove a defective part’s history?”**

Search the batch or material lot in `/quality/traceability`. The system links
the supplier lot, GRN, material issue, Work Order, QC, shipment lot, delivery,
and return path.

**“How do you prevent one person from approving their own payroll?”**

HR is the payroll maker; Finance is the checker. The payroll record and
approval history show different responsibilities. The separate SOD page is not
needed for this demonstration.

**“Can a supplier see another supplier’s data?”**

No. Supplier and customer portals use separate guards and organization-scoped
records. Demonstrate both portal logins in separate windows.

**“Is this only a collection of screens?”**

Point to the linked records, automatic status changes, generated documents,
and the backend/test column in [`DEFENSE-TRACEABILITY.md`](DEFENSE-TRACEABILITY.md).

## 9. References

- [`DEMO-SCRIPT.md`](DEMO-SCRIPT.md) — short panel script and seeded demo commands
- [`DEFENSE-TRACEABILITY.md`](DEFENSE-TRACEABILITY.md) — adviser item evidence matrix
- [`PROCESS-FLOWS.md`](PROCESS-FLOWS.md) — complete status transitions and manual testing flows
- [`AUTO-BROWSER-TESTS.md`](AUTO-BROWSER-TESTS.md) — seeded accounts, role boundaries, and browser checks
- `docs/defense-screenshots/` — fallback screenshots for the showcase screens
