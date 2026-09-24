# Module / Feature Audit Tracker

Purpose: audit every module and feature in the OGAMI ERP one by one, from code.
 
## Re-audit status 2026-09-19

Every tracker row and every artifact in this directory has been rechecked against current source. The current canonical result is `RE-AUDIT-REGISTER-2026-09-19.md`; the row statuses below are historical inventory labels, not closure evidence.
Status values: **audited** (deep trace document written), **partial** (touched by another
chain's trace), **queued**.

**Cross-cutting backlog:** `FINDINGS-REGISTER-2026-09-18.md` — all findings ranked P0/P1/P2
with verified status (the working tree carries an uncommitted fix pass).

**Current full re-audit:** `RE-AUDIT-REGISTER-2026-09-19.md` — all 26 rows and all current
audit artifacts were rechecked against the current code; historical `FINISHED`/`PARTIAL`
labels are not treated as closure.

| # | Module | Status | Document / notes |
|---|---|---|---|
| 1 | CRM — Sales Order (O2C) | audited | `FINISHED-SALES-ORDER-CHAIN-TRACE-2026-09-18.md` |
| 2 | Purchasing — PR (P2P) | FINISHED | `PURCHASE-REQUEST-CHAIN-TRACE-2026-09-18-FINISHED.md` (re-audit residuals remediated 2026-09-23) |
| 3 | Payroll / HR separation (H2R) | remediated for current engineering scope | `HIRE-TO-RETIRE-PAYROLL-TRACE-2026-09-18-FINISHED.md` |
| 4 | Accounting core (COA, JE, AR, AP, VAT, statements) | finished | `ACCOUNTING-CORE-AUDIT-2026-09-18-FINISHED.md` |
| 5 | Budgeting (budgets, revisions, transfers, fiscal year) | audited | same doc, §3.7/§8 |
| 6 | Inventory (stock, valuation, transfers, counts, warehouse) | finished | `INVENTORY-RETURNS-AUDIT-2026-09-18-FINISHED.md` |
| 7 | Quality (specs, inspections, NCR, CoC, PPAP, SPC) | FINISHED | `QUALITY-AUDIT-2026-09-18-FINISHED.md` (re-audit residuals remediated 2026-09-23) |
| 8 | Production (WO, output, OEE, downtime, routing) | FINISHED | `PRODUCTION-AUDIT-2026-09-18-FINISHED.md` (re-audit residuals remediated 2026-09-23 — §10) |
| 9 | MRP / MRP II (BOM, capacity, molds, machines) | remediated for current engineering scope | `MRP-AUDIT-2026-09-18-FINISHED.md` (missing follow-up coverage: `MRP-AUDIT-2026-09-18-MISSING-TESTS.md`) |
| 10 | SupplyChain (shipments, impex, fleet, landed cost) | FINISHED | `SUPPLYCHAIN-AUDIT-2026-09-18-FINISHED.md` (re-audit residuals remediated 2026-09-23) |
| 11 | CRM — complaints / 8D / price agreements / products | audited | `CRM-AUDIT-2026-09-18.md` (complaints/8D in `QUALITY-AUDIT-2026-09-18-FINISHED.md`) |
| 12 | HR (employees, recruitment, onboarding, training, docs) | FINISHED (2026-09-23) | `HR-AUDIT-2026-09-18-FINISHED.md` |
| 13 | Attendance (DTR, shifts, holidays, OT) | remediated for current engineering scope | `ATTENDANCE-LEAVE-LOANS-AUDIT-2026-09-18-FINISHED.md` |
| 14 | Leave (types, balances, year-end) | remediated for current engineering scope | same doc as Attendance |
| 15 | Loans (company loan, cash advance) | remediated for current engineering scope | same doc as Attendance |
| 16 | Return Management (RMA) | finished | `INVENTORY-RETURNS-AUDIT-2026-09-18-FINISHED.md` |
| 17 | Forecasting (demand forecasts) | FINISHED | `FORECASTING-ASSETS-AUDIT-2026-09-18-FINISHED.md` |
| 18 | Assets (fixed assets, depreciation, QR) | FINISHED | `FORECASTING-ASSETS-AUDIT-2026-09-18-FINISHED.md` |
| 19 | B2B Portal (supplier + customer self-service) | FINISHED (2026-09-23) | `B2B-MAINTENANCE-AUDIT-2026-09-18-FINISHED.md` |
| 20 | Maintenance (breakdowns, mold shots, preventive) | FINISHED (2026-09-23) | same doc as B2B Portal |
| 21 | Dashboard (KPIs, widgets, analytics, alerts) | audited | `PLATFORM-CROSS-CUTTING-AUDIT-2026-09-18-FINISHED.md` |
| 22 | Admin (roles, permissions, audit logs, settings, flags) | audited | same doc as Dashboard |
| 23 | Auth / security (Sanctum SPA, lockout, hashing) | audited | same doc as Dashboard |
| 24 | Edge / shop-floor PWAs | audited (does not exist) | same doc as Dashboard |
| 25 | Landing / public pages | audited | same doc as Dashboard |
| 26 | Common infra (outbox, chains, notifications, documents, sequences) | audited | same doc as Dashboard |

Audit method per module: enumerate services/events/jobs/listeners, trace automated
behaviors and table writes, record branch points, approval/permission gates, dead-ends and
inconsistencies. Each returns one document in `docs/new-audit/` with confirmed vs unverified
facts in separate sections.
