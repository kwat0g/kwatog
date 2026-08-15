# System Improvement Roadmap — 2026-08-13

## Current implementation tranche

Implemented and focused-test verified in the current worktree: `GOV-P0-01`
(F-001), the serialization core of `FIN-P1-02` (F-002), `AUTH-P1-02`
(F-003), `AUTH-P1-03` (F-004), `OPS-P1-01` (F-009), the supplier-boundary
portion of F-023, and `OPS-P2-03` health-detail hardening (F-029). The original
roadmap remains below as the authoritative remaining-work sequence. F-031's PR
approval timeline terminal-state UX is also implemented and focused-test
verified. The stale `Edge` registry entry (F-037) is removed behind a
bidirectional module-registration drift test. Output-bound outgoing QC and
finite delivery allocation now close F-006 behind 24 focused tests / 48
assertions. Annual 13th-month period uniqueness and per-year serialization
close F-019 behind 12 tests / 51 assertions. Corrective effective-dated BIR
brackets close F-038 behind 17 tests / 34 assertions; F-005 remains open because
13th-month taxable-excess annualization is a separate statutory workflow.
Leave submission now serializes on its employee row before overlap evaluation
(F-012), and total/customer forecast keys use PostgreSQL null-aware uniqueness
plus stable writer locks (F-021). Auto-detected overtime now has a durable
attendance-source key (F-013). The cited PO, GRN, bill/match, invoice, and work
order detail tables now preserve their actions through narrow-screen overflow
guards (F-032); live browser visual proof remains part of the release gate.
Generic immediate stock-adjustment bypasses are removed in favor of the
canonical approval path plus one locked stock-count reconciliation command
(F-015). Dashboard layout writes use optimistic versions and return conflicts
instead of silently replacing another tab's work (F-024). Automated GL writers
now share the canonical journal posting lifecycle, protected by a static writer
contract (F-025). Final-pay computation and posting use decimal-string money
arithmetic and reconcile adversarial cent values exactly (F-027). A structured
finding-lifecycle registry and CI validator now govern owner, status, evidence
scope, and policy-decision metadata for all 38 findings (F-036). Sensitive
single and bulk role assignments now require expected-current roles and surface
stale administrative edits without overwriting the winner (F-026). Nine
high-risk enum-backed lifecycle columns now have additive database guards and
enum-drift tests; F-033 remains mitigated while lower-risk statuses are covered.
Payroll compute claims now carry durable fencing tokens through every worker
write and the stale reaper rechecks ownership under lock (F-011); a real
paused-worker/two-connection harness remains in the release gate.

The Accounting Periods surface is reachable again with seeded view/manage
permissions, a manager bootstrap action for an empty period ledger, and focused
backend/SPA authorization coverage. Static token and RBAC audits now run in the
SPA container; RBAC reports zero unseeded permission references. The API route
audit is run from the host because the SPA container is deliberately not
granted the host Docker socket: 814 requests match and all 27 unmatched clients
are checked against an explicit scope/supersession manifest (F-034). Live
browser audit still requires a Chrome binary in the audit image.

**Decision status:** audited target state with a current-worktree remediation
overlay. Seven policy decisions remain for business-owner approval.

**Scope:** system-wide controls, business-chain integrity, operational recovery,
role UX, auditability, data invariants, and release operations.

**Evidence basis:** the 2026-08-11 process-hardening and failure-path audits,
the 2026-08-12 demo-readiness pass, the role/API/UX audits, current route and
service inspection, and the existing regression/live-smoke evidence. The
baseline design remains below; the opening tranche and lifecycle registry state
which items are already implemented or mitigated in the current worktree.

## Executive verdict

The product has a credible control foundation: server-side permission guards,
approval ledgers, transactional outbox delivery, row-locking in most audited
state transitions, durable chain-run evidence, role-derived dashboards, and
operator recovery surfaces. The main risk is not lack of features. It is that a
small number of high-blast-radius seams can still turn a valid business action
into privilege escalation, a silent financial omission, an unbalanced ledger,
or an unrepairable operational state.

The original first release gate was the approval-delegation escalation defect
(F-001). That gate is now verified in the current worktree: delegation is
limited to the delegator's current exact role and revalidated when used. This
historical priority remains important because it explains why delegation must
never regress, but it is no longer an open current-worktree defect.

The next gate is financial and inventory integrity. The current dirty tree
contains substantial hardening work, including payroll/GL fencing, production
idempotency, inventory/QC controls, portal constraints, and race regressions.
That work must be preserved and integrated as a coherent migration-compatible
cohort, not selectively discarded or mixed with unrelated formatting. Remaining
policy decisions (invoice finalization, RMA classification, tax/BIR exports,
payroll GL ordering, no-BOM production, CRM transition outcomes, and supplier-
bill provenance) must be made explicit before the corresponding UI is expanded.

The target state is deliberately modest:

1. Every money, stock, approval, and external-write boundary has one
   authoritative server-side state machine, one transaction boundary, one
   replay key, and a visible recovery path.
2. Database constraints enforce business facts that must never be duplicated,
   orphaned, negative, or silently re-used.
3. UI actions describe the next legal action and the reason an action is
   unavailable; UI visibility is an aid, never the authorization boundary.
4. Exceptions remain actionable until an operator records a resolution or a
   policy-approved terminal disposition. “Completed” means the downstream
   effect is evidenced, not merely that a job was queued.
5. Release confidence comes from reproducible HTTP, queue, scheduler,
   migration, restore, and role/browser gates, including real external-provider
   staging checks where local tests cannot prove delivery.

The baseline priority sequence remains useful for historical traceability. The
current lifecycle is authoritative: 18 verified, 8 mitigated, 5 open, and 7
decision-required findings. F-030 is the leading operational release gate;
F-014, F-020, F-022, and F-028 are the remaining open engineering findings.

## Current control posture

### What is already sound and should be retained

- Route guards and sidebar gates are centralized. The SPA exposes module and
  permission gates across the routed surface (`spa/src/components/layout/Sidebar.tsx:675-705`;
  module route files under `spa/src/routes/`).
- Approval timelines distinguish approved, rejected, skipped, pending, actor,
  remarks, and overdue status (`spa/src/components/chain/ApprovalTimeline.tsx:33-68`).
- Action Center is a durable, permission-filtered work queue with priority,
  ownership, retries, and exception scope (`api/app/Modules/Dashboard/Services/ActionCenterService.php:44-85`;
  `spa/src/pages/action-center/index.tsx:125-190`).
- The dashboard dispatcher derives landing paths from live permissions and
  falls back to a generic widget dashboard rather than depending on role-name
  branching (`api/app/Modules/Dashboard/Services/DashboardDispatchService.php:30-69`).
- Outbox/listener recovery, stale leases, scheduler evidence, and chain
  recovery are designed as durable operational controls. Current recovery
  status and external-evidence limitations are recorded in the findings and
  lifecycle registries.
- The hardening audit reports local backend, PHPStan, SPA, chain-smoke,
  worker-recovery, backup-restore, and focused regression evidence. Staging
  still must prove provider behavior, scheduler restart/missed periods, Redis
  failover, and a deployed restore.

### Known control gaps and caveats

- **Policy boundary:** F-005, F-007, F-008, F-010, F-016, F-017, and F-018
  require owner decisions before engineering encodes tax, invoice, return,
  payroll GL, production, CRM-transition, or supplier-bill semantics.
- **Open engineering:** incoming receipt versus QC acceptance (F-014), generic
  source-reference integrity (F-020), material-detail audit history (F-022),
  annual run ownership (F-028), and deployed restore/recovery proof (F-030).
- **Mitigated proof boundaries:** loan/auth/payroll/leave service serialization
  still benefits from real two-connection harnesses; cited responsive surfaces
  still need live narrow-browser proof; status constraints cover a high-risk
  tranche rather than every lifecycle column.
- **External state:** local source evidence cannot prove provider delivery,
  Redis failover, scheduler restart/missed-run repair, off-site backup restore,
  or deployed rollback.
- **Generated-artifact ownership:** host SPA builds can fail to clean container-
  generated root-owned `spa/dist/assets`; the intended container build succeeds,
  but build artifact ownership should be normalized in developer/release tooling.

### Findings-register mapping and status convention

The canonical finding IDs are `F-001` through `F-038` in
`docs/SYSTEM-AUDIT-FINDINGS-2026-08-13.md`. Roadmap IDs below use the register
ID in parentheses; they are not new findings. The lifecycle registry—not the
historical baseline wording—determines whether an item is verified, mitigated,
open, or waiting for a policy decision.

| Register finding | Current roadmap treatment | Current evidence status |
|---|---|---|
| Verified: F-001, F-006, F-009, F-013, F-015, F-019, F-021, F-023–F-027, F-029, F-031, F-034, F-036–F-038 | Preserve controls and their regression/governance contracts. | Closed within each explicitly recorded verification scope. |
| Mitigated: F-002–F-004, F-011–F-012, F-032–F-033, F-035 | Finish external idempotency, real concurrency, browser breadth, schema breadth, and CI evidence. | Material service/source control exists; residual boundary is explicit. |
| Open: F-014, F-020, F-022, F-028, F-030 | Implement in priority order, with F-030 as a release gate. | No sufficient current control or operational proof. |
| Decision required: F-005, F-007–F-008, F-010, F-016–F-018 | Obtain signed policy, then implement the selected invariant and exception path. | Engineering must not infer these semantics. |

Current dirty-tree hardening is recorded in the findings overlay and lifecycle
registry. Local verification closes only its named scope; staging-dependent
evidence remains open even when the source implementation is complete.

## Current and proposed process maps

The maps below separate the business event from the control that proves it.
“Draft” means a reviewable record with no irreversible accounting effect;
“posted/finalized” means the effect is committed and auditable.

### Order-to-cash (O2C)

**CURRENT**

```text
CRM inquiry/product/customer
  → sales order
  → production work order / reservation
  → in-process or outgoing QC
  → delivery / shipment confirmation
  → draft invoice
  → invoice finalize → AR/GL
  → collection → credit note/reversal where permitted
```

The chain tracker, work-order, QC, delivery, and invoice surfaces exist. The
demo hero path deliberately proves SO → WO → confirmed delivery → draft
invoice. The major control question is what event makes an invoice final and
what evidence is required for revenue recognition; the current system should
not infer that policy from a convenient UI button.

**PROPOSED**

```text
SO (approved commercial source)
  → WO reservation (available, non-quarantine stock only)
  → output receipt (one source operation/output key)
  → QC result (batch/lot allocation recorded)
  → delivery proof + acceptance policy
  → invoice DRAFT (immutable source links and quantities)
  → invoice FINALIZE (policy gate; one fiscal posting run)
  → AR collection / credit note (separate authorized state machine)
```

Required controls: immutable source references on each downstream document;
one idempotency key per source event; explicit partial delivery/invoice
quantities; invoice finalization blocked unless the chosen acceptance policy is
met; credit notes reverse the original invoice lines and cannot silently alter
the original posted document.

### Gate design: add only control where the risk changes state

The roadmap does not propose a new approval layer for every screen. A gate is
added only at an irreversible boundary, and every gate has a named owner,
stored reason, and recovery path:

| Boundary | Added/changed gate | Why it is justified | Deliberate restraint |
|---|---|---|---|
| Delegation | Permit only the delegator's current actual role authority; revalidate that authority when the delegate acts; add expiry and SOD checks | A role escalation can approve high-value work and is a P0 exploit | Do not blanket-ban finance/admin delegation when the delegator legitimately holds that role. A capability-scoped model is an optional later redesign, not the P0 fix. |
| QC release | Required inspection + lot/quantity allocation | Missing or cancelled QC can release unsafe stock | Do not add QC to items explicitly classified as non-QC; use the existing policy flag. |
| Invoice finalization | Commercial acceptance/revenue policy | Posting AR/GL is irreversible and must match policy | Keep draft invoices and the current chain; add pro-forma only if the owner requires it. |
| RMA availability | Quarantine before available stock | Returned goods have uncertain condition | Do not add a parallel inventory ledger; use existing movement types and GL mappings. |
| Payroll/GL | Run ID, closed-period fence, balanced JE, reversal link | Payroll is money and replay can duplicate or omit effects | Use transactional outbox and existing reconciliation, not a distributed saga. |
| External provider | Durable intent/receipt/retry status | Provider acceptance can occur before a worker crash | Do not claim exactly-once without provider idempotency support. |
| UI | Explicit terminal/blocked/next-action states | Operators otherwise see misleading pending or clipped actions | Do not duplicate server policy in a separate client state machine. |

### Plan to produce (P2M)

**CURRENT**

```text
Demand forecast / sales demand
  → MRP plan and shortages
  → production schedule
  → work order
  → material reservation / issue
  → operation and output recording
  → outgoing QC
  → finished-goods receipt
  → delivery availability / cost and performance reporting
```

The system contains each major stage. Durable output idempotency and exact
output-bound outgoing QC materially strengthen the output-to-stock boundary.
The principal product-policy gap is whether any stock-producing work order may
run without an effective BOM/material plan (F-016).

**PROPOSED**

```text
Approved demand source
  → versioned MRP run with explainable shortages
  → finite schedule and machine/capacity assignment
  → WO class determines BOM requirement
  → locked reservation and authorized material issue
  → operation evidence and durable output command
  → exact output-batch QC
  → accepted FG receipt once
  → variance, scrap, WIP, stock, and GL reconciliation
```

Standard stock-producing WOs should require an effective BOM and material
plan. A no-BOM path should exist only for an explicit service, non-stock, or
prototype class with an authorized reason, costing rule, and visible exception.

### Record to report (R2R)

**CURRENT**

```text
Operational source transaction
  → automated or manual journal draft
  → accounting-period validation
  → balanced posting
  → reversal/correction
  → GL and financial reports
```

Canonical posting, period guards, and automated-writer routing are present.
The remaining structural weakness is that generic `(reference_type,
reference_id)` sources cannot be mechanically guaranteed to resolve (F-020),
and some material detail changes lack immutable before/after attribution
(F-022).

**PROPOSED**

```text
Typed immutable source/run identity
  → canonical balanced journal command
  → open-period and segregation-of-duties checks
  → posted entry with actor/system attribution
  → source-to-GL reconciliation
  → reversal linked to original (never destructive edit)
  → period close with owned exceptions and evidence pack
```

Accounting Periods should remain a supported Finance surface. An empty ledger
may bootstrap the current month through the existing close command; close and
reopen actions remain permission-gated, reasoned, and auditable.

### Procure-to-pay (P2P)

**CURRENT**

```text
PR draft → submit → approval chain → approved PR
  → PO draft → submit/approve → supplier dispatch or manual send
  → GRN receive → incoming QC
  → accept/partial accept → stock + weighted-average cost + GL
  → AP bill draft/3-way match → post bill + GL → payment
```

The PO detail explains portal/manual dispatch and exposes GRN, bill, approval,
and linked-record context (`spa/src/pages/purchasing/purchase-orders/detail.tsx:196-220,253-275`).
Incoming QC and inventory/GL hardening are present in the dirty tree. The
compact PR chain now distinguishes rejected and skipped terminal steps; the
remaining process issue is accepted-versus-physically-received semantics.

**PROPOSED**

```text
PR (request and budget evidence)
  → approval ledger (delegation/SOD checked server-side)
  → PO (supplier/price/PPAP/budget gates)
  → dispatch intent + confirmed transmission evidence
  → GRN (received quantities and lots)
  → QC allocation/result (fail closed when required)
  → accepted stock + GRNI/GL
  → bill draft (3-way match/variance policy)
  → AP post → payment reconciliation
```

Separate “approved” from “sent,” “received,” and “accepted.” A queued
notification is not a dispatch confirmation; a received quantity is not
available stock until QC and location rules pass; a matched bill is not a
posted payable until the accounting action succeeds.

### Hire-to-retire and payroll (H2R)

**CURRENT**

```text
Employee/profile → attendance/DTR → leave/overtime/adjustments/loans
  → payroll period claim → compute → anomaly review → approve
  → finalize → bank file + payslips + notifications + GL handoff
  → disbursement proof / void and reversal
  → separation/final pay → 13th-month and statutory outputs
```

Payroll computation and outbox recovery are durable. The hardening audit proved
the force-unlock and GL dedupe defects and records them as fixed in the current
dirty tree, but the fixes need integrated regression and staging evidence.
Final-pay accounting must not 500 when deductions exceed a partial earnings
snapshot (historical control P05-01, consolidated into F-010).

**PROPOSED**

```text
Attendance/approved adjustments/loan ledger snapshot
  → period claim (unique employee + cycle)
  → compute (per-employee transaction, anomaly flags)
  → maker-checker approve
  → FINALIZE RUN (run_id; GL/bank/payslip intents)
  → provider acknowledgements and reconciliation
  → disbursement proof
  → void/reversal creates a new correction run, never reuses old intent
  → final pay uses frozen earnings + live, locked deductions + explicit recovery
```

The period run ID is the boundary for GL, bank, and notification idempotency.
Void clears/reverses the old run linkage and re-finalization gets a new run ID.
Queued/system actions carry a system actor plus originating request/run ID.

### Quality and CAPA

**CURRENT**

```text
Inspection spec → incoming/in-process/outgoing inspection
  → measurements/result → pass or fail
  → NCR → disposition/rework/scrap/use-as-is
  → corrective action → effectiveness verification → close
```

Inspection and NCR pages expose terminal status, unresolved measurements,
linked records, retry, and close/cancel rules. Incoming QC is a cross-module
gate to GRN acceptance. The operational risk is batch/lot allocation: a pass
must identify exactly which received quantities become available.

**PROPOSED**

```text
Spec/version + sampling plan
  → inspection instance (source document + lot/batch + allocated quantity)
  → immutable measurements and actor/time
  → result (pass/fail/partial/cancelled)
  → stock disposition (available/quarantine/rework/scrap/return)
  → NCR/CAPA with owner, due date, evidence, effectiveness check
  → close only when disposition and CAPA obligations are terminal
```

“Cancelled” must not be treated as “passed,” and a missing inspection must fail
closed for QC-eligible material. The UI should show the blocked downstream
quantity and the next responsible role, not merely a red status chip.

### Returns (RMA)

**CURRENT**

```text
Customer return request → receive → inspection
  → disposition → restock / quarantine / scrap / vendor return / replacement
  → credit or replacement PO and audit trail
```

The RMA path exists and the current hardening fixed customer-return restock
cost handling. Product policy is still needed for how each disposition maps to
available stock and financial treatment.

**PROPOSED**

```text
RMA request (reason, source SO/invoice, serial/lot)
  → receipt (never available stock)
  → QC inspection and quantity allocation
  → explicit disposition:
       restock-to-available | quarantine/rework | scrap | return-to-vendor
  → inventory movement + GL policy + customer credit/replacement
  → close only after all child effects reconcile
```

Default recommendation: all returned goods enter quarantine; only a passed
inspection can restock available inventory. Scrap removes quantity from the
ledger and uses a configured loss account. Return-to-vendor leaves customer
credit and supplier movement as separate, linked actions.

### Maintenance

**CURRENT**

```text
Preventive schedule / corrective request
  → maintenance work order → assignment → logs/spares/downtime
  → complete/cancel → machine hours/cost/next due date
```

The desktop and mobile work-order surfaces are guarded, retryable, and
operator-oriented. Condition readings and machine-health pages are intentionally
hidden because no IoT source exists (`api/app/Modules/Maintenance/routes.php:55-81`),
not because a reachable page is broken.

**PROPOSED**

```text
Schedule/request (source and priority)
  → WO with asset/machine lock and SLA
  → technician logs + spare-part stock issue + downtime reason
  → completion verification
  → cost/stock/asset updates
  → next-due recompute that never regresses
  → overdue/failed automation in Action Center
```

Do not build a “live health” dashboard until a real source contract exists.
Preserve the mobile work-order path and expose manual recovery for missed
preventive generation.

## Feature and scope disposition

| Surface | Disposition | Target decision and rationale |
|---|---|---|
| Approval ledger, timeline, SOD checks | KEEP / IMPROVE | Same-role delegation, use-time revalidation, and rejected/skipped presentation are complete. Preserve them; continue expiry/overlap notification and run-level evidence where needed. |
| Action Center and exceptions | KEEP / MERGE | Keep one prioritized queue; retain Exceptions as a URL scope/category, not a second workbench. Add domain reconciliation and “manual required” terminal evidence. |
| Permission-gated sidebar and route guards | KEEP | Centralized gates are correct. Add full role/feature test matrix; never treat hidden navigation as authorization. |
| Role dashboards | KEEP / IMPROVE | Keep eight bespoke dashboards and permission-derived dispatch. Keep five generic widget dashboards deliberately, but ensure their widgets are seeded, useful, and tested for all 13 roles. |
| PR/PO/P2P chain | KEEP / IMPROVE | Rejected/skipped presentation is complete. Continue dispatch evidence, incoming-QC acceptance semantics, supplier-bill provenance, and financial reconciliation. |
| Invoice/final-pay posting lifecycles | REDESIGN | Exact-cent final pay and bounded recovery are complete. Keep existing documents/services, but obtain invoice timing and payroll/annualization policy before tightening finalization and disbursement gates. |
| Accounting periods UI | KEEP / IMPROVE | The Finance surface and view/manage permissions are restored. Preserve close/reopen audit semantics, current-month empty-ledger bootstrap, and include the page in live role/mobile verification. |
| Asset transfers | DEPRECATE / HIDE | Keep model/service for future custody policy; no UI/API until live rows, lifecycle, and approval ownership exist (`api/app/Modules/Assets/routes.php:31-44`). |
| HR directory/org chart and employee properties | DEPRECATE / HIDE | Keep supported Employees list and document paths; remove scanner noise for orphaned clients. Re-enable only with data and owner policy (`api/app/Modules/HR/routes.php:25-37,112-124`). |
| Inventory stock transfers | MERGE | Keep Transfer Orders as the one execution surface; retain service internals needed by the order path (`api/app/Modules/Inventory/routes.php:98-105`). |
| Stock count/movements/categories | MERGE / RELOCATE | Keep Warehouse Map count toggle and Stock Levels movement view; keep Categories as an Items modal as documented in route/sidebar comments. |
| Maintenance condition readings | DEPRECATE / HIDE | Do not expose manual IoT-shaped UI without an actual source. Restore only with source freshness, device identity, and alert ownership. |
| PR templates | DEPRECATE / HIDE | Keep template write compatibility only while no supported consumer exists; remove API-client audit noise and reintroduce after template application and versioning policy. |
| Payroll pipeline/de-minimis standalone pages | MERGE / RELOCATE | Keep pipeline/de-minimis operations in Payroll period/detail surfaces; preserve direct service/API controls for audited operators. |
| Admin depreciation page | MERGE / RELOCATE | Keep depreciation action on Fixed Assets with its existing permission, not a second admin page. |
| Supplier/customer portals | KEEP / IMPROVE | Keep separate tenant-scoped layouts and authenticated blob downloads. Supplier deliveries now use a scoped allow-list; continue document dedupe, external retry, and exposure review. |
| Factory/driver/maintenance mobile PWAs | KEEP / IMPROVE | Keep touch shells and role scopes. Add narrow-device smoke checks, offline/error copy, and explicit retry/manual escalation. |
| “Live” machine-health and predictive analytics without source | REMOVE until sourced | Avoid a misleading dashboard; retain domain code only if reactivation criteria are documented. |

## Target role dashboards and Action Center

The role target is task-oriented, not a copy of every module. Every role gets
the generic dashboard fallback plus only the widgets authorized by current
permissions. Action Center exposes only source categories the user may act on;
read-only KPI widgets never imply approval authority.

| Role | Landing/dashboard target | Action Center scope |
|---|---|---|
| `system_admin` | Admin dashboard plus cross-chain health, audit, failed jobs, and delegation/SOD alerts | All permitted sources; system-level failures require explicit acknowledge/resolve reason. |
| `production_manager` | Production KPI, active WOs, OEE, QC, maintenance, chain bottlenecks | WO exceptions, QC holds, material shortages, overdue maintenance. |
| `ppc_head` | Gantt, MRP shortages, reservations, machine status, due maintenance | MRP runs, shortages, schedule conflicts, unprocessed chain outcomes. |
| `finance_officer` | Cash, AR/AP aging, revenue, payables, budget, forecast | Bills/variance, invoices/collections, GL handoffs, payroll GL exceptions, period locks. |
| `hr_officer` | Headcount, leave, approvals, probation, payroll calendar, training | Leave/OT/profile approvals, payroll anomalies, final-pay and statutory exceptions. |
| `purchasing_officer` | Open PR/PO, supplier performance, overdue delivery, low stock, returns | PR/PO approvals, budget/PPAP blocks, supplier dispatch, overdue receipts. |
| `qc_inspector` | Pending inspections, pass rate, Pareto, NCRs, returns | Inspection work, NCR/CAPA due dates, rejected GRN/RMA dispositions. |
| `warehouse_staff` | Pending GRNs, low stock, issues, deliveries, returns | Receiving/QC gates, MRB holds, stock adjustments/count variances, failed GL handoffs. |
| `department_head` | Generic widget layout: approvals, team DTR/leave, requests, loans | Department-scoped approvals and overdue requests only; no HR/payroll administration. |
| `maintenance_tech` | Generic widget layout: open WOs, schedules, machine status, asset maintenance | Assigned/available maintenance work, overdue schedules, spare-part exceptions. |
| `impex_officer` | Generic supply/purchasing layout: deliveries, schedules, suppliers, approvals | Shipment/delivery exceptions, supplier documents, logistics approvals. |
| `employee` | Generic self-service: payslip, leave balance, DTR, pending requests | Own requests and notifications; no approval or cross-employee data. |
| `driver` | Generic self-service plus delivery-focused mobile route | Assigned deliveries, failed proof/status updates, no broad operational queue. |

The browser audit must cover all thirteen rows at desktop and mobile widths.
Portal users are separate supplier/customer personas and must not inherit ERP
dashboard widgets or Action Center permissions.

## Exception, reconciliation, and audit model

### One exception lifecycle

Every asynchronous or cross-module handoff should produce a chain outcome with:

```text
created → queued → processing → succeeded
                         ├→ retryable_failed → queued
                         ├→ manual_required → resolved/waived
                         └→ permanently_failed → resolved/waived
```

Each row carries source type/id, run/request ID, dedupe key, attempt count,
first/last error, next retry time, owner, timestamps, and a link to the source
document. “Skipped” is distinct from “succeeded”; “manual required” cannot be
counted as completed. Resolution requires actor, reason, evidence/reference,
and the resulting state. Bulk exception actions must be bounded by source
permissions and create one audit event per affected item plus a batch event.

### Reconciliation jobs

Reconciliation is a derived read/check, not a second mutation path. Each domain
gets an explicit command/report:

- O2C: delivered quantity vs invoiced quantity vs collected balance;
- P2P: PO/GRN/QC/bill/payment and open GRNI;
- payroll: period status vs GL/bank/payslip/provider acknowledgements;
- inventory: stock movement ledger vs stock levels vs GL valuation;
- quality: inspection allocation vs accepted/rejected quantities and CAPA;
- RMA: received quantity vs disposition vs credit/replacement;
- maintenance: schedule due date vs WO completion and asset cost;
- portals: tenant-owned uploads/schedules vs dedupe and storage records.

Reports must return non-zero/failed status on partial computation, preserve the
failed source IDs, and offer reviewed target-period reruns. A reconciliation
finding never silently “fixes” a posted ledger; it creates an exception or a
controlled correction document.

## Auditability standards

Every irreversible or externally visible action must record:

1. authenticated actor or named system actor;
2. tenant/role/permission context where relevant;
3. source entity and immutable hash ID;
4. correlation/request/run ID and idempotency key;
5. action, prior status, resulting status, and timestamps;
6. reason/remarks for reject, cancel, override, waiver, retry, or manual
   resolution;
7. downstream references (JE, movement, outbox row, provider receipt, file);
8. error class and operator-visible recovery instruction.

Model audit hooks are useful but not sufficient: query-builder/raw inserts
must call an explicit audit service or be replaced with model/service writes.
Queued work must preserve the originating actor/run context without pretending
that a worker was the human approver. Audit data is append-only, tenant-scoped,
retained according to policy, and exportable with integrity checks. Sensitive
PII and portal documents require access events and redacted error messages.

## Database invariant strategy

Use the database for facts that must hold even if an API, worker, or UI is wrong.
Application services still provide readable business errors and transaction
ordering.

- **Identity and scope:** FKs for tenant/source ownership; portal partial unique
  indexes for supplier/customer schedules; document dedupe key for uploads.
- **One-per-source effects:** unique `(source_type, source_id, effect_type,
  run_id)` or a domain-specific equivalent for GL, movement, receipt, and
  external intent. Keep historical runs distinct; never reuse a voided run key.
- **Approval:** unique step/order per workflow instance; one current action per
  step; delegation rows may cover only authority in the delegator's current
  actual role set, are revalidated at use, and cannot overlap for the same
  principal/time window. A later capability-scoped design may support finer
  granularity but is not required for the P0 repair.
- **Money:** fixed-precision numeric columns, non-negative checks where policy
  requires, balanced journal constraint/service, immutable posted entries,
  explicit reversal links, and closed-period write fences.
- **Stock:** movement ledger is append-only; source/destination/item/location
  FKs; available quantity excludes quarantine/scrap; QC allocation cannot exceed
  received quantity; stock level version is checked under lock.
- **Payroll/loans:** unique employee/cycle claim; one accrual row per
  employee/year; locked or atomic loan balance updates; one final-pay deduction
  per source run; statutory table effective-date uniqueness.
- **Workflow state:** database enum/check constraints where PostgreSQL
  compatibility permits; otherwise centralized transition service plus tests
  enumerating every writer.
- **Idempotency:** request token or deterministic source key stored with result;
  repeat returns the original result, not a raw unique-violation 500.

Every migration must be additive/backward-compatible first: add nullable/index
or shadow columns, backfill and verify, switch readers/writers, then tighten or
remove only in a later release. Partial/unique indexes must include a preflight
duplicate report and a reviewed deduplication policy; no migration may delete
business rows implicitly.

## Prioritized implementation backlog

Complexity is relative engineering effort: S (days), M (one to two weeks), L
(multi-team or policy-dependent). Acceptance evidence is required before an
item is marked complete.

| ID / priority | Work and mapped evidence | Impact | Complexity / dependencies | Acceptance evidence |
|---|---|---|---|---|
| **GOV-P0-01 (F-001)** | Fix approval-delegation role escalation. Permit a delegator to delegate only authority from the delegator's current actual role set; revalidate that authority when the delegate acts. A `system_admin` managing someone else cannot create a target role escalation. Do not blanket-ban finance/admin delegation when the delegator legitimately holds it. A capability-scoped redesign is optional later architecture, not this P0 fix. | Privilege escalation / approval bypass | M; mandatory first item; depends on live role/permission resolution | HTTP test: ordinary employee POST with `role_slug=system_admin` or another unheld role returns 403/422 and no row; a system admin cannot create a target role escalation; a legitimate finance/admin delegator can delegate its actual authority; changing/revoking the delegator role makes use fail. |
| **GOV-P2-02 (F-001)** | Add delegation expiry, overlap, self-delegation, use-time audit, notification, and revocation hardening after the authority boundary is fixed. | Prevents stale/ambiguous authority | M; after GOV-P0-01 | Feature tests for expiry/time overlap/revocation; audit row contains actor, actual role, delegated authority, reason, and target; Action Center shows active delegation changes. |
| **FIN-P1-01 (F-010/P05-01/P01-01/P01-02)** | Verify existing dirty-tree payroll hardening: force-unlock lock-then-guard, per-run GL dedupe, void clears old JE link, final-pay bounded recovery. | Prevents paid-period mutation, silent GL omission, separation 500 | M; integration/review of existing changes, not new implementation | Focused payroll event and GL handoff regressions; void → re-finalize produces one fresh JE; final-pay deductions never exceed frozen earnings without policy-approved recovery. |
| **FIN-P1-02 (F-002)** | New loan ledger serialization and canonical payment ledger. Lock loan rows or use atomic deltas; derive balance from immutable payments with reconciliation; make recompute reversal idempotent. | Prevents lost loan credit and over-deduction | M/L; policy on rounding and settlement | Two real DB connections concurrently compute/recompute one employee across periods; payments equal deductions; balance equals ledger; final pay and payroll reconciliation agree. |
| **AUTH-P1-02 (F-003)** | New reset-token consume primitive: lock/re-read or atomic `used_at IS NULL` winner condition, one password-history mutation, deterministic replay failure. | Prevents concurrent token reuse/account takeover | S; Auth | Same reset token in two concurrent requests yields exactly one success, one password mutation/history row, and one used timestamp. |
| **AUTH-P1-03 (F-004)** | New failed-login concurrency primitive: atomic increment/threshold update or locked authoritative row; define success-versus-failure ordering and threshold audit. | Preserves brute-force lockout under parallel attack | S-M; Auth/rate-limit policy | N concurrent failures at threshold plus a success/failure interleave produce no lost increments and one correct lockout/audit outcome. |
| **FIN-P1-03 (F-005/F-019/F-028/F-038)** | Continue the 13th-month statutory track. Effective-dated BIR tables (F-038) and annual-period uniqueness (F-019) are complete; taxable/non-taxable annualization, export reconciliation, and compute/void/retry ownership remain. | Legal/payroll correctness | L; HR/Finance/legal policy first | Signed fixture matrix; BIR export validation/checksum; taxable excess reconciles; rerun/void creates correction, not duplicate payment. |
| **FIN-P1-04 (F-010/F-022/F-025)** | Automated writers now share the canonical journal posting lifecycle and a mutation contract guards F-025. Continue payroll GL policy/fencing: actor/run metadata completeness, explicit disbursement-while-pending policy, and reconciliation. | Financial statement integrity | M; business policy remains | Duplicate finalize/void/re-finalize and GL-pending disbursement tests; JE balances; `created_by/posted_by`, run ID, audit events, and reconciliation findings are present. |
| **AUTH-P1-01 (F-011/F-025/F-026)** | Role assignment conflicts (F-026) and the canonical GL writer guard (F-025) are complete; payroll claim fencing (F-011) is service-mitigated. Continue the remaining high-risk state-writer and real two-connection sweep. | Prevents stale approval/rejection/status regression and role drift | L; use existing trace inventory | Matrix of live writers; every high-risk writer re-reads authoritative state; concurrent tests prove second action is blocked/no duplicate effect. |
| **QTY-P1-01 (F-006/F-014)** | Output-bound outgoing QC and finite delivery allocation (F-006) are complete. Continue the incoming/PO contract: store receipt-lot acceptance semantics and prevent physical receipt status from being mistaken for accepted stock. | Prevents uninspected or misallocated stock | M/L; Quality + Inventory + business owner decision | GRN with multiple lots and partial pass; accepted quantity never exceeds receipt; PO/reporting quantities distinguish physical receipt from accepted stock; stock/GL/NCR links reconcile. |
| **INV-P1-02 (F-008)** | New RMA inventory classification and accounting policy. Enforce quarantine-first disposition and distinct restock/scrap/RTV/rework movements; require provenance for stockable credits. | Prevents available-stock, valuation, and credit abuse errors | M/L; business owner policy required | RMA scenario proves each disposition, movement, WAC/GL treatment, credit/replacement linkage, and exactly-once completion; product-only stockable return is blocked or explicitly non-stock. |
| **BILL-P1-01 (F-007)** | New invoice policy/pro-forma decision and implementation. Default: confirmed delivery creates a draft invoice; finalization requires policy acceptance/proof and posts once. Add pro-forma only if the business owner requires it. | Revenue/AR correctness without duplicate document types | M; Finance/Commercial policy | Signed policy; draft/final/pro-forma lifecycle tests; partial delivery quantities and credit notes reconcile; no final invoice without required evidence. |
| **OPS-P1-01 (F-009)** | New durable production idempotency: persist request key/output ID with a unique constraint, namespace by WO/operation, and make the receipt listener idempotent by output ID. | Prevents cache-loss duplicate output | M; preserve/verify current dirty-tree namespacing hardening | Same key under simultaneous requests, cache flush, expiry, and worker restart yields one output/receipt; two WOs never share a dedupe key. |
| **DATA-P2-01 (F-012/F-019/F-020/F-021/F-033)** | F-012 employee-row serialization, F-019 annual-period uniqueness, and F-021 null-aware forecast uniqueness are complete. Nine high-risk lifecycle columns now have database status guards (F-033). Continue source-reference reconciliation/FKs and the remaining lower-risk status inventory, promoting money/stock invariants where policy requires. | Makes invariants survive bad clients/workers | M/L; migration compatibility and preflight reports | Additive migrations apply to a copy; duplicate report is empty or dispositioned; constraint violations return business 4xx; rollback/restore tested; no destructive implicit dedup. |
| **AUD-P2-01 (F-022/F-023)** | Concrete audit/portal exposure coverage: audit raw/query-builder GL/handoff writes, actor/system attribution, source/run lineage, portal tenant scoping, allow-listed GRN fields, document access events, redaction, and retention. | Accountability and data exposure control | M; depends on audit standard | Negative cross-tenant/field exposure tests; every money/stock/approval effect has actor/run/source; exported audit archive verifies integrity; automated GL has system actor plus initiating run. |
| **OPS-P1-02 (F-030/F-036)** | Deployment/restore/live verification gate. Keep current local hardening as verification only, then prove migration ordering, workers after migrations, scheduler health/restart, Redis failover, provider timeout/accepted-before-crash, off-site backup, restore, and rollback. | Production recoverability | M; staging infrastructure | Non-zero deploy on migration/smoke failure; restore latest backup into scratch and boot app; scheduler restart/missed-month repair; zero failed jobs after worker recovery; evidence artifact attached to release. |
| **OPS-P2-03 (F-029)** | Public health detail/Edge contract tests: keep liveness minimal/public; require intended token and scope for detail; reject stale/revoked Edge credentials; do not claim a leak absent evidence. | Prevents boundary regression and metadata exposure | S; Auth/Edge/deployment owners | Positive/negative health-detail token tests, stale credential rejection, redacted response assertion, and monitored liveness/detail separation. |
| **UX-P2-01 (F-032)** | Responsive business-detail surfaces. Wrap PO/GRN/bill/invoice/production tables or supply mobile cards; retain keyboard and screen-reader semantics. | Prevents mobile dead ends in warehouse/finance operations | M; browser viewport matrix | 375px/768px screenshots or Playwright assertions show no clipped actionable controls; editable GRN fields remain usable. |
| **UX-P2-02 (F-031/F-032)** | PR chain state clarity and mobile approval path. Add rejected/skipped/blocked/terminal visual states and next-action copy; align compact chain with ApprovalTimeline. | Prevents misleading operational status | S/M | Rejected, skipped, pending, overdue, converted fixtures render distinct states and actionable explanations at desktop/mobile widths. |
| **UX-P2-03 (F-024/F-031)** | Dashboard save conflicts and PR terminal-state rendering are complete. Expand browser harness from 9 to 13 roles, add mobile viewports, and verify generic-role widgets/source-permission filters live. | Detects blank/403/misleading role homes | M; browser image dependency | All 13 roles land on expected paths; no console/API errors; role-specific queue items are scoped; mobile actions remain usable. |
| **OPS-P2-01 (F-014/F-020/F-022)** | Reconciliation UI/reporting: domain checks, failed source IDs, target-period rerun, manual-required ownership, and exportable evidence. | Turns hidden drift into repairable work | M/L; depends on P1 invariants | Seed each mismatch and verify report, Action Center item, operator resolution, and no silent mutation of posted records. |
| **OPS-P2-02 (F-034/F-035/F-036)** | Hidden-scope classification, route reachability, and the finding-status lifecycle registry are complete. Continue the adversarial acceptance-test manifest and make the complete P0/P1 release suite reproducible in CI. | Reduces false positives and preserves attention for real drift | S/M | API audit reports hidden/dead clients separately; role/permission audit has zero unexplained references; lifecycle validation passes; CI runs all P0/P1 acceptance IDs in container. |
| **NOT-P2-01 (F-011/F-022)** | Notification/retry UX: durable inbox first, visible provider/queue status, retry/manual-required copy, duplicate suppression where provider supports it. | Prevents “queued means delivered” confusion | M; provider contract | Simulated timeout, retry, dead-letter, and accepted-before-crash scenarios show correct state and operator action; no duplicate in-app row. |
| **POL-P3-01 (F-034/F-036)** | Product polish: empty-state copy, next-action links, mobile spacing, dashboard widget ordering, documentation/runbook refresh. | Lower support cost and better adoption | S/M; after P1/P2 | Accessibility/visual smoke, copy review, scope-manifest review, and demo checklist complete. |

## Product-owner decisions required

The following choices change data and accounting semantics. The recommended
defaults should be adopted unless the product owner/business owner explicitly
chooses otherwise.

| Decision | Recommended default | Why it is the safe default |
|---|---|---|
| 13th-month taxable excess (F-005) | Use cumulative year-to-date statutory excess with effective-dated rules; post the correction delta and retain a signed export reconciliation. | Prevents per-period treatment from understating/overstating annual taxable excess. |
| Invoice vs prebill (F-007) | Confirmed delivery creates a draft invoice; standard finalization requires delivered quantity. Add an explicit approved prebill/pro-forma lifecycle only if the business requires it. | Prevents premature revenue/AR while avoiding two indistinguishable invoice paths. |
| RMA provenance (F-008) | Stockable returns require invoice/delivery/SO-line source, authoritative original price, quarantine receipt, and controlled lot/serial where applicable. | Credit and stock disposition remain tied to the original commercial and physical facts. |
| Payroll GL gate (F-010) | Disbursement requires GL `posted` or policy-backed `not_required`; `pending` and `manual_required` block and remain assigned exceptions. | Prevents money leaving while the accounting effect is missing or unresolved. |
| No-BOM production (F-016) | Standard stock-producing WOs require an effective BOM/material plan; only explicit service/non-stock/prototype classes may use an approved exception. | Prevents uncosted, unplanned output while preserving legitimate non-standard work. |
| Illegal CRM transitions (F-017) | Same-state replay is idempotent; illegal different-state transitions return a typed conflict/skipped outcome with an audit reason. | Makes retries safe without silently hiding invalid workflow commands. |
| Supplier bill provenance (F-018) | Stock/item bills require PO and accepted GRN quantity; service/non-stock bills require an explicit type, evidence, owner, and approval. | Preserves three-way-match integrity without blocking legitimate service purchasing. |

## Implementation sequencing and dirty-tree safety

1. **Preserve and review the current cohort.** Record the branch, changed files,
   migrations, and generated artifacts. Do not reset, clean, mass-format, or
   selectively discard unrelated user work.
2. **Approve the seven policy decisions.** Record the selected tax, invoice,
   RMA, payroll GL, no-BOM, CRM transition, and supplier-bill semantics before
   adding corresponding constraints or UI states.
3. **Close the operational release gate.** Execute F-030 in a production-like
   environment: migrations before consumers, worker/scheduler restart,
   provider failure, current backup restore, authenticated smoke, and rollback.
4. **Close open engineering controls.** Deliver F-014, F-020, F-022, and F-028
   with explicit owners, additive migrations, reconciliation, and recovery.
5. **Apply remaining invariants additively.** Add nullable/shadow fields and indexes,
   backfill with reports, enforce in services, then tighten constraints in a
   later release. Existing rows must get a reviewed disposition rather than a
   destructive cleanup.
6. **Build reconciliation and recovery before broad UX.** A new automation or
   dashboard widget must have an exception row, retry/manual path, and
   reconciliation query before it becomes an operational dependency.
7. **Run P2 UI/tooling work against the final states.** Responsive layouts,
   dashboard matrices, chain statuses, and scanner manifests should reflect
   the canonical server state machine, not duplicate it.
8. **Stage and verify live behavior.** Apply migrations, start consumers only
   after migration/cache completion, run role/browser and mobile smoke, kill and
   recover a worker, restart scheduler, simulate provider outcomes, run a missed
   period backfill, and restore the latest backup.
9. **Release/rollback gate.** Keep the previous image/release and database
   backup available; a failed migration, route cache, worker, scheduler, or
   smoke gate is non-zero and blocks promotion. Roll back application code only
   when schema compatibility is confirmed; otherwise forward-fix with the
   additive migration path.

## Definition of done for the target state

- The delegation HTTP exploit regression is green: ordinary users cannot
  delegate authority outside their current actual role set, while legitimate
  finance/admin delegation remains possible for users who actually hold that
  authority and is revalidated when used.
- Each O2C, P2P, H2R/payroll, Quality/CAPA, RMA, and Maintenance map has a
  documented owner, server-side gates, idempotency key, audit event, and
  reconciliation report.
- Money and stock effects have database-backed uniqueness/foreign-key/state
  invariants and two-connection concurrency coverage where applicable.
- Role dashboards and Action Center are verified for all thirteen ERP roles and
  separate portal personas at desktop/mobile widths.
- Exceptions distinguish retryable failure, manual-required work, skipped, and
  succeeded; resolution is actor- and evidence-backed.
- Deploy, scheduler, worker, provider, backup, restore, and rollback gates have
  current staging evidence, not only local unit-test evidence.
