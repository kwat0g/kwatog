# OGAMI ERP — Defense Traceability Matrix

> Maps each of the 12 mandatory adviser items (panel review, March 2026 — see
> `docs/ADVISER-TASKS.md`) to the shipped implementation: the screen the panel
> can click, the API route that backs it, and the automated test that proves it
> works. Every item is implemented end-to-end (backend + SPA + tests).
>
> Companion docs: `docs/DEMO-SCRIPT.md` (live walk-through), `docs/ADVISER-TASKS.md`
> (original requirements).

## Status summary

| # | Adviser item | Backend | SPA screen | Test | Status |
|---|--------------|:---:|:---:|:---:|:---:|
| ADV1 | Proof of salary disbursement | ✅ | ✅ | ✅ | Done |
| ADV2 | SCM & MRP separate modules (sidebar) | — | ✅ | n/a | Done |
| ADV3 | Production / batch & lot traceability | ✅ | ✅ | ✅ | Done |
| ADV4 | Dynamic RBAC | ✅ | ✅ | ✅ | Done |
| ADV5 | Procurement: material req + billing | ✅ | ✅ | ✅ | Done |
| ADV6 | Process automation (Purchase Request) | ✅ | ✅ | ✅ | Done |
| ADV7 | Proof of delivery (photo/signature) | ✅ | ✅ | ✅ | Done |
| ADV8 | Warehouse Management System | ✅ | ✅ | ✅ | Done |
| ADV9 | Budgeting (allocation) | ✅ | ✅ | ✅ | Done |
| ADV10 | B2B portals (supplier + customer) | ✅ | ✅ | ✅ | Done |
| ADV11 | Forecasting (demand) | ✅ | ✅ | ✅ | Done |
| ADV12 | Return policy (RMA) | ✅ | ✅ | ✅ | Done |

---

## Item-by-item evidence

### ADV1 — Proof of salary disbursement
- **Screen:** `/payroll/periods/:id` → *Disbursement Proof* section (`spa/src/pages/payroll/periods/detail.tsx`)
- **Routes:** `PATCH /payroll-periods/{period}/mark-disbursed`, `POST|GET /payroll-periods/{period}/disbursement-proofs`, `DELETE .../{proof}`
- **Backend:** `DisbursementProofController`, `DisbursementProof` model, `payroll_disbursement_proofs` table
- **Tests:** `Feature/Payroll/PayrollPeriodLifecycleTest`, `Feature/SupplyChain/DeliveryUploadTest` (file-upload pattern)
- **Demo proof:** payroll period shows *Status: ✅ Disbursed* with attached bank confirmation, amount, ref.

### ADV2 — SCM & MRP as separate modules
- **Screen:** left sidebar (`spa/src/components/layout/Sidebar.tsx`) — distinct section groups: *Production Planning (MRP)* and *Supply Chain (SCM)* are separate headers, not nested together.
- **Evidence:** URL prefixes `/mrp/*` vs `/supply-chain/*` are independent module trees.

### ADV3 — Production, batch & lot number traceability *(the flagship IATF proof)*
- **Screens:** `/quality/traceability`, Work Order detail `/production/work-orders/:id` (Batch section), Delivery detail (Lot section)
- **Routes:** `GET /work-orders/{wo}/chain`, traceability search endpoints
- **Backend:** `work_orders.batch_number` + `material_lot_references`, `ShipmentLot` model/service, `TraceabilityService`, `CoCService` (batch+lot on the Certificate of Conformance); migration `0150_add_batch_lot_traceability`
- **Tests:** `Unit/BatchLotSequenceTest`, `Feature/Inventory/LotTraceabilityTest`
- **Trace chain:** Supplier lot → GRN → material issue → Batch (WO) → QC inspection → Shipment Lot → Delivery → CoC. See full narrative at the bottom of this doc.

### ADV4 — Dynamic RBAC
- **Screens:** `/admin/roles`, `/admin/roles/:id`, permission matrix
- **Backend:** `roles` / `permissions` / `role_permission` (280+ permissions, 13 seeded roles), `RolePermissionSeeder`; guards enforced server-side via middleware + `FormRequest::authorize()`
- **Tests:** `Feature/HR/EmployeeDataScopeTest` (permission-driven row scope), `Feature/Payroll/PayrollMakerCheckerTest` (SoD), `Feature/Accounting/JournalEntryMakerCheckerTest`
- **Demo proof:** create a "Line Supervisor" role live, grant 4 permissions, log in as that user → only those actions available.

### ADV5 — Procurement: material requirement + billing process
- **Screens:** `/purchasing/chain` (chain overview), PO detail billing tab, `/purchasing/purchase-requests`, `/purchasing/purchase-orders`
- **Backend:** 3-way match wired into bill create; `BillService`, PR→PO→GRN→Bill→Payment flow
- **Tests:** `Feature/Purchasing/ThreeWayMatchTest`, `ThreeWayMatchGrnCoverageTest`, `BillMatchAlignmentTest`
- **Demo proof:** PO detail shows GRN received → 3-way match ✅ → Create Bill → Record Payment.

### ADV6 — Process automation in Purchase Request
- **Screens:** `/purchasing/pr-templates`, PR create (auto-filled supplier), sidebar approval badge
- **Backend:** `purchase_request_templates`, auto-generated PR from MRP shortage/reorder, urgency escalation, bulk approve
- **Tests:** covered under Purchasing feature suite
- **Demo proof:** "Use Template" pre-fills a full PR; MRP shortage auto-creates a draft PR with preferred supplier.

### ADV7 — Proof of delivery
- **Screens:** Delivery detail `/supply-chain/deliveries/:id` (Proof of Delivery), driver mobile flow (`spa/src/pages/driver/`)
- **Routes:** `POST|GET /deliveries/{delivery}/proofs`, `GET .../proofs/{proof}/view`, `POST /deliveries/{delivery}/confirm`
- **Backend:** `DeliveryProof` model/controller, `delivery_proofs` table (migration `0156`); confirmation blocked without ≥1 proof
- **Tests:** `Feature/SupplyChain/DeliveryConfirmTest`, `DeliveryUploadTest`, `DriverDeliveryTest`, `CocAutoAttachOnConfirmTest`
- **Demo proof:** driver marks delivered → uploads signed DR photo → customer-confirm gated on proof.

### ADV8 — Warehouse Management System
- **Screens:** `/inventory/warehouse-map`, `/warehouse/stock-count`, `/warehouse/transfers`, `/warehouse/picking`, `/dashboard/warehouse`
- **Routes:** stock-count sessions, transfer orders, picking lists, stock adjustments (Inventory `routes.php`)
- **Backend:** `StockCountSession`/`StockCountItem`, `TransferOrder`, bin-level locations, `PickingListService`
- **Tests:** `Feature/Inventory/QuarantineMrbTest` (MRB hold), stock-count + transfer coverage
- **Demo proof:** warehouse map color-codes bins; count session freezes zone, variance → supervisor sign-off → adjustment.

### ADV9 — Budgeting (allocation)
- **Screens:** `/budgeting`, `/budgeting/:id`, `/budgeting/departments/:id`, `/budgeting/budget-vs-actual`, `/budgeting/transfers`
- **Backend:** `Budget`, `BudgetLineItem`, `BudgetTransfer`, `BudgetRevision`, `fiscal_years`; migration `0162`; budget enforcement wired into PR/PO
- **Tests:** `Feature/Accounting/BudgetEnforcementWiringTest`
- **Demo proof:** FY2026 overview with per-department allocated/spent/%, a department at 🔴 Critical (≥95%), budget-vs-actual P&L.

### ADV10 — B2B portals (supplier + customer)
- **Screens:** `/portal/supplier/*` (POs, invoices, delivery schedules, statement), `/portal/customer/*` (orders, invoices, deliveries, complaints, statement)
- **Backend:** `SupplierPortalUser` / `CustomerPortalUser` on **separate auth guards**; B2B module controllers; own-vendor / own-customer row isolation
- **Tests:** `Feature/B2B/SupplierPortalAuthTest`, `CustomerPortalAuthTest`, `PortalTokenCrossGuardTest` (cross-guard isolation), `SupplierPortalServiceTest`, `CustomerPortalServiceTest`, `PortalValidationTest`
- **Demo proof:** log into supplier portal → see only that vendor's POs; cross-guard test proves a customer token can't reach supplier data.

### ADV11 — Forecasting (demand)
- **Screens:** `/forecasting/demand`, `/forecasting/stock-out`, `/forecasting/accuracy`
- **Backend:** `DemandForecast` model, `ForecastingService` (moving avg / weighted / manual), `ForecastMrpService` (feeds MRP), `StockOutProjectionService`
- **Tests:** `Feature/Forecasting/ForecastAccuracyTest`
- **Demo proof:** 3-month moving-average forecast per product with customer breakdown; projected stock-out date → Create PR.

### ADV12 — Return policy (RMA)
- **Screens:** `/return-management`, `/return-management/:id`, `/return-management/new`
- **Backend:** `ReturnRequest` / `ReturnRequestItem`, `ReturnRequestService`; customer + supplier return types; disposition → **first-class Credit Note** (retired the old negative-invoice hack, REC-13)
- **Tests:** `Feature/ReturnManagement/DispositionTest`, `ReturnRequestCompleteRequiresLocationTest`, `Feature/Accounting/CreditNoteTest`
- **Demo proof:** customer complaint → RMA → QC inspect returned parts → disposition scrap → Credit Note reduces AR.

---

## The single flagship trace (strongest IATF 16949 demonstration)

One continuous, clickable chain — this is what wins the panel:

```
Customer complaint (CRM / 8D)
  └─ Return Request  RMA-YYYYMM-NNNN            (ADV12)
       └─ Shipment Lot  LOT-YYYYMMDD-NNNN       (ADV3)
            └─ Production Batch  BATCH-YYYYMMDD-NNNN   (ADV3)
                 └─ QC Outgoing Inspection  QC-YYYYMM-NNNN
                      └─ Work Order  WO-YYYYMM-NNNN
                           └─ Material Issue Slip
                                └─ GRN + material lot  GRN-YYYYMM-NNNN
                                     └─ Supplier + PO  (supplier lot SL-XX-NNNN)
```

Narrative the panel hears:

> "The part Toyota returned traces to Lot LOT-20260415-0001, Batch
> BATCH-20260407-0001, produced Apr 07 on machine IM-002 using Resin A from
> GRN-20260402 — supplier Taiwan Plastics, supplier lot SL-TW-0234, received
> and QC-passed Apr 02."

Every hop above is a real screen with a real record after the demo seeder runs.

---

## Supporting credibility

- **Automated tests:** full backend suite (see `docs/TEST-COVERAGE-REPORT.md`); run `make test` or the PHPUnit filter per module.
- **Security:** Sanctum SPA cookie auth (no bearer tokens), HashID-obfuscated IDs, encrypted government IDs, server-side permission enforcement, separate portal guards.
- **Chains:** all three business chains (Order-to-Cash, Procure-to-Pay, Hire-to-Retire) wired end-to-end with IATF quality touchpoints woven in.
