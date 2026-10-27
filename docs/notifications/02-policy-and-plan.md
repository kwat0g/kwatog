# Notification policy and implementation plan — approved baseline

**Status:** approved by the user on 2026-10-25. On 2026-10-26, the budget and transfer approval signal was split into two source-specific list filters; the approved maker-checker recipient rule did not change. Any unverified scope or status remains an explicit implementation gate.

**Evidence base:** `00-discovery.md` and `01-inventory.md`. Current source behavior remains the baseline until an approved, separately reviewable PR changes it. Proposed rules do not assert that a role currently has a permission or scope; backend policy and list scope must verify each recipient.

## A. Proposed notification catalog and recipient rules

### Shared delivery rules

| Building block | Proposed default |
| --- | --- |
| Current approval | Notify only active user(s) assigned to the current approval step and active delegates; require the same view permission and source scope used by the approval queue; exclude submitter/actor. Never notify future approval steps. |
| Outcome | Notify active requester/submitter only when the decision changes their next action; verify record visibility before linking. |
| Owner / assignee | Notify the record owner, explicitly assigned person, or current responsible queue member only; require active account and record-view access. |
| Permission + scope | Use a specific view/action permission plus the same department/site/customer/vendor/source scope as the destination list. A role slug alone is insufficient. |
| External party | Notify active portal contacts whose customer/vendor ID equals the record's bound party; verify the portal policy can open that exact record. Never cross customer/vendor boundaries. |
| Escalation | Notify configured current supervisor only after the approved threshold; retain current assignee; stop on action, reassignment, cancellation, or resolution. |
| Exclusions | Exclude actor for action-required notices, inactive/deleted users, users without subject access, future-step approvers, and unrelated role holders. Outcome notices may go to actor only if actor is requester and outcome matters to them. |
| Empty audience | Log an auditable no-recipient result; do not fan out to admins or a whole role. Any emergency fallback needs explicit event-specific policy and view access. |
| Channels | In-app is default for personal/action-required messages. Existing email remains opt-in. Payroll, HR, finance, and discipline details stay minimal; do not add email by default. Portal notices remain within portal account. |
| Dedupe / grouping | Stable key `(event, subject, recipient, current attempt/step)`; update one unread item for repeat state; group repetitive noncritical reminders by recipient and filtered-list link. No broad flood. |
| Resolve / expiry | Resolve when underlying action disappears, subject is closed/cancelled, ownership changes, or user loses access. Keep resolved entry read-only with short explanation. Proposed read retention: existing 90 days; unresolved critical/action items do not auto-expire. Review required. |
| Message / link | Template text, display reference, actor when useful, route key + hash ID; link exact record. Aggregates link to matching filtered list. Avoid raw enum, numeric database ID, class name, null, and sensitive amounts in general channels. |
| Realtime | Per-user private channel; push ID/type only, then fetch details through authenticated API. Reconnect refetches unread rows/counts. Never send record details on role-wide channel. |

### Event-family rules

Exact call-site/type expressions live in the 129-row register in `01-inventory.md`; current catalogue has 79 stored values and 96 distinct source expressions, including dynamic expressions and `$type` values. The table groups every discovered event expression by proposed rule family. Dynamic values remain subject to source-level verification before implementation.

| Event family; discovered expressions | Severity / category | Proposed recipients and exclusions | Channels, copy, link, grouping, lifecycle |
| --- | --- | --- | --- |
| `approval.action_required`, `approval_reminder`, `approval_escalation`, `approval.completed`, `approval.rejected`, dynamic approval `$type`; PO/PR/budget/payroll/stock/adjustment approval events | Action-required; Approvals | Current active step + active delegates with subject-view scope; exclude actor/submitter. Outcomes to active submitter only. Escalation only to configured supervisor after threshold. Payroll stays in payroll-specific permission scope. | In-app required; existing email opt-in only. “{record} needs your approval” / “{record} was approved/rejected.” Link exact record or approval section. Dedupe per attempt/step/recipient. Resolve on decision, reassignment, cancellation, or access loss. |
| `leave.*`, `attendance.ot_*`, `attendance.missing_time_out`, `loans.*`, `training.expiry`, `recruitment.*`, `hr.onboarding.stale`, `chain.separation_initiated`, profile/account lifecycle | Action-required or warning; HR / Attendance | Training expiry: affected active employee plus configured active department head(s)/HR officer(s) who hold record-view permissions and pass employee scope. Other HR/attendance events use requester for outcomes/own exceptions and current approver, assignee, or department-scoped HR reviewer for action. Exclude unrelated HR staff and other employees. | In-app required for action; optional email only if current per-type preference permits. “Leave request needs your approval”; “Training expires on {date}.” Link to the exact training record; approved self-service and HR routes enforce owner or department scope. Dedupe by request/training/action window. Resolve on decision, correction, expiry, or reassignment. |
| `chain.payslip_ready`, payroll period/adjustment approvals, payslip job/email | Action-required / info; Payroll | Payslip-ready: active user linked to that employee's publishable payroll row and holding `payroll.view`. Period approval: active current approver or delegate with period visibility; exclude actor and permission-bypass administrators. Adjustment approval: active user who can approve or reject this record under `ApprovalDelegation::canActForPermission()`, has adjustment visibility, and is not the requester or a permission-bypass administrator. Decision: active requester who retains adjustment visibility. No broad HR/Finance fanout or fallback recipient. | In-app payslip-ready notice contains no amount and links to that employee's payslip. Approval/decision notice links to exact period or adjustment. Payslip email remains employee-addressed; failure notice only to active linked account with `payroll.view`. Dedupe per employee/payroll row or adjustment/period event; suppress delivery when access is lost. No eligible checker means no notification until an authorized operational approver is configured. |
| `invoice.*`, `bill_payment.posted`, `ar.dunning.escalation`, budget and finance payment events | Action-required / warning; Finance | Current invoice/bill owner, payment reviewer, assigned AR/AP officer, or current finance approver with record scope; customer contact only for their account. Sensitive finance content restricted to finance view permission. | In-app required for action; email opt-in as today, no new default. “Invoice {number} has an unpaid balance” or “Payment needs review.” Exact invoice/bill/payment route. Dedupe per subject/state/recipient; resolve on payment, void, or assignment change. Amount display only where finance policy approves. |
| `inventory.*`, `auto_po_pending`, `purchase_order.*`, `purchasing.*`, `supplier_listing_submitted`, RFQ lifecycle, `supplier.dispatch_action_required` | Action-required / warning; Inventory / Purchasing | Internal `send`/`sendInApp`: current approver, assigned buyer/warehouse/receiver, or site-scoped permission with source access. `purchase_order.*` portal call sites: active contact bound to the record vendor and authorized for that PO. Supplier-named internal event types do not select portal users. | Internal or portal in-app according to delivery method; no new email default. “GRN {number} needs inspection” / “Quote for {RFQ} is due.” Link exact record. Dedupe by record+step; resolve on approval, receipt, rejection, or reassignment. |
| `production.*`, `mrp_run_completed`, production/QC handoffs | Action-required / warning; Production / MRP | Assigned crew/owner, current reviewer, or PPC operator with plan/work-order visibility; handoff recipient only for next active step. Exclude stale crew, actor, and other production areas. | In-app; scheduled reminders grouped per user/shift. “Work order {number} is blocked by material” / “Output confirmation is due.” Link exact work order/plan. Dedupe by state/window; resolve on release, completion, unpause, or handoff. |
| `chain.pr_*` | Purchasing handoff / action-required | PR requester/owner and assigned buyer who must convert or correct the request, with `PurchaseRequestAccessPolicy` visibility; event is from Purchasing listeners despite `chain.` prefix. | Internal in-app; existing email preference only. Link exact PR. Resolve on conversion, correction, cancellation, or reassignment. |
| `quality.*`, `auto_ncr_created`, `ncr.*`, `8d.sla`, `chain.incoming_qc_required`, `chain.in_process_qc_required`, `chain.outgoing_qc_*` | Action-required / warning / critical by approved SLA; Quality | Assigned inspector/NCR owner, current quality reviewer, scoped quality manager for configured escalation. Rejected lot is limited to quality/production roles with lot scope. Customer complaint updates only to bound customer contact when policy requires. | In-app; critical SLA toast only after confirmation. “NCR {number} is due {date}.” Link NCR/inspection/lot. Dedupe by NCR/inspection/SLA stage; resolve on closure, disposition, or reassignment. |
| `maintenance.*`, machine breakdown, work-order assignment/repair/parts, schedule reminders | Action-required / warning / critical for safety stop; Maintenance | Assigned technician, current maintenance dispatcher/supervisor for same site, requester for decision. Escalate only per approved downtime/safety threshold. | In-app; critical toast only for active machine-down/safety event. “Machine {name} is down” / “Work order {number} is unassigned.” Link maintenance record. Dedupe by machine/work order + state; resolve on repair, assignment, or closure. |
| `sales_order.*`, `delivery.*`, `delivery_schedule.*`, and other `sendPortalInApp` call sites | Action-required / info; Customer / Supplier / Supply Chain | Active portal contacts bound to the record's customer/vendor and permitted to open that exact record. | Portal in-app only. “Order {number} needs your confirmation”; “Delivery {number} needs acknowledgment.” Link exact portal record. Dedupe by subject/status transition; resolve on response, receipt, cancellation, or access loss. |
| `customer.*`, `portal.order_awaiting_review`, `supplier.*`, `return.*`, `chain.so_confirmed`, `chain.delivery_*`, `invoice.finalized` when called through `send` / `sendInApp` | Action-required / info; CRM / Supplier / Supply Chain | Internal requester, owner, assignee, or current reviewer selected by the caller, with exact source scope and permission. Names in the event type do not make recipients external. | Internal in-app; existing email preference only. “Order {number} needs review”; “Delivery {number} needs acknowledgment.” Link exact internal record. Dedupe by subject/status transition; resolve on response, receipt, cancellation, or access loss. |
| `queue.long_wait`, `email.delivery_failed`, `permission.override`, system backup/chain recovery, critical alert mail | Warning / critical; System | Queue/backup operator with relevant admin permission; failed-mail owner who can retry/view source; affected user and authorized change actor for permission changes; alert audience must have alert and subject access. No generic administrator fallback. | In-app for actionable system issue; existing critical email remains config-based pending audience review. Copy excludes secrets and record payloads. Link authorized system job/alert. Dedupe per failed job/alert; resolve on recovery or dismissal. |
| `dss.recommendation` | Warning / action-required only if detector is promoted from shadow | Recommendation owner plus permission/scope-qualified audience explicitly approved for that detector. No broad executive/role notice by default. | Disabled until active publisher, valid detail route, and recipient rule exist. Existing sender is unused. |

### Exact event-expression coverage crosswalk

This crosswalk includes every distinct `NotificationService` event-type expression from the 129 call sites (96 source expressions). An expression can resolve to multiple runtime type strings; dynamic expressions and `$type` rows stay open until each caller state mapping is approved. Delivery method determines audience: `sendPortalInApp` targets active contacts bound to the record's customer/vendor; `send` and `sendInApp` target internal `User` records. Event prefixes such as `customer.`, `supplier.`, and `portal.` do not override that distinction. Where one expression uses both kinds of method, split policy by call site. Exact recipient expressions are in `01-inventory.md`.

| Event-type expression | Proposed rule family | Current delivery method(s) | Source sites |
| --- | --- | --- | --- |
| `$target === Status::Approved ? 'approval.completed' : 'approval.rejected'` | Caller-specific dynamic outcome; resolve exact runtime type/state at cited sites before approval | send | `api/app/Modules/Inventory/Services/ItemCostReviewService.php:95` |
| `$type` | Caller-specific dynamic outcome; resolve exact runtime type/state at cited sites before approval | send, sendInApp | `api/app/Modules/CRM/Services/PricingReviewService.php:196`, `api/app/Common/Services/CustomApprovalEscalationService.php:97`, `api/app/Common/Services/ApprovalService.php:392`, `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:3305`, `api/app/Modules/Purchasing/Listeners/DeliverRfqLifecycleNotification.php:81`, `api/app/Modules/Accounting/Services/BudgetTransferService.php:194`, `api/app/Modules/Accounting/Services/BudgetService.php:203`, `api/app/Modules/Payroll/Services/PayrollAdjustmentService.php:176`, `api/app/Modules/Quality/Services/EffectivenessService.php:283`, `api/app/Modules/Attendance/Listeners/NotifyOnOvertimeDecided.php:29`, `api/app/Modules/Inventory/Services/StockAdjustmentService.php:379`, `api/app/Modules/Loans/Listeners/NotifyOnLoanDecided.php:39` |
| `'8d.sla'` | Assigned inspector/NCR owner/current quality reviewer with plant/lot scope | send | `api/app/Modules/CRM/Services/Complaint8dEscalationService.php:271` |
| `'approval.action_required'` | Current approval step / current submitter outcome; source scope required | send | `api/app/Common/Services/ApprovalService.php:367`, `api/app/Modules/Accounting/Services/InvoiceService.php:171`, `api/app/Modules/Accounting/Services/BudgetTransferService.php:87`, `api/app/Modules/Accounting/Services/BudgetService.php:135`, `api/app/Modules/Payroll/Services/PayrollPeriodService.php:1011`, `api/app/Modules/Payroll/Services/PayrollAdjustmentService.php:78`, `api/app/Modules/Inventory/Services/StockAdjustmentService.php:215` |
| `'approval.completed'` | Current approval step / current submitter outcome; source scope required | send | `api/app/Modules/Accounting/Services/InvoiceService.php:331` |
| `'approval_escalation'` | Current approval step / current submitter outcome; source scope required | send | `api/app/Common/Services/ApprovalEscalationService.php:137`, `api/app/Common/Services/ApprovalEscalationService.php:313` |
| `'approval_reminder'` | Current approval step / current submitter outcome; source scope required | send | `api/app/Common/Services/ApprovalEscalationService.php:86` |
| `'ar.dunning.escalation'` | Assigned finance owner/current reviewer with source scope; external customer/vendor event remains party-bound | send | `api/app/Modules/Accounting/Services/ArDunningService.php:192` |
| `'attendance.missing_time_out'` | Employee, current approver, assignee, or scoped HR; sensitive HR/payroll rules apply | send | `api/app/Console/Commands/NotifyMissingTimeOut.php:63`, `api/app/Console/Commands/NotifyMissingTimeOut.php:98` |
| `'attendance.ot_submitted'` | Employee, current approver, assignee, or scoped HR; sensitive HR/payroll rules apply | send | `api/app/Modules/Attendance/Listeners/NotifyOnOvertimeSubmitted.php:38` |
| `'auto_ncr_created'` | Assigned inspector/NCR owner/current quality reviewer with plant/lot scope | send | `api/app/Modules/Quality/Services/NcrService.php:407` |
| `'auto_po_pending'` | Current approval step / current submitter outcome; source scope required | send | `api/app/Modules/Purchasing/Services/AutoPurchaseOrderService.php:209` |
| `'bill_payment.posted'` | Assigned finance owner/current reviewer with source scope; external customer/vendor event remains party-bound | send | `api/app/Modules/Accounting/Services/BillService.php:1070` |
| `'chain.delivery_attempt_reported'` | Internal warehouse/dispatch owner or assigned depot reviewer with delivery source scope | sendInApp | `api/app/Modules/SupplyChain/Services/DeliveryAttemptOutcomeService.php:562` |
| `'chain.delivery_confirmed'` | Internal finance owner/reviewer with invoice and delivery scope; no customer portal audience from this call | send | `api/app/Modules/Accounting/Listeners/NotifyFinanceOnDeliveryConfirmed.php:47` |
| `'chain.delivery_drafted'` | Internal quality or fulfillment owner with delivery source scope; no portal audience from this call | send | `api/app/Modules/Quality/Listeners/CreateDeliveryDraftOnQcPass.php:100` |
| `'chain.in_process_qc_required'` | Assigned inspector/NCR owner/current quality reviewer with plant/lot scope | send | `api/app/Modules/Quality/Listeners/TriggerInProcessQC.php:154` |
| `'chain.incoming_qc_required'` | Assigned inspector/NCR owner/current quality reviewer with plant/lot scope | send | `api/app/Modules/Quality/Listeners/TriggerIncomingQC.php:174` |
| `'chain.outgoing_qc_missing'` | Assigned inspector/NCR owner/current quality reviewer with plant/lot scope | send | `api/app/Console/Commands/SweepMissingOutgoingQc.php:201` |
| `'chain.outgoing_qc_required'` | Assigned inspector/NCR owner/current quality reviewer with plant/lot scope | send | `api/app/Modules/Quality/Listeners/TriggerOutgoingQC.php:159` |
| `'chain.payslip_ready'` | Active user linked to the employee on that publishable payroll row with `payroll.view`; that employee only | send | `api/app/Modules/Payroll/Listeners/NotifyEmployeesOnPayrollFinalized.php:71` |
| `'chain.po_approved'` | Next chain owner/current handoff recipient; exact source-list scope | send | `api/app/Modules/Purchasing/Listeners/NotifyOnPurchaseOrderApproved.php:33` |
| `'chain.pr_approved'` | Purchase-request owner/requester or purchasing handoff recipient selected by the listener, with PR visibility; do not infer PPC from the event name | send | `api/app/Modules/Purchasing/Listeners/NotifyOnPurchaseRequestApproved.php:33` |
| `'chain.pr_auto_convert_skipped'` | Purchase-request owner/requester or assigned buyer who must perform manual conversion, with PR visibility | send | `api/app/Modules/Purchasing/Listeners/ConsolidatePurchaseOrders.php:259` |
| `'chain.separation_initiated'` | Employee, current approver, assignee, or scoped HR; sensitive HR/payroll rules apply | send | `api/app/Modules/HR/Listeners/NotifyOnSeparationInitiated.php:34` |
| `'chain.so_confirmed'` | Internal CRM order owner and any explicitly selected production-planning recipient with sales-order scope; no customer portal audience from this call | send | `api/app/Modules/CRM/Listeners/NotifyOnSalesOrderConfirmed.php:40` |
| `'customer.rma_created'` | Internal return-case owner and quality reviewer with case visibility; no customer portal audience from this call | send | `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:658` |
| `'customer.so_responded'` | Internal sales-order owner/current reviewer with order visibility; no customer portal audience from this call | send | `api/app/Modules/CRM/Services/SalesOrderResponseService.php:508` |
| `'delivery.'.$next->value` | Active portal contact bound to the record customer/vendor; record-level portal access required | sendPortalInApp | `api/app/Modules/SupplyChain/Services/DeliveryService.php:1239` |
| `'delivery_schedule.'.$schedule->status->value` | Active portal contact bound to the record customer/vendor; record-level portal access required | sendPortalInApp | `api/app/Modules/B2B/Services/DeliveryScheduleReviewService.php:109` |
| `'email.delivery_failed'` | Relevant system operator or affected user; precise permission and subject access | sendInApp | `api/app/Common/Services/EmailDeliveryFailureNotifier.php:37` |
| `'hr.onboarding.stale'` | Employee, current approver, assignee, or scoped HR; sensitive HR/payroll rules apply | sendInApp | `api/app/Modules/HR/Services/OnboardingService.php:231` |
| `'inventory.grn_received'` | Current buyer/warehouse/approver with site/source scope; vendor only if explicitly portal-bound | send | `api/app/Modules/Inventory/Listeners/NotifyOnGrnReceived.php:33` |
| `'inventory.low_stock'` | Current buyer/warehouse/approver with site/source scope; vendor only if explicitly portal-bound | send | `api/app/Modules/Inventory/Listeners/NotifyOnLowStockPrCreated.php:36` |
| `'inventory.replenishment_blocked'` | Current buyer/warehouse/approver with site/source scope; vendor only if explicitly portal-bound | sendInApp | `api/app/Modules/Inventory/Services/AutoReplenishmentService.php:184` |
| `'invoice.auto_failed'` | Assigned finance owner/current reviewer with source scope; external customer/vendor event remains party-bound | send | `api/app/Modules/SupplyChain/Services/DeliveryService.php:2195` |
| `'invoice.finalized'` | Active portal contact bound to the record customer/vendor; record-level portal access required | sendPortalInApp | `api/app/Modules/Accounting/Listeners/EmailCustomerOnInvoiceFinalized.php:28` |
| `'invoice.payment_reviewed'` | Active portal contact bound to the record customer/vendor; record-level portal access required | sendPortalInApp | `api/app/Modules/Accounting/Services/InvoicePaymentSubmissionService.php:230` |
| `'invoice.payment_submitted'` | Assigned finance owner/current reviewer with source scope; external customer/vendor event remains party-bound | sendInApp | `api/app/Modules/Accounting/Services/InvoicePaymentSubmissionService.php:100` |
| `'leave.approved'` | Employee, current approver, assignee, or scoped HR; sensitive HR/payroll rules apply | send | `api/app/Modules/Leave/Listeners/NotifyOnLeaveApproved.php:26` |
| `'leave.pending_hr'` | Employee, current approver, assignee, or scoped HR; sensitive HR/payroll rules apply | send | `api/app/Modules/Leave/Listeners/NotifyOnLeavePendingHR.php:32` |
| `'leave.rejected'` | Employee, current approver, assignee, or scoped HR; sensitive HR/payroll rules apply | send | `api/app/Modules/Leave/Listeners/NotifyOnLeaveRejected.php:26` |
| `'leave.submitted'` | Employee, current approver, assignee, or scoped HR; sensitive HR/payroll rules apply | send | `api/app/Modules/Leave/Listeners/NotifyOnLeaveSubmitted.php:40` |
| `'loans.submitted'` | Employee, current approver, assignee, or scoped HR; sensitive HR/payroll rules apply | send | `api/app/Modules/Loans/Listeners/NotifyOnLoanSubmitted.php:48` |
| `'maintenance.assigned'` | Assigned technician/site dispatcher/current supervisor; escalation by approved SLA | send | `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php:348` |
| `'maintenance.assignment_escalated'` | Assigned technician/site dispatcher/current supervisor; escalation by approved SLA | send | `api/app/Console/Commands/CheckMaintenanceAssignment.php:48` |
| `'maintenance.assignment_needed'` | Assigned technician/site dispatcher/current supervisor; escalation by approved SLA | send | `api/app/Modules/Maintenance/Listeners/NotifyMaintenanceWorkOrder.php:30` |
| `'maintenance.breakdown'` | Assigned technician/site dispatcher/current supervisor; escalation by approved SLA | send | `api/app/Modules/Production/Listeners/NotifyOnMachineBreakdown.php:39` |
| `'maintenance.parts_handed_over'` | Assigned technician/site dispatcher/current supervisor; escalation by approved SLA | send | `api/app/Modules/Maintenance/Services/MaintenancePartRequestService.php:127` |
| `'maintenance.parts_requested'` | Assigned technician/site dispatcher/current supervisor; escalation by approved SLA | send | `api/app/Modules/Maintenance/Services/MaintenancePartRequestService.php:70` |
| `'maintenance.repair_rejected'` | Assigned technician/site dispatcher/current supervisor; escalation by approved SLA | send | `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php:670` |
| `'maintenance.repair_submitted'` | Assigned technician/site dispatcher/current supervisor; escalation by approved SLA | send | `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php:624` |
| `'maintenance.repeat_breakdown'` | Assigned technician/site dispatcher/current supervisor; escalation by approved SLA | send | `api/app/Console/Commands/CheckMaintenanceRepeatBreakdowns.php:36` |
| `'maintenance.shift_handover'` | Assigned technician/site dispatcher/current supervisor; escalation by approved SLA | send | `api/app/Console/Commands/CheckMaintenanceAssignment.php:71` |
| `'mrp_run_completed'` | Assigned owner/crew/current reviewer; PPC only for visible plan | send, sendInApp | `api/app/Console/Commands/RunDailyMrp.php:50`, `api/app/Modules/SupplyChain/Services/DeliveryService.php:471`, `api/app/Modules/MRP/Services/MrpAutomationService.php:78` |
| `'ncr.escalation'` | Assigned inspector/NCR owner/current quality reviewer with plant/lot scope | send | `api/app/Modules/Quality/Services/NcrEscalationService.php:180` |
| `'ncr.recurrence'` | Assigned inspector/NCR owner/current quality reviewer with plant/lot scope | send | `api/app/Modules/Quality/Services/NcrRecurrenceDetector.php:201` |
| `'ncr.return_to_supplier'` | Assigned inspector/NCR owner/current quality reviewer with plant/lot scope | send | `api/app/Modules/Quality/Services/NcrService.php:1280` |
| `'permission.override'` | Relevant system operator or affected user; precise permission and subject access | send | `api/app/Modules/Admin/Services/UserPermissionOverrideService.php:167`, `api/app/Modules/Admin/Services/UserPermissionOverrideService.php:225`, `api/app/Modules/Admin/Services/UserPermissionOverrideService.php:288` |
| `'portal.order_awaiting_review'` | Internal CRM owner/reviewer who must review the newly placed portal order; no customer portal audience from this call | send | `api/app/Modules/B2B/Services/CustomerPortalService.php:325` |
| `'pricing.negotiation'` | Split by channel: bound customer/vendor account for portal send; scoped internal owner/reviewer for internal send | sendInApp, sendPortalInApp | `api/app/Modules/CRM/Services/PriceNegotiationService.php:287`, `api/app/Modules/CRM/Services/PriceNegotiationService.php:289` |
| `'pricing.review_required'` | Assigned pricing reviewer/product or account owner; financial-change scope | sendInApp | `api/app/Modules/CRM/Services/ProductService.php:337`, `api/app/Modules/CRM/Services/ProductService.php:371` |
| `'production.assignment.review'` | Each caller retains its workflow audience. `AlertEngineService::checkProduction()` sends the Work Order notice to active PPC/Production Manager candidates and equipment-slot Maintenance Technicians only if they hold both `production.view` and `production.work_orders.view`; a separate Sales Officer notice links to the Sales Order. | sendInApp | `api/app/Console/Commands/NotifyUpcomingWorkOrders.php:49`, `api/app/Common/Services/AlertEngineService.php:486`, `api/app/Common/Services/AlertEngineService.php:492`, `api/app/Modules/Production/Services/WorkOrderAssignmentService.php:626` |
| `'production.crew.assigned'` | Assigned owner/crew/current reviewer; PPC only for visible plan | sendInApp | `api/app/Modules/Production/Services/WorkOrderAssignmentService.php:478` |
| `'production.crew.changed'` | Assigned owner/crew/current reviewer; PPC only for visible plan | sendInApp | `api/app/Modules/Production/Services/WorkOrderAssignmentService.php:484` |
| `'production.rework.'.$state` | Assigned owner/crew/current reviewer; PPC only for visible plan | sendInApp | `api/app/Modules/Production/Services/WorkOrderReworkService.php:167`, `api/app/Modules/Production/Services/WorkOrderReworkService.php:171` |
| `'production.schedule.reminder'` | Assigned owner/crew/current reviewer; PPC only for visible plan | sendInApp | `api/app/Console/Commands/NotifyUpcomingWorkOrders.php:71`, `api/app/Console/Commands/NotifyUpcomingWorkOrders.php:78` |
| `'production.wo_completed'` | Assigned owner/crew/current reviewer; PPC only for visible plan | send | `api/app/Modules/Production/Listeners/NotifyOnWorkOrderCompleted.php:33` |
| `'production.wo_paused'` | Assigned owner/crew/current reviewer; PPC only for visible plan | send | `api/app/Modules/Production/Listeners/NotifyOnWorkOrderPaused.php:55` |
| `'purchase_order.awaiting_approval'` | Active portal contact bound to the record customer/vendor; record-level portal access required | sendPortalInApp | `api/app/Modules/Purchasing/Services/SupplierResponseService.php:281` |
| `'purchase_order.published'` | Active portal contact bound to the record customer/vendor; record-level portal access required | sendPortalInApp | `api/app/Modules/B2B/Services/SupplierPortalDispatchGateway.php:40` |
| `'purchase_order.response_decided'` | Active portal contact bound to the record customer/vendor; record-level portal access required | sendPortalInApp | `api/app/Modules/Purchasing/Services/SupplierResponseService.php:500` |
| `'purchasing.po_pending_approval'` | Current approval step / current submitter outcome; source scope required | send | `api/app/Modules/Purchasing/Listeners/NotifyOnPurchaseOrderSubmitted.php:32` |
| `'purchasing.supplier_deterioration'` | Current buyer/warehouse/approver with site/source scope; vendor only if explicitly portal-bound | send | `api/app/Modules/Purchasing/Listeners/AlertOnSupplierDeterioration.php:61` |
| `'quality.inspection_awaiting_review'` | Assigned inspector/NCR owner/current quality reviewer with plant/lot scope | send | `api/app/Modules/Quality/Services/InspectionService.php:2392` |
| `'quality.inspection_failed'` | Assigned inspector/NCR owner/current quality reviewer with plant/lot scope | sendInApp | `api/app/Modules/Quality/Listeners/NotifyOnInspectionFailed.php:55` |
| `'quality.late_incoming'` | Assigned inspector/NCR owner/current quality reviewer with plant/lot scope | sendInApp | `api/app/Modules/Quality/Services/NcrService.php:245` |
| `'queue.long_wait'` | Relevant system operator or affected user; precise permission and subject access | sendInApp | `api/app/Common/Listeners/NotifySystemAdminsOnQueueLongWait.php:32` |
| `'recruitment.bottleneck'` | Employee, current approver, assignee, or scoped HR; sensitive HR/payroll rules apply | sendInApp | `api/app/Console/Commands/CheckRecruitmentBottlenecks.php:182` |
| `'recruitment.new_application'` | Employee, current approver, assignee, or scoped HR; sensitive HR/payroll rules apply | send | `api/app/Modules/HR/Services/RecruitmentService.php:717` |
| `'return.case_updated'` | Internal case owner/assignee and explicitly scoped dispatcher; all listed call sites use internal delivery | send, sendInApp | `api/app/Modules/ReturnManagement/Services/ReturnCaseService.php:269`, `api/app/Modules/ReturnManagement/Services/ReturnCaseService.php:617`, `api/app/Modules/ReturnManagement/Services/ReturnCaseService.php:658` |
| `'return.supplier_defect'` | Internal quality/return owner with supplier-defect case visibility; no supplier portal audience from this call | sendInApp | `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:844` |
| `'return.supplier_defect_approved'` | Active portal contact bound to the record customer/vendor; record-level portal access required | sendPortalInApp | `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:1875` |
| `'return.truck_return_received'` | Internal warehouse/return owner with truck-return record visibility; no external portal audience from this call | sendInApp | `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:328` |
| `'sales_order.'.$action` | Active portal contact bound to the record customer/vendor; record-level portal access required | sendPortalInApp | `api/app/Modules/CRM/Services/SalesOrderService.php:1118` |
| `'sales_order.cancellation_requested'` | Active portal contact bound to the record customer/vendor; record-level portal access required | sendPortalInApp | `api/app/Modules/CRM/Services/SalesOrderResponseService.php:185` |
| `'sales_order.delivery_decided'` | Active portal contact bound to the record customer/vendor; record-level portal access required | sendPortalInApp | `api/app/Modules/CRM/Services/SalesOrderResponseService.php:228` |
| `'sales_order.delivery_proposed'` | Active portal contact bound to the record customer/vendor; record-level portal access required | sendPortalInApp | `api/app/Modules/CRM/Services/SalesOrderResponseService.php:84` |
| `'sales_order.response_decided'` | Active portal contact bound to the record customer/vendor; record-level portal access required | sendPortalInApp | `api/app/Modules/CRM/Services/SalesOrderResponseService.php:536` |
| `'sales_order.submitted'` | Active portal contact bound to the record customer/vendor; record-level portal access required | sendPortalInApp | `api/app/Modules/B2B/Services/CustomerPortalService.php:302` |
| `'supplier.dispatch_action_required'` | Internal buyer/warehouse/approver with purchase-order and site/source scope | sendInApp | `api/app/Modules/Purchasing/Services/SupplierDispatchService.php:285` |
| `'supplier.po_responded'` | Internal buyer/PO owner who handles supplier response, with purchase-order visibility; no supplier portal audience from this call | send | `api/app/Modules/Purchasing/Services/SupplierResponseService.php:461` |
| `'supplier.schedule_cancelled'` | Internal buyer/logistics owner responsible for the affected schedule, with schedule visibility | send | `api/app/Modules/B2B/Services/SupplierDeliveryScheduleService.php:237` |
| `'supplier.shipment_late'` | Internal buyer/receiving owner for the affected PO, with source scope; no supplier portal audience from this call | sendInApp | `api/app/Modules/B2B/Services/SupplierShipmentService.php:273` |
| `'supplier_listing_submitted'` | Current buyer/warehouse/approver with site/source scope; vendor only if explicitly portal-bound | send | `api/app/Modules/Purchasing/Services/SupplierListingService.php:350` |
| `'training.expiry'` | Active account linked to this employee for self-service; configured active department head/HR recipients only when employee/training view permission and row scope allow access | send | `api/app/Modules/HR/Services/TrainingExpiryService.php:186`, `api/app/Modules/HR/Services/TrainingExpiryService.php:190` |

### Sensitive policy defaults requiring explicit approval

| Area | Proposed least-privilege default | Business decision needed |
| --- | --- | --- |
| Payroll / payslip | Employee receives own readiness only; payroll approvers/operators receive workflow notices only with payroll-specific permission and period scope; no amount in toast. | Confirm whether payroll email remains mandatory/current behavior and which named payroll roles may receive failure or disbursement alerts. |
| HR / discipline / separation / salary changes | Subject employee receives own actionable outcome; assigned HR case owner and required approver only; no broad HR role fanout. | Confirm whether department heads see all employee cases in department or only assigned/approval cases; confirm disciplinary audiences. |
| Finance / receivables / payables | Assigned finance owner/current approver and customer/vendor account contacts only; details minimal outside finance module. | Confirm executive escalation threshold and whether executive roles see invoice balances or only material exceptions. |
| Customer/vendor portals | Active party-bound contacts with record-level portal access. | Confirm whether all active contacts on one account receive each order/invoice notice or only transaction contact. |
| Admin / queue / alerts | Users with precise admin-operational permission and subject visibility. | Confirm whether `system_admin` should receive domain notifications as a permission bypass or only system/admin events. |

### Message and link standards proposed

- Titles start with a verb or clear action: “Approve PO-2026-0042”, “Review NCR-2026-018”, “Confirm order SO-2026-0071”.
- Body has one useful fact line, actor when relevant, and no sensitive value unless route permission allows it.
- Dates use `Asia/Manila`; amounts use `₱` and two decimals only in authorized finance views.
- Keep legacy renderer for stored Laravel class-name rows. New rows should keep structured type/subject/actor/route/severity/category fields and renderer version; do not rewrite legacy rows before a copied-data migration rehearsal.
- Links use named SPA route builders and hash IDs, not API URLs or raw database IDs. Record links need forbidden/deleted fallback. A link without verified SPA route stays blocked from catalog approval.
- Toast only for urgent safety stop, active critical machine breakdown, critical approved quality SLA, or explicitly approved system outage; other events stay in bell/center.

## B. Sidebar signal catalog proposal

### Shared definitions

| Term | Proposed definition |
| --- | --- |
| `waiting_on_me` | Record is at current approval/action step assigned to user or active delegate; same source-scope and access checks as destination list. |
| `overdue` | Date-only business due date `< Asia/Manila today`; timestamp deadline `< now(Asia/Manila)`. Due today is not overdue. Exclude cancelled, voided, archived, completed, rejected, and soft-deleted records unless explicit follow-up remains actionable. |
| `due_soon` | Proposed default: due today through next 3 calendar days, inclusive, in `Asia/Manila`; configurable per signal after owner approval. Training retains existing 14-day schedule / 30-day certification thresholds unless policy changes them. |
| `unpaid` / `partial` | Proposed when stored balance is > 0; partial only when paid amount is > 0 and < total. Exclude void/cancelled/archived. Verify each model's authoritative balance columns before enabling. |
| `low_stock` | Existing definition: reorder point > 0 and total available stock <= reorder point. `out_of_stock` means available quantity <= 0 for an active stocked item. Confirm reservations/quarantine treatment with inventory owner. |
| Audience | Personal signals use user ownership/current assignment; area signals require backend view permission plus exact department/site/customer/vendor scope of destination list. No count includes rows user cannot open. |
| Filter / route | Each route row below names intended signal and destination. `signal=<id>` is a proposed filter contract, not verified as supported by current page/API. Implement through shared query scope, not client-only filtering. |
| Freshness | Proposed shared badge endpoint per user/scope, cache per signal group, short TTL, invalidation on contributing writes, private user refresh ping, and hidden-tab-paused polling. Current baseline is 30s cache/60s poll; no latency benchmark exists. |

### Signal definitions, audience, and destination

`A` = personal, `S` = permission-and-record-scope qualified area, `Sys` = system-operator permission. Route filters are proposals to verify against actual page/API query contracts.

| Signal ID | Label / severity | Exact proposed definition | Audience | Destination + filter |
| --- | --- | --- | --- | --- |
| `action_center` | Action required | Existing `ActionCenterService` summary total; retain only tasks with a valid same-user actionable row. | A + source scope | `/dashboard?signal=action_center` |
| `approvals.waiting_on_me` | Awaiting my approval / action-required | Current pending ApprovalRecord, current step only, plus active delegate role; require source-view scope. | A | `/approvals?signal=waiting_on_me` |
| `approvals.submitted_outcome` | Decision outcome / info | Unread approval decision notifications for the current submitter, limited to records the submitter can open. Include approved/rejected/returned only where the event exists; destination is the same filtered notification list. | A: current submitter | `/notifications?filter=approval_outcomes` |
| `notifications.unread` | Unread / info | Authenticated user's unread notification rows only. | A | `/notifications?filter=unread` |
| `portal.notifications.unread` | Unread / info | Unread portal notification rows for the authenticated portal user only; preserve supplier/customer morph and tenant scope. | A: current portal user | `/portal/supplier/notifications?filter=unread` or `/portal/customer/notifications?filter=unread` |
| `crm.inquiry.triage` | New inquiry / action-required | `ContactInquiryStatus::New` only. `InProgress` means someone has started handling it; `Closed` is terminal. The inbox has no assignee or team column, so this is a shared queue. | S: `crm.inquiries.view` and `crm.inquiries.manage`; company-wide inquiry list, matching the destination permissions. | `/crm/inquiries?status=new` |
| `crm.so.awaiting_confirmation` | Confirmation / response needed | Internal queue: `awaiting_confirmation`, `delivery_negotiation`, and `draft` only when `customer_confirmation_requested_at` is set. Exclude unsent drafts and terminal states. This matches the workflow: sending a draft to a customer records the timestamp without changing `draft`; submitted portal orders use `awaiting_confirmation`; delivery proposals use `delivery_negotiation`. | S: `crm.sales_orders.view`, company-wide like the destination list. Customer portal rows stay customer-bound. | `/crm/sales-orders?signal=awaiting_confirmation`; customer `/portal/customer/orders?signal=awaiting_confirmation` |
| `crm.so.response_due` | Due soon / overdue | Open customer response/delivery proposal with due date/time past or within threshold. | A/S with order scope | `/crm/sales-orders?signal=response_due` |
| `crm.complaint.open` | Open complaint / action-required | Complaint status outside resolved/closed/cancelled, scoped to assigned team/list. | S: complaint view + record scope | `/crm/complaints?signal=open` |
| `crm.complaint.sla_due` | SLA due / warning | Open complaint/8D with response/action deadline <= now+3 Manila calendar days; overdue split from due soon. | A/S assigned owner/reviewer | `/crm/complaints?signal=sla_due` |
| `pricing.review_required` | Review / action-required | Pending price/standard-cost/negotiation review assigned to current reviewer; not every open price record. | A/S pricing permission + record scope | `/crm/products?signal=review_required` or `/crm/price-agreements?signal=review_required` after route ownership is confirmed |
| `pricing.agreement_expiring` | Expiring / warning | Active agreement end date between Manila today and +3 days; exclude superseded/terminated. | S: agreement view + account scope | `/crm/price-agreements?signal=expiring` |
| `returns.open_action` | Open return / action-required | Implemented return states requiring a current owner; exclude completed/rejected/cancelled and records with no next action. | A/S: return view + case scope | `/return-management?signal=open_action` |
| `returns.case_action` | Case action / warning | Open case in a state with a current assigned next step; exact map deferred until return workflow status audit. | A/S | `/return-management/cases?signal=action` |
| `production.release` | Awaiting confirmation / action-required | `WorkOrderStatus::Planned` only; this is the pre-confirmation state. No per-work-order confirmer assignment or site/line scope exists. `WorkOrder::scopeAwaitingConfirmation()` backs the badge and `signal=release` list filter; exclude soft-deleted rows. | A/S: `production.view` + `production.work_orders.view` + `production.wo.confirm`; current list is company-wide. | `/production/work-orders?signal=release` |
| `production.overdue` | Overdue / warning | Status `confirmed` or `in_progress`, non-null `planned_end < now()` in app timezone (default `Asia/Manila`); exclude terminal and soft-deleted rows. `WorkOrder::scopeOverdue()` powers both badge and list filter. No site/line row scope exists. | S: `production.view` + `production.work_orders.view`; current list scope is company-wide. | `/production/work-orders?signal=overdue` |
| `production.due_soon` | Due soon / warning | `planned`, `confirmed`, `in_progress`, or `paused`; non-null `planned_end` from now through end of the date `production.work_orders.due_soon_days` calendar days ahead (default 3; configurable 0–365); exclude soft-deleted rows. `WorkOrder::scopeDueSoon()` powers badge and list filter. | S: `production.view` + `production.work_orders.view`; company-wide. | `/production/work-orders?signal=due_soon` |
| `production.paused` | Paused / action-required | `WorkOrderStatus::Paused` only; exclude soft-deleted rows. `WorkOrder::scopePaused()` backs the badge and `signal=paused` list filter. | S: `production.view` + `production.work_orders.view` + `production.work_orders.lifecycle`; company-wide list. | `/production/work-orders?signal=paused` |
| `production.blocked` | Blocked / warning | Active work order with explicit blocked/paused/material-shortage state; confirm exact states and blocking relation first. | A/S: assigned crew/production site | `/production/work-orders?signal=blocked` |
| `production.output_pending` | Output confirmation / action-required | Completed/active operation with required output report missing and current reporting assignee. | A: assigned operator/crew | `/production/work-orders?signal=output_pending` |
| `production.schedule.due_soon` | Schedule due / warning | Scheduled action/start due within 3 days; no count until schedule list exposes same stable date/status scope. | S: schedule permission + line/site | `/production/schedule?signal=due_soon` |
| `production.schedule.conflict` | Schedule conflict / warning | Unresolved, non-dismissed `Alert` rows with type `mrp_schedule_conflict`; count only alerts whose referenced work order/schedule the current user can open. Alert API accepts `type`; SPA needs a matching type filter before release. | `alerts.view` plus source-record visibility | `/alerts?type=mrp_schedule_conflict` |
| `production.crew.assigned` | Assigned work / action-required | Active work orders whose crew assignment contains the current employee; exclude completed/cancelled and assignments outside current shift/site. | A: assigned employee | `/self-service/work-orders?signal=assigned` or `/factory?signal=assigned` |
| `chain.bottlenecks` | Stuck chain item / warning | Rows returned by currently enabled `ChainBottleneckService` detectors using each configured age threshold. These are stale-step detections, not a generic blocked/overdue status. Count only rows the same user may open. | S: `dashboard.view_bottlenecks` plus source-record visibility | `/chains?signal=bottlenecks`; keep disabled until row scope and total match the tracker |
| `mrp.shortage` | Material shortage / warning | Current definition: active MRP plan with `shortages_found > 0`; only visible plans; exclude canceled/archived runs. | S: MRP plan view + site/material scope | `/mrp/plans?signal=shortage` |
| `mrp.run_failed_or_stale` | Run failed / warning | Failed run state or active plan older than approved freshness threshold; stale threshold requires PPC approval. | S: plan/run permission | `/mrp/plans?signal=run_health` |
| `production.machine_down` | Machine down / critical | Machine in explicit down/breakdown state and not restored; machine-list scope required. | A/S: assigned maintenance/production, same site | `/mrp/machines?signal=down` |
| `production.mold_limit_due` | Shot limit / warning | Mold shot count at/above configured warning threshold or limit; use actual mold limit field; exclude inactive molds. | S: mold view + site scope | `/mrp/molds?signal=limit_due` |
| `purchasing.pr.awaiting_my_approval` | Awaiting my approval / action-required | Pending PR with current ApprovalRecord step assigned to user/delegate; replace role-only badge. | A | `/purchasing/purchase-requests?signal=awaiting_my_approval` |
| `purchasing.po.awaiting_my_action` | PO action / action-required | Open PO requiring current buyer approval/confirmation or supplier response; classify actions separately. | A/S: purchasing permission + assignment/source scope | `/purchasing/purchase-orders?signal=action` |
| `purchasing.po.receipt_overdue` | Receipt overdue / warning | Approved, non-cancelled PO line with expected receipt date < Manila today and remaining quantity > 0. | S: purchasing/warehouse scope | `/purchasing/purchase-orders?signal=receipt_overdue` |
| `purchasing.rfq.response_due` | Quote due / warning | Open RFQ with vendor response deadline past/within 3 days; internal assigned buyer and bound vendor contact in separate views. | A/S and vendor-account-bound | `/purchasing/rfqs?signal=response_due`; supplier `/portal/supplier/rfqs?signal=response_due` |
| `purchasing.supplier_listing.review` | Listing review / action-required | Listing in submitted/review state assigned to current reviewer. | A/S: listing-review permission | `/purchasing/supplier-listings?signal=review` |
| `inventory.low_stock` | Low stock / warning | Active item reorder point > 0 and available quantity <= reorder point. Reservation/quarantine math needs owner approval. | S: inventory view + warehouse/site scope | `/inventory/items?signal=low_stock` |
| `inventory.out_of_stock` | Out of stock / critical | Active stock item with available quantity <= 0; separate from low-stock count. | S: inventory view + warehouse/site scope | `/inventory/items?signal=out_of_stock` |
| `inventory.grn.pending_inspection` | Pending inspection / action-required | GRN in `pending_qc`, matching existing badge, accessible to assigned QC/receiving user. | A/S: inventory/quality permission + site | `/inventory/grn?status=pending_qc` |
| `inventory.grn.pending_putaway` | Pending putaway / action-required | Received/cleared GRN with received quantity remaining unput away; verify field/state parity. | A/S: warehouse scope | `/inventory/grn?signal=pending_putaway` |
| `inventory.mrb.held` | Quarantined material / warning | `MrbStatus::Held` only; released, scrapped, and returned records are excluded. The list has no site/department filter, so this is company-wide, matching the current API scope. | S: `inventory.mrb.view`, same as the list route | `/inventory/mrb?status=held` |
| `inventory.material_issue.awaiting_handover` | Handover / action-required | Material issue awaiting current picker/warehouse handoff; exact state map from enum required. | A: assigned picker/warehouse user | `/inventory/material-issues?signal=handover` |
| `inventory.adjustment.awaiting_my_approval` | Approval / action-required | Adjustment at current approval step for user/delegate; same approval source access. | A | `/inventory/stock-adjustments?signal=awaiting_my_approval` |
| `inventory.transfer.awaiting_my_action` | Transfer / action-required | Active transfer awaiting current approval, dispatch, or receipt; expose each stage as separate filter. | A/S: assigned source/destination warehouse | `/inventory/transfer-orders?signal=awaiting_my_action` |
| `inventory.picking.awaiting_action` | Picking / action-required | Open pick/handover task assigned to current worker/queue; exact list status required. | A/S: assigned warehouse scope | `/inventory/picking?signal=awaiting_action` |
| `quality.inspection.awaiting_my_action` | Inspection / action-required | Inspection in draft/in-progress or review state only when current inspector/reviewer has action. | A/S: quality permission + plant/lot scope | `/quality/inspections?signal=awaiting_my_action` |
| `quality.lot.rejected` | Rejected lot / critical | Lot in rejected/held disposition not yet released/disposed; exact enum and owning list required. | S: quality/production with lot scope | `/quality/traceability?signal=rejected_or_held` |
| `quality.calibration.due` | Calibration due / warning | Calibration records in `due` or `overdue`; exclude `retired`. The existing due-window setting is 30 days; use stored status recomputed from `next_calibration_date`. | S: `quality.calibration.view` plus equipment/site scope | `/quality/calibration?status=due` and `/quality/calibration?status=overdue`; no sidebar item currently links to this route |
| `quality.ncr.open` | Open NCR / action-required | NCR outside closed/cancelled, with assigned owner or actionable next step. | A/S: NCR view + site scope | `/quality/ncrs?signal=open` |
| `quality.ncr.overdue` | Overdue / warning | Open NCR/CAPA/effectiveness record due date < Manila today; confirm authoritative due field. | A/S: assigned owner + scoped reviewer | `/quality/ncrs?signal=overdue` |
| `quality.effectiveness.due` | Effectiveness check / warning | Approved corrective action with scheduled effectiveness check due within 3 days or overdue. | A/S: assigned quality reviewer | `/quality/ncrs?signal=effectiveness_due` |
| `maintenance.breakdown.open` | Breakdown / critical | Machine breakdown open and not restored/closed. | A/S: same-site assigned maintenance/production users | `/maintenance/work-orders?signal=breakdown` |
| `maintenance.work_order.unassigned` | Unassigned / action-required | Open maintenance work order (`open`, `assigned`, or `in_progress`) with no assignee. | S: `maintenance.view` + `maintenance.wo.assign`; company-wide, matching the current list. Site scope is unsupported by the current list. | `/maintenance/work-orders?queue=unassigned` |
| `maintenance.work_order.assigned` | Assigned work order / action-required | Nonterminal maintenance work orders assigned to the current technician; use the same assignment/site filter as the technician mobile list. | A: assigned technician and site | `/maintenance/mobile?signal=assigned` |
| `maintenance.work_order.overdue` | Overdue / warning | Open maintenance work order past authoritative due date/time; determine whether waiting-parts pauses SLA. | A/S | `/maintenance/work-orders?signal=overdue` |
| `maintenance.pm.due_soon` | PM due / warning | Active PM schedule with `next_due_at` from Manila now through +3 calendar days and no nonterminal generated work order. | S: maintenance view + site/equipment scope | `/maintenance/schedules?signal=due_soon` |
| `maintenance.pm.overdue` | PM overdue / warning | Active PM schedule with `next_due_at < now(Asia/Manila)` and no nonterminal work order, using the same open-work-order exclusion as generation. | S: maintenance view + site/equipment scope | `/maintenance/schedules?signal=overdue` |
| `finance.ar.unpaid` | Unpaid / warning | Non-void issued/open invoice with authoritative balance > 0; exclude draft and cancelled. | S: finance permission + customer/account scope; bound customer portal contact for own account | `/accounting/invoices?signal=unpaid`; customer `/portal/customer/invoices?signal=unpaid` |
| `finance.ar.overdue` | Overdue receivable / warning | Issued/open invoice with balance > 0 and due date before Manila today. | S: finance permission + account scope; bound customer portal contact for own account | `/accounting/invoices?signal=overdue`; customer `/portal/customer/invoices?signal=overdue` |
| `finance.ar.payment_review` | Payment review / action-required | Customer payment submission in current review state assigned to finance reviewer. | A/S: assigned finance user | `/accounting/invoices?signal=payment_review` |
| `finance.invoice.draft` | Draft / action-required | Existing `draft` invoice count only for assigned owner/current reviewer; never every company draft. | A/S: finance permission + ownership | `/accounting/invoices?signal=draft` |
| `finance.ap.unpaid` | Unpaid payable / warning | Bill status is `unpaid` or `partial` and stored balance > 0, regardless of due date; excludes draft, paid, and cancelled. | S: `accounting.bills.view`; the current internal bill list is company-wide and has no vendor-row scope. | `/accounting/bills?signal=unpaid` |
| `finance.ap.overdue` | Overdue payable / warning | Bill status is `unpaid` or `partial` and due date < today in `Asia/Manila`, matching `Bill::scopeOverdue()`. | S: `accounting.bills.view`; the current internal bill list is company-wide and has no vendor-row scope. | `/accounting/bills?overdue=1` (current list filter) |
| `finance.ap.due_soon` | Due soon / warning | Bill status is `unpaid` or `partial`, stored balance > 0, and due date today through +3 calendar days inclusive in `Asia/Manila`; excludes draft, paid, cancelled, and zero-balance rows. | S: `accounting.bills.view`; the current internal bill list is company-wide and has no vendor-row scope. | `/accounting/bills?signal=due_soon` |
| `finance.budget.awaiting_my_approval` | Approval / action-required | Budget in `submitted`; require `budgeting.view` and direct or active delegated `budgeting.approve`; exclude self-submission and require at least one eligible approval role different from the submitter's role. This matches the existing `BudgetService` maker-checker rule. | A | `/budgeting?signal=awaiting_my_approval` |
| `finance.budget_transfer.awaiting_my_approval` | Approval / action-required | Transfer in `pending`; apply the same direct/delegated `budgeting.approve`, self-submission, and different-role checks used by `BudgetTransferService`. | A | `/budgeting/transfers?status=pending&actionable=1` |
| `hr.leave.awaiting_my_approval` | Leave approval / action-required | Pending department or HR approval at current stage, scoped via existing `DepartmentScope`. | A/S | `/hr/leaves?signal=awaiting_my_approval` |
| `hr.leave.requester_outcome` | Leave outcome / info | Own leave requests with status `rejected` in self-service history. The current enum has no `returned` status; show an outcome badge only for recent/unseen records if the same list filter supports it. | A: requesting employee | `/self-service/leave?signal=requester_outcome`; off by default unless user enables outcome badges |
| `hr.overtime.awaiting_my_approval` | Overtime / action-required | Pending overtime at current approver stage and existing `DepartmentScope`. | A/S | `/hr/attendance/overtime?signal=awaiting_my_approval` |
| `hr.attendance.exception` | Attendance exception / action-required | Deferred: current attendance enum has recorded states (`present`, `absent`, `late`, `halfday`, `on_leave`, `holiday`, `rest_day`) but no unresolved exception/correction state. Do not count historical absence/late rows as actions without HR rule. | Disabled pending HR definition | No verified exception filter |
| `hr.attendance.missing_timeout` | Missing time-out / action-required | Attendance row with non-null `time_in`, null `time_out`, and date before Manila today; exclude archived rows. Mirror `AttendanceService` employee/department/full-HR visibility rules. | A/S: employee self, department approver, or HR list scope | `/hr/attendance?missing_time_out=1` |
| `hr.profile_update.awaiting_my_action` | Profile change / action-required | Pending profile update assigned to current finance/HR reviewer; use existing department scope and exact review stage. | A/S | `/hr/profile-update-requests?signal=awaiting_my_action` |
| `hr.training.expired` | Expired / warning | Active employee training with status `expired`; employee self view or authorized HR scope. | A/S | `/hr/trainings?signal=expired` |
| `hr.training.expiring_soon` | Expiring / warning | Completed certification expiring within existing 30-day window; upcoming training within existing 14-day window. Separate when list does. | A/S: employee + scoped HR | `/hr/trainings?signal=expiring` |
| `hr.recruitment.action` | Recruitment / action-required | Application/posting at current assigned review/action stage; exclude closed/withdrawn. | A/S: recruiter assignment or scoped permission | `/hr/recruitment?signal=action` |
| `hr.recruitment.bottleneck` | Recruitment bottleneck / warning | Records flagged by `recruitment:check-bottlenecks`: new 2 days, screening 3, interview 2 without future interview or with past interview lacking outcome, offer 5, hired 3 without employee conversion; also open postings past `closes_at`. Keep current configured thresholds. | S: recruitment permission plus same HR recipient scope as list | `/hr/recruitment?signal=bottleneck`; disabled until list filter reproduces command's exact conditions |
| `hr.employee.contract_or_regularization_due` | Regularization review / warning | Deferred: dashboard counts active employees by `date_regularized` within its horizon, while self-service derives expected date from hire date and `hr.probation.period_months`. Confirm authoritative field and employee-list filter before counting. | Disabled pending HR policy/data reconciliation | No verified employee-list filter |
| `hr.separation.clearance_pending` | Clearance / action-required | Separation checklist has a current incomplete task assigned to user/department; hide reason/details broadly. | A/S: assigned clearance owner | `/hr/separations?signal=clearance_pending` |
| `hr.loan.awaiting_my_action` | Loan / action-required | Loan/write-off at current approval stage for user; employee sees own outcome only. | A/S: current approver or subject employee | `/hr/loans?signal=awaiting_my_action` |
| `payroll.awaiting_my_action` | Payroll / action-required | Payroll period/adjustment at current workflow step for user with payroll-specific permission and source scope. | A/S, restricted payroll | `/payroll/periods?signal=awaiting_my_action` or `/payroll/adjustments?signal=awaiting_my_action` |
| `payroll.run_or_disbursement_failed` | Payroll handoff / action-required | Period has `bank_file_status=manual_required` or `gl_handoff_status=manual_required`; exclude voided periods and `not_required` GL handoffs. Current enums have no `failed` value, so do not label this as failure. | A/S, payroll-specific permission and period scope | `/payroll/periods?signal=manual_handoff` |
| `payroll.payslip_ready` | Payslip ready / info | Current employee has a finalized or disbursed payroll row with a ready payslip; never count another employee's row. | A: employee linked to current account | `/self-service/payslips?signal=ready`; off by default unless unread readiness needs a badge |
| `payroll.payslip_email_failed` | Payslip email failed / warning | Payroll row has `payslip_email_status=failed` while its period remains finalized/disbursed and the employee can still open the payslip; exclude voided/no-longer-publishable rows. | A: affected employee for own row; payroll operator only with payroll permission and period scope | `/self-service/payslips?signal=email_failed`; internal `/payroll/periods?signal=payslip_email_failed` only after both lists expose same filter |
| `supply_chain.delivery.overdue` | Delivery overdue / warning | Open delivery scheduled before now and not acknowledged/cancelled/completed; use delivery list scope. | A/S: assigned dispatcher/driver/site | `/supply-chain/deliveries?signal=overdue` |
| `supply_chain.delivery.awaiting_ack` | Acknowledgment / action-required | Delivered/awaiting-ack state not acknowledged by bound customer; portal sees own delivery; internal owner sees assigned row. | A + party-bound portal | `/supply-chain/deliveries?signal=awaiting_ack`; customer `/portal/customer/deliveries?signal=awaiting_ack` |
| `supply_chain.delivery.in_transit` | In transit / info | Existing definition: `loading` or `in_transit`; retain only if user needs to act, else hide as info. | S: delivery permission + assignment/site | `/supply-chain/deliveries?signal=in_transit` |
| `supply_chain.shipment.mid_pipe` | Inbound shipment / warning | Active `ShipmentStatus::InTransit`, `Customs`, or `Cleared` records. Other lifecycle states and soft-deleted records are excluded. The list has no narrower record scope. | S: either `supply_chain.view` or `supply_chain.shipments.view`, matching the API route | `/supply-chain/shipments?status=mid_pipe` |
| `admin.user.locked_or_invite_pending` | Account issue / warning | Locked or expired/pending invite requiring admin action; exclude ordinary inactive users. | Sys: `admin.users.manage` | `/admin/users?signal=locked_or_invite_pending` |
| `admin.portal_access.pending` | Portal access / action-required | Portal user rows with status `pending` in the existing access list; do not include inactive/locked users. | S: `b2b.portal_access.view` plus account scope | `/accounting/portal-access?status=pending` |
| `supply_chain.vehicle.maintenance` | Vehicle unavailable / warning | Vehicle status `maintenance`; exclude retired vehicles. Match fleet list `status=maintenance` filter. | S: supply-chain view and fleet scope | `/supply-chain/fleet?status=maintenance` |
| `system.chain_listener_failed` | Automation failure / critical | Failed/stuck chain listener awaiting authorized recovery, not completed/retried. | Sys: chain recovery permission | `/chains/recovery?signal=failed` |
| `admin.backup.failed_or_overdue` | Backup / critical | Required backup failed or scheduled backup missed approved SLA, only if scheduler stores reliable result/time. | Sys: backup admin permission | `/admin/backups?signal=failed_or_overdue` |
| `alerts.open` | System alert / severity-derived | Unresolved, non-dismissed Alert rows; explicitly area-wide only if alert list is shared and filter identical. Never call personal unread. | S/Sys per alert type and subject scope | `/alerts?signal=open` |
| `dss.critical` | DSS critical / critical | Open critical recommendation with active publisher, owner, and record-access rule; disabled today. | A/S only after detector owner approval | `/decision-center?signal=critical` |

Maintenance scope reconciliation: Phase 2 proposed site scope and `signal=unassigned`. Source review found no site filter in the work-order list; `maintenance.view` opens the company-wide list, and the actual API filter is `queue=unassigned`. The signal therefore uses the list's company scope and additionally requires assignment permission. Site scoping remains unsupported; do not claim site-restricted parity.

### Internal sidebar route coverage: 76 destinations (73 Phase 0 baseline)

Every internal route appears below. `Current` is the current `Sidebar.tsx` key; `proposed` is for review, not an approved badge. `—` means no reliable actionable signal proposed from current evidence. Permission/feature gates are copied from discovery; backend route authorization remains authoritative.

| Path | Current badge | Frontend permission gate | Proposed signal IDs |
| --- | --- | --- | --- |
| `/dashboard` | `action_center` | `dashboard.action_center.view` | action_center |
| `/decision-center` | — | `dss.view` | dss.critical (conditional: no active publisher) |
| `/chains` | — | any: crm.sales_orders.view, dashboard.view_bottlenecks | chain.bottlenecks (disabled until source-record scope and tracker total match) |
| `/chains/recovery` | — | `dashboard.chain_recovery.view` | system.chain_listener_failed |
| `/approvals` | `approvals` | `approvals.board.view` | approvals.waiting_on_me |
| `/notifications` | `unread` | `notifications.view` | notifications.unread; approvals.submitted_outcome |
| `/crm/inquiries` | — | `crm.inquiries.view` | crm.inquiry.triage |
| `/crm/sales-orders` | `pending_so` | `crm.sales_orders.view` | crm.so.awaiting_confirmation; crm.so.response_due |
| `/crm/customers` | — | `accounting.customers.view` | — (no actionable customer master state verified) |
| `/crm/products` | — | `crm.products.view` | pricing.review_required (item-cost review only if Product list exposes same filter) |
| `/crm/price-agreements` | — | `crm.price_agreements.view` | pricing.agreement_expiring (if end date/active state reliable) |
| `/crm/complaints` | `open_complaints` | `crm.complaints.view` | crm.complaint.open; crm.complaint.sla_due |
| `/return-management` | `pending_returns` | `return_management.view` | returns.open_action |
| `/return-management/cases` | — | `return_management.view` | returns.case_action |
| `/production/work-orders` | `work_orders_awaiting_confirmation`, `work_orders`, `work_orders_due_soon`, `work_orders_paused` | API: `production.view` + `production.work_orders.view`; release additionally requires `production.wo.confirm`; paused additionally requires `production.work_orders.lifecycle` | production.release; production.overdue; production.due_soon; production.paused; production.blocked (deferred); production.output_pending |
| `/production/schedule` | — | `production.schedule.view` | production.schedule.due_soon; production.schedule.conflict (alert list has persisted types; add matching SPA filter and source access scope) |
| `/production/routings` | — | `production.routings.view` | — (no reliable actionable status verified) |
| `/mrp/plans` | `mrp_plans` | `mrp.plans.view` | mrp.shortage; mrp.run_failed_or_stale (only with reliable run state) |
| `/mrp/boms` | — | `mrp.boms.view` | — (no actionable review state verified) |
| `/mrp/machines` | — | `mrp.machines.view` | production.machine_down |
| `/mrp/molds` | — | `mrp.molds.view` | production.mold_limit_due |
| `/purchasing/chain` | — | `purchasing.chain.view` | — (multi-module overview; actionable signals stay on their source lists to avoid an unscoped aggregate) |
| `/purchasing/purchase-orders` | — | `purchasing.view` | purchasing.po.awaiting_my_action; purchasing.po.receipt_overdue |
| `/purchasing/purchase-requests` | `purchase_requests` | `purchasing.view` | purchasing.pr.awaiting_my_approval |
| `/purchasing/rfqs` | — | `purchasing.rfq.view` | purchasing.rfq.response_due |
| `/purchasing/approved-suppliers` | — | `purchasing.po.create` | — (no actionable state verified) |
| `/purchasing/supplier-listings` | — | `purchasing.supplier_listings.review` | purchasing.supplier_listing.review |
| `/inventory/items` | `low_stock` | `inventory.view` | inventory.out_of_stock; inventory.low_stock |
| `/inventory/uoms` | — | `inventory.items.manage` | — |
| `/inventory/grn` | `pending_grn` | `inventory.view` | inventory.grn.pending_inspection; inventory.grn.pending_putaway |
| `/inventory/mrb` | `mrb_holds` | `inventory.mrb.view` | inventory.mrb.held |
| `/inventory/material-issues` | — | `inventory.issue.create` | inventory.material_issue.awaiting_handover |
| `/inventory/stock-levels` | — | `inventory.view` | — (avoid duplicate low-stock count; link to Items signal) |
| `/inventory/stock-adjustments` | — | any: inventory.adjust, inventory.adjust.approve | inventory.adjustment.awaiting_my_approval |
| `/inventory/warehouse-map` | — | `inventory.warehouse.manage` | — |
| `/inventory/transfer-orders` | — | `inventory.adjust` | inventory.transfer.awaiting_my_action |
| `/inventory/picking` | — | `inventory.picking.view` | inventory.picking.awaiting_action |
| `/supply-chain/deliveries` | `deliveries` | any: supply_chain.view, supply_chain.deliveries.view | supply_chain.delivery.overdue; supply_chain.delivery.awaiting_ack; supply_chain.delivery.in_transit |
| `/supply-chain/shipments` | `shipments` | any: supply_chain.view, supply_chain.shipments.view | supply_chain.shipment.mid_pipe |
| `/supply-chain/fleet` | — | `supply_chain.view` | supply_chain.vehicle.maintenance |
| `/quality/inspection-specs` | — | `quality.specs.view` | — |
| `/quality/inspections` | `pending_inspections` | `quality.inspections.view` | quality.inspection.awaiting_my_action |
| `/quality/ncrs` | `ncrs` | `quality.ncr.view` | quality.ncr.open; quality.ncr.overdue; quality.effectiveness.due |
| `/quality/traceability` | — | `quality.inspections.view` | quality.lot.rejected (covers held lots only if traceability filter exposes held disposition) |
| `/budgeting` | `pending_budgets` | `budgeting.view` | finance.budget.awaiting_my_approval |
| `/budgeting/transfers` | `pending_transfers` | any: `budgeting.view`, `budgeting.approve` | finance.budget_transfer.awaiting_my_approval |
| `/accounting/invoices` | `draft_invoices` | `accounting.invoices.view` | finance.ar.unpaid; finance.ar.overdue; finance.ar.payment_review; finance.invoice.draft |
| `/accounting/bills` | `overdue_bills` | `accounting.bills.view` | finance.ap.unpaid; finance.ap.overdue; finance.ap.due_soon |
| `/accounting/vendors` | — | `accounting.vendors.view` | — |
| `/accounting/income-statement` | — | `accounting.statements.view` | — |
| `/accounting/balance-sheet` | — | `accounting.statements.view` | — |
| `/hr/recruitment` | — | `hr.recruitment.view` | hr.recruitment.action; hr.recruitment.bottleneck |
| `/hr/trainings` | — | `hr.trainings.view` | hr.training.expired; hr.training.expiring_soon |
| `/hr/loans` | — | `loans.view` | hr.loan.awaiting_my_action |
| `/hr/separations` | — | `hr.separation.view` | hr.separation.clearance_pending |
| `/hr/profile-update-requests` | — | any: hr.employees.view, hr.profile_updates.finance_review | hr.profile_update.awaiting_my_action |
| `/hr/employees` | `profile_requests` | any: hr.employees.view, hr.salary_adjustments.view | hr.employee.contract_or_regularization_due (only if reliable date/state exists) |
| `/hr/departments` | — | `hr.departments.view` | — |
| `/hr/attendance` | — | any: attendance.edit, attendance.import, attendance.ot.approve | hr.attendance.missing_timeout |
| `/hr/attendance/overtime` | `overtime` | `attendance.ot.approve` | hr.overtime.awaiting_my_approval |
| `/hr/leaves` | `leaves` | any: leave.approve_dept, leave.approve_hr, leave.types.manage | hr.leave.awaiting_my_approval |
| `/payroll/periods` | `payroll` | `payroll.periods.view` | payroll.awaiting_my_action; payroll.run_or_disbursement_failed; payroll.payslip_email_failed |
| `/payroll/adjustments` | — | `payroll.adjustments.view` | payroll.awaiting_my_action |
| `/payroll/statutory` | — | `payroll.statutory.export` | — (exports are generated on request; no persisted failed/unposted export state found) |
| `/maintenance/work-orders` | `maintenance_wo` | `maintenance.view` | maintenance.breakdown.open; maintenance.work_order.unassigned; maintenance.work_order.overdue |
| `/maintenance/schedules` | — | `maintenance.view` | maintenance.pm.due_soon; maintenance.pm.overdue |
| `/assets` | — | `assets.view` | — (transfer list route was removed; disposal action appears on record detail; calibration belongs to Quality) |
| `/admin/users` | — | `admin.users.manage` | admin.user.locked_or_invite_pending |
| `/admin/roles` | — | `admin.roles.manage` | — |
| `/admin/audit-logs` | — | `admin.audit_logs.view` | — (audit feed is not a personal action queue) |
| `/admin/settings` | — | `admin.settings.manage` | — |
| `/admin/sessions` | — | `admin.settings.manage` | — |
| `/admin/component-gallery` | — | `admin.settings.manage` | — |
| `/accounting/portal-access` | — | `b2b.portal_access.view` | admin.portal_access.pending |
| `/admin/backups` | — | `admin.backups.view` | admin.backup.failed_or_overdue |
| `/admin/gov-tables` | — | `admin.gov_tables.manage` | — (rows have effective dates, but no authoritative expiry/update deadline) |

### Customer and supplier portal route coverage: all 19 destinations

Portal audience always means active contact bound to that route's customer/vendor account and authorized for the exact record. Proposed signals require API list-filter parity before release.

`—` means no distinct actionable filter was verified, so no badge is proposed. The SPA also has `/quality/calibration` outside sidebar navigation; its status filter supports `due` and `overdue`, so the `quality.calibration.due` proposal is ready for review but has no sidebar attachment.

`/alerts` is also a standalone SPA route, not a sidebar item. `alerts.open` remains a candidate for its own filtered page; current unread-count/list parity defect is recorded in `FINDINGS.md`.

| Shell | Path | Label | Proposed signal IDs |
| --- | --- | --- | --- |
| Supplier portal | `/portal/supplier` | Dashboard | — |
| Supplier portal | `/portal/supplier/notifications` | Notifications | portal.notifications.unread |
| Supplier portal | `/portal/supplier/purchase-orders` | Purchase Orders | — |
| Supplier portal | `/portal/supplier/rfqs` | Supplier RFQs | purchasing.rfq.response_due |
| Supplier portal | `/portal/supplier/item-listings` | Item Listings | — |
| Supplier portal | `/portal/supplier/invoices` | Invoices | — |
| Supplier portal | `/portal/supplier/statement-of-account` | Account Statement | — |
| Supplier portal | `/portal/supplier/deliveries` | Deliveries | — |
| Supplier portal | `/portal/supplier/problems` | Problem reports | — |
| Customer portal | `/portal/customer` | Dashboard | — |
| Customer portal | `/portal/customer/notifications` | Notifications | portal.notifications.unread |
| Customer portal | `/portal/customer/orders` | Orders | crm.so.awaiting_confirmation |
| Customer portal | `/portal/customer/orders/new` | Place Order | — |
| Customer portal | `/portal/customer/pricing` | Pricing agreements | — |
| Customer portal | `/portal/customer/invoices` | Invoices | finance.ar.unpaid; finance.ar.overdue |
| Customer portal | `/portal/customer/statement-of-account` | Account Statement | — |
| Customer portal | `/portal/customer/deliveries` | Deliveries | supply_chain.delivery.awaiting_ack |
| Customer portal | `/portal/customer/returns` | Returns | — |
| Customer portal | `/portal/customer/problems` | Problem reports | — |

### Compact navigation coverage

| Shell item | Proposed signal | Audience |
| --- | --- | --- |
| Employee self-service `/self-service` | — (unified hub deferred to Phase 6) | A: current employee only |
| Employee self-service `/self-service/work-orders` | production.crew.assigned; production.output_pending | A: assigned employee only |
| Employee self-service `/self-service/dtr` | — (DTR is history; no unresolved exception state verified) | A: current employee only |
| Employee self-service `/self-service/leave` | hr.leave.requester_outcome | A: own leave records |
| Employee self-service `/self-service/payslips` | payroll.payslip_ready; payroll.payslip_email_failed | A: own finalized payslip only |
| Employee self-service `/self-service/me` | — | No cross-record count |
| Factory floor `/factory` | production.crew.assigned; production.output_pending | A: signed-in employee assigned to current line/shift |
| Factory floor `/factory/qc` | quality.inspection.awaiting_my_action | A/S: assigned inspection/line |
| Maintenance mobile `/maintenance/mobile` | maintenance.work_order.assigned; maintenance.breakdown.open | A: technician's assigned site/work orders |

### Signal presentation / refresh proposal

- Highest-severity or `waiting_on_me` signal is primary; up to two secondary chips; popover lists every signal with separate filtered links. Parent group aggregate appears only when collapsed. Zero is hidden.
- Every label includes text/icon and ARIA summary; color never carries meaning alone. Same severity/signal uses same color/icon. Counts cap at `99+`; reduced motion; keyboard popovers; stale-value marker on fetch error.
- Noncritical informational signals default off or user-hideable. Action-required in-app signals default on. Critical safety/system items cannot be muted without named business rule.
- One aggregated endpoint returns signals visible to current user; per-signal scopes also power route filters. Do not ship a badge before parity tests pass for relevant roles.
- Proposed freshness: user/scope cache 30 seconds, invalidated by signal source writes; private `user.{hash}` content-free refresh; SPA poll every 60 seconds and pause in background. Latency target (<200 ms p95) remains unmeasured and is a release gate.
- Proposed due-soon threshold: three calendar days; training retains current 14/30-day tiers; PM/SLA use three days unless owner approves another threshold. Threshold changes get settings audit history.

## C. Architecture and roadmap proposal

| PR / unit | Scope | Acceptance gates |
| --- | --- | --- |
| 0. Policy approval | Resolve business questions below; freeze event and signal definitions. | Written approval of sensitive recipients, status definitions, scopes, thresholds. No code before this gate. |
| 1. Central notification contract | Structured event/template/link schema with legacy fallback; after-commit, queued, deduped dispatcher; guard against direct in-app dispatch outside adapters. | Existing endpoints/rows render; no bypass remains in migrated slice; copied-data migration rehearsal. |
| 2. Recipient resolver + access gate | Approved building blocks, permission/scope/policy gate, actor exclusions, reason audit; first approval/finance/HR group. | Positive and negative per-event recipient tests; unauthorized subject never delivered; no-recipient result audited without broad fallback. |
| 3. Realtime hardening | Per-user minimal payload, authorization tests for every private subject channel, reconnect/multi-tab behavior. | Cross-user and anonymous channel tests; no sensitive metadata on shared channels. |
| 4. Signal registry + shared scopes | Start with approvals, AP/AR, leave/overtime, low stock/GRN; aggregate endpoint; same scopes in list endpoints. | Role/scope parity and query-count/latency tests before each signal ships. |
| 5. Frontend notification + badges | Anchor deep links, legacy fallback, multi-signal popover, responsive/accessibility behavior, preferences. | Route registry test; keyboard/mobile/collapsed QA; visual evidence. |
| 6. Migrate event groups | Separate revertible PRs: approvals; finance; HR/payroll; purchasing/inventory; production/quality/maintenance; CRM/supply chain/returns; system/portal. | Per-event positive/negative tests and completion report. Sensitive groups first. |
| 7. Extras / gaps | Waiting-on-me hub, digests/escalations, admin simulator/health, DSS, browser push only after policy/need exist. | Separate approval and PR; no scope expansion into foundation. |

### Migration, compatibility, and risks

- Preserve current routes/endpoints and render old `data.title/message/link_to` plus Laravel class-name types through legacy renderer.
- Introduce structured rows compatibly; backfill only after a copied database rehearsal verifies message, link, retention, and portal/internal morph types. No destructive rewrite proposed.
- Existing direct mail-only security/account notifications remain explicit adapters unless policy requires unification; do not change send timing or business event timing.
- Runtime behavior risk remains high until record policies, list filters, route pages, and all 17 local role slugs are exercised. Local role inventory differs from existing role audit by one slug.
- Sidebar scope performance risk: current per-key closures can issue one query per badge. Aggregate endpoint needs grouped queries and representative seed volume before the <200 ms goal is credible.
- Existing broad alerts may be intentionally area-wide; converting them to personal notices without owner decision could suppress needed warnings or expose alert details.
- No deadline supplied; no ship-date promise. Phase 3 began after the user approved the policy defaults; remaining business questions keep their documented defaults until confirmed.

## Open business questions for review

1. **Payroll:** Which exact roles may see run failures, bank/GL exceptions, and employee payslip readiness? Keep email behavior current or require opt-in? Default: own employee + current payroll approver/operator only; no amount in toast.
2. **HR:** Should department heads see all department leave/profile/training/separation counts, or only assigned/current approval tasks? Default: current approval/assigned case only, plus own notices.
3. **Finance:** Which thresholds justify executive receivables/payables escalation? Default: no executive copy absent configured escalation and view scope.
4. **Delegation:** Does active `ApprovalDelegation` fully represent out-of-office/delegation across modules? Default: active configured delegates only; no inferred backup approver.
5. **System admin:** Should `system_admin` receive business notifications through permission bypass? Default: only when event rule selects them and subject policy grants access; no blanket fallback.
6. **Scope:** Are areas always department/site-specific, and can company-wide view see every item in module? Default: mirror each destination list's backend scope exactly.
7. **Due soon:** Approve three Manila calendar days, or different windows for AR/AP, production, quality, maintenance? Default: three days; retain training 14/30 days.
8. **Money:** Should unpaid include draft invoices/bills or only posted/issued items? Default: posted/open balance only; draft stays owner-only signal.
9. **Portal contacts:** Notify every active account contact, or only named transaction contact? Default: named contact when stored, otherwise active party-bound contacts who can open record.
10. **Retention/resolution:** Keep resolved items 90 days, unresolved critical/action until read/closed? Default: 90-day read retention; no auto-expiry for unresolved critical.
11. **Alerts:** Should `Alert` read/dismiss state remain shared by all `alerts.view` users, or become personal? Current schema stores one shared state on each alert; the implementation labels the unread count “shared” and links it to a matching unread filter. Default: keep current shared behavior until Ogami requests per-user state; do not describe it as “my unread alerts.” A personal model needs a recipient/read table and migration.
12. **Quality escalation:** Confirm NCR/CAPA SLA windows, rejected-lot audience, and customer complaint visibility. Default: assigned owner/current reviewer; customer sees only approved external updates.
13. **Threshold ownership:** Who may edit signal thresholds and mandatory categories? Default: admin settings permission with audit log and signal-owner approval.
14. **HR regularization:** Is `employees.date_regularized` the authoritative upcoming due date, or should the system derive it from hire date and the configured probation period? Default: no badge until dashboard, employee list, and self-service use one approved rule.

## Review record

The user approved the proposed defaults on 2026-10-25. Open business questions retain their documented defaults until the underlying policy/list scope can be verified; sensitive runtime claims still require positive and negative tests. Implementation proceeds as separate, revertible units.
