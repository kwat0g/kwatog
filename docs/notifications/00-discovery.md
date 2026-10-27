# Notification and badge discovery

**Audit date:** 2026-10-25
**Scope:** static source and configuration review across `api/`, `spa/`, migrations, tests, schedulers, and the running local Compose database. No notification or badge code changed.

## Evidence and limits

- Searched all PHP application, route, and console sources for Laravel notifications, `NotificationService` calls, notification-like service wrappers, event dispatch, and `ShouldBroadcast` classes. Cross-checked event listeners in `AppServiceProvider`, scheduled commands, notification catalogs, SPA routes, and frontend subscriptions.
- Read the live local PostgreSQL database using aggregate-only queries. No names, message bodies, or individual user rows were read. The database is the development Compose database (`ogami`), not evidence of production behavior.
- Static review is complete for dispatch and navigation source inventory below. No test suite, browser scenario, or seeded-user action was run in this phase. Existing focused tests are listed below as coverage evidence, not as passing results from this audit.
- Runtime recipient and badge parity remain unverified. Several source selectors depend on role grants, user overrides, feature settings, department scopes, configured recipient roles, and current record ownership. Those need isolated seeded scenarios after policy review.

## Project and module map

The API contains 23 business/system modules: Accounting, Admin, Assets, Attendance, Auth, B2B, CRM, Dashboard, DSS, Forecasting, HR, Inventory, Landing, Leave, Loans, Maintenance, MRP, Payroll, Production, Purchasing, Quality, ReturnManagement, and SupplyChain. `Common` supplies notifications, approvals, alerts, outbox, mail, and shared controllers. The SPA has internal ERP, supplier portal, customer portal, employee self-service, factory-floor, and maintenance mobile navigation shells.

## Notification pipeline

### In-app delivery and persistence

- `api/app/Common/Services/NotificationService.php` is the principal dispatcher. `send()` inserts rows into Laravel's `notifications` table, publishes `UserNotificationCreated` after commit, and queues email only when that user's per-type email preference is enabled. `sendInApp()` suppresses email. `sendPortalInApp()` writes rows for active customer/supplier portal users only; it does not broadcast or use internal preferences.
- The internal envelope is a JSON `data` blob with caller-provided `title`, `message`, `link_to`, and optional `entity_type` / `entity_id`. There is no event/subject schema, template rendering, or central link builder. The service clamps title/message lengths and normalizes malformed values, but it does not check record visibility, exclude the actor, supply fallback recipients, or store a recipient-selection reason.
- Recipients are deduplicated by internal user ID in `send()`; portal recipients are deduplicated by class and ID. `dedupe_key` is optional. A unique index prevents a second row for the same notifiable and non-null key; duplicate delivery is ignored, not merged into the existing row. Most call sites do not pass a key. `sendDssRecommendationInApp()` has a daily cap and stable dedupe key but has no caller in the current tree; DSS notification publishing is not active.
- `NotificationService::notify()` is a legacy Laravel-notification wrapper. No external call to this wrapper was found. It applies in-app preferences, then calls `$user->notify()`; direct Laravel notification dispatches elsewhere bypass this wrapper.
- `notifications` rows use `type`, polymorphic `notifiable_*`, JSON `data`, `read_at`, timestamps, and optional `dedupe_key`. Legacy fully qualified Laravel class names still have a frontend fallback renderer.
- One custom notification-like table also exists: `ncr_effectiveness_notifications`. It supports quality corrective-action reminder lifecycle; it is distinct from Laravel's `notifications` table.

### Direct notification and email paths

- Direct `$user->notify()` sites send mail-only welcome, password-reset, and employee account messages: `Admin/UserAdminController.php:87`, `Auth/PasswordResetService.php:40`, and `HR/UserProvisioningService.php:79,170`. The corresponding `via()` methods return `['mail']`.
- Direct `Notification::send()` sites send mail-only daily/weekly production summaries and critical alert email: `Console/Commands/SendDailyProductionSummary.php:68`, `SendWeeklyProductionSummary.php:66`, and `Common/Services/AlertEngineService.php:832`. Their notification classes return `['mail']`. Laravel notification classes found: `WelcomeNotification`, `PasswordResetNotification`, `PasswordResetLinkNotification`, `EmployeeWelcomeNotification` and `EmployeePasswordResetNotification` (mail channels); `DailyProductionSummary`, `WeeklyProductionSummary`, and `CriticalAlertEmail` (mail only). No notification class with `toDatabase()` was found; in-app rows are inserted by `NotificationService`.
- `EmailDeliveryFailureNotifier` converts selected external mail failures to in-app `email.delivery_failed` notices. Callers include recruitment, supplier response/dispatch, scheduled exports, production summaries, critical alerts, and accounting/dunning flows. Selection is explicit user IDs or users with a permission; the wrapper does not check subject visibility.
- Payroll also has a direct `Mail::to(...)->send(new PayslipMail(...))` job path, separate from the `chain.payslip_ready` in-app notice.
- `notifications:send-digest` runs daily at 07:05. Digest is opt-in via `notification_preferences` type `*`, channel `digest`. `notifications:prune --days=90` removes read rows older than 90 days. Unread rows have no expiry policy in the inspected code.

### Preferences, list, read, and delete endpoints

`api/app/Modules/Auth/routes.php` exposes authenticated `GET /api/v1/notifications/`, `PATCH /api/v1/notifications/{id}/read`, `PATCH /api/v1/notifications/read-all`, `DELETE /api/v1/notifications/clear-read`, and `DELETE /api/v1/notifications/{id}`; all require `notifications.view`. The list endpoint returns a paginated page plus that user's unread count in response metadata; no separate unread-count endpoint exists. Preference routes are `GET /api/v1/notification-preferences/`, `GET /api/v1/notification-preferences/options`, and `PUT /api/v1/notification-preferences/` with `notifications.preferences.manage`. `UserNotificationService` scopes every query/mutation to the authenticated internal user and returns 404 for another user's row. There is no mark-unread route, archive/resolved/snooze lifecycle, or user-specific retention setting. Supplier and customer portals use `GET /api/v1/b2b/{supplier|customer}/notifications`, `PATCH .../notifications/read-all`, `PATCH .../notifications/{id}/read`, `DELETE .../notifications/clear-read`, and `DELETE .../notifications/{id}`. `PortalNotificationController` scopes rows by guarded portal user morph type and ID; B2B tenancy middleware also applies. Portal has no notification preferences or realtime event channel.

Preferences are per notification type and channel (`in_app`, `email`, `digest`), not category. In-app defaults on; email defaults off. Digest uses the global `*` row. `NotificationCatalog` combines an editable settings snapshot with defaults, but types absent from both the catalog and frontend metadata cannot be muted or rendered with their intended category.

### Realtime and authorization

- The SPA uses lazy Laravel Echo/Reverb through `spa/src/lib/echo.ts`, with Sanctum cookie and CSRF authorization at `/api/v1/broadcasting/auth`. Notifications subscribe to `private-user.{hash_id}`; the channel compares the authenticated user ID with the decoded channel ID. A negative cross-user test exists.
- `UserNotificationCreated` broadcasts the whole notification envelope, including `title`, `message`, `link_to`, and any extra data, to that per-user private channel. Channel isolation is good; payload is not minimal.
- `private-badges` is authorized for every authenticated internal user and broadcasts no record data. Clients refetch their own permission-filtered badge response. This is not a public or role-wide data channel.
- Other private channels include production dashboard/work order/machine, payroll period, maintenance dashboard, and chain entity channels. Several channel callbacks check only a permission slug and do not load the specific record to enforce its record-level scope. The payroll period event carries run progress/headcount metadata; subject-scope parity needs verification before treating permission-only authorization as sufficient.
- No public broadcast channel was found. `BroadcastAuthorizationTest` covers own-user subscription allowed and cross-user denied. It does not cover unauthenticated channel auth or each subject channel's record scope.

### SPA notification experience

- `spa/src/components/layout/NotificationBell.tsx` polls the list every 60 seconds, displays eight newest notifications, and uses Reverb for realtime toast plus cache invalidation. Every received notification triggers a toast; no urgency filter or dedupe is applied at the toast layer. No explicit reconnect handler fetches missed items; 60-second polling is the fallback. Portal shells reuse the bell with portal APIs and realtime disabled.
- The notification center at `/notifications` supports All/Unread/Approvals/Alerts/System, date grouping, load-more paging, mark-read, mark-all-read, and delete. Group filters are client-side over loaded pages. No search, module/date filter, preferences panel, mute, snooze, or resolved state was found.
- Bell and list row click targets are `<button>` elements that call `navigate(data.link_to)`, not anchors. Middle-click/open-new-tab does not work. A missing or portal-prefix-rejected `link_to` leaves the click without a destination. Routes are hand-authored at dispatch sites; no central route registry exists.
- The SPA classifies exact internal types in `spa/src/lib/notificationMeta.ts`, has a separate portal-type map, and falls back to class-name patterns or a generic System bell. Legacy rows retain a renderer. Relative times and date buckets use browser-local time; date buckets are not explicitly Asia/Manila.

## Notification dispatch source index

The source index below is generated from the `NotificationService` call scan. Each `path:line` is a call site, including multiple calls in one file. Exact audience construction is in the cited caller/helper; no central resolver wraps these calls. Direct Laravel notifications, mail paths, wrapper-triggered mail failures, observer/listener triggers, and scheduler paths are listed separately above and in the event/trigger section of `01-inventory.md`.

**Call-site count:** 129.

| Source call site |
| --- |
| `api/app/Common/Listeners/NotifySystemAdminsOnQueueLongWait.php:32` |
| `api/app/Console/Commands/SweepMissingOutgoingQc.php:201` |
| `api/app/Modules/CRM/Services/ProductService.php:337` |
| `api/app/Modules/CRM/Services/ProductService.php:371` |
| `api/app/Console/Commands/RunDailyMrp.php:50` |
| `api/app/Modules/CRM/Services/SalesOrderResponseService.php:84` |
| `api/app/Modules/CRM/Services/SalesOrderResponseService.php:185` |
| `api/app/Modules/CRM/Services/SalesOrderResponseService.php:228` |
| `api/app/Modules/CRM/Services/SalesOrderResponseService.php:508` |
| `api/app/Modules/CRM/Services/SalesOrderResponseService.php:536` |
| `api/app/Modules/CRM/Services/Complaint8dEscalationService.php:271` |
| `api/app/Console/Commands/NotifyMissingTimeOut.php:63` |
| `api/app/Console/Commands/NotifyMissingTimeOut.php:98` |
| `api/app/Console/Commands/CheckMaintenanceRepeatBreakdowns.php:36` |
| `api/app/Console/Commands/CheckMaintenanceAssignment.php:48` |
| `api/app/Console/Commands/CheckMaintenanceAssignment.php:71` |
| `api/app/Console/Commands/CheckRecruitmentBottlenecks.php:182` |
| `api/app/Modules/CRM/Services/SalesOrderService.php:1118` |
| `api/app/Console/Commands/NotifyUpcomingWorkOrders.php:49` |
| `api/app/Console/Commands/NotifyUpcomingWorkOrders.php:71` |
| `api/app/Console/Commands/NotifyUpcomingWorkOrders.php:78` |
| `api/app/Modules/CRM/Services/PricingReviewService.php:196` |
| `api/app/Modules/CRM/Services/PriceNegotiationService.php:287` |
| `api/app/Modules/CRM/Services/PriceNegotiationService.php:289` |
| `api/app/Common/Services/CustomApprovalEscalationService.php:97` |
| `api/app/Common/Services/EmailDeliveryFailureNotifier.php:37` |
| `api/app/Common/Services/ApprovalService.php:367` |
| `api/app/Common/Services/ApprovalService.php:392` |
| `api/app/Common/Services/ApprovalEscalationService.php:86` |
| `api/app/Common/Services/ApprovalEscalationService.php:137` |
| `api/app/Common/Services/ApprovalEscalationService.php:313` |
| `api/app/Common/Services/AlertEngineService.php:485` |
| `api/app/Common/Services/AlertEngineService.php:491` |
| `api/app/Modules/SupplyChain/Services/DeliveryService.php:471` |
| `api/app/Modules/SupplyChain/Services/DeliveryService.php:1239` |
| `api/app/Modules/SupplyChain/Services/DeliveryService.php:2195` |
| `api/app/Modules/CRM/Listeners/NotifyOnSalesOrderConfirmed.php:40` |
| `api/app/Modules/SupplyChain/Services/DeliveryAttemptOutcomeService.php:562` |
| `api/app/Modules/B2B/Services/CustomerPortalService.php:302` |
| `api/app/Modules/B2B/Services/CustomerPortalService.php:325` |
| `api/app/Modules/Admin/Services/UserPermissionOverrideService.php:167` |
| `api/app/Modules/Admin/Services/UserPermissionOverrideService.php:225` |
| `api/app/Modules/Admin/Services/UserPermissionOverrideService.php:288` |
| `api/app/Modules/B2B/Services/SupplierPortalDispatchGateway.php:40` |
| `api/app/Modules/B2B/Services/SupplierDeliveryScheduleService.php:237` |
| `api/app/Modules/ReturnManagement/Services/ReturnCaseService.php:269` |
| `api/app/Modules/ReturnManagement/Services/ReturnCaseService.php:617` |
| `api/app/Modules/ReturnManagement/Services/ReturnCaseService.php:658` |
| `api/app/Modules/B2B/Services/SupplierShipmentService.php:273` |
| `api/app/Modules/B2B/Services/DeliveryScheduleReviewService.php:109` |
| `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:328` |
| `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:658` |
| `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:844` |
| `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:1875` |
| `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:3305` |
| `api/app/Modules/Purchasing/Services/SupplierResponseService.php:281` |
| `api/app/Modules/Purchasing/Services/SupplierResponseService.php:461` |
| `api/app/Modules/Purchasing/Services/SupplierResponseService.php:500` |
| `api/app/Modules/Purchasing/Services/SupplierListingService.php:350` |
| `api/app/Modules/Purchasing/Services/SupplierDispatchService.php:285` |
| `api/app/Modules/Purchasing/Services/AutoPurchaseOrderService.php:209` |
| `api/app/Modules/Purchasing/Listeners/NotifyOnPurchaseRequestApproved.php:33` |
| `api/app/Modules/Purchasing/Listeners/NotifyOnPurchaseOrderSubmitted.php:32` |
| `api/app/Modules/Accounting/Services/BillService.php:1070` |
| `api/app/Modules/Purchasing/Listeners/NotifyOnPurchaseOrderApproved.php:33` |
| `api/app/Modules/Purchasing/Listeners/DeliverRfqLifecycleNotification.php:81` |
| `api/app/Modules/Accounting/Services/InvoiceService.php:171` |
| `api/app/Modules/Accounting/Services/InvoiceService.php:331` |
| `api/app/Modules/Purchasing/Listeners/ConsolidatePurchaseOrders.php:259` |
| `api/app/Modules/Accounting/Services/BudgetTransferService.php:87` |
| `api/app/Modules/Accounting/Services/BudgetTransferService.php:194` |
| `api/app/Modules/Purchasing/Listeners/AlertOnSupplierDeterioration.php:61` |
| `api/app/Modules/Accounting/Services/BudgetService.php:135` |
| `api/app/Modules/Accounting/Services/BudgetService.php:203` |
| `api/app/Modules/Accounting/Services/ArDunningService.php:192` |
| `api/app/Modules/Accounting/Services/InvoicePaymentSubmissionService.php:100` |
| `api/app/Modules/Accounting/Services/InvoicePaymentSubmissionService.php:230` |
| `api/app/Modules/Production/Services/WorkOrderReworkService.php:167` |
| `api/app/Modules/Production/Services/WorkOrderReworkService.php:171` |
| `api/app/Modules/Production/Services/WorkOrderAssignmentService.php:478` |
| `api/app/Modules/Production/Services/WorkOrderAssignmentService.php:484` |
| `api/app/Modules/Production/Services/WorkOrderAssignmentService.php:626` |
| `api/app/Modules/Payroll/Services/PayrollPeriodService.php:1011` |
| `api/app/Modules/Payroll/Services/PayrollAdjustmentService.php:78` |
| `api/app/Modules/Payroll/Services/PayrollAdjustmentService.php:176` |
| `api/app/Modules/Quality/Services/NcrService.php:245` |
| `api/app/Modules/Quality/Services/NcrService.php:407` |
| `api/app/Modules/Quality/Services/NcrService.php:1280` |
| `api/app/Modules/Quality/Services/NcrRecurrenceDetector.php:201` |
| `api/app/Modules/Quality/Services/NcrEscalationService.php:180` |
| `api/app/Modules/Quality/Services/InspectionService.php:2392` |
| `api/app/Modules/Quality/Services/EffectivenessService.php:283` |
| `api/app/Modules/Production/Listeners/NotifyOnWorkOrderCompleted.php:33` |
| `api/app/Modules/Quality/Listeners/TriggerOutgoingQC.php:159` |
| `api/app/Modules/Production/Listeners/NotifyOnWorkOrderPaused.php:55` |
| `api/app/Modules/Quality/Listeners/TriggerIncomingQC.php:174` |
| `api/app/Modules/Quality/Listeners/TriggerInProcessQC.php:154` |
| `api/app/Modules/Production/Listeners/NotifyOnMachineBreakdown.php:39` |
| `api/app/Modules/Quality/Listeners/CreateDeliveryDraftOnQcPass.php:100` |
| `api/app/Modules/Quality/Listeners/NotifyOnInspectionFailed.php:55` |
| `api/app/Modules/Payroll/Listeners/NotifyEmployeesOnPayrollFinalized.php:71` |
| `api/app/Modules/Attendance/Listeners/NotifyOnOvertimeSubmitted.php:38` |
| `api/app/Modules/Attendance/Listeners/NotifyOnOvertimeDecided.php:29` |
| `api/app/Modules/Accounting/Listeners/NotifyFinanceOnDeliveryConfirmed.php:47` |
| `api/app/Modules/Accounting/Listeners/EmailCustomerOnInvoiceFinalized.php:28` |
| `api/app/Modules/Leave/Listeners/NotifyOnLeaveSubmitted.php:40` |
| `api/app/Modules/HR/Listeners/NotifyOnSeparationInitiated.php:34` |
| `api/app/Modules/Leave/Listeners/NotifyOnLeaveRejected.php:26` |
| `api/app/Modules/Leave/Listeners/NotifyOnLeavePendingHR.php:32` |
| `api/app/Modules/Leave/Listeners/NotifyOnLeaveApproved.php:26` |
| `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php:348` |
| `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php:624` |
| `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php:670` |
| `api/app/Modules/Maintenance/Services/MaintenancePartRequestService.php:70` |
| `api/app/Modules/Maintenance/Services/MaintenancePartRequestService.php:127` |
| `api/app/Modules/HR/Services/OnboardingService.php:231` |
| `api/app/Modules/HR/Services/TrainingExpiryService.php:186` |
| `api/app/Modules/HR/Services/TrainingExpiryService.php:190` |
| `api/app/Modules/HR/Services/RecruitmentService.php:717` |
| `api/app/Modules/MRP/Services/MrpAutomationService.php:78` |
| `api/app/Modules/Maintenance/Listeners/NotifyMaintenanceWorkOrder.php:30` |
| `api/app/Modules/Inventory/Services/StockAdjustmentService.php:215` |
| `api/app/Modules/Inventory/Services/StockAdjustmentService.php:379` |
| `api/app/Modules/Inventory/Services/AutoReplenishmentService.php:184` |
| `api/app/Modules/Inventory/Services/ItemCostReviewService.php:95` |
| `api/app/Modules/Loans/Listeners/NotifyOnLoanSubmitted.php:48` |
| `api/app/Modules/Loans/Listeners/NotifyOnLoanDecided.php:39` |
| `api/app/Modules/Inventory/Listeners/NotifyOnLowStockPrCreated.php:36` |
| `api/app/Modules/Inventory/Listeners/NotifyOnGrnReceived.php:33` |

### Transactional/account email dispatch inventory

The following lists every direct `Mail::to(...)` call found under `api/app`. These mails are separate from in-app rows unless an adjacent failure handler creates `email.delivery_failed`. Mailable `build()` content and recipient policy remain in the listed caller; external party addresses are not internal app accounts.

| Source | Recipient expression | Mode | Mailable |
| --- | --- | --- | --- |
| `api/app/Common/Services/NotificationDigestService.php:76` | `trim($user->email)` | `queue` | `NotificationDigestMail` |
| `api/app/Common/Services/NotificationService.php:457` | `$address` | `queue` | `UserNotificationMail` |
| `api/app/Console/Commands/RunDueScheduledExports.php:176` | `$recipient` | `queue` | `ScheduledExportMail` |
| `api/app/Modules/Accounting/Listeners/EmailCustomerOnInvoiceFinalized.php:54` | `$recipient` | `queue` | `CustomerInvoiceFinalizedMail` |
| `api/app/Modules/Accounting/Listeners/EmailCustomerOnOfficialReceiptIssued.php:41` | `$snapshot['customer_email']` | `queue` | `CustomerOfficialReceiptMail` |
| `api/app/Modules/Accounting/Listeners/EmailPartyOnCreditNoteFinalized.php:43` | `$snapshot['party_email']` | `queue` | `PartyCreditNoteMail` |
| `api/app/Modules/Accounting/Services/ArDunningService.php:102` | `$email` | `queue` | `InvoiceDunningMail` |
| `api/app/Modules/B2B/Listeners/EmailSupplierOnInvoiceSubmitted.php:42` | `$snapshot['recipient_email']` | `queue` | `SupplierInvoiceStatusMail` |
| `api/app/Modules/B2B/Listeners/EmailSupplierOnPurchaseOrderCancelled.php:53` | `$snapshot['recipient_email']` | `queue` | `SupplierPurchaseOrderCancelledMail` |
| `api/app/Modules/B2B/Services/PortalInvitationService.php:158` | `$email` | `queue` | `PortalAccessInvitationMail` |
| `api/app/Modules/B2B/Services/PortalPasswordResetService.php:92` | `$recipient->email` | `queue` | `PortalPasswordResetMail` |
| `api/app/Modules/B2B/Services/SupplierPortalDispatchGateway.php:51` | `$vendorEmail` | `queue` | `SupplierPurchaseOrderMail` |
| `api/app/Modules/CRM/Listeners/EmailCustomerOnComplaintUpdated.php:42` | `$snapshot['recipient_email']` | `queue` | `CustomerComplaintUpdateMail` |
| `api/app/Modules/CRM/Listeners/EmailCustomerOnSalesOrderConfirmed.php:51` | `$snapshot['customer_email']` | `queue` | `CustomerSalesOrderConfirmedMail` |
| `api/app/Modules/CRM/Services/SalesOrderResponseService.php:127` | `$address` | `queue` | `dynamic mail` |
| `api/app/Modules/CRM/Services/SalesOrderResponseService.php:577` | `$recipients` | `queue` | `CustomerSalesOrderDecisionMail` |
| `api/app/Modules/HR/Services/RecruitmentService.php:191` | `$application->email` | `queue` | `ApplicationReceivedMail` |
| `api/app/Modules/HR/Services/RecruitmentService.php:598` | `$application->email` | `queue` | `InterviewScheduledMail` |
| `api/app/Modules/HR/Services/RecruitmentService.php:627` | `$application->email` | `queue` | `InterviewDetailsUpdatedMail` |
| `api/app/Modules/HR/Services/RecruitmentService.php:746` | `$application->email` | `queue` | `ApplicationStatusUpdatedMail` |
| `api/app/Modules/Landing/Services/ContactInquiryService.php:59` | `$recipient` | `queue` | `ContactInquiryReceivedMail` |
| `api/app/Modules/Payroll/Jobs/SendPayslipEmailJob.php:96` | `$email` | `send` | `PayslipMail` |
| `api/app/Modules/Purchasing/Listeners/DeliverRfqLifecycleNotification.php:125` | `$email` | `queue` | `SupplierRfqLifecycleMail` |
| `api/app/Modules/Purchasing/Services/SupplierResponseService.php:538` | `$recipient` | `queue` | `SupplierPoDecisionMail` |
| `api/app/Modules/ReturnManagement/Listeners/EmailPartyOnReturnRequestUpdated.php:71` | `$partyEmail` | `queue` | `ReturnRequestUpdateMail` |
| `api/app/Modules/ReturnManagement/Services/ReturnCaseService.php:644` | `$email` | `queue` | `ReturnCaseUpdateMail` |
| `api/app/Modules/SupplyChain/Listeners/EmailCustomerOnDeliveryConfirmed.php:52` | `$customerEmail` | `queue` | `CustomerDeliveryConfirmedMail` |

**Direct `Mail::to` call count:** 27.

### Scheduled and indirect triggers

Cadences below come from `api/routes/console.php`; indirect rows may publish a domain event whose listener is in the 129-site register. “No direct sender” means the scheduled command itself was not found calling `NotificationService`; it may still update a list or trigger another event.

| Command / trigger | Cadence | Notification effect found | Evidence / limit |
| --- | --- | --- | --- |
| `production:notify-upcoming-work-orders` | Every minute | Owner/workers receive assignment-review and schedule-reminder notices. | `NotifyUpcomingWorkOrders.php`; `console.php:25`. |
| `purchasing:close-due-rfqs` | Every minute | RFQ lifecycle listener can notify internal users and supplier contacts. | `console.php:30`; `DeliverRfqLifecycleNotification.php`; caller's recipient snapshot still needs scope review. |
| `grn:retry-pending-incoming-qc --limit=50` | Every 15 minutes | Retry may re-fire incoming-QC handoff/listener. | `console.php:39`; sender is listener-side, dedupe/idempotency must be verified per retry. |
| `qc:sweep-missing-outgoing --limit=50` | Hourly | Sends `chain.outgoing_qc_missing` to escalation audience. | `SweepMissingOutgoingQc.php:201`; hourly/idempotent per command comments. |
| `attendance:notify-missing-timeout` | Daily 07:00 | Sends HR-wide and department-head missing-time-out notices. | `NotifyMissingTimeOut.php:63,98`; `console.php:55`; audience scope is a policy review item. |
| `mrp:run-daily` | Daily 06:00 | Sends MRP completion notice to PPC heads. | `RunDailyMrp.php:50`; `console.php:94`. |
| `alerts:run` | Every 15 minutes | Evaluates alert rules; critical alert delivery/email can be created. | `AlertEngineService`; `console.php:116`; alert-recipient settings and alert list semantics need review. |
| `dss:run` | Hourly | No active notification publisher found; detectors remain shadow. | `console.php:123`; `sendDssRecommendationInApp()` has no caller. |
| `alerts:retry-critical-emails --limit=100` | Every 15 minutes | Retries critical-alert mail only; failure may create `email.delivery_failed`. | `console.php:135`; `AlertEngineService.php:832,855`. |
| `payroll:reconcile-payslip-emails` | Every 15 minutes | Reconciles send jobs; failure path can notify affected employee. | `SendPayslipEmailJob.php:96,107,147`; `console.php:180`; no other employee should see payslip failure. |
| `approvals:run-escalations` | Every 6 hours | Sends approval reminders/escalations and custom approval escalations. | `ApprovalEscalationService.php`, `CustomApprovalEscalationService.php`; `console.php:193`. |
| `ncr:escalate` | Every 15 minutes | Sends NCR escalation notices. | `NcrEscalationService.php:180`; `console.php:199`. |
| `complaints:check-8d-slas` | Every 15 minutes | Sends 8D SLA notices. | `Complaint8dEscalationService.php:271`; `console.php:205`; current audience list needs scenario proof. |
| `ar:run-dunning` | Daily 07:00 | Sends invoice dunning email and internal escalation notice. | `ArDunningService.php:102,192`; `console.php:408`; finance/customer boundaries need review. |
| `production:send-daily-summary` | Daily 18:00 | Sends production summary email to selected internal users. | `SendDailyProductionSummary.php:68`; `console.php:217`. |
| `production:send-weekly-summary` | Fridays 18:00 | Sends production summary email to selected internal users. | `SendWeeklyProductionSummary.php:66`; `console.php:221`. |
| `hr:onboarding-reminders` | Daily 09:00 | Sends HR reminders for stale onboarding. | `OnboardingService.php:231`; `console.php:229`. |
| `recruitment:check-bottlenecks` | Hourly | Sends recruitment bottleneck notices. | `CheckRecruitmentBottlenecks.php:182`; `console.php:245`. |
| `chain:check-bottlenecks` | Hourly | Updates chain bottleneck/action-center state; no direct `NotificationService` call found in the scheduled command. | `console.php:252`; shared action-center destination still needs parity review. |
| `outbox:dispatch --limit=100` | Every minute | Dispatches committed domain outbox events; downstream listeners can notify. | `console.php:260`; indirect trigger path, not a second notification recipient resolver. |
| `exports:run-due` | Every 5 minutes | Sends scheduled export email; mail failure can notify owner. | `RunDueScheduledExports.php:94,176`; `console.php:293`. |
| `notifications:prune --days=90` | Daily 02:30 | Removes read notifications older than 90 days. | `PruneOldNotifications`; `console.php:314`. |
| `training:check-expiries` | Daily 06:30 | Sends 30/14/7/expired employee-training notices. | `TrainingExpiryService.php:186,190`; `console.php:423`; own employee notice currently has no link. |
| `notifications:send-digest` | Daily 07:05 | Sends opt-in per-user unread digest. | `NotificationDigestService`; `console.php:441`. |
| `pricing:review-agreements` | Daily 07:00; dispatch-only every minute | Sends agreement-expiring and pricing-review notices; dispatch-only recovers queued refresh handoffs. | `PricingReviewService.php:172,186,196`; `console.php:486,489`; current role list argument is not used by its audience builder. |
| `maintenance:check-assignment` | Every 5 minutes | Sends assignment escalation / shift handover notices. | `CheckMaintenanceAssignment.php:48,71`; `console.php:491`. |
| `maintenance:check-repeat-breakdowns` | Daily 07:10 | Sends repeated-breakdown notices to active maintenance supervisors. | `CheckMaintenanceRepeatBreakdowns.php:36`; `console.php:493`. |

### Static SPA route-target check

Compared literal `link_to` path fragments from the in-app dispatch register with route declarations under `spa/src/routes/` and `spa/src/App.tsx`. No fixed literal route fragment failed to match a declared route pattern. This was static path matching only; no route was rendered, and 403/404/deleted-record behavior was not exercised. Four calls omit `link_to`; variable/helper-built links and conditional-null links still need source-by-source confirmation. The exhaustive link expression and dedupe expression are recorded in `01-inventory.md`.

## Broadcast and event wiring

The `ShouldBroadcast` inventory includes: `UserNotificationCreated`, `PermissionOverrideChanged`, `ChainStepAdvanced`, `BadgesChanged`, `SalesOrderConfirmed`, `MachineStatusChanged`, `MoldShotLimitNearing`, `MoldShotLimitReached`, `MrpPlanGenerated`, `MaintenanceWorkOrderCreated`, `PayrollProgressEvent`, `MachineBreakdownDetected`, `WorkOrderOutputRecorded`, and `WorkOrderStatusChanged`. Most are state-refresh/domain events, not user notification rows. Notification-related listeners are registered in `api/app/Providers/AppServiceProvider.php`: sales-order confirmation, work-order pause/completion, GRN, purchase approval/submission, inspection failure/QC follow-up, separation, payroll finalization, leave, overtime, loan, machine breakdown, maintenance assignment, and low-stock PR creation.

## Sidebar and navigation discovery

The Phase 0 capture of the internal ERP sidebar is `spa/src/components/layout/Sidebar.tsx`, permission/feature gated by frontend metadata. It recorded 73 internal destinations; 22 carried a badge key. Supplier/customer portals define 19 destinations total. Employee self-service has a six-item conditional mobile bottom navigation; factory floor has two tabs; maintenance mobile has one tab. The three compact shells do not render badge counters. Later implementation deltas are listed below the baseline inventory.

`BadgeService` is the backend badge source, registered at `GET /dashboards/badges`. It defined 29 count keys at capture; 22 were wired to internal sidebar items. The current implementation branch wires 23. Counts are cached per user (default 30 seconds; setting-bounded), use one closure/query per active key, and trigger global version invalidation plus an empty `BadgesChanged` private-channel ping for observed model writes. SPA polling is 60 seconds and pauses in background. StockLevel changes are intentionally not observed; low-stock relies on TTL/poll.

Current visual behavior: one numeric badge per nav item; counts over 99 render as `99+`; no signal chips/popovers. Collapsed item badges remain visible. Collapsed module groups sum child counts and show the highest current severity; the summary is in a title/ARIA label. Mobile uses the same one-badge rendering. There is no signal legend or global system-notice strip in the main shell; alerts, Action Center, and DSS have separate screens.

The Phase 0 internal destination/badge-key inventory is below, followed by its implementation delta. `—` means no badge key.

**Phase 0 internal item count:** 73; **current count:** 74; **baseline items with badge keys:** 22; **current items with badge keys:** 23.

| Path | Label | Permission gate | Feature gate | Badge key |
| --- | --- | --- | --- | --- |
| `/dashboard` | `Dashboard` | `dashboard.action_center.view` | none | `action_center` |
| `/decision-center` | `Decision Center` | `dss.view` | none | — |
| `/chains` | `Business Chain Tracker` | any: crm.sales_orders.view, dashboard.view_bottlenecks | none | — |
| `/chains/recovery` | `Automation Recovery` | `dashboard.chain_recovery.view` | none | — |
| `/approvals` | `Approval Queue` | `approvals.board.view` | none | `approvals` |
| `/notifications` | `Notifications` | `notifications.view` | none | `unread` |
| `/crm/inquiries` | `Customer Inquiries` | `crm.inquiries.view` | `crm` | — |
| `/crm/sales-orders` | `Sales Orders` | `crm.sales_orders.view` | `crm` | `pending_so` |
| `/crm/customers` | `Customers` | `accounting.customers.view` | `crm` | — |
| `/crm/products` | `Products` | `crm.products.view` | `crm` | — |
| `/crm/price-agreements` | `Price Agreements` | `crm.price_agreements.view` | `crm` | — |
| `/crm/complaints` | `Customer Complaints` | `crm.complaints.view` | `crm` | `open_complaints` |
| `/return-management` | `Returns (RMA)` | `return_management.view` | `return_management` | `pending_returns` |
| `/return-management/cases` | `Problem Reports` | `return_management.view` | `return_management` | — |
| `/production/work-orders` | `Production Work Orders` | `production.work_orders.view` | `production` | `work_orders` |
| `/production/schedule` | `Production Schedule (Gantt)` | `production.schedule.view` | `production` | — |
| `/production/routings` | `Production Routings` | `production.routings.view` | `production` | — |
| `/mrp/plans` | `MRP Plans` | `mrp.plans.view` | `mrp` | `mrp_plans` |
| `/mrp/boms` | `Bill of Materials` | `mrp.boms.view` | `mrp` | — |
| `/mrp/machines` | `Production Machines` | `mrp.machines.view` | `mrp` | — |
| `/mrp/molds` | `Production Molds` | `mrp.molds.view` | `mrp` | — |
| `/purchasing/chain` | `Procurement Chain` | `purchasing.chain.view` | `purchasing` | — |
| `/purchasing/purchase-orders` | `Purchase Orders` | `purchasing.view` | `purchasing` | — |
| `/purchasing/purchase-requests` | `Purchase Requests` | `purchasing.view` | `purchasing` | `purchase_requests` |
| `/purchasing/rfqs` | `Supplier RFQs` | `purchasing.rfq.view` | `purchasing` | — |
| `/purchasing/approved-suppliers` | `Approved Suppliers` | `purchasing.po.create` | `purchasing` | — |
| `/purchasing/supplier-listings` | `Supplier Listings` | `purchasing.supplier_listings.review` | `purchasing` | — |
| `/inventory/items` | `Inventory Items` | `inventory.view` | `inventory` | `low_stock` |
| `/inventory/uoms` | `Units of Measure` | `inventory.items.manage` | `inventory` | — |
| `/inventory/grn` | `Goods Receipts (GRN)` | `inventory.view` | `inventory` | `pending_grn` |
| `/inventory/material-issues` | `Material Issues` | `inventory.issue.create` | `inventory` | — |
| `/inventory/stock-levels` | `Inventory Stock Levels` | `inventory.view` | `inventory` | — |
| `/inventory/stock-adjustments` | `Stock Adjustments` | any: inventory.adjust, inventory.adjust.approve | `inventory` | — |
| `/inventory/warehouse-map` | `Warehouse Map` | `inventory.warehouse.manage` | `inventory` | — |
| `/inventory/transfer-orders` | `Inventory Transfers` | `inventory.adjust` | `inventory` | — |
| `/inventory/picking` | `Material Handover` | `inventory.picking.view` | `inventory` | — |
| `/supply-chain/deliveries` | `Outbound Deliveries` | any: supply_chain.view, supply_chain.deliveries.view | `supply_chain` | `deliveries` |
| `/supply-chain/fleet` | `Delivery Fleet` | `supply_chain.view` | `supply_chain` | — |
| `/quality/inspection-specs` | `Inspection Specifications` | `quality.specs.view` | `quality` | — |
| `/quality/inspections` | `Quality Inspections` | `quality.inspections.view` | `quality` | `pending_inspections` |
| `/quality/ncrs` | `Nonconformance Reports (NCRs)` | `quality.ncr.view` | `quality` | `ncrs` |
| `/quality/traceability` | `Lot Traceability` | `quality.inspections.view` | `quality` | — |
| `/budgeting` | `Budgeting` | `budgeting.view` | `budgeting` | `pending_budgets` |
| `/budgeting/transfers` | `Budget Transfers` | any: `budgeting.view`, `budgeting.approve` | `budgeting` | `pending_transfers` (implementation delta) |
| `/accounting/invoices` | `Accounts Receivable Invoices` | `accounting.invoices.view` | `accounting` | `draft_invoices` |
| `/accounting/bills` | `Accounts Payable Bills` | `accounting.bills.view` | `accounting` | `overdue_bills` |
| `/accounting/vendors` | `Vendors` | `accounting.vendors.view` | `accounting` | — |
| `/accounting/income-statement` | `Income Statement` | `accounting.statements.view` | `accounting` | — |
| `/accounting/balance-sheet` | `Balance Sheet` | `accounting.statements.view` | `accounting` | — |
| `/hr/recruitment` | `Recruitment` | `hr.recruitment.view` | `recruitment` | — |
| `/hr/trainings` | `Training Programs` | `hr.trainings.view` | `hr` | — |
| `/hr/loans` | `Employee Loans` | `loans.view` | `loans` | — |
| `/hr/separations` | `Separation Clearance` | `hr.separation.view` | `hr` | — |
| `/hr/profile-update-requests` | `Bank Account Changes` | any: hr.employees.view, hr.profile_updates.finance_review | `hr` | — |
| `/hr/employees` | `Employees` | any: hr.employees.view, hr.salary_adjustments.view | `hr` | `profile_requests` |
| `/hr/departments` | `Departments` | `hr.departments.view` | `hr` | — |
| `/hr/attendance` | `Attendance & DTR` | any: attendance.edit, attendance.import, attendance.ot.approve | `attendance` | — |
| `/hr/attendance/overtime` | `Overtime` | `attendance.ot.approve` | `attendance` | `overtime` |
| `/hr/leaves` | `Leave Management` | any: leave.approve_dept, leave.approve_hr, leave.types.manage | `leave` | `leaves` |
| `/payroll/periods` | `Payroll Processing` | `payroll.periods.view` | `payroll` | `payroll` |
| `/payroll/adjustments` | `Payroll Adjustments` | `payroll.adjustments.view` | `payroll` | — |
| `/payroll/statutory` | `Payroll Statutory Exports` | `payroll.statutory.export` | `payroll` | — |
| `/maintenance/work-orders` | `Maintenance Work Orders` | `maintenance.view` | `maintenance` | `maintenance_wo` |
| `/maintenance/schedules` | `Maintenance Schedules` | `maintenance.view` | `maintenance` | — |
| `/assets` | `Fixed Assets` | `assets.view` | `assets` | — |
| `/admin/users` | `User Accounts` | `admin.users.manage` | none | — |
| `/admin/roles` | `Roles & Permissions` | `admin.roles.manage` | none | — |
| `/admin/audit-logs` | `Audit Logs` | `admin.audit_logs.view` | none | — |
| `/admin/settings` | `Settings` | `admin.settings.manage` | none | — |
| `/admin/sessions` | `Active Sessions` | `admin.settings.manage` | none | — |
| `/admin/component-gallery` | `Component Gallery` | `admin.settings.manage` | none | — |
| `/accounting/portal-access` | `Portal Access` | `b2b.portal_access.view` | none | — |
| `/admin/backups` | `Backup & Restore` | `admin.backups.view` | none | — |
| `/admin/gov-tables` | `Government Contribution Tables` | `admin.gov_tables.manage` | none | — |

### Post-discovery implementation delta

PR #367 adds `/budgeting/transfers` (`Budget Transfers`), shown to users with `budgeting.view` or `budgeting.approve`, under the `budgeting` feature. Its `pending_transfers` badge opens `?status=pending&actionable=1`; the badge and filter share the same pending-approval scope. The original 73-destination, 22-badge-key counts above remain the Phase 0 baseline.

| Shell | Path | Label |
| --- | --- | --- |
| Supplier portal | `/portal/supplier` | Dashboard |
| Supplier portal | `/portal/supplier/notifications` | Notifications |
| Supplier portal | `/portal/supplier/purchase-orders` | Purchase Orders |
| Supplier portal | `/portal/supplier/rfqs` | Supplier RFQs |
| Supplier portal | `/portal/supplier/item-listings` | Item Listings |
| Supplier portal | `/portal/supplier/invoices` | Invoices |
| Supplier portal | `/portal/supplier/statement-of-account` | Account Statement |
| Supplier portal | `/portal/supplier/deliveries` | Deliveries |
| Supplier portal | `/portal/supplier/problems` | Problem reports |
| Customer portal | `/portal/customer` | Dashboard |
| Customer portal | `/portal/customer/notifications` | Notifications |
| Customer portal | `/portal/customer/orders` | Orders |
| Customer portal | `/portal/customer/orders/new` | Place Order |
| Customer portal | `/portal/customer/pricing` | Pricing agreements |
| Customer portal | `/portal/customer/invoices` | Invoices |
| Customer portal | `/portal/customer/statement-of-account` | Account Statement |
| Customer portal | `/portal/customer/deliveries` | Deliveries |
| Customer portal | `/portal/customer/returns` | Returns |
| Customer portal | `/portal/customer/problems` | Problem reports |

**Other navigation shells:** employee self-service bottom bar: `/self-service` (always), `/self-service/work-orders` (production feature), `/self-service/dtr` (attendance feature), `/self-service/leave` (leave feature), `/self-service/payslips` (payroll feature plus `payroll.view`), `/self-service/me` (always). Factory floor: `/factory`, `/factory/qc`. Maintenance mobile: `/maintenance/mobile`. These use no numeric badges.

### Existing badge definitions

The 29 backend keys and their current meanings are defined in `api/app/Modules/Dashboard/Services/BadgeService.php`:

| Key | Current count definition |
| --- | --- |
| `approvals` | `ApprovalRecord` current/pending rows for the user's role or active delegated roles; no source-record scope filter here. |
| `action_center` | `ActionCenterService::for($user)['summary']['total']`. |
| `purchase_requests` | Current/pending PR approval rows assigned to the user's own role, with PR status pending; delegated roles are not included. |
| `leaves` | Pending-dept/HR leave statuses for the user's permissions, with `DepartmentScope`. |
| `overtime` | Pending overtime requests with `DepartmentScope`. |
| `maintenance_wo` | Maintenance WOs in open, assigned, or in-progress status. |
| `low_stock` | Items with reorder point greater than zero and total available stock at/below reorder point. |
| `ncrs` | NCR status not closed/cancelled. |
| `profile_requests` | Pending profile updates with `DepartmentScope`. |
| `payroll` | Payroll periods in `draft` or `processing`. |
| `training_expiry` | Employee training status `expired`. |
| `training_upcoming` | Non-cancelled/non-expired training scheduled within 14 days or completed certification expiring within 30 days. |
| `open_postings` | Job postings with status `open`. |
| `work_orders` | Work orders `confirmed`/`in_progress` with `planned_end < now()`. |
| `deliveries` | Deliveries in `loading` or `in_transit`. |
| `unread` | Current user's unread Laravel notifications. |
| `pending_so` | Sales orders in `draft`, `awaiting_confirmation`, or `delivery_negotiation`. |
| `inquiries` | Contact inquiries in `new` or `in_progress`. |
| `open_complaints` | Complaints outside `resolved`, `closed`, and `cancelled`. |
| `pending_inspections` | Inspections in `draft` or `in_progress`. |
| `pending_grn` | GRNs in `pending_qc`. |
| `mrb_holds` | Material review records in `held`. |
| `shipments` | Shipments in `in_transit`, `customs`, or `cleared`. |
| `mrp_plans` | Active MRP plans with `shortages_found > 0`. |
| `pending_returns` | Return requests outside `completed`, `rejected`, and `cancelled`; customer-service scope can restrict to customer returns. |
| `draft_invoices` | Invoices in `draft`. |
| `overdue_bills` | Unpaid/partial bills with `due_date < today()` in app timezone. |
| `pending_budgets` | Budgets with status `submitted`. |
| `pending_transfers` | Phase 0 baseline counted all transfers with status `pending`; the current branch scopes this to pending transfers the user can approve under the direct/delegated maker-checker rule. |

## Candidate signal data discovery

Status enums and due/state columns exist across the modules below. The candidate definitions are not approved policy; they are the proposed data-backed review set. Exact excluded/terminal status classification must be locked against each enum and destination list during Phase 2/implementation.

| Module | Relevant data found | Candidate signals to evaluate | Current sidebar badge |
| --- | --- | --- | --- |
| Accounting | Invoice, bill, payment, journal, credit-note, and accounting-period status enums; due dates and payment submission rows | AR unpaid/partial/overdue/due soon; AP unpaid/partial/overdue; submitted payment review; draft/unposted invoice; unreconciled or failed posting; period close/reopen | Draft invoice, overdue bills |
| Admin | Active/locked users, permission overrides, audit, backup operations, queue/failed-job health | Locked accounts; pending/expired invites; failed jobs, queue wait, backup failure; expiring system credential/certificate if stored | None |
| Assets | Asset and transfer status; depreciation and disposal workflows | Disposal awaiting approval; asset transfer pending; depreciation run due/failed; calibration overlap only if asset-attached | None |
| Attendance | Attendance and overtime statuses; missing time-in/out records | Pending my overtime approval; own attendance exception; department exception for authorized approver; missing timeout | Overtime |
| Auth | Internal user account/lock state; password reset and invite flows | Locked/disabled account/admin action; no cross-user personal badge proposed | None |
| B2B | Customer/supplier portal users, delivery schedule state, portal orders and responses | Supplier/customer response awaiting the current party; delivery schedule proposed/rejected/late; portal payment evidence awaiting review | None; portal notification bell only |
| CRM | Sales order, complaint, price request and customer response status; price agreement end date | Sales order awaiting confirmation/customer response; complaint 8D clock; price review/approval; agreement expiring; customer cancellation/return action | Sales orders, complaints |
| Dashboard | Action Center tasks, approvals board, alerts, chain bottlenecks | Personal pending action; area-level risk only when its list query scopes identically; unresolved system alert for authorized audience | Action Center, approvals |
| DSS | Recommendation status/severity, snooze and outcome fields | Open critical/high-confidence recommendation assigned to the user's eligible audience; no publisher currently wired | Decision Center has no count |
| Forecasting | Forecasting module exists; no sidebar badge or status enum was found in the status-enum scan | Stale forecast run or decision awaiting owner, only if a reliable freshness/status field and destination filter exist | None |
| HR | Employee, training, job, profile update, salary adjustment, clearance, training matrix and review statuses | Training/cert expiry; recruitment queue/bottleneck; profile/salary change approval; separation clearance tasks; contract/regularization if a reliable end date exists | Payroll-side profile requests only (currently attached to Employees item); no training/recruitment badge |
| Inventory | Stock level, item reorder, GRN, MRB, reservations, stock adjustment/count, transfer, material issue, cost review statuses | Low/out of stock; material shortage/reservation block; GRN incoming QC/putaway; MRB disposition; stock-count/adjustment approval; receipt awaiting inspection | Low stock, pending GRN |
| Landing | Contact inquiry and newsletter statuses | New/untriaged contact inquiry; newsletter failures only if actionable internal owner exists | None |
| Leave | `LeaveRequestStatus`: `pending_dept`, `pending_hr`, `approved`, `rejected`, `cancelled`; no `returned` state found | Pending current department or HR approval; rejected outcome only for requester if policy wants a history signal; no deadline field confirmed | Leaves |
| Loans | Loan and write-off statuses; employee-linked request ownership | Pending current loan/write-off approval; requester outcome; repayment exception only with actionable data | None |
| Maintenance | Maintenance work-order statuses, preventive maintenance generation and machine breakdown events | Unassigned work orders; open breakdown; PM due/overdue; part request handoff; maintenance SLA | Open maintenance WOs |
| MRP | Run/plan, machine and mold statuses; shortage count and shot limits | Active shortage; failed/stale run; machine down/shot limit; plan needs review | Active shortage plans |
| Payroll | Period, adjustment, bank file, GL handoff statuses and restricted payroll scopes; payslip email status also persists `failed` | Pending my approval/review; `manual_required` bank/GL handoff; failed payslip email for the affected employee; no `failed` value exists in bank/GL enums; never a broad HR/finance payroll count | Draft/processing periods |
| Production | Work-order, schedule, operation, late-report and receipt handoff statuses; planned start/end | Pending release; overdue/starting soon; paused/blocked; missing output confirmation; material block; staffing assignment change | Overdue work orders |
| Purchasing | PR/PO, RFQ, supplier response/listing/dispatch statuses; approval records | Pending current approval; supplier response/quote due; unfulfilled/late PO; auto-PO review; supplier listing review; inbound receipt and failed conversion | PR approvals only |
| Quality | Inspection, NCR, effectiveness, calibration, PPAP statuses and due dates | Inspection awaiting completion/review; rejected lot; open/overdue NCR/CAPA/effectiveness; calibration due/expired; customer complaint response SLA | Pending inspections, open NCRs |
| ReturnManagement | Return request/case, inspection handoff, restock and supplier-return state | Open customer/supplier return needing current owner; overdue inspection/credit; rejected/returned outcome for requester; only fields supported by current return implementation | Pending returns |
| SupplyChain | Delivery, shipment, vehicle and delivery handoff statuses; delivery date/attempts | Delivery planned/late/in transit/awaiting customer acknowledgement; failed delivery/depot count; shipment clearance/receipt; vehicle issue | Loading/in-transit deliveries |

## Foundation facts

- Compose uses PostgreSQL 16, Redis 7, Reverb, Horizon, API, SPA, Nginx, and Caddy. Laravel defaults to Redis queue and Reverb broadcasting; Horizon has separate high/default/mail/automation/bulk/DSS lanes. `after_commit` is false at queue-connection level, so notification side effects rely on `NotificationService`'s explicit `DB::afterCommit()` callback.
- `APP_TIMEZONE` defaults to `Asia/Manila`; PHP formatting, notification relative-time grouping, and several badge date paths do not all explicitly use it. Bills compare date-only values with `today()`; production work-order overdue uses a datetime comparison with `now()`. The dashboard probation widget filters `employees.date_regularized`, while employee self-service derives expected regularization from `date_hired` and `hr.probation.period_months`; authoritative meaning needs HR confirmation before using it for a badge.
- Local DB aggregate: 2,513 notification rows; 1,546 unread; 79 notification type strings; 3 `notifiable_type` families. `notification_preferences` has zero rows. Notifications table total size is about 2.1 MB. Existing indexes cover `(notifiable_type, notifiable_id, created_at)`, `(notifiable_type, notifiable_id, read_at)`, unread rows, and unique `(notifiable_type, notifiable_id, dedupe_key)`; there is no dedicated `type` index.
- Local DB has 17 nonempty internal role slugs and 110 users. The existing `docs/role-access-alignment-audit.md` describes sixteen seeded roles; reconcile that difference before declaring role coverage complete.
- Settings are stored through `SettingsService`; notification catalog, notification roles, badge TTL/severity thresholds, module feature gates, and alert notification roles are settings-backed. Preferences exist by type/channel, but no mandatory-category model was found.
- Internal auth uses role permissions plus per-user overrides and `system_admin` bypass. Department scoping helpers and `ApprovalSourceScope` exist, but scope enforcement varies by caller and by list endpoint. Frontend `Sidebar` shares permission metadata with Command Palette; route guards and backend middleware remain the authority. Existing role-alignment tests cover selected role/nav boundaries.
- Existing targeted test files include `NotificationServiceTest`, `NotificationControllerTest`, `BadgeControllerTest`, `BadgeRealtimeTest`, `BroadcastAuthorizationTest`, and event-specific audience/link tests under `api/tests/Feature`. No badge-to-destination-list parity test was found in the inspected filenames or badge test bodies.
