// Real-cookie Inventory acceptance runner.
// Requires api/tests/Browser/inventory_warehouse_fixture.php in the isolated
// browser DB and a temporary API/Vite proxy described by the audit report.
const { chromium, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const BASE = process.env.INVENTORY_TEST_URL || 'http://127.0.0.1:5210';
const RUN_ID = process.env.INVENTORY_TEST_RUN_ID || `run-${Date.now()}-${process.pid}`;
const OUT = process.env.INVENTORY_TEST_OUTPUT || path.join('/tmp', `ogami-inventory-warehouse-${RUN_ID}`);
const fixture = JSON.parse(fs.readFileSync(
  process.env.INVENTORY_TEST_FIXTURE || '/tmp/inventory-warehouse-browser-fixture.json',
  'utf8',
));
fs.mkdirSync(OUT, { recursive: true });
const report = { database: fixture.database, checks: [], findings: [], artifacts: [] };
const errors = [];
const pages = [];
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
  const wo = warehouse.getByLabel('Work order', { exact: true });
  await expect(wo.locator('option', { hasText: 'WO-AUD-INV-0925' })).toHaveCount(1);
  await expect(warehouse.locator('form select').nth(1).locator(`option[value="${fixture.reserved_item}"]`)).toHaveCount(1);
  await wo.selectOption(fixture.work_order);
  const line = warehouse.locator('form').locator('select').nth(1);
  await line.selectOption(fixture.reserved_item);
  const sourceSelect = warehouse.locator('form').locator('select').nth(2);
  await expect(sourceSelect.locator(`option[value="${fixture.reservation_location}"]`)).toContainText('20.000 reserved');
  await sourceSelect.selectOption(fixture.reservation_location);
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
  await warehouse.locator('form select').nth(1).selectOption(fixture.reserved_item);
  await warehouse.locator('form select').nth(2).selectOption(fixture.reservation_location);
  await warehouse.getByLabel('Issue from').selectOption('available');
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
  const transferItem = transferForm.locator('select').nth(0);
  await transferItem.selectOption(fixture.reserved_item);
  const transferSource = transferForm.locator('select').nth(1);
  await expect(transferSource.locator(`option[value="${fixture.reservation_location}"]`)).toHaveCount(0);
  await transferSource.selectOption(fixture.available_location);
  const transferDestination = transferForm.locator('select').nth(2);
  await expect(transferDestination.locator(`option[value="${fixture.quarantine_location}"]`)).toHaveCount(1);
  await expect(transferDestination.locator(`option[value="${fixture.scrap_location}"]`)).toHaveCount(1);
  await expect(transferDestination.locator(`option[value="${fixture.blocked_location}"]`)).toHaveCount(0);
  await transferDestination.selectOption(fixture.transfer_location);
  await transferForm.locator('input[type="number"]').fill('2');
  await modal.getByRole('button', { name: 'Create transfer order', exact: true }).click();
  await expect(modal).toBeHidden();
  await expect(warehouse.getByRole('button', { name: 'Execute', exact: true })).toBeVisible();
  const transferList = await api(warehouse, 'GET', '/inventory/transfer-orders');
  expect(transferList.status).toBe(200);
  const transfer = dataOf(transferList).find((candidate) => candidate.from_location?.id === fixture.available_location && candidate.to_location?.id === fixture.transfer_location);
  expect(transfer, JSON.stringify(transferList.body)).toBeTruthy();
  expect(transfer.status).toBe('pending');
  await warehouse.getByRole('button', { name: 'Execute', exact: true }).click();
  await warehouse.getByRole('dialog').getByRole('button', { name: 'Execute', exact: true }).click();
  await expect.poll(async () => (await api(warehouse, 'GET', `/inventory/transfer-orders/${transfer.id}`)).body.data?.status).toBe('transferred');
  const afterTransferSource = await stockAt(warehouse, fixture.reserved_item, fixture.available_location);
  const afterTransferDestination = await stockAt(warehouse, fixture.reserved_item, fixture.transfer_location);
  expect(afterTransferSource).toMatchObject({ quantity: '4.000', reserved_quantity: '0.000', available: '4.000' });
  expect(afterTransferDestination).toMatchObject({ quantity: '2.000', reserved_quantity: '0.000', available: '2.000' });
  pass('Live transfer order creates pending, then executes with scoped source and valid destination options', {
    sourceOpening: openingFree.quantity,
    sourceClosing: afterTransferSource.quantity,
    destinationOpening: '0.000',
    destinationClosing: afterTransferDestination.quantity,
    reservedSourceExcluded: true,
    blockedDestinationExcluded: true,
    quarantineAndScrapDestinationsPresent: true,
    status: 'pending -> transferred',
  });
  const transferScreenshot = path.join(OUT, 'success-transfer-order.png');
  await warehouse.screenshot({ path: transferScreenshot, fullPage: true });
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
  await warehouse.getByLabel(`Qty ${itemCode}`).fill('1');
  await warehouse.getByLabel('Received UOM', { exact: true }).fill('BAG');

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
  await warehouse.getByLabel('Received UOM', { exact: true }).fill('BOX');
  const validationResponsePromise = warehouse.waitForResponse((response) =>
    response.url().includes(`/inventory/grn/${fixture.grn}/finalize`) && response.request().method() === 'PATCH');
  await warehouse.getByRole('button', { name: 'Finalize receiving', exact: true }).click();
  await warehouse.getByRole('dialog').getByRole('button', { name: 'Finalize GRN', exact: true }).click();
  const validationResponse = await validationResponsePromise;
  expect(validationResponse.status()).toBe(422);
  await expect(warehouse.getByLabel('Received UOM', { exact: true })).toHaveValue('BOX');
  await expect(warehouse.getByLabel(`Qty ${itemCode}`)).toHaveValue('1');
  await expect(warehouse.getByRole('button', { name: 'Finalize receiving', exact: true })).toBeVisible();
  pass('GRN validation failure preserves entered quantity/UOM and allows correction', {
    invalidUom: 'BOX',
    response: validationResponse.status(),
    valuesPreserved: { quantity: '1', uom: 'BOX' },
    correctedUom: 'BAG',
  });

  await warehouse.getByLabel('Received UOM', { exact: true }).fill('BAG');
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
    receivedPurchaseUom: '1 BAG',
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
  const record = await api(qc, 'POST', `/quality/inspections/${inspection.id}/lot-result`, {
    checklist: inspection.measurements.filter((row) => row.tolerance_min === null && row.tolerance_max === null).map((row) => ({ id: row.id, is_pass: true })),
    measurements: inspection.measurements.filter((row) => row.tolerance_min !== null || row.tolerance_max !== null).map((row) => ({ id: row.id, measured_value: row.target_value ?? row.tolerance_min ?? '0' })),
    sample_defect_count: 0,
    complete: true,
  });
  expect(record.status, JSON.stringify(record.body)).toBe(200);
  expect(dataOf(record).status).toBe('awaiting_review');
  const selfReview = await api(qc, 'PATCH', `/quality/inspections/${inspection.id}/review`, { decision: 'passed', remarks: 'Self review should be denied.' });
  expect([403, 422]).toContain(selfReview.status);

  const productionApprover = await login(browser, 'production@ogami.test');
  const independentReview = await api(productionApprover, 'PATCH', `/quality/inspections/${inspection.id}/review`, { decision: 'passed', remarks: 'Independent incoming QC approval.' });
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
  const completed = await api(checker, 'POST', `/inventory/stock-counts/${sessionId}/complete`);
  expect(completed.status, JSON.stringify(completed.body)).toBe(200);
  expect(dataOf(completed).status).toBe('completed');
  const sourceAfter = await stockAt(checker, fixture.reserved_item, fixture.available_location);
  expect(sourceAfter).toMatchObject({ quantity: '3.000', reserved_quantity: '0.000', available: '3.000' });
  const card = await api(checker, 'GET', `/inventory/items/${fixture.reserved_item}/stock-card?location_id=${fixture.available_location}`);
  expect(card.status, JSON.stringify(card.body)).toBe(200);
  expect(dataOf(card).closing.balance).toBe('3.000');
  expect(dataOf(card).closing.value).toBe('37.50');
  const movements = await api(checker, 'GET', `/inventory/stock-movements?item_id=${fixture.reserved_item}&per_page=100`);
  expect(movements.status).toBe(200);
  const adjustmentMovement = dataOf(movements).find((movement) =>
    movement.movement_type === 'adjustment_out'
    && movement.reference_type === 'stock_count_session'
    && movement.from_location?.id === fixture.available_location
    && movement.quantity === '1.000');
  expect(adjustmentMovement).toBeTruthy();
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
  pass('Warehouse stock count freezes a zone; separate Warehouse checker approves variance; Finance approves gated adjustment', {
    maker: 'warehouse@ogami.test',
    checker: 'warehouse-checker@ogami.test',
    lines: countedValues,
    freeze: { attemptedTransfer: transferDuringCount.status, executeDuringCount: frozenExecute.status, unchangedQuantity: '4.000', cancelled: cancelFrozenTransfer.status },
    variance: { system: target.system_quantity, counted: '3.000', delta: '-1.000', approvalRequired: true },
    makerSelfApprovalStatus: selfApproval.status,
    independentApprovalStatus: approved.status,
    sessionStatus: dataOf(completed).status,
    opening: { quantity: sourceBefore.quantity, value: '50.00' },
    afterCountApproval: { quantity: sourceAfter.quantity, value: dataOf(card).closing.value, weightedAverageCost: dataOf(card).closing.weighted_avg },
    adjustment: { statusBeforeFinance: dataOf(pendingAdjustment).status, warehouseApprovalStatus: warehouseDeniedApproval.status, financeStatus: dataOf(financeApproval).status },
    closing: { quantity: afterFinanceApproval.quantity, value: dataOf(afterFinanceCard).closing.value, weightedAverageCost: dataOf(afterFinanceCard).closing.weighted_avg },
    stockCardMovementCount: dataOf(card).rows.length,
    stockCountVarianceMovement: { id: adjustmentMovement.id, type: adjustmentMovement.movement_type, quantity: adjustmentMovement.quantity, referenceType: adjustmentMovement.reference_type },
  });
  await checker.goto('/inventory/stock-count');
  await checker.getByRole('button').filter({ hasText: 'Inventory audit cycle count' }).click();
  await expect(checker.getByText('Completed', { exact: true })).toBeVisible();
  const screenshot = path.join(OUT, 'success-stock-count-approved.png');
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

async function runMobile(browser) {
  const warehouse = await login(browser, 'warehouse@ogami.test', { width: 390, height: 844 });
  await warehouse.goto(`/inventory/material-issues/create?work_order_id=${fixture.work_order}`);
  const issueSelects = warehouse.locator('form select');
  await issueSelects.nth(0).selectOption(fixture.work_order);
  await issueSelects.nth(1).selectOption(fixture.reserved_item);
  await expect(issueSelects.nth(2).locator(`option[value="${fixture.reservation_location}"]`)).toHaveCount(1);
  await issueSelects.nth(2).selectOption(fixture.reservation_location);
  await assertMobileControls(warehouse, 'Material issue form');
  const issueScreenshot = path.join(OUT, 'mobile-material-issue.png');
  await warehouse.screenshot({ path: issueScreenshot, fullPage: true });
  report.artifacts.push(issueScreenshot);

  await warehouse.goto('/inventory/transfer-orders');
  await warehouse.getByRole('button', { name: 'New transfer' }).first().click();
  const modal = warehouse.getByRole('dialog');
  const selects = modal.locator('form select');
  await selects.nth(0).selectOption(fixture.reserved_item);
  await expect(selects.nth(1).locator(`option[value="${fixture.available_location}"]`)).toHaveCount(1);
  await selects.nth(1).selectOption(fixture.available_location);
  await selects.nth(2).selectOption(fixture.transfer_location);
  await modal.locator('input[type="number"]').fill('1');
  await assertMobileControls(warehouse, 'Transfer form');
  const transferScreenshot = path.join(OUT, 'mobile-transfer.png');
  await warehouse.screenshot({ path: transferScreenshot, fullPage: true });
  report.artifacts.push(transferScreenshot);
}

(async () => {
  const browser = await chromium.launch({ headless: true, args: ['--disable-dev-shm-usage', '--no-sandbox'] });
  try {
    const phase = process.env.INVENTORY_TEST_PHASE || 'issue-transfer';
    if (phase === 'baseline') await runBaseline(browser);
    else if (phase === 'issue-transfer') { await runIssueTransfer(browser); await runTransfer(browser); }
    else if (phase === 'transfer') await runTransfer(browser);
    else if (phase === 'grn-qc') await runGrnQc(browser);
    else if (phase === 'stock-count') await runStockCount(browser);
    else if (phase === 'mobile') await runMobile(browser);
    else throw new Error(`Unknown INVENTORY_TEST_PHASE: ${phase}`);
    report.browserErrors = errors;
    expect(errors, 'No uncaught browser errors').toEqual([]);
    const reportPath = path.join(OUT, 'report.json');
    fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));
    console.log(`REPORT: ${reportPath}`);
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
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
