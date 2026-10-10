// Real-cookie Inventory acceptance runner.
//
//   node scripts/inventory-warehouse-headless.cjs
//
// With INVENTORY_TEST_BOOTSTRAP=1 the runner is self-contained: it creates
// ogami_test_inventory_browser_<run>, migrates it, seeds the role fixture
// (api/tests/Browser/inventory_warehouse_fixture.php), serves a temporary API
// and Vite proxy, runs every phase in order, then tears both down. Without the
// flag it points at INVENTORY_TEST_URL and INVENTORY_TEST_FIXTURE, as before.
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync, spawn } = require('node:child_process');
const { pathToFileURL } = require('node:url');
const { createRequire } = require('node:module');

const ROOT = path.resolve(__dirname, '..');
const SPA = path.join(ROOT, 'spa');
const spaRequire = createRequire(path.join(SPA, 'package.json'));
const { chromium, expect } = spaRequire('@playwright/test');

const BOOTSTRAP = process.env.INVENTORY_TEST_BOOTSTRAP === '1';
const RUN = (process.env.INVENTORY_TEST_RUN_ID || `INV${Date.now().toString(36)}`).toUpperCase().replace(/[^A-Z0-9]/g, '');
const DB = `ogami_test_inventory_browser_${RUN.toLowerCase()}`;
const INVENTORY_TAG = process.env.E2E_INVENTORY_TAG || (BOOTSTRAP ? RUN.slice(-6).toLowerCase() : undefined);
const TEST_STORAGE = `/tmp/ogami-audit-storage/inventory-warehouse-${RUN.toLowerCase()}`;
const API_PORT = Number(process.env.INVENTORY_TEST_API_PORT || 8210);
const SPA_PORT = Number(process.env.INVENTORY_TEST_SPA_PORT || 5210);
const SERVER_LOG_IN_CONTAINER = `/tmp/inventory-warehouse-${RUN.toLowerCase()}-server.log`;
const WORKFLOW_FIXTURE_IN_CONTAINER = INVENTORY_TAG
  ? `${TEST_STORAGE}/app/inventory-workflow-e2e-${INVENTORY_TAG}.json`
  : null;
let BASE = process.env.INVENTORY_TEST_URL || `http://127.0.0.1:${SPA_PORT}`;
let OUT_ROOT = process.env.INVENTORY_TEST_OUTPUT || path.join('/tmp', `ogami-inventory-warehouse-${RUN}`);
let OUT = OUT_ROOT;
let fixture;

/* ─── Self-contained environment (INVENTORY_TEST_BOOTSTRAP=1) ──────── */

const FIXTURE_IN_CONTAINER = `/tmp/inventory-warehouse-${RUN.toLowerCase()}-fixture.json`;
const ENV = {
  DB_DATABASE: DB, CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array',
  BROADCAST_CONNECTION: 'log', SESSION_DRIVER: 'database', APP_URL: BASE,
  LARAVEL_STORAGE_PATH: TEST_STORAGE,
  SESSION_SECURE_COOKIE: 'false', SESSION_SAME_SITE: 'lax',
  SANCTUM_STATEFUL_DOMAINS: `127.0.0.1:${SPA_PORT},localhost:${SPA_PORT}`,
  INVENTORY_BROWSER_DB: DB, INVENTORY_BROWSER_FIXTURE: FIXTURE_IN_CONTAINER,
  ...(INVENTORY_TAG ? { E2E_INVENTORY_TAG: INVENTORY_TAG } : {}),
};
const compose = (args) => execFileSync('docker', ['compose', ...args], { cwd: ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 64 * 1024 * 1024 });
const envFlags = () => Object.entries(ENV).flatMap(([k, v]) => ['-e', `${k}=${v}`]);
const artisan = (...args) => compose(['exec', '-T', ...envFlags(), 'api', 'php', 'artisan', ...args]);
const psql = (query, db = 'postgres') => compose(['exec', '-T', 'db', 'psql', '-U', 'ogami', '-d', db, '-At', '-c', query]).trim();
const apiIp = () => execFileSync('docker', ['inspect', '-f', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}', 'ogami-api'], { encoding: 'utf8' }).trim();

/** Free the port so a run killed before its teardown cannot poison this one. */
function stopApiServer() {
  for (const pattern of [`port=${API_PORT}`, `0.0.0.0:${API_PORT}`]) {
    try { compose(['exec', '-T', 'api', 'pkill', '-f', pattern]); } catch { /* not running */ }
  }
}

async function setUp() {
  fs.mkdirSync(OUT_ROOT, { recursive: true });
  stopApiServer();
  psql(`CREATE DATABASE ${DB} OWNER ogami;`);
  compose(['exec', '-T', 'api', 'mkdir', '-p', `${TEST_STORAGE}/app`, `${TEST_STORAGE}/app/public`, `${TEST_STORAGE}/framework/cache/data`, `${TEST_STORAGE}/framework/sessions`, `${TEST_STORAGE}/framework/views`, `${TEST_STORAGE}/logs`]);
  const actualStorage = artisan('tinker', '--execute', 'echo storage_path();').trim();
  if (actualStorage !== TEST_STORAGE) throw new Error(`Expected isolated Laravel storage ${TEST_STORAGE}; got ${actualStorage}.`);
  artisan('migrate', '--force');
  compose(['exec', '-T', 'api', 'rm', '-f', FIXTURE_IN_CONTAINER, SERVER_LOG_IN_CONTAINER]);
  artisan('tinker', '--execute', "require 'tests/Browser/inventory_warehouse_fixture.php';");
  compose(['cp', `api:${FIXTURE_IN_CONTAINER}`, path.join(OUT_ROOT, 'fixture.json')]);
  fixture = JSON.parse(fs.readFileSync(path.join(OUT_ROOT, 'fixture.json'), 'utf8'));
  if (INVENTORY_TAG && WORKFLOW_FIXTURE_IN_CONTAINER) {
    artisan('tinker', '--execute', "require 'tests/Browser/inventory_workflow_items_fixture.php';");
    compose(['cp', `api:${WORKFLOW_FIXTURE_IN_CONTAINER}`, path.join(OUT_ROOT, 'inventory-workflow.json')]);
  }
  compose(['exec', '-d', ...envFlags(), 'api', 'sh', '-lc', `php artisan serve --no-reload --host=0.0.0.0 --port=${API_PORT} > ${SERVER_LOG_IN_CONTAINER} 2>&1`]);
  const target = `http://${apiIp()}:${API_PORT}`;
  let ready = false;
  for (let i = 0; i < 60; i++) {
    try {
      const csrf = await fetch(`${target}/sanctum/csrf-cookie`);
      const user = await fetch(`${target}/api/v1/auth/user`, { headers: { Accept: 'application/json' } });
      if (csrf.status < 500 && user.status < 500) { ready = true; break; }
    } catch { /* booting */ }
    await new Promise((resolve) => setTimeout(resolve, 1000));
  }
  if (!ready) throw new Error(`The API on :${API_PORT} never answered against ${DB}.`);
  const vite = await import(pathToFileURL(path.join(SPA, 'node_modules/vite/dist/node/index.js')).href);
  // Tailwind resolves its content globs from the working directory, so run the
  // SPA toolchain from the SPA root; every runner path is absolute.
  process.chdir(SPA);
  const server = await vite.createServer({
    configFile: path.join(SPA, 'vite.config.ts'), root: SPA, logLevel: 'error',
    server: {
      host: '127.0.0.1', port: SPA_PORT, strictPort: true,
      hmr: { host: '127.0.0.1', port: SPA_PORT, clientPort: SPA_PORT },
      proxy: { '/api': { target, changeOrigin: false }, '/sanctum': { target, changeOrigin: false } },
    },
  });
  await server.listen();
  return server;
}

async function tearDown(server) {
  try { await server?.close(); } catch { /* already closed */ }
  try { compose(['cp', `api:${SERVER_LOG_IN_CONTAINER}`, path.join(OUT_ROOT, 'server.log')]); } catch { /* API did not start */ }
  if (process.env.INVENTORY_TEST_KEEP === '1' || process.exitCode) {
    console.log(`Kept ${DB} and API :${API_PORT} for inspection.`);
    return;
  }
  stopApiServer();
  try { compose(['exec', '-T', 'api', 'rm', '-f', FIXTURE_IN_CONTAINER, SERVER_LOG_IN_CONTAINER]); } catch { /* gone */ }
  try {
    psql(`SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='${DB}' AND pid <> pg_backend_pid();`);
    psql(`DROP DATABASE IF EXISTS ${DB};`);
  } catch (e) { console.log(`Could not drop ${DB}: ${e.message}`); }
  try { compose(['exec', '-T', 'api', 'rm', '-rf', TEST_STORAGE]); } catch (e) { console.log(`Could not remove ${TEST_STORAGE}: ${e.message}`); }
}

/* ─── Report state (reset per phase) ───────────────────────────────── */

let report = { database: '', checks: [], findings: [], artifacts: [] };
const errors = [];
const pages = [];
const diagnostics = { consoleErrors: [], failedRequests: [], httpErrors: [] };
const pass = (name, evidence) => {
  report.checks.push({ name, evidence });
  console.log('PASS:', name);
};
const failFinding = (severity, name, evidence) => {
  report.findings.push({ severity, name, evidence });
  console.log('FINDING:', name);
};

async function api(page, method, url, body, headers = {}) {
  return page.evaluate(async ({ method, url, body, headers }) => {
    const xsrf = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='))?.slice(11);
    const response = await fetch('/api/v1' + url, {
      method,
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
        ...headers,
      },
      ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
    });
    const text = await response.text();
    let data;
    try { data = JSON.parse(text); } catch { data = { raw: text }; }
    return { status: response.status, body: data };
  }, { method, url, body, headers });
}

async function login(browser, email, viewport = { width: 1440, height: 1000 }) {
  const context = await browser.newContext({ baseURL: BASE, viewport, reducedMotion: 'reduce' });
  const page = await context.newPage();
  page.setDefaultTimeout(20000);
  page.on('pageerror', (error) => errors.push(`${email}: ${error.message}`));
  page.on('console', (message) => {
    if (message.type() === 'error') diagnostics.consoleErrors.push({ email, message: message.text() });
  });
  page.on('requestfailed', (request) => {
    diagnostics.failedRequests.push({ email, method: request.method(), path: new URL(request.url()).pathname, error: request.failure()?.errorText });
  });
  page.on('response', (response) => {
    if (response.status() >= 400) diagnostics.httpErrors.push({ email, method: response.request().method(), path: new URL(response.url()).pathname, status: response.status() });
  });
  pages.push({ page, context, email });
  await page.goto('/sign-in');
  await page.getByLabel('Email', { exact: true }).fill(email);
  await page.getByLabel('Password', { exact: true }).fill(process.env.INVENTORY_TEST_PASSWORD || 'password');
  const responsePromise = page.waitForResponse((response) =>
    response.url().endsWith('/api/v1/auth/sign-in') && response.request().method() === 'POST');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  const response = await responsePromise;
  const loginBody = await response.text();
  expect(response.status(), `${email} login: ${loginBody}`).toBe(200);
  await expect(page).not.toHaveURL(/\/sign-in$/);
  pass(`Real cookie login: ${email}`);
  return page;
}

function dataOf(result) {
  return result.body?.data ?? result.body;
}

async function chooseInventoryItem(page, container, itemId, label = 'Item') {
  const result = await api(page, 'GET', `/inventory/items/${itemId}`);
  expect(result.status, JSON.stringify(result.body)).toBe(200);
  const item = dataOf(result);
  const control = container.getByRole('combobox', { name: label, exact: true });
  await control.fill(item.code);
  await page.getByRole('listbox').getByRole('option').filter({ hasText: item.code }).first().click();
}

async function runBaseline(browser) {
  const warehouse = await login(browser, 'warehouse@ogami.test');
  const stockResponse = await api(warehouse, 'GET', `/inventory/stock-levels?item_id=${fixture.reserved_item}&per_page=200`);
  expect(stockResponse.status).toBe(200);
  const stockLevel = dataOf(stockResponse).find((row) => row.location?.id === fixture.reservation_location);
  expect(stockLevel).toBeTruthy();
  expect(stockLevel.quantity).toBe('20.000');
  expect(stockLevel.reserved_quantity).toBe('20.000');
  expect(stockLevel.available).toBe('0.000');

  const supportingResponses = [];
  warehouse.on('response', (response) => {
    if (response.url().includes('/api/v1/production/work-orders')) {
      supportingResponses.push({ url: response.url(), status: response.status() });
    }
  });
  await warehouse.goto(`/inventory/material-issues/create?work_order_id=${fixture.work_order}`);
  await expect(warehouse.getByRole('heading', { name: 'New material issue' })).toBeVisible();
  const selects = warehouse.locator('form select');
  await expect.poll(async () => supportingResponses.length).toBeGreaterThan(0);
  const workOrderOptions = await selects.nth(0).locator('option').allTextContents();
  const warehouseCannotLoadWorkOrders = !workOrderOptions.some((label) => label.includes('WO-AUD-INV-0925'));
  if (warehouseCannotLoadWorkOrders) {
    await warehouse.getByLabel('Reference', { exact: true }).fill('Audit reserved material recovery');
  } else {
    await selects.nth(0).selectOption(fixture.work_order);
  }
  await selects.nth(1).selectOption(fixture.reserved_item);
  await selects.nth(2).selectOption(fixture.reservation_location);
  await warehouse.locator('input[placeholder="0"]').fill('5');

  const optionLabels = await selects.nth(2).locator('option').allTextContents();
  const pickerEvidence = {
    listedQuarantine: optionLabels.some((label) => label.includes('AUD-QUARANTINE')),
    listedScrap: optionLabels.some((label) => label.includes('AUD-SCRAP')),
    listedBlocked: optionLabels.some((label) => label.includes('AUD-BLOCKED')),
    listedInactive: optionLabels.some((label) => label.includes('AUD-INACTIVE')),
    listedInactiveWarehouse: optionLabels.some((label) => label.includes('AUD-OFF-BIN')),
    selectedReservedSource: optionLabels.some((label) => label.includes('AUD-RESERVED-BIN')),
    optionCount: optionLabels.length - 1,
  };

  const requestBody = [];
  warehouse.on('request', (request) => {
    if (request.url().endsWith('/api/v1/inventory/material-issues') && request.method() === 'POST') {
      requestBody.push(request.postDataJSON());
    }
  });
  const responsePromise = warehouse.waitForResponse((response) =>
    response.url().endsWith('/api/v1/inventory/material-issues') && response.request().method() === 'POST');
  await warehouse.getByRole('button', { name: 'Create issue', exact: true }).click();
  const response = await responsePromise;
  const responseText = await response.text();
  const body = JSON.parse(responseText);
  expect(response.status(), JSON.stringify(body)).toBe(422);
  expect(JSON.stringify(body)).toContain('available 0.000');
  expect(requestBody).toHaveLength(1);
  expect(requestBody[0].items[0]).not.toHaveProperty('material_reservation_id');
  const screenshot = path.join(OUT, 'baseline-material-issue-reserved-stock.png');
  await warehouse.screenshot({ path: screenshot, fullPage: true });
  report.artifacts.push(screenshot);

  failFinding('P1', 'Material issue form cannot issue stock reserved for its selected work order', {
    location: '/inventory/material-issues/create',
    workOrderOptions: { warehouseCannotLoad: warehouseCannotLoadWorkOrders, responses: supportingResponses },
    stock: { quantity: stockLevel.quantity, reserved: stockLevel.reserved_quantity, available: stockLevel.available },
    submitted: requestBody[0],
    response: { status: response.status(), body },
  });
  if (warehouseCannotLoadWorkOrders) {
    failFinding('P1', 'Warehouse work-order selector is backed by a Production-only read permission', {
      responses: supportingResponses,
      availableOptions: workOrderOptions,
    });
  }
  const invalidLocationFlags = [
    'listedQuarantine', 'listedScrap', 'listedInactive', 'listedInactiveWarehouse',
  ];
  if (invalidLocationFlags.some((key) => pickerEvidence[key])) {
    failFinding('P2', 'Material issue location picker exposes ineligible locations', pickerEvidence);
  }
  if (warehouseCannotLoadWorkOrders && supportingResponses.some(({ status }) => status === 403)) {
    pass('Observed Warehouse work-order support request denied with 403', { responses: supportingResponses });
  }
  pass('Captured real warehouse form submission and server refusal for fully reserved stock', {
    response: response.status(),
    message: body.message,
    picker: pickerEvidence,
  });
}

async function stockAt(page, itemId, locationId) {
  const response = await api(page, 'GET', `/inventory/stock-levels?item_id=${itemId}&per_page=100`);
  expect(response.status, JSON.stringify(response.body)).toBe(200);
  return dataOf(response).find((row) => row.location?.id === locationId) ?? null;
}

async function runIssueTransfer(browser) {
  const warehouse = await login(browser, 'warehouse@ogami.test');
  const openingReserved = await stockAt(warehouse, fixture.reserved_item, fixture.reservation_location);
  expect(openingReserved).toMatchObject({ quantity: '20.000', reserved_quantity: '20.000', available: '0.000' });

  // Select a real work order and an item that the old per_page=500 request
  // could not reach because ItemService caps page size at 100.
  await warehouse.goto(`/inventory/material-issues/create?work_order_id=${fixture.work_order}`);
  await expect(warehouse.getByRole('heading', { name: 'New material issue' })).toBeVisible();
  const workOrder = warehouse.getByRole('combobox', { name: 'Work order', exact: true });
  await expect(workOrder).toHaveValue(/WO-AUD-INV-0925/);
  await workOrder.click();
  const workOrderOption = warehouse.getByRole('listbox', { name: 'Work order' })
    .getByRole('option', { name: /^WO-AUD-INV-0925/ });
  await expect(workOrderOption).toBeVisible();
  await workOrderOption.click();
  await chooseInventoryItem(warehouse, warehouse.locator('form'), fixture.reserved_item, 'Item for line 1');
  const location = warehouse.getByRole('combobox', { name: 'Location for line 1', exact: true });
  await expect(location).toBeEnabled();
  await location.click();
  const reservedLocation = warehouse.getByRole('listbox', { name: 'Location for line 1' })
    .getByRole('option').filter({ hasText: 'AUD-RESERVED-BIN' });
  await expect(reservedLocation).toContainText('20.000 reserved');
  await reservedLocation.click();
  await warehouse.getByPlaceholder('0').fill('5');
  await expect(warehouse.getByText(/remain on this work-order reservation/)).toBeVisible();

  const requestKeys = [];
  let issueAttempts = 0;
  await warehouse.route('**/api/v1/inventory/material-issues', async (route) => {
    const request = route.request();
    if (request.method() !== 'POST') return route.continue();
    requestKeys.push((await request.allHeaders())['idempotency-key']);
    issueAttempts++;
    if (issueAttempts === 1) {
      const committed = await route.fetch();
      expect(committed.status()).toBe(201);
      await route.fulfill({ status: 504, contentType: 'application/json', body: '{"message":"simulated response loss after commit"}' });
      return;
    }
    await route.continue();
  });
  await warehouse.getByRole('button', { name: 'Create issue', exact: true }).click();
  await expect.poll(() => issueAttempts).toBe(1);
  await expect(warehouse.getByRole('button', { name: 'Create issue', exact: true })).toBeEnabled();
  await warehouse.getByRole('button', { name: 'Create issue', exact: true }).click();
  await expect.poll(() => issueAttempts).toBe(2);
  expect(requestKeys).toHaveLength(2);
  expect(requestKeys[0]).toBeTruthy();
  expect(requestKeys[1]).toBe(requestKeys[0]);
  const createdIssue = await api(warehouse, 'GET', '/inventory/material-issues?per_page=100');
  expect(createdIssue.status).toBe(200);
  const issueSlip = dataOf(createdIssue)[0];
  await expect(warehouse).toHaveURL(new RegExp(`/inventory/material-issues/${issueSlip.id}$`));
  expect(issueSlip.work_order.wo_number).toBe('WO-AUD-INV-0925');
  const afterIssue = await stockAt(warehouse, fixture.reserved_item, fixture.reservation_location);
  expect(afterIssue).toMatchObject({ quantity: '15.000', reserved_quantity: '15.000', available: '0.000' });
  pass('Material issue form handles page-two item, own fully reserved stock and response-loss retry', {
    opening: { quantity: openingReserved.quantity, reserved: openingReserved.reserved_quantity, available: openingReserved.available },
    afterSingleCommittedRetry: { quantity: afterIssue.quantity, reserved: afterIssue.reserved_quantity, available: afterIssue.available },
    issueAttempts,
    sameIdempotencyKey: requestKeys[0] === requestKeys[1],
    slip: issueSlip.slip_number,
    workOrder: issueSlip.work_order.wo_number,
  });

  const cancellation = await api(warehouse, 'DELETE', `/inventory/material-issues/${issueSlip.id}`);
  expect(cancellation.status, JSON.stringify(cancellation.body)).toBe(200);
  const afterCancel = await stockAt(warehouse, fixture.reserved_item, fixture.reservation_location);
  expect(afterCancel).toMatchObject({ quantity: '20.000', reserved_quantity: '15.000', available: '5.000' });
  await warehouse.goto(`/inventory/material-issues/create?work_order_id=${fixture.work_order}`);
  await chooseInventoryItem(warehouse, warehouse.locator('form'), fixture.reserved_item, 'Item for line 1');
  const reissueLocation = warehouse.getByRole('combobox', { name: 'Location for line 1', exact: true });
  await reissueLocation.click();
  await warehouse.getByRole('listbox', { name: 'Location for line 1' })
    .getByRole('option').filter({ hasText: 'AUD-RESERVED-BIN' }).click();
  const issueSource = warehouse.getByRole('combobox', { name: 'Issue from', exact: true });
  await issueSource.click();
  await warehouse.getByRole('listbox', { name: 'Issue from' })
    .getByRole('option', { name: /Available stock/ }).click();
  await warehouse.getByPlaceholder('0').fill('5');
  await warehouse.getByRole('button', { name: 'Create issue', exact: true }).click();
  const reissuedList = await api(warehouse, 'GET', '/inventory/material-issues?per_page=100');
  const replacementSlip = dataOf(reissuedList)[0];
  await expect(warehouse).toHaveURL(new RegExp(`/inventory/material-issues/${replacementSlip.id}$`));
  const afterReissue = await stockAt(warehouse, fixture.reserved_item, fixture.reservation_location);
  expect(afterReissue).toMatchObject({ quantity: '15.000', reserved_quantity: '15.000', available: '0.000' });
  pass('Issued-slip cancellation restores issued stock while preserving the remaining reservation, then supports reissue', {
    afterCancel: { quantity: afterCancel.quantity, reserved: afterCancel.reserved_quantity, available: afterCancel.available },
    afterReissue: { quantity: afterReissue.quantity, reserved: afterReissue.reserved_quantity, available: afterReissue.available },
    issueSlipCount: dataOf(await api(warehouse, 'GET', '/inventory/material-issues?per_page=100')).length,
  });
  const issueScreenshot = path.join(OUT, 'success-material-issue-recovery.png');
  await warehouse.screenshot({ path: issueScreenshot, fullPage: true });
  report.artifacts.push(issueScreenshot);
}

async function runTransfer(browser) {
  const warehouse = await login(browser, 'warehouse@ogami.test');
  // The live transfer-order form has a separate Execute step: first create a
  // pending document, then execute it and verify source/destination stock.
  const openingFree = await stockAt(warehouse, fixture.reserved_item, fixture.available_location);
  expect(openingFree).toMatchObject({ quantity: '6.000', reserved_quantity: '0.000', available: '6.000' });
  await warehouse.goto('/inventory/transfer-orders');
  await warehouse.getByRole('button', { name: 'New transfer' }).first().click();
  const modal = warehouse.getByRole('dialog');
  const transferForm = modal.locator('form');
  await chooseInventoryItem(warehouse, transferForm, fixture.reserved_item);
  const transferSource = transferForm.locator('select').nth(0);
  await expect(transferSource.locator(`option[value="${fixture.reservation_location}"]`)).toHaveCount(0);
  await transferSource.selectOption(fixture.available_location);
  const transferDestination = transferForm.locator('select').nth(1);
  await expect(transferDestination.locator(`option[value="${fixture.quarantine_location}"]`)).toHaveCount(1);
  await expect(transferDestination.locator(`option[value="${fixture.scrap_location}"]`)).toHaveCount(1);
  await expect(transferDestination.locator(`option[value="${fixture.blocked_location}"]`)).toHaveCount(0);
  await transferDestination.selectOption(fixture.transfer_location);
  await transferForm.locator('input[type="number"]').fill('2');
  await modal.getByRole('button', { name: 'Create transfer order', exact: true }).click();
  await expect(modal).toBeHidden();
  const transferList = await api(warehouse, 'GET', '/inventory/transfer-orders');
  expect(transferList.status).toBe(200);
  const transfer = dataOf(transferList).find((candidate) => candidate.from_location?.id === fixture.available_location && candidate.to_location?.id === fixture.transfer_location);
  expect(transfer, JSON.stringify(transferList.body)).toBeTruthy();
  const transferUrl = `/inventory/transfer-orders/${transfer.id}`;
  const transferNumber = transfer.transfer_number;
  const movementCount = () => psql(`select count(*) from stock_movements where reference_type='stock_transfer' and reference_id=(select id from transfer_orders where transfer_number='${transferNumber}')`, fixture.database);
  const auditCount = () => psql(`select count(*) from audit_logs where model_type like '%TransferOrder' and model_id=(select id from transfer_orders where transfer_number='${transferNumber}')`, fixture.database);
  const executionAuditCount = () => psql(`select count(*) from audit_logs where model_type like '%TransferOrder' and model_id=(select id from transfer_orders where transfer_number='${transferNumber}') and action='updated' and user_id=(select id from users where email='${fixture.warehouse_checker}') and old_values->>'status'='pending' and new_values->>'status'='transferred'`, fixture.database);
  expect(transfer).toMatchObject({ status: 'pending', quantity: '2.000', transferred_by: null });
  const sourceWhilePending = await stockAt(warehouse, fixture.reserved_item, fixture.available_location);
  const destinationWhilePending = await stockAt(warehouse, fixture.reserved_item, fixture.transfer_location);
  expect(sourceWhilePending).toMatchObject({ quantity: openingFree.quantity, reserved_quantity: '0.000', available: openingFree.available });
  expect(destinationWhilePending).toBeNull();
  expect(movementCount()).toBe('0');
  const pendingAuditCount = auditCount();
  expect(pendingAuditCount).toBe('1');
  await expect(warehouse.getByText('Another warehouse user must execute a transfer you created.', { exact: true })).toBeVisible();
  await expect(warehouse.getByRole('button', { name: 'Execute', exact: true })).toHaveCount(0);
  const creatorAttempt = await api(warehouse, 'POST', `${transferUrl}/execute`);
  expect(creatorAttempt.status).toBe(422);
  expect(creatorAttempt.body.message).toBe('A transfer order creator cannot execute their own transfer.');
  const afterCreatorAttempt = dataOf(await api(warehouse, 'GET', transferUrl));
  expect(afterCreatorAttempt).toMatchObject({ status: 'pending', transferred_by: null });
  expect(await stockAt(warehouse, fixture.reserved_item, fixture.available_location)).toEqual(sourceWhilePending);
  expect(await stockAt(warehouse, fixture.reserved_item, fixture.transfer_location)).toBeNull();
  expect(movementCount()).toBe('0');
  expect(auditCount()).toBe(pendingAuditCount);
  pass('Transfer creator sees the next-actor explanation; direct self-execution is rejected with no stock, movement, or audit change', {
    status: afterCreatorAttempt.status,
    response: creatorAttempt.status,
    movementCount: movementCount(),
    auditCount: auditCount(),
  });

  const checker = await login(browser, fixture.warehouse_checker);
  await checker.goto('/inventory/transfer-orders');
  const checkerTransfer = checker.getByRole('button', { name: new RegExp(transfer.transfer_number) });
  await expect(checkerTransfer).toBeVisible();
  await checkerTransfer.click();
  await expect(checker.getByRole('button', { name: 'Execute', exact: true })).toBeVisible();
  await checker.getByRole('button', { name: 'Execute', exact: true }).click();
  const executeDialog = checker.getByRole('dialog');
  const executeResponsePromise = checker.waitForResponse((response) =>
    response.url().endsWith(`/api/v1${transferUrl}/execute`) && response.request().method() === 'POST');
  await executeDialog.getByRole('button', { name: 'Execute', exact: true }).click();
  const executeResponse = await executeResponsePromise;
  expect(executeResponse.status(), await executeResponse.text()).toBe(200);
  const completedTransfer = dataOf(await api(checker, 'GET', transferUrl));
  expect(completedTransfer).toMatchObject({ status: 'transferred', transferred_by: { id: (await dataOf(await api(checker, 'GET', '/auth/user'))).id } });
  const afterTransferSource = await stockAt(checker, fixture.reserved_item, fixture.available_location);
  const afterTransferDestination = await stockAt(checker, fixture.reserved_item, fixture.transfer_location);
  expect(afterTransferSource).toMatchObject({ quantity: '4.000', reserved_quantity: '0.000', available: '4.000' });
  expect(afterTransferDestination).toMatchObject({ quantity: '2.000', reserved_quantity: '0.000', available: '2.000' });
  expect(movementCount()).toBe('1');
  expect(auditCount()).toBe(String(Number(pendingAuditCount) + 1));
  expect(executionAuditCount()).toBe('1');
  await expect(checker.getByRole('button', { name: 'Execute', exact: true })).toHaveCount(0);
  const completedAuditCount = auditCount();
  const duplicateExecution = await api(checker, 'POST', `${transferUrl}/execute`);
  expect(duplicateExecution.status).toBe(422);
  expect(duplicateExecution.body.message).toBe('Transfer order is not pending.');
  expect(dataOf(await api(checker, 'GET', transferUrl))).toMatchObject({ status: 'transferred', transferred_by: completedTransfer.transferred_by });
  expect(await stockAt(checker, fixture.reserved_item, fixture.available_location)).toEqual(afterTransferSource);
  expect(await stockAt(checker, fixture.reserved_item, fixture.transfer_location)).toEqual(afterTransferDestination);
  expect(movementCount()).toBe('1');
  expect(auditCount()).toBe(completedAuditCount);
  pass('Independent Warehouse checker executes once; duplicate execute is rejected without another movement or audit', {
    sourceOpening: openingFree.quantity,
    sourceClosing: afterTransferSource.quantity,
    destinationOpening: '0.000',
    destinationClosing: afterTransferDestination.quantity,
    reservedSourceExcluded: true,
    blockedDestinationExcluded: true,
    quarantineAndScrapDestinationsPresent: true,
    movementCount: movementCount(),
    status: completedTransfer.status,
    transferredBy: completedTransfer.transferred_by.name,
  });
  const transferScreenshot = path.join(OUT, 'success-transfer-order.png');
  await checker.screenshot({ path: transferScreenshot, fullPage: true });
  report.artifacts.push(transferScreenshot);
}

async function runGrnQc(browser) {
  const warehouse = await login(browser, 'warehouse@ogami.test');
  const opening = await stockAt(warehouse, fixture.receipt_item, fixture.receipt_location);
  expect(opening).toBeNull();
  await warehouse.goto(`/inventory/grn/${fixture.grn}`);
  const initialGrn = await api(warehouse, 'GET', `/inventory/grn/${fixture.grn}`);
  if (dataOf(initialGrn).status === 'pending_qc') {
    pass('Recovered GRN is server-confirmed Pending QC with inventory still excluded', {
      status: dataOf(initialGrn).status,
      handoff: dataOf(initialGrn).incoming_qc_handoff.status,
      qcInspection: dataOf(initialGrn).qc_inspection?.inspection_number,
      stockBeforeQc: opening?.quantity ?? '0.000',
    });
    await runQcRelease(browser);
    return;
  }
  await expect(warehouse.getByRole('button', { name: 'Finalize receiving', exact: true })).toBeVisible();
  const line = await api(warehouse, 'GET', `/inventory/grn/${fixture.grn}`);
  expect(line.status).toBe(200);
  const grnLine = dataOf(line).items[0];
  const itemCode = grnLine.item.code;
  await warehouse.getByLabel(`Bin ${itemCode}`).selectOption(fixture.receipt_location);
  await warehouse.getByLabel(`Entered quantity ${itemCode}`).fill('5');
  await expect(warehouse.getByLabel('Entered quantity unit (base)', { exact: true })).toHaveValue(grnLine.item.unit_of_measure);

  const finalizeUrl = `**/api/v1/inventory/grn/${fixture.grn}/finalize`;
  let finalizeAttempts = 0;
  await warehouse.route(finalizeUrl, async (route) => {
    if (route.request().method() !== 'PATCH') return route.continue();
    finalizeAttempts++;
    if (finalizeAttempts === 2) {
      const committed = await route.fetch();
      expect(committed.status()).toBe(200);
      await route.fulfill({ status: 504, contentType: 'application/json', body: '{"message":"simulated GRN response loss after commit"}' });
      return;
    }
    await route.continue();
  });

  // A real server-side validation rejection must leave the GRN in Draft and
  // preserve the entered line values so the receiver can correct and retry.
  await warehouse.getByLabel(`Entered quantity ${itemCode}`).fill('999999');
  const validationResponsePromise = warehouse.waitForResponse((response) =>
    response.url().includes(`/inventory/grn/${fixture.grn}/finalize`) && response.request().method() === 'PATCH');
  await warehouse.getByRole('button', { name: 'Finalize receiving', exact: true }).click();
  await warehouse.getByRole('dialog').getByRole('button', { name: 'Finalize GRN', exact: true }).click();
  const validationResponse = await validationResponsePromise;
  expect(validationResponse.status()).toBe(422);
  await expect(warehouse.getByLabel(`Entered quantity ${itemCode}`)).toHaveValue('999999');
  await expect(warehouse.getByRole('button', { name: 'Finalize receiving', exact: true })).toBeVisible();
  pass('GRN validation failure preserves entered quantity and locked base UOM', {
    invalidQuantity: '999999',
    response: validationResponse.status(),
    valuesPreserved: { quantity: '999999', uom: grnLine.item.unit_of_measure },
    correctedQuantity: '5',
  });

  await warehouse.getByLabel(`Entered quantity ${itemCode}`).fill('5');
  const lostResponsePromise = warehouse.waitForResponse((response) =>
    response.url().includes(`/inventory/grn/${fixture.grn}/finalize`) && response.request().method() === 'PATCH');
  await warehouse.getByRole('button', { name: 'Finalize receiving', exact: true }).click();
  await warehouse.getByRole('dialog').getByRole('button', { name: 'Finalize GRN', exact: true }).click();
  const lostResponse = await lostResponsePromise;
  expect(lostResponse.status()).toBe(504);
  await expect(warehouse.getByRole('button', { name: 'Finalize receiving', exact: true })).toBeHidden();
  const pendingQc = await api(warehouse, 'GET', `/inventory/grn/${fixture.grn}`);
  expect(pendingQc.status).toBe(200);
  expect(dataOf(pendingQc).status).toBe('pending_qc');
  expect(dataOf(pendingQc).incoming_qc_handoff.status).toBe('generated');
  const stillNoStock = await stockAt(warehouse, fixture.receipt_item, fixture.receipt_location);
  expect(stillNoStock).toBeNull();
  await expect(warehouse.getByText('Pending QC', { exact: true })).toBeVisible();
  await expect(warehouse.getByText(new RegExp(`QC: ${dataOf(pendingQc).qc_inspection.inspection_number}`))).toBeVisible();

  const recovered = pendingQc;
  expect(dataOf(recovered).status).toBe('pending_qc');
  expect(dataOf(recovered).items).toHaveLength(1);
  pass('Lost GRN response reconciles to the committed handoff without a second submission', {
    receivedBaseQuantity: `5 ${grnLine.item.unit_of_measure}`,
    convertedBaseQuantity: dataOf(recovered).items[0].quantity_received,
    pendingQcStatus: dataOf(recovered).status,
    handoff: dataOf(recovered).incoming_qc_handoff.status,
    inspection: dataOf(recovered).qc_inspection?.inspection_number,
    stockExcludedUntilQcPass: stillNoStock === null,
    requests: { normalValidationFailure: validationResponse.status(), lostResponse: lostResponse.status(), totalFinalizeAttempts: finalizeAttempts },
    qcInspectionId: dataOf(recovered).qc_inspection?.id,
  });

  await runQcRelease(browser);
}

async function runQcRelease(browser) {
  const warehouse = await login(browser, 'warehouse@ogami.test');
  const recovered = await api(warehouse, 'GET', `/inventory/grn/${fixture.grn}`);
  expect(dataOf(recovered).status).toBe('pending_qc');
  const qc = await login(browser, 'qc@ogami.test');
  const linkedInspections = await api(qc, 'GET', `/quality/inspections?entity_type=grn&entity_id=${fixture.grn}&per_page=100`);
  expect(linkedInspections.status).toBe(200);
  const grnInspectionRows = dataOf(linkedInspections);
  expect(grnInspectionRows).toHaveLength(1);
  expect(grnInspectionRows[0].id).toBe(dataOf(recovered).qc_inspection.id);
  const inspectionResponse = await api(qc, 'GET', `/quality/inspections/${dataOf(recovered).qc_inspection.id}`);
  expect(inspectionResponse.status, JSON.stringify(inspectionResponse.body)).toBe(200);
  const inspection = dataOf(inspectionResponse);
  expect(inspection.status).toBe('draft');
  expect(inspection.measurements.length).toBeGreaterThan(0);
  const checklistItems = inspection.measurements
    .filter((row, index, rows) => rows.findIndex(other => other.spec_id === row.spec_id && other.parameter_name === row.parameter_name) === index)
    .map(row => ({id: row.id, result: 'passed'}));
  const itemResults = await api(qc, 'POST', `/quality/inspections/${inspection.id}/item-results`, {
    items: checklistItems,
    submit: false,
    expected_result_version: inspection.result_version,
  });
  expect(itemResults.status, JSON.stringify(itemResults.body)).toBe(200);
  const savedDraft = dataOf(itemResults);
  expect(savedDraft.result_version).toBe(inspection.result_version + 1);
  expect(savedDraft.status).toBe('in_progress');
  const staleDraft = await api(qc, 'POST', `/quality/inspections/${inspection.id}/item-results`, {
    items: checklistItems, submit: false, expected_result_version: inspection.result_version,
  });
  expect(staleDraft.status).toBe(422);
  expect(JSON.stringify(staleDraft.body)).toContain('This inspection changed. Reload before submitting.');
  const afterStaleDraft = await api(qc, 'GET', `/quality/inspections/${inspection.id}`);
  expect(dataOf(afterStaleDraft)).toMatchObject({ status: 'in_progress', result_version: savedDraft.result_version });
  expect(dataOf(afterStaleDraft).measurements.map(row => row.item_result))
    .toEqual(savedDraft.measurements.map(row => row.item_result));
  const record = await api(qc, 'POST', `/quality/inspections/${inspection.id}/batch-result`, {
    items: checklistItems,
    expected_result_version: savedDraft.result_version,
    coverage_confirmed: true,
    inspected_quantity: inspection.sample_size,
  });
  expect(record.status, JSON.stringify(record.body)).toBe(200);
  const awaitingReview = dataOf(record);
  expect(awaitingReview.status).toBe('awaiting_review');
  expect(awaitingReview.result_version).toBe(savedDraft.result_version + 1);
  const selfReview = await api(qc, 'PATCH', `/quality/inspections/${inspection.id}/review`, {
    decision: 'passed', remarks: 'Self review should be denied.',
    expected_result_version: awaitingReview.result_version,
  });
  expect(selfReview.status).toBe(403);
  const afterSelfReview = await api(qc, 'GET', `/quality/inspections/${inspection.id}`);
  expect(dataOf(afterSelfReview)).toMatchObject({
    status: 'awaiting_review', result_version: awaitingReview.result_version, reviewer: null,
  });

  const productionApprover = await login(browser, 'production@ogami.test');
  const independentReview = await api(productionApprover, 'PATCH', `/quality/inspections/${inspection.id}/review`, {
    decision: 'passed', remarks: 'Independent incoming QC approval.',
    expected_result_version: awaitingReview.result_version,
  });
  expect(independentReview.status, JSON.stringify(independentReview.body)).toBe(200);
  expect(dataOf(independentReview).status).toBe('passed');
  const settledGrn = await api(warehouse, 'GET', `/inventory/grn/${fixture.grn}`);
  expect(dataOf(settledGrn).status).toBe('accepted');
  const acceptedStock = await stockAt(warehouse, fixture.receipt_item, fixture.receipt_location);
  expect(acceptedStock).toMatchObject({ quantity: '5.000', reserved_quantity: '0.000', available: '5.000' });
  expect(Number(acceptedStock.weighted_avg_cost)).toBeCloseTo(5, 4);
  const card = await api(warehouse, 'GET', `/inventory/items/${fixture.receipt_item}/stock-card?location_id=${fixture.receipt_location}`);
  expect(card.status, JSON.stringify(card.body)).toBe(200);
  pass('QC actor records pass evidence; a separate Production Manager checker releases accepted stock at converted quantity and cost', {
    makerRole: 'Warehouse receiving actor',
    qcRole: 'QC result maker',
    checkerRole: 'Production Manager independent approver',
    selfReviewStatus: selfReview.status,
    actualLinkedInspectionCount: grnInspectionRows.length,
    independentReviewStatus: independentReview.status,
    grnStatus: dataOf(settledGrn).status,
    opening: { quantity: '0.000', value: '0.00' },
    accepted: { quantity: acceptedStock.quantity, reserved: acceptedStock.reserved_quantity, available: acceptedStock.available, weightedAverageCost: acceptedStock.weighted_avg_cost },
    stockCard: dataOf(card),
  });
  await warehouse.goto(`/inventory/grn/${fixture.grn}`);
  await expect(warehouse.getByRole('heading', { name: dataOf(settledGrn).grn_number })).toBeVisible();
  await expect(warehouse.locator('span').filter({ hasText: /^Accepted$/ }).first()).toBeVisible();
  const screenshot = path.join(OUT, 'success-grn-qc-accepted.png');
  await warehouse.screenshot({ path: screenshot, fullPage: true });
  report.artifacts.push(screenshot);
}

async function runStockCount(browser) {
  const warehouse = await login(browser, 'warehouse@ogami.test');
  const sourceBefore = await stockAt(warehouse, fixture.reserved_item, fixture.available_location);
  expect(sourceBefore).toMatchObject({ quantity: '4.000', reserved_quantity: '0.000', available: '4.000' });
  const created = await api(warehouse, 'POST', '/inventory/stock-counts', {
    title: 'Inventory audit cycle count',
    scope: 'zone',
    zone_id: fixture.count_zone,
  });
  expect(created.status, JSON.stringify(created.body)).toBe(201);
  const sessionId = dataOf(created).id;
  const started = await api(warehouse, 'POST', `/inventory/stock-counts/${sessionId}/start`);
  expect(started.status, JSON.stringify(started.body)).toBe(200);
  const transferDuringCount = await api(warehouse, 'POST', '/inventory/transfer-orders', {
    item_id: fixture.reserved_item,
    from_location_id: fixture.available_location,
    to_location_id: fixture.transfer_location,
    quantity: '1.000',
    reason: 'Verify active cycle-count freeze',
  });
  expect(transferDuringCount.status, JSON.stringify(transferDuringCount.body)).toBe(201);
  const frozenExecute = await api(warehouse, 'POST', `/inventory/transfer-orders/${dataOf(transferDuringCount).id}/execute`);
  expect(frozenExecute.status).toBe(422);
  expect((await stockAt(warehouse, fixture.reserved_item, fixture.available_location)).quantity).toBe('4.000');
  const cancelFrozenTransfer = await api(warehouse, 'DELETE', `/inventory/transfer-orders/${dataOf(transferDuringCount).id}`);
  expect(cancelFrozenTransfer.status).toBe(204);
  const session = await api(warehouse, 'GET', `/inventory/stock-counts/${sessionId}`);
  expect(session.status).toBe(200);
  const countLines = dataOf(session).items;
  expect(countLines.length).toBeGreaterThan(0);
  const target = countLines.find((line) => line.item.id === fixture.reserved_item && line.location.id === fixture.available_location);
  expect(target).toBeTruthy();
  const countedValues = [];
  for (const row of countLines) {
    const count = row.id === target.id ? '3.000' : row.system_quantity;
    const response = await api(warehouse, 'POST', `/inventory/stock-counts/items/${row.id}/count`, { counted_quantity: count });
    expect(response.status, JSON.stringify(response.body)).toBe(200);
    countedValues.push({ item: row.item.code, location: row.location.full_code, system: row.system_quantity, counted: count });
  }
  const selfApproval = await api(warehouse, 'POST', `/inventory/stock-counts/items/${target.id}/approve`);
  expect([403, 422]).toContain(selfApproval.status);
  const unchangedBeforeIndependentApproval = await stockAt(warehouse, fixture.reserved_item, fixture.available_location);
  expect(unchangedBeforeIndependentApproval.quantity).toBe('4.000');

  const checker = await login(browser, 'warehouse-checker@ogami.test');
  const approved = await api(checker, 'POST', `/inventory/stock-counts/items/${target.id}/approve`);
  expect(approved.status, JSON.stringify(approved.body)).toBe(200);
  expect(dataOf(approved).status).toBe('verified');
  const sessionNumber = dataOf(await api(checker, 'GET', `/inventory/stock-counts/${sessionId}`)).session_number;
  const sessionAuditCount = () => psql(`select count(*) from audit_logs where model_type like '%StockCountSession' and model_id=(select id from stock_count_sessions where session_number='${sessionNumber}')`, fixture.database);
  const auditCountBeforeBlockedCompletion = sessionAuditCount();
  const checkerCompletion = await api(checker, 'POST', `/inventory/stock-counts/${sessionId}/complete`);
  expect(checkerCompletion.status).toBe(422);
  expect(checkerCompletion.body.message).toBe('A different user must complete a stock count after recording or approving a line.');
  const afterCheckerCompletionAttempt = dataOf(await api(checker, 'GET', `/inventory/stock-counts/${sessionId}`));
  expect(afterCheckerCompletionAttempt.status).toBe('in_progress');
  expect(afterCheckerCompletionAttempt.items.find((line) => line.id === target.id).verified_by.id).toBe(dataOf(await api(checker, 'GET', '/auth/user')).id);
  expect(await stockAt(checker, fixture.reserved_item, fixture.available_location)).toEqual(unchangedBeforeIndependentApproval);
  const checkerMovements = dataOf(await api(checker, 'GET', `/inventory/stock-movements?item_id=${fixture.reserved_item}&per_page=100`));
  expect(checkerMovements.filter((movement) => movement.reference_type === 'stock_count_session')).toHaveLength(0);
  expect(sessionAuditCount()).toBe(auditCountBeforeBlockedCompletion);
  const completer = await login(browser, fixture.warehouse_finisher);
  const completed = await api(completer, 'POST', `/inventory/stock-counts/${sessionId}/complete`);
  expect(completed.status, JSON.stringify(completed.body)).toBe(200);
  expect(dataOf(completed).status).toBe('completed');
  expect(dataOf(completed).approved_by.id).toBe(dataOf(await api(completer, 'GET', '/auth/user')).id);
  const completedAuditCount = psql(`select count(*) from audit_logs where model_type like '%StockCountSession' and model_id=(select id from stock_count_sessions where session_number='${sessionNumber}') and action='updated' and old_values->>'status'='in_progress' and new_values->>'status'='completed' and user_id=(select id from users where email='${fixture.warehouse_finisher}')`, fixture.database);
  expect(completedAuditCount).toBe('1');
  const sourceAfter = await stockAt(completer, fixture.reserved_item, fixture.available_location);
  expect(sourceAfter).toMatchObject({ quantity: '3.000', reserved_quantity: '0.000', available: '3.000' });
  const card = await api(completer, 'GET', `/inventory/items/${fixture.reserved_item}/stock-card?location_id=${fixture.available_location}`);
  expect(card.status, JSON.stringify(card.body)).toBe(200);
  expect(dataOf(card).closing.balance).toBe('3.000');
  expect(dataOf(card).closing.value).toBe('37.50');
  const movements = await api(completer, 'GET', `/inventory/stock-movements?item_id=${fixture.reserved_item}&per_page=100`);
  expect(movements.status).toBe(200);
  const adjustmentMovement = dataOf(movements).find((movement) =>
    movement.movement_type === 'adjustment_out'
    && movement.reference_type === 'stock_count_session'
    && movement.from_location?.id === fixture.available_location
    && movement.quantity === '1.000');
  expect(adjustmentMovement).toBeTruthy();
  expect(dataOf(movements).filter((movement) => movement.reference_type === 'stock_count_session')).toHaveLength(1);
  const duplicateCompletion = await api(completer, 'POST', `/inventory/stock-counts/${sessionId}/complete`);
  expect(duplicateCompletion.status).toBe(422);
  expect(duplicateCompletion.body.message).toBe('Session must be in progress to complete.');
  expect(dataOf(await api(completer, 'GET', `/inventory/stock-counts/${sessionId}`)).status).toBe('completed');
  expect(await stockAt(completer, fixture.reserved_item, fixture.available_location)).toEqual(sourceAfter);
  expect(dataOf(await api(completer, 'GET', `/inventory/stock-movements?item_id=${fixture.reserved_item}&per_page=100`)).filter((movement) => movement.reference_type === 'stock_count_session')).toHaveLength(1);
  expect(sessionAuditCount()).toBe(String(Number(auditCountBeforeBlockedCompletion) + 1));
  const adjustmentOptions = await api(warehouse, 'GET', '/inventory/stock-adjustments/options');
  expect(adjustmentOptions.status).toBe(200);
  const reasonCode = dataOf(adjustmentOptions).reasons[0].value;
  const pendingAdjustment = await api(warehouse, 'POST', '/inventory/stock-adjustments', {
    item_id: fixture.reserved_item,
    location_id: fixture.available_location,
    direction: 'out',
    quantity: '1.000',
    reason_code: reasonCode,
    reason: 'Inventory audit Finance approval check',
  });
  expect(pendingAdjustment.status, JSON.stringify(pendingAdjustment.body)).toBe(201);
  expect(dataOf(pendingAdjustment).status).toBe('pending');
  expect((await stockAt(warehouse, fixture.reserved_item, fixture.available_location)).quantity).toBe('3.000');
  const warehouseDeniedApproval = await api(warehouse, 'PATCH', `/inventory/stock-adjustments/${dataOf(pendingAdjustment).id}/approve`);
  expect(warehouseDeniedApproval.status).toBe(403);
  const finance = await login(browser, 'finance@ogami.test');
  const financeApproval = await api(finance, 'PATCH', `/inventory/stock-adjustments/${dataOf(pendingAdjustment).id}/approve`);
  expect(financeApproval.status, JSON.stringify(financeApproval.body)).toBe(200);
  expect(dataOf(financeApproval).status).toBe('approved');
  // Finance owns the approval, while Warehouse owns operational stock reads.
  const afterFinanceApproval = await stockAt(warehouse, fixture.reserved_item, fixture.available_location);
  expect(afterFinanceApproval).toMatchObject({ quantity: '2.000', reserved_quantity: '0.000', available: '2.000' });
  const afterFinanceCard = await api(warehouse, 'GET', `/inventory/items/${fixture.reserved_item}/stock-card?location_id=${fixture.available_location}`);
  expect(dataOf(afterFinanceCard).closing.balance).toBe('2.000');
  expect(dataOf(afterFinanceCard).closing.value).toBe('25.00');
  pass('Warehouse stock count freezes a zone; separate checker approves variance, third actor completes; Finance approves gated adjustment', {
    maker: 'warehouse@ogami.test',
    checker: 'warehouse-checker@ogami.test',
    completer: fixture.warehouse_finisher,
    lines: countedValues,
    freeze: { attemptedTransfer: transferDuringCount.status, executeDuringCount: frozenExecute.status, unchangedQuantity: '4.000', cancelled: cancelFrozenTransfer.status },
    variance: { system: target.system_quantity, counted: '3.000', delta: '-1.000', approvalRequired: true },
    makerSelfApprovalStatus: selfApproval.status,
    independentApprovalStatus: approved.status,
    checkerCompletionDeniedStatus: checkerCompletion.status,
    checkerCompletionDeniedMessage: checkerCompletion.body.message,
    stockUnchangedAfterCheckerCompletionAttempt: unchangedBeforeIndependentApproval.quantity,
    duplicateCompletionDeniedStatus: duplicateCompletion.status,
    duplicateCompletionDeniedMessage: duplicateCompletion.body.message,
    completionAuditCount: completedAuditCount,
    sessionStatus: dataOf(completed).status,
    opening: { quantity: sourceBefore.quantity, value: '50.00' },
    afterCountApproval: { quantity: sourceAfter.quantity, value: dataOf(card).closing.value, weightedAverageCost: dataOf(card).closing.weighted_avg },
    adjustment: { statusBeforeFinance: dataOf(pendingAdjustment).status, warehouseApprovalStatus: warehouseDeniedApproval.status, financeStatus: dataOf(financeApproval).status },
    closing: { quantity: afterFinanceApproval.quantity, value: dataOf(afterFinanceCard).closing.value, weightedAverageCost: dataOf(afterFinanceCard).closing.weighted_avg },
    stockCardMovementCount: dataOf(card).rows.length,
    stockCountVarianceMovement: { id: adjustmentMovement.id, type: adjustmentMovement.movement_type, quantity: adjustmentMovement.quantity, referenceType: adjustmentMovement.reference_type },
  });
  await completer.goto('/inventory/stock-count');
  await completer.getByRole('button').filter({ hasText: 'Inventory audit cycle count' }).click();
  await expect(completer.getByText('Completed', { exact: true })).toBeVisible();
  const screenshot = path.join(OUT, 'success-stock-count-approved.png');
  await completer.screenshot({ path: screenshot, fullPage: true });
  report.artifacts.push(screenshot);
}

async function runMrb(browser) {
  const maker = await login(browser, 'warehouse@ogami.test');
  const makerUser = dataOf(await api(maker, 'GET', '/auth/user'));
  const sourceBefore = await stockAt(maker, fixture.reserved_item, fixture.available_location);
  const quarantineBefore = await stockAt(maker, fixture.reserved_item, fixture.quarantine_location);
  expect(Number(sourceBefore.quantity)).toBeGreaterThanOrEqual(2);
  expect(quarantineBefore).toBeNull();

  await maker.goto('/inventory/mrb');
  await expect(maker.getByRole('heading', { name: 'MRB / Quarantine' })).toBeVisible();
  await maker.getByRole('button', { name: 'Raise hold', exact: true }).first().click();
  const holdDialog = maker.getByRole('dialog', { name: 'Raise MRB hold' });
  await chooseInventoryItem(maker, holdDialog, fixture.reserved_item);
  await holdDialog.getByLabel('Source location').selectOption(fixture.available_location);
  await holdDialog.getByLabel('Quantity').fill('1');
  await holdDialog.getByLabel('Quarantine location').selectOption(fixture.quarantine_location);
  await holdDialog.getByLabel('Notes').fill('Independent MRB maker-checker audit');
  const holdResponsePromise = maker.waitForResponse((response) =>
    response.url().endsWith('/api/v1/inventory/mrb') && response.request().method() === 'POST');
  await holdDialog.getByRole('button', { name: 'Raise hold', exact: true }).click();
  const holdResponse = await holdResponsePromise;
  const holdBody = await holdResponse.json();
  expect(holdResponse.status(), JSON.stringify(holdBody)).toBe(201);
  const heldRecord = holdBody.data;
  expect(heldRecord).toMatchObject({
    status: 'held',
    held_by_id: makerUser.id,
    item: { id: fixture.reserved_item },
    source_location: { id: fixture.available_location },
    quarantine_location: { id: fixture.quarantine_location },
  });
  const movementCount = () => psql(`select count(*) from stock_movements where reference_type='material_review_record' and reference_id=(select id from material_review_records where mrb_number='${heldRecord.mrb_number}')`, fixture.database);
  const auditCount = () => psql(`select count(*) from audit_logs where model_type like '%MaterialReviewRecord' and model_id=(select id from material_review_records where mrb_number='${heldRecord.mrb_number}')`, fixture.database);
  const sourceAfterHold = await stockAt(maker, fixture.reserved_item, fixture.available_location);
  const quarantineAfterHold = await stockAt(maker, fixture.reserved_item, fixture.quarantine_location);
  const sourceAfterHoldQuantity = (Number(sourceBefore.quantity) - 1).toFixed(3);
  expect(sourceAfterHold).toMatchObject({ quantity: sourceAfterHoldQuantity, reserved_quantity: '0.000', available: sourceAfterHoldQuantity });
  expect(quarantineAfterHold).toMatchObject({ quantity: '1.000', reserved_quantity: '0.000', available: '1.000' });
  expect(movementCount()).toBe('1');
  const auditAfterHold = auditCount();
  expect(Number(auditAfterHold)).toBeGreaterThan(0);

  const selfRelease = await api(maker, 'POST', `/inventory/mrb/${heldRecord.id}/release`, {
    disposition: 'use_as_is',
    target_location_id: fixture.available_location,
  });
  expect(selfRelease.status).toBe(422);
  expect(selfRelease.body.message).toBe('A different user must release an MRB hold than the one who placed it.');
  const heldAfterSelfAttempt = dataOf(await api(maker, 'GET', `/inventory/mrb/${heldRecord.id}`));
  expect(heldAfterSelfAttempt).toMatchObject({ status: 'held', held_by_id: makerUser.id, release_movement_id: null, released_by: null });
  expect(await stockAt(maker, fixture.reserved_item, fixture.available_location)).toEqual(sourceAfterHold);
  expect(await stockAt(maker, fixture.reserved_item, fixture.quarantine_location)).toEqual(quarantineAfterHold);
  expect(movementCount()).toBe('1');
  expect(auditCount()).toBe(auditAfterHold);
  await maker.goto(`/inventory/mrb/${heldRecord.id}`);
  await expect(maker.getByRole('status').filter({ hasText: 'Another authorized user must release a hold you created.' })).toBeVisible();
  await expect(maker.getByRole('button', { name: 'Release', exact: true })).toHaveCount(0);

  const checker = await login(browser, fixture.warehouse_checker);
  await checker.goto(`/inventory/mrb/${heldRecord.id}`);
  await expect(checker.getByRole('button', { name: 'Release', exact: true })).toBeVisible();
  await checker.getByRole('button', { name: 'Release', exact: true }).click();
  const releaseDialog = checker.getByRole('dialog');
  await releaseDialog.getByLabel('Disposition').selectOption('use_as_is');
  await releaseDialog.getByLabel('Completed reinspection evidence reference').fill('WAREHOUSE-INDEPENDENT-REINSPECTION');
  await releaseDialog.getByLabel('Completed reinspection confirms all held material passes every requirement.').check();
  const targetSelect = releaseDialog.getByLabel('Target good location');
  await expect(targetSelect.locator(`option[value="${fixture.available_location}"]`)).toHaveCount(1);
  await targetSelect.selectOption(fixture.available_location);
  await releaseDialog.getByLabel('Notes').fill('Independent checker release');
  const releaseResponsePromise = checker.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/inventory/mrb/${heldRecord.id}/release`) && response.request().method() === 'POST').catch(() => null);
  await releaseDialog.getByRole('button', { name: 'Release', exact: true }).click();
  const releaseResponse = await releaseResponsePromise;
  if (!releaseResponse) throw new Error(`MRB release produced no API request. Dialog: ${await releaseDialog.innerText()}`);
  const releaseBody = await releaseResponse.json();
  expect(releaseResponse.status(), JSON.stringify(releaseBody)).toBe(200);
  const releasedRecord = releaseBody.data;
  const checkerUser = dataOf(await api(checker, 'GET', '/auth/user'));
  expect(releasedRecord).toMatchObject({
    status: 'released',
    disposition: 'use_as_is',
    held_by_id: makerUser.id,
    released_by: checkerUser.name,
    release_location: { id: fixture.available_location },
  });
  expect(releasedRecord.release_movement_id).toBeTruthy();
  const sourceAfterRelease = await stockAt(checker, fixture.reserved_item, fixture.available_location);
  const quarantineAfterRelease = await stockAt(checker, fixture.reserved_item, fixture.quarantine_location);
  expect(sourceAfterRelease).toMatchObject({ quantity: sourceBefore.quantity, reserved_quantity: '0.000', available: sourceBefore.quantity });
  expect(quarantineAfterRelease).toMatchObject({ quantity: '0.000', reserved_quantity: '0.000', available: '0.000' });
  expect(movementCount()).toBe('2');
  const releaseAuditCount = psql(`select count(*) from audit_logs where model_type like '%MaterialReviewRecord' and model_id=(select id from material_review_records where mrb_number='${heldRecord.mrb_number}') and action='updated' and user_id=(select id from users where email='${fixture.warehouse_checker}') and new_values->>'status'='released'` , fixture.database);
  expect(releaseAuditCount).toBe('1');
  const completedAuditCount = auditCount();
  const duplicateRelease = await api(checker, 'POST', `/inventory/mrb/${heldRecord.id}/release`, {
    disposition: 'use_as_is',
    target_location_id: fixture.available_location,
  });
  expect(duplicateRelease.status).toBe(422);
  expect(duplicateRelease.body.message).toBe(`MRB ${heldRecord.mrb_number} is not held (status: released).`);
  expect(dataOf(await api(checker, 'GET', `/inventory/mrb/${heldRecord.id}`)).status).toBe('released');
  expect(await stockAt(checker, fixture.reserved_item, fixture.available_location)).toEqual(sourceAfterRelease);
  expect(await stockAt(checker, fixture.reserved_item, fixture.quarantine_location)).toEqual(quarantineAfterRelease);
  expect(movementCount()).toBe('2');
  expect(auditCount()).toBe(completedAuditCount);
  await expect(checker.getByRole('button', { name: 'Release', exact: true })).toHaveCount(0);
  pass('MRB hold moves stock to quarantine; creator is blocked by UI and API; independent checker releases once with audit', {
    maker: makerUser.email,
    checker: checkerUser.email,
    mrbNumber: heldRecord.mrb_number,
    held: { source: sourceAfterHold.quantity, quarantine: quarantineAfterHold.quantity, movementCount: '1', auditCount: auditAfterHold },
    creatorAttempt: { status: selfRelease.status, message: selfRelease.body.message, state: heldAfterSelfAttempt.status, unchanged: true },
    released: { status: releasedRecord.status, disposition: releasedRecord.disposition, source: sourceAfterRelease.quantity, quarantine: quarantineAfterRelease.quantity, movementCount: movementCount(), auditCount: releaseAuditCount },
    duplicateAttempt: { status: duplicateRelease.status, message: duplicateRelease.body.message, unchanged: true },
  });
  const screenshot = path.join(OUT, 'success-mrb-maker-checker.png');
  await checker.screenshot({ path: screenshot, fullPage: true });
  report.artifacts.push(screenshot);
}

async function assertMobileControls(page, label) {
  const evidence = await page.evaluate(() => {
    const width = document.documentElement.clientWidth;
    const controls = [...document.querySelectorAll('form input, form select, form textarea, form button')]
      .filter((node) => node.getClientRects().length > 0)
      .map((node) => {
        const rect = node.getBoundingClientRect();
        return { tag: node.tagName.toLowerCase(), label: node.getAttribute('aria-label') ?? node.getAttribute('name') ?? node.textContent?.trim().slice(0, 30), left: Math.round(rect.left), right: Math.round(rect.right), width: Math.round(rect.width) };
      });
    return { viewport: width, document: document.documentElement.scrollWidth, controls };
  });
  expect(evidence.document, `${label} has horizontal page overflow: ${JSON.stringify(evidence)}`).toBeLessThanOrEqual(evidence.viewport);
  expect(evidence.controls.length, `${label} has no visible form controls`).toBeGreaterThan(0);
  for (const control of evidence.controls) {
    expect(control.left, `${label} control is off the left edge: ${JSON.stringify(control)}`).toBeGreaterThanOrEqual(0);
    expect(control.right, `${label} control exceeds viewport: ${JSON.stringify(control)}`).toBeLessThanOrEqual(evidence.viewport);
  }
  pass(`${label} controls fit a 390px viewport`, evidence);
}

function runRealSpaTests(name, files) {
  const output = path.join(OUT_ROOT, name.replaceAll(/[^a-z0-9-]/gi, '-'));
  fs.mkdirSync(output, { recursive: true });
  const playwright = path.join(SPA, 'node_modules', '.bin', 'playwright');
  const fixturePath = BOOTSTRAP ? path.join(OUT_ROOT, 'fixture.json') : process.env.INVENTORY_TEST_FIXTURE;
  const child = spawn(playwright, [
    'test', '--config=playwright.real.config.ts', '--project=real-chromium',
    '--workers=1', '--reporter=list', `--output=${output}`,
    ...files,
  ], {
    cwd: SPA,
    env: {
      ...process.env,
      E2E_BASE_URL: BASE,
      ...(INVENTORY_TAG ? { E2E_INVENTORY_TAG: INVENTORY_TAG } : {}),
      ...(fixturePath ? { E2E_INVENTORY_FIXTURE_PATH: fixturePath } : {}),
      ...(WORKFLOW_FIXTURE_IN_CONTAINER && BOOTSTRAP
        ? { E2E_INVENTORY_MANIFEST: path.join(OUT_ROOT, 'inventory-workflow.json') }
        : {}),
    },
    stdio: 'inherit',
  });
  return new Promise((resolve, reject) => {
    child.once('error', reject);
    child.once('close', (code, signal) => {
      if (code !== 0) {
        reject(new Error(`Playwright ${name} failed (${signal || code})`));
        return;
      }
      pass(name, { output, workers: 1, files });
      resolve();
    });
  });
}

async function runStockCountUi() {
  await runRealSpaTests('stock-count-ui', ['e2e-real/stock-count-chain.spec.ts']);
}

async function runOtherInventoryUi() {
  await runRealSpaTests('other-inventory-ui', [
    'e2e-real/inventory-adjustment-approval.spec.ts',
    'e2e-real/inventory-transfer-movements.spec.ts',
    'e2e-real/uom-management.spec.ts',
    'e2e-real/item-uom-edit.spec.ts',
  ]);
}

async function runQualityHeldAdjustmentUi() {
  await runRealSpaTests('quality-held-adjustment-ui', [
    'e2e-real/quality-held-stock-adjustment.spec.ts',
  ]);
}

async function runMobile(browser) {
  const warehouse = await login(browser, 'warehouse@ogami.test', { width: 390, height: 844 });
  await warehouse.goto(`/inventory/material-issues/create?work_order_id=${fixture.work_order}`);
  const issueSelects = warehouse.locator('form select');
  await issueSelects.nth(0).selectOption(fixture.work_order);
  await chooseInventoryItem(warehouse, warehouse.locator('form'), fixture.reserved_item, 'Item for line 1');
  const issueOptions = dataOf(await api(
    warehouse,
    'GET',
    `/inventory/material-issues/options?work_order_id=${encodeURIComponent(fixture.work_order)}&item_id=${encodeURIComponent(fixture.reserved_item)}`,
  ));
  const reservedSource = issueOptions.sources.find((source) => source.location_id === fixture.reservation_location);
  expect(reservedSource).toBeDefined();
  expect(Number(reservedSource.reserved_for_work_order)).toBeGreaterThan(0);
  const sourcePicker = warehouse.getByRole('combobox', { name: 'Location for line 1' });
  await expect(sourcePicker).toBeEnabled();
  await sourcePicker.click();
  const reservedLocationOption = warehouse.getByRole('listbox', { name: 'Location for line 1' })
    .getByRole('option').filter({ hasText: 'AUD-RESERVED-BIN' });
  await expect(reservedLocationOption).toBeVisible();
  await reservedLocationOption.click();
  await expect(warehouse.getByText(/remain on this work-order reservation/))
    .toContainText(reservedSource.reserved_for_work_order);
  await assertMobileControls(warehouse, 'Material issue form');
  const issueScreenshot = path.join(OUT, 'mobile-material-issue.png');
  await warehouse.screenshot({ path: issueScreenshot, fullPage: true });
  report.artifacts.push(issueScreenshot);

  await warehouse.goto('/inventory/transfer-orders');
  await warehouse.getByRole('button', { name: 'New transfer' }).first().click();
  const modal = warehouse.getByRole('dialog');
  const selects = modal.locator('form select');
  await chooseInventoryItem(warehouse, modal, fixture.reserved_item);
  await expect(selects.nth(0).locator(`option[value="${fixture.available_location}"]`)).toHaveCount(1);
  await selects.nth(0).selectOption(fixture.available_location);
  await selects.nth(1).selectOption(fixture.transfer_location);
  await modal.locator('input[type="number"]').fill('1');
  await assertMobileControls(warehouse, 'Transfer form');
  const transferScreenshot = path.join(OUT, 'mobile-transfer.png');
  await warehouse.screenshot({ path: transferScreenshot, fullPage: true });
  report.artifacts.push(transferScreenshot);
}

(async () => {
  const phases = (BOOTSTRAP
    ? (process.env.INVENTORY_TEST_PHASES || 'issue-transfer,grn-qc,stock-count,mrb,stock-count-ui,other-inventory-ui,quality-held-adjustment-ui,mobile')
    : (process.env.INVENTORY_TEST_PHASE || 'issue-transfer')).split(',').map((value) => value.trim()).filter(Boolean);
  let server;
  try {
    if (BOOTSTRAP) server = await setUp();
    else fixture = JSON.parse(fs.readFileSync(process.env.INVENTORY_TEST_FIXTURE || '/tmp/inventory-warehouse-browser-fixture.json', 'utf8'));
  } catch (error) {
    console.error(error);
    process.exitCode = 1;
    if (BOOTSTRAP) await tearDown(server);
    return;
  }
  const browser = await chromium.launch({ headless: true, args: ['--disable-dev-shm-usage', '--no-sandbox'] });
  try {
    for (const phase of phases) {
      OUT = BOOTSTRAP && phases.length > 1 ? path.join(OUT_ROOT, phase) : OUT_ROOT;
      fs.mkdirSync(OUT, { recursive: true });
      report = { database: fixture.database, phase, checks: [], findings: [], artifacts: [] };
      errors.length = 0;
      pages.length = 0;
      diagnostics.consoleErrors.length = 0;
      diagnostics.failedRequests.length = 0;
      diagnostics.httpErrors.length = 0;
      if (phase === 'baseline') await runBaseline(browser);
      else if (phase === 'issue-transfer') { await runIssueTransfer(browser); await runTransfer(browser); }
      else if (phase === 'transfer') await runTransfer(browser);
      else if (phase === 'grn-qc') await runGrnQc(browser);
      else if (phase === 'stock-count') await runStockCount(browser);
      else if (phase === 'mrb') await runMrb(browser);
      else if (phase === 'stock-count-ui') await runStockCountUi();
      else if (phase === 'other-inventory-ui') await runOtherInventoryUi();
      else if (phase === 'quality-held-adjustment-ui') await runQualityHeldAdjustmentUi();
      else if (phase === 'mobile') await runMobile(browser);
      else throw new Error(`Unknown INVENTORY_TEST_PHASE: ${phase}`);
      report.browserErrors = errors;
      report.diagnostics = {
        consoleErrors: diagnostics.consoleErrors,
        failedRequests: diagnostics.failedRequests,
        httpErrors: diagnostics.httpErrors,
      };
      report.serverLog = BOOTSTRAP ? path.join(OUT_ROOT, 'server.log') : null;
      expect(errors, 'No uncaught browser errors').toEqual([]);
      const unexpectedHttpErrors = diagnostics.httpErrors.filter(({ method, path: requestPath, status }) => !(
        (method === 'GET' && requestPath === '/api/v1/auth/user' && status === 401)
        || (method === 'POST' && requestPath === '/api/v1/inventory/material-issues' && status === 504)
        || (method === 'POST' && /^\/api\/v1\/inventory\/transfer-orders\/[^/]+\/execute$/.test(requestPath) && status === 422)
        || (method === 'POST' && /^\/api\/v1\/inventory\/stock-counts\/[^/]+\/complete$/.test(requestPath) && status === 422)
        || (method === 'POST' && /^\/api\/v1\/inventory\/stock-counts\/items\/[^/]+\/approve$/.test(requestPath) && status === 422)
        || (method === 'PATCH' && /^\/api\/v1\/inventory\/stock-adjustments\/[^/]+\/approve$/.test(requestPath) && status === 403)
        || (method === 'POST' && /^\/api\/v1\/inventory\/mrb\/[^/]+\/release$/.test(requestPath) && status === 422)
        || (method === 'PATCH' && /^\/api\/v1\/inventory\/grn\/[^/]+\/finalize$/.test(requestPath) && [422, 504].includes(status))
        || (method === 'PATCH' && /^\/api\/v1\/quality\/inspections\/[^/]+\/review$/.test(requestPath) && [403, 422].includes(status))
        || (method === 'POST' && /^\/api\/v1\/quality\/inspections\/[^/]+\/item-results$/.test(requestPath) && status === 422)
      ));
      expect(unexpectedHttpErrors, 'Every HTTP error must match an asserted test probe').toEqual([]);
      const staleChecklistWrites = diagnostics.httpErrors.filter(({ method, path: requestPath, status }) =>
        method === 'POST' && /^\/api\/v1\/quality\/inspections\/[^/]+\/item-results$/.test(requestPath) && status === 422,
      );
      if (phase === 'grn-qc') expect(staleChecklistWrites, 'one stale checklist draft must be rejected').toHaveLength(1);
      const httpErrorCounts = new Map();
      for (const { email, status } of diagnostics.httpErrors) {
        const key = `${email}:${status}`;
        httpErrorCounts.set(key, (httpErrorCounts.get(key) ?? 0) + 1);
      }
      const consoleErrorCounts = new Map();
      const unexplainedConsoleErrors = diagnostics.consoleErrors.filter(({ email, message }) => {
        const status = message.match(/status of (\d+)/)?.[1];
        if (!status) return true;
        const key = `${email}:${status}`;
        consoleErrorCounts.set(key, (consoleErrorCounts.get(key) ?? 0) + 1);
        return false;
      });
      expect(unexplainedConsoleErrors, 'Console errors must match a captured HTTP response').toEqual([]);
      expect(Object.fromEntries(consoleErrorCounts), 'Console response errors must match the HTTP error counts').toEqual(Object.fromEntries(httpErrorCounts));
      expect(diagnostics.failedRequests, 'No failed browser requests').toEqual([]);
      const reportPath = path.join(OUT, 'report.json');
      fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));
      console.log(`REPORT: ${reportPath}`);
    }
  } catch (error) {
    report.failure = error instanceof Error ? error.stack : String(error);
    for (let index = 0; index < pages.length; index++) {
      const { page } = pages[index];
      if (page.isClosed()) continue;
      try {
        const screenshot = path.join(OUT, `failure-${index + 1}.png`);
        await page.screenshot({ path: screenshot, fullPage: true });
        report.artifacts.push(screenshot);
      } catch { /* preserve the original assertion as the failure */ }
    }
    const reportPath = path.join(OUT, 'report.json');
    fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));
    console.error(`REPORT: ${reportPath}`);
    throw error;
  } finally {
    await Promise.all(pages.map(({ context }) => context.close()));
    await browser.close();
    if (BOOTSTRAP) await tearDown(server);
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
