import { test, expect, type BrowserContext, type Page, type TestInfo } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { localIsoDate } from '../src/lib/formatDate';
import { assignCrewThroughUi, baseUrl, loginAs, statefulHeaders } from './helpers';

// Real backend, real role permissions, isolated tagged fixtures. No network mocks.
const fixtureTag = process.env.E2E_QC_TAG;
if (!fixtureTag || !/^[a-z0-9-]{1,6}$/.test(fixtureTag)) throw new Error('Set E2E_QC_TAG to 1–6 lowercase letters, digits, or hyphens.');
const fixturePath = process.env.E2E_QC_MANIFEST
  ?? `../api/storage/app/work-order-qc-e2e-${fixtureTag}.json`;
const fixture = JSON.parse(readFileSync(resolve(fixturePath), 'utf8'));
if (fixture.tag !== fixtureTag) throw new Error(`Expected QC fixture ${fixtureTag}; loaded ${fixture.tag ?? 'untagged fixture'}.`);
const pages: Record<string, Page> = {};
const contexts: Record<string, BrowserContext> = {};
const errors: string[] = [];
const requirements = ['Surface condition', 'Color', 'Packaging'];

async function api(page: Page, method: string, path: string, data?: unknown, extraHeaders: Record<string, string> = {}) {
  const xsrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
  const response = await page.request.fetch(`/api/v1${path}`, {
    method, data,
    headers: { ...statefulHeaders(), ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value) } : {}), ...extraHeaders },
  });
  return { status: response.status(), body: await response.json() };
}

async function success(page: Page, method: string, path: string, data?: unknown) {
  const response = await api(page, method, path, data);
  expect(response.status, JSON.stringify(response.body)).toBeLessThan(300);
  expect(response.status).toBeGreaterThanOrEqual(200);
  return response.body.data;
}

async function recordLotCoverage(page: Page, inspection: { inspection_mode: string; sample_size: number }, defectCount = 0) {
  if (inspection.inspection_mode !== 'lot_checklist') return;
  await page.getByRole('spinbutton', { name: 'Inspected pieces' }).fill(String(inspection.sample_size));
  await page.getByRole('spinbutton', { name: 'Defective pieces found' }).fill(String(defectCount));
  await page.getByRole('checkbox', { name: 'I confirm all required checks on the planned sample were completed' }).check();
}

async function auditTrail(modelType: string, id: string) {
  return success(pages.audit, 'GET', `/admin/audit-logs/entity?model_type=${modelType}&model_id=${id}&per_page=100`);
}

async function clickMutation(page: Page, button: string, endpoint: string, dialog = false) {
  if (dialog) await page.getByRole('button', { name: button, exact: true }).click();
  const response = page.waitForResponse((res) => res.url().includes(endpoint) && res.request().method() !== 'GET', { timeout: 20_000 });
  await (dialog ? page.getByRole('dialog') : page).getByRole('button', { name: button, exact: true }).click();
  const result = await response;
  expect(result.ok(), await result.text()).toBe(true);
  return (await result.json()).data;
}

async function passInspection(id: string) {
  const maker = pages.qc;
  await maker.goto(`/quality/inspections/${id}`, { waitUntil: 'domcontentloaded' });
  const inspection = await success(maker, 'GET', `/quality/inspections/${id}`);
  const numericMeasurements = maker.locator('input[type="number"][step="0.0001"]');
  const tolerancedRows = inspection.measurements.filter((row: {
    tolerance_min: number | null;
    tolerance_max: number | null;
  }) => row.tolerance_min !== null || row.tolerance_max !== null);
  await expect(numericMeasurements).toHaveCount(tolerancedRows.length);
  for (let index = 0; index < tolerancedRows.length; index += 1) {
    const row = tolerancedRows[index];
    const measured = row.nominal_value ?? row.tolerance_min ?? row.tolerance_max;
    expect(measured).not.toBeNull();
    await numericMeasurements.nth(index).fill(String(measured));
  }
  await recordLotCoverage(maker, inspection);
  for (const name of requirements) await maker.getByRole('button', { name: `PASS ${name}`, exact: true }).click();
  const submitLabel = inspection.inspection_mode === 'lot_checklist' ? 'Submit result' : 'Submit inspection';
  const submitEndpoint = inspection.inspection_mode === 'lot_checklist' ? '/batch-result' : '/item-results';
  await clickMutation(maker, submitLabel, submitEndpoint);
  const submitted = await success(maker, 'GET', `/quality/inspections/${id}`);
  if (submitted.status === 'awaiting_review') {
    const reviewer = submitted.inspector?.id === fixture.checker ? pages.production : pages.checker;
    await reviewer.goto(`/quality/inspections/${id}`, { waitUntil: 'domcontentloaded' });
    await clickMutation(reviewer, 'Approve pass', '/review', true);
  }
  expect((await success(maker, 'GET', `/quality/inspections/${id}`)).status).toBe('passed');
}

test.beforeAll(async ({ browser }) => {
  for (const [role, email] of Object.entries({
    qc: 'qc@ogami.test', checker: fixture.checker_email, production: 'production@ogami.test',
    crewWorker: fixture.worker_email,
    warehouse: 'warehouse@ogami.test', employee: 'employee@ogami.test', finance: 'finance@ogami.test',
    audit: 'admin@ogami.test',
  })) {
    const context = await browser.newContext({ baseURL: baseUrl() });
    contexts[role] = context;
    const page = await context.newPage();
    page.setDefaultTimeout(30_000);
    pages[role] = page;
    page.on('console', (message) => { if (message.type() === 'error') errors.push(`${role}: console.error ${message.text()}`); });
    page.on('pageerror', (error) => errors.push(`${role}: ${error.message}`));
    page.on('requestfailed', (request) => {
      if (request.url().includes('/api/')) errors.push(`${role}: API request failed ${request.method()} ${request.url()}: ${request.failure()?.errorText ?? 'unknown error'}`);
    });
    page.on('response', (res) => { if (res.url().includes('/api/') && res.status() >= 500) errors.push(`${role}: ${res.status()} ${res.url()}`); });
    await loginAs(page, email, 'password');
  }
});

test.afterEach(async ({ browser }, info: TestInfo) => {
  const openContexts = browser.contexts();
  for (const context of Object.values(contexts)) expect(openContexts).toContain(context);
  for (const [role, page] of Object.entries(pages)) {
    await page.screenshot({ path: info.outputPath(`${role}.png`), fullPage: true });
  }
});

test.afterAll(async () => {
  const capturedErrors = [...new Set(errors)];
  for (const context of Object.values(contexts)) await context.close();
  expect(capturedErrors, `Unexpected browser/API failures:\n${capturedErrors.join('\n')}`).toEqual([]);
});

test('five QC stages use three PASS/FAIL requirements for 100 pieces; failure requires explanation and independent review', async () => {
  const qc = pages.qc;
  for (const [stage, id] of Object.entries(fixture.stages)) {
    await test.step(stage, async () => {
      await qc.goto(`/quality/inspections/${id}`, { waitUntil: 'domcontentloaded' });
      const inspection = await success(qc, 'GET', `/quality/inspections/${id}`);
      await expect(qc.getByRole('heading', { name: 'Inspection checklist', exact: true })).toBeVisible();
      await expect(qc.getByRole('button', { name: /^PASS (Surface condition|Color|Packaging)$/ })).toHaveCount(3);
      await expect(qc.getByRole('button', { name: /^FAIL (Surface condition|Color|Packaging)$/ })).toHaveCount(3);
      const lotChecklist = inspection.inspection_mode === 'lot_checklist';
      await expect(qc.getByRole('spinbutton', { name: 'Inspected pieces' })).toHaveCount(lotChecklist ? 1 : 0);
      await expect(qc.getByRole('spinbutton', { name: 'Defective pieces found' })).toHaveCount(lotChecklist ? 1 : 0);
      await expect(qc.locator('input[type="number"][step="0.0001"]')).toHaveCount(0);
      const submitLabel = inspection.inspection_mode === 'lot_checklist' ? 'Submit result' : 'Submit inspection';
      await expect(qc.getByRole('button', { name: submitLabel, exact: true })).toBeDisabled();
    });
  }
  await qc.goto(`/quality/inspections/${fixture.stages.outgoing}`, { waitUntil: 'domcontentloaded' });
  await qc.getByRole('button', { name: 'FAIL Surface condition', exact: true }).click();
  await qc.getByRole('button', { name: 'PASS Color', exact: true }).click();
  await qc.getByRole('button', { name: 'PASS Packaging', exact: true }).click();
  await expect(qc.getByRole('button', { name: 'Submit result', exact: true })).toBeDisabled();
  await qc.getByRole('textbox', { name: 'Problem found for Surface condition' }).fill('Crack found in sampled piece.');
  await recordLotCoverage(qc, await success(qc, 'GET', `/quality/inspections/${fixture.stages.outgoing}`), 1);
  await clickMutation(qc, 'Submit result', '/batch-result');
  expect((await success(qc, 'GET', `/quality/inspections/${fixture.stages.outgoing}`)).status).toBe('awaiting_review');
  const ownReview = await api(qc, 'PATCH', `/quality/inspections/${fixture.stages.outgoing}/review`, { decision: 'failed', remarks: 'Own review must be refused.' });
  expect(ownReview.status, JSON.stringify(ownReview.body)).toBe(403);
  await pages.production.goto(`/quality/inspections/${fixture.stages.outgoing}`, { waitUntil: 'domcontentloaded' });
  await pages.production.getByRole('button', { name: 'Confirm failure', exact: true }).click();
  await pages.production.getByRole('dialog').getByRole('textbox', { name: 'Reason' }).fill('Independent checker confirms crack.');
  const failureResponse = pages.production.waitForResponse((res) => res.url().includes('/review') && res.request().method() === 'PATCH');
  await pages.production.getByRole('dialog').getByRole('button', { name: 'Confirm failure', exact: true }).click();
  expect((await failureResponse).ok()).toBe(true);
  const failed = await success(qc, 'GET', `/quality/inspections/${fixture.stages.outgoing}`);
  expect(failed.status).toBe('failed');
  expect(failed.accepted_quantity).toBe(0);
  await expect.poll(async () => (await success(qc, 'GET', `/quality/ncrs?inspection_id=${fixture.stages.outgoing}`)).length).toBe(1);
});

test('role boundaries protect QC and production mutations', async () => {
  for (const role of ['warehouse', 'employee']) {
    expect((await api(pages[role], 'GET', `/quality/inspections/${fixture.closed_inspection}`)).status).toBe(403);
    expect((await api(pages[role], 'POST', `/quality/inspections/${fixture.stages.incoming}/item-results`, { items: [] })).status).toBe(403);
    await pages[role].goto(`/quality/inspections/${fixture.closed_inspection}`, { waitUntil: 'domcontentloaded' });
    await expect(pages[role].getByRole('button', { name: 'Report defect', exact: true })).toHaveCount(0);
  }
  await pages.production.goto(`/quality/inspections/${fixture.stages.incoming}`, { waitUntil: 'domcontentloaded' });
  await expect(pages.production.getByRole('button', { name: /^PASS Surface condition$/ })).toHaveCount(0);
  expect((await api(pages.production, 'POST', `/quality/inspections/${fixture.stages.incoming}/item-results`, { items: [] })).status).toBe(403);
  expect((await api(pages.production, 'POST', '/quality/ncrs', {})).status).toBe(403);
  expect((await api(pages.qc, 'POST', `/production/work-orders/${fixture.closed_wo}/outputs`, { good_count: 1, reject_count: 0 })).status).toBe(403);
  expect((await api(pages.employee, 'POST', `/production/work-orders/${fixture.closed_wo}/close`)).status).toBe(403);
});

test('late defect on closed WO holds stock, releases 99 sorted pieces, repairs one, and closes NCR after new QC', async () => {
  const qc = pages.qc;
  const checker = pages.checker;
  const production = pages.production;
  expect(await success(qc, 'GET', `/quality/inspections/${fixture.closed_process_inspection}`)).toMatchObject({
    stage: 'in_process', status: 'passed', accepted_quantity: 100,
  });
  await qc.goto(`/quality/inspections/${fixture.closed_inspection}`, { waitUntil: 'domcontentloaded' });
  await qc.getByRole('button', { name: 'Report defect', exact: true }).click();
  await expect(qc).toHaveURL(new RegExp(`inspection_id=${fixture.closed_inspection}`));
  await qc.getByLabel('Severity').selectOption('high');
  await qc.getByLabel('Affected quantity').fill('1');
  await qc.getByLabel('Defect description').fill(`E2E ${fixture.tag}: one cracked piece discovered after WO closure.`);
  const ncr = await clickMutation(qc, 'Open NCR', '/quality/ncrs');
  expect((await success(production, 'GET', `/production/work-orders/${fixture.closed_wo}`)).status).toBe('closed');
  expect((await success(qc, 'GET', `/quality/inspections/${fixture.closed_inspection}`)).accepted_quantity).toBe(0);
  for (const lot_number of [fixture.lot]) {
    const held = await api(pages.warehouse, 'POST', '/inventory/material-issues', {
      issued_date: localIsoDate(), reference_text: `E2E ${fixture.tag} blocked issue`,
      items: [{ item_id: fixture.item, location_id: fixture.location, quantity_issued: '1', lot_number }],
    });
    expect(held.status, JSON.stringify(held.body)).toBe(422);
    expect(JSON.stringify(held.body)).toMatch(/QC|quality/i);
  }
  const sorting = {
    disposition: 'rework', root_cause: 'Mold surface damage.', corrective_action: 'Repair one segregated piece.',
    mrb_accepted_quantity: '99', sorting_evidence: {
      inspected_quantity: '100', evidence_reference: `E2E-SORT-${fixture.tag}`, performed_by: fixture.maker, recovery_passed: true,
    },
  };
  expect((await api(qc, 'PATCH', `/quality/ncrs/${ncr.id}/disposition`, sorting)).status).toBe(422);
  await checker.goto(`/quality/ncrs/${ncr.id}`, { waitUntil: 'domcontentloaded' });
  await checker.getByLabel('Disposition').selectOption('rework');
  await checker.getByLabel('Verified good quantity after sorting').fill('99');
  await checker.getByLabel('Quantity inspected after full-batch sorting').fill('100');
  await checker.getByLabel('Sorting / reinspection evidence reference').fill(`E2E-SORT-${fixture.tag}`);
  await checker.getByLabel('Sorting and reinspection performed by').selectOption(fixture.maker);
  await checker.getByLabel('Remaining good pieces were segregated, reinspected and passed all requirements.').check();
  await checker.getByLabel('Root cause').fill('Mold surface damage.');
  await checker.getByLabel('Corrective action').fill('Repair one segregated piece.');
  await clickMutation(checker, 'Save disposition', '/disposition');
  const sorted = await success(checker, 'GET', `/quality/ncrs/${ncr.id}`);
  const repair = sorted.rework_work_order;
  expect(repair.quantity_target).toBe(1);
  expect((await success(qc, 'GET', `/quality/inspections/${fixture.closed_inspection}`)).accepted_quantity).toBe(99);
  expect((await api(production, 'POST', `/production/work-orders/${repair.id}/cancel`)).status).toBe(422);
  for (const type of ['corrective', 'preventive']) {
    await checker.getByLabel('Type').selectOption(type);
    await checker.getByLabel('Description').fill(`E2E ${type}: ${type === 'corrective' ? 'Repair the cracked piece' : 'Inspect mold before each batch'}.`);
    await clickMutation(checker, 'Add action', '/actions');
  }
  expect((await api(checker, 'POST', `/quality/ncrs/${ncr.id}/close`)).status).toBe(422);
  await production.goto(`/production/work-orders/${repair.id}`, { waitUntil: 'domcontentloaded' });
  await production.getByRole('button', { name: 'Confirm', exact: true }).click();
  const assignment = production.getByRole('dialog');
  await assignment.getByLabel('Number of workers').selectOption('1');
  const assignmentResponse = production.waitForResponse((response) =>
    response.url().includes(`/production/work-orders/${repair.id}/assignment-apply`) && response.request().method() === 'POST',
  );
  await assignment.getByRole('button', { name: 'Apply assignment and confirm', exact: true }).click();
  expect((await assignmentResponse).status()).toBe(200);
  const confirmedRepair = await success(production, 'GET', `/production/work-orders/${repair.id}`);
  expect(confirmedRepair.status).toBe('confirmed');
  expect(confirmedRepair.machine.id).toBe(fixture.machine);
  expect(confirmedRepair.mold.id).toBe(fixture.mold);
  const repairCrew = await success(production, 'GET', `/production/work-orders/${repair.id}/crew`);
  expect(repairCrew.current.members).toHaveLength(1);
  expect(repairCrew.schedule).toMatchObject({ status: 'confirmed' });
  await clickMutation(production, 'Start', '/start', true);
  const startedRepair = await success(production, 'GET', `/production/work-orders/${repair.id}`);
  expect(startedRepair.status).toBe('in_progress');
  expect((await success(pages.audit, 'GET', `/mrp/machines/${fixture.machine}`)).status).toBe('running');
  expect((await success(pages.audit, 'GET', `/mrp/molds/${fixture.mold}`)).status).toBe('in_use');
  const repairInspections = await success(qc, 'GET', `/quality/inspections?entity_type=work_order&entity_id=${repair.id}&per_page=100`);
  const processQc = repairInspections.find((inspection: { stage: string }) => inspection.stage === 'in_process');
  expect(processQc, 'Starting the repair work order must create its in-process QC before output.').toBeTruthy();
  await passInspection(processQc.id);
  await production.goto(`/production/work-orders/${repair.id}`, { waitUntil: 'domcontentloaded' });
  expect((await success(production, 'GET', `/production/work-orders/${repair.id}`)).process_quality_blocker).toBeNull();
  await production.getByRole('button', { name: 'Record output', exact: true }).click();
  await production.getByLabel('Good count').fill('1');
  await production.getByLabel('Recording on behalf reason').fill('Production manager records the repaired piece for the designated recorder.');
  await production.getByLabel('Remarks').fill('Repaired original held piece.');
  const output = await clickMutation(production, 'Record', '/outputs');
  await production.goto(`/production/work-orders/${repair.id}`, { waitUntil: 'domcontentloaded' });
  await clickMutation(production, 'Complete', '/complete', true);
  await expect.poll(async () => {
    const rows = await success(qc, 'GET', `/quality/inspections?entity_type=work_order&entity_id=${repair.id}&per_page=100`);
    return rows.some((inspection: { stage: string }) => inspection.stage === 'outgoing');
  }, { timeout: 30_000 }).toBe(true);
  const inspections = await success(qc, 'GET', `/quality/inspections?entity_type=work_order&entity_id=${repair.id}&per_page=100`);
  const outgoing = inspections.find((inspection: { stage: string }) => inspection.stage === 'outgoing');
  expect(outgoing, `Missing repair QC for output ${output.id}`).toBeTruthy();
  expect((await api(checker, 'POST', `/quality/ncrs/${ncr.id}/close`)).status).toBe(422);
  for (const inspection of inspections) {
    if (inspection.status === 'passed') continue;
    expect(['draft', 'in_progress', 'awaiting_review']).toContain(inspection.status);
    await passInspection(inspection.id);
  }
  await checker.goto(`/quality/ncrs/${ncr.id}`, { waitUntil: 'domcontentloaded' });
  await clickMutation(checker, 'Close NCR', `/quality/ncrs/${ncr.id}/close`, true);
  expect((await success(checker, 'GET', `/quality/ncrs/${ncr.id}`)).status).toBe('closed');
  await production.goto(`/production/work-orders/${repair.id}`, { waitUntil: 'domcontentloaded' });
  await expect.poll(async () => (await success(production, 'GET', `/production/work-orders/${repair.id}`)).status, { timeout: 60_000 }).toBe('closed');
  expect((await success(production, 'GET', `/production/work-orders/${fixture.closed_wo}`)).status).toBe('closed');
  const levels = await success(pages.warehouse, 'GET', `/inventory/stock-levels?item_id=${fixture.item}&per_page=200`);
  expect(levels.reduce((sum: number, level: { quantity: string }) => sum + Number(level.quantity), 0)).toBe(200);
});

test('open WO repairs inside same order, notifies production and automatically closes after fresh QC and NCR resolution', async () => {
  const qc = pages.qc;
  const checker = pages.checker;
  const production = pages.production;
  expect(await success(qc, 'GET', `/quality/inspections/${fixture.inline_process_inspection}`)).toMatchObject({
    stage: 'in_process', status: 'passed', accepted_quantity: 100,
  });
  const qcActor = await success(qc, 'GET', '/auth/user');
  const checkerActor = await success(checker, 'GET', '/auth/user');
  await qc.goto(`/quality/inspections/${fixture.inline_inspection}`, { waitUntil: 'domcontentloaded' });
  await qc.getByRole('button', { name: 'Report defect', exact: true }).click();
  await qc.getByLabel('Severity').selectOption('high');
  await qc.getByLabel('Affected quantity').fill('1');
  await qc.getByLabel('Defect description').fill(`E2E ${fixture.tag}: repair one original piece inside same WO.`);
  const ncr = await clickMutation(qc, 'Open NCR', '/quality/ncrs');
  expect(await auditTrail('NonConformanceReport', ncr.id)).toEqual(expect.arrayContaining([
    expect.objectContaining({
      action: 'created', model_type: 'NonConformanceReport', model_id: ncr.id,
      user: expect.objectContaining({ id: qcActor.id, role: expect.objectContaining({ slug: 'qc_inspector' }) }),
      created_at: expect.any(String),
    }),
  ]));
  await checker.goto(`/quality/ncrs/${ncr.id}`, { waitUntil: 'domcontentloaded' });
  await checker.getByLabel('Disposition').selectOption('rework');
  await checker.getByLabel('Verified good quantity after sorting').fill('99');
  await checker.getByLabel('Quantity inspected after full-batch sorting').fill('100');
  await checker.getByLabel('Sorting / reinspection evidence reference').fill(`E2E-INLINE-${fixture.tag}`);
  await checker.getByLabel('Sorting and reinspection performed by').selectOption(fixture.maker);
  await checker.getByLabel('Remaining good pieces were segregated, reinspected and passed all requirements.').check();
  await checker.getByLabel('Root cause').fill('Surface damage.');
  await checker.getByLabel('Corrective action').fill('Polish segregated piece.');
  await clickMutation(checker, 'Save disposition', '/disposition');
  const sorted = await success(checker, 'GET', `/quality/ncrs/${ncr.id}`);
  expect(sorted.is_inline_rework).toBe(true);
  expect(sorted.rework_work_order.id).toBe(fixture.inline_wo);
  expect(sorted.confirmed_rework_quantity).toBe(1);
  expect(await auditTrail('NonConformanceReport', ncr.id)).toEqual(expect.arrayContaining([
    expect.objectContaining({
      action: 'updated', model_id: ncr.id,
      user: expect.objectContaining({ id: checkerActor.id, role: expect.objectContaining({ slug: 'qc_inspector' }) }),
      new_values: expect.objectContaining({ disposition: 'rework' }),
      created_at: expect.any(String),
    }),
  ]));
  const sortedWorkOrder = await success(production, 'GET', `/production/work-orders/${fixture.inline_wo}`);
  expect(sortedWorkOrder).toMatchObject({ status: 'completed', quantity_produced: 100, quantity_good: 100 });
  const reworkPath = `/production/work-orders/${fixture.inline_wo}/rework/${ncr.id}`;
  for (const role of ['qc', 'warehouse', 'employee']) {
    expect((await api(pages[role], 'POST', `${reworkPath}/start`)).status).toBe(403);
    expect((await api(pages[role], 'POST', `${reworkPath}/outputs`, { good_count: 1, reject_count: 0 })).status).toBe(403);
    expect((await api(pages[role], 'POST', `${reworkPath}/finish`)).status).toBe(403);
  }
  const wrongOrder = await api(production, 'POST', `/production/work-orders/${fixture.closed_wo}/rework/${ncr.id}/start`);
  expect(wrongOrder.status).toBe(422);
  expect(JSON.stringify(wrongOrder.body)).toMatch(/NCR|repair|work order/i);
  for (const action of ['outputs', 'finish']) {
    const response = await api(production, 'POST', `${reworkPath}/${action}`, action === 'outputs' ? { good_count: 1, reject_count: 0 } : undefined);
    expect(response.status).toBe(422);
  }
  expect((await success(production, 'GET', `/production/work-orders/${fixture.inline_wo}`)).rework).toContainEqual(expect.objectContaining({
    id: ncr.id, started_at: null, finished_at: null, recorded_quantity: 0, remaining_quantity: 1,
  }));
  const productionActor = await success(production, 'GET', '/auth/user');
  await assignCrewThroughUi(production, fixture.inline_wo, [pages.crewWorker], {
    reworkNcrId: ncr.id,
  });
  await production.goto(`/production/work-orders/${fixture.inline_wo}`, { waitUntil: 'domcontentloaded' });
  const repairCrewPanel = production.getByRole('heading', { name: 'Repair crew', exact: true }).locator('xpath=../../..');
  await repairCrewPanel.getByRole('button', { name: 'Review production slot', exact: true }).click();
  const repairAssignment = production.getByRole('dialog');
  const slotStart = new Date(Date.now() - 60_000);
  const slotEnd = new Date(Date.now() + 60 * 60_000);
  const asLocalDateTime = (date: Date) => {
    const pad = (value: number) => String(value).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
  };
  await repairAssignment.getByLabel('Reviewed production start').fill(asLocalDateTime(slotStart));
  await repairAssignment.getByLabel('Reviewed production end').fill(asLocalDateTime(slotEnd));
  await repairAssignment.getByLabel('Scheduling reason').fill('Reviewed current inline repair slot.');
  const slotResponse = production.waitForResponse((response) =>
    response.url().includes(`/rework/${ncr.id}/review-slot`) && response.request().method() === 'POST',
  );
  await repairAssignment.getByRole('button', { name: 'Approve production slot', exact: true }).click();
  expect((await slotResponse).status()).toBe(200);
  const assignedRepair = await success(production, 'GET', `/production/work-orders/${fixture.inline_wo}/crew?rework_ncr_id=${encodeURIComponent(ncr.id)}`);
  expect(assignedRepair.current.members).toHaveLength(1);
  expect(assignedRepair.schedule).toMatchObject({ status: 'confirmed' });
  await expect(production.getByRole('button', { name: 'Start rework', exact: true })).toBeVisible();
  await production.setViewportSize({ width: 390, height: 844 });
  expect(await production.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await production.screenshot({ path: 'wo-qc-test-results/inline-rework-mobile.png', fullPage: true });
  await production.setViewportSize({ width: 1440, height: 1000 });
  await clickMutation(production, 'Start rework', `/rework/${ncr.id}/start`);
  const startedWorkOrder = await success(production, 'GET', `/production/work-orders/${fixture.inline_wo}`);
  expect(startedWorkOrder.rework).toContainEqual(expect.objectContaining({
    id: ncr.id, quantity: 1, recorded_quantity: 0, remaining_quantity: 1,
    started_at: expect.any(String), finished_at: null,
    started_by: { id: productionActor.id, name: productionActor.name },
  }));
  expect(await success(checker, 'GET', `/quality/ncrs/${ncr.id}`)).toMatchObject({
    rework_started_by: { id: productionActor.id, name: productionActor.name },
    rework_finished_by: null,
  });
  expect(await auditTrail('NonConformanceReport', ncr.id)).toEqual(expect.arrayContaining([
    expect.objectContaining({
      action: 'updated', model_id: ncr.id,
      user: expect.objectContaining({ id: productionActor.id, role: expect.objectContaining({ slug: 'production_manager' }) }),
      new_values: expect.objectContaining({ rework_started_at: expect.any(String) }),
      created_at: expect.any(String),
    }),
  ]));
  await expect(production.getByText(`Started by ${productionActor.name}`, { exact: false })).toBeVisible();
  await expect(production.getByRole('spinbutton', { name: 'Repaired pieces' })).toBeVisible();
  expect((await api(production, 'POST', `${reworkPath}/start`)).status).toBe(422);
  expect((await api(production, 'POST', `${reworkPath}/outputs`, { good_count: 2, reject_count: 0 }, { 'X-Idempotency-Key': `qc-${fixture.tag}-over-quantity` })).status).toBe(422);
  expect((await api(production, 'POST', `${reworkPath}/finish`)).status).toBe(422);
  expect((await success(production, 'GET', `/production/work-orders/${fixture.inline_wo}`)).rework).toContainEqual(expect.objectContaining({
    id: ncr.id, recorded_quantity: 0, remaining_quantity: 1, finished_at: null,
  }));
  await production.getByRole('spinbutton', { name: 'Repaired pieces' }).fill('1');
  await production.getByLabel('Recording on behalf reason').fill('Production manager records the repaired piece for the designated repair recorder.');
  const outputRequestPromise = production.waitForRequest((request) => request.url().includes(`/rework/${ncr.id}/outputs`) && request.method() === 'POST');
  const output = await clickMutation(production, 'Record rework output', `/rework/${ncr.id}/outputs`);
  const outputRequest = await outputRequestPromise;
  const outputKey = outputRequest.headers()['x-idempotency-key'];
  if (!outputKey) throw new Error('Rework output request omitted its idempotency key.');
  const replayPayload = outputRequest.postDataJSON() as Record<string, unknown>;
  expect(replayPayload).toMatchObject({
    good_count: 1,
    reject_count: 0,
    crew_id: expect.any(String),
    recording_reason: 'Production manager records the repaired piece for the designated repair recorder.',
    defects: [],
  });
  const beforeReplayWorkOrder = await success(production, 'GET', `/production/work-orders/${fixture.inline_wo}`);
  const outputRowsBeforeReplay = beforeReplayWorkOrder.outputs.filter((row: { id: string }) => row.id === output.id);
  expect(outputRowsBeforeReplay).toHaveLength(1);
  const movementsBeforeReplay = await success(pages.warehouse, 'GET', `/inventory/stock-movements?item_id=${fixture.item}&reference_type=work_order_output&per_page=200`);
  const repairMovementsBeforeReplay = movementsBeforeReplay.filter((movement: { reference_id: string }) => movement.reference_id === output.id);
  const outputAuditBeforeReplay = await auditTrail('WorkOrderOutput', output.id);
  const [firstReplay, changedReplay, secondReplay] = await Promise.all([
    api(
      production,
      'POST',
      `${reworkPath}/outputs`,
      replayPayload,
      { 'X-Idempotency-Key': outputKey },
    ),
    api(
      production,
      'POST',
      `${reworkPath}/outputs`,
      { ...replayPayload, recording_reason: `${String(replayPayload.recording_reason)} changed` },
      { 'X-Idempotency-Key': outputKey },
    ),
    api(
      production,
      'POST',
      `${reworkPath}/outputs`,
      replayPayload,
      { 'X-Idempotency-Key': outputKey },
    ),
  ]);
  expect(changedReplay.status).toBe(422);
  expect(JSON.stringify(changedReplay.body)).toMatch(/idempotency key was already used/i);
  for (const replay of [firstReplay, secondReplay]) {
    expect(replay.status, JSON.stringify(replay.body)).toBeLessThan(300);
    expect(replay.body.data.id).toBe(output.id);
  }
  const recordedWorkOrder = await success(production, 'GET', `/production/work-orders/${fixture.inline_wo}`);
  expect(recordedWorkOrder.rework).toEqual(beforeReplayWorkOrder.rework);
  expect(recordedWorkOrder.outputs.filter((row: { id: string }) => row.id === output.id)).toEqual(outputRowsBeforeReplay);
  const movementRows = await success(pages.warehouse, 'GET', `/inventory/stock-movements?item_id=${fixture.item}&reference_type=work_order_output&per_page=200`);
  const repairMovements = movementRows.filter((movement: { reference_id: string }) => movement.reference_id === output.id);
  expect(repairMovements).toEqual(repairMovementsBeforeReplay);
  const outputAuditAfterReplay = await auditTrail('WorkOrderOutput', output.id);
  expect(outputAuditAfterReplay).toEqual(outputAuditBeforeReplay);
  expect(outputAuditAfterReplay).toEqual(expect.arrayContaining([
    expect.objectContaining({
      action: 'created', model_type: 'WorkOrderOutput', model_id: output.id,
      user: expect.objectContaining({ id: productionActor.id, role: expect.objectContaining({ slug: 'production_manager' }) }),
      created_at: expect.any(String),
    }),
  ]));
  expect(output).toMatchObject({ good_count: 1, reject_count: 0 });
  expect(recordedWorkOrder.status).toBe('completed');
  expect(recordedWorkOrder.rework).toContainEqual(expect.objectContaining({
    id: ncr.id, quantity: 1, recorded_quantity: 1, remaining_quantity: 0,
    started_at: expect.any(String), finished_at: null,
  }));
  expect(recordedWorkOrder.outputs).toContainEqual(expect.objectContaining({
    id: output.id, good_count: 1, reject_count: 0, is_rework: true,
    production_receipt_handoff: expect.objectContaining({ status: 'generated' }),
  }));
  expect(repairMovements.map((movement: { movement_type: string }) => movement.movement_type).sort()).toEqual(['adjustment_out', 'production_receipt']);
  for (const movement of repairMovements) {
    expect(movement).toMatchObject({ quantity: '1.000', total_cost: '5.00', gl_handoff: { status: 'generated' } });
    const journalId = movement.gl_handoff.journal_entry?.id;
    expect(journalId).toBeTruthy();
    const journal = await success(pages.finance, 'GET', `/journal-entries/${journalId}`);
    expect(journal).toMatchObject({
      status: 'posted', reference_type: 'stock_movement', reference_id: movement.id,
      total_debit: '5.00', total_credit: '5.00',
    });
    expect(journal.lines.reduce((sum: number, line: { debit: string }) => sum + Math.round(Number(line.debit) * 100), 0)).toBe(500);
    expect(journal.lines.reduce((sum: number, line: { credit: string }) => sum + Math.round(Number(line.credit) * 100), 0)).toBe(500);
  }
  expect(repairMovements).toHaveLength(2);
  const stockAfterRepair = await success(pages.warehouse, 'GET', `/inventory/stock-levels?item_id=${fixture.item}&per_page=200`);
  expect(stockAfterRepair.reduce((sum: number, level: { quantity: string }) => sum + Number(level.quantity), 0)).toBe(200);
  await clickMutation(production, 'Finish rework', `/rework/${ncr.id}/finish`, true);
  const finishedWorkOrder = await success(production, 'GET', `/production/work-orders/${fixture.inline_wo}`);
  expect(finishedWorkOrder.status).toBe('completed');
  expect(finishedWorkOrder.rework).toContainEqual(expect.objectContaining({
    id: ncr.id, quantity: 1, recorded_quantity: 1, remaining_quantity: 0,
    started_at: expect.any(String), finished_at: expect.any(String),
    started_by: { id: productionActor.id, name: productionActor.name },
    finished_by: { id: productionActor.id, name: productionActor.name },
  }));
  expect(await success(checker, 'GET', `/quality/ncrs/${ncr.id}`)).toMatchObject({
    rework_started_by: { id: productionActor.id, name: productionActor.name },
    rework_finished_by: { id: productionActor.id, name: productionActor.name },
  });
  expect(await auditTrail('NonConformanceReport', ncr.id)).toEqual(expect.arrayContaining([
    expect.objectContaining({
      action: 'updated', model_id: ncr.id,
      user: expect.objectContaining({ id: productionActor.id, role: expect.objectContaining({ slug: 'production_manager' }) }),
      new_values: expect.objectContaining({ rework_finished_at: expect.any(String) }),
      created_at: expect.any(String),
    }),
  ]));
  await expect(production.getByText(`Finished by ${productionActor.name}`, { exact: false })).toBeVisible();
  expect((await api(production, 'POST', `${reworkPath}/finish`)).status).toBe(422);
  expect((await success(production, 'GET', `/production/work-orders/${fixture.inline_wo}`)).rework).toContainEqual(expect.objectContaining({
    id: ncr.id, recorded_quantity: 1, remaining_quantity: 0, finished_at: expect.any(String),
  }));
  expect((await api(checker, 'POST', `/quality/ncrs/${ncr.id}/close`)).status).toBe(422);
  const inspections = await success(qc, 'GET', `/quality/inspections?entity_type=work_order&entity_id=${fixture.inline_wo}&per_page=100`);
  const fresh = inspections.find((i: { work_order_output?: { id: string } }) => i.work_order_output?.id === output.id) ?? inspections.find((i: { id: string; status: string }) => i.id !== fixture.inline_inspection && i.status === 'draft');
  expect(fresh).toBeTruthy();
  expect(fresh).toMatchObject({ status: 'draft', work_order_output: { id: output.id } });
  await passInspection(fresh.id);
  expect((await success(production, 'GET', `/production/work-orders/${fixture.inline_wo}`)).status).toBe('completed');
  await checker.goto(`/quality/ncrs/${ncr.id}`, { waitUntil: 'domcontentloaded' });
  for (const [index, action_type] of ['corrective', 'preventive'].entries()) {
    await checker.getByLabel('Type').selectOption(action_type);
    await checker.getByLabel('Description').fill(`E2E ${action_type} ${fixture.tag}.`);
    await clickMutation(checker, 'Add action', '/actions');
    expect((await success(checker, 'GET', `/quality/ncrs/${ncr.id}`)).actions).toHaveLength(index + 1);
  }
  await checker.goto(`/quality/ncrs/${ncr.id}`, { waitUntil: 'domcontentloaded' });
  await clickMutation(checker, 'Close NCR', `/quality/ncrs/${ncr.id}/close`, true);
  expect((await success(checker, 'GET', `/quality/ncrs/${ncr.id}`)).status).toBe('closed');
  expect(await auditTrail('NonConformanceReport', ncr.id)).toEqual(expect.arrayContaining([
    expect.objectContaining({
      action: 'updated', model_id: ncr.id,
      user: expect.objectContaining({ id: checkerActor.id, role: expect.objectContaining({ slug: 'qc_inspector' }) }),
      new_values: expect.objectContaining({ status: 'closed' }),
      created_at: expect.any(String),
    }),
  ]));
  await expect.poll(async () => (await success(production, 'GET', `/production/work-orders/${fixture.inline_wo}`)).status, { timeout: 60_000 }).toBe('closed');
  const final = await success(production, 'GET', `/production/work-orders/${fixture.inline_wo}`);
  expect(final.quantity_produced).toBe(100);
  expect(final.quantity_good).toBe(100);
  expect(final.rework).toHaveLength(1);
  const levels = await success(pages.warehouse, 'GET', `/inventory/stock-levels?item_id=${fixture.item}&per_page=200`);
  expect(levels.reduce((sum: number, level: { quantity: string }) => sum + Number(level.quantity), 0)).toBe(200);
  const notifications = await success(production, 'GET', '/notifications?per_page=100');
  expect(JSON.stringify(notifications)).toContain(fixture.inline_wo);
});

test('Report defect opens linked NCR creation form', async () => {
  await pages.qc.goto(`/quality/inspections/${fixture.closed_inspection}`, { waitUntil: 'domcontentloaded' });
  await pages.qc.getByRole('button', { name: 'Report defect', exact: true }).click();
  await expect(pages.qc.getByLabel('Affected quantity')).toBeVisible();
});

test('WO close and required-operation gates refuse uninspected output; frozen output cannot change', async () => {
  const production = pages.production;
  expect(await success(pages.qc, 'GET', `/quality/inspections/${fixture.draft_process_inspection}`)).toMatchObject({
    stage: 'in_process', status: 'passed', accepted_quantity: 100,
  });
  expect(await success(pages.qc, 'GET', `/quality/inspections/${fixture.operation_process_inspection}`)).toMatchObject({
    stage: 'in_process', status: 'passed', accepted_quantity: 100,
  });
  await assignCrewThroughUi(production, fixture.operation_wo, [pages.crewWorker], {});
  const close = await api(production, 'POST', `/production/work-orders/${fixture.draft_wo}/close`);
  expect(close.status, JSON.stringify(close.body)).toBe(422);
  expect(JSON.stringify(close.body)).toMatch(/QC|quality|NCR/i);
  const complete = await api(production, 'POST', `/production/operations/${fixture.operation}/complete`);
  expect(complete.status, JSON.stringify(complete.body)).toBe(409);
  expect(JSON.stringify(complete.body)).toMatch(/QC/i);
  const inspections = await success(pages.qc, 'GET', `/quality/inspections?entity_type=work_order&entity_id=${fixture.operation_wo}&per_page=100`);
  const operationQc = inspections.find((inspection: { stage: string; notes: string | null }) =>
    inspection.stage === 'in_process' && inspection.notes?.includes('Required by operation'),
  );
  expect(operationQc).toBeTruthy();
  await passInspection(operationQc.id);
  const beforeFrozenOutput = await success(production, 'GET', `/production/operations/${fixture.operation}`);
  const freeze = await api(production, 'POST', `/production/operations/${fixture.operation}/output`, { qty: 1, scrap: 0 });
  expect(freeze.status, JSON.stringify(freeze.body)).toBe(409);
  expect(JSON.stringify(freeze.body)).toMatch(/frozen/i);
  expect(await success(production, 'GET', `/production/operations/${fixture.operation}`)).toMatchObject({
    status: beforeFrozenOutput.status,
    qty_completed: beforeFrozenOutput.qty_completed,
    qty_scrapped: beforeFrozenOutput.qty_scrapped,
  });
  await success(production, 'POST', `/production/operations/${fixture.operation}/complete`);
  expect((await success(production, 'GET', `/production/operations/${fixture.operation}`)).status).toBe('completed');
  expect(errors).toEqual([]);
});
