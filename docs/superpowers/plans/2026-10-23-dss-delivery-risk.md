# DSS R4 — Delivery Risk and Priority

## Status

Source review complete. One bounded D07 enrichment is implemented in the workspace. Priority ranking and cross-module delivery-risk claims remain blocked until the evidence and policy gates below pass.

## Current evidence map

| Decision | Verified source path | Current limit | Safe next step |
|---|---|---|---|
| D03 customer orders at risk | `SalesOrderItem` delivery dates; `SalesOrder` planning/work-order relations; `WorkOrder` order/line links; MRP line diagnostics | No order-level detector or verified blocker-clearance contract | Map one real order-line chain; keep missing links explicitly unavailable |
| D07 production intervention | Active work order, `planned_end`, target/good quantities; linked order and line | Detector sees deadline and remaining production quantity, not proof of all downstream fulfillment | Keep the source deadline and verifier narrow; add no cause claim |
| D08 dispatch priority | Delivery, delivery items, sales order, vehicle, assignment, schedule and dispatch fields | Capacity comparison and customer-priority proof not established; chain age uses `updated_at` | Readiness evidence only until weight/capacity and dispatch outcome are verified |
| D25 approval bottlenecks | Current pending `approval_records`, role, `created_at`, configured `approvals.reminder_hours` | V2 separates queue and role, applies each registered queue's source permissions, requires a source row, and excludes row-scoped queues. Custom queues outside `ApprovalTypeRegistry` remain uncovered. No DSS verifier. | Keep partial and shadow. Do not expose per-document or approver details; decide how custom queues should be safely represented |
| D26 chain bottlenecks | Existing 15 chain rule families, configured visibility and role ownership | Most ages use `updated_at`; no per-source verifier; references capped and generic | Select one family; document source clock, view permission, destination, and exact clear condition |

Source pointers: `api/app/Modules/CRM/Models/SalesOrderItem.php:24-36`; `api/app/Modules/CRM/Models/SalesOrder.php:84-106`; `api/app/Modules/Production/Models/WorkOrder.php:64-112`; `api/app/Modules/MRP/Services/MrpEngineService.php:175-220`; `api/app/Modules/DSS/Detectors/WorkOrderInterventionDetector.php`; `api/app/Modules/DSS/Verifiers/WorkOrderInterventionVerifier.php`; `api/app/Modules/SupplyChain/Models/Delivery.php:28-60`; `api/app/Modules/SupplyChain/Models/DeliveryItem.php:20-44`; `api/app/Modules/SupplyChain/Services/DeliveryService.php:749-777`; `api/app/Modules/DSS/Detectors/ApprovalBottleneckDetector.php`; `api/app/Common/Services/ApprovalService.php:55-120`; `api/app/Common/Services/ChainBottleneckService.php:115-141,189-244,788-813`.

## Delivered D07 slice

- Adds a Sales Order reference only when the work order's linked order and line agree.
- Projects the order link only to users with `crm.sales_orders.view`.
- Shows remaining good quantity with the product unit when available.
- Retains `planned_end` as the sole due date. No blocker cause or fulfilled customer commitment is inferred.
- Parent order link is used because no line-level route exists.

## Delivered D25 safety slice

- Groups registered approval types separately by current step role and applies the source type's view/approval permissions.
- Requires the underlying source row to exist, matching the approval board's raw-table lookup.
- Excludes Leave, Purchase Requests, Purchase Orders, and Loans because the detector cannot reproduce their user-specific row scopes.
- Uses the current step's `created_at` plus configured reminder hours as the business due time.
- Names excluded queues in readable evidence and labels the coverage partial. Custom Approval Board queues that do not write `approval_records` remain outside this detector.
- Changes the decision identity to v2. Existing v1 role-only rows stop matching emitted keys; a successful run hides them from the current queue, and normal expiry clears them from active storage. Run a complete scan before enabling advisory.

## Implementation gates

1. **D03 chain proof:** use confirmed foreign keys and real source records. Separate observed contributing factors from proven causes. Resolve only against the same source condition.
2. **D08 dispatch feasibility:** verify delivery-item weight basis, vehicle capacity unit, readiness conditions, customer commitment, and actual dispatch state. Until then, do not rank by consolidation or capacity.
3. **D25 remaining coverage:** current workflow creates new approval rows with `created_at` at submission and supersedes pending rows on resubmission, so the current-step clock is usable. The v2 slice applies registry permissions and omits row-scoped source types. Keep it partial and shadow until custom queues have safe source scope and shadow validation passes. Keep document and approver identity out of the aggregate. Do not add automatic approval actions.
4. **D26 source family:** before changing a family, record exact source query, age field, owner permission, route, role scope, and verified-clear condition. Do not use `updated_at` as business wait time without proving its write semantics.
5. **Priority policy:** no weighted ranking until business owners confirm components, units, tie-breaking, and thresholds. Keep unknown money unknown; normalize impact within comparable units; record policy version and old/new ordering in shadow.
6. **Scenario proof:** exercise one linked order, production work, a real blocker, and delivery commitment. Act through the source workflow and verify only the source condition that actually changed.

## Required checks and exit

- Focused detector, source-scope, and presentation tests cover matched/mismatched order links, unit display, permission filtering, missing source fields, and approval aggregates for hidden module rows. A dedicated local database scenario exercises D07 through the real runner.
- Browser review covers linked-order permission, narrow-screen detail, and unavailable evidence.
- Shadow evaluation records source age, missing links, false-positive review, and priority movement before any ordering changes.

Exit only when a user can inspect a proven commitment chain, distinguish evidence from inferred cause, act through the right source module, and see source-verified resolution. No detector promotion is part of this plan.

## Limits

Code inspection only. Production data, live roles, deployed configuration, and business-owner policy were not inspected. Tests were not run. D03/D08 and generic D26 remain incomplete.
