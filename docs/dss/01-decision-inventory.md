# Phase 1 — Decision Inventory & Gap Analysis

Scoring: V=value, D=data readiness, E=effort, R=risk-if-wrong (flags validation need, not in formula).
**Priority = V × D ÷ E**, rounded to 1 decimal. Scores are the author's judgment from Phase 0 evidence;
assumption-heavy cells marked `*`. Types: O=operational, T=tactical, S=strategic.
Support today: N=none, P=partial, E=existing (ref).

## Decision catalog

| ID | Decision ("…?") | Module/chain | Roles | Freq | Support | Type → capability | V | D | E | R | Pri |
|---|---|---|---|---|---|---|---|---|---|---|---|
| D01 | Reorder what, how much, when? | Inv/P2P | warehouse, purchasing | daily | E (auto-replenish+SS+ABC) | O → recommend | 5 | 5 | 2 | 3 | **12.5** |
| D02 | Which supplier for item X? | Purch/P2P | purchasing | per-PR | P (scorecard+tier suggest) | O → recommend | 5 | 4 | 3 | 4 | **6.7** |
| D03 | Which customer orders are at risk this week? | CRM/O2C | sales, ppc, VP | daily | P (MRP diag+alerts) | O → detect | 5 | 4 | 2 | 3 | **10.0** |
| D04 | Can we promise this date/commit this SO? (ATP/CTP) | CRM/O2C | sales, ppc | per-SO | P (MRP net+capacity) | O → predict | 5 | 4 | 3 | 4 | **6.7** |
| D05 | What runs on which machine/mold when? (sequence) | MRP/Prod | ppc, prod-mgr | daily | P (greedy scheduler+proposals) | O → optimize | 5 | 4 | 4 | 4 | **5.0** |
| D06 | Shortage: wait / partial / expedite / substitute? | MRP/P2P | ppc, purchasing | per-shortage | N | O → recommend | 5 | 4 | 3 | 4 | **6.7** |
| D07 | Which production orders need intervention today? | Prod/O2C | prod-mgr | daily | P (overdue alerts+OEE proxy) | O → detect | 4 | 4 | 2 | 2 | **8.0** |
| D08 | Which deliveries to consolidate/dispatch today? | SC/O2C | impex, warehouse | daily | P (schedule+overdue) | O → recommend | 4 | 4 | 3 | 3 | **5.3** |
| D09 | Whom to collect from first? (collection priority) | Acct/O2C | finance | daily | P (dunning tiers+AR bands) | O → recommend | 5 | 4 | 2 | 3 | **10.0** |
| D10 | Which bills to pay now under cash C? | Acct/P2P | finance, VP | weekly | N | O → optimize | 5 | 3 | 3 | 4 | **5.0** |
| D11 | Is this price/discount safe? (margin guard) | CRM/O2C | sales, finance | per-SO | N | O → detect | 4 | 3 | 2 | 4 | **6.0** |
| D12 | Customer credit exposure OK before accepting order? | CRM/O2C | sales, finance | per-SO | P (`credit_limit` exists, no check found) | O → detect | 4 | 4 | 2 | 4 | **8.0** |
| D13 | Which NCR/CAPA/8D needs action now? | Qual/all | qc, owners | daily | E (SLA escalations+aging) | O → detect | 5 | 5 | 1 | 2 | **25.0** |
| D14 | Where is the defect source? (Pareto→machine/mold/material/shift) | Qual | qc, prod-mgr | weekly | P (Pareto+SPC, no auto-drill) | T → predict | 4 | 4 | 3 | 3 | **5.3** |
| D15 | Is the process drifting? (SPC early warning) | Qual | qc | per-lot | P (SpcService, no auto-alert loop) | O → predict | 5 | 3* | 3 | 4 | **5.0** |
| D16 | Traceability: which FG/customers hit by suspect lot? | Qual | qc, sales, VP | on-event | N (lot data partial) | O → detect | 5 | 2 | 4 | 5 | **2.5** |
| D17 | Which maintenance job first? (criticality×impact) | Maint | maint-sup, prod-mgr | daily | P (suggest+SLA+repeat flags) | O → recommend | 4 | 4 | 2 | 3 | **8.0** |
| D18 | PM due now vs deferrable? (overdue risk) | Maint | maint-sup | daily | P (auto-gen, no defer optimizer) | O → recommend | 4 | 4 | 2 | 3 | **8.0** |
| D19 | Repair vs replace this asset? | Assets | finance, VP | quarterly | N | T → recommend | 3 | 2 | 3 | 4 | **2.0** |
| D20 | Overtime approve? Staffing vs load this week? | HR/H3 | dept-head, hr | weekly | P (DTR+OT rules, no load link) | O → recommend | 4 | 3 | 3 | 3 | **4.0** |
| D21 | Pre-payroll: is this run clean? (anomalies before finalize) | Payroll/H3 | finance, hr | semi-monthly | P (anomaly flags exist) | O → detect | 5 | 4 | 2 | 5 | **10.0** |
| D22 | Whose contract/probation/training/cert expires soon? | HR/H3 | hr, dept-head | weekly | P (expiry alerts exist) | O → detect | 3 | 4 | 1 | 2 | **12.0** |
| D23 | Accept this return? What disposition? | Returns | cs, finance, qc | per-RMA | N (flow exists, no rules) | O → recommend | 3 | 3 | 3 | 3 | **3.0** |
| D24 | Which slow/excess/obsolete stock to clear? | Inv | warehouse, finance | monthly | P (ABC exists, unscheduled) | T → detect | 4 | 3 | 2 | 2 | **6.0** |
| D25 | Which approval is stuck, who unblocks it? | Cross | all approvers | daily | P (escalations, no bottleneck view) | O → detect | 4 | 5 | 1 | 2 | **20.0** |
| D26 | Which documents are stuck/missing downstream? | Cross | chain owners | daily | E (16 bottleneck detectors) | O → detect | 5 | 5 | 1 | 2 | **25.0** |
| D27 | Is this master-data gap breaking automation? | Cross | admin, owners | weekly | N (no hygiene score) | O → detect | 4 | 5 | 1 | 1 | **20.0** |
| D28 | Cash shortfall in next N weeks? | Acct | finance, VP | weekly | P (cash-flow forecast svc) | T → predict | 5 | 3 | 3 | 5 | **5.0** |
| D29 | Budget overrun coming? (budget-vs-actual trajectory) | Acct | finance, VP | monthly | P (live sync, no trajectory) | T → predict | 4 | 4 | 2 | 3 | **8.0** |
| D30 | Company health + weekly briefing? | Exec | VP | weekly | P (KpiStrip+forecasts, monthly KPIs) | S → predict/simulate | 4 | 3 | 4 | 3 | **3.0** |
| D31 | What-if: big order / late supplier / down machine? | Cross | ppc, VP | on-event | N | T → simulate | 5 | 3* | 5 | 4 | **3.0** |
| D32 | Attrition risk / absence pattern? | HR | hr (restricted) | monthly | N | T → predict | 2 | 2 | 3 | 5 | **1.3** |
| D33 | Duplicate/suspicious transaction? (SoD, anomalies) | Cross | finance, admin | daily | P (3-way, SOD rules exist?) | O → detect | 4 | 3* | 3 | 4 | **4.0** |

Ranked: D13/D26 (25) > D25/D27 (20) > D01 (12.5) > D22 (12) > D03/D09/D21 (10) > D07/D12/D17/D18/D29 (8) >
D02/D04/D06 (6.7) > D11 (6) > D24 (6) > D08/D14 (5.3) > D05/D10/D15/D28 (5) > D20/D33 (4) > D23/D30/D31 (3) >
D16 (2.5) > D19 (2) > D32 (1.3).

## Module × decision coverage matrix

| Module | Covered (E/P) | Zero-coverage decisions → why |
|---|---|---|
| Inventory | D01(E) D24(P) | — |
| Purchasing | D02(P) | — (D06 shared) |
| MRP | D05(P) D06(N) | D06: no make-vs-wait model |
| Production | D07(P) | — |
| CRM/O2C | D03(P) D04(P) D11(N) D12(P) | D11: no cost/margin service found; verify std-cost availability |
| SupplyChain | D08(P) | — |
| Quality | D13(E) D14(P) D15(P) D16(N) | D16: lot genealogy partial (`lot_number` on movements, no FG→RM linkage table) |
| Maintenance | D17(P) D18(P) | — |
| Assets | D19(N) | no failure-cost history, no replacement model |
| HR/Attendance/Leave | D20(P) D22(P) | — |
| Payroll/Loans | D21(P) | — |
| Accounting | D09(P) D10(N) D28(P) D29(P) | D10: no payment-prioritizer; needs cash position + terms |
| Forecasting | feeds D03/D28/D30 | method gap: no seasonal/intermittent (domain methods missing) |
| Returns | D23(N) | flow exists, rules don't |
| Dashboard | D26(E) D30(P) | D30 grain: KPIs monthly-only |
| Cross-cutting | D25(P) D27(N) D31(N) D33(P) | D27/D31: entirely new builds |
| B2B Portal | — (no portal decisions catalogued) | portal self-service decisions (supplier confirm? customer RMA status?) out of DSS scope until usage data exists |
| Landing | — | public-site, no DSS relevance |

Every module with production data appears. Zero-intelligence modules: Assets (D19), Returns-rules (D23),
B2B/Landing (no recurring internal decision identified — explicitly out of scope).

## Role × decision matrix (primary consumers)

| Role | Decisions served |
|---|---|
| vice_president | D03, D06, D10, D16, D19, D28, D30, D31 |
| ppc_head | D03, D04, D05, D06, D31 |
| sales_officer | D03, D04, D11, D12 |
| purchasing_officer | D01, D02, D06 |
| warehouse_staff | D01, D08, D24 |
| production_manager | D05, D07, D14, D17 |
| qc_inspector | D13, D14, D15, D16, D23(inspect) |
| maintenance_supervisor/tech | D17, D18 |
| finance_officer | D09, D10, D12, D21, D28, D29, D33 |
| hr_officer | D20, D21, D22, D32(restricted) |
| department_head | D20, D22, D25 |
| customer_service_officer | D13(complaints), D23 |
| impex_officer | D08 |
| employee/driver | none (self-service only — no DSS push) |
| system_admin | D27, DSS health (not business decisions) |

## Process-gap register (own track — block good decisions until fixed)

| # | Gap | Blocks | Fix hint |
|---|---|---|---|
| G-01 | No `stock_level_snapshots` — levels overwritten live | D01 trends, D24 aging, D28 stock value | Wave 0 table + daily job |
| G-02 | Single `last_price` on ASL, no price history | D02 price-aware selection (see F-01) | `supplier_price_history` + receipt hook |
| G-03 | No FG→RM lot genealogy linkage | D16 traceability/recall | lot-lineage on issue/output (M) |
| G-04 | No returns auto-rules / scheduled scan | D23 | disposition matrix + `returns:scan` (S) |
| G-05 | No spare-part reorder hook | D17 completeness | min-level on spares → reuse replenishment (S) |
| G-06 | No cash-position snapshot / payment prioritizer inputs | D10, D28 accuracy | `ar_ap_snapshots` + terms/discount capture |
| G-07 | KPIs monthly-only | D30 weekly briefing, all trend DSS | `kpi_daily` for 3–4 KPIs or weekly grain |
| G-08 | Forecast methods: no seasonal/intermittent (Croston/SBA) | D03/D28 for spares/slow items | forecasting service extension (Wave 3) |
| G-09 | Machine-hour series overwritten (`running_hours_total`) | D05/D18 accuracy, OEE trend | append-only hour ledger |
| G-10 | RESERVED workflows (NCR/8D/maintenance/WO) non-blocking | D13/D17 enforcement assumptions | decide: enforce or keep advisory (owner call) |
| G-11 | MWO polymorphic owner (no direct `machine_id`) | D17/D18 query cost + correctness | nullable `machine_id` denormalised column (S) |
| G-12 | Credit-limit check on SO accept not found (column exists) | D12 | confirm-or-build pre-accept gate (S) |
