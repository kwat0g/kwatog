# OGAMI ERP — Live Demo Script (Panel Defense)

> ~15-minute walk-through for the thesis panel. Follows the strongest possible
> narrative: the three business chains, then the single end-to-end IATF 16949
> traceability trace that ties everything together.
>
> **Before the demo:** run the demo seeder so every screen has data:
> ```
> docker compose up -d
> docker compose exec api php artisan db:seed --class=GoldenPathDemoSeeder
> ```
> Log in as `admin@ogami.test`. Companion: `docs/DEFENSE-TRACEABILITY.md` maps
> each adviser item to its screen/route/test.

---

## 0. Opening (1 min) — what it is
- Production ERP for Philippine Ogami (Japanese plastic-injection molder, Toyota/Nissan/Honda supplier, IATF 16949 certified).
- Three end-to-end chains: **Order-to-Cash**, **Procure-to-Pay**, **Hire-to-Retire** — quality woven through, not bolted on.
- Stack: React 18 SPA + Laravel 11 API, PostgreSQL, cookie-based auth, HashID-obfuscated URLs.
- Point at the **Dashboard** — live KPIs, chain-stage breakdown, alerts.

## 1. Security posture (1 min) — say while on any page
- No bearer tokens; Sanctum SPA cookie session (open dev-tools → session cookie is `HttpOnly`, JS can't read it).
- URLs show `yR3kLm`, never integer IDs.
- Government IDs encrypted at rest; non-HR users see masked `***-**-4567`.
- Every action permission-checked **server-side** — frontend guards are UX only.

---

## 2. Order-to-Cash chain (2 min)
1. **CRM › Sales Orders** — open a confirmed SO. Show the ChainHeader (SO → MRP → WO → QC → Delivery → Invoice).
2. **Production › Work Orders** — open a WO → **Batch section**: `BATCH-…` number + the material-lot references (this is ADV3, the traceability seed).
3. **Supply Chain › Deliveries** — open the delivered one → **Proof of Delivery** (receiver, signed DR) — *ADV7*.
4. **Accounting › Invoices** — the auto-generated invoice off the confirmed delivery.

## 3. Procure-to-Pay chain (2 min)
1. **Purchasing › Chain** (`/purchasing/chain`) — the overview: PRs → POs → GRN → Bills, with counts. *ADV5.*
2. **Purchasing › Purchase Requests** — show a **template** (`/purchasing/pr-templates`) and note MRP auto-creates draft PRs with preferred supplier. *ADV6.*
3. **PO detail › Billing** — GRN received → **3-way match ✅** → Create Bill → Record Payment. *ADV5.*

## 4. Hire-to-Retire chain (2 min)
1. **HR › Employees** — 200 seeded employees; open one (masked gov IDs for non-HR).
2. **Payroll › Periods** — open the disbursed period → **Disbursement Proof**: *Status ✅ Disbursed*, BDO confirmation, amount, ref. *ADV1.*
3. Mention maker-checker: computed_by ≠ approver (separation of duties, enforced + tested).

---

## 5. THE FLAGSHIP TRACE (3 min) — strongest IATF proof
Go to **Quality › Traceability** (`/quality/traceability`). Search the batch number from step 2.2. Walk the chain out loud:

```
Customer complaint → RMA → Shipment Lot LOT-… → Batch BATCH-… → QC inspection
   → Work Order → Material Issue → GRN + supplier lot SL-TW-0234 → Supplier
```

> "The part the customer returned traces to this lot, this batch, produced on
> this machine using Resin A from this GRN — supplier Taiwan Plastics, supplier
> lot SL-TW-0234, QC-passed on receipt. One click, full genealogy — that is the
> IATF 16949 traceability requirement."

**Verified search terms (all three resolve to the full trace — try any):**
- Batch: `BATCH-20260709-0001` → machine IMM-01, mold M-WB-001, 9955 good / 45 rejected, passed outgoing QC, 2 shipment lots
- Shipment lot: `LOT-20260709-0002`
- Material lot: `MLOT-20260703-01` → GRN-20260704-0001, supplier lot SL-TW-0234


Then **Return Management** (`/return-management`) — open the RMA → disposition → **Credit Note** (`/accounting/credit-notes`) reduces AR. *ADV12.*

---

## 6. Supporting modules — quick hits (2 min)
Click through, one line each:
- **Budgeting** (`/budgeting`) — FY2026 per-department allocated/spent/%. Maintenance sits at 🔴 **98% critical**. *ADV9.*
- **Forecasting › Demand** (`/forecasting/demand`) — moving-average projection; **Stock-out** page → projected stock-out date → Create PR. *ADV11.*
- **Warehouse Map** (`/inventory/warehouse-map`) — bin-level, color-coded; **Stock Count** freezes a zone, variance → sign-off. *ADV8.*
- **B2B Portals** — open `/portal/supplier` in a second tab: supplier sees only their own POs (separate auth guard, cross-guard isolation is tested). *ADV10.*
- **Admin › Roles** — create a "Line Supervisor" role live, grant 4 permissions, note dynamic RBAC. *ADV4.*

---

## 7. Close (1 min)
- All 12 adviser items implemented end-to-end (backend + SPA + automated tests) — hand panel `docs/DEFENSE-TRACEABILITY.md`.
- Full automated test suite green (state the pass count from `docs/TEST-COVERAGE-REPORT.md`).
- Scope discipline: cut cost-accounting, bank-rec, tax calendars deliberately — thesis ships a coherent, production-grade core.

---

## Panel Q&A — likely questions + where to click
| Question | Answer / screen |
|---|---|
| "Show me traceability of a defective part." | Quality › Traceability → search batch → the flagship trace. |
| "How do you prove salaries were paid?" | Payroll › Periods → disbursed period → Disbursement Proof. |
| "How do you prevent one person paying themselves?" | Payroll maker-checker (computed_by ≠ approver) — `PayrollMakerCheckerTest`. |
| "Can a supplier see another supplier's data?" | No — separate guard; `PortalTokenCrossGuardTest` proves it. |
| "What stops going over budget?" | Budget enforcement on PR/PO; near-critical dept shown at 98%. |
| "Is this just screens, or does it work?" | Point at the test suite count + `docs/DEFENSE-TRACEABILITY.md` test column. |

## If something looks empty mid-demo
Re-run the seeder (idempotent, safe):
`docker compose exec api php artisan db:seed --class=GoldenPathDemoSeeder`
