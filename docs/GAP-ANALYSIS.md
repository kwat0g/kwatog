# OGAMI ERP — Complete Module-by-Module Gap Analysis

> Every module audited against its business domain. Rankings based on: manufacturing
> necessity, Philippine regulatory compliance, IATF 16949 alignment, thesis narrative
> value, and effort-to-impact ratio.

---

## Module health summary

| Module | Health | Missing Critical | Gaps |
|---|---|---|---|
| **Quality** | 🟢 Strong | 0 | COPQ data incomplete, no CAPA effectiveness (designed) |
| **Payroll** | 🟢 Strong | 0 | De minimis benefits, multi-format bank file |
| **Production** | 🟢 Strong | 0 | No multi-mold per WO, no rework tracking per operator |
| **MRP / MRP II** | 🟢 Strong | 0 | No multi-level capacity/scheduling, no what-if planner |
| **Inventory** | 🟢 Strong | 2 | No ABC classification, no FEFO picking |
| **Accounting** | 🟡 Good | 1 | No GL auto-reconcile with budget, no fiscal-year close wizard |
| **Maintenance** | 🟡 Good | 1 | Predictive only for machines, not molds |
| **Attendance** | 🟡 Good | 1 | No grace period config, no biometric API |
| **HR** | 🟡 Good | 3 | No skills matrix, no performance review, no succession |
| **Leave** | 🟡 Good | 1 | No forced-leave enforcement, no forfeiture job |
| **Loans** | 🟡 Good | 1 | Zero-interest only, no SSS/Pag-IBIG gov loans |
| **B2B Portal** | 🟡 Good | 2 | Supplier can't view PPAP status, no customer 8D portal |
| **CRM** | 🟠 Fair | 3 | No pipeline, no price tiers, no commission tracking |
| **Supply Chain** | 🟠 Fair | 3 | No landed cost, no incoterms, no container tracking |
| **Budgeting** | 🟠 Fair | 2 | No GL auto-sync, no enforcement at PO level |
| **Forecasting** | 🟠 Fair | 2 | No MRP feed, no advanced methods |
| **Return Mgmt** | 🟠 Fair | 2 | No credit note creation, no NCR integration |
| **Assets** | 🟠 Fair | 1 | Straight-line depreciation only |
| **Dashboard** | 🟡 Good | 1 | No WebSocket live updates, no KPI customization per user |
| **Admin** | 🟢 Strong | 0 | (Complete) |
| **Auth** | 🟢 Strong | 0 | (Complete) |
| **Edge/Driver** | 🟡 Good | 0 | (Complete for scope) |

---

## Detailed findings per module

### 1. CRM MODULE — 3 Critical Gaps

**GAP 1 — No sales pipeline (lead → opportunity → quote → SO)**
- The system jumps directly to `SalesOrder`. Draft SO acts as a quasi-quote.
- Missing: `Lead`, `Opportunity`, `Quote` models. Quote revision tracking, quote expiry, quote-to-SO conversion.
- Impact: No visibility into the top of the funnel. Sales forecasting relies on historical SO data only.
- Effort: 3 new models + 2 services + 3 pages. ~2 weeks.

**GAP 2 — No price tiers or volume discounts**
- `PriceAgreement` stores a single flat price per product+customer.
- Missing: tiered pricing (qty 1-100 = ₱X, 101+ = ₱Y), volume discount bands, discount % field.
- Impact: Ogami negotiates volume pricing with Toyota/Nissan — the system can't express it.
- Effort: Add `pricing_method` + `tiers` JSON column to PriceAgreement. ~3 days.

**GAP 3 — No sales rep commission tracking**
- No `sales_rep_id` on Customer or SalesOrder. No commission rate table.
- Impact: Cannot answer "how much commission does each sales rep earn."
- Effort: 1 field + 1 service + 1 report page. ~3 days.

**Also: Customer model is minimal.** No billing/shipping address distinction, no customer group/classification, no default price tier. The `Customer` model lives in Accounting (not CRM). Adding 6 columns would significantly improve it.

---

### 2. SUPPLY CHAIN MODULE — 3 Critical Gaps

**GAP 1 — No landed cost calculation**
- `Shipment` has no `freight_cost`, `insurance_cost`, `duties_amount`, `brokerage_fee` fields.
- No service to allocate freight/insurance/duties to PO lines per weight or value.
- Impact: Ogami imports resin — the purchase price on the PO is NOT the actual cost. Without landed cost, inventory valuation is incomplete.
- Effort: Add 4 fields to Shipment + `LandedCostAllocationService` + per-PO-line landed cost view. ~1 week.

**GAP 2 — No Incoterm support**
- `Shipment` and `PurchaseOrder` have no incoterm field. `SalesOrder` has free-text `delivery_terms`.
- Impact: Incoterms determine who pays freight/insurance/duties. Missing incoterms make landed cost allocation ambiguous.
- Effort: Add `Incoterm` enum (EXW, FOB, CIF, DDP, etc.) + field on PO, SO, Shipment. ~2 days.

**GAP 3 — No container-level tracking**
- `Shipment` has a single `container_number` string. One container per shipment.
- Missing: `Container` model with seal number, size, weight, multi-container support.
- Impact: A resin import typically has 2-5 containers. The system forces each container to be a separate shipment.
- Effort: 1 new model + refactor Shipment.line ↔ Container.items. ~1 week.

**Present but incomplete:**
- Import document tracking (9 types) is thorough but document storage is single-file — no multi-file per document type.

---

### 3. INVENTORY MODULE — 2 Critical Gaps

**GAP 1 — No ABC classification**
- No `abc_class` field on Item. No auto-classification (by usage value, throughput).
- Impact: ABC is fundamental to inventory management — A items need tight control, C items are loose. Without it, counting and replenishment policies are uniform.
- Effort: 1 field + `AbcClassificationService` (recomputes from stock movements) + cron. ~2 days.

**GAP 2 — No FEFO (first-expiry-first-out) picking**
- `PickingListService` uses FIFO by `created_at`. Expiry dates exist on `GrnItem` but are unused.
- Impact: Resin has shelf life. FIFO can suggest oldest-manufactured stock even if it expires sooner.
- Effort: Modify `PickingListService` to prefer earliest `expiry_date` when present. ~1 day.

**Also missing:**
- Stock aging report (aggregate view of inventory by days-since-last-movement)
- Full pick-confirm-pack-ship workflow (scanning, package hierarchy, tracking numbers)
- Expiry monitoring with scheduled alerts
- Bin location capacity/type attributes (max_capacity, is_pickable, is_receivable, temperature_zone)
- First-class `Lot` model (lot is a string on movements, not an entity with aggregate views)

---

### 4. HR MODULE — 3 Critical Gaps

**GAP 1 — No skills matrix**
- No model for employee skills, proficiency levels, or certifications.
- Impact: IATF 16949 requires documented operator competence. "Who is certified on Press #3?" is unanswerable.
- Effort: 2 models (`Skill`, `EmployeeSkill`) + 1 matrix page. ~1 week.

**GAP 2 — No performance review**
- No appraisal cycle, rating scales, goal tracking, or review forms.
- Impact: 200+ employees with no structured performance data. Promotions and discipline are offline.
- Effort: 3 models + 1 service + 2 pages. ~2 weeks.

**GAP 3 — No succession planning**
- No model for succession plans, potential successors, readiness levels.
- Impact: Key positions (production manager, QC head) have no documented backup.
- Effort: 1 model + 1 page embedded in org chart. ~3 days.

**Present but incomplete:**
- Exit interview is a binary clearance checkbox — no structured exit survey with reason categories
- Org chart is department-grouped only, not individual-box visualization

---

### 5. PAYROLL MODULE — Low Priority Gaps

**Payroll is the strongest module.** All Philippine statutory compliance (BIR 1601-C, 1604-CF, 2316, SSS R-3, PhilHealth RF-1, Pag-IBIG MCRF) is present. The gaps are all "nice to have."

**GAP 1 — De minimis benefits missing**
- No tracker for rice subsidy, uniform allowance, medical cash — which are tax-exempt up to limits.
- Effort: 1 model + integration into PayrollCalculatorService. ~3 days.

**GAP 2 — Single bank file format**
- Only one CSV format. No BDO/BPI/Metrobank-specific variants.
- Effort: Extract `BankFileService` to strategy pattern + 2 format classes. ~2 days.

**What the system already handles impressively:**
- TRAIN Law-based BIR withholding (correct 2023+ brackets)
- Semi-monthly + daily-rate dual pay types with proration
- OT + ND stacking with holiday premium
- Mid-cycle salary changes prorated correctly
- Loan auto-deduction + 13th-month separate computation
- Anomaly detection (unusual OT hours, missing attendance, salary variance)
- GL posting idempotency + bank file auto-generation on finalize

---

### 6. ATTENDANCE MODULE — 2 Critical Gaps

**GAP 1 — No grace period configuration**
- Tardiness counts every minute after shift start. No "15-minute grace" before marking late.
- Impact: Philippine labor law allows de minimis tardiness. Zero-tolerance is harsh and legally questionable.
- Effort: Add `grace_minutes` to Shift model + integrate into DTRComputationService. ~1 day.

**GAP 2 — No direct biometric device API integration**
- Only CSV import. No ZKteco SDK, no real-time punch capture.
- Impact: Every payroll cycle requires manual CSV export from the biometric device.
- Effort: Install `zkteco-js` or equivalent, build import service. ~1 week.

**What the system handles:**
- Full 14 holiday/rest-day combinations (regular holiday, special non-working, rest day, OT, ND)
- Night differential (10 PM - 6 AM, 10% premium)
- Extended shift auto-OT detection (6 AM - 6 PM pattern)
- Punch sessionization (raw timestamps → paired IN/OUT)
- Shift assignment with bulk-by-department and effective dates

---

### 7. LOANS MODULE — 1 Critical Gap

**GAP 1 — Zero-interest only, no government loans**
- Only `CompanyLoan` and `CashAdvance`. No SSS Salary Loan, no Pag-IBIG MPL.
- The `AmortizationService` ignores the `interest_rate` field entirely.
- Impact: Philippine employees commonly take SSS and Pag-IBIG loans. These are legal requirements to support.
- Effort: Add 2 loan types + interest-bearing amortization. ~1 week.

---

### 8. LEAVE MODULE — 1 Critical Gap

**GAP 1 — No forced leave enforcement or forfeiture**
- Philippine law mandates 5 days Service Incentive Leave. No mechanism ensures employees take it.
- `LeaveType` has `is_convertible_year_end` and conversion_rate, but no scheduled job forfeits/converts unused leave.
- Impact: Constitutional compliance gap. Unused leave accumulates unbounded.
- Effort: 1 cron job (year-end leave forfeiture/encashment) + 1 forced leave scheduling view. ~3 days.

**What's present and impressive:**
- 8 legal leave types including ML (105d), PL (7d), SPL (7d), VAWC (10d), SLW/Magna Carta (60d)
- Leave conversion on separation AND year-end
- Paid/unpaid distinction per type
- Balance consumption only on HR approval (correct IATF workflow)

---

### 9. ACCOUNTING / BUDGETING — 2 Critical Gaps

**GAP 1 — Budget-vs-actual not synced from GL**
- `BudgetLineItem.actual_total` is never automatically populated from journal entries.
- The budget-vs-actual comparison is effectively manual.
- Impact: The entire budgeting module is advisory — it reports numbers that may not reflect actual spending.
- Effort: 1 job that reconciles GL account totals into budget line items. ~3 days.

**GAP 2 — No budget enforcement at transaction level**
- `BudgetEnforcementService::checkAvailability()` returns advisory messages.
- There is no middleware, observer, or service hook that blocks PO creation or bill posting when a department is over budget.
- Impact: Budgets are suggestions, not controls.
- Effort: Add enforcement call in PurchaseOrderService and BillService. ~2 days.

**Also missing:**
- Fiscal year close wizard
- Budget carry-over / roll-forward between fiscal years
- Budget revision auto-application (revisions recorded but changes not applied to line items)

---

### 10. RETURN MANAGEMENT — 2 Critical Gaps

**GAP 1 — Credit note not created on completion**
- The `credit_note_id` column exists but `ReturnRequestService::complete()` never creates it.
- The return completes with stock movement only. No financial reversal.
- Impact: Customer returns result in inventory being restocked but no credit issued.
- Effort: Add credit note creation in `complete()`. ~2 days.

**GAP 2 — No quality inspection integration**
- The `inspect` step is a free-text notes field. No link to the Quality module's `Inspection` model.
- No NCR auto-create for defective returns.
- Impact: Returned goods can be accepted without quality evaluation. Defect patterns from returns are invisible.
- Effort: Link return.inspect to Quality inspection creation. ~3 days.

---

### 11. ASSETS MODULE — 1 Critical Gap

**GAP 1 — Straight-line depreciation only**
- No declining balance, SYD, or units-of-production methods.
- Impact: Fixed for Philippine manufacturing (most companies use straight-line for simplicity). Low urgency.
- Effort: Add `depreciation_method` field + strategy classes. ~3 days.

**Also missing:**
- Asset transfer between departments
- Asset maintenance history integration (no link to MaintenanceWorkOrder)
- Physical verification/audit schedule
- Insurance tracking

---

## Cross-cutting gaps (affect multiple modules)

### 1. MRP ⇄ Forecasting Integration
The `StockOutProjectionService` computes days-until-stockout, but the MRP engine never reads forecast data. MRP triggers from confirmed SOs only. Adding forecast-driven MRP would enable pre-building for anticipated demand.

### 2. Quality ⇄ Return Management Integration
Returns should trigger quality inspections, and defect reasons should feed into the NCR/defect database. Currently completely disconnected.

### 3. Budgeting ⇄ Purchasing Enforcement
Budgets are advisory. Adding a PO-level gate where `BudgetEnforcementService::checkAvailability(department, amount)` blocks transactions would make budgeting enforce actual spending controls.

### 4. Real-Time Dashboard (WebSocket)
Every dashboard uses REST with 30-second cache TTL. The Reverb WebSocket infrastructure is already wired. Pushing badge updates, stock movements, machine status, and NCR creation live would make the dashboards feel dynamic rather than stale.

### 5. Mobile PWA for All Shop-Floor Roles
Only drivers have a mobile PWA. Production operators, QC inspectors, and maintenance techs need the same treatment. Designed in `DEEP-DESIGN.md`.

---

## Prioritized build backlog (ranked by thesis + business impact)

### TIER 1 — Build immediately (highest impact, lowest effort)
| # | Feature | Module | Effort | Why |
|---|---|---|---|---|
| 1 | Grace period config | Attendance | 1 day | Legally required, trivial |
| 2 | FEFO picking | Inventory | 1 day | Resin shelf-life safety |
| 3 | ABC classification | Inventory | 2 days | Fundamental inventory control |
| 4 | Budget-vs-actual GL sync | Budgeting | 3 days | Makes budgeting real |
| 5 | Forced leave forfeiture job | Leave | 3 days | Legal compliance |
| 6 | Landed cost calculation | Supply Chain | 1 week | Accurate inventory valuation |
| 7 | Credit note auto-create | Return Mgmt | 2 days | Completes the RMA financial loop |
| 8 | Return → Quality inspection link | Return Mgmt | 3 days | Quality traceability on returns |

### TIER 2 — Build next (high impact, moderate effort)
| # | Feature | Module | Effort | Why |
|---|---|---|---|---|
| 9 | Skills matrix | HR | 1 week | IATF operator competence |
| 10 | Sales pipeline (Lead/Oppty/Quote) | CRM | 2 weeks | Complete sales funnel |
| 11 | Price tiers / volume discounts | CRM | 3 days | Toyota/Nissan volume pricing |
| 12 | Container tracking | Supply Chain | 1 week | Multi-container imports |
| 13 | BI device API integration | Attendance | 1 week | Eliminates manual CSV export |
| 14 | De minimis benefits tracker | Payroll | 3 days | PH tax compliance edge case |
| 15 | Multi-format bank file | Payroll | 2 days | Supports major PH banks |

### TIER 3 — Thesis differentiators (high impact, higher effort)
| # | Feature | Module | Effort | Why |
|---|---|---|---|---|
| 16 | PPAP & APQP Tracking | Quality/Vendor | 2 weeks | IATF-specific, no competitor has it |
| 17 | CAPA Effectiveness Loop | Quality/NCR | 1 week | PDCA in software |
| 18 | Mold Lifecycle Manager | MRP/Maint | 1 week | Core to injection molding |
| 19 | Mobile Factory Floor PWA | Production/QC/Maint | 2 weeks | Factory usability |
| 20 | Forecast→MRP integration | Forecasting/MRP | 1 week | Demand-driven replenishment |

### TIER 4 — Nice to have (lower urgency)
| # | Feature | Module | Effort |
|---|---|---|---|
| 21 | Performance review system | HR | 2 weeks |
| 22 | Succession planning | HR | 3 days |
| 23 | Commission tracking | CRM | 3 days |
| 24 | Customer statement of account | CRM/Accounting | 3 days |
| 25 | Interest-bearing loan amortization | Loans | 1 week |
| 26 | SSS/Pag-IBIG government loans | Loans | 1 week |
| 27 | Insurance tracking | Assets | 2 days |
| 28 | Asset transfer workflow | Assets | 2 days |
| 29 | Depreciation method selection | Assets | 3 days |
| 30 | Real-time dashboard (WebSocket) | Dashboard | 1 week |
| 31 | Budget enforcement at PO level | Budgeting/Purchasing | 2 days |
| 32 | B2B portal → supplier PPAP view | B2B/Quality | 2 days |
