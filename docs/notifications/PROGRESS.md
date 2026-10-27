# Notification and badge remediation progress

**Updated:** 2026-10-27
**Current gate:** Phase 2 approved. PRs #391–#402, #404–#406 are merged; #395 merged as `d7795f26`, #396 as `a4d6e31c`, #397 as `84ff7bfa`, #398 as `bd3817ff`, #399 as `aa993b56`, #400 as `313dfbf0`, #401 as `a8fd87df`, #402 as `64ed9288`, #404 as `71c8cc61`, and #405 as `3f96ddcb`, #406 as `4a33bf31`. PR #403 duplicates the inactive-recipient fix already merged in #382; PR #407's missing-import fix is already in main via #371. Both remain queued for cleanup. PR #406 merged safe warnings for empty notification audiences. PR #408 merged as `ee8beae6`, scopes separation notices to clearance visibility, and excludes the initiator; its original-head audience test passed 2/9, post-rebase API tests skipped to avoid shared `ogami_test`. PR #409 merged as `fcf9c988`; original-head API passed 1/26 and SPA passed 42 tests across two files; post-rebase tests were not run here. PR #410 merged as `6f4d4661`; original-head API passed 1/27 and SPA passed 42 tests. PR #411 merged as `ee585af0`; original-head API passed 1/14 and portal layout passed 1 test. PR #412 merged as `c39257c3`, adding the approval-subject gate; its original-head tests passed and post-rebase tests were skipped to avoid shared `ogami_test`. PR #413 merged as `07f7451c`; its inactive-recipient expectation fix passed 22 tests / 62 assertions on the original head; production behavior is unchanged. PR #414 merged as `f12bf20e`; original-head subject-access tests passed 6/27 and invoice workflow tests passed 40/308; API tests were skipped after rebase to avoid shared `ogami_test`. PR #415 merged as `0d78b312`; original-head subject-access tests passed 7/35 and budget/transfer tests passed 21/118; its added deleted-requester denial assertion was not rerun after rebase. PR #416 merged as `0983d9d9`; original-head bill self-notice tests passed 2/23. PR #417 merged as `1255df40`; original-head subject-access tests passed 8/40 and supplier-invoice fallback passed 1/8; post-rebase API tests were skipped. PR #418 merged as `a2f80d0a`, adding StockAdjustment subject authorization; original-head tests passed, post-rebase API tests were skipped to avoid shared `ogami_test`. PR #419 merged as `087ed152`, adding SupplierItemListing subject authorization; its original-head description reports 29 tests / 150 assertions, and post-rebase API tests were skipped to avoid shared `ogami_test`. PR #420 merged as `89f54139`, adding EmployeeLoan subject authorization; original-head tests passed 7 / 24 assertions. PR #421 merged as `bc071131`, adding WorkOrder subject authorization; original-head tests passed 24 / 134 assertions. PR #422 merged as `335860bc`, fixing duplicate staffing reviews under F-51; original-head tests passed 15 / 91 assertions. PR #423 merged as `7975d391`, adding SalesOrder subject authorization and email-failure rechecks; original-head focused tests passed, with five unrelated failures in a broader run. PR #424 merged as `4f4922d1`, adding PurchaseOrder subject authorization. PR #425 is redundant: PR #460 already contains its supplier-mail text fix; its open PR and branch await final cleanup. PR #426 merged as `e7126105`, adding PurchaseRequest subject authorization. PR #427 adds Delivery subject authorization; original-head evidence is recorded below. F-50 records a separate Supplier Listings sidebar permission mismatch. CI is waived per the user instruction. Full-project recipient and badge verification remain open.

## Phase status

| Phase | Status | Evidence |
| --- | --- | --- |
| 0. Discovery | Draft complete | `00-discovery.md`: 23 modules, 129 in-app service call sites, 27 direct `Mail::to` sites, scheduled trigger table, static route-target scan, 14 broadcast classes, 73 internal routes, 19 portal routes, nine compact-shell links, 29 badge definitions. Static source review plus aggregate-only local DB query. |
| 1. Inventory / leak analysis | Draft complete; runtime proof outstanding | `01-inventory.md`: source audience expressions, direct delivery paths, 17-role static selector-risk matrix, 77 email-failure wrapper call sites, payload/link/dedupe expressions, badge audit, root causes, module coverage. Initial audit had no seeded-role scenarios; partial targeted recipient and badge/list comparisons are recorded below. Full role/scope proof remains open. |
| 2. Policy and plan | Approved | User approved the proposed defaults on 2026-10-25. |
| 3. Backend foundation | In progress | Units 1–4 and documented fixes through #364, #371, #380, #382, #485, and #487 are merged to `main`. PR #412 gates supported approval notices; PR #414 extends that gate to invoices and related email-failure fallbacks; PR #415 adds Budget and BudgetTransfer subject checks; PR #417 adds Bill and supplier-invoice fallback checks; PR #418 merged as `a2f80d0a` and extends the gate to StockAdjustment; PR #419 merged as `087ed152` and adds SupplierItemListing subject access; PR #420 merged as `89f54139` and adds EmployeeLoan access for scoped internal readers and the employee-owned route; PR #421 merged as `bc071131` and adds WorkOrder access for route viewers and assigned crew; PR #422 merged as `335860bc` and deduplicates duplicate staffing reviews under F-51; PR #423 merged as `7975d391` and adds SalesOrder access plus queued email-failure rechecks; PR #424 merged as `4f4922d1` and adds PurchaseOrder access for permission- and row-scoped route readers; PR #426 merged as `e7126105` and adds PurchaseRequest access for permission- and row-scoped readers; PR #427 adds Delivery access for internal detail-route readers. Global record authorization coverage, signal registry, and remaining event families remain open. |
| 4. Frontend | In progress | PR #378 hardens same-app notification destinations. PR #381 adds labeled AP signals and a keyboard-operable signal popover. PR #384 narrows the inquiry triage signal to actionable users. Replacement PR #490 adds MRB / Quarantine with held-only click-through. PR #386 adds inbound shipments with mid-pipe click-through. PR #387 adds Sales Orders confirmation click-through. PR #388 adds multi-signal Work Order navigation for awaiting confirmation, overdue, due soon, and paused; PR #389 aligns its frontend and badge permission gates with the API route. PR #391 links the HR badge to the actionable Profile Update queue. PR #392 adds open and unassigned Maintenance Work Order click-through. PR #396 gates Leave row, detail, and bulk actions by API-computed actionability. PR #409 adds a permission- and employee-scoped Training Records list with expired and upcoming/expiring filters. PR #410 adds filtered AR invoice signals and a four-signal sidebar. PR #411 adds customer invoice filters and account-scoped unpaid/overdue chips. Notification Center filters, responsive/browser QA, and multi-signal coverage for other modules remain open. |
| 5. Migration | Partial | PR #380 scopes late-supplier notices to users who can open the linked PO. PR #381 aligns overdue, unpaid, and due-soon AP signals with filtered list scopes. PR #382 applies inactive-user exclusion through the shared internal delivery normalizer. PR #408 reuses clearance detail row scope for separation recipients and excludes the initiator. PR #405 verifies a department-head recipient boundary for training-expiry notices. PR #384 restricts the triage badge to users with both list and status-management access. PR #490 shares the held scope between MRB badge and list. PR #386 shares mid-pipe shipment scope between badge and list and aligns route permissions. PR #387 shares the confirmation queue scope between the badge and Sales Orders list. PR #388 shares Work Order scopes with the four filtered-list signals. PR #389 aligns Work Order permissions across the API, SPA, Sidebar, and overdue/due-soon badges. PR #390 filters scheduled assignment-alert recipients by Work Order route permissions without changing its trigger or message. PR #391 shares the actionable Profile Update scope between its badge and filtered list. PR #392 shares Maintenance Work Order scopes between badge and filtered list. PR #395 shares Leave approval badge/list scope; PR #396 aligns Leave action controls with API actionability; PR #409 shares employee-training badge and list scopes. Remaining dispatch sites and sidebar signals remain open. |
| 6. Extras | Not started | No extra features started. |
| 7. Verification | Partial | PR #381 targeted API parity tests passed **4 tests / 89 assertions** and `Sidebar.behavior.test.tsx` passed **30 tests** on the original feature head. PR #382 `NotificationServiceTest.php --filter=inactive_internal_users_are_excluded_from_notification_delivery` passed **1 test / 8 assertions** on its original feature head. PR #405 `TrainingExpiryAlertTest.php --filter=test_training_expiry_notice_only_reaches_department_heads_with_employee_scope` passed **1 test / 4 assertions** on its original feature head. PR #384 `Sidebar.behavior.test.tsx` passed **34 tests** after rebase; PHP syntax, targeted ESLint, and diff checks passed. Its API test passed **1 test / 10 assertions** on the original feature head but was not rerun to avoid the configured shared `ogami_test` database. PR #490 API test passed **1 test / 8 assertions** on the original feature head; after rebase, its two focused sidebar files passed **60 tests**, targeted ESLint and PHP syntax checks passed, and `git diff --check` passed. Its API test was not rerun to avoid the configured shared `ogami_test` database. PR #386 API test passed **1 test / 19 assertions** and sidebar tests passed **57** on its original feature head; after rebase, the two sidebar files passed **62 tests**, targeted ESLint, PHP syntax checks, and `git diff --check` passed. Its API test was not rerun to avoid the configured shared `ogami_test` database. PR #387 API test passed **1 test / 8 assertions** and sidebar behavior suite passed **34 tests** on its original feature head; after rebase the sidebar behavior suite passed **37 tests**, targeted ESLint, PHP 8.3 syntax checks, and `git diff --check` passed. Its API test was not rerun to avoid the configured shared `ogami_test` database. PR #388 API badge tests passed **4 tests / 59 assertions**, focused list-filter/settings tests passed on its original feature head, and sidebar tests passed **36 tests**. After rebase, sidebar tests passed **39 tests**, changed-file ESLint, PHP 8.3 syntax checks, and `git diff --check` passed. Its API tests were not rerun to avoid the configured shared database. PR #389 API badge tests passed **5 tests / 65 assertions** on its original feature head; after rebase, Sidebar permission tests passed **27** and Production route permission tests passed **3**, with targeted ESLint, PHP syntax checks, and `git diff --check` passing. Its API test was not rerun against the shared database. PR #390 `ProductionAssignmentAlertAudienceTest` passed **1 test / 10 assertions** on its original feature head; post-rebase API test is not rerun against shared `ogami_test`. CI is waived. Full role, channel, and badge parity, browser QA, and the full suite remain open. |

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
| F-36. CRM inquiry triage audience | `notifications/triage-inquiry-badge` | [#384](https://github.com/kwat0g/kwatog/pull/384) | merged as `cc377cc4` |
| F-37. MRB held-list badge | `notifications/mrb-holds-sidebar-main` | [#490](https://github.com/kwat0g/kwatog/pull/490) | merged as `bac068db` |
| F-38. Mid-pipe shipment badge | `notifications/mid-pipe-shipments` | [#386](https://github.com/kwat0g/kwatog/pull/386) | merged as `3e126717` |
| F-39. Sales Orders confirmation queue | `notifications/sales-order-confirmation-badge` | [#387](https://github.com/kwat0g/kwatog/pull/387) | merged as `16f01f92` |
| Work Order multi-signal parity | `notifications/production-overdue-badge` | [#388](https://github.com/kwat0g/kwatog/pull/388) | merged as `51d415b7` |
| F-40. Work Order route/RBAC gate alignment | `notifications/fix-production-badge-route-permissions` | [#389](https://github.com/kwat0g/kwatog/pull/389) | merged as `723cc8f1` |
| F-41. Production assignment-alert access | `notifications/fix-production-alert-access` | [#390](https://github.com/kwat0g/kwatog/pull/390) | merged as `e6fc83c6`; original-head API test passed; post-rebase API test not run against shared DB |
| F-01. Profile Update actionable queue | `notifications/fix-profile-request-badge` | [#391](https://github.com/kwat0g/kwatog/pull/391) | merged as `ffb34fdd`; original-head API and post-rebase SPA tests passed; post-rebase API test not run against shared DB |
| F-09. Maintenance Work Order badge/list parity | `notifications/maintenance-work-order-signals` | [#392](https://github.com/kwat0g/kwatog/pull/392) | merged as `fb904737`; original-head API and post-rebase SPA/syntax checks passed; post-rebase API test not run against shared DB |
| F-42. Overtime route permission parity | `notifications/fix-overtime-list-permission` | [#393](https://github.com/kwat0g/kwatog/pull/393) | merged as `5f630dd7`; original-head API test passed; post-rebase syntax/diff checks passed; API test not rerun against shared DB |
| F-09. Overtime approval badge/list parity | `notifications/overtime-approval-signal` | [#394](https://github.com/kwat0g/kwatog/pull/394) | merged as `1109dc43`; post-rebase Sidebar 42, permission 28, page 1, ESLint, PHP syntax, and diff checks passed; post-fix API test not run against shared DB |
| F-09. Leave approval badge/list parity | `notifications/leave-approval-signal` | [#395](https://github.com/kwat0g/kwatog/pull/395) | merged as `d7795f26`; original-head API tests passed 14/44; post-rebase SPA 73, ESLint, PHP syntax, and diff checks passed; API tests not rerun against shared DB |
| F-45. Leave action visibility and stage authorization | `notifications/fix-leave-action-authorization` | [#396](https://github.com/kwat0g/kwatog/pull/396) | merged as `a4d6e31c`; original-head API tests passed 34/160 and SPA tests 7; post-rebase SPA 7, ESLint, PHP syntax, and diff checks passed; API tests not rerun against shared DB |
| 1.1. Native Laravel dispatch boundary | `notifications/central-dispatch-guard` | [#397](https://github.com/kwat0g/kwatog/pull/397) | merged as `84ff7bfa`; focused API test passed 9/74 on the original head; post-rebase PHP syntax and `git diff --check` passed; no post-rebase API test against shared DB |
| F-12. Chain broadcast payload minimization | `notifications/minimize-chain-broadcast-payload` | [#398](https://github.com/kwat0g/kwatog/pull/398) | merged as `bd3817ff`; original-head API 4/6 and SPA 2 tests passed; post-rebase SPA 2, ESLint, PHP syntax, and diff checks passed; API test not rerun against shared DB |
| F-12. Chain channel detail access | `notifications/scope-purchase-order-chain-channel` | [#399](https://github.com/kwat0g/kwatog/pull/399) | merged as `aa993b56`; original-head broadcast authorization tests passed 11/32; post-rebase PHP 8.3 syntax and `git diff --check` passed; API test not rerun against shared DB |
| F-12. Payroll progress channel access | `notifications/scope-payroll-progress-channel` | [#400](https://github.com/kwat0g/kwatog/pull/400) | merged as `313dfbf0`; original-head authorization test passed 12/36; post-rebase diff check passed; API test skipped to avoid shared DB; PHP unavailable |
| F-12. Operational channel route authorization | `notifications/scope-operational-channels` | [#401](https://github.com/kwat0g/kwatog/pull/401) | merged as `a8fd87df`; original-head authorization test passed 16/48; post-rebase `git diff --check` passed; API test skipped to avoid shared DB; PHP unavailable |
| 1.2. Laravel notification dispatch guard | `notifications/centralize-laravel-notifications-reconciled` | [#402](https://github.com/kwat0g/kwatog/pull/402) | merged as `64ed9288`; existing sends already used central service on current main; dispatch guard strengthened; post-rebase API tests skipped to avoid shared DB |
| F-35. Inactive recipient duplicate | `notifications/exclude-inactive-notice-recipients` | [#403](https://github.com/kwat0g/kwatog/pull/403) | redundant; behavior and tests already merged in #382/#402; close and delete branch during final cleanup |
| HR password-reset mail-only catalog coverage | `notifications/integrate-password-reset-fix` | [#404](https://github.com/kwat0g/kwatog/pull/404) | merged as `71c8cc61`; parent signature fix already in main via #371; original-head mail-channel test passed 1/2 |
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
| F-14. Training-expiry department scope proof | `notifications/verify-training-expiry-audience-stack` | [#405](https://github.com/kwat0g/kwatog/pull/405) | merged as `3f96ddcb`; original-head boundary test passed 1/4; post-merge runtime recheck not run |
| Empty notification recipient audit | `notifications/audit-empty-recipient-audiences` | [#406](https://github.com/kwat0g/kwatog/pull/406) | merged as `4a33bf31`; original-head `NotificationServiceTest.php` passed 12/44; post-rebase API tests skipped to avoid shared DB |
| F-46. Separation notice row-scope and actor exclusion | `notifications/fix-separation-notice-scope` | [#408](https://github.com/kwat0g/kwatog/pull/408) | merged as `ee8beae6`; original-head audience test passed 2/9; post-rebase `git diff --check` passed; API test skipped to avoid shared DB |
| F-05/F-09. HR training badge/list parity | `notifications/hr-training-signal-parity` | [#409](https://github.com/kwat0g/kwatog/pull/409) | merged as `fcf9c988`; original-head API passed 1/26 and SPA passed 42 tests across two files; post-rebase `git diff --check` passed |
| F-09/F-13. Internal AR invoice signals | `notifications/ar-invoice-signal-parity` | [#410](https://github.com/kwat0g/kwatog/pull/410) | merged as `6f4d4661`; original-head API passed 1/27, Sidebar tests 42, targeted ESLint passed; after rebase API/SPA tests skipped due shared DB and missing tools; `git diff --check` passed |
| F-09/F-13. Customer portal AR invoice signals | `notifications/customer-ar-signals` | [#411](https://github.com/kwat0g/kwatog/pull/411) | original-head API passed 1/14 and portal layout passed 1 test; post-rebase API test skipped to avoid shared DB; `git diff --check` passed |
| Approval notification subject access gate | `notifications/subject-access-gate` | [#412](https://github.com/kwat0g/kwatog/pull/412) | merged as `c39257c3`; original-head tests passed 25 tests / 84 assertions; post-rebase API tests skipped to avoid shared DB; `git diff --check` passed |
| F-48. Inactive-recipient test correction | `notifications/fix-inactive-notice-test` | [#413](https://github.com/kwat0g/kwatog/pull/413) | merged as `07f7451c`; original-head hardening tests passed 22 / 62; no production behavior change |
| Invoice subject access gate | `notifications/ar-invoice-subject-gate` | [#414](https://github.com/kwat0g/kwatog/pull/414) | original-head subject-access tests passed 6 / 27; invoice prebill, payment, and dunning tests passed 40 / 308; post-rebase API tests skipped to avoid shared DB; `git diff --check` passed |
| Budget and transfer subject access gate | `notifications/accounting-budget-subject-gate` | [#415](https://github.com/kwat0g/kwatog/pull/415) | merged as `0d78b312`; original-head subject-access tests passed 7 / 35; Budget consumption/lifecycle and transfer tests passed 21 / 118; added a deleted-requester negative-access assertion during rebase review; post-rebase tests not rerun |
| F-39. Bill payment self-notice | `notifications/fix-bill-self-notice` | [#416](https://github.com/kwat0g/kwatog/pull/416) | merged as `0983d9d9`; original-head targeted tests passed 2 / 23; post-rebase API test not rerun |
| Bill subject access gate | `notifications/accounting-bill-subject-gate` | [#417](https://github.com/kwat0g/kwatog/pull/417) | merged as `1255df40`; original-head subject-access tests passed 8 / 40; supplier-invoice fallback test passed 1 / 8; post-rebase API tests not rerun |
| Stock adjustment subject access gate | `notifications/inventory-stock-adjustment-subject-gate` | [#418](https://github.com/kwat0g/kwatog/pull/418) | merged as `a2f80d0a`; original-head subject access passed 1 / 8; approval/rejection metadata passed 1 / 30; audience and feature denial passed 1 / 5; post-rebase tests not rerun |
| Supplier listing subject access gate | `notifications/purchasing-supplier-listing-subject-gate` | [#419](https://github.com/kwat0g/kwatog/pull/419) | merged as `087ed152`; original-head tests 29 / 150; post-rebase API tests skipped to avoid shared DB; separate Sidebar mismatch documented as F-50 |
| Loan notification subject access gate | `notifications/hr-loan-subject-access` | [#420](https://github.com/kwat0g/kwatog/pull/420) | merged as `89f54139`; original-head subject access passed 1 / 8; loan notification tests passed 6 / 16; post-rebase tests skipped to avoid shared DB |
| Production work-order notification subject access gate | `notifications/production-work-order-subject-access` | [#421](https://github.com/kwat0g/kwatog/pull/421) | merged as `bc071131`; original-head targeted tests passed 24 / 134; post-rebase API tests skipped to avoid shared DB |
| F-51. Duplicate Production staffing notices | `notifications/dedupe-production-staffing-alerts` | [#422](https://github.com/kwat0g/kwatog/pull/422) | merged as `335860bc`; original-head schedule tests passed 6 / 56; attendance-connection tests passed 9 / 35; post-rebase API tests skipped to avoid shared DB |
| SalesOrder notification subject access gate | `notifications/sales-order-subject-access` | [#423](https://github.com/kwat0g/kwatog/pull/423) | original-head subject-access, order response, confirmation, delivery-risk, and email fallback tests passed; five unrelated failures remain from broader run; post-rebase API tests skipped to avoid shared DB |
| Duplicate final-pay import fix | `fix/hr-final-pay-service-import` | [#407](https://github.com/kwat0g/kwatog/pull/407) | redundant; import already merged in #371; close and delete branch during final cleanup |
| F-29. Generic approval recipient collection | `fix/approval-notification-recipient-collection` | [#485](https://github.com/kwat0g/kwatog/pull/485) | merged as `4c525aa0` |

## Coverage ledger

- NotificationService call sites inventoried: **129 / 129** found by scan and cross-check; all 129 remain on existing entry points.
- Direct `Mail::to(...)` call sites inventoried: **27 / 27** under `api/app`; mail recipient runtime outcomes not tested.
- Direct Laravel `->notify()` / `Notification::send()` and central preference email paths inventoried in discovery; mail-only account/security and summary paths remain separate.
- Internal sidebar routes: **73** in the Phase 0 baseline; current branches add `/budgeting/transfers`, `/inventory/mrb`, `/supply-chain/shipments`, and `/hr/employee-trainings` (**77 current**); portal routes: **19**; compact-shell links: **9**.
- Phase 2 approved **87** signal IDs; source-specific additions through internal AR bring implementation to **93** without changing notification recipients. Baseline cross-reference covered 73 internal, 19 portal, and nine compact routes.
- Badge definitions inventoried: **29**; AP, Production, Maintenance, and internal AR add nine (**38**); baseline wired to internal nav: **22**; current branches wire **37**, leaving only informational `open_postings` unattached.
- Local role slugs: **17**; existing role-access audit says **16**. Reconcile before role-complete claim.
- Status/signal catalog: candidate data reviewed for all **23 modules**; exact enum classification, scope parity, and business approval remain outstanding.
- Unit 1 code changes are limited to `NotificationService` envelope normalization, its unit and portal tests, the SPA notification data type, and schema/progress documentation. No database migration or existing-row rewrite is needed.
- Payload schema v1 fields: `schema_version`, `event_type`, optional `subject_type`, `subject_id`, and caller-supplied `actor_id`. Existing `title`, `message`, `link_to`, event timing, recipients, channels, and legacy rows remain unchanged.
- Core `ApprovalService` action-required notices resolve only the active step's role holders and active delegates who pass the mapped action permission and `ApprovalTypeRegistry::userCanViewRecord`; exclude submitter and known acting approver. Store `recipient_reason` on each in-app row. F-16 outcome access is gated at all six inventoried senders. PR #412 adds `NotificationService::sendForSubject()` for approval-backed notices: missing/deleted or unregistered subjects fail closed, recipients without current record visibility are dropped, and stored rows include the subject class and key. Other approval senders and event families remain on existing paths.
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
- Four badge definitions lacked destinations on the original audit; PR #386 attached `shipments`, PR #387 aligned `pending_so` with its filter, and PR #388 attaches three Work Order signals. `training_expiry`, `training_upcoming`, and `open_postings` remain unattached. No full role matrix or browser layout check.

## Separate signal fix: F-36 CRM inquiry triage audience (PR [#384](https://github.com/kwat0g/kwatog/pull/384), merged as `cc377cc4`)

- The inquiry badge now requires `crm.inquiries.manage`; its count also requires `crm.inquiries.view` and reuses `ContactInquiryInboxService::listQuery()` with the `new` filter. The inbox's `new` filter uses `ContactInquiry::scopeAwaitingTriage()`, shared with the badge query.
- A read-only user with `crm.inquiries.view` can still open the filtered list but receives no triage badge. The seeded customer-service manager receives the count, equal to the filtered list total.
- On the original feature head: `BadgeControllerTest::test_inquiry_badge_matches_new_inquiry_list_and_requires_manage_permission` passed **1 test / 10 assertions**; `Sidebar.behavior.test.tsx` passed **31 tests**. After rebase, `Sidebar.behavior.test.tsx` passed **34 tests**; targeted ESLint, PHP syntax checks, and `git diff --check` passed. The API test was not rerun because PHPUnit uses the configured shared `ogami_test` database. CI is waived. Browser QA remains outstanding.

## Separate signal fix: F-37 MRB held-list badge (replacement PR [#490](https://github.com/kwat0g/kwatog/pull/490), merged as `bac068db`)

- `MaterialReviewRecord::scopeHeld()` powers both the `mrb_holds` counter and `/inventory/mrb?status=held`. Held is the only counted state; released, scrapped, and returned rows are excluded. The list has no department or site filter, so the badge matches its company-wide scope.
- Warehouse navigation shows MRB / Quarantine only with `inventory.mrb.view`; badge click opens the held filter. Employees without that permission receive neither the route nor the badge.
- The original PR #385 feature head's API parity/access test passed **1 test / 8 assertions**; its `Sidebar.behavior.test.tsx` and `Sidebar.permissions.test.tsx` passed **55 tests**. After rebase, the two sidebar files passed **60 tests**; targeted ESLint, PHP syntax checks, and `git diff --check` passed. The API test was not rerun because PHPUnit uses the configured shared `ogami_test` database. PR #385 could not be reopened after GitHub closed it for deleting its stacked base and rejected reopening its force-pushed head; replacement PR #490 targets `main`. CI is waived. Browser QA remains outstanding.

## Separate signal fix: F-38 mid-pipe shipment badge (PR [#386](https://github.com/kwat0g/kwatog/pull/386), merged as `3e126717`)

- `Shipment::scopeMidPipe()` includes only `InTransit`, `Customs`, and `Cleared`; it powers both the shipment badge and `/supply-chain/shipments?status=mid_pipe`. Soft-deleted rows and other shipment states are excluded.
- The sidebar item and shipment list accept either `supply_chain.view` or `supply_chain.shipments.view`; warehouse users with delivery-only access receive neither. The list has no narrower record/site scope.
- On the original feature head, the API scope/permission test passed **1 test / 19 assertions**; `Sidebar.behavior.test.tsx` and `Sidebar.permissions.test.tsx` passed **57 tests**. After rebase, the two sidebar files passed **62 tests**; targeted ESLint, PHP syntax checks, and `git diff --check` passed. The API test was not rerun because PHPUnit uses the configured shared `ogami_test` database. Browser QA remains outstanding.

## Separate signal fix: F-39 Sales Orders confirmation queue (PR [#387](https://github.com/kwat0g/kwatog/pull/387), merged as `16f01f92`)

- `SalesOrder::scopeAwaitingConfirmation()` includes sent drafts with `customer_confirmation_requested_at`, portal-submitted `awaiting_confirmation`, and `delivery_negotiation`; unsent drafts and soft-deleted orders are excluded. Badge and `signal=awaiting_confirmation` list share this scope.
- The SPA exposes the signal in the Sales Orders filter and directs the badge to the filtered list. The list remains gated by `crm.sales_orders.view`, matching the detail/list permission.
- Original feature-head API parity test passed **1 test / 8 assertions** and sidebar behavior suite passed **34 tests**. After rebase, sidebar behavior passed **37 tests**, targeted ESLint, PHP 8.3 syntax checks, and `git diff --check` passed. The API test was not rerun against the configured shared `ogami_test` database.

## Separate signal fix: Production Work Order badges (PR [#388](https://github.com/kwat0g/kwatog/pull/388), merged as `51d415b7`)

- `WorkOrder::scopeOverdue()`, `scopeDueSoon()`, `scopePaused()`, and `scopeAwaitingConfirmation()` power both their badge counters and corresponding list filters. Terminal and soft-deleted rows are excluded. Planned records are the real pre-confirmation state; no per-order confirmer exists. Paused remains distinct from material-blocked because `pause_reason` is free text and also captures machine downtime.
- The due-soon threshold defaults to three Manila calendar days and is admin-configurable from 0–365 days. Due soon includes planned, confirmed, in-progress, and paused records whose planned end is from now through end of the configured date.
- Overdue/ due-soon remain warnings; confirmation requires `production.view`, `production.work_orders.view`, `production.wo.confirm`; paused requires `production.view`, `production.work_orders.view`, `production.work_orders.lifecycle`. The endpoint has no site/line/assignee scope. PR #389 fixes F-40 by aligning sidebar, SPA route, and badge permissions with the API route group.
- Original feature-head API badge tests passed **4 tests / 59 assertions**; filter/settings tests passed. Original sidebar suite passed **36 tests**; post-rebase sidebar suite passed **39 tests**. Changed-file ESLint, PHP 8.3 syntax checks, and `git diff --check` passed. API tests were not rerun after rebase because PHPUnit uses the shared `ogami_test` database. Browser QA and full suites remain outstanding.

## Separate permission fix: F-40 Work Order parent-route mismatch (PR [#389](https://github.com/kwat0g/kwatog/pull/389), merged as `723cc8f1`)

- `Production/routes.php` requires `production.view` for every route in the module, including the work-order list, in addition to `production.work_orders.view`. PR #389 aligns Sidebar, SPA route, and overdue/due-soon badge checks with those API permissions.
- On the original feature head, the negative API override test passed as part of `BadgeControllerTest --filter=production_` (**5 tests / 65 assertions**). After rebase, Sidebar permission tests passed **27**, Production route tests passed **3**, targeted ESLint and PHP syntax checks passed, and `git diff --check` passed. The API test was not rerun against shared `ogami_test`.

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

## Separate finding fix: F-41 Production assignment-alert access (PR [#390](https://github.com/kwat0g/kwatog/pull/390), merged as `e6fc83c6`)

- `AlertEngineService::checkProduction()` starts with active `ppc_head` and `production_manager` users and adds active `maintenance_tech` users when a slot has a machine or mold. The linked Work Order route requires both `production.view` and `production.work_orders.view`.
- PR #390 filters the existing assignment-review audience through both route permissions before `NotificationService::sendInApp()`. It leaves event timing, title/body, deep link, dedupe key, PPC/Production Manager candidates, and the separate Sales Officer delivery-risk notice unchanged.
- `ProductionAssignmentAlertAudienceTest` passed **1 test / 10 assertions** on the original feature head. It proves PPC/Production Manager recipients are retained, Maintenance Technician is excluded, and an eligible notice links to the Work Order. After rebase, PHP 8.3 syntax and `git diff --check` passed. API test was not rerun against shared `ogami_test`. Browser verification remains outstanding.

## Separate signal fix: F-01 Profile Update actionable queue (PR [#391](https://github.com/kwat0g/kwatog/pull/391), merged as `ffb34fdd`)

- The `profile_requests` badge now appears on Bank Account Changes and links to `/hr/profile-update-requests?signal=awaiting_my_action`. `ProfileUpdateRequestService::awaitingMyActionQuery()` is shared by the badge and list.
- HR reviewers need both `hr.employees.view` and `hr.employees.edit`; their rows remain department-scoped and exclude self-submissions. Finance reviewers need `hr.profile_updates.finance_review`; they see only bank requests after HR review, excluding the requester and previous HR reviewer. Users with both audiences see their union. Existing broad review lists retain their prior filters.
- Original-head API parity coverage passed **9 tests / 69 assertions** for HR, Finance, combined permissions, department-scoped HR, and view-only users. SPA Sidebar and profile-request page tests passed **40 tests**; changed-file ESLint, PHP syntax, and `git diff --check` passed. Post-rebase API tests were not run against shared `ogami_test`; browser QA remains outstanding.
- Full SPA typecheck reports unrelated existing errors in export helpers, missing `lucide-react`, stock-transfer API, and purchase-order props. Pint reports existing style findings in touched service files; no broad formatting was applied.
- PR checks stop before tests on inherited issues: API syntax scan finds duplicate `PdfRenderService::ensureDompdfDirs()` (F-22); SPA dependency audit finds four existing advisories (two moderate, two high). Accessibility, visual baselines, and finding-lifecycle checks pass.

## Separate signal fix: F-09 Maintenance Work Order badge/list parity (PR [#392](https://github.com/kwat0g/kwatog/pull/392))

- `maintenance_wo` uses `MaintenanceWorkOrder::scopeOpen()` for both its count and `/maintenance/work-orders?queue=open`. `maintenance_wo_unassigned` uses `scopeUnassigned()` for both its count and `queue=unassigned`; the unassigned signal requires `maintenance.view` and `maintenance.wo.assign`.
- The existing list grants company-wide visibility to `maintenance.view` users and has no site column/filter. This follows current route/list scope; site-restricted counts remain a known gap.
- The policy catalog had proposed `signal=unassigned`; API and SPA use `queue=unassigned`, and the sidebar links to that real filter. The open badge also links to the `queue=open` filter.
- Original-head `MaintenanceWorkOrderBadgeParityTest`: **1 test / 16 assertions**; Sidebar behavior and Maintenance Work Order page tests: **39 passed**. After rebase, `Sidebar.behavior.test.tsx`: **41 passed**, Maintenance Work Order page: **1 passed**, targeted ESLint, PHP 8.3 syntax, and `git diff --check` passed. API tests were not rerun against shared `ogami_test`.

## Separate finding fix: F-42 Overtime route permission parity (PR [#393](https://github.com/kwat0g/kwatog/pull/393), merged as `5f630dd7`)

- The SPA lets approvers open the overtime list and request creators open the create page. The API had limited the list and options endpoints to `attendance.view`, returning 403 to approver-only and creator-only users.
- The list now accepts `attendance.view` or `attendance.ot.approve`; options accept any of `attendance.view`, `attendance.ot.approve`, or `attendance.ot.create`. `OvertimeService::list()` continues applying the existing `OvertimeDecisionPolicy::visibleTo()` row scope.
- `OvertimeListRoutePermissionTest` passed **1 test / 8 assertions** on the original feature head. It verifies approver access, creator options access, creator-only list denial, and unprivileged list/options denial. Post-rebase API test was not run against shared `ogami_test`.
- PHP 8.3 syntax checks passed for `Attendance/routes.php` and the feature test; `git diff --check` passed.

## Separate signal fix: F-09 overtime approval badge/list parity (PR [#394](https://github.com/kwat0g/kwatog/pull/394))

- `OvertimeDecisionPolicy::awaitingMyApprovalQuery()` powers both the `overtime` badge and `/attendance/overtime-requests?signal=awaiting_my_approval`. It requires pending status, applies the list's current row scope, and admits direct or active delegated approvers. `Sidebar` and the SPA route use the existing `delegated_approvals` field.
- The original query excluded the current user's own employee row but included a request submitted by the delegator, despite `assertNotSelfDecision()` rejecting delegates from approving their delegator's own request. The query and parity test now exclude that request only for delegate-only authority; direct approvers retain existing access.
- Before this review fix, `OvertimeApprovalBadgeParityTest` passed **3 tests / 15 assertions** and Sidebar/overtime SPA tests passed **66 tests** on the original head. After rebase, Sidebar behavior passed **42**, Sidebar permissions **28**, and overtime page tests **1**; targeted ESLint, PHP 8.3 syntax, and `git diff --check` passed. The post-fix API regression was not run against shared `ogami_test`; re-run only against an explicitly isolated database. Cross-department delegation remains unverified.

## Separate signal fix: F-09 Leave approval badge/list parity (PR [#395](https://github.com/kwat0g/kwatog/pull/395))

- `LeaveApprovalPolicy::awaitingMyApprovalQuery()` powers both the `leaves` badge and `/leaves/requests?signal=awaiting_my_approval`. It requires `leave.view`, the current pending workflow step (`department_head` step 1 or `hr_officer` step 2), matching stage permission and role/delegation, the existing `DepartmentScope`, and excludes the employee's own request.
- Signal mode ignores conflicting status and trashed filters; ordinary list behavior stays unchanged. Sidebar and page use the filtered queue link and hide the conflicting status filter.
- Before rebase, `LeaveApprovalBadgeParityTest` and `LeaveRequestVisibilityTest` passed **14 tests / 44 assertions**; Sidebar and Leave page tests passed **68**. After rebase, Sidebar behavior passed **43**, Sidebar permissions **29**, and Leave page tests **1**; targeted ESLint, PHP 8.3 syntax, and `git diff --check` passed. API tests were not rerun against shared `ogami_test`.
- F-45 is a separate action-visibility fix; F-44 records an unconfirmed delegation/self-approval policy question. The badge follows current endpoint actionability.

## Separate finding fix: F-45 Leave action visibility (draft PR #396)

- `LeaveApprovalPolicy` supplies `can_approve` for list and detail resources using the current pending step, stage permission, active workflow role/delegation, row scope, submitter exclusion, and prior-step separation of duties. List actionability uses one batch query per page; detail uses the same policy.
- Leave row, detail, and bulk controls use `can_approve`. Rejection requires the permission for the active stage. Approval and notification event timing stays unchanged.
- Original-head API `LeaveApprovalBadgeParityTest`, `LeaveRequestVisibilityTest`, and `LeaveRequestHardeningTest`: **34 passed / 160 assertions**. Original-head SPA leave list/detail and approval-history tests: **7 passed**. After rebase, the same SPA tests passed **7**, targeted ESLint and PHP 8.3 syntax checks passed, and `git diff --check` passed. API tests were not rerun against shared `ogami_test`.
- F-44 remains an unconfirmed business-policy question about delegates approving their delegator's own leave submission.

## Central dispatch boundary (PR #397, merged as `84ff7bfa`)

- `NotificationService::notify()` had no repository callers; Ncr and Maintenance already use `send()`. The unused wrapper is removed without changing event timing or direct `Mail::to(...)` paths.
- `NotificationCatalogTest` guards native Laravel dispatch outside the central service, ensures the wrapper does not return, checks mail-only channels, and inventories object-level `->notify()` calls for review.
- The earlier HR password-reset signature report was stale against current `main`: PR #371 already fully qualifies `\Throwable`. No HR finding is opened by this PR.
- The original-head test passed **9 tests / 74 assertions**. Post-rebase PHP 8.3 syntax and `git diff --check` pass. The API test was not rerun against shared `ogami_test`; no database was accessed.

## Separate finding fix: F-12 chain broadcast payload (PR #398, merged as `bd3817ff`)

- `ChainStepAdvanced` broadcasts only `{refresh: true}` under `.chain.refresh`; the SPA refetches its current record and chain queries rather than receiving status, document number, completed steps, or actor name.
- Older SPA bundles ignore the new event name and keep the existing 30-second polling fallback. Reverb-unavailable behavior remains polling-based.
- Original-head `ChainBroadcasterTest.php`: **4 passed / 6 assertions**; `useChainProgress.test.tsx`: **2 passed**. After rebase, `useChainProgress.test.tsx` passed **2 tests**, targeted ESLint, PHP 8.3 syntax, and `git diff --check` passed. API unit tests were not rerun against shared `ogami_test`.
- The toast previously displayed from chain progress is removed; the underlying record now refreshes without exposing its status or actor in a transient message. PR #399 separately aligns chain channel authorization with record access. Payroll progress policy remains open with HR/Finance.

## Separate finding fix: F-12 chain channel detail access (PR #399, merged as `aa993b56`)

- All eight `ChainDefinitions::allowedTypes()` now require the owning module to be enabled, a permission accepted by the matching detail route, and a live hashed record. Purchase Request and Purchase Order use their module access policies; Purchase Order approvers retain detail access without `purchasing.view`.
- `BroadcastAuthorizationTest.php` covers route permission alternatives, disabled modules, missing records, Purchase Request/Order row scope, department-head scope, and existing subject-channel checks.
- Original-head `BroadcastAuthorizationTest.php`: **11 passed / 32 assertions**. After rebase, PHP 8.3 syntax checks for the event, channel definitions, and feature test passed; `git diff --check` passed. API tests were not rerun against shared `ogami_test`; no database was accessed.
- Payroll progress payload and audience remain unchanged pending HR/Finance policy. Other subject-channel families remain under review.

## Separate finding fix: F-12 Payroll progress channel access (PR #400, merged as `313dfbf0`)

- `payroll.period.{hashId}` now requires the Payroll feature flag, `payroll.periods.view`, a decodable period hash, and an existing period, matching the period detail route. Progress payload and audience remain unchanged pending HR/Finance review.
- Original-head `BroadcastAuthorizationTest.php`: **12 passed / 36 assertions**; PHP syntax, targeted Pint, and `git diff --check` passed. After rebase, only `git diff --check` was available. API tests were not rerun against shared `ogami_test`; PHP is unavailable in this workspace.
- No unauthorized payroll subscription was proven; run metadata/headcount audience remains a policy question.

## Separate finding fix: F-12 operational channel access (PR #401, merged as `a8fd87df`)

- Production dashboard subscription now requires its module, parent read, and dashboard read permissions. Work Order subscription requires the Production module, parent read permission, Work Order detail permission, and a live visible record. Machine subscription requires the MRP module, machine permission, and a live visible record. Maintenance dashboard requires its module and read permission.
- The existing channels emit refresh-only events; event timing and payload remain unchanged. Original-head `BroadcastAuthorizationTest.php`: **16 passed / 48 assertions**. After rebase, `git diff --check` passed; PHP is unavailable and API tests were not rerun against shared `ogami_test`.
- Existing dashboard channels remain area-wide refresh pings with no record content.

## Centralize remaining Laravel notification dispatches (PR #402, merged as `64ed9288`)

- Current main already routes all seven reported email-notification sends through `NotificationService::sendLaravelNotification()` with their existing classes, channels, recipients, and timing. No production call-site changes remain in this rebased PR; it reconciles and strengthens the dispatch guard/test coverage.
- `NotificationCatalogTest` retains its object-level `->notify()` inventory, rejects native dispatches outside `NotificationService`, and checks mail-only channels. `NotificationServiceTest` verifies explicit-recipient deduplication and inactive-recipient exclusion; stale coverage for the removed `notify()` wrapper is removed.
- Original-head results: `NotificationServiceTest` **9 passed / 28 assertions**; `NotificationCatalogTest` **9 passed / 74 assertions**; password-reset, employee-welcome, and admin-welcome targeted tests passed. After rebase, API tests were skipped to avoid shared `ogami_test`; `git diff --check` is the static check.
- Recipient resolution policy, subject access checks, preferences for native email notifications, and lifecycle handling remain separate work.

## HR password-reset mail-only catalog coverage (PR #404, merged as `71c8cc61`)

- The inherited parent callback signature fix was already present on current `main` through PR #371; no production change remains here.
- `NotificationCatalogTest` now instantiates `EmployeePasswordResetNotification` and verifies it remains mail-only. Original-head results: subclass serialization test **1 passed / 2 assertions**, `NotificationCatalogTest` **9 passed / 76 assertions**, `UserProvisioningTest` **8 passed / 25 assertions**, and `UserAdministrationHardeningTest` **8 passed / 27 assertions**.
- After rebase, API tests were skipped to avoid shared `ogami_test`; PHP is unavailable. No database was accessed.

## Empty notification recipient audit (PR #406, merged as `4a33bf31`)

- `NotificationService` warns when structured, Laravel, or DSS notification delivery has no active recipient. Logs include only the notification type/class; no fallback recipient, database row, realtime event, or Laravel send is added.
- Original-head `NotificationServiceTest.php`: **12 passed / 44 assertions**, including inactive DSS suppression. After rebase, API tests were skipped to avoid shared `ogami_test`; PHP is unavailable.
- Behavior and recipients remain unchanged for eligible active users.

## Separation notice row-scope and actor exclusion (PR #408, merged as `ee8beae6`)

- Recipients retain configured role membership but must also pass `hr.separation.view`, the same clearance row scope used by the detail service, and must not be `initiated_by`.
- Original-head `SeparationNotificationAudienceTest.php`: **2 passed / 9 assertions**. This verifies same-department access, cross-department exclusion/404, and initiator exclusion.
- Post-rebase API tests are skipped to avoid shared `ogami_test`; PHP is unavailable.
- Finance audience policy remains bounded by the existing settings and permission. No new sensitive recipients are added.

## F-05/F-09 HR employee training signal parity (PR #409, merged as `fcf9c988`)

- `EmployeeTraining::scopeExpiredForActiveEmployees()` and `scopeUpcomingOrExpiringForActiveEmployees()` power both BadgeService counts and the filtered list. The list and badge share `EmployeeCompetenceScope`, active-employee filtering, and current-certificate selection. Dates use the application timezone; scheduled sessions include today through +14 days, completed certifications expiring today through +30 days.
- The sidebar item requires `hr.employees.trainings.view`; both signals open the matching `signal=expired|upcoming` list filter. The existing training-program catalog remains separate.
- Original feature-head API parity test passed **1 test / 26 assertions**; Sidebar and page tests passed **42 tests / 2 files**, and targeted ESLint passed. After rebase, API tests were not run to avoid shared `ogami_test`; focused SPA tests were unavailable because pnpm and Vitest are missing. `git diff --check` passed. CI failures are inherited F-22 / existing SPA typecheck and dependency issues.
- Informational `open_postings` remains unattached.

## F-09/F-13 internal AR invoice signal parity (PR #410, merged as `6f4d4661`)

- `Invoice::scopeUnpaid()`, `scopeOverdue()`, and `scopeAwaitingPaymentReview()` back both `/invoices?signal=...` and badge counters. Unpaid means finalized/partial with stored balance > 0; overdue also requires due date before `today(Asia/Manila)`; payment review requires a pending customer payment submission. Draft badge/list shows only invoices created by the current user. Prebill approval remains in Approvals.
- Payment review requires invoice-view and collect permission and excludes `system_admin`, matching the current review-notice audience. Internal invoice list is company-wide for users with view permission; no customer-row scope exists.
- Sidebar exposes overdue, unpaid, payment-review, and personal-draft chips. Payment-submission create/reject invalidates cached badges.
- Original-head `AccountsReceivableSignalParityTest`: **1 passed / 27 assertions**; Sidebar tests: **42 passed**; targeted ESLint, PHP syntax, and `git diff --check` passed. Post-rebase API tests were skipped to avoid shared `ogami_test`; SPA test tools and PHP are unavailable here.
- Due-today invoice row labels remain inconsistent with the new Manila date-only overdue filter; this separate functional bug is F-47. Portal customer filters/counts remain open.

## F-09/F-13 customer portal AR invoice signal parity (PR #411)

- Customer invoice filters and badge counts reuse `Invoice::scopeUnpaid()` and `scopeOverdue()`. Both counts and lists are bound to the signed-in `CustomerPortalUser::customer_id`; cross-customer counts are not exposed. Dashboard open-invoice count and outstanding balance use the same positive-balance scope as the unpaid link.
- Customer Invoices sidebar shows Overdue and Unpaid chips. Supplier sidebar and other portal items remain without counts. The new badges reuse existing Phase 2 signal IDs and filters.
- Original-head `CustomerPortalInvoiceSignalParityTest`: **1 passed / 14 assertions**; `PortalLayout.test.tsx`: **1 passed**. The API test covers list/count parity, cross-account isolation, cancelled/paid/zero-balance exclusions, dashboard totals, and invalid-filter rejection. After rebase, API tests were not rerun to avoid shared `ogami_test`; pnpm/Vitest and PHP are unavailable here. `git diff --check` passed.
- No notification dispatch or schema change. Browser QA and full portal-role coverage remain open.

## Approval notification subject access gate (PR #412)

- Core approval action-required and submitter outcome notices use `NotificationService::sendForSubject()`. `NotificationSubjectAccessRegistry` accepts registered approval subjects and supported Invoice, Bill, Budget, BudgetTransfer, and StockAdjustment subjects, then rechecks each recipient's permission and route scope; denied recipients are logged and not sent the notice.
- Structured notification data now stores the subject model class and key. Missing, soft-deleted, and unsupported subjects fail closed. No database migration.
- Original-head tests: subject-access unit tests **3 passed / 15 assertions**, approval visibility tests **3 / 20**, submitter outcome test **1 / 5**, and approval service tests **18 / 44**. Targeted PHP syntax, Pint, and diff checks passed per the PR record. The original API tests used a temporary removal of inherited F-22 duplicate code, restored byte-for-byte afterward.
- After rebase, API tests were not rerun to avoid shared `ogami_test`; `git diff --check` passed. Other notification modules still need exact subject-access rules and migration.

## Separate finding fix: F-48 inactive-recipient test correction (PR #413, merged as `07f7451c`)

- Corrected the stale in-app row expectation from four to three. Inactive users are excluded by `NotificationService::normaliseRecipients()` from database, broadcast, and email delivery.
- `NotificationServiceHardeningTest.php`: **22 passed / 62 assertions**. PHP syntax, targeted Pint, and `git diff --check` pass. Test used a temporary local workaround for inherited F-22 and restored that file exactly.
- Production behavior is unchanged. The fix is isolated from notification feature work.

## Invoice notification subject access gate (PR #414, merged as `f12bf20e`)

- `NotificationSubjectAccessRegistry` now extends the subject gate to `Invoice`: the Accounting feature must be enabled and each internal recipient must pass `accounting.invoices.view`, matching the invoice detail route's feature and permission gates. Invoice list/detail is company-wide; no customer-row scope exists for internal users.
- Internal invoice prebill, payment-submission, AR-dunning, delivery-confirmed (when a draft invoice exists), official-receipt fallback, and invoice email-failure notices pass their Invoice subject through the gate. Subject-gated delivery-failure fallbacks stay in-app and do not requeue email. Official-receipt fallback is skipped and logged if its linked invoice no longer exists. Customer portal invoice notices retain their separate active customer-account scope.
- Original-head `NotificationServiceSubjectAccessTest`: **6 passed / 27 assertions**; invoice prebill, payment-submission, and AR dunning tests: **40 / 308**. PHP syntax, selected Pint, and `git diff --check` passed per the PR record. These tests used a temporary removal of inherited F-22 duplicate code, restored afterward. API tests were not rerun after rebase to avoid shared `ogami_test`.
- No schema or database changes. Bills, credit notes, portal invoice notification authorization, full role matrix, and remaining subject types stay open.

## Separate finding: Accounting-disabled manual-invoice fallback (F-49)

- Static review found `NotifyFinanceOnDeliveryConfirmed` sends the no-draft fallback to configured users with `accounting.invoices.create` and links to invoice creation, but does not check `modules.accounting`. That route is rejected while Accounting is disabled. The linked-record branch is subject-gated by PR #414; this subject-less fallback remains unchanged.
- Separate fix: check the Accounting feature before sending, or use a feature-aware recipient resolver; do not change delivery event timing. Runtime scenario not run.

## Budget and transfer notification subject access gate (PR #415)

- `NotificationSubjectAccessRegistry` registers `Budget` and `BudgetTransfer`. Budgeting and Accounting must be enabled. `budgeting.view` opens any record; approval-only access mirrors the detail route's active submitted/pending status, direct/delegated permission, requester exclusion, and maker-checker role check.
- Budget and transfer submission/decision notices now pass their exact subject through `sendForSubject()`. Existing audience queries from current main remain intact.
- Rebase review found the transfer gate did not mirror the detail route's explicit `canActForPermission()` check when requester was deleted (`requested_by = null`). Added that check and a negative test assertion for an unrelated user; this assertion has not been run after rebase.
- Original-head `NotificationServiceSubjectAccessTest`: **7 passed / 35 assertions**; `BudgetConsumptionAndLifecycleTest.php` plus `BudgetTransferTest.php`: **21 passed / 118 assertions**. PHP syntax, Pint, and `git diff --check` passed per PR record. Tests used the temporary F-22 workaround and restored the source.
- No database or badge changes. Full suite, browser checks, and full role matrix remain open.

## Separate finding fix: F-39 bill payment self-notice (PR [#416](https://github.com/kwat0g/kwatog/pull/416), merged as `0983d9d9`)

- `BillService::postApprovedPayment()` now excludes the posting actor from the payment-created notification while retaining a notice for a different active creator with bill-view access.
- `BillServiceTest.php` self-post and separate-approver scenarios: **2 passed / 23 assertions**; requester still receives the notice when another user posts, approver does not. PHP syntax and `git diff --check` pass. Pint reports test-file style issues (`method_argument_space`, `fully_qualified_strict_types`); no broad formatting applied. The test run used and restored the F-22 workaround.
- This is isolated from subject-access feature work.


## Phase 3 Accounting bill subject access gate (PR [#417](https://github.com/kwat0g/kwatog/pull/417), merged as `1255df40`)

- Registered Bill subjects with the global bill detail route rule: Accounting must be enabled and the recipient must have `accounting.bills.view`. Bill payment-posted notices now pass through `sendForSubject()`; the existing requester permission check and actor exclusion remain.
- Supplier invoice email failure notices now target only the active Bill creator. Both immediate enqueue/invalid-address failures and queued delivery failures carry the Bill subject and pass the same access gate. The old broad `accounting.vendors.view` fanout was removed. No active creator means log and skip; no broad fallback is configured. `Bill.created_by` is the available owner field; no separate AP assignment rule was found in these paths.
- `NotificationServiceSubjectAccessTest`: **8 passed / 40 assertions**. Supplier invoice email failure target test: **1 passed / 8 assertions**, including the queued-mailable failure path. Bill payment self-post and distinct-poster cases: **2 passed / 23 assertions**. These targeted tests used the temporary F-22 workaround and restored `PdfRenderService.php` exactly.
- Original-head PHP syntax checks pass for all six changed PHP files. Pint passes five files; its check reports `class_attributes_separation` in `BillService.php`, outside this change. After rebase, `git diff --check` passed; API tests were skipped to avoid shared `ogami_test`.

## Phase 3 Inventory stock-adjustment subject access gate (PR [#418](https://github.com/kwat0g/kwatog/pull/418))

- Registered `StockAdjustment` with its list-route access rule: Inventory must be enabled and the recipient must hold `inventory.adjust` or `inventory.adjust.approve`. The list route has no row scope; this matches its `permission_any` middleware. Delegated approvers without either list permission fail closed.
- High-value adjustment approval and requester decision notices now use `sendForSubject()`. Existing direct/current approver selection, requester selection, event timing, and list links remain unchanged.
- Original-head `NotificationServiceSubjectAccessTest` stock-adjustment case: **1 passed / 8 assertions**; `PendingStockAdjustmentResolutionTest` approval/rejection case: **1 passed / 30 assertions**; revoked and approver-only audience case: **1 passed / 5 assertions**. The tests cover the shared route permission and disabled Inventory module.
- Original-head PHP syntax and selected Pint checks passed. After rebase, `git diff --check` passed; API tests were not rerun against shared `ogami_test`.

## Phase 3 Purchasing supplier-listing subject access gate (PR #419)

- Registered `SupplierItemListing` with the Purchasing feature flag and `purchasing.supplier_listings.view`; submission notices pass the listing subject through `sendForSubject()`. Current reviewer audience still requires both review eligibility and list access.
- Original PR reports 29 tests / 150 assertions across three files, syntax and Pint checks passed; post-rebase API tests were skipped to avoid shared `ogami_test`. CI is waived.
- Static review found separate F-50: Sidebar gates the list route with `purchasing.supplier_listings.review`, while SPA and API list routes require `purchasing.supplier_listings.view`. The defect remains separate; no runtime role scenario ran.

## Phase 3 HR loan subject access gate (PR #420)

- Registered `EmployeeLoan` access for two existing read paths: internal readers require the Loans feature, `loans.view`, and the existing `LoanAccessPolicy` row scope; the employee self-service path requires HR enabled and a user linked to the loan employee.
- Submission and decision notices now pass the loan subject through `sendForSubject()`. Recipient selection, event timing, message, and links remain unchanged.
- Original-head subject-access test passed **1 / 8 assertions**; `LoanNotificationTest.php` passed **6 / 16 assertions**. Tests cover owner access, Finance row scope, another employee denial, and feature disablement. Post-rebase API tests skipped to avoid shared `ogami_test`; no database was touched.

## Phase 3 Production work-order subject access gate (PR #421)

- Registered `WorkOrder` access for the existing routes: Production must be enabled, then the recipient must have both `production.view` and `production.work_orders.view`, or have current/historical crew access through the employee self-service route.
- Migrated the discovered WorkOrder-subject dispatches in Production and the outgoing-QC recovery command to `sendForSubject()`. Existing event timing and non-aggregate audience selectors remain; former crew and rework notices now link to the exact work order. The outgoing-QC aggregate is limited to users who can open its company-wide list.
- The outgoing-QC alert aggregates multiple work orders. Its configured audience and system-admin fallback now require both permissions for the company-wide list route, preventing crew-only access to one record from unlocking an aggregate containing other records.
- Original-head targeted tests passed **24 / 134 assertions**. The separate F-51 baseline failure on PR #420 was fixed by PR #422 (`335860bc`): the staffing observer and upcoming-work-order sweep now share a slot/crew/issue dedupe key (four notices vs. two expected before the fix). Post-rebase tests skipped to avoid shared `ogami_test`; the new limited-permission aggregate-fallback regression test is not run. No database touched.

## Separate finding fix: F-51 duplicate Production staffing notices (PR #422)

- `NotifyUpcomingWorkOrders` and `WorkOrderAssignmentService::notifyStaffingChange()` now use one canonical dedupe key built from slot, crew, and sorted issue details. No event timing changed.
- Original-head `WorkOrderScheduleReminderTest.php` passed **6 / 56 assertions**; `ProductionAttendanceConnectionTest.php` passed **9 / 35 assertions**, including the former duplicate and distinct-key cases. Post-rebase API tests skipped to avoid shared `ogami_test`. A cleared issue that returns unchanged may remain deduped until notification lifecycle resolution exists.

## Phase 3 CRM SalesOrder subject access gate (PR #423, merged as `7975d391`)

- Registered SalesOrder access against the CRM feature and `crm.sales_orders.view`, matching the internal list/detail route. Internal SalesOrder confirmation, customer-response, portal-order review, and Production delivery-risk notices use `sendForSubject()`; external portal notices remain bound to the customer account.
- Carries the subject through `NotificationService` and `UserNotificationMail` into queued email-failure fallbacks, so recipients are rechecked when mail delivery fails.
- Original PR evidence: subject-access tests passed 14; Outbox fact-tolerance tests passed 12; SalesOrder response tests passed 19; delivery-risk and internal portal-order audience cases passed 1 each. Rebased tests skipped to avoid shared `ogami_test`. A broader run had 81 passes and five unrelated failures (four stale `PHP` currency expectations and one portal-catalog expectation); root cause was not investigated here.

## Phase 3 Purchasing PurchaseOrder subject access gate (PR #424, merged as `4f4922d1`)

- Registered PurchaseOrder access against the Purchasing feature flag, `purchasing.view` or `purchasing.po.approve`, and `PurchaseOrderAccessPolicy::canView()`, matching the detail route and its row scope.
- Seven internal PO notices and five supplier-email failure paths now pass the PO subject through the shared gate. Existing recipient selection, event timing, message, dedupe, and links remain; supplier portal paths keep their vendor-bound audience. A cancelled supplier schedule without a linked PO now sends no internal notice because it has no valid PO destination.
- Original-head evidence in the PR: subject-access tests passed **15 / 95 assertions**; PO approval, auto-PO, supplier response, late ETA, dispatch, cancellation-email, and schedule tests passed as recorded in the PR description. The amended commit was not retested after rebasing; no database was touched.

## Separate finding: supplier cancellation email text (F-52)

- PR #425 proposed removing the Markdown escape for `>` in `SupplierPurchaseOrderCancelledMail::safeMarkdownText()`. Main already contains the same fix in PR #460 (`c64f50a2`), which also HTML-escapes the final string. PR #425 has no remaining code delta and is queued for closure and branch deletion during final cleanup.
- PR #460's focused mail suite passed **72 tests / 549 assertions** on isolated temporary PostgreSQL and Redis containers. No database schema changes.

## Phase 3 Purchasing PurchaseRequest subject access gate (PR #426, merged as `e7126105`)

- Route review: the Purchasing API group requires the feature flag, the detail route requires `purchasing.view`, and `PurchaseRequestController::show()` applies `PurchaseRequestAccessPolicy::canView()`. The subject registry enforces all three checks; approval permission alone does not open the detail route.
- The two discovered PR-subject notices, `chain.pr_approved` and `chain.pr_auto_convert_skipped`, now use `sendForSubject()`. Existing active-user, permission, and row-scope audience filters remain. Messages, event timing, and exact PR links remain unchanged. No PurchaseRequest email-failure fallback dispatch was found.
- Original-head PR evidence: `NotificationServiceSubjectAccessTest.php` passed **16 / 101 assertions**; focused approval-audience and skipped-auto-conversion tests passed **4 / 26 assertions**. PHP syntax, Pint on all six changed files, and `git diff --check` passed on the feature head. The amended commit was not retested after rebasing; no database was touched.

## Phase 3 Supply Chain Delivery subject access gate (PR #427)

- Registered Delivery against the Supply Chain feature flag and either `supply_chain.view` or `supply_chain.deliveries.view`, matching the internal detail route. That route has no additional record scope. Driver self-service and exact-customer portal access remain separate.
- Seven internal delivery-linked notification branches now use `sendForSubject()`, including immediate and queued customer-email failure fallbacks. The outgoing-QC draft notice now stores Delivery subject metadata matching its detail link.
- Original-head PR evidence: `NotificationServiceSubjectAccessTest.php` passed **17 / 114 assertions**; focused delivery-draft, delivery-attempt, and email-failure tests passed **4 / 20 assertions**; automatic-supply reconciliation passed **1 / 12 assertions**. PHP syntax and `git diff --check` passed on the feature head. A dedicated after-commit Delivery Trace recipient test remains absent. The amended commit was not retested after rebasing; no database was touched.

## Outstanding proof / risks

1. HR/Finance must configure the payroll-adjustment checker. Review remaining HR/discipline, finance escalation, system-admin fanout, and portal contacts before broadening recipients.
2. Seed one user per local role and department/site scope; run positive and negative recipient scenarios after approval.
3. Compare each badge to the destination list's total under same filter and authorization for every eligible role.
4. Verify channel authorization for unauthenticated, cross-user, and cross-scope cases.
5. Validate route links, legacy renderer, responsive/keyboard UI, and performance after implementation.
6. No test suite, browser/e2e session, or seeded-user scenario ran in discovery.

## Resume instruction

Continue Phase 3 from `02-policy-and-plan.md`, applying each remaining subject-access rule on its own PR, then finish approved badge signals. Keep unrelated defects in `FINDINGS.md` and separate PRs; update this file with verification and gaps.
