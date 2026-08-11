# Cross-module process audit — 2026-08-10

## Purpose

This is the current-state audit record for the cross-module process-hardening
objective. It audits the PHP API, queue/outbox boundary, database migrations,
recovery API/UI, SPA process surfaces, deployment gate, and the documented
process map. It is an evidence record for the next implementation decision;
it is not a claim that live staging or external provider behavior has been
verified.

## Audit method

The review used the live worktree as the source of truth and checked:

1. State-changing services for direct event dispatch, `try/catch` blocks that
   can commit one side of a cross-module handoff, and transaction/after-commit
   boundaries.
2. Domain events against the allow-listed outbox codec, chain-step dedupe
   records, queued listeners, listener outcomes, replay lineage, and manual
   resolution paths.
3. Entity/resource contracts, SPA deep links, permissions, and operator
   actions for every detected manual or failed state.
4. Bottleneck detection clocks and links, deployment migration ordering, Redis
   health/lease configuration, and the repeatable worker smoke path.
5. Focused and full regression evidence, PHP syntax, frontend lint/typecheck,
   and diff/Compose checks.

The review deliberately distinguishes queue execution from business outcome:
an event being published does not mean that the receiving document exists.

## Verified backbone

The following controls are present across the current hardened surface:

- Domain mutations can persist an allow-listed event in the transactional
  outbox; the dispatcher recovers publication after commit or worker outage.
- Chain steps use stable dedupe keys. Listener runs record queue lifecycle,
  business outcome, replay lineage, and operator resolution separately.
- Recovery replays one selected listener from the stored event. It does not
  re-fire sibling notification/listener work or rewrite the original run.
- Stateful P2P/QC, O2C, H2R/payroll, production/MRP, supplier dispatch, and
  final-pay paths have explicit idempotency or manual-handoff behavior in the
  current source.
- Deployment starts consumers after migrations, waits for PostgreSQL/Redis
  health, and has a real Redis worker smoke path.
- The process documentation and chain bottleneck surface now include the
  Production → Inventory, Inventory → Accounting, Return → Quality,
  Complaint → Quality, and GRN → Quality handoffs described below. The
  customer-portal complaint entry point now uses the same canonical CRM
  handoff service as the internal route.

## Boundary inventory

| Boundary | Current state | Audit result |
|---|---|---|
| SO → MRP → WO | Durable chain publication, locked lifecycle/scheduling/reservation paths, chain progress | Hardened; verify live golden path in staging |
| WO → in-process/outgoing QC | Durable work-order events and queued listeners; invalid prerequisites fail visibly | Hardened; zero-quantity and missing-product cases covered |
| WO output → finished-goods Inventory | Output facts commit independently; receipt is now persisted per output with replay/manual state | **Closed in this tranche** |
| GRN → incoming QC → accepted stock | Durable GRN event, explicit QC handoff state, manual retry/bottleneck, sibling-QC gate, locked/idempotent acceptance, GL ownership | **Closed in this tranche**; quantity precision policy remains external |
| Accepted GRN → supplier bill → 3-way match | Idempotent draft bill, persisted match snapshot, blocked/override state, bottleneck | Hardened; live Accounting configuration still external |
| PO approval → supplier dispatch | Durable dispatch row, provider boundary, stale recovery, cancellation closure, locked supplier-portal acknowledgement | Portal/manual states are intentional until transport is selected |
| Supplier portal shipment update → PO logistics metadata | Locked/reloaded PO, preserved shipment fields, terminal-state guard | Source-level closed in this tranche |
| Supplier portal invoice → AP draft → 3-way match/GL | Locked PO/vendor boundary, item-aligned unposted draft, duplicate retry idempotency, review-time posting gate | Source-level closed in this tranche; staging Accounting configuration remains external |
| Supplier portal bill detail/PDF → vendor-scoped AP artifact | Bill model binding, vendor ownership check, AP-specific PDF renderer, cross-vendor denial | Source-level closed in this tranche |
| Delivery → customer invoice | Persisted handoff, narrow queued replay, exact invoice reuse, bottleneck/deep link | Closed in the preceding tranche |
| Payroll finalization → bank file/payslip | Persisted generation/email states, retries/manual outcomes, recovery actions | Hardened; bank/provider delivery remains deployment-dependent |
| Payroll finalization → Accounting GL | Pending/posted/manual/not-required state, durable narrow request, authoritative lock, idempotent journal link, retry route, finance bottleneck | **Closed in this tranche**; live chart-of-accounts and posting-period configuration remains external |
| Separation → clearance → account/final pay | Locked transitions, fail-closed loan/property/final-pay reads, idempotent posting/deactivation | Hardened; live HR/Accounting smoke remains |
| HR self-service overtime → Attendance → Payroll | Canonical create/decision/cancel/restore service, locked lifecycle transitions, cancellation provenance, durable resubmission | Source-level closed in this tranche |
| HR self-service loans → Loans → Approval → Payroll | Canonical request/list path, locked employee and loan transitions, approval records, durable submission | Source-level closed in this tranche |
| Stock movement → reorder | Durable stock movement event and idempotent replenishment listener | Hardened as an inventory process |
| Stock movement → GL | Explicit movement state, exact journal link, durable narrow replay, operator retry, and finance bottleneck; GRN/non-GL ownership remains separate | **Closed in this tranche** |
| Return → Quality inspection | Explicit inspection handoff state, narrow retry, disposition gate, bottleneck/deep link | **Closed in this tranche** |
| Return disposition → Inventory/Accounting/Purchasing | Authoritative locked lifecycle transitions, terminal replay guard, idempotent stock/credit/supplier effects | **Closed in this tranche** |
| Customer complaint → Quality NCR | Explicit NCR handoff state, durable replay, closure gate, bottleneck/deep link | **Closed in this tranche** |
| Customer portal complaint → CRM → Quality | Portal-scoped order resolution, system-user audit context, shared 8D/NCR creation, resource contract | **Closed in this tranche** |

## Closed slice: Production → Inventory

For a good `WorkOrderOutput`, the service now persists a handoff status and
timestamp. A successful movement is linked by
`reference_type=work_order_output` and the exact output ID. A failed expected
handoff is marked `manual_required` and records `ProductionReceiptRequested`
in the same transaction as the output event. The queued listener:

- reloads the output and resolves a valid actor;
- retries only the receipt handoff;
- reuses an exact/linked movement on replay;
- adopts a legacy work-order-level movement only when there is exactly one
  good output and one legacy movement;
- leaves ambiguous legacy data manual rather than guessing; and
- records `completed`, `skipped`, or `manual_required` business outcomes while
  allowing unexpected failures to reach queue retry/dead-letter handling.

The work-order detail exposes a permission-gated retry endpoint and the
`production_output_without_receipt` bottleneck deep-links to the parent work
order using the handoff timestamp as its SLA clock.

## Closed slice: Inventory → Accounting GL

The audit confirmed that `StockMovementService::move()` is the single stock
mutation boundary, but the previous GL service could return after logging when
Accounting setup was missing. The movement now has an explicit
`gl_handoff_status`, reason, and attempt timestamp:

- adjustments, cycle counts, material issues, scrap, supplier returns, and
  production receipts post through `MovementGlPostingService`;
- GRN receipts remain owned by `GrnGlPostingService`, while transfers, opening
  balances, deliveries, disabled Accounting, and zero-value movements are
  explicitly `not_required`;
- missing COA/configuration, missing Accounting tables, and blocked posting
  business rules commit the physical movement as `manual_required` rather than
  losing the warehouse fact;
- successful posting stores the exact movement-level journal reference;
- `StockMovementGlPostingRequested` is allow-listed in the outbox codec and
  recorded with a stable `inventory / stock_movement / gl_handoff` chain step;
- the queued listener and permission-gated retry endpoint lock the movement,
  reuse an existing journal link, and never change stock quantity;
- `inventory_movement_without_gl` uses the persisted handoff timestamp and
  deep-links Finance to the filtered movement list.

## Closed slice: Return Management → Quality inspection

Return inspection staging is now a guarded cross-module boundary. An RMA with
product-linked lines remains `received` when any required inspection cannot be
created; it records `manual_required`, a reason, and an authoritative handoff
timestamp. The durable `ReturnInspectionRequested` event and
`returns / return_request / inspection_handoff` chain step let the queued
listener retry only inspection staging. Existing inspections are reused by
RMA/product, so replay cannot create duplicate inspections.

Disposition and completion reject a known manual-required handoff. Quality or
return operators can retry from the RMA detail, while
`return_without_inspection` deep-links stale RMAs to the same recovery target.

## Closed slice: CRM complaint → Quality NCR

Complaint creation no longer swallows every NCR exception. Expected Quality or
data-setup failures commit the complaint and its 8D shell with
`ncr_handoff_status=manual_required`, a durable `ComplaintNcrRequested` event,
and the `crm_quality / customer_complaint / ncr_handoff` chain step. Unexpected
failures still roll back the complaint. The queued listener retries only NCR
creation, reuses an existing complaint-linked NCR, and records business
outcomes separately from queue lifecycle.

Complaint resolve/close now require a generated linked NCR. The complaint
detail exposes a permission-gated retry action and
`complaint_without_ncr` deep-links stale manual handoffs to CRM.

The B2B customer portal no longer creates complaint rows directly. It resolves
the optional order hash against the authenticated customer, impersonates the
configured system audit user, and calls the same `ComplaintService::create`
path used by CRM staff. That preserves the 8D shell, NCR handoff state, audit
foreign key, and rollback/manual-recovery semantics across both entry points.

The supplier portal acknowledgement path was also rechecked as a second
external boundary. It now locks and re-reads the PO, requires the authoritative
state to remain `approved`, and delegates the sent transition to
`PurchaseOrderService::markAsSent`. This keeps the dispatch proof, durable
`PurchaseOrderSent` event, GRN trigger, and chain broadcast in one canonical
mutation path; a late terminal-state acknowledgement is rejected without a
sent handoff.

## Closed slice: Supplier portal shipment update → PO logistics metadata

The shipment-update endpoint previously saved the route-bound PO and ignored
the validated `shipped_date` and `notes` fields. It now locks and reloads the
PO inside a transaction, rechecks vendor ownership, preserves the latest
remarks, records all supplied shipment metadata, and rejects cancelled,
received, or closed POs. This keeps shipment metadata from racing the PO's
acknowledgement, receiving, or cancellation transitions.

## Closed slice: Supplier portal bill detail/PDF artifact

The supplier invoice list and detail endpoints already returned Accounts
Payable `Bill` rows, but the PDF endpoint implicitly bound the customer AR
`Invoice` model and rendered the customer-invoice template. It now resolves a
vendor-scoped `Bill` through the same service used by detail, then delegates to
`PdfService::bill`. Valid supplier bills—including drafts—render through the AP
template; another vendor's bill remains a 403.

## Closed slice: Supplier portal invoice → Accounts Payable draft

The supplier invoice route previously called the ordinary bill creation path,
which posted the AP journal immediately even though the portal contract said it
created a draft. The route now locks and reloads the PO, locks the vendor while
checking the vendor-scoped bill number, and delegates to an explicit
`BillService::createDraft` entry point. The draft preserves item identity and
the three-way-match snapshot, but creates no journal entry; `postDraft()` is
the human review boundary and recomputes the match before any ledger mutation.

Same-vendor retries for the same PO and bill number return the existing bill,
while a number already attached to another PO is rejected. Optional uploaded
invoice files are attached to the bill only after the draft is created, and a
failed transaction cleans up the stored file.

## Closed slice: GRN → incoming Quality control

The existing synchronous trigger and durable `GoodsReceiptNoteCreated` event
remain in place, but the receiving boundary now records
`incoming_qc_handoff_status` as `not_started`, `generated`, `manual_required`,
or `not_required`. Known Quality setup/data failures become a manual outcome;
unexpected failures leave the GRN pending and visible for queue replay. The
fail-closed acceptance gate still requires every incoming inspection to pass or
be explicitly cancelled before stock can move.

The GRN detail exposes a Quality-permissioned retry, and
`grn_without_incoming_qc` uses the handoff timestamp and existing GRN deep link
to surface stale pending receipts. Replaying or retrying the listener reuses
existing line inspections and does not duplicate QC work.

## Evidence

Completed evidence for this slice:

- Targeted backend: **5 tests / 28 assertions**, including successful receipt,
  reject-only output, missing item/location manual handoff, replay idempotency,
  and the permission-gated operator retry.
- Prior full backend gate after the main slice: **1,452 tests / 4,972
  assertions**, passing.
- All-file PHP syntax check: passing.
- `git diff --check`: passing.
- SPA ESLint, TypeScript, and Vitest: **24 files / 202 tests**, passing.
- Production Compose validation: passing.
- Disposable `make chain-smoke`: passing with the new migrations, a real
  Redis worker, target-only replay, and zero failed jobs.

Completed evidence for the Inventory → Accounting slice:

- Focused backend: **25 tests / 139 assertions** across movement posting,
  listener wiring, and bottleneck detection.
- Movement-specific regression: **9 tests / 41 assertions**, including
  missing-configuration commit/replay, duplicate delivery idempotence,
  disabled-Accounting semantics, and the permissioned hashed retry route.
- Final full backend gate: **1,456 tests / 5,003 assertions**, passing in
  **705.86 seconds**.
- Final frontend/release gate: SPA **24 files / 202 tests**, ESLint,
  TypeScript, all-file PHP syntax, `git diff --check`, and production Compose
  validation all passed.
- Final `make chain-smoke`: migrations through `2026_08_10_250000` applied,
  real Redis worker replay completed, target business outcome recorded, and
  **0 failed jobs**.

Current local release evidence after the HR self-service overtime and loan
boundary corrections is green:

- Full backend: **1,481 tests / 5,153 assertions**, passing in **709.36s**.
- Customer portal: **14 tests / 45 assertions**; supplier portal: **19 tests /
  49 assertions**.
- HR/Attendance overtime focus: **25 tests / 86 assertions**.
- HR self-service loan focus: **10 tests / 39 assertions**.
- SPA: **24 files / 202 tests**, plus ESLint and TypeScript, passing.
- All-file PHP syntax, `git diff --check`, and production Compose validation
  are green.
- `make chain-smoke` applied migrations through `2026_08_11_100000`, drained a
  real Redis worker, recorded the replay business outcome, and ended with
  **0 failed jobs**.

Latest focused evidence for the supplier-invoice slice remains green:

- Supplier portal: **17 tests / 45 assertions**.
- Accounting bill, bill-item, and GRN auto-bill regression suites: **14 tests /
  56 assertions**.
- The full release gate above includes the supplier-invoice slice.

Latest focused evidence for the supplier-PDF slice is green: supplier portal
**19 tests / 49 assertions**. The full release gate above includes this
controller correction.

Latest focused evidence for the HR self-service overtime slice is green:
**25 tests / 86 assertions**. It covers canonical cancellation, cancellation
provenance, rejected-request restore denial, owner-only restore, and durable
outbox publication on cancel/resubmit.

## Closed slice: HR self-service loans → Loans/Approval/Payroll

The self-service loan endpoint previously inserted legacy-shaped rows directly
into `employee_loans`, bypassing salary-limit and duplicate-loan policy, loan
number and amortization setup, approval records, and the durable
`LoanSubmitted` event. It now reads and writes the authoritative `EmployeeLoan`
model through `LoanService`, preserving the existing response contract while
using canonical loan type, balance, period, status, and purpose fields.

The request path locks the employee row to serialize same-employee duplicate
submissions. Approve, reject, and cancel reload and lock the authoritative loan
row before checking status or writing approval state, so stale portal/admin
requests cannot commit an invalid transition.

Focused verification is green: **10 tests / 39 assertions**, covering canonical
submission and outbox publication, authoritative list fields, and duplicate
request rejection. The full backend gate is **1,481 tests / 5,153 assertions**
in **709.36s**; static PHP syntax, `git diff --check`, and production Compose
validation pass. `make chain-smoke` reached migration `2026_08_11_100000`,
completed real-worker replay, and ended with **0 failed jobs**.

## Closed slice: Payroll finalization → Accounting GL

Payroll finalization previously committed the locked period and then dispatched
`PostPayrollToGlJob` directly from the controller. A queue dispatch failure could
therefore leave a finalized payroll with no durable GL retry, no operator-facing
state, and no bottleneck signal. The posting service also checked the passed
model before its transaction lock, so concurrent direct calls were not protected
by the authoritative period row.

The boundary now:

- marks the GL handoff `pending` and records a distinct `PayrollGlPostingRequested`
  outbox event plus `gl_handoff` chain-step row in the finalization transaction;
- locks and reloads the payroll period before checking status or journal linkage,
  and records `posted`, `manual_required`, or `not_required` explicitly;
- uses a queued listener with chain outcomes, narrow replay, and a permissioned
  `POST /api/v1/payroll-periods/{period}/retry-gl` recovery action; and
- exposes a finance bottleneck for finalized/disbursed periods without a linked
  journal entry, while the detail page shows the status, reason, linked entry,
  and retry action.

Focused verification is green: **8 tests / 34 assertions** in the payroll GL
handoff suite; the handoff-plus-chain-wiring focus is **13 tests / 88
assertions**, including the terminal void/replayed-request no-op regression.
The full backend gate is **1,489 tests / 5,191 assertions** in **715.46s**;
SPA is **24 files / 202 tests**, with ESLint and TypeScript passing. All-file
PHP syntax, `git diff --check`, and production Compose validation pass.
`make chain-smoke` applied migrations through `2026_08_11_120000`, drained a
real Redis worker, completed target replay, and ended with **0 failed jobs**.

## Closed slice: Final pay source integrity + Return Management lifecycle serialization

The next source audit found two cross-module integrity hazards:

- `FinalPayService` treated any loan, cash-advance, or employee-property
  source-read failure as a zero balance. The final-pay computation now reports
  the source failure and raises an actionable business error, so unavailable
  deductions cannot silently understate a separation settlement.
- Return Management validated several route-bound models before entering its
  transaction. Submit, approve, receive, inspect, reject, cancel, and complete
  now reload and lock the authoritative RMA row before checking state or
  mutating related records. Completion therefore rejects a stale replay before
  issuing a second stock movement or repeating terminal cross-module effects.

The return-recipient fan-out was also narrowed to the identity columns it
uses, with model-wide role eager loading disabled. Migration
`2026_08_11_130000_add_user_permission_audience_indexes` adds the role-first
active-user lookup and the permission-first pivot lookup used by role-based
notification audiences.

Evidence for this slice:

- Final-pay focus: **20 tests / 47 assertions**.
- Return Management focus: **44 tests / 199 assertions**; the combined
  HR/return/chain focus was **79 tests / 330 assertions**.
- Full backend: **1,491 tests / 5,196 assertions** in **771.17s**.
- SPA: **24 files / 202 tests**, typecheck, and token discipline across **731
  files** passed; the production bundle built successfully.
- All-file PHP syntax, `git diff --check`, and production Compose validation
  passed.
- `make chain-smoke` applied the new migration through
  `2026_08_11_130000`, completed the real Redis worker replay, and ended with
  **0 failed jobs**.

The full gate no longer reports the earlier roughly 1.9-second return audience
query. Local telemetry still shows role-permission hydration reads around
100–220 ms; production-cardinality staging verification remains appropriate.

## Closed slice: Payroll compute claim → durable execution + scheduler idempotency — 2026-08-11

The fresh direct-dispatch inventory found one remaining value-changing payroll
gap: the HTTP and automatic compute paths claimed a period as `Processing`, then
dispatched `ProcessPayrollJob` directly. A queue outage could therefore strand a
valid claim without a durable work request. The auto scheduler also relied on
application prechecks, so two scheduler processes could both pass the check and
create duplicate auto periods.

The boundary now:

- wraps the conditional compute claim and an allow-listed
  `PayrollComputationRequested` outbox event plus `compute` chain-step row in
  one database transaction;
- routes both manual and automatic compute through
  `RunPayrollComputationOnRequested`, which executes the existing payroll
  engine under an authoritative `Processing` check and a per-period
  `WithoutOverlapping` lock;
- treats duplicate/stale requests as explicit safe no-ops and preserves the
  compute job's catastrophic-failure claim release on listener dead-letter;
  and
- adds `payroll_periods.auto_idempotency_key` with a nullable unique index, so
  human-scoped periods remain possible while concurrent auto-creation cannot
  create the same window twice.

Evidence for this slice:

- compute lifecycle/recovery focus: **26 tests / 77 assertions**;
- durable handoff focus: **3 tests / 8 assertions**;
- auto-period focus: **2 tests / 11 assertions**;
- full payroll feature suite: **231 tests / 665 assertions** in **184.67s**;
- migration through `2026_08_11_140000_add_auto_payroll_idempotency_key` applied
  cleanly; the live database had **0 duplicate auto-created windows** before
  the guard was added.

## Ranked open findings

### Performance follow-up

The return-management audience hotspot is closed at source level: the current
full gate no longer reports the earlier roughly **1.9s** query after the
projection and audience indexes. The remaining local warning is a separate
role-permission hydration read at roughly **100–220 ms**; profile it against
staging cardinality and cache/index behavior before high-volume rollout.

### Release verification and external prerequisites

The source-level handoff audit is closed for the identified Return, Complaint,
GRN, Production, Delivery, Stock/GL, payroll bank-file and payroll GL, HR
self-service overtime, HR self-service loan, customer-portal, supplier
dispatch, supplier-invoice, preventive-maintenance, and depreciation
boundaries. Remaining work is release evidence and external configuration:

- The remaining direct scheduled-job gap was preventive maintenance and
  monthly depreciation. Both now stage allow-listed requests in `event_outbox`
  and execute through retryable listeners; depreciation also locks assets in
  stable order to prevent overlapping periods from overwriting accumulated
  balances.
- Year-end leave and budget actuals already stage durable requests; their
  synchronous job classes remain execution primitives behind those listeners.
- `PostPayrollToGlJob` has no current application caller after the durable GL
  handoff migration. It remains as a compatibility adapter that stages the
  durable outbox handoff instead of posting directly; retire it only after the
  compatibility/reference audit.

- Select the real supplier transport and provider receipt contract.
- Decide whether incoming QC/GRN quantities are decimal-capable and migrate
  validation/storage consistently if required.
- Run migrations, queue replay, and the golden process path against staging
  PostgreSQL/Redis with real Accounting, mail, bank, and provider secrets.

## Next move

The next move is controlled staging verification: apply migrations before
consumers, run the same Redis replay and golden process smokes, then verify
external Accounting, mail, bank, and supplier-provider prerequisites before
claiming live process hardening complete.

## Expanded process/failure-path audit — 2026-08-11

The direct-job and scheduler audit is now documented in the full [process
failure matrix](PROCESS-FAILURE-MATRIX-2026-08-11.md). It supersedes the older
open items above for year-end leave and budget actuals: both now stage durable
outbox requests and execute through retryable listeners.

This tranche also closed false-green batch behavior and scheduler recovery
gaps:

- year-end leave processing is durable and actor-aware;
- budget actuals has a reachable POST route, durable queue handoff, and throws
  when no fiscal year exists instead of reporting a successful no-op;
- scheduled exports have an expiring database lease, attempt/error state,
  non-zero failure reporting, and visible admin status;
- monthly KPI and supplier snapshot batches continue independent rows but exit
  non-zero with exact failed definitions/vendors;
- safety-stock, alert checks, AR dunning, and stale-run reapers now surface
  partial failures instead of returning green after silently skipping work;
- every registered scheduler overlap lock has an explicit bounded 10- or
  120-minute expiry, and `schedule:run-fail-fast` observes Laravel's
  `ScheduledTaskFailed` event so the production scheduler exits non-zero and
  Compose can restart it;
- AR dunning reloads and row-locks each invoice candidate, and defers the
  queued reminder until the transaction commits, preventing concurrent
  duplicate tiers;
- monthly depreciation has an explicit year/month command for missed-period
  recovery;
- audit archive generation streams rows into a validated temporary gzip,
  replaces corrupt prior archives, and atomically publishes the final file;
- production host-cron backups use the persistent Compose mount, validate and
  retain the host copy, and perform off-site upload from the host rather than
  assuming AWS tooling in the Postgres image;
- the queue configuration is verified against the actual 1,800-second payroll
  timeout, with a 2,400-second default Redis retry lease; and
- monthly export dates preserve day 29–31 semantics by using the last valid day
  of short months rather than silently clamping every schedule to day 28.

New regression evidence covers queue, scheduled-export, KPI, budget, dunning,
depreciation, archive execution, supplier-provider acceptance-before-crash,
fail-fast scheduler behavior, real worker interruption/redelivery, and legacy
payroll compatibility routing; migration
`2026_08_11_150000_add_execution_tracking_to_scheduled_exports` applied cleanly
to the live PostgreSQL container. The local execution/static-analysis gate is
current: **1,539 backend tests / 5,354 assertions**, PHPStan, **24 SPA test
files / 202 tests**, the focused scheduler/queue/provider/backfill regression
(**16 tests / 54 assertions**, including an actual due-task failure returning
non-zero), ESLint, TypeScript, token audit/build,
PHP/shell syntax, backup/restore, scheduled-job durable handoff focus (**9
tests / 42 assertions**), real Redis replay, and the killed-worker
redelivery smoke all pass. The audited
PHP files pass their scoped Pint check. Repository-wide Pint remains an
explicit baseline debt (**1,531 issues across 2,165 files**) and was not
mass-formatted across the dirty worktree. The remaining process evidence is
staging-only scheduler restart, missed-period backfill, external-provider
scenarios, Redis failover, and deployed backup freshness/off-site/app-health/
rollback verification.
