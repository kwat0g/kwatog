// Browser-authenticated Delivery / Dispatch acceptance flow.
// The fixture creates prerequisite records only. Run against an explicitly
// selected, migrated database with a temporary API + Vite proxy.
const { chromium, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { randomUUID } = require('node:crypto');

const BASE = process.env.DISPATCH_TEST_URL || 'http://127.0.0.1:5210';
const FIXTURE_PATH = process.env.DISPATCH_FIXTURE_PATH;
if (!FIXTURE_PATH) throw new Error('Set DISPATCH_FIXTURE_PATH to the fixture manifest.');
const fixture = JSON.parse(fs.readFileSync(FIXTURE_PATH, 'utf8'));
const RUN = fixture.run_id;
const OUT = process.env.DISPATCH_TEST_OUTPUT
  || path.join('/tmp', `delivery-completion-headless-${RUN}-${Date.now()}`);
fs.mkdirSync(OUT, { recursive: false });

const report = {
  run_id: RUN,
  database: fixture.database,
  base_url: BASE,
  authentication: 'Sanctum session cookie + CSRF cookie; no Bearer token or localStorage auth',
  websocket: 'Inert Pusher-protocol transport acknowledges connect/subscription/ping only; no real-time business events are sent',
  checks: [],
  failures: [],
  handoffs: [],
  artifacts: [],
};
const live = [];
const jsErrors = [];
const diagnosticsByPage = new WeakMap();
let echoWebsocketSessions = 0;
let websocketSubscriptionsAcknowledged = 0;
let websocketPingsAnswered = 0;
const echoWebsocketUrls = [];
let sharedBrowser;

function passed(name, evidence = {}) {
  report.checks.push({ name, evidence });
  console.log(`PASS: ${name}`);
}

function dataOf(result) {
  return result.body?.data ?? result.body;
}

async function request(page, method, route, body) {
  return page.evaluate(async ({ method, route, body }) => {
    const xsrf = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='))?.slice(11);
    const response = await fetch(`/api/v1${route}`, {
      method,
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
      },
      ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
    });
    const text = await response.text();
    let data;
    try { data = JSON.parse(text); } catch { data = { raw: text }; }
    return { status: response.status, body: data };
  }, { method, route, body });
}

async function login(email, viewport = { width: 1440, height: 1000 }) {
  if (!sharedBrowser) sharedBrowser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  const context = await sharedBrowser.newContext({ baseURL: BASE, viewport, timezoneId: 'Asia/Manila', reducedMotion: 'reduce' });
  await context.routeWebSocket((url) => {
    const candidate = new URL(url);
    return (candidate.port === '8080' || candidate.port === '443') && candidate.pathname.startsWith('/app/');
  }, (socket) => {
    echoWebsocketSessions++;
    echoWebsocketUrls.push(socket.url());
    socket.onMessage((message) => {
      if (typeof message !== 'string') return;
      let frame;
      try { frame = JSON.parse(message); } catch { return; }
      if (frame.event === 'pusher:ping') {
        websocketPingsAnswered++;
        socket.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
      } else if (frame.event === 'pusher:subscribe' && frame.data?.channel) {
        websocketSubscriptionsAcknowledged++;
        socket.send(JSON.stringify({
          event: 'pusher_internal:subscription_succeeded',
          channel: frame.data.channel,
          data: '{}',
        }));
      }
    });
    socket.send(JSON.stringify({
      event: 'pusher:connection_established',
      data: JSON.stringify({ socket_id: `9900925.${echoWebsocketSessions}`, activity_timeout: 120 }),
    }));
  });
  const page = await context.newPage();
  page.setDefaultTimeout(20_000);
  const diagnostics = { pageErrors: [], consoleErrors: [], failedRequests: [], errorResponses: [], mainFrameNavigations: [], websocketConnections: [], crashes: [] };
  diagnosticsByPage.set(page, diagnostics);
  const appendDiagnostic = (rows, value) => {
    if (rows.length < 100) rows.push(value);
  };
  page.on('pageerror', (error) => {
    jsErrors.push(`${email}: ${error.message}`);
    appendDiagnostic(diagnostics.pageErrors, { message: error.message, stack: error.stack ?? null, url: page.url() });
  });
  page.on('console', (message) => {
    if (message.type() === 'error') appendDiagnostic(diagnostics.consoleErrors, {
      text: message.text(), url: message.location().url || null, line: message.location().lineNumber || null,
    });
  });
  page.on('requestfailed', (request) => appendDiagnostic(diagnostics.failedRequests, {
    url: request.url(), method: request.method(), resourceType: request.resourceType(), failure: request.failure()?.errorText ?? null,
  }));
  page.on('response', (response) => {
    if (response.status() >= 400) appendDiagnostic(diagnostics.errorResponses, {
      status: response.status(), url: response.url(), method: response.request().method(),
      resourceType: response.request().resourceType(),
    });
  });
  page.on('framenavigated', (frame) => {
    if (frame === page.mainFrame()) appendDiagnostic(diagnostics.mainFrameNavigations, { url: frame.url(), at: new Date().toISOString() });
  });
  page.on('crash', () => appendDiagnostic(diagnostics.crashes, { url: page.url(), at: new Date().toISOString() }));
  page.on('websocket', (websocket) => appendDiagnostic(diagnostics.websocketConnections, {
    url: websocket.url(), isViteHmr: new URL(websocket.url()).port === '5210',
  }));
  await page.goto('/sign-in');
  await page.getByLabel('Email', { exact: true }).fill(email);
  await page.getByLabel('Password', { exact: true }).fill(process.env.DISPATCH_TEST_PASSWORD || 'password');
  const responsePromise = page.waitForResponse((response) =>
    response.url().endsWith('/api/v1/auth/sign-in') && response.request().method() === 'POST');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  const response = await responsePromise;
  const body = await response.text();
  expect(response.status(), `${email} login: ${body}`).toBe(200);
  await expect(page).not.toHaveURL(/\/sign-in$/);
  const authStorage = await page.evaluate(() => ({
    local: Object.keys(localStorage),
    session: Object.keys(sessionStorage),
    cookies: document.cookie.split(';').map((part) => part.trim().split('=')[0]),
  }));
  expect(authStorage.local.some((key) => /token|auth/i.test(key))).toBe(false);
  expect(authStorage.session.some((key) => /token|auth/i.test(key))).toBe(false);
  expect(authStorage.cookies).toContain('XSRF-TOKEN');
  live.push({ context, page, email, diagnostics });
  passed(`Real cookie + CSRF login: ${email}`, { authStorage });
  return page;
}

async function screenshot(page, name, fullPage = true) {
  const file = path.join(OUT, name);
  await page.screenshot({ path: file, fullPage });
  report.artifacts.push(file);
  return file;
}

async function ok(page, method, route, body) {
  const result = await request(page, method, route, body);
  expect(result.status, `${method} ${route}: ${JSON.stringify(result.body)}`).toBeGreaterThanOrEqual(200);
  expect(result.status).toBeLessThan(300);
  return dataOf(result);
}

async function fit(page) {
  const dimensions = await page.evaluate(() => ({ viewport: innerWidth, width: document.documentElement.scrollWidth }));
  expect(dimensions.width).toBeLessThanOrEqual(dimensions.viewport + 1);
}

async function createDelivery(page, batchIndex, lostResponse = false, quantityOverride = null) {
  const batch = fixture.batches[batchIndex];
  await page.goto('/supply-chain/deliveries/create');
  await page.getByLabel('Find sales order', { exact: true }).fill(fixture.so_number);
  const orderSelect = page.getByLabel(/^Sales order\s*\*?$/);
  await expect(orderSelect.locator(`option[value="${fixture.sales_order}"]`)).toHaveCount(1);
  await orderSelect.selectOption(fixture.sales_order);
  const lineSelect = page.getByLabel(/^Sales order line/);
  await expect(lineSelect.locator(`option[value="${fixture.line}"]`)).toHaveCount(1);
  await lineSelect.selectOption(fixture.line);
  const inspection = page.getByLabel(/^Passed outgoing inspection/);
  await expect(inspection.locator(`option[value="${batch.inspection}"]`)).toHaveCount(1);
  await inspection.selectOption(batch.inspection);
  await page.getByLabel(/^Qty/).fill(String(quantityOverride ?? batch.quantity));
  let savedId;
  const matchCreate = (url) => new URL(url).pathname === '/api/v1/supply-chain/deliveries';
  if (lostResponse) {
    await page.route(matchCreate, async (route) => {
      if (route.request().method() !== 'POST') return route.continue();
      const response = await route.fetch();
      const body = await response.json();
      expect(response.status(), JSON.stringify(body)).toBe(201);
      savedId = body.data.id;
      await route.abort('failed');
    });
    await page.getByRole('button', { name: 'Create delivery', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Retry last save' })).toBeVisible();
    expect(savedId).toBeTruthy();
    await page.unroute(matchCreate);
    await page.reload();
    await expect(page.getByRole('button', { name: 'Retry last save' })).toBeVisible();
    await page.getByRole('button', { name: 'Retry last save' }).click();
    await expect(page).toHaveURL(new RegExp(`/supply-chain/deliveries/${savedId}$`));
    const list = await ok(page, 'GET', `/supply-chain/deliveries?sales_order_id=${fixture.sales_order}`);
    expect(list).toHaveLength(1);
    passed('A lost create response survives reload and returns the same delivery without duplicates', { id: savedId });
  } else {
    const response = page.waitForResponse((r) => matchCreate(r.url()) && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'Create delivery', exact: true }).click();
    const saved = await response;
    const body = await saved.json();
    expect(saved.status(), JSON.stringify(body)).toBe(201);
    savedId = body.data.id;
    await expect(page).toHaveURL(new RegExp(`/supply-chain/deliveries/${savedId}$`));
  }
  return savedId;
}

async function assign(page, id) {
  await page.goto(`/supply-chain/deliveries/${id}`);
  await page.getByRole('button', { name: 'Assign driver & vehicle' }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel(/^Vehicle/).selectOption(fixture.vehicle);
  await dialog.getByLabel(/^Driver/).selectOption(fixture.driver);
  await dialog.getByLabel(/^Assignment reason/).fill('Approved load and available driver for this delivery.');
  const response = page.waitForResponse((r) => r.url().endsWith(`/deliveries/${id}/assignment`) && r.request().method() === 'PATCH');
  await dialog.getByRole('button', { name: 'Save assignment' }).click();
  const assigned = await response;
  expect(assigned.status(), await assigned.text()).toBe(200);
  await expect(dialog).toHaveCount(0);
  passed('ImpEx assigns the real driver and available vehicle', { id });
}

async function driverStep(page, id, label, status) {
  await page.getByRole('button', { name: new RegExp(`^Mark ${label}$`, 'i') }).click();
  const response = page.waitForResponse((r) => r.url().endsWith(`/driver/deliveries/${id}/status`) && r.request().method() === 'PATCH');
  await page.getByRole('dialog').getByRole('button', { name: new RegExp(`^Mark ${label}$`, 'i') }).click();
  const updated = await response;
  const body = await updated.json();
  expect(updated.status(), JSON.stringify(body)).toBe(200);
  expect(body.data.status).toBe(status);
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await expect(page.locator('strong').filter({ hasText: new RegExp(`^${body.data.status_label}$`) })).toBeVisible();
}

async function uploadProof(page, id) {
  const photo = await page.screenshot(); // Test-only image of the recorded delivery.
  await page.getByRole('button', { name: 'Capture receipt photo', exact: true }).click();
  await page.locator('input[type=file]').first().setInputFiles({ name: `${RUN}-receipt.png`, mimeType: 'image/png', buffer: photo });
  const response = page.waitForResponse((r) => r.url().endsWith(`/driver/deliveries/${id}/receipt`) && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Upload photo', exact: true }).click();
  const uploaded = await response;
  expect(uploaded.status(), await uploaded.text()).toBe(200);
  await expect(page).toHaveURL(new RegExp(`/driver/${id}$`));
}

async function reportAttempt(driver, id, customerQty, truckQty, loseResponse = false) {
  await driver.goto(`/driver/${id}`);
  await driver.getByRole('button', { name: 'Report delivery problem', exact: true }).click();
  await driver.getByLabel(/^What happened/).selectOption('customer_refused');
  await driver.getByLabel(/^Received by customer/).fill(customerQty);
  await driver.getByLabel(/^Returning on truck/).fill(truckQty);
  await driver.getByLabel('Additional details', { exact: true }).fill('Customer could not accept the full load; remaining goods are on the truck.');
  const path = `/api/v1/driver/deliveries/${id}/attempt-outcome`;
  const matches = (url) => url.pathname === path;
  let original;
  if (loseResponse) {
    await driver.route(matches, async (route) => {
      original = route.request().postDataJSON();
      const response = await route.fetch();
      expect(response.status(), await response.text()).toBeLessThan(300);
      await route.abort('failed');
    });
    await driver.getByRole('button', { name: 'Save delivery outcome', exact: true }).click();
    await expect(driver.getByText(/We could not confirm the save/)).toBeVisible();
    await driver.unroute(matches);
    await driver.reload();
    const retry = driver.waitForRequest((request) => new URL(request.url()).pathname === path && request.method() === 'POST');
    const response = driver.waitForResponse((response) => new URL(response.url()).pathname === path && response.request().method() === 'POST');
    await driver.getByRole('button', { name: 'Retry saved report', exact: true }).click();
    expect((await retry).postDataJSON()).toEqual(original);
    const saved = await response;
    expect(saved.status(), await saved.text()).toBeLessThan(300);
  } else {
    const response = driver.waitForResponse((response) => new URL(response.url()).pathname === path && response.request().method() === 'POST');
    await driver.getByRole('button', { name: 'Save delivery outcome', exact: true }).click();
    const saved = await response;
    expect(saved.status(), await saved.text()).toBeLessThan(300);
  }
  await expect(driver.getByText('Truck return pending', { exact: true }).first()).toBeVisible();
  await expect(driver.getByRole('button', { name: /^Mark delivered$/i })).toHaveCount(0);
  await expect(driver.getByRole('button', { name: 'Save delivery outcome', exact: true })).toHaveCount(0);
  await expect(driver.getByRole('button', { name: 'Retry saved report', exact: true })).toHaveCount(0);
  await expect(driver.getByLabel('What happened?', { exact: true })).toHaveCount(0);
  await driver.evaluate(() => window.scrollTo(0, 0));
  await fit(driver);
  passed('Driver records exact customer/truck quantities with safe retry', { id, customerQty, truckQty, loseResponse });
}

async function receiveTruck(warehouse, id, quantity, loseResponse = false) {
  await warehouse.goto(`/supply-chain/deliveries/${id}`);
  await warehouse.getByRole('button', { name: 'Record depot count', exact: true }).click();
  await warehouse.getByLabel(/^Physically received/).fill(quantity);
  await warehouse.getByLabel(/^Quarantine location/).selectOption(fixture.quarantine);
  await warehouse.getByLabel(/^Count differences or missing goods/).fill('Depot count verified; any missing goods remain under investigation.');
  const path = `/api/v1/supply-chain/deliveries/${id}/truck-return-receipt`;
  const matches = (url) => url.pathname === path;
  let original;
  if (loseResponse) {
    await warehouse.route(matches, async (route) => {
      original = route.request().postDataJSON();
      const response = await route.fetch();
      expect(response.status(), await response.text()).toBeLessThan(300);
      await route.abort('failed');
    });
    await warehouse.getByRole('button', { name: 'Confirm depot count', exact: true }).click();
    await expect(warehouse.getByText(/We could not confirm this receipt/)).toBeVisible();
    await screenshot(warehouse, '02-warehouse-pending-receipt-desktop.png');
    await warehouse.unroute(matches);
    await warehouse.reload();
    const request = warehouse.waitForRequest((request) => new URL(request.url()).pathname === path && request.method() === 'POST');
    const response = warehouse.waitForResponse((response) => new URL(response.url()).pathname === path && response.request().method() === 'POST');
    await warehouse.getByRole('button', { name: 'Retry saved receipt', exact: true }).click();
    expect((await request).postDataJSON()).toEqual(original);
    const saved = await response;
    expect(saved.status(), await saved.text()).toBeLessThan(300);
  } else {
    const response = warehouse.waitForResponse((response) => new URL(response.url()).pathname === path && response.request().method() === 'POST');
    await warehouse.getByRole('button', { name: 'Confirm depot count', exact: true }).click();
    const saved = await response;
    expect(saved.status(), await saved.text()).toBeLessThan(300);
  }
  await expect(warehouse.getByRole('button', { name: 'Confirm depot count', exact: true })).toHaveCount(0);
  const delivery = await ok(warehouse, 'GET', `/supply-chain/deliveries/${id}`);
  expect(delivery.attempt_outcome.return_request.id).toBeTruthy();
  passed('Warehouse receives actual goods into quarantine once', { id, quantity, loseResponse, return_request: delivery.attempt_outcome.return_request });
  return delivery.attempt_outcome.return_request.id;
}

async function inspectAndRestock(manager, qc, checker, rmaId) {
  const url = `/return-management/return-requests/${rmaId}`;
  let rma = await ok(manager, 'GET', url);
  expect(rma.status).toBe('received');
  // The existing Quality workflow is exercised with the actual QC role and
  // independent reviewer cookies; no inspection status is forced in fixtures.
  rma = await ok(qc, 'POST', `${url}/inspect`, { internal_notes: 'Physically returned unopened goods checked against the original load.' });
  expect(rma.inspections.length).toBeGreaterThan(0);
  for (const row of rma.inspections) {
    const inspection = await ok(qc, 'GET', `/quality/inspections/${row.id}`);
    expect(inspection.measurements.length).toBeGreaterThan(0);
    await ok(qc, 'PATCH', `/quality/inspections/${row.id}/measurements`, { measurements: inspection.measurements.map((measurement) => ({ id: measurement.id, is_pass: true, notes: 'Returned unopened goods meet the visual condition specification.' })) });
    const completed = await ok(qc, 'POST', `/quality/inspections/${row.id}/complete`);
    expect(completed.status).toBe('awaiting_review');
    expect((await request(qc, 'PATCH', `/quality/inspections/${row.id}/review`, { decision: 'passed' })).status).toBe(403);
    await ok(checker, 'PATCH', `/quality/inspections/${row.id}/review`, { decision: 'passed', remarks: 'Independently checked returned-goods measurements.' });
  }
  await manager.goto(`/return-management/${rmaId}`);
  await manager.getByRole('button', { name: 'Dispose Items', exact: true }).click();
  await expect(manager.getByRole('combobox', { name: 'Disposition', exact: true }).first()).toBeVisible();
  for (const select of await manager.getByRole('combobox', { name: 'Disposition', exact: true }).all()) await select.selectOption('restock');
  await manager.getByRole('combobox', { name: 'Restock location', exact: true }).selectOption(fixture.location);
  const disposed = manager.waitForResponse((response) => response.url().endsWith(`${rmaId}/dispose`) && response.request().method() === 'POST');
  await manager.getByRole('button', { name: 'Record Disposition', exact: true }).click();
  const response = await disposed;
  expect(response.status(), await response.text()).toBe(200);
  rma = await ok(manager, 'GET', url);
  for (const item of rma.items) expect(item.quarantine_status).toBe('released');
  rma = await ok(manager, 'POST', `${url}/complete`, {});
  expect(rma.status).toBe('completed');
  expect(rma.credit_note?.id ?? null).toBeNull();
  passed('Returned stock passes Quality and existing disposition without customer credit', { rmaId });
}

async function deliverAndConfirm(impex, driver, customer, finance, id, expectedQuantity, alreadyArrived = false) {
  if (!alreadyArrived) {
    await assign(impex, id);
    await driver.goto(`/driver/${id}`);
    await driverStep(driver, id, 'loading', 'loading');
    await driverStep(driver, id, 'in transit', 'in_transit');
    await driverStep(driver, id, 'delivered', 'delivered');
  } else await driver.goto(`/driver/${id}`);
  await uploadProof(driver, id);
  await customer.goto(`/portal/customer/deliveries/${id}`);
  await customer.getByRole('button', { name: 'Confirm Receipt', exact: true }).click();
  await customer.getByLabel(/^Received by/).fill('Delivery recovery receiver');
  const response = customer.waitForResponse((response) => response.url().endsWith(`/customer/deliveries/${id}/confirm`) && response.request().method() === 'POST');
  await customer.getByRole('button', { name: 'Confirm receipt', exact: true }).click();
  const saved = await response;
  expect(saved.status(), await saved.text()).toBe(200);
  const delivery = await ok(impex, 'GET', `/supply-chain/deliveries/${id}`);
  expect(delivery.invoice_handoff.status).toBe('generated');
  expect(delivery.coc_handoff.status).toBe('generated');
  const invoice = await ok(finance, 'GET', `/invoices/${delivery.invoice.id}`);
  expect(invoice.items.reduce((sum, line) => sum + Number(line.quantity), 0)).toBe(expectedQuantity);
  passed('Customer receipt and invoice use only actually received quantity', { id, expectedQuantity, invoice: delivery.invoice.id });
  return invoice;
}

async function amendAttempt(driver, id) {
  await driver.goto(`/driver/${id}`);
  await driver.getByRole('button', { name: 'Correct report', exact: true }).click();
  await driver.getByLabel(/^Returning on truck/).fill('4');
  await driver.getByLabel(/^Reason for correction/).fill('Recount found four units on the truck; two units need tracing.');
  const path = `/api/v1/driver/deliveries/${id}/amend-attempt`;
  let original;
  await driver.route((url) => url.pathname === path, async (route) => {
    original = route.request().postDataJSON();
    const result = await route.fetch();
    expect(result.status(), await result.text()).toBeLessThan(300);
    await route.abort('failed');
  });
  await driver.getByRole('button', { name: 'Save correction', exact: true }).click();
  await expect(driver.getByText(/We could not confirm the save/)).toBeVisible();
  await driver.unroute((url) => url.pathname === path); // remove all routes below by matching exact path
  await driver.unrouteAll({ behavior: 'wait' });
  await driver.reload();
  const nextRequest = driver.waitForRequest((r) => new URL(r.url()).pathname === path && r.method() === 'POST');
  const nextResponse = driver.waitForResponse((r) => new URL(r.url()).pathname === path && r.request().method() === 'POST');
  await driver.getByRole('button', { name: 'Retry saved report', exact: true }).click();
  expect((await nextRequest).postDataJSON()).toEqual(original);
  const response = await nextResponse;
  expect(response.status(), await response.text()).toBe(200);
  await expect(driver.getByRole('button', { name: 'Retry saved report', exact: true })).toHaveCount(0);
  await driver.getByText(/Correction and recovery history/).click();
  await screenshot(driver, '05-driver-correction-mobile.png');
  const result = await ok(driver, 'GET', `/driver/deliveries/${id}`);
  expect(result.attempt_outcome.version).toBe(2);
  expect(result.attempt_outcome.revisions.length).toBe(1);
  passed('Driver correction preserves history and recovers the exact request after a lost response');
}

async function reportTrace(customer, id) {
  await customer.goto(`/portal/customer/deliveries/${id}`);
  await customer.getByRole('button', { name: 'Shipment not arrived', exact: true }).click();
  await customer.getByLabel('Details (optional)', { exact: true }).fill('Our receiving dock has not received this shipment.');
  const response = customer.waitForResponse((r) => r.url().endsWith(`/problems/not-arrived/${id}`) && r.request().method() === 'POST');
  await customer.getByRole('button', { name: 'Send shipment report', exact: true }).click();
  const saved = await response;
  expect(saved.status(), await saved.text()).toBe(201);
  const record = (await saved.json()).data;
  await customer.waitForURL(`**/portal/customer/problems/${record.id}`);
  await expect(customer.getByText('Case timeline', { exact: true })).toBeVisible();
  await expect(customer.getByText('Quantities', { exact: true })).toHaveCount(0);
  await fit(customer);
  await screenshot(customer, '06-not-arrived-customer-mobile.png');
  expect(record.lines).toEqual([]);
  passed('Customer reports a shipment not received using one optional message and can follow the case', { caseId: record.id });
  return record.id;
}

async function recoverLate(warehouse, id) {
  await warehouse.goto(`/supply-chain/deliveries/${id}`);
  await warehouse.getByRole('button', { name: 'Receive late-found goods', exact: true }).click();
  await warehouse.getByLabel(/^Physically received/).fill('2');
  await warehouse.getByLabel(/^Quarantine location/).selectOption(fixture.quarantine);
  await warehouse.getByLabel(/^Where were the goods found/).fill('Carrier located two original units in its holding bay and returned them today.');
  const path = `/api/v1/supply-chain/deliveries/${id}/receive-late-return`;
  let original;
  const matches = (url) => url.pathname === path;
  await warehouse.route(matches, async (route) => {
    original = route.request().postDataJSON();
    const result = await route.fetch();
    expect(result.status(), await result.text()).toBe(200);
    await route.abort('failed');
  });
  await warehouse.getByRole('button', { name: 'Receive recovered goods', exact: true }).click();
  await expect(warehouse.getByText(/We could not confirm this receipt/)).toBeVisible();
  await warehouse.unroute(matches);
  await warehouse.reload();
  const nextRequest = warehouse.waitForRequest((r) => new URL(r.url()).pathname === path && r.method() === 'POST');
  const nextResponse = warehouse.waitForResponse((r) => new URL(r.url()).pathname === path && r.request().method() === 'POST');
  await warehouse.getByRole('button', { name: 'Retry saved receipt', exact: true }).click();
  expect((await nextRequest).postDataJSON()).toEqual(original);
  const result = await nextResponse;
  expect(result.status(), await result.text()).toBe(200);
  await expect(warehouse.getByRole('button', { name: 'Retry saved receipt', exact: true })).toHaveCount(0);
  const delivery = await ok(warehouse, 'GET', `/supply-chain/deliveries/${id}`);
  expect(delivery.attempt_outcome.return_requests.length).toBe(2);
  expect(Number(delivery.attempt_outcome.lines[0].unaccounted_quantity)).toBe(0);
  await screenshot(warehouse, '07-late-recovery-desktop.png');
  passed('Late found goods create a separate quarantined batch, safely retried after a lost response');
  return delivery.attempt_outcome.return_requests[1].id;
}

(async () => {
  try {
    const impex = await login('impex@ogami.test');
    const warehouse = await login('warehouse@ogami.test');
    const driver = await login('driver@ogami.test', { width: 390, height: 844 });
    const manager = await login('customerservice@ogami.test');
    const qc = await login('qc@ogami.test');
    const checker = await login(fixture.checker_email);
    const customer = await login(fixture.customer_email, { width: 390, height: 844 });
    const finance = await login('finance@ogami.test');
    const first = await createDelivery(impex, 0);
    report.first_delivery = first;
    await assign(impex, first);
    await driver.goto(`/driver/${first}`);
    await driverStep(driver, first, 'loading', 'loading');
    await driverStep(driver, first, 'in transit', 'in_transit');
    const traceId = await reportTrace(customer, first);
    await reportAttempt(driver, first, '0', '6', true);
    await amendAttempt(driver, first);
    await screenshot(driver, '01-driver-return-pending-mobile.png');
    const pending = await ok(impex, 'GET', `/supply-chain/deliveries/${first}`);
    expect(pending.status).toBe('return_pending');
    expect(pending.invoice).toBeNull();
    const otherDriver = await login('other.driver.dispatch@ogami.test');
    expect((await request(otherDriver, 'GET', `/driver/deliveries/${first}`)).status).toBe(404);
    expect((await request(otherDriver, 'POST', `/driver/deliveries/${first}/attempt-outcome`, {
      request_key: randomUUID(), reason_code: 'customer_refused', lines: pending.attempt_outcome.lines.map((line) => ({
        delivery_item_id: line.delivery_item_id, customer_received_quantity: '0', customer_received_damaged_quantity: '0',
        truck_return_quantity: '6', truck_return_damaged_quantity: '0', unaccounted_quantity: '0',
      })),
    })).status).toBe(404);
    passed('Another driver cannot read or report an outcome for this delivery');
    expect((await request(driver, 'POST', `/supply-chain/deliveries/${first}/truck-return-receipt`, {})).status).toBe(403);
    const beforeReceipt = await ok(warehouse, 'GET', `/inventory/stock-levels?item_id=${fixture.item}&per_page=100`);
    expect(beforeReceipt.reduce((sum, level) => sum + Number(level.quantity), 0)).toBe(9);
    passed('Driver report cannot restore stock or generate an invoice');
    const rma = await receiveTruck(warehouse, first, '4', true);
    const returned = await ok(impex, 'GET', `/supply-chain/deliveries/${first}`);
    expect(returned.status).toBe('returned');
    expect(returned.invoice).toBeNull();
    expect((await request(impex, 'POST', `/supply-chain/deliveries/${first}/confirm`, {})).status).toBe(422);
    const premature = await request(impex, 'POST', '/supply-chain/deliveries', { sales_order_id: fixture.sales_order, scheduled_date: new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' }), items: [{ sales_order_item_id: fixture.line, inspection_id: fixture.batches[0].inspection, quantity: '6' }] });
    expect(premature.status).toBe(422);
    passed('Whole refused delivery cannot invoice or reuse returned stock before Quality');
    await manager.goto(`/return-management/cases/${traceId}`);
    await manager.getByRole('button', { name: 'Close shipment tracking', exact: true }).click();
    await manager.getByLabel('Agreed action and next step', { exact: true }).fill('Delivery returned to depot. Original order remains open and redelivery follows Quality release.');
    const traceClosed = manager.waitForResponse((r) => r.url().endsWith(`/cases/${traceId}/actions`) && r.request().method() === 'POST');
    await manager.getByRole('button', { name: 'Close shipment tracking', exact: true }).last().click();
    expect((await traceClosed).status()).toBe(200);
    passed('Customer Service closes tracking only after depot reconciliation with a public next step');
    await inspectAndRestock(manager, qc, checker, rma);
    const lateRma = await recoverLate(warehouse, first);
    await inspectAndRestock(manager, qc, checker, lateRma);
    const redelivery = await createDelivery(impex, 0);
    const invoice1 = await deliverAndConfirm(impex, driver, customer, finance, redelivery, 6);
    const partial = await createDelivery(impex, 1);
    await assign(impex, partial);
    await driver.goto(`/driver/${partial}`);
    await driverStep(driver, partial, 'loading', 'loading');
    await driverStep(driver, partial, 'in transit', 'in_transit');
    await reportAttempt(driver, partial, '2', '2');
    const partialRma = await receiveTruck(warehouse, partial, '2');
    await inspectAndRestock(manager, qc, checker, partialRma);
    const invoice2 = await deliverAndConfirm(impex, driver, customer, finance, partial, 2, true);
    await screenshot(customer, '03-partial-customer-receipt-mobile.png');
    const last = await createDelivery(impex, 1, false, 2);
    const invoice3 = await deliverAndConfirm(impex, driver, customer, finance, last, 2);
    const totalInvoiced = [invoice1, invoice2, invoice3].reduce((sum, invoice) => sum + invoice.items.reduce((total, line) => total + Number(line.quantity), 0), 0);
    expect(totalInvoiced).toBe(10);
    const levels = await ok(warehouse, 'GET', `/inventory/stock-levels?item_id=${fixture.item}&per_page=100`);
    expect(levels.reduce((sum, level) => sum + Number(level.quantity), 0)).toBe(5);
    report.final_stock = levels;
    report.deliveries = { refused: first, redelivery, partial, last };
    report.total_invoiced = totalInvoiced;
    await impex.goto(`/supply-chain/deliveries/${partial}`);
    await expect(impex.getByText('Delivery outcome', { exact: true })).toBeVisible();
    await screenshot(impex, '04-partial-return-reconciliation-desktop.png');
    passed('Full and partial redeliveries reconcile ten received/invoiced units and retain five unapproved units');
    expect(jsErrors).toEqual([]);
    report.success = true;
  } catch (error) {
    report.failures.push({ message: error.message, stack: error.stack });
    report.success = false;
    for (let index = 0; index < live.length; index++) {
      try { await screenshot(live[index].page, `failure-${index}.png`); } catch { /* Preserve original failure. */ }
    }
    process.exitCode = 1;
  } finally {
    report.javascript_errors = jsErrors;
    report.pages = live.map(({ email, page, diagnostics }) => ({ email, url: page.url(), diagnostics }));
    fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2));
    for (const { context } of live) await context.close();
    if (sharedBrowser) await sharedBrowser.close();
    console.log(`Report: ${OUT}/report.json`);
  }
})();
