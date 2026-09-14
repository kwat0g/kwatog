# Audit: crm — 2026-09-06

## Summary

CRM is one of the hardest units in the codebase. The 2026-08-26 dead 8D-escalation
subsystem is genuinely fixed: the table is pinned on the model + migrated, the durable
claim ledger records failures as retryable `pending` rows with `last_error`, notification
failure rolls back tier claims and inbox rows atomically, idempotency and terminal-skip
are all test-pinned (7 dedicated tests, all green). Pricing is correct: overlap windows
are refused under product/customer row locks on create/update/restore, resolution is by
delivery date with a deterministic latest-`effective_from` winner, and an expired window
hard-fails the SO with a field-targeted 422 instead of stale pricing. The SO state
machine is forward-only with a persisted rejection ledger, cancel has real downstream
reconciliation (deliveries/invoices/WOs), credit-limit checks are serialized by customer
row lock, and money stays decimal-string end-to-end. All 94 CRM-filtered tests pass
(305 assertions, ~89s). The one real break is order-to-cash sequencing: once any invoice
is finalized the SO is terminal, so the remaining deliveries of a partially-billed order
can never be confirmed. Complaint linkage fields (replacement WO, credit memo) are dead,
and complaint SLA state has no UI surface.

## Findings

| ID | Category | Severity | Effort | Title | Location | Evidence/Repro |
|---|---|---|---|---|---|---|
| CR-01 | Broken process | High | M | SO becomes terminal on first finalized invoice — remaining deliveries of a partially-billed order can never be confirmed | api/app/Modules/CRM/Services/SalesOrderService.php:69-77,776-786; api/app/Modules/SupplyChain/Services/DeliveryService.php:916-924; api/app/Modules/Accounting/Services/InvoiceService.php:298-301 | `ALLOWED_TRANSITIONS['invoiced'] = []`; `markDelivered/markPartiallyDelivered` throw via `transitionOrFail`; DeliveryService::confirm() calls them uncaught inside its own transaction. Repro: deliver 4 of 10 → confirm (SO=partially_delivered, draft invoice auto-created) → finalize invoice (supported: `partially_delivered → invoiced` is a tested forward-skip; SO detail even ships a "Finalize" button for the draft) → deliver remaining 6 → confirm → BusinessRuleException → **entire delivery confirmation rolls back** (status flip, qty sync, invoice handoff). No path forward: cancelling the invoice does not demote SO status. No test covers deliver-after-invoice; the transition tests pin "invoiced is terminal" as intended. |
| CR-02 | Gap | Medium | M | Complaint `replacement_work_order_id` and `credit_memo_id` are dead columns; no replacement-WO path exists for complaint-originated NCRs | api/database/migrations/0098_create_customer_complaints_table.php:31-32; api/app/Modules/Quality/Services/NcrService.php:319-357; api/app/Modules/CRM/Services/ComplaintService.php:144-167 | Nothing anywhere writes either complaint column (grep across api/app + spa). NCR auto-creates replacement/rework WOs **only when `ncr.inspection_id` is set and the inspection stage is Outgoing** — a complaint NCR is created with no inspection_id, so even a `scrap` disposition on a customer complaint never spawns replacement production, and the complaint row can never link one. `credit_memo_id` is "FK reserved for finance Sprint 8" (migration comment) — credit memos for complaints are unimplemented. Traceability today is complaint → NCR only. Cross-module → quality. |
| CR-03 | Gap | Medium | S | 8D SLA state has no UI or API surface — overdue/escalated complaints are invisible in the product | api/app/Modules/CRM/Resources/CustomerComplaintResource.php:15-77; spa/src/pages/crm/complaints/detail.tsx | The escalation engine works, but `d3_due_at`/`d4_due_at`/`finalize_due_at`/`sla_alert_levels` are not in the resource, and no SPA page renders due dates, fired tiers, or the pending-delivery ledger. Operators can only discover a breached SLA via the in-app notification or by querying the DB. For an IATF differentiator, the complaint detail should show tier due/overdue state and escalation history. |
| CR-04 | Missing | Low | S | Complaint statuses `investigating` and `cancelled` are unreachable | api/app/Modules/CRM/Services/ComplaintService.php:53-61; Enums/ComplaintStatus.php:10-14 | `LIFECYCLE_TRANSITIONS` only defines `resolve` (open/investigating → resolved) and `close` (resolved → closed); no service method or route ever sets `investigating` or `cancelled` (grep confirms only reads). The SPA complaint-flow stepper and dashboard widgets render an Investigating stage that no complaint can ever occupy. Either add start-investigation/cancel transitions or drop the cases from the enum/UI. |
| CR-05 | Bad practice | Low | M | SO detail "Activity" panel is a hardcoded 2-line stub, not the sanctioned feed; cancellation emits no domain event | spa/src/pages/crm/sales-orders/detail.tsx:355-366; api/app/Modules/CRM/Services/SalesOrderService.php:649-710 | SO is one of the four sanctioned activity surfaces, but the panel fabricates items from `created_at` + current status. confirm() publishes via outbox and mirrors into the company feed through the wildcard listener; cancel() emits no event at all (ChainBroadcaster only), so a cancellation reaches the admin feed only indirectly, if ChainStepAdvanced fires. No per-SO activity endpoint exists. |
| CR-06 | Other (docs) | Low | S | docs/SCHEMA.md CRM section drifted from the real schema | docs/SCHEMA.md:354-358 vs migrations 0098/0099/0193/2026_08_26_000000 | customer_complaints doc: severity "critical/major/minor" (actual low/medium/high/critical), `date_received` (actual `received_date`), `invoice_id` column (does not exist); omits `ncr_id`, `assigned_to`, `resolved_at/closed_at`, NCR-handoff columns, SLA columns. complaint_8d_reports doc uses stale column names (`d2_problem_description`… `completed_at`; actual `d2_problem`… `finalized_at`). Missing entirely: `complaint_8d_escalation_deliveries`, `sales_order_transition_rejections`. |
| CR-07 | Risk | Low | S | `complaints:check-8d-slas` still exits SUCCESS with zero counts when everything fails | api/app/Console/Commands/RunComplaint8dSlaChecks.php:19-27; Services/Complaint8dEscalationService.php:250-258 | advanceOne() catches every Throwable into recordFailure() + Log::warning and returns []; run() counts only successful sends. If the whole batch fails, the command prints `d3=0 d4=0 finalize=0` and exits 0 — exactly the shape the 2026-08-26 lesson condemned. Mitigated (not solved) because failures now persist as `pending` ledger rows with `last_error`; the exit code still cannot distinguish "nothing to do" from "everything failed". |
| CR-08 | Bad practice | Low | S | Decimal-as-string convention leaks into JS floats on two CRM pages | spa/src/pages/crm/sales-orders/index.tsx:50-52; spa/src/pages/crm/sales-orders/create.tsx:212-221 | List-page "Total Value" stat sums `Number(order.total_amount)` (float accumulation of decimal strings); create-page subtotal preview multiplies floats. Both display-only (server stays authoritative), but both violate the documented decimal-as-string rule; use the shared money/string helpers. |
| CR-09 | Risk | Low | S | CRM inquiry inbox routes bypass the `feature:crm` toggle | api/app/Modules/Landing/routes.php:26-31 vs api/app/Modules/CRM/routes.php:17 | All CRM module routes sit behind `feature:crm`; the Landing module mounts `/crm/inquiries*` with `auth:sanctum` + permissions only, so the CRM inbox remains reachable when the CRM feature flag is off. Cross-module → landing. |

### CR-01 detail (High — the one real break)

The forward-only transition matrix deliberately makes `invoiced` terminal ("Refusing
the transition therefore rolls back a posted JE"), and InvoiceService::finalize()
promotes any standard SO-linked invoice — including the per-delivery draft invoices
that DeliveryService auto-creates. Partial-delivery billing is an explicitly supported,
test-pinned flow (`partially_delivered → invoiced`). Once it happens, the next
`DeliveryService::confirm()` computes coverage, calls `markDelivered`/
`markPartiallyDelivered`, hits the terminal state, and `transitionOrFail` throws inside
the confirmation transaction — the delivery never reaches Confirmed, goods for the
balance cannot ship, and the SO is stuck `invoiced` with open quantity (cancelling the
invoice does not demote the SO). Note also that in this nested-transaction path the
`SalesOrderTransitionRejection` row written by `transitionTo()` is rolled back with
everything else, so the rejection ledger misses precisely the rejections that cause a
rollback. Fix options: (a) allow `invoiced → delivered/partially_delivered` (billing is
not the physical terminal state; keep `delivered → invoiced` too), or (b) have
DeliveryService treat a terminal-SO transition as a recorded no-op instead of a rollback.
Either needs a matching test for the deliver-after-invoice sequence.

### CR-02 detail (Medium)

The complaint→Quality handoff itself is well built (failure degrades to
`manual_required` + durable outbox replay, retry endpoint, idempotent listener), but the
commercial tail of a complaint is unimplemented: the replacement WO the business context
expects per complaint is only ever created on the NCR, and only for outgoing-inspection
NCRs; complaint NCRs have no `inspection_id`, so a customer complaint that ends in
`scrap` generates no replacement production and no link back to the complaint. Credit
memos do not exist in Accounting at all (the complaint FK is a reservation comment).
Until this is built, the complaint record cannot show how the customer was made whole —
a visible gap for an IATF customer-audit trail (8D report exists; disposition evidence
chain stops at the NCR).

## Cross-module flags

- **quality**: CR-02 — replacement/rework WO auto-creation in NcrService::close() is
  gated on `inspection.stage === Outgoing`; complaint-source NCRs never qualify. Decide
  whether scrap-on-complaint should spawn replacement WOs (and back-link them to
  `customer_complaints.replacement_work_order_id`).
- **supply_chain / accounting**: CR-01 — the stuck sequence spans three units; the fix
  belongs to the SO transition matrix (CRM) but must be agreed with SupplyChain
  (delivery confirm) and Accounting (finalize semantics). Rejection-ledger rollback note
  included there.
- **landing**: CR-09 — inquiry inbox routes lack the `feature:crm` guard every other
  `/crm/*` route carries.
- **docs**: CR-06 — SCHEMA.md CRM section needs regeneration (also missing the
  escalation-deliveries and transition-rejections tables).

## What was NOT checked

- B2B customer-portal surfaces that read SOs/complaints (module 13) — only the CRM-owned
  resource payloads were reviewed.
- MRP consumption of `SalesOrderConfirmed` (QueueMrpOnSalesOrderConfirmed, capacity
  planning) beyond confirming the outbox publication — belongs to the mrp unit.
- Invoice/collection internals past `finalize()` (AR aging, receipts) — accounting unit.
- WebSocket delivery of ChainBroadcaster/Reverb events; only the emitting call sites.
- No full-suite run: one `--filter='CRM'` pass (94 passed / 305 assertions / 89s).
- Email rendering content (Mail classes) beyond enqueue/fallback logic.
- Forecasting/ReturnManagement modules' use of CRM data (modules 14/15).

## Code-reading re-audit — 2026-09-14 (56e0d431)

### Status changes

- **CR-01 — materially fixed, with one residual auditability risk.** `SalesOrderService::ALLOWED_TRANSITIONS` now permits `invoiced` to move to `partially_delivered` or `delivered` (`api/app/Modules/CRM/Services/SalesOrderService.php:79-87`). The delivery confirmation path still calls the promotion inside its transaction (`api/app/Modules/SupplyChain/Services/DeliveryService.php:977-987`), and the current CRM transition suite contains the full partial-billing/remaining-delivery regressions (`api/tests/Feature/CRM/SalesOrderStatusTransitionsTest.php:313-385`). The original stuck process is therefore no longer supported by the current source. The rejection-ledger residual is recorded below.
- **CR-02 through CR-09 — unresolved in the current source.** The re-audit found no implementation or route/resource change that closes those findings. Their current evidence is listed below rather than treating the historical audit wording as proof of closure.

### Unresolved findings

| ID | Category | Severity | Effort | Title | Location | Evidence/Reproduction |
|---|---|---|---|---|---|---|
| CR-01-R1 | Risk | Medium | M | Rejected SO transitions can still lose their rejection ledger row when invoked inside an owning transaction | api/app/Modules/CRM/Services/SalesOrderService.php:838-892; api/app/Modules/SupplyChain/Services/DeliveryService.php:977-987 | `transitionTo()` inserts `SalesOrderTransitionRejection` inside its nested transaction at lines 869-882, then `transitionOrFail()` throws at lines 840-845. If a sibling service invokes a refused `mark*()` transition inside its outer transaction, the outer rollback removes the rejection row along with the business operation. Direct calls can retain the row, so the ledger is execution-context dependent. Reproduce with any illegal transition called from an enclosing `DB::transaction()` and inspect `sales_order_transition_rejections` after the exception. |
| CR-02 | Gap | Medium | M | Complaint replacement-WO and credit-memo outcomes remain unimplemented | api/app/Modules/CRM/Models/CustomerComplaint.php:27-35,72-75; api/app/Modules/Quality/Services/NcrService.php:319-357; api/app/Modules/CRM/Services/ComplaintService.php:141-167 | The complaint model still exposes `replacement_work_order_id` and `credit_memo_id`, but no CRM path writes either field. `NcrService::close()` creates replacement/rework WOs only when `ncr.inspection_id` points to an outgoing inspection; complaint-originated NCRs have no inspection ID, so a complaint NCR closed as scrap/rework does not create a replacement/rework WO or back-link it to the complaint. No credit-memo consumer exists. Reproduce by creating a complaint, closing its linked complaint NCR with a disposition, and inspecting both complaint columns. |
| CR-03 | Gap | Medium | M | 8D SLA due dates, fired tiers, and delivery history remain absent from the CRM API/UI | api/app/Modules/CRM/Resources/CustomerComplaintResource.php:15-77; spa/src/pages/crm/complaints/detail.tsx:257-327; api/app/Modules/CRM/Services/Complaint8dEscalationService.php:139-248 | The escalator still writes due-tier delivery state to `customer_complaints` and `complaint_8d_escalation_deliveries`, but `CustomerComplaintResource` does not return `d3_due_at`, `d4_due_at`, `finalize_due_at`, `sla_alert_levels`, or escalation deliveries. The detail page renders only received/resolved dates and a generic status message, so an operator cannot see the deadline, overdue tier, retry state, recipient outcome, or escalation history. Reproduce by making a complaint overdue and requesting its detail resource; the overdue metadata is not in the response and no page section renders it. |
| CR-04 | Missing | Low | S | Complaint `investigating` and `cancelled` remain unreachable lifecycle states | api/app/Modules/CRM/Services/ComplaintService.php:45-61,259-266; api/app/Modules/CRM/Enums/ComplaintStatus.php:8-18; spa/src/pages/crm/complaints/detail.tsx:154-158 | The service still exposes only `resolve()` and `close()`. `LIFECYCLE_TRANSITIONS` accepts `open` or `investigating` as inputs to resolve, but no route/service method changes an open complaint to `investigating` or to `cancelled`. The enum, options endpoint, stepper, and status-chip maps advertise states that operators cannot enter. Reproduce by creating an open complaint and trying to start investigation or cancel it: there is no CRM endpoint/action, while the UI still renders both cases. |
| CR-05 | Bad practice | Low | M | Sales-order Activity remains fabricated and cancellation still has no sanctioned activity event | spa/src/pages/crm/sales-orders/detail.tsx:355-366; api/app/Modules/CRM/Services/SalesOrderService.php:711-771 | The SO detail Activity panel still constructs two local rows from `created_at`, current status, and `updated_at`; it does not call an activity endpoint or consume a per-SO event stream. `cancel()` broadcasts chain progress but emits no `SalesOrder` domain/activity event, so cancellation is not guaranteed to appear in the sanctioned SO activity surface. Reproduce by confirming and then cancelling an order, then opening the detail page: the panel still shows only the synthetic creation/current-status rows. |
| CR-06 | Gap | Low | S | CRM schema documentation remains materially stale | docs/SCHEMA.md:343-358; api/database/migrations/0098_create_customer_complaints_table.php:19-37; api/database/migrations/0099_create_complaint_8d_reports_table.php:20-36; api/database/migrations/2026_08_26_000000_create_complaint_8d_escalation_deliveries.php:13-41 | `docs/SCHEMA.md` still describes `date_received`, the old severity set, `invoice_id`, and old 8D column names, while the migrations use `received_date`, `low/medium/high/critical`, `d2_problem` through `d8_recognition`, and `finalized_at`. It also omits the complaint NCR/handoff fields and both `complaint_8d_escalation_deliveries` and `sales_order_transition_rejections`. A reader following the required schema reference will build against non-existent CRM columns. |
| CR-07 | Risk | Low | S | 8D SLA command still reports success when all candidate evaluations fail | api/app/Console/Commands/RunComplaint8dSlaChecks.php:19-27; api/app/Modules/CRM/Services/Complaint8dEscalationService.php:250-258,342-397 | `advanceOne()` catches every `Throwable`, records a pending delivery when possible, logs, and returns an empty result. The command counts only sent tiers and unconditionally returns `SUCCESS`, so a schema/configuration failure across every candidate prints `d3=0 d4=0 finalize=0` and exits zero, indistinguishable from a healthy idle run. The durable pending ledger is an improvement but does not change the command's failure signal. Reproduce by making the batch evaluation throw for every overdue candidate and inspect the command exit status. |
| CR-08 | Bad practice | Low | S | CRM frontend still converts decimal money strings to JavaScript numbers | spa/src/pages/crm/sales-orders/index.tsx:50-52; spa/src/pages/crm/sales-orders/create.tsx:209-221 | The SO list totals the page with `Number(order.total_amount)` and the create form's estimate multiplies `Number(quantity)` by `Number(standard_cost)`. These are display-only estimates, so the server totals remain authoritative, but they violate the repository's decimal-as-string rule and can visibly round or accumulate incorrectly for large/cent-sensitive values. Reproduce with values whose binary float representation is not exact and compare the displayed estimate/page total with decimal-string arithmetic. |
| CR-09 | Risk | Low | S | CRM inquiry API routes still bypass the CRM feature toggle | api/app/Modules/Landing/routes.php:25-31; api/app/Modules/CRM/routes.php:18-19 | The Landing module mounts `/api/v1/crm/inquiries*` with `auth:sanctum` and permissions but no `feature:crm` middleware, while CRM-owned routes use `auth:sanctum` plus `feature:crm`. Turning off the CRM feature therefore leaves the inquiry API reachable to an authorized user and diverges from the SPA module guard. Reproduce with `crm` disabled and an authenticated user holding `crm.inquiries.view`; the Landing route still resolves. Cross-module flag: landing. |
| CR-10 | Gap | Medium | M | Accepted customer proposed delivery dates are stored but never applied to the sales order | api/app/Modules/CRM/Services/SalesOrderResponseService.php:136-164,202-241; api/app/Modules/CRM/Models/SalesOrderResponse.php:26-39; api/tests/Feature/CRM/SalesOrderResponseTest.php:89-106,208-256 | Customer responses persist `proposed_delivery_date`, expose it in the response/resource, and the tests assert only that it is stored. `resolve()` applies proposed quantities and prices through `applyProposal()`, but neither `resolve()` nor `applyProposal()` reads the accepted response date or updates the affected `sales_order_items.delivery_date`; the date has no other CRM consumer. A customer can propose or accept a new delivery date, sales can accept the response, and the order continues to carry its old schedule. The analogous supplier workflow applies its accepted proposal date to the PO (`api/app/Modules/Purchasing/Services/SupplierResponseService.php:159-168`). Reproduce with a pending customer proposal containing `proposed_delivery_date`, accept it internally, and compare `sales_order_items.delivery_date` before and after. |

### Clean areas

- HashID output and decimal-string API serialization are consistent across the inspected CRM resources, including SO lines, products, price agreements, and response items.
- Price-agreement create/update/restore paths retain product/customer row locking, active-reference checks, overlap rejection, and tier normalization (`api/app/Modules/CRM/Services/PriceAgreementService.php:78-150,204-360`).
- SO create/confirm paths retain transaction boundaries, active reference rechecks, customer credit-limit serialization, and server-side price resolution (`api/app/Modules/CRM/Services/SalesOrderService.php:361-431,518-579`).
- Complaint-to-NCR handoff remains durable and retryable, and 8D update/finalize operations retain parent/report lock ordering and the finalized edit guard (`api/app/Modules/CRM/Services/ComplaintService.php:177-256,269-315`).
- The prior dead 8D escalation table/model defect is not present: the delivery model pins `complaint_8d_escalation_deliveries`, and the service records pending/sent outcomes with idempotency keys (`api/app/Modules/CRM/Models/Complaint8dEscalationDelivery.php:17-45`; `api/app/Modules/CRM/Services/Complaint8dEscalationService.php:179-248`).
- CRM routes and SPA routes remain lazy-loaded and permission-gated; the inquiry feature-toggle exception is specifically the backend Landing-route issue recorded as CR-09.

### Verification limits

- This was a read-only code-reading audit at commit `56e0d431`; no tests, Docker, Artisan, browser, queue, scheduler, database, or migration commands were run.
- The prior audit's reported test results are historical evidence only; this re-audit did not re-run them.
- Cross-module behavior was inspected only at directly relevant consumers, notably Delivery/Invoice, Quality NCR, B2B customer responses, Purchasing supplier responses, Forecasting product toggles, and Landing inquiry routes. Full B2B, Accounting, Supply Chain, Quality, MRP, and browser surfaces were not re-audited.

### No code change

No application code, migration, test, registry, or roadmap file was modified. This section was appended to `audit/crm.md` only, as requested.
