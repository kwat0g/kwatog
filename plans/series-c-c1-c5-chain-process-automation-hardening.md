# Series C (C1–C5) — Chain Process Automation Hardening

> **Mode note:** This plan is delivered from Architect mode. Implementation must be carried out in `code` mode (preferably `superpowers-tdd` for TDD discipline and `kwatog-quality-gate` for the final lint/typecheck/test gate). The user must switch modes themselves; this plan does not request a mode switch.

---

## 0. Scope summary

C1–C5 from [`docs/NEW-TASKS-V2.md`](../docs/NEW-TASKS-V2.md:316) are **automation/wiring tasks**, not greenfield modules. Most domain services already exist:

- [`MrpEngineService`](../api/app/Modules/MRP/Services/MrpEngineService.php:1), [`CapacityPlanningService`](../api/app/Modules/MRP/Services/CapacityPlanningService.php:1)
- [`AutoPurchaseOrderService`](../api/app/Modules/Purchasing/Services/AutoPurchaseOrderService.php:1), [`PurchaseOrderService`](../api/app/Modules/Purchasing/Services/PurchaseOrderService.php:1), [`ThreeWayMatchService`](../api/app/Modules/Purchasing/Services/ThreeWayMatchService.php:1)
- [`SalesOrderService`](../api/app/Modules/CRM/Services/SalesOrderService.php:1), [`WorkOrderService`](../api/app/Modules/Production/Services/WorkOrderService.php:1)
- [`GrnService`](../api/app/Modules/Inventory/Services/GrnService.php:1), [`AutoReplenishmentService`](../api/app/Modules/Inventory/Services/AutoReplenishmentService.php:1)
- [`InspectionService`](../api/app/Modules/Quality/Services/InspectionService.php:1), [`CoCService`](../api/app/Modules/Quality/Services/CoCService.php:1)
- [`OnboardingService`](../api/app/Modules/HR/Services/OnboardingService.php:1), [`UserProvisioningService`](../api/app/Modules/HR/Services/UserProvisioningService.php:1), [`FinalPayService`](../api/app/Modules/HR/Services/FinalPayService.php:1), [`SeparationService`](../api/app/Modules/HR/Services/SeparationService.php:1)

Existing events: [`SalesOrderConfirmed`](../api/app/Modules/CRM/Events/SalesOrderConfirmed.php:1), [`WorkOrderStatusChanged`](../api/app/Modules/Production/Events/WorkOrderStatusChanged.php:1), [`StockMovementCompleted`](../api/app/Modules/Inventory/Events/StockMovementCompleted.php:1), [`DeliveryConfirmed`](../api/app/Modules/SupplyChain/Events/DeliveryConfirmed.php:1), [`MrpPlanGenerated`](../api/app/Modules/MRP/Events/MrpPlanGenerated.php:1).

The work is: add the missing events, wire listeners that orchestrate the chain, broadcast a unified `ChainStepAdvanced` event for real-time UI, and add a bottleneck-detection service + dashboard widget.

## 0.1 Initial implementation audit update — 2026-08-10

The repository audit and hardening pass covered the active cross-module chains, with special attention to transaction boundaries, duplicate delivery, stale status decisions, partial writes, and queue retry behavior.

### Completed in the current hardening pass

- **Procure-to-pay:** GRN acceptance/rejection now locks and rechecks authoritative rows, applies accepted quantities cumulatively, posts only incremental GL deltas, and broadcasts only after commit. Bill creation is idempotent under concurrent accepted-GRN events. Incoming QC is line-scoped when `grn_item_id` is present; legacy entity-scoped incoming rows remain protected by a partial uniqueness index.
- **Order-to-cash:** delivery reservation and delivered-quantity reconciliation are concurrency-safe; QC-pass delivery drafts validate the sales-order line and quantity before creating a header; cancellation/deletion guards prevent ledger corruption.
- **Production/MRP:** work-order transitions, machine status changes, scheduling, reassignment, and capacity checks lock fresh state and re-run transition rules inside the transaction. Work-order completion events are deferred until commit.
- **Hire-to-retire and operations:** leave-balance initialization, user provisioning/deactivation, recurring NCR spawning, overtime, payroll-period, replenishment, and maintenance events now preserve transaction/after-commit boundaries. Unexpected stateful listener failures propagate to queue retry/`failed_jobs`; expected business handoffs remain explicit no-ops or warnings.
- **Verification:** the current backend suite passes `1361 tests / 4608 assertions`; the SPA passes `24 test files / 186 tests`, lint, and TypeScript checks.

### Remaining system-level gap at the time of the initial audit

The repository did not yet have a durable transactional outbox or chain-step ledger. Queue configuration uses Redis with `after_commit=false`, so a committed database transition could lose its event between commit and queue publication. `PurchaseOrderApproved` also notified rather than performing the planned `SendPOToSupplier` action: the vendor email/PDF primitives exist, but the product has not defined the outbound provider, delivery state, retry policy, or idempotency key.

### Next move at the time of the initial audit

1. Agree the supplier-dispatch contract (email/PDF, EDI, or both), including sender identity, delivery states, retry/dead-letter behavior, and the idempotency key.
2. Add a transactional outbox plus a durable chain-step/run ledger. Publish after commit, record attempts and failures, and make listeners idempotent by `(chain, entity, step)` rather than only by row checks.
3. Implement `SendPOToSupplier` against that contract, then add the missing incoming-pass “accept GRN → 3-way match → draft bill” orchestration with explicit compensation/manual-review states.
4. Convert the remaining cross-module listeners to the same outbox/job middleware and add a chain bottleneck monitor fed by the ledger.

Until steps 1–2 were scoped and implemented, this plan was an audit-backed hardening baseline, not proof that every chain was fully autonomous.

## 0.3 End-to-end execution tranche — 2026-08-10

The audit moved the remaining cross-module gaps into explicit durable states:

- **Listener completion:** `chain_listener_runs` correlates queued listener payloads to the originating outbox message and records processing, retrying, completed, and failed outcomes. The bottleneck endpoint and dashboard now show publication and listener health together; failed listener recovery points to the queue retry path.
- **Incoming QC:** a passed incoming inspection is consumed by `AcceptGrnOnIncomingQcPass`. The listener waits for every incoming inspection on the GRN, then calls the locked/idempotent GRN acceptance path. Missing automation actors fail visibly for retry rather than silently accepting stock.
- **Three-way match:** partial quantities on a present bill line are valid; omitted, duplicate, unmatched, over-PO, over-GRN, and price-variance lines remain reviewable/blocking. Auto-created draft bills re-match at creation and again at posting. A blocked post persists the latest snapshot and requires an audited override reason before GL posting.
- **Supplier dispatch:** `SupplierDispatchGateway` is the provider boundary. The current `SupplierPortalDispatchGateway` records that an approved PO is available to active portal users, but does not claim that an email was sent. Without an active portal recipient it records `manual_required`; provider errors are retryable and persisted. A unique idempotency key and one dispatch row per PO prevent duplicate publication. The existing “Mark as sent” / supplier acknowledgement remains the proof boundary and closes the dispatch row as `confirmed`.
- **Operator visibility:** dispatch status, attempts, provider note, and confirmation timestamps are exposed on internal PO detail; the automation summary includes pending portal/manual/failed dispatch counts.

This tranche intentionally does not invent an email/EDI provider contract. Selecting sender identity, transport, delivery receipt semantics, and credentials is still an explicit deployment decision; until then, the portal/manual states prevent the system from falsely marking a PO as sent.

## 0.5 Operational recovery tranche — 2026-08-10

The release and scheduler audit found that the supplier-dispatch dashboard
could report a stale `pending` row without an operator-safe scheduled recovery,
and that the durable `PurchaseOrderCancelled` event had no listener to close a
dispatch row if cancellation replayed after the request completed. The gap is
now closed:

- `supplier:dispatch-recover` runs every five minutes and reclaims only stale
  `pending` rows. The provider idempotency key remains stable, so a worker
  crash between provider publication and local finalization cannot create a
  second logical dispatch.
- `--retry-failed` is explicit and reviewable; `portal_available`,
  `manual_required`, and `confirmed` rows are never automatically resent.
- PO rejection/cancellation closes the dispatch row as `cancelled` in the
  owning transaction and through an idempotent cancellation listener for
  outbox replay. Late acknowledgements cannot resurrect a cancelled row.
- Stateful P2P handoffs now fail visibly when their automation actor is
  unavailable: PO-sent → expected-GRN and GRN-accepted → draft-bill listeners
  raise a retryable business error instead of marking a missing document as
  completed. Shared actor resolution excludes inactive users.
- The automation summary now distinguishes stale work from cancelled history,
  while the production runbook documents queue/scheduler logs and recovery
  commands.

The production compose audit confirmed that `api`, `queue`, and `scheduler`
are separate restartable services; the scheduler owns both outbox polling and
supplier-dispatch recovery. No LLM/agent runtime, tool registry, or agent
memory layer exists in this PHP/React ERP, so agent-specific architecture
controls are not applicable to the current process surface.

## 0.6 Cross-module failure truthfulness and queue lease tranche — 2026-08-10

The next process audit found two reliability gaps at the boundary between a
durable domain event and its queued listener:

- `RejectGRNOnQcFail` previously logged and returned when a failed incoming QC
  inspection had no eligible active actor. The GRN remained `pending_qc`, but
  the listener could be recorded as completed. It now raises a
  `BusinessRuleException`, leaving the state unchanged while exposing the
  handoff to queue retry/`failed_jobs` and operator replay.
- Redis `retry_after` was 90 seconds while the production worker timeout was
  120 seconds. The lease is now configurable as `REDIS_QUEUE_RETRY_AFTER` and
  defaults to 180 seconds; a configuration test guards the invariant.

Verification for this tranche:

- Focused cross-module checks: **15 tests / 40 assertions**.
- Full backend suite: **1,380 tests / 4,694 assertions**.
- PHP syntax and `git diff --check` are clean.

The current audit still has one intentionally unresolved external process
decision: real supplier transport requires a product decision before an
email/EDI adapter can be built. The approved-PR → PO conversion fallback was
closed in the next tranche below.

## 0.7 P2P conversion serialization and operator-outcome tranche — 2026-08-10

The approved-PR → PO handoff now has one authoritative source-row boundary and
an operator-visible outcome:

- `purchase_requests.po_conversion_status` persists `not_started`, `pending`,
  `manual_required`, or `converted`, with a note and timestamp for manual
  recovery. Existing converted PRs are backfilled during migration.
- Final approval marks the conversion `pending`. Missing preferred supplier,
  missing price, missing automation actor, and known business-rule failures
  record `manual_required` while leaving the PR `approved`; notifications are
  emitted only when the durable reason changes.
- `PurchaseOrderService::convertFromPr()` locks the authoritative PR row,
  rechecks live POs, and returns existing live POs on a replay. Direct PO
  creation locks the same PR row, preventing a manual/automatic race. The
  source-PR reopen path uses the lock as well and resets the conversion
  outcome only after the last live PO is gone.
- The purchasing list and detail surfaces expose the manual handoff and its
  reason, while keeping the existing **Convert to PO** action as the recovery
  path.

This preserves the valid one-PR-to-many-vendors shape without relying on an
incorrect unique index on `purchase_request_id`.

Verification for this tranche:

- Focused purchasing/chain checks: **31 tests / 106 assertions**.
- Full backend suite: **1,382 tests / 4,707 assertions**.
- SPA: ESLint, TypeScript, and Vitest — **24 test files / 201 tests**.
- PHP syntax and `git diff --check` are clean.
- Testing database migration status: `2026_08_10_100000` through
  `2026_08_10_140000` are applied. The normal development database was not
  mutated by the test verification command.

## 0.8 Payroll finalization to bank-file outcome tranche — 2026-08-10

The H2R finalization handoff no longer stops at “event published”:

- `payroll_periods.bank_file_status` persists `not_started`, `pending`,
  `manual_required`, or `generated`, with a note and timestamp. Historical
  finalized periods without a bank-file record are surfaced as
  `manual_required` during migration.
- Finalization marks the bank-file step `pending` in the same transaction as
  the finalized status and outbox event. The generation listener runs through
  the queue boundary, locks the period, and treats an existing record or
  `generated` state as an idempotent replay.
- Missing automation attribution, bank-account shortfalls, invalid settings,
  reconciliation failures, and generation errors persist an actionable manual
  handoff. The manual download endpoint also records `generated` on success;
  failed storage writes clean up their uncommitted private file.
- Bank-file generation rechecks the authoritative period row and accepts both
  finalized and disbursed periods, matching the UI recovery action. The period
  resource and detail page show the pending/manual reason instead of leaving
  operators to infer it from an empty bank-file list.

Focused payroll verification: **29 tests / 57 assertions**. The full backend
and SPA gates must be rerun after this tranche.

## 0.9 Production-to-quality and GRN handoff tranche — 2026-08-10

The remaining work-order and incoming-receipt audit found two ways for a
stateful QC handoff to appear complete while no inspection existed:

- In-process and outgoing QC listeners silently returned when a started or
  completed work order had no product or no positive production quantity. They
  now raise an actionable `BusinessRuleException`; valid internal-WO and
  duplicate-event no-ops remain unchanged.
- Outgoing QC previously treated every inspection-service exception as “no
  active spec” and created a bare fallback inspection. Only the expected
  business-rule case now uses that fallback; database/runtime failures escape
  for queue retry and failed-job visibility. Creator attribution is also
  explicit instead of falling back to an arbitrary first user.
- `GrnService` now enforces positive received quantities at the service
  boundary, including after UOM conversion and during draft finalization, so a
  caller cannot advance a zero receipt into `pending_qc` while the incoming-QC
  listener intentionally skips zero-quantity draft lines.

Focused verification for this tranche: **31 tests / 89 assertions**, covering
the QC listeners, GRN finalization, incoming QC, the fail-closed GRN gate, and
listener wiring.

Release verification after this tranche:

- Full backend suite: **1,389 tests / 4,728 assertions**.
- SPA: ESLint, TypeScript, and Vitest — **24 test files / 201 tests**.
- PHP syntax and `git diff --check` are clean.
- Testing database migrations through
  `2026_08_10_150000_add_bank_file_generation_state` are applied. The normal
  development database was not mutated by verification.

## 0.10 Supplier-dispatch replay and cancellation-event closure — 2026-08-10

The focused supplier/P2P audit found two cross-module replay gaps at the PO
approval and rejection boundaries:

- A replayed `PurchaseOrderApproved` event for a PO already marked `sent` was
  reconciled to `confirmed`, but then fell through and called the supplier
  gateway again. The dispatch service now treats every non-`pending` claim as
  a terminal/reconciled result and does not cross the provider boundary.
- Explicit cancellation already recorded a durable cancellation event, but
  rejection only changed the PO and synchronously closed the dispatch row.
  Rejection now records the same allow-listed `PurchaseOrderCancelled` event
  and `chain_step_runs` row, so future listeners and outbox replay see both
  terminal paths consistently.
- Explicit cancellation now re-reads and locks the authoritative PO before
  evaluating received/GRN guards or applying the terminal update. A stale
  route-bound model can no longer overwrite a PO that became received or
  closed concurrently.

Focused verification: **18 tests / 53 assertions** across supplier dispatch,
PO cancellation, and PR reopening/rejection.

Release verification after this tranche:

- Full backend suite: **1,391 tests / 4,735 assertions**.
- The existing SPA gate remains green: ESLint, TypeScript, and Vitest — **24
  test files / 201 tests**; this tranche changed no SPA files.
- PHP syntax and `git diff --check` are clean.
- Testing database migrations through
  `2026_08_10_150000_add_bank_file_generation_state` are applied.

## 0.11 Authoritative-row lifecycle closure — 2026-08-10

The next cross-module audit followed the stateful boundaries beyond event
publication and found the same stale-model hazard in four process families:

- **Quality:** measurement entry, completion, and cancellation now lock and
  re-read the inspection row inside the transaction. Terminal inspection
  outcomes cannot be reopened or overwritten by a delayed request, and the
  failure-side NCR handoff reloads committed inspection state before creating
  its record.
- **Order-to-cash:** sales-order confirmation locks the authoritative order and
  customer before credit validation/MRP creation; cancellation rechecks the
  live order before mutating it. Same-customer confirmations therefore
  serialize their credit exposure decision, and a stale request cannot
  resurrect a cancelled or invoiced order.
- **Hire-to-retire:** payroll approval, finalization, disbursement, and voiding
  now perform status, anomaly, proof, maker-checker, and GL-related guards on a
  locked authoritative period row. A stale request cannot overwrite a newer
  terminal state or duplicate a disbursement/outbox handoff.
- **Delivery/proof:** receipt-photo upload and deletion now lock and recheck the
  delivery before writing proof rows or removing the shipment. File cleanup
  remains rollback-safe and confirmation/status transitions already serialize
  the associated sales order.

Focused verification for this tranche: **77 tests / 217 assertions** across
quality, CRM, payroll, and supply-chain lifecycle/concurrency suites.

One data-contract decision remains intentionally open: GRN quantities support
two-decimal values, while incoming-QC inspection `batch_quantity` and related
NCR affected quantities are integer-backed and the trigger currently casts the
GRN quantity. The safe next move is to choose whether QC quantities become
decimal-capable or GRNs must be whole-unit for QC-controlled items; silently
rounding or changing the schema would alter acceptance semantics.

The real supplier email/EDI transport decision remains the other external
boundary. Until those two decisions are made, the durable portal/manual
supplier states and the current integer quantity behavior remain explicit,
operator-visible constraints rather than inferred success.

Release verification after this tranche:

- Full backend suite: **1,402 tests / 4,756 assertions**.
- SPA: ESLint, TypeScript, and Vitest — **24 test files / 201 tests**.
- PHP syntax checks and `git diff --check` are clean.
- Testing database migrations through
  `2026_08_10_150000_add_bank_file_generation_state` are applied. The normal
  development database was not mutated by the verification commands.

## 0.12 Replay-safe source claims and truthful outbound delivery — 2026-08-10

The next audit pass followed queued source events into their first stateful
side effect and found four additional handoff gaps:

- **Expected receipt staging:** duplicate or stale `PurchaseOrderSent` events
  now re-read and lock the authoritative PO before creating an expected GRN.
  A cancelled/non-sent PO is a no-op, and the PO lock prevents duplicate draft
  GRNs from winning a check-then-create race.
- **Automatic PR conversion:** approved-PR conversion now claims the source PR
  under a lock and only proceeds from the authoritative `approved` state.
  System-created POs preserve their originating PR foreign key and are marked
  automatic at creation, so a stale approval event cannot relabel a manual PO.
- **Machine breakdown:** queued breakdown/restoration events lock and recheck
  the current machine state. A valid breakdown pauses the active work order but
  leaves the machine in `breakdown` until an authoritative restoration closes
  open downtime; stale events cannot mutate either side. Integer downtime
  duration is also enforced at the write boundary.
- **Low-stock replenishment:** the low-stock decision and open-PR check now
  serialize on the item row, preventing concurrent stock-movement events from
  creating duplicate auto-PRs.
- **Payslip delivery:** finalization now claims a per-payroll delivery state
  (`pending`, `queued`, `failed`, `sent`) and dispatches a retryable job. The
  `payslip_emailed_at` marker is written only after the mailer accepts the
  message; PDF/render/provider failures remain retryable instead of becoming a
  permanent false success.

Focused verification for the new boundaries is green:

- Machine breakdown/restoration plus existing status-transition checks: **5
  tests / 17 assertions**.
- Payslip claim/job delivery checks: **5 tests / 17 assertions**.
- Replenishment and adjacent inventory ledger checks: **15 tests / 57
  assertions**.
- P2P expected-receipt/conversion suites: **33 tests / 101 assertions**.

Release verification after this tranche:

- Full backend suite: **1,411 tests / 4,793 assertions** in 653.85 seconds.
- SPA: ESLint, TypeScript, and Vitest — **24 test files / 201 tests**.
- PHP syntax checks and `git diff --check` are clean.
- Testing database migrations through
  `2026_08_10_160000_add_payslip_email_delivery_state` are applied. The normal
  development database was not mutated by the verification commands.

The two previously recorded decisions remain open: choose decimal-capable QC
quantities versus a whole-unit GRN rule, and select the real supplier email/EDI
transport. Neither is being inferred from the current portal/manual or integer
fallback behavior.

## 0.13 Authoritative H2R clearance and production backup boundaries — 2026-08-10

The next audit followed the H2R clearance aggregate through its HR, identity,
and accounting handoffs, then checked the production backup path against the
actual image and compose files. Two concrete process failures were closed:

- **Separation initiation:** the employee row is locked and re-read before
  creating a clearance, and an existing open clearance blocks a replay. This
  serializes the employee-level aggregate and prevents parallel separation
  chains.
- **Checklist signing:** the clearance row is locked inside the transaction
  before the JSON checklist is read and written. Replayed item signatures are
  no-ops, stale requests cannot reopen a terminal clearance, and the fully
  signed outbox handoff is emitted only for the real completion transition.
- **Final-pay finalization:** current clearance status, final-pay readiness,
  outstanding loan rows, and the employee status are checked from locked rows.
  A second request therefore cannot post another final-pay journal entry or
  append another finalized employment-history row.
- **Identity side effect:** the queued completion listener reloads the current
  clearance and only deactivates access for `completed` or `finalized` rows;
  delayed completion events cannot deactivate a cancelled clearance.
- **Production backups:** `db:backup` now resolves its script through cached
  configuration, the production image contains the script, `pg_dump`, and the
  optional AWS CLI, and the production Postgres service mounts the documented
  operator backup path. S3 credentials/prefix values are passed to the backup
  subprocess instead of being silently dropped after config caching.

Focused H2R verification is green: **5 tests / 18 assertions**. At this
tranche boundary the backup path had contract-level coverage; the subsequent
quality/release tranche records the completed local image build and
non-destructive restore drill.

One H2R product boundary remains open: the legacy
`PATCH /hr/employees/{employee}/separate` endpoint changes employee status
directly and bypasses the formal clearance chain, while the newer
`POST /hr/employees/{employee}/separation` path creates the clearance. The
next move is to decide whether that endpoint is an intentional emergency/fast
path (and label/audit it as such) or should be retired in favor of one formal
separation process. The decision should not be inferred in code because it
changes an existing API contract.

## 0.14 Authoritative quality handoffs and compatible successor states — 2026-08-10

The final listener sweep followed queued work-order and inspection events into
their quality, delivery, and receiving side effects. Four source-of-truth gaps
were closed:

- **In-process QC:** `TriggerInProcessQC` now reloads and locks the work order
  before creating the inspection. Replays remain idempotent, cancelled or
  pre-start rows are ignored, and paused/completed/closed successor states
  still honor the original start-quality obligation.
- **Outgoing QC:** `TriggerOutgoingQC` applies the same locked source claim and
  only accepts completed or closed work orders. The inspection create/fallback
  paths remain unique-index safe, and stale snapshots cannot override current
  product, quantity, or creator data.
- **Delivery and receiving handoffs:** `CreateDeliveryDraftOnQcPass` now
  re-reads the inspection and requires the current status to be passed;
  `RejectGRNOnQcFail` requires the current failed status; the incoming-pass
  listener explicitly requires current passed rows before releasing a GRN.

Focused verification for this boundary is green: **28 tests / 51 assertions**
across the work-order QC triggers, outgoing delivery draft, incoming QC, and
GRN rejection handoffs.

Release verification for the completed tranche is green:

- Full backend suite: **1,422 tests / 4,815 assertions** in 855.84 seconds.
- SPA: ESLint, TypeScript, and Vitest — **24 test files / 201 tests**.
- All changed and untracked PHP files pass `php -l`; `git diff --check` is
  clean; the production Compose file validates; and the testing database is
  migrated through `2026_08_10_160000_add_payslip_email_delivery_state`.
- The production PHP image built successfully as
  `kwatog-api:process-audit`; Composer platform checks passed, and an image
  smoke check found the backup script, `pg_dump`, `aws`, `pdo_pgsql`, `pgsql`,
  and `redis`.
- A non-destructive backup/restore drill produced a 1.4 MB gzip dump, restored
  it into a temporary `ogami_restore_drill` database, and verified restored
  `users=14` and `audit_logs=5058` before removing only that scratch database.

The previously recorded decisions remain open: choose decimal-capable QC
quantities versus a whole-unit GRN rule; select the real supplier email/EDI
transport; and decide whether the legacy direct HR separation endpoint is an
intentional emergency path or should be retired. An actual production image
deployment and an S3 upload/restore drill remain live-environment work.

## 0.15 Transactional chain-progress publication — 2026-08-10

The next cross-module audit found a narrower but systemic crash window: several
services committed their business status first and only then created the
canonical `ChainStepAdvanced` outbox row from an `afterCommit` callback. A
process failure between those operations could leave the business state
committed without durable chain evidence or realtime recovery.

The chain-facing lifecycle services now stage their canonical progress row in
the owning transaction, while `OutboxService` still schedules delivery only
after the outermost commit. This was applied to sales-order confirmation,
cancellation, and downstream transitions; GRN accept/partial-accept/reject
and PO receipt updates; work-order lifecycle transitions; delivery updates and
confirmation; bill, invoice, and credit settlement; and purchase-order
approval, send, cancellation, rejection, and close. Purchase-order close now
also locks and re-reads the authoritative row before changing state.

The Work Order regression test proves the boundary explicitly: both the
`WorkOrderStatusChanged` and `ChainStepAdvanced` outbox rows are visible before
an enclosing transaction commits and both disappear on rollback. A committed
transition retains both rows and schedules exactly two dispatcher jobs.

Focused verification after this tranche is green: **96 tests / 375
assertions** across chain broadcasting, CRM, production, accounting,
inventory, purchasing, and delivery. A source audit now finds no
`ChainBroadcaster` call deferred from an `afterCommit` callback; remaining
`afterCommit` uses are notifications, file cleanup, or outbox queue dispatch.
The post-refactor release gate is also green:

- Full backend suite: **1,424 tests / 4,824 assertions** in 692.86 seconds.
- SPA: ESLint, TypeScript, and Vitest — **24 test files / 201 tests**.
- All changed and untracked PHP files pass `php -l`; `git diff --check` is
  clean; production Compose validates; and all migrations through
  `2026_08_10_160000_add_payslip_email_delivery_state` are applied.
- The production PHP image was rebuilt as `kwatog-api:process-audit`; the
  image smoke check again found the backup script, `pg_dump`, `aws`,
  `pdo_pgsql`, `pgsql`, and `redis`.

## 0.16 Transactional quality corrective-action handoff — 2026-08-10

The next audit pass found the same crash-window shape in Quality, outside the
chain-progress stream: a failed inspection committed first and deferred the
required NCR creation to an `afterCommit` callback. A worker or process loss in
that interval could leave a failed inspection without its corrective-action
record.

Inspection failure now opens the linked NCR and records `InspectionFailed` in
the same lifecycle transaction. NCR recurrence detection also runs inside the
NCR creation transaction because its lineage link is persisted state; only
notifications and queue delivery remain post-commit side effects. The new
regression coverage verifies both the normal handoff and an enclosing
transaction rollback, proving that the failed inspection and NCR disappear
together.

Focused Quality verification: **35 tests / 89 assertions**. The final backend
gate is green: **1,425 tests / 4,829 assertions** in 840.46 seconds. The full
PHP application and test trees pass `php -l`; `git diff --check` and production
Compose validation are clean. The current production image was rebuilt as
`kwatog-api:process-audit` and its smoke check found the backup script,
`pg_dump`, `aws`, `pdo_pgsql`, `pgsql`, and `redis`.

## 0.17 Fail-closed canonical chain staging — 2026-08-10

A fresh source audit found that the previous transactional publication fix
still had a failure path: `ChainBroadcaster` caught and swallowed exceptions
from durable outbox staging. A database, codec, or chain-definition failure
could therefore commit the business status without its canonical
`ChainStepAdvanced` evidence.

`ChainBroadcaster` now logs and rethrows durable staging failures, and rejects
unsupported model classes instead of returning a value callers could ignore.
This makes the owning lifecycle transaction fail closed. Queue or Reverb
outages remain recoverable: they occur after commit in the outbox publication
path, where the pending row is retained for scheduler recovery.

Regression coverage now proves the helper rethrows staging failures and that a
Work Order status mutation rolls back its status, status-event outbox row, and
chain-step row together when canonical staging fails.

Focused verification: **6 tests / 20 assertions**. The shared-boundary release
gate is green: **1,427 tests / 4,836 assertions** in 746.36 seconds. The full
PHP application and test trees pass `php -l`; `git diff --check` and production
Compose validation are clean. The production image was rebuilt as
`kwatog-api:process-audit`, and its smoke check found the backup script,
`pg_dump`, `aws`, `pdo_pgsql`, `pgsql`, and `redis`.

## 0.18 Stateful listener and chain-contract closure — 2026-08-10

The next queued-listener audit found one broad idempotency catch and one
canonical-chain mismatch:

- `AutoProvisionUserOnEmployeeHire` previously treated every `DomainException`
  as “the account already exists.” The provisioning service now exposes
  dedicated duplicate-account and deleted-employee exceptions. Only those
  explicit terminal outcomes are consumed; an unclassified domain/invariant
  failure reaches queue retry and the listener failure ledger. Regression
  coverage preserves duplicate replay while proving an unexpected domain
  failure is rethrown.
- Delivery has a real `loading` state between `scheduled` and `in_transit`,
  but the API chain definition omitted that step. The canonical resolver now
  maps `loading` correctly and inserts newly code-defined steps into stale
  persisted chain settings at their canonical position. Realtime progress for
  scheduled → loading and loading → in transit can no longer report the prior
  step.

Focused verification: **35 tests / 94 assertions**. The shared backend gate is
green: **1,430 tests / 4,843 assertions** in 732.71 seconds. The full PHP
application and test trees, including the new exception classes and resolver,
pass `php -l`; `git diff --check` and production Compose validation are clean.
The production PHP image was rebuilt as `kwatog-api:process-audit` and its
smoke check found the backup script, `pg_dump`, `aws`, `pdo_pgsql`, `pgsql`, and
`redis`.

The external decisions remain unchanged: supplier transport, decimal-capable
QC quantities versus a whole-unit GRN policy, and the intended status of the
legacy direct HR separation endpoint.

## 0.19 Direct cross-module status publication and recovery atomicity — 2026-08-10

The next process audit found four decision-free gaps at durable boundaries:

- Supplier-portal PO acknowledgement changed the PO to `sent` and staged the
  GRN trigger, but did not stage the canonical PO chain step. It now mirrors
  `PurchaseOrderService::markAsSent()` and records both durable events in the
  acknowledgement transaction.
- Supplier-return disposition recalculated the authoritative PO receipt status
  without publishing the resulting `partial_received` or `approved` step. The
  locked status mutation now publishes with the acting user and skips no-op
  writes.
- Customer credit-note application advanced invoice balances/statuses without
  chain evidence. The customer and supplier branches now lock the credit note
  and target invoice/bill, recheck state under those locks, and publish status
  changes with the actor. A stale finalized credit note can no longer replay
  after the first application consumes it.
- Reviewed outbox requeue selected failed rows without locks and updated the
  outbox and chain-run tables in separate statements. Requeue now locks the
  selected failed rows and updates both ledgers in one transaction, preventing
  a retry command from overwriting a worker claim or splitting the recovery
  state.

Targeted verification: **40 tests / 164 assertions** across accounting,
supplier portal, returns, outbox, listener telemetry, and bottleneck recovery.
The final backend gate is green: **1,431 tests / 4,850 assertions** in 754.32
seconds. SPA lint, TypeScript, and Vitest are green: **24 files / 201 tests**.
The full PHP application and test trees pass `php -l`; `git diff --check` and
production Compose validation are clean. The production image was rebuilt as
`kwatog-api:process-audit`; its smoke check found the backup script, `pg_dump`,
`aws`, `pdo_pgsql`, `pgsql`, and `redis`.

The next decision gate remains external: select the supplier transport and
provider receipt contract, decide whether incoming-QC/GRN quantities support
decimals, and choose the canonical path for the legacy direct HR separation
endpoint. Once those are settled, the next audit target is listener
completion/outcome visibility and explicit compensation/manual-review states
for incoming-QC, three-way-match, and accounting failures.

## 0.20 Incoming-QC, shared-resource, and final-pay recovery tranche — 2026-08-10

The next cross-module audit found and closed three decision-free consistency
gaps:

- Incoming-QC pass orchestration now treats a cancelled sibling inspection as
  an explicit completed logistics decision, matching GrnService::assertQcGate
  instead of leaving a multi-line GRN stuck in pending_qc.
- Delivery status transitions now lock the assigned vehicle, reject a second
  active delivery from claiming it, and preserve in_use while another active
  delivery still owns the resource.
- Final-pay computation now locks the clearance and refuses writes after a
  terminal state. Final-pay journal creation is idempotent across direct or
  replayed calls, recovers a linked draft/posted entry, and stops explicitly
  when the source entry has been reversed.

Focused regressions covered the incoming-QC fix with **22 tests / 60
assertions**, then the shared-vehicle and final-pay slice with **31 tests / 80
assertions**. The final backend gate is green: **1,435 tests / 4,861
assertions** in 697.63 seconds. SPA lint and TypeScript typecheck are green;
Vitest is green with **24 files / 201 tests**. The full PHP application and
test trees pass php -l; git diff --check and production Compose validation
are clean.

A fresh kwatog-api:latest production image built successfully
(sha256:30a49a7672c2f6596f8eb838c7e28e30c61db3be08ed7243ee66a58542cd7a9a).
The image smoke check verified pdo_pgsql, pgsql, and redis, and booted
Laravel 12.64.0 with explicit non-default production assertion values.

The next decision gate remains external: select the supplier transport and
provider receipt contract, decide whether incoming-QC/GRN quantities support
decimals, and choose the canonical path for the legacy direct HR separation
endpoint. The next implementation target is listener completion/outcome
visibility plus explicit compensation/manual-review states for incoming-QC,
three-way-match, and accounting failures.

## 0.21 GRN→bill recovery visibility and chain deep-link completion — 2026-08-10

The next audit stayed within the existing publication contract and closed the
remaining read-side blind spot at the P2P accounting boundary:

- `ChainBottleneckService` now detects accepted GRNs that have no non-cancelled
  linked supplier bill after the configured SLA. This catches a failed,
  exhausted, or legacy-broken `AutoCreateBillOnGrnAccepted` handoff without
  inventing a compensating accounting mutation.
- The same service now detects draft bills whose persisted 3-way-match snapshot
  is `blocked` and not overridden. A blocked review is therefore a distinct
  finance bottleneck rather than an indistinguishable draft or overdue bill.
- Both policies are added with four-hour defaults while preserving existing
  operator SLA overrides. The hourly bottleneck command and alert path inherit
  the document-level recovery target automatically.
- The chain tracker now deep-links every existing bottleneck entity type,
  including the new GRN and bill recovery rows, to its owning module detail
  page.

Focused verification: **9 tests / 39 assertions** for the chain detector slice.
The full backend gate is green: **1,437 tests / 4,872 assertions** in 763.95
seconds. SPA lint, TypeScript, and Vitest are green: **24 files / 201 tests**.
The touched PHP files pass syntax checks; `git diff --check` and production
Compose validation remain required release checks.

The external decisions remain unchanged: supplier transport/provider receipt
contract, decimal-capable incoming-QC/GRN quantities, and the canonical path
for the legacy direct HR separation endpoint. The next implementation target
is still listener completion/outcome telemetry and explicit manual recovery
actions for failures that cannot be resolved by the read-side bottleneck path.

## 0.22 Listener business outcomes and cross-module recovery telemetry — 2026-08-10

The listener ledger now distinguishes queue execution from the business
meaning of a completed stateful listener:

- chain_listener_runs adds outcome_status, outcome_code, outcome_message, and
  outcome_at. Existing completed/failed rows are backfilled as legacy queue
  outcomes, so the migration is additive and backwards-readable during
  rollout.
- ChainListenerRunService::recordOutcome() uses a process-local context
  stack. Nested synchronous queued listeners therefore update their own row
  without overwriting the parent listener's outcome. Queue lifecycle state
  remains authoritative for retries/dead letters; business outcomes are
  completed, skipped, manual_required, or failed.
- Stateful cross-module listeners now record explicit outcomes across P2P/QC,
  O2C/production, CRM recurrence, HR account/leave flows, and payroll
  finalization. Notification-only listeners remain best-effort and retain
  queue lifecycle telemetry without being misclassified as business failures.
- The automation summary counts completed, safely skipped, manual handoff,
  failed, and unclassified listener outcomes. Manual or business-failed
  outcomes move the dashboard to attention; unknown/null values are counted
  separately so rolling migrations and in-flight work are visible.
- The incoming-QC transaction now returns its outcome before telemetry is
  written, preventing an awaiting_sibling_qc no-op from being overwritten by
  a later completed write. A regression also covers nested listener context
  isolation and null-outcome aggregation.

Focused verification covered 63 backend tests / 222 assertions for the P2P/QC
and ledger slice, 20 tests / 80 assertions for HR/payroll/wiring, 31 tests /
90 assertions for CRM/production/payroll, and 12 tests / 65 assertions for
final ledger aggregation. The full backend gate is green: 1,439 tests /
4,889 assertions in 755.24 seconds. SPA lint and TypeScript are green; Vitest
is green with 24 files / 202 tests. The application and test trees pass PHP
lint (1,534 files), git diff --check, and production Compose validation.

The next implementation target is operator actionability beyond counts:
provide a filtered listener-run detail/replay surface (with permissions,
outbox/job correlation, and safe manual-resolution notes), then use that
surface to close the remaining supplier-provider and decimal-quantity
deployment decisions without silently inventing external behavior.

## 0.23 Listener recovery operator surface — 2026-08-10

The operator-actionability tranche is now implemented as a separate recovery
surface rather than expanding the order-to-cash journey page:

- chain_listener_runs now carries resolution status/note/actor/timestamp,
  replay request metadata, replay count, and replayed_from_id lineage. A
  replay never rewrites the source queue row into a new result.
- ChainListenerRecoveryService provides a paginated, attention-first ledger
  with queue state, business outcome, outbox state, chain-step hash context,
  job UUID, and replay lineage. Stored event payloads and raw numeric entity
  IDs are deliberately excluded from the API response.
- Replays decode the original allow-listed outbox event and enqueue only the
  selected queued listener through ReplayChainListenerJob. Sibling
  listeners (including notifications, email, or bank-file generation) are not
  re-fired. The replay context is attached while the job is actually queued,
  including the sync-queue path, so the resulting run is correlated and
  linked back to its source.
- Manual resolution requires a note, is idempotent, rejects active processing
  runs, and writes append-only chain_listener.resolved,
  chain_listener.replay_requested, or replay-failure audit rows. Replay and
  resolve are separate from the read permission:
  dashboard.chain_recovery.view and dashboard.chain_recovery.manage.
- /chains/recovery gives authorized operators attention/all filters, search,
  source-record deep links, explicit replay confirmation, and a
  resolution-note dialog. The page explains that replay scope is one
  listener and that the outbox remains untouched.

Focused verification: **8 recovery tests / 40 assertions** and **25
cross-module regression tests / 130 assertions**. The final local gate is
green: backend **1,447 tests / 4,929 assertions**, SPA **24 test files / 202
tests**, ESLint, TypeScript, PHP syntax across **2,069 files**, `git diff
--check`, production Compose validation, and the registered chain routes.
The app database still reports the new migration tranche as pending, so the
remaining release evidence is a controlled staging migration plus a real
queue-worker replay smoke. The supplier-provider and decimal-quantity
decisions remain external deployment inputs.

## 0.24 Production-boundary audit and repeatable release gate — 2026-08-10

The follow-up production audit found three concrete gaps in the deployment
boundary and closed them:

- The deploy workflow and Makefile now bring up only PostgreSQL/Redis, wait for
  the database healthcheck, run migrations in a one-shot API container, and
  start the API, realtime server, queue, scheduler, and Nginx afterward. New
  consumers cannot process work against an old schema during rollout.
- Production Nginx now mounts `prod.conf` as an official template and expands
  `SERVER_NAME` at container start. `ProductionAssertions` rejects an empty or
  localhost server name in production, and the deploy workflow validates the
  requested DNS name against the shared `.env`. The health probe uses HTTPS,
  matching the port-80 redirect.
- Production Redis has a healthcheck and the queue/scheduler/API dependencies
  wait for it. CI now runs the API suite against PostgreSQL 16 and Redis rather
  than SQLite without Redis, matching the runtime data and queue boundaries.

`make chain-smoke` is now a repeatable disposable gate: it applies every
migration to a constrained temporary database, dispatches the target-only
listener replay into an isolated Redis queue, drains a real worker, asserts
linked replay lineage/business outcome/zero failed jobs, and cleans up. It
passed after the deployment changes. The full local gate is green: backend
**1,448 tests / 4,932 assertions**, SPA **24 test files / 202 tests**, ESLint,
TypeScript, PHP syntax across **2,069 files**, workflow YAML parsing, production
Compose validation, Nginx `nginx -t`, and `git diff --check`.

The remaining evidence is external to this checkout: run the same gate against
the real staging database/Redis and then the production domain/secrets, select
the supplier transport/provider, and settle the decimal-quantity policy before
claiming a live deployment is fully verified.

## 0.25 O2C delivery-to-invoice recovery tranche — 2026-08-10

The cross-module audit found one remaining asymmetric handoff: delivery
confirmation was durable, but draft customer-invoice creation was a best-effort
synchronous call whose failure survived only in logs and a notification. The
confirmation boundary is now explicit and replayable:

- `deliveries.invoice_handoff_status` records `not_started`, `generated`, or
  `manual_required`, with a safe operator message and attempt timestamp.
- Failed confirmation-time attempts publish the allow-listed
  `DeliveryInvoiceRequested` event with a dedicated `invoice_handoff` chain
  step and stable delivery dedupe key. Its queued listener retries only this
  handoff; it never repeats confirmation, SO reconciliation, or confirmation
  notifications.
- The retry path locks the delivery, reuses an existing linked/reverse-linked
  invoice, and otherwise creates exactly one draft before linking it. Expected
  Accounting/data failures become a manual outcome; unexpected infrastructure
  failures remain queue-retryable.
- Delivery detail exposes the Finance warning, the chain recovery surface can
  replay the target listener, and the four-hour
  `delivery_confirmed_without_invoice` bottleneck points back to the delivery.
- Focused verification covers successful generation, durable manual-required
  state, outbox codec round-trip, replay recovery without duplication, listener
  wiring, and stale-vs-fresh bottleneck selection.

Final verification for this tranche is green: **1,450 backend tests / 4,947
assertions**, SPA **24 test files / 202 tests**, ESLint, TypeScript, PHP syntax
across **2,000 files**, `git diff --check`, and production Compose validation.
`make chain-smoke` also passed against a disposable database and real Redis
worker, including the new migrations, target-only listener replay, lineage and
business-outcome assertions, and zero failed jobs.
The final detector review then changed the bottleneck clock to authoritative
`confirmed_at` (rather than mutable `updated_at`); the post-change focused
regression remains green at **14 tests / 76 assertions**.

The remaining deployment prerequisite is operational: migrations must run before
API/consumer startup (now enforced by the deployment workflow), and live
Accounting/provider credentials still require staging verification.

## 0.26 Production-output to finished-goods receipt recovery — 2026-08-10

The current-state process audit found one remaining production boundary where
the physical/operational fact could commit while its inventory consequence was
only logged: a good work-order output could exist without a finished-goods
receipt when the item or location setup was incomplete. That boundary is now
durable and operator-recoverable:

- `work_order_outputs.production_receipt_handoff_status` records
  `not_started`, `generated`, `not_required`, or `manual_required`, with a
  reason and authoritative handoff timestamp. Historical good outputs are
  backfilled to `manual_required` rather than being silently treated as
  complete.
- A generated movement uses `reference_type=work_order_output` and the exact
  output ID. Replays reuse the linked/exact movement; ambiguous legacy
  work-order-level movements remain manual to avoid double-counting stock.
- Failed automatic creation records the allow-listed
  `ProductionReceiptRequested` event and a dedicated chain step. The queued
  listener retries only this handoff, records business outcomes, and leaves
  unexpected infrastructure failures retryable.
- Work-order detail exposes the handoff state and a permission-gated **Retry**
  action. The chain bottleneck detector groups by parent work order but uses
  the output handoff timestamp and deep-links to that work order.

Focused verification: **5 production tests / 28 assertions** after adding the
operator retry endpoint; the prior full backend gate remains green at **1,452
tests / 4,972 assertions**. Incremental SPA lint and TypeScript checks are
green. The final tranche gate is also green: SPA Vitest **24 files / 202
tests**, all-file PHP syntax, `git diff --check`, production Compose
validation, and `make chain-smoke` with a real Redis worker and zero failed
jobs.

### Ranked next audit targets

1. **Returns → Quality inspection (P1/P2):** return inspection creation is
   caught per product and the return continues; confirm whether the return can
   become financially/operationally terminal without a durable inspection
   outcome.
2. **Complaint → NCR (P2):** automatic NCR creation is best-effort; add an
   explicit manual/replay state if complaint disposition depends on it.
3. **GRN → incoming QC telemetry (P2):** the durable GRN event already
   protects recovery, but synchronous QC-attempt failures should be measured
   as a business handoff rather than only inferred from listener state.

External release inputs remain separate from code hardening: supplier
transport/provider credentials, decimal quantity policy for incoming QC/GRN,
and staging verification against real PostgreSQL/Redis and production secrets.

## 0.27 Inventory-to-Accounting GL recovery — 2026-08-10

The P1 slice identified by the current-state audit is now closed. A stock
movement can no longer change inventory value and leave the Accounting gap only
in logs:

- `stock_movements.gl_handoff_status`, `gl_handoff_message`, and
  `gl_handoff_at` persist the attempt state. Existing journal-linked rows are
  backfilled as generated; intentional non-GL/zero-value rows are not required;
  ambiguous historical value-changing rows are manual-required for
  reconciliation.
- `MovementGlPostingService` retains the existing mapping and GRN ownership,
  but turns missing tables, missing items, missing COA mappings, absent COA
  accounts, and recoverable journal business rules into a durable manual
  handoff. Physical stock remains committed.
- `StockMovementGlPostingRequested` is an allow-listed outbox event with a
  stable `inventory / stock_movement / gl_handoff` chain step. The queued
  listener retries only the GL handoff and records business outcomes; exact
  `journal_entry_id` linkage prevents duplicate journals.
- Finance can retry through
  `POST /inventory/stock-movements/{movement}/retry-gl` with
  `accounting.journal.post`; the stock movement list shows state/message and
  offers the same permission-gated action. The four-hour bottleneck detector
  deep-links to the filtered movement list.

Focused verification is green: **25 backend tests / 139 assertions** for the
movement, bottleneck, and listener-wiring surface, including **9 movement tests
/ 41 assertions** for commit/replay/idempotence/disabled-accounting/permission
semantics. The final release evidence is also green: **1,456 backend tests /
5,003 assertions** in 705.86 seconds, SPA **24 files / 202 tests**, ESLint,
TypeScript, all-file PHP syntax, `git diff --check`, production Compose
validation, and `make chain-smoke` through migration `2026_08_10_250000` with a
real Redis worker and zero failed jobs.

The next ranked slices are recorded below; the source-level audit is now
focused on release verification and external deployment inputs.

## 0.28 Return-to-Quality inspection recovery — 2026-08-10

The return audit found that inspection creation could fail per product while
the RMA continued toward inspection/disposition. The handoff is now explicit:

- `return_requests.inspection_handoff_status`, message, and timestamp persist
  generated/not-required/manual-required state; legacy RMAs without required
  inspections are surfaced rather than silently considered inspected.
- `ReturnInspectionRequested` is allow-listed in the outbox and records the
  `returns / return_request / inspection_handoff` chain step.
- The queued listener and RMA retry endpoint lock the RMA, reuse an existing
  RMA/product inspection, and record business outcomes. Dispose/complete are
  blocked for a known manual-required handoff.
- The QC bottleneck and all chain deep links point to the RMA detail.

## 0.29 Complaint-to-NCR recovery — 2026-08-10

Complaint creation now distinguishes expected Quality setup failures from
unexpected infrastructure failures. Expected failures commit the complaint
and 8D shell with a durable `ComplaintNcrRequested` recovery event; unexpected
failures roll back. The queued listener and operator retry reuse an existing
complaint-linked NCR, and resolve/close require a generated NCR handoff.

The complaint resource, detail page, chain recovery links, and
`complaint_without_ncr` bottleneck expose the manual state and safe retry.

## 0.30 GRN incoming-QC handoff telemetry and recovery — 2026-08-10

The GRN audit found that the fail-closed QC gate and outbox protected the
business invariant, but the synchronous trigger outcome was only inferable
from missing inspections and the rollout-health counter. The GRN now persists
`incoming_qc_handoff_status`, message, and timestamp; known Quality/data setup
failures become manual-required, unexpected failures remain queue-retryable,
and no-QC cases are explicit `not_required`.

The existing idempotent `TriggerIncomingQC` listener updates this state, the
GRN detail exposes a `quality.inspections.manage` retry, and
`grn_without_incoming_qc` deep-links stale pending receipts. Existing GRN
acceptance remains fail-closed and no stock mutation is repeated by retry.

Local release verification is green after this tranche: **1,468 backend tests /
5,076 assertions**, SPA **24 files / 202 tests**, ESLint, TypeScript, all-file
PHP syntax, `git diff --check`, production Compose validation, and
`make chain-smoke` through migration `2026_08_10_310000` with a real Redis
worker and zero failed jobs. The remaining evidence is controlled staging
execution with real PostgreSQL/Redis and external provider configuration.

## 0.31 Customer-portal complaint canonicalization — 2026-08-10

The cross-entry-point audit found that the B2B customer portal was creating
`CustomerComplaint` directly. That bypassed CRM's 8D shell and the durable
Complaint → Quality NCR handoff introduced in 0.29. The portal now:

- decodes and customer-scopes the optional sales-order hash in the service;
- impersonates the configured system user so audit rows retain a valid internal
  `users.id`;
- calls `ComplaintService::create`, so successful submissions create the 8D
  shell and linked NCR with `generated` handoff state; and
- returns the shared complaint resource, including safe handoff and linked
  document fields.

The B2B portal regression now covers order linkage, generated NCR handoff,
8D-shell creation, resource serialization, and audit attribution. Focused
verification: **14 tests / 45 assertions**, PHP syntax, and SPA TypeScript
pass. The subsequent full release gate passed **1,469 backend tests / 5,087
assertions** in 698.86 seconds; SPA **24 files / 202 tests**, ESLint,
TypeScript, all-file PHP syntax, `git diff --check`, production Compose
validation, and real-Redis `make chain-smoke` also passed.

## 0.32 Supplier-portal acknowledgement race hardening — 2026-08-10

The external-entry-point audit found that supplier acknowledgement duplicated
the PO sent-transition orchestration and updated the route-bound model without
an authoritative lock. A late acknowledgement could therefore race a
cancellation or other send action. The portal now locks and re-reads the PO,
requires `approved`, and delegates to `PurchaseOrderService::markAsSent` with
the `supplier_portal_acknowledgement` dispatch channel. The canonical service
owns the dispatch proof, `PurchaseOrderSent` outbox row, expected-GRN trigger,
and chain broadcast.

Regression coverage now proves both the approved path and the terminal-state
422/no-sent-handoff path. Focused supplier-portal verification: **14 tests /
28 assertions**, passing.

## 0.33 Supplier-portal invoice → AP draft handoff — 2026-08-10

The external-entry-point audit found that supplier invoice submission called
`BillService::create`, which immediately posted an AP journal despite the
portal contract describing a draft bill. The boundary now:

- locks and reloads the PO and vendor before checking the vendor-scoped bill
  number, so concurrent retries cannot create duplicate bills;
- copies PO item identity into bill lines so the three-way match remains aligned
  even when lines are reordered or omitted;
- uses `BillService::createDraft`, preserving totals and the match snapshot but
  leaving `journal_entry_id` null and the bill in `draft` status;
- returns the existing bill for an idempotent same-PO retry and rejects a bill
  number already attached to another PO; and
- keeps `postDraft()` as the review boundary, where the match is recomputed and
  an audited override is required before AP/GL posting.

Focused verification is green: supplier portal **16 tests / 39 assertions**;
Accounting bill, bill-item, and GRN auto-bill regressions **14 tests / 56
assertions**. The final local release gate is also current: **1,471 backend
tests / 5,098 assertions** in 706.35 seconds; SPA **24 files / 202 tests**,
ESLint, TypeScript, all-file PHP syntax, `git diff --check`, production Compose
validation, and `make chain-smoke` through migration `2026_08_10_310000` with a
real Redis worker and zero failed jobs all passed.

## 0.34 Supplier-portal shipment metadata race hardening — 2026-08-11

The B2B boundary scan found that shipment updates still saved the
route-bound PO directly and silently dropped two validated request fields
(`shipped_date` and `notes`). The endpoint now:

- locks and reloads the authoritative PO inside a transaction;
- rechecks vendor ownership and rejects cancelled, received, and closed POs;
- preserves the latest remarks while recording shipped date, carrier, tracking,
  ETA, and supplier notes; and
- prevents a stale portal page from overwriting a concurrent acknowledgement,
  receiving, or cancellation transition.

Focused supplier-portal verification is now **17 tests / 45 assertions**,
including terminal-state no-mutation coverage. The final local release gate is
current: **1,472 backend tests / 5,104 assertions** in 700.03 seconds; SPA **24
files / 202 tests**, ESLint, TypeScript, all-file PHP syntax, `git diff
--check`, production Compose validation, and `make chain-smoke` through
migration `2026_08_10_310000` with a real Redis worker and zero failed jobs all
passed.

## 0.35 Supplier-portal bill PDF model-boundary correction — 2026-08-11

The B2B audit found that supplier invoice detail returned `Bill` rows but its
PDF route bound `Invoice` and used the customer AR renderer. The route now:

- binds the Accounts Payable `Bill` model;
- reuses the vendor-scoped `SupplierPortalService::invoiceDetail` check; and
- renders through `PdfService::bill`, so draft and posted supplier bills use the
  correct AP document template.

Focused verification is green: supplier portal **19 tests / 49 assertions**,
including valid bill PDF rendering and cross-vendor denial. The final local
release gate is current: **1,474 backend tests / 5,108 assertions** in 708.75
seconds; SPA **24 files / 202 tests**, ESLint, TypeScript, all-file PHP syntax,
`git diff --check`, production Compose validation, and `make chain-smoke`
through migration `2026_08_10_310000` with a real Redis worker and zero failed
jobs all passed.

## 0.36 HR self-service overtime lifecycle hardening — 2026-08-11

The cross-module entry-point scan found that self-service overtime cancellation
and restoration still wrote directly to `OvertimeRequest`, bypassing the
canonical Attendance service and its durable event boundary. Restoration also
accepted any `rejected` row, which allowed an employee to reopen an approver's
rejection.

The lifecycle now:

- routes self-service cancellation and restoration through `OvertimeService`;
- reloads and locks the authoritative row for approve, reject, cancel, and
  restore transitions;
- records `cancelled_by` and `cancelled_at` provenance, so only the same
  employee can restore their own cancellation; and
- records durable outbox events for cancellation and resubmission, while the
  self-service UI exposes the valid restore action.

Focused verification is green: overtime/attendance/notification coverage is
**25 tests / 86 assertions**; SPA **24 files / 202 tests**, ESLint, and
TypeScript also pass. The final backend gate is current at **1,478 tests /
5,133 assertions** in 700.68 seconds. Final PHP syntax, diff, production
Compose, and Redis chain smoke checks also pass; the smoke migration reached
`2026_08_11_100000` with a real worker and zero failed jobs.

## 0.37 HR self-service loan lifecycle canonicalization — 2026-08-11

The cross-module entry-point scan found that self-service loan submission and
listing bypassed the Loans module: submission inserted legacy-shaped rows
directly, while listing read columns that were not authoritative on
`EmployeeLoan`. That skipped loan policy, numbering/amortization setup,
approval records, and durable `LoanSubmitted` publication.

The lifecycle now:

- routes self-service submission through `LoanService::request` and reads the
  authoritative `EmployeeLoan` model while preserving the portal response
  shape;
- locks the employee row during request validation to serialize duplicate
  same-type submissions; and
- reloads and locks the authoritative loan row for approve, reject, and cancel
  transitions before checking status or writing approval state.

Focused verification is green: **10 tests / 39 assertions**, covering canonical
submission/outbox publication, authoritative list fields, and duplicate
rejection. The final backend gate is **1,481 tests / 5,153 assertions** in
709.36 seconds. SPA **24 files / 202 tests**, ESLint, and TypeScript remain
green from the preceding slice; all-file PHP syntax, `git diff --check`, and
production Compose validation pass. `make chain-smoke` reached migration
`2026_08_11_100000`, completed replay with a real Redis worker, and ended with
zero failed jobs.

## 0.38 Payroll finalization → Accounting GL durable handoff — 2026-08-11

The source-level cross-module audit found one remaining high-risk boundary in
the payroll path: finalization committed the period and the controller then
dispatched `PostPayrollToGlJob` directly. A queue outage could leave a finalized
period without a durable retry, visible handoff state, or finance bottleneck;
the GL service also checked a potentially stale model before its transaction
lock.

Implemented:

- `PayrollGlHandoffStatus` on `payroll_periods` with explicit pending, posted,
  manual-required, and not-required outcomes plus migration backfill for legacy
  finalized rows;
- `PayrollGlPostingRequested` and `PostPayrollToGlOnRequested` as a narrow,
  allow-listed outbox/listener boundary with chain outcome telemetry;
- authoritative `lockForUpdate()` reload before status/journal checks, with
  journal-link idempotency across direct calls and replayed listeners;
- permissioned `POST /api/v1/payroll-periods/{period}/retry-gl` recovery;
- `payroll_gl_without_journal` finance bottleneck detection; and
- payroll detail UI status, linked entry, manual reason, and Retry GL action.

Evidence: focused payroll GL handoff **8 tests / 34 assertions**; the
handoff-plus-chain-wiring focus is **13 tests / 88 assertions**, including the
terminal void/replayed-request no-op regression; full backend **1,489 tests /
5,191 assertions** in **715.46s**; SPA **24 files / 202 tests** with ESLint and
TypeScript passing; all-file PHP syntax, diff, production Compose, and Redis
worker chain smoke green through migration `2026_08_11_120000` with zero failed
jobs.

## 0.39 Final-pay source integrity + Return Management transition serialization — 2026-08-11

The next boundary audit found that final-pay source reads could turn a failed
loan, cash-advance, or employee-property query into a zero balance. It also
found that several Return Management transitions validated a stale route-bound
model before entering their transaction.

The implementation now:

- fails final-pay computation closed with an actionable business error when a
  required source is unavailable, while preserving the original exception for
  diagnostics;
- reloads and locks the authoritative RMA for submit, approve, receive,
  inspect, reject, cancel, and complete before status checks or side effects;
- makes completion reject stale terminal replays before a second stock
  movement or duplicate terminal handoff; and
- narrows return notification recipient reads to identity columns, disables
  the unused role eager load, and adds
  `2026_08_11_130000_add_user_permission_audience_indexes` for user-role and
  permission-pivot audience lookups.

Verification is green: final-pay focus **20 tests / 47 assertions**; Return
Management focus **44 tests / 199 assertions**; combined HR/return/chain focus
**79 tests / 330 assertions**; full backend **1,491 tests / 5,196 assertions**
in **771.17s**; SPA **24 files / 202 tests**; SPA typecheck and 731-file token
discipline; all-file PHP syntax; `git diff --check`; production Compose; and
`make chain-smoke` through `2026_08_11_130000` with a real Redis worker and
zero failed jobs.

The known return-recipient slow query no longer appears in the full-gate
telemetry. Separate role-permission hydration reads still measure roughly
100–220 ms locally and should be profiled against staging cardinality.

## 0.40 Payroll compute claim → durable execution + scheduler idempotency — 2026-08-11

The direct-process audit found a remaining queue-loss window in payroll
computation: manual and automatic entrypoints committed `Processing` and then
dispatched `ProcessPayrollJob` directly. The automatic scheduler also had only
application-level duplicate checks.

Implemented:

- `PayrollComputationRequested` is allow-listed by `OutboxEventCodec` and is
  recorded with the `Processing` claim and `compute` chain step in one database
  transaction;
- `RunPayrollComputationOnRequested` executes the existing engine under a
  per-period `WithoutOverlapping` lock, checks the authoritative period status,
  records listener outcomes, and releases catastrophic claims when the queued
  listener is dead-lettered;
- both the HTTP controller and `AutoPayrollPeriodService` use the same staged
  claim path; and
- `payroll_periods.auto_idempotency_key` plus a nullable unique index closes the
  concurrent auto-period creation race without restricting human-scoped runs.

Evidence: compute lifecycle/recovery **26 tests / 77 assertions**; handoff
**3 tests / 8 assertions**; auto-period **2 tests / 11 assertions**; full
payroll feature suite **231 tests / 665 assertions** in **184.67s**; migration
through `2026_08_11_140000` applied cleanly with no pre-existing duplicate auto
windows.

Next ranked process slice: replace the direct `ProcessYearEndLeave` command and
controller dispatch with a durable request/status boundary. `SyncBudgetActuals`
is lower priority because it rebuilds derived totals and is rerunnable; the
unused legacy `PostPayrollToGlJob` should be retired after a compatibility
audit.

## 0.4 Verification record — 2026-08-10

- Backend: `DB_HOST=172.18.0.2 REDIS_HOST=172.18.0.3 php artisan test` — **1,380 tests / 4,694 assertions** before the 0.7 tranche; rerun after the conversion-state changes below.
- SPA: ESLint, TypeScript, and Vitest — **24 test files / 201 tests**.
- PHP syntax and `git diff --check` are clean.
- Migrations `2026_08_10_100000` through `2026_08_10_130000` are applied in the test database.

The next decision is now narrow and external to the chain invariants: select and configure the real supplier transport (email, EDI, or another provider), then implement that adapter behind `SupplierDispatchGateway` and add provider receipt tests. Until that decision is made, `portal_available` and `manual_required` are intentional operator states rather than silently inferred delivery.

## 0.2 Durable cross-module publication tranche — 2026-08-10

The next audit move is now implemented and verified:

- `event_outbox` persists an allow-listed, replayable domain-event envelope in the same transaction as the business mutation. `OutboxEventCodec` rejects arbitrary event classes and reloads only typed, existing models.
- `chain_step_runs` records the chain, entity, step, dedupe key, attempt count, publication state, and last failure. The unique key makes retries/replays safe at the event-publication boundary.
- `OutboxService` publishes after commit as an optimization; the scheduled `outbox:dispatch` command recovers rows when Redis or a worker is unavailable. `OutboxDispatcher` retries with backoff, reclaims processing rows older than ten minutes, exposes failed/dead-letter rows, and supports reviewed requeue.
- Core O2C, P2P, H2R, inventory, quality, production, MRP, payroll, attendance, leave, loans, maintenance, CRM, and supplier-portal domain transitions now record their durable events inside the owning transaction. `ChainBroadcaster` uses the same durable path for realtime chain progress.
- The remaining direct dispatches are intentionally ephemeral notification/progress events or queue-job dispatches; the domain-event scan no longer shows a state-changing cross-module event bypassing the outbox.

This ledger currently means “the domain event was durably published,” not “every queued listener completed successfully.” Listener failures remain visible through Laravel’s failed-job path. The next architecture tranche should add listener outcome/step-completion records if the UI or operations team needs end-to-end completion rather than publication visibility.

Verification for this tranche:

- Backend: `DB_HOST=172.18.0.2 REDIS_HOST=172.18.0.3 php artisan test` — **1365 tests / 4628 assertions**.
- SPA: lint, TypeScript, and Vitest — **24 files / 186 tests**.
- Infrastructure coverage includes transaction rollback, duplicate replay, corrupt payload/dead-letter requeue, and stale-processing reclamation.

### Next move from the current state

1. Agree the supplier-dispatch contract (email/PDF, EDI, or both), sender identity, delivery states, retry/dead-letter behavior, and idempotency key.
2. Implement `SendPOToSupplier` as a durable outbound side effect against that contract; persist provider response and manual-review state rather than treating event publication as supplier delivery.
3. Close the incoming-QC pass orchestration: explicit GRN acceptance, three-way-match result, draft-bill creation, and compensation/manual-review states for mismatches or accounting failures.
4. Add listener completion/outcome tracking to the chain ledger and expose failed outbox/listener counts in the operational bottleneck view.

---

## 1. C1 — Order-to-Cash Auto-Chain

### 1.1 New events to add

| Event | File | When fired |
|---|---|---|
| `WorkOrderCompleted` | `api/app/Modules/Production/Events/WorkOrderCompleted.php` | When `WorkOrderService::complete()` transitions WO to `done` |
| `InspectionPassed` | `api/app/Modules/Quality/Events/InspectionPassed.php` | When `InspectionService` records a passing result |
| `InspectionFailed` | `api/app/Modules/Quality/Events/InspectionFailed.php` | When `InspectionService` records failing result |

Each event implements `ShouldBroadcast` and exposes the entity hash_id + chain context (so the C4 listener can fan-out).

### 1.2 New listeners (orchestrators)

All in `api/app/Modules/<Module>/Listeners/`. Each is `ShouldQueue`, idempotent, wrapped in `DB::transaction()`, and tagged with `chain_automation` audit reason.

| Listener | Subscribes to | Calls |
|---|---|---|
| `InitiateOrderToCashChain` | `SalesOrderConfirmed` | `MrpEngineService::runForSalesOrder()` → for each line creates a `WorkOrder` via `WorkOrderService::createForSalesOrderLine()` → `CapacityPlanningService::autoSchedule($wo)` → on schedule success calls `MaterialReservationService::reserve($wo)` → notifies Production Manager. On no-capacity, notifies PPC Head. |
| `TriggerOutgoingQC` | `WorkOrderCompleted` (only when WO has SO link) | Creates pending outgoing `Inspection` row via `InspectionService::createPending(stage: outgoing, entity: workOrder)`. Sample size from `AqlSampleSizeService`. Notifies QC team. |
| `CreateDeliveryDraftOnQcPass` | `InspectionPassed` (filter: `stage === outgoing`) | Creates `Delivery` draft (`status: scheduled`) + `DeliveryItem` rows. Calls `CoCService::generate($delivery)`. Notifies Warehouse. |
| `CreateDraftInvoiceOnDelivery` | `DeliveryConfirmed` (already exists) | Extend existing [`NotifyFinanceOnDeliveryConfirmed`](../api/app/Modules/Accounting/Listeners/NotifyFinanceOnDeliveryConfirmed.php:1) to also create draft `Invoice` via `InvoiceService::createDraftFromDelivery()`. Notifies Finance. |

### 1.3 Service additions / signatures

- `WorkOrderService::createForSalesOrderLine(SalesOrder $so, SalesOrderItem $line, MrpPlan $plan): WorkOrder` — sets `is_auto_generated=true`, `auto_generated_reason='chain_automation'`.
- `InspectionService::createPending(string $stage, Model $entity, int $batchQty): Inspection` — assigns no inspector yet.
- `InvoiceService::createDraftFromDelivery(Delivery $delivery): Invoice` — pre-fills lines from delivery items + price agreements; status = `draft`.

### 1.4 Registration

Update `api/app/Providers/EventServiceProvider.php` (or use Laravel 11 attribute discovery) to bind the four event→listener pairs.

### 1.5 Tests (PHPUnit, place in `api/tests/Feature/Chain/`)

- `OrderToCashChainTest::test_confirming_so_runs_mrp_creates_wos_and_reserves_materials`
- `OrderToCashChainTest::test_wo_completion_creates_pending_outgoing_inspection`
- `OrderToCashChainTest::test_outgoing_pass_creates_delivery_draft_with_coc`
- `OrderToCashChainTest::test_delivery_confirmed_creates_draft_invoice`
- Each test asserts: row created, `is_auto_generated=true`, audit log written, notification dispatched (use `Notification::fake()` and `Event::fake()` for partial assertions).

---

## 2. C2 — Procure-to-Pay Auto-Chain

### 2.1 New events

| Event | File |
|---|---|
| `PurchaseRequestApproved` | `api/app/Modules/Purchasing/Events/PurchaseRequestApproved.php` |
| `PurchaseOrderApproved` | `api/app/Modules/Purchasing/Events/PurchaseOrderApproved.php` |
| `GoodsReceiptNoteCreated` | `api/app/Modules/Inventory/Events/GoodsReceiptNoteCreated.php` |

Fire `PurchaseRequestApproved` from `ApprovalService` when a PR's final approval level completes. Same for `PurchaseOrderApproved`. `GoodsReceiptNoteCreated` fires from `GrnService::create()`.

### 2.2 New listeners

| Listener | Subscribes to | Behavior |
|---|---|---|
| `ConsolidatePurchaseOrders` | `PurchaseRequestApproved` | Groups all newly-approved PRs by vendor; calls existing `AutoPurchaseOrderService::consolidate($vendor, $prItems)`; if total < ₱50K, auto-marks PO as approved (skip approval workflow) and fires `PurchaseOrderApproved`; otherwise leaves PO pending VP approval. |
| `SendPOToSupplier` | `PurchaseOrderApproved` | Renders PDF via [`PurchaseOrderPdfService`](../api/app/Modules/Purchasing/Services/PurchaseOrderPdfService.php:1), emails to vendor via new `PurchaseOrderToSupplierMail` notification. Marks `sent_to_supplier_at`. |
| `TriggerIncomingQC` | `GoodsReceiptNoteCreated` | Creates pending incoming `Inspection` (stage: `incoming`). Notifies QC team. |
| `AcceptGRNAndDraftBill` | `InspectionPassed` (filter: `stage === incoming`) | Calls `GrnService::accept($grn)` (updates stock + weighted-avg cost). Calls `ThreeWayMatchService::match($po, $grn, null)` to validate. Creates draft `Bill` via new `BillService::createDraftFromGrn(GoodsReceiptNote $grn): Bill`. Notifies Finance. |
| `RejectGRNOnQcFail` | `InspectionFailed` (filter: `stage === incoming`) | Calls `GrnService::reject($grn, $reason)`; creates NCR via existing `NcrService` (already wired). |

### 2.3 Threshold constant

Add `'auto_approve_po_threshold' => 50000` to `api/config/purchasing.php` (new file). Reference via `config()` to allow per-env override.

### 2.4 Tests (`api/tests/Feature/Chain/ProcureToPayChainTest.php`)

- `test_pr_approval_consolidates_pos_per_vendor`
- `test_po_under_threshold_auto_approves_and_emails_supplier`
- `test_po_over_threshold_waits_for_vp_approval`
- `test_grn_creation_triggers_incoming_inspection`
- `test_incoming_qc_pass_accepts_grn_and_drafts_bill`
- `test_incoming_qc_fail_rejects_grn_and_creates_ncr`

---

## 3. C3 — Hire-to-Retire Auto-Chain

Most pieces already exist:

- Onboarding: [`OnboardingService`](../api/app/Modules/HR/Services/OnboardingService.php:1), [`SendOnboardingReminders`](../api/app/Console/Commands/SendOnboardingReminders.php:1) command (already scheduled).
- Payroll auto-period: [`CreateAutoPayrollPeriod`](../api/app/Console/Commands/CreateAutoPayrollPeriod.php:1) (already scheduled).
- Final pay: [`FinalPayService`](../api/app/Modules/HR/Services/FinalPayService.php:1), [`SeparationService`](../api/app/Modules/HR/Services/SeparationService.php:1).

### 3.1 Gaps to fill

| Missing piece | File | Behavior |
|---|---|---|
| `EmployeeCreated` event | `api/app/Modules/HR/Events/EmployeeCreated.php` | Fired by `EmployeeService::create()` after the row is committed. |
| `InitializeLeaveBalances` listener | `api/app/Modules/Leave/Listeners/InitializeLeaveBalances.php` | Subscribes to `EmployeeCreated`; iterates `leave_types`; pro-rates against `date_hired` vs current calendar year; inserts `employee_leave_balances` rows. Idempotent via unique key (`employee_id`,`leave_type_id`,`year`). |
| `PayrollPeriodFinalized` event | `api/app/Modules/Payroll/Events/PayrollPeriodFinalized.php` | Fired by `PayrollPeriodService::finalize()`. |
| `GeneratePayslipsAndNotify` listener | `api/app/Modules/Payroll/Listeners/GeneratePayslipsAndNotify.php` | Generates PDF payslips (queue per employee) + bank file CSV + GL post + per-employee notification. |
| `SeparationInitiated` event | `api/app/Modules/HR/Events/SeparationInitiated.php` | Fired by `SeparationService::initiate()`. |
| `OpenClearanceItems` listener | `api/app/Modules/HR/Listeners/OpenClearanceItems.php` | Creates `Clearance` rows for all department heads listed in seed (`docs/SEEDS.md`). Notifies each. |
| `ClearanceFullySigned` event | `api/app/Modules/HR/Events/ClearanceFullySigned.php` | Fired by `ClearanceService::sign()` once all rows complete. |
| `ComputeFinalPayAndDeactivate` listener | `api/app/Modules/HR/Listeners/ComputeFinalPayAndDeactivate.php` | Calls `FinalPayService::compute($employee)`, generates BIR 2316 PDF, calls `UserProvisioningService::deactivateForEmployee($employee)`. |
| Year-rollover scheduled command | `api/app/Console/Commands/ResetLeaveBalancesForYear.php` | Runs Jan 1 00:01 via `app/Console/Kernel.php`. Resets balances per `is_carried_over_to_next_year` rule on each leave_type. |

### 3.2 Tests (`api/tests/Feature/Chain/HireToRetireChainTest.php`)

- `test_creating_employee_initializes_pro_rated_leave_balances`
- `test_finalizing_payroll_generates_payslips_and_bank_file`
- `test_initiating_separation_opens_all_clearance_items`
- `test_full_clearance_signoff_computes_final_pay_and_deactivates_account`
- `test_year_rollover_command_resets_balances_per_carryover_rule`

---

## 4. C4 — Real-Time Chain Progress Tracker

### 4.1 Backend — single canonical event

Create `api/app/Common/Events/ChainStepAdvanced.php`:

```
class ChainStepAdvanced implements ShouldBroadcast {
    public function __construct(
        public string $entityType,    // 'sales_order'|'purchase_order'|'work_order'|'delivery'|'grn'
        public string $entityHashId,  // never raw id
        public string $newStatus,
        public string $activeStep,
        public array  $completedSteps,
        public ?string $actorName = null
    ) {}
    public function broadcastOn(): Channel {
        return new Channel("chain.{$this->entityType}.{$this->entityHashId}");
    }
}
```

### 4.2 Centralized broadcast helper

Create `api/app/Common/Services/ChainBroadcaster.php` with a method `broadcastFor(Model $entity, string $newStatus, ?User $actor = null)`. It maps the entity class to:
- `entityType` slug (`SalesOrder` → `sales_order`),
- `activeStep` and `completedSteps` derived from the chain definitions in [`spa/src/lib/chains/index.ts`](../spa/src/lib/chains/index.ts:1) — mirrored on the backend in a new `app/Common/Support/ChainDefinitions.php` (single source of truth, exported to TS via Tasks E2/X build artifact later — out of scope here).

Every status-mutation in `SalesOrderService`, `PurchaseOrderService`, `WorkOrderService`, `DeliveryService`, `GrnService`, `InspectionService` calls `ChainBroadcaster::broadcastFor(...)` after committing.

### 4.3 Frontend hook

Create [`spa/src/hooks/useChainProgress.ts`](../spa/src/hooks/useChainProgress.ts:1):

```
export function useChainProgress(entityType: string, entityId: string) {
  const queryClient = useQueryClient();
  useEffect(() => {
    const channel = window.Echo.channel(`chain.${entityType}.${entityId}`);
    channel.listen('.ChainStepAdvanced', (data: ChainStepEvent) => {
      queryClient.invalidateQueries({ queryKey: [entityType, entityId] });
      toast(`${data.activeStep} updated${data.actorName ? ' by ' + data.actorName : ''}`, { icon: '🔁' });
    });
    return () => { window.Echo.leave(`chain.${entityType}.${entityId}`); };
  }, [entityType, entityId, queryClient]);
}
```

Add a `ChainStepEvent` type to [`spa/src/types/chain.ts`](../spa/src/types/chain.ts:1).

### 4.4 Wire into existing detail pages

Add `useChainProgress(<type>, id)` near the top of:

- [`spa/src/pages/crm/sales-orders/detail.tsx`](../spa/src/pages/crm/sales-orders/detail.tsx:1) (verify exact path; create if missing)
- [`spa/src/pages/purchasing/purchase-orders/detail.tsx`](../spa/src/pages/purchasing/purchase-orders/detail.tsx:1)
- [`spa/src/pages/production/work-orders/detail.tsx`](../spa/src/pages/production/work-orders/detail.tsx:1)
- [`spa/src/pages/supply-chain/deliveries/detail.tsx`](../spa/src/pages/supply-chain/deliveries/detail.tsx:1)

### 4.5 Echo / Reverb setup

Verify [`spa/src/lib/echo.ts`](../spa/src/lib/echo.ts:1) is initialized; use existing public-channel pattern (`Channel`, not `PrivateChannel`) since chain progress is non-sensitive (only IDs and status, no PII). Document this decision in the listener docblock so a future security review (skill: [`security-review.md`](../.roo/skills/kwatog/security-review.md)) can revisit.

### 4.6 Tests

- Backend feature test: `tests/Feature/Chain/ChainBroadcastingTest.php` using `Event::fake([ChainStepAdvanced::class])` — confirm one broadcast per status change, correct payload shape.
- Frontend Vitest: `spa/src/hooks/useChainProgress.test.ts` — mock `window.Echo`, assert the hook subscribes and invalidates the query on event.

---

## 5. C5 — Chain Bottleneck Detection

### 5.1 Service

Create [`api/app/Common/Services/ChainBottleneckService.php`](../api/app/Common/Services/ChainBottleneckService.php:1) with one method per chain step + a single aggregator `detectAll(): array`. Thresholds in `api/config/chain.php`:

```
'bottlenecks' => [
    'so_at_mrp_planned'         => ['hours' => 48,  'audience' => 'ppc_head'],
    'wo_confirmed_unmaterialized' => ['hours' => 24, 'audience' => 'warehouse'],
    'inspection_outgoing_pending' => ['hours' => 4,  'audience' => 'qc_head'],
    'delivery_scheduled'        => ['hours' => 24, 'audience' => 'impex'],
    'invoice_draft'             => ['hours' => 24, 'audience' => 'finance_officer'],
    'pr_pending'                => ['hours' => 48, 'audience' => 'next_approver'],
    'bill_unpaid'               => ['hours' => 720,'audience' => 'finance_officer'],
],
```

Each detector returns rows: `['entity_type', 'hash_id', 'doc_number', 'stuck_since', 'hours_stuck', 'audience']`.

### 5.2 Scheduled command

Create `api/app/Console/Commands/RunChainBottleneckCheck.php` scheduled hourly. Persists results into the existing `alerts` table (migration [`0111_create_alerts_table.php`](../api/database/migrations/0111_create_alerts_table.php:1)) with `category='chain_bottleneck'`. Idempotent — won't double-create alert for same `(entity_type, entity_id, category)` open alert.

### 5.3 API endpoint

`GET /api/v1/chain/bottlenecks` — returns groups by step.
- Controller: `api/app/Common/Http/Controllers/ChainBottleneckController.php`.
- Permission: `dashboard.view_bottlenecks` (seed in `RolePermissionSeeder`).
- Route: register under `api/routes/api.php` with `['auth:sanctum', 'permission:dashboard.view_bottlenecks']`.

### 5.4 Frontend dashboard widget

Create `spa/src/components/dashboard/ChainBottleneckWidget.tsx` following Panel pattern from [`docs/DESIGN-SYSTEM.md`](../docs/DESIGN-SYSTEM.md:534). Uses TanStack Query (`['chain-bottlenecks']`, 60s `staleTime`). Renders rows with:

- Step label (text-sm primary)
- Count chip (variant: warning if < 5, danger if ≥ 5)
- Click row → navigates to filtered list of those entities

Include the widget in:

- Plant Manager dashboard
- PPC Head dashboard
- Finance Officer dashboard

Each widget instance pre-filters the rows that match its audience's relevant steps (so Finance only sees `invoice_draft` and `bill_unpaid`).

### 5.5 Tests

- `tests/Unit/ChainBottleneckServiceTest.php` — feed fixtures, assert correct rows returned for each detector.
- `tests/Feature/RunChainBottleneckCheckTest.php` — runs the scheduled command, asserts alerts created and idempotent on second run.
- `spa/src/components/dashboard/ChainBottleneckWidget.test.tsx` — renders all 5 page states (loading/error/empty/data/stale).

---

## 6. Cross-cutting concerns

### 6.1 Mandatory rules to verify per file

Per [`CLAUDE.md`](../CLAUDE.md:507) and [`docs/PATTERNS.md`](../docs/PATTERNS.md:1716):

- Every new model gets `HasHashId`. (None expected here — we are not adding tables.)
- Every API Resource returns `hash_id`, never raw `id`. The `ChainStepAdvanced` payload uses `entityHashId`.
- Every listener wraps its writes in `DB::transaction()` even if the underlying service already does — listener-level transaction protects multi-service orchestrations.
- Auto-generated rows tagged `is_auto_generated=true`, `auto_generated_reason='chain_automation'`. Confirm columns exist (already added in migrations [`0114`](../api/database/migrations/0114_add_is_auto_generated_to_ncr.php:1) and [`0116`](../api/database/migrations/0116_add_is_auto_generated_to_purchase_orders.php:1)). Add similar columns where missing via a single new migration `0122_add_is_auto_generated_to_chain_entities.php`.
- Every list/detail page touched honors the 5 mandatory states.
- All numbers in widget tables use `font-mono tabular-nums`; status uses `<Chip>` with semantic variant.
- No color on canvas — bottleneck widget rows are gray; only chip + count are colored.

### 6.2 Permissions to seed

Add to `RolePermissionSeeder`:

- `dashboard.view_bottlenecks` → roles: plant_manager, ppc_head, finance_officer, system_admin.

### 6.3 Performance — N+1 prevention

In `ChainBottleneckService` detectors, eager load the relationships the API Resource accesses. See [`eloquent-performance.md`](../.roo/skills/kwatog/eloquent-performance.md). Add DB indexes on `(status, updated_at)` for the seven tables queried (one migration: `0123_add_indexes_for_chain_bottleneck_queries.php`).

### 6.4 Security review checkpoints

Per [`security-review.md`](../.roo/skills/kwatog/security-review.md):

- Public broadcast channel for chain progress is acceptable because payload contains no PII or money — only doc numbers, statuses, and an actor display name. Add a docblock to `ChainStepAdvanced` stating this and forbidding payload expansion without re-review.
- Bottleneck endpoint enforces `permission:dashboard.view_bottlenecks` server-side. Frontend `<CanDo>` is UX only.
- Auto-emailed POs go to `vendors.email` — validate `email` field on Vendor model is a real email, sanitize before render. If missing, listener queues a "supplier email missing" notification instead of crashing.

### 6.5 Quality gate (mandatory before marking done)

Per [`code-quality-gate.md`](../.roo/skills/kwatog/code-quality-gate.md):

```
cd api && composer install && php artisan migrate:fresh --seed && php artisan test
cd spa && npm ci && npm run lint && npm run typecheck && npm run test
```

All must pass. Report results in PR body.

---

## 7. File-by-file create/modify list

### 7.1 New backend files

```
api/app/Common/Events/ChainStepAdvanced.php
api/app/Common/Services/ChainBroadcaster.php
api/app/Common/Services/ChainBottleneckService.php
api/app/Common/Support/ChainDefinitions.php
api/app/Common/Http/Controllers/ChainBottleneckController.php
api/app/Console/Commands/RunChainBottleneckCheck.php
api/app/Console/Commands/ResetLeaveBalancesForYear.php
api/config/chain.php
api/config/purchasing.php
api/database/migrations/0122_add_is_auto_generated_to_chain_entities.php
api/database/migrations/0123_add_indexes_for_chain_bottleneck_queries.php

# Events
api/app/Modules/Production/Events/WorkOrderCompleted.php
api/app/Modules/Quality/Events/InspectionPassed.php
api/app/Modules/Quality/Events/InspectionFailed.php
api/app/Modules/Purchasing/Events/PurchaseRequestApproved.php
api/app/Modules/Purchasing/Events/PurchaseOrderApproved.php
api/app/Modules/Inventory/Events/GoodsReceiptNoteCreated.php
api/app/Modules/HR/Events/EmployeeCreated.php
api/app/Modules/HR/Events/SeparationInitiated.php
api/app/Modules/HR/Events/ClearanceFullySigned.php
api/app/Modules/Payroll/Events/PayrollPeriodFinalized.php

# Listeners (C1)
api/app/Modules/CRM/Listeners/InitiateOrderToCashChain.php
api/app/Modules/Production/Listeners/TriggerOutgoingQC.php
api/app/Modules/Quality/Listeners/CreateDeliveryDraftOnQcPass.php

# Listeners (C2)
api/app/Modules/Purchasing/Listeners/ConsolidatePurchaseOrders.php
api/app/Modules/Purchasing/Listeners/SendPOToSupplier.php
api/app/Modules/Quality/Listeners/TriggerIncomingQC.php
api/app/Modules/Quality/Listeners/AcceptGRNAndDraftBill.php
api/app/Modules/Quality/Listeners/RejectGRNOnQcFail.php

# Listeners (C3)
api/app/Modules/Leave/Listeners/InitializeLeaveBalances.php
api/app/Modules/Payroll/Listeners/GeneratePayslipsAndNotify.php
api/app/Modules/HR/Listeners/OpenClearanceItems.php
api/app/Modules/HR/Listeners/ComputeFinalPayAndDeactivate.php

# Mailables / Notifications
api/app/Modules/Purchasing/Notifications/PurchaseOrderToSupplierMail.php

# Tests
api/tests/Feature/Chain/OrderToCashChainTest.php
api/tests/Feature/Chain/ProcureToPayChainTest.php
api/tests/Feature/Chain/HireToRetireChainTest.php
api/tests/Feature/Chain/ChainBroadcastingTest.php
api/tests/Feature/RunChainBottleneckCheckTest.php
api/tests/Unit/ChainBottleneckServiceTest.php
```

### 7.2 New frontend files

```
spa/src/hooks/useChainProgress.ts
spa/src/api/chain.ts                    # GET /chain/bottlenecks wrapper
spa/src/components/dashboard/ChainBottleneckWidget.tsx
spa/src/components/dashboard/ChainBottleneckWidget.test.tsx
spa/src/hooks/useChainProgress.test.ts
spa/src/types/chain.ts                  # extend with ChainStepEvent
```

### 7.3 Backend files to modify

```
api/app/Modules/CRM/Services/SalesOrderService.php          # call ChainBroadcaster on every transition
api/app/Modules/Production/Services/WorkOrderService.php    # add complete() + fire WorkOrderCompleted; broadcast
api/app/Modules/Production/Services/WorkOrderOutputService.php  # broadcast on auto-complete
api/app/Modules/Quality/Services/InspectionService.php      # createPending(); fire Passed/Failed; broadcast
api/app/Modules/Inventory/Services/GrnService.php           # fire GoodsReceiptNoteCreated; broadcast
api/app/Modules/SupplyChain/Services/DeliveryService.php    # broadcast on every transition
api/app/Modules/Accounting/Services/InvoiceService.php      # createDraftFromDelivery(); broadcast
api/app/Modules/Accounting/Services/BillService.php         # createDraftFromGrn()
api/app/Modules/Purchasing/Services/PurchaseOrderService.php # auto-approve under threshold; fire PurchaseOrderApproved
api/app/Modules/Purchasing/Services/AutoPurchaseOrderService.php # accept multi-PR consolidation
api/app/Modules/HR/Services/EmployeeService.php             # fire EmployeeCreated
api/app/Modules/HR/Services/SeparationService.php           # fire SeparationInitiated
api/app/Modules/HR/Services/ClearanceService.php            # fire ClearanceFullySigned
api/app/Modules/Payroll/Services/PayrollPeriodService.php   # fire PayrollPeriodFinalized
api/app/Common/Services/ApprovalService.php                 # fire PurchaseRequestApproved/PurchaseOrderApproved on final approval
api/app/Providers/EventServiceProvider.php                  # bind all event→listener pairs
api/app/Console/Kernel.php                                  # schedule RunChainBottleneckCheck hourly + ResetLeaveBalancesForYear yearly
api/database/seeders/RolePermissionSeeder.php               # add dashboard.view_bottlenecks
api/routes/api.php                                          # register /chain/bottlenecks
```

### 7.4 Frontend files to modify

```
spa/src/pages/crm/sales-orders/detail.tsx           # useChainProgress
spa/src/pages/purchasing/purchase-orders/detail.tsx # useChainProgress
spa/src/pages/production/work-orders/detail.tsx     # useChainProgress
spa/src/pages/supply-chain/deliveries/detail.tsx    # useChainProgress
spa/src/pages/dashboard/index.tsx                   # mount ChainBottleneckWidget per role
```

---

## 8. Execution order (recommended for code mode)

Execute in five PRs, in this order, each gated independently:

1. **PR-C1-events-and-O2C** — events `WorkOrderCompleted`/`InspectionPassed`/`InspectionFailed`, three O2C listeners, service additions, tests. Quality gate must pass.
2. **PR-C2-procure-to-pay** — events + five listeners + threshold config + supplier email. Tests + gate.
3. **PR-C3-hire-to-retire** — remaining HR/Payroll events, listeners, year-rollover command. Tests + gate.
4. **PR-C4-realtime-tracker** — `ChainStepAdvanced`, `ChainBroadcaster`, hook into 4 detail pages, broadcast hooks in services. Tests + gate.
5. **PR-C5-bottlenecks** — service, scheduled command, endpoint, dashboard widget. Tests + gate.

Each PR follows the [`commit-and-pr.md`](../.roo/skills/kwatog/commit-and-pr.md) skill: conventional commits, target `kwat0g/kwatog`, PR body must include the gate output.

---

## 9. Risks and watch-outs

- **Event loops.** `InspectionPassed` is consumed by both the C1 (outgoing) and C2 (incoming) listeners; they must filter on `stage` first. Add a unit test that fires both stages and asserts only the correct listener acts.
- **Idempotency.** Re-running `InitiateOrderToCashChain` on the same SO must not create duplicate WOs. Solution: check for existing WOs with `(sales_order_id, sales_order_item_id)` before creating; use a unique partial index.
- **Queue ordering.** Chain listeners use `ShouldQueue` + same queue (`chain`) to preserve order per entity. Configure a single worker for that queue, or use `WithoutOverlapping` middleware keyed by `entity:id`.
- **Migration safety.** `0122` and `0123` only ADD columns/indexes — safe online.
- **Test data complexity.** Hire-to-retire chain test needs a fully seeded employee with leave types, payroll period, departments. Build a `ChainTestSeeder` to keep tests readable.
- **Reverb channel limits.** Per the spec the chain channel is public; if Reverb config caps concurrent channels, add a single shared `chain.{type}` channel with payload-level filtering as a fallback. Document in the listener.

---

## 10. Definition of done

- [ ] All five PRs merged to main, each with green CI.
- [ ] [`docs/PATTERNS.md`](../docs/PATTERNS.md:1) checklist passes for every changed file.
- [ ] Quality gate (`api`: composer + migrate + test; `spa`: lint + typecheck + test) green on each PR.
- [ ] Manual smoke: confirm an SO end-to-end and observe in real time the WO/inspection/delivery/invoice cascade in another browser tab without refresh.
- [ ] Bottleneck dashboard widget shows non-empty data when an SO is intentionally left at `mrp_planned` for > 48h (use freezable `Carbon::setTestNow()` in a one-off test scenario).
- [ ] No new `console.log`, no Bearer-token usage, no localStorage auth, no raw integer IDs in any payload.

## 11. Expanded process audit tranche — 2026-08-11

The original chain plan is now complemented by the [failure-path matrix](../docs/PROCESS-FAILURE-MATRIX-2026-08-11.md).
The implementation pass has closed the remaining direct value-changing
triggers identified by the audit (payroll compute, year-end leave, and budget
actuals), then hardened the remaining scheduled jobs (preventive maintenance
and depreciation), scheduled exports, derived monthly batches, safety stock,
alert checks, dunning, stale-run reapers, scheduler lock expiry, queue lease
configuration, archive publication, deployment migration ordering, and host
backup/restore handling.

The local execution/static-analysis verification pass is current: **1,564
backend tests / 5,431 assertions**, PHPStan, **24 SPA files / 202 tests**,
SPA lint/typecheck/token audit/build, the focused scheduler/queue/provider/
backfill regression (**16 tests / 54 assertions**, including an actual failed
due-task tick), the legacy payroll
compatibility regression (**9 tests / 37 assertions**), scheduled-job durable
handoff focus (**9 tests / 42 assertions**), real Redis replay,
real worker interruption/redelivery, backup/restore, and disposable cleanup all
pass. The audited PHP files pass scoped Pint. The repository-wide Pint target
still reports the pre-existing
baseline of **1,531 issues across 2,165 files**; that unrelated dirty surface
was not mass-formatted. The next move is controlled staging verification:

1. Exercise scheduler restart, stale export lease, missed monthly
   period/backfill against real operational data, and provider-timeout
   scenarios in staging.
2. Verify SMTP, bank, supplier transport, backup freshness, and restore
   integrity with real deployment configuration.
3. Only then claim live process hardening complete; until those external gates are green, keep the
   remaining-risk ledger open.

## 12. Final failure-path hardening tranche — 2026-08-11

The adversarial process audit found and closed the remaining local false-green
and stale-worker boundaries:

- outbox leases now carry a UUID fence, so an old worker cannot mark a message
  published, pending, or failed after a newer worker reclaimed it;
- delayed duplicate outbox jobs cannot bypass retry backoff, and scheduled
  dispatch reclaims processing rows with a missing lease timestamp;
- missing scheduler terminal rows and missing listener outcome rows are
  recreated so execution evidence is not silently lost;
- failed queue jobs are part of chain automation health, dashboard visibility,
  and hourly alerts rather than being visible only in an admin page;
- finalized payroll payslip delivery has a targeted fifteen-minute
  reconciliation command that recovers only failed/pending/stale-queued claims;
- realtime notification broadcast failures no longer retry the durable inbox
  write, while queued notification failures rethrow for queue recovery;
- notification digest enqueue failures are counted and return a non-zero
  scheduler result; and
- the scheduler clean-tick regression is independent of wall-clock production
  due times, while the actual failing-task behavior remains covered.

Final evidence:

- isolated full backend gate: **1,564 tests / 5,431 assertions**;
- targeted final recovery suites: **83 tests / 265 assertions**;
- PHPStan, SPA typecheck/lint, **202 SPA tests**, production Compose config,
  PHP/shell syntax, and diff checks pass;
- complete migration chain reaches
  `2026_08_11_170000_add_lease_token_to_event_outbox`;
- `make chain-smoke` passes with a real Redis worker and zero failed jobs; and
- `make worker-recovery-smoke` passes after killing the first worker, with the
  reclaimed job completing exactly once on attempt two.

The remaining risks are intentionally external or policy-specific: provider
exactly-once receipts, Redis failover, scheduler restart and missed-period
backfills against operational data, real SMTP/bank/supplier transport, and
deployed backup freshness/restore/rollback drills.
