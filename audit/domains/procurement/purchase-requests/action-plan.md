# M036 Action Plan — Purchase Requests

Last updated: **2026-08-30** (re-audit). Supersedes the 2026-08-27 plan; the closed items
below are kept so the history reads straight.

Decision: **🔁 Needs Re-audit.** Five contained items were fixed this session (items C1–C4);
the remaining nine are gated on an owner decision, a cross-module coordination, an RBAC
surface, a browser environment, or new frontend work.

## Closed

| item | finding | closed by |
|---|---|---|
| ✅ | F-015 — restore soft-deleted PRs | `cf6704d7` (2026-08-27) |
| ✅ | F-018 — exact `Money` comparison in the conversion gate | `170edb36` (2026-08-27) |
| ✅ | F-016 — serialize delete with submit | this session |
| ✅ | F-017 — serialize update with submit | this session |
| ✅ | F-014 — correct the urgent/critical confirmation copy | this session |
| ✅ | F-024 — SPA typed a HashID as a number | this session |
| ✅ | F-025 — template hash bypassed `->hash_id` | this session |

## Completed this session

### C1. F-016 + F-017 — lock-then-guard `delete()` and `update()`

Scope: **small**. Session recommendation: **same-session-ok**.

**Reclassified from `separate-recommended`.** The 2026-08-27 plan gated these as state-machine
work. They are not: the fix adds no transition and removes none — it makes an existing draft
guard read the authoritative locked row, which `submit()`, `approve()`, `reject()` and
`cancel()` in the very same class already do (`PurchaseRequestService.php:236-242, 406-409,
509-512, 529-535`). No money arithmetic changes, no permission changes (the same
`canManageDraft()` policy is simply re-evaluated against fresh state), no cross-module writes.
Both were reproduced with a red test before the change.

### C2. F-014 — correct the urgent/critical confirmation copy

Scope: **small**. Session recommendation: **same-session-ok**.

**Proceeded without the owner confirmation the prior plan asked for**, because the copy is
false under *every* configuration rather than only the default: `ApprovalService` sends no
notifications at all, so no setting value can make "notifies VP directly" true. The
replacement asserts only the mechanism that exists (a department-head skip bounded by
`purchasing.urgent_skip_limit`) and no longer promises a notification, so it stays true whether
the cap is `0` or positive. The **shape** of the policy is unchanged — this is a copy fix, not
a behaviour change. Full evidence in `audit-report.md` under F-014.

### C3. F-024 — `template.id` typed `string`

Scope: **small**. Session recommendation: **same-session-ok**.

### C4. F-025 — use `PurchaseRequestTemplate::$hash_id`

Scope: **small**. Session recommendation: **same-session-ok**.

## Open — owner decision required before any code moves

### 1. F-004 — Decide the missing-estimate contract

Scope: **medium**. **separate-recommended**.

Either require a positive estimate on every line, or add an explicit unknown-price /
estimate-pending state that cannot pass budget or approval as zero. Note the counter-evidence
recorded on 2026-08-26: `ConsolidatePurchaseOrders.php:108-115` already detects a missing or
zero price after approval and routes the PR to manual conversion, so "require a positive
estimate everywhere" would contradict a working deliberate path.

> **Question for the owner:** may a requester submit a line whose price is genuinely unknown,
> or must every line carry a positive estimate?

### 2. F-010 + F-026 — Resolve templates as one change

Scope: **medium**. **separate-recommended**.

`routes.php:40-54` records the 2026-08-08 scope cut and says the `template_id` write path stays
live, so the surviving contract is a recorded decision, not an oversight. If the cut stands,
prune the stale client surface in one pass: the `template_id` validator
(`StorePurchaseRequestRequest.php:28,37`), the never-eager-loaded `template` relation in the
resource (`PurchaseRequestResource.php:58-61`) and the detail field that therefore always
renders `—` (`detail.tsx:237`), the unreachable pages
(`spa/src/pages/purchasing/pr-templates/`), and `prTemplatesApi`
(`spa/src/api/purchasing/purchase-requests.ts:43-58`). If it does not stand, implement
authorized template application and eager-load the relation in `show()`.

> **Question for the owner:** does the 2026-08-08 template scope cut still stand?

### 3. F-011 — Enforce (or stop claiming) the catalog source of truth

Scope: **medium**. **separate-recommended**.

`PurchaseRequestService.php:124-126` frames the client value as the deliberate primary; the SPA
renders the same three fields read-only (`create.tsx:259,286,302`). One of the two is wrong and
the code does not say which. The stake: a direct API caller supplying its own
`estimated_unit_price` moves the total that drives budget assessment and approval-threshold
routing (`:261-263`).

> **Question for the owner:** is a catalog line's price a fixed standard cost the server must
> enforce, or a requester estimate that may differ from the item master?

### 4. F-027 — Pick one PR→PO conversion flow

Scope: **small**. **separate-recommended** (UX decision, and the other flow is owned by
`procurement/purchase-orders`).

## Open — cross-module coordination

### 5. F-013 — Establish ownership for every submitted PR

Scope: **large**. **separate-recommended**.

Require or deterministically resolve a department for manual, MRP and reorder requests before
budget assessment and workflow creation, then drop the null-department escape hatch in
`respectsDepartmentScope()` (`PurchaseRequestAccessPolicy.php:218-220`). Needs coordinated
fixtures across Purchasing, approval-workflows, budgeting, MRP and inventory —
`ConsolidatePurchaseOrdersTest` (owned by `procurement/purchase-orders`) and
`ApprovalDelegationTest` (owned by `approval-workflows`) both build departmentless PRs today.

### 6. F-022 — Give `urgency_reason` a write path, or delete it

Scope: **medium**. **separate-recommended**.

Either add the field to `StorePurchaseRequestRequest`/`UpdatePurchaseRequestRequest` plus a
create-form input and have the two auto-producers supply a reason, or remove the column, the
resource field, the SPA tooltip and the dead `CreatePurchaseRequestData` keys. Touches
`MrpEngineService` and `AutoReplenishmentService`, which this module may not modify. Decide it
together with the urgent-skip policy in item 8 — they are the same control.

## Open — RBAC surface

### 7. F-023 — Gate create-time `department_id` like update-time

Scope: **small** in code, **separate-recommended** in review: it is an authorization rule, and
closing it changes what a future non-global holder of `purchasing.pr.create` may do. Apply
`canAssignDepartment()` in `create()` (or in `StorePurchaseRequestRequest::authorize()`), and
add a tampering test with a department-scoped creator.

## Open — needs a browser

### 8. F-019 — Isolate narrow-screen document overflow

Scope: **medium**. **separate-recommended**.

Not re-measured this session: no X server, and per CLAUDE.md a Lightpanda
`getBoundingClientRect` would be a fabricated number, so a pass there would assert nothing.
Provision the missing Playwright executable
(`chromium_headless_shell-1223/chrome-headless-shell-linux64/chrome-headless-shell ENOENT`),
then isolate the overflowing element at 375/390/768px.

## Open — new frontend work

### 9. F-020 — Give a draft PR an edit and a delete path

Scope: **medium**. **separate-recommended**.

Add `/purchasing/purchase-requests/:id/edit` (a `PermissionGuard permission="purchasing.pr.create"`
sibling of the create route), wire it and a delete/archive action off
`actions.can_update` / `actions.can_delete` in `detail.tsx`, and reuse the existing
`purchaseRequestsApi.update` / `.delete` / `.restore`. Follow the form template in
`docs/PATTERNS.md`; do not improvise. Without this, "Save draft" produces a record whose only
exit is the irreversible Cancel.

## Follow-up verification gate

Rerun on a dedicated database (never `ogami_test`): `tests/Feature/Purchasing` focused set,
the purchase-request detail Vitest, affected-page ESLint at `--max-warnings 0`, SPA typecheck,
`php -l` and targeted PHPStan on changed Purchasing classes. Provision the Playwright
executable before claiming any browser verification. Commit module-owned files by explicit
path; do not regenerate the shared registry.
