# Notification and badge remediation progress

**Updated:** 2026-10-26
**Current gate:** Phase 2 approved; PRs #353, #354, #357, and #358 merged. PR #359 updates permission-override message copy; PR #362 narrows invoice payment-review recipients to users who can open the invoice. Remaining channel and recipient-scope reviews, full-project migration, and role-wide verification remain open.

## Phase status

| Phase | Status | Evidence |
| --- | --- | --- |
| 0. Discovery | Draft complete | `00-discovery.md`: 23 modules, 129 in-app service call sites, 27 direct `Mail::to` sites, scheduled trigger table, static route-target scan, 14 broadcast classes, 73 internal routes, 19 portal routes, nine compact-shell links, 29 badge definitions. Static source review plus aggregate-only local DB query. |
| 1. Inventory / leak analysis | Draft complete; runtime proof outstanding | `01-inventory.md`: source audience expressions, direct delivery paths, 17-role static selector-risk matrix, 77 email-failure wrapper call sites, payload/link/dedupe expressions, badge audit, root causes, module coverage. No seeded-role scenarios or badge/list comparisons run. |
| 2. Policy and plan | Approved | User approved the proposed defaults on 2026-10-25. |
| 3. Backend foundation | In progress | Units 1–3 and F-16–F-18, F-01–F-06, F-08, F-12, F-15, and F-24 fixes are merged (#332–#347). F-03 filtered list and permission parity merged in #348; #349 adds role-to-route regression coverage for the #339 permission fix; #350 adds a negative test for the #341 pricing recipient filter. #351 updates sales-order confirmation recipients and links; PR #352 minimizes its shared-channel payload. Global authorization coverage, signal registry, and remaining event families remain open. |
| 4. Frontend | In progress | PR #344 converts notification rows with allowed destinations to anchors and preserves filtered sidebar navigation from #342. Notification Center filters, accessibility, responsive QA, and multi-signal sidebar remain open. |
| 5. Migration | Partial | Core `ApprovalService` action-required notices migrated in #333. Remaining dispatch sites and all sidebar signals remain open. |
| 6. Extras | Not started | No extra features started. |
| 7. Verification | Partial | Targeted unit 1–3 and F-16, F-01–F-06, F-08, F-12, and F-18 tests passed on earlier feature heads. PR #346's original queue test passed before the permission gate was added; its new revoked-permission case is unverified. PR #347's pre-rebase targeted runs passed: `ApprovalBoardTest.php` 5 tests / 27 assertions and `BadgeControllerTest.php` 11 tests / 118 assertions, including the 101-row parity case. No post-rebase rerun; PHP unavailable locally. PR #348's original feature-head runs passed `BadgeControllerTest.php` 12 tests / 124 assertions, `PurchaseRequestHardeningTest.php` 15 / 36, and `Sidebar.behavior.test.tsx` 24 tests. The new approval-only/no-list-access regression and all tests after current-main integration remain unverified; PHP is unavailable locally. PR #349's original feature-head `BadgeControllerTest.php` passed 13 tests / 141 assertions; these role-to-route tests were not rerun after current-main integration. PR #350's original feature-head `PricingReviewNotificationAudienceTest.php` passed 2 tests / 6 assertions; it was not rerun after current-main integration. PR #351's original feature-head `OutboxFactEventToleranceTest.php` passed 11 tests / 30 assertions; it was not rerun after current-main integration. PR #352's original feature-head test passed 12 tests / 33 assertions; it was not rerun after current-main integration. PHP is unavailable locally. Post-merge tests for #334 and #336–#351 were not rerun. Visual baseline was cancelled; browser QA, all-role/channel coverage, broad badge parity, and full-suite verification remain open. The latest no-list-access endpoint assertion remains unrun. |

## Phase 3 PR stack

| Unit | Branch | PR | Commit |
| --- | --- | --- | --- |
| 1. Versioned payload contract | `notifications/phase3-central-contract` | [#332](https://github.com/kwat0g/kwatog/pull/332) | `a8a479e0` |
| 2. Core approval recipients | `notifications/phase3-recipient-resolver` | [#333](https://github.com/kwat0g/kwatog/pull/333) | merged as `1b4ba8aa` |
| 3. Realtime payload security | `notifications/phase3-realtime-payload` | [#334](https://github.com/kwat0g/kwatog/pull/334) | merged as `00eef8df` |
| F-17. Custom approval escalation audience | `notifications/fix-custom-approval-escalation-audience` | [#335](https://github.com/kwat0g/kwatog/pull/335) | merged as `89b9b1df` |
| F-16. Submitter outcome access | `notifications/fix-approval-outcome-access` | [#336](https://github.com/kwat0g/kwatog/pull/336) | merged as `a9e3f394` |
| F-02. Approval badge source scope | `notifications/fix-approval-badge-scope` | [#337](https://github.com/kwat0g/kwatog/pull/337) | merged as `7fbab781` |
| F-03. Purchase-request badge scope | `notifications/fix-pr-approval-badge-scope` | [#338](https://github.com/kwat0g/kwatog/pull/338) | merged as `5569d897` |
| F-04. Badge route permissions | `notifications/fix-badge-route-permissions` | [#339](https://github.com/kwat0g/kwatog/pull/339) | merged as `f4c63a4c` |
| F-01. Profile-request badge route and query parity | `notifications/fix-profile-request-badge-route` | [#340](https://github.com/kwat0g/kwatog/pull/340) | merged as `ba5e429a` |
| F-18. Pricing notification role audience | `notifications/fix-pricing-notification-audience` | [#341](https://github.com/kwat0g/kwatog/pull/341) | merged as `aa8d28b2` |
| F-05. CRM inquiry badge filter parity | `notifications/fix-inquiry-badge-parity` | [#342](https://github.com/kwat0g/kwatog/pull/342) | merged as `2c0a78dd` |
| F-06. Notification event catalog coverage | `notifications/fix-event-catalog-coverage` | [#343](https://github.com/kwat0g/kwatog/pull/343) | merged as `2f921b21` |
| F-08. Notification deep-link anchors | `notifications/fix-notification-link-anchors` | [#344](https://github.com/kwat0g/kwatog/pull/344) | merged as `07279606` |
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
| F-25. Invoice payment-review recipient scope | `notifications/fix-invoice-payment-notice-audience` | [#362](https://github.com/kwat0g/kwatog/pull/362) | pending review |

## Coverage ledger

- NotificationService call sites inventoried: **129 / 129** found by scan and cross-check; all 129 remain on existing entry points.
- Direct `Mail::to(...)` call sites inventoried: **27 / 27** under `api/app`; mail recipient runtime outcomes not tested.
- Direct Laravel `->notify()` / `Notification::send()` and central preference email paths inventoried in discovery; mail-only account/security and summary paths remain separate.
- Internal sidebar routes inventoried: **73 / 73**; portal routes: **19 / 19**; compact-shell links: **9 / 9**.
- Phase 2 defines **87** signal IDs; static cross-reference found no undefined IDs in the 73 internal, 19 portal, or 9 compact route rows. Disabled/deferred signals stay labeled in the policy table.
- Badge definitions inventoried: **29**; currently wired to internal nav: **22**; unwired: **7**.
- Local role slugs: **17**; existing role-access audit says **16**. Reconcile before role-complete claim.
- Status/signal catalog: candidate data reviewed for all **23 modules**; exact enum classification, scope parity, and business approval remain outstanding.
- Unit 1 code changes are limited to `NotificationService` envelope normalization, its unit and portal tests, the SPA notification data type, and schema/progress documentation. No database migration or existing-row rewrite is needed.
- Payload schema v1 fields: `schema_version`, `event_type`, optional `subject_type`, `subject_id`, and caller-supplied `actor_id`. Existing `title`, `message`, `link_to`, event timing, recipients, channels, and legacy rows remain unchanged.
- Core `ApprovalService` action-required notices resolve only the active step's role holders and active delegates who pass the mapped action permission and `ApprovalTypeRegistry::userCanViewRecord`; exclude submitter and known acting approver. Store `recipient_reason` on each in-app row. F-16 outcome access is gated at all six inventoried senders. Other approval senders and event families remain on existing paths.
- Realtime notification events now publish only notification ID and type on the recipient's private channel; channel authorization requires both matching user ID and `notifications.view`. SPA fetches full content from `GET /notifications/{id}`, scoped to that user. `badges` remains an authenticated private, content-free refresh channel.

## Current confirmed findings

See `FINDINGS.md`. Remaining high-priority static mismatches include Notification Center behavior, configured-role audiences without record scope, and all-role badge parity (F-09), with board-count cap fixed in PR #347. PR #345 adds subject existence and known row-scope checks to private channels; all-role coverage and every chain type remain unverified. PR #346 adds `/dashboard/admin` for active system administrators who retain `dashboard.admin.view`; three permission-override notices remain no-link. PR #344 uses anchors for notification destinations. PR #341 respects pricing role lists while product/customer assignment scope remains open. Purchase Requests use an `awaiting_my_approval` filtered link from #348. PR #349 checks seeded-role badge visibility against destination read permissions. PR #350 tests that a permission holder outside the sender's configured role list receives no pricing notice. PR #351 adds a direct sales-order route, configured-planner access gates, creator targeting, actor exclusion, and idempotent delivery. PR #352 keeps `production.dashboard` private and sends this event only a refresh ping. No public channel or all-users notification dispatch found. No runtime cross-role leak has been proven.

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
- Six badge definitions remain unattached; see `FINDINGS.md`. No full role matrix or browser layout check.

## Separate finding fix: F-18

- Pricing notifications now intersect caller-supplied roles with the permission for the destination route. BOM updates use `mrp.boms.view`; unassigned cost-review notices remain permission-scoped when no role list is supplied.
- Earlier coverage: expiry reminder test **1 passed / 7 assertions**; BOM refresh test **1 passed / 11 assertions**. PR #350 adds `PricingReviewNotificationAudienceTest.php`, which passed **2 tests / 6 assertions** on its original feature head, including a seeded HR user with permission but outside the configured audience. Not rerun after current-main integration.
- Product/customer ownership or assignment fields were not found, so F-18 is partial. Empty role lists still represent a permission-wide cost-review queue; its intended audience remains a business question.

## Separate finding fix: F-06 notification catalog

- Added catalog and frontend metadata for `supplier.shipment_late`, `quality.late_incoming`, and all four emitted `production.rework.*` values. Rework titles now use human labels instead of raw state names.
- On the pre-merge feature head: `NotificationCatalogTest` **8 passed / 58 assertions**; `WorkOrderInlineReworkTest::test_resolved_wo_closes_automatically_once_with_notification` **1 passed / 6 assertions**; `notificationMeta.test.ts` **15 passed**. Tests were not rerun after merge.
- Recipient scope and negative role coverage remain open; this PR changes message metadata only.

## Separate finding fix: F-08 notification anchors

- Bell and Notification Center rows with allowed destinations now use anchors. Normal clicks and middle-clicks mark unread items read; missing and cross-realm portal links stay non-navigating.
- On the pre-merge feature head: `PortalNotifications.test.tsx` **5 passed**; axe accessibility gate passed. `impeccable detect` found no UI findings. Typecheck errors were limited to existing unrelated export, invite, forbidden-page, stock-transfer, and purchase-order files.
- Visual baseline was cancelled; no browser/manual visual QA or post-merge tests were run.

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

## Outstanding proof / risks

1. Review sensitive policy: payroll, HR/discipline, finance, executive escalation, system-admin fanout, portal contacts.
2. Seed one user per local role and department/site scope; run positive and negative recipient scenarios after approval.
3. Compare each badge to the destination list's total under same filter and authorization for every eligible role.
4. Verify channel authorization for unauthenticated, cross-user, and cross-scope cases.
5. Validate route links, legacy renderer, responsive/keyboard UI, and performance after implementation.
6. No test suite, browser/e2e session, or seeded-user scenario ran in discovery.

## Resume instruction

Continue Phase 3 from `02-policy-and-plan.md`, starting with remaining central-service work. Keep each unit on its own branch/PR and update this file with test evidence, remaining gaps, and findings.
