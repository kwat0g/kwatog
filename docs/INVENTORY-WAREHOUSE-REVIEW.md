# Inventory & Warehouse audit

Audit date: 2026-09-25. The audit used isolated PHPUnit and browser databases. It did not deploy or change real account passwords.

## Confirmed findings and fixes

| Severity | Confirmed evidence | Fix |
| --- | --- | --- |
| High | A real Warehouse user opening a material issue form made a Production work-order lookup that returned 403. The selector contained only the “None” option even when a work-order hash was in the URL. Submitting 5 units from stock fully reserved for that order then returned 422 (`available 0.000`). Captured before edits in `/tmp/ogami-inventory-warehouse-headless/report.json`. | Added a narrow Inventory options endpoint behind `inventory.issue.create`, returning only operational fields and supported work orders. It includes Confirmed, In Progress, and Paused orders because all three retain material demand. Issue creation locks and validates that status; Planned, Completed, Closed, and Cancelled are rejected. The Warehouse UI now uses this lookup and labels each status. |
| High | The form omitted the selected work order’s reservation ID, so the service treated its reserved stock as general stock and rejected the issue. API validation also required an integer although operational IDs are HashIDs. | The form now matches eligible reservations to the selected work order/item/location, sends the reservation HashID, requires an explicit choice when multiple reservations or reserved plus free stock make the choice meaningful, and still offers the unreserved balance. The request decodes the reservation HashID. A selected reservation remains usable when general availability is zero. |
| Medium | The material issue form requested 500 items while the API caps a page at 100, leaving later active items unreachable. | The form loads all active item pages. The real browser fixture places the selected item beyond page 1 and confirms selection and issue. The live transfer form uses the same complete-page pattern. |
| Medium | The material issue source picker used broad location data and did not reliably communicate current source eligibility. It could leave stale selection after item/work-order changes. | The scoped options lookup returns only active locations in active warehouses and excludes quarantine/scrap sources. It retains blocked sources for outbound movement, matching the receiving-only block rule. The UI uses available stock plus the selected work order’s own reservation, clears stale item/location/reservation values, and presents loading/error/retry and quantity guidance. Quarantine and scrap remain valid transfer destinations; blocked destinations are omitted. |
| Medium | The material issue UI allowed four decimal places although the API accepts three. General-availability/reservation client checks also needed to respect alternate issue UOMs. | The UI limit is three decimals. Client source comparisons use base quantities only when the user selected the base UOM; converted quantities remain service-validated, without rounding entered values. The form checks eligibility in its submit handler as well as disabling the button. |
| Medium | A linked material issue serialized an internal numeric work-order key and its list did not show the actual WO number. | The API emits a HashID plus compact `{id, wo_number}` relationship data; list/detail eager-load the number, and the list/picking UI render it. The regression asserts the linked work order. |
| Medium | A real GRN finalize response-loss reproduction committed the receipt but left the form looking Draft, making a retry misleading. | On ambiguous failure, the detail form refetches authoritative GRN state. If finalized, it displays the current Pending QC handoff and inspection without reposting. If still Draft, it retains line quantities/UOM for retry; a normal 422 likewise preserves input. |
| Low | The live transfer form asked the user to paste an Item ID, and its “Create & execute” label did not match the API: creation only made a Pending order. This was found by code review, not a pre-edit browser finding. | Replaced the free-text ID with an active item selector and available-stock source selector, scoped active destinations, failure/retry guidance, stale-selection clearing, and the accurate “Create transfer order” label. Execution remains the separate supported action. |

The initial location-picker observation also included a blocked source. That is deliberately not classified as invalid: blocked locations prevent receiving, while outbound movement is supported. The baseline observations and eligible/ineligible rules are distinguished in the report above.

## Workflow and handoffs

| Workflow | Ownership and verified handoff |
| --- | --- |
| PO receipt | Purchasing sends the PO. Warehouse selects the receiving bin and purchase UOM, then finalizes the GRN. Finalization stages incoming QC and keeps the received quantity out of available stock. |
| Incoming QC | QC records inspection evidence. The inspector cannot review their own result. An independent authorized reviewer approves it; the exercised account was the Production Manager. A pass accepts the GRN and releases base-UOM stock. |
| Reservations and material issue | Production owns the work-order demand/reservation. Warehouse selects a supported work order, item, eligible source, and either that order’s reservation or general available stock. Issue movements preserve WAC and reservation remainder. A cancelled issue restores on-hand quantity but preserves the remaining reservation; the returned quantity is available for an explicit replacement issue. |
| Transfers | Warehouse creates a Pending transfer order, then executes it as a separate step. Source eligibility is available stock; destinations follow active-location and receipt-block rules, with quarantine/scrap purpose-specific destinations supported. |
| Stock count and adjustment | Warehouse starts a count; movement is frozen for its scope. Warehouse records counts, a different Warehouse checker approves variance, then the session completes and posts the reconciliation. A separately gated manual adjustment is approved by Finance when above the configured threshold. |
| Stock ledger and accounting | GRN acceptance and adjustments update stock/WAC; stock-card and GL regression suites verify their ledger/accounting contracts. The browser journeys also compare location balances and stock-card closing value. |

## Verification

The full Inventory feature suite passed on private database `ogami_test_inventory_audit_suite_0925`: **301 tests, 1,182 assertions, 303.73 seconds**. This includes material-issue/reservation invariants and retry behavior, transfer races, stock-count freeze/maker-checker, UOM and WAC, `CycleCountWacTest`, `WeightedAvgCostTest`, `MovementGlPostingTest`, `GrnGlPostingTest`, and `StockCardServiceTest`.

Real-cookie, headless Chromium acceptance ran against `ogami_test_inventory_browser_delivery_0925`, using real Warehouse, Warehouse Checker, QC, Production Manager, and Finance accounts. Issue, transfer, and GRN flows used form interaction. QC, stock count, and approval transitions used authenticated browser API handoffs; those actions are identified here rather than described as form submissions.

- **Issue and recovery:** 20.000 on-hand / 20.000 reserved / 0.000 free; issue 5.000 with the reservation; a simulated lost response followed by the same idempotency key produced one issue and 15.000 / 15.000 / 0.000. Cancellation restored 20.000 on-hand while retaining 15.000 reserved (5.000 free); explicit available-stock reissue returned the location to 15.000 / 15.000 / 0.000. The list contained two slips (one cancelled, one replacement).
- **Transfer:** source moved from 6.000 to 4.000; destination from 0.000 to 2.000. The order moved Pending → Transferred. The fully reserved source and blocked destination were excluded; quarantine and scrap destinations remained selectable.
- **GRN, UOM, and QC:** invalid `1 BOX` returned 422 and preserved `1` plus `BOX`; corrected `1 BAG` converted to 5.000 KG. The commit response was replaced with a simulated 504; an authoritative GET and the rendered detail both showed Pending QC, with one linked inspection and no stock yet available. QC self-review returned 403; Production Manager review returned 200. Accepted stock was 5.000 / 0.000 reserved / 5.000 available, WAC 5.0000, stock-card value 25.00.
- **Count and Finance:** count opened with 4.000 units / 50.00 value. While In Progress, transfer execution returned 422 and stock remained 4.000; the pending test transfer was cancelled. Warehouse’s self-approval returned 422; the separate Warehouse checker approved the 4.000 → 3.000 variance and completed the session. Value became 37.50 at WAC 12.5000. A 1.000-unit adjustment entered Pending; Warehouse approval returned 403 and Finance approval succeeded. Warehouse readback showed 2.000 units / 25.00 value at WAC 12.5000. The stock-count variance ledger row was `adjustment_out`, quantity 1.000, `stock_count_session` reference.
- **Mobile:** material issue and transfer forms were exercised at 390px. All visible form controls stayed inside the viewport and document width equalled 390px; both saved screenshots are listed below.
- **Static checks:** `npm run typecheck` passed; PHP syntax checks passed for the modified Inventory service and feature regression; `node --check scripts/inventory-warehouse-headless.cjs` and `git diff --check` passed.

Browser reports and screenshots:

- `/tmp/ogami-inventory-warehouse-delivery-0925/issue-transfer/report.json`
- `/tmp/ogami-inventory-warehouse-delivery-0925/issue-transfer/success-material-issue-recovery.png`
- `/tmp/ogami-inventory-warehouse-delivery-0925/issue-transfer/success-transfer-order.png`
- `/tmp/ogami-inventory-warehouse-delivery-0925/grn-qc/report.json`
- `/tmp/ogami-inventory-warehouse-delivery-0925/grn-qc/success-grn-qc-accepted.png`
- `/tmp/ogami-inventory-warehouse-delivery-0925/stock-count/report.json`
- `/tmp/ogami-inventory-warehouse-delivery-0925/stock-count/success-stock-count-approved.png`
- `/tmp/ogami-inventory-warehouse-delivery-0925/mobile/report.json`
- `/tmp/ogami-inventory-warehouse-delivery-0925/mobile/mobile-material-issue.png`
- `/tmp/ogami-inventory-warehouse-delivery-0925/mobile/mobile-transfer.png`

The original failing browser baseline is preserved at `/tmp/ogami-inventory-warehouse-headless/report.json` and `/tmp/ogami-inventory-warehouse-headless/baseline-material-issue-reserved-stock.png`. Intermediate post-fix reports, including an earlier count attempt that correctly exposed Finance’s lack of stock-read permission, remain in their separate artifact directories; they are not counted as the final passing run.

## Safe browser rerun

The fixture refuses databases outside the `ogami_test_inventory_browser_` prefix. Its default database is the valid dedicated name `ogami_test_inventory_browser_audit_0925`; its default manifest is `/tmp/inventory-warehouse-browser-fixture.json`. The runner defaults to `issue-transfer` and writes to a unique `/tmp/ogami-inventory-warehouse-run-<timestamp>-<pid>` directory. `baseline` is only run when explicitly requested; always give it a separate output directory so the preserved baseline above is not overwritten. Run one audit at a time because the temporary services use fixed ports and a shared active-run environment file.

From the repository root, create a fresh uniquely named browser database (do not reset or reuse `ogami_test`, `ogami_test_inventory_audit_suite_0925`, or a database used by another run). This bootstrap creates a small environment file so the next terminal commands use the same names:

```bash
set -euo pipefail
RUN_ID="$(date -u +%Y%m%d%H%M%S%N)"
DB_NAME="ogami_test_inventory_browser_${RUN_ID}"
FIXTURE_CONTAINER="/tmp/inventory-warehouse-${RUN_ID}-fixture.json"
FIXTURE_HOST="$FIXTURE_CONTAINER"
OUTPUT_ROOT="/tmp/ogami-inventory-warehouse-${RUN_ID}"
ENV_FILE="/tmp/ogami-inventory-warehouse-active.env"
cat > "$ENV_FILE" <<EOF
RUN_ID='$RUN_ID'
DB_NAME='$DB_NAME'
FIXTURE_CONTAINER='$FIXTURE_CONTAINER'
FIXTURE_HOST='$FIXTURE_HOST'
OUTPUT_ROOT='$OUTPUT_ROOT'
SESSION_COOKIE='ogami_inventory_${RUN_ID}'
CACHE_PREFIX='ogami_inventory_${RUN_ID}'
EOF
chmod 600 "$ENV_FILE"
docker compose exec -T db psql -U ogami -d postgres -c "CREATE DATABASE ${DB_NAME} OWNER ogami;"
docker compose exec -T -e DB_DATABASE="$DB_NAME" api php artisan migrate --force
docker compose exec -T \
  -e DB_DATABASE="$DB_NAME" \
  -e INVENTORY_BROWSER_DB="$DB_NAME" \
  -e INVENTORY_BROWSER_FIXTURE="$FIXTURE_CONTAINER" \
  -e CACHE_PREFIX="ogami_inventory_${RUN_ID}" \
  -e QUEUE_CONNECTION=sync -e MAIL_MAILER=array -e BROADCAST_CONNECTION=log \
  api php artisan tinker --execute='require "tests/Browser/inventory_warehouse_fixture.php";'
docker compose exec -T api cat "$FIXTURE_CONTAINER" > "$FIXTURE_HOST"
printf 'Run environment: %s\nFixture: %s\nArtifacts: %s\n' "$ENV_FILE" "$FIXTURE_HOST" "$OUTPUT_ROOT"
```

Keep the following processes in separate terminals. The API command uses `--no-reload` so its child PHP server retains the isolated database and session settings:

Confirm ports 8123 and 5210 are free before starting these temporary services; do not terminate a process unless it is your prior audit server:

```bash
ss -ltnp | rg ':(8123|5210)\b'
```

```bash
cd /home/kwat0g/Desktop/kwatog
set -euo pipefail
source /tmp/ogami-inventory-warehouse-active.env
docker compose exec -T \
  -e APP_ENV=local -e APP_DEBUG=true \
  -e DB_DATABASE="$DB_NAME" \
  -e SESSION_DRIVER=database -e SESSION_COOKIE="$SESSION_COOKIE" \
  -e CACHE_PREFIX="$CACHE_PREFIX" \
  -e SANCTUM_STATEFUL_DOMAINS=localhost,127.0.0.1,localhost:5210,127.0.0.1:5210 \
  -e QUEUE_CONNECTION=sync -e MAIL_MAILER=array -e BROADCAST_CONNECTION=log \
  api php artisan serve --host=0.0.0.0 --port=8123 --no-reload
```

For the SPA terminal, inspect the current API container IP before writing the temporary proxy config (do not assume a previous container IP):

```bash
cd /home/kwat0g/Desktop/kwatog
source /tmp/ogami-inventory-warehouse-active.env
API_CONTAINER_IP="$(docker inspect "$(docker compose ps -q api)" --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}')"
cat > spa/vite.inventory-audit.config.ts <<EOF
import { mergeConfig } from 'vite';
import config from './vite.config';

export default mergeConfig(config, {
  server: {
    port: 5210,
    strictPort: true,
    proxy: {
      '/api': { target: 'http://$API_CONTAINER_IP:8123', changeOrigin: false, secure: false },
      '/sanctum': { target: 'http://$API_CONTAINER_IP:8123', changeOrigin: false, secure: false },
    },
    hmr: { protocol: 'ws', host: '127.0.0.1', clientPort: 5210 },
  },
});
EOF
cd spa
npm exec vite -- --config vite.inventory-audit.config.ts --host 0.0.0.0 --port 5210 --strictPort
```

After both servers are ready, run all phases in order against the same fixture/database. Separate per-phase directories keep each report and screenshot:

```bash
cd /home/kwat0g/Desktop/kwatog
set -euo pipefail
source /tmp/ogami-inventory-warehouse-active.env
for TEST_PHASE in issue-transfer grn-qc stock-count mobile; do
  NODE_PATH=./spa/node_modules \
    INVENTORY_TEST_PHASE="$TEST_PHASE" \
    INVENTORY_TEST_FIXTURE="$FIXTURE_HOST" \
    INVENTORY_TEST_OUTPUT="$OUTPUT_ROOT/$TEST_PHASE" \
    node scripts/inventory-warehouse-headless.cjs
done
```

When finished, stop the API and Vite with Ctrl-C in their terminals, then remove only the temporary proxy file. Keep the dedicated database and `/tmp` artifacts for review; do not drop/reset shared or normal-development databases:

```bash
cd /home/kwat0g/Desktop/kwatog
rm spa/vite.inventory-audit.config.ts
rm /tmp/ogami-inventory-warehouse-active.env
```

The historical `baseline` phase is retained as an explicit runner mode for use with a pre-fix checkout. On the fixed checkout, use the preserved pre-edit report and screenshot above as baseline evidence; do not run that phase expecting to reproduce old defects or point it at `/tmp/ogami-inventory-warehouse-headless`.

## Boundaries and policy notes

The browser acceptance did not exercise customer returns, supplier-return disposition, finished-goods receipts/delivery, real concurrent browser clients, or an actual mobile device. Existing feature-suite coverage exercises GRN rejection/supplier-return linkage, transfer concurrency, reservation concurrency, stock-count freeze, WAC, GL posting, and stock-card behavior; it does not substitute for those omitted end-to-end journeys. Email delivery and production integrations were not exercised.

The tested cancellation contract returns issued stock while keeping the reduced reservation; the Warehouse UI exposes an explicit general-stock reissue path. No reservation reopening was added. Transfer quarantine/scrap destinations and blocked outbound sources follow current backend movement rules. Finance can approve an adjustment but does not have stock-read access; Warehouse performed the authoritative closing-balance and stock-card readback. The count variance tolerance and adjustment threshold were the fixture’s isolated settings, not proposed business-policy changes.

The workspace had extensive unrelated pre-existing changes, including Return Management and RFQ work. Scoped before-edit snapshots are preserved at `/tmp/inventory-baseline-audit-0925.diff`, `/tmp/inventory-preexisting-files-0925.txt`, and `/tmp/inventory-preexisting-sha256-0925.txt`. Only the Inventory/UI/test files and this audit report were edited for this task; pre-existing changes in shared files were preserved. Temporary API/Vite test processes were stopped and `spa/vite.inventory-audit.config.ts` removed after verification.
