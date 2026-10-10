import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { baseUrl, loginAs, statefulHeaders } from './helpers';
import { localIsoDateTime } from '../src/lib/formatDate';

const tag = process.env.E2E_START_TAG;
if (!tag || !/^[a-z0-9-]{1,6}$/.test(tag)) throw new Error('Set E2E_START_TAG to an unused 1–6 character lowercase tag.');
const fixturePath = process.env.E2E_START_MANIFEST ?? `../api/storage/app/work-order-start-qc-e2e-${tag}.json`;
const fixture = JSON.parse(readFileSync(resolve(fixturePath), 'utf8'));
if (fixture.tag !== tag) throw new Error(`Expected start QC fixture ${tag}; loaded ${fixture.tag ?? 'untagged fixture'}.`);

const pages: Record<string, Page> = {};
const contexts: Record<string, BrowserContext> = {};
const errors: string[] = [];
const requirements = ['Surface condition', 'Color', 'Packaging', 'Outer diameter'];

async function api(page: Page, method: string, path: string, data?: unknown) {
  const xsrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
  const response = await page.request.fetch(`/api/v1${path}`, {
    method,
    data,
    headers: {
      ...statefulHeaders(),
      ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value) } : {}),
    },
  });
  return { status: response.status(), body: await response.json() };
}

async function success(page: Page, method: string, path: string, data?: unknown) {
  const response = await api(page, method, path, data);
  expect(response.status, JSON.stringify(response.body)).toBeLessThan(300);
  expect(response.status).toBeGreaterThanOrEqual(200);
  return response.body.data;
}

async function auditTrail(id: string) {
  return success(pages.audit, 'GET', `/admin/audit-logs/entity?model_type=WorkOrder&model_id=${id}&per_page=100`);
}

async function inspections(workOrderId: string) {
  return success(pages.qc, 'GET', `/quality/inspections?entity_type=work_order&entity_id=${workOrderId}&per_page=100`);
}

async function stock(itemId: string) {
  return success(pages.warehouse, 'GET', `/inventory/stock-levels?item_id=${itemId}&per_page=100`);
}

async function observedState(workOrderId: string) {
  const [workOrder, crew, machine, mold, rawStock, finishedStock, qcNotifications, audit, processInspections, chain] = await Promise.all([
    success(pages.production, 'GET', `/production/work-orders/${workOrderId}`),
    success(pages.production, 'GET', `/production/work-orders/${workOrderId}/crew`),
    success(pages.audit, 'GET', `/mrp/machines/${fixture.machine}`),
    success(pages.audit, 'GET', `/mrp/molds/${fixture.mold}`),
    stock(fixture.raw_item),
    stock(fixture.finished_item),
    success(pages.qc, 'GET', '/notifications?type=chain.in_process_qc_required&per_page=100'),
    auditTrail(workOrderId),
    inspections(workOrderId),
    success(pages.production, 'GET', `/production/work-orders/${workOrderId}/chain`),
  ]);

  return {
    workOrder,
    crew,
    machine,
    mold,
    rawStock,
    finishedStock,
    qcNotifications: qcNotifications.filter((notification: { data?: { entity_id?: string } }) => notification.data?.entity_id === workOrderId),
    audit,
    processInspections,
    chain,
  };
}

async function passInspection(id: string) {
  const maker = pages.qc;
  await maker.goto(`/quality/inspections/${id}`, { waitUntil: 'domcontentloaded' });
  const initial = await success(maker, 'GET', `/quality/inspections/${id}`);

  const numericMeasurements = maker.locator('input[type="number"][step="0.0001"]');
  if (initial.capture_method === 'checklist') {
    await expect(numericMeasurements).toHaveCount(0);
    await maker.getByRole('textbox', { name: 'Actual size for Outer diameter' }).fill('10.0000');
  } else {
    await expect(numericMeasurements).toHaveCount(5);
    for (let index = 0; index < await numericMeasurements.count(); index += 1) {
      await numericMeasurements.nth(index).fill('10.0000');
    }
    const savedMeasurements = maker.waitForResponse((response) =>
      response.url().includes(`/quality/inspections/${id}/measurements`)
      && response.request().method() === 'PATCH',
    );
    await maker.getByRole('button', { name: 'Save measurements', exact: true }).click();
    expect((await savedMeasurements).ok()).toBe(true);

    const recorded = await success(maker, 'GET', `/quality/inspections/${id}`);
    expect(recorded.status).toBe('in_progress');
    const criticalReadings = recorded.measurements.filter((row: {
      is_critical: boolean;
      tolerance_min: number | null;
      tolerance_max: number | null;
    }) => row.is_critical && (row.tolerance_min !== null || row.tolerance_max !== null));
    expect(criticalReadings).toHaveLength(5);
    expect(criticalReadings.every((row: { measured_value: number | string | null; is_pass: boolean | null }) =>
      Number(row.measured_value) === 10 && row.is_pass === true,
    )).toBe(true);
  }

  if (initial.capture_method !== 'checklist' && initial.inspection_mode === 'lot_checklist') {
    await maker.getByRole('spinbutton', { name: 'Inspected pieces' }).fill(String(initial.sample_size));
    await maker.getByRole('spinbutton', { name: 'Defective pieces found' }).fill('0');
  }
  if (initial.capture_method === 'checklist' || initial.inspection_mode === 'lot_checklist') {
    await maker.getByRole('checkbox', {
      name: /I confirm (all required checks on the planned sample were completed|every piece was inspected against all required checks)/,
    }).check();
  }
  for (const name of requirements) await maker.getByRole('button', { name: `PASS ${name}`, exact: true }).click();
  const checklistCapture = initial.capture_method === 'checklist';
  const resultEndpoint = checklistCapture || initial.inspection_mode === 'lot_checklist' ? 'batch-result' : 'item-results';
  const submit = maker.waitForResponse((response) => response.url().includes(`/quality/inspections/${id}/${resultEndpoint}`) && response.request().method() === 'POST');
  const submitLabel = checklistCapture
    ? initial.sampling_basis === 'lot' ? 'Submit result' : 'Submit inspection'
    : initial.inspection_mode === 'lot_checklist' ? 'Submit result' : 'Submit inspection';
  await maker.getByRole('button', { name: submitLabel, exact: true }).click();
  const submittedResult = await submit;
  expect(submittedResult.status(), await submittedResult.text()).toBe(200);

  const submitted = await success(maker, 'GET', `/quality/inspections/${id}`);
  if (initial.capture_method === 'checklist') {
    const criticalReadings = submitted.measurements.filter((row: {
      is_critical: boolean;
      tolerance_min: number | null;
      tolerance_max: number | null;
    }) => row.is_critical && (row.tolerance_min !== null || row.tolerance_max !== null));
    expect(criticalReadings).toHaveLength(1);
    expect(Number(criticalReadings[0].measured_value)).toBe(10);
    expect(criticalReadings[0].item_result).toBe('passed');
  }
  if (submitted.status === 'awaiting_review') {
    const reviewer = submitted.inspector?.id === fixture.checker ? pages.production : pages.checker;
    await reviewer.goto(`/quality/inspections/${id}`, { waitUntil: 'domcontentloaded' });
    await reviewer.getByRole('button', { name: 'Approve pass', exact: true }).click();
    const dialog = reviewer.getByRole('dialog');
    const response = reviewer.waitForResponse((res) => res.url().includes(`/quality/inspections/${id}/review`) && res.request().method() === 'PATCH');
    await dialog.getByRole('button', { name: 'Approve pass', exact: true }).click();
    expect((await response).ok()).toBe(true);
  }

  expect((await success(maker, 'GET', `/quality/inspections/${id}`)).status).toBe('passed');
}

test.beforeAll(async ({ browser }) => {
  for (const [role, email] of Object.entries({
    production: 'production@ogami.test',
    crewWorker: fixture.worker_email,
    qc: 'qc@ogami.test',
    checker: fixture.checker_email,
    warehouse: 'warehouse@ogami.test',
    audit: 'admin@ogami.test',
  })) {
    const context = await browser.newContext({ baseURL: baseUrl() });
    contexts[role] = context;
    const page = await context.newPage();
    page.setDefaultTimeout(30_000);
    pages[role] = page;
    page.on('console', (message) => {
      if (message.type() !== 'error') return;
      errors.push(`${role}: console.error ${message.text()}`);
    });
    page.on('pageerror', (error) => errors.push(`${role}: ${error.message}`));
    page.on('requestfailed', (request) => {
      if (request.url().includes('/api/')) errors.push(`${role}: API request failed ${request.method()} ${request.url()}: ${request.failure()?.errorText ?? 'unknown error'}`);
    });
    page.on('response', (response) => { if (response.url().includes('/api/') && response.status() >= 500) errors.push(`${role}: ${response.status()} ${response.url()}`); });
    await loginAs(page, email, 'password');
  }
});

test.afterAll(async () => {
  for (const context of Object.values(contexts)) await context.close();
  expect([...new Set(errors)], `Unexpected browser/API failures:\n${errors.join('\n')}`).toEqual([]);
});

test('zero-length planned window cannot be confirmed and remains cancellable without side effects', async () => {
  const production = pages.production;
  const workOrders = '/production/work-orders';
  await production.goto(`${workOrders}/create`, { waitUntil: 'domcontentloaded' });
  await production.locator('select[name="product_id"]').selectOption(fixture.product);
  await production.locator('input[name="quantity_target"]').fill('1');
  const createResponse = production.waitForResponse((response) => response.url().endsWith(`/api/v1${workOrders}`) && response.request().method() === 'POST');
  await production.getByRole('button', { name: 'Create work order', exact: true }).click();
  const created = await createResponse;
  expect(created.ok(), await created.text()).toBe(true);
  const workOrderId = (await created.json()).data.id as string;

  const planned = await success(production, 'GET', `${workOrders}/${workOrderId}`);
  expect(planned.status).toBe('planned');
  expect(planned.planned_start).toBe(planned.planned_end);
  const before = await observedState(workOrderId);
  expect(before.crew).toMatchObject({ current: null, schedule: null, history: [] });

  await production.getByRole('button', { name: 'Confirm', exact: true }).click();
  const review = production.getByRole('dialog');
  await review.getByLabel('Number of workers').selectOption('1');
  await expect(review.getByText(/No equipment slot available/)).toBeVisible();
  await expect(review.getByRole('button', { name: 'Apply assignment and confirm' })).toBeDisabled();
  const beforeRejectedApply = await observedState(workOrderId);
  const worker = await success(pages.crewWorker, 'GET', '/auth/user');
  const bypass = await api(production, 'POST', `${workOrders}/${workOrderId}/assignment-apply`, {
    employee_ids: [worker.employee.id],
    recorder_employee_id: worker.employee.id,
    minimum_workers: 1,
    maximum_workers: null,
    expected_crew_id: null,
    schedule_id: 2147483647,
    schedule_updated_at: new Date().toISOString(),
    reason: 'Attempt to confirm without an equipment reservation.',
  });
  expect(bypass.status).toBe(422);
  expect(JSON.stringify(bypass.body)).toMatch(/schedule_id/i);
  expect(await observedState(workOrderId)).toEqual(beforeRejectedApply);

  await review.getByRole('button', { name: 'Cancel', exact: true }).click();
  await production.getByRole('button', { name: 'Cancel', exact: true }).click();
  const cancelDialog = production.getByRole('dialog', { name: 'Cancel this work order?' });
  const cancelResponse = production.waitForResponse((response) => response.url().endsWith(`/api/v1${workOrders}/${workOrderId}/cancel`) && response.request().method() === 'POST');
  await cancelDialog.getByRole('button', { name: 'Cancel', exact: true }).nth(1).click();
  expect((await cancelResponse).ok()).toBe(true);

  const cancelled = await success(production, 'GET', `${workOrders}/${workOrderId}`);
  expect(cancelled.status).toBe('cancelled');
  expect((await observedState(workOrderId)).workOrder.status).toBe('cancelled');
  expect((await stock(fixture.raw_item))[0]).toMatchObject({ quantity: '100.000', reserved_quantity: '0.000' });
  expect((await success(pages.audit, 'GET', `/mrp/machines/${fixture.machine}`)).status).toBe('idle');
  expect((await success(pages.audit, 'GET', `/mrp/molds/${fixture.mold}`)).status).toBe('available');
  expect((await auditTrail(workOrderId)).some((row: { action: string; new_values?: { status?: string } }) => row.action === 'updated' && row.new_values?.status === 'cancelled')).toBe(true);
});

test('production creates, confirms, and starts WO only with QC ready; QC then releases output', async () => {
  test.setTimeout(240_000);
  const production = pages.production;
  const workOrders = '/production/work-orders';

  await production.goto(`${workOrders}/create`, { waitUntil: 'domcontentloaded' });
  await production.locator('select[name="product_id"]').selectOption(fixture.product);
  await production.locator('input[name="quantity_target"]').fill('10');
  await production.locator('input[name="planned_start"]').fill(localIsoDateTime(new Date(Date.now() - 60_000)));
  await production.locator('input[name="planned_end"]').fill(localIsoDateTime(new Date(Date.now() + 24 * 60 * 60_000)));
  const createResponse = production.waitForResponse((response) => response.url().endsWith(`/api/v1${workOrders}`) && response.request().method() === 'POST');
  await production.getByRole('button', { name: 'Create work order', exact: true }).click();
  const created = await createResponse;
  expect(created.ok(), await created.text()).toBe(true);
  const workOrderId = (await created.json()).data.id as string;

  const planned = await success(production, 'GET', `${workOrders}/${workOrderId}`);
  expect(planned).toMatchObject({ status: 'planned', quantity_target: 10, quantity_produced: 0, quantity_good: 0 });
  expect(planned.inspections).toEqual([]);
  expect((await stock(fixture.raw_item))[0]).toMatchObject({ quantity: '100.000', reserved_quantity: '0.000' });
  expect((await success(pages.audit, 'GET', `/mrp/machines/${fixture.machine}`)).status).toBe('idle');
  expect((await success(pages.audit, 'GET', `/mrp/molds/${fixture.mold}`)).status).toBe('available');
  expect((await auditTrail(workOrderId)).some((row: { action: string }) => row.action === 'created')).toBe(true);

  const beforeSkippedStart = await observedState(workOrderId);
  const skippedStart = await api(production, 'POST', `${workOrders}/${workOrderId}/start`);
  expect(skippedStart.status).toBe(422);
  expect(skippedStart.body.errors).toHaveProperty('expected_crew_id');
  expect(await observedState(workOrderId)).toEqual(beforeSkippedStart);

  const unassignedCrew = await success(pages.production, 'GET', `${workOrders}/${workOrderId}/crew`);
  expect(unassignedCrew.current).toBeNull();
  expect(unassignedCrew.history).toHaveLength(0);
  await production.getByRole('button', { name: 'Confirm', exact: true }).click();
  const reviewDialog = production.getByRole('dialog');
  await expect(reviewDialog.getByRole('heading', { name: 'Review and confirm work order' })).toBeVisible();
  await reviewDialog.getByLabel('Number of workers').selectOption('1');
  const suggestedRecorderId = await reviewDialog.getByLabel('Output recorder').inputValue();
  expect(suggestedRecorderId).not.toBe('');
  await expect(reviewDialog.getByText(/Selected: 1/)).toBeVisible();
  const confirmResponse = production.waitForResponse((response) =>
    response.url().includes(`${workOrders}/${workOrderId}/assignment-apply`) && response.request().method() === 'POST',
  );
  await reviewDialog.getByRole('button', { name: 'Apply assignment and confirm' }).click();
  expect((await confirmResponse).status()).toBe(200);

  const confirmed = await success(production, 'GET', `${workOrders}/${workOrderId}`);
  expect(confirmed.status).toBe('confirmed');
  expect(confirmed.machine.id).toBe(fixture.machine);
  expect(confirmed.mold.id).toBe(fixture.mold);
  const confirmedCrew = await success(production, 'GET', `${workOrders}/${workOrderId}/crew`);
  expect(confirmedCrew.current.recorder_employee_id).toBe(suggestedRecorderId);
  expect(confirmedCrew.current.members).toEqual([
    expect.objectContaining({ id: suggestedRecorderId, has_active_account: true }),
  ]);
  expect(confirmedCrew.history).toHaveLength(1);
  expect(confirmed.materials[0]).toMatchObject({ bom_quantity: '10.000', actual_quantity_issued: '0.000' });
  expect((await stock(fixture.raw_item))[0]).toMatchObject({ quantity: '100.000', reserved_quantity: '10.000' });
  expect((await success(pages.audit, 'GET', `/mrp/machines/${fixture.machine}`)).status).toBe('idle');
  expect((await success(pages.audit, 'GET', `/mrp/molds/${fixture.mold}`)).status).toBe('available');
  expect((await auditTrail(workOrderId)).some((row: { action: string; new_values?: { status?: string } }) => row.action === 'updated' && row.new_values?.status === 'confirmed')).toBe(true);

  const activeSpec = await success(pages.qc, 'GET', `/quality/products/${fixture.product}/inspection-spec`);
  expect(activeSpec).toMatchObject({ is_active: true });
  await pages.qc.goto('/quality/inspection-specs');
  await pages.qc.getByPlaceholder(/Search/).fill(`START-QC-${tag}`);
  const specRow = pages.qc.getByRole('row').filter({ hasText: `START-QC-${tag}` });
  await expect(specRow).toBeVisible();
  const archiveResponse = pages.qc.waitForResponse((response) => response.url().includes(`/quality/inspection-specs/${activeSpec.id}`) && response.request().method() === 'DELETE');
  await specRow.getByRole('button', { name: 'Archive inspection spec' }).click();
  const archiveDialog = pages.qc.getByRole('dialog');
  await archiveDialog.getByRole('button', { name: 'Archive', exact: true }).click();
  const archivedSpec = await archiveResponse;
  expect(archivedSpec.ok(), await archivedSpec.text()).toBe(true);
  await expect(specRow.getByText('Archived')).toBeVisible();
  expect(await success(pages.qc, 'GET', `/quality/products/${fixture.product}/inspection-spec`)).toBeNull();

  const beforeMissingSpecStart = await observedState(workOrderId);
  await production.goto(`/production/work-orders/${workOrderId}`);
  await production.getByRole('button', { name: 'Start', exact: true }).click();
  const missingSpecDialog = production.getByRole('dialog');
  await expect(missingSpecDialog.getByText(/QC must publish an inspection specification before production can start/)).toBeVisible();
  await expect(missingSpecDialog.getByRole('button', { name: 'Start', exact: true })).toHaveCount(0);
  const blockedStart = await api(production, 'POST', `${workOrders}/${workOrderId}/start`, {
    expected_crew_id: confirmedCrew.current.id,
  });
  expect(blockedStart.status).toBe(422);
  expect(JSON.stringify(blockedStart.body)).toMatch(/inspection spec/i);
  const afterMissingSpecStart = await observedState(workOrderId);
  expect(afterMissingSpecStart).toEqual(beforeMissingSpecStart);
  expect(afterMissingSpecStart.workOrder.status).toBe('confirmed');
  expect(afterMissingSpecStart.workOrder.materials[0].actual_quantity_issued).toBe('0.000');
  expect(afterMissingSpecStart.processInspections).toHaveLength(0);
  expect(afterMissingSpecStart.qcNotifications).toHaveLength(0);

  const restoreResponse = pages.qc.waitForResponse((response) => response.url().includes(`/quality/inspection-specs/${activeSpec.id}/restore`) && response.request().method() === 'PATCH');
  await specRow.getByRole('button', { name: 'Restore inspection spec' }).click();
  const restoredSpec = await restoreResponse;
  expect(restoredSpec.ok(), await restoredSpec.text()).toBe(true);
  expect((await restoredSpec.json()).data).toMatchObject({ is_active: true });
  expect((await success(pages.qc, 'GET', `/quality/products/${fixture.product}/inspection-spec`)).id).toBe(activeSpec.id);

  const startDialog = production.getByRole('dialog');
  await startDialog.getByRole('button', { name: 'Start production', exact: true }).click();
  await expect(production.getByText('Production started.', { exact: true }).last()).toBeVisible();

  const started = await success(production, 'GET', `${workOrders}/${workOrderId}`);
  expect(started).toMatchObject({ status: 'in_progress', quantity_target: 10, quantity_produced: 0 });
  expect(started.materials[0]).toMatchObject({
    bom_quantity: '10.000',
    actual_quantity_issued: '10.000',
    actual_cost: '50.00',
  });
  expect(started.inspections).toHaveLength(1);
  const processQc = started.inspections[0];
  expect(processQc).toMatchObject({ stage: 'in_process', status: 'draft' });
  expect(started.process_quality_blocker).toContain(processQc.inspection_number);
  expect((await stock(fixture.raw_item))[0]).toMatchObject({ quantity: '90.000', reserved_quantity: '0.000' });
  expect((await success(pages.audit, 'GET', `/mrp/machines/${fixture.machine}`)).status).toBe('running');
  expect((await success(pages.audit, 'GET', `/mrp/molds/${fixture.mold}`)).status).toBe('in_use');

  const notices = await success(pages.qc, 'GET', '/notifications?type=chain.in_process_qc_required&per_page=100');
  expect(notices).toContainEqual(expect.objectContaining({
    type: 'chain.in_process_qc_required',
    data: expect.objectContaining({ link_to: `/quality/inspections/${processQc.id}`, entity_id: workOrderId }),
  }));
  const startAudit = await auditTrail(workOrderId);
  expect(startAudit).toEqual(expect.arrayContaining([
    expect.objectContaining({
      action: 'updated',
      user: expect.objectContaining({ role: expect.objectContaining({ slug: 'production_manager' }) }),
      new_values: expect.objectContaining({ status: 'in_progress' }),
    }),
  ]));
  expect(await success(production, 'GET', `${workOrders}/${workOrderId}/chain`)).toEqual(expect.arrayContaining([
    expect.objectContaining({ key: 'confirmed', state: 'done' }),
    expect.objectContaining({ key: 'in_progress', state: 'active' }),
  ]));
  const beforeDuplicateStart = await observedState(workOrderId);
  const duplicateStart = await api(production, 'POST', `${workOrders}/${workOrderId}/start`, {
    expected_crew_id: confirmedCrew.current.id,
  });
  expect(duplicateStart.status).toBe(409);
  expect(duplicateStart.body.code).toBe('ILLEGAL_WORK_ORDER_TRANSITION');
  expect(await observedState(workOrderId)).toEqual(beforeDuplicateStart);
  await production.goto(`/production/work-orders/${workOrderId}`);
  await expect(production.getByRole('button', { name: 'Start', exact: true })).toHaveCount(0);
  await expect(production.getByRole('button', { name: 'Record output', exact: true })).toHaveCount(0);
  await expect(production.getByRole('button', { name: 'Complete', exact: true })).toHaveCount(0);

  const beforeEarlyOutput = await observedState(workOrderId);
  const earlyOutput = await api(production, 'POST', `${workOrders}/${workOrderId}/outputs`, {
    good_count: 10,
    reject_count: 0,
    crew_id: confirmedCrew.current.id,
    recording_reason: 'Production manager validates the in-process quality gate.',
  });
  expect(earlyOutput.status).toBe(422);
  expect(JSON.stringify(earlyOutput.body)).toMatch(/in-process QC|quality/i);
  expect(await observedState(workOrderId)).toEqual(beforeEarlyOutput);
  const beforeEarlyComplete = await observedState(workOrderId);
  expect((await api(production, 'POST', `${workOrders}/${workOrderId}/complete`)).status).toBe(422);
  expect(await observedState(workOrderId)).toEqual(beforeEarlyComplete);

  await production.goto(`/production/work-orders/${workOrderId}/record-output`, { waitUntil: 'domcontentloaded' });
  await expect(production.getByRole('alert').filter({ hasText: processQc.inspection_number })).toBeVisible();
  await expect(production.getByRole('button', { name: 'Record', exact: true })).toBeDisabled();

  const processInspectionBeforeArchive = await success(pages.qc, 'GET', `/quality/inspections/${processQc.id}`);
  const pinnedRevision = processInspectionBeforeArchive.spec_revision;
  expect(pinnedRevision.id).toBe(processInspectionBeforeArchive.spec.revision_id);
  expect(pinnedRevision.version).toBe(activeSpec.version);
  expect(pinnedRevision.items.map((item: { parameter_name: string }) => item.parameter_name)).toEqual(requirements);
  await pages.qc.goto('/quality/inspection-specs');
  await pages.qc.getByPlaceholder(/Search/).fill(`START-QC-${tag}`);
  const archiveAfterStartResponse = pages.qc.waitForResponse((response) => response.url().includes(`/quality/inspection-specs/${activeSpec.id}`) && response.request().method() === 'DELETE');
  await specRow.getByRole('button', { name: 'Archive inspection spec' }).click();
  const archiveAfterStartDialog = pages.qc.getByRole('dialog');
  await archiveAfterStartDialog.getByRole('button', { name: 'Archive', exact: true }).click();
  expect((await archiveAfterStartResponse).ok()).toBe(true);
  const archivedProcessInspection = await success(pages.qc, 'GET', `/quality/inspections/${processQc.id}`);
  expect(archivedProcessInspection.status).toBe('draft');
  expect(archivedProcessInspection.spec).toBeNull();
  expect(archivedProcessInspection.spec_revision.id).toBe(pinnedRevision.id);
  expect(archivedProcessInspection.spec_revision.items.map((item: { parameter_name: string }) => item.parameter_name)).toEqual(requirements);
  expect(archivedProcessInspection.measurements).toEqual(processInspectionBeforeArchive.measurements);

  await passInspection(processQc.id);
  const qcReleased = await success(production, 'GET', `${workOrders}/${workOrderId}`);
  expect(qcReleased.process_quality_blocker).toBeNull();
  await pages.qc.goto('/quality/inspection-specs');
  await pages.qc.getByPlaceholder(/Search/).fill(`START-QC-${tag}`);
  const restoreAfterStartResponse = pages.qc.waitForResponse((response) => response.url().includes(`/quality/inspection-specs/${activeSpec.id}/restore`) && response.request().method() === 'PATCH');
  await specRow.getByRole('button', { name: 'Restore inspection spec' }).click();
  const restoredAfterStart = await restoreAfterStartResponse;
  expect(restoredAfterStart.ok()).toBe(true);
  expect((await restoredAfterStart.json()).data).toMatchObject({ id: activeSpec.id, is_active: true });

  await production.goto(`/production/work-orders/${workOrderId}`);
  await production.getByRole('button', { name: 'Record output', exact: true }).click();
  await production.locator('input[name="good_count"]').fill('10');
  await production.getByLabel('Recording on behalf reason').fill('Production manager records output for the designated crew recorder.');
  const outputResponse = production.waitForResponse((response) => response.url().includes(`${workOrders}/${workOrderId}/outputs`) && response.request().method() === 'POST');
  await production.getByRole('button', { name: 'Record', exact: true }).click();
  expect((await outputResponse).ok()).toBe(true);
  const outputs = await success(production, 'GET', `${workOrders}/${workOrderId}/outputs`);
  expect(outputs).toHaveLength(1);
  expect(outputs[0]).toMatchObject({
    good_count: 10,
    reject_count: 0,
    production_receipt_handoff: { status: 'generated' },
  });
  expect((await stock(fixture.finished_item))[0]).toMatchObject({ quantity: '10.000', reserved_quantity: '0.000' });
  expect((await success(production, 'GET', `${workOrders}/${workOrderId}`)).status).toBe('in_progress');

  await production.goto(`/production/work-orders/${workOrderId}`);
  await production.getByRole('button', { name: 'Complete', exact: true }).click();
  const completeDialog = production.getByRole('dialog');
  const completeResponse = production.waitForResponse((response) => response.url().includes(`${workOrders}/${workOrderId}/complete`) && response.request().method() === 'POST');
  await completeDialog.getByRole('button', { name: 'Complete', exact: true }).click();
  expect((await completeResponse).ok()).toBe(true);
  const completed = await success(production, 'GET', `${workOrders}/${workOrderId}`);
  expect(completed.status).toBe('completed');
  expect(completed).toMatchObject({ quantity_produced: 10, quantity_good: 10 });
  expect((await success(pages.audit, 'GET', `/mrp/machines/${fixture.machine}`)).status).toBe('idle');
  expect((await success(pages.audit, 'GET', `/mrp/molds/${fixture.mold}`)).status).toBe('available');
  expect((await auditTrail(workOrderId)).some((row: { action: string; new_values?: { status?: string } }) => row.action === 'updated' && row.new_values?.status === 'completed')).toBe(true);

  await expect.poll(async () => (await inspections(workOrderId)).filter((inspection: { stage: string }) => inspection.stage === 'outgoing').length).toBe(1);
  const outgoingQc = (await inspections(workOrderId)).find((inspection: { stage: string }) => inspection.stage === 'outgoing');
  expect(outgoingQc).toMatchObject({ status: 'draft', work_order_output: { id: outputs[0].id, good_count: 10 } });
  expect((await success(pages.qc, 'GET', '/notifications?type=chain.outgoing_qc_required&per_page=100')).some((notification: { data?: { entity_id?: string } }) => notification.data?.entity_id === workOrderId)).toBe(true);
  const beforeEarlyClose = await observedState(workOrderId);
  const earlyClose = await api(production, 'POST', `${workOrders}/${workOrderId}/close`);
  expect(earlyClose.status).toBe(422);
  expect(JSON.stringify(earlyClose.body)).toMatch(/final QC coverage|inspection/i);
  expect(await observedState(workOrderId)).toEqual(beforeEarlyClose);
  await production.goto(`/production/work-orders/${workOrderId}`);
  await expect(production.getByRole('button', { name: 'Close', exact: true })).toHaveCount(0);

  await passInspection(outgoingQc.id);
  const releasedOutgoingQc = await success(pages.qc, 'GET', `/quality/inspections/${outgoingQc.id}`);
  expect(releasedOutgoingQc).toMatchObject({ status: 'passed' });
  expect(releasedOutgoingQc.reviewer?.id).not.toBe(releasedOutgoingQc.inspector?.id);
  expect(releasedOutgoingQc.result_authors.map((author: { id: string }) => author.id)).not.toContain(releasedOutgoingQc.reviewer?.id);
  await expect.poll(async () => (await success(production, 'GET', `${workOrders}/${workOrderId}`)).status).toBe('closed');

  const closed = await success(production, 'GET', `${workOrders}/${workOrderId}`);
  expect(closed.process_quality_blocker).toBeNull();
  expect(closed).toMatchObject({ quantity_produced: 10, quantity_good: 10 });
  expect(closed.inspections).toHaveLength(2);
  expect(closed.inspections).toEqual(expect.arrayContaining([
    expect.objectContaining({ id: processQc.id, stage: 'in_process', status: 'passed' }),
    expect.objectContaining({ id: outgoingQc.id, stage: 'outgoing', status: 'passed' }),
  ]));
  expect((await stock(fixture.finished_item))[0]).toMatchObject({ quantity: '10.000', reserved_quantity: '0.000' });
  expect((await auditTrail(workOrderId)).some((row: { action: string; new_values?: { status?: string } }) => row.action === 'updated' && row.new_values?.status === 'closed')).toBe(true);
});
