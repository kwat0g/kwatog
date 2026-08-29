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

---

# Re-audit — 2026-08-30

Card: M036 / procurement / purchase-requests (Tier 3)
Claim result: `CLAIMED` with `audit/scripts/claim-module.sh procurement purchase-requests`
Status at entry: 🔁 Needs Re-audit · Status released: 🔁 Needs Re-audit
Passes run: discovery, hardening, polish

## What was actually applied since the 2026-08-27 report

The working tree was **clean** at session start (`git status --short` empty, `git diff --stat`
empty), so nothing was pending-but-unlogged. Two commits landed against this module after
the previous report was written, both logged in `fix-log.md`:

| commit | date | finding |
|---|---|---|
| `cf6704d7` | 2026-08-27 | F-015 — restore soft-deleted purchase requests |
| `170edb36` | 2026-08-27 | F-018 — compare PR conversion prices exactly |

Both verified **resolved in current source**, not merely claimed:

- **F-015 closed.** `api/app/Modules/Purchasing/routes.php:31` now carries `->withTrashed()`
  on the PR restore route, matching the PO restore route at `:63`.
- **F-018 closed.** `api/app/Modules/Purchasing/Listeners/ConsolidatePurchaseOrders.php:108`
  now reads `Money::lte((string) $line->estimated_unit_price, Money::zero())`; the float cast
  is gone and the explicit `null` branch is retained.

Everything else in the 2026-08-27 plan is untouched. F-001/F-002/F-003/F-005/F-006/F-007/
F-008/F-009 were spot-checked and remain resolved (`PurchaseRequestAccessPolicy::visibleTo()`
at `:40-70`; `Money::add`/`Money::mul` totals at `PurchaseRequest.php:102-121`; generated-PR
ownership gate at `PurchaseRequestService.php:250-257`; submit lock at `:236-242`; urgency
normalisation at `:271-274`; `items.suggestedVendor` eager loads at `:47,79`; the automation-FK
migration `2026_08_25_152000_harden_purchase_request_automation_fields.php`).

## Verification performed this session

- Dedicated database `ogami_test_pr` (`CREATE DATABASE ogami_test_pr OWNER ogami`), never the
  shared `ogami_test`. Runner: `docker compose run --rm -e DB_DATABASE=ogami_test_pr api php artisan test …`.
  Runner sanity-checked on the pre-existing `PrCancelStaleGuardRaceTest`: 1 passed, 2 assertions.
- F-016 and F-017 were **measured, not inferred**: a new
  `api/tests/Feature/Purchasing/PrDraftMutationStaleGuardRaceTest.php` reproduces both, and
  before any source change it failed **2 failed (4 assertions)** with
  `update() accepted a stale draft instance after submit committed.` and the delete equivalent.
- Full evidence for the fixes applied is in `fix-log.md` under *Session 2026-08-30*.
- **Not verified this session:** F-019 (no browser run — no X server, and per CLAUDE.md a
  Lightpanda geometry assertion would be fabricated), and the 5 remaining role-facing SPA
  surfaces were read, not rendered. No visual-baseline claim is made.

## Findings that still reproduce from the prior report

### F-016 — Delete is not lock-then-guarded against submit — **Broken**, P2

Reproduced by test, then **fixed this session** (see fix-log). Before the fix,
`PurchaseRequestService::delete()` (`:541-550` at HEAD~) checked `status` on the caller's
possibly-stale instance outside any transaction, so a draft read before a concurrent
`submit()` committed was soft-deleted while `pending` — leaving `is_current` approval records
attached to a row no list query returns.

### F-017 — Update can rewrite a PR after submit wins the race — **Broken**, P2

Reproduced by test, then **fixed this session**. Before the fix, `update()` (`:164-219` at
HEAD~) guarded draft status pre-transaction and then replaced every line item inside it, so a
stale editor could change the lines *after* `submit()` had already fed
`totalEstimatedAmount()` into `BudgetEnforcementService::assess()` and the approval threshold
— the chain would be approving an amount the lines no longer produce.

### F-013 — Manual departmentless PRs bypass budget and can stall approval — **Broken**, P1

Still reproduces, unchanged. `create()` writes `$data['department_id'] ?? $by->employee?->department_id ?? null`
(`PurchaseRequestService.php:103`); `submit()` refuses a null department **only** for
`is_auto_generated` rows (`:250-254`) and calls `budget->assess()` only when one resolved
(`:261-263`); `PurchaseRequestAccessPolicy::respectsDepartmentScope()` deliberately waves a
null-department PR through (`:218-220`) because refusing would strand it with no eligible
step-1 approver. Both auto-producers still start at `department_id => null`
(`api/app/Modules/MRP/Services/MrpEngineService.php:333-341`;
`api/app/Modules/Inventory/Services/AutoReplenishmentService.php:95-103`).
**Deferred — large, cross-module.**

### F-004 / F-010 / F-011 — **Incomplete**, owner decisions

Unchanged and still blocked on the three questions already recorded in `fix-log.md`
("Deferred — itemised"). No new evidence changes any of them.

### F-014 — Critical-priority confirmation copy overstates the workflow — **Polish**

Still present at HEAD~ (`spa/src/pages/purchasing/purchase-requests/create.tsx:385-389`), and
this pass found the copy is **false under every configuration**, not merely overbroad — which
is why it was fixed this session without waiting for a policy answer:

1. `purchasing.urgent_skip_limit` ships as **`0`**
   (`api/database/seeders/SettingsSeeder.php:390`;
   `api/database/migrations/0306_seed_runtime_enforcement_policy_settings.php:8`), and
   `submitUrgent()` gates the skip on `Money::gt($limit, '0')`
   (`PurchaseRequestService.php:343-350`). Out of the box **no step is ever skipped**.
2. There is **no VP notification anywhere on the submit path.** `ApprovalService` sends no
   notifications at all (grep for `notification|notify|NotificationService` in
   `api/app/Common/Services/ApprovalService.php` returns nothing), so no setting value can
   make "notifies VP directly" true.
3. The seeded step the VP actually occupies is the one that gets **skipped** for small
   requests, not notified: step 4 is `system_admin` / label `VP` with
   `'threshold' => '50000.00'` (`api/database/seeders/WorkflowSeeder.php:57-65`), and
   `ApprovalService::submit()` skips a step whose threshold exceeds the amount.
4. The dialog showed the warning for `critical` only, while the service treats `urgent` and
   `critical` identically (`isUrgentPriority()`, `PurchaseRequestService.php:559-565`), so an
   `urgent` requester saw nothing.

### F-019 — Narrow-screen document-level overflow — **Polish**

Not re-measured (no browser environment). Local overflow wrappers are still present
(`create.tsx:221`, `detail.tsx:244`). **Deferred**, unchanged.

## New findings

### F-020 — A saved draft PR can never be edited or deleted from the UI — **Missing**, P1 for the requester journey

The server side is complete and, since F-015, even binds trashed rows. The client side does
not exist:

- No edit route. `spa/src/routes/purchasingRoutes.tsx:30-53` registers exactly
  `/purchasing/purchase-requests`, `…/create`, `…/:id` — no `…/:id/edit`.
- `spa/src/pages/purchasing/purchase-requests/detail.tsx:106-128` renders Submit, Reject,
  Approve, Convert, PDF and Cancel, and reads `actions.can_submit`, `can_cancel`,
  `can_approve`, `can_reject`, `can_convert`, `can_print`, `can_acknowledge_budget`. It never
  reads `actions.can_update` or `actions.can_delete`, which the policy computes and the
  resource ships (`PurchaseRequestAccessPolicy.php:171-187`;
  `PurchaseRequestResource.php:115-117`). `grep -rn 'can_update\|can_delete' spa/src/pages/purchasing spa/src/components` returns nothing.
- `purchaseRequestsApi.update`, `.delete` and `.restore`
  (`spa/src/api/purchasing/purchase-requests.ts:14-17`) are called from nowhere —
  `grep -rn 'purchaseRequestsApi\.\(update\|delete\|restore\)' spa/src spa/e2e` returns no hits.

Consequence: `create.tsx:354-361` offers "Save draft", and that draft is then a dead end. The
only lifecycle action the requester is given is Cancel, which the dialog itself calls
irreversible — "Cancellation is permanent. A cancelled PR cannot be re-submitted."
(`detail.tsx:329`). To correct a typo the requester must abandon the PR number and start over.
**Deferred — medium frontend work (a new edit page + route + detail actions).**

### F-022 — `urgency_reason` has no write path at all — **Incomplete**, P2

The column is fillable (`PurchaseRequest.php:38`), read for the audit trail
(`PurchaseRequestService.php:359` stamps it into the skipped step's remarks), exposed by the
resource (`PurchaseRequestResource.php:38`), rendered as the tooltip on the urgent icon
(`detail.tsx:235`), and advertised in the client payload type
(`spa/src/types/purchasing.ts:114`, `CreatePurchaseRequestData.urgency_reason?: string`).

Nothing can write it:
- absent from `StorePurchaseRequestRequest::rules()` (`:33-49`) and
  `UpdatePurchaseRequestRequest::rules()` (`:31-46`), so `validated()` drops it before
  `create()`/`update()` ever see `$data['urgency_reason']` (`PurchaseRequestService.php:115`);
- the create form has no urgency-reason field at all (`create.tsx:169-194`);
- both auto-producers leave it null (`MrpEngineService.php:333-341`,
  `AutoReplenishmentService.php:95-103`).

So every urgent PR carries an empty justification, and the skipped-step remark degrades to the
bare `'Skipped — urgent PR escalation'`. That reason is the stated purpose of the cap the
docblock introduces — "A high-value 'urgent' PR can no longer bypass its department head with
only a free-text reason" (`PurchaseRequestService.php:330-336`) — yet the free-text reason
cannot be captured. `CreatePurchaseRequestData.is_urgent` (`spa/src/types/purchasing.ts:113`)
is dead for the same reason.
**Deferred — touches the request contract and two other modules' producers.**

### F-023 — Create-time `department_id` has no authorization check; update-time does — **Incomplete**, P2 (latent)

`update()` gates the field: `canAssignDepartment()` requires the caller be global or assigning
to its own department (`PurchaseRequestService.php:169-172`;
`PurchaseRequestAccessPolicy.php:107-115`). `create()` writes `$data['department_id']`
verbatim (`:103`) behind only `['nullable','integer','exists:departments,id']`
(`StorePurchaseRequestRequest.php:36`).

Not currently exploitable: the only seeded holders of `purchasing.pr.create` are `system_admin`
(`permissions => '*'`, `RolePermissionSeeder.php:470-473`) and `purchasing_officer`
(`$this->module('purchasing', …)`, `:633-637`), and both satisfy `isGlobal()`
(`PurchaseRequestAccessPolicy.php:291-296`). But `department_id` is what selects the budget
assessed at submit (`PurchaseRequestService.php:261-263`), so the asymmetry becomes a
budget-routing hole the first time `purchasing.pr.create` is granted to a department-scoped
role — and `department_head` is already listed as one of this module's roles in
`inventory.md:5`. **Deferred — permissions/RBAC surface.**

### F-024 — SPA types a HashID as a number — **Polish**

`spa/src/types/purchasing.ts:80` declared `template: { id: number; name: string } | null` while
the resource returns an encoded string (`PurchaseRequestResource.php:59`). CLAUDE.md: "`id` is
always a `string` (a HashID), never a number." **Fixed this session.**

### F-025 — Template hash bypasses the model's own accessor — **Polish**

`PurchaseRequestResource.php:59` called `app('hashids')->encode((int) $this->template->id)`
where every other id in the same resource uses `->hash_id`. `PurchaseRequestTemplate` already
uses `HasHashId` (`PurchaseRequestTemplate.php:17`), so the accessor exists.
**Fixed this session.**

### F-026 — Unreachable PR-template pages calling routes that do not exist — **Missing** (dead surface), P3

`spa/src/pages/purchasing/pr-templates/index.tsx` and `create.tsx` are live components calling
`prTemplatesApi` (`spa/src/api/purchasing/purchase-requests.ts:43-58`), but no route registers
them — `purchasingRoutes.tsx:15` is only the comment "ADV6 — PR Templates (pages hidden
2026-08-08, scope cut — files kept)" — and every `/purchasing/pr-templates*` API route is
commented out (`api/app/Modules/Purchasing/routes.php:40-54`). Unreachable pages whose every
call would 404. This is the SPA half of F-010 and should be resolved with it, not separately.
**Deferred.**

### F-027 — Two different conversion UX for one action — **Polish**, P3

`index.tsx:172-186` opens a vendor-map modal and POSTs `/purchase-requests/{id}/convert`;
`detail.tsx:120-122` instead navigates to `/purchasing/purchase-orders/create?pr_id=…`. Same
verb, same permission (`purchasing.po.create`), two flows. **Deferred — UX decision.**

## Observations (not classified as findings)

- `bulkApprove()` catches `RuntimeException` per row (`PurchaseRequestService.php:493`), which
  is deliberate and documented on `ForbiddenActionException`. It also catches
  `ModelNotFoundException` (a `RuntimeException` via `RecordsNotFoundException`), so an unknown
  id is reported to the user as `skipped` with a framework-internal sentence rather than "no
  such request". Cosmetic.
- **Question, unverified:** `list()` consumes `$request->query()` with no FormRequest
  (`PurchaseRequestController.php:47-50`), and `$filters['status']` goes straight into
  `where('status', $filters['status'])` (`PurchaseRequestService.php:53`). An array-valued
  `?status[]=x` would reach the driver as an array binding. This was **not measured** — no
  HTTP probe was run for it — and it may well be a repo-wide list convention rather than a
  module defect. Flagged for the coordinator rather than asserted.

## Handoff decision

Two Broken findings (F-016, F-017) were reproduced with a red test and fixed, because the
lock-then-guard shape is already applied three times in the same class and the change adds no
transition — see the reclassification justification in `action-plan.md`. F-014 was fixed
because the copy is false under every configuration, so no policy answer is needed to remove
it. F-024/F-025 are one-liners.

Everything else is gated on an owner decision (F-004/F-010/F-011/F-026/F-027), a cross-module
coordination (F-013/F-022), an RBAC surface (F-023), a browser environment (F-019), or new
frontend work (F-020). Released **🔁 Needs Re-audit**.
