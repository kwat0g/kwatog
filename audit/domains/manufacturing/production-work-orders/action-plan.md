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
