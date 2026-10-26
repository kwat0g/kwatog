# Notification and badge remediation progress

**Updated:** 2026-10-26
**Current gate:** Delivery finance notice subject access is in draft PR [#448](https://github.com/kwat0g/kwatog/pull/448), stacked on F-50 machine site-scope gap #447, machine notice subject access #446, F-49 missing-timeout link filter #445, F-48 overtime requester exclusion #444, missing-timeout subject access #443, overtime event subject access #442, Leave event subject access #441, recruitment bottlenecks #440, new applications #439, onboarding #438, separation #437, training expiry #436, Payroll #435, Quality/RMA #434, Maintenance creator-exclusion #433, Maintenance subject access #432, and cost-review click-through #431. Earlier stack includes actor exclusion and subject access #430, item/GRN gates #429, Delivery gate #427, Purchase Requests #426, Purchase Orders #424, and separate F-41 fix #425. PR #448 finding-lifecycle passes; API CI fails before PHPUnit on inherited F-22 (`PdfRenderService::ensureDompdfDirs()` redeclaration at line 200). API CI on earlier stack PRs has the same blocker; SPA CI remains blocked by the existing dependency audit. Cross-role recipient coverage and badge/list parity remain open.

## Phase status

| Phase | Status | Evidence |
| --- | --- | --- |
| 0. Discovery | Draft complete | `00-discovery.md`: 23 modules, 129 in-app service call sites, 27 direct `Mail::to` sites, scheduled trigger table, static route-target scan, 14 broadcast classes, 73 internal routes at capture, 19 portal routes, nine compact-shell links, 29 badge definitions. Static source review plus aggregate-only local DB query. |
| 1. Inventory / leak analysis | Draft complete; runtime proof outstanding | `01-inventory.md`: source audience expressions, direct delivery paths, 17-role static selector-risk matrix, 77 email-failure wrapper call sites, payload/link/dedupe expressions, badge audit, root causes, module coverage. Initial audit had no seeded-role scenarios; later targeted badge/list comparisons are recorded below. |
| 2. Policy and plan | Approved | User approved the proposed defaults on 2026-10-25. |
| 3. Backend foundation | In progress | Units 1–3 have PRs; #397 removes the unused Laravel wrapper, #402 routes seven native sends through `NotificationService`, #403 filters inactive recipients, #404 carries the HR reset-signature fix, and #406 audits empty audiences without a broad fallback. F-17 no-fallback fix is in #335; F-16 outcome access guards are in #336. Core approval notices resolve scoped recipients; PR #412 adds a central fail-closed gate for the approval-backed subject types in `ApprovalTypeRegistry`. Inaccessible outcome recipients are suppressed across approval, budget, bill, invoice, and stock-adjustment paths; user-channel broadcasts carry IDs/types only and hydrate through an owner-scoped endpoint. PR #380 applies purchase-order permission and row visibility to late-supplier notices. PR #432 gates all eight Maintenance work-order and part-request dispatches; #434 gates Quality Inspection/NCR and supplier-return notices; #435 gates all four Payroll notices; #436 gates both training-expiry recipient branches; #437 gates the separation notice against clearance route access; #438 gates onboarding reminders against employee detail access and leaves reminders retryable when no configured role can open the employee; #439 gates new-application notices; #440 gates application/posting bottleneck notices; #441 gates all four Leave event notices against module access and `ApprovalSourceScope`; #442 gates submitted and decided overtime notices against their linked Attendance and self-service routes; #443 gates missing-timeout summaries against Attendance list access; #444 excludes the requesting employee from the overtime submission audience; #445 adds yesterday's explicit date range to missing-timeout summary links; #446 gates machine breakdown and repeat-breakdown notices against their linked MRP/Maintenance routes; #448 gates both no-invoice finance notices against the exact Delivery detail route and links directly to that delivery. Quality plant/lot scope, F-50 machine site/assignment scope, exact Payroll adjustment checker policy, other notification subjects, signal registry, and remaining event families remain open. |
| 4. Frontend | In progress, partial | Sidebar budget and transfer badges open their filtered lists in PRs #367 and #374. PR #377 exposes the budget queue to approval-only users without loading broad overview data. PR #378 converts linked Bell/Center rows to same-app anchors with mark-read behavior. PR #379 adds exact labels/groups for the discovered late-supply, incoming-quality, and dynamic rework event keys. PR #381 adds multi-signal Bills filters. PR #384 adds inquiry triage; PR #385 adds MRB holds; PR #386 adds inbound shipments; PR #387 adds Sales Orders confirmation; PR #388 adds Production work-order signals; PR #389 aligns Production SPA route and sidebar gates with the API; PR #392 adds Maintenance unassigned/open filters and multi-signal UI. F-31 aligns overtime API list/options gates; PR #394 adds delegate-aware overtime sidebar/page access. PR #396 gates Leave row, detail, and bulk actions by API-computed actionability. Draft PR #409 adds the permission-gated Employee Training Records list and two filtered sidebar signals. Draft PR #410 adds filtered internal AR invoice signals and a four-signal sidebar. Draft PR #411 adds customer-portal unpaid/overdue filters and two account-scoped invoice chips. Preferences, broader Center interactions, other multi-signal items, and browser QA remain open. |
| 5. Migration | In progress, partial | Budget, transfer, overdue-bill, unpaid-bill, due-soon bill, inquiry triage, MRB hold, mid-pipe shipment, Sales Orders confirmation, Production awaiting-confirmation, Production overdue, Production due-soon, Production paused, Maintenance open/unassigned, overtime awaiting-my-approval, leave awaiting-my-approval, HR training, internal AR invoice, and customer-portal AR invoice signals share list scopes in PRs #367, #369, #374, #381, #384, #385, #386, #387, #388, #392, #394, #395, #409, #410, and #411; PR #389 aligns existing Work Order badge audiences. PR #396 aligns Leave action controls with API actionability. Approval-only budget queue access is in #377. Remaining notification dispatches and badge definitions are unmigrated. |
| 6. Extras | Not started | No extra features started. |
| 7. Verification | Partial | Production badge and route permission tests pass 10/10 (127 assertions) across PRs #388–#389; Sidebar tests pass 64/64. Role-leak, all-module badge parity, browser QA, and full-suite verification remain open. |

## Phase 3 PR stack

| Unit | Branch | PR | Commit |
| --- | --- | --- | --- |
| 1. Versioned payload contract | `notifications/phase3-central-contract` | [#332](https://github.com/kwat0g/kwatog/pull/332) | `a8a479e0` |
| 2. Core approval recipients | `notifications/phase3-recipient-resolver` | [#333](https://github.com/kwat0g/kwatog/pull/333) | `e85cc70f` |
| 3. Realtime payload security | `notifications/phase3-realtime-payload` | [#334](https://github.com/kwat0g/kwatog/pull/334) | `cee47e18` |
| F-08. Notification row anchors | `notifications/fix-notification-row-anchors` | [#378](https://github.com/kwat0g/kwatog/pull/378) | `8eeec6fa` |
| F-06. Late supply, incoming quality, and rework metadata | `notifications/catalog-late-supply-rework-events` | [#379](https://github.com/kwat0g/kwatog/pull/379) | `b07a8a31` |
| F-28. Late supplier notice audience | `notifications/fix-late-supplier-notice-audience` | [#380](https://github.com/kwat0g/kwatog/pull/380) | `78d4d6e9` |
| F-13. AP multi-signal sidebar | `notifications/multi-signal-ap-badges` | [#381](https://github.com/kwat0g/kwatog/pull/381) | `4aa553ec`, `3da4952c`, `2f3a48e1`, `8b2b0629` |
| F-05/F-09. CRM inquiry triage badge | `notifications/triage-inquiry-badge` | [#384](https://github.com/kwat0g/kwatog/pull/384) | `f71c1125`, `8eb01b1f` |
| F-05/F-09. MRB held-list badge | `notifications/mrb-holds-sidebar` | [#385](https://github.com/kwat0g/kwatog/pull/385) | `aec4fc97`, `def5bc68` |
| F-04/F-05/F-09. Inbound shipments badge | `notifications/mid-pipe-shipments` | [#386](https://github.com/kwat0g/kwatog/pull/386) | `a168e7a7`, `7c5a50d7` |
| F-05/F-09. HR training badge/list parity | `notifications/hr-training-signal-parity` | [#409](https://github.com/kwat0g/kwatog/pull/409) | `f1f99dfc` |
| F-09. Sales Orders confirmation queue parity | `notifications/sales-order-confirmation-badge` | [#387](https://github.com/kwat0g/kwatog/pull/387) | `bcce67a0` |
| F-09. Production work-order signal/list parity | `notifications/production-overdue-badge` | [#388](https://github.com/kwat0g/kwatog/pull/388) | `f011d84c`, `60e20e2b`, `51bb83d3`, `25f9e78f` |
| F-29. Production parent-route permission parity | `notifications/fix-production-badge-route-permissions` | [#389](https://github.com/kwat0g/kwatog/pull/389) | `215d4130` |
| F-01. Profile-review actionable queue parity | `notifications/fix-profile-request-badge` | [#391](https://github.com/kwat0g/kwatog/pull/391) | pending |
| F-09. Maintenance Work Order badge/list parity | `notifications/maintenance-work-order-signals` | [#392](https://github.com/kwat0g/kwatog/pull/392) | `6ba5e170` |
| F-09/F-13. Internal AR invoice signal parity | `notifications/ar-invoice-signal-parity` | [#410](https://github.com/kwat0g/kwatog/pull/410) | `01ca4eb9`, `73f28caa` |
| F-09/F-13. Customer portal AR invoice signal parity | `notifications/customer-ar-signals` | [#411](https://github.com/kwat0g/kwatog/pull/411) | `e7e70315` |
| Phase 3. Approval-backed subject access gate | `notifications/subject-access-gate` | [#412](https://github.com/kwat0g/kwatog/pull/412) | `2eb2b843`, `e855165e` |
| Phase 3. Purchase Request subject access gate | `notifications/purchase-request-subject-access` | [#426](https://github.com/kwat0g/kwatog/pull/426) | `65e54054` |
| Phase 3. Delivery subject access gate | `notifications/delivery-subject-access` | [#427](https://github.com/kwat0g/kwatog/pull/427) | `df71d2c5` |
| F-42. Delivery source alert metadata | `fix/delivery-source-notification-type` | [#428](https://github.com/kwat0g/kwatog/pull/428) | `afb2e8a4` |
| Phase 3. Inventory item and GRN subject access gate | `notifications/inventory-subject-access` | [#429](https://github.com/kwat0g/kwatog/pull/429) | `ee1ccd26` |
| F-43. Cost-review actor exclusion and subject access | `notifications/item-cost-review-subject-access` | [#430](https://github.com/kwat0g/kwatog/pull/430) | `8a3292f6` |
| F-44. Exact cost-review notification links | `notifications/item-cost-review-deep-links` | [#431](https://github.com/kwat0g/kwatog/pull/431) | `fd584e13` |
| Phase 3. Maintenance subject access gates | `notifications/maintenance-subject-access` | [#432](https://github.com/kwat0g/kwatog/pull/432) | `cba920bb`, `32c987ef` |
| F-46. Maintenance assignment notice actor exclusion | `notifications/fix-maintenance-creator-notice` | [#433](https://github.com/kwat0g/kwatog/pull/433) | `d9501a33` |
| Phase 3. Quality Inspection, NCR, and supplier-return subject access | `notifications/quality-record-subject-access` | [#434](https://github.com/kwat0g/kwatog/pull/434) | `560577ef` |
| Phase 3. Payroll period, adjustment, and payslip subject access | `notifications/payroll-subject-access` | [#435](https://github.com/kwat0g/kwatog/pull/435) | `b733cfb6` |
| Phase 3. HR training-expiry subject access | `notifications/training-expiry-subject-access` | [#436](https://github.com/kwat0g/kwatog/pull/436) | pending |
| Phase 3. Separation clearance subject access | `notifications/separation-subject-access` | [#437](https://github.com/kwat0g/kwatog/pull/437) | pending |
| Phase 3. HR onboarding employee subject access | `notifications/onboarding-subject-access` | [#438](https://github.com/kwat0g/kwatog/pull/438) | pending |
| Phase 3. Recruitment application subject access | `notifications/recruitment-application-subject-access` | [#439](https://github.com/kwat0g/kwatog/pull/439) | pending |
| Phase 3. Recruitment bottleneck subject access | `notifications/recruitment-bottleneck-subject-access` | [#440](https://github.com/kwat0g/kwatog/pull/440) | pending |
| Phase 3. Leave event subject access | `notifications/leave-event-subject-access` | [#441](https://github.com/kwat0g/kwatog/pull/441) | pending |
| Phase 3. Overtime event subject access | `notifications/overtime-event-subject-access` | [#442](https://github.com/kwat0g/kwatog/pull/442) | `c44ff848` |
| Phase 3. Missing time-out subject access | `notifications/attendance-missing-timeout-access` | [#443](https://github.com/kwat0g/kwatog/pull/443) | `bb87b0c7` |
| F-48. Overtime requester self-notice | `notifications/fix-overtime-submitter-self-notice` | [#444](https://github.com/kwat0g/kwatog/pull/444) | `3ac2e582` |
| F-49. Missing-timeout notice date filter | `notifications/fix-missing-timeout-link-filter` | [#445](https://github.com/kwat0g/kwatog/pull/445) | `dd0f8321` |
| Phase 3. Machine breakdown notice subject access | `notifications/machine-maintenance-notice-access` | [#446](https://github.com/kwat0g/kwatog/pull/446) | `d61a79e7` |
| F-50. Unsupported machine site scope | `notifications/fix-machine-site-scope-finding` | [#447](https://github.com/kwat0g/kwatog/pull/447) | `26ab7989` |
| Phase 3. Delivery finance notice subject access | `notifications/delivery-invoice-notice-access` | [#448](https://github.com/kwat0g/kwatog/pull/448) | `568d207d` |
| F-31. Overtime list/options permission parity | `notifications/fix-overtime-list-permission` | [#393](https://github.com/kwat0g/kwatog/pull/393) | `93485538` |
| F-09. Overtime approval badge/list parity | `notifications/overtime-approval-signal` | [#394](https://github.com/kwat0g/kwatog/pull/394) | `3fef0452` |
| F-09. Leave approval badge/list parity | `notifications/leave-approval-signal` | [#395](https://github.com/kwat0g/kwatog/pull/395) | `69ca1a5e` |
| F-32. Leave action visibility and stage authorization | `notifications/fix-leave-action-authorization` | [#396](https://github.com/kwat0g/kwatog/pull/396) | pending |
| 1.1. Native dispatch boundary guard | `notifications/central-dispatch-guard` | [#397](https://github.com/kwat0g/kwatog/pull/397) | pending |
| F-12. Chain broadcast payload minimization | `notifications/minimize-chain-broadcast-payload` | [#398](https://github.com/kwat0g/kwatog/pull/398) | pending |
| F-12. Chain channel route authorization | `notifications/scope-purchase-order-chain-channel` | [#399](https://github.com/kwat0g/kwatog/pull/399) | pending |
| F-12. Payroll progress channel route authorization | `notifications/scope-payroll-progress-channel` | [#400](https://github.com/kwat0g/kwatog/pull/400) | pending |
| F-12. Operational channel route authorization | `notifications/scope-operational-channels` | [#401](https://github.com/kwat0g/kwatog/pull/401) | pending |
| F-17. Custom approval escalation audience | `notifications/fix-custom-approval-escalation-audience` | [#335](https://github.com/kwat0g/kwatog/pull/335) | `50abb689`, `9b1c78c5` |
| F-16. Approval outcome access gates | `notifications/fix-approval-outcome-access` | [#336](https://github.com/kwat0g/kwatog/pull/336) | `c8115e8f`, `6f528ba5` |
| F-03. Purchase-request badge/list scope | `notifications/fix-purchase-request-badge-scope` | [#348](https://github.com/kwat0g/kwatog/pull/348) | `6c25d82c` |
| F-04. Badge destination permission gates | `notifications/fix-badge-permission-gates` | [#349](https://github.com/kwat0g/kwatog/pull/349) | `3b759909` |
| F-02. Approval badge source scope | `notifications/fix-approval-badge-scope` | [#337](https://github.com/kwat0g/kwatog/pull/337) | `af68eb60` |

## Coverage ledger

- NotificationService call sites inventoried: **129 / 129** found by scan and cross-check; all 129 remain on shared service entry points. Eight Maintenance dispatches use `sendForSubject()` in #432; #434 migrates 11 Quality and linked return-notice dispatches; #435 migrates all four Payroll dispatches; #436 migrates both training-expiry recipient branches; #437 migrates the separation clearance dispatch; #438 migrates stale-onboarding reminders; #439 migrates new-application notice; #440 migrates application and posting bottleneck notices; #441 migrates all four Leave event dispatches; #442 migrates both overtime event dispatches; #443 migrates both missing-timeout summary dispatches. #444 excludes overtime request owners from approval notices; #445 links missing-timeout summaries to their exact date range; #446 gates both machine maintenance notices; #448 gates both no-invoice delivery finance notices. All dispatched Maintenance, Quality, Payroll, training-expiry, separation, onboarding, recruitment, Leave, overtime, missing-timeout, machine breakdown, and no-invoice delivery finance notices handled in these units now use the subject gate.
- Direct `Mail::to(...)` call sites inventoried: **27 / 27** under `api/app`; mail recipient runtime outcomes not tested.
- Direct Laravel `->notify()` / `Notification::send()` and central preference email paths inventoried in discovery; mail-only account/security and summary paths remain separate.
- Internal sidebar routes: **73** in the Phase 0 baseline; current branches add `/budgeting/transfers`, `/inventory/mrb`, and `/supply-chain/shipments` (**76 current**); portal routes: **19**; compact-shell links: **9**.
- Phase 2 approved **87** signal IDs; implementation splits the budget/transfer approval signal into two source-specific definitions, adds MRB holds, mid-pipe shipments, paused work orders, and awaiting-confirmation work orders (**92 current**). Maintenance unassigned implements an existing approved signal. Baseline cross-reference covered 73 internal, 19 portal, and nine compact routes.
- Badge definitions inventoried: **29**; AP unpaid/due-soon, Production awaiting-confirmation/due-soon/paused, Maintenance unassigned, and AR unpaid/overdue/payment-review add nine definitions (**38**); baseline wired to internal nav: **22**; current branches wire **35**, leaving **3** unattached.
- Customer portal now renders two additional account-bound badge instances for the existing `finance.ar.unpaid` and `finance.ar.overdue` definitions; they are tracked separately from internal sidebar wiring counts.
- Local role slugs: **17**; existing role-access audit says **16**. Reconcile before role-complete claim.
- Status/signal catalog: candidate data reviewed for all **23 modules**; exact enum classification, scope parity, and business approval remain outstanding.
- Unit 1 code changes are limited to `NotificationService` envelope normalization, its unit and portal tests, the SPA notification data type, and schema/progress documentation. No database migration or existing-row rewrite is needed.
- Payload schema v1 fields: `schema_version`, `event_type`, optional `subject_type`, `subject_id`, and caller-supplied `actor_id`. Existing `title`, `message`, `link_to`, event timing, recipients, channels, and legacy rows remain unchanged.
- Core `ApprovalService` action-required notices now resolve only the active step's role holders and active delegates who pass `ApprovalTypeRegistry::userCanViewRecord`; exclude submitter and known acting approver. Store `recipient_reason` on each in-app row. Core, budget, transfer, bill-payment, invoice, and stock-adjustment outcome notices are suppressed when recipients cannot open their destination record/list. Other approval senders and event families remain on existing paths.
- Realtime notification events now publish only notification ID and type on the recipient's private channel; channel authorization requires both matching user ID and `notifications.view`. SPA fetches full content from `GET /notifications/{id}`, scoped to that user. `badges` remains an authenticated private, content-free refresh channel.

## Current confirmed findings

See `FINDINGS.md`. Remaining high-priority static mismatches include notification UI behavior and configured-role audiences without record scope. F-01's badge placement is in PR #340; PR #391 adds the approved actionable signal and exact filtered destination. F-02 reuses the approval board filter; F-03 shares a PR current-step scope across the badge, pending-count endpoint, and filtered list; F-04 aligns three badge gates to their destination reads; F-06 event metadata is in PR #379; F-16 is fixed at all six inventoried sender paths; F-28 scopes the late-supplier notice. F-23's 100-item board summary cap fix is tracked in PR #347. F-46's Maintenance actor exclusion is fixed in draft PR #433. No public channel or all-users notification dispatch found. No runtime cross-role leak has been proven beyond findings recorded here.

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

## Separate finding fix: F-07 training-expiry deep links (draft PR [#365](https://github.com/kwat0g/kwatog/pull/365))

- Employees now get a hash-addressed self-service training-record link. Its endpoint resolves the session employee and returns only their matching record; a second employee receives 404. Configured HR/department recipients get the exact HR training-record route, gated by `hr.employees.trainings.view` and `EmployeeCompetenceScope`.
- `TrainingExpiryAlertTest.php`: **11 passed / 39 assertions**, including exact per-audience links, HR access, employee-owner access, cross-employee 404, and employee denial on the HR route. SPA route test: **2 passed**. Targeted ESLint, PHP syntax, and `git diff --check` pass.
- New detail view is read-only and shares one component across the owner-only and scoped-HR APIs. Visual QA and full-suite verification remain outstanding. F-14's message-detail and recipient-scope review remains separate.

## Separate finding fix: F-14 training-expiry department scope (draft PR #405)

- Added a seeded recipient test for the existing training-expiry audience. A department head linked to an employee in the training employee's department receives the notice and opens the exact training record; a different-department head receives no notice and gets 404.
- `TrainingExpiryAlertTest.php --filter=test_training_expiry_notice_only_reaches_department_heads_with_employee_scope`: **1 passed / 4 assertions**. This supplements PR #365's employee-owner, HR-scope, and cross-employee link coverage.
- Message-detail minimization remains open. API CI inherits F-22 and fails before PHPUnit; finding-lifecycle passes.

## Separate finding fix: F-10 shared alert unread/list parity (draft PR [#366](https://github.com/kwat0g/kwatog/pull/366))

- `/alerts` accepts `is_read`; unread count and list use the same active, non-dismissed, unread predicate. The alert page labels this as shared state because the database stores `is_read` on the global alert row, and its “Show shared unread alerts” action resets other filters before opening the matching list.
- `AlertsHardeningTest` targeted parity test: **1 passed / 7 assertions**. `pages/alerts/index.test.tsx`: **3 passed**. Direct PHPUnit, targeted SPA tests, PHP syntax, ESLint, and `git diff --check` passed. Full SPA typecheck still fails in exports, Action Center, invite, stock transfer, purchase-order, and profile files; none is in this PR.
- `php artisan test --filter=...` still fails before filtering because existing `RecommendationAudienceScopeTest.php` declares `AudienceFakeDetector::detect(): array`, incompatible with `DetectorInterface::detect(): DetectorScanResult`; running the target file with direct PHPUnit succeeds. No sidebar item or existing SPA consumer uses `/alerts/unread-count`; it is a page-level count action only. Whether read state should be personal remains a business question.

## Separate finding fix: F-05/F-09 budget-transfer badge/list parity (draft PR [#367](https://github.com/kwat0g/kwatog/pull/367))

- Adds Finance > Budget Transfers, gated by `budgeting.view` or `budgeting.approve`. A positive `pending_transfers` badge opens `/budgeting/transfers?status=pending&actionable=1`; approval-only users can open only transfers they may approve. The filter and badge both use `BudgetTransfer::scopeAwaitingApprovalFor()`, matching the service's direct/delegated role-separation rule. Draft PR #374 completes the separate budget queue.
- `BudgetTransferTest.php`: **2 passed / 19 assertions** for approval-only access, same-role exclusion, active delegation, and badge/list parity. `BadgeControllerTest.php`: **1 passed / 11 assertions** for VP badge/list parity. `Sidebar.behavior.test.tsx`: **28 passed**; `Sidebar.permissions.test.tsx`: **21 passed**.
- Changed PHP files passed syntax checks. Model, controller, and transfer workflow test passed targeted Pint; targeted ESLint and `git diff --check` passed. Full SPA typecheck still fails in unrelated export, Action Center, invite, stock-transfer, purchase-order, and profile files; no changed file was named. Pint still flags style rules in `routes.php` and `BadgeControllerTest.php`; broad formatting was avoided. Mechanical Impeccable detector reported no findings. Browser visual QA and full-suite verification remain outstanding.

## Separate finding fix: F-09 AP overdue badge/list parity (draft PR [#369](https://github.com/kwat0g/kwatog/pull/369))

- `Bill::scopeOverdue()` is shared by the `overdue_bills` badge and `/bills?overdue=1` list filter. Definition: unpaid or partially paid bill with due date before `today()` in `Asia/Manila`; draft, paid, and cancelled statuses are excluded. Database schema requires a due date.
- Sidebar badge opens `/accounting/bills?overdue=1`. Phase 2's earlier `signal=overdue` target did not match the existing page filter and is corrected in the catalog.
- `BadgeControllerTest.php`: **15 passed / 175 assertions**, including Finance Officer badge/list parity and due-today, paid, draft, and cancelled exclusions. `Sidebar.behavior.test.tsx`: **29 passed** after the first run showed the active Finance link needed no section-toggle interaction; the test now clicks the link directly. Targeted ESLint passes.
- PHP syntax and `git diff --check` pass. Pint `--test` reports style violations across the four checked PHP files; no broad formatting was applied. Browser visual QA and full-suite verification remain outstanding.

## Separate finding fix: F-08 notification row anchors (draft PR [#378](https://github.com/kwat0g/kwatog/pull/378))

- Bell and Center rows with valid app destinations now render as React Router anchors. Clicking marks the row read; middle-click/open-in-new-tab retains native anchor behavior. Portal destinations must stay within the current customer/supplier path; external, malformed, and cross-realm links are rejected. Maintenance repeat-breakdown rows retain their schedule destination.
- `notificationLink.test.ts` and `PortalNotifications.test.tsx`: **6 passed**, including Bell and Center click-through, mark-read, and portal-scope checks. Targeted ESLint and `git diff --check` passed.
- SPA typecheck still fails on existing unrelated export API, missing `lucide-react`, stock-transfer API, and duplicate purchase-order prop errors; no changed file appears. Browser middle-click, deleted/forbidden record handling, and visual QA remain outstanding.

## Separate finding fix: F-06 notification event metadata (draft PR [#379](https://github.com/kwat0g/kwatog/pull/379))

- Added backend preference-catalog entries and exact SPA icon/group/label metadata for `supplier.shipment_late`, `quality.late_incoming`, and all emitted `production.rework.*` states (`required`, `awaiting_qc`, `qc_failed`, `closed`). Verified each event's linked PO, NCR, production work-order, or self-service work-order route exists in the SPA.
- API `NotificationCatalogTest.php`: **8 passed / 58 assertions**. SPA `notificationMeta.test.ts`: **15 passed**. Targeted ESLint and `git diff --check` passed.
- This closes metadata coverage only. It does not broaden or repair the events' recipient selectors; those remain in the recipient audit and separate findings.

## Separate finding fix: F-28 late supplier notice audience (draft PR [#380](https://github.com/kwat0g/kwatog/pull/380))

- Late-ETA notifications now resolve active `purchasing.delivery_schedules.review` permission holders, exclude `system_admin`, and require both purchase-order detail permission (`purchasing.view` or `purchasing.po.approve`) and `PurchaseOrderAccessPolicy::canView()` for the exact PO. Each notice stores `purchasing_delivery_schedule_reviewer` as its recipient reason. Event timing, ETA condition, message, deep link, and dedupe key stay unchanged.
- Before the fix, the negative test failed because a system administrator received the notice. Its custom review-only fixture also lacked the route's `purchasing.view`/`purchasing.po.approve` gate; the old selector included that role. After the fix, the existing purchasing-officer positive case plus both negative cases pass: **2 tests / 13 assertions**. `git diff --check` passes.
- API test execution ran in the mounted Laravel container with the changed files safely overlaid and restored. The host has no PHP CLI, so host-side syntax and Pint checks were not run.
- PR #380 API CI failed before PHPUnit during its PHP syntax scan on the inherited duplicate `PdfRenderService::ensureDompdfDirs()` declaration (F-22); governance check passed. No changed-file failure was reported.

## Separate signal fix: F-13 Accounts Payable multi-signal item (draft PR [#381](https://github.com/kwat0g/kwatog/pull/381))

- Accounts Payable exposes overdue, unpaid-balance, and due-soon signals. `Bill::scopeOverdue()` backs `overdue=1`; `Bill::scopeUnpaid()` means status `unpaid`/`partial` with stored balance > 0 and backs `signal=unpaid`; `Bill::scopeDueSoon()` uses that same open-balance scope with due dates today through +3 days inclusive in `Asia/Manila` and backs `signal=due_soon`. Draft, paid, cancelled, and zero-balance rows are excluded from the two balance-based signals. All three counters reuse one aggregate query composed from model scopes.
- Sidebar Bills shows labeled severity chips, opens the highest-severity active signal on item click, and provides a signal dialog with every active filtered link. Expanded, collapsed, hover, focus, click, Escape, severity priority, and filter-link behavior are covered by `Sidebar.behavior.test.tsx`.
- API `BadgeControllerTest.php` targeted permission and parity cases: **4 passed / 89 assertions**. SPA `Sidebar.behavior.test.tsx`: **30 passed**. Targeted ESLint and `git diff --check` pass.
- `npm run typecheck` reports only existing errors in export helpers, missing `lucide-react`, stock-transfer API exports, and duplicate purchase-order props; none names a changed file. Pint `--test` reports style issues in all four touched PHP files; no broad formatting was applied. Browser visual QA and full-suite checks remain outstanding. Multi-signal coverage for other modules remains open.
- PR #381 API CI stops before PHPUnit on inherited F-22 (`PdfRenderService::ensureDompdfDirs()` redeclaration). SPA CI stops at dependency audit on existing advisories for `@vitest/mocker`, `undici`, and `zod`; accessibility and visual-baseline checks pass. No changed-file CI failure is reported.

## Separate signal fix: CRM inquiry triage badge (draft PR [#384](https://github.com/kwat0g/kwatog/pull/384))

- The existing `inquiries` counter included both `new` and `in_progress` records, though only `new` means nobody has started handling the inquiry. It now counts `ContactInquiryStatus::New` and uses the same `scopeAwaitingTriage()` as `/crm/inquiries?status=new`.
- The sidebar badge opens that status filter. Badge counts require `crm.inquiries.manage`; the destination also requires `crm.inquiries.view`. Read-only users can open the list but receive a zero actionable count.
- `BadgeControllerTest::test_inquiry_badge_matches_new_inquiry_list_and_requires_manage_permission`: **1 passed / 10 assertions** in an isolated PostgreSQL test container. It covers the New/In Progress/Closed partition, count/list parity, and a view-only user. `Sidebar.behavior.test.tsx`: **31 passed**, including filtered click-through. Targeted ESLint, PHP syntax, and `git diff --check` pass.
- The inquiry model is already registered with `BadgeInvalidationObserver`, so status changes invalidate cached counts and trigger the existing badge refresh event. Browser visual QA and full-suite verification remain outstanding.

## Separate signal fix: MRB held-list badge (draft PR [#385](https://github.com/kwat0g/kwatog/pull/385))

- The existing `mrb_holds` count now shares `MaterialReviewRecord::scopeHeld()` with `/inventory/mrb?status=held`. `held` is the only active hold state; `released`, `scrapped`, and `returned` are excluded. The list and badge both use `inventory.mrb.view`, and the list currently has no site or department filter.
- Warehouse navigation now exposes MRB / Quarantine with the badge and opens the held filter. The item uses the dedicated `inventory.mrb.view` permission, not broad `inventory.view`. `MaterialReviewRecord` was already registered with `BadgeInvalidationObserver`.
- API: `BadgeControllerTest::test_mrb_hold_badge_matches_the_filtered_list_and_requires_view_permission`: **1 passed / 8 assertions**, including two held rows, one released row, list/badge parity, and denied employee access. SPA `Sidebar.behavior.test.tsx` and `Sidebar.permissions.test.tsx`: **55 passed**. Targeted ESLint and `git diff --check` pass.
- `pint --test` reports file-wide style issues in `BadgeService.php` and `BadgeControllerTest.php`; `MaterialReviewRecord.php` import ordering was fixed and `QuarantineService.php` passes. No broad formatting was applied. Browser visual QA, full suites, other-role runtime checks, and CI remain outstanding.
- PR #385 CI: governance, accessibility, and visual baselines pass; API fails before PHPUnit on F-22's duplicate `PdfRenderService::ensureDompdfDirs()`; SPA fails `Audit all dependencies` on existing advisories.

## Separate signal fix: inbound shipment badge (draft PR [#386](https://github.com/kwat0g/kwatog/pull/386))

- The `shipments` badge and list now share `Shipment::scopeMidPipe()` for active `in_transit`, `customs`, and `cleared` rows. Ordered, shipped, received, cancelled, and soft-deleted rows are excluded. The list filter exposes the same grouped stages as `status=mid_pipe`.
- Supply Chain navigation now includes Inbound Shipments. Both `supply_chain.view` and the narrower `supply_chain.shipments.view` permission open the route and receive the badge; warehouse users with delivery-only access do not.
- API `BadgeControllerTest::test_shipment_badge_matches_the_mid_pipe_list_for_both_read_permissions`: **1 passed / 19 assertions**. Covers all `ShipmentStatus` cases, soft-deleted exclusion, badge/list parity for Purchasing and ImpEx, and Warehouse denial. SPA Sidebar behavior and permission tests: **57 passed**. Targeted ESLint and `git diff --check` pass.
- `Shipment` is already registered with `BadgeInvalidationObserver`. Pint reports pre-existing style issues in `Shipment.php` (`ordered_traits`, `binary_operator_spaces`); the new scope and `ShipmentService.php` pass its checks. PR #386 CI: governance, accessibility, and visual baselines pass; API fails before PHPUnit on F-22's duplicate `PdfRenderService::ensureDompdfDirs()`; SPA stops at dependency audit (2 moderate, 2 high advisories). Browser visual QA and full suites remain outstanding.

## Separate signal fix: Sales Orders confirmation queue (draft PR [#387](https://github.com/kwat0g/kwatog/pull/387))

- The `pending_so` badge previously counted every draft, including drafts not sent to a customer. `SalesOrder::scopeAwaitingConfirmation()` now matches the workflow: sent drafts with `customer_confirmation_requested_at`, `awaiting_confirmation`, and `delivery_negotiation`. Unsent drafts, terminal statuses, and soft-deleted orders are excluded.
- `GET /crm/sales-orders?signal=awaiting_confirmation` and the badge share that scope. The Sales Orders sidebar badge opens this filter; the list exposes a Queue selector so users can see and change the pre-applied filter.
- API `BadgeControllerTest::test_sales_order_confirmation_badge_matches_its_filtered_list`: **1 passed / 8 assertions**, covering every `SalesOrderStatus`, requested versus unsent drafts, deleted-row exclusion, access denial, and count/list parity. SPA `Sidebar.behavior.test.tsx`: **34 passed**. Targeted ESLint, PHP syntax, and `git diff --check` pass. Full SPA typecheck still fails in unrelated export, `lucide-react`, stock-transfer, and purchase-order files; no changed file is named. Pint reports the same style failures on these five files at the parent revision; no broad formatting was applied. Browser QA and PR CI remain outstanding.

## Separate signal fix: Production overdue, due-soon, and paused badge/list parity (draft PR [#388](https://github.com/kwat0g/kwatog/pull/388))

- Before the fix, `signal=overdue` returned 8 work orders while the badge counted 2. `WorkOrder::scopeOverdue()` now defines the shared set: status `confirmed` or `in_progress`, non-null `planned_end < now()` in `app.timezone` (default `Asia/Manila`), and not soft-deleted. The badge and filtered list both use this scope.
- The Production Work Orders badge links to `/production/work-orders?signal=overdue`; the page exposes the Signal filter and the API rejects unknown signal values. The `production_manager` role can open the list and receives the badge; the `employee` role can do neither.
- `BadgeControllerTest::test_production_overdue_badge_matches_filtered_list_for_every_work_order_status`: **1 passed / 9 assertions**, covering all enum states, a future due time, a deleted row, permission access, and count/list parity. `WorkOrderListFilterTest::test_invalid_signal_is_rejected`: **1 passed / 3 assertions**. `Sidebar.behavior.test.tsx`: **35 passed**. Targeted ESLint, PHP syntax, and `git diff --check` pass.
- The current work-order list has no site or line row scope, so this parity fix retains its permission-wide company scope. The policy catalog now records that the earlier site-scope proposal is not enforced by the current endpoint.
- Production Work Orders also has `Awaiting confirmation`: exact `planned` status, sharing `WorkOrder::scopeAwaitingConfirmation()` between badge and `signal=release` list filter. It requires `production.view`, `production.work_orders.view`, and `production.wo.confirm`; seeded PPC Head has all three, while view-only, confirm-only, and parent-route-denied overrides do not receive the badge. Destination list is company-wide, with no assigned confirmer or site scope.
- Production Work Orders also has `Due soon` and `Paused` signals. Due soon includes planned, confirmed, in-progress, and paused work orders with `planned_end` from now through the end of the third upcoming Manila calendar day. `production.work_orders.due_soon_days` defaults to 3 and is admin-editable from Settings. Badge and `signal=due_soon` list filter share `WorkOrder::scopeDueSoon()`; terminal, past-end, beyond-window, and soft-deleted rows are excluded. Paused uses `WorkOrder::scopePaused()` and requires lifecycle permission. Sidebar shows separate chips and links each to its filter.
- `Paused` uses `WorkOrder::scopePaused()` and requires `production.view`, `production.work_orders.view`, and `production.work_orders.lifecycle` so each badge holder can open the queue and act. PPC Head can open the filtered list but receives no paused badge because it lacks lifecycle permission. Generic material-blocked work orders remain deferred: pause reasons are unstructured and include machine downtime, so they are not labeled as material shortages.
- `BadgeControllerTest --filter=production_`: **4 passed / 59 assertions**, covering every work-order status, Manila due-window edges, deleted rows, Production Manager parity, PPC Head release access, and negative view/confirm/module gates. `WorkOrderListFilterTest --filter=release`: **1 passed / 3 assertions**. The due-soon filter and Settings governance tests previously passed **1/2** and **1/6** assertions. `Sidebar.behavior.test.tsx`: **36 passed**, including four filtered links and severity-first primary routing. PHP syntax, targeted ESLint, and `git diff --check` pass. Pint `--test` reports style debt in `BadgeService.php` and two existing feature test files; no broad formatting was applied.
- New awaiting-confirmation/paused and existing overdue/due-soon badges require the exact parent and Work Order permissions enforced by their API routes. F-29 also wraps the Production SPA route group in `production.view` and gates the sidebar item on both route permissions. Targeted negative override tests prove users with only one permission get no badge, no navigation link, and no data route. Full SPA typecheck still reports unrelated export, missing `lucide-react`, stock-transfer API, and duplicate purchase-order prop errors. Browser QA, full suites, and current-head PR CI remain outstanding.

## Separate finding fix: F-29 Production parent-route permission parity (draft PR #389)

- `Production/routes.php` requires `production.view` at the module route group before individual Work Order permissions. Existing overdue/due-soon badges and SPA navigation had omitted that parent requirement.
- Badge definitions now require `production.view` in addition to `production.work_orders.view`; the Sidebar requires both, and all Production SPA routes now sit behind the same parent permission guard as the API.
- `BadgeControllerTest --filter=production_`: **5 passed / 65 assertions**, including an override user with Work Order view permission but no module permission. That user gets 403 from the list API and no overdue or due-soon badge.
- `Sidebar.permissions.test.tsx`: **25 passed**. `productionRoutes.permissions.test.tsx`: **3 passed**, covering parent-only, Work-Order-only, and both permissions.
- PHP syntax, targeted ESLint, and `git diff --check` pass. Full suite and browser QA remain outstanding.

## Separate finding fix: F-30 Production assignment alert access (draft PR #390)

- Equipment-triggered assignment alerts added active `maintenance_tech` users even though the linked Work Order route requires `production.view` and `production.work_orders.view`, which the seeded technician role lacks.
- The existing PPC and Production Manager audience and event timing stay unchanged. The sender now filters recipients through both permissions before calling `NotificationService`.
- `ProductionAssignmentAlertAudienceTest`: **1 passed / 10 assertions**. Confirms PPC and Production Manager receive the alert, Maintenance Technician does not, and an eligible notice links to the exact Work Order.
- PHP syntax and `git diff --check` pass. `pint --test` reports pre-existing style debt in the inventory query of `AlertEngineService.php`, outside the changed lines. PR CI and full-suite verification remain outstanding.

## Separate finding fix: F-01 Profile Update actionable badge/list parity (draft PR [#391](https://github.com/kwat0g/kwatog/pull/391))

- The `profile_requests` badge now sits on Bank Account Changes and opens `/hr/profile-update-requests?signal=awaiting_my_action`. The approved filter includes only work the current user can perform: HR users need both `hr.employees.view` and `hr.employees.edit` and retain `DepartmentScope`; Finance needs `hr.profile_updates.finance_review` and sees only bank changes after HR review. Both queues exclude self-submissions, and Finance excludes the prior HR reviewer. Users with both audiences see their deduplicated union.
- `ProfileUpdateRequestService::awaitingMyActionQuery()` supplies the same scope to the badge count and filtered list. Existing unfiltered HR/Finance lists and their default status behavior remain unchanged. The action-filter page hides the status control because this signal spans the active HR and Finance stages.
- API `ProfileUpdateActionBadgeParityTest` plus `ProfileUpdateSodTest`: **9 passed / 69 assertions**. Coverage compares exact badge and destination totals for HR, Finance, dual-permission, department-scoped HR, and view-only users; it includes requester and previous-reviewer exclusions. SPA Sidebar and profile-request page tests: **40 passed**. Targeted ESLint, PHP syntax, and `git diff --check` pass.
- Pint `--test` reports two existing style issues in `BadgeService.php` and `ProfileUpdateRequestService.php`; the new API test passes Pint. No broad formatting was applied. Full suites and browser visual QA remain outstanding.
- Full SPA typecheck reports existing export-helper, missing `lucide-react`, stock-transfer API, and duplicate purchase-order property errors; none names a changed file.
- PR checks: API syntax scan stops on the inherited duplicate `PdfRenderService::ensureDompdfDirs()` in F-22 before PHPUnit; SPA dependency audit stops on four existing advisories (two moderate, two high) before typecheck/Vitest/build. Accessibility, visual baselines, and finding-lifecycle pass.

## Separate finding fix: F-09 Maintenance Work Order badge/list parity

- `maintenance_wo` uses `MaintenanceWorkOrder::scopeOpen()` for both its count and `/maintenance/work-orders?queue=open`. `maintenance_wo_unassigned` uses `scopeUnassigned()` for both its count and `queue=unassigned`; that signal requires both `maintenance.view` and `maintenance.wo.assign`.
- The current list has company-wide visibility for `maintenance.view`; assigned employee scoping applies only to users without that permission. `maintenance_work_orders` has no site field and the list applies no site filter. This follows the existing route/list scope, but does not fulfill the policy's proposed site restriction. The unassigned signal is limited to assign-capable users; site-based restriction remains an implementation/business gap.
- The policy catalog proposes `signal=unassigned`; the actual list API and SPA use `queue=unassigned`. The sidebar opens the actual filter. Open Work Orders is also a real queue filter rather than an unfiltered destination.
- `MaintenanceWorkOrderBadgeParityTest`: **1 passed / 16 assertions**, with 4 open and 2 unassigned rows; view-only gets only the open count, assign-only gets no badge. Sidebar behavior and work-order list tests: **39 passed**. Targeted ESLint, PHP syntax, and `git diff --check` pass. Pint and browser visual QA were not run.

## Separate finding fix: F-31 overtime list/options permission parity

- The SPA overtime list requires `attendance.ot.approve`, and its create page requires `attendance.ot.create`; the list and options APIs previously required only `attendance.view`. An approver-only permission override reproduced a 403 before the route change.
- The list API now accepts `attendance.view` or `attendance.ot.approve`. Options accept `attendance.view`, `attendance.ot.approve`, or `attendance.ot.create`. Create-only and no-permission users still cannot list overtime requests.
- `OvertimeListRoutePermissionTest`: **1 passed / 8 assertions**, covering approver-only list access, approver/creator options access, and list/options denial without the corresponding permission. SPA behavior is unchanged.

## Separate finding fix: F-09 overtime approval badge/list parity

- `OvertimeDecisionPolicy::awaitingMyApprovalQuery()` powers both the `overtime` badge and `/attendance/overtime-requests?signal=awaiting_my_approval`. It requires pending status, applies the current list visibility scope, requires direct or active delegated approval authority, and excludes the current user's own employee request.
- Direct approvers and active delegates with the required list-read permission receive the badge and can open the queue. `Sidebar` and the SPA route use existing `delegated_approvals`; view-only users cannot load the actionable signal.
- API `OvertimeApprovalBadgeParityTest`: **3 passed / 15 assertions** for department scope, own-request exclusion, active delegation, status filtering, and view-only denial. SPA Sidebar and overtime page tests: **66 passed**. Targeted ESLint, PHP syntax, and `git diff --check` pass. Pint reports two style issues in touched legacy files; no broad formatting was applied. Draft PR [#394](https://github.com/kwat0g/kwatog/pull/394) is stacked on #393.
- Existing row visibility remains `OvertimeDecisionPolicy::visibleTo()`. Delegates are tested within their own department, matching current application policy; cross-department delegation behavior remains outside this signal fix.

## Separate finding fix: F-09 leave approval badge/list parity

- `LeaveApprovalPolicy::awaitingMyApprovalQuery()` powers the `leaves` badge and `/leaves/requests?signal=awaiting_my_approval`. It requires `leave.view`, the stage's current pending `ApprovalRecord` (`department_head` step 1 or `hr_officer` step 2), matching stage permission and workflow role/delegation, the existing `DepartmentScope`, and excludes the employee's own request.
- Signal mode ignores conflicting status and trashed filters; the regular list remains unchanged. The sidebar and SPA route require API read access plus a page responsibility; the sidebar opens `/hr/leaves?signal=awaiting_my_approval`, and the page hides the Status selector while the queue is active.
- `LeaveApprovalBadgeParityTest`: **5 passed / 18 assertions**. With `LeaveRequestVisibilityTest`: **14 passed / 44 assertions**. Coverage includes department and HR stages, own/cross-department exclusions, terminal/stale/deleted rows, wrong-role permission holders, missing read permission, active same-department delegation, and badge/list parity. Sidebar and leave-page SPA tests: **68 passed**. Targeted ESLint, PHP syntax, and Pint on new/changed leave files pass; `BadgeService.php` retains a pre-existing Pint style warning. Draft PR [#395](https://github.com/kwat0g/kwatog/pull/395) is stacked on #394.
- A separate leave-approval permission/workflow-role mismatch is recorded as F-32; no permission behavior changed here.

## Separate finding fix: F-32 Leave action visibility (draft PR #396)

- Leave list and detail resources expose `can_approve` from `LeaveApprovalPolicy`, which applies the current pending step, stage permission, direct or active delegated workflow role, department scope, submitter exclusion, and the API's separation-of-duties rule. List actionability uses one batch query per page; detail uses the same policy.
- Leave row, detail, and bulk controls follow `can_approve`. Rejection now also requires the permission for the request's active stage, matching the approved policy. Legacy/self-service resource rows default the field to `false`; approval and notification event timing is unchanged.
- API `LeaveApprovalBadgeParityTest`, `LeaveRequestVisibilityTest`, and `LeaveRequestHardeningTest`: **34 passed / 160 assertions**, including direct/delegated positives, wrong-role/system-admin negatives, stage-specific reject permission, prior-step separation-of-duties, and existing scope checks. SPA leave list/detail and approval-history tests: **7 passed**. Targeted ESLint, PHP syntax, focused Pint on policy/service/test, and `git diff --check` pass. Controller/resource files retain pre-existing Pint style exceptions; no broad formatting applied.
- No browser click-through or end-to-end session run. Draft PR #396 is stacked on #395.

## Separate finding: F-33 HR password-reset notification load failure

- `EmployeePasswordResetNotification` fails PHP method-signature compatibility when autoloaded because its parent references unimported `Throwable`, resolving to the Auth notification namespace. The issue surfaced while checking which direct Laravel notification paths remain mail-only; no reset email was queued.
- The parent signature fix from draft PR [#372](https://github.com/kwat0g/kwatog/pull/372) is carried into the active stack in draft PR #404. `NotificationCatalogTest` now instantiates the HR subclass and confirms its mail-only channel.
- On this stack, `EmployeePasswordResetNotificationTest.php`: **1 passed / 2 assertions**; `NotificationCatalogTest.php`: **9 passed / 76 assertions**; `UserProvisioningTest.php`: **8 passed / 25 assertions**; `UserAdministrationHardeningTest.php`: **8 passed / 27 assertions**. PHP syntax and `git diff --check` pass. PR #404 API CI fails before PHPUnit on inherited F-22; finding-lifecycle passes. No reset email was queued during discovery.

## Central dispatch boundary (draft PR #397)

- Repository search found no callers of `NotificationService::notify()`; its comment naming Ncr/Maintenance as legacy callers was stale. Those services already use `send()`, so the unused Laravel-native wrapper is removed.
- `NotificationCatalogTest` pins direct Laravel dispatch files to the existing mail-only authentication, production-summary, and critical-alert flows and fails if the legacy wrapper returns. The HR reset subclass was unverified at this PR boundary; PR #404 adds it to mail-only channel coverage after integrating F-33. Direct `Mail::to(...)` paths and event timing are unchanged.
- Older `audit/domains/platform/notifications/*` snapshots still claim NcrService calls the wrapper; current code search shows those references are stale historical findings, not live call sites.
- `NotificationCatalogTest`: **9 passed / 74 assertions**. Focused Pint passes for `NotificationService`; the existing `NotificationCatalogTest` file retains baseline style debt. PHP syntax and `git diff --check` pass.
- F-33 was discovered while checking an HR mail exception and logged separately. No email sender migration or browser verification is included.

## Centralize remaining Laravel notification dispatches (draft PR #402)

- Migrates the seven inventoried direct Laravel Notification sends into `NotificationService::sendLaravelNotification()`. Original recipients, notification classes, channels, and dispatch timing stay unchanged.
- `NotificationCatalogTest` now requires no direct Laravel Notification dispatch outside `NotificationService`, while retaining the no-legacy-wrapper assertion and mail-only channel checks. `NotificationServiceTest` verifies duplicate explicit recipients produce one send and an unrelated user receives none.
- This is a stacked integration of the existing PR #370 unit with the #397 dispatch guard. The old #370 branch's test expected direct send sites; its expectation is reconciled here. F-33 is a separate failure at this PR boundary; PR #404 later carries its fix into the active stack.
- Targeted API results on the integrated stack: `NotificationServiceTest`: **9 passed / 28 assertions**; `NotificationCatalogTest`: **9 passed / 74 assertions**; `PasswordResetTest.php`: **13 passed / 40 assertions**; `EmployeeWelcomeNotificationTest.php`: **3 passed / 11 assertions**; focused admin welcome: **1 passed / 4 assertions**. Direct PHPUnit was used because `php artisan test --filter=...` hits the existing `RecommendationAudienceScopeTest` fake-detector signature fatal before filtering.
- PHP syntax and `git diff --check` pass. Targeted Pint reports four style issues in touched legacy files (`AlertEngineService`, daily production summary, `UserProvisioningService`, and `NotificationCatalogTest`); broad formatting was avoided. At this PR boundary, full provisioning/reset verification remained blocked by F-33; PR #404 later verifies those flows.
- PR #402 API CI fails before PHPUnit on inherited F-22 (`PdfRenderService::ensureDompdfDirs()` redeclaration); finding-lifecycle passes. This unit centralizes delivery only; policy recipient resolution, subject authorization, preferences for Laravel Notification classes, and lifecycle handling remain open.

## Exclude inactive internal notice recipients (draft PR #403)

- `NotificationService::normaliseRecipients()` now excludes inactive and soft-deleted internal `User` records. Both `send()` and `sendLaravelNotification()` share this check; portal-recipient delivery is unchanged.
- Adapted the regression test to the current service API after #397 removed the unused `notify()` wrapper. It proves inactive/deleted users receive no in-app row, realtime event, or Laravel notification, while an active user still receives the in-app notice.
- `NotificationServiceTest.php`: **10 passed / 36 assertions**, including the inactive-recipient regression. PHP syntax, Pint on both changed PHP files, and `git diff --check` pass. PR #403 API CI fails before PHPUnit on inherited F-22; finding-lifecycle passes. This recipient filter does not replace subject-access authorization or the Phase 2 rule resolver.

## Separate finding fix: F-36 clearance detail controller resolution (draft PR #407)

- Clearance detail requests returned 500 while building `SeparationController`: `FinalPayService` referenced `EmploymentProrationService` in HR, but the existing class lives in Payroll.
- Added the missing Payroll import only. `ClearanceReadScopeTest.php`: **4 passed / 61 assertions**, including exact detail-route resolution and department-scope outcomes.
- PR #407's API CI stops before PHPUnit on inherited F-22: duplicate `PdfRenderService::ensureDompdfDirs()` declaration. PHP syntax and targeted tests pass locally.
- This fix is isolated from notification changes. No event timing, recipient rule, or final-pay calculation changed.

## Audit empty notification audiences (draft PR #406)

- Structured, Laravel, and DSS notification entry points now log a warning with only event type or notification class when normalization leaves no eligible recipients. They do not fall back to administrators or a broad role, matching the approved Phase 2 rule.
- `NotificationServiceTest.php`: **12 passed / 44 assertions**. The empty-audience case checks both log contexts and confirms no row, realtime event, or Laravel send; an inactive DSS recipient is also logged and suppressed.
- API CI inherits F-22 and fails before PHPUnit; finding-lifecycle passes. Preference opt-outs and intentional DSS rate-cap suppression are not logged as empty audiences.

## Separate finding fix: F-35 separation notice access scope (draft PR #408)

- The configured separation roles remain unchanged. Recipients now also need `hr.separation.view`, the same clearance row scope used by `SeparationService::show()`, and must not be the recorded initiator.
- `SeparationNotificationAudienceTest.php`: **2 passed / 9 assertions**. Same-department delivery and detail access pass; cross-department access returns 404; initiator gets no notice; configured Finance remains eligible through `hr.separation.view_all`.
- PR #408 API CI fails before PHPUnit on inherited F-22 (`Cannot redeclare ... ensureDompdfDirs()`); finding-lifecycle passes. The focused audience tests pass locally.
- This narrows delivery only; it does not add the affected employee or new roles to the configured audience. HR/Finance audience policy remains bounded by the approved catalog and current setting.

## Separate finding fix: F-12 chain broadcast payload (draft PR #398)

- `ChainStepAdvanced` now broadcasts only `{refresh: true}` under the new `.chain.refresh` event name. The SPA only invalidates its current record and chain queries; it no longer needs status, document number, completed steps, or actor name from the channel.
- The new event name lets an already-loaded older SPA bundle ignore the reduced payload and keep its existing 30-second polling fallback. The new SPA refreshes on `.chain.refresh` and retains polling when Reverb is unavailable.
- `ChainBroadcasterTest.php`: **4 passed / 6 assertions**, including the new event name and exact payload. `useChainProgress.test.tsx`: **2 passed**. PHP syntax and `git diff --check` passed. `Pint --test` reports style issues in these two existing files; broad formatting was avoided.
- Channel authorization remains permission-based and is not made record-specific in this unit. Payroll progress payload and audience are unchanged pending HR/Finance policy; F-12 remains open.
- PR #398 API CI stops at the inherited F-22 duplicate-method syntax error; SPA CI stops at the existing `@vitest/mocker`, `undici`, and `zod` audit advisories. Accessibility, visual baseline, and finding-lifecycle checks pass.

## Separate finding fix: F-12 chain channel route authorization (draft PR #399)

- All eight `ChainBroadcaster` entity types now require the matching module to be enabled, the permissions used by their detail routes, and a live, non-deleted record. Work Orders require both `production.view` and `production.work_orders.view`; deliveries accept either detail-route read permission. Purchase Request and Purchase Order channels also apply their record visibility policies. Unknown configured types, malformed/missing IDs, and disabled modules are denied.
- `BroadcastAuthorizationTest.php`: **11 passed / 32 assertions**. Coverage includes each supported chain type, positive and negative permissions, Purchase Request and Purchase Order row scope, approval-only Purchase Order access, the narrow delivery permission, Work Order parent permission, module disablement, missing records, and private user-channel controls. Pint and `git diff --check` pass.
- PR #399 API CI still fails at the unrelated F-22 duplicate `PdfRenderService::ensureDompdfDirs()` syntax check. PR #400 aligns Payroll period subscription access with its detail route; progress payload and intended HR/Finance audience remain open for policy review. F-12 is only partially resolved.

## Separate finding fix: F-12 Payroll progress channel route authorization (draft PR #400)

- `payroll.period.{hashId}` now requires the Payroll feature flag, `payroll.periods.view`, a decodable period hash, and an existing period. This matches `GET /payroll-periods/{period}`. No role audience or progress payload fields changed; the headcount/metadata policy remains open for HR/Finance review.
- `BroadcastAuthorizationTest.php`: **12 passed / 36 assertions**, covering supported channels, correct and denied read permissions, disabled module, and missing record. PHP syntax, targeted Pint, and `git diff --check` pass.
- PR #400 API CI inherits the F-22 duplicate `PdfRenderService::ensureDompdfDirs()` blocker. No unauthorized payroll subscription was reproduced.

## Separate finding fix: F-12 operational channel route authorization (draft PR #401)

- Production dashboard subscriptions now require `modules.production`, `production.view`, and `production.dashboard.view`. Work Order subscriptions require both detail-route permissions plus a live Work Order; machine subscriptions require `modules.mrp`, `mrp.machines.view`, and a live machine. The maintenance dashboard requires `modules.maintenance` and `maintenance.view`.
- Source event review confirms these channels carry only `{refresh: true}`; no event timing or payload changed. `BroadcastAuthorizationTest.php`: **16 passed / 48 assertions**, covering route permission mismatches, module disablement, missing records, and the inherited chain/payroll cases. PHP syntax, targeted Pint, and `git diff --check` pass.
- PR #401 API CI inherits F-22. Existing dashboard private channels remain area-wide by design and carry no record content.

## Separate finding fix: F-09 budget approval badge/list parity (draft PR [#374](https://github.com/kwat0g/kwatog/pull/374))

- `Budget::scopeAwaitingApprovalFor()` is shared by `pending_budgets` and `/budgets?signal=awaiting_my_approval`. It counts only submitted budgets a direct approver or active delegate can act on under the existing maker-checker role rule, and excludes the submitter acting on their own budget. The badge remains `budgeting.view`-gated per policy; approval-only users now get a separate restricted queue in PR #377.
- Targeted rerun: `BadgeControllerTest.php` approval/delegation parity tests **2 passed / 25 assertions**; `Sidebar.behavior.test.tsx` plus `budgeting/index.test.tsx`: **31 passed**. Targeted ESLint and `git diff --check` passed.
- CI API fails before PHPUnit on F-22, duplicate `PdfRenderService::ensureDompdfDirs()`; SPA fails at dependency audit on advisories for `@vitest/mocker`, `undici`, and `zod`; accessibility, visual baseline, and finding-lifecycle checks pass. Browser visual QA and full-suite verification remain outstanding. PR is based on #369.

## Separate finding fix: F-27 restricted budget approval queue (draft PR [#377](https://github.com/kwat0g/kwatog/pull/377))

- Users with `budgeting.approve` but no `budgeting.view` can open `/budgeting` and the budget list API only returns `Budget::scopeAwaitingApprovalFor()` rows. The `pending_budgets` badge stays view-only, matching the approved signal audience; overview, fiscal-year, and options endpoints remain view-only.
- SPA route and sidebar permit view or approval access. The approval-only page skips overview/fiscal-year/options requests and cannot switch to the broad list. Approval-board budget and transfer cards now use the shared per-record scopes and appear to direct approvers without broad view permission.
- API: `BadgeControllerTest.php` plus `ApprovalBoardTest.php`: **23 passed / 230 assertions**, including approval-only transfer cards. SPA: Sidebar permission and budget page tests **24 passed**; targeted ESLint and `git diff --check` passed.
- Full SPA typecheck still fails on unrelated export helpers, missing `lucide-react`, stock-transfer API exports, and duplicate purchase-order `type` props; no changed file is named. API full-suite, browser, and CI verification remain outstanding.

## F-05/F-09 HR employee training signal parity (draft PR [#409](https://github.com/kwat0g/kwatog/pull/409))

- The old badges checked all employee-training rows and were permissioned like the training-program catalog, while their suggested `/hr/trainings` target was a catalog page with no signal filter. Added a separate `hr.employees.trainings.view` list route, scoped with `EmployeeCompetenceScope`, and a SPA page whose expired/upcoming filters use the same model scopes as the badges.
- Counts exclude inactive employees and superseded certificates. The code's training-expiry service confirms only the latest completion is current; same-day ties resolve by larger record ID. Manila date windows remain inclusive at today through +14 days for scheduled sessions and +30 days for current completed certificates. Employee changes now invalidate badge cache because status/department changes affect those counts.
- Department-head and HR-officer API parity: **1 test / 26 assertions**. It checks both badge totals against list totals, cross-department and inactive exclusions, inclusive/exclusive date edges, cancelled rows, superseded current/expired certificates, cache invalidation after an employee becomes inactive, and denial/hidden counts for a customer-service user. Sidebar plus page tests: **42 passed / 2 files**. Targeted ESLint passes.
- Full SPA typecheck still fails on existing unrelated export helpers, missing `lucide-react`, stock-transfer API exports, one stale pagination fixture, and duplicate purchase-order `type` props; no changed file is reported. Targeted Pint check reports existing style issues in `BadgeService.php`, `EmployeeTrainingController.php`, `EmployeeTraining.php`, and `HR/routes.php`; new test file passes Pint. API CI/full-suite and browser visual QA remain outstanding.
- `open_postings` stays unattached because it is informational and the app has no user control to hide non-action badges. This PR changes only the training badge audience/filter scope and list destination.

## F-09/F-13 internal AR invoice signal parity (draft PR [#410](https://github.com/kwat0g/kwatog/pull/410))

- `Invoice::scopeUnpaid()`, `scopeOverdue()`, and `scopeAwaitingPaymentReview()` now back both `/invoices?signal=...` and the corresponding badge counters. Unpaid means finalized/partial with stored balance > 0; overdue adds due date before `today('Asia/Manila')`; payment review adds a pending customer submission. Draft badge/list shows only invoices created by the current user. Prebill approval stays in the existing Approvals board.
- Payment review filter and badge require invoice view plus collect permission and exclude `system_admin`, matching the current notification audience. Internal invoice list is company-wide for users with view permission; the code has no customer-row scope.
- Sidebar shows overdue, unpaid, payment review, and personal draft chips. Payment-submission create/reject updates invalidate the badge cache through `BadgeInvalidationObserver`. The existing unpaid StatCard now links to the supported unpaid filter.
- `AccountsReceivableSignalParityTest`: **1 passed / 27 assertions**. Compares all four list totals to badges for Finance, checks due-today/overdue and paid/cancelled/zero-balance exclusions, denies payment-review list to VP and `system_admin`, and verifies pending-to-rejected badge invalidation. `Sidebar.behavior.test.tsx`: **42 passed**. Targeted ESLint and PHP syntax pass.
- SPA typecheck still reports unrelated export helper, missing `lucide-react`, stock-transfer export, leaves fixture, and purchase-order duplicate-property errors; no changed AR file is named. Pint reports existing style issues in `InvoiceController.php`, `Invoice.php`, `InvoiceService.php`, and `BadgeService.php`; the new test file passes Pint. No CI checks are reported for #410 yet. API full-suite, browser visual QA, and full role-matrix parity remain open.
- F-37 separately records the `Invoice::isOverdue()` due-today display mismatch.

## F-09/F-13 customer portal AR invoice signal parity (draft PR [#411](https://github.com/kwat0g/kwatog/pull/411))

- Customer portal invoice filters and counts reuse `Invoice::scopeUnpaid()` and `scopeOverdue()`. Both badge subqueries are bound to the signed-in `CustomerPortalUser::customer_id`; one account cannot observe another account's counts. The dashboard open-invoice count and outstanding balance now use the same positive-balance unpaid scope as the linked filtered list.
- The customer sidebar shows overdue as the primary destination and unpaid as a separate filter link. Both use the existing Phase 2 signal IDs and customer invoice route; the badge endpoint sits behind the customer portal session and password-change middleware.
- `CustomerPortalInvoiceSignalParityTest`: **1 passed / 14 assertions**; verifies unpaid/overdue list totals against each account's counts, cross-account isolation, cancelled/paid/zero-balance exclusions, dashboard count and amount parity, and invalid-filter rejection. `PortalLayout.test.tsx`: **1 passed**; targeted ESLint and PHP syntax pass. The API test used a temporary local removal of the inherited F-22 duplicate method and restored that file exactly afterward.
- Full SPA `npm run typecheck` still fails in existing export helpers, missing `lucide-react`, stock-transfer exports, a Leave pagination fixture, and duplicate purchase-order props; no changed file is named. Targeted Pint reports existing style differences in `CustomerPortalController.php` and `CustomerPortalService.php`; the added API test passes Pint. Browser visual QA, portal-role end-to-end coverage, full suite, and CI remain outstanding. No notification dispatch changed.

## Phase 3 approval-backed subject access gate (draft PR [#412](https://github.com/kwat0g/kwatog/pull/412))

- `NotificationService::sendForSubject()` now checks that the subject still exists and is not soft-deleted, denies unregistered subject types, then filters active recipients through `ApprovalTypeRegistry::userCanViewRecord()` before database, realtime, or email delivery. It stores the resolved model class and primary key in `subject_type`/`subject_id`. Rejected user IDs and unsupported/missing subjects are logged without notification content.
- `ApprovalService` uses this central gate for action-required and submitter outcome notices. Its separate submitter visibility check is removed; the existing current-step resolver remains as the recipient rule, with the new service check as defense in depth. Other `NotificationService::send()` call sites remain ungated pending exact per-module access rules and migration.
- `NotificationServiceSubjectAccessTest`: **3 passed / 15 assertions**; proves allowed requester, same-permission but out-of-scope recipient, missing permission, structured subject fields, unknown subject denial, and soft-deleted subject denial. `ApprovalNoticeVisibilityTest`: **3 passed / 20 assertions**; `ApprovalSubmitterOutcomeVisibilityTest`: **1 passed / 5 assertions**; `ApprovalServiceTest`: **18 passed / 44 assertions**. PHP syntax, Pint for changed code/tests, and `git diff --check` pass.
- Tests used a temporary local removal of inherited F-22 duplicate method and restored it exactly. Full suite and CI remain blocked by F-22; global subject-type coverage, other notification senders, and performance under large approval audiences remain open.

## Separate finding fix: F-38 inactive-recipient test correction (draft PR [#413](https://github.com/kwat0g/kwatog/pull/413))

- Corrected the stale in-app row expectation from four to three. Inactive users are excluded by `NotificationService::normaliseRecipients()` from database, broadcast, and email delivery.
- `NotificationServiceHardeningTest.php`: **22 passed / 62 assertions**. PHP syntax, targeted Pint, and `git diff --check` pass. Test used a temporary local workaround for inherited F-22 and restored that file exactly.
- Production behavior is unchanged. The fix is isolated from notification feature work. CI API checks fail before PHPUnit at the inherited F-22 duplicate `PdfRenderService::ensureDompdfDirs()` method; finding-lifecycle passes.

## Phase 3 Accounting invoice subject access gate (draft PR [#414](https://github.com/kwat0g/kwatog/pull/414))

- Registered Invoice subjects with the detail-route access rule: `modules.accounting` must be enabled and the user must have `accounting.invoices.view`. Invoice list/detail have no row scope for internal users. Unknown subject types continue to fail closed.
- Routed internal invoice notices through `sendForSubject()`: prebill approval/outcome, payment-submission review, AR dunning escalation, draft-invoice-ready after delivery, and invoice/official-receipt/dunning email-failure fallbacks. Email-failure notices remain in-app only. Official-receipt fallbacks are skipped and logged if the linked invoice was deleted or is unavailable, because their target route is the invoice detail page.
- Customer-portal invoice notices remain on the separate portal path; current recipients are selected by the invoice's exact customer ID. Portal subject authorization still needs its own rule and parity review.
- `NotificationServiceSubjectAccessTest`: **6 passed / 27 assertions**, including positive/negative invoice permission, disabled Accounting module, and subject-gated failure paths. `InvoicePrebillApprovalTest.php`, `InvoicePaymentSubmissionServiceTest.php`, and `RunArDunningCommandTest.php`: **40 passed / 308 assertions**. The separate F-38 test passes **22 / 62**. PHP syntax, selected Pint checks, and `git diff --check` pass.
- Test runs used a temporary local workaround for inherited F-22 duplicate `PdfRenderService::ensureDompdfDirs()` and restored the source exactly. PR #414 CI API check stops at the same F-22 syntax error; finding-lifecycle passes. Broader Pint reports existing style debt in Accounting listener/mail/service files; no broad formatting applied. Full suite, browser/e2e, and role matrix remain outstanding.

## Phase 3 Accounting budget and transfer subject access gate (draft PR [#415](https://github.com/kwat0g/kwatog/pull/415))

- Registered Budget and BudgetTransfer with the actual detail-route rules. Both Accounting and Budgeting feature flags must be enabled. View permission opens any record; approval-only access requires the current submitted/pending state, a valid direct or delegated approver, and the existing maker-checker role rule.
- Routed budget submission/decision and transfer request/decision notices through `sendForSubject()`. Current role/delegation recipient selection and messages remain unchanged; ineligible users are suppressed by the shared access gate.
- `NotificationServiceSubjectAccessTest`: **7 passed / 35 assertions**, including allowed budget viewer/distinct approver, denied submitter/same-role peer, and disabled Budgeting module. `BudgetConsumptionAndLifecycleTest.php` plus `BudgetTransferTest.php`: **21 passed / 118 assertions**. PHP syntax, Pint, and `git diff --check` pass. Test runs used the temporary F-22 workaround and restored the source exactly.
- CI, browser/e2e, and full role matrix remain outstanding. No badge logic changed in this unit.

## Separate finding fix: F-39 bill payment self-notice (draft PR [#416](https://github.com/kwat0g/kwatog/pull/416))

- `BillService::postApprovedPayment()` now excludes the posting actor from the payment-created notification while retaining a notice for a different active creator with bill-view access.
- `BillServiceTest.php` self-post and separate-approver scenarios: **2 passed / 23 assertions**; requester still receives the notice when another user posts, approver does not. PHP syntax and `git diff --check` pass. Pint reports test-file style issues (`method_argument_space`, `fully_qualified_strict_types`); no broad formatting applied. The test run used and restored the F-22 workaround.
- This is isolated from subject-access feature work.


## Phase 3 Accounting bill subject access gate (draft PR [#417](https://github.com/kwat0g/kwatog/pull/417))

- Registered Bill subjects with the global bill detail route rule: Accounting must be enabled and the recipient must have `accounting.bills.view`. Bill payment-posted notices now pass through `sendForSubject()`; the existing requester permission check and actor exclusion remain.
- Supplier invoice email failure notices now target only the active Bill creator. Both immediate enqueue/invalid-address failures and queued delivery failures carry the Bill subject and pass the same access gate. The old broad `accounting.vendors.view` fanout was removed. No active creator means log and skip; no broad fallback is configured. `Bill.created_by` is the available owner field; no separate AP assignment rule was found in these paths.
- `NotificationServiceSubjectAccessTest`: **8 passed / 40 assertions**. Supplier invoice email failure target test: **1 passed / 8 assertions**, including the queued-mailable failure path. Bill payment self-post and distinct-poster cases: **2 passed / 23 assertions**. These targeted tests used the temporary F-22 workaround and restored `PdfRenderService.php` exactly.
- PHP syntax checks pass for all six changed PHP files. Pint passes five files; its check reports `class_attributes_separation` in `BillService.php`, outside this change. `git diff --check` passes. API CI remains blocked before PHPUnit by inherited F-22. Draft PR #417 is based on the separate F-39 finding PR #416. API CI fails before PHPUnit on inherited F-22 (`PdfRenderService::ensureDompdfDirs()` duplicate); finding-lifecycle passes.


## Phase 3 Inventory stock-adjustment subject access gate (draft PR [#418](https://github.com/kwat0g/kwatog/pull/418))

- Registered `StockAdjustment` with its list-route access rule: Inventory must be enabled and the recipient must hold `inventory.adjust` or `inventory.adjust.approve`. The list route has no row scope; this matches its `permission_any` middleware. Delegated approvers without either list permission fail closed.
- High-value adjustment approval and requester decision notices now use `sendForSubject()`. Existing direct/current approver selection, requester selection, event timing, and list links remain unchanged.
- `NotificationServiceSubjectAccessTest` stock-adjustment case: **1 passed / 8 assertions**; proves maker and checker access, denies a user without list permission, and denies all delivery while Inventory is disabled. `PendingStockAdjustmentResolutionTest` approval/rejection case: **1 passed / 30 assertions**; verifies subject metadata for both notices. Its revoked/approver-only audience case: **1 passed / 5 assertions**.
- PHP syntax passes for all four changed PHP files. Pint passes the registry and new unit test; it flags `unary_operator_spaces` in `StockAdjustmentService.php` and `ordered_imports` in the existing feature test, so no broad formatting was applied. `git diff --check` passes. Test runs used the temporary F-22 workaround and restored `PdfRenderService.php` exactly. Draft PR #418 is based on Bill subject gate PR #417. API CI fails before PHPUnit on inherited F-22 (`PdfRenderService::ensureDompdfDirs()` duplicate); finding-lifecycle passes.


## Phase 3 Purchasing supplier-listing subject access gate (draft PR [#419](https://github.com/kwat0g/kwatog/pull/419))

- Registered `SupplierItemListing` with its list-route access rule: Purchasing must be enabled and the recipient must hold `purchasing.supplier_listings.view`. The review route allows `purchasing.supplier_listings.review`, but the list route requires the view permission; a review-only recipient is denied by the notification gate.
- `supplier_listing_submitted` now uses `sendForSubject()`. Existing `ApprovalDelegation::approvalAudience('purchasing.supplier_listings.review')`, list-view filter, message, list link, and event timing stay unchanged. The internal event remains separate from supplier-portal notices.
- `NotificationServiceSubjectAccessTest.php`, `SupplierItemListingTest.php`, and `SupplierListingBulkTest.php`: **29 passed / 150 assertions**. Covers subject metadata, module disablement, rejection of review-only access, and the current reviewer audience. Two test mocks now use partial mocks so `ApprovalDelegation::approvalAudience()` can call the real `usersWithPermission()` helper; previously the mocks swallowed that lookup and the service caught the resulting exception.
- PHP syntax, Pint on all five changed files, and `git diff --check` pass. Test run used the temporary F-22 workaround and restored `PdfRenderService.php` exactly. Draft PR #419 is based on stock-adjustment subject gate PR #418. API CI fails before PHPUnit on inherited F-22 (`PdfRenderService::ensureDompdfDirs()` duplicate); finding-lifecycle passes.


## Phase 3 HR loan subject access gate (draft PR [#420](https://github.com/kwat0g/kwatog/pull/420))

- Migrated `loans.submitted`, `loans.approved`, and `loans.rejected` through `sendForSubject()`. Internal readers must pass the existing `loans.view` permission and `LoanAccessPolicy` scope. Employee outcome notices use the HR self-service route, which permits only the account linked to the loan's employee; the gate now recognizes that route even without `loans.view`.
- The sender's current configured-role and `LoanAccessPolicy` recipient selection, owner-only outcome target, message, amount, and links remain unchanged. HR and Loans feature flags are checked against the actual destination routes.
- Subject access test: **1 passed / 8 assertions**; it accepts the own employee route and scoped Finance reader, denies another employee, and denies both routes when disabled. `LoanNotificationTest.php`: **6 passed / 16 assertions**, including exact subject metadata for submitted and employee outcome notices.
- PHP syntax and `git diff --check` pass. Pint passes the registry, submitted listener, and new unit test; it flags `unary_operator_spaces` in the existing decision listener and `blank_line_before_statement` in the existing feature test. No broad formatting applied. Tests used the temporary F-22 workaround and restored `PdfRenderService.php` exactly. Draft PR #420 is based on supplier-listing subject gate PR #419; API quality/PHPUnit fails before tests on the inherited F-22 duplicate method, and finding-lifecycle passes.


## Phase 3 Production work-order subject access gate (draft PR [#421](https://github.com/kwat0g/kwatog/pull/421))

- Registered `WorkOrder` with the actual read routes: Production must be enabled, then either the recipient has both `production.view` and `production.work_orders.view`, or the recipient is an active employee with current or historical crew membership. The employee check reuses `WorkOrderAssignmentService::historicalCrewQuery()`, which is the same scope used by the self-service detail route.
- Migrated all 12 discovered WorkOrder-subject dispatches in Production and the QC recovery command to `sendForSubject()`: crew assigned/changed, staffing review, upcoming schedule reminders, rework states, paused/completed work orders, assignment review, and missing outgoing-QC escalation. Existing audiences and event timing stay unchanged; email remains disabled for prior `sendInApp()` paths and enabled where prior `send()` was used.
- Crew-change notices now link to the exact historical work order and crew. Rework notices to assigned workers link to the work order detail instead of the self-service list.
- The Sales Order delivery-risk notice in `AlertEngineService` remains deferred to the Sales Order access-rule unit. Quality notifications whose actual destination is an Inspection or Delivery remain deferred to those subject gates; their legacy `entity_type=work_order` metadata does not match the linked record.
- `NotificationServiceSubjectAccessTest::test_work_order_subject_allows_internal_viewers_and_assigned_history_only`: **1 passed / 7 assertions**; accepts a route-permitted internal reader and assigned former crew member, rejects an unassigned employee, and denies both while Production is disabled. `WorkOrderNotificationTest.php`, `WorkOrderPausedNotificationTest.php`, selected `WorkOrderScheduleReminderTest.php` (four unaffected scenarios), the crew handover test, two staffing-connection tests, `SweepMissingOutgoingQcTest.php`, and the work-order rework close test: **23 passed / 127 assertions**. Total targeted passing coverage: **24 tests / 134 assertions**.
- F-40 is fixed in the separate draft [#422](https://github.com/kwat0g/kwatog/pull/422): staffing observer and start-reminder sweep now share a slot/crew/issue dedupe key. `WorkOrderScheduleReminderTest.php`: **6 passed / 56 assertions**, including canonical key, changed-slot, changed-crew, and changed-issue coverage. `ProductionAttendanceConnectionTest.php`: **9 passed / 35 assertions**. The original duplicate was reproduced on parent #420. An unchanged issue that clears and returns within one slot may remain deduped until notification lifecycle resolution ships.
- PHP syntax and `git diff --check` pass. Pint passes all changed files except existing `fully_qualified_strict_types` debt in `AlertEngineService.php`; no broad formatting applied. Targeted tests used and restored the temporary F-22 workaround. API CI remains blocked before PHPUnit by F-22; browser/e2e and full role matrix remain open.

## Separate finding fix: F-40 staffing notice dedupe (draft PR [#422](https://github.com/kwat0g/kwatog/pull/422))

- `WorkOrderAssignmentService::staffingNoticeDedupeKey()` now builds a stable key from slot, crew, and a canonicalized issue set. The staffing observer and upcoming-work-order sweep share it, so one unresolved issue set does not create duplicate review notices.
- `WorkOrderScheduleReminderTest.php`: **6 passed / 56 assertions**, including key order, slot, crew, and issue distinctions. `ProductionAttendanceConnectionTest.php`: **9 passed / 35 assertions**. PHP syntax, targeted Pint, and `git diff --check` pass; the temporary F-22 workaround was restored.
- Limit: if an issue clears and returns unchanged within the same slot and crew, the persistent dedupe key may suppress the later cycle until notification lifecycle resolution is implemented.

## Phase 3 Sales Order subject access gate (draft PR [#423](https://github.com/kwat0g/kwatog/pull/423))

- Registered `SalesOrder` against its actual routes: the CRM feature must be enabled and the recipient must hold `crm.sales_orders.view`. The internal list/detail has no additional row scope; this matches the route group and `channels.php` rule.
- Migrated the Sales Order delivery-risk, confirmation, portal-review, and customer-response notices through `sendForSubject()`. Recipient selection, event timing, messages, links, and prior email preference stay unchanged. Customer-portal notices remain on the exact-customer portal path.
- Sales Order email-failure fallbacks now carry the SalesOrder model. `NotificationService` passes the subject into queued `UserNotificationMail` failure handling, which rechecks access before writing an in-app fallback if CRM access changed after queueing.
- `NotificationServiceSubjectAccessTest.php`: **14 passed**, including denied/no-permission, CRM-disabled, and lost-access email fallback cases. `OutboxFactEventToleranceTest.php`: **12 passed**. `SalesOrderResponseTest.php`: **19 passed**. The delivery-risk recipient test passed (**1 / 3 assertions**), as did the internal portal-order notice test (**1 / 5 assertions**).
- The six-class focused batch had **81 passes / 5 unrelated failures**. Four existing email assertions expect `PHP` while `CurrencyDisplayService` renders PHP as `₱`. One portal-catalog assertion expects no rows while the service returns a visible product with `pricing_issue`; its source and behavior are unchanged here. The specifically changed notification tests pass on rerun.
- PHP syntax passes for all changed PHP files. Pint passes the registry, central service, Sales Order listener, and both changed subject-access tests. Broader Pint reports existing style debt in other touched files; no broad formatting applied. `git diff --check` passes. Test runs used and restored the temporary F-22 workaround. Full API CI, browser/e2e, and complete role matrix remain open.

## Phase 3 Purchase Order subject access gate (draft PR [#424](https://github.com/kwat0g/kwatog/pull/424))

- Route review: Purchasing must be enabled; PO detail requires `purchasing.view` or `purchasing.po.approve`, then `PurchaseOrderController::show()` applies `PurchaseOrderAccessPolicy::canView()`. That policy supplies the same owner, department, and current-approver row scope used by the PO list. The generic approval registry already knew the PO scope but its subject gate incorrectly required `purchasing.view` only and ignored the Purchasing feature flag, under-notifying approval-only route users.
- Added the exact detail-route rule to `NotificationSubjectAccessRegistry` and routed seven internal PO notices through `sendForSubject()`: submitted/approved, auto-PO approval, supplier response, manual dispatch, late supplier ETA, and acknowledged schedule cancellation. Existing recipient selectors, messages, event timing, and links remain. The two prior `sendInApp()` paths remain email-disabled; late-ETA dedupe and recipient reason stay intact.
- Five PO email-failure paths now pass the PO to the same gate: initial supplier dispatch failure; missing supplier email and queue failure on cancellation; queued cancellation mail failure; queued approved-PO mail failure. Cancellation mail without a subject now fails closed. Supplier-portal notices remain on their existing exact-vendor portal path. Purchase-request auto-conversion and vendor deterioration notices retain their own subjects.
- An acknowledged supplier schedule with no linked PO now produces no internal notice; it cannot provide a valid PO link. The schema also stores customer schedules without a PO, but this sender is reached only for a supplier cancellation.
- `NotificationServiceSubjectAccessTest.php`: **15 passed / 95 assertions**, including PO owner, out-of-scope reader, approval-only detail access without `purchasing.view`, missing permission, disabled module, and negative delivery. PO approval audience tests: **3 passed / 6 assertions**; auto-PO: **1 / 13**; supplier response: **1 / 5**; late-ETA: **2 / 13**; manual dispatch and immediate email-failure: **2 / 3**; queued supplier mail failure: **1 / 3**; queued cancellation/normal cancellation: **3 / 5**; acknowledged schedule cancellation: **1 / 5**.
- The full cancellation-mail test class had one existing snapshot-render assertion failure: it expects `&lt;img ... &gt;`, while Markdown renders the `>` escape as `&amp;gt;`. Re-running against the parent version of the mailable reproduces the same failure; the HTML contains text, not an active tag. This unrelated email-copy/test mismatch stays outside this unit.
- PHP syntax and `git diff --check` pass; Pint reports existing style debt in legacy touched files. Tests use the temporary F-22 workaround and restore `PdfRenderService.php` exactly. Full API CI, full suite, browser/e2e, and complete role matrix remain open.

## Separate finding fix: F-41 supplier cancellation email text (draft PR [#425](https://github.com/kwat0g/kwatog/pull/425))

- `SupplierPurchaseOrderCancelledMail::safeMarkdownText()` no longer backslash-escapes `>`; Laravel Markdown now renders a supplier contact such as `Original <img ...>` as inert text with both angle brackets encoded consistently.
- This corrects the existing snapshot-render test failure reproduced on parent #423. The focused test now passes **1 / 18 assertions**; the full `SupplierPurchaseOrderCancelledNotificationTest.php` class passes **5 / 22 assertions**. No notification audience, link, event, or email recipient changed.
- This is separate from PO notification access changes in PR #424. PHP syntax, Pint for the mailable, and `git diff --check` pass. The test run used and restored the temporary F-22 workaround.

## Phase 3 Purchase Request subject access gate (draft PR [#426](https://github.com/kwat0g/kwatog/pull/426))

- Route review: the Purchasing API group requires the feature flag, the PR detail route requires `purchasing.view`, and `PurchaseRequestController::show()` applies `PurchaseRequestAccessPolicy::canView()`. The subject registry now enforces all three checks; approval permission alone does not open the detail route.
- Repository search found two PR-subject notification dispatches: `chain.pr_approved` and `chain.pr_auto_convert_skipped`. Both now use `sendForSubject()`. Their configured-role audience, active-user, permission, and row-scope filters remain. Existing message, timing, and exact PR link remain. No PR email-failure fallback dispatch was found; other `purchase_request` matches are catalog/config or chain-bottleneck reads.
- Added positive owner delivery, negative out-of-scope/approval-only/no-permission delivery, disabled-module suppression, and subject metadata checks for both notification events.
- `NotificationServiceSubjectAccessTest.php`: **16 passed / 101 assertions**. Focused approval-audience and skipped-auto-conversion tests: **4 passed / 26 assertions**. PHP syntax, Pint on all six changed files, and `git diff --check` pass. Targeted tests used the temporary F-22 workaround and restored `PdfRenderService.php` exactly.
- PR #426 API CI fails in the pre-test PHP syntax step on inherited F-22: duplicate `PdfRenderService::ensureDompdfDirs()` at line 200. The finding-lifecycle check passes. Full suite, browser/e2e, and complete role matrix remain open.

## Phase 3 Delivery subject access gate (draft PR [#427](https://github.com/kwat0g/kwatog/pull/427))

- Route review: the internal delivery group requires `feature:supply_chain`; list/detail both require `supply_chain.view` or `supply_chain.deliveries.view`, and `DeliveryController::show()` adds no row scope. The subject registry now applies those exact checks. Driver detail uses a separate driver-assignment scope and is not targeted by these internal links. Customer-portal delivery detail checks the authenticated user's customer ID; portal status notices stay on the existing exact-customer path.
- Searched all API delivery links and `entity_type` values. Migrated the internal delivery-draft, automatic-supply blocker, delivery-attempt, missing-arrival, and customer-email failure notifications (missing email, enqueue failure, queued mail failure). Existing recipients, event timing, and dedupe keys remain. The delivery-drafted notice now identifies Delivery as its subject because that is the record its link opens; prior rows remain renderable through their stored `link_to`.
- `NotificationServiceSubjectAccessTest.php`: **17 passed / 114 assertions**. Focused delivery-draft, delivery-attempt, and email-failure tests: **4 passed / 20 assertions**. Automatic-supply reconciliation notice: **1 passed / 12 assertions**. PHP syntax and `git diff --check` pass. Pint reports seven legacy style issues in touched files; no broad formatting applied. Tests used and restored the temporary F-22 workaround.
- The after-commit Delivery Trace notice has no dedicated recipient scenario test yet. PR #427 API CI fails before tests on inherited F-22, duplicate `PdfRenderService::ensureDompdfDirs()` at line 200; finding-lifecycle passes. Full suite, browser/e2e, and complete role matrix remain open.

## Separate finding fix: F-42 shipment source event classification (draft PR [#428](https://github.com/kwat0g/kwatog/pull/428))

- Replaced the MRP planning event key on the invalid shipment-source hold with `delivery.source_review_required`; added the key to the preferences catalog and the SPA alert metadata. MRP run notifications retain `mrp_run_completed`.
- `NotificationCatalogTest::test_every_emitted_notification_type_is_in_the_catalog` and the automatic-supply blocker scenario: **2 passed / 14 assertions**. SPA `notificationMeta.test.ts`: **16 passed**. `git diff --check` passes. The backend run used the temporary F-22 syntax workaround and restored `PdfRenderService.php` exactly. PR #428 API CI fails before tests on F-22; SPA CI stops at dependency audit (2 moderate and 2 high advisories). Accessibility, visual-baseline, and finding-lifecycle checks pass.

## Phase 3 Inventory item and GRN subject access gate (draft PR #429)

- Route review: Item and GRN detail both require the Inventory module and `inventory.view`; neither controller adds a row-specific scope. The subject registry applies those same checks.
- Migrated the low-stock notice and automatic-replenishment blocker to their Item subjects, and the goods-received notice to its GRN subject. Existing recipient selectors, event timing, links, dedupe keys, and channel choices remain; the existing in-app-only blocker explicitly keeps email disabled.
- `NotificationServiceSubjectAccessTest.php`, GRN, low-stock, and automatic-replenishment tests: **34 passed / 158 assertions**, including permission-denial and module-disabled coverage. `git diff --check` passes. Pint reports one existing brace-style issue in `NotifyOnLowStockPrCreated.php`; no broad formatting applied. Tests used the temporary F-22 workaround and restored `PdfRenderService.php` exactly.
- Inventory cost-review notices remain outside this item/GRN slice; their actor self-notice is tracked separately as F-43. PR #429 CI, browser/e2e, and the full role matrix remain open.

## Separate finding fix: F-43 cost-review actor exclusion (draft PR #430)

- Registered `ItemCostReview` under the Inventory feature flag and `inventory.costs.review` list permission. Both cost-review event paths now use `sendForSubject()`; decision outcomes exclude the acting approver and remain visible to other authorized active reviewers.
- `NotificationServiceSubjectAccessTest.php` and `ItemCostReviewTest.php`: **19 passed / 133 assertions**, including actor-negative and other-reviewer-positive outcomes. Pint passes all four touched PHP files; `git diff --check` passes. Tests used and restored the temporary F-22 workaround.
- A broader pricing-review run exposes two existing failures on the unchanged #429 base: one test calls `ProductService::update()` with price fields prohibited by the API request (the supported route uses `updateSellingPrices()`), and one expects all permission holders while the source filters to Sales, Customer Service, and system-admin roles. Recipient-policy/test reconciliation remains open; no unrelated production behavior changed here.
- Exact cost-review click-through remains separate F-44. PR #430 checks and full suite remain open.

## Separate finding fix: F-44 exact cost-review click-through (draft PR #431)

- Cost-review notices now link to `/crm/price-agreements/cost-reviews?review={hash}`. The Inventory API validates and decodes the filter, returns only that row, and the SPA opens its review dialog. Missing or inaccessible rows show an unavailable message without revealing which condition occurred. Legacy rows still open the unfiltered list.
- `ItemCostReviewTest.php`: **1 passed / 18 assertions**, including both event links and exact filtered-list result. SPA `reviews.test.tsx`: **7 passed**, including direct-open and unavailable states. Pint, targeted ESLint, Prettier, and `git diff --check` pass. PHP test used and restored the temporary F-22 workaround.
- Full SPA typecheck, browser QA, and CI remain open.

## Outstanding proof / risks

1. Payroll defaults are approved; exact payroll adjustment checker and HR/Finance sensitive audiences remain open business decisions. Review remaining HR/discipline, executive escalation, system-admin fanout, and portal contacts.
2. Seed one user per local role and department/site scope; run positive and negative recipient scenarios after approval.
3. Compare each badge to the destination list's total under same filter and authorization for every eligible role.
4. Verify channel authorization for unauthenticated, cross-user, and cross-scope cases.
5. Validate route links, legacy renderer, responsive/keyboard UI, and performance after implementation.
6. Discovery had no runtime scenarios. Later targeted payroll, finance, approval, and badge tests are recorded above; browser/e2e, cross-role complete matrices, and full-suite runs remain outstanding.

## Phase 3 Maintenance subject access gates (draft PR [#432](https://github.com/kwat0g/kwatog/pull/432))

- Registered `MaintenanceWorkOrder` against the actual detail-route scope: the Maintenance feature must be enabled, and the recipient must be active with `maintenance.view` or assigned-work read access matching `MaintenanceWorkAccess::canRead()`. Registered `MaintenancePartRequest` against the filtered `/inventory/picking?maintenance_work_order_id=...` destination, which requires the Inventory feature and `inventory.picking.view`.
- Migrated all eight Maintenance dispatches found in the source scan: assignment needed/assigned, repair submitted/rejected, assignment escalation, shift handover, parts requested, and parts handed over. Existing selector, timing, message, link, dedupe, and email behavior remain; the subject gate adds subject metadata and suppresses recipients without access. F-46 separately excludes the creating supervisor from `maintenance.assignment_needed` in PR #433.
- Tests assert a manager and assigned worker can receive a work-order notice while an unassigned worker and warehouse picker cannot; part requests require the linked picker route. Maintenance lifecycle tests assert the linked subject and exact destination.
- Combined target run: **40 passed / 336 assertions, 1 existing test failure** across the subject-access unit test and four Maintenance feature files. A second run excluding that known failure passed **40 tests / 333 assertions**. `MaintenanceVerifiedRecoveryTest::test_submission_validates_evidence_and_assignee_and_pending_freezes_mutations` expects 422 but returns 403 on its repeated submit attempt. The failure reproduces with the old `send()` call temporarily restored, so it is tracked separately as F-45. `PdfRenderService.php` was restored exactly after the temporary F-22 workaround.
- Pint reports three `fully_qualified_strict_types` style issues in pre-existing touched code; no broad formatting applied. `git diff --check` passes. CI, browser/e2e, full role matrix, and full suite remain open.

## Separate finding fix: F-46 Maintenance creator notice (draft PR [#433](https://github.com/kwat0g/kwatog/pull/433))

- `NotifyMaintenanceWorkOrder` now excludes the work-order creator while retaining other active `maintenance_supervisor` users with `maintenance.view`.
- `MaintenanceRoutingNotificationTest.php`: **5 passed / 31 assertions**, including creator-negative and second-supervisor-positive delivery. Tests used and restored the temporary F-22 workaround. `git diff --check` passes.
- API CI remains blocked by inherited F-22; browser/e2e and the role matrix remain open.

## Phase 3 Quality Inspection, NCR, and supplier-return subject access (draft PR [#434](https://github.com/kwat0g/kwatog/pull/434))

- Registered `Inspection` against the `/quality/inspections/{id}` route (`modules.quality` + `quality.inspections.view`) and `NonConformanceReport` against `/quality/ncrs/{id}` (`modules.quality` + `quality.ncr.view`). Both endpoints are permission-wide today; they do not enforce plant, lot, department, or assignment scope.
- Registered supplier `ReturnRequest` access against `/return-management/{id}` (`modules.return_management` + `return_management.view`), including the controller rule that hides supplier returns from a `customer_service_officer` without `purchasing.po.create`.
- Migrated 11 direct Quality and linked-return notice sends to `sendForSubject()`: failed/review inspections; incoming, in-process, and outgoing QC; late incoming and auto-created NCRs; NCR recurrence, escalation, effectiveness, and supplier return. Incoming GRN, QC-passed Delivery, and missing-outgoing-QC Work Order notices already used the gate. Existing recipient selectors, links, timing, dedupe, and mail behavior remain; in-app-only Inspection failure and late-incoming notices still suppress email.
- The auto-created NCR message now uses the Inspection stage label (`In-process`) rather than the raw `in_process` value. Added an assertion for the rendered message.
- Targeted API tests: **14 passed / 86 assertions** across subject access, linked Quality routes, and supplier returns; auto-created NCR copy test: **1 passed / 4 assertions**. PHP syntax and `git diff --check` pass. Tests used and restored the temporary F-22 workaround.
- Pint reports **10** style issues in touched files, outside the new registry and unit-test changes; no broad formatting applied. API CI still stops at F-22. The finish-first final-inspection and Work Order fallback notification branches lack targeted runtime coverage.
- F-47 records the gap between approved plant/lot recipient scope and current permission-wide Quality routes. Subject access now matches what the app lets users open; it does not narrow the underlying Quality page visibility.

## Phase 3 Payroll subject access (draft PR [#435](https://github.com/kwat0g/kwatog/pull/435))

- Registered `PayrollPeriod` against active users with the Payroll module enabled and `payroll.periods.view`; registered `PayrollAdjustment` against `payroll.adjustments.view`; registered employee `Payroll` rows against the owner-only `/self-service/payslips/{hash}` route (`payroll.view` and matching employee ID).
- Migrated all four Payroll notice dispatches: period approval, adjustment approval, adjustment outcome, and payslip readiness. Existing candidate selection, actor exclusions, event timing, copy, links, dedupe, and channels remain unchanged; the added gate suppresses recipients who cannot open the linked subject. Payslip-ready delivery stays limited to the employee who owns that payroll row.
- Added positive and negative subject-access coverage for period, adjustment, and payslip subjects; feature tests assert subject metadata for all four paths.
- Targeted API tests: **7 passed / 38 assertions** across the subject-access unit test, period approval, adjustment action/outcome, payslip readiness, and duplicate payslip notice tests. PHP syntax and `git diff --check` pass. Tests used and restored the temporary F-22 workaround.
- Pint reports **4** pre-existing style violations in touched legacy files; new registry and unit-test code pass. No broad formatting applied. API CI remains blocked by F-22. Exact adjustment checker remains a business-policy gap: current seeded roles grant no operational checker permission; no audience expansion was made.

## Phase 3 HR training-expiry subject access (draft PR [#436](https://github.com/kwat0g/kwatog/pull/436))

- Registered `EmployeeTraining` access against both existing detail routes: the employee's own `/self-service/trainings/{id}` route, or active HR users with `hr.employees.trainings.view` whose row scope from `EmployeeCompetenceScope` includes the employee. Both require the HR feature to be enabled. This reuses the route's existing department-plus-self and view-all rules.
- Migrated both training-expiry sends (employee and HR/department staff) to `sendForSubject()`. The existing resolver already restricts HR recipients by active account, training-view permission, and employee row scope; this gate adds a final authorization check without broadening recipients or changing tiers, dedupe, timing, links, or channels.
- The test covers owner and in-scope staff positives, out-of-scope and unpermissioned negatives, disabled-module suppression, and subject metadata on both notice links.
- `NotificationServiceSubjectAccessTest.php` plus `TrainingExpiryAlertTest.php`: **35 passed / 219 assertions**. `git diff --check` passed and the F-22 source workaround was restored exactly.
- Pint reports **2** existing style issues in touched legacy files (`TrainingExpiryService.php` import/spacing and `TrainingExpiryAlertTest.php` import/order/spacing); the new registry and unit-test changes pass. No broad formatting applied. API CI for the stack remains blocked by inherited F-22.

## Phase 3 Separation clearance subject access (draft PR [#437](https://github.com/kwat0g/kwatog/pull/437))

- Registered `Clearance` against the existing detail-route rule: HR feature enabled, active user, `hr.separation.view`, and `SeparationService::canView()` row scope.
- Migrated the separation-initiated listener to `sendForSubject()`. Existing configured role selection, actor exclusion, permission and scope checks, message, route, and event timing remain unchanged; no HR or Finance recipient expansion was made.
- `SeparationNotificationAudienceTest.php`: **2 passed / 13 assertions**, including an in-scope positive, out-of-scope negative through the central gate, exact subject metadata, and real route access checks. `git diff --check` passes; temporary F-22 workaround was restored.
- Pint passes the registry. Two legacy style issues remain in the listener payload alignment and existing test import order; the new test import has been ordered. No broad formatting applied. API CI remains blocked by inherited F-22.

## Phase 3 HR onboarding employee subject access (draft PR [#438](https://github.com/kwat0g/kwatog/pull/438))

- Registered `Employee` against `/hr/employees/{id}` using the HR feature, active-user check, `hr.employees.view`, and the same `DepartmentScope` arguments as `EmployeeService::show()`.
- Migrated stale onboarding reminders to `sendForSubject()` and kept delivery in-app only. Configured role candidates are filtered through the shared access registry before claiming the reminder; when no selected user can open the employee, the reminder remains retryable. A missing employee row also leaves it pending rather than emitting an unlinked notice.
- Tests verify exact employee subject metadata, in-scope department-head delivery, out-of-scope denial and route 404, empty-scope retry, and delivery-exception rollback.
- `OnboardingTest.php`: **13 passed / 49 assertions**. `git diff --check` passes; temporary F-22 workaround restored exactly. Pint passes the registry and service; one pre-existing spacing issue remains in the touched test file.

## Phase 3 Recruitment application subject access (draft PR [#439](https://github.com/kwat0g/kwatog/pull/439))

- Registered `JobApplication` against its detail route: HR and Recruitment modules enabled, active account, and `hr.recruitment.view`. The current route and application list are company-wide for that permission; no per-application assignee or row scope exists.
- Migrated the new-application notice to `sendForSubject()`. Existing configured `RecruitmentNotificationRecipients` selection remains unchanged and already requires active users with `hr.recruitment.view`; the subject gate rechecks that access immediately before delivery.
- `PublicRecruitmentTest.php` verifies actual notice subject/link metadata, allowed and revoked/inactive recipient outcomes, and application detail route access. Targeted API run: **2 passed / 13 assertions**. `git diff --check` and Pint pass; temporary F-22 workaround restored.

## Phase 3 Recruitment bottleneck subject access (draft PR [#440](https://github.com/kwat0g/kwatog/pull/440))

- Registered `JobPosting` against the same HR/Recruitment feature flags and active-user `hr.recruitment.view` rule as the posting detail route. Application bottlenecks use the `JobApplication` rule added in #439.
- Migrated the bottleneck command's application and expired-posting notices to `sendForSubject()`. Preserve the command's existing active configured HR audience, alert conditions, exact links, 24-hour default dedupe, and in-app-only delivery.
- `RecruitmentBottleneckCommandTest.php`: **4 passed / 18 assertions**, including subject metadata for both model types, negative access attempts, route permission denial, and existing dedupe behavior. `git diff --check` passes; temporary F-22 workaround restored.
- Pint reports two pre-existing style issues in the command and test file; no broad formatting applied. API CI remains blocked by inherited F-22.

## Phase 3 Leave event subject access (draft PR [#441](https://github.com/kwat0g/kwatog/pull/441))

- Leave requests use an explicit subject rule: Leave module enabled, active recipient, and `ApprovalTypeRegistry::userCanViewRecord()` so the existing `leave.view` permission and `ApprovalSourceScope` match the detail route's self/department/HR visibility.
- Migrated submitted, pending-HR, approved, and rejected notices to `sendForSubject()`. Existing selector, event timing, message, links, and email behavior remain unchanged.
- `LeaveNotificationTest.php`: **8 passed / 32 assertions**, including department-scope allow/deny, owner outcome access, module-disabled suppression, and subject metadata on all event forms. The actual Leave detail route matches the central access outcomes.
- `git diff --check` passes; temporary F-22 workaround restored. Pint reports four pre-existing style issues in touched legacy listener/test lines; no broad formatting applied.

## Phase 3 Overtime event subject access (draft PR [#442](https://github.com/kwat0g/kwatog/pull/442))

- Registered `OvertimeRequest` with link-specific access. The HR detail target requires Attendance enabled and `attendance.view`, then matches the detail controller's all-record HR/system-admin, owner, or delegated same-department visibility. The self-service target requires both Attendance and HR enabled and the exact employee owner. Unknown link targets fail closed.
- Migrated submitted and approved/rejected notices to `sendForSubject()`. Existing audience selector, event timing, messages, links, email behavior, and dedupe remain unchanged.
- `OvertimeNotificationTest.php`: **6 passed / 23 assertions**, including in-scope and cross-department recipients, the actual HR detail route, owner self-service route, subject metadata, and feature-disabled suppression. The preceding combined subject-access run passed **29 tests / 194 assertions**; after the final feature-gate tightening, the overtime feature file passed again.
- `git diff --check` passes; temporary F-22 workaround restored. Pint reports four existing style issues across the touched registry, listeners, and legacy test formatting; no broad formatting applied.

## Phase 3 Missing time-out subject access (draft PR [#443](https://github.com/kwat0g/kwatog/pull/443))

- Registered Attendance against the HR Attendance list target. Access requires the Attendance feature, `attendance.view`, an active account, and the same owner, department-approver, or company-wide scope used by `AttendanceService::list()`.
- Migrated the HR company summary and per-department head summary to `sendForSubject()`. Existing recipient selectors, run time, message, current list link, email behavior, and dedupe remain unchanged.
- `PunchCommandsTest.php`: **7 passed / 35 assertions**, including HR and department-head route access, a cross-department row, a revoked approver, and subject metadata. The HR sees all three seeded rows, the authorized head sees the two rows in their department, and the revoked head sees none.
- The current SPA defaults its date filter to today while the command reports yesterday; the existing link omits explicit dates and can open an empty list. This link-filter correction is a separate finding/PR and remains required for exact click-through.
- `git diff --check` and PHP syntax checks pass; temporary F-22 workaround restored. Pint reports one existing `fully_qualified_strict_types` style issue in the touched test file.

## Separate finding fix: F-48 overtime requester self-notice (draft PR [#444](https://github.com/kwat0g/kwatog/pull/444))

- Excluded the employee linked to the overtime request from the configured department-head approval audience. Other active, authorized department heads in that department still receive the submitted notice.
- The regression test failed before the fix because the requester received `attendance.ot_submitted`; after the fix `OvertimeNotificationTest.php` passes **7 tests / 25 assertions** and verifies another same-department approver still receives the notice.
- `git diff --check` passes; temporary F-22 workaround restored. API CI remains blocked by inherited F-22.

## Separate finding fix: F-49 missing-timeout notice date filter (draft PR [#445](https://github.com/kwat0g/kwatog/pull/445))

- The previous-day missing-timeout notice now links with `from` and `to` both set to that day, overriding the Attendance page's today default while preserving the existing list scope.
- `PunchCommandsTest.php`: **7 passed / 35 assertions**, including notification text/link and HR, department-head, and revoked-head list totals. The exact-link regression failed against the old link and passed after the date range was added. SPA `index.test.tsx`: **2 passed**, including the incoming query filter. `git diff --check` passes; temporary F-22 workaround restored.

## Phase 3 Machine breakdown notice subject access (draft PR [#446](https://github.com/kwat0g/kwatog/pull/446))

- Registered MRP Machine access by link target: `/mrp/machines/{hash}` requires MRP plus `mrp.machines.view`; `/maintenance/downtime?search=...` requires Maintenance plus `maintenance.view`.
- Migrated the active breakdown listener and repeat-breakdown command to `sendForSubject()`. Existing role selectors, severity, event timing, message, links, email, and dedupe remain unchanged.
- `MachineBreakdownNotificationTest.php`, `MaintenanceRepeatBreakdownTest.php`, and `NotificationServiceSubjectAccessTest.php`: **29 passed / 203 assertions**, including both routes, subject metadata, repeat-breakdown query results, and feature-disabled suppression. `git diff --check` passes; Pint reports one existing registry style issue; temporary F-22 workaround restored.
- F-50 records the unresolved approved same-site audience rule: Machine has no normalized site/assignment field, and the underlying list routes are permission-wide. The new gates enforce current route access only.

## Separate scope finding: F-50 machine site and assignment scope (draft PR [#447](https://github.com/kwat0g/kwatog/pull/447))

- Recorded the mismatch between the approved same-site/assigned recipient default and current company-wide role/permission selectors. `Machine.asset_id` links to an Asset whose `location` is free text; machine and downtime readers have no site row scope.
- Proposed a normalized site/assignment relationship and shared Machine/downtime list/detail scope before narrowing recipients. Do not infer site from free-text location.
- Documentation only; no tests run.

## Phase 3 Delivery finance notice subject access (draft PR [#448](https://github.com/kwat0g/kwatog/pull/448))

- Both no-invoice notifications now carry the confirmed Delivery as their subject and link to `/supply-chain/deliveries/{hash}`. The SPA route exists; the API detail route is the exact target.
- Existing role/permission recipient selectors remain unchanged. `sendForSubject()` suppresses finance users with `accounting.invoices.create` who lack `supply_chain.view` or `supply_chain.deliveries.view`; eligible users receive delivery number, exact link, and subject metadata. Existing invoice-created notifications remain gated against Invoice access.
- `AutoInvoiceOnDeliveryConfirmTest.php`: **6 passed / 54 assertions**. It verifies eligible delivery access and notification metadata, no notice to a finance-only user without Delivery access, and route 200/403 outcomes for those users. The run used and restored the temporary F-22 syntax workaround.
- `git diff --check` passes. Pint reports one pre-existing `fully_qualified_strict_types` issue in the feature test. Recipient role appropriateness and duplicate no-invoice event notices remain open. PR #448 finding-lifecycle passes; API CI fails before PHPUnit on inherited F-22 (`PdfRenderService::ensureDompdfDirs()` redeclaration, line 200).

## Resume instruction

Next: continue the remaining notification dispatch scan. F-40, F-45, F-47, and F-50 remain separate scope/functional findings. Keep each unit on its own branch/PR and update this file with test evidence, remaining gaps, and findings.
