# E-023 generic notification caller audit

## Scope and status

Static repository search found 84 exact `->send(` call sites under `api/app`. Eighty-two are NotificationService generic email-capable calls. Excluded false positives: the auth forgot-password service call and direct payslip `Mail::send()`. The inventory below preserves every generic call site as an audit anchor.

`NotificationService::send()` creates an email only when that active internal user explicitly opted into the exact type. PR #256 rejects invalid addresses, excludes inactive users, deduplicates normalized mailboxes, and applies stable delivery keys when a caller supplies a dedupe key. The caller still controls audience selection; this audit is incomplete until every audience is checked against its SPA route guard and record-level API authorization.

The customer delivery-date proposal is a direct `UserNotificationMail` path outside `NotificationService::send()`. PR #256 queues one message per valid active customer portal address and alerts CRM permission holders on delivery failure.

Route declarations are in `spa/src/routes/*.tsx`; approval links resolve through `ApprovalTypeRegistry`. Extracted route presence alone does not prove recipient visibility. Dynamic helper paths, null links, role-setting audiences, data fields, and trigger conditions remain to be verified per caller.

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
| `api/app/Modules/Quality/Services/NcrService.php:1277` | `$this->notifications->send($recipients, 'ncr.return_to_supplier', [` |
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

## Remaining audit work

- Resolve each audience expression to role, permission, owner, approver, or external-contact rules; compare each recipient to route and record visibility.
- Verify all static and helper-built links against SPA routes and in-app return-to-login behavior.
- Check payload fields for sensitive data and confirm each source is the triggering event snapshot.
- Record exceptions and split functional fixes into independent PRs. Until complete, E-023 caller coverage and recipient RBAC remain unverified.
