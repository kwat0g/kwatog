# Friday Demo Hardening — Design

**Date:** 2026-08-11 (Tuesday)
**Demo:** Friday 2026-08-14, on the VPS deployment
**Working time:** Tuesday evening, Wednesday, Thursday
**Demo mode:** free clicking — the panel takes the mouse; all 13 roles, all modules
**Objective:** make every module and every role survive unscripted human clicking, then freeze.

---

## 1. Problem statement

The backend is well tested (1,564 backend tests, 5,431 assertions; PHPStan clean;
`make chain-smoke` and `make worker-recovery-smoke` both pass). A week of failure-path
auditing produced `docs/PROCESS-FAILURE-MATRIX-2026-08-11.md`, which closes the
duplicate/concurrency/recovery questions across every audited boundary.

None of that is what a panelist sees on Friday. Free clicking exposes a different
class of defect:

- an empty table reads as a broken module;
- a record whose detail page shows no children reads as fake;
- a role with no pending work reads as a database, not a business;
- a route that 403s or renders blank reads as unfinished.

Measurement against the live database on 2026-08-11 confirms this is the dominant
risk, not logic bugs.

### 1.1 Measured state

Populated and demo-ready: `attendances` 22,069 · `payrolls` 1,200 · `employees` 200 ·
`non_conformance_reports` 48 · `accounts` 52 · `government_contribution_tables` 126 ·
`molds` 15 · `audit_logs` 7,679.

Empty (0 rows) on surfaces a panelist will open:

| Surface | Tables at 0 | Why it matters |
|---|---|---|
| Approval inbox | `approval_records` | 4-level approval chain is a thesis centerpiece; 17 workflow definitions exist, zero pending items |
| Inventory ledger | `stock_movements`, `stock_adjustments` | 12 stock levels with no movement history; every stock card is empty |
| Leave balances | `employee_leave_balances` | 33 leave requests exist against zero balances |
| Shift assignment | `employee_shift_assignments` | 22,069 attendance rows computed against zero assignments; `employees` has no shift column |
| Training / IATF | `employee_trainings`, `trainings`, `skills`, `calibration_records` | T3.4 training matrix and calibration show nothing |
| Attention surfaces | `alerts`, `action_center_tasks`, `activity_events` | three separate "what needs me" screens, all blank |
| Production depth | `work_order_outputs`, `wo_operations`, `production_logs`, `machine_downtimes`, `material_reservations`, `production_schedules` | OEE and downtime analysis have no inputs; only 3 WOs, all `planned` |
| Chain 1 tail | `collections`, `official_receipts` | order-to-cash stops at invoice, never reaches cash |
| Chain 2 tail | `bill_payments` | procure-to-pay stops at bill |
| Accounting | `accounting_periods` | period lock has no periods to lock |
| IATF quality | `ppap_submissions`, `complaint_8d_reports` | 1 complaint with no 8D |

Also: `failed_jobs` = 4 (a red number if any sysadmin screen surfaces it).

### 1.2 Provenance defects (the sharpest risk)

Three data contradictions that a detail-page click exposes:

1. **Orphan invoices.** All three rows in `invoices` have zero `invoice_items`, and
   NULL `delivery_id`, `sales_order_id`, and `journal_entry_id`. `INV-20260811-0002`
   is `paid` with `balance = 0.00`; `INV-20260811-0003` is `partial` with ₱38,000
   outstanding — yet `collections` is empty, so nothing records the money moving.
   These were inserted directly, not produced by the chain. This is money, it is the
   tail of Chain 1, and the lie is invisible on the list page and obvious on the
   detail page.
2. **Leave without balances.** 33 leave requests (31 approved, all 2026) against zero
   `employee_leave_balances`.
3. **Attendance without shifts.** 22,069 attendance rows against zero
   `employee_shift_assignments`.

The prior plan (`docs/superpowers/plans/2026-08-11-demo-readiness-hardening.md`,
Round 2 Task 4) already required the hero invoice be "a draft produced from a
genuinely confirmed delivery, not an orphan/directly fabricated invoice." That task
was never built.

### 1.3 Work already finished but unmerged

`demo-hardening/integration` is 10 commits ahead of `main` with all three Round 1
tasks complete and tested (946 insertions, 18 files):

- terminal listener failure preserved + bank-file storage fails closed;
- accounting `lockForUpdate()` reload for invoice finalize/collection, bill pay, JE reverse;
- receiving-vs-QC authority split + RMA disposition gated on passed inspections.

Round 2 was never built: `r2-5-mrp` and `r2-6-wo-output` carry only Round 1 commits;
no `MrpEngineService` or `WorkOrderOutputService` changes exist. Task 4 has no branch.

**Merge hazard:** the branch predates `docs/superpowers/plans/2026-08-11-role-dashboard-pack.md`,
so a naive merge deletes 3,069 lines of it. Must rebase.

**Worktree fragility:** the six worktrees live under `/tmp/kwatog-demo-hardening.8nzJuQ/`.
Branches are safe in `.git`; worktree metadata is not reboot-safe.

---

## 2. Coverage contract

The single testable rule governing all work:

> For every module, every role that can reach it must find: (a) a populated list,
> (b) at least one record whose detail page shows real children, (c) at least one item
> **in flight awaiting that role's action**, and (d) no blank-or-403 dead end on any
> route in its sidebar.

Clause (c) is what makes the system read as a business rather than a database. It
currently fails for nearly every role: `approval_records` is 0, so no approver has an
inbox; `alerts` and `action_center_tasks` are 0, so nobody has a to-do list.

### 2.1 Provenance rule

In-flight and completed records are produced by **driving real domain services**, never
by direct insert:

- `DeliveryService` → `InvoiceService::finalize()` → `InvoiceService::recordCollection()`
- `ApprovalService` for every pending approval
- `WorkOrderOutputService::record()` for production output (idempotent; mold shots + scrap)
- `InspectionService::recordMeasurements()` for QC measurements
- `NotificationService::send()` for notification state

A fabricated row survives a list page and dies on a detail page — precisely where a
curious panelist goes. Driving the services also makes the chain demonstrate the thesis
claim while it seeds.

### 2.2 Additivity rule and its one carve-out

Seeding is additive: stable natural keys, `firstOrCreate`, no truncation, no
`migrate:fresh`, no `db:wipe`, no reset.

**Carve-out — decided: repair, not leave-in-place.** The three orphan invoices and their
fabricated `paid`/`partial` statuses are replaced, because they assert money movement no
`collections` row supports. Leaving them and seeding correct invoices alongside was
considered and rejected: a panelist sorting the invoice list by date still reaches the
bad rows, and AR is money at the tail of Chain 1.

Repair procedure, in order:

1. User takes and validates a backup (`scripts/db-backup.sh`).
2. The replacement chain is built and proven in an isolated test database first: a
   confirmed delivery → `InvoiceService::finalize()` → `recordCollection()` for the paid
   and partial cases, producing real `invoice_items`, `collections`, and
   `journal_entry_id` links.
3. The three fabricated rows are voided or removed by a reviewed, logged repair — the one
   non-additive operation in this plan.
4. User executes against the demo database; no agent runs it.
5. `demo:verify` confirms zero orphan invoices remain and every `paid`/`partial` status
   is backed by a `collections` row.

---

## 3. Scope

### In scope

1. Rebase and merge `demo-hardening/integration` onto `main`; confirm the dashboard plan doc survives.
2. Repair the three provenance defects (§1.2).
3. Seed every empty headline surface from §1.1 additively, via domain services.
4. Sweep all 234 routes × 13 roles for dead ends; fix by frequency.
5. Clear the 4 `failed_jobs`.
6. **MRP rerun safety** (§3.1) — in scope because the trigger is a visible button.
7. Deploy to VPS with a tagged rollback point; rehearse there.

### 3.1 MRP rerun safety — why this is in scope

`spa/src/pages/mrp/plans/index.tsx:108` renders a **"Run MRP now"** button. It POSTs to
`/api/v1/mrp/runs` (`MrpRunController::store`), which calls
`MrpEngineService::runForAllActiveSalesOrders` — every active SO, not one. Permission is
`mrp.runs.trigger`, held by PPC Head and system_admin. The same engine also runs daily at
06:00 via `mrp:run-daily`.

`MrpEngineService::runForSalesOrder` (`:203-260`) creates one consolidated draft PR plus
one draft WO per SO line on **every** invocation. It supersedes the prior plan
(`:74-79`) but never reuses or cancels that plan's children. There is no guard against
repeated presses.

Current state: 12 sales orders (3 confirmed, 9 draft), 5 purchase requests, 3 work
orders. Two presses during a free-click demo put visibly duplicated draft PRs in the
purchasing queue and duplicated planned WOs in production — the exact
"duplicated/stuck process" failure the demo is meant to disprove, behind the most
tempting button on the MRP page.

This reverses an earlier deferral. The original reasoning — "a rerun is not reachable by
hand-clicking" — was factually wrong.

**Scope note:** the prior plan's Round 2 Task 5 targeted `runForSalesOrder`. The button
reaches it through `runForAllActiveSalesOrders`, so the fix belongs in
`runForSalesOrder` (covering both entry points) but the regression test must exercise
the button's path — repeated `POST /api/v1/mrp/runs` — not just the per-SO method.

**Fix shape:** reconcile new requirements against only the superseded plan's
`is_auto_generated=true, status=draft` PRs and `status=planned` WOs — reuse compatible
rows, cancel eligible surplus, create only what is missing. Never repoint, cancel, or
rewrite PRs or WOs that have progressed (submitted, approved, released, in-progress,
completed) or that were created manually.

### Deferred, with reasoning

| Deferred | Why it is safe to defer for a hand-clicked demo |
|---|---|
| Round 2 WO cache-key namespacing | Requires an idempotency-token collision across two WOs; not reachable by clicking |
| Full Playwright suite repair | The targeted sweep (§4) covers the demo surface |
| Repository-wide Pint (1,531 issues / 2,165 files) | Style only; mass-formatting an unrelated dirty surface adds risk |
| Static-audit noise cleanup | Known noise; recorded, not silenced |
| Blob disaster recovery, final-pay loan settlement, maintenance multi-MWO | Carried forward from the prior plan's deferred list |

---

## 4. The coverage solution

234 routes × 13 roles = **3,042 role-route pairs**. Solved in three layers, cheapest
first, so the expensive layer runs only against what the cheap layers could not decide.

**Layer 1 — static (seconds, no page loads).**
Parse `PermissionGuard permission="…"` and `ModuleGuard module="…"` from the 23 files in
`spa/src/routes/`, cross-join against `role_permissions` in PostgreSQL. Produces the
complete 3,042-cell expected-access matrix analytically. Every 403 is known before a
browser opens. Any route reachable by **no** role is a dead feature — reported separately.

**Layer 2 — API (~2 minutes).**
For each role, call the list endpoint behind each reachable route with a session cookie;
record HTTP status and `data[]` length. This is the layer that distinguishes *empty
because no data* from *empty because broken* — the distinction a browser sweep cannot
make, and the one that matters most given §1.1.

**Layer 3 — browser (~35 minutes, shardable by role).**
Reuse the `pushState` SPA navigation from `scripts/role-permission-audit.js` (~0.65s per
route, no full page reload) over each role's reachable subset. Catches render crashes,
console errors, and missing next-action buttons.

3,042 pairs × 0.65s ≈ 33 minutes single-threaded, less when sharded across browser
contexts. **Full coverage is achievable** in under an hour of machine time and
approximately zero human hours.

### 4.1 Existing tooling to extend, not rebuild

| Script | What it already provides | What it lacks |
|---|---|---|
| `scripts/role-permission-audit.js` | all 13 accounts (`admin@ogami.test` … `driver@ogami.test`, password `password`), `AUDIT_ROLES` filter, `pushState` SPA navigation | only 6 hardcoded surfaces; no full route cross-join |
| `scripts/live-spa-route-audit.js` | route discovery across all 23 route files, console-error and HTTP≥400 monitoring, guest-401 suppression | single session; no role dimension |
| `scripts/dynamic-spa-route-audit.js` | `:id` fixture-endpoint resolution for ~25 parameterized routes | not role-crossed |
| `scripts/spa_route_audit.py` | SPA API paths vs the real Laravel route table | static only |

The sweep is **read-only by construction**: it issues GET navigation and list reads only
— never a form submit, POST, PUT, PATCH, or DELETE. Login (which writes a `sessions` row)
and audit-log rows produced by reads are the only permitted writes. This keeps it inside
the no-destructive rule and makes it safe to run against the demo database.

---

## 5. Execution model

**Driving constraint:** the sweep cannot run before seeding, because empty tables
produce false "broken" verdicts. So the audit runs **twice** — once against today's data
to separate broken-from-empty, once after seeding to confirm. The first run is the
specification for the second.

### Tuesday evening — two independent tracks

- **Merge track.** Rebase `demo-hardening/integration` onto `main`, merge, verify
  `docs/superpowers/plans/2026-08-11-role-dashboard-pack.md` survives intact, run the
  four focused GREEN suites from the prior plan.
- **Matrix track.** Build and run Layer 1. Output: the 3,042-cell expected-access grid
  plus the orphan-route list.

### Wednesday — four parallel tracks

- **Track A (audit).** Layer 2 API sweep → the broken-vs-empty baseline report. This is
  the input every other track prioritizes against.
- **Track B (provenance).** Replace the three orphan invoices by driving
  `DeliveryService` → `InvoiceService::finalize()` → `recordCollection()`; backfill
  `employee_leave_balances` for the 33 requests; create `employee_shift_assignments` for
  the 200 employees behind the 22,069 attendance rows.
- **Track C (seeding).** Additive `DemoDataSeeder` covering the §1.1 table list.

- **Track D (MRP rerun safety).** Implement §3.1 TDD-first: a RED test that presses
  `POST /api/v1/mrp/runs` twice and asserts no accumulating draft auto-PRs or planned WOs,
  plus a mixed-children test proving progressed and manual documents are untouched. Code
  only — `MrpEngineService.php` and its tests. No table writes, so it runs freely
  alongside B and C.

**Concurrency guard:** B and C both write. Table ownership is disjoint — B owns AR,
leave, and shift tables; C owns everything else. Both are built and tested against an
isolated test database, never the demo database. Track D writes no data.

**Ordering note:** Track D must land before Track C seeds anything MRP-derived, otherwise
seeding a plan and then fixing the engine can leave inconsistent children. If D slips,
C seeds MRP last.

### Wednesday night — throwaway VPS deploy (decided: yes)

Deploy the current state to the VPS end-to-end, purely to prove the path. This converts
Thursday's deploy from a first attempt into a second attempt, on the only day there is
still time to react. The VPS has never been exercised at this data volume, and a deploy
that fails for the first time on Thursday night before a Friday defense has no recovery
window. The cost is roughly an hour; the alternative is discovering a migration,
env, or asset-build problem with no slack left.

### Thursday morning

Layer 3 browser sweep, all 13 roles, sharded. Fix dead ends **by frequency**: one broken
shared component behind twelve routes beats twelve individual fixes. Clear the 4
`failed_jobs`.

### Thursday afternoon — freeze

1. User takes and validates a backup.
2. User applies the seeders (never an agent).
3. `demo:verify` confirms zero mutation.
4. VPS deploy via `scripts/deploy-update.sh` with a tagged rollback point.
5. Rehearse **on the VPS**, not locally.

### 5.1 Model routing

The requested `gpt-5.6-sol` / `gpt-5.6-luna` split is not available — this harness is
Claude Code running Opus 5 (`claude-opus-5[1m]`), with no cross-provider dispatch.

**Decision: Opus for every agent**, no cheaper tier for mechanical work. The rationale is
that this plan's "mechanical" tracks are not actually mechanical — Track C seeds through
domain services that fire real events and outbox rows, and Track B repairs money rows. A
cheap-tier mistake in either is a corrupted demo database on the day before the defense,
which costs far more than the token difference. Uniform Opus also removes per-task tier
decisions from the critical path.

---

## 6. Risks

| Risk | Consequence | Mitigation |
|---|---|---|
| Wednesday seeding is the critical path | If Track C slips, Thursday's sweep runs against partial data and its verdicts get noisy | Track C is mechanical and parallelizable; start it first Wednesday morning |
| VPS never exercised at this data volume | Thursday-PM deploy leaves one evening to react | Throwaway deploy Wednesday night (adopted, §5) |
| "Run MRP now" pressed during the demo | Duplicated draft PRs and planned WOs appear live, in the purchasing and production queues | Track D fixes the engine (§3.1); rehearse pressing it twice on the VPS before Friday |
| Wednesday now carries four tracks, not three | Track D competes with seeding for attention | D is code-only with no data writes, so it can slip to Thursday morning if C is at risk; C then seeds MRP last |
| Provenance repair touches money rows | Wrong repair corrupts AR | Backup first; drive real services; user executes, not an agent; `demo:verify` afterward |
| `/tmp` worktrees not reboot-safe | Round 1 worktree metadata lost | Merge to `main` Tuesday evening — branches in `.git` are already safe |
| Seeding via services triggers real events/outbox | Unexpected queue or notification volume | Seed against an isolated test DB first; inspect `event_outbox` and `failed_jobs` before applying to demo |

---

## 7. Success criteria

Friday-ready means all of:

1. `demo-hardening/integration` merged to `main`; the four focused suites green; the
   dashboard plan doc intact.
2. Zero orphan invoices: every invoice has line items and a delivery or sales-order
   parent; every `paid`/`partial` status is backed by a `collections` row.
3. `employee_leave_balances` and `employee_shift_assignments` are non-empty and
   consistent with existing leave requests and attendance.
4. Every table in the §1.1 list is non-empty, seeded through domain services.
5. The 3,042-cell matrix is computed; every pair is classified reachable / expected-403 /
   defect; every defect is fixed or explicitly accepted in writing.
6. `failed_jobs` = 0.
7. Pressing **"Run MRP now"** twice in a row produces no additional draft auto-PRs or
   planned WOs, and leaves every progressed or manually created PR/WO untouched —
   verified on the VPS, not only in tests.
8. Full backend suite green; SPA lint, typecheck, test, build, and token audit green.
9. VPS deployed from a tagged commit with a proven rollback path, rehearsed on the VPS.

**Reported honestly, not implied:** any role-route pair the sweep could not visit is
listed as unvisited rather than counted as passing.
