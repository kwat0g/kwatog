// Continue the material-return acceptance flow from a fixture whose main WO is
// already closed and whose auto-issued WO is in progress. This runner verifies
// that state before making the auto-return scenario's own business writes.
const { chromium, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const BASE = process.env.PRODUCTION_WORKORDERS_TEST_URL || 'http://127.0.0.1:5210';
const FIXTURE_PATH = process.env.PRODUCTION_WORKORDERS_FIXTURE_PATH;
if (!FIXTURE_PATH) throw new Error('Set PRODUCTION_WORKORDERS_FIXTURE_PATH to the D fixture manifest.');
const fixture = JSON.parse(fs.readFileSync(FIXTURE_PATH, 'utf8'));
const returnedBeforeRun = process.env.MATERIAL_RECOVERY_CONTINUATION_RETURNED || '0';
const continuationState = process.env.MATERIAL_RECOVERY_CONTINUATION_STATE || 'after-return';
if (!['0', '0.500'].includes(returnedBeforeRun)) {
  throw new Error('MATERIAL_RECOVERY_CONTINUATION_RETURNED must be 0 or 0.500.');
}
if (!['after-return', 'ready-to-close'].includes(continuationState)) {
  throw new Error('MATERIAL_RECOVERY_CONTINUATION_STATE must be after-return or ready-to-close.');
}
const OUT = process.env.MATERIAL_RECOVERY_CONTINUATION_OUTPUT
  || path.join('/tmp', `material-recovery-auto-continuation-${fixture.run_id}-${Date.now()}`);
fs.mkdirSync(OUT, { recursive: false });

const report = {
  run_id: fixture.run_id,
  database: fixture.database,
  base_url: BASE,
  source_run_report: '/tmp/material-recovery-headless-MR0925D-final/report.json',
  authentication: 'Sanctum session cookie + CSRF cookie; no bearer or browser-storage auth',
  checks: [],
  failures: [],
  artifacts: [],
};
const contexts = [];
const pageErrors = [];
let browser;

function dataOf(result) {
  return result.body?.data ?? result.body;
}

function passed(name, evidence = {}) {
  report.checks.push({ name, evidence });
  console.log(`PASS: ${name}`);
}

async function screenshot(page, name) {
  const file = path.join(OUT, name);
  await page.screenshot({ path: file, fullPage: true });
  report.artifacts.push(file);
  return file;
}

async function login(email) {
  const context = await browser.newContext({
    baseURL: BASE,
    viewport: { width: 1440, height: 1000 },
    timezoneId: 'Asia/Manila',
    reducedMotion: 'reduce',
  });
  context.routeWebSocket((url) => {
    const candidate = new URL(url);
    return (candidate.port === '8080' || candidate.port === '443') && candidate.pathname.startsWith('/app/');
  }, (socket) => {
    socket.onMessage((message) => {
      if (typeof message !== 'string') return;
      let frame;
      try { frame = JSON.parse(message); } catch { return; }
      if (frame.event === 'pusher:ping') {
        socket.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
      } else if (frame.event === 'pusher:subscribe' && frame.data?.channel) {
        socket.send(JSON.stringify({
          event: 'pusher_internal:subscription_succeeded',
          channel: frame.data.channel,
          data: '{}',
        }));
      }
    });
    socket.send(JSON.stringify({
      event: 'pusher:connection_established',
      data: JSON.stringify({ socket_id: `9900925.auto.${contexts.length}`, activity_timeout: 120 }),
    }));
  });
  const page = await context.newPage();
  page.setDefaultTimeout(20_000);
  page.on('pageerror', (error) => pageErrors.push(`${email}: ${error.message}`));
  await page.goto('/sign-in');
  await page.getByLabel('Email', { exact: true }).fill(email);
  await page.getByLabel('Password', { exact: true }).fill(process.env.PRODUCTION_WORKORDERS_TEST_PASSWORD || 'password');
  const responsePromise = page.waitForResponse((response) =>
    response.url().endsWith('/api/v1/auth/sign-in') && response.request().method() === 'POST');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  const response = await responsePromise;
  expect(response.status(), `${email} login`).toBe(200);
  const storage = await page.evaluate(() => ({
    local: Object.keys(localStorage),
    session: Object.keys(sessionStorage),
    cookies: document.cookie.split(';').map((part) => part.trim().split('=')[0]),
  }));
  expect(storage.local.some((key) => /token|auth/i.test(key))).toBe(false);
  expect(storage.session.some((key) => /token|auth/i.test(key))).toBe(false);
  expect(storage.cookies).toContain('XSRF-TOKEN');
  contexts.push({ context, page, email });
  passed(`Real cookie + CSRF login: ${email}`, { storage });
  return page;
}

async function api(page, method, route, body) {
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
    let parsed;
    try { parsed = JSON.parse(text); } catch { parsed = { raw: text }; }
    return { status: response.status, body: parsed };
  }, { method, route, body });
}

async function at(page, method, route, body, expectedStatus = 200) {
  const result = await api(page, method, route, body);
  expect(result.status, `${method} ${route}: ${JSON.stringify(result.body)}`).toBe(expectedStatus);
  return result;
}

async function sourceMovement(page) {
  const movements = dataOf(await at(page, 'GET', `/inventory/stock-movements?item_id=${encodeURIComponent(fixture.raw_item_id)}&movement_type=material_issue&reference_type=work_order&per_page=200`));
  const source = movements.find((movement) => movement.reference_id === fixture.auto_return_work_order_id);
  expect(source, 'auto-start MaterialIssue source movement').toBeTruthy();
  expect(Number(source.quantity)).toBe(1);
  expect(source.lot_number).toBe(fixture.material_lot_number);
  return source;
}

async function stockAt(page, itemId, locationId) {
  const rows = dataOf(await at(page, 'GET', `/inventory/stock-levels?item_id=${encodeURIComponent(itemId)}&per_page=100`));
  const row = rows.find((entry) => entry.location?.id === locationId);
  expect(row, `stock row ${itemId}/${locationId}`).toBeTruthy();
  return row;
}

async function openReturn(page, movementId) {
  await page.goto(`/inventory/stock-levels?view=movements&movement_id=${encodeURIComponent(movementId)}`);
  await expect(page.getByRole('heading', { name: 'Stock movements' }).first()).toBeVisible();
  const button = page.getByRole('button', { name: 'Return unused', exact: true });
  await expect(button).toBeVisible();
  await button.click();
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('heading', { name: 'Return unused material' })).toBeVisible();
  await expect(dialog.getByText('Originally issued', { exact: true })).toBeVisible();
  return dialog;
}

async function returnOptions(page, sourceId) {
  return dataOf(await at(page, 'GET', `/inventory/stock-movements/${sourceId}/return-options`));
}

async function createGeneralIssue(warehouse, workOrderId, quantity) {
  await warehouse.goto(`/inventory/material-issues/create?work_order_id=${encodeURIComponent(workOrderId)}`);
  await expect(warehouse.getByRole('heading', { name: 'New material issue' })).toBeVisible();
  const form = warehouse.locator('form');
  const selects = form.locator('select');
  await expect(selects.nth(0)).toHaveValue(workOrderId);
  expect((await selects.nth(0).locator('option').allTextContents()).join(' ')).toContain(fixture.auto_return_work_order_number);
  await selects.nth(1).selectOption(fixture.raw_item_id);
  await selects.nth(2).selectOption(fixture.raw_location_id);
  await form.getByPlaceholder('Lot (optional)').fill(fixture.material_lot_number);
  await form.getByPlaceholder('0').fill(String(quantity));
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
  expect(requestBodies[0].items[0].material_reservation_id).toBeUndefined();
  const slip = payload.data;
  expect(slip.items?.[0]?.stock_movement_id).toBeTruthy();
  await expect(warehouse).toHaveURL(new RegExp(`/inventory/material-issues/${slip.id}$`));
  await expect(warehouse.getByText(fixture.auto_return_work_order_number, { exact: true })).toBeVisible();
  return slip;
}

async function blockedOutput(manager, workOrderId) {
  await manager.goto(`/production/work-orders/${workOrderId}/record-output`);
  await expect(manager.getByRole('heading', { name: /Record output/ })).toBeVisible();
  await manager.getByLabel(/^Good count\s?\*?$/).fill('1');
  await manager.getByLabel('Shift', { exact: true }).selectOption(fixture.shift_name);
  const responsePromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${workOrderId}/outputs`)
    && response.request().method() === 'POST');
  await manager.getByRole('button', { name: 'Record', exact: true }).click();
  const response = await responsePromise;
  const body = await response.json();
  expect(response.status(), JSON.stringify(body)).toBe(422);
  expect(body.message).toMatch(/material|coverage|shortage/i);
  return body;
}

async function recordOutput(manager, workOrderId) {
  await manager.goto(`/production/work-orders/${workOrderId}/record-output`);
  await expect(manager.getByRole('heading', { name: /Record output/ })).toBeVisible();
  await manager.getByLabel(/^Good count\s?\*?$/).fill('1');
  await manager.getByLabel('Shift', { exact: true }).selectOption(fixture.shift_name);
  const responsePromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${workOrderId}/outputs`)
    && response.request().method() === 'POST');
  await manager.getByRole('button', { name: 'Record', exact: true }).click();
  const response = await responsePromise;
  const payload = await response.json();
  expect(response.status(), JSON.stringify(payload)).toBe(201);
  const output = payload.data;
  await expect(manager).toHaveURL(new RegExp(`/production/work-orders/${workOrderId}$`));
  const readCounter = async (label) => manager.getByText(label, { exact: true }).evaluate((node) => node.nextElementSibling?.textContent?.trim() ?? '');
  await expect.poll(() => readCounter('Target / Produced')).toBe('1 / 1');
  await expect.poll(() => readCounter('Good / Reject')).toContain('1 / 0');
  return output;
}

async function completeOperation(manager, workOrderId) {
  await manager.goto(`/production/work-orders/${workOrderId}`);
  await manager.getByRole('tab', { name: 'Operations', exact: true }).click();
  const row = manager.getByRole('row').filter({ hasText: 'Injection moulding' });
  await expect(row).toBeVisible();
  await row.getByRole('button', { name: 'Setup', exact: true }).click();
  await row.getByRole('button', { name: 'End setup', exact: true }).click();
  await row.getByRole('button', { name: 'Start', exact: true }).click();
  await expect(row.getByText('In Progress', { exact: true })).toBeVisible();
  await row.getByRole('button', { name: 'Output', exact: true }).click();
  const dialog = manager.getByRole('dialog');
  await dialog.getByLabel(/^Quantity\s?\*?$/).fill('1');
  await dialog.getByLabel('Scrap', { exact: true }).fill('0');
  await dialog.getByRole('button', { name: 'Record output', exact: true }).click();
  await expect(row.getByText(/1 \/ 1/)).toBeVisible();
  await row.getByRole('button', { name: 'Complete', exact: true }).click();
  await expect(row.getByText('Completed', { exact: true })).toBeVisible();
}

async function qualityReview(qc, checker, workOrderId, stage) {
  const listed = dataOf(await at(qc, 'GET', `/quality/inspections?entity_type=work_order&entity_id=${encodeURIComponent(workOrderId)}&per_page=100`));
  const inspections = listed.filter((row) => row.stage === stage);
  expect(inspections.length, `one ${stage} inspection`).toBeGreaterThan(0);
  const reviewed = [];
  for (const row of inspections) {
    const detail = dataOf(await at(qc, 'GET', `/quality/inspections/${row.id}`));
    expect(detail.measurements?.length ?? 0, 'measurement rows should exist').toBeGreaterThan(0);
    let completed;
    if (detail.inspection_mode === 'lot_checklist') {
      const checklist = (detail.measurements ?? [])
        .filter((measurement) => measurement.tolerance_min === null && measurement.tolerance_max === null)
        .map((measurement) => ({ id: measurement.id, is_pass: true, notes: 'Auto-return continuation checklist passed.' }));
      const measurements = (detail.measurements ?? [])
        .filter((measurement) => measurement.tolerance_min !== null || measurement.tolerance_max !== null)
        .map((measurement) => ({ id: measurement.id, measured_value: 10, notes: 'Within seeded specification.' }));
      completed = await at(qc, 'POST', `/quality/inspections/${row.id}/lot-result`, {
        sample_defect_count: 0,
        ...(checklist.length ? { checklist } : {}),
        ...(measurements.length ? { measurements } : {}),
        complete: true,
      });
    } else {
      const measurements = detail.measurements.map((measurement) => ({
        id: measurement.id,
        measured_value: 10,
        notes: 'Auto-return continuation measurement within the seeded specification.',
      }));
      await at(qc, 'PATCH', `/quality/inspections/${row.id}/measurements`, { measurements });
      completed = await at(qc, 'POST', `/quality/inspections/${row.id}/complete`);
    }
    const disposition = dataOf(completed);
    let final = disposition;
    if (disposition.status === 'awaiting_review') {
      await at(checker, 'PATCH', `/quality/inspections/${row.id}/review`, {
        decision: 'passed',
        remarks: `Independent QC Inspector review for auto-return ${stage} inspection.`,
      });
      final = dataOf(await at(checker, 'GET', `/quality/inspections/${row.id}`));
    }
    expect(final.status).toBe('passed');
    if (stage === 'outgoing') {
      expect(disposition.status).toBe('awaiting_review');
      expect(final.reviewer?.id).toBeTruthy();
      expect(final.inspector?.id).toBeTruthy();
      expect(final.reviewer.id).not.toBe(final.inspector.id);
      expect(final.accepted_quantity).toBe(final.batch_quantity);
      expect(final.work_order_output?.id).toBe(detail.work_order_output?.id);
    }
    reviewed.push({ id: row.id, stage, status: final.status, inspector: final.inspector?.id, reviewer: final.reviewer?.id });
  }
  passed(`QC measurement and independent review: ${stage}`, reviewed);
  return reviewed;
}

async function clickComplete(manager, workOrderId) {
  await manager.goto(`/production/work-orders/${workOrderId}`);
  await manager.getByRole('button', { name: 'Complete', exact: true }).click();
  const responsePromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${workOrderId}/complete`));
  await manager.getByRole('dialog').getByRole('button', { name: 'Complete', exact: true }).click();
  const response = await responsePromise;
  expect(response.status()).toBe(200);
}

async function closeWorkOrder(manager, workOrderId) {
  await manager.goto(`/production/work-orders/${workOrderId}`);
  await manager.getByRole('button', { name: 'Close', exact: true }).click();
  const responsePromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${workOrderId}/close`));
  await manager.getByRole('dialog').getByRole('button', { name: 'Close', exact: true }).click();
  const response = await responsePromise;
  expect(response.status()).toBe(200);
  await expect(manager.getByText('Closed', { exact: true })).toBeVisible();
}

function saveReport() {
  report.result = 'passed';
  report.finished_at = new Date().toISOString();
  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2));
  fs.writeFileSync(path.join(OUT, 'report.txt'), [
    `Material auto-return continuation ${fixture.run_id}`,
    `Database: ${fixture.database}`,
    `Checkpoints: ${report.checks.length}`,
    `Real-cookie roles: ${contexts.map((entry) => entry.email).join(', ')}`,
    `Artifacts: ${report.artifacts.length}`,
  ].join('\n') + '\n');
  console.log(`REPORT_DIR=${OUT}`);
  console.log(`REPORT_JSON=${path.join(OUT, 'report.json')}`);
  console.log(`CHECKS=${report.checks.length}`);
}

async function main() {
  expect(fixture.database).toBe('ogami_test_production_workorders_browser_mr0925d');
  browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  const manager = await login('production@ogami.test');
  const warehouse = await login('warehouse@ogami.test');
  const qc = await login('qc@ogami.test');
  const checker = await login(fixture.qc_checker_email);

  // Read-only preflight: continue from the explicitly requested saved state.
  const before = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.auto_return_work_order_id}`));
  expect(Number(before.materials[0].auto_gross_quantity_issued)).toBe(1);
  const priorReturned = Number(returnedBeforeRun);
  expect(Number(before.materials[0].auto_returned_quantity)).toBe(priorReturned);
  const source = await sourceMovement(warehouse);
  const sourceBefore = await returnOptions(warehouse, source.id);
  expect(Number(sourceBefore.returned_quantity)).toBe(priorReturned);
  if (continuationState === 'after-return') {
    expect(before.status).toBe('in_progress');
    expect(before.quantity_produced).toBe(0);
    expect(Number(before.materials[0].auto_quantity_issued)).toBe(1 - priorReturned);
    expect(Number(before.materials[0].manual_quantity_issued)).toBe(0);
    expect(sourceBefore.consumption_basis).toBe('preproduction');
    expect(Number(sourceBefore.returnable_quantity)).toBe(1 - priorReturned);
  } else {
    expect(priorReturned).toBe(0.5);
    expect(before.status).toBe('completed');
    expect(before.quantity_produced).toBe(1);
    expect(before.quantity_good).toBe(1);
    expect(Number(before.materials[0].auto_quantity_issued)).toBe(0.5);
    expect(Number(before.materials[0].manual_quantity_issued)).toBe(0.5);
    expect(Number(before.materials[0].manual_gross_quantity_issued)).toBe(0.5);
    expect(before.sales_order).toBeNull();
    expect(before.outputs).toHaveLength(1);
    expect(before.outputs[0].good_count).toBe(1);
    expect(before.outputs[0].production_receipt_handoff.status).toBe('generated');
  }
  passed(`Read-only preflight matched saved ${continuationState} auto-WO state (${priorReturned} already returned)`, {
    workOrder: fixture.auto_return_work_order_number,
    sourceMovement: source.id,
    status: before.status,
    grossIssued: before.materials[0].auto_gross_quantity_issued,
    previouslyReturned: sourceBefore.returned_quantity,
    returnable: sourceBefore.returnable_quantity ?? null,
  });

  if (continuationState === 'ready-to-close') {
    const priorReport = '/tmp/material-recovery-auto-continuation-MR0925D-resume-stepfix/report.json';
    const previous = JSON.parse(fs.readFileSync(priorReport, 'utf8'));
    expect(previous.checks.some((check) => check.name === 'QC measurement and independent review: in_process')).toBe(true);
    const inspections = dataOf(await at(qc, 'GET', `/quality/inspections?entity_type=work_order&entity_id=${encodeURIComponent(fixture.auto_return_work_order_id)}&per_page=100`));
    const inProcess = inspections.filter((row) => row.stage === 'in_process');
    const outgoing = inspections.filter((row) => row.stage === 'outgoing');
    expect(inProcess).toHaveLength(1);
    expect(inProcess[0].status).toBe('passed');
    expect(outgoing).toHaveLength(0);
    const inProcessDetail = dataOf(await at(qc, 'GET', `/quality/inspections/${inProcess[0].id}`));
    expect(inProcessDetail.measurements?.length ?? 0).toBeGreaterThan(0);
    expect(inProcessDetail.measurements.every((measurement) => measurement.is_pass === true)).toBe(true);
    passed('Saved in-process measurements and pass were verified; no outgoing inspection is due for this standalone WO', {
      inProcess: inProcess[0].id,
      inProcessStatus: inProcess[0].status,
      measurementRows: inProcessDetail.measurements.length,
      salesOrder: before.sales_order,
      outgoingInspectionCount: outgoing.length,
      basis: 'TriggerOutgoingQC only opens customer-linked or NCR replacement batches.',
      priorEvidence: priorReport,
    });

    await closeWorkOrder(manager, fixture.auto_return_work_order_id);
    const finalWorkOrder = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.auto_return_work_order_id}`));
    expect(finalWorkOrder.status).toBe('closed');
    expect(Number(finalWorkOrder.materials[0].auto_quantity_issued)).toBe(0.5);
    expect(Number(finalWorkOrder.materials[0].auto_gross_quantity_issued)).toBe(1);
    expect(Number(finalWorkOrder.materials[0].auto_returned_quantity)).toBe(0.5);
    expect(Number(finalWorkOrder.materials[0].manual_quantity_issued)).toBe(0.5);
    expect(Number(finalWorkOrder.materials[0].manual_gross_quantity_issued)).toBe(0.5);
    expect(Number(finalWorkOrder.materials[0].actual_quantity_issued)).toBe(1);
    const rawFinal = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
    const fgFinal = await stockAt(warehouse, fixture.finished_item_id, fixture.fg_location_id);
    expect(Number(rawFinal.quantity)).toBe(0);
    expect(Number(fgFinal.quantity)).toBe(8);
    const finalMovementCount = dataOf(await at(warehouse, 'GET', '/inventory/stock-movements?movement_type=material_return&per_page=200'))
      .filter((movement) => movement.reference_type === 'stock_movement' && movement.reference_id === source.id).length;
    expect(finalMovementCount).toBe(1);
    expect(pageErrors).toEqual([]);
    passed('Warehouse replacement issue, auto partial return, saved output/QC and close reconcile without replaying prior writes', {
      netAuto: finalWorkOrder.materials[0].auto_quantity_issued,
      grossAuto: finalWorkOrder.materials[0].auto_gross_quantity_issued,
      autoReturned: finalWorkOrder.materials[0].auto_returned_quantity,
      netManual: finalWorkOrder.materials[0].manual_quantity_issued,
      grossManual: finalWorkOrder.materials[0].manual_gross_quantity_issued,
      raw: rawFinal.quantity,
      finishedGoods: fgFinal.quantity,
      returnRows: finalMovementCount,
    });
    await screenshot(manager, 'auto-return-work-order-closed.png');
    saveReport();
    return;
  }

  let returned;
  if (priorReturned > 0) {
    const priorReport = '/tmp/material-recovery-auto-continuation-MR0925D-final/report.json';
    const previous = JSON.parse(fs.readFileSync(priorReport, 'utf8'));
    expect(previous.checks.some((check) => /lost success response, browser reload/.test(check.name))).toBe(true);
    passed('Using the saved 0.5 return after its lost-response/reload/idempotent replay was verified', {
      sourceMovement: source.id,
      returned: sourceBefore.returned_quantity,
      priorEvidence: priorReport,
    });
    returned = sourceBefore;
  } else {
  const dialog = await openReturn(warehouse, source.id);
  const returnReason = 'Return half the auto-issued unopened resin.';
  await dialog.getByLabel(/^Quantity to return/).fill('0.500');
  await dialog.getByLabel(/^Reason/).fill(returnReason);
  const warehouseUserId = dataOf(await at(warehouse, 'GET', '/auth/user')).id;
  const storageKey = `ogami:inventory:material-return:${warehouseUserId}`;
  let committedStatus = 0;
  let originalPayload;
  let originalKey;
  const routePattern = `**/api/v1/inventory/stock-movements/${source.id}/return-unused`;
  await warehouse.route(routePattern, async (route) => {
    originalPayload = route.request().postDataJSON();
    originalKey = route.request().headers()['idempotency-key'];
    const committed = await route.fetch();
    committedStatus = committed.status();
    await committed.dispose();
    await route.abort('failed');
  });
  const failedRequest = warehouse.waitForEvent('requestfailed', (request) => request.url().endsWith(`/inventory/stock-movements/${source.id}/return-unused`));
  await dialog.getByRole('button', { name: 'Post return', exact: true }).click();
  await failedRequest;
  expect(committedStatus).toBe(200);
  await expect(dialog.getByText(/Your request is saved with its original details/)).toBeVisible();
  const saved = await warehouse.evaluate((key) => JSON.parse(localStorage.getItem(key) ?? 'null'), storageKey);
  expect(saved).toMatchObject({ sourceId: source.id, quantity: '0.500', expectedReturned: '0.000', reason: returnReason });
  expect(saved.idempotencyKey).toBe(originalKey);
  await screenshot(warehouse, 'auto-return-unknown-response.png');
  await warehouse.unroute(routePattern);

  await warehouse.reload();
  await expect(warehouse.getByRole('status').filter({ hasText: 'awaiting confirmation' })).toBeVisible();
  const retryDialog = await openReturn(warehouse, source.id);
  await expect(retryDialog.getByLabel(/^Quantity to return/)).toBeDisabled();
  expect(await retryDialog.getByLabel(/^Quantity to return/).inputValue()).toBe('0.500');
  expect(await retryDialog.getByLabel(/^Reason/).inputValue()).toBe(returnReason);
  const retryRequestPromise = warehouse.waitForRequest((request) =>
    request.url().endsWith(`/api/v1/inventory/stock-movements/${source.id}/return-unused`) && request.method() === 'POST');
  const retryResponsePromise = warehouse.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/inventory/stock-movements/${source.id}/return-unused`) && response.request().method() === 'POST');
  await retryDialog.getByRole('button', { name: 'Retry last return', exact: true }).click();
  const replayRequest = await retryRequestPromise;
  const replayResponse = await retryResponsePromise;
  expect(replayRequest.postDataJSON()).toEqual(originalPayload);
  expect(replayRequest.headers()['idempotency-key']).toBe(originalKey);
  expect(replayResponse.status()).toBe(200);
  await expect(retryDialog).not.toBeVisible();
  expect(await warehouse.evaluate((key) => localStorage.getItem(key), storageKey)).toBeNull();
  const returnMovements = dataOf(await at(warehouse, 'GET', '/inventory/stock-movements?movement_type=material_return&per_page=200'));
  const ownReturns = returnMovements.filter((movement) => movement.reference_type === 'stock_movement' && movement.reference_id === source.id);
  expect(ownReturns).toHaveLength(1, 'Lost-response retry must replay the existing return row, not post a duplicate.');
  expect(Number(ownReturns[0].quantity)).toBe(0.5);
  returned = await returnOptions(warehouse, source.id);
  expect(Number(returned.returned_quantity)).toBe(0.5);
  expect(Number(returned.returnable_quantity)).toBe(0.5);
  passed('Auto-issued half-return survived a lost success response, browser reload and same-payload/idempotency-key replay', {
    postedStatus: committedStatus,
    replayStatus: replayResponse.status(),
    sourceMovement: source.id,
    returnRows: ownReturns.length,
    returned: returned.returned_quantity,
    returnable: returned.returnable_quantity,
  });
  }

  const blocked = await blockedOutput(manager, fixture.auto_return_work_order_id);
  const stockBeforeReissue = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  const issue = await createGeneralIssue(warehouse, fixture.auto_return_work_order_id, 0.5);
  const ready = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.auto_return_work_order_id}`));
  expect(Number(ready.materials[0].auto_quantity_issued)).toBe(0.5);
  expect(Number(ready.materials[0].auto_gross_quantity_issued)).toBe(1);
  expect(Number(ready.materials[0].auto_returned_quantity)).toBe(0.5);
  expect(Number(ready.materials[0].manual_quantity_issued)).toBe(0.5);
  expect(Number(ready.materials[0].manual_gross_quantity_issued)).toBe(0.5);
  expect(Number(ready.materials[0].actual_quantity_issued)).toBe(1);
  const output = await recordOutput(manager, fixture.auto_return_work_order_id);
  expect(output.production_receipt_handoff.status).toBe('generated');
  const receipts = dataOf(await at(warehouse, 'GET', '/inventory/stock-movements?movement_type=production_receipt&reference_type=work_order_output&per_page=200'));
  const receipt = receipts.find((movement) => movement.reference_id === output.id);
  expect(receipt).toBeTruthy();
  expect(receipt.lot_number).toBe(output.batch_code);
  await completeOperation(manager, fixture.auto_return_work_order_id);
  await clickComplete(manager, fixture.auto_return_work_order_id);
  const inProcess = await qualityReview(qc, checker, fixture.auto_return_work_order_id, 'in_process');
  let outgoing = [];
  if (before.sales_order) {
    outgoing = await qualityReview(qc, checker, fixture.auto_return_work_order_id, 'outgoing');
  } else {
    const listed = dataOf(await at(qc, 'GET', `/quality/inspections?entity_type=work_order&entity_id=${encodeURIComponent(fixture.auto_return_work_order_id)}&per_page=100`));
    outgoing = listed.filter((row) => row.stage === 'outgoing');
    expect(outgoing).toHaveLength(0);
    passed('Standalone auto-return WO has no outgoing-QC inspection obligation', {
      salesOrder: before.sales_order,
      outgoingInspectionCount: outgoing.length,
      basis: 'The fixture has no sales-order or NCR replacement link, so TriggerOutgoingQC intentionally skips it.',
    });
  }
  await closeWorkOrder(manager, fixture.auto_return_work_order_id);

  const finalWorkOrder = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.auto_return_work_order_id}`));
  expect(finalWorkOrder.status).toBe('closed');
  expect(Number(finalWorkOrder.materials[0].auto_quantity_issued)).toBe(0.5);
  expect(Number(finalWorkOrder.materials[0].auto_gross_quantity_issued)).toBe(1);
  expect(Number(finalWorkOrder.materials[0].auto_returned_quantity)).toBe(0.5);
  expect(Number(finalWorkOrder.materials[0].manual_quantity_issued)).toBe(0.5);
  expect(Number(finalWorkOrder.materials[0].manual_gross_quantity_issued)).toBe(0.5);
  expect(Number(finalWorkOrder.materials[0].actual_quantity_issued)).toBe(1);
  const rawFinal = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  const fgFinal = await stockAt(warehouse, fixture.finished_item_id, fixture.fg_location_id);
  expect(Number(rawFinal.quantity)).toBe(0);
  expect(Number(fgFinal.quantity)).toBe(8);
  const finalMovementCount = dataOf(await at(warehouse, 'GET', '/inventory/stock-movements?movement_type=material_return&per_page=200'))
    .filter((movement) => movement.reference_type === 'stock_movement' && movement.reference_id === source.id).length;
  expect(finalMovementCount).toBe(1);
  expect(pageErrors).toEqual([]);

  passed('Warehouse reissued the 0.5 shortage; auto-WO output, operation, independent QC checks, completion and close reconciled', {
    blockedOutput: blocked.message,
    manualIssue: issue.items[0].quantity_issued,
    preReissueStock: stockBeforeReissue.quantity,
    netAuto: finalWorkOrder.materials[0].auto_quantity_issued,
    grossAuto: finalWorkOrder.materials[0].auto_gross_quantity_issued,
    autoReturned: finalWorkOrder.materials[0].auto_returned_quantity,
    netManual: finalWorkOrder.materials[0].manual_quantity_issued,
    grossManual: finalWorkOrder.materials[0].manual_gross_quantity_issued,
    receiptLot: receipt.lot_number,
    inProcessInspections: inProcess.length,
    outgoingInspections: outgoing.length,
    raw: rawFinal.quantity,
    finishedGoods: fgFinal.quantity,
  });

  await screenshot(manager, 'auto-return-work-order-closed.png');
  saveReport();
}

main().catch(async (error) => {
  report.result = 'failed';
  report.error = String(error?.stack ?? error);
  report.failures.push({ message: String(error?.message ?? error), stack: String(error?.stack ?? error) });
  for (const { page, email } of contexts) {
    try {
      const screenshotPath = path.join(OUT, `failure-${email.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}.png`);
      await page.screenshot({ path: screenshotPath, fullPage: true, timeout: 10_000 });
      report.artifacts.push(screenshotPath);
    } catch {}
  }
  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2));
  console.error(error);
  console.error(`REPORT_DIR=${OUT}`);
  process.exitCode = 1;
}).finally(async () => {
  await Promise.allSettled(contexts.map(({ context }) => context.close()));
  await browser?.close();
});
