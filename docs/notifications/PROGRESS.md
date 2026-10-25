# Notification and badge remediation progress

**Updated:** 2026-10-25
**Current gate:** Phase 2 approved; PR #359 fixes permission-change notice copy, PR [#362](https://github.com/kwat0g/kwatog/pull/362) narrows invoice payment review recipients, PR [#363](https://github.com/kwat0g/kwatog/pull/363) scopes AR dunning notices, payroll audience/access/link fixes are in draft PR [#364](https://github.com/kwat0g/kwatog/pull/364), training-expiry deep links are in draft PR [#365](https://github.com/kwat0g/kwatog/pull/365), alert unread/list parity is in draft PR [#366](https://github.com/kwat0g/kwatog/pull/366), budget-transfer badge/list parity is in draft PR [#367](https://github.com/kwat0g/kwatog/pull/367), and AP overdue badge/list parity is in draft PR [#369](https://github.com/kwat0g/kwatog/pull/369). Recipient ownership rules remain open.

## Phase status

| Phase | Status | Evidence |
| --- | --- | --- |
| 0. Discovery | Draft complete | `00-discovery.md`: 23 modules, 129 in-app service call sites, 27 direct `Mail::to` sites, scheduled trigger table, static route-target scan, 14 broadcast classes, 73 internal routes at capture, 19 portal routes, nine compact-shell links, 29 badge definitions. Static source review plus aggregate-only local DB query. |
| 1. Inventory / leak analysis | Draft complete; runtime proof outstanding | `01-inventory.md`: source audience expressions, direct delivery paths, 17-role static selector-risk matrix, 77 email-failure wrapper call sites, payload/link/dedupe expressions, badge audit, root causes, module coverage. Initial audit had no seeded-role scenarios; later targeted badge/list comparisons are recorded below. |
| 2. Policy and plan | Approved | User approved the proposed defaults on 2026-10-25. |
| 3. Backend foundation | In progress | Units 1–4 have PRs (#332–#334, #370); F-17 no-fallback fix is in PR #335; F-16 outcome access guards are in PR #336. Core approval notices resolve scoped recipients; inaccessible outcome recipients are suppressed across approval, budget, bill, invoice, and stock-adjustment paths; user-channel broadcasts carry IDs/types only and hydrate through an owner-scoped endpoint. Global authorization coverage, links, signal registry, and remaining event families are open. |
| 4. Frontend | In progress, partial | Sidebar now links the transfer badge to its filtered list; notification bell/center and multi-signal rendering remain open. |
| 5. Migration | In progress, partial | Budget-transfer badge/list scope fixed in this branch; remaining notification dispatches and badge definitions are unmigrated. |
| 6. Extras | Not started | No extra features started. |
| 7. Verification | Partial | Targeted Phase 3 unit 1–3 tests ran; role-leak, badge parity, frontend interaction, and full-suite verification remain open. |

## Phase 3 PR stack

| Unit | Branch | PR | Commit |
| --- | --- | --- | --- |
| 1. Versioned payload contract | `notifications/phase3-central-contract` | [#332](https://github.com/kwat0g/kwatog/pull/332) | `a8a479e0` |
| 2. Core approval recipients | `notifications/phase3-recipient-resolver` | [#333](https://github.com/kwat0g/kwatog/pull/333) | `e85cc70f` |
| 3. Realtime payload security | `notifications/phase3-realtime-payload` | [#334](https://github.com/kwat0g/kwatog/pull/334) | `cee47e18` |
| 4. Laravel notification dispatch centralization | `notifications/centralize-laravel-notifications` | [#370](https://github.com/kwat0g/kwatog/pull/370) | `f6519ec1`, `7b7d5953` |
| F-17. Custom approval escalation audience | `notifications/fix-custom-approval-escalation-audience` | [#335](https://github.com/kwat0g/kwatog/pull/335) | `50abb689`, `9b1c78c5` |
| F-16. Approval outcome access gates | `notifications/fix-approval-outcome-access` | [#336](https://github.com/kwat0g/kwatog/pull/336) | `c8115e8f`, `6f528ba5` |
| F-03. Purchase-request badge/list scope | `notifications/fix-purchase-request-badge-scope` | [#348](https://github.com/kwat0g/kwatog/pull/348) | `6c25d82c` |
| F-04. Badge destination permission gates | `notifications/fix-badge-permission-gates` | [#349](https://github.com/kwat0g/kwatog/pull/349) | `3b759909` |
| F-02. Approval badge source scope | `notifications/fix-approval-badge-scope` | [#337](https://github.com/kwat0g/kwatog/pull/337) | `af68eb60` |

## Coverage ledger

- NotificationService call sites inventoried: **129 / 129** found by scan and cross-check; all 129 remain on existing entry points.
- Direct `Mail::to(...)` call sites inventoried: **27 / 27** under `api/app`; mail recipient runtime outcomes not tested.
- Direct Laravel `->notify()` / `Notification::send()` and central preference email paths inventoried in discovery; mail-only account/security and summary paths remain separate.
- Internal sidebar routes: **73** in the Phase 0 baseline; current branch adds `/budgeting/transfers` (**74 current**); portal routes: **19**; compact-shell links: **9**.
- Phase 2 defines **87** signal IDs; baseline cross-reference covered 73 internal, 19 portal, and nine compact routes. The current `/budgeting/transfers` route is an implementation delta; the approved combined budget signal still targets `/budgeting?signal=awaiting_my_approval` and is not complete.
- Badge definitions inventoried: **29**; baseline wired to internal nav: **22**; current branch wires **23**, leaving **6** unattached.
- Local role slugs: **17**; existing role-access audit says **16**. Reconcile before role-complete claim.
- Status/signal catalog: candidate data reviewed for all **23 modules**; exact enum classification, scope parity, and business approval remain outstanding.
- Unit 1 code changes are limited to `NotificationService` envelope normalization, its unit and portal tests, the SPA notification data type, and schema/progress documentation. No database migration or existing-row rewrite is needed.
- Payload schema v1 fields: `schema_version`, `event_type`, optional `subject_type`, `subject_id`, and caller-supplied `actor_id`. Existing `title`, `message`, `link_to`, event timing, recipients, channels, and legacy rows remain unchanged.
- Core `ApprovalService` action-required notices now resolve only the active step's role holders and active delegates who pass `ApprovalTypeRegistry::userCanViewRecord`; exclude submitter and known acting approver. Store `recipient_reason` on each in-app row. Core, budget, transfer, bill-payment, invoice, and stock-adjustment outcome notices are suppressed when recipients cannot open their destination record/list. Other approval senders and event families remain on existing paths.
- Realtime notification events now publish only notification ID and type on the recipient's private channel; channel authorization requires both matching user ID and `notifications.view`. SPA fetches full content from `GET /notifications/{id}`, scoped to that user. `badges` remains an authenticated private, content-free refresh channel.

## Current confirmed findings

See `FINDINGS.md`. Remaining high-priority static mismatches include the profile badge attached to the wrong route, missing metadata for three emitted event patterns, notification links/UI behavior, and configured-role audiences without record scope. F-02 reuses the approval board filter; F-03 shares a PR current-step scope across the badge, pending-count endpoint, and filtered list; F-04 aligns three badge gates to their destination reads; F-16 is fixed at all six inventoried sender paths. F-23's 100-item board summary cap fix is tracked in PR #347. No public channel or all-users notification dispatch found. No runtime cross-role leak has been proven.

## Phase 3 unit 1 verification

- API: `NotificationServiceHardeningTest` **21 passed / 58 assertions**; `NotificationServiceTest` **8 passed / 26 assertions**; `PortalNotificationsTest` **7 passed / 33 assertions**; `NotificationCatalogTest` **8 passed / 54 assertions**.
- PHP syntax checks passed for every changed PHP file; `git diff --check` passed.
- Pint passed on the three changed PHP files.
- SPA `npm run typecheck` failed on files outside this unit: `src/api/exports.test.ts`, `src/components/ui/ExportButton.tsx`, `src/pages/admin/users/invites.tsx`, `src/pages/auth/accept-invite.tsx`, `src/pages/error/Forbidden.tsx`, `src/pages/inventory/stock-transfers/create.tsx`, and `src/pages/purchasing/purchase-orders/create.tsx`. No error named a changed notification file.
- No route/template registry, message rendering changes, recipient resolver, authorization gate, Reverb hardening, or badge code shipped in unit 1. Those remain open units.

## Phase 3 unit 2 verification

- API: `NotificationServiceHardeningTest` **22 passed / 62 assertions**; `ApprovalNoticeVisibilityTest` **3 passed / 20 assertions**; asset disposal full-chain test **1 passed / 19 assertions**; `ApprovalServiceTest` **18 passed / 44 assertions**; `ApprovalDelegationTest` **19 passed / 72 assertions**.
- Pint passed on all six changed PHP files. Tests exercise per-user reasons, direct approvers, active delegates, missing view access, inactive/unrelated users, submitter/actor exclusions, and the real approval record path.
- This is a core `ApprovalService` slice, not whole-project recipient coverage. Custom approval queues, escalations/reminders, submitter outcomes, and other module dispatch sites remain unmigrated or unverified.

## Phase 3 unit 3 verification

- API: `NotificationServiceTest` **8 passed / 26 assertions**; `NotificationServiceHardeningTest` **22 passed / 62 assertions**; `NotificationControllerTest` **10 passed / 30 assertions**; `BroadcastAuthorizationTest` **4 passed / 5 assertions**.
- PHP syntax checks passed. Broadcast payload test verifies only `id` and `type`; API tests verify own-user fetch and cross-user 404; channel tests verify self access, cross-user denial, unauthenticated denial, and denied permission.
- SPA typecheck still fails only in unrelated export, invite, stock-transfer, and purchase-order files. No changed notification file reports an error. Browser/toast behavior was not exercised.
- This unit does not change notification recipient selection, event timing, stored rows, or portal notification APIs. Global payload/access authorization remains open.

## Separate finding fix: F-17

- Custom approval escalation no longer adds every active `system_admin` after record-specific recipient checks. `CustomApprovalDelegationAndEscalationTest` passed **5 tests / 29 assertions**, including a prebill editor exclusion case. Existing broken assertions in that test were repaired so the suite runs.
- Escalation timing is unchanged. No supervisor recipient resolver was found in this service; `approval_escalation` still goes to eligible approvers. See F-21 for this remaining policy gap.

## Separate finding fix: F-16 approval outcomes

- `ApprovalService::notifySubmitter()` now returns before dispatch when the submitter fails `ApprovalTypeRegistry::userCanViewRecord()`. Budget, transfer, bill-payment, invoice, and stock-adjustment senders now suppress recipients without the destination route's view permission. Eligible messages, links, and event timing stay unchanged.
- Positive and negative coverage: `ApprovalSubmitterOutcomeVisibilityTest` **1/5**; asset disposal full-chain/rejection **2/26**; `ApprovalServiceTest` **18/44**; budget outcome **1/1**; transfer outcome **1/2**; invoice denied **1/58** and allowed **1/5**; bill denied **1/15** and allowed **1/20**; stock-adjustment scope **1/5** (tests/assertions).
- Budget, transfer, invoice, and bill checks match destination route permissions. Stock-adjustment list access accepts `inventory.adjust` or `inventory.adjust.approve`; either supports the existing list link.
- PR #336 CI backend job fails before PHPUnit on the existing duplicate `PdfRenderService::ensureDompdfDirs()` declaration in `origin/main`; tracked separately as F-22. Local targeted tests pass.
- This closes F-16's six inventoried sender paths. Broader subject authorization across other notification families remains open for central resolver work.

## Separate finding fix: F-03 purchase-request badge scope

- `PurchaseRequestAccessPolicy::awaitingMyApproval()` now supplies the common current-step filter to the sidebar badge, existing pending-count endpoint, and purchase-request list. It respects current direct/delegated roles, module row visibility, pending PR state, and self-submission exclusion.
- A positive purchase-request badge opens `/purchasing/purchase-requests?signal=awaiting_my_approval`; the list exposes that filter and an All requests option.
- `BadgeControllerTest.php`: **12 passed / 124 assertions**, including department boundary, wrong role, self-submission, badge/list/count parity, and active delegate cases. `PurchaseRequestHardeningTest.php`: **15 passed / 36 assertions**. `Sidebar.behavior.test.tsx`: **24 passed**.
- PHP syntax, `git diff --check`, targeted ESLint, and Pint on the policy, service, and controller passed. `BadgeService.php` has existing file-wide Pint formatting differences; it was not reformatted. SPA typecheck still reports unrelated errors in export, action-center, invite, stock-transfer, purchase-order, and profile files; no changed file was named.
- PR #348 checks: backend job fails before PHPUnit on F-22 (`PdfRenderService::ensureDompdfDirs()` redeclaration); SPA job stops at the repository dependency audit (Vitest mocker, `undici`, and `zod` advisories). Accessibility and visual-baseline checks pass. Dependency updates and F-22 remain separate work.

## Separate finding fix: F-04 badge destination permissions

- Payroll, complaints, and delivery badge gates now match the read permissions on their sidebar destinations: `payroll.periods.view`, `crm.complaints.view`, and `supply_chain.view OR supply_chain.deliveries.view`.
- `BadgeControllerTest.php`: **13 passed / 141 assertions**. Seeded roles verify employee `payroll.view` alone does not expose payroll-period counts, VP read-only complaint access and warehouse narrow delivery access both open their list and receive the corresponding badge, and HR period access receives the payroll badge.
- PHP syntax and `git diff --check` passed. Badge count/list record-scope parity remains F-09; this change verifies permission visibility only.

## Separate finding fix: F-18 pricing notification role audience

- `PricingReviewService::notify()` now intersects its existing destination permission check with the role allowlist supplied by each sender. Empty role lists retain the current permission-only cost-review queue behavior.
- `PricingReviewNotificationAudienceTest.php`: **2 passed / 6 assertions** through the direct PHPUnit file target. A seeded HR role granted `crm.price_agreements.view` receives no pricing-risk notice; a seeded Finance user does.
- Pint and PHP syntax checks passed. `php artisan test --filter=PricingReviewNotificationAudienceTest` could not bootstrap because unrelated `AudienceFakeDetector::detect()` return type conflicts with `DetectorInterface`; direct PHPUnit execution of the target file passed.
- PR [#350](https://github.com/kwat0g/kwatog/pull/350) is stacked on #349. `finding-lifecycle` passes; API CI fails before PHPUnit on F-22 duplicate `PdfRenderService::ensureDompdfDirs()`.
- The policy's assigned reviewer/account owner is not represented on Product, PriceAgreement, Customer, or ItemCostReview. Product/account ownership targeting remains open. Confirm whether `inventory.costs.review` holders are the correct shared queue for cost-review notices; the current list endpoint is permission-wide.

## Separate finding fix: F-20 sales-order confirmation notice

- Configured planners must hold both `production.schedule.view` and `crm.sales_orders.view`. When the confirming user is captured, the order creator also receives the notice if they can open the order; the confirmer is excluded. Legacy outbox payloads default actor to null and do not notify the creator. The record link, order number, subject fields, and per-confirmation dedupe key are included.
- `OutboxFactEventToleranceTest.php`: **11 passed / 30 assertions**, including exact route/payload, duplicate delivery, creator and actor targeting, legacy payload compatibility, and negative permission cases. Pint passed on the event, listener, and test; PHP syntax passed on all four changed PHP files.
- PR #351 is stacked on #350. `created_by` is the only active user ownership relation; `sales_rep_id` exists in schema but no application writer/reader was found. Confirm whether imported/nonstandard sales-representative values should also receive notices.
- PR #351 `finding-lifecycle` passes; API CI fails before PHPUnit on F-22, duplicate `PdfRenderService::ensureDompdfDirs()`.

## Separate finding fix: F-12 sales-order broadcast payload

- `SalesOrderConfirmed` still broadcasts on the shared private `production.dashboard` channel, but now sends only `{ refresh: true }`. The SPA handler ignores event payload and invalidates production dashboard queries, so no order number, customer, or amount is needed.
- `OutboxFactEventToleranceTest.php`: **12 passed / 33 assertions**, including the private channel and exact `refresh`-only payload. Pint and PHP syntax checks passed.
- PR #352 is stacked on #351. CI was pending at last check. Broader subject-channel authorization and payroll metadata review remain open.

## Separate finding fix: F-12 shared dashboard broadcast payloads

- Production and maintenance dashboard listeners only invalidate/refetch their lists or dashboard queries. Events on those shared channels now carry only `{ refresh: true }`; identifiers, quantities, reasons, candidate lists, and descriptions stay off broad dashboard channels.
- The production output page now refetches the authorized work-order detail after an output event instead of consuming cumulative output values from the event payload.
- `DashboardBroadcastPayloadTest`: **1 passed / 8 assertions**. The plan event's focused outbox test: **1 passed / 3 assertions**, retaining the active/cancelled delivery guard. The SPA `record-output.test.tsx`: **5 passed**. Pint, ESLint, and `git diff --check` passed.
- Payroll progress channel uses `payroll.periods.view`, matching the period detail endpoint's current gate. Its progress counts/status payload is unchanged; the sensitive HR/Finance audience question remains open.
- PR [#358](https://github.com/kwat0g/kwatog/pull/358): `finding-lifecycle`, accessibility, and visual baselines pass. API CI stops before PHPStan/PHPUnit on inherited F-22 (`PdfRenderService::ensureDompdfDirs()` redeclaration). SPA CI stops at dependency audit (2 moderate, 2 high vulnerabilities), skipping lint/typecheck/Vitest/build. Locally, the targeted API tests, SPA test, Pint, ESLint, and diff check pass; full SPA typecheck reports existing errors outside the changed page.

## Separate finding fix: F-15 permission-override notice copy

- Permission-change notices use the permission's display name and describe the rule as allowing or blocking access. They tell the affected user to contact a system administrator if unexpected; no link is included because the only matching admin user page requires `admin.users.manage`, and no self-service override page exists.
- Audience stays unchanged: the affected user receives each notice; the system administrator who made the change does not.
- `PermissionOverrideNotificationCopyTest`: **1 passed / 29 assertions**, covering add, update, remove, restore, no-link copy, raw slug/enum exclusion, and actor exclusion. Pint and `git diff --check` pass.
- Queue long-wait link remains in separate PR [#346](https://github.com/kwat0g/kwatog/pull/346); verify it before marking F-15 fully closed.
- PR #359 `finding-lifecycle` passes; API CI fails before PHPUnit on inherited F-22 (`PdfRenderService::ensureDompdfDirs()` redeclaration).

## Separate finding fix: F-24 invoice payment review audience (PR [#362](https://github.com/kwat0g/kwatog/pull/362))

- The payment-submission flow is a shared Finance review queue, not an assigned queue: `InvoicePaymentSubmission` has no reviewer assignment, and verify/reject routes use `accounting.invoices.collect`. Invoice detail requires `accounting.invoices.view`; the list/detail service adds no record-specific row scope.
- Recipients now need active `accounting.invoices.collect` and `accounting.invoices.view` access. Exclude `system_admin`, whose permission bypass made it appear to be a Finance reviewer. Event timing, dedupe key, message, link, and review workflow stay unchanged.
- `InvoicePaymentSubmissionServiceTest`: **13 passed / 80 assertions**. Negative checks exclude system administrators, inactive Finance users, a user granted collection permission without invoice visibility, and a view-only executive; the Finance Officer receives the exact invoice link.
- Empty-recipient fallback/audit remains open in the central notification service. Do not use a generic system-admin fallback for customer payment data without an explicit business rule.

## Separate finding fix: F-25 AR dunning audience and links (PR [#363](https://github.com/kwat0g/kwatog/pull/363))

- Final-tier escalation, missing-email fallback, and queued-mail failure now share active AR reviewers who hold both `accounting.invoices.collect` and `accounting.invoices.view`; exclude `system_admin` permission bypass. Queued mail failure re-resolves current access rather than trusting its queue-time ID snapshot.
- Internal dunning escalation and email-failure notices link to the exact invoice. Customer reminder recipients and portal links stay unchanged; dunning schedule, tier timing, and email preference behavior stay unchanged.
- `RunArDunningCommandTest`: **12 passed / 58 assertions**. Positive and negative cases cover Finance Officer, system administrator, view-only executive, and collection access revoked before a queued email failure.
- Updated three stale test expectations from `PHP` text to the configured `₱` display. Pint, PHP syntax, and `git diff --check` pass.
- No eligible AR reviewer still produces no notice or audit. Central empty-recipient fallback remains open; no generic administrator fallback is used for finance data.

## Separate finding fix: F-02 approval badge scope

- `BadgeService.approvals` now reads the approval board's `summary.my_action`, so it inherits the board's supported type, permission, delegation, and source-row filtering. The test verifies badge/board count parity and excludes an out-of-department leave row plus an unsupported approval type.
- Targeted API tests: `BadgeControllerTest` selected tests **3 passed / 12 assertions**. The board summary previously capped at the requested page size (default 100); F-23 fixes the summary/badge total independently.
- Full BadgeControllerTest and all-role badge/list parity were not run. No frontend changes.

## Separate finding fix: F-09 pending-QC GRN badge parity

- PR [#353](https://github.com/kwat0g/kwatog/pull/353) is a draft stacked on #352.
- `GoodsReceiptNote::pendingQc()` now defines the shared count/list condition. The `pending_grn` badge and GRN list's `status=pending_qc` filter use that scope; warehouse staff badge permission matches the list route's `inventory.view` gate.
- A positive GRN badge opens `/inventory/grn?status=pending_qc`. Phase 2's route entry now matches the SPA's real query parameter; `signal=pending_qc` was not a supported filter.
- `BadgeControllerTest::test_pending_grn_badge_matches_the_filtered_receipt_list`: **1 passed / 6 assertions**, comparing the warehouse user's badge count with the filtered list total and returned statuses. `Sidebar.behavior.test.tsx`: **25 passed**.
- Scope covers only pending-QC GRNs. Other sidebar signals and cross-role/scope parity remain F-09 work; no full suite or visual QA ran.
- PR #353 CI: `finding-lifecycle`, accessibility, and visual baselines pass. API job fails before PHPUnit on inherited F-22 (`PdfRenderService::ensureDompdfDirs()` duplicate); SPA job stops at the existing dependency audit (4 vulnerabilities). Targeted API and SPA tests pass locally.

## Separate finding fix: F-09 inspection badge parity

- PR [#354](https://github.com/kwat0g/kwatog/pull/354) is a draft stacked on #353.
- `Inspection::toInspect()` now defines the shared draft/in-progress predicate for both the `pending_inspections` badge and `work_queue=to_inspect` list queue. A positive badge opens that queue.
- `BadgeControllerTest::test_widened_scope_badge_counts_track_recent_rows`: **1 passed / 18 assertions**, including parity under the `qc_inspector` role. `Sidebar.behavior.test.tsx`: **26 passed**.
- This remains an area-wide queue matching the current list. The proposed per-user inspection action signal needs separate assignment/authorization verification.

## Separate finding fix: F-09 assigned complaint badge parity

- PR [#357](https://github.com/kwat0g/kwatog/pull/357) is a draft stacked on #354.
- `CustomerComplaint::openAssignedTo()` defines active complaints (`open`, `investigating`) assigned to one user. Both the `open_complaints` badge and `signal=open` list use it; the sidebar opens that list, the page exposes an All/Assigned queue filter, and its header names the assigned scope.
- The badge and filtered list require both complaint visibility and manage permission. `BadgeControllerTest::test_widened_scope_badge_counts_track_recent_rows`: **1 passed / 33 assertions**, including assigned admin/CSO users, an unassigned CSO, and a view-only VP. `Sidebar.behavior.test.tsx`: **27 passed**.
- Targeted ESLint and `git diff --check` passed. SPA typecheck still fails on existing errors in exports, Action Center, invites, stock transfers, purchase-order creation, and profile; none names a changed file. No complaint team relation exists. Unassigned complaints do not appear in this personal signal; a triage signal remains unapproved.

## Separate finding fix: F-26 payroll audience, access, and links (draft PR [#364](https://github.com/kwat0g/kwatog/pull/364))

- Payroll-period approval excludes `system_admin` permission bypass, the creator/computer actor, and no longer falls back to all system administrators when no eligible checker remains. Finance and explicit active delegates still receive notices when they have period-view access.
- Payroll-adjustment action notices exclude permission-bypass administrators and require adjustment-view access. Outcomes are suppressed if the active requester no longer has that route permission. Seeded roles grant `payroll.adjustments.approve/reject` to no operational role; only `system_admin` has them by wildcard, so no default adjustment approver receives a notice. HR/Finance must name the checker before a role grant is added.
- Payslip-ready notices now identify and link to each employee's own payroll row; email-failure notices require an active account with `payroll.view` and link to that owner-only detail route. The route checks the authenticated employee against the payroll row. Payroll adjustments now have an SPA detail route backed by the existing view-protected API endpoint. Existing legacy period-level payslip notices still dedupe on redelivery.
- Targeted API results: `PayrollMakerCheckerTest` **12 passed / 67 assertions**; `PayrollAdjustmentStaleRejectRaceTest` **4 passed / 12 assertions**; `NotifyEmployeesOnPayrollFinalizedTest` **2 passed / 9 assertions**; `PayslipNotificationDedupeTest` **1 passed / 1 assertion**.
- `SendPayslipEmailJobTest`: the new access/link case passed; full file has **4 passed / 2 pre-existing failures**. Existing failures are a case-mismatched email-copy assertion and an old test passing `PayslipPdfService` where `PayrollPublicationPolicy` is expected. These are separate test cleanup work.
- PHP syntax passed for all changed PHP files. Targeted ESLint passed for the new route and test. `npm run typecheck` reports errors in other SPA files, none in this payroll route; the dynamic-route audit could not run without an isolated test `BASE_URL`. Pint reported six style violations across the legacy PHP files; its broad formatting was reverted to avoid unrelated churn. The detail route test passed; visual QA remains outstanding.

## Separate finding fix: F-27 password-reset failure callback signature

- Draft PR pending. `PasswordResetNotification::failed()` now fully qualifies `\Throwable`, matching the HR subclass override on PHP 8.3. This prevents class-loading failure before queued HR password-reset tests can run.
- Baseline reproduction: `EmployeePasswordResetNotificationTest.php` failed before the patch with a fatal method compatibility error because PHP resolved the parent's type as `App\Modules\Auth\Notifications\Throwable`.
- `EmployeePasswordResetNotificationTest.php`: **1 passed / 2 assertions**; `UserProvisioningTest.php`: **8 passed / 25 assertions**; `UserAdministrationHardeningTest.php`: **8 passed / 27 assertions**. PHP syntax and `git diff --check` pass. Targeted Pint reports one existing style issue in the unrelated fully qualified test-class `@see` reference; left it unchanged.

## Separate finding fix: F-07 training-expiry deep links (draft PR [#365](https://github.com/kwat0g/kwatog/pull/365))

- Employees now get a hash-addressed self-service training-record link. Its endpoint resolves the session employee and returns only their matching record; a second employee receives 404. Configured HR/department recipients get the exact HR training-record route, gated by `hr.employees.trainings.view` and `EmployeeCompetenceScope`.
- `TrainingExpiryAlertTest.php`: **11 passed / 39 assertions**, including exact per-audience links, HR access, employee-owner access, cross-employee 404, and employee denial on the HR route. SPA route test: **2 passed**. Targeted ESLint, PHP syntax, and `git diff --check` pass.
- New detail view is read-only and shares one component across the owner-only and scoped-HR APIs. Visual QA and full-suite verification remain outstanding. F-14's message-detail and recipient-scope review remains separate.

## Separate finding fix: F-10 shared alert unread/list parity (draft PR [#366](https://github.com/kwat0g/kwatog/pull/366))

- `/alerts` accepts `is_read`; unread count and list use the same active, non-dismissed, unread predicate. The alert page labels this as shared state because the database stores `is_read` on the global alert row, and its “Show shared unread alerts” action resets other filters before opening the matching list.
- `AlertsHardeningTest` targeted parity test: **1 passed / 7 assertions**. `pages/alerts/index.test.tsx`: **3 passed**. Direct PHPUnit, targeted SPA tests, PHP syntax, ESLint, and `git diff --check` passed. Full SPA typecheck still fails in exports, Action Center, invite, stock transfer, purchase-order, and profile files; none is in this PR.
- `php artisan test --filter=...` still fails before filtering because existing `RecommendationAudienceScopeTest.php` declares `AudienceFakeDetector::detect(): array`, incompatible with `DetectorInterface::detect(): DetectorScanResult`; running the target file with direct PHPUnit succeeds. No sidebar item or existing SPA consumer uses `/alerts/unread-count`; it is a page-level count action only. Whether read state should be personal remains a business question.

## Separate finding fix: F-05/F-09 budget-transfer badge/list parity (draft PR [#367](https://github.com/kwat0g/kwatog/pull/367))

- Adds Finance > Budget Transfers, gated by `budgeting.view` or `budgeting.approve`. A positive `pending_transfers` badge opens `/budgeting/transfers?status=pending&actionable=1`; approval-only users can open only transfers they may approve. The filter and badge both use `BudgetTransfer::scopeAwaitingApprovalFor()`, matching the service's direct/delegated role-separation rule. The existing combined Phase 2 budget signal at `/budgeting?signal=awaiting_my_approval` remains incomplete.
- `BudgetTransferTest.php`: **2 passed / 19 assertions** for approval-only access, same-role exclusion, active delegation, and badge/list parity. `BadgeControllerTest.php`: **1 passed / 11 assertions** for VP badge/list parity. `Sidebar.behavior.test.tsx`: **28 passed**; `Sidebar.permissions.test.tsx`: **21 passed**.
- Changed PHP files passed syntax checks. Model, controller, and transfer workflow test passed targeted Pint; targeted ESLint and `git diff --check` passed. Full SPA typecheck still fails in unrelated export, Action Center, invite, stock-transfer, purchase-order, and profile files; no changed file was named. Pint still flags style rules in `routes.php` and `BadgeControllerTest.php`; broad formatting was avoided. Mechanical Impeccable detector reported no findings. Browser visual QA and full-suite verification remain outstanding.

## Separate finding fix: F-09 AP overdue badge/list parity (draft PR [#369](https://github.com/kwat0g/kwatog/pull/369))

- `Bill::scopeOverdue()` is shared by the `overdue_bills` badge and `/bills?overdue=1` list filter. Definition: unpaid or partially paid bill with due date before `today()` in `Asia/Manila`; draft, paid, and cancelled statuses are excluded. Database schema requires a due date.
- Sidebar badge opens `/accounting/bills?overdue=1`. Phase 2's earlier `signal=overdue` target did not match the existing page filter and is corrected in the catalog.
- `BadgeControllerTest.php`: **15 passed / 175 assertions**, including Finance Officer badge/list parity and due-today, paid, draft, and cancelled exclusions. `Sidebar.behavior.test.tsx`: **29 passed** after the first run caught the Finance section's collapsed state in the new click-through test. Targeted ESLint passes.
- PHP syntax and `git diff --check` pass. Pint `--test` reports style violations across the four checked PHP files; no broad formatting was applied. Browser visual QA and full-suite verification remain outstanding.

## Outstanding proof / risks

1. Payroll defaults are approved; exact payroll adjustment checker and HR/Finance sensitive audiences remain open business decisions. Review remaining HR/discipline, executive escalation, system-admin fanout, and portal contacts.
2. Seed one user per local role and department/site scope; run positive and negative recipient scenarios after approval.
3. Compare each badge to the destination list's total under same filter and authorization for every eligible role.
4. Verify channel authorization for unauthenticated, cross-user, and cross-scope cases.
5. Validate route links, legacy renderer, responsive/keyboard UI, and performance after implementation.
6. Discovery had no runtime scenarios. Later targeted payroll, finance, approval, and badge tests are recorded above; browser/e2e, cross-role complete matrices, and full-suite runs remain outstanding.

## Resume instruction

Continue Phase 3 from `02-policy-and-plan.md`, starting with remaining central-service work. Keep each unit on its own branch/PR and update this file with test evidence, remaining gaps, and findings.
