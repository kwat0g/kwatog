# Phase 0 — Section C: Approval Workflows

Original read-only discovery against primary sources on branch `docs/dss-discovery-phase0`; implementation
status below was updated on 2026-10-12.
Engine: `api/app/Common/Services/ApprovalService.php` (step-ledger on `approval_records`);
board: `api/app/Common/Services/ApprovalBoardService.php` (read-only);
SLA sweep: `api/app/Common/Services/ApprovalEscalationService.php` (`approvals:run-escalations`, every 6h);
custom approval sweep: `api/app/Common/Services/CustomApprovalEscalationService.php` (notify-only, deduplicated; auto-resolve disabled);
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
`vice_president` except `department_head → production_manager`; migration
`2026_10_11_150000` adds `vice_president → system_admin` for notification only, without granting
decision authority.
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
| Leave (`leave`) | 1 `department_head` → 2 `hr_officer` (`WorkflowSeeder.php:24-31`); separate `approveDept`/`approveHR` arms, status-gated per arm | `LeaveRequestStatus`: `pending_dept → pending_hr → approved`, `pending_* → rejected`, `→ cancelled` (`Leave/Enums/LeaveRequestStatus.php`); no `draft` — created pending (`LeaveRequestService.php:305`); skips to HR at submit if no eligible head/delegate other than requester | `PATCH /requests/{id}/approve-dept` (`leave.approve_dept`), `PATCH …/approve-hr` (`leave.approve_hr`), bulk variants, `PATCH …/reject` (either) (`Leave/routes.php:41-48`) | Delegation yes (via engine). Submitter = employee's user (`LeaveRequest.php:78-85`). Dept arm additionally scope-checked (`assertDepartmentDecisionScope`, `LeaveRequestService.php`: `leave.approve_hr` bypasses, else same-department only) |
| Purchase request (`pr`) | 1 `finance_officer` → 2 `vice_president` iff `total ≥ ₱50,000` (`WorkflowSeeder.php:57-64`); **RFQ-sourced PRs skip the chain** — submit marks Approved need-only (`PurchaseRequestService.php:419-424`) | `PurchaseRequestStatus`: `draft → pending → approved → converted`; `pending → rejected`; `draft/pending → cancelled` (`PurchaseRequestService.php:371,428-431,511-513,649-654,677`) | `PATCH /purchase-requests/{id}/approve|reject`, `POST /bulk-approve` (`purchasing.pr.approve`), `PATCH …/acknowledge-budget` (`budgeting.approve`) (`Purchasing/routes.php:26,37-39`) | Delegation yes. Submitter = `requested_by` column fallback (`ApprovalService.php:274-279`; `PurchaseRequest.php:38`). Budget ack gate before approve (`PurchaseRequestService.php:507`); dept scope on decide (`assertMayDecide`, `:601-613`) |
| Purchase order (`po`) | 1 `finance_officer` → 2 `vice_president` iff `total ≥ ₱50,000` (`WorkflowSeeder.php:73-80`); buyer (`purchasing_officer`) is creator, never a step | `PurchaseOrderStatus`: `draft → pending_approval → approved → sent → acknowledged … → received/closed`; `→ cancelled` (`Purchasing/Enums/PurchaseOrderStatus.php`) | `PATCH /purchase-orders/{id}/approve|reject` (`purchasing.po.approve`), `…/acknowledge-budget` (`budgeting.approve`) (`Purchasing/routes.php:98-100`) | Delegation yes. Submitter = `created_by` (`PurchaseOrder.php:44`). Extra approve gates: vendor SoD (`assertVendorSod`), PPAP gate, budget enforce (`PurchaseOrderService.php:759-803`) |
| Bill payment (`bill_payment`) | Single `vice_president` iff bill `total ≥ ₱50,000`, else auto-skipped → auto-posts (`2026_10_09_000300_bill_payment_vp_threshold.php`; `BillService.php:841-845`) | `BillPaymentStatus`: `pending_approval → posted`; `→ rejected`; `→ voided` (`Accounting/Enums/BillPaymentStatus.php`) | `POST /{bill}/payments/{payment}/approve|reject` (`accounting.bills.payment_approve`) (`Accounting/routes.php:98-99`) | Delegation yes. Submitter = `created_by` (`BillPayment.php:26`). Single-step → `assertNewDecisionMaker` never fires here |
| Loan / cash advance (`loan`) | CA: `department_head → finance_officer → vice_president`; company loan: `department_head → hr_officer → finance_officer → vice_president` iff `principal ≥ ₱50,000` (`WorkflowSeeder.php:32-50`); submission skips the head step if no active same-department head other than requester | `LoanStatus`: `pending → active → paid`; `pending → rejected|cancelled`; `active → write_off_pending → written_off` (`Loans/Enums/LoanStatus.php`; `LoanService.php:197,222-226,284-285,310-311`) | `PATCH /{loan}/approve|reject`, `POST /bulk-approve` (`loans.approve`); write-off request/approve separate (`loans.write_off.request`, `loans.write_off.approve`) (`Loans/routes.php:17-24`) | Delegation yes (engine + `plantWideStepRoles` visibility, `LoanAccessPolicy.php:170-186`). Submitter = employee's user (`EmployeeLoan.php:69-76`). Decide additionally row-scoped (`canDecide`, `LoanService.php:216-218,280-282`) |
| Salary adjustment (`salary_adjustment`) | 1 `production_manager` → 2 `vice_president`, no thresholds (`WorkflowSeeder.php:96-103`); one pending per employee enforced (`SalaryAdjustmentService.php:49-56`); apply is idempotent on `applied_at` (`:113-117`) | `SalaryAdjustmentStatus`: `pending → approved → (applied)`; `pending → rejected` (`HR/Enums/SalaryAdjustmentStatus.php`; `SalaryAdjustmentService.php:89-106`) | `PATCH /salary-adjustments/{id}/act` (`hr.salary_adjustments.act`; step role enforced by engine) (`HR/routes.php:134-135`; `ActSalaryAdjustmentRequest.php:11-16`) | Delegation yes. Submitter = `requested_by` (`SalaryAdjustment.php:50-53`) |
| Asset disposal (`asset_disposal`) | 1 `finance_officer` → 2 `vice_president`, no thresholds (`WorkflowSeeder.php:142-149`; `0529_…` rewired, retired transfer permissions) | No disposal status — `AssetStatus` stays `active/under_maintenance` until final approve flips to `disposed` + posts JE (`AssetService.php:405-411`); reject/cancel clears proposal columns (`:417-435,476-484`) | `POST /assets/{asset}/dispose` (`assets.dispose`), `…/dispose/approve|reject` (`assets.dispose.approve`), `…/dispose/cancel` (`assets.dispose`) (`Assets/routes.php:32-34`) | Delegation yes. Submitter = `disposal_requested_by` (`Asset.php:104-107`). Double-submit refused via `nextStep()` (`AssetService.php:331-333`) |
| Return request (`return_request`, `finance_only_return_request`) | Standard: `department_head → production_manager`; finance-only: single `finance_officer` (`WorkflowSeeder.php:169-185`; `2026_09_23_100000_…`). QC-generated supplier returns bypass the chain (auto-approved at submit, `ReturnRequestService.php:1763-1779`) | `ReturnRequestStatus`: `draft → pending_approval → approved → received → inspected → completed`; `→ rejected`; `draft/pending/approved → cancelled` (pre-physical only, `:3652-3677`) (`ReturnManagement/Enums/ReturnRequestStatus.php`) | `POST /return-requests/{id}/approve|reject` (`return_management.approve`) (`ReturnManagement/routes.php:33-37`); case actions separate (`case-routes.php:17-18`) | Delegation yes. Submitter = `created_by` fallback (`ReturnRequest.php:83`). QC-submit restricted to `purchasing_officer|system_admin` (`:1728`) |
| Payroll period (`payroll`) | **No chain.** `payroll` workflow exists in seeds but is NOT wired (`wiredTypes` omits it, `WorkflowSeeder.php:188-199`) and `PayrollPeriodService::approve()` never calls `ApprovalService::submit` — custom maker-checker: approver must hold `payroll.periods.approve` and be neither `created_by` nor `computed_by` unless holding `payroll.periods.self_approve_override` (`PayrollPeriodService.php:1205-1220`) | `PayrollPeriodStatus`: `draft → processing → computed → approved → finalized → disbursed`; `→ voided`; computed/legacy-unapproved `→ draft` via requestCorrection (`PayrollPeriodService.php:1173-1179,1259-1277`) | `PATCH /{period}/approve` (`payroll.periods.approve`) (`Payroll/routes.php:62`); correction/finalize/void separate arms | Delegation and notify-only reminder/escalation sweep implemented; no `approval_records` history. `ApprovalBoardService::payrollCards()` sources computed periods separately, checks view + approve permissions, and excludes both makers unless override |

### 1b. Custom single-step approvals (no step-ledger; board and SLA coverage vary)

| Type | Steps / guard | Status machine | Act endpoints + permissions |
|---|---|---|---|
| Overtime | Single checker; self-decision refused via employee→user link (`OvertimeDecisionPolicy.php:51-58`); department policy (`OvertimeService.php:269-312`); active delegation supported, delegate needs `attendance.view` | `pending → approved|rejected` (`Attendance/Enums/OvertimeStatus.php`); `overtime_request` workflow row deleted as dead config (`0502_…`; `ATTENDANCE-LEAVE-LOANS-AUDIT-2026-09-18-FINISHED.md:37`) | `PATCH /overtime-requests/{id}/approve|reject`, `POST /bulk-approve` (`attendance.ot.approve`) (`Attendance/routes.php:59-62`) |
| Stock adjustment | Single checker, `inventory.adjust.approve`; requester cannot decide own (`StockAdjustmentService.php:250-269,301-319`); above-threshold only (threshold `inventory.adjustment_approval_threshold`, `SettingsSeeder.php:433`) | `pending → approved|rejected|withdrawn` (`Inventory/Enums/StockAdjustmentStatus.php`) | `PATCH /stock-adjustments/{id}/approve|reject` (`inventory.adjust.approve`) (`Inventory/routes.php:136-137`) |
| Item cost review | `inventory.costs.review` to request, `inventory.costs.approve` to decide (`ItemCostReviewService.php:31-58`) | `pending → approved|rejected` (`Inventory/Enums/ItemCostReviewStatus.php`) | `POST /cost-reviews/{id}/decide` (`inventory.costs.approve`) (`Inventory/routes.php:29`) |
| Stock-count variance | `approveVariance`, lock-then-guard (`StockCountService.php:277-282`) | item `counted/verified → …` under session `in_progress` | `POST /stock-counts/items/{id}/approve` (`inventory.stock_count.manage`) (`Inventory/routes.php:150`) |
| GRN reject | No approval — warehouse refusal arms | `GrnStatus`: `pending_qc|accepted|partial_accepted|rejected` (CLAUDE.md enum note) | `PATCH /grn/{id}/reject|reject-remainder` (`inventory.grn.create`) (`Inventory/routes.php:177-178`) |
| Payroll adjustment | Single checker; requester (`created_by`) cannot approve own (`PayrollAdjustmentService.php:102-122`) | `pending → approved → applied`; `→ rejected` (`Payroll/Enums/PayrollAdjustmentStatus.php`) | `PATCH /{adjustment}/approve` (`payroll.adjustments.approve`), `…/reject` (`payroll.adjustments.reject`) (`Payroll/routes.php:115-116`) |
| Budget | Single checker; submitter cannot approve own **plus** different-role-from-submitter rule (`BudgetService.php:133-163`) | `draft → submitted → active` (`approved` legacy); reject returns to `draft` (`Accounting/Enums/BudgetStatus.php`; `BudgetService.php:110-204`) | `POST /{budget}/approve|reject` (`budgeting.approve`) (`Accounting/routes.php:172-173`); submit arm same file |
| Budget transfer | Checker arm (`BudgetTransferService.php:86-141`) | `pending → approved|rejected` (`Accounting/Enums/BudgetTransferStatus.php`) | `POST /{transfer}/approve|reject` (`budgeting.approve`) (`Accounting/routes.php:182-183`) |
| Invoice prebill | `approvePrebill` (`InvoiceService.php:244`); customer payment submissions reviewed under `accounting.invoices.collect` (`InvoicePaymentSubmissionService.php:99-131,225`) | prebill invoice states (`Invoice/Enums/InvoiceStatus.php`) | `POST /{invoice}/approve-prebill` (`accounting.invoices.prebill_approve`); `POST …/payment-submissions/{id}/reject` (`accounting.invoices.collect` + `throttle:sensitive`) (`Accounting/routes.php:126-131`) |
| Supplier listing | Reviewer decide (`SupplierListingService.php:128-170`) + notify on submit (`:344`) | `pending → approved|rejected|superseded` (`Purchasing/Enums/SupplierListingStatus.php`) | `PATCH /supplier-listings/{id}/approve|reject`, bulk arms (`purchasing.supplier_listings.review`) (`Purchasing/routes.php:121-124`) |
| Asset transfer | Checker; requester cannot approve own (`AssetTransferService.php:81-92,124-129`) | `pending → approved → completed`; `→ rejected` (`Assets/Enums/TransferStatus.php`) | **Out of scope** — permissions retired and no API route can create transfers; running database has zero rows. Reopen only after custody policy is approved |
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
VP steps escalate to `system_admin` for notification (`2026_10_11_150000`); that role gains no approval
authority from the escalation setting. Payroll periods and ten custom single-step queues use a separate
notify-only sweep; it does not create ledger rows or auto-decide.

1. **Leave — CLOSED.** Submission skips department step 1 and routes to HR when the requester's
   department has no active head or scoped delegate other than the requester. The ledger records the
   skipped step and reason; HR receives the pending notice. If another eligible head/delegate exists,
   the normal department scope and submitter guards remain unchanged. Different users still act at the
   two approval steps.
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
   active VP (and no delegate): approval still waits for a valid VP/delegate; the sweep now notifies
   system_admin after escalation. Self-guard via `created_by` (finance staff who stage the payment cannot also
   approve it — intended SoD, but a single-finance-user site stalls here too).
5. **Loans — headless/sole-head path closed.** Both chains start with `department_head`; submission
   skips that step when no active same-department head other than the requester exists, then notifies
   the next role (Finance for cash advances, HR for company loans). With a head available, the normal
   department scope and self-approval guard remain. Chain-participant branch (`isChainParticipant`,
   `LoanAccessPolicy.php:147-160`) lets later step holders read rows they could not otherwise open.
   `0534_…` migrated legacy pending rows to VP, evidence this class stalled in production before.
   Government loan types (`sss_loan`,
   `pagibig_loan`) are unsupported (`LoanType.php:9-12`, `isSupported`) so no chain exists for them —
   N/A, not stalled.
6. **Salary adjustment — default PM self-request is route-blocked (F-013 closed).** Step 1
   `production_manager`; the seeded PM role has view/act but not `hr.salary_adjustments.request`,
   verified by a submit-route 403 test. HR submits by default, so PM self-guard cannot stall that
   path. An explicit per-user maker grant to a PM can still expose the self-stall (submitter is
   `requested_by`, `SalaryAdjustment.php:50-53`). Step 2 remains VP; `act` route permission is
   step-agnostic and the engine enforces role (`ActSalaryAdjustmentRequest.php:11-16`).
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
   (`PayrollPeriodService.php:1205-1220`). Computation now notifies eligible approvers; scoped delegation
   and recurring reminder/escalation notices are implemented. The board sources computed periods
   separately; the queue still has no synthetic `approval_records` history.
10. **Custom single-step approvals (OT, stock adjustment, payroll adjustment, budget, budget transfer,
    invoice prebill, supplier listing, cost review, stock-count variance, loan write-off) — STALL RISK**
    remains if no eligible checker or delegate exists. These queues do not write `approval_records`;
    the six-hour sweep now sends deduplicated reminders/escalations and custom decision endpoints honor
    active permission-scoped delegation. Delegates need existing source-module read permission, and
    makers remain excluded. Escalation can notify `system_admin`; it never auto-decides. Self-guards
    remain in force (OT `OvertimeDecisionPolicy.php:51-58`; budgets/transfers also enforce different-role
    rule, `BudgetService.php:142-151`), so the failure mode is *stall*, never self-approval. Circular:
    none (all single-checker, strictly forward status machines).

**Remaining default stuck-risk count: 3 types with a concrete stall path** (bill-payment VP-empty above
threshold; payroll-period maker-checker without second holder; custom single-step family with no
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
| Loan submit | `LoanSubmitted` → `NotifyOnLoanSubmitted`: roles in `loans.submitted.notification_roles` (`Loans/Listeners/NotifyOnLoanSubmitted.php:35-48`) | in-app+email | next-step handoffs now also receive `approval.action_required`; delivery verification pending |
| Loan decided (final only) | `LoanDecided` → requester's user (`NotifyOnLoanDecided.php:35-45`) | in-app+email | terminal notices retained; next-step handoffs now covered by `approval.action_required` |
| PR submit | `approval.action_required` to the current Finance role holders and active delegates | in-app/email/Reverb after commit | runtime delivery verification pending |
| PR/PO fully approved | `PurchaseRequestApproved`/`PurchaseOrderApproved` → configured roles (`NotifyOnPurchaseRequestApproved.php`, `NotifyOnPurchaseOrderApproved.php:23-35`); PO also triggers supplier dispatch prep (`AppServiceProvider.php:382-384`) | in-app+email | Finance→VP handoffs now receive `approval.action_required`; runtime delivery verification pending |
| PO submitted | `PurchaseOrderSubmitted` → notify (`AppServiceProvider.php:383`) | in-app+email | — |
| Bill payment submit/approve/reject/post | `approval.action_required`, `approval.completed`, `approval.rejected`, `bill_payment.posted` | current VP/delegates; final outcome to submitter | implemented; delivery verification pending |
| Salary adjustment request/approve/reject | workflow action-required, next-step handoff and terminal outcome notices | current step roles/delegates; final outcome to submitter | implemented; delivery verification pending |
| Asset disposal request/approve/reject | workflow action-required, next-step handoff and terminal outcome notices | current step roles/delegates; final outcome to submitter | implemented; delivery verification pending |
| Return submit/approve/reject | existing portal/customer notices plus `approval.action_required` for internal steps | in-app/email/Reverb after commit | implemented; delivery verification pending |
| Payroll compute/approve | `approval.action_required` after normal or 13th-month computation; employee payslip notice remains on finalize | eligible non-maker approvers; system-admin fallback if no eligible reviewer | implemented; delivery verification pending; computed periods have a separate read-only board source |
| OT submit/decide | `OvertimeRequestSubmitted/Decided` → dept approvers / requester (`NotifyOnOvertimeSubmitted/Decided`, `AppServiceProvider.php:415-416`) | in-app+email | — |
| Supplier listing submit | `supplier_listing_submitted` to reviewers (`SupplierListingService.php:344`) | in-app+email | decide notification not verified |
| Stock adjustment, payroll adjustment, budget, budget transfer, invoice prebill, cost review | Action-required on submit and completed/rejected notices on available decision paths; cost capture reuses pricing-review notice | current permission holders or requester, according to each service's maker-checker rule | implemented where transitions exist; delivery verification pending; prebill has no rejection endpoint |
| SLA reminder/escalation/auto-escalation | `approval_reminder` / `approval_escalation` via ledger and custom sweeps; both dedupe notifications; custom escalation excludes makers and includes active `system_admin` recipients | in-app+email | payroll periods + ten custom queues are notify-only; auto-resolve disabled; delivery verification remains pending; VP escalation notifies system_admin |

Audit: every parent document model carries `HasAuditLog` (verified present on PR, PO, Leave, Loan,
PayrollPeriod, BillPayment, SalaryAdjustment, Asset, ReturnRequest, StockAdjustment, OT, Budget,
BudgetTransfer, PayrollAdjustment) — status flips and actor columns write `audit_logs` rows with
IP/user-agent/source/correlation (`HasAuditLog.php:66-96`), encrypted fields redacted (`:102-133`).
Payroll approve additionally writes an explicit `payroll.period.approve` audit row with
maker-checker-override flag (`PayrollPeriodService.php:1239-1249`). `ApprovalRecord` now uses
`HasAuditLog`; bulk status mutations update each model so observer events can write audit rows.
`ApprovalDelegationTest::test_approval_record_audit_captures_actor_ip_and_user_agent` verifies an HTTP
decision writes actor, IP, user-agent and request ID. Production samples remain unavailable. Leave also retains its parent
`dept_approver_id` audit. Delegation rows are audited (`ApprovalDelegation` uses `HasAuditLog`).

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
| Payroll period | `payroll.periods.approve` + maker-checker vs creator/computer (`PayrollPeriodService.php:1205-1220`) | `Computed` only + rows-vs-eligible-set match (`:1173-1203`) | maker≠checker (+override perm) | claim fencing per CLAUDE.md (`payroll_cycle_claims` UNIQUE) | row lock | **Board-visible, read-only** — `ApprovalBoardService::payrollCards()` queries computed periods with view/approve and maker-checker gates; approve still uses the payroll endpoint; no ledger history or custom sweep |
| Custom single-step (OT, stock adj, payroll adj, budget, transfers, prebill, listings, cost review, stock-count variance, loan write-off) | permission + source row scope + scoped delegation + self/requester checks | module-specific status gates | requester-cannot-decide; budget adds different-role | lock-then-guard | row locks | **Board-visible, read-only** — separate source cards and notify-only reminder/escalation sweep cover these ten queues; action stays on module endpoints; live notification delivery remains unverified |

Missing across the board for inline: no existing `POST /approvals/{id}/approve` generic endpoint
(board controller exposes only `GET /approvals/board` + `/approvals/options` behind
`approvals.board.view`, `api/routes/api.php:142-146`); every act route lives on the owning module
with its own permission — a dashboard button must call N different endpoints with N different
payloads (remarks required on reject everywhere; `reject` needs reason length rules per type).

## 5. Discrepancies vs docs / CLAUDE.md, gaps & blockers

1. **Payroll remains outside the step ledger.** Registry + `ApprovalSourceScope` document payroll as a board kind
   (`ApprovalTypeRegistry.php:62-69`; `ApprovalSourceScope.php:29-31`), but the `payroll` workflow is
   deliberately unwired (`WorkflowSeeder.php:188-199`) and no `approval_records` rows are ever written
   for periods. The board now uses a separate computed-period source with maker-checker gates; it does
   not invent ledger records.
2. **CLAUDE.md's "9 seeded workflows / VP top step" matches code** (VP terminates PR/PO/loan/bill/
   salary/asset chains; `vice_president` replaced `system_admin` per `0482_…`, escalation map rewired
   to VP). VP stalls now notify `system_admin`; this does not grant system administrators approval rights.
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
7. **Asset transfers remain an explicit scope cut** (`Assets/routes.php:44-48`): former transfer permissions are retired, no API route can create transfers, and the running database has zero rows. Do not expose the checker service until a custody policy is approved.
8. **Sibling doc `A-roles-access.md` vs code**: verify its purchasing/approval claims against
   `PurchaseRequestAccessPolicy`/`PurchaseOrderAccessPolicy` before building on them — the policies,
   not the doc, are authoritative (per the aggregator-scope rule in CLAUDE.md).
9. **Drift guard exists, use it**: `tests/Feature/Approvals/ApprovalChainRolePermissionDriftTest.php`
   pins step-role ↔ route-permission ↔ registry consistency — any chain/permission touch must run it.

**Remaining blockers for a complete dashboard approval center**: (i) separate card sources now cover
payroll periods plus OT, stock adjustment, cost review, stock-count variance, payroll adjustment,
budget, budget transfer, invoice prebill, supplier listing and loan write-off; synthetic
`approval_records` were not added. GRN rejection is not an approval; return cases use a state machine;
PPAP has no SPA review route; late production reports only expose a per-work-order list, so those are not
represented as global cards; (ii) submit, decision, handoff and SLA notifications are implemented but
live delivery verification remains pending; (iii) approval-record audit events are verified for actor,
IP, user-agent and request ID by HTTP feature test; no historical audit rows existed in the running DB;
(iv) auto-resolve stays disabled because timeout-driven decisions would act without an approver.
