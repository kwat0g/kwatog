# Notification and badge remediation progress

**Updated:** 2026-10-25
**Current gate:** Phase 2 approved; Phase 3 foundation in progress on `notifications/fix-approval-outcome-access`.

## Phase status

| Phase | Status | Evidence |
| --- | --- | --- |
| 0. Discovery | Draft complete | `00-discovery.md`: 23 modules, 129 in-app service call sites, 27 direct `Mail::to` sites, scheduled trigger table, static route-target scan, 14 broadcast classes, 73 internal routes, 19 portal routes, nine compact-shell links, 29 badge definitions. Static source review plus aggregate-only local DB query. |
| 1. Inventory / leak analysis | Draft complete; runtime proof outstanding | `01-inventory.md`: source audience expressions, direct delivery paths, 17-role static selector-risk matrix, 77 email-failure wrapper call sites, payload/link/dedupe expressions, badge audit, root causes, module coverage. No seeded-role scenarios or badge/list comparisons run. |
| 2. Policy and plan | Approved | User approved the proposed defaults on 2026-10-25. |
| 3. Backend foundation | In progress | Units 1–3 have PRs; F-17 no-fallback fix is in PR #335; F-16 outcome access guards are in PR #336. Core approval notices resolve scoped recipients; inaccessible outcome recipients are suppressed across approval, budget, bill, invoice, and stock-adjustment paths; user-channel broadcasts carry IDs/types only and hydrate through an owner-scoped endpoint. Global authorization coverage, links, signal registry, and remaining event families are open. |
| 4. Frontend | Not started | Blocked by approved registry/link contracts. |
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
| F-03. Purchase-request badge/list scope | `notifications/fix-purchase-request-badge-scope` | [#348](https://github.com/kwat0g/kwatog/pull/348) | `6c25d82c` |
| F-04. Badge destination permission gates | `notifications/fix-badge-permission-gates` | pending | pending |
| F-02. Approval badge source scope | `notifications/fix-approval-badge-scope` | [#337](https://github.com/kwat0g/kwatog/pull/337) | `af68eb60` |

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

## Separate finding fix: F-04 badge destination permissions

- Payroll, complaints, and delivery badge gates now match the read permissions on their sidebar destinations: `payroll.periods.view`, `crm.complaints.view`, and `supply_chain.view OR supply_chain.deliveries.view`.
- `BadgeControllerTest.php`: **13 passed / 141 assertions**. Seeded roles verify employee `payroll.view` alone does not expose payroll-period counts, VP read-only complaint access and warehouse narrow delivery access both open their list and receive the corresponding badge, and HR period access receives the payroll badge.
- PHP syntax and `git diff --check` passed. Badge count/list record-scope parity remains F-09; this change verifies permission visibility only.

## Separate finding fix: F-02 approval badge scope

- `BadgeService.approvals` now reads the approval board's `summary.my_action`, so it inherits the board's supported type, permission, delegation, and source-row filtering. The test verifies badge/board count parity and excludes an out-of-department leave row plus an unsupported approval type.
- Targeted API tests: `BadgeControllerTest` selected tests **3 passed / 12 assertions**. The board summary previously capped at the requested page size (default 100); F-23 fixes the summary/badge total independently.
- Full BadgeControllerTest and all-role badge/list parity were not run. No frontend changes.

## Outstanding proof / risks

1. Review sensitive policy: payroll, HR/discipline, finance, executive escalation, system-admin fanout, portal contacts.
2. Seed one user per local role and department/site scope; run positive and negative recipient scenarios after approval.
3. Compare each badge to the destination list's total under same filter and authorization for every eligible role.
4. Verify channel authorization for unauthenticated, cross-user, and cross-scope cases.
5. Validate route links, legacy renderer, responsive/keyboard UI, and performance after implementation.
6. No test suite, browser/e2e session, or seeded-user scenario ran in discovery.

## Resume instruction

Continue Phase 3 from `02-policy-and-plan.md`, starting with remaining central-service work. Keep each unit on its own branch/PR and update this file with test evidence, remaining gaps, and findings.
