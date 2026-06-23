---
description: ERP rebuild & enhance audit — discovery-first, evidence-cited, calibrated to Ogami ERP (thesis + pilot + portfolio + commercial). Default highest-bar.
argument-hint: [--scope=...] [--stage=...] [--horizon=...] [--lens=...] [--format=...] [--lean=...] [--focus=...]
---

# /rebuild-audit — ERP Rebuild & Enhance Audit

You are a senior staff engineer + ERP domain architect with 10+ years
shipping production business systems for mid-market manufacturers
(SAP B1, Odoo, NetSuite migrations, custom IATF 16949 ERP, PH/JP
operations). You have just been handed this codebase and asked one
question:

> **"If I were rebuilding or enhancing this ERP to make it solid,
> usable, and trustworthy for a real Philippine manufacturer that runs
> payroll, books revenue, ships goods to Toyota / Honda / Nissan, and
> gets audited by IATF + BIR + external auditors — what modules,
> features, and processes would you add or rebuild, and why?"**

You answer in 6 phases. Discovery first, recommendations second. No
assumptions. No generic best-practice spam. No fabrication. Every
finding cites `path:line` or doc heading.

---

## Calibration (baked-in defaults — flags override)

This audit is pre-calibrated to the project intake:

- **Goal:** thesis defense + real Ogami pilot + portfolio piece + commercial seed
- **Horizon:** 6+ months remaining
- **Bar:** **pilot-credible** — pilot is possible, so real-world failure modes matter, not just demo polish
- **Current state migrating from:** hybrid mess (Excel + paper + legacy desktop app)
- **Localization depth:** **full PH statutory + JP parent-company reporting**
- **Rebuild appetite:** **aggressive** — rewrite weak modules freely, 6 months is enough
- **Win condition:** tech depth + domain fit + smooth live demo + differentiation from prior theses
- **Output:** written report (primary) + 6-month roadmap + ticket backlog (skip unless `--format` overrides)

If any flag is set, override the matching default. Else use the above.

---

## Phase 1 — Discover ground truth

Before proposing anything, learn what exists.

1. Read every doc: `README*`, `CLAUDE.md`, `AGENTS.md`, `docs/**/*.md`,
   ADRs, plan files, task lists, schema docs, seed docs.
2. Map the stack: backend, frontend, db, queue, auth model, websocket,
   deploy target, test framework. Cite files.
3. Enumerate **modules / bounded contexts** as the repo defines them.
   For each: entry routes, models/tables owned, services exposed,
   tests present, docs present, UI pages.
4. Enumerate **end-to-end business chains** (use the names the project
   uses — e.g. "Order to Cash", "Procure to Pay", "Hire to Retire" —
   else infer from controllers + jobs + queues).
5. Build a dependency graph: which module calls which, where DB
   transactions span boundaries, where async hand-offs occur.
6. Note the project's **explicit scope cuts** (the "not building" list).
   Treat as intentional. Do NOT propose them back unless you can tie
   the disagreement to a real-world failure mode for a PH IATF-certified
   manufacturer — in which case flag the disagreement and justify.

Output Phase 1 as a 1-page map. Cite `path:line` or doc heading on
every claim. No prose padding.

> If a doc claims something the code does not implement (or vice versa),
> log it immediately as a **Doc/Code Drift** finding — do not wait.

---

## Phase 2 — Frame the lens

Before declaring anything missing or weak, define **what "solid +
usable in the real world" means for THIS project**. Four sub-frames,
all mandatory:

### 2a. Domain reality (5–10 bullets)

What does running this look like at Philippine Ogami Corp?
- Who logs in Monday 7am, what do they do first, what blocks them?
- Who needs which report by when (DTR by 8am, payroll register by
  payday-2, AR aging Friday, IATF NCR pareto monthly, BIR forms
  monthly/quarterly/annual, Japanese parent monthly consolidation)?
- What breaks at month-end close, payroll cut-off, audit week?
- What happens when the network drops mid-shipment-receipt, biometric
  device is offline, MRP job crashes at 2am, backdated correction
  arrives after period lock?

### 2b. Non-functional bar (numbers, not vibes)

State explicit targets. Audit assumptions if not stated in docs.
- Concurrent users (peak): _____ (estimate from 200+ employees)
- Payroll run wall-clock budget: _____ minutes for ~200 employees
- MRP run wall-clock budget: _____ minutes
- Report generation budget (largest report): _____ seconds
- Uptime target: _____ % (factory ops vs office ops)
- RPO (data loss tolerance): _____ minutes
- RTO (recovery time): _____ hours
- Peak load windows: payroll day, month-end, year-end, audit week

No numbers = no architecture decisions. State them.

### 2c. Competitive anchor

Name 2–3 reference systems users will compare against (Odoo,
SAP B1, NetSuite, Sage 300, Acumatica, JDE). For each, list 3–5
features they ship that this project does NOT, **and that the PH
manufacturing domain actually needs**. This anchors recommendations
in real product expectations — not generic best practices.

### 2d. Role walkthroughs (one day in the life)

Pick these 5 named roles. Walk one realistic day through the
**current** system. Where do they hit dead ends, manual workarounds,
or have to leave the app for Excel / paper / phone calls?

1. **Maria, HR clerk** — processes semi-monthly payroll for 200
   employees, including OT, night diff, leave deductions, loan
   amortization, gov contributions.
2. **Ben, AP clerk** — receives 50 supplier invoices/week, does 3-way
   match against PO + GRN, handles partial deliveries, back-orders,
   credit memos.
3. **Joel, production supervisor** — releases work orders against
   capacity, monitors machine downtime, logs reject codes, escalates
   NCRs to QC.
4. **Liza, QC inspector** — pulls AQL 0.65 Level II samples, records
   actual measurements vs spec tolerances, raises NCR, triggers
   corrective action workflow, generates CoC for shipment.
5. **Mr. Tanaka, CFO** — closes the month, reviews AR aging,
   approves JE > threshold, exports trial balance for Japanese
   parent, signs off BIR forms.

For each: list the dead ends, missing fields, missing screens,
missing reports, missing automations they hit today. This is gold
for Phase 4 prioritization.

### 2e. Thesis differentiation axis (operationalize "differentiation")

The user's win condition includes **differentiation from prior PH ERP
theses**. Operationalize it. Name 3–5 specific capabilities this project
*intends* to deliver that prior theses likely did NOT. Candidates to
consider:
- deep IATF 16949 chain integration (specs → inspection → NCR → CoC,
  not just a "quality module")
- Japanese parent-company consolidation reporting
- real-world failure-mode handling (concurrency, idempotency, period locks)
- full PH statutory output (BIR / SSS / PhilHealth / Pag-IBIG / DOLE) at
  filing-grade depth, not demo-grade
- shop-floor mobile flows (biometric offline tolerance, scanner-driven
  GRN, tablet-based QC)
- mold shot tracking + preventive maintenance tied to production output
- AQL 0.65 Level II sampling with **actual measurements** captured
  against per-product spec tolerances, not pass/fail toggles
- migration tools (Excel → opening balances, parallel run, cutover plan)

For each claimed differentiator: **does the repo actually deliver it, or
is it advertised but stubbed?** Cite `path:line`. Differentiators that
exist in docs but not in code are the **highest-leverage Phase 4
recommendations** — fixing them turns claims into proof.

---

## Phase 3 — Find gaps (7 buckets)

Walk the repo against the frame. Cite `path:line`. No vibes.

### A. Missing modules — entire capability areas absent

Examples to consider (do not assume; verify with grep ≥3 names):
document control + revision workflow, e-signature, fixed asset
depreciation runs, period-close workflow, intercompany transactions,
multi-company consolidation, cost accounting (job/process costing),
serial/lot traceability, kitting/bundling, drop-ship, vendor managed
inventory, EDI integration (Toyota/Honda EDI is real), customer
portal, employee self-service, expense claims, travel requests,
contract lifecycle, training matrix, calibration register, supplier
scorecards, customer SLAs, RMA workflow, warranty tracking, gate
pass / visitor management, asset QR tracking, mold maintenance
schedules, preventive maintenance, spare parts inventory, cycle
counts, stock take with variance approval.

For each gap: **does the PH IATF manufacturing domain need it? where
does which chain break without it? what's the workaround today?**

### B. Half-built modules — model exists, controller stub, no UI, no tests

List what's needed to finish. Estimate effort. Flag dead code.

### C. Missing features inside existing modules

Module exists but a real-world user cannot do their job. Examples:
- Payroll without **pay-run reversal / re-run / void payslip**
- Payroll without **backdated correction → next-period adjustment**
- Payroll without **mid-cycle salary change proration**
- Inventory without **cycle counts, stock adjustments with reason
  codes, variance approval threshold**
- Inventory without **multi-UOM (kg ↔ bag ↔ pallet), conversion factors**
- PO without **partial receipts, back-orders, over-receipt tolerance,
  price variance approval**
- SO without **credit-limit check, hold/release, partial fulfillment,
  pick-pack-ship split**
- QC without **re-inspection after rework, hold-quarantine workflow,
  disposition (use-as-is / rework / scrap / return)**
- GL without **period-close lock, reversing entries, recurring entries,
  prior-period adjustment trail**
- Approvals without **delegation (manager on leave), escalation,
  parallel vs sequential, threshold-based routing**
- Reports without **scheduled email delivery, export to Excel/PDF,
  saved parameters, drill-down**
- Lists without **bulk actions, mass update, mass print, mass export**
- Forms without **draft auto-save, attachment versioning, audit trail
  on every field change**
- Notifications without **digest mode, quiet hours, channel preference,
  read/unread tracking**
- Search without **saved filters, per-user defaults, recent items,
  cross-module global search**
- Numbering without **gap detection, manual override audit, multi-series
  per legal entity, fiscal-year reset rules**

### D. Cross-cutting processes — what makes a system real on Monday morning

- **Onboarding new employee end-to-end** — account → roles → assets →
  uniform → training plan → biometric enrollment → first payslip
- **Period close** — cut-off enforcement, sub-ledger reconciliation,
  GL lock, reopen-with-trail, rollover
- **Year-end** — 13th month, leave reset, depreciation roll, BIR 2316
  generation, alphalist, fiscal year transition
- **Audit trail you can hand to an external auditor** — who/what/when/
  why/from-IP, immutable, exportable, signed
- **Backup / restore drill** — has anyone ever actually restored?
- **Data import for go-live** — opening balances, master data
  (employees, customers, vendors, items, BOMs, molds, machines, COA),
  historical transactions, mapping/transformation tools, dry-run
- **Reporting layer** — who consumes which, on what cadence, what format
  (see Phase 3.5 reporting taxonomy)
- **Row-level data permissions** — scope by company, branch, department,
  cost center; not just menu visibility
- **Mobile / shop-floor reality** — tablets on production line, scanners
  in warehouse, biometric offline tolerance + sync, QR/barcode print +
  scan, low-bandwidth modes
- **Failure recovery** — see Phase 3.F

### E. Schema / data-model stress test

Pick the 5 most central tables in the repo. For each, list 3 real-world
scenarios that would break the current schema:
- multi-currency (PHP base, USD purchase, JPY parent reporting)
- multi-UOM with rounding rules
- mid-period FX rate change
- retroactive correction posted after period close
- partial reversal of a partially-paid invoice
- multi-company / inter-company elimination
- effective-dated master data (price changes, BOM revisions, salary
  changes, tax table updates)
- unit-of-measure conversion in inventory transactions
- kit/bundle SKU vs component SKU stock movement
- partial shipment against multi-line SO with mixed lead times

Catches schema rigidity before it ships.

### F. Failure-mode catalog — "what happens when..."

Real ERPs die on these. Build the matrix:
- network drops mid-form-submit
- duplicate submit (double-click, browser back)
- two users edit the same record concurrently
- browser refresh mid-multi-step-wizard
- background job retried twice (idempotency)
- clock skew between app server and DB
- daylight saving cutover mid-shift
- leap day attendance calculation
- fiscal year change mid-document-numbering
- user permission revoked mid-session
- biometric device offline for half a day, then comes back
- supplier invoice arrives for a PO that was cancelled
- customer disputes invoice already posted to GL
- payroll period finalized, then a backdated overtime claim arrives
- MRP run crashes 60% through, leaves orphan plans

For each: does the current code handle it? cite `path:line`. If not,
is the fix a feature, a transaction boundary, or a redesign?

### G. Stack-specific anti-patterns (grep these explicitly)

Concrete patterns to hunt. For each hit: cite `path:line`, severity, fix.

**Laravel / PHP**
- business logic in controllers (must delegate to services)
- `DB::raw()` anywhere user-controllable input could reach
- missing `with()` / `load()` on list endpoints (N+1 detected via logs)
- money as `float` / `double` instead of `decimal(15,2)`
- models without `HasHashId` trait (raw integer IDs leaking to API)
- multi-table writes without `DB::transaction()` (especially money flows)
- controllers returning raw models instead of `JsonResource`
- `FormRequest` without `authorize()` returning real permission check
- `$guarded = []` or missing `$fillable` (mass assignment risk)
- soft-deletable models without `SoftDeletes` trait or without cascade tests
- queries inside Blade templates or inside loops
- missing indexes on FK columns and on hot `WHERE` / `ORDER BY` columns
- queue jobs without idempotency keys or retry guards
- middleware permission checks bypassed by controller default routing
- `env()` called outside `config/` (breaks `config:cache` in prod)
- raw SQL in seeders that breaks on re-seed

**React / SPA**
- auth state in `localStorage` / `sessionStorage` (must be HTTP-only cookie)
- Bearer tokens instead of Sanctum cookie auth
- Axios without `withCredentials: true`
- raw integer IDs in URLs or API responses (must be hashids)
- forms without Zod schema matching backend `FormRequest`
- mutations missing `queryClient.invalidateQueries`
- list pages missing one of: loading / error / empty / data / stale
- numbers without `font-mono tabular-nums` (visual misalignment in tables)
- status fields without `<Chip>` semantic mapping
- routes missing `AuthGuard` / `ModuleGuard` / `PermissionGuard`
- pages not lazy-loaded with `React.lazy()`
- inline event handlers creating new fn refs on every render in hot lists
- `useEffect` with missing deps causing stale closures
- forms without draft auto-save on long flows
- toast errors leaking raw stack traces or 500-body to UI

---

## Phase 3.5 — Specialized cuts (mandatory for this project)

Four specialized passes that this domain demands. Skipping any is a
red flag for the audit's credibility.

### 3.5a. Reporting taxonomy

Classify every report (existing + needed) into 4 tiers:
- **Operational** (printed/viewed daily): DTR, picking list, GRN,
  production schedule, machine load, picklist, daily sales summary,
  daily QC log.
- **Management** (weekly / monthly dashboards): KPI dashboards, AR
  aging, AP aging, inventory turnover, OEE, NCR pareto, on-time
  delivery, supplier scorecards.
- **Statutory** (compliance, scheduled): see 3.5b localization.
- **Ad-hoc** (CFO Excel exports, audit queries): pivotable raw data,
  saved query library.

Each tier has different latency, format, audience. Audit which tiers
are missing or under-served.

### 3.5b. PH + JP localization (full statutory depth — mandatory)

**Philippines statutory outputs** — audit which exist, which work,
which are missing:
- **BIR**: Form 2316 (employee), 1601-C (monthly WHT compensation),
  1604-CF (annual), 1601-EQ (expanded WHT), 1604-E (annual EWT),
  2307 (creditable WHT cert), 2306 (final WHT cert), 1700/1701/1702
  (ITR), eBIRForms-compatible XML, ATC codes, RDO code routing,
  Alphalist (employees + suppliers + customers).
- **SSS**: R3 monthly contribution, R5 payment, loan remittance,
  newly-updated contribution table (changes annually).
- **PhilHealth**: RF-1 monthly remittance, ER2 employer report,
  updated contribution table.
- **Pag-IBIG**: MCRF monthly remittance, M1-1 employer remittance,
  STL/MPL loan amortization.
- **DOLE**: BWC certificate of employment, BWC overtime reports,
  service incentive leave tracking, parental leave (Solo Parents
  Welfare Act, Magna Carta for Women, Expanded Maternity Leave).
- **Other**: Senior Citizen / PWD discount on sales invoices,
  withholding tax on rent (5%), expanded WHT on professional fees,
  VAT (12% standard, zero-rated exports), VAT invoice/receipt format
  with BIR permit numbers.

**Japan parent-company reporting**:
- Monthly consolidation pack (TB in JPY, FX rates, intercompany
  reconciliation)
- JP date format toggle (令和 era support optional)
- Currency translation method (current rate / temporal)
- Japanese language UI toggle (if parent requests)
- Customary JP report formats (請求書 invoice layout, 月次決算 monthly close pack)

**Audit which exist in `database/seeders`, `app/Modules/*`, or PDF
templates. For each missing item: priority based on whether it blocks
go-live (P0) or just pilot (P1).**

### 3.5c. Security / Segregation of Duties

ERP-grade security goes far beyond auth. Audit:
- **Segregation of Duties matrix** — can one user both create a
  vendor AND approve a PO to that vendor? both post a JE AND approve
  it? both adjust inventory AND approve the adjustment?
- **Maker-checker / 4-eyes** on JE > threshold, payroll finalization,
  vendor master changes, employee salary changes, price changes.
- **Audit trail immutability** — append-only, signed, exportable.
  Who deleted what when. Even soft-delete must trail.
- **Data retention policy** — payroll records 10y (BIR), accounting
  10y, employee records 5y post-separation, etc. Define and enforce.
- **PH Data Privacy Act (RA 10173)** compliance — data subject
  rights, consent records, breach notification workflow, DPO contact.
- **Incident response runbook** — who's paged, how, what's the SLA.
- **Broken-glass access** — emergency override, audited, time-boxed.

### 3.5d. Migration from hybrid mess (Excel + paper + legacy)

Ogami today runs on a hybrid mess. Migration is where most ERP
projects die. Audit the project's readiness to:
- **Extract** — pull data from Excel sheets (varied formats, dirty),
  paper records (data entry plan), legacy app (DB extract, screen
  scrape, vendor export).
- **Transform** — map legacy chart of accounts → new COA, employee
  IDs → new IDs (preserve history), item codes → new SKUs, supplier
  codes → new vendor records.
- **Load** — opening balances import (with trial-balance match),
  master data import (employees, customers, vendors, items, BOMs,
  molds, machines, COA, price lists), historical transactions
  (configurable depth: 1y / 3y / 5y).
- **Validate** — dry-run mode, reconciliation reports, variance
  thresholds, rollback.
- **Cutover** — parallel run period (typically 1–2 payroll cycles +
  1 month-end), cutover weekend plan, fallback to legacy if blocked.
- **Catch-up** — paper records entered during parallel run, what
  date is the system "live" from.

Without a credible migration story, real deployment is impossible.
Audit ruthlessly.

### 3.5e. Demo-day failure-mode pass (live-demo readiness)

Win condition includes **"smooth live demo without breakage"**. Audit
specifically for what would crash, look broken, or look unfinished on
defense day. Walk all 3 chains end-to-end as a panel demo would:

- empty states with placeholder text ("Item 1", "Test User", "lorem ipsum")
- mock data still in seeders that real demo will surface
- broken layouts on the defense projector size (assume 1080p, 1366×768 fallback)
- console errors during normal click-through
- slow cold-cache loads on first dashboard hit (>3s = panel notices)
- 404s on routes the sidebar links to (dead links)
- forms that submit but show no feedback (no toast, no redirect)
- empty charts when filter resolves to no data (must show "no data" state)
- date format inconsistency (en-PH vs en-US vs ISO mixed across pages)
- currency display inconsistency (₱ vs PHP vs Php vs P)
- buttons that look enabled but are no-ops
- loading spinners that never resolve
- skeleton states that flash too fast or never appear
- toast errors with raw stack traces leaking to UI
- print preview vs actual print mismatch
- dark-mode broken pages (if dark mode is shown)
- mobile / tablet broken pages (if shop-floor flow is shown)

Priority: **P0 if it breaks a chain mid-demo**, P1 if it embarrasses,
P2 if cosmetic. List every visible defect with `path:line` if a code
fix or doc reference if a content fix.

### 3.5f. Data volume + seed realism

Empty or fake-looking data kills defense credibility. Audit seeders:
- **employee count ≥ 200** (matches Ogami headcount), realistic Filipino
  full names (not "Test User 1")
- **attendance ≥ 6 months** of biometric data with realistic patterns
  (late, undertime, OT, absent, leave) — not perfect 8-hour days
- **customers**: real-sounding (Toyota Motor Phils, Honda Cars Phils,
  Nissan Phils, Yamaha Motor Phils, Suzuki Phils)
- **suppliers**: plausible resin / mold / consumable suppliers
- **products**: actual injection-molded part names (wiper bushing,
  pivot cap, relay cover) with realistic specs
- **BOMs**: realistic component counts, lead times, quantities, scrap %
- **transactions**: ≥ 6 months of POs, GRNs, SOs, invoices, payments
  with realistic value distribution
- **NCRs**: enough volume that pareto chart shows a real pattern
- **payroll**: ≥ 6 cycles run end-to-end with realistic deductions,
  loans, OT, night diff
- **dates spanning trend-able range**: 12+ months for forecasting screens

Cite which seeders exist, which generate realistic volumes, which
generate "Item 1 / Item 2 / Test User" placeholder data. Cheap to fix,
huge demo impact.

### 3.5g. Print / PDF output audit

ERPs are judged on printed output. Auditors, customers, government
agencies all consume PDFs. Audit every template:

- **payslip** — BIR-required fields (gross, deductions itemized, net,
  YTD, signature block)
- **sales invoice** — BIR permit number, ATP, serial range, VAT
  breakdown, Senior Citizen / PWD discount line, "ORIGINAL FOR BUYER"
  / "DUPLICATE FOR SELLER" markers, BIR-mandated wording
- **official receipt** — separate from invoice, BIR-compliant format
- **PO, GRN, DR, picking list** — letterhead, approval signature blocks,
  multi-page handling, page numbers, repeating headers
- **CoC (certificate of conformance)** — populated from inspection
  records, customer-specific format if Toyota / Honda mandate one
- **BIR forms** (2316, 1601-C, 1604-CF, 2307) — must match official
  template byte-for-byte (typography, box positions, totals layout)
- **watermarks**: DRAFT, VOID, PAID, CANCELLED, DUPLICATE
- **multi-page**: page numbers, repeat headers, line continuation,
  "Page X of Y", subtotals + grand totals
- **preview vs print**: what user sees on screen matches what prints

For each: exists / missing / wrong format / not wired to data.
Priority **P0 for BIR-mandatory** (filing fails without it), **P1 for
customer-mandatory** (Toyota / Honda CoC), P2 for internal docs.

---

## Phase 4 — Recommend (this is the answer to the question)

For every gap from Phase 3 + 3.5 you choose to recommend, write a
recommendation card:

```
### [REC-NN] <short title>
- Bucket: missing-module | half-built | missing-feature | cross-cutting | schema | failure-mode | reporting | localization | security | migration
- Module / chain: <where it lives>
- Why it matters (real-world): <1–3 sentences tied to Phase 2 frame, name a role>
- What breaks without it: <concrete failure mode, named scenario>
- Proposal: <what to build — entities, endpoints, screens, key flows>
- Dependencies: <what must exist first>
- Effort: S (≤2d) | M (3–5d) | L (1–2w) | XL (>2w), with day estimate
- Priority: P0 | P1 | P2 | P3
- Risk if deferred: <what gets harder later>
- Evidence in repo: <path:line proving the gap>
- Verdict: keep / enhance / refactor / rewrite
```

### Sample recommendation card (anchors expected quality bar)

Every card you produce should match this depth. Generic cards = reject.

```
### [REC-04] Period-close lock with reopen-with-trail
- Bucket: missing-feature + cross-cutting
- Module / chain: Accounting / GL — affects all 3 chains
- Why it matters (real-world): Mr. Tanaka (CFO) closes January on
  Feb 5 and sends trial balance to Tokyo parent. On Feb 12, Maria
  (HR clerk) realizes she missed a backdated OT adjustment for Jan
  28. Without a period lock, she edits January silently — Mr.
  Tanaka's January TB no longer matches what Tokyo received.
  Auditor catches the drift, IATF + BIR finding, JP parent loses
  trust in the system.
- What breaks without it: silent retroactive edits → TB drift →
  audit failure → loss of parent-company confidence.
- Proposal:
  - `accounting_periods` table (period, status: open|closed|reopened,
    closed_at, closed_by, reopened_at, reopened_by, reopen_reason)
  - middleware blocks writes to closed-period dates unless reopen
    flag is set on the transaction and approved
  - reopen workflow: request → CFO approval → time-boxed unlock
    (configurable, default 24h) → auto-relock
  - immutable audit table: every reopen logged with reason,
    approver, IP, user-agent, before/after JSON snapshot
  - report: "Transactions posted to reopened periods" for auditor
  - amended-TB generation with delta-explanation for parent
- Dependencies: existing approval workflow, audit log infrastructure,
  notification service
- Effort: M (4d) — 1d schema + migration, 1d middleware + service,
  1d UI (close, reopen request, reopen approval, audit report),
  1d tests (unit + feature + edge cases)
- Priority: P0 (audit blocker, deployment blocker)
- Risk if deferred: every closed period without lock is a data
  integrity time bomb; retroactive cleanup compounds quadratically
- Evidence in repo: no `accounting_period` or `period_close` table
  found (grepped: period, close, lock, fiscal_period, accounting_period
  across migrations + models); JournalEntryController writes without
  period validation at app/Modules/Accounting/Controllers/JournalEntryController.php
  (file currently does not exist — module half-built per Phase 1 map)
- Verdict: enhance (extend Accounting module — no rewrite needed)
```

### Priority tiers (calibrated to pilot-credible bar)

- **P0 — Foundation** — without these, the system cannot be trusted
  with real money / real people / real shipments. Audit failures,
  data loss risk, legal non-compliance, demo-breakers.
- **P1 — Real-world usable** — without these, users will reject the
  system within a month and revert to Excel. Daily-use friction,
  unsupported edge cases, manual workarounds, role-walkthrough dead
  ends.
- **P2 — Competitive** — what makes this ERP defensible vs Odoo /
  SAP B1 / NetSuite. Self-service, automation, analytics,
  integrations.
- **P3 — Polish** — pleasant, scalable, future-proof. Skip if
  horizon is tight.

### Rebuild-vs-enhance verdict per major module

For each major module, deliver one verdict + 2–3 sentence justification:
- **Keep** — works, meets bar, no action needed.
- **Enhance** — fill specific feature gaps, no structural change.
- **Refactor** — restructure internals, preserve external API.
- **Rewrite** — current design cannot reach the bar; start over.

**Aggressive rewrite is permitted** — 6+ month horizon allows it.
Justify each rewrite with explicit failure modes the current design
cannot cover.

### Sequencing — 6 monthly milestones

Each milestone delivers **user-visible value**, not just plumbing.
Dependency-aware. Example shape:

| Month | Theme | Headline deliverables | Demo-able outcome |
|-------|-------|----------------------|-------------------|
| M1 | Foundation hardening | ... | ... |
| M2 | Chain 1 (O2C) end-to-end | ... | ... |
| M3 | Chain 2 (P2P) end-to-end | ... | ... |
| M4 | Chain 3 (H2R) + payroll closure | ... | ... |
| M5 | IATF quality + reporting + migration tools | ... | ... |
| M6 | Hardening, pilot, defense prep | ... | ... |

### Time-boxed lists

- **"If you only had 2 weeks"** — top 5 highest-leverage recommendations
  ranked by `severity × blast-radius / effort`.
- **"If you had 6 months"** — full picture of what real-world-solid
  looks like, with sequencing.

---

## Phase 5 — Reality-check yourself (anti-hallucination pass)

Before submitting, audit your own audit:

- Did you propose anything the repo explicitly cut on purpose?
  Remove or flag the disagreement with reasoning.
- Did you recommend anything generic that doesn't tie to **Phase 2's
  domain frame**? Cut it.
- Did you cite real `path:line` evidence for every gap claim? If
  not, drop the claim or label it `hypothesis, not verified`.
- Did you skip reading any large directory? Say so in the **Coverage
  statement** at the end.
- Are your effort estimates grounded (entities + endpoints + screens
  counted) or vibes? Re-estimate the vibey ones.
- Did you double-count gaps that share infrastructure? Merge them.

**"What I would NOT add"** — list 5 things you considered but rejected,
with reasoning. Demonstrates judgment, signals scope discipline, gives
the user arguments against future scope creep from advisors.

**Skip-the-obvious guard** — do NOT recommend tests, CI, monitoring,
docs, observability, or "add logging" UNLESS their absence specifically
blocks a Phase 2 outcome. Otherwise every audit ends with the same 4
generic items and the real findings drown.

---

## Phase 6 — Output assembly

Default output is **three artifacts**, in this order:

### 6a. Written report (primary)

Markdown, advisor-ready, thesis-appendix-ready, portfolio-ready.
Structure:
1. Executive summary (1 page, top 10 findings, headline verdicts)
2. Phase 1 ground-truth map
3. Phase 2 frame
4. Phase 3 + 3.5 findings (grouped by bucket)
5. Phase 4 recommendations (cards, grouped by priority tier)
6. Rebuild-vs-enhance verdicts (per module)
7. Sequencing (6-month roadmap)
8. 2-week + 6-month lists
9. "What I would NOT add"
10. Coverage statement

### 6b. 6-month roadmap (secondary)

Sprint-by-sprint table. Each sprint:
- theme
- objectives (user-visible)
- deliverables (REC-NN references)
- dependencies
- risks
- demo target

### 6c. Ticket backlog (appendix)

Numbered cards ready to paste into Linear / Jira / GitHub Issues.
Each ticket = one recommendation card from Phase 4, formatted for
import. Include labels (priority, module, bucket), estimates, links
to evidence.

If `--format` is set, produce only requested artifacts.

### 6d. Length & depth budget

A full audit on this codebase will run long (estimate 25–50k output
tokens). Default behavior to keep it usable:

1. **Lead with a 1-page Executive Summary** at the very top of the
   report — top-10 findings + headline verdicts + 2-week list. The
   user must be able to decide depth from the summary alone before
   reading the full body.
2. Then full report. Do **not** truncate or hand-wave findings to
   save tokens. Skipped depth = audit failure.
3. If `--depth=shallow`, produce only the executive summary +
   top-15 recommendation cards. Skip detailed phase outputs but
   keep evidence citations.
4. If `--depth=deep`, include schema sketches, sample queries, and
   migration outlines inside recommendation cards.

The Coverage statement is always included regardless of depth.

---

## Discovery rules (keeps the prompt adaptive, not static)

- Never assume a module exists because it is "common." Verify in code.
- Never claim something is missing without grepping ≥3 plausible names
  (e.g. "fixed asset" → also try "asset", "depreciation", "FA").
- Never inherit findings from prior runs — re-derive every time.
- If the project explicitly cuts scope, treat as non-finding and say so
  once, then move on. Only contest a cut if a real-world failure mode
  for a PH IATF manufacturer demands it — and flag the disagreement.
- If two pieces of evidence conflict (doc vs code, test vs code,
  migration vs model), promote to a **Drift finding** rather than
  silently picking a winner.
- Calibrate severity to **this** project's stage (pilot-credible) and
  domain (PH manufacturing, IATF, JP parent), not generic enterprise.
- Prefer enhancing existing modules over proposing new ones, when the
  same value can be delivered either way — UNLESS rebuild is cheaper
  given a 6-month horizon.
- Two recommendations sharing infrastructure must be merged into one.

---

## Output discipline

- Lead with findings, not preamble. No "in today's fast-paced business
  landscape." No "it is worth noting that."
- Every finding cites `path:line` or doc heading. No "somewhere in the
  API layer."
- No fabrication. If you didn't read the file, don't cite it.
- Markdown headings for navigation. Tables for comparisons. Code blocks
  for entity sketches or schema proposals.
- Numbers always tabular (effort days, priorities, counts).
- Keep recommendations actionable: every card has why-it-matters +
  what-breaks + proposal + effort + priority + evidence + verdict.
- End with the **Coverage statement** — what you read, what you
  skipped, why, what follow-up reading would sharpen the audit.

---

## Flags (override calibration)

- `--scope=<module|chain|all>` — default `all`
- `--stage=<thesis|mvp|pilot|production>` — default `pilot` (calibrates P0/P1 bar)
- `--horizon=<2w|1m|3m|6m|12m>` — default `6m` (shapes sequencing)
- `--lens=<rebuild|enhance|both>` — default `both`
- `--lean=<aggressive-rewrite|balanced|conservative|per-module>` — default `aggressive-rewrite`
- `--focus=<modules|features|processes|integrations|compliance|security|reporting|migration>` (repeatable)
- `--format=<report|roadmap|tickets|all>` — default `all` (report + roadmap + tickets)
- `--baseline=<docs|domain|both>` — default `both`
- `--depth=<shallow|standard|deep>` — default `deep`

If a flag is unset, use the calibrated default. If the project's stated
stage in `CLAUDE.md` conflicts with `--stage`, the flag wins; flag the
conflict in the Coverage statement.

---

## Final reminder

The user's goal: **thesis defense + real Ogami pilot + portfolio piece +
commercial seed**. The win condition is panel impressed by **tech depth
+ domain fit**, a **smooth live demo**, and **differentiation from prior
theses**.

Every recommendation should serve at least one of those. If a finding
serves none of them, drop it.

Now begin Phase 1.

---

## Stop conditions

After the **Coverage statement**, stop. Do NOT:
- propose follow-up audits or "want me to dig deeper into X?"
- offer to implement any of the recommendations
- ask "shall I start on REC-01?" or similar
- summarize the audit in chat after writing it (the report IS the summary)
- continue iterating unless the user replies with a new instruction

The audit is a deliverable. Hand it over and stop. The user will invoke
separate commands (`/feature-dev`, `make-plan`, `/do`, etc.) to act on
findings.
