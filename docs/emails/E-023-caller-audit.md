# E-023 generic notification caller audit

## Scope and status

Static repository search found 85 exact `->send(` call sites under `api/app`. Eighty-three are NotificationService generic email-capable calls. Excluded false positives: the auth forgot-password service call and direct payslip `Mail::send()`. The inventory below preserves every generic call site as an audit anchor.

`NotificationService::send()` creates an email only when that active internal user explicitly opted into the exact type. PR #256 rejects invalid addresses, excludes inactive users, deduplicates normalized mailboxes, and applies stable delivery keys when a caller supplies a dedupe key. The caller still controls audience selection; this audit is incomplete until every audience is checked against its SPA route guard and record-level API authorization.

The customer delivery-date proposal is a direct `UserNotificationMail` path outside `NotificationService::send()`. PR #256 queues one message per valid active customer portal address and alerts CRM permission holders on delivery failure.

Route declarations are in `spa/src/routes/*.tsx`; approval links resolve through `ApprovalTypeRegistry`. Extracted route presence alone does not prove recipient visibility. Dynamic helper paths, null links, role-setting audiences, data fields, and trigger conditions remain to be verified per caller.

## Deep-link defects found and fixed

- Budget approval and outcome links now use `/budgeting/{hash}`, matching the SPA detail route. The API permits `budgeting.view` or an approver eligible for that exact submitted budget; rejection controls also follow record-level `can_approve`.
- Budget-transfer links already used `/budgeting/transfers/{hash}` but lacked a SPA detail route. The added page loads that exact transfer. API detail requires global view access or eligibility for that record; decision controls require effective approval permission and `can_approve`.
- Overtime and loan decision links now resolve to `/:id` self-service routes. Their API reads fetch that one hash within the signed-in employee's scope and return 404 for another employee's record. They bypass history-list limits without widening visibility.
- Authenticated return behavior was source-checked: `AuthGuard` stores the requested path, query, and hash; the login page restores it. Browser login was not exercised.
- Focused checks: 31 API tests / 177 assertions and 9 SPA tests passed; SPA typecheck, targeted ESLint, and PHPStan passed. No email sent.

These changes fix the route families found by static search. They do not complete the 83-call-site audience, payload-sensitivity, snapshot, or trigger audit below.

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
| `api/app/Modules/HR/Services/TrainingExpiryService.php:186` | `$this->notifications->send($employeeRecipients, 'training.expiry', $payload)` — employee self-notice; no HR detail link. |
| `api/app/Modules/HR/Services/TrainingExpiryService.php:190` | `$this->notifications->send($staffRecipients, 'training.expiry', $payload)` — configured staff audience; employee detail link after access filtering. |
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

### Follow-up fixes

- EF-090 / training expiry: `TrainingExpiryService` now keeps employee-self alerts separate from configured staff recipients. Self alerts have no HR-only employee-detail link. Staff recipients must hold effective `hr.employees.view` and `hr.employees.trainings.view` and pass `EmployeeCompetenceScope` for the employee record. Draft PR #301 adds regression coverage for revoked access and the self-notice link.
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

- Maintenance assignment, repair submission, and repair rejection notices: assignment targets the current assignee and routes employee accounts through the assigned-work page; rejected-repair notices target the recorded submitter. Repair-submission recipients ignored individual verifier and page-access revocations; EF-108 filters both permissions. Assignment candidates can still lack the linked route permission after an individual revocation; recorded separately as EF-109.
- `ArDunningService` internal escalation and delivery-failure notices resolve active users through effective `accounting.invoices.view` and link to the SPA invoice list, which has the same permission guard. Customer dunning sends one queued, after-commit reminder per invoice/tier to the customer's address captured at scan time; `InvoiceDunningMail` snapshots display values. Highest-tier internal notices can also scale with invoice count. Customer-level aggregation remains deferred under EF-012 pending product direction.
- Manual prebill approval notices used `accounting.invoices.prebill_approve` but linked to invoice detail, which requires `accounting.invoices.view`; direct approval holders with a view revocation could not open it. The audience now also checks effective invoice-view access. `InvoicePrebillApprovalTest` covers the revocation. Draft PR pending.
- Budget, budget-transfer, prebill, and bill-payment outcomes target the record requester. Their detail routes require the matching effective view permission; requesters with that permission revoked still receive the brief outcome but get no broken detail link. Regression coverage spans all four paths. Draft PR pending.
- `PayrollAdjustmentService`: direct `payroll.adjustments.approve` users could receive an action link without `payroll.adjustments.view`; requester outcomes could retain that link after view access was revoked. The action audience now checks effective view access; requester notices remain generic and omit the link when needed. `PayrollAdjustmentStaleRejectRaceTest` covers both. Approval/outcome messages contain no adjustment amount or reason; notification writes remain after-commit and deduplicated.
- Return-request and return-case alerts now use effective permission resolution and require `return_management.view` for their linked record. Customer-created alerts additionally retain `return_management.manage`; restock and supplier-ship alerts retain their existing inventory/purchasing permission. Regression cases cover view revocation in all four call paths.
- Auto-PO conversion handoff retains configured purchasing roles but now filters out users lacking effective `purchasing.view` or row access under `PurchaseRequestAccessPolicy`, matching the detail route. `ConsolidatePurchaseOrdersTest` covers a per-user view revocation.
- Customer-portal order review alert targets effective `crm.sales_orders.confirm` holders; it now also checks the `crm.sales_orders.view` permission required by the linked CRM order page. `CustomerPortalOrderTest` covers a view revocation.
- Stock-adjustment outcomes still notify the requester after access is revoked, but now omit the list link unless effective `inventory.adjust` remains. `StockAdjustmentReasonTest` covers this case.
- Supplier-response internal alerts require effective `purchasing.po.responses.resolve`, `purchasing.view`, and exact `PurchaseOrderAccessPolicy` visibility; the decision email uses event-time PO/response snapshots and individually validated active supplier addresses with vendor-email fallback. Existing tests cover snapshots, recipient filtering, and the portal route.
- Customer-response internal alerts retain effective `crm.sales_orders.confirm` targeting and now also require `crm.sales_orders.view` for their CRM link. `SalesOrderResponseTest` covers a view revocation.
- Adjacent channel check: truck-return receipt uses `sendInApp()`, so it is not an email path. Its role-table audience ignores effective permission overrides and does not require the linked RMA's `return_management.view`; tracked separately as EF-101.
- Recruitment new-application alerts use configured active HR roles filtered by effective `hr.recruitment.view`, matching the SPA/API detail route. Payload includes candidate name and posting title only. Hourly recruitment bottleneck notices use the same resolver but call `sendInApp()`, so they are not email. Candidate data handling needs HR/legal/data-protection review.
- `NotifyOnLeaveSubmitted`: the configured `department_head` role audience is filtered to the requester's department. This matches `/hr/leaves/{id}` route permission and `LeaveRequestController::authorizeView()` row scope. The employees schema requires `department_id`; an attempted null-department case was rejected by PostgreSQL, so it is not a valid runtime state. Regression coverage verifies matching-department heads receive the notice while other-department and unlinked heads do not. Remaining leave approval/completion callers still require their own audit.
- `NotifyOnLeaveSubmitted` and `NotifyOnLeavePendingHR`: configured role membership alone did not honor individual permission revocations. Draft PR #281 now checks effective `leave.approve_dept` / `leave.approve_hr` permission before dispatch; tests ensure revoked users receive neither inbox notification nor email-eligible audience membership.
- `NcrService::notifyPurchasing` and supplier-defect in-app notice: role membership alone did not enforce the permission required to open the linked RMA. PR #283 filters by effective `return_management.view`; the NCR notice now links to the exact created RMA. A regression test confirms purchasing can read that RMA while lacking NCR view permission.
- Auto-created NCR, recurrence, escalation, and effectiveness notices: configured QC/production/executive audiences and direct CAPA owners linked to `/quality/ncrs/{hash}`. Their role defaults include `quality.ncr.view`, but individual revocations were ignored; action owners could be any active account. PR #284 filters each recipient by effective NCR-view permission and limits owner selection/validation to users with that permission. Existing user-level grants remain honored by `User::hasPermission()`.
- Incoming/in-process/outgoing QC and delivery-drafted notices: configured role membership alone ignored effective permission revocations. Multi-batch outgoing QC sent inspectors to a production work-order page they cannot access; PR #285 links to the outgoing-inspection list filtered by exact work order and stage. Draft-delivery notice now opens its created delivery. Recipients are filtered by `inventory.view`, `quality.inspections.view`, or `supply_chain.deliveries.view` as required by their link.
- `NotifyEmployeesOnPayrollFinalized`: users linked to employees receive only a generic period label and `/self-service/payslips` link; the SPA page is permission-gated and its API scopes rows to the authenticated employee. PR #286 now excludes recipients without effective `payroll.view`; the failed-payroll and voided-period checks remain intact.
- `NotifyOnSeparationInitiated`: configured finance recipients had `hr.separation.view` but not `hr.employees.view`; the previous employee-detail link was forbidden. PR #287 links to the clearance detail route and filters effective `hr.separation.view`. The message includes the employee full name, so confirm this personal-data content with company HR/legal/data-protection reviewers.
- `NotifyOnWorkOrderPaused`, `NotifyOnWorkOrderCompleted`, and `NotifyOnMachineBreakdown`: destinations require `production.work_orders.view` or `mrp.machines.view`; PR #288 filters configured audiences through those effective permissions. API reads and revoked-user exclusions are covered by focused tests.
- `NotifyOnSalesOrderConfirmed`: default PPC/production roles lack `crm.sales_orders.view`. Draft PR #302 preserves configured recipients who hold effective `production.schedule.view`, removes sales-order/customer identifiers from the shared notice, and links to `/production/schedule`; no CRM read access is added. Tests cover the route, redacted payload, and permission revocation.
- `NotifyOnLeaveApproved` and `NotifyOnLeaveRejected`: each targets the request owner's `employee.user` and links to existing `/self-service/leaves/{hash}`. Employee self-service grants `leave.view`; API detail access also enforces owner/eligible department-head/HR scope. Outbox version pin rejects stale status notices. Draft PR #303 fixes ambiguous start-only dates by showing full date range and year; `LeaveNotificationTest` covers both payloads and owner links. `LeaveRequestService::cancel()` still emits no event/notification; cancellation of a pending approval remains a missing-email recommendation.
- `Complaint8dEscalationService`: role-configured recipients and the direct assignee receive complaint number, SLA tier, and `/crm/complaints/{hash}`. The SPA/API route requires `crm.complaints.view`; draft PR #304 now filters both groups by effective permission. Regression coverage revokes the assignee's view permission and confirms they receive no notification.
- `DeliveryService::notifyAutoInvoiceFailure()` and `NotifyFinanceOnDeliveryConfirmed`: invoice creators previously received a delivery link/number; invoice detail recipients were not filtered by invoice-view permission. Draft PR #305 sends manual handoffs to `/accounting/invoices/create` with no delivery identifiers, and requires effective `accounting.invoices.view` for existing-invoice detail notices. `AutoInvoiceOnDeliveryConfirmTest` covers the manual path and redaction.
- `mrp:run-daily`: configured roles received run counts and `/mrp/plans` without effective plan visibility; the unused run entity ID had no detail route. Draft PR #306 filters by `mrp.plans.view` and removes that identifier. Regression coverage excludes a revoked PPC user.
- `attendance:notify-missing-timeout`: HR and same-department head summaries linked to `/hr/attendance`, but role resolution ignored the page's effective permissions and the API row scope. Draft PR #307 requires attendance view plus HR-wide edit/import or department-head approval access; tests cover revoked recipients.
- `MaintenancePartRequestService` part-request alert: audience resolution used `inventory.issue.create`, while `/inventory/picking` requires `inventory.picking.view`. Draft PR #308 requires both effective permissions; a regression test covers an issue creator without picking access.

## Remaining audit work

- Resolve each audience expression to role, permission, owner, approver, or external-contact rules; compare each recipient to route and record visibility.
- Verify all static and helper-built links against SPA routes and in-app return-to-login behavior.
- Check payload fields for sensitive data and confirm each source is the triggering event snapshot.
- Settings-configured role audiences, delegation behavior, dynamic links, route record scoping, payload sensitivity, and event snapshot semantics still need per-caller verification. Record exceptions and split functional fixes into independent PRs. Until that review completes, E-023 caller coverage remains partial.
- `NotifyOnOvertimeSubmitted`: the configured department-head audience was already scoped to the requester's department, matching `OvertimeController::show()` row access. It did not honor an individual `attendance.ot.approve` revocation; draft PR #290 filters on effective permission and tests the revoked-user case. `NotifyOnOvertimeDecided` targets only the employee who owns the request and links to the authenticated self-service route, whose API scopes the record to that employee.
- `ApprovalService::notifyActionRequired`: role members and active delegates were selected without checking their exact record-view permission or per-record scope. Draft PR #291 adds that view permission to `ApprovalTypeRegistry` and applies `ApprovalSourceScope` to scoped records. A regression test confirms a department-head role member with RMA view permission revoked gets no action-required notice even while their approval permission remains; eligible role member still does.
- `NotifyOnLoanSubmitted`: configured finance roles were not checked against the linked detail route. Draft PR #291 filters by effective `loans.view` and `LoanAccessPolicy::canView()` so permission revocations and row scope match the API destination. `NotifyOnLoanDecided` targets only the linked employee and uses the authenticated self-service route, whose API scopes the result to that employee.
- `PayrollPeriodService::notifyPendingApproval`: direct approvers were permission-filtered, but eligible `payroll.periods.approve` delegates were omitted from the notification audience. Draft PR #291 reuses `ApprovalDelegation::approvalAudience()`; period-view filtering, maker exclusion, and system-admin fallback remain. Focused test confirms the delegated HR checker receives the notice.
- `ApprovalEscalationService::runReminders()`, `runEscalations()`, and auto-escalation: current approver and superior recipients were selected by role/assignment without route-read or record-scope checks. Draft PR #291 adds shared `ApprovalTypeRegistry::userCanViewRecord()` filtering; reminder/escalation timing and role resolution stay unchanged. `ApprovalEscalationVisibilityTest` covers a revoked-view department head.
- `NotifyOnPurchaseRequestApproved`, `NotifyOnPurchaseOrderApproved`, and `NotifyOnPurchaseOrderSubmitted`: approved notices used configured roles without exact route-read or row-visibility checks; pending notices queried role permission rows directly. Draft PR #292 checks effective `purchasing.view`, the matching `PurchaseRequestAccessPolicy` / `PurchaseOrderAccessPolicy`, and effective `purchasing.po.approve` while preserving current-role targeting. Focused tests cover individual permission revocation.
- `NotifyOnLowStockPrCreated` linked warehouse recipients to a purchase-request page gated by `purchasing.view` and included its number. Draft PR #293 links to the item page, removes the inaccessible PR reference, and filters recipients by effective `inventory.view`. `NotifyOnGrnReceived` now applies the same effective view check for its GRN route.
- `SupplierListingService::notifyReviewers()` used the review permission to choose recipients, but direct reviewers with a view-permission revocation could not open `/purchasing/supplier-listings`. Draft PR #294 applies the page's effective view permission to the resolved audience.
- `maintenance:check-repeat-breakdowns` sent machine details to every active maintenance supervisor, ignoring revocation of the downtime route's `maintenance.view` permission. Draft PR #295 filters this audience by effective permission.
- `NotifyMaintenanceWorkOrder` and `maintenance:check-assignment` also selected recipients by role alone for work-order links. Draft PR #296 filters creation, critical-escalation, and handover audiences by effective `maintenance.view`.
- `CustomApprovalEscalationService` checked approval actions and row scope but not the route's read permission for direct approvers; budget-transfer alerts linked approval-only users to a view-only list. Draft PR #297 checks each route's read alternatives and sends transfers to their exact approval-capable detail route.
- `AutoPurchaseOrderService::notifyApprovers()` selected the next role but did not verify effective PO approval permission, page access, or `PurchaseOrderAccessPolicy` visibility. Draft PR #298 mirrors the submitted-PO notice's checks.
- `InspectionService::notifyReviewers()` checked `quality.inspections.review` but ignored the `quality.inspections.view` permission required by its SPA detail link. Draft PR #299 filters the resolved reviewers by effective view access while preserving maker exclusion.
- `AlertOnSupplierDeterioration` sent supplier score details to configured roles without checking `purchasing.suppliers.performance.view`, required by its linked page. Draft PR #300 applies the effective destination permission.
- Training-expiry notices in PR #301 now filter staff by the effective route permissions and `EmployeeCompetenceScope`; employee-self notices omit the HR-only link.
- Sales-order confirmations in PR #302 retain configured recipients who can view the production schedule, remove customer/order identifiers, and link to `/production/schedule`.
- Leave outcomes in PR #303 show the full date range and year; the event-version check prevents stale status notices. Pending-request cancellation still has no notification.
- Complaint SLA alerts in PR #304 filter both configured roles and assignees by effective `crm.complaints.view`.
- Finance delivery notices in PR #305 require effective invoice-view access for invoice details. Auto-invoice failure handoffs use the invoice-creation page without exposing delivery identifiers.
- Daily MRP notices in PR #306 require effective `mrp.plans.view` and omit the unused run identifier.
- Missing-timeout notices in PR #307 require attendance view plus the HR-wide or same-department read scope used by the destination API.
- Maintenance part-request notices in PR #308 require both issue-creation and picking-view permissions, matching the linked page.
- Prebill approval notices in PR #309 require effective invoice-view access in addition to approval permission.
- PR #310 covers financial outcomes, payroll adjustment, return management, auto-PO handoff, customer portal review, stock adjustment, and supplier/customer response notices. Recipients now need effective destination access; record-scoped links use the matching access policy. The CI-discovered missing `User` import in customer portal review was fixed in commit `3ca66cc9`.
