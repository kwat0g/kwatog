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
