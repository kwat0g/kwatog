// Browser-authenticated Delivery / Dispatch acceptance flow.
// The fixture creates prerequisite records only. Run against an explicitly
// selected, migrated database with a temporary API + Vite proxy.
const { chromium, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const BASE = process.env.DISPATCH_TEST_URL || 'http://127.0.0.1:5210';
const FIXTURE_PATH = process.env.DISPATCH_FIXTURE_PATH;
if (!FIXTURE_PATH) throw new Error('Set DISPATCH_FIXTURE_PATH to the fixture manifest.');
const fixture = JSON.parse(fs.readFileSync(FIXTURE_PATH, 'utf8'));
const RUN = fixture.run_id;
const OUT = process.env.DISPATCH_TEST_OUTPUT
  || path.join('/tmp', `delivery-dispatch-headless-${RUN}-${Date.now()}`);
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

async function createDelivery(page, batchIndex, lostResponse = false) {
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
  await page.getByLabel(/^Qty/).fill(String(batch.quantity));
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

(async () => {
  try {
    const impex = await login('impex@ogami.test');
    expect((await request(impex, 'GET', '/crm/sales-orders')).status).toBe(403);
    // Force only the order lookup to fail, then recover through the visible retry.
    const optionsRoute = (url) => new URL(url).pathname === '/api/v1/supply-chain/deliveries/form-options';
    await impex.route(optionsRoute, (route) => route.fulfill({ status: 503, contentType: 'application/json', body: '{"message":"Test lookup outage"}' }));
    await impex.goto('/supply-chain/deliveries/create');
    await expect(impex.getByRole('button', { name: 'Retry sales orders' })).toBeVisible();
    await impex.unroute(optionsRoute);
    await impex.getByRole('button', { name: 'Retry sales orders' }).click();
    await expect(impex.getByLabel(/^Sales order\s*\*?$/)).toBeEnabled();
    passed('ImpEx form recovers a lookup failure without broad CRM permission');
    const first = await createDelivery(impex, 0, true);
    report.first_delivery = first;
    await expect(impex.getByText(fixture.batches[0].lot, { exact: false }).first()).toBeVisible();
    await screenshot(impex, '01-dispatch-preparation-desktop.png');
    const warehouse = await login('warehouse@ogami.test');
    await warehouse.goto(`/supply-chain/deliveries/${first}`);
    await expect(warehouse.getByText('Warehouse preparation', { exact: true })).toBeVisible();
    await expect(warehouse.getByRole('button', { name: 'Assign driver & vehicle' })).toHaveCount(0);
    expect((await request(warehouse, 'PATCH', `/supply-chain/deliveries/${first}/status`, { status: 'loading' })).status).toBe(403);
    passed('Warehouse can read approved-lot preparation but cannot dispatch');
    await assign(impex, first);
    const driver = await login('driver@ogami.test', { width: 390, height: 844 });
    await driver.goto(`/driver/${first}`);
    await driverStep(driver, first, 'loading', 'loading');
    await driverStep(driver, first, 'in transit', 'in_transit');
    const dispatched = await ok(impex, 'GET', `/supply-chain/deliveries/${first}`);
    expect(dispatched.items[0].stock_movements.map((m) => m.quantity)).toEqual(['2.000', '4.000']);
    expect(dispatched.items[0].stock_movements.every((m) => m.lot_number === fixture.batches[0].lot)).toBe(true);
    expect((await request(impex, 'PATCH', `/supply-chain/deliveries/${first}/status`, { status: 'cancelled' })).status).toBe(422);
    passed('Driver dispatch consumes only the approved lot across two bins; in-transit cancellation is refused');
    await driverStep(driver, first, 'delivered', 'delivered');
    await fit(driver);
    await screenshot(driver, '02-driver-arrival-mobile.png');
    const customer = await login(fixture.customer_email, { width: 390, height: 844 });
    await customer.goto(`/portal/customer/deliveries/${first}`);
    await expect(customer.getByText(/Awaiting our driver.s proof of delivery/)).toBeVisible();
    await expect(customer.getByRole('button', { name: 'Confirm Receipt', exact: true })).toHaveCount(0);
    await uploadProof(driver, first);
    await customer.reload();
    await customer.getByRole('button', { name: 'Confirm Receipt', exact: true }).click();
    await customer.getByLabel(/^Received by/).fill('Dispatch audit receiver');
    const confirmedResponse = customer.waitForResponse((r) => r.url().endsWith(`/customer/deliveries/${first}/confirm`) && r.request().method() === 'POST');
    await customer.getByRole('button', { name: 'Confirm receipt', exact: true }).click();
    const confirmed = await confirmedResponse;
    expect(confirmed.status(), await confirmed.text()).toBe(200);
    await expect(customer.getByText('Confirmed', { exact: true }).first()).toBeVisible();
    await fit(customer);
    await screenshot(customer, '03-customer-receipt-mobile.png');
    const firstState = await ok(impex, 'GET', `/supply-chain/deliveries/${first}`);
    report.first_final = firstState;
    expect(firstState.status).toBe('confirmed');
    expect(firstState.invoice_handoff.status).toBe('generated');
    expect(firstState.coc_handoff.status).toBe('generated');
    passed('Customer confirms after real driver proof; invoice and CoC are generated', { invoice: firstState.invoice.id });
    const finance = await login('finance@ogami.test');
    const invoice = await ok(finance, 'GET', `/invoices/${firstState.invoice.id}`);
    expect(invoice.items.reduce((sum, line) => sum + Number(line.quantity), 0)).toBe(6);
    expect((await request(finance, 'PATCH', `/supply-chain/deliveries/${first}/status`, { status: 'loading' })).status).toBe(403);
    await finance.goto(`/accounting/invoices/${firstState.invoice.id}`);
    await expect(finance.getByRole('heading', { name: /DRAFT/ })).toBeVisible();
    passed('Finance receives an invoice for only the six delivered units and cannot operate dispatch');

    const second = await createDelivery(impex, 1);
    report.second_delivery = second;
    await assign(impex, second);
    await driver.goto(`/driver/${second}`);
    await driverStep(driver, second, 'loading', 'loading');
    await driverStep(driver, second, 'in transit', 'in_transit');
    await driverStep(driver, second, 'delivered', 'delivered');
    await uploadProof(driver, second);
    await customer.goto(`/portal/customer/deliveries/${second}`);
    await customer.getByRole('button', { name: 'Report a problem', exact: true }).click();
    await expect(customer.getByText('Affected goods', { exact: true })).toBeVisible();
    await customer.getByRole('checkbox').first().check();
    await customer.getByLabel('Actually received', { exact: true }).fill('3');
    await customer.getByLabel('Damaged or defective', { exact: true }).fill('1');
    await customer.getByLabel('Explain the problem').fill('One part missing and one damaged during the second delivery.');
    await customer.getByLabel('Preferred outcome').selectOption('credit');
    const intakeResponse = customer.waitForResponse((r) => r.url().endsWith('/b2b/customer/problems') && r.request().method() === 'POST');
    await customer.getByRole('button', { name: 'Submit report', exact: true }).click();
    const intake = await intakeResponse;
    const caseBody = await intake.json();
    expect(intake.status(), JSON.stringify(caseBody)).toBe(201);
    const problem = caseBody.data;
    report.problem_case = problem;
    await expect(customer).toHaveURL(new RegExp(`/portal/customer/problems/${problem.id}$`));
    await expect(customer.getByRole('heading', { name: 'Reported problem', exact: true })).toBeVisible();
    expect(problem.lines[0].lot_number).toBe(fixture.batches[1].lot);
    expect(Number(problem.lines[0].missing_quantity)).toBe(1);
    expect(Number(problem.lines[0].defective_quantity)).toBe(1);
    await customer.goto(`/portal/customer/deliveries/${second}`);
    await expect(customer.getByRole('link', { name: problem.case_number, exact: true })).toBeVisible();
    await expect(customer.getByRole('button', { name: 'Confirm Receipt', exact: true })).toHaveCount(0);
    await expect(customer.getByText(/Awaiting our driver.s proof/)).toHaveCount(0);
    await fit(customer);
    await screenshot(customer, '04-customer-problem-hold-mobile.png');
    expect((await request(customer, 'POST', `/b2b/customer/deliveries/${second}/confirm`, { receiver_name: 'Audit receiver' })).status).toBe(422);
    expect((await request(impex, 'POST', `/supply-chain/deliveries/${second}/confirm`, { receiver_name: 'Audit receiver' })).status).toBe(422);
    const secondState = await ok(impex, 'GET', `/supply-chain/deliveries/${second}`);
    expect(secondState.invoice).toBeNull();
    expect(secondState.billing_hold.case_id).toBe(problem.id);
    report.second_final = secondState;
    passed('Customer reports shortage and damage once with the dispatched lot; confirmation and invoicing wait for resolution');
    const other = await login('other.dispatch@ogami.test');
    expect([403, 404]).toContain((await request(other, 'GET', `/b2b/customer/deliveries/${first}`)).status);
    expect([403, 404]).toContain((await request(other, 'GET', `/b2b/customer/problems/${problem.id}`)).status);
    passed('Another customer cannot read the delivery or problem report');
    const stock = await ok(warehouse, 'GET', `/inventory/stock-levels?item_id=${fixture.item}&per_page=100`);
    expect(stock.reduce((sum, level) => sum + Number(level.quantity), 0)).toBe(5);
    report.remaining_stock = stock;
    passed('All ten approved units dispatched; five unapproved units remain untouched');
    expect(jsErrors).toEqual([]);
    report.success = true;
  } catch (error) {
    report.failures.push({ message: error.message, stack: error.stack });
    report.success = false;
    for (let index = 0; index < live.length; index++) {
      const { page } = live[index];
      try {
        await screenshot(page, `failure-${index}.png`);
        fs.writeFileSync(path.join(OUT, `failure-${index}.html`), await page.content());
      } catch { /* Preserve the original failure if a page already closed. */ }
    }
    process.exitCode = 1;
  } finally {
    report.javascript_errors = jsErrors;
    report.pages = live.map(({ email, page, diagnostics }) => ({ email, url: page.url(), diagnostics }));
    report.websocket_sessions = echoWebsocketSessions;
    fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2));
    for (const { context } of live) await context.close();
    if (sharedBrowser) await sharedBrowser.close();
    console.log(`Report: ${OUT}/report.json`);
  }
})();
