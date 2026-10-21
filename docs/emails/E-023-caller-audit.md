# E-023 generic notification caller audit

## Scope and status

Static repository search found 84 exact `->send(` call sites under `api/app`. Eighty-two are NotificationService generic email-capable calls. Excluded false positives: the auth forgot-password service call and direct payslip `Mail::send()`. The inventory below preserves every generic call site as an audit anchor.

`NotificationService::send()` creates an email only when that active internal user explicitly opted into the exact type. PR #256 rejects invalid addresses, excludes inactive users, deduplicates normalized mailboxes, and applies stable delivery keys when a caller supplies a dedupe key. The caller still controls audience selection; this audit is incomplete until every audience is checked against its SPA route guard and record-level API authorization.

The customer delivery-date proposal is a direct `UserNotificationMail` path outside `NotificationService::send()`. PR #256 queues one message per valid active customer portal address and alerts CRM permission holders on delivery failure.

Route declarations are in `spa/src/routes/*.tsx`; approval links resolve through `ApprovalTypeRegistry`. Extracted route presence alone does not prove recipient visibility. Dynamic helper paths, null links, role-setting audiences, data fields, and trigger conditions remain to be verified per caller.

## Deep-link defects found and fixed

- Budget approval and outcome links now use `/budgeting/{hash}`, matching the SPA detail route. The API permits `budgeting.view` or an approver eligible for that exact submitted budget; rejection controls also follow record-level `can_approve`.
- Budget-transfer links already used `/budgeting/transfers/{hash}` but lacked a SPA detail route. The added page loads that exact transfer. API detail requires global view access or eligibility for that record; decision controls require effective approval permission and `can_approve`.
- Overtime and loan decision links now resolve to `/:id` self-service routes. Their API reads fetch that one hash within the signed-in employee's scope and return 404 for another employee's record. They bypass history-list limits without widening visibility.
- Authenticated return behavior was source-checked: `AuthGuard` stores the requested path, query, and hash; the login page restores it. Browser login was not exercised.
- Focused checks: 31 API tests / 177 assertions and 9 SPA tests passed; SPA typecheck, targeted ESLint, and PHPStan passed. No email sent.

These changes fix the route families found by static search. They do not complete the 82-call-site audience, payload-sensitivity, snapshot, or trigger audit below.

## Caller index

| Source | Call expression |
|---|---|
| `api/app/Modules/SupplyChain/Services/DeliveryService.php:2134` | `$this->notifications->send($recipients, 'invoice.auto_failed', [` |
| `api/app/Modules/Accounting/Services/InvoiceService.php:170` | `app(NotificationService::class)->send($audience, 'approval.action_required', [` |
| `api/app/Modules/Accounting/Services/InvoiceService.php:330` | `app(NotificationService::class)->send($requester, 'approval.completed', [` |
| `api/app/Modules/Accounting/Services/BudgetTransferService.php:87` | `app(NotificationService::class)->send($audience, 'approval.action_required', [` |
| `api/app/Modules/Accounting/Services/BudgetTransferService.php:194` | `app(NotificationService::class)->send($requester, $type, [` |
| `api/app/Modules/CRM/Services/SalesOrderResponseService.php:442` | `$this->notifications->send($audience, 'customer.so_responded', [` |
| `api/app/Modules/Accounting/Services/BudgetService.php:135` | `$notifications->send($audience, 'approval.action_required', [` |
| `api/app/Modules/Accounting/Services/BudgetService.php:203` | `app(NotificationService::class)->send($submitter, $type, [` |
| `api/app/Modules/Accounting/Services/BillService.php:1038` | `app(NotificationService::class)->send($requester, 'bill_payment.posted', [` |
| `api/app/Modules/Accounting/Services/ArDunningService.php:195` | `$this->notifications->send($officers, 'ar.dunning.escalation', [` |
| `api/app/Modules/CRM/Services/Complaint8dEscalationService.php:271` | `$this->notifications->send($audience->all(), '8d.sla', [` |
| `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:660` | `$this->notifications->send($audience, 'customer.rma_created', [` |
| `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:3322` | `$this->notifications->send($recipients, $type, [` |
| `api/app/Modules/Accounting/Listeners/NotifyFinanceOnDeliveryConfirmed.php:39` | `$this->notifications->send($financeUsers, 'chain.delivery_confirmed', [` |
| `api/app/Modules/Leave/Listeners/NotifyOnLeaveSubmitted.php:38` | `$this->notifications->send($audience, 'leave.submitted', [` |
| `api/app/Modules/ReturnManagement/Services/ReturnCaseService.php:613` | `$this->notifications->send($recipients, 'return.case_updated', [` |
| `api/app/Modules/Leave/Listeners/NotifyOnLeaveRejected.php:26` | `$this->notifications->send($user, 'leave.rejected', [` |
| `api/app/Modules/Leave/Listeners/NotifyOnLeavePendingHR.php:30` | `$this->notifications->send($audience, 'leave.pending_hr', [` |
| `api/app/Modules/CRM/Listeners/NotifyOnSalesOrderConfirmed.php:39` | `$this->notifications->send($audience, 'chain.so_confirmed', [` |
| `api/app/Modules/Leave/Listeners/NotifyOnLeaveApproved.php:26` | `$this->notifications->send($user, 'leave.approved', [` |
| `api/app/Console/Commands/SweepMissingOutgoingQc.php:186` | `$notifications->send($recipients, 'chain.outgoing_qc_missing', [` |
| `api/app/Console/Commands/RunDailyMrp.php:49` | `app(NotificationService::class)->send($ppcHeads, 'mrp_run_completed', [` |
| `api/app/Modules/B2B/Services/SupplierDeliveryScheduleService.php:240` | `$this->notifications->send($audience, 'supplier.schedule_cancelled', [` |
| `api/app/Modules/B2B/Services/CustomerPortalService.php:311` | `$this->notifications->send($audience, 'portal.order_awaiting_review', [` |
| `api/app/Console/Commands/NotifyMissingTimeOut.php:60` | `$notifications->send($hr, 'attendance.missing_time_out', [` |
| `api/app/Console/Commands/NotifyMissingTimeOut.php:92` | `$notifications->send($head, 'attendance.missing_time_out', [` |
| `api/app/Modules/Inventory/Services/StockAdjustmentService.php:215` | `app(NotificationService::class)->send($audience, 'approval.action_required', [` |
| `api/app/Modules/Inventory/Services/StockAdjustmentService.php:379` | `app(NotificationService::class)->send($requester, $type, [` |
| `api/app/Console/Commands/CheckMaintenanceRepeatBreakdowns.php:33` | `$notifications->send($supervisors, 'maintenance.repeat_breakdown', [` |
| `api/app/Modules/Quality/Services/NcrService.php:405` | `app(NotificationService::class)->send($qcHeads, 'auto_ncr_created', [` |
| `api/app/Modules/Quality/Services/NcrService.php:1279` | `$this->notifications->send($recipients, 'ncr.return_to_supplier', [` |
| `api/app/Modules/Quality/Services/NcrRecurrenceDetector.php:200` | `$this->notifications->send($recipients, 'ncr.recurrence', [` |
| `api/app/Modules/Inventory/Services/ItemCostReviewService.php:95` | `app(NotificationService::class)->send($audience, $target === Status::Approved ? 'approval.completed' : 'approval.rejected', [` |
| `api/app/Modules/Quality/Services/NcrEscalationService.php:179` | `$this->notifications->send($recipients, 'ncr.escalation', [` |
| `api/app/Console/Commands/CheckMaintenanceAssignment.php:45` | `$notifications->send($recipients, 'maintenance.assignment_escalated', NotifyMaintenanceWorkOrder::payload(` |
| `api/app/Console/Commands/CheckMaintenanceAssignment.php:68` | `$notifications->send($supervisors, 'maintenance.shift_handover', NotifyMaintenanceWorkOrder::payload(` |
| `api/app/Modules/Quality/Services/InspectionService.php:2393` | `app(NotificationService::class)->send($reviewers, 'quality.inspection_awaiting_review', [` |
| `api/app/Modules/Quality/Services/EffectivenessService.php:279` | `$this->notifications->send($recipient, $type, [` |
| `api/app/Modules/HR/Services/TrainingExpiryService.php:169` | `$this->notifications->send($recipients, 'training.expiry', [` |
| `api/app/Modules/HR/Services/RecruitmentService.php:717` | `$this->notifications->send($hrUsers, 'recruitment.new_application', [` |
| `api/app/Modules/Inventory/Listeners/NotifyOnLowStockPrCreated.php:35` | `$this->notifications->send($audience, 'inventory.low_stock', [` |
| `api/app/Modules/Quality/Listeners/TriggerOutgoingQC.php:158` | `app(NotificationService::class)->send($recipients, 'chain.outgoing_qc_required', [` |
| `api/app/Modules/Inventory/Listeners/NotifyOnGrnReceived.php:31` | `$this->notifications->send($audience, 'inventory.grn_received', [` |
| `api/app/Common/Services/CustomApprovalEscalationService.php:95` | `$this->notifications->send($recipients, $type, [` |
| `api/app/Modules/Quality/Listeners/TriggerIncomingQC.php:173` | `app(NotificationService::class)->send($recipients, 'chain.incoming_qc_required', [` |
| `api/app/Modules/Quality/Listeners/TriggerInProcessQC.php:153` | `app(NotificationService::class)->send($recipients, 'chain.in_process_qc_required', [` |
| `api/app/Modules/Attendance/Listeners/NotifyOnOvertimeSubmitted.php:36` | `$this->notifications->send($audience, 'attendance.ot_submitted', [` |
| `api/app/Modules/Quality/Listeners/CreateDeliveryDraftOnQcPass.php:99` | `app(NotificationService::class)->send($recipients, 'chain.delivery_drafted', [` |
| `api/app/Modules/Purchasing/Services/SupplierResponseService.php:461` | `$this->notifications->send($this->purchasingAudience($po), 'supplier.po_responded', [` |
| `api/app/Modules/Attendance/Listeners/NotifyOnOvertimeDecided.php:29` | `$this->notifications->send($user, $type, [` |
| `api/app/Modules/Purchasing/Services/SupplierListingService.php:348` | `app(NotificationService::class)->send($reviewers, 'supplier_listing_submitted', [` |
| `api/app/Common/Services/ApprovalService.php:361` | `$this->notifications->send($recipients, 'approval.action_required', [` |
| `api/app/Common/Services/ApprovalService.php:383` | `$this->notifications->send($submitter, $type, [` |
| `api/app/Common/Services/ApprovalEscalationService.php:86` | `$this->notifications->send($approver, 'approval_reminder', [` |
| `api/app/Common/Services/ApprovalEscalationService.php:137` | `$this->notifications->send($recipients, 'approval_escalation', [` |
| `api/app/Common/Services/ApprovalEscalationService.php:313` | `$this->notifications->send($recipients, 'approval_escalation', [` |
| `api/app/Modules/Purchasing/Services/AutoPurchaseOrderService.php:205` | `app(NotificationService::class)->send($vps, 'auto_po_pending', [` |
| `api/app/Modules/Purchasing/Listeners/NotifyOnPurchaseRequestApproved.php:29` | `$this->notifications->send($audience, 'chain.pr_approved', [` |
| `api/app/Modules/Purchasing/Listeners/NotifyOnPurchaseOrderSubmitted.php:29` | `app(NotificationService::class)->send($audience, 'purchasing.po_pending_approval', [` |
| `api/app/Modules/Purchasing/Listeners/NotifyOnPurchaseOrderApproved.php:29` | `$this->notifications->send($audience, 'chain.po_approved', [` |
| `api/app/Modules/Purchasing/Listeners/DeliverRfqLifecycleNotification.php:84` | `$this->notifications->send($audience->unique('id')->values(), $type, [` |
| `api/app/Modules/Purchasing/Listeners/ConsolidatePurchaseOrders.php:254` | `$this->notifications->send($audience, 'chain.pr_auto_convert_skipped', [` |
| `api/app/Modules/Purchasing/Listeners/AlertOnSupplierDeterioration.php:59` | `$this->notifications->send($audience, 'purchasing.supplier_deterioration', [` |
| `api/app/Modules/Admin/Services/UserPermissionOverrideService.php:167` | `$this->notifications->send($user, 'permission.override', [` |
| `api/app/Modules/Admin/Services/UserPermissionOverrideService.php:226` | `$this->notifications->send($removed['user'], 'permission.override', [` |
| `api/app/Modules/Admin/Services/UserPermissionOverrideService.php:290` | `$this->notifications->send($user, 'permission.override', [` |
| `api/app/Modules/HR/Listeners/NotifyOnSeparationInitiated.php:32` | `$this->notifications->send($audience, 'chain.separation_initiated', [` |
| `api/app/Modules/Production/Listeners/NotifyOnWorkOrderPaused.php:53` | `$this->notifications->send($audience, 'production.wo_paused', [` |
| `api/app/Modules/Production/Listeners/NotifyOnWorkOrderCompleted.php:31` | `$this->notifications->send($audience, 'production.wo_completed', [` |
| `api/app/Modules/Production/Listeners/NotifyOnMachineBreakdown.php:37` | `$this->notifications->send($audience, 'maintenance.breakdown', [` |
| `api/app/Modules/Maintenance/Listeners/NotifyMaintenanceWorkOrder.php:27` | `$this->notifications->send($supervisors, 'maintenance.assignment_needed', self::payload(` |
| `api/app/Modules/Payroll/Services/PayrollPeriodService.php:1012` | `app(\App\Common\Services\NotificationService::class)->send($audience, 'approval.action_required', [` |
| `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php:348` | `$this->notifications->send($employee->user, 'maintenance.assigned', $payload,` |
| `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php:622` | `$this->notifications->send($recipients, 'maintenance.repair_submitted',` |
| `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php:668` | `$this->notifications->send($technician, 'maintenance.repair_rejected',` |
| `api/app/Modules/Maintenance/Services/MaintenancePartRequestService.php:67` | `$notifications->send($notifications->usersWithPermission('inventory.issue.create'),` |
| `api/app/Modules/Maintenance/Services/MaintenancePartRequestService.php:123` | `app(NotificationService::class)->send($user, 'maintenance.parts_handed_over', ['title' => 'Spare parts handed over',` |
| `api/app/Modules/Payroll/Services/PayrollAdjustmentService.php:77` | `app(NotificationService::class)->send($audience, 'approval.action_required', [` |
| `api/app/Modules/Payroll/Services/PayrollAdjustmentService.php:175` | `app(NotificationService::class)->send($requester, $type, [` |
| `api/app/Modules/Payroll/Listeners/NotifyEmployeesOnPayrollFinalized.php:63` | `$this->notifications->send($users, 'chain.payslip_ready', [` |
| `api/app/Modules/Loans/Listeners/NotifyOnLoanSubmitted.php:44` | `$this->notifications->send($audience, 'loans.submitted', [` |
| `api/app/Modules/Loans/Listeners/NotifyOnLoanDecided.php:39` | `$this->notifications->send($user, $type, [` |

## Confirmed caller-level issue

- EF-044: employee maintenance repair submission was blocked by route and request authorization despite the assigned-work rule. Repair rejection notice also used a supervisor-only link. Fixed in draft PR #257.
- EF-045: sales response notices used role permissions only, so individual grants and revocations were ignored. Fixed in draft PR #258.
- EF-046: ten permission lookups across nine notification paths used role-only database queries, omitting active user grants and revocations. Replaced with the shared effective-permission resolver in draft PR #259. This fixes permission calculation only; the per-caller route and record-visibility checks below remain open.
- EF-056: supplier schedule cancellation notices used role permission rows directly and missed effective user grants/revocations. Fixed in draft PR #268 with the shared permission resolver. Regression coverage added for role access, grants, revocations, and inactive accounts. The remaining direct role checks found in this pass are intentional role-scoped/configured audiences or in-app-only paths; they remain subject to the per-caller audit below.
- EF-065: permission override grant, removal, and restore notices linked affected users to `/admin/users/{hash}`, which requires `admin.users.manage`. Removed those three links in draft PR #279; the recipient still receives the change notice without a forbidden destination.
- EF-069: `NcrService::notifyPurchasing()` selected configured roles without checking the permission required by its `/quality/ncrs/{hash}` link. Purchasing can view return requests but need not have `quality.ncr.view`. The payload also quoted `ncr.affected_quantity`, which can differ from the quantity bounded into the RMA. PR #283 links to the created RMA, removes the stale quantity, and filters the email-capable audience through `return_management.view`. The generated in-app `return.supplier_defect` audience uses the same effective permission so users whose return visibility was revoked receive neither notice.

## Caller checks completed

- `NotifyOnLeaveSubmitted`: the configured `department_head` role audience is filtered to the requester's department. This matches `/hr/leaves/{id}` route permission and `LeaveRequestController::authorizeView()` row scope. The employees schema requires `department_id`; an attempted null-department case was rejected by PostgreSQL, so it is not a valid runtime state. Regression coverage verifies matching-department heads receive the notice while other-department and unlinked heads do not. Remaining leave approval/completion callers still require their own audit.
- `NotifyOnLeaveSubmitted` and `NotifyOnLeavePendingHR`: configured role membership alone did not honor individual permission revocations. Draft PR #281 now checks effective `leave.approve_dept` / `leave.approve_hr` permission before dispatch; tests ensure revoked users receive neither inbox notification nor email-eligible audience membership.
- `NcrService::notifyPurchasing` and supplier-defect in-app notice: role membership alone did not enforce the permission required to open the linked RMA. PR #283 filters by effective `return_management.view`; the NCR notice now links to the exact created RMA. A regression test confirms purchasing can read that RMA while lacking NCR view permission.
- Auto-created NCR, recurrence, escalation, and effectiveness notices: configured QC/production/executive audiences and direct CAPA owners linked to `/quality/ncrs/{hash}`. Their role defaults include `quality.ncr.view`, but individual revocations were ignored; action owners could be any active account. PR #284 filters each recipient by effective NCR-view permission and limits owner selection/validation to users with that permission. Existing user-level grants remain honored by `User::hasPermission()`.
- Incoming/in-process/outgoing QC and delivery-drafted notices: configured role membership alone ignored effective permission revocations. Multi-batch outgoing QC sent inspectors to a production work-order page they cannot access; PR #285 links to the outgoing-inspection list filtered by exact work order and stage. Draft-delivery notice now opens its created delivery. Recipients are filtered by `inventory.view`, `quality.inspections.view`, or `supply_chain.deliveries.view` as required by their link.
- `NotifyEmployeesOnPayrollFinalized`: users linked to employees receive only a generic period label and `/self-service/payslips` link; the SPA page is permission-gated and its API scopes rows to the authenticated employee. PR #286 now excludes recipients without effective `payroll.view`; the failed-payroll and voided-period checks remain intact.
- `NotifyOnSeparationInitiated`: configured finance recipients had `hr.separation.view` but not `hr.employees.view`; the previous employee-detail link was forbidden. PR #287 links to the clearance detail route and filters effective `hr.separation.view`. The message includes the employee full name, so confirm this personal-data content with company HR/legal/data-protection reviewers.
- `NotifyOnWorkOrderPaused`, `NotifyOnWorkOrderCompleted`, and `NotifyOnMachineBreakdown`: destinations require `production.work_orders.view` or `mrp.machines.view`; PR #288 filters configured audiences through those effective permissions. API reads and revoked-user exclusions are covered by focused tests.
- `NotifyOnSalesOrderConfirmed`: default `ppc_head` and `production_manager` audience roles lack `crm.sales_orders.view`, required by the linked detail route. Adding that permission would expose customer/order details, so EF-075 remains open for an access-scope decision; current delivery behavior is unchanged.
- `NotifyOnLeaveApproved` and `NotifyOnLeaveRejected`: each targets the request owner's `employee.user` and links to the existing `/self-service/leaves/{hash}` page. The SPA requires `leave.view`; the API additionally restricts detail reads to the employee, an eligible department head in the same department, or HR. The outbox records the model version and refuses dispatch after a later mutation, avoiding stale status mail. `LeaveRequestService::cancel()` emits no event or notification; added a missing-email recommendation for cancellation of a pending approval.

## Remaining audit work

- Resolve each audience expression to role, permission, owner, approver, or external-contact rules; compare each recipient to route and record visibility.
- Verify all static and helper-built links against SPA routes and in-app return-to-login behavior.
- Check payload fields for sensitive data and confirm each source is the triggering event snapshot.
- Settings-configured role audiences, delegation behavior, dynamic links, route record scoping, payload sensitivity, and event snapshot semantics still need per-caller verification. Record exceptions and split functional fixes into independent PRs. Until that review completes, E-023 caller coverage remains partial.
- `NotifyOnOvertimeSubmitted`: the configured department-head audience was already scoped to the requester's department, matching `OvertimeController::show()` row access. It did not honor an individual `attendance.ot.approve` revocation; draft PR #290 filters on effective permission and tests the revoked-user case. `NotifyOnOvertimeDecided` targets only the employee who owns the request and links to the authenticated self-service route, whose API scopes the record to that employee.
- `ApprovalService::notifyActionRequired`: role members and active delegates were selected without checking their exact record-view permission or per-record scope. Draft PR #291 adds that view permission to `ApprovalTypeRegistry` and applies `ApprovalSourceScope` to scoped records. A regression test confirms a department-head role member with RMA view permission revoked gets no action-required notice even while their approval permission remains; eligible role member still does.
- `NotifyOnLoanSubmitted`: configured finance roles were not checked against the linked detail route. Draft PR #291 filters by effective `loans.view` and `LoanAccessPolicy::canView()` so permission revocations and row scope match the API destination. `NotifyOnLoanDecided` targets only the linked employee and uses the authenticated self-service route, whose API scopes the result to that employee.
- `PayrollPeriodService::notifyPendingApproval`: direct approvers were permission-filtered, but eligible `payroll.periods.approve` delegates were omitted from the notification audience. Draft PR #291 reuses `ApprovalDelegation::approvalAudience()`; period-view filtering, maker exclusion, and system-admin fallback remain. Focused test confirms the delegated HR checker receives the notice.
