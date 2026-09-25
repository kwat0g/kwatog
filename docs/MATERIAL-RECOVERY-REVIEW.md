# Production material recovery review — 2026-09-25

Backend and UI implementation, headless verification, and audit-process cleanup are complete. Delivery and dispatch are covered separately in `DELIVERY-DISPATCH-REVIEW.md`.

## Scope and resulting behavior

This review closes the handoff between Warehouse issues, production rejects/replacement output, unused-material returns, work-order cost, and MRP. It does not change RFQ or quotation behavior.

- **Replacement output requires its additional material.** Starting production still requires the saved full target plan. Once running, a BOM-backed order checks issued material against cumulative gross production using its saved recipe. Rejects count as production already performed. A five-piece plan with five material units can record three good plus one rejected piece; producing two more good pieces requires a sixth material unit. The good-output target stays five.
- Canonical output and routed-operation output describe overlapping work. Coverage uses the larger cumulative quantity, rather than adding both ledgers. Repeated BOM rows are aggregated by item and the scaled material requirement rounds upward once to 0.001. Resume checks material coverage for recorded production; each additional output checks its proposed cumulative requirement.
- **Warehouse can return part of an issue.** The two input fields are quantity and reason. The form shows the original issue, lot, source bin, original cost, prior returns, and current returnable balance. An issue-detail link opens the exact movement. Both automatic work-order issues and manually issued slips are supported.
- Each return is a canonical `material_return` stock receipt referencing its original issue. Quantity is limited both by the unreturned source balance and by the work order's remaining material above its recorded-production floor. The original issue's location, lot, expiry and unit-cost provenance are retained. Blocked/frozen receipt locations are refused.
- Work-order detail and MRP use net issued quantities and costs after returns. Automatic and manual issues remain separate; manual issues do not overwrite automatic counters. New output snapshots record gross, returned, and net amounts. Historical output snapshots remain unchanged.
- Accounting reverses material consumption into inventory. Split returns allocate the original rounded issue value cumulatively, including the final residual cent. The same allocated value feeds the stock ledger, weighted-average stock valuation and GL reversal. A source unit cost of 5.0050 with total 5.01 returns as 2.51 plus 2.50 when split in halves.
- Whole-slip cancellation remains limited to before any production. If a partial return already exists, cancellation restores only the remaining issue quantity/value, so it cannot return the same material twice.
- **Retry survives a lost response and page reload.** A pending request retains its original source, quantity, expected prior returns, reason and key in user-scoped browser storage. Inputs remain protected while its result is unknown. Retrying recovers the existing movement. A stale balance produces a conflict response, refreshes the totals, and requires the operator to review the new balance.
- New finished-goods receipts carry the output batch code. Delivery can therefore use the independently approved output's exact lot, including after warehouse transfers. Legacy anonymous receipts are not retrospectively stamped with a guessed lot.

## Migration and compatibility

`api/database/migrations/2026_09_25_230000_add_material_return_source_links_and_idempotency.php` adds the unique material-issue-line source movement link and stock-movement idempotency fields. The backfill links only exact one-to-one matches of slip, item, location, lot, quantity and cost. Ambiguous historical lines remain unlinked and return intake explains that their issue history needs reconciliation.

The migration was applied to isolated verification databases. This work did not deploy the application or migrate the shared operational database.

## Backend and build evidence

| Check | Result | Evidence |
| --- | --- | --- |
| Focused new return, source-link migration, GL, MRP and reject-coverage regressions | 14 tests, 101 assertions passed | `/tmp/material-recovery-focused-green-0925k.log` |
| Existing and new Production/Inventory/MRP regression selection | 97 passed, two test-fixture/expectation failures subsequently corrected | `/tmp/material-regressions-focused-green-0925l.log` |
| Both affected test classes after corrections | 23 tests, 141 assertions passed | `/tmp/material-test-corrections-root-0925.txt` |
| Shared stock-service impact on dispatch | 10 tests, 45 assertions passed | `/tmp/dispatch-stock-final-0925.txt` |
| Final SPA TypeScript and Vite build after the fractional-issue UI correction | Passed (`tsc -b`; 8,088 Vite modules) | `/tmp/material-recovery-spa-build-MR0925B.log`; assets `/tmp/material-recovery-spa-MR0925B/` |
| Targeted material-issue ESLint and final no-emit TypeScript check after the stable fallback correction | Passed | ESLint: `src/pages/inventory/material-issues/create.tsx`; `/tmp/material-recovery-typecheck-MR0925D.log` |

These runs overlap and their counts must not be added. All 99 cases in the broader selection have a passing result across the broader run and the affected-class rerun. The two corrections were:

1. An unlinked-issue balance test specified a lot absent from its fixture. It now issues the anonymous stock that actually exists in that fixture.
2. An older production handoff test demanded the full target quantity before recording even one covered piece. Its replacement asserts the intended cumulative rule: fifteen remaining material units at two units per piece allow seven good pieces, block three more with a five-unit shortage, then allow the final three after reissue. It also verifies that cancellation leaves the original automatic counter intact.

## Headless acceptance

Reproducible material-specific runner: `scripts/material-recovery-headless.cjs`.
Prerequisites: `api/tests/Browser/material_recovery_fixture.php`.
The original Production browser runner and fixture were restored from their pre-edit snapshots and retain their own scenario.

The runner signs in through the real UI as Production Manager, PPC, Warehouse, Maintenance and two independent QC users. It uses session/CSRF cookies, separate headless Chromium contexts, the built SPA and a real isolated API. The inert Pusher transport acknowledges connection/subscription/ping only and sends no business events. Material issue/return, production output and routed operation, work-order completion and close are exercised through the UI. QC measurements and checker decisions use authenticated API requests from the real QC and checker cookie contexts. Business requests are not mocked, except deliberate response/lookup failures. Authenticated API reads check stock and ledger results; API calls create the stale-return race and test unauthorized access.

The D main run recorded 29 passing checkpoints through its manual partial-return, output-shortage/reissue, production cost snapshot, routed operation, maintenance and customer-linked outgoing-QC flows. The script then stopped on a duplicate `Stock movements` heading selector while preparing the separate auto-issued scenario. This was a runner selector issue; evidence is in `/tmp/material-recovery-headless-MR0925D-final/report.json` and `/tmp/material-recovery-headless-MR0925D-final/`.

The separate continuation first verified an auto-issued half-return whose successful server response was deliberately dropped. After reload, the saved request replayed with the same payload and idempotency key, and the ledger still had exactly one return; this is recorded in `/tmp/material-recovery-auto-continuation-MR0925D-final/report.json`. That run then exposed a real Warehouse form defect: the native number input defaulted to step 1 and blocked the valid 0.500 replacement issue. The quantity field now declares `step="0.001"`, and selecting a source location revalidates the location field so an earlier “Location required” error clears. The final SPA rebuild includes these changes.

On the same saved D database, the updated built SPA accepted the 0.500 replacement issue, recorded the output with its batch lot, completed the routed operation, and passed measured in-process QC. The next check originally expected outgoing QC, but this fixture's standalone auto work order has neither a sales order nor an NCR replacement link. `TriggerOutgoingQC` intentionally skips that case. The generic material runner and continuation now check this fixture condition and require no outgoing row for the standalone work order. Because this saved D run had already recorded its replacement issue and output, they were not replayed: a read-only preflight resumed at “ready to close,” verified the saved in-process measurements and absence of a due outgoing inspection, and closed the work order through the Production UI. Final net/gross counters reconcile (automatic net 0.5/gross 1/returned 0.5; manual net/gross 0.5), raw stock is 0, finished stock is 8, and the source still has one return row.

The accepted browser evidence is composite across saved-state reports, not a claim that a single run passed every checkpoint. The main report has 29 green checkpoints before its selector stop; the initial auto continuation at `/tmp/material-recovery-auto-continuation-MR0925D-final/report.json` documents the committed return and same-key retry; `/tmp/material-recovery-auto-continuation-MR0925D-resume-stepfix/report.json` reaches and passes the fractional replacement issue, output, operation and in-process QC before stopping at the incorrect outgoing expectation; `/tmp/material-recovery-auto-continuation-MR0925D-close-proof/report.json` has 7 passing read-only/close checkpoints and final stock/counter evidence. These runs deliberately continue the same isolated D database rather than replaying consumed prerequisites.

`material-recovery-auto-continuation.cjs` is a D-only forensic continuation. It intentionally guards the D database and refers to D's prior evidence files. D is now closed; do not rerun, reset or repurpose it. For new verification, `scripts/material-recovery-headless.cjs` with a fresh uniquely named browser database is the repeatable end-to-end path. Reports and screenshots from the D continuation are preserved under `/tmp/material-recovery-auto-continuation-MR0925D-*`.

The original Production browser runner and fixture were restored from their pre-edit snapshots and retain their own scenario. Only the isolated API on 8123, built-SPA preview/host relay on 5210, and temporary material Vite config were owned by this run. `/tmp/material-recovery-process-cleanup-MR0925D.txt` records their removal; the normal development server on 5173 remained running and answered HTTP 200 inside its container.

## Boundaries and remaining operational risks

- The material floor is a saved planning allowance, not a measurement of physical usage. Warehouse must verify that stock is actually unused and fit to return. Defective material still belongs in the existing quality/return disposition process.
- Manual/no-BOM plans have no defensible per-unit recipe. Their full stated quantity remains the floor after output starts; issued items without a usage plan are retained conservatively. They require reconciliation rather than an invented consumption norm.
- Returns receive into the original source bin. A blocked, frozen, inactive or otherwise unusable destination requires Warehouse reconciliation; the form does not silently choose a substitute bin.
- A stale-balance test verifies an interleaving of two requests, not a timed parallel-database stress test. Source/work-order row locks, expected-returned quantities and unique retry keys protect the actual writes.
- Browser storage supports reload recovery where available. If storage is disabled, the current page still retains an in-memory retry, but that snapshot cannot survive a browser restart.
- Source lot links remain unresolved for ambiguous legacy issue history. No automatic historical reassignment is performed.

## Repeating the browser scenario

The material runner retains the `PRODUCTION_WORKORDERS_*` environment names used by its original helpers.

1. Create a new database named `ogami_test_production_workorders_browser_<unique suffix>`. Run migrations with explicit `DB_DATABASE`, an unused `APP_CONFIG_CACHE` path, `CACHE_STORE=array`, `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync` and `BROADCAST_CONNECTION=log`.
2. Execute `tests/Browser/material_recovery_fixture.php` through `artisan tinker` with the same database and matching `PRODUCTION_WORKORDERS_BROWSER_DB`, unique `PRODUCTION_WORKORDERS_RUN_ID`, and a fixture output path. Copy the manifest to the host.
3. Start an isolated API and built-SPA preview using separate cache prefix/session cookie and a matching frontend/stateful origin. Proxy `/api` and `/sanctum` to that isolated API. Do not point the browser fixture at the normal application database.
4. Run:

   ```bash
   NODE_PATH=./spa/node_modules \
   PRODUCTION_WORKORDERS_FIXTURE_PATH=/tmp/material-fixture-UNIQUE.json \
   PRODUCTION_WORKORDERS_TEST_URL=http://127.0.0.1:5210 \
   PRODUCTION_WORKORDERS_TEST_OUTPUT=/tmp/material-recovery-headless-UNIQUE \
   node scripts/material-recovery-headless.cjs
   ```

5. Preserve `report.json`, screenshots and logs, then stop only the API/preview/forwarding processes created for the audit. The fixture accounts are local test data.

The continuation script is retained for the D saved-state evidence only. The D database is closed and should not be rerun or reset; use the generic fresh-database runner above for repeatable verification.
