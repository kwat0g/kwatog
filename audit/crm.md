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
