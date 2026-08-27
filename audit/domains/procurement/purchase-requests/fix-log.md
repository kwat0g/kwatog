# M036 Fix Log

Session: 2026-08-25 (audit + fixes), resumed 2026-08-26 (runtime verification)

## Session 2026-08-27 — M036-F018

### F-018 — Use exact Money comparison for conversion positivity gate

- Scope: replace only the conversion listener's float cast in the estimated-unit-price positivity check; preserve manual-conversion fallback for null/zero values.
- Regression: add focused Purchasing coverage for decimal/cent-boundary positive estimates and null/zero fallback.
- Fix: `ConsolidatePurchaseOrders` now uses `Money::lte((string) $line->estimated_unit_price, Money::zero())` for the non-positive gate; the explicit null branch remains unchanged.
- Regression: `ConsolidatePurchaseOrdersTest` covers the smallest positive cent (`0.01`) auto-conversion and preserves manual-required fallback for both null and `0.00` prices.
- Verification: the focused conversion suite passed in the dedicated `ogami_test_m036_f018` database — 15 tests, 48 assertions.
- Scope guard: F-014, F-019, and all concurrency/policy findings were not changed; remaining findings stay open and release will be **🔁 Needs Re-audit**.

## Session 2026-08-27 — M036-F015

### F-015 — Restore soft-deleted purchase requests through hash routes

- Before: the purchase-request restore route did not opt into soft-deleted model binding, so a deleted PR's hash resolved to a 404 before `PurchaseRequestController::restore()` could clear `deleted_at`.
- Fix: added `->withTrashed()` to the purchase-request restore route, matching the neighboring purchase-order restore route (`api/app/Modules/Purchasing/routes.php`).
- Regression: `PurchaseRequestTest::test_deleted_purchase_request_can_be_restored_through_hash_route` archives a draft PR through `DELETE`, asserts it is soft-deleted, restores it through the hash `PATCH .../restore` route, and asserts `deleted_at` is cleared (`api/tests/Feature/Purchasing/PurchaseRequestTest.php`).
- Red/green evidence: the new regression first failed with HTTP 404, then passed with 7 assertions using `DB_DATABASE=ogami_test_m036_impl`.
- Scope guard: F-014, F-018, and all concurrency/policy actions were not changed.

## Session 2026-08-26 — resumed after crash

The 2026-08-25 session applied items 1–9 below but could never run a test
(PostgreSQL host `db` did not resolve), so every fix was source-verified only.
This session ran them against a dedicated database (`ogami_test_s3`) and found
one of them was over-refusing. Nothing else in the inherited work regressed.

### 10. F-001 follow-up — the new row policy denied the plant-wide chain approvers

Classification: **Broken** (introduced by fix 1). Severity: P1.

`PurchaseRequestAccessPolicy::canApprove()` gated step authority on
`canView()`. `canView()` grants rows to system_admin/purchasing_officer
(company-wide), a department head inside its own department, and the requester —
nobody else. The seeded `purchase_request` chain
(`api/database/seeders/WorkflowSeeder.php:53-65`) is
`department_head → production_manager → purchasing_officer → system_admin`, so
`production_manager` at step 2 held no scope to be visible through and could
never approve. A submitted PR was stuck at step 2 forever.

- Before — `api/app/Modules/Purchasing/Policies/PurchaseRequestAccessPolicy.php:88-101`:
  ```php
  if (! $user->hasPermission('purchasing.pr.approve')
      || $pr->status !== PurchaseRequestStatus::Pending
      || ! $this->canView($user, $pr)          // ← denied every plant-wide step role
      || (int) $pr->requested_by === (int) $user->id) {
      return false;
  }
  $next = $this->nextPendingRecord($pr);
  return $next !== null && in_array($next->role_slug, $this->approvalRoleSlugs($user), true);
  ```
- After — `PurchaseRequestAccessPolicy.php:112-121` + `mayActOnCurrentStep()` at `:198-228`:
  the permission / status / self-submission guards stay, and step authority is
  decided by `mayActOnCurrentStep()`, which adds to `ApprovalService`'s central
  step-role check exactly one rule that service cannot know — the department
  scope, and only on the `department_head` step
  (`DEPARTMENTAL_STEP_ROLE`, `:31`). This is narrower than the seeder intent it
  restores, not wider: a foreign-department head is still refused at step 1.
- `canView()` (`:78-98`) and `visibleTo()` (`:38-72`) now also admit a chain
  participant, so an approver can open and find the row it must act on.
  `approvalRecords` is already constrained to `is_current` and exists only after
  submit, so no draft leaks. The department_head slug is deliberately excluded
  from that branch (`plantWideStepRoles()`, `:240-250`) — otherwise every head
  would see every other department's submitted PRs.

### 11. F-001 follow-up — `production_manager` held no purchasing permission at all

Classification: **Broken** (pre-existing, not from fix 1). Severity: P1.

Even with the policy corrected, the HTTP path stayed dead: the approve route is
`permission:purchasing.pr.approve` (`api/app/Modules/Purchasing/routes.php:35`)
and show is `permission:purchasing.view` (`:25`), and `production_manager` —
step 2 of the chain — held neither.

Evidence the grant was intended, not a loosening:
1. `WorkflowSeeder.php:53-65` names `production_manager` as step 2 of
   `purchase_request`; a step no user can satisfy is a stall, not a control.
2. CLAUDE.md's documented chain is Staff → Dept Head → **Manager** → Officer →
   VP, and `production_manager` is the seeder's "Manager".
3. The seeder already records this exact defect and this exact fix for another
   workflow — L-37 at `RolePermissionSeeder.php:446-450`: the `return_request`
   chain routed to `department_head` then `production_manager`, "but neither
   role holds `manage`, so the approve route rejected the only users the chain
   would accept and every submitted RMA stalled in pending_approval."
4. `ApprovalWorkflowTest::test_full_approval_chain_marks_entity_approved`
   (predates the audit, mtime 2026-08-20) asserts `production_manager` clears
   step 2 — the repo's own stated expectation.

- Before — `api/database/seeders/RolePermissionSeeder.php` `production_manager`
  permissions: no `purchasing.*` slug.
- After — `RolePermissionSeeder.php:583-591`: added `purchasing.view` and
  `purchasing.pr.approve` only. Deliberately NOT `purchasing.pr.create` or any
  `purchasing.po.*`: raising and converting a PR stays purchasing's, so the
  maker/checker split is preserved.

### 12. Fixture correction — the chain test never exercised its own rule

`ApprovalWorkflowTest::makeUser()` built users with no employee, so the PR
carried `department_id = null` and the department scope was vacuous.

- Before — `api/tests/Feature/Purchasing/ApprovalWorkflowTest.php:31-41`: bare
  `User::create()`, no `employee_id`.
- After — `ApprovalWorkflowTest.php:22-49`: one `Department` per test, every
  actor backed by an `Employee` in it. Step 1 now runs the real departmental
  comparison rather than the null-department fallback.

### 13. Flaky scope assertion — hash ids are not model-scoped

`test_department_head_rows_are_scoped_on_list_and_direct_show` passed under
`--filter` and failed in a full-directory run (~1 in 1 ordering). It was not an
authorization leak: the response carried `meta.total = 1` and the one correct
row. `hashids->encode()` salts nothing per model, so PR #N and user #N encode to
the same string; when the id sequences lined up, the hidden PR's hash equalled
the visible PR's nested `requester.id`, and `assertJsonMissing(['id' => …])`
matches any nested object with that key.

- Before — `api/tests/Feature/Purchasing/PurchaseRequestHardeningTest.php:51-55`:
  `->assertJsonFragment(['id' => $visible->hash_id])->assertJsonMissing(['id' => $hidden->hash_id])`
- After — `PurchaseRequestHardeningTest.php:51-61`: asserts the collection's own
  ids exactly — `assertSame([$visible->hash_id], $response->json('data.*.id'))`.

Observation, not a defect: a hash id identifies a row number, not a model. Route
binding decodes then queries one model, so nothing leaks across tables — but any
`assertJsonMissing(['id' => …])` anywhere in the suite is unsound for the same
reason and will flake on id alignment.

### 14. New regression coverage

`api/tests/Feature/Purchasing/PurchaseRequestHardeningTest.php:203-233` —
drift guard: every role the seeded `purchase_request` chain names must hold both
`purchasing.pr.approve` and `purchasing.view`, i.e. must be able to reach the
routes its own step needs. This is the check that would have caught both L-37
and defect 11 above.

`:235-267` — a `production_manager` from a *different* department can open and
approve step 2 over HTTP (plant-wide step is not department-scoped).

`:269-282` — a department head from another department is refused at step 1
(the departmental scope still holds; the fix is not a loosening).

## Deferred — itemised

### F-013 (new) — a PR can still reach Pending with no owning department

The 2026-08-25 fix 3 closed the budget-gate bypass only for
`is_auto_generated` rows. A **manual** PR whose creator has no employee record
still submits with `department_id = null`, and
`PurchaseRequestService.php:261` only calls `budget->assess()` when a department
resolved — so that PR skips budget enforcement entirely, exactly the F-003
defect on the manual path. It also has no meaningful step-1 approver, which is
why `mayActOnCurrentStep()` has to wave a departmentless PR through.

The correct fix is to require an owning department on **every** submission, not
just generated ones. **Not done here** because it is not local to this module:
it changes fixtures in `api/tests/Feature/Purchasing/ConsolidatePurchaseOrdersTest.php`
(owned by `procurement/purchase-orders`) and
`api/tests/Feature/Approvals/ApprovalDelegationTest.php` (owned by
`approval-workflows`), plus `PurchaseRequestTest` and `PrCancelStaleGuardRaceTest`.
This session may not modify another module's files. Needs a coordinated
cross-module session.

### F-004 — missing/zero estimates: still a product decision

Not changed. New evidence that the null case is **deliberate**, which the
original finding did not weigh: `ConsolidatePurchaseOrders.php:95-114` already
detects a missing/zero unit price after approval and routes the PR to manual
conversion rather than failing. A downstream mechanism exists specifically to
handle it, so "require a positive estimate on every line" would contradict a
working deliberate path. If the decision is taken, the "explicit
estimate-pending state" option is the one consistent with existing behaviour.

**Question for the owner:** may a requester submit a line whose price is
genuinely unknown, or must every line carry a positive estimate?

### F-010 — templates: likely intentional, not incomplete

Not changed, and the classification should probably be downgraded.
`api/app/Modules/Purchasing/routes.php:40-54` documents the present state as a
deliberate 2026-08-08 scope cut and says explicitly that "the `template_id`
write path in PurchaseRequestService stays live". So the live-but-unapplied
`template_id` is a recorded decision, not an oversight. Removing it would
reverse a documented choice.

**Question for the owner:** confirm the scope cut still stands; if so F-010 is
closed as intentional and only the stale SPA client contract needs pruning.

### F-011 — catalog source of truth: still a product decision

Not changed. The audit's concern is real — a direct API caller can supply its own
`estimated_unit_price` on a catalog line
(`PurchaseRequestService.php:150-153`), and that total drives budget assessment
and approval-threshold routing, so it is an approval-routing manipulation
vector. But the code states the opposite intent from the SPA:
`PurchaseRequestService.php:124-126` — "Catalog lines inherit description / unit
/ price from the Item record **when the client didn't supply them**" — frames the
client value as the deliberate primary. The SPA renders the same fields
read-only. One of the two is wrong and the code does not say which.

**Question for the owner:** is a catalog line's price a fixed standard cost
(server must ignore the client value) or a requester estimate that may differ
from the item master (the SPA should stop showing it as read-only)?

## Implemented (session 2026-08-25 — now runtime-verified)

1. **F-001 — Department/ownership row authorization (fixed in code; runtime verification pending).**
   - Before: `PurchaseRequestService::list()` treated the approve permission as company-wide visibility, and direct row routes had no department/ownership guard.
   - After: added `PurchaseRequestAccessPolicy::visibleTo()` and action checks for department heads, request owners, purchasing officers, and system administrators (`api/app/Modules/Purchasing/Policies/PurchaseRequestAccessPolicy.php:26-123`); wired list, show, PDF, update/delete/restore, submit/cancel, approval, budget acknowledgement, and conversion boundaries (`api/app/Modules/Purchasing/Controllers/PurchaseRequestController.php:41-205`; `api/app/Modules/Purchasing/Services/PurchaseRequestService.php:41-70,154-240,390-515`).
   - Added scoped pending-count filtering for department, self-submission, current-step, and active delegation (`api/app/Modules/Purchasing/Controllers/PurchaseRequestController.php:176-194`).

2. **F-002 — Exact PR money arithmetic (fixed in code; runtime verification pending).**
   - Before: request totals, line totals, and PR approval/urgent thresholds crossed through float casts.
   - After: totals use `Money::mul()`/`Money::add()` and thresholds use canonical decimal strings with `Money` comparisons (`api/app/Modules/Purchasing/Models/PurchaseRequest.php:102-120`; `api/app/Modules/Purchasing/Models/PurchaseRequestItem.php:44-50`; `api/app/Modules/Purchasing/Services/PurchaseRequestService.php:259-297,333-345,533-545`).

3. **F-003 — Generated-PR budget ownership (fixed in code; runtime verification pending).**
   - Before: generated PRs with `department_id = null` skipped budget assessment.
   - After: submit resolves the requester’s employee department, persists it, and refuses an unowned generated PR before approval records are created (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:236-263`). The update request now accepts a hash-resolved department for an authorized draft operator (`api/app/Modules/Purchasing/Requests/UpdatePurchaseRequestRequest.php:23-45`).

4. **F-005 — Concurrent/idempotent submit (fixed in code; runtime verification pending).**
   - Before: the draft guard ran before the transaction and no authoritative row lock/re-check existed.
   - After: submit locks and re-checks the PR inside the transaction before budget, supplier prefill, or approval workflow creation (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:225-241`).

5. **F-006 — Priority/urgency alignment (fixed in code; runtime verification pending).**
   - Before: the public `priority` field and automation labels did not drive the `is_urgent` workflow branch.
   - After: priority `urgent`/`critical` is the public and automation-facing urgency signal; create/update normalize the legacy flag, submit normalizes legacy rows, and the resource reports the effective urgency (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:95-115,175-187,267-285`; `api/app/Modules/Purchasing/Resources/PurchaseRequestResource.php:14-38`).

6. **F-007 — Preferred-supplier conversion data (fixed in code; runtime verification pending).**
   - Before: list/show/PDF did not eager-load `items.suggestedVendor`, so the conversion UI could not receive the persisted default.
   - After: eager-loaded the relation in list/show/PDF (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:43-49,75-84`; `api/app/Modules/Purchasing/Services/PurchaseRequestPdfService.php:26-32`).

7. **F-008 — Approval queue/action presentation (fixed in code; runtime verification pending).**
   - Before: pending counts and detail buttons were based on coarse permission checks rather than the current step, department, delegation, and self-approval rules.
   - After: the server exposes a detail-level action model (`api/app/Modules/Purchasing/Policies/PurchaseRequestAccessPolicy.php:125-173`; `api/app/Modules/Purchasing/Resources/PurchaseRequestResource.php:112-117`), and the detail page gates submit, approve/reject, convert, PDF, cancel, and budget acknowledgement from that model (`spa/src/pages/purchasing/purchase-requests/detail.tsx:111-127`).

8. **F-009 — Automation reference integrity (fixed in code; migration verification pending).**
   - Before: `template_id` and `suggested_vendor_id` were nullable bare integers without indexes or foreign keys.
   - After: added a migration that fails fast on orphaned legacy references, then adds indexes and `nullOnDelete()` foreign keys (`api/database/migrations/2026_08_25_152000_harden_purchase_request_automation_fields.php:12-67`).

9. **F-012 — Narrow-screen line-item tables (fixed in code).**
   - Before: create/detail tables could force page-level horizontal scrolling.
   - After: wrapped both tables in the design-system overflow container (`spa/src/pages/purchasing/purchase-requests/create.tsx:221-343`; `spa/src/pages/purchasing/purchase-requests/detail.tsx:243-270`).

## Deferred pending decisions (2026-08-25 — superseded by "Deferred — itemised" above)

- **F-004 — Missing estimates:** not changed. The existing plan requires a product decision between requiring positive estimates and introducing an explicit manual-review state; treating null/zero as either valid or invalid would guess at that business rule.
- **F-010 — Templates:** not changed. The existing plan requires a product decision to remove the live `template_id` contract or implement template application end-to-end.
- **F-011 — Catalog source of truth:** not changed. The existing plan requires confirmation whether catalog description/unit/standard cost may be overridden by requesters.

## Verification

### Session 2026-08-26 (runtime, dedicated database `ogami_test_s3`)

- `tests/Feature/Purchasing` in full: **114 passed (401 assertions)**, 0 failed.
  This includes `ConsolidatePurchaseOrdersTest`, which the coordinator listed as
  a known failure — it passes on a dedicated database, so that failure was
  shared-`ogami_test` contamination, not a defect.
- Baseline before the fix, same command scope:
  `ApprovalWorkflowTest` 2 failed / 3 passed —
  `ForbiddenActionException: You are not authorized to approve this purchase
  request.` at `PurchaseRequestService.php:411`, on
  `test_full_approval_chain_marks_entity_approved` and
  `test_reject_stops_workflow`. `PurchaseRequestHardeningTest` was already
  8/8 green, so the coordinator's attribution of a third failure to that file
  was wrong.
- Permission-surface regression set, all green:
  `RoleResponsibilityAlignmentTest`, `ApprovalDelegationTest`,
  `ApprovalDelegationAuthorityTest`, `ApprovalAutoResolveTest`,
  `DashboardDispatchTest`, `DashboardPanelGateTest`, `WidgetSeedIntegrityTest`,
  `GlobalSearchTest`, `ChainDefinitionsTest`, the Budget* suites —
  63 + 50 + 49 passed across the three batches.
- PHPStan `[OK] No errors` on `PurchaseRequestAccessPolicy.php`,
  `RolePermissionSeeder.php`, and both changed test files.
- **F-009 migration verified against the live schema** (was source-only before):
  `purchase_requests.template_id` and
  `purchase_request_items.suggested_vendor_id` are both bigint with a btree
  index and an `ON DELETE SET NULL` foreign key.
- **F-002/F-003/F-005/F-006/F-007 are now runtime-verified** by the green
  `PurchaseRequestHardeningTest` (centavo totals, generated-PR ownership, submit
  idempotency, urgency contract, persisted suggested vendor).
- **F-012 not visually verified.** The overflow wrapper is present in the
  source, but no browser check at narrow widths was run this session. Per
  CLAUDE.md this needs Chromium (Lightpanda computes no layout, so a geometry
  assertion there would be fabricated).

#### Out-of-scope failure observed — for the coordinator to route

`Tests\Feature\Dashboard\NewDomainAnalyticsTest > budget gauge reports a
percentage once allocated` fails with `ErrorException: Undefined array key
"value"` at `NewDomainAnalyticsTest.php:105`. Not caused by this module: the
test seeds a **2031** fiscal year (`NewDomainAnalyticsTest.php:78-84`) while
`FiscalYear::scopeCurrent()` (`api/app/Modules/Accounting/Models/FiscalYear.php:45-50`)
requires `start_date <= today() <= end_date`, so the year is never current,
`BudgetWidgetAnalytics::payload()` returns `[]`, and the assertion reads a
missing key. A dormant time-bomb that fails on every date outside 2031. Belongs
to the dashboard/budgeting module; left untouched.

### Session 2026-08-25 (source only — no database was reachable)

- Passed PHP syntax checks for all changed PHP files.
- Passed targeted PHPStan for the changed Purchasing classes: `[OK] No errors`.
- Passed targeted SPA ESLint for the changed PR pages/types.
- Added focused regression coverage in `api/tests/Feature/Purchasing/PurchaseRequestHardeningTest.php:34-224` for row scope, pending-count scope, centavo totals, generated ownership, submit idempotency, urgency, and preferred vendors.
- Focused PHP tests could not run because PostgreSQL host `db` does not resolve (`SQLSTATE[08006]`).
- Focused Vitest could not start because the existing root-owned `spa/node_modules/.vite-temp` directory denies writes (`EACCES`). The full SPA typecheck was stopped after several minutes without output; no affected-file parser errors remained, and targeted ESLint passed.
