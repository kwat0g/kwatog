# Delivery & Dispatch review — 2026-09-25

Delivery and dispatch repairs passed role-based headless acceptance and focused regressions.

## Scope and behavior

The starting boundary is an independently reviewed outgoing inspection bound to a specific production output and sales-order line. Dispatch preparation, driver assignment, loading, departure, arrival, proof of delivery, customer receipt, invoice/CoC creation, and customer problem reporting are in scope. RFQ and quotation code are outside this work.

- Dispatch used the first active finished-goods bin and generic FIFO/FEFO stock, even when its inspection authorized a different output. `DeliveryStockService` now requires the output's traceable production receipt and uses that receipt's lot across current active finished-goods bins. Transfers and split bins are supported. Every issue remains in the canonical stock ledger under its delivery item; the legacy singular pointer identifies the first issue, while `stockMovements` exposes all issues.
- The whole departure transaction rolls back when the approved lot is short, reserved, quarantined, or otherwise unavailable. A partial issue cannot survive a failed departure. The delivery detail provides read-only lot/bin preparation guidance and actual dispatched movement history.
- In-transit cancellation previously released delivery capacity after stock had physically left. Ordinary cancellation is now limited to scheduled/loading deliveries. Delivered/in-transit shipments require reconciliation through delivery/return operations.
- ImpEx could open the delivery form but its CRM sales-order requests returned 403. A narrow dispatch-only form-options endpoint now provides searchable, paginated order choices and unallocated line quantities, without granting CRM access or exposing order pricing. Changing the order/line clears dependent selections. Lookup errors have retry actions.
- A delivery save whose response is lost retains its original payload and idempotency key in user-scoped browser draft storage. Retry, including after reload, recovers the original delivery rather than creating another. Inputs remain protected until the save is resolved.
- An open customer problem already blocked receipt confirmation/billing on the server, but the portal still offered confirmation. A shared `blockingReturnCase` relation now drives the guard and both detail-page explanations. The customer can open the related report instead of repeatedly receiving a failed confirmation response.
- Customer problem intake inherits an unambiguous lot from actual delivery movements. Mixed or untraceable legacy movement history is left unresolved rather than guessed.
- Arrival/status changes, confirmation, and customer intake now acquire sales-order/delivery locks in the same order. Dispatch validates all source evidence first and locks stock by inventory item/location order. Focused regressions validate the resulting lifecycle behavior; this audit did not simulate parallel database transactions to measure deadlock behavior.

The accompanying material-recovery work adds the production output's batch code to **new** finished-goods receipts. Historical receipts are not blindly stamped after stock may already have moved.

## Validation

| Check | Result | Evidence |
| --- | --- | --- |
| Existing complete Supply Chain baseline | 208 tests, 598 assertions passed | `/tmp/dispatch-supervisor-baseline-0925.txt` |
| Initial new dispatch regression | Four intended failures | `/tmp/dispatch-provenance-red-0925.txt` |
| Initial fixes | Four tests, 13 assertions passed | `/tmp/dispatch-provenance-green-0925a.txt` |
| Expanded dispatch + quantity/inventory tests | 19 tests, 58 assertions passed | `/tmp/dispatch-provenance-green-0925c.txt` |
| Customer open-case confirmation regression | Intended failure: can_confirm incorrectly true | `/tmp/dispatch-hold-red-0925.txt` |
| Dispatch/portal confirmation/Return Case integration | 28 tests, 170 assertions passed | `/tmp/dispatch-final-handoffs-0925a.txt` |
| Customer delivery UI | Three tests passed | `/tmp/dispatch-portal-vitest-0925.txt` |
| Final outbound Delivery/Dispatch regression | 98 tests, 289 assertions passed | `/tmp/dispatch-outbound-final-0925.txt` |
| Confirmation lock-order and Return Case regression | 21 tests, 132 assertions passed | `/tmp/dispatch-confirm-lock-final-0925.txt` |
| Dispatch stock integration after material-return WAC fix | 10 tests, 45 assertions passed | `/tmp/dispatch-stock-final-0925.txt` |
| Final arrival/status/driver/stock lock-order regression | 41 tests, 130 assertions passed | `/tmp/dispatch-arrival-lock-final-0925.txt` |
| Scoped ESLint | Passed without warnings | `/tmp/dispatch-eslint-final-0925b.txt` |

These runs overlap; their counts must not be added. The earlier outbound-only run had three failures (two obsolete anonymous-stock fixtures and cancellation error ordering). The stock fixtures now include real output/inspection/receipt provenance, and the cancellation message retains its customer-return guidance. Both fixes passed the final outbound rerun. PHP syntax passed for 17 scoped files, and the browser runner passed Node syntax validation. The built SPA passed `tsc -b` and Vite compilation (`/tmp/dispatch-build-DSP0925A-retry.log`).

## Headless acceptance

Runner: `scripts/delivery-dispatch-headless.cjs`. Prerequisites: `api/tests/Browser/delivery_dispatch_fixture.php`. The fixture seeds approved outputs and calls the real production-receipt recovery service; it never hand-stamps the approved lot. The browser creates every delivery.

The runner uses real role logins with Sanctum session and CSRF cookies, a built SPA preview, and separate Chromium contexts. Its inert Pusher transport acknowledges only connection/subscription/ping messages; it sends no business events. Business requests use the real API. Deliberate fault injection covers a lookup outage and loss of a successful creation response. Driver photo evidence is a clearly test-only image.

Final headless result: **17 checkpoints passed, four screenshots, zero JavaScript errors**. Evidence: `/tmp/delivery-dispatch-headless-DSP0925C/report.json` and `/tmp/delivery-dispatch-headless-DSP0925C.log`.

- ImpEx recovered a lookup outage, then retried a successful save with a lost response after reload; only one delivery existed.
- Warehouse read approved-lot/bin preparation and was denied dispatch authority. ImpEx assigned a real driver and available vehicle.
- The assigned driver loaded, departed, arrived, and uploaded photo proof through the mobile UI. Dispatch consumed the approved lot across two bins; cancellation after departure was denied.
- The customer could not confirm before proof. After proof, UI confirmation created a draft invoice and generated CoC. Finance read exactly six invoiced units and was denied dispatch actions.
- A second four-unit delivery reused the released vehicle. The customer reported three received, one missing, and one defective, with a credit preference. Its case inherited the actual dispatched lot. Both internal and portal confirmation were blocked, no invoice was created, and the portal linked the case instead of displaying an impossible confirmation action.
- Another customer was denied both delivery and case access. All ten approved units left stock; five unapproved units remained.

The runner records actual UI interactions for creation, driver assignment/status/proof, customer confirmation, and problem intake. It uses authenticated API reads for ledger/invoice assertions and API calls for negative permission/guard checks. It does not claim that every assertion is a UI interaction. Desktop preparation and customer mobile screenshots were visually inspected; layouts remained usable without horizontal overflow. The first driver screenshot caught the short query-refresh interval. A separate read-only revisit verified the settled `Delivered` state, available photo replacement, and absence of another status transition (`/tmp/delivery-driver-readonly-DSP0925C-final/report.json` and `driver-delivered-mobile.png`). The runner now waits for the displayed status before continuing; no product change was required.

Final test resources: DB `ogami_test_dispatch_browser_0925c`, run `DSP0925C`, API port 8124, preview/host port 5220, fixture `/tmp/dispatch-fixture-DSP0925C.json`. Evidence is written to `/tmp/delivery-dispatch-headless-DSP0925C/`.

The first browser attempt correctly hit the CoC evidence guard because the fixture omitted inspection measurements; the fixture now includes recorded visual measurements. The second attempt reached generated invoice/CoC but stopped on an incorrect invoice API URL in the runner. Neither failure was bypassed by weakening product guards; each full rerun used a fresh database.

Migration and fixture processes use `CACHE_STORE=array`; the API server uses its own cache prefix and session cookie. An initial fixture used the shared settings-cache namespace; only SettingsService settings keys were invalidated to remove that test cache. No global cache/session clear was used. All mail is captured locally, queues are synchronous, and broadcasts are logged.

Cleanup completed: owned API 8124, preview 5220, and host forwarding processes stopped; temporary Vite config removed. Databases, compiled audit assets, logs, and screenshots are retained for review. The shared development server on 5173 remains running.

## Boundaries and remaining operational risks

- Scheduling reserves accepted QC/SO capacity. It does not create a durable physical lot reservation. Preparation is a current-stock guide, and departure authoritatively rechecks stock under locks; another authorized stock operation can cause a later shortage.
- The preparation list is not a barcode scanning, packing, pallet, or seal-verification system. Warehouse staff have read access; dispatch authority remains with ImpEx/assigned Driver.
- Legacy anonymous or ambiguous production receipts block dispatch pending reconciliation. No automatic history rewrite or guessed lot assignment is performed.
- Ordinary cancellation is deliberately unavailable after departure. A dedicated failed-delivery/vehicle-return intake remains an operational extension; blocking cancellation prevents it from silently erasing stock and quantity history.
- Missing goods can be reported after arrival (`delivered`/`confirmed`). A whole shipment that has not arrived does not yet have a separate in-transit problem intake.
- An open customer problem is an intentional handoff awaiting staff resolution. This review validates its billing hold and linkage; the complete return settlement workflow is documented in the prior Return Management review.

## Change isolation

This workspace contained extensive pre-existing and concurrent changes. Baseline copies and a manifest were captured at `/tmp/ogami-material-delivery-baseline-0925-nd0dyv0y`; additional narrow B2B/Return handoff files were copied before editing. No reset, clean, stash, deployment, commit, or RFQ modification was performed.

## Repeating the headless scenario

1. Create a new PostgreSQL database named `ogami_test_dispatch_browser_<unique suffix>`; never reuse a completed scenario as an empty fixture.
2. Run migrations with that explicit `DB_DATABASE`, an unused `APP_CONFIG_CACHE` path, and `CACHE_STORE=array`. Run `tests/Browser/delivery_dispatch_fixture.php` through `artisan tinker` with the same database plus matching `DISPATCH_BROWSER_DB`, a unique 6–16 character `DISPATCH_RUN_ID`, and `DISPATCH_FIXTURE_PATH`. Use `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`, and `BROADCAST_CONNECTION=log` for both fixture and API.
3. Start an isolated API on a free port with the same database, its own cache prefix/session cookie/config-cache path, and frontend/stateful origin matching the preview. Build the SPA into a separate directory. A temporary Vite preview config must proxy `/api` and `/sanctum` to this API; the normal development server is not the test backend.
4. Copy the fixture manifest to the host, then run:

   ```bash
   NODE_PATH=./spa/node_modules \
   DISPATCH_FIXTURE_PATH=/tmp/dispatch-fixture-UNIQUE.json \
   DISPATCH_TEST_URL=http://127.0.0.1:5220 \
   DISPATCH_TEST_OUTPUT=/tmp/delivery-dispatch-headless-UNIQUE \
   node scripts/delivery-dispatch-headless.cjs
   ```

5. Read `report.json`, preserve logs/screenshots, and stop only the API/preview/port-forward processes started for the audit. Fixture accounts and password `password` are strictly local test data.
