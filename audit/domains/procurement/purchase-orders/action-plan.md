# M037 — Purchase Orders Action Plan

Session: 2026-08-25  
Status: `🔁 Needs Re-audit`  
Plan posture: resumed an existing `📋 Plan Ready` plan after a required
current-state re-audit. The separate-session labels below do not defer work
again; they describe the risk and the required owner/verification boundary.

## Ordered fix items

| Order | Finding(s) | Fix item | Scope | Session recommendation | Current disposition / acceptance evidence |
|---:|---|---|---|---|---|
| 1 | F01 | Keep the auto-PO path on one valid lifecycle: registered `purchase_order` sequence, `PurchaseOrderStatus::PendingApproval`, durable `ApprovalService` records, idempotency, and one agreed approval route. | large | separate-recommended | Technical repair and focused test added in this session. Confirm canonical PO workflow versus the documented VP-only route, then run the test on an isolated PostgreSQL schema and prove one downstream handoff. |
| 2 | F05 | Preserve the locked draft update/delete boundary and add two-connection race coverage against submit/approve and PR reopening. | medium | separate-recommended | Current service locks/re-reads update, delete, and submit. Added PostgreSQL two-connection coverage at `api/tests/Feature/Purchasing/PurchaseOrderTwoConnectionRaceTest.php:44-175`; execution remains pending until the dedicated test database is reachable. |
| 3 | F02, F09, F10 | Maintain the supplier DTO/lifecycle contract, align capability flags with endpoint preconditions (including accepted GRN for invoices), and verify FormData uploads. | large | separate-recommended | DTO and multipart changes exist in the worktree; F09’s accepted-GRN capability mismatch remains in the B2B dependency and was not modified under this module claim. |
| 4 | F03 | Preserve PR-line source IDs through converter, create form, request, service, and response; reject a source line belonging to another PR/item. | medium | separate-recommended | Current form/service/resource paths carry and validate the hash-string provenance. Add isolated HTTP contract coverage. |
| 5 | F06 | Round-trip incoterm through validated create/update, internal detail, supplier contract, and PDF. | medium | separate-recommended | Current internal persistence, resource, form, detail, and PDF paths are present. Add isolated round-trip coverage. |
| 6 | F07 | Recalculate budget warning/acknowledgment whenever a locked draft amount changes and approve only against current financial state. | medium | separate-recommended | Current update path re-assesses and clears stale fields. Add upward/downward amount tests in the dedicated database. |
| 7 | F11 | Resolve maker/checker policy for `purchasing_officer`: role separation, explicit monitored exception, or another documented control. | large | separate-recommended | Deferred for human/RBAC decision; changing shared role seeders is outside this module scope. |
| 8 | F04 | Keep soft-deleted hash binding and service-only restore state/permission checks. | small | same-session-ok | Current route and service use `withTrashed()` and reject active rows. Add permission/state test. |
| 9 | F08 | Keep approval/rejection actions restricted to `pending_approval` and confirm whether rejection is represented as cancellation or a distinct state. | small | same-session-ok | Current service and SPA gate actions correctly. Business vocabulary and terminal-state test remain. |
| 10 | F12, F14 | Keep permission-aware cancel visibility and align trimmed bounded reason validation between API and UI. | small | same-session-ok | Current route/UI/request agree; add unauthorized and boundary-value coverage. |
| 11 | F13 | Keep internal PO and source-line IDs typed and serialized as hash strings. | small | same-session-ok | Current TypeScript and resource paths agree; add response-shape assertion. |
| 12 | F15 | Keep vendor, overdue, date, and PO-number filters and preserve the overdue predicate in queue links. | small | same-session-ok | Current service/UI paths support the filters; add URL/backend filter coverage. |
| 13 | F16 | Decide whether manual direct PO creation exists. If yes, implement its permission/approval/provenance contract; if no, update `PROCESS-FLOWS.md` and test language. | medium | separate-recommended | Deferred for a product decision because current docs and request/UI disagree. |

## Verification gate

Before `✅ Verified`:

- Run migrations and the focused Purchasing, Approval, Inventory auto-
  replenishment, B2B supplier, dispatch, budget, and PR-reopening suites in a
  dedicated PostgreSQL test database. The shared `ogami_test` schema was not
  usable in this session.
- Run the new `AutoPurchaseOrderServiceTest` and prove the PO is
  `pending_approval`, contains the expected current approval records, rejects
  `pending_vp`, and does not duplicate on retry.
- Add/execute two-connection race coverage for draft update/delete versus
  submit/approve.
- Add HTTP/contract coverage for supplier line fields, lifecycle capabilities,
  accepted-GRN invoice gating, multipart upload, source-line provenance,
  incoterm, restore, rejection validation, cancel permission, and filter URLs.
- Record decisions for the auto-PO approval route, direct-create policy, SoD
  role overlap, rejection naming, and accepted-supplier enforcement before
  changing any cross-module policy.

---

# Action plan — re-audit 2026-08-30

Ordered. Items 1–4 were executed this session; 5 onward are handed off.

## Done this session (contained)

### 1. Restore the bill variance flags to the PO projection — `Broken P0`
Scope **small** · **same-session-ok** · **DONE**
`PurchaseOrderService.php:140`. Purely additive: four columns added to an
existing eager-load select. Changes no amount, no approver, no state machine. It
makes an already-written truth visible where three UI branches were waiting for
it. Containment is total — the only observable difference is that
`has_variances`, `three_way_overridden`, `due_date` and
`three_way_review_status` now carry their real values instead of `null`-coerced
defaults.

### 2. Bound the money fields — `Broken P1`
Scope **small** · **same-session-ok** · **DONE**
`StorePurchaseOrderRequest.php:58,60`, `UpdatePurchaseOrderRequest.php:47,49`.
A missing guard refusing impossible input: values that previously reached
PostgreSQL and returned `SQLSTATE[22003]` as a 500 are now 422. No input that
used to succeed is now refused — the ceiling sits at the `decimal(15,2)` column
limit, above any real purchase order.

### 3. Stop leaking primary keys in error bodies — `Broken P2`
Scope **small** · **same-session-ok** · **DONE**
`PurchaseOrderService.php:263,273,432`. Message text only. No control flow
changed; the same conditions still refuse the same operations.

### 4. Refuse cancelling an already-cancelled PO — `Broken P2`
Scope **small** · **same-session-ok** · **DONE**
`PurchaseOrderService.php:611-613`. A guard refusing an operation that cannot be
correct, closing a duplicate-publication path on the `p2p` outbox. The only
behaviour removed is a second cancellation of an already-cancelled order.

**Split justification.** The plan below is mostly gated, and per Step 6 only the
genuinely contained items were executed. Each of 1–4 either restores a value that
was already computed, refuses input that could only ever have 500'd, or rewords a
message. None of them changes an amount a supplier is paid or who may approve —
which is precisely what separates them from items 5–8.

---

## Handed off — human decision required first

### 5. Decide the intended PO reach, then fix row scope in BOTH places — `Incomplete P0`
Scope **medium** · **separate-recommended**

This is item 1 of the two questions global-search escalated, and it cannot be
closed by a developer. **Two sub-decisions:**

**(a) Is `production_manager`'s intended purchase-order reach department-wide or
author-only?** Options, with consequences, are written up in full at the end of
this plan. No permission currently distinguishes it from `department_head`, so
whichever answer is chosen, one of the two implementations must change and the
role-slug branch at `PurchaseOrderService.php:102` must go — `DepartmentScope`
exists specifically to eliminate that pattern and the PO list still hand-rolls it.

**(b) Independently and more urgently: `show` and `pdf` must apply the row scope
that `list()` applies.** `PurchaseOrderService::show()` accepts no `?User` at all,
so whatever the answer to (a), any holder of `purchasing.view` can fetch any PO
by hash id — measured 200 with the full record for a caller whose list returns
zero rows. Until (b) lands, the row scope is ornamental and (a) is academic.

Sequence (b) before (a). (b) is a genuine authorization gap; (a) is a policy
choice about breadth.

### 6. Reconcile the two ₱50,000 thresholds — `Incomplete P0`
Scope **small-to-medium** · **separate-recommended**

Measured: with the operator setting at ₱1,000 and a PO at ₱1,680, the PO reads
`requires_vp_approval = true` while the VP approval step is `skipped`. One of
these must become authoritative:

- **Make the setting govern.** `ApprovalService::submit()` would read the step
  threshold from settings rather than the seeded `steps` JSON. Editing the
  setting then genuinely changes who approves — which is presumably why it is
  editable, and is why this is not a developer's call.
- **Make the workflow govern and stop pretending otherwise.** Delete the
  setting (and `UpdateSettingRequest.php:65`), and derive
  `requires_vp_approval` from the workflow step so the chip cannot contradict
  the chain.

Either way, decide separately whether the gate should sit on the VAT-inclusive
total (it does today, measured: ₱45,000 of goods triggers VP at ₱50,400) or on
the goods subtotal.

### 7. Weighted-average the GRN cost basis — `Broken P1`
Scope **small** · **separate-recommended**

`ThreeWayMatchService.php:48`: replace `AVG(unit_cost)` with
`SUM(unit_cost * quantity_accepted) / SUM(quantity_accepted)`, matching
CLAUDE.md's documented weighted-average valuation. One line, and the code change
is trivial — but it moves the variance gate in both directions (measured: 550.00
reported against a true 109.00, false-blocking a correct bill; mirrored, it would
pass a bill 44% under the real received cost). Changing which bills clear the
gate changes whether a supplier is paid without review, so it needs an owner who
can accept that, plus a decision on what to do with bills already blocked or
already passed under the old basis.

### 8. Give a stranded partially-received PO an exit — `Missing P1`
Scope **medium** · **separate-recommended**

Measured: `close`, `cancel`, `update` and `delete` all refuse a PO at
`partially_received`. A short-close transition ("accept what arrived, cancel the
remainder") is the conventional answer, but it writes off an outstanding purchase
commitment and so needs a permission and an approval decision, not just a state
transition.

---

## Handed off — no decision needed, just work

### 9. Fix the SPA money floats — `Polish`
Scope **medium** · **separate-recommended**
`index.tsx:286`; `create.tsx:166-169,184,185,186-187,424-427`. Route through the
existing `spa/src/lib/money.ts` (currently used by one unrelated page). The
`create.tsx:186-187` VP-required decision matters most — it is a float
comparison against a threshold that itself arrives as a JSON number; changing
`BusinessPolicyService::purchaseOrderVpThreshold()` to return a decimal string
touches shared `Common` code, so pair this with item 6. Note the SPA hook
reformats UI files on write; check `git diff --stat` after each edit.

### 10. Build the PO edit surface, or retire the route — `Missing`
Scope **medium** · **separate-recommended**
`PUT /purchase-orders/{po}` is live with zero SPA callers, no `edit.tsx`, and no
Edit button, so a wrong draft can only be cancelled — and the budget
re-assessment at `PurchaseOrderService.php:348` is unreachable. Either build the
edit form (the create form is the template) or delete the route and the dead
client functions. Same call for `delete`/`restore` plus an `<ArchiveFilter>` on
the list, which two sibling pages in this module already have.

### 11. HashID the three-way-match response — `Polish`
Scope **small** · **separate-recommended**
`Support/ThreeWayMatchResult.php` and `ThreeWayMatchService.php:126,161,239` emit
raw `po_id` / `item_id` integers through the only PO-adjacent endpoint with no
`JsonResource`. Cross-module: `spa/src/pages/accounting/bills/detail.tsx:332`
consumes `item_id` as a React key and `types/purchasing.ts:289,292` types both
`number`, so the SPA changes with it.

### 12. Duplicate-`item_id` PO lines double-count the billed quantity — `Incomplete`
Scope **small** · **separate-recommended**
`ThreeWayMatchService.php:70` keys by `item_id`, so two PO lines of the same item
each compare against the whole bill quantity and both false-block. Key by
`purchase_order_item_id` where the bill line carries it. Over-blocks rather than
leaks, so it is not urgent — but it refuses legitimate bills.

### 13. Report to the Dashboard owner: `purchasing.open_pos` counts archived POs
Scope **small** · **outside this module — do not fix here**
`DashboardWidgetDataService.php:135` needs `whereNull('deleted_at')` or the
Eloquent scope. Measured 2 against a true 1.

### 14. Deferred: no DB guard that the header agrees with its lines — `Missing`
Scope **medium** · **separate-recommended**
Unreachable through the API today (both write paths recompute from
`normalizeLines()`; measured exact to the centavo). Needs a trigger, not a CHECK.
Recorded so it is not rediscovered; not worth a migration until a second write
path exists.

### 15. Report to the Inventory owner: raw PK in a GRN error body
`GrnService.php:208,420` emit "Cannot receive 7 for PO line 1: only 6.000
remaining" — a raw `purchase_order_items` PK, the same class as item 3.

---

## Open questions carried forward from 2026-08-25

F09 (supplier `can_submit_invoice` ignores the accepted-GRN gate — B2B change),
F11 (`purchasing_officer` holds both `po.create` and `po.approve`; the
self-approval guard blocks the same user but the role-level SoD conflict seeded at
`SodConflictRuleSeeder.php:22-27` stands), and F16 (`PROCESS-FLOWS.md:539-549`
promises direct PO creation; the API and UI require an approved PR) all remain
open and unchanged. None was re-opened by this session's measurements.

---

## Written-up options for F17 (a) — recorded, not acted on

**The question:** is `production_manager`'s intended purchase-order reach
department-wide, or author-only?

**Measured facts.** `production_manager` and `department_head` hold identical
`purchasing.*` permissions — `{purchasing.view, purchasing.pr.approve}` — and the
symmetric difference is empty. `production_manager` receives
`purchasing.pr.approve` solely so it can serve as step 2 of the seeded
`purchase_request` chain (`RolePermissionSeeder.php:578-585`). It does **not**
hold `purchasing.po.create`, so its author-only list is empty in practice: today
it sees zero POs in the list and its department's POs in search.

**Option A — department-wide reach is intended.** Adopt the department scope in
`list()` by replacing the role-slug branch with `DepartmentScope::apply(...)`
using the same arguments the search arm already passes.
*For:* one implementation, one helper, the divergence closes by deletion; search
becomes correct-by-construction rather than a hand-copy; a production manager can
see the POs raised for their own department's requisitions, which is coherent
with them approving those requisitions.
*Against:* it silently widens what a `production_manager` can list today from
zero to every PO in their department. It also makes `purchasing.pr.approve` do
double duty — gating PR approval *and* PO visibility — so any future role given
PR-approval authority inherits PO visibility as a side effect nobody chose.

**Option B — author-only is intended.** Narrow search to match `list()`. This
requires a new permission, because none exists: mint
`purchasing.po.view_department`, grant it to `department_head` only, and gate the
department tier on it in both places.
*For:* the grant becomes explicit and auditable — a role's PO reach is visible in
the seeder instead of implied by a role slug or borrowed from PR authority; it
removes the last role-slug branch from the PO list; and it is the only option
that lets the two roles ever differ.
*Against:* a new permission slug to seed, migrate for existing installs, and
document; and it narrows `production_manager` from what search shows today, which
someone may already be relying on.

**Option C — leave both, document the divergence.** Not recommended, and stated
only to be dismissed: the two implementations will keep drifting, and
`GlobalSearchTest.php:221` cannot catch it (it uses the real seeded
`department_head`, which satisfies *both* gates, so it passes under either
implementation — there is no `production_manager` equivalent).

**Independent of A/B/C:** `show` and `pdf` need the scope regardless — see item
5(b). A caller who can name a PO can read it in full today, so choosing between
A and B changes only what the *list* shows, not what is reachable.

**Recommendation for the human, not a decision:** Option B, sequenced after
5(b). It is the only one that makes the grant explicit, and the alternative
overloads a PR permission with PO visibility. But it is a question about who may
see what, and it is recorded here for an owner to answer.
