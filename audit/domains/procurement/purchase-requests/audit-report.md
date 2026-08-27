# M036 Audit Report — Purchase Requests

Audit date: 2026-08-27
Card: M036 / procurement / purchase-requests
Claim result: `CLAIMED` with `audit/scripts/claim-module.sh procurement purchase-requests`
Status at handoff: 📋 Plan Ready
Audit passes: discovery, hardening, polish

## Scope and evidence

Read the registry row and inherited audit workflow, domain and triage guidance, process flows, user manual, design system, QA matrix, implementation, routes, migrations, tests, current git history/diff, and relevant MRP, inventory, approval, employee, and finance dependencies. Dependencies were read-only. The coordinator registry was not regenerated or edited.

The module had no tracked source diff at the start of this session. Relevant source and audit-artifact mtimes were inspected; the current Purchasing service/policy were last changed on 2026-08-27, and the prior audit artifacts were from 2026-08-26. Unrelated active work under `audit/domains/platform/documents-exports/` was preserved and never staged.

## Verification

- API focused regression set, using only `DB_DATABASE=ogami_test_m036_roll_d`: **39 tests, 149 assertions, all passed**. The set covered purchase requests, hardening, approval workflow, template hash IDs, stale cancel guards, and PR-to-PO conversion.
- SPA focused checks passed: purchase-request detail Vitest (**2 tests**), affected-page ESLint with `--max-warnings 0`, and full SPA TypeScript typecheck.
- PHP syntax checks passed for the current Purchasing service, access policy, controller, and conversion listener. Targeted PHPStan reported `[OK] No errors`.
- A host-Chrome smoke probe with mocked API data loaded create and detail at 375px and detail at 1440px. The 375px create and detail pages reported `document.documentElement.scrollWidth > innerWidth`; desktop detail did not. The only console errors were Vite HMR websocket disconnects. Screenshots and the temporary harness were removed before handoff.
- The repository Playwright check remains blocked by the exact missing executable: `spawn /root/.cache/ms-playwright/chromium_headless_shell-1223/chrome-headless-shell-linux64/chrome-headless-shell ENOENT`. The Chrome DevTools browser was also unavailable because no X server was present. No visual-baseline comparison was claimed.

## Previously raised findings rechecked

These original classifications remain recorded for traceability; the first eight are resolved in the current source.

### F-001 — Department row authorization (originally Broken; resolved)

`PurchaseRequestService::list()` applies `visibleTo()` (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:41-70`), direct show/PDF routes call `canView()` (`api/app/Modules/Purchasing/Controllers/PurchaseRequestController.php:41-49,71-74`), and the policy distinguishes global, requester, departmental, and current-chain access (`api/app/Modules/Purchasing/Policies/PurchaseRequestAccessPolicy.php:40-90`). Focused hardening tests cover list/direct-show and pending-count scope (`api/tests/Feature/Purchasing/PurchaseRequestHardeningTest.php:36-89`).

### F-002 — PR money arithmetic (originally Broken; resolved with one residual polish item)

Request and line totals now use exact `Money` operations (`api/app/Modules/Purchasing/Models/PurchaseRequest.php:102-120`; `api/app/Modules/Purchasing/Models/PurchaseRequestItem.php:44-50`), and submit thresholds use decimal-string comparisons (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:259-298,337-345`). Centavo coverage passes (`api/tests/Feature/Purchasing/PurchaseRequestHardeningTest.php:91-104`). The conversion listener still has a float zero-check; that residual is F-018 below.

### F-003 — Generated-PR budget ownership (originally Broken; resolved for enforcement)

MRP and reorder producers still start generated rows without a department (`api/app/Modules/MRP/Services/MrpEngineService.php:332-342`; `api/app/Modules/Inventory/Services/AutoReplenishmentService.php:95-105`), but submit now resolves the requester employee department, persists it, or rejects an unowned generated PR before budget assessment and approval creation (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:244-263`). Both cases are covered by tests (`api/tests/Feature/Purchasing/PurchaseRequestHardeningTest.php:106-145`).

### F-005 — Concurrent/idempotent submit (originally Broken; resolved in implementation)

Submit now locks and re-checks the authoritative row before budget, supplier, or workflow work (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:227-266`). The retry regression confirms no second workflow record set is created (`api/tests/Feature/Purchasing/PurchaseRequestHardeningTest.php:147-166`). A true multi-process race test remains useful but is not treated as a separate defect here.

### F-006 — Priority/urgency alignment (originally Incomplete; server contract resolved)

Create and submit normalize urgent/critical priority into the legacy flag and workflow path (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:95-115,268-283`), and the regression test verifies the critical wire contract (`api/tests/Feature/Purchasing/PurchaseRequestHardeningTest.php:168-185`). The confirmation copy is now stale/overbroad; see F-014.

### F-007 — Preferred-supplier conversion data (originally Incomplete; resolved)

List and show eager-load `items.suggestedVendor` (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:41-49,73-85`), the resource exposes it when loaded (`api/app/Modules/Purchasing/Resources/PurchaseRequestItemResource.php:28-31`), and the API regression verifies the persisted vendor (`api/tests/Feature/Purchasing/PurchaseRequestHardeningTest.php:187-204`).

### F-008 — Approval queue/action presentation (originally Incomplete; resolved in current policy/resource)

The policy computes current-step, delegation, self-submission, department, and conversion decisions (`api/app/Modules/Purchasing/Policies/PurchaseRequestAccessPolicy.php:117-186`); the pending badge applies current rows and visibility (`api/app/Modules/Purchasing/Controllers/PurchaseRequestController.php:176-194`); detail actions are server-provided (`api/app/Modules/Purchasing/Resources/PurchaseRequestResource.php:112-117`). Role and department approval coverage passes in the focused set.

### F-009 — Automation reference integrity (originally Missing; resolved)

The hardening migration validates legacy orphans, adds indexes, and adds `ON DELETE SET NULL` foreign keys for both automation references (`api/database/migrations/2026_08_25_152000_harden_purchase_request_automation_fields.php:14-53`). The focused API suite boots against the dedicated database with the migration applied.

## Current findings

### F-004 — Manual lines can submit with an unknown or zero estimate

Classification: **Incomplete**
Severity: P1 if an estimate is required; otherwise P2
Recommendation: separate-recommended

The store and update contracts allow a nullable, zero-valued price (`api/app/Modules/Purchasing/Requests/StorePurchaseRequestRequest.php:33-47`; `api/app/Modules/Purchasing/Requests/UpdatePurchaseRequestRequest.php:31-44`). Null is converted to amount zero in the exact total (`api/app/Modules/Purchasing/Models/PurchaseRequest.php:110-116`), while automatic conversion rejects missing/zero prices only after approval (`api/app/Modules/Purchasing/Listeners/ConsolidatePurchaseOrders.php:95-113`). The owner must choose between requiring positive estimates and introducing an explicit manual-review/unknown-price state; the audit does not guess that product rule.

### F-010 — Template contract is live enough to mislead but not end-to-end

Classification: **Incomplete**
Severity: P2/P3
Recommendation: separate-recommended

Template routes are intentionally hidden while the comment says the `template_id` write path remains live (`api/app/Modules/Purchasing/routes.php:40-54`). The request still accepts and stores a template ID (`api/app/Modules/Purchasing/Requests/StorePurchaseRequestRequest.php:24-30,35-38`; `api/app/Modules/Purchasing/Services/PurchaseRequestService.php:100-105`), but creation does not load/apply template department or lines (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:120-160`). The resource declares a template relation (`api/app/Modules/Purchasing/Resources/PurchaseRequestResource.php:58-61`), but `show()` does not eager-load it (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:73-85`), so even the stored template is absent from the detail response. The current hash-ID test supplies lines manually and asserts only the FK (`api/tests/Feature/Purchasing/PurchaseRequestTemplateHashIdTest.php:40-69`). Remove the public contract or implement and authorize template application as one coordinated change.

### F-011 — Catalog-item financial fields remain client-authoritative

Classification: **Incomplete**  
Severity: P2  
Recommendation: separate-recommended

The SPA marks catalog description, unit, and standard cost read-only (`spa/src/pages/purchasing/purchase-requests/create.tsx:94-108,253-306`), but create and update accept client values whenever supplied and only fall back to the item master when omitted (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:141-153,197-212`). The resulting total feeds budget and approval routing (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:259-263`). Confirm whether these are editable estimates; if not, enforce the catalog source of truth on the API and test create/update tampering.

### F-013 — Manual departmentless PRs bypass budget and can stall approval

Classification: **Broken**
Severity: P1
Recommendation: separate-recommended

Manual creation permits a null department and defaults to a requester with no employee department (`api/app/Modules/Purchasing/Requests/StorePurchaseRequestRequest.php:36`; `api/app/Modules/Purchasing/Services/PurchaseRequestService.php:100-104`). Submit rejects missing ownership only for auto-generated rows (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:247-254`) and assesses budget only when a department is present (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:255-263`). The department-head scope deliberately returns true for a null department (`api/app/Modules/Purchasing/Policies/PurchaseRequestAccessPolicy.php:205-222`), but the visibility query has no department-head row to expose for such a pending request (`api/app/Modules/Purchasing/Policies/PurchaseRequestAccessPolicy.php:53-68`). A manual PR can therefore enter approval without budget enforcement and without an accountable department-head approver. Require or resolve ownership for every submission, with coordinated fixtures/tests for the approval and budget modules.

### F-014 — Critical-priority confirmation copy overstates the workflow

Classification: **Incomplete**  
Severity: P2  
Recommendation: same-session-ok after policy confirmation

The SPA tells the requester that critical priority “bypasses some approval steps and notifies VP directly” (`spa/src/pages/purchasing/purchase-requests/create.tsx:379-389`). The service only skips the first department-head record when the configured exact-money cap permits it, and retains the full chain above that cap (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:337-368`). The copy does not communicate the cap and the shown service path does not directly notify a VP. Align the text with the final urgent/critical policy.

### F-015 — Purchase-request restore route cannot bind soft-deleted rows

Classification: **Broken**
Severity: P2  
Recommendation: same-session-ok

`PurchaseRequest` uses `SoftDeletes` (`api/app/Modules/Purchasing/Models/PurchaseRequest.php:22-27`), and the controller has a restore action expecting the bound model (`api/app/Modules/Purchasing/Controllers/PurchaseRequestController.php:100-104`). The PR restore route lacks `withTrashed()` (`api/app/Modules/Purchasing/routes.php:28-33`), while the neighboring purchase-order restore route includes it (`api/app/Modules/Purchasing/routes.php:60-64`). The shared hash-id binder only includes trashed rows when a route opts in (`api/app/Common/Traits/HasHashId.php:23-55`), so the deleted PR is excluded before the controller can restore it. Add `withTrashed()` and a focused soft-delete/restore test.

### F-016 — Delete is not lock-then-guarded against submit

Classification: **Broken**
Severity: P2
Recommendation: separate-recommended

Delete authorizes and checks the caller's possibly stale model outside a transaction, then soft-deletes it (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:541-549`). Submit uses an authoritative row lock and status re-check (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:233-241`). If delete passes its draft check and submit commits first, the request can be soft-deleted after becoming pending, leaving approval records attached to a deleted pending PR. Make delete use the same lock-then-guard transition and add a stale-instance race test.

### F-017 — Update can rewrite a PR after submit wins the race

Classification: **Broken**
Severity: P2  
Recommendation: separate-recommended

Update checks draft status before its transaction and does not re-read/lock the authoritative row (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:164-176`). It then replaces line items inside that transaction (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:190-218`), while submit locks and transitions the same row (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:233-266`). A stale update can therefore modify line items after submit has created the approval amount. Add a lock/re-check and a concurrent update/submit regression test.

### F-018 — Conversion zero check still casts money to float

Classification: **Polish**
Severity: P3
Recommendation: separate-recommended

The conversion listener uses `(float) $line->estimated_unit_price <= 0` (`api/app/Modules/Purchasing/Listeners/ConsolidatePurchaseOrders.php:95-113`) even though PR totals and approval thresholds now use exact decimal strings. Replace the final positivity check with the repository `Money` comparison so the module has one money invariant; retain the existing manual-conversion behavior for null/zero values.

### F-019 — Narrow-screen pages still produce document-level horizontal overflow

Classification: **Polish**  
Severity: P3  
Recommendation: separate-recommended pending browser environment

The PR tables do have local overflow wrappers (`spa/src/pages/purchasing/purchase-requests/create.tsx:221-223`; `spa/src/pages/purchasing/purchase-requests/detail.tsx:243-245`), and the shared chain header also declares a local horizontal scroller (`spa/src/components/chain/ChainHeader.tsx:24-29`). Nevertheless, the focused host-Chrome probe reported document overflow at 375px on both PR create and detail, with the detail approval chain visibly clipped in the captured viewport. The Playwright binary blocker prevented the repository's normal visual suite. Isolate the overflowing element at phone/tablet widths, keep wide tables/steppers locally scrollable, and rerun the standard browser check.

## Handoff decision

The open plan contains more separate-recommended work than same-session work and is not a small isolated change set. No production files were changed. No fix-log entries were added. The correct release status is **📋 Plan Ready**.
