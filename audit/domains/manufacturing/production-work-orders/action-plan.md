# M051 — Production Work Orders Action Plan

**Prepared:** 2026-08-25  
**Status:** 📋 Plan Ready  
**Recommendation:** separate implementation sessions, followed by a focused re-audit.

## Execution order

| Priority | Finding | Planned change | Scope / dependency | Session guidance |
|---|---|---|---|---|
| 1 | B05, B06 | Define one reservation → issue → lot lineage contract; make required issue behavior transactional and persist the actual lot/quantity used. | Production + Inventory services, stock movement/input model, migration/tests; requires inventory policy decision. | Dedicated cross-module session |
| 2 | B03 | Add explicit machine ownership/conflict guards to start, pause, resume, and complete; preserve the current assignment on failed transitions. | Work-order service/tests; confirm whether running-machine reuse is ever valid. | Dedicated lifecycle session |
| 3 | B02 | Pass the authenticated actor through lifecycle/material-issue calls and audit the true performer. | Controller/service signatures, stock movement audit tests. | Small once contract is agreed |
| 4 | B04 | Refresh/reload the authoritative output after manual receipt fallback and return the durable state in response/events. | Output service, resource/controller test. | Small same-session fix |
| 5 | B07 | Enforce completed + scrapped quantity against operation plan and define overproduction behavior. | Operation service/request tests; confirm tolerance/overrun policy. | Dedicated operation session |
| 6 | B08 | Reject non-empty defect rows when reject quantity is zero; validate persisted defect sum and duplicate/invalid rows. | Request/service/tests. | Small same-session fix |
| 7 | B09 | Log skip transitions with actor, reason, previous/current status, and timestamps. | Operation service/audit test. | Small same-session fix |
| 8 | M01 | Call routing generation from work-order creation after the routing/material snapshot is established, with idempotent behavior. | Work-order + operation services, transaction, routing fixtures/tests. | Dedicated creation session |
| 9 | I06 | Require parent work order state and ownership/context for every operation command; decide nested route contract. | Operation service/routes/policies/tests. | Dedicated operation session |
| 10 | I01 | Define approver permission/separation-of-duties rule; validate the approver server-side and record the approval actor/event. | Product policy, request/service, permissions, migration/tests. | Blocked on policy decision |
| 11 | I08 | Document planner/executor role matrix and add authorization coverage for create, confirm, lifecycle, and output actions. | Seeder/policies/feature tests. | After policy confirmation |
| 12 | I02 | Hash exception approver, child product, and embedded material-lineage identifiers in resources and SPA types. | API resources, transformers, types, contract tests. | Small focused API session |
| 13 | I03 | Route restore through a transactional service method with permission and explicit audit event. | Controller/service/audit tests. | Small focused API session |
| 14 | I04 | Add work-order class/reason/authorization controls to the SPA create form and preserve API validation messages. | SPA schema/form/types/API tests. | After I01 policy is settled |
| 15 | I05 | Generate the idempotency key once per form submission and retain it for retry/replay. | SPA mutation state, output tests. | Small focused SPA session |
| 16 | I07 | Add operation actions, permissions, mutation states, and parent-context feedback to work-order detail. | SPA detail page/API hooks, responsive/error tests. | After I06 |
| 17 | P01 | Wrap the operations table responsively and add loading/empty/error states using opaque semantic surfaces. | SPA detail page and UI tests. | Small same-session polish |

## Re-audit gate

Before changing the registry back to complete, verify:

1. A standard order cannot start without a real, attributable material issue, and every lot reference matches the issued quantity.
2. A machine assigned to one work order cannot be overwritten or cleared by another order's lifecycle command.
3. Operation quantities, parent lifecycle, skip logging, and routing generation are covered by feature tests.
4. Receipt fallback responses expose the durable manual-required state and retries preserve idempotency.
5. API resources contain no raw internal IDs in the audited work-order/lineage payloads.
6. SPA create and detail flows cover class/exception data, operation actions, responsive layout, and blocked/error states.
7. The frontend test command runs successfully in a writable dependency environment.

---

## Re-audit action plan — 2026-09-01 (M051, session 3)

Ordered by value-per-risk. Tags per the audit gate: **Scope** (small / medium /
large) and **Session recommendation** (`same-session-ok` / `separate-recommended`).

The gate's containment rule is applied literally: a *missing guard refusing
impossible input*, or a *constraint closing a race without changing correct
behaviour*, is judged on containment and can land in-session. **Changing a
recorded production quantity, a scrap figure, a mold-life threshold, or who may
authorise, is not** — those are gated regardless of how small the diff looks.

| # | Item | Finding | Scope | Session recommendation |
|---|---|---|---|---|
| 1 | Replace the raw `items` PK in the insufficient-stock message with the item **code** | I06 | small | `same-session-ok` |
| 2 | `qty`/`scrap` on `/operations/{op}/output` → `integer` (piece counts) + reject values that would overflow `numeric(15,4)` | B01 | small | `same-session-ok` |
| 3 | Give `skipOperation()` the `assertStatus()` guard its 7 sibling commands have — refuse a **Completed** (and no-op `Skipped`) source | B03 | small | `same-session-ok` |
| 4 | Reject negative `good_count`/`reject_count` in `WorkOrderOutputService::record()` itself, not only in the FormRequest | §3 unrun probe | small | `same-session-ok` |
| 5 | Run the §3 "NOT executed" probe suite; convert `[code-read]` → measured | all | medium | `same-session-ok` |
| 6 | Re-check mold status **during** output recording, not only at `start()` | §3 unrun probe | small | `separate-recommended` — touches mold-life enforcement |
| 7 | OEE availability from recorded shifts + holidays instead of a weekday count | I04 | medium | `separate-recommended` |
| 8 | Attribute downtime to the window it *occupies*, not the one it starts in | I05 | medium | `separate-recommended` |
| 9 | Reconcile `WoOperationService::recordOutput()` through `WorkOrderOutputService::record()` | B02 | large | `separate-recommended` |
| 10 | Build the per-operation in-process QC gate (`qc_required`) | M01 | large | `separate-recommended` — blocked on Q2 |
| 11 | Refuse `complete()` with zero output | I01 | small | `separate-recommended` — blocked on Q3 |
| 12 | Reconcile issued material against BOM at `complete()` | I02 | large | `separate-recommended` — cross-module |
| 13 | Account for issued material when a paused WO is cancelled | I03 | medium | `separate-recommended` — blocked on Q4, cross-module |
| 14 | Operation overrun ceiling vs `qty_planned` | B02 (prior B07) | medium | `separate-recommended` — blocked on Q5 |
| 15 | Remove `status` + recorded quantities from `WorkOrder::$fillable` | I07 | medium | `separate-recommended` |
| 16 | Observer + PostgreSQL `P0001` trigger making recorded output immutable | M03 | medium | `separate-recommended` |
| 17 | Seed `work_order` / `production_batch` sequence rows | M02 | small | **do not fix here** — shared-service scope |
| 18 | OEE `report()` trend query count | P01 | medium | `separate-recommended` |

### Why the split falls where it does

Items 1–5 are the whole `same-session-ok` set, and every one of them either
refuses input that is already impossible through the intended UI or corrects a
message. None changes a recorded quantity, a scrap figure, a threshold, or an
authorisation. Specifically:

- **1** changes only the text of an error string; the 422 and the rollback are unchanged.
- **2** narrows a quantity field from `numeric` to `integer`. Piece counts are
  integral everywhere else in this module — the canonical path already uses
  `integer|min:0` (`RecordOutputRequest.php:28-34`) — so this makes the outlier
  match the rule and converts two 500s into 422s. It cannot alter any value that
  is currently recorded *correctly*; it only refuses ones that currently crash or
  silently round. Worth stating the one risk: any client legitimately sending a
  fractional quantity (a continuous UoM, e.g. kilograms) would start failing.
  `qty_planned` is seeded from `work_orders.quantity_target`, which is
  `numeric(10,0)` and validated `integer|min:1`, so fractional operation output
  has no legitimate source — but confirm before landing.
- **3** adds a guard that every sibling command already has. The docblock's "any
  status" intent is preserved for `Pending`/`Setup`/`InProgress`/`Paused`; only
  `Completed` (destructive) and `Skipped` (duplicate log row) are refused.
- **4** is defence-in-depth behind an existing FormRequest guard, on a path
  reachable today only by another service.

Items 6–18 are gated, and the reasons are not interchangeable:

- **6, 11, 14** each change *when production may proceed or be declared done*.
  Item 6 in particular decides whether an in-flight run stops when its mold
  crosses its rated life — a mold-life enforcement decision, explicitly on the
  not-contained list even though the diff is a few lines.
- **7, 8** change reported OEE figures. Availability currently reads `null` on a
  weekend run and a hard `0.0` when downtime is over-charged; fixing either moves
  numbers a human may already be reporting, so it needs a before/after comparison
  on real data rather than an in-session edit.
- **9** is the largest and most valuable structural item, and it is genuinely
  hard: `wo_operations.qty_completed` and `work_orders.quantity_produced` have
  been diverging in any existing installation, so reconciling the write paths
  also raises a migration question about the rows already recorded. That is not
  an in-session change.
- **10** requires the sampling-regime decision (Q2). The brief is explicit that
  designing one is not the auditor's call.
- **15** is behaviour-preserving on the service side but **breaks existing
  fixtures** that mass-assign `status` — at minimum
  `WorkOrderOutputIdempotencyKeyTest.php:59-71`, measured as passing today. Per
  the gate that is a judgement between the payroll precedent (revert, leave an
  `AUDIT NOTE`) and the quality precedent (keep the guard, leave fixtures red).
  Here the red fixtures would be *this module's own* and they mass-assign a state
  the service reaches legitimately, so the payroll shape applies — which is
  exactly why it should not be attempted incidentally.
- **17** is `Common`. Seeding the rows would narrow the race window without
  closing it, and the real fix (upsert / `ON CONFLICT`) belongs to
  `DocumentSequenceService`. Reported, not touched.

### Gate outcome

Majority of the plan is `separate-recommended`, so the module does **not** clear
Step 6's "majority same-session-ok + small scope → fix now" test. The brief
permits fixing only the genuinely contained items from a mostly-gated plan
provided the split is stated and justified — that is the intent here: land 1–4
behind the probe results from 5, and hand off 6–18 as **📋 Plan Ready** with Q1–Q5
answered first.
