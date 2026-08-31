# M040 — Warehouse & Stock Control Action Plan

Disposition: Plan Ready after the 2026-08-25 re-audit. The current working-tree changes were present before this session; no application-source fixes were applied while rebuilding the report.

## Ordered remediation

1. **Define authoritative lot balances and finish map/picking projection work** — addresses M040-F11 and the remaining lot portion of the prior projection finding.

   Scope: large  
   Session recommendation: separate-recommended

   Choose a persisted lot-balance/allocation model or an atomically maintained projection. Reconcile existing movement history, make mixed-lot bins authoritative, allocate transfer/pick quantities against lot balances, remove inferred over-allocation, and add receipt → issue → transfer → mixed-lot tests. Keep the dependency contract with the ledger module explicit.

2. **Make count scope and lifecycle race-safe** — addresses M040-F03, M040-F04, M040-F05, M040-F06, and M040-F21.

   Scope: large  
   Session recommendation: separate-recommended

   Validate required scope IDs and warehouse/zone ownership; decide how empty bins and unexpected items are represented; add a shared location claim/unique guard for active sessions; require all required rows before completion; use decimal variance math and a defined WAC/value unit; enforce distinct counter/approver permissions and maker-checker rules; and replace direct status writes with an explicit `StateMachine`/`TRANSITIONS` contract. Add concurrent start, record-vs-complete/cancel, incomplete-count, and self-approval tests.

3. **Harden reservation and financial arithmetic** — addresses M040-F08 and M040-F09.

   Scope: medium  
   Session recommendation: separate-recommended

   Reject non-positive reserve/release values; enforce `reserved_quantity <= quantity`; remove float-derived available/total-value/status calculations from the M040 money boundary; read thresholds as decimal strings; and add high-precision valuation plus reservation-invariant tests.

4. **Validate transfer orders at the canonical creation boundary** — addresses M040-F07 and part of M040-F21.

   Scope: medium  
   Session recommendation: separate-recommended

   Replace the inline controller validation with a canonical FormRequest/shared validator, require distinct source/destination locations, validate active/blocked policy and source availability before inserting a pending order, and centralize pending → transferred/cancelled transitions. Add API tests for malformed hashes, same-location orders, inactive/blocked locations, insufficient stock, and execute race behavior.

5. **Repair scanner IDs and context-aware deep links** — addresses M040-F02.

   Scope: medium  
   Session recommendation: separate-recommended

   Return HashIDs for location/count-item actions, encode query values through the API contract, and make map/count pages consume `location_id` and `count_item_id` to select/open the intended record. Add deployed-mode feature tests proving raw numeric IDs are never emitted and each scanner action opens the requested context.

6. **Define and implement the picking lifecycle and permission boundary** — addresses M040-F12, M040-F11, M040-F17, and M040-F20.

   Scope: large  
   Session recommendation: separate-recommended

   Decide whether picking reserves, records an intermediate event, or executes material issue. Add persistent pick lines/statuses, authorized mutations, stock locks/idempotency, and lot allocation. Gate API and SPA read access on `inventory.picking.view`, return HashIDs consistently, filter inactive/blocked/quarantine/scrap locations, and add the full pick workflow and permission tests.

7. **Harden hierarchy lifecycle and location controls** — addresses M040-F13, M040-F14, and M040-F15.

   Scope: medium  
   Session recommendation: separate-recommended

   Bind restore routes with trashed models; block reparenting, deletion, or deactivation when stock, reservations, active counts, or pending transfers make it unsafe; add explicit blocked/capacity management; and enforce the chosen active/blocked policy consistently in movement, count, transfer, picking, map, and receiving seams.

8. **Close WMS API and frontend contract gaps** — addresses M040-F17 and M040-F18.

   Scope: medium  
   Session recommendation: separate-recommended

   Paginate transfer/count collections, align TypeScript status unions with backend enums, add detail-query error/retry states, and persist `cycle_count_variance` for count-generated adjustments. Add API/typecheck/browser coverage for map, count, transfer, scanner, and adjustment flows.

9. **Apply presentation cleanup after correctness work** — addresses M040-F19.

   Scope: small  
   Session recommendation: same-session-ok

   Replace opacity surface classes with opaque design-system tokens, add responsive breakpoint prefixes to dense grids, verify floor hit targets/focus states, and keep numeric/status content monospace and semantic. Run the token audit and the relevant browser flows.

## Verification gate

- Restore PostgreSQL connectivity; the focused inventory run on 2026-08-25 stopped at setup with 42 failures and 0 assertions because host `db` could not resolve.
- Add tests for every Broken finding, especially count scope/claim/closure/SoD, negative reservations, decimal valuation, same-location transfer creation, restore binding, scanner deep links, lot allocation, picking permission/execution, and lifecycle transitions.
- Run `php -l` on changed PHP files, the focused backend suite, SPA typecheck/build, token audit, and browser flows for map → bin, scan → bin/count, count → approval/completion, transfer create → execute, adjustment → approval, and picking execution.
- Re-run the M040 audit after implementation and regenerate the registry.

---

# M040 — Action Plan, 2026-09-01 re-audit

Disposition: **Partially Fixed** — the contained items were fixed and verified this
session; the rest are gated on policy decisions or on files outside this module.

## Fixed this session (containment — commit `ae98ee65`)

Each of these is a *missing guard refusing impossible input* or a plumbing repair that
changes no correct behaviour. All verified with before/after measurement.

| # | Item | Scope | Verification |
|---|---|---|---|
| 1 | N1 — `variance_percent` numeric(8,2) overflow 500 on an ordinary count | small | 500 → 200, saturates at `999999.99`; everyday 5.00% unchanged |
| 2 | N2 — `counted_quantity` `numeric` admits `1e3`/`1e17` → 500 | small | 500 → 422; `1.999` still stores exactly |
| 3 | N3 — transfer `quantity` `numeric` → 500 on `1e17`, silent truncation of `10.00005` | small | 500 → 422, truncation → 422; `1.999` still 201 |
| 4 | M040-F07 (create half) — transfer to its own source | small | 201-then-stuck → 422 at create |
| 5 | M040-F03 — warehouse/zone scope silently widening to every location | small | 201 covering 2 warehouses → 422; correct scope still 201/1 location |
| 6 | M040-F13 — all three restore routes 404 for every valid target | small | 404/404/404 → 200/200/200 |
| 7 | M040-F08 — negative reserve/release corrupting reservations | small | `reserved` −40.000 and 959.000-over-on-hand → `InvalidMovementException`, reservation intact |

## Deferred — ordered

### 1. Make the stock-movement ledger immutable after it has moved stock (N4)

Scope: **medium** · Session recommendation: **separate-recommended**

`$movement->save()` with a changed quantity succeeds, and a hard `DELETE` succeeds, while
`stock_levels` is untouched — on-hand and the ledger diverge silently and permanently, with
no void/reversal surface as the safe alternative. Needs an observer **plus** a PostgreSQL
`P0001` trigger, following the `journal-ledger` precedent.

**Why not containment:** the trigger must be **column-scoped**, not row-scoped.
`StockMovementService::stampLot()` and `MovementGlPostingService::markManual()/
markGenerated()` legitimately update a movement after creation, so a blanket rule would
break lot capture and the GL handoff replay. It also needs a decision on what the intended
reversal path *is* (today there is none), which is a policy question. Requires a migration —
check `ls api/database/migrations | grep -E '^04' | sort | tail -3` and note that a
dependency on any `2026_*` migration forces a timestamp name.

### 2. Give the adjustment CHECKER a way to see what it approves (N5)

Scope: **small** · Session recommendation: **separate-recommended** — *and needs a human*

`finance_officer`'s only inventory permission is `inventory.adjust.approve`, so it is 403
on the adjustment list, the options endpoint, stock levels and the warehouse map. It can
approve only if handed a hash id out of band, and cannot load the SPA page at all.

**Why not containment:** the fix is a permission grant in
`api/database/seeders/RolePermissionSeeder.php` — **outside this module**, and an approval/
visibility boundary this session must not move unilaterally. Two shapes are possible and
the choice is a human's: grant `inventory.view` to `finance_officer` (widest, simplest), or
introduce a narrow `inventory.adjust.review` gate on `GET /stock-adjustments*` only.

Separately, `StockAdjustmentService::approve()` has no `requested_by !== approved_by`
check, so any role holding both permissions can self-approve — `system_admin` does, and
this was measured. Adding that check is containment-sized but belongs with the decision
above so the two do not contradict each other.

### 3. Claim shared locations when a count starts (M040-F04)

Scope: **medium** · Session recommendation: **separate-recommended**

Measured with two real concurrent connections: two draft sessions covering the same
location both reached `in_progress`. `startSession()` locks its own session row and then
*pre-reads* for overlap, which is exactly the shape the payroll module could not close
without a UNIQUE index. Needs a shared claim — a unique partial index on (location, active
session) or a claim table — not another application check.

### 4. Decide count closure and variance semantics (M040-F05, M040-F06)

Scope: **large** · Session recommendation: **separate-recommended**

Measured: a session completes with items still `pending` (left `counted_quantity = null`);
a session with **zero** items starts and completes; `variance_value` stores the raw
quantity variance in a `numeric(15,2)` money column (5 units at WAC 10.00 recorded as
`5.00`, not `50.00`); and the user who counted can approve their own variance and then
complete the session, because `inventory.stock_count.manage` gates all three and
`approveVariance()` never inspects the actor.

**Why not containment:** changing `variance_value` changes a valuation figure; blocking
completion-with-pending changes whether an existing flow is allowed; and separating count
from approval changes who may approve. All three are explicitly out of bounds for a
containment pass, and the first needs the open policy answer on whether variance is
monetary or quantity.

### 5–11. Carried forward unchanged from the 2026-08-25 plan

M040-F02 (scanner deep links — the backend emits `location_id`/`count_item_id`/
`material_issue_id` and no page reads them), M040-F11 (authoritative lot balances),
M040-F12 (picking execution missing), M040-F14 (reparenting a stocked location),
M040-F15 (active/blocked/capacity policy), M040-F17/F18 (list pagination, type-union
drift, cycle-count reason code), M040-F19 (opaque-surface and responsive polish),
M040-F21 (state-machine contract). All remain `separate-recommended`; see the
2026-08-25 section above for the detail, which this re-audit confirmed still applies.

### 12. Dead surfaces and documentation (N7, N8)

Scope: **small** each · Session recommendation: **separate-recommended** (SPA + docs, not
this module's files)

- `GET /api/v1/inventory/warehouses` has a wrapper (`warehouse.ts:31`) and no caller.
- `stockTransfersApi.create` → `/inventory/stock-transfers` (route commented out at
  `routes.php:104`), called only from the unrouted
  `spa/src/pages/inventory/stock-transfers/create.tsx` — dead the whole way down.
- `/inventory/warehouse` is the only UI for 12 warehouse/zone/location mutation routes and
  has no sidebar entry.
- `inventory.picking.view` is seeded but enforced by no route — a dead permission.
- `docs/PROCESS-FLOWS.md:1578-1579` sends operators to two routes that do not exist.
- The entire WMS surface (map, counts, transfers, picking, scanner) is undocumented in
  `docs/USER-MANUAL.md`.
- `usePermission` is never imported in `stock-adjustments/index.tsx` or
  `warehouse/index.tsx`, so mutation buttons render then 403.

## Outside-module blocker to report, not fix (N6)

`php artisan migrate:fresh --seed` fails at HEAD:
`ComprehensiveDemoSeeder.php:634` inserts `journal_entry_lines` for an already-`posted`
entry and `journal-ledger`'s `prevent_posted_journal_line_mutation()` trigger raises
`P0001`. Reproduced on a throwaway database independently of any test. Belongs to whoever
owns `ComprehensiveDemoSeeder` / `journal-ledger`.

## Verification gate for the deferred work

- Reproduce N4 with the edit/hard-delete probe before and after the trigger, and prove
  `stampLot()` and the GL handoff replay still work.
- Reproduce the count-start race with two real concurrent connections, never under
  `RefreshDatabase` (which hides uncommitted rows from a second connection).
- Any count-semantics change must re-run `tests/Feature/Inventory` (177 passed / 621
  assertions at this commit) and state which pre-existing tests it turns red and why.
