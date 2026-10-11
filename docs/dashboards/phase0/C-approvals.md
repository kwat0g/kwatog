# Phase 0 — Section C: Approval Workflows

Read-only discovery against primary sources on branch `docs/dss-discovery-phase0`.
Engine: `api/app/Common/Services/ApprovalService.php` (step-ledger on `approval_records`);
board: `api/app/Common/Services/ApprovalBoardService.php` (read-only);
SLA sweep: `api/app/Common/Services/ApprovalEscalationService.php` (`approvals:run-escalations`, every 6h);
registry: `api/app/Common/Support/ApprovalTypeRegistry.php`;
row visibility: `api/app/Common/Support/ApprovalSourceScope.php`.
Every claim cites a path (`:line` where useful).

Shared guards (all `ApprovalService` types): self-approval refuse in `approve()`/`reject()`
(`api/app/Common/Services/ApprovalService.php:129-132,157-160`, message `You cannot act on a record you submitted.`);
distinct-decision-maker per attempt in `assertNewDecisionMaker()`
(`api/app/Common/Services/ApprovalService.php:245-255`, `A different user must act at each approval step.`);
role-or-delegate check in `userMayActFor()` (`api/app/Common/Services/ApprovalService.php:224-243`);
threshold skip in `submit()` — step `threshold` as JSON **string**, skipped iff `amount < threshold`
(`api/app/Common/Services/ApprovalService.php:85-89`); submit serialises on row `lockForUpdate`
(`api/app/Common/Services/ApprovalService.php:46-50`); every act path re-reads under `lockForUpdate`
before guarding (lock-then-guard). SLA knobs: `approvals.reminder_hours=24`,
`approvals.escalation_hours=48` (`SettingsSeeder.php:151-152`), sweep every 6h
(`api/routes/console.php:166-170`), `approvals.recent_history_days=30`
(`0323_seed_approval_and_kpi_window_settings.php:10`),
auto-resolve **disabled by default** (`enabled=false, default_hours=72, default_action=reject`,
`SettingsSeeder.php:131-149`). Escalation superior map
(`0416_seed_approval_escalation_role_map.php`, rewired `0482_…` so every step role escalates to
`vice_president` except `department_head → production_manager`; **no entry for `vice_president` itself**).
Delegation: `ApprovalDelegation` model (`api/app/Common/Models/ApprovalDelegation.php`),
windowed `[starts_at, ends_at]` day-granularity, exact-role or blanket (null `role_slug`, effective only
while delegator still holds the role, `:96-107`), self-service CRUD under `admin/approval-delegations`
(`api/app/Modules/Admin/routes.php:232-244`, no permission slug beyond auth).
Delegates are honoured **only** inside `ApprovalService::userMayActFor()` (and loan visibility);
no custom single-step approval consults delegation (verified: Attendance has zero delegation references).

## 1. Approvable document types

### 1a. `ApprovalService` chains (step-ledger, board-visible, SLA-swept)

| Type (registry kind) | Chain steps (roles in order) | Status machine (exact enum) | Act endpoints + permissions | SLA / delegation |
|---|---|---|---|---|
| Leave (`leave`) | 1 `department_head` → 2 `hr_officer` (`WorkflowSeeder.php:24-31`); separate `approveDept`/`approveHR` arms, status-gated per arm | `LeaveRequestStatus`: `pending_dept → pending_hr → approved`, `pending_* → rejected`, `→ cancelled` (`Leave/Enums/LeaveRequestStatus.php`); no `draft` — created pending (`LeaveRequestService.php:305`) | `PATCH /requests/{id}/approve-dept` (`leave.approve_dept`), `PATCH …/approve-hr` (`leave.approve_hr`), bulk variants, `PATCH …/reject` (either) (`Leave/routes.php:41-48`) | Delegation yes (via engine). Submitter = employee's user (`LeaveRequest.php:78-85`). Dept arm additionally scope-checked (`assertDepartmentDecisionScope`, `LeaveRequestService.php:730-749`: `leave.approve_hr` bypasses, else same-department only) |
| Purchase request (`pr`) | 1 `finance_officer` → 2 `vice_president` iff `total ≥ ₱50,000` (`WorkflowSeeder.php:57-64`); **RFQ-sourced PRs skip the chain** — submit marks Approved need-only (`PurchaseRequestService.php:419-424`) | `PurchaseRequestStatus`: `draft → pending → approved → converted`; `pending → rejected`; `draft/pending → cancelled` (`PurchaseRequestService.php:371,428-431,511-513,649-654,677`) | `PATCH /purchase-requests/{id}/approve|reject`, `POST /bulk-approve` (`purchasing.pr.approve`), `PATCH …/acknowledge-budget` (`budgeting.approve`) (`Purchasing/routes.php:26,37-39`) | Delegation yes. Submitter = `requested_by` column fallback (`ApprovalService.php:274-279`; `PurchaseRequest.php:38`). Budget ack gate before approve (`PurchaseRequestService.php:507`); dept scope on decide (`assertMayDecide`, `:601-613`) |
| Purchase order (`po`) | 1 `finance_officer` → 2 `vice_president` iff `total ≥ ₱50,000` (`WorkflowSeeder.php:73-80`); buyer (`purchasing_officer`) is creator, never a step | `PurchaseOrderStatus`: `draft → pending_approval → approved → sent → acknowledged … → received/closed`; `→ cancelled` (`Purchasing/Enums/PurchaseOrderStatus.php`) | `PATCH /purchase-orders/{id}/approve|reject` (`purchasing.po.approve`), `…/acknowledge-budget` (`budgeting.approve`) (`Purchasing/routes.php:98-100`) | Delegation yes. Submitter = `created_by` (`PurchaseOrder.php:44`). Extra approve gates: vendor SoD (`assertVendorSod`), PPAP gate, budget enforce (`PurchaseOrderService.php:759-803`) |
| Bill payment (`bill_payment`) | Single `vice_president` iff bill `total ≥ ₱50,000`, else auto-skipped → auto-posts (`2026_10_09_000300_bill_payment_vp_threshold.php`; `BillService.php:841-845`) | `BillPaymentStatus`: `pending_approval → posted`; `→ rejected`; `→ voided` (`Accounting/Enums/BillPaymentStatus.php`) | `POST /{bill}/payments/{payment}/approve|reject` (`accounting.bills.payment_approve`) (`Accounting/routes.php:98-99`) | Delegation yes. Submitter = `created_by` (`BillPayment.php:26`). Single-step → `assertNewDecisionMaker` never fires here |
| Loan / cash advance (`loan`) | CA: `department_head → finance_officer → vice_president`; company loan: `department_head → hr_officer → finance_officer → vice_president` iff `principal ≥ ₱50,000` (`WorkflowSeeder.php:32-50`, step 2 rewired to HR by `2026_10_10_000100…`, pending rows migrated to VP by `0534_…`) | `LoanStatus`: `pending → active → paid`; `pending → rejected|cancelled`; `active → write_off_pending → written_off` (`Loans/Enums/LoanStatus.php`; `LoanService.php:197,222-226,284-285,310-311`) | `PATCH /{loan}/approve|reject`, `POST /bulk-approve` (`loans.approve`); write-off request/approve separate (`loans.write_off.request`, `loans.write_off.approve`) (`Loans/routes.php:17-24`) | Delegation yes (engine + `plantWideStepRoles` visibility, `LoanAccessPolicy.php:170-186`). Submitter = employee's user (`EmployeeLoan.php:69-76`). Decide additionally row-scoped (`canDecide`, `LoanService.php:216-218,280-282`) |
| Salary adjustment (`salary_adjustment`) | 1 `production_manager` → 2 `vice_president`, no thresholds (`WorkflowSeeder.php:96-103`); one pending per employee enforced (`SalaryAdjustmentService.php:49-56`); apply is idempotent on `applied_at` (`:113-117`) | `SalaryAdjustmentStatus`: `pending → approved → (applied)`; `pending → rejected` (`HR/Enums/SalaryAdjustmentStatus.php`; `SalaryAdjustmentService.php:89-106`) | `PATCH /salary-adjustments/{id}/act` (`hr.salary_adjustments.act`; step role enforced by engine) (`HR/routes.php:134-135`; `ActSalaryAdjustmentRequest.php:11-16`) | Delegation yes. Submitter = `requested_by` (`SalaryAdjustment.php:50-53`) |
| Asset disposal (`asset_disposal`) | 1 `finance_officer` → 2 `vice_president`, no thresholds (`WorkflowSeeder.php:142-149`; `0529_…` rewired, retired transfer permissions) | No disposal status — `AssetStatus` stays `active/under_maintenance` until final approve flips to `disposed` + posts JE (`AssetService.php:405-411`); reject/cancel clears proposal columns (`:417-435,476-484`) | `POST /assets/{asset}/dispose` (`assets.dispose`), `…/dispose/approve|reject` (`assets.dispose.approve`), `…/dispose/cancel` (`assets.dispose`) (`Assets/routes.php:32-34`) | Delegation yes. Submitter = `disposal_requested_by` (`Asset.php:104-107`). Double-submit refused via `nextStep()` (`AssetService.php:331-333`) |
| Return request (`return_request`, `finance_only_return_request`) | Standard: `department_head → production_manager`; finance-only: single `finance_officer` (`WorkflowSeeder.php:169-185`; `2026_09_23_100000_…`). QC-generated supplier returns bypass the chain (auto-approved at submit, `ReturnRequestService.php:1763-1779`) | `ReturnRequestStatus`: `draft → pending_approval → approved → received → inspected → completed`; `→ rejected`; `draft/pending/approved → cancelled` (pre-physical only, `:3652-3677`) (`ReturnManagement/Enums/ReturnRequestStatus.php`) | `POST /return-requests/{id}/approve|reject` (`return_management.approve`) (`ReturnManagement/routes.php:33-37`); case actions separate (`case-routes.php:17-18`) | Delegation yes. Submitter = `created_by` fallback (`ReturnRequest.php:83`). QC-submit restricted to `purchasing_officer|system_admin` (`:1728`) |
| Payroll period (`payroll`) | **No chain.** `payroll` workflow exists in seeds but is NOT wired (`wiredTypes` omits it, `WorkflowSeeder.php:188-199`) and `PayrollPeriodService::approve()` never calls `ApprovalService::submit` — custom maker-checker: approver must hold `payroll.periods.approve` and be neither `created_by` nor `computed_by` unless holding `payroll.periods.self_approve_override` (`PayrollPeriodService.php:1205-1220`) | `PayrollPeriodStatus`: `draft → processing → computed → approved → finalized → disbursed`; `→ voided`; computed/legacy-unapproved `→ draft` via requestCorrection (`PayrollPeriodService.php:1173-1179,1259-1277`) | `PATCH /{period}/approve` (`payroll.periods.approve`) (`Payroll/routes.php:62`); correction/finalize/void separate arms | Delegation NO (engine never invoked). No `approval_records` rows are ever written for periods (verified: zero `submit` calls under `Modules/Payroll`; `PayrollPeriod` model has no approval-records relation) — the board's `payroll` kind is therefore always empty (see §5) |

### 1b. Custom single-step approvals (no step-ledger, no board card, no SLA sweep)

| Type | Steps / guard | Status machine | Act endpoints + permissions |
|---|---|---|---|
| Overtime | Single checker; self-decision refused via employee→user link (`OvertimeDecisionPolicy.php:51-58`); dept policy (`OvertimeService.php:269-312`); **no delegation path** (zero delegation refs in Attendance) | `pending → approved|rejected` (`Attendance/Enums/OvertimeStatus.php`); `overtime_request` workflow row deleted as dead config (`0502_…`; `ATTENDANCE-LEAVE-LOANS-AUDIT-2026-09-18-FINISHED.md:37`) | `PATCH /overtime-requests/{id}/approve|reject`, `POST /bulk-approve` (`attendance.ot.approve`) (`Attendance/routes.php:59-62`) |
| Stock adjustment | Single checker, `inventory.adjust.approve`; requester cannot decide own (`StockAdjustmentService.php:250-269,301-319`); above-threshold only (threshold `inventory.adjustment_approval_threshold`, `SettingsSeeder.php:433`) | `pending → approved|rejected|withdrawn` (`Inventory/Enums/StockAdjustmentStatus.php`) | `PATCH /stock-adjustments/{id}/approve|reject` (`inventory.adjust.approve`) (`Inventory/routes.php:136-137`) |
| Item cost review | `inventory.costs.review` to request, `inventory.costs.approve` to decide (`ItemCostReviewService.php:31-58`) | `pending → approved|rejected` (`Inventory/Enums/ItemCostReviewStatus.php`) | `POST /cost-reviews/{id}/decide` (`inventory.costs.approve`) (`Inventory/routes.php:29`) |
| Stock-count variance | `approveVariance`, lock-then-guard (`StockCountService.php:277-282`) | item `counted/verified → …` under session `in_progress` | `POST /stock-counts/items/{id}/approve` (`inventory.stock_count.manage`) (`Inventory/routes.php:150`) |
| GRN reject | No approval — warehouse refusal arms | `GrnStatus`: `pending_qc|accepted|partial_accepted|rejected` (CLAUDE.md enum note) | `PATCH /grn/{id}/reject|reject-remainder` (`inventory.grn.create`) (`Inventory/routes.php:177-178`) |
| Payroll adjustment | Single checker; requester (`created_by`) cannot approve own (`PayrollAdjustmentService.php:102-122`) | `pending → approved → applied`; `→ rejected` (`Payroll/Enums/PayrollAdjustmentStatus.php`) | `PATCH /{adjustment}/approve` (`payroll.adjustments.approve`), `…/reject` (`payroll.adjustments.reject`) (`Payroll/routes.php:115-116`) |
| Budget | Single checker; submitter cannot approve own **plus** different-role-from-submitter rule (`BudgetService.php:133-163`) | `draft → submitted → active` (`approved` legacy); reject returns to `draft` (`Accounting/Enums/BudgetStatus.php`; `BudgetService.php:110-204`) | `POST /{budget}/approve|reject` (`budgeting.approve`) (`Accounting/routes.php:172-173`); submit arm same file |
| Budget transfer | Checker arm (`BudgetTransferService.php:86-141`) | `pending → approved|rejected` (`Accounting/Enums/BudgetTransferStatus.php`) | `POST /{transfer}/approve|reject` (`budgeting.approve`) (`Accounting/routes.php:182-183`) |
| Invoice prebill | `approvePrebill` (`InvoiceService.php:244`); customer payment submissions reviewed under `accounting.invoices.collect` (`InvoicePaymentSubmissionService.php:99-131,225`) | prebill invoice states (`Invoice/Enums/InvoiceStatus.php`) | `POST /{invoice}/approve-prebill` (`accounting.invoices.prebill_approve`); `POST …/payment-submissions/{id}/reject` (`accounting.invoices.collect` + `throttle:sensitive`) (`Accounting/routes.php:126-131`) |
| Supplier listing | Reviewer decide (`SupplierListingService.php:128-170`) + notify on submit (`:344`) | `pending → approved|rejected|superseded` (`Purchasing/Enums/SupplierListingStatus.php`) | `PATCH /supplier-listings/{id}/approve|reject`, bulk arms (`purchasing.supplier_listings.review`) (`Purchasing/routes.php:121-124`) |
| Asset transfer | Checker; requester cannot approve own (`AssetTransferService.php:81-92,124-129`) | `pending → approved → completed`; `→ rejected` (`Assets/Enums/TransferStatus.php`) | **Unrouted by design** — "intentionally not routed (scope cut)" (`Assets/routes.php:44-48`) |
| Return case | `/{case}/actions` state machine | `ReturnCaseStatus` (`ReturnManagement/Enums/ReturnCaseStatus.php`) | `POST /{case}/actions|attachments` (`return_management.manage|approve` or `accounting.credit_notes.manage`) (`case-routes.php:17-18`) |
| Loan write-off | Maker-checker on active loans (`LoanService.php:316-358`) | `active → write_off_pending → written_off` | `…/write-off/approve` (`loans.write_off.approve`) (`Loans/routes.php:24`) |
| PPAP / late production report | `quality.ppap.manage`; production late-report review | module-local | (`Quality/routes.php:203-204`; `Production/routes.php:94-96`) — out of the 8 in-scope modules' core chains, listed for completeness |

## 2. Stuck-approval analysis (per type)

Global backstop exists but is **notify-only**: reminders (24h) + escalations (48h) write `notifications`
rows and stamp `reminder_sent_at`/`escalated_at`; nothing reassigns or decides
(`ApprovalEscalationService.php:59-152`). Auto-resolve is the only terminating timeout and ships
**disabled** (`enabled=false`, §head). Unstaffed steps are counted, not rescued (`failed`/`unstaffed`
in `runWithOutcome`, `ApprovalEscalationService.php:41-57`); reminder/escalation deliberately skip
stamping when no active holder exists so a later run can notify (`:73-80,121-128`).
VP steps have **no superior** in the escalation map (map ends at VP), so a VP stall notifies only the
VP role itself, or nobody if the role is empty (`resolveSuperior` returns null, `:385-398`).

1. **Leave — STUCK RISK: department without an active head.** Step 1 needs a `department_head` of the
   *requester's own* department (`LeaveRequestService.php:730-749`); HR (`leave.approve_hr`) can act at
   step 1 only via the engine role check if an HR user also… no — step 1 role is strictly
   `department_head`, HR perm only bypasses the *scope* check, not the step role
   (`ApprovalService.php:134-136`). If the department has no active head and no delegation covers it,
   the request stalls at `pending_dept` until the sweep reminds/escalates (to production_manager per
   map — who cannot act on it). Self-guard edge: a department head requesting leave cannot approve
   step 1 themselves (submitter hook, `LeaveRequest.php:78-85`); needs another head of the same dept,
   an HR officer… again no — step 1 is head-only; a sole head's own leave **always stalls at step 1**
   unless they hold/obtain a delegation. `assertNewDecisionMaker` further bars one user covering both
   steps. Circular: no. Timeout: notify-only.
2. **PR — LOW (by design).** Money-only chain names no creator role (creators are
   `department_head`+`purchasing_officer` via create gate), so the self-guard can never strand a
   submission on role grounds (`WorkflowSeeder.php:51-56` comment). Stall needs *both* roles empty
   (no active finance_officer, or no active VP for ≥₱50k). Residual: a finance_officer who *requests* a
   PR is self-blocked at step 1 (submitter = `requested_by`) and needs a second finance holder or
   delegate. RFQ path cannot stall (no chain). Budget-unacknowledged blocks with an explicit error,
   not a stall (`PurchaseRequestService.php:507`).
3. **PO — LOW, same shape as PR.** Buyer never a step. Self-block only if the PO creator also holds the
   acting step role — impossible under single-role users (U2/R1, `ApprovalBoardService.php:294-296`)
   unless the creator's role *is* finance/VP. Non-role blockers (vendor SoD, PPAP gate, budget
   enforce) refuse loudly at approve time (`PurchaseOrderService.php:759-803`).
4. **Bill payment — STUCK RISK if VP seat empty (above-threshold only).** Single-step VP; below ₱50k
   the step self-skips and posts immediately, so small payments cannot stall. Above threshold with no
   active VP (and no delegate): permanent stall, escalation notifies nobody (`resolveSuperior`=null for
   `vice_president`). Self-guard via `created_by` (finance staff who stage the payment cannot also
   approve it — intended SoD, but a single-finance-user site stalls here too).
5. **Loans — STUCK RISK: department without head (step 1) + sole-holder steps.** Step 1 is
   `department_head` for both CA and company loans; same-department ladder in `canDecide`
   (`LoanAccessPolicy.php:106-141`); borrower's department with no head stalls at submit+1.
   Chain-participant branch (`isChainParticipant`, `:147-160`) lets step holders read rows they could
   not otherwise open — visibility follows the chain, good. `0534_…` migrated legacy pending rows to
   VP, evidence this class stalled in production before. Government loan types (`sss_loan`,
   `pagibig_loan`) are unsupported (`LoanType.php:9-12`, `isSupported`) so no chain exists for them —
   N/A, not stalled.
6. **Salary adjustment — STUCK RISK: production_manager sole-holder + self-request.** Step 1
   `production_manager`; a PM requesting their own/department's adjustment is self-blocked at step 1
   (submitter = `requested_by`, `SalaryAdjustment.php:50-53`) with no second holder by default.
   Step 2 VP as usual. `act` route permission (`hr.salary_adjustments.act`) is step-agnostic; the
   engine does the role gating (`ActSalaryAdjustmentRequest.php:11-16`).
7. **Asset disposal — LOW-MEDIUM.** Two finance-owned steps; requester (any `assets.dispose` holder)
   is barred from both arms via `disposal_requested_by` (`Asset.php:104-107`). A finance_officer who
   requests disposal self-blocks step 1 — needs a second finance holder or delegate (seeder comment
   acknowledges: "a single-holder role uses delegation to cover its own step",
   `WorkflowSeeder.php:140-141`). Dead-workflow fails whole instead of stranding (`AssetService.php:363-378`).
8. **Return request — LOW.** Dept-head step is *not* department-scoped in code (no scope check in
   `approve()` path beyond engine role + `created_by` self-guard), so any head can take step 1 —
   harder to stall than leave/loans. Finance-only variant is single-step. Submit fails whole on dead
   workflow (no stranded `pending_approval` without records, `ReturnRequestService.php:1783-1803` —
   this exact strand happened before and was fixed). QC auto-path bypasses.
9. **Payroll period — STUCK RISK: maker-checker with no override holder.** Approver must differ from
   *both* creator and computer; on a small team where one person creates+computes, approval requires a
   second `payroll.periods.approve` holder or the `payroll.periods.self_approve_override` grant
   (`PayrollPeriodService.php:1205-1220`). No delegation, no sweep, no reminder — stall is silent
   (no notification fires on compute or on waiting-approval at all, §3). Separately the registry's
   `payroll` board kind is dead (§1a/§5).
10. **Custom single-step approvals (OT, stock adjustment, payroll adjustment, budget, budget transfer,
    invoice prebill, supplier listing, cost review) — SYSTEMIC STALL RISK: no timeout, no delegation.**
    None writes `approval_records`, so the 6-hourly sweep never sees them: no reminder, no escalation,
    no auto-resolve. None consults `ApprovalDelegation` (Attendance: zero references; stock/budget/
    payroll-adjustment use raw permission + `requested_by`/`created_by`/`submitted_by` identity checks:
    `StockAdjustmentService.php:267-268,317-318`, `PayrollAdjustmentService.php:114-116`,
    `BudgetService.php:139-151`). A sole eligible checker on leave = silent stall. Self-guards are
    present everywhere (OT `OvertimeDecisionPolicy.php:51-58`; budget adds the stronger different-role
    rule, `BudgetService.php:142-151`), so the failure mode is *stall*, never self-approval. Circular:
    none (all single-checker, strictly forward status machines).

**Stuck-risk count: 6 types with a concrete stall path** (leave sole-head incl. head's-own-leave;
bill-payment VP-empty above threshold; loans headless-department step 1; salary-adjustment PM
self-request; payroll-period maker-checker without second holder; custom single-step family with no
timeout/delegation as one systemic entry — 9+ concrete routes). PR/PO/asset-disposal/return-request
are LOW by construction (money-only chains, fail-whole submits, unscoped step 1).

## 3. Notification + audit-log mechanisms

Channel: `NotificationService::send()` — in-app row (opt-out) + queued email (opt-in) + Reverb
broadcast deferred to `DB::afterCommit()` so rollbacks never notify
(`NotificationService.php:18-37,211-231`); per-type opt prefs in `NotificationCatalog.php`;
dedupe keys suppress replays (`:190-207`).

| Event | What fires | Channel / audience | Missing |
|---|---|---|---|
| Leave submit | `LeaveRequestSubmitted` → `NotifyOnLeaveSubmitted`: dept head(s) of requester's dept (`Leave/Listeners/NotifyOnLeaveSubmitted.php:32-44`, roles from `leave.submitted.notification_roles`) | in-app+emailnias `send()` | — |
| Leave dept-approve | `LeaveRequestPendingHR` → `NotifyOnLeavePendingHR` (`AppServiceProvider.php:409`) | HR audience | — |
| Leave final approve/reject | `LeaveRequestApproved/Rejected` → requester's user (`NotifyOnLeaveApproved/Rejected`) | in-app+email | — |
| Loan submit | `LoanSubmitted` → `NotifyOnLoanSubmitted`: roles in `loans.submitted.notification_roles` (`Loans/Listeners/NotifyOnLoanSubmitted.php:35-48`) | in-app+email | mid-chain step handoffs (dept→HR→finance→VP): **no notification tells the next step it's theirs** — only the generic 24h sweep reminder |
| Loan decided (final only) | `LoanDecided` → requester's user (`NotifyOnLoanDecided.php:35-45`) | in-app+email | same as above; rejections mid-chain notify only at terminal |
| PR submit | chain broadcast only (`PurchaseRequestService.php:436-440`); no role-targeted "waiting on Finance" notification found | chain board/WebSocket | **no submit notification to the next step** |
| PR/PO fully approved | `PurchaseRequestApproved`/`PurchaseOrderApproved` → configured roles (`NotifyOnPurchaseRequestApproved.php`, `NotifyOnPurchaseOrderApproved.php:23-35`); PO also triggers supplier dispatch prep (`AppServiceProvider.php:382-384`) | in-app+email | mid-chain handoff (Finance→VP): none |
| PO submitted | `PurchaseOrderSubmitted` → notify (`AppServiceProvider.php:383`) | in-app+email | — |
| Bill payment submit/approve/reject | **Nothing** — `BillService` contains zero notification sends (verified grep); only board card + sweep | — | submit, approve, reject, post notifications all missing |
| Salary adjustment request/approve/reject | **Nothing** — `SalaryAdjustmentService` has zero notify/outbox calls (verified grep) | — | all decision notifications missing; approvers learn only via board |
| Asset disposal request/approve/reject | **Nothing** — `AssetService` has zero notify/outbox calls (verified grep) | — | same as salary adjustment |
| Return submit (portal) | `customer.rma_created` to `return_management.manage` holders; QC defect → `return.supplier_defect` to purchasing (`ReturnRequestService.php:~660,~846`) | in-app(+email for customer one) | internal submitter → next-step (dept head) notification: none found |
| Return approve/reject | `ReturnRequestUpdated` event (`ReturnRequestService.php:1869,3647`) → customer/supplier email listeners (`AppServiceProvider.php:344-345`) + portal in-app for QC path (`:1874-1889`) | email + portal | internal step-handoff: none |
| Payroll compute/approve | **Nothing on approve** — outbox chain events only; employees notified only at finalize (`NotifyEmployeesOnPayrollFinalized`, `chain.payslip_ready`) | chain/outbox | waiting-approval notification missing (compounds §2.9 silent stall) |
| OT submit/decide | `OvertimeRequestSubmitted/Decided` → dept approvers / requester (`NotifyOnOvertimeSubmitted/Decided`, `AppServiceProvider.php:415-416`) | in-app+email | — |
| Supplier listing submit | `supplier_listing_submitted` to reviewers (`SupplierListingService.php:344`) | in-app+email | decide notification not verified |
| Stock adjustment, payroll adjustment, budget, budget transfer, invoice prebill, cost review | **Nothing found** — zero notification sends in `BudgetService`, `BudgetTransferService`, `StockAdjustmentService`, `InvoiceService` (prebill path), `PayrollAdjustmentService`, `ItemCostReviewService` (verified grep) | — | all submit/decide notifications missing |
| SLA reminder/escalation/auto-escalation | `approval_reminder` / `approval_escalation` via sweep, per-step dedupe keys (`ApprovalEscalationService.php:83-89,130-140,298-304,360-369`) | in-app+email | covers only `approval_records` types; VP-step escalation degrades (no superior, §2 head) |

Audit: every parent document model carries `HasAuditLog` (verified present on PR, PO, Leave, Loan,
PayrollPeriod, BillPayment, SalaryAdjustment, Asset, ReturnRequest, StockAdjustment, OT, Budget,
BudgetTransfer, PayrollAdjustment) — status flips and actor columns write `audit_logs` rows with
IP/user-agent/source/correlation (`HasAuditLog.php:66-96`), encrypted fields redacted (`:102-133`).
Payroll approve additionally writes an explicit `payroll.period.approve` audit row with
maker-checker-override flag (`PayrollPeriodService.php:1239-1249`). **Gap: `ApprovalRecord` itself has
no audit trait** (verified) — mid-chain step decisions that touch only `approval_records` (PR/PO/loan/
bill/salary/asset/return intermediate approves) leave **no `audit_logs` row**; the ledger *is* the
trail (actor + `acted_at` + remarks + workflow snapshot per row), but it is not in the audit log and
carries no IP/user-agent. Leave is the exception that still audits mid-chain (parent
`dept_approver_id` write). Delegation rows are audited (`ApprovalDelegation` uses `HasAuditLog`).

## 4. Inline-approve-from-dashboard readiness (per type — exists vs missing, no design)

What inline approve needs: (a) current-approver check, (b) state check, (c) SoD, (d) idempotency,
(e) row locking. All five exist server-side per type; the board is read-only by design
(`ApprovalBoardService.php:31-34`).

| Type | (a) approver check | (b) state check | (c) SoD | (d) idempotency | (e) locking | Readiness |
|---|---|---|---|---|---|---|
| Leave | engine role/delegate + dept scope (`ApprovalService.php:134-136`; `LeaveRequestService.php:730-749`) | per-arm status (`:328,:351`) + lock re-read | submitter guard + distinct-maker | status gate; bulk per-row try/catch (`:387-438`) | `lockRequest` + row lock | **Ready** — two arms already separated; UI must route dept vs HR arm |
| PR | route perm + engine + dept scope (`PurchaseRequestService.php:601-613`) | `Pending` under lock (`:502-505`) | submitter guard | lock-then-guard; no dup outbox (`:497-504`) | row `lockForUpdate` | **Ready** (+ budget-ack precondition must surface, `:507`) |
| PO | engine (+ vendor SoD, PPAP, budget gates, `:759-803`) | `PendingApproval` fast-path + under lock (`:753-754,810-812`) | submitter guard | same pattern | row lock | **Ready** (must surface gate reasons, not just allow/deny) |
| Bill payment | engine | `PendingApproval` on locked payment + bill-open/cancelled checks (`BillService.php:863-880`) | submitter guard | `idempotency_key` on create + status gates | bill + payment locks | **Ready** |
| Loan | route perm + `canDecide` row scope + engine (`LoanService.php:216-219`) | `Pending` under lock (`:212-215`) | submitter guard + distinct-maker | state machine transition | row lock | **Ready** (bulk exists, `:252-271`) |
| Salary adjustment | route perm (step-agnostic) + engine step role | locked re-read; one-pending-per-employee | submitter guard | `applied_at` once-guard | row lock | **Ready** |
| Asset disposal | route perm + engine (`AssetService.php:469-474`) | `nextStep()` null-checks (`:394-400,425-426`) | submitter guard | JE posts only on full approval inside same tx | row lock | **Ready** |
| Return request | engine (no dept scope) | `PendingApproval` + truck-return guard (`ReturnRequestService.php:1829-1831`) | submitter guard | fail-whole submit; approve-failure throws, never silent 200 (`:1833-1850`) | row lock | **Ready** |
| Payroll period | `payroll.periods.approve` + maker-checker vs creator/computer (`PayrollPeriodService.php:1205-1220`) | `Computed` only + rows-vs-eligible-set match (`:1173-1203`) | maker≠checker (+override perm) | claim fencing per CLAUDE.md (`payroll_cycle_claims` UNIQUE) | row lock | **Ready but board-blind** — no `approval_records` rows exist, so the board has no payroll card to hang the button on; needs a board data source first |
| Custom single-step (OT, stock adj, payroll adj, budget, transfers, prebill, listings, cost review) | permission + self/requester checks per service | status under lock everywhere | requester-cannot-decide everywhere; budget adds different-role | lock-then-guard everywhere | row locks everywhere | **Action-ready, discovery-blind** — none appears on the board (no ledger rows) and none emits waiting notifications; inline approve would need per-type card sourcing + precondition surfacing (budget ack, PPAP, threshold) |

Missing across the board for inline: no existing `POST /approvals/{id}/approve` generic endpoint
(board controller exposes only `GET /approvals/board` + `/approvals/options` behind
`approvals.board.view`, `api/routes/api.php:142-146`); every act route lives on the owning module
with its own permission — a dashboard button must call N different endpoints with N different
payloads (remarks required on reject everywhere; `reject` needs reason length rules per type).

## 5. Discrepancies vs docs / CLAUDE.md, gaps & blockers

1. **Board `payroll` kind is dead.** Registry + `ApprovalSourceScope` document payroll as a board kind
   (`ApprovalTypeRegistry.php:62-69`; `ApprovalSourceScope.php:29-31`), but the `payroll` workflow is
   deliberately unwired (`WorkflowSeeder.php:188-199`) and no `approval_records` rows are ever written
   for periods — the payroll board column can only ever be empty. Either wire it or drop the kind.
2. **CLAUDE.md's "9 seeded workflows / VP top step" matches code** (VP terminates PR/PO/loan/bill/
   salary/asset chains; `vice_president` replaced `system_admin` per `0482_…`, escalation map rewired
   to VP). **But the escalation map still has no `vice_president → ?` entry**, so VP-step escalation
   notifies only the (possibly empty) VP role — the top step is the least rescuable.
3. **Bill-payment chain changed under CLAUDE.md's text**: was Finance→VP, now single VP step with
   ₱50k threshold (`2026_10_09_…`); below-threshold payments skip approval entirely and post —
   correct but undocumented in CLAUDE.md's approval-chain paragraph.
4. **Company-loan step 2 is now `hr_officer`** (was `production_manager` per `2026_10_10_…`), with a
   ₱50k threshold added to the VP step — CLAUDE.md does not describe loan chains at all; the
   `LoanAccessPolicy.DEPARTMENTAL_STEP_ROLE` + `plantWideStepRoles` delegation interplay is the
   operative spec (`LoanAccessPolicy.php:162-186`).
5. **Reserved (defined-but-inactive) workflows** `department_transfer, separation_clearance,
   maintenance_request, 8d_report, work_order, ncr` are seeded `is_active=false` with no `submit()`
   path (`WorkflowSeeder.php:14-23`) — presenting them as approvable (board kinds, UI filters) would
   be false advertising; registry correctly excludes them.
6. **`overtime_request` workflow row deleted** (`0502_…`) — any doc still referencing OT workflow
   approval is stale; OT is custom single-checker (confirmed by `ATTENDANCE-LEAVE-LOANS-AUDIT…:37`).
7. **Asset transfers have an approval service but no routes** (scope cut, `Assets/routes.php:44-48`) —
   pending transfers (if any exist) are undecidable via API.
8. **Sibling doc `A-roles-access.md` vs code**: verify its purchasing/approval claims against
   `PurchaseRequestAccessPolicy`/`PurchaseOrderAccessPolicy` before building on them — the policies,
   not the doc, are authoritative (per the aggregator-scope rule in CLAUDE.md).
9. **Drift guard exists, use it**: `tests/Feature/Approvals/ApprovalChainRolePermissionDriftTest.php`
   pins step-role ↔ route-permission ↔ registry consistency — any chain/permission touch must run it.

**Blockers for a dashboard approval center**: (i) payroll + all custom single-step types need a card
data source (no ledger rows); (ii) 7 of 9+ types lack waiting-decision notifications, so "my action"
counts will surprise approvers who were never told; (iii) mid-chain audit gap (§3) if compliance
needs per-step IP-stamped trails; (iv) auto-resolve off by default — enabling it with
`default_action=reject` silently kills stale requests, with `approve` silently spends money.
