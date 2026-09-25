// Browser-authenticated Production / Work Orders acceptance flow.
// The fixture creates prerequisite records only. Run against an explicitly
// selected, migrated database with a temporary API + Vite proxy.
const { chromium, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const BASE = process.env.PRODUCTION_WORKORDERS_TEST_URL || 'http://127.0.0.1:5210';
const FIXTURE_PATH = process.env.PRODUCTION_WORKORDERS_FIXTURE_PATH;
if (!FIXTURE_PATH) throw new Error('Set PRODUCTION_WORKORDERS_FIXTURE_PATH to the fixture manifest.');
const fixture = JSON.parse(fs.readFileSync(FIXTURE_PATH, 'utf8'));
const RUN = fixture.run_id;
const OUT = process.env.PRODUCTION_WORKORDERS_TEST_OUTPUT
  || path.join('/tmp', `production-workorders-headless-${RUN}-${Date.now()}`);
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
  await page.getByLabel('Password', { exact: true }).fill(process.env.PRODUCTION_WORKORDERS_TEST_PASSWORD || 'password');
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

async function assertMobileFit(page, label, requireScrollableTables = false) {
  const metrics = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    document: document.documentElement.scrollWidth,
    body: document.body.scrollWidth,
    tablePanels: Array.from(document.querySelectorAll('.overflow-x-auto'))
      .filter((panel) => panel.querySelector('table'))
      .map((panel) => ({ clientWidth: panel.clientWidth, scrollWidth: panel.scrollWidth })),
  }));
  expect(metrics.document, `${label} must not overflow the 390px viewport`).toBeLessThanOrEqual(metrics.viewport + 1);
  expect(metrics.body, `${label} body must not overflow the viewport`).toBeLessThanOrEqual(metrics.viewport + 1);
  if (requireScrollableTables) {
    expect(metrics.tablePanels.length, `${label} should render table panels`).toBeGreaterThan(0);
    expect(metrics.tablePanels.every((panel) => panel.clientWidth <= metrics.viewport + 1), `${label} table panels must stay within the viewport`).toBe(true);
    expect(metrics.tablePanels.some((panel) => panel.scrollWidth > panel.clientWidth), `${label} tables should scroll inside their panels`).toBe(true);
  }
  passed(`390px responsive layout: ${label}`, metrics);
}

async function at(page, method, route, body, expectedStatus = 200) {
  const result = await request(page, method, route, body);
  expect(result.status, `${method} ${route}: ${JSON.stringify(result.body)}`).toBe(expectedStatus);
  return result;
}

async function stockAt(page, itemId, locationId) {
  const result = await at(page, 'GET', `/inventory/stock-levels?item_id=${encodeURIComponent(itemId)}&per_page=100`);
  const rows = dataOf(result);
  const row = Array.isArray(rows) ? rows.find((value) => value.location?.id === locationId) : null;
  expect(row, `stock row for ${itemId} / ${locationId}`).toBeTruthy();
  return row;
}

async function openMaterialReturn(page, movementId) {
  await page.goto(`/inventory/stock-levels?view=movements&movement_id=${encodeURIComponent(movementId)}`);
  await expect(page.getByRole('heading', { name: 'Stock movements' }).first()).toBeVisible();
  return openMaterialReturnFromCurrentPage(page);
}

async function openMaterialReturnFromCurrentPage(page) {
  await page.getByRole('button', { name: 'Return unused', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('heading', { name: 'Return unused material' })).toBeVisible();
  await expect(dialog.getByText('Originally issued', { exact: true })).toBeVisible();
  return dialog;
}

async function returnMovementViaApi(page, movementId, quantity, expectedReturned, idempotencyKey) {
  return page.evaluate(async ({ movementId, quantity, expectedReturned, idempotencyKey }) => {
    const xsrf = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='))?.slice(11);
    const response = await fetch(`/api/v1/inventory/stock-movements/${movementId}/return-unused`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'Idempotency-Key': idempotencyKey,
        ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
      },
      body: JSON.stringify({
        quantity_returned: quantity,
        expected_returned_quantity: expectedReturned,
        reason: 'Browser audit returned unopened material',
      }),
    });
    const body = await response.json();
    return { status: response.status, body };
  }, { movementId, quantity, expectedReturned, idempotencyKey });
}

async function sourceMovementFor(page, itemId, referenceType, referenceId) {
  const result = await at(page, 'GET', `/inventory/stock-movements?item_id=${encodeURIComponent(itemId)}&movement_type=material_issue&reference_type=${encodeURIComponent(referenceType)}&per_page=200`);
  const rows = dataOf(result);
  const movement = rows.find((row) => row.reference_id === referenceId);
  expect(movement, `material issue movement for ${referenceType} ${referenceId}`).toBeTruthy();
  return movement;
}

async function fillAndSubmitMaterialReturn(page, dialog, quantity, reason) {
  await dialog.getByLabel(/^Quantity to return/).fill(quantity);
  await dialog.getByLabel(/^Reason/).fill(reason);
  const responsePromise = page.waitForResponse((response) =>
    response.url().includes('/inventory/stock-movements/')
    && response.url().endsWith('/return-unused')
    && response.request().method() === 'POST');
  await dialog.getByRole('button', { name: 'Post return', exact: true }).click();
  return responsePromise;
}

async function blockedOutputViaUi(page, workOrderId, goodCount, shiftName) {
  await page.goto(`/production/work-orders/${workOrderId}/record-output`);
  await expect(page.getByRole('heading', { name: /Record output/ })).toBeVisible();
  await page.getByLabel(/^Good count\s?\*?$/).fill(String(goodCount));
  await page.getByLabel('Shift', { exact: true }).selectOption(shiftName);
  const responsePromise = page.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${workOrderId}/outputs`)
    && response.request().method() === 'POST');
  await page.getByRole('button', { name: 'Record', exact: true }).click();
  const response = await responsePromise;
  const body = await response.json();
  expect(response.status(), JSON.stringify(body)).toBe(422);
  expect(body.message).toMatch(/material|coverage|shortage/i);
  return body;
}

async function createIssueInUi(warehouse, workOrderId, quantity, lotNumber, expectedWoNumber, funding) {
  await warehouse.goto(`/inventory/material-issues/create?work_order_id=${encodeURIComponent(workOrderId)}`);
  await expect(warehouse.getByRole('heading', { name: 'New material issue' })).toBeVisible();
  const form = warehouse.locator('form');
  const selects = form.locator('select');
  await expect(selects.nth(0)).toHaveValue(workOrderId);
  const workOrderOptions = await selects.nth(0).locator('option').allTextContents();
  expect(workOrderOptions.join(' ')).toContain(expectedWoNumber);
  await selects.nth(1).selectOption(fixture.raw_item_id);
  const location = selects.nth(2);
  await expect(location.locator(`option[value="${fixture.raw_location_id}"]`)).toHaveCount(1);
  await location.selectOption(fixture.raw_location_id);
  await form.getByPlaceholder('Lot (optional)').fill(lotNumber);
  await form.getByPlaceholder('0').fill(String(quantity));
  await expect(form.getByRole('button', { name: 'Create issue', exact: true })).toBeEnabled();
  const requestBodies = [];
  warehouse.on('request', (request) => {
    if (request.url().endsWith('/api/v1/inventory/material-issues') && request.method() === 'POST') {
      requestBodies.push(request.postDataJSON());
    }
  });
  const responsePromise = warehouse.waitForResponse((response) =>
    response.url().endsWith('/api/v1/inventory/material-issues') && response.request().method() === 'POST');
  await form.getByRole('button', { name: 'Create issue', exact: true }).click();
  const response = await responsePromise;
  const payload = await response.json();
  expect(response.status(), JSON.stringify(payload)).toBe(201);
  expect(requestBodies).toHaveLength(1);
  expect(requestBodies[0].work_order_id).toBe(workOrderId);
  expect(requestBodies[0].items[0].lot_number).toBe(lotNumber);
  if (funding === 'reservation') {
    expect(requestBodies[0].items[0].material_reservation_id).toBeTruthy();
  } else {
    expect(requestBodies[0].items[0].material_reservation_id).toBeUndefined();
  }
  const slip = payload.data;
  expect(slip.items?.[0]?.stock_movement_id).toBeTruthy();
  await expect(warehouse).toHaveURL(new RegExp(`/inventory/material-issues/${slip.id}$`));
  await expect(warehouse.getByText(expectedWoNumber, { exact: true })).toBeVisible();
  return { slip, request: requestBodies[0] };
}

async function confirmViaUi(manager, workOrderId) {
  await manager.goto(`/production/work-orders/${workOrderId}`);
  await expect(manager.getByRole('button', { name: 'Confirm', exact: true })).toBeVisible();
  await manager.getByRole('button', { name: 'Confirm', exact: true }).click();
  await manager.getByLabel(/^Machine\s?\*?$/).selectOption(fixture.machine_id);
  await manager.getByLabel(/^Mold\s?\*?$/).selectOption(fixture.mold_id);
  const responsePromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${workOrderId}/confirm`));
  await manager.getByRole('button', { name: 'Confirm work order', exact: true }).click();
  const response = await responsePromise;
  const body = await response.json();
  expect(response.status(), JSON.stringify(body)).toBe(200);
  await expect(manager.getByText('Confirmed', { exact: true })).toBeVisible();
}

async function startViaUi(manager, workOrderId, expectedStatus = 200) {
  await manager.goto(`/production/work-orders/${workOrderId}`);
  await expect(manager.getByRole('button', { name: 'Start', exact: true })).toBeVisible({ timeout: 20_000 });
  await manager.getByRole('button', { name: 'Start', exact: true }).click();
  const dialog = manager.getByRole('dialog');
  const responsePromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${workOrderId}/start`));
  await dialog.getByRole('button', { name: 'Start', exact: true }).click();
  const response = await responsePromise;
  const body = await response.json();
  expect(response.status(), JSON.stringify(body)).toBe(expectedStatus);
  if (expectedStatus === 200) await expect(manager.getByText('In Progress', { exact: true })).toBeVisible();
  return { response, body };
}

async function outputViaUi(manager, workOrderId, goodCount, rejectCount, defectId, shiftName, label, expectedCounters) {
  await manager.goto(`/production/work-orders/${workOrderId}/record-output`);
  await expect(manager.getByRole('heading', { name: /Record output/ })).toBeVisible();
  await expect(manager.getByLabel('Shift', { exact: true }).locator(`option[value="${shiftName}"]`)).toHaveCount(1);
  await manager.getByLabel(/^Good count\s?\*?$/).fill(String(goodCount));
  await manager.getByLabel('Shift', { exact: true }).selectOption(shiftName);
  if (rejectCount > 0) {
    await manager.getByRole('button', { name: 'Add defect', exact: true }).click();
    await manager.getByLabel('Defect type', { exact: true }).selectOption(defectId);
    await manager.getByLabel('Count', { exact: true }).fill(String(rejectCount));
  }
  if (label === 'partial' || goodCount === 3) {
    await screenshot(manager, `${label}-record-output-form.png`);
    if ((await manager.evaluate(() => innerWidth)) <= 390) await assertMobileFit(manager, 'record-output form');
  }
  const responsePromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${workOrderId}/outputs`) && response.request().method() === 'POST');
  await manager.getByRole('button', { name: 'Record', exact: true }).click();
  const response = await responsePromise;
  const body = await response.json();
  expect(response.status(), JSON.stringify(body)).toBe(201);
  await expect(manager).toHaveURL(new RegExp(`/production/work-orders/${workOrderId}$`));
  const readCounter = async (labelText) => manager.getByText(labelText, { exact: true }).evaluate((node) => node.nextElementSibling?.textContent?.trim() ?? '');
  await expect.poll(() => readCounter('Target / Produced')).toBe(`${expectedCounters.target} / ${expectedCounters.produced}`);
  await expect.poll(() => readCounter('Good / Reject')).toContain(`${expectedCounters.good} / ${expectedCounters.reject}`);
  if ((await manager.evaluate(() => innerWidth)) <= 390) {
    await expect(manager.locator('.overflow-x-auto table').first()).toBeVisible();
    await assertMobileFit(manager, 'work-order detail after output', true);
  }
  return body.data;
}

async function runOperationViaUi(manager, workOrderId, target, good, scrap) {
  await manager.goto(`/production/work-orders/${workOrderId}`);
  await manager.getByRole('tab', { name: 'Operations', exact: true }).click();
  const row = manager.getByRole('row').filter({ hasText: 'Injection moulding' });
  await expect(row).toBeVisible();
  await row.getByRole('button', { name: 'Setup', exact: true }).click();
  await expect(row.getByRole('button', { name: 'End setup', exact: true })).toBeVisible();
  await row.getByRole('button', { name: 'End setup', exact: true }).click();
  await row.getByRole('button', { name: 'Start', exact: true }).click();
  await expect(row.getByText('In Progress', { exact: true })).toBeVisible();
  await row.getByRole('button', { name: 'Output', exact: true }).click();
  const dialog = manager.getByRole('dialog');
  await dialog.getByLabel(/^Quantity\s?\*?$/).fill(String(good));
  await dialog.getByLabel('Scrap', { exact: true }).fill(String(scrap));
  if (scrap > 0) await dialog.getByLabel('Scrap reason', { exact: true }).fill('Reject recorded against the output defect line.');
  await dialog.getByRole('button', { name: 'Record output', exact: true }).click();
  await expect(row.getByText(new RegExp(`${good} / ${target}`))).toBeVisible();
  await row.getByRole('button', { name: 'Complete', exact: true }).click();
  await expect(row.getByText('Completed', { exact: true })).toBeVisible();
}

async function measureReviewAll(qc, qcChecker, manager, managerId, workOrderId, stage, verifyManagerSelfReview = false) {
  const list = await at(qc, 'GET', `/quality/inspections?entity_type=work_order&entity_id=${encodeURIComponent(workOrderId)}&per_page=100`);
  const inspections = dataOf(list).filter((row) => row.stage === stage);
  expect(inspections.length, `expected ${stage} inspection(s) for ${workOrderId}`).toBeGreaterThan(0);
  const reviewed = [];
  for (const inspection of inspections) {
    const detail = await at(qc, 'GET', `/quality/inspections/${inspection.id}`);
    const inspectionData = dataOf(detail);
    expect(inspectionData.measurements?.length ?? 0, `${stage} measurement rows`).toBeGreaterThan(0);
    let completed;
    if (inspectionData.inspection_mode === 'lot_checklist') {
      const checklist = (inspectionData.measurements ?? [])
        .filter((measurement) => measurement.tolerance_min === null && measurement.tolerance_max === null)
        .map((measurement) => ({ id: measurement.id, is_pass: true, notes: 'Audit checklist item passed.' }));
      const measurements = (inspectionData.measurements ?? [])
        .filter((measurement) => measurement.tolerance_min !== null || measurement.tolerance_max !== null)
        .map((measurement) => ({ id: measurement.id, measured_value: 10, notes: 'Within seeded specification.' }));
      completed = await at(qc, 'POST', `/quality/inspections/${inspection.id}/lot-result`, {
        sample_defect_count: 0,
        ...(checklist.length ? { checklist } : {}),
        ...(measurements.length ? { measurements } : {}),
        complete: true,
      });
    } else {
      const measurements = inspectionData.measurements.map((measurement) => ({
        id: measurement.id,
        measured_value: 10,
        notes: 'Headless audit measurement within the seeded specification.',
      }));
      await at(qc, 'PATCH', `/quality/inspections/${inspection.id}/measurements`, { measurements });
      completed = await at(qc, 'POST', `/quality/inspections/${inspection.id}/complete`);
    }
    const completion = dataOf(completed);
    let finalInspection = completion;
    if (completion.status === 'awaiting_review') {
      if (verifyManagerSelfReview && inspectionData.inspector?.id === managerId) {
        const selfReview = await at(manager, 'PATCH', `/quality/inspections/${inspection.id}/review`, {
          decision: 'passed',
          remarks: `Attempted maker self-review of ${stage} inspection; Quality must reject this.`,
        }, 403);
        expect(selfReview.body.message).toMatch(/cannot review.*performed/i);
        passed('Quality prevents the Production Manager from reviewing an inspection they performed', {
          inspection: inspection.id,
          inspector: inspectionData.inspector.id,
          reviewerAttempt: managerId,
          status: selfReview.status,
          message: selfReview.body.message,
        });
      }
      const review = await at(qcChecker, 'PATCH', `/quality/inspections/${inspection.id}/review`, {
        decision: 'passed',
        remarks: `Independent QC Inspector review of ${stage} inspection measurements.`,
      });
      finalInspection = dataOf(review);
    }
    if (stage === 'outgoing') {
      expect(completion.status, 'every output-bound outgoing lot waits for checker review').toBe('awaiting_review');
      finalInspection = dataOf(await at(qcChecker, 'GET', `/quality/inspections/${inspection.id}`));
    }
    expect(finalInspection.status, `${stage} inspection disposition`).toBe('passed');
    if (stage === 'outgoing') {
      expect(finalInspection.reviewed_at).toBeTruthy();
      expect(finalInspection.reviewer?.id).toBeTruthy();
      expect(finalInspection.inspector?.id).toBeTruthy();
      expect(finalInspection.reviewer.id).not.toBe(finalInspection.inspector.id);
      expect(finalInspection.accepted_quantity).toBe(finalInspection.batch_quantity);
      expect(finalInspection.work_order_output?.id).toBe(inspectionData.work_order_output?.id);
      expect(finalInspection.work_order_output?.id).toBeTruthy();
    }
    reviewed.push({
      id: inspection.id,
      stage: inspection.stage,
      output_batch: inspectionData.work_order_output?.batch_code ?? null,
      batch_quantity: finalInspection.batch_quantity,
      accepted_quantity: finalInspection.accepted_quantity,
      final_status: finalInspection.status,
      inspector: finalInspection.inspector?.id ?? null,
      reviewer: finalInspection.reviewer?.id ?? null,
      reviewed_at: finalInspection.reviewed_at ?? null,
    });
  }
  report.handoffs.push({ from: 'QC Inspector', to: 'Independent QC Inspector checker', stage, count: reviewed.length, evidence: reviewed });
  passed(`QC measurement and ${reviewed.length} ${stage} disposition(s)`, reviewed);
  return reviewed;
}

async function closeViaUi(manager, workOrderId) {
  await manager.goto(`/production/work-orders/${workOrderId}`);
  await expect(manager.getByRole('button', { name: 'Close', exact: true })).toBeVisible();
  await manager.getByRole('button', { name: 'Close', exact: true }).click();
  const dialog = manager.getByRole('dialog');
  const responsePromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${workOrderId}/close`));
  await dialog.getByRole('button', { name: 'Close', exact: true }).click();
  const response = await responsePromise;
  const body = await response.json();
  expect(response.status(), JSON.stringify(body)).toBe(200);
  await expect(manager.getByText('Closed', { exact: true })).toBeVisible();
}

async function main() {
  expect(fixture.database).toMatch(/^ogami_test_production_workorders_browser_/);
  const mobileManager = await login('production@ogami.test', { width: 390, height: 844 });
  const roleOptions = await at(mobileManager, 'GET', '/production/work-orders/form-options');
  expect(dataOf(roleOptions).products.some((product) => product.id === fixture.product_id)).toBe(true);
  const deniedCrm = await request(mobileManager, 'GET', '/crm/products?per_page=1');
  const deniedShifts = await request(mobileManager, 'GET', '/attendance/shifts?per_page=200');
  expect(deniedCrm.status).toBe(403);
  expect(deniedShifts.status).toBe(403);
  passed('Production Manager can use scoped product/machine/mold/shift lookups without CRM or Attendance permission', {
    scopedOptionsStatus: roleOptions.status,
    products: dataOf(roleOptions).products.length,
    machines: dataOf(roleOptions).machines.length,
    molds: dataOf(roleOptions).molds.length,
    shifts: dataOf(roleOptions).shifts.length,
    unrelatedCrmStatus: deniedCrm.status,
    unrelatedAttendanceStatus: deniedShifts.status,
  });

  let formOptionsFailures = 2;
  let formOptionsAttempts = 0;
  await mobileManager.route('**/api/v1/production/work-orders/form-options', async (route) => {
    formOptionsAttempts++;
    if (formOptionsFailures > 0) {
      formOptionsFailures--;
      await route.fulfill({ status: 503, contentType: 'application/json', body: '{"message":"audit injected lookup failure"}' });
      return;
    }
    await route.continue();
  });
  await mobileManager.goto('/production/work-orders/create');
  await expect(mobileManager.getByRole('heading', { name: 'New work order' })).toBeVisible();
  await expect(mobileManager.getByText('Could not load work-order choices. Your current entries are unchanged.', { exact: true })).toBeVisible();
  await mobileManager.getByLabel(/^Quantity target\s?\*?$/).fill('1');
  await screenshot(mobileManager, 'mobile-create-options-retry-state.png');
  await mobileManager.unroute('**/api/v1/production/work-orders/form-options');
  await mobileManager.getByRole('button', { name: 'Retry lookups', exact: true }).click();
  await expect(mobileManager.getByLabel(/^Product\s?\*?$/).locator(`option[value="${fixture.product_id}"]`)).toHaveCount(1);
  expect(await mobileManager.getByLabel(/^Quantity target\s?\*?$/).inputValue()).toBe('1');
  expect(formOptionsFailures).toBe(0);
  passed('Form-options failure is visible after automatic retries, manual Retry reloads live lookups and preserves typed quantity', { injectedFailureStatus: 503, failedRequests: 2, totalAttempts: formOptionsAttempts });
  await expect(mobileManager.getByLabel(/^Product\s?\*?$/).locator(`option[value="${fixture.product_id}"]`)).toHaveCount(1);
  await assertMobileFit(mobileManager, 'new work-order form');
  const localDateEvidence = await mobileManager.getByLabel(/^Planned start\s?\*?$/).evaluate((input) => {
    const [date, time] = (input.value || '').split('T');
    const [year, month, day] = date.split('-').map(Number);
    const [hour, minute] = time.split(':').map(Number);
    const value = new Date(year, month - 1, day, hour, minute);
    return { field: input.value, timezone: Intl.DateTimeFormat().resolvedOptions().timeZone, minuteDelta: Math.abs(value.getTime() - Date.now()) / 60_000 };
  });
  expect(localDateEvidence.minuteDelta).toBeLessThan(2);
  await mobileManager.getByLabel(/^Product\s?\*?$/).selectOption(fixture.product_id);
  await mobileManager.getByLabel(/^Quantity target\s?\*?$/).fill('1');
  const dateFields = await mobileManager.locator('input[type="datetime-local"]').evaluateAll((nodes) => nodes.map((node) => node.value));
  const plusHour = (value) => {
    const date = new Date(`${value}:00`);
    date.setHours(date.getHours() + 1);
    return new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);
  };
  await mobileManager.getByLabel(/^Planned start\s?\*?$/).fill(plusHour(dateFields[0]));
  await mobileManager.getByLabel(/^Planned end\s?\*?$/).fill(plusHour(plusHour(dateFields[0])));
  const createdResponsePromise = mobileManager.waitForResponse((response) =>
    response.url().endsWith('/api/v1/production/work-orders') && response.request().method() === 'POST');
  await mobileManager.getByRole('button', { name: 'Create work order', exact: true }).click();
  const createdResponse = await createdResponsePromise;
  const createdBody = await createdResponse.json();
  expect(createdResponse.status(), JSON.stringify(createdBody)).toBe(201);
  await expect(mobileManager).toHaveURL(/\/production\/work-orders\/[A-Za-z0-9]+$/);
  await expect(mobileManager.locator('.overflow-x-auto table').first()).toBeVisible();
  await assertMobileFit(mobileManager, 'created work-order detail', true);
  await screenshot(mobileManager, 'mobile-work-order-created.png');
  passed('Production Manager creates a planned work order from the production UI; wall-clock datetime-local default is local', {
    status: createdBody.data.status,
    wo_number: createdBody.data.wo_number,
    planned_start_field: localDateEvidence.field,
    timezone: localDateEvidence.timezone,
  });

  const ppc = await login('ppc@ogami.test');
  const ppcOptions = await at(ppc, 'GET', '/production/work-orders/form-options');
  expect(dataOf(ppcOptions).products.some((product) => product.id === fixture.product_id)).toBe(true);
  const ppcAttendance = await request(ppc, 'GET', '/attendance/shifts?per_page=200');
  expect(ppcAttendance.status).toBe(403);
  passed('PPC role has scoped Production form lookups and no Attendance shift-list permission', { options: ppcOptions.status, attendance: ppcAttendance.status });
  await ppc.context().close();

  const manager = await login('production@ogami.test');
  const warehouse = await login('warehouse@ogami.test');
  const qc = await login('qc@ogami.test');
  const qcChecker = await login(fixture.qc_checker_email);
  const maintenance = await login('maintenance@ogami.test');
  const managerId = dataOf(await at(manager, 'GET', '/auth/user')).id;
  expect(managerId).toBeTruthy();

  // Confirm and reserve the Recovery work order through the actual detail modal.
  await confirmViaUi(manager, fixture.recovery_work_order_id);
  await confirmViaUi(manager, fixture.main_work_order_id);
  const confirmedRecovery = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.recovery_work_order_id}`));
  const confirmedMain = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.main_work_order_id}`));
  expect(confirmedRecovery.status).toBe('confirmed');
  expect(confirmedMain.status).toBe('confirmed');
  expect(Number(confirmedRecovery.quantity_target) + Number(confirmedMain.quantity_target)).toBe(7);
  let rawStock = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  expect(Number(rawStock.quantity)).toBe(9);
  expect(Number(rawStock.reserved_quantity)).toBe(7);
  expect(Number(rawStock.available)).toBe(2);
  passed('Confirmation reserves the BOM quantity across the two linked orders', {
    target: confirmedRecovery.quantity_target,
    quantity: rawStock.quantity,
    reserved: rawStock.reserved_quantity,
    available: rawStock.available,
  });

  const recoveryIssue = await createIssueInUi(warehouse, fixture.recovery_work_order_id, 2, fixture.material_lot_number, fixture.recovery_work_order_number, 'reservation');
  const afterRecoveryIssue = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  expect(Number(afterRecoveryIssue.quantity)).toBe(7);
  expect(Number(afterRecoveryIssue.reserved_quantity)).toBe(5);
  await at(warehouse, 'DELETE', `/inventory/material-issues/${recoveryIssue.slip.id}`);
  const afterPreOutputCancel = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  expect(Number(afterPreOutputCancel.quantity)).toBe(9);
  expect(Number(afterPreOutputCancel.reserved_quantity)).toBe(5);
  expect(Number(afterPreOutputCancel.available)).toBe(4);
  const shortStart = await startViaUi(manager, fixture.recovery_work_order_id, 422);
  expect(shortStart.body.message).toMatch(/material|issue|coverage|short/i);
  await manager.getByRole('dialog').getByRole('button', { name: 'Cancel', exact: true }).click();
  passed('Pre-output Warehouse cancellation returns unused stock without reopening reservation; Production start blocks the resulting short coverage', {
    reservedLinkedSlip: recoveryIssue.slip.slip_number,
    afterIssue: { quantity: afterRecoveryIssue.quantity, reserved: afterRecoveryIssue.reserved_quantity },
    afterCancel: { quantity: afterPreOutputCancel.quantity, reserved: afterPreOutputCancel.reserved_quantity, available: afterPreOutputCancel.available },
    blockedStart: shortStart.response.status,
    blockedReason: shortStart.body.message,
  });

  const recoveryReissue = await createIssueInUi(warehouse, fixture.recovery_work_order_id, 2, fixture.material_lot_number, fixture.recovery_work_order_number, 'general');
  expect(recoveryReissue.slip.items?.[0]?.material_reservation_id ?? null).toBeNull();
  await startViaUi(manager, fixture.recovery_work_order_id, 200);
  const recoveryDetail = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.recovery_work_order_id}`));
  expect(recoveryDetail.material_lot_references.some((row) => row.material_lot_number === fixture.material_lot_number)).toBe(true);
  expect(Number(recoveryDetail.materials[0].actual_quantity_issued)).toBe(2);
  expect(Number(recoveryDetail.material_cost_summary.actual_cost)).toBe(8);

  // Exercise breakdown pause, system-created corrective WO and maintenance role recovery.
  await manager.getByRole('button', { name: 'Pause', exact: true }).click();
  await manager.getByLabel(/^Downtime category\s?\*?$/).selectOption('breakdown');
  await manager.getByLabel(/^Reason\s?\*?$/).fill('Audit: machine breakdown recovery.');
  const pausePromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${fixture.recovery_work_order_id}/pause`));
  await manager.getByRole('dialog').getByRole('button', { name: 'Pause work order', exact: true }).click();
  const pauseResponse = await pausePromise;
  expect(pauseResponse.status()).toBe(200);
  await expect(manager.getByText('Paused', { exact: true })).toBeVisible();
  const maintenanceList = dataOf(await at(maintenance, 'GET', '/maintenance/work-orders?per_page=100'));
  const corrective = maintenanceList.find((row) => row.maintainable?.id === fixture.machine_id && row.status === 'open');
  expect(corrective, 'system-created machine corrective work order').toBeTruthy();
  const maintenanceStart = await at(maintenance, 'PATCH', `/maintenance/work-orders/${corrective.id}/start`);
  const maintenanceComplete = await at(maintenance, 'PATCH', `/maintenance/work-orders/${corrective.id}/complete`, { remarks: 'Machine checked; safe to return to production.' });
  expect(dataOf(maintenanceStart).status).toBe('in_progress');
  expect(dataOf(maintenanceComplete).status).toBe('completed');
  await manager.getByRole('button', { name: 'Resume', exact: true }).click();
  const resumeDialog = manager.getByRole('dialog');
  const resumePromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${fixture.recovery_work_order_id}/resume`));
  await resumeDialog.getByRole('button', { name: 'Resume', exact: true }).click();
  expect((await resumePromise).status()).toBe(200);
  await expect(manager.getByText('In Progress', { exact: true })).toBeVisible();
  passed('Maintenance Technician starts/completes the automatic breakdown MWO; Production Manager resumes the paused WO', {
    breakdownMwo: corrective.mwo_number,
    start: maintenanceStart.status,
    complete: maintenanceComplete.status,
    resume: 200,
  });
  await maintenance.context().close();

  // A good output is per-positive-output FG receipt; the operation ledger must reconcile.
  const recoveryOutput = await outputViaUi(manager, fixture.recovery_work_order_id, 2, 0, fixture.defect_type_id, fixture.shift_name, 'recovery', { target: 2, produced: 2, good: 2, reject: 0 });
  expect(recoveryOutput.production_receipt_handoff.status).toBe('generated');
  await runOperationViaUi(manager, fixture.recovery_work_order_id, 2, 2, 0);
  await manager.goto(`/production/work-orders/${fixture.recovery_work_order_id}`);
  await manager.getByRole('button', { name: 'Complete', exact: true }).click();
  let completeDialog = manager.getByRole('dialog');
  let completePromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${fixture.recovery_work_order_id}/complete`));
  await completeDialog.getByRole('button', { name: 'Complete', exact: true }).click();
  expect((await completePromise).status()).toBe(200);
  await measureReviewAll(qc, qcChecker, manager, managerId, fixture.recovery_work_order_id, 'in_process');
  const recoveryOutgoing = await measureReviewAll(qc, qcChecker, manager, managerId, fixture.recovery_work_order_id, 'outgoing');
  await closeViaUi(manager, fixture.recovery_work_order_id);
  passed('Recovery WO completed with routed output, in-process QC and output-bound outgoing review', {
    good: recoveryOutput.good_count,
    fgReceipt: recoveryOutput.production_receipt_handoff.status,
    outgoingInspections: recoveryOutgoing.length,
  });

  // Issue the already-reserved Main target, then prove consumed material cannot be reversed after output.
  const mainIssue = await createIssueInUi(warehouse, fixture.main_work_order_id, 5, fixture.material_lot_number, fixture.main_work_order_number, 'reservation');
  await startViaUi(manager, fixture.main_work_order_id, 200);
  const startedMain = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.main_work_order_id}`));
  expect(Number(startedMain.quantity_target)).toBe(5);
  expect(Number(startedMain.materials[0].actual_quantity_issued)).toBe(5);
  expect(Number(startedMain.material_cost_summary.actual_cost)).toBe(20);
  expect(startedMain.material_lot_references.some((row) => row.material_lot_number === fixture.material_lot_number)).toBe(true);
  const firstMainOutput = await outputViaUi(mobileManager, fixture.main_work_order_id, 3, 1, fixture.defect_type_id, fixture.shift_name, 'partial', { target: 5, produced: 4, good: 3, reject: 1 });
  expect(firstMainOutput.production_receipt_handoff.status).toBe('generated');
  const mainFreshAfterFirst = dataOf(await at(mobileManager, 'GET', `/production/work-orders/${fixture.main_work_order_id}`));
  expect(mainFreshAfterFirst.quantity_target).toBe(5);
  expect(mainFreshAfterFirst.quantity_produced).toBe(4);
  expect(mainFreshAfterFirst.quantity_good).toBe(3);
  expect(mainFreshAfterFirst.quantity_rejected).toBe(1);
  await screenshot(mobileManager, 'main-partial-output-refreshed-detail.png');
  const stockBeforeForbiddenCancel = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  const forbiddenCancel = await request(warehouse, 'DELETE', `/inventory/material-issues/${mainIssue.slip.id}`);
  expect(forbiddenCancel.status).toBe(422);
  expect(forbiddenCancel.body.message).toMatch(/after the work order has recorded output|may already have been consumed/i);
  const stockAfterForbiddenCancel = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  expect(stockAfterForbiddenCancel.quantity).toBe(stockBeforeForbiddenCancel.quantity);
  expect(stockAfterForbiddenCancel.reserved_quantity).toBe(stockBeforeForbiddenCancel.reserved_quantity);
  passed('Partial output returns from UI with current counters while realtime business events are withheld; post-output Warehouse cancellation refuses and leaves stock unchanged', {
    target: mainFreshAfterFirst.quantity_target,
    produced: mainFreshAfterFirst.quantity_produced,
    good: mainFreshAfterFirst.quantity_good,
    rejected: mainFreshAfterFirst.quantity_rejected,
    cancellation: forbiddenCancel.status,
    stock: { before: stockBeforeForbiddenCancel.quantity, after: stockAfterForbiddenCancel.quantity },
  });
  await mobileManager.context().close();

  const mainIssueMovement = await sourceMovementFor(warehouse, fixture.raw_item_id, 'material_issue_slip', mainIssue.slip.id);
  expect(mainIssue.slip.items[0].stock_movement_id).toBe(mainIssueMovement.id);
  const ppcReturnAttempt = await login('ppc@ogami.test');
  const deniedMaterialReturn = await returnMovementViaApi(ppcReturnAttempt, mainIssueMovement.id, '0.100', '0.000', 'pwo-unauthorized-return-001');
  expect(deniedMaterialReturn.status, JSON.stringify(deniedMaterialReturn.body)).toBe(403);
  await ppcReturnAttempt.context().close();
  const sourceOptionsBeforeReturn = dataOf(await at(warehouse, 'GET', `/inventory/stock-movements/${mainIssueMovement.id}/return-options`));
  expect(sourceOptionsBeforeReturn.consumption_basis).toBe('saved_bom_norm');
  expect(Number(sourceOptionsBeforeReturn.gross_production_units)).toBe(4);
  expect(Number(sourceOptionsBeforeReturn.consumption_floor)).toBe(4);
  expect(Number(sourceOptionsBeforeReturn.returnable_quantity)).toBe(1);
  await warehouse.goto(`/inventory/material-issues/${mainIssue.slip.id}`);
  const sourceMovementLink = warehouse.getByRole('link', { name: 'View movement / return unused', exact: true });
  await expect(sourceMovementLink).toHaveAttribute('href', `/inventory/stock-levels?view=movements&movement_id=${mainIssueMovement.id}`);
  await sourceMovementLink.click();
  await expect(warehouse).toHaveURL(new RegExp(`/inventory/stock-levels\\?view=movements&movement_id=${mainIssueMovement.id}$`));
  const mainReturnDialog = await openMaterialReturnFromCurrentPage(warehouse);
  await mainReturnDialog.getByLabel(/^Quantity to return/).fill('0.500');
  await mainReturnDialog.getByLabel(/^Reason/).fill('Return partial unused resin after reject review.');
  const racedReturn = await returnMovementViaApi(warehouse, mainIssueMovement.id, '0.500', '0.000', 'pwo-manual-concurrent-return-001');
  expect(racedReturn.status, JSON.stringify(racedReturn.body)).toBe(200);
  const staleUiPromise = fillAndSubmitMaterialReturn(warehouse, mainReturnDialog, '0.500', 'Return the remaining verified unused resin.');
  const staleUiResponse = await staleUiPromise;
  expect(staleUiResponse.status()).toBe(409);
  await expect(mainReturnDialog.getByText(/A newer return changed this movement/)).toBeVisible();
  const returnQty = mainReturnDialog.getByLabel(/^Quantity to return/);
  await expect.poll(() => returnQty.getAttribute('max')).toBe('0.500');
  const returnSummary = mainReturnDialog.getByText('Already returned', { exact: true }).locator('..');
  await expect(returnSummary).toContainText('0.500');
  await screenshot(warehouse, 'manual-return-conflict-refreshed-balance.png');
  const finalPartialReturn = await fillAndSubmitMaterialReturn(warehouse, mainReturnDialog, '0.500', 'Return the remaining verified unused resin.');
  expect(finalPartialReturn.status()).toBe(200);
  const manualReturnOptionsAfter = dataOf(await at(warehouse, 'GET', `/inventory/stock-movements/${mainIssueMovement.id}/return-options`));
  expect(Number(manualReturnOptionsAfter.returned_quantity)).toBe(1);
  expect(Number(manualReturnOptionsAfter.returnable_quantity)).toBe(0);
  const manualReturnRows = dataOf(await at(warehouse, 'GET', '/inventory/stock-movements?movement_type=material_return&per_page=200'))
    .filter((row) => row.reference_type === 'stock_movement' && row.reference_id === mainIssueMovement.id);
  expect(manualReturnRows).toHaveLength(2);
  expect(manualReturnRows.reduce((sum, row) => sum + Number(row.quantity), 0)).toBe(1);
  const belowFloorOutput = await blockedOutputViaUi(manager, fixture.main_work_order_id, 2, fixture.shift_name);
  const materialReissue = await createIssueInUi(warehouse, fixture.main_work_order_id, 2, fixture.material_lot_number, fixture.main_work_order_number, 'general');
  expect(materialReissue.slip.items?.[0]?.material_reservation_id ?? null).toBeNull();
  const secondMainOutput = await outputViaUi(manager, fixture.main_work_order_id, 2, 0, fixture.defect_type_id, fixture.shift_name, 'final', { target: 5, produced: 6, good: 5, reject: 1 });
  expect(secondMainOutput.production_receipt_handoff.status).toBe('generated');
  passed('Issue-detail link opens the return action; a concurrent partial return refreshes with 409, and replacement output waits for material above the reject-aware floor', {
    linkedMovement: mainIssueMovement.id,
    originalFloor: sourceOptionsBeforeReturn.consumption_floor,
    returnableBefore: sourceOptionsBeforeReturn.returnable_quantity,
    externalReturnStatus: racedReturn.status,
    staleUiStatus: staleUiResponse.status(),
    returned: manualReturnOptionsAfter.returned_quantity,
    attemptedReplacementShortage: belowFloorOutput.message,
    replacementIssue: materialReissue.slip.items[0].quantity_issued,
    replacementOutputStatus: 201,
  });
  await runOperationViaUi(manager, fixture.main_work_order_id, 5, 5, 1);
  await manager.goto(`/production/work-orders/${fixture.main_work_order_id}`);
  await manager.getByRole('button', { name: 'Complete', exact: true }).click();
  completeDialog = manager.getByRole('dialog');
  completePromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${fixture.main_work_order_id}/complete`));
  await completeDialog.getByRole('button', { name: 'Complete', exact: true }).click();
  expect((await completePromise).status()).toBe(200);
  await measureReviewAll(qc, qcChecker, manager, managerId, fixture.main_work_order_id, 'in_process');
  const mainOutgoing = await measureReviewAll(qc, qcChecker, manager, managerId, fixture.main_work_order_id, 'outgoing', true);
  await closeViaUi(manager, fixture.main_work_order_id);

  const finalDetail = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.main_work_order_id}`));
  expect(finalDetail.status).toBe('closed');
  expect(finalDetail.quantity_target).toBe(5);
  expect(finalDetail.quantity_produced).toBe(6);
  expect(finalDetail.quantity_good).toBe(5);
  expect(finalDetail.quantity_rejected).toBe(1);
  expect(Number(finalDetail.materials[0].manual_quantity_issued)).toBe(6);
  expect(Number(finalDetail.materials[0].manual_returned_quantity)).toBe(1);
  expect(Number(finalDetail.material_cost_summary.actual_cost)).toBe(24);
  const finalOutputs = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.main_work_order_id}/outputs`));
  expect(finalOutputs).toHaveLength(2);
  expect(new Set(finalOutputs.map((item) => item.id))).toEqual(new Set([firstMainOutput.id, secondMainOutput.id]));
  const outputsById = new Map(finalOutputs.map((item) => [item.id, item]));
  expect([outputsById.get(firstMainOutput.id).good_count, outputsById.get(firstMainOutput.id).reject_count]).toEqual([3, 1]);
  expect([outputsById.get(secondMainOutput.id).good_count, outputsById.get(secondMainOutput.id).reject_count]).toEqual([2, 0]);
  expect(finalOutputs.every((item) => item.production_receipt_handoff.status === 'generated')).toBe(true);
  for (const item of finalOutputs) {
    const lineage = item.material_lineage;
    if (item.id === firstMainOutput.id) {
      expect(Number(lineage.materials[0].manual_quantity_issued)).toBe(5);
      expect(Number(lineage.materials[0].manual_gross_quantity_issued)).toBe(5);
      expect(Number(lineage.materials[0].manual_returned_quantity)).toBe(0);
      expect(Number(lineage.materials[0].manual_actual_cost)).toBe(20);
    } else {
      expect(Number(lineage.materials[0].manual_quantity_issued)).toBe(6);
      expect(Number(lineage.materials[0].manual_gross_quantity_issued)).toBe(7);
      expect(Number(lineage.materials[0].manual_returned_quantity)).toBe(1);
      expect(Number(lineage.materials[0].manual_actual_cost)).toBe(24);
    }
    expect(lineage.material_lot_references.some((row) => row.material_lot_number === fixture.material_lot_number)).toBe(true);
  }
  const finalStock = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  expect(Number(finalStock.quantity)).toBe(1);
  expect(Number(finalStock.reserved_quantity)).toBe(0);
  expect(Number(finalStock.available)).toBe(1);
  const finalFg = await stockAt(warehouse, fixture.finished_item_id, fixture.fg_location_id);
  expect(Number(finalFg.quantity)).toBe(7);
  passed('Main work order completed, independently quality-reviewed and closed; each positive output generated its own FG receipt', {
    status: finalDetail.status,
    target: finalDetail.quantity_target,
    produced: finalDetail.quantity_produced,
    good: finalDetail.quantity_good,
    rejected: finalDetail.quantity_rejected,
    material: finalDetail.materials[0],
    outputs: finalOutputs.map((item) => ({ good: item.good_count, reject: item.reject_count, receipt: item.production_receipt_handoff.status })),
    raw: { quantity: finalStock.quantity, reserved: finalStock.reserved_quantity, available: finalStock.available },
    finishedGoods: { quantity: finalFg.quantity, location: fixture.fg_location_id },
    outgoingInspections: mainOutgoing.length,
  });

  await confirmViaUi(manager, fixture.auto_return_work_order_id);
  await startViaUi(manager, fixture.auto_return_work_order_id, 200);
  const autoReturnDetail = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.auto_return_work_order_id}`));
  expect(Number(autoReturnDetail.materials[0].auto_quantity_issued)).toBe(1);
  expect(Number(autoReturnDetail.materials[0].auto_gross_quantity_issued)).toBe(1);
  expect(Number(autoReturnDetail.materials[0].manual_quantity_issued)).toBe(0);
  const autoIssueMovement = await sourceMovementFor(warehouse, fixture.raw_item_id, 'work_order', fixture.auto_return_work_order_id);
  const autoReturnDialog = await openMaterialReturn(warehouse, autoIssueMovement.id);
  const autoBeforeReturn = dataOf(await at(warehouse, 'GET', `/inventory/stock-movements/${autoIssueMovement.id}/return-options`));
  expect(autoBeforeReturn.consumption_basis).toBe('preproduction');
  expect(Number(autoBeforeReturn.returnable_quantity)).toBe(1);
  const autoReturnReason = 'Return half the auto-issued unopened resin.';
  await autoReturnDialog.getByLabel(/^Quantity to return/).fill('0.500');
  await autoReturnDialog.getByLabel(/^Reason/).fill(autoReturnReason);
  const warehouseUserId = dataOf(await at(warehouse, 'GET', '/auth/user')).id;
  const pendingReturnStorageKey = `ogami:inventory:material-return:${warehouseUserId}`;
  let droppedReturnStatus = 0;
  let droppedReturnPayload;
  let droppedReturnKey;
  const autoReturnRoute = `**/api/v1/inventory/stock-movements/${autoIssueMovement.id}/return-unused`;
  await warehouse.route(autoReturnRoute, async (route) => {
    droppedReturnPayload = route.request().postDataJSON();
    droppedReturnKey = route.request().headers()['idempotency-key'];
    const committed = await route.fetch();
    droppedReturnStatus = committed.status();
    await committed.dispose();
    await route.abort('failed');
  });
  const lostResponsePromise = warehouse.waitForEvent('requestfailed', (request) =>
    request.url().endsWith(`/inventory/stock-movements/${autoIssueMovement.id}/return-unused`));
  await autoReturnDialog.getByRole('button', { name: 'Post return', exact: true }).click();
  await lostResponsePromise;
  expect(droppedReturnStatus).toBe(200);
  await expect(autoReturnDialog.getByText(/Your request is saved with its original details/)).toBeVisible();
  const savedPending = await warehouse.evaluate((key) => JSON.parse(localStorage.getItem(key) ?? 'null'), pendingReturnStorageKey);
  expect(savedPending).toMatchObject({
    sourceId: autoIssueMovement.id,
    quantity: '0.500',
    expectedReturned: '0.000',
    reason: autoReturnReason,
  });
  expect(savedPending.idempotencyKey).toBe(droppedReturnKey);
  await screenshot(warehouse, 'auto-return-unknown-response-saved-request.png');
  await warehouse.unroute(autoReturnRoute);
  await warehouse.reload();
  await expect(warehouse.getByRole('status').filter({ hasText: 'awaiting confirmation' })).toBeVisible();
  const retryReturnDialog = await openMaterialReturn(warehouse, autoIssueMovement.id);
  await expect(retryReturnDialog.getByLabel(/^Quantity to return/)).toBeDisabled();
  expect(await retryReturnDialog.getByLabel(/^Quantity to return/).inputValue()).toBe('0.500');
  expect(await retryReturnDialog.getByLabel(/^Reason/).inputValue()).toBe(autoReturnReason);
  const retryPostPromise = warehouse.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/inventory/stock-movements/${autoIssueMovement.id}/return-unused`)
    && response.request().method() === 'POST');
  const retryRequestPromise = warehouse.waitForRequest((request) =>
    request.url().endsWith(`/api/v1/inventory/stock-movements/${autoIssueMovement.id}/return-unused`)
    && request.method() === 'POST');
  await retryReturnDialog.getByRole('button', { name: 'Retry last return', exact: true }).click();
  const retryRequest = await retryRequestPromise;
  const retryPost = await retryPostPromise;
  expect(retryRequest.postDataJSON()).toEqual(droppedReturnPayload);
  expect(retryRequest.headers()['idempotency-key']).toBe(droppedReturnKey);
  expect(retryPost.status()).toBe(200);
  await expect(retryReturnDialog).not.toBeVisible();
  expect(await warehouse.evaluate((key) => localStorage.getItem(key), pendingReturnStorageKey)).toBeNull();
  const returnMovements = dataOf(await at(warehouse, 'GET', '/inventory/stock-movements?movement_type=material_return&per_page=200'));
  const autoReturns = returnMovements.filter((row) => row.reference_type === 'stock_movement' && row.reference_id === autoIssueMovement.id);
  expect(autoReturns).toHaveLength(1, 'An aborted-success retry must replay the committed original return rather than post another movement.');
  expect(Number(autoReturns[0].quantity)).toBe(0.5);
  const autoReturnOptions = dataOf(await at(warehouse, 'GET', `/inventory/stock-movements/${autoIssueMovement.id}/return-options`));
  expect(Number(autoReturnOptions.returned_quantity)).toBe(0.5);
  expect(Number(autoReturnOptions.returnable_quantity)).toBe(0.5);
  const autoShortOutput = await blockedOutputViaUi(manager, fixture.auto_return_work_order_id, 1, fixture.shift_name);
  const autoReplacementIssue = await createIssueInUi(warehouse, fixture.auto_return_work_order_id, 0.5, fixture.material_lot_number, fixture.auto_return_work_order_number, 'general');
  expect(autoReplacementIssue.slip.items?.[0]?.material_reservation_id ?? null).toBeNull();
  const autoReady = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.auto_return_work_order_id}`));
  expect(Number(autoReady.materials[0].auto_quantity_issued)).toBe(0.5, 'Automatic quantity issued is the net of returns.');
  expect(Number(autoReady.materials[0].auto_gross_quantity_issued)).toBe(1, 'The immutable automatic counter keeps its gross source quantity.');
  expect(Number(autoReady.materials[0].auto_returned_quantity)).toBe(0.5);
  expect(Number(autoReady.materials[0].manual_quantity_issued)).toBe(0.5);
  expect(Number(autoReady.materials[0].actual_quantity_issued)).toBe(1);
  const autoOutput = await outputViaUi(manager, fixture.auto_return_work_order_id, 1, 0, fixture.defect_type_id, fixture.shift_name, 'auto-return-recovered', { target: 1, produced: 1, good: 1, reject: 0 });
  expect(autoOutput.production_receipt_handoff.status).toBe('generated');
  await runOperationViaUi(manager, fixture.auto_return_work_order_id, 1, 1, 0);
  await manager.goto(`/production/work-orders/${fixture.auto_return_work_order_id}`);
  await manager.getByRole('button', { name: 'Complete', exact: true }).click();
  completeDialog = manager.getByRole('dialog');
  completePromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${fixture.auto_return_work_order_id}/complete`));
  await completeDialog.getByRole('button', { name: 'Complete', exact: true }).click();
  expect((await completePromise).status()).toBe(200);
  await measureReviewAll(qc, qcChecker, manager, managerId, fixture.auto_return_work_order_id, 'in_process');
  let autoOutgoing = [];
  if (autoReturnDetail.sales_order) {
    autoOutgoing = await measureReviewAll(qc, qcChecker, manager, managerId, fixture.auto_return_work_order_id, 'outgoing');
  } else {
    const autoInspections = dataOf(await at(qc, 'GET', `/quality/inspections?entity_type=work_order&entity_id=${encodeURIComponent(fixture.auto_return_work_order_id)}&per_page=100`));
    autoOutgoing = autoInspections.filter((inspection) => inspection.stage === 'outgoing');
    expect(autoOutgoing).toHaveLength(0);
    passed('Standalone auto-return work order requires in-process QC but no outgoing inspection', {
      salesOrder: autoReturnDetail.sales_order,
      outgoingInspectionCount: autoOutgoing.length,
      basis: 'The fixture has no customer-order or NCR replacement link; TriggerOutgoingQC intentionally skips it.',
    });
  }
  await closeViaUi(manager, fixture.auto_return_work_order_id);
  const fullyReconciledAuto = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.auto_return_work_order_id}`));
  expect(fullyReconciledAuto.status).toBe('closed');
  expect(Number(fullyReconciledAuto.materials[0].auto_quantity_issued)).toBe(0.5);
  expect(Number(fullyReconciledAuto.materials[0].auto_gross_quantity_issued)).toBe(1);
  expect(Number(fullyReconciledAuto.materials[0].auto_returned_quantity)).toBe(0.5);
  expect(Number(fullyReconciledAuto.materials[0].manual_quantity_issued)).toBe(0.5);
  expect(Number(fullyReconciledAuto.materials[0].actual_quantity_issued)).toBe(1);
  const finalRawAfterAuto = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  expect(Number(finalRawAfterAuto.quantity)).toBe(0);
  const finalFgAfterAuto = await stockAt(warehouse, fixture.finished_item_id, fixture.fg_location_id);
  expect(Number(finalFgAfterAuto.quantity)).toBe(8);
  passed('Warehouse returns part of auto-issued material, Production blocks output until a manual reissue restores net coverage, and counters stay auto-only', {
    autoIssue: autoIssueMovement.quantity,
    returned: autoReturnOptions.returned_quantity,
    blockedOutput: autoShortOutput.message,
    manualReissue: autoReplacementIssue.slip.items[0].quantity_issued,
    autoGross: autoReady.materials[0].auto_gross_quantity_issued,
    autoReturned: autoReady.materials[0].auto_returned_quantity,
    manualNet: autoReady.materials[0].manual_quantity_issued,
    netCoverage: autoReady.materials[0].actual_quantity_issued,
    productionReceipt: autoOutput.production_receipt_handoff.status,
    outgoingReviews: autoOutgoing.length,
    finalWorkOrderStatus: fullyReconciledAuto.status,
    rawRemaining: finalRawAfterAuto.quantity,
    finishedGoods: finalFgAfterAuto.quantity,
  });

  // The next module owns delivery creation; merely assert approved, output-bound FG eligibility.
  const releaseEligible = await at(manager, 'GET', `/quality/inspections?entity_type=work_order&entity_id=${encodeURIComponent(fixture.main_work_order_id)}&stage=outgoing&status=passed&per_page=100`);
  expect(dataOf(releaseEligible).length).toBeGreaterThan(0);
  report.handoffs.push({ from: 'Production / QC', to: 'Delivery', status: 'passed output-bound outgoing inspections are available as FG eligibility evidence; no Delivery record forced' });
  passed('Production hands approved output-bound inspections and posted FG to the next Delivery stage without forcing delivery', { passedOutgoingInspections: dataOf(releaseEligible).length, fg_quantity: finalFg.quantity });

  await screenshot(manager, 'closed-main-work-order.png');
  await screenshot(warehouse, 'warehouse-material-issue-detail.png');
  if (jsErrors.length) throw new Error(`Browser page errors: ${jsErrors.join(' | ')}`);
  report.echo_websocket_sessions = echoWebsocketSessions;
  report.websocket_subscriptions_acknowledged = websocketSubscriptionsAcknowledged;
  report.websocket_pings_answered = websocketPingsAnswered;
  report.echo_websocket_urls_handled = echoWebsocketUrls;
  report.real_time_business_events_sent = 0;
  report.real_time_transport = 'inert Reverb protocol fixture; business events withheld';
  report.vite_hmr_websockets_observed = live.flatMap((entry) => entry.diagnostics.websocketConnections).filter((entry) => entry.isViteHmr).length;
  report.result = 'passed';
  report.finished_at = new Date().toISOString();
  report.javascript_errors = jsErrors;
  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2));
  fs.writeFileSync(path.join(OUT, 'report.txt'), [
    `Production work-order browser acceptance ${RUN}`,
    `Database: ${fixture.database}`,
    `Checks: ${report.checks.length}`,
    `Cookie-authenticated roles: ${[...new Set(live.map((entry) => entry.email))].join(', ')}`,
    `Inert Reverb protocol test-double sessions: ${echoWebsocketSessions}; business events sent: 0`,
    `Artifacts: ${report.artifacts.length}`,
  ].join('\n') + '\n');
  console.log(`REPORT_DIR=${OUT}`);
  console.log(`REPORT_JSON=${path.join(OUT, 'report.json')}`);
  console.log(`CHECKS=${report.checks.length}`);
}

main().catch(async (error) => {
  report.result = 'failed';
  report.error = String(error?.stack ?? error);
  report.echo_websocket_sessions = echoWebsocketSessions;
  report.websocket_subscriptions_acknowledged = websocketSubscriptionsAcknowledged;
  report.websocket_pings_answered = websocketPingsAnswered;
  report.echo_websocket_urls_handled = echoWebsocketUrls;
  report.real_time_business_events_sent = 0;
  report.real_time_transport = 'inert Reverb protocol fixture; business events withheld';
  report.vite_hmr_websockets_observed = live.flatMap((entry) => entry.diagnostics.websocketConnections).filter((entry) => entry.isViteHmr).length;
  report.javascript_errors = jsErrors;
  report.failures.push({ message: String(error?.message ?? error), stack: String(error?.stack ?? error) });
  await Promise.allSettled(live.map(async ({ page, email, diagnostics }, index) => {
    try {
      const name = email.replace(/[^a-z0-9]+/gi, '-').toLowerCase();
      const viewport = page.viewportSize();
      const context = `${index + 1}-${viewport?.width ?? 'unknown'}x${viewport?.height ?? 'unknown'}`;
      const file = path.join(OUT, `failure-${name}-${context}.png`);
      const pageState = await Promise.race([
        page.evaluate(() => {
          const root = document.querySelector('#root') ?? document.body;
          return {
            url: location.href,
            readyState: document.readyState,
            title: document.title,
            documentElementHtmlLength: document.documentElement?.outerHTML.length ?? 0,
            appRootHtmlLength: root?.outerHTML.length ?? 0,
            appRootText: root?.innerText?.slice(0, 3000) ?? '',
            scriptResources: performance.getEntriesByType('resource')
              .filter((entry) => entry.name.includes('.js'))
              .slice(-30)
              .map((entry) => ({ url: entry.name, duration: Math.round(entry.duration), transferSize: entry.transferSize })),
          };
        }),
        new Promise((resolve) => setTimeout(() => resolve({ evaluationTimedOut: true }), 2_000)),
      ]);
      await page.screenshot({ path: file, fullPage: true, timeout: 10_000 });
      report.artifacts.push(file);
      report.failures.push({ context, url: page.url(), screenshot: file, pageState, diagnostics });
    } catch (screenshotError) {
      report.failures.push({ screenshot_error: String(screenshotError), diagnostics });
    }
  }));
  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2));
  console.error(error);
  console.error(`REPORT_DIR=${OUT}`);
  process.exitCode = 1;
}).finally(async () => {
  await Promise.allSettled(live.map(async (entry) => {
    await entry.context.close();
  }));
  if (sharedBrowser) await sharedBrowser.close();
});
