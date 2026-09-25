# Production & Work Orders audit

## Verified findings and repairs

| Priority | Finding | Repair and evidence |
| --- | --- | --- |
| High | A work order could consume an existing manual material issue and issue the same BOM demand again from reserved stock. Repeated BOM rows, another work order's hold, and cancelled-slip lot fallback also made the handoff ledger inconsistent. | `WorkOrderMaterialUsageService` consolidates automatic issues and active manual issues per item, checks reservation/issued coverage, preserves current-lot lineage, and is shared by start, resume, output, operations, and resource summaries. Auto-only persisted material actuals remain unchanged so MRP does not double count manual issues. RED evidence: `/tmp/production-supervisor-handoff-red-0925.txt`; expanded regression: `/tmp/production-handoff-expanded-green-0925.txt` (13 tests, 72 assertions); full Production suite: `/tmp/production-supervisor-suite-green-0925.txt` (115 tests, 406 assertions). |
| High | Cancelling an under-issued work order's material slip returned stock without restoring the reservation, but start previously did not require adequate backed material coverage. A slip could also be cancelled after positive output, making the ledger claim consumed material had returned. | Start checks issue/reservation coverage and usable backing, issues only the uncovered remaining demand, and releases excess reservations. Resume, output, and operation records require actual issued coverage. Pre-output slip cancellation returns unused stock but does not silently recreate a reservation; post-output cancellation is rejected before changing stock. A focused cross-module run covers Inventory cancellation and reservation/lot invariants. |
| High | A standard work order created without an explicit `work_order_class` was rejected for missing `exception_reason`, even though service creation defaults the class to standard. | Request validation now treats omitted class as standard and requires a reason only for explicit exception classes. `WorkOrderCreationClassValidationTest` passed 1 test / 11 assertions against the explicit isolated suite DB (recorded in the focused test run). |
| Medium | Production create-form choices used broad/capped lookups; the browser exposed lookup failure without preserving typed form values. Local `datetime-local` defaults were not aligned to Manila wall time. | Added scoped `form-options` for products, machines, molds, and shifts under existing permissions, wired Production forms to it, retained entered values across retry, and fixed local datetime defaults. Browser evidence injects two 503 lookup failures and verifies the automatic retry/manual retry path. |
| Medium | An Inventory issue opened with `?work_order_id=...` lost the selected work order when async choices arrived. A linked slip also displayed an em dash instead of the linked work-order number. | The selection is controlled from form state after choices load; the runner asserts the submitted hashed WO ID; the detail stat shows `work_order.wo_number`, falling back to reference text for general issues. E7 exposed the display defect. E10's UI request assertion and [Warehouse detail screenshot](/tmp/production-workorders-headless-PWO0925E10-final-20260925/warehouse-material-issue-detail.png) confirm the selected WO number is retained and displayed without a link to a forbidden Production page. |
| Medium | Work-order detail tables expanded a 390 px viewport. The output form's defect row could also expand the mobile page to 444 px. | Detail grids now allow children to shrink and table overflow stays in each horizontal-scroll panel. The defect row uses a constrained responsive grid. Browser assertions after create, output form, and output detail check document/body width and internal table scrolling at 390 px. |
| Medium | Recording an output could navigate away before the detail query finished invalidating, showing stale output counters when pushed events were absent. | The output form awaits query invalidation before navigation. The real-browser run with business events withheld verifies the returned detail shows current target/produced and good/reject counts after each output. |

The design change is a targeted responsive fix in the existing design system. No new dashboard or workflow redesign was added.

## Role handoffs exercised

All seven browser login events used real Sanctum session cookies and the XSRF cookie/header; no bearer token or browser-storage auth was used. There are six distinct accounts because the Production Manager has separate mobile and desktop contexts. The fixture-only independent QC checker uses the existing `qc_inspector` role and permission. `system_admin` is IT and is not used as an approver.

| Account / role | Actual UI actions | Browser-authenticated API actions |
| --- | --- | --- |
| Production Manager | Creates a mobile planned WO; confirms both fixture WOs; attempts the blocked short-coverage start; starts, pauses, resumes, records output, completes routed operations, and closes WOs. | Reads scoped options and WO/stock/lineage data; attempts two outgoing self-reviews that Quality rejects with 403. |
| PPC | Signs in separately. | Loads scoped Production choices; verifies unrelated Attendance access is denied. |
| Warehouse | Creates both reservation-linked and general-reissue slips in the Inventory form; verifies submitted WO ID and linked WO number on slip detail. | Cancels the pre-output slip, attempts the forbidden post-output cancellation, and reads stock to verify ledger effects. |
| QC Inspector maker | Signs in separately. | Records measurement/checklist results for in-process and output-bound outgoing inspections. |
| Independent QC Inspector checker | Signs in as the separate fixture-created account, with the existing QC role. | Reviews three output-bound outgoing inspections after verifying the two main-WO maker self-review attempts are denied. The final checks assert checker != inspector, review timestamp, accepted quantity equals batch quantity, and exact output binding. |
| Maintenance Technician | Signs in separately. | Starts and completes the automatic breakdown maintenance work order. |

Maintenance and QC steps are real, browser-cookie-authenticated API requests, not represented as clicks in their module screens. The fixture creates only prerequisites and a separate eligible reviewer; the browser performs the audited actions. The next-stage Delivery check is a read-only eligibility handoff and does not create a Delivery record.

## Browser evidence

The complete production-preview acceptance run is [PWO0925E10](/tmp/production-workorders-headless-PWO0925E10-final-20260925/report.json), on fresh DB `ogami_test_production_workorders_browser_e2e_0925j`. It passed 28 checkpoints (7 login checks and 21 other checks), produced six screenshots, recorded zero JavaScript page errors, and finished with the recovery and main WOs closed. Final evidence reports raw stock 0, reserved stock 0, finished stock 7, main target 5 / produced 6 / good 5 / rejected 1, manual issue cost 20 PHP, and three independently reviewed output-bound outgoing batches. Two Production Manager self-review attempts returned Quality's expected 403 before the independent checker completed review.

Screenshots: [mobile create form after the deliberate lookup-failure injection](/tmp/production-workorders-headless-PWO0925E10-final-20260925/mobile-create-options-retry-state.png), [mobile created WO detail](/tmp/production-workorders-headless-PWO0925E10-final-20260925/mobile-work-order-created.png), [390 px output form](/tmp/production-workorders-headless-PWO0925E10-final-20260925/partial-record-output-form.png), [refreshed partial-output detail](/tmp/production-workorders-headless-PWO0925E10-final-20260925/main-partial-output-refreshed-detail.png), [closed main WO](/tmp/production-workorders-headless-PWO0925E10-final-20260925/closed-main-work-order.png), and [linked Warehouse material issue](/tmp/production-workorders-headless-PWO0925E10-final-20260925/warehouse-material-issue-detail.png). The mobile created-detail screenshot can retain the global toast from the deliberately injected 503 because it was captured within the API client's toast lifetime; the create request itself returned 201 and the detail page rendered.

The final run used the built SPA served by Vite preview, with both the preview index and proxied `/api/v1/health` returning HTTP 200 before the run. A browser-only fault route injects two 503 responses for form-options, then verifies the visible retry state, successful manual retry, and preserved typed quantity. Reverb sockets use an inert Pusher-protocol transport test double that acknowledges connection, subscription, and ping frames only. E10 records 25 such sessions, 104 subscription acknowledgements, 3 pings, zero real-time business events, and zero Vite HMR sockets. Production, Inventory, Maintenance, and Quality HTTP business APIs remain real and cookie-authenticated. UI output counters are checked after query invalidation/navigation, independently of pushed events. The runner saves page URL/root state, script resources, page/console errors, failed requests, error responses, navigation history, WebSocket URLs, and renderer crashes on failure.

Earlier partial runs are retained as diagnosis evidence and are not included as successful-flow counts: [E5](/tmp/production-workorders-headless-PWO0925E5-final-20260925/) exposed the 444 px output-form overflow; [E7](/tmp/production-workorders-headless-PWO0925E7-final-20260925/) stopped at a transient blank page/Start lookup; [E8](/tmp/production-workorders-headless-PWO0925E8-final-20260925/) encountered Vite module-load `ERR_INSUFFICIENT_RESOURCES` under dev serving; and [E9](/tmp/production-workorders-headless-PWO0925E9-final-20260925/) demonstrated the intended Quality 403 but used the maker as checker. Their defects were addressed in the responsive styles, runner diagnostics/transport setup, production preview, and separate checker fixture used for E10.

## Verification evidence

- Production baseline: 100 passed, 2 historical failures, 331 assertions; `/tmp/ogami-production-workorders-baseline-0925/production-suite-baseline.txt`.
- Material-usage regression proof: four intentional RED failures at `/tmp/production-supervisor-handoff-red-0925.txt`; expanded green handoff coverage 13 passed / 72 assertions at `/tmp/production-handoff-expanded-green-0925.txt`.
- Full Production suite: 115 passed / 406 assertions at `/tmp/production-supervisor-suite-green-0925.txt`.
- Cross-module set: 91 passed and one obsolete MRP expectation at `/tmp/production-supervisor-integrations-0925.txt`. That expectation conflicted with the existing good-output target policy; the root-owned test fixture/assertion was corrected, then the focused MRP recheck passed 12 tests / 25 assertions at `/tmp/production-supervisor-mrp-recheck-0925.txt`. The focused MRP run overlaps 11 earlier passes and is not an additional unique-test count.
- Form options: 1 passed / 16 assertions at `/tmp/production-supervisor-form-options-0925.txt`.
- UI regression: detail component Vitest 1 passed at `/tmp/production-supervisor-ui-regression-0925.txt`.
- Work-order creation-class request regression: 1 focused test / 11 assertions recorded in the tool run against the explicit suite DB; no persistent host log was produced. It covers omitted class defaulting to standard and reason requirements for each explicit exception class.
- TypeScript: initial check passed at `/tmp/production-supervisor-typecheck-0925.txt`. The final production build/typecheck log is `/tmp/production-workorders-spa-build-PWO0925E9.txt`.
- Syntax and whitespace: final checks after the runner's label-only edit passed: `node --check scripts/production-workorders-headless.cjs`, `php -l` on the browser fixture and material-usage helper, and scoped `git diff --check`. The review document was separately checked for trailing whitespace. Final E10 report: 28 checkpoints, 6 screenshots, 0 JS errors; do not add partial-run checkpoints to this count.

## Existing edits and audit boundaries

The audit began with substantial unrelated shared-tree edits. Pre-existing Production/UI hardening—including the completion guard, good-output target guard, outgoing-QC trigger, detail target/produced order, and associated regression—was recorded before worker changes in `/tmp/production-preexisting-sha256-0925.txt` and `/tmp/production-preexisting-diff-0925.diff`. Two stale Production fixtures were repaired to record positive good output before completion, preserving their original machine/routing assertions. Concurrent changes to the WorkOrder model/events, MRP/NCR, and Inventory are preserved. The MRP stale-test fixture correction belongs to the root supervisor, not this worker. No RFQ/quotation code was changed.

The main raw-material assertion compares active issues with the stored BOM requirement. The main browser scenario issues BOM target 5, records 5 good plus 1 reject, and proves target, issue-ledger, output, costing, receipt, and lineage behavior. It does not recalculate raw demand from cumulative good-plus-reject output and does not prove extra physical raw consumption/replenishment for scrap. The run also does not exhaustively cover NCR flows, physical surplus returns, or partial unused-material-return policy branches. Delivery creation remains the next module's responsibility: the audit verifies passed, output-bound outgoing QC and posted finished goods as eligibility evidence, without creating a Delivery record.

## Safe isolated rerun

Use a fresh database name under the exact `ogami_test_production_workorders_browser_` prefix and a fresh 6+ character run ID every time. Never rerun against a browser DB with a fixture product or browser mutations. The fixture guard compares `DB_DATABASE` with `PRODUCTION_WORKORDERS_BROWSER_DB`; do not weaken it. The default below creates a fresh empty database and runs migrations with an explicit `DB_DATABASE` and unique nonexistent config-cache path. A clean clone may replace this only after verifying its schema is current and its app tables contain no rows. The example is Bash because it uses `${RUN_ID,,}` for lowercase cookie names:

```sh
DB_NAME=ogami_test_production_workorders_browser_e2e_YYMMDDx
RUN_ID=PWOYYMMDDX
API_IP=$(docker inspect -f '{{range.NetworkSettings.Networks}}{{.IPAddress}}{{end}}' ogami-api)
SPA_IP=$(docker inspect -f '{{range.NetworkSettings.Networks}}{{.IPAddress}}{{end}}' ogami-spa)
docker exec ogami-db createdb -U ogami -O ogami "$DB_NAME"
docker exec ogami-api test ! -e "/tmp/production-workorders-api-${RUN_ID}-bootstrap.php"
docker exec -w /var/www \
  -e DB_DATABASE="$DB_NAME" \
  -e APP_CONFIG_CACHE="/tmp/production-workorders-api-${RUN_ID}-bootstrap.php" \
  -e MAIL_MAILER=array -e QUEUE_CONNECTION=sync -e BROADCAST_CONNECTION=log \
  ogami-api php artisan migrate --force
docker exec -w /var/www \
  -e DB_DATABASE="$DB_NAME" \
  -e APP_CONFIG_CACHE="/tmp/production-workorders-api-${RUN_ID}-bootstrap.php" \
  -e MAIL_MAILER=array -e QUEUE_CONNECTION=sync -e BROADCAST_CONNECTION=log \
  -e PRODUCTION_WORKORDERS_BROWSER_DB="$DB_NAME" \
  -e PRODUCTION_WORKORDERS_RUN_ID="$RUN_ID" \
  -e PRODUCTION_WORKORDERS_FIXTURE_PATH="/tmp/production-workorders-fixture-${RUN_ID}.json" \
  ogami-api php artisan tinker --execute='require "tests/Browser/production_workorders_fixture.php";'
docker cp "ogami-api:/tmp/production-workorders-fixture-${RUN_ID}.json" "/tmp/production-workorders-fixture-${RUN_ID}.json"
```

All fixture/bootstrap/server commands pin `DB_DATABASE` to this fresh name and use `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`, and `BROADCAST_CONNECTION=log`. The migration and server each have their own unique nonexistent `APP_CONFIG_CACHE`. Do not generate or clear shared config caches. If you choose to clone a template instead, verify its current schema and empty app tables first.

The temporary Vite config is not committed. Recreate it exactly, then build into a run-specific container `/tmp` directory. The proxy target uses the inspected API container IP; do not assume an old address:

```sh
cat > spa/vite.production-audit.config.ts <<'TS'
import { defineConfig, mergeConfig } from 'vite';
import base from './vite.config';

const target = process.env.PRODUCTION_WORKORDERS_API_TARGET;
if (!target) throw new Error('PRODUCTION_WORKORDERS_API_TARGET must point to the temporary isolated API server.');
const proxy = {
  '/api': { target, changeOrigin: false, secure: false },
  '/sanctum': { target, changeOrigin: false, secure: false },
};

export default mergeConfig(base, defineConfig({
  build: { outDir: process.env.PRODUCTION_WORKORDERS_SPA_OUT_DIR || 'dist' },
  server: {
    port: 5210,
    strictPort: true,
    proxy,
    hmr: { protocol: 'ws', host: '127.0.0.1', clientPort: 5210 },
  },
  preview: { port: 5210, strictPort: true, proxy },
}));
TS

SPA_OUT="/tmp/production-workorders-spa-build-${RUN_ID}"
docker exec -w /app \
  -e PRODUCTION_WORKORDERS_API_TARGET="http://${API_IP}:8123" \
  -e PRODUCTION_WORKORDERS_SPA_OUT_DIR="$SPA_OUT" \
  ogami-spa npm run build -- --config vite.production-audit.config.ts --outDir "$SPA_OUT"
docker exec -d -w /app \
  -e PRODUCTION_WORKORDERS_API_TARGET="http://${API_IP}:8123" \
  -e PRODUCTION_WORKORDERS_SPA_OUT_DIR="$SPA_OUT" \
  ogami-spa ./node_modules/.bin/vite preview --config vite.production-audit.config.ts --host 0.0.0.0 --port 5210 --strictPort
socat TCP-LISTEN:5210,bind=127.0.0.1,reuseaddr,fork "TCP:${SPA_IP}:5210" &
SOCAT_PID=$!
```

Keep the normal Vite 5173 process untouched. Start the API in a separate, tracked terminal/session with an explicit database and `--no-reload`; the unique nonexistent `APP_CONFIG_CACHE` prevents reading or clearing shared config caches. Set the same mail/queue/broadcast values used for the fixture bootstrap:

Run the API as a worker-owned process with explicit env and `--no-reload` so the child retains the isolated configuration:

```sh
docker exec ogami-api test ! -e "/tmp/production-workorders-api-${RUN_ID}.php"
docker exec -w /var/www \
  -e DB_DATABASE="$DB_NAME" \
  -e APP_CONFIG_CACHE="/tmp/production-workorders-api-${RUN_ID}.php" \
  -e SESSION_COOKIE="pwo_audit_${RUN_ID,,}_session" \
  -e CACHE_PREFIX="pwo_audit_${RUN_ID,,}_" \
  -e FRONTEND_URL=http://127.0.0.1:5210 \
  -e SANCTUM_STATEFUL_DOMAINS=localhost,localhost:5210,127.0.0.1,127.0.0.1:5210 \
  -e MAIL_MAILER=array -e QUEUE_CONNECTION=sync -e BROADCAST_CONNECTION=log \
  ogami-api php artisan serve --host=0.0.0.0 --port=8123 --no-reload
```

Before running the browser, verify `http://127.0.0.1:5210/` and the proxied `http://127.0.0.1:5210/api/v1/health` return HTTP 200.

Copy the fixture manifest to the host before invoking the real-cookie browser runner:

```sh
NODE_PATH=./spa/node_modules \
PRODUCTION_WORKORDERS_FIXTURE_PATH="/tmp/production-workorders-fixture-${RUN_ID}.json" \
PRODUCTION_WORKORDERS_TEST_URL=http://127.0.0.1:5210 \
PRODUCTION_WORKORDERS_TEST_OUTPUT="/tmp/production-workorders-headless-${RUN_ID}-final" \
node scripts/production-workorders-headless.cjs
```

After preserving host evidence, stop only the exact API and preview processes created for that run, stop the recorded `SOCAT_PID`, and remove the temporary Vite config. Keep the isolated DB and `/tmp` reports/screenshots for review. Do not stop the shared API/DB containers or the normal SPA 5173 server.
