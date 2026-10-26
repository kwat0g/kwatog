# Notification and badge remediation progress

**Updated:** 2026-10-26
**Current gate:** Phase 2 approved; Phase 3 foundation in progress on `notifications/phase3-central-contract`.

## Phase status

| Phase | Status | Evidence |
| --- | --- | --- |
| 0. Discovery | Draft complete | `00-discovery.md`: 23 modules, 129 in-app service call sites, 27 direct `Mail::to` sites, scheduled trigger table, static route-target scan, 14 broadcast classes, 73 internal routes, 19 portal routes, nine compact-shell links, 29 badge definitions. Static source review plus aggregate-only local DB query. |
| 1. Inventory / leak analysis | Draft complete; runtime proof outstanding | `01-inventory.md`: source audience expressions, direct delivery paths, 17-role static selector-risk matrix, 77 email-failure wrapper call sites, payload/link/dedupe expressions, badge audit, root causes, module coverage. No seeded-role scenarios or badge/list comparisons run. |
| 2. Policy and plan | Approved | User approved the proposed defaults on 2026-10-25. |
| 3. Backend foundation | In progress | Unit 1 adds an additive versioned payload contract; remaining service, resolver, authorization, link, Reverb, and signal work is not started. |
| 4. Frontend | Not started | Blocked by approved registry/link contracts. |
| 5. Migration | Not started | No event or badge migrated. |
| 6. Extras | Not started | No extra features started. |
| 7. Verification | Partial | Targeted Phase 3 unit 1 tests ran; role-leak, badge parity, frontend interaction, and full-suite verification remain open. Both Playwright browser jobs were canceled at the 15-minute limit while apt downloaded system dependencies; `ci/playwright-browser-install` removes that apt install and awaits verification. |

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

## Current confirmed findings

See `FINDINGS.md`. Highest priority static mismatches: approval and PR badge scopes, profile badge attached to wrong route, permission-gate mismatches, missing metadata for three emitted event patterns, notification links/UI behavior, and configured-role audiences without record scope. No public channel or all-users notification dispatch found. No runtime cross-role leak has been proven.

## Phase 3 unit 1 verification

- API: `NotificationServiceHardeningTest` **21 passed / 58 assertions**; `NotificationServiceTest` **8 passed / 26 assertions**; `PortalNotificationsTest` **7 passed / 33 assertions**; `NotificationCatalogTest` **8 passed / 54 assertions**.
- PHP syntax checks passed for every changed PHP file; `git diff --check` passed.
- Pint passed on the three changed PHP files.
- SPA `npm run typecheck` failed on files outside this unit: `src/api/exports.test.ts`, `src/components/ui/ExportButton.tsx`, `src/pages/admin/users/invites.tsx`, `src/pages/auth/accept-invite.tsx`, `src/pages/error/Forbidden.tsx`, `src/pages/inventory/stock-transfers/create.tsx`, and `src/pages/purchasing/purchase-orders/create.tsx`. No error named a changed notification file.
- No route/template registry, message rendering changes, recipient resolver, authorization gate, Reverb hardening, or badge code shipped in unit 1. Those remain open units.

## Outstanding proof / risks

1. Review sensitive policy: payroll, HR/discipline, finance, executive escalation, system-admin fanout, portal contacts.
2. Seed one user per local role and department/site scope; run positive and negative recipient scenarios after approval.
3. Compare each badge to the destination list's total under same filter and authorization for every eligible role.
4. Verify channel authorization for unauthenticated, cross-user, and cross-scope cases.
5. Validate route links, legacy renderer, responsive/keyboard UI, and performance after implementation.
6. No test suite, browser/e2e session, or seeded-user scenario ran in discovery.

## Resume instruction

Continue Phase 3 from `02-policy-and-plan.md`, starting with remaining central-service work. Keep each unit on its own branch/PR and update this file with test evidence, remaining gaps, and findings.
