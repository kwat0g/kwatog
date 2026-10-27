# Notification and badge remediation progress

**Updated:** 2026-10-27
**Current gate:** Phase 2 approved. PR #380 scopes late-supplier notices to reviewers who can open the exact PO and merged as `7aa4820b`; PR #381 adds overdue, unpaid, and due-soon AP signals with badge/list parity and merged as `6c3124c0`. PR #382 excludes inactive and soft-deleted internal recipients and merged as `f53c4e7b`. PR #405 adds department-boundary proof for `training.expiry`; its targeted test passed on the original feature head, with post-rebase execution unavailable locally. PR #384 narrows the CRM inquiry triage badge to users with list and status-management access; its focused sidebar test passes after rebase. API test rerun and merge remain pending. CI is waived per the user's instruction. Full-project recipient and badge verification remain open.

## Phase status

| Phase | Status | Evidence |
| --- | --- | --- |
| 0. Discovery | Draft complete | `00-discovery.md`: 23 modules, 129 in-app service call sites, 27 direct `Mail::to` sites, scheduled trigger table, static route-target scan, 14 broadcast classes, 73 internal routes, 19 portal routes, nine compact-shell links, 29 badge definitions. Static source review plus aggregate-only local DB query. |
| 1. Inventory / leak analysis | Draft complete; runtime proof outstanding | `01-inventory.md`: source audience expressions, direct delivery paths, 17-role static selector-risk matrix, 77 email-failure wrapper call sites, payload/link/dedupe expressions, badge audit, root causes, module coverage. Initial audit had no seeded-role scenarios; partial targeted recipient and badge/list comparisons are recorded below. Full role/scope proof remains open. |
| 2. Policy and plan | Approved | User approved the proposed defaults on 2026-10-25. |
| 3. Backend foundation | In progress | Units 1–4 and documented fixes through #364, #371, #380, #382, #485, and #487 are merged to `main`. Global record authorization coverage, signal registry, and remaining event families remain open. |
| 4. Frontend | In progress | PR #378 hardens same-app notification destinations. PR #381 adds labeled AP signals and a keyboard-operable signal popover. PR #384 links the existing CRM inquiry badge to its triage audience. Notification Center filters, responsive/browser QA, and multi-signal coverage for other modules remain open. |
| 5. Migration | Partial | PR #380 scopes late-supplier notices to users who can open the linked PO. PR #381 aligns overdue, unpaid, and due-soon AP signals with filtered list scopes. PR #382 applies inactive-user exclusion through the shared internal delivery normalizer. PR #405 verifies a department-head recipient boundary for training-expiry notices. PR #384 restricts the triage badge to users with both list and status-management access. Remaining dispatch sites and sidebar signals remain open. |
| 6. Extras | Not started | No extra features started. |
| 7. Verification | Partial | PR #381 targeted API parity tests passed **4 tests / 89 assertions** and `Sidebar.behavior.test.tsx` passed **30 tests** on the original feature head. PR #382 `NotificationServiceTest.php --filter=inactive_internal_users_are_excluded_from_notification_delivery` passed **1 test / 8 assertions** on its original feature head. PR #405 `TrainingExpiryAlertTest.php --filter=test_training_expiry_notice_only_reaches_department_heads_with_employee_scope` passed **1 test / 4 assertions** on its original feature head. PR #384 `Sidebar.behavior.test.tsx` passed **34 tests** after rebase; PHP syntax checks, targeted ESLint, and diff checks passed. Its API test passed **1 test / 10 assertions** on the original feature head but was not rerun after rebase to avoid using the configured shared `ogami_test` database. CI is waived. Full role, channel, and badge parity, browser QA, and the full suite remain open. |

## Phase 3 PR stack

| Unit | Branch | PR | Commit |
| --- | --- | --- | --- |
| 1. Versioned payload contract | `notifications/phase3-central-contract` | [#332](https://github.com/kwat0g/kwatog/pull/332) | `a8a479e0` |
| 2. Core approval recipients | `notifications/phase3-recipient-resolver` | [#333](https://github.com/kwat0g/kwatog/pull/333) | merged as `1b4ba8aa` |
| 3. Realtime payload security | `notifications/phase3-realtime-payload` | [#334](https://github.com/kwat0g/kwatog/pull/334) | merged as `00eef8df` |
| 4. Laravel notification dispatch entry | `notifications/centralize-laravel-notifications` | [#370](https://github.com/kwat0g/kwatog/pull/370) | merged as `d21770f7` |
| F-17. Custom approval escalation audience | `notifications/fix-custom-approval-escalation-audience` | [#335](https://github.com/kwat0g/kwatog/pull/335) | merged as `89b9b1df` |
| F-16. Submitter outcome access | `notifications/fix-approval-outcome-access` | [#336](https://github.com/kwat0g/kwatog/pull/336) | merged as `a9e3f394` |
| F-02. Approval badge source scope | `notifications/fix-approval-badge-scope` | [#337](https://github.com/kwat0g/kwatog/pull/337) | merged as `7fbab781` |
| F-03. Purchase-request badge scope | `notifications/fix-pr-approval-badge-scope` | [#338](https://github.com/kwat0g/kwatog/pull/338) | merged as `5569d897` |
| F-04. Badge route permissions | `notifications/fix-badge-route-permissions` | [#339](https://github.com/kwat0g/kwatog/pull/339) | merged as `f4c63a4c` |
| F-01. Profile-request badge route and query parity | `notifications/fix-profile-request-badge-route` | [#340](https://github.com/kwat0g/kwatog/pull/340) | merged as `ba5e429a` |
| F-18. Pricing notification role audience | `notifications/fix-pricing-notification-audience` | [#341](https://github.com/kwat0g/kwatog/pull/341) | merged as `aa8d28b2` |
| F-05. CRM inquiry badge filter parity | `notifications/fix-inquiry-badge-parity` | [#342](https://github.com/kwat0g/kwatog/pull/342) | merged as `2c0a78dd` |
| F-36. CRM inquiry triage audience | `notifications/triage-inquiry-badge` | [#384](https://github.com/kwat0g/kwatog/pull/384) | `bd6378b8` |
| F-06. Notification event catalog coverage | `notifications/fix-event-catalog-coverage` | [#343](https://github.com/kwat0g/kwatog/pull/343) | merged as `2f921b21` |
| F-08. Notification deep-link anchors | `notifications/fix-notification-link-anchors` | [#344](https://github.com/kwat0g/kwatog/pull/344) | merged as `07279606` |
| F-08. Notification destination validation | `notifications/fix-notification-row-anchors` | [#378](https://github.com/kwat0g/kwatog/pull/378) | merged as `a143fbb4` |
| F-12. Subject channel authorization | `notifications/fix-subject-channel-authorization` | [#345](https://github.com/kwat0g/kwatog/pull/345) | merged as `f7e3e67f` |
| F-15. Queue long-wait destination and audience | `notifications/fix-queue-long-wait-link` | [#346](https://github.com/kwat0g/kwatog/pull/346) | merged as `337e0018` |
| F-24. Full approval-board count beyond page limit | `notifications/fix-approval-board-count` | [#347](https://github.com/kwat0g/kwatog/pull/347) | merged as `8ef2d47e` |
| F-03. Purchase-request badge and filtered list parity | `notifications/fix-purchase-request-badge-scope` | [#348](https://github.com/kwat0g/kwatog/pull/348) | merged as `4e8ec1a7` |
| F-04. Badge permission role regression coverage | `notifications/fix-badge-permission-gates` | [#349](https://github.com/kwat0g/kwatog/pull/349) | merged as `38af6f5f`; source gates in #339 |
| F-18. Pricing recipient negative-role coverage | `notifications/fix-pricing-recipient-role-scope` | [#350](https://github.com/kwat0g/kwatog/pull/350) | merged as `9a5a0525`; source filter in #341 |
| F-20. Sales-order confirmation notice access and link | `notifications/fix-sales-order-confirmation-notice` | [#351](https://github.com/kwat0g/kwatog/pull/351) | merged as `cab02246` |
| F-12. Sales-order broadcast payload minimization | `notifications/minimize-sales-order-broadcast` | [#352](https://github.com/kwat0g/kwatog/pull/352) | `ff2a2a92` plus current-main merge |
| F-09. Pending-QC GRN badge/list parity | `notifications/fix-grn-badge-parity` | [#353](https://github.com/kwat0g/kwatog/pull/353) | merged as `a8ecb9a9` |
| F-09. Inspection badge/list parity | `notifications/fix-inspection-badge-parity` | [#354](https://github.com/kwat0g/kwatog/pull/354) | merged as `e10db3fd` |
| F-09. Assigned complaint badge/list parity | `notifications/fix-open-complaint-badge-parity` | [#357](https://github.com/kwat0g/kwatog/pull/357) | merged as `f592f6ca` |
| F-12. Shared dashboard event payload minimization | `notifications/minimize-dashboard-broadcast-payloads` | [#358](https://github.com/kwat0g/kwatog/pull/358) | merged as `4c26b530` |
| F-15. Permission-override notice copy | `notifications/fix-permission-override-notice-copy` | [#359](https://github.com/kwat0g/kwatog/pull/359) | merged as `79e1dbc6` |
| F-25. Invoice payment-review recipient scope | `notifications/fix-invoice-payment-notice-audience` | [#362](https://github.com/kwat0g/kwatog/pull/362) | merged as `d776cc0c` |
| F-26. AR dunning audience and links | `notifications/fix-ar-dunning-review-audience` | [#363](https://github.com/kwat0g/kwatog/pull/363) | merged as `8cfe7abb` |
| F-27. Payroll recipient scope and deep links | `notifications/fix-payroll-notice-audience` | [#364](https://github.com/kwat0g/kwatog/pull/364) | merged as `658e3be4` |
| F-07. Training-expiry deep links | `notifications/fix-training-expiry-links` | [#365](https://github.com/kwat0g/kwatog/pull/365), promotion [#487](https://github.com/kwat0g/kwatog/pull/487) | promoted to `main` as `0d8f00d0` |
| F-10. Shared alert unread/list parity | `notifications/fix-alert-count-parity` | [#366](https://github.com/kwat0g/kwatog/pull/366) | merged as `49649075` |
| F-05/F-09. Budget-transfer badge/list parity | `notifications/fix-budget-transfer-badge-parity` | [#367](https://github.com/kwat0g/kwatog/pull/367) | merged as `5364a1b0` |
| F-09/F-30. AP overdue badge and row-status parity | `notifications/fix-ap-bills-badge-parity` | [#369](https://github.com/kwat0g/kwatog/pull/369) | merged as `46b81627` |
| F-09. Budget approval badge/list parity | `notifications/fix-budget-approval-badge-parity` | [#374](https://github.com/kwat0g/kwatog/pull/374) | merged as `f1538e45` |
| F-32. Restricted budget approval-only queue | `notifications/fix-budget-approval-only-queue` | [#377](https://github.com/kwat0g/kwatog/pull/377) | merged as `75d3d4e5` |
| F-28. Budget/transfer action-notice audience | `notifications/fix-budget-approval-recipient-audience` | [#375](https://github.com/kwat0g/kwatog/pull/375) | merged as `09237d48` |
| F-33. Stock-adjustment notice links | `notifications/fix-stock-adjustment-notice-target` | [#376](https://github.com/kwat0g/kwatog/pull/376) | merged as `ee1861a6` |
| F-08. Notification destination validation | `notifications/fix-notification-row-anchors` | [#378](https://github.com/kwat0g/kwatog/pull/378) | merged as `a143fbb4` |
| F-34. Late supplier notice audience | `notifications/fix-late-supplier-notice-audience` | [#380](https://github.com/kwat0g/kwatog/pull/380) | merged as `7aa4820b` |
| F-13. AP multi-signal sidebar | `notifications/multi-signal-ap-badges` | [#381](https://github.com/kwat0g/kwatog/pull/381) | merged as `6c3124c0` |
| F-35. Inactive internal notification recipients | `notifications/filter-inactive-notice-recipients` | [#382](https://github.com/kwat0g/kwatog/pull/382) | merged as `f53c4e7b` |
| F-14. Training-expiry department scope proof | `notifications/verify-training-expiry-audience-stack` | [#405](https://github.com/kwat0g/kwatog/pull/405) | rebased on current `main`; merge pending |
| F-29. Generic approval recipient collection | `fix/approval-notification-recipient-collection` | [#485](https://github.com/kwat0g/kwatog/pull/485) | merged as `4c525aa0` |

## Coverage ledger

- NotificationService call sites inventoried: **129 / 129** found by scan and cross-check; all 129 remain on existing entry points.
- Direct `Mail::to(...)` call sites inventoried: **27 / 27** under `api/app`; mail recipient runtime outcomes not tested.
- Direct Laravel `->notify()` / `Notification::send()` and central preference email paths inventoried in discovery; mail-only account/security and summary paths remain separate.
- Internal sidebar routes inventoried: **73 / 73**; portal routes: **19 / 19**; compact-shell links: **9 / 9**.
- Phase 2 catalog now defines **88** signal IDs after splitting the budget and transfer approval queues by source list; this keeps the approved maker-checker recipient rule unchanged. Static cross-reference found no undefined IDs in the 73 internal, 19 portal, or 9 compact route rows. Disabled/deferred signals stay labeled in the policy table.
- Badge definitions inventoried: **29**; currently wired to internal nav: **22**; unwired: **7**.
- Local role slugs: **17**; existing role-access audit says **16**. Reconcile before role-complete claim.
- Status/signal catalog: candidate data reviewed for all **23 modules**; exact enum classification, scope parity, and business approval remain outstanding.
- Unit 1 code changes are limited to `NotificationService` envelope normalization, its unit and portal tests, the SPA notification data type, and schema/progress documentation. No database migration or existing-row rewrite is needed.
- Payload schema v1 fields: `schema_version`, `event_type`, optional `subject_type`, `subject_id`, and caller-supplied `actor_id`. Existing `title`, `message`, `link_to`, event timing, recipients, channels, and legacy rows remain unchanged.
- Core `ApprovalService` action-required notices resolve only the active step's role holders and active delegates who pass the mapped action permission and `ApprovalTypeRegistry::userCanViewRecord`; exclude submitter and known acting approver. Store `recipient_reason` on each in-app row. F-16 outcome access is gated at all six inventoried senders. Other approval senders and event families remain on existing paths.
- Realtime notification events now publish only notification ID and type on the recipient's private channel; channel authorization requires both matching user ID and `notifications.view`. SPA fetches full content from `GET /notifications/{id}`, scoped to that user. `badges` remains an authenticated private, content-free refresh channel.

## Current confirmed findings

See `FINDINGS.md`. PR #380 corrected late-supplier recipient scope; #381 adds three shared-scope AP signals and the first multi-signal sidebar item; #382 excludes inactive and soft-deleted internal recipients. PR #405 verifies one department-head training-expiry boundary. Configured-role audiences without record scope, remaining Notification Center behavior, full role coverage, and broad badge parity remain open.

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
- PR #333 adds per-type/step action-permission metadata and negative cases for revoked action permission, viewable delegates blocked by the route, inactive delegates, and expired delegation. The final PO action-only access regression was added after earlier targeted runs and has not been rerun. PHP is unavailable locally.
- Follow-up commit `3ad0067a` adds a registry-wide assertion that every core approval type declares action permission metadata included in its registry permission list; this assertion is unverified pending PR CI.

## Phase 3 unit 3 verification

- API: `NotificationServiceTest` **8 passed / 26 assertions**; `NotificationServiceHardeningTest` **22 passed / 62 assertions**; `NotificationControllerTest` **10 passed / 30 assertions**; `BroadcastAuthorizationTest` **4 passed / 5 assertions**.
- PHP syntax checks passed. Broadcast payload test verifies only `id` and `type`; API tests verify own-user fetch and cross-user 404; channel tests verify self access, cross-user denial, unauthenticated denial, and denied permission.
- SPA typecheck still fails only in unrelated export, invite, stock-transfer, and purchase-order files. No changed notification file reports an error. Browser/toast behavior was not exercised.
- This unit does not change notification recipient selection, event timing, stored rows, or portal notification APIs. Global payload/access authorization remains open.

## Phase 3 unit 4 verification

- `NotificationService::sendLaravelNotification()` normalizes/deduplicates explicit internal-user recipients, then calls Laravel's notification facade. Seven existing mail-only dispatch sites now use it: password reset, employee welcome, employee password reset, admin welcome, daily and weekly production summaries, and critical alert email. Recipient selection, notification class, channel, and timing are unchanged.
- Added a source guard against direct `Notification::send[Now]()` and inline `$user->notify(new ...)` outside the dispatcher, plus a test that duplicate explicit recipients receive one notice and an unrelated user receives none. This does not yet provide subject authorization, policy resolution, preferences, lifecycle, or fallback recipients for these Laravel notification classes.
- Before rebase, targeted API results: `NotificationServiceTest` **9 passed / 28 assertions**; dispatch catalog guard **1 / 1**; `ValidEmailRecipientFilterTest` **6 / 16**; `PasswordResetTest` **13 / 40**; focused admin welcome and employee provisioning welcome **1 / 4** and **1 / 8**. These results predate rebase and were not rerun.
- Local post-rebase `git diff --check` passes. PHP is unavailable in this shell. The full provisioning/admin tests hit an `EmployeePasswordResetNotification::failed()` signature mismatch that was fixed in #371. PR #370's post-merge API workflow had passed lint and was running PHPStan at this update; PHPUnit was still pending. No database, browser, or full-suite check ran locally.

## Separate finding fix: F-17

- Custom approval escalation no longer adds every active `system_admin` after record-specific recipient checks. `CustomApprovalDelegationAndEscalationTest` passed **5 tests / 29 assertions**, including a prebill editor exclusion case. Existing broken assertions in that test were repaired so the suite runs.
- Escalation timing is unchanged. No supervisor recipient resolver was found in this service; `approval_escalation` still goes to eligible approvers. See F-23 for this remaining policy gap.

## Separate finding fix: F-02

- `BadgeService.approvals` now reads the approval board's `summary.my_action`, inheriting supported-type, permission, delegation, and source-row filtering. The feature test compares badge and board counts and excludes an out-of-department leave row plus an unsupported approval type.
- Targeted API tests: `BadgeControllerTest` selected tests **3 passed / 12 assertions** on the pre-merge feature head. Post-merge tests were not rerun. F-24's 100-row summary cap is fixed in PR #347; full role parity remains open.
- Full `BadgeControllerTest` and all-role badge/list parity were not run. No frontend changes.

## Separate finding fix: F-03

- `PurchaseRequestAccessPolicy::pendingApprovalsFor()` supplies the sidebar badge, pending-count endpoint, and filtered list. It applies current pending steps, active direct/delegated roles, submitter exclusion, PR row visibility, and requires both `purchasing.view` and `purchasing.pr.approve`. The sidebar opens `?signal=awaiting_my_approval`.
- On the original feature head: `BadgeControllerTest.php` **12 passed / 124 assertions**, `PurchaseRequestHardeningTest.php` **15 passed / 36 assertions**, and `Sidebar.behavior.test.tsx` **24 passed**. New approval-only/no-list-access regression was added after these runs and remains unverified; no tests were rerun after integrating current main.
- All-role badge/list parity remains open under F-09.

## Separate finding fix: F-04 badge destination permissions

- PR #339 aligns Payroll, Complaints, and Deliveries badge permissions with their destination list routes: `payroll.periods.view`, `crm.complaints.view`, and either `supply_chain.view` or `supply_chain.deliveries.view`.
- PR #349 adds seeded-role regression cases. On its original feature head, `BadgeControllerTest.php` passed **13 tests / 141 assertions**. The test changes were not rerun after current-main integration; PHP is unavailable locally.
- This verifies visibility gates only. Count/filter parity and other route gates remain open.

## Separate finding fix: F-01

- The profile-request badge moved from Employees to Bank Account Changes/Profile Change Requests. `ProfileUpdateRequestService::reviewQueueQuery()` is shared by list and badge, preserving HR department scope and Finance's `pending_finance`/`requires_finance` filters.
- `BadgeControllerTest::test_profile_request_badge_matches_the_hr_and_finance_review_queues` passed **1 test / 8 assertions**; existing finance bank-request workflow test passed **1 / 34**; `Sidebar.permissions.test.tsx` passed **21 tests** on the pre-merge feature head. Post-merge tests were not rerun.
- Full role-by-role list parity and browser layout were not checked.

## Separate finding fix: F-05 CRM inquiry badge

- The inquiry badge now counts only `ContactInquiryStatus::New`. `ContactInquiryInboxService::listQuery()` powers the badge and list; the sidebar opens `/crm/inquiries?status=new`. Query-aware active-link and recent-page matching preserve navigation behavior with the filter.
- On the pre-merge feature head: API parity test **1 passed / 4 assertions**; sidebar permission and behavior tests **46 passed across two files**. Tests were not rerun after merge.
- Five badge definitions remain unattached; see `FINDINGS.md`. No full role matrix or browser layout check.

## Separate signal fix: F-36 CRM inquiry triage audience (draft PR [#384](https://github.com/kwat0g/kwatog/pull/384))

- The inquiry badge now requires `crm.inquiries.manage`; its count also requires `crm.inquiries.view` and reuses `ContactInquiryInboxService::listQuery()` with the `new` filter. The inbox's `new` filter uses `ContactInquiry::scopeAwaitingTriage()`, shared with the badge query.
- A read-only user with `crm.inquiries.view` can still open the filtered list but receives no triage badge. The seeded customer-service manager receives the count, equal to the filtered list total.
- On the original feature head: `BadgeControllerTest::test_inquiry_badge_matches_new_inquiry_list_and_requires_manage_permission` passed **1 test / 10 assertions**; `Sidebar.behavior.test.tsx` passed **31 tests**. After rebase, `Sidebar.behavior.test.tsx` passed **34 tests**; targeted ESLint, PHP syntax checks, and `git diff --check` passed. The API test was not rerun because PHPUnit uses the configured shared `ogami_test` database. CI is waived. Browser QA remains outstanding.

## Separate finding fix: F-18

- Pricing notifications now intersect caller-supplied roles with the permission for the destination route. BOM updates use `mrp.boms.view`; unassigned cost-review notices remain permission-scoped when no role list is supplied.
- Earlier coverage: expiry reminder test **1 passed / 7 assertions**; BOM refresh test **1 passed / 11 assertions**. PR #350 adds `PricingReviewNotificationAudienceTest.php`, which passed **2 tests / 6 assertions** on its original feature head, including a seeded HR user with permission but outside the configured audience. Not rerun after current-main integration.
- Product/customer ownership or assignment fields were not found, so F-18 is partial. Empty role lists still represent a permission-wide cost-review queue; its intended audience remains a business question.

## Separate finding fix: F-06 notification catalog

- Added catalog and frontend metadata for `supplier.shipment_late`, `quality.late_incoming`, and all four emitted `production.rework.*` values. Rework titles now use human labels instead of raw state names.
- On the pre-merge feature head: `NotificationCatalogTest` **8 passed / 58 assertions**; `WorkOrderInlineReworkTest::test_resolved_wo_closes_automatically_once_with_notification` **1 passed / 6 assertions**; `notificationMeta.test.ts` **15 passed**. Tests were not rerun after merge.
- Recipient scope and negative role coverage remain open; this PR changes message metadata only.

## Separate finding fix: F-08 notification anchors and destination validation

- PR #344 added Bell and Notification Center anchors; ordinary and middle clicks mark unread items read. PR #378 adds `notificationLinkTo()` to reject malformed/external URLs, reject portal destinations in internal pages, and keep portal links within the active portal prefix. Maintenance schedule links use the same validator.
- On the PR #378 original feature head: `notificationLink.test.ts` plus `PortalNotifications.test.tsx`: **6 passed**. Targeted ESLint and `git diff --check` passed. During rebase, an extra assertion was added for rejecting portal URLs when no portal prefix is present; that assertion was not rerun. Local Vitest is unavailable.
- Typecheck errors were limited to existing unrelated export, invite, forbidden-page, stock-transfer, and purchase-order files. Browser middle-click, forbidden/deleted destinations, and visual QA remain unverified.

## Separate finding fix: F-12 private channel access

- Private subject channels now require destination permission and an existing subject. Chain authorization fails closed on unmapped types and applies `ApprovalSourceScope` to purchase requests and orders; work orders, machines, and payroll periods follow their existing module-wide endpoint permissions.
- On the pre-merge feature head: `BroadcastAuthorizationTest` **7 passed / 17 assertions**, including out-of-department PR/PO denial and missing-subject denial. Tests were not rerun after merge.
- All eight chain entity types, every role, and cross-scope model policies were not tested.

## Separate finding fix: F-12 shared dashboard payloads

- Eight production/maintenance broadcast events now send only `{ refresh: true }`. Their SPA dashboard listeners only invalidate/refetch queries; the output-entry page now refetches the authorized work-order detail instead of reading cumulative totals from the event payload.
- On the original feature head, `DashboardBroadcastPayloadTest` passed **1 test / 8 assertions** and the focused plan outbox tolerance case passed **1 / 3**. PHP was unavailable for a post-integration rerun. After integrating current main, `record-output.test.tsx` passed **5 tests**.
- F-12 remains open for payroll progress payload sensitivity and the full subject-channel role/scope matrix. CI on the feature head was blocked before PHPStan/PHPUnit by inherited F-22 and before SPA lint/typecheck/Vitest/build by the existing dependency audit.

## Separate finding fix: F-15 queue long-wait destination

- Queue-long-wait notices link to `/dashboard/admin`; the link requires `dashboard.admin.view`. Recipient resolution now excludes inactive administrators and system administrators whose user-level permission override removes access to that destination.
- The original `HorizonLongWaitNotificationTest` run passed **2 tests / 12 assertions** before the permission gate was added. The new revoked-permission negative case was added afterward and remains unverified; current branch tests were not rerun after merge.
- Three permission-override notices remain no-link because affected users may not open the admin override page.

## Separate finding fix: F-15 permission-override notice copy

- Permission-change notices now show the permission display name and explain whether the rule allows or blocks access. They tell affected users to contact a system administrator if unexpected; no link is included because the admin user page requires `admin.users.manage` and no self-service override page exists.
- Recipients stay unchanged. The affected user receives the notice; a different administrator who changed the override does not.
- On the original feature head, `PermissionOverrideNotificationCopyTest` passed **1 test / 29 assertions**, covering add, update, remove, restore, clear copy, raw slug/enum exclusion, no-link behavior, and actor exclusion. PHP is unavailable locally for a post-integration rerun.
- F-15 remains partial until the queue-long-wait route and recipient gate are verified with the affected role.

## Separate finding fix: F-25 invoice payment-review audience

- Payment submissions use the shared Finance review queue; there is no reviewer assignment field. The seeded `finance_officer` role holds both `accounting.invoices.collect` and `accounting.invoices.view`; the invoice detail route requires the latter and adds no row-level filter.
- Recipients now need both permissions and active status. Exclude `system_admin` because `usersWithPermission()` intentionally includes permission-bypass admins, who are not Finance reviewers. Existing message, link, dedupe key, and submission timing stay unchanged.
- On the original feature head, `InvoicePaymentSubmissionServiceTest` passed **13 tests / 80 assertions**. The added negative case excludes a system administrator, inactive Finance user, collect-only user, and view-only executive, while confirming the Finance reviewer gets the exact invoice link. PHP is unavailable locally for a post-integration rerun.
- Empty-recipient fallback/audit remains open in the central notification service. No generic system-admin fallback is approved for customer payment data.

## Separate finding fix: F-26 AR dunning audience and links

- Final-tier escalation, missing-email fallback, and queued-mail failure use active AR reviewers holding both `accounting.invoices.collect` and `accounting.invoices.view`; exclude `system_admin` permission bypass. Queued failure now resolves current access rather than using its queue-time ID snapshot.
- Internal escalation and email-failure notices link to the exact invoice. Customer reminder recipients and portal links stay unchanged; dunning schedule, tier timing, and email preference behavior stay unchanged.
- On the original feature head, `RunArDunningCommandTest` passed **12 tests / 58 assertions**. Positive and negative cases cover Finance Officer, system administrator, view-only executive, and collection access revoked before a queued email failure. PHP is unavailable locally for a post-integration rerun.
- The original PR also refreshed three stale peso-symbol expectations; current main already asserts through `CurrencyDisplayService`. Pint and PHP syntax checks passed on the original feature head; the integrated diff passes `git diff --check`.
- If no eligible AR reviewer exists, the system still sends no notice or audit. Central empty-recipient fallback remains open; no generic administrator fallback is used for finance data.

## Separate finding fix: F-27 payroll audience and links (PR [#364](https://github.com/kwat0g/kwatog/pull/364))

- Period approval excludes permission-bypass administrators and the creator/computer; there is no broad administrator fallback. Payroll-adjustment notices require the recipient to be able to approve or reject this request, and exclude the requester and permission-bypass administrators.
- The seeded role permissions grant adjustment approval/rejection to no operational role. No action notice reaches anyone until HR/Finance assigns an authorized checker; the PR does not invent a role.
- Payslip-ready and email-failure notices target the active employee account with current `payroll.view` access and link to that employee's exact payroll row. Payroll adjustment notices link to the exact adjustment; the SPA detail route uses the existing view-protected API endpoint.
- Feature-head tests previously recorded: `PayrollMakerCheckerTest` **12 tests / 67 assertions**; `PayrollAdjustmentStaleRejectRaceTest` **5 / 15**; `NotifyEmployeesOnPayrollFinalizedTest` **2 / 9**; `PayslipNotificationDedupeTest` **1 / 1**. The integrated CI run's only API error was the missing-permission generic-collection failure fixed by #485; no post-merge rerun was awaited. PHP is unavailable in the local shell.
- PR #364 merged as `658e3be4`. Browser and visual QA remain open; no operational payroll-adjustment checker exists in seeded permissions.

## Separate finding fix: F-07 training-expiry deep links (stacked PR [#365](https://github.com/kwat0g/kwatog/pull/365); main promotion [#487](https://github.com/kwat0g/kwatog/pull/487))

- Employees now get a hash-addressed self-service training-record link. Its endpoint resolves the session employee and returns only their matching record; a second employee receives 404. Configured HR/department recipients get the exact HR training-record route, gated by `hr.employees.trainings.view` and `EmployeeCompetenceScope`.
- `TrainingExpiryAlertTest.php`: **11 passed / 39 assertions**, including exact per-audience links, HR access, employee-owner access, cross-employee 404, and employee denial on the HR route. SPA route test: **2 passed**. Targeted ESLint, PHP syntax, and `git diff --check` pass on the feature head.
- PR #365 first merged into the already-merged payroll branch; PR #487 promoted the training changes to `main` as `0d8f00d0`. The detail view is read-only and shares one component across the owner-only and scoped-HR APIs. Visual QA and post-merge full-suite verification remain outstanding. F-14's message-detail and recipient-scope review remains separate.

## Separate finding fix: F-10 shared alert unread/list parity (PR [#366](https://github.com/kwat0g/kwatog/pull/366), merged as `49649075`)

- `/alerts` uses `Alert::active()` for default and non-dismissed lists; unread count applies that same scope plus `is_read=false`. The endpoint accepts `is_read`, and the page labels state as shared because `is_read` is stored on the global alert row. “Show shared unread alerts” resets other filters before opening the matching list.
- `AlertsHardeningTest` parity test: **1 passed / 7 assertions**. `pages/alerts/index.test.tsx`: **3 passed**. PHP syntax, ESLint, and `git diff --check` passed on the feature head. The old full API job timed out and the old SPA job stopped at dependency audit before typecheck/tests; current full workflows were not awaited.
- No sidebar item or current SPA caller uses `/alerts/unread-count`; this is a page-level count action. Whether read state should become personal remains a business question.

## Separate finding fix: F-05/F-09 budget-transfer badge/list parity (PR [#367](https://github.com/kwat0g/kwatog/pull/367), merged as `5364a1b0`)

- Adds Finance > Budget Transfers, gated by `budgeting.view` or `budgeting.approve`. A positive `pending_transfers` badge opens `/budgeting/transfers?status=pending&actionable=1`; approval-only users see only transfers they can approve. `BudgetTransfer::scopeAwaitingApprovalFor()` powers both badge and list and follows direct/delegated maker-checker eligibility. PR #374 implements the budget queue with `Budget::scopeAwaitingApprovalFor()`; transfers remain a separate queue under PR #367.
- On the original feature head, `BudgetTransferTest.php` plus `BadgeControllerTest.php`: **22 passed / 235 assertions**. `Sidebar.behavior.test.tsx` plus `Sidebar.permissions.test.tsx`: **49 passed**. These targeted results were recorded before rebase; they were not rerun afterward.
- Existing GitHub workflow blockers: API stops at a duplicate `PdfRenderService::ensureDompdfDirs()` declaration before PHPUnit; SPA stops at `npm audit` findings in `@vitest/mocker`, `undici`, and `zod`. CI was not awaited under the user's priority. Browser visual QA, full suite, and parity across every eligible role remain open.

## Separate finding fix: F-09/F-30 AP overdue badge and row-status parity (PR [#369](https://github.com/kwat0g/kwatog/pull/369))

- `Bill::scopeOverdue()` is shared by the `overdue_bills` badge and `/bills?overdue=1` filter. It includes only unpaid/partial bills with due date before app `today()` (default `Asia/Manila`) and excludes due-today, paid, draft, and cancelled rows. Sidebar opens `/accounting/bills?overdue=1`.
- `Bill::isOverdue()` previously called `isPast()` on a date cast at midnight, labeling due-today bills overdue. It now uses the same unpaid/partial and strict-date condition. This field is returned in internal and supplier/customer bill resources.
- Before rebase, `BadgeControllerTest.php` passed **15 tests / 175 assertions** and `Sidebar.behavior.test.tsx` passed **29**. The due-today row-status assertion was added after those runs; it was not rerun locally because PHP is unavailable.
- CI was not awaited under the user's priority. Browser visual QA and full-suite verification remain open.

## Separate finding fix: F-29 generic approval recipient collection (PR [#485](https://github.com/kwat0g/kwatog/pull/485))

- `ApprovalNotificationRecipientResolver` now extracts keys through `Collection::map()` and `Model::getKey()`, which supports both generic and Eloquent collections. Missing permission configuration can no longer abort approval resubmission.
- GitHub CI reproduced the failure in `ApprovalServiceTest::test_resubmit_preserves_acted_records` before the fix; PHPStan passed on the fix branch. The full PHPUnit job was not awaited under the user's CI priority. PR #485 merged as `4c525aa0`.


## Separate finding fix: F-24 approval-board total

- `ApprovalBoardService` now paginates distinct pending documents and computes full `summary.my_action` with the same source scopes, role/delegation rules, payroll maker-checker checks, and custom approval path. Board cards stay paginated; the badge consumes the full summary.
- On the pre-rebase feature head: `ApprovalBoardTest.php` **5 passed / 27 assertions**; `BadgeControllerTest.php` **11 passed / 118 assertions**, including 101 actionable requests, 100 cards, summary 101, badge 101. These tests were not rerun after merging current main; PHP is unavailable locally.
- Full role-by-role parity, post-rebase verification, and production-scale latency remain unverified.

## Separate finding fix: F-09 pending-QC GRN badge parity

- PR [#353](https://github.com/kwat0g/kwatog/pull/353) adds this shared scope and parity coverage on top of #352.
- `GoodsReceiptNote::pendingQc()` now defines the shared count/list condition. The `pending_grn` badge and GRN list's `status=pending_qc` filter use that scope; warehouse staff badge permission matches the list route's `inventory.view` gate.
- A positive GRN badge opens `/inventory/grn?status=pending_qc`. Phase 2's route entry now matches the SPA's real query parameter; `signal=pending_qc` was not a supported filter.
- On the original feature head, `BadgeControllerTest::test_pending_grn_badge_matches_the_filtered_receipt_list` passed **1 test / 6 assertions**, comparing the warehouse user's badge count with the filtered list total and returned statuses. PHP was unavailable for a post-integration rerun. After integrating current main, `Sidebar.behavior.test.tsx` passed **28 tests**.
- Scope covers only pending-QC GRNs. Other sidebar signals and cross-role/scope parity remain F-09 work; no full suite or visual QA ran.
- PR #353 CI: `finding-lifecycle`, accessibility, and visual baselines pass. API job fails before PHPUnit on inherited F-22 (`PdfRenderService::ensureDompdfDirs()` duplicate); SPA job stops at the existing dependency audit (4 vulnerabilities). Targeted API tests passed on its original feature head; sidebar tests passed again after main integration.

## Separate finding fix: F-09 inspection badge parity

- PR [#354](https://github.com/kwat0g/kwatog/pull/354) adds this parity slice on top of #353.
- `Inspection::toInspect()` now defines the shared draft/in-progress predicate for both the `pending_inspections` badge and `work_queue=to_inspect` list queue. A positive badge opens that queue.
- On the original feature head, `BadgeControllerTest::test_widened_scope_badge_counts_track_recent_rows` passed **1 test / 18 assertions**, including parity under the `qc_inspector` role. PHP is unavailable for a post-integration rerun. After integrating current main, `Sidebar.behavior.test.tsx` passed **29 tests**.
- This remains an area-wide queue matching the current list. The proposed per-user inspection action signal needs separate assignment/authorization verification.

## Separate finding fix: F-09 assigned complaint badge parity

- PR [#357](https://github.com/kwat0g/kwatog/pull/357) adds this personal queue signal on top of #354.
- `CustomerComplaint::openAssignedTo()` defines active complaints (`open`, `investigating`) assigned to one user. Both the `open_complaints` badge and `signal=open` list use it; the sidebar opens that list, the page exposes an All/Assigned queue filter, and its header names the assigned scope.
- The badge and filtered list require both complaint visibility and manage permission. On the original feature head, `BadgeControllerTest::test_widened_scope_badge_counts_track_recent_rows` passed **1 test / 33 assertions**, including assigned admin/CSO users, an unassigned CSO, and a view-only VP. PHP is unavailable for a post-integration rerun. After integrating current main, `Sidebar.behavior.test.tsx` passed **30 tests**.
- Targeted ESLint and `git diff --check` passed. SPA typecheck still fails on existing errors in exports, Action Center, invites, stock transfers, purchase-order creation, and profile; none names a changed file. No complaint team relation exists. Unassigned complaints do not appear in this personal signal; a triage signal remains unapproved.

## Separate finding fix: F-31 password-reset failure callback signature (PR [#371](https://github.com/kwat0g/kwatog/pull/371))

- `PasswordResetNotification::failed()` fully qualifies `\Throwable`, matching the HR subclass callback and preventing the PHP 8.3 class-loading failure. The redundant draft PR #372 carries the same one-line code change and will be closed during final unused-PR cleanup.
- The targeted `EmployeePasswordResetNotificationTest.php` passed **1 test / 2 assertions**, `UserProvisioningTest.php` passed **8 / 25**, and `UserAdministrationHardeningTest.php` passed **8 / 27** on PR #372's original feature head before the duplicate source fix merged in #371. These were not rerun after merge; PHP is unavailable locally.
- PR #371's API workflow passed PHP lint and PHPStan, then failed in PHPUnit on unrelated suite errors.

## Separate finding fix: F-09 budget approval badge/list parity (PR [#374](https://github.com/kwat0g/kwatog/pull/374))

- `Budget::scopeAwaitingApprovalFor()` powers `pending_budgets` and `/budgeting?signal=awaiting_my_approval`. It covers submitted budgets awaiting the current direct approver or active delegate, applies the existing maker-checker role separation, and excludes self-submission. The badge and destination require `budgeting.view`.
- On the original feature head, `BadgeControllerTest.php` passed **16 tests / 189 assertions**, including VP/Finance role separation, delegation, and badge/list parity. `Sidebar.behavior.test.tsx` plus `budgeting/index.test.tsx` passed **31 tests**. Targeted ESLint and `git diff --check` passed.
- Merged as `f1538e45` while CI was running under the user-approved CI waiver. Tests were not rerun after rebase. PHP and Vitest are unavailable locally. Browser visual QA and full-suite verification remain open.

## Separate finding fix: F-28 budget/transfer approval notice audience (PR [#375](https://github.com/kwat0g/kwatog/pull/375))

- `BudgetService::submit()` and `BudgetTransferService::request()` now filter candidates from `ApprovalDelegation::approvalAudience()` through `Budget::awaitingApprovalFor($user)` and `BudgetTransfer::awaitingApprovalFor($user)`, respectively. Delegates whose represented role matches the submitter's role cannot act and no longer receive action-required notices; actionable delegates remain eligible.
- Red/green targeted tests: `BudgetConsumptionAndLifecycleTest.php` plus `BudgetTransferTest.php`: **23 passed / 122 assertions** on the original feature head. Merged as `09237d48`. Tests were not rerun after rebase. `git diff --check` passed. PHP is unavailable locally; browser QA and full-suite verification remain open.

## Separate finding fix: F-32 restricted budget approval-only queue (PR [#377](https://github.com/kwat0g/kwatog/pull/377))

- `budgeting.approve` users without `budgeting.view` can access allowed detail records but cannot reach the actionable submitted-budget list. PR #374 keeps the badge behind `budgeting.view`; PR #377 adds a filtered approval-only queue and limits it to records the user may act on.
- PR #377's feature-head results: API `BadgeControllerTest.php` plus `ApprovalBoardTest.php` **23 passed / 230 assertions**; SPA sidebar permissions plus budget page tests **24 passed**. Targeted ESLint and `git diff --check` passed before rebase.
- F-27 remains payroll; this access gap is F-32. The branch is rebased on current `main`; PHP and Vitest are unavailable locally, so no post-rebase test run.

## Separate finding fix: F-33 stock-adjustment notification links (PR [#376](https://github.com/kwat0g/kwatog/pull/376))

- Approval-required and requester outcome notices link to `/inventory/stock-adjustments?id={hash_id}`. The list endpoint decodes the hash and returns only the matching row; invalid hashes return an empty list. The SPA labels the focused view, offers “Show all adjustments,” and explains when the record is missing or inaccessible. This also preserves current `PageShell` layout and status details from main.
- Merged as `ee1861a6`. On the original feature head, `StockAdjustmentQueueTest.php` passed **7 tests / 32 assertions**; the SPA page test passed **1 test**. The red test failed with the old queue link. Targeted SPA ESLint and Vitest passed; PHP syntax checks passed. Tests were not rerun after rebase. The earlier CI attempt stopped before tests on duplicate PDF helper and npm audit failures; user waived CI as a merge gate. Browser visual QA and full-suite verification remain open.

## Separate finding fix: F-34 late supplier notice audience (PR [#380](https://github.com/kwat0g/kwatog/pull/380))

- Late-ETA notifications now resolve active `purchasing.delivery_schedules.review` permission holders, exclude `system_admin`, and require both purchase-order detail permission (`purchasing.view` or `purchasing.po.approve`) and `PurchaseOrderAccessPolicy::canView()` for the exact PO. Each notice stores `purchasing_delivery_schedule_reviewer` as its recipient reason. Event timing, ETA condition, message, deep link, and dedupe key stay unchanged.
- Before the fix, the negative test failed because a system administrator received the notice. Its custom review-only fixture also lacked the route's `purchasing.view`/`purchasing.po.approve` gate; the old selector included that role. After the fix, the existing purchasing-officer positive case plus both negative cases pass: **2 tests / 13 assertions**. `git diff --check` passes.
- API test execution ran in the mounted Laravel container with the changed files safely overlaid and restored. The host has no PHP CLI, so host-side syntax and Pint checks were not run.
- PR #380 API CI failed before PHPUnit during its PHP syntax scan on the inherited duplicate `PdfRenderService::ensureDompdfDirs()` declaration (F-22); governance check passed. No changed-file failure was reported.

## Separate signal fix: F-13 Accounts Payable multi-signal item (PR [#381](https://github.com/kwat0g/kwatog/pull/381))

- Bills now expose separate overdue, unpaid, and due-soon counts. `Bill::scopeOverdue()`, `scopeUnpaid()`, and `scopeDueSoon()` power both the badge and `/accounting/bills` filters (`overdue=1`, `signal=unpaid`, `signal=due_soon`). Due soon means open balance due today through three calendar days ahead in the application timezone; zero-balance rows are excluded.
- The Bills sidebar item shows labeled severity chips, links the item to the highest-severity active signal, and offers all active filtered destinations in a keyboard-operable popover. Expanded, collapsed, hover, focus, tap, Escape, and link behavior have targeted coverage.
- On the original feature head, `BadgeControllerTest.php` targeted cases passed **4 tests / 89 assertions**; `Sidebar.behavior.test.tsx` passed **30 tests**. Targeted ESLint and `git diff --check` passed. PHP/PHPUnit and Vitest are unavailable locally, so tests were not rerun after rebase. Browser visual QA and full-suite checks remain outstanding.
- Pint `--test` reports style findings in touched PHP files; no broad formatting was applied. Earlier CI stopped before PHPUnit on the inherited duplicate PDF helper; CI is waived per the user's instruction.

## Separate finding proof: F-14 training-expiry department scope (PR [#405](https://github.com/kwat0g/kwatog/pull/405))

- A seeded department head whose linked employee shares the training employee's department receives the notice and opens the exact HR training detail. A different-department head receives no notice and gets 404. Existing owner, HR, cross-employee, and permission-revoked coverage remains in `TrainingExpiryAlertTest.php`.
- On the original feature head, `TrainingExpiryAlertTest.php --filter=test_training_expiry_notice_only_reaches_department_heads_with_employee_scope` passed **1 test / 4 assertions**. No production code changed. Post-rebase tests were not rerun because PHP/PHPUnit are unavailable locally; API CI inherits F-22 and stops before PHPUnit, while `finding-lifecycle` passed.
- Message-detail minimization and other configured-role/scope combinations remain open.

## Separate finding fix: F-35 inactive internal recipients (draft PR [#382](https://github.com/kwat0g/kwatog/pull/382))

- `NotificationService::normaliseRecipients()` now drops inactive and soft-deleted internal users before structured in-app sends, Laravel notifications, or the legacy wrapper. Portal-recipient delivery remains on its separate path; active-user behavior is unchanged.
- Before the fix, the negative case failed because an inactive user received a persisted notification. On the original feature head, `NotificationServiceTest.php --filter=inactive_internal_users_are_excluded_from_notification_delivery` passed **1 test / 8 assertions**, covering database rows, realtime events, Laravel notifications, inactive users, and soft-deleted users. `git diff --check` passed.
- Post-rebase tests were not rerun because PHP/PHPUnit are unavailable locally. The API CI stopped before PHPUnit on the inherited F-22 duplicate PDF helper; `finding-lifecycle` passed. No SPA files changed, so no SPA CI check applies. CI is waived per the user's instruction.

## Outstanding proof / risks

1. HR/Finance must configure the payroll-adjustment checker. Review remaining HR/discipline, finance escalation, system-admin fanout, and portal contacts before broadening recipients.
2. Seed one user per local role and department/site scope; run positive and negative recipient scenarios after approval.
3. Compare each badge to the destination list's total under same filter and authorization for every eligible role.
4. Verify channel authorization for unauthenticated, cross-user, and cross-scope cases.
5. Validate route links, legacy renderer, responsive/keyboard UI, and performance after implementation.
6. No test suite, browser/e2e session, or seeded-user scenario ran in discovery.

## Resume instruction

Continue Phase 3 from `02-policy-and-plan.md`, starting with remaining central-service work. Keep each unit on its own branch/PR and update this file with test evidence, remaining gaps, and findings.
