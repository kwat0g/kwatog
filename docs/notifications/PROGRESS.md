# Notification and badge remediation progress

**Updated:** 2026-10-26
**Current gate:** Phase 2 approved; Phase 3 units 1–3, F-16–F-17, F-01–F-06, F-08, and F-18 merged; F-12 subject-channel access in PR #345.

## Phase status

| Phase | Status | Evidence |
| --- | --- | --- |
| 0. Discovery | Draft complete | `00-discovery.md`: 23 modules, 129 in-app service call sites, 27 direct `Mail::to` sites, scheduled trigger table, static route-target scan, 14 broadcast classes, 73 internal routes, 19 portal routes, nine compact-shell links, 29 badge definitions. Static source review plus aggregate-only local DB query. |
| 1. Inventory / leak analysis | Draft complete; runtime proof outstanding | `01-inventory.md`: source audience expressions, direct delivery paths, 17-role static selector-risk matrix, 77 email-failure wrapper call sites, payload/link/dedupe expressions, badge audit, root causes, module coverage. No seeded-role scenarios or badge/list comparisons run. |
| 2. Policy and plan | Approved | User approved the proposed defaults on 2026-10-25. |
| 3. Backend foundation | In progress | Units 1–3 and F-16–F-17 merged (#332–#336); F-01–F-06, F-08, and F-18 fixes merged (#337–#344). Subject-channel access work is in PR #345. Global authorization coverage, signal registry, and remaining event families remain open. |
| 4. Frontend | In progress | PR #344 converts notification rows with allowed destinations to anchors and preserves filtered sidebar navigation from #342. Notification Center filters, accessibility, responsive QA, and multi-signal sidebar remain open. |
| 5. Migration | Partial | Core `ApprovalService` action-required notices migrated in #333. Remaining dispatch sites and all sidebar signals remain open. |
| 6. Extras | Not started | No extra features started. |
| 7. Verification | Partial | Targeted unit 1–3 and F-16, F-01–F-06, F-08, F-12, and F-18 tests passed on earlier feature heads. PR #345's broadcast authorization test passed before merge; post-merge tests for #334 and #336–#345 were not rerun. Visual baseline was cancelled; browser QA, all-role/channel coverage, broad badge parity, and full-suite verification remain open. The latest no-list-access endpoint assertion remains unrun. |

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
| F-12. Subject channel authorization | `notifications/fix-subject-channel-authorization` | [#345](https://github.com/kwat0g/kwatog/pull/345) | `a8fc0a59` |

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

See `FINDINGS.md`. Remaining high-priority static mismatches include Notification Center behavior, configured-role audiences without record scope, and the 100-row board cap (F-24). PR #345 adds subject existence and known row-scope checks to private channels; all-role coverage and every chain type remain unverified. PR #344 uses anchors for notification destinations. PR #341 respects pricing role lists while product/customer assignment scope remains open. Purchase Requests still link to the broad list. No public channel or all-users notification dispatch found. No runtime cross-role leak has been proven.

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
- Targeted API tests: `BadgeControllerTest` selected tests **3 passed / 12 assertions** on the pre-merge feature head. Post-merge tests were not rerun. The board summary still caps at 100 rows; F-24 tracks this separate limitation.
- Full `BadgeControllerTest` and all-role badge/list parity were not run. No frontend changes.

## Separate finding fix: F-03

- `PurchaseRequestAccessPolicy::pendingApprovalsFor()` supplies both the sidebar badge and existing pending-count endpoint. It applies current pending steps, active direct/delegated roles, submitter exclusion, PR row visibility, and requires both `purchasing.view` and `purchasing.pr.approve` before returning rows.
- Earlier targeted API coverage on the pre-merge feature head: `PurchaseRequestHardeningTest::test_pending_count_excludes_self_submissions_and_other_roles_steps` **1 passed / 18 assertions**; `BadgeControllerTest::test_department_head_sees_pending_approvals_with_severity` **1 passed / 6 assertions**. A new negative assertion for an approval-only user without list access was added after these runs and remains unverified.
- Sidebar still opens broad PR list; destination-filter parity remains under F-09. No frontend changes.

## Separate finding fix: F-04

- Payroll, Complaints, and Deliveries badge permissions now match their destination list routes: `payroll.periods.view`, `crm.complaints.view`, and either `supply_chain.view` or `supply_chain.deliveries.view`.
- `BadgeControllerTest::test_badge_permissions_match_destination_list_permissions` passed **1 test / 18 assertions** on the pre-merge feature head. Post-merge tests were not rerun; PHP was unavailable in the rebase worktree.
- This aligns visibility gates only. Count/filter parity and other route gates remain open.

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
- On the pre-merge feature head: expiry reminder test **1 passed / 7 assertions**; BOM refresh test **1 passed / 11 assertions**; `PricingReviewNotificationAudienceTest` **1 passed / 4 assertions**. Tests were not rerun after merging current `main` at `ba5e429a`.
- Product/customer ownership or assignment fields were not found, so F-18 is partial. No new broad fallback was added.

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

## Outstanding proof / risks

1. Review sensitive policy: payroll, HR/discipline, finance, executive escalation, system-admin fanout, portal contacts.
2. Seed one user per local role and department/site scope; run positive and negative recipient scenarios after approval.
3. Compare each badge to the destination list's total under same filter and authorization for every eligible role.
4. Verify channel authorization for unauthenticated, cross-user, and cross-scope cases.
5. Validate route links, legacy renderer, responsive/keyboard UI, and performance after implementation.
6. No test suite, browser/e2e session, or seeded-user scenario ran in discovery.

## Resume instruction

Continue Phase 3 from `02-policy-and-plan.md`, starting with remaining central-service work. Keep each unit on its own branch/PR and update this file with test evidence, remaining gaps, and findings.
