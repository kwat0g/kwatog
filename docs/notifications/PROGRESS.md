# Notification and badge remediation progress

**Updated:** 2026-10-25
**Current gate:** Phase 2 approved; Phase 3 foundation in progress. Latest separate finding fix is PR #344, stacked after PR #343.

## Phase status

| Phase | Status | Evidence |
| --- | --- | --- |
| 0. Discovery | Draft complete | `00-discovery.md`: 23 modules, 129 in-app service call sites, 27 direct `Mail::to` sites, scheduled trigger table, static route-target scan, 14 broadcast classes, 73 internal routes, 19 portal routes, nine compact-shell links, 29 badge definitions. Static source review plus aggregate-only local DB query. |
| 1. Inventory / leak analysis | Draft complete; runtime proof outstanding | `01-inventory.md`: source audience expressions, direct delivery paths, 17-role static selector-risk matrix, 77 email-failure wrapper call sites, payload/link/dedupe expressions, badge audit, root causes, module coverage. No seeded-role scenarios or badge/list comparisons run. |
| 2. Policy and plan | Approved | User approved the proposed defaults on 2026-10-25. |
| 3. Backend foundation | In progress | Units 1–3 have PRs; F-17 no-fallback fix is in PR #335; F-16 outcome access guards are in PR #336. Core approval notices resolve scoped recipients; inaccessible outcome recipients are suppressed across approval, budget, bill, invoice, and stock-adjustment paths; user-channel broadcasts carry IDs/types only and hydrate through an owner-scoped endpoint. Global authorization coverage, links, signal registry, and remaining event families are open. |
| 4. Frontend | In progress (F-08 navigation slice) | Bell and Center notification rows with permitted destinations are anchors; broader notification UI and multi-signal sidebar remain open. |
| 5. Migration | Not started | No event or badge migrated. |
| 6. Extras | Not started | No extra features started. |
| 7. Verification | Partial | Targeted Phase 3 unit 1–3 tests ran; role-leak, badge parity, frontend interaction, and full-suite verification remain open. |

## Phase 3 PR stack

| Unit | Branch | PR | Commit |
| --- | --- | --- | --- |
| 1. Versioned payload contract | `notifications/phase3-central-contract` | [#332](https://github.com/kwat0g/kwatog/pull/332) | `a8a479e0` |
| 2. Core approval recipients | `notifications/phase3-recipient-resolver` | [#333](https://github.com/kwat0g/kwatog/pull/333) | `e85cc70f` |
| 3. Realtime payload security | `notifications/phase3-realtime-payload` | [#334](https://github.com/kwat0g/kwatog/pull/334) | `cee47e18` |
| F-17. Custom approval escalation audience | `notifications/fix-custom-approval-escalation-audience` | [#335](https://github.com/kwat0g/kwatog/pull/335) | `50abb689`, `9b1c78c5` |
| F-16. Approval outcome access gates | `notifications/fix-approval-outcome-access` | [#336](https://github.com/kwat0g/kwatog/pull/336) | `c8115e8f`, `6f528ba5` |
| F-02. Approval badge source scope | `notifications/fix-approval-badge-scope` | [#337](https://github.com/kwat0g/kwatog/pull/337) | `af68eb60` |
| F-03. Purchase-request badge scope | `notifications/fix-pr-approval-badge-scope` | [#338](https://github.com/kwat0g/kwatog/pull/338) | `8512116d` |
| F-04. Badge destination permissions | `notifications/fix-badge-route-permissions` | [#339](https://github.com/kwat0g/kwatog/pull/339) | `7089562c` |
| F-01. Profile-request badge route and query parity | `notifications/fix-profile-request-badge-route` | [#340](https://github.com/kwat0g/kwatog/pull/340) | `93c0f12c` |
| F-18. Pricing notification role allow-list | `notifications/fix-pricing-notification-audience` | [#341](https://github.com/kwat0g/kwatog/pull/341) | `9e9243a0` |
| F-05. CRM inquiry badge filter parity | `notifications/fix-inquiry-badge-parity` | [#342](https://github.com/kwat0g/kwatog/pull/342) | `9fc19063` |
| F-06. Notification event catalog coverage | `notifications/fix-event-catalog-coverage` | [#343](https://github.com/kwat0g/kwatog/pull/343) | `673e3f5c` |
| F-08. Notification deep-link anchors | `notifications/fix-notification-link-anchors` | [#344](https://github.com/kwat0g/kwatog/pull/344) | `6b4fef06` |

## Coverage ledger

- NotificationService call sites inventoried: **129 / 129** found by scan and cross-check; all 129 remain on existing entry points.
- Direct `Mail::to(...)` call sites inventoried: **27 / 27** under `api/app`; mail recipient runtime outcomes not tested.
- Direct Laravel `->notify()` / `Notification::send()` and central preference email paths inventoried in discovery; mail-only account/security and summary paths remain separate.
- Internal sidebar routes inventoried: **73 / 73**; portal routes: **19 / 19**; compact-shell links: **9 / 9**.
- Phase 2 defines **87** signal IDs; static cross-reference found no undefined IDs in the 73 internal, 19 portal, or 9 compact route rows. Disabled/deferred signals stay labeled in the policy table.
- Badge definitions inventoried: **29**; currently wired to internal nav: **23**; unwired: **6**.
- Local role slugs: **17**; existing role-access audit says **16**. Reconcile before role-complete claim.
- Status/signal catalog: candidate data reviewed for all **23 modules**; exact enum classification, scope parity, and business approval remain outstanding.
- Unit 1 code changes are limited to `NotificationService` envelope normalization, its unit and portal tests, the SPA notification data type, and schema/progress documentation. No database migration or existing-row rewrite is needed.
- Payload schema v1 fields: `schema_version`, `event_type`, optional `subject_type`, `subject_id`, and caller-supplied `actor_id`. Existing `title`, `message`, `link_to`, event timing, recipients, channels, and legacy rows remain unchanged.
- Core `ApprovalService` action-required notices now resolve only the active step's role holders and active delegates who pass `ApprovalTypeRegistry::userCanViewRecord`; exclude submitter and known acting approver. Store `recipient_reason` on each in-app row. Core, budget, transfer, bill-payment, invoice, and stock-adjustment outcome notices are suppressed when recipients cannot open their destination record/list. Other approval senders and event families remain on existing paths.
- Realtime notification events now publish only notification ID and type on the recipient's private channel; channel authorization requires both matching user ID and `notifications.view`. SPA fetches full content from `GET /notifications/{id}`, scoped to that user. `badges` remains an authenticated private, content-free refresh channel.

## Current confirmed findings

See `FINDINGS.md`. Remaining high-priority static mismatches include other notification UI behavior and configured-role audiences without record scope. F-01 moves the profile-request badge to its queue and shares the list query; F-02 reuses the approval board filter; F-03 shares the purchase-request pending-approval scope with its count endpoint; F-04 aligns three badge permission gates with their destination routes; F-16 is fixed at all six inventoried sender paths. F-23 records the board's existing 100-item summary cap. No public channel or all-users notification dispatch found. No runtime cross-role notification leak has been proven.

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

## Separate finding fix: F-02 approval badge scope

- `BadgeService.approvals` now reads the approval board's `summary.my_action`, so it inherits the board's supported type, permission, delegation, and source-row filtering. The test verifies badge/board count parity and excludes an out-of-department leave row plus an unsupported approval type.
- Targeted API tests: `BadgeControllerTest` selected tests **3 passed / 12 assertions**. The board still caps its summary at the requested page size (default 100); this undercount is separately tracked as F-23. F-03 purchase-request badge scope remains open.
- Full BadgeControllerTest and all-role badge/list parity were not run. F-03 is covered in the following section; no frontend changes in F-02.

## Separate finding fix: F-03 purchase-request badge scope

- `PurchaseRequestAccessPolicy::pendingApprovalsFor()` is now the shared query for the sidebar badge and existing `pending-count` endpoint. It applies the current pending step, active direct/delegated role slugs, submitter exclusion, and the same row scope as the PR list. Badge visibility now requires `purchasing.view`; counting also requires `purchasing.pr.approve`.
- Targeted API test: `PurchaseRequestHardeningTest::test_pending_count_excludes_self_submissions_and_other_roles_steps` **1 passed / 18 assertions**, covering direct approver, active delegate, role with no workflow step, and a user whose approval-permission override still cannot open the list.
- The sidebar still navigates to the unfiltered PR list; badge drill-down parity remains open under F-09. No frontend changes.

## Separate finding fix: F-04 badge destination permissions

- Payroll badge now requires `payroll.periods.view`, Complaints requires `crm.complaints.view`, and Deliveries accepts `supply_chain.view` or `supply_chain.deliveries.view`, matching the sidebar and list routes.
- Targeted API test: `BadgeControllerTest::test_badge_permissions_match_destination_list_permissions` **1 passed / 18 assertions**. An employee with only `payroll.view` cannot open Payroll Periods and receives no periods badge; period-view, complaints-view-only, and warehouse-delivery users can open their destination and receive its badge.
- No frontend changes. Full role matrix and feature-flag combinations remain untested.

## Separate finding fix: F-01 profile-request badge

- Moved `profile_requests` from Employees to Bank Account Changes/Profile Change Requests. The badge now shares `ProfileUpdateRequestService::reviewQueueQuery()` with the list endpoint, so HR users count their default `pending` queue under the same department/view-all scope and Finance-only users count only `requires_finance` `pending_finance` rows across the finance queue.
- Targeted parity test: `BadgeControllerTest::test_profile_request_badge_matches_the_hr_and_finance_review_queues` **1 passed / 8 assertions**. Department-head list and badge both count **2**; finance-officer list and badge both count **3** in seeded test fixtures, including excluded statuses, non-finance rows, and an out-of-department HR row. Existing Finance bank-request workflow test: **1 passed / 34 assertions**. Sidebar permission suite: **21 passed**.
- The first `artisan test --filter` invocation could not load the test suite because an unrelated existing `AudienceFakeDetector::detect()` return type conflicts with `DetectorInterface`. Running the target test file directly with PHPUnit succeeded. Full role parity remains open under F-09.
- PHP syntax and `git diff --check` pass. `pint --test` still reports existing formatting violations in these legacy files; no file-wide formatting was applied.

## Separate finding fix: F-18 pricing notification role allow-list

- `PricingReviewService::notify()` now intersects its caller-supplied role allow-list with the destination permission. BOM notifications now use `mrp.boms.view`, which gates the linked list/detail route. No product/account owner or assignment field was found, so record-specific ownership remains unresolved; no new role was added.
- Targeted API tests: expiry reminder **1 passed / 7 assertions**; BOM cost-refresh negative recipient case **1 passed / 11 assertions**; audience batching **1 passed / 4 assertions**. The expiry test confirms Finance can open the shared agreement list but receives no expiry notice when absent from that event's allowed roles; the BOM test confirms an unlisted role with BOM view/manage permissions receives no notice while PPC does.
- PHP syntax and `git diff --check` pass. Full pricing-event role coverage and product/account assignment policy remain open.

## Separate finding fix: F-05 CRM inquiry badge

- The inquiry badge now counts only `ContactInquiryStatus::New`, the state that has not entered the triage workflow. `ContactInquiryInboxService::listQuery()` powers both the badge and list; the sidebar opens `/crm/inquiries?status=new`. Query-aware active-link and recent-page matching preserve navigation behavior with the filter.
- Targeted parity test: `BadgeControllerTest::test_inquiries_badge_matches_the_new_inquiry_list_filter` **1 passed / 4 assertions** for `customer_service_officer`; it excludes `in_progress` and `closed`. Sidebar permission and behavior tests: **46 passed** across two files. PHP syntax and `git diff --check` pass.
- Six orphan definitions remain and are still deferred until each has a verified destination filter; training's two signals also need multi-signal UI.

## Separate finding fix: F-06 notification event catalog

- Added preferences catalog and frontend metadata for `supplier.shipment_late`, `quality.late_incoming`, and all four emitted `production.rework` states. Rework notification titles now display human labels instead of enum-like state text. Recipient selection and event timing are unchanged.
- API: `NotificationCatalogTest` **8 passed / 58 assertions**; `WorkOrderInlineReworkTest::test_resolved_wo_closes_automatically_once_with_notification` **1 passed / 6 assertions**. SPA `notificationMeta.test.ts`: **15 passed**.
- PHP syntax and `git diff --check` pass. Frontend typecheck and full suites were not run.
- Existing recipient scope questions remain open; this metadata fix does not prove role isolation.

## Separate finding fix: F-08 notification links

- Bell and Center notification rows with allowed record destinations now use React Router anchors. Primary click marks unread rows read and navigates; middle-click marks read while preserving the browser's new-tab behavior. Portal rows still require the matching realm prefix. Missing or out-of-realm links keep the non-navigating button behavior.
- `PortalNotifications.test.tsx`: **5 passed**, including center navigation, bell navigation, middle-click read handling, and rejection of cross-realm links.
- `npm run typecheck` reports only existing errors in export, invite, stock-transfer, and purchase-order files; no error names either changed notification component. Impeccable mechanical detector returned no findings. Visual browser QA was not run.

## Outstanding proof / risks

1. Review sensitive policy: payroll, HR/discipline, finance, executive escalation, system-admin fanout, portal contacts.
2. Seed one user per local role and department/site scope; run positive and negative recipient scenarios after approval.
3. Compare each badge to the destination list's total under same filter and authorization for every eligible role.
4. Verify channel authorization for unauthenticated, cross-user, and cross-scope cases.
5. Validate route links, legacy renderer, responsive/keyboard UI, and performance after implementation.
6. No test suite, browser/e2e session, or seeded-user scenario ran in discovery.

## Resume instruction

Continue with the next isolated high-priority finding or Phase 3 unit from `02-policy-and-plan.md`. Keep each unit on its own branch/PR and update this file with test evidence, remaining gaps, and findings.
