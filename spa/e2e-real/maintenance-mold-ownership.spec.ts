import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import { baseUrl, captureNetwork, loginAs, statefulHeaders, type NetworkCapture } from './helpers';

interface Fixture {
  tag: string;
  machine: string;
  machine_code: string;
  mold: string;
  mold_code: string;
  mold_name: string;
  production_work_order: string;
  production_wo_number: string;
  maintenance_machine: string;
  maintenance_machine_code: string;
  maintenance_machine_name: string;
}

interface Envelope<T> {
  data: T;
  errors?: Record<string, string[]>;
  message?: string;
}

interface AuditEntry {
  action: string;
  new_values: Record<string, unknown> | null;
  user?: { email?: string; role?: { slug?: string } };
}

const tag = process.env.E2E_MAINTENANCE_TAG;
if (!tag || !/^[a-z0-9-]{1,6}$/.test(tag)) {
  throw new Error('Set E2E_MAINTENANCE_TAG to an unused 1–6 character lowercase tag.');
}
const fixturePath = process.env.E2E_MAINTENANCE_FIXTURE
  ?? `../api/storage/app/maintenance-mold-ownership-e2e-${tag}.json`;
const fixture = JSON.parse(readFileSync(resolve(fixturePath), 'utf8')) as Fixture;
if (fixture.tag !== tag) throw new Error(`Expected maintenance fixture ${tag}, loaded ${fixture.tag}.`);

let maintenancePage: Page;
let auditPage: Page;
let network: NetworkCapture;

async function apiData<T>(page: Page, path: string): Promise<T> {
  const response = await page.request.get(`/api/v1${path}`, { headers: statefulHeaders() });
  const body = await response.json() as Envelope<T>;
  expect(response.status(), JSON.stringify(body)).toBe(200);
  return body.data;
}

async function auditTrail(id: string): Promise<AuditEntry[]> {
  const query = new URLSearchParams({
    model_type: 'MaintenanceWorkOrder',
    model_id: id,
    per_page: '100',
  });
  return apiData<AuditEntry[]>(auditPage, `/admin/audit-logs/entity?${query.toString()}`);
}

async function observedState(id: string, machineId = fixture.machine) {
  const [maintenanceWorkOrder, mold, moldHistory, machine, productionWorkOrder, audit] = await Promise.all([
    apiData<Record<string, unknown>>(maintenancePage, `/maintenance/work-orders/${id}`),
    apiData<Record<string, unknown>>(auditPage, `/mrp/molds/${fixture.mold}`),
    apiData<unknown>(auditPage, `/mrp/molds/${fixture.mold}/history?per_page=100`),
    apiData<Record<string, unknown>>(auditPage, `/mrp/machines/${machineId}`),
    apiData<Record<string, unknown>>(auditPage, `/production/work-orders/${fixture.production_work_order}`),
    auditTrail(id),
  ]);

  return { maintenanceWorkOrder, mold, moldHistory, machine, productionWorkOrder, audit };
}

test.beforeAll(async ({ browser }) => {
  const maintenanceContext = await browser.newContext({ baseURL: baseUrl() });
  maintenancePage = await maintenanceContext.newPage();
  maintenancePage.setDefaultTimeout(30_000);
  await loginAs(maintenancePage, 'maintenance@ogami.test', 'password');
  network = captureNetwork(maintenancePage);

  const auditContext = await browser.newContext({ baseURL: baseUrl() });
  auditPage = await auditContext.newPage();
  await loginAs(auditPage, 'admin@ogami.test', 'password');
});

test.afterAll(async () => {
  network?.stop();
});

test('maintenance tech cannot start work on a mold still owned by production', async () => {
  const actor = await apiData<{ role?: { slug?: string } }>(maintenancePage, '/auth/user');
  expect(actor.role?.slug).toBe('maintenance_tech');

  await maintenancePage.goto('/maintenance/work-orders/create', { waitUntil: 'domcontentloaded' });
  await maintenancePage.locator('select[name="maintainable_type"]').selectOption('mold');

  const targetSearch = maintenancePage.getByRole('combobox', { name: 'Target', exact: true });
  const targetResponse = maintenancePage.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname === '/api/v1/maintenance/target-options'
      && url.searchParams.get('type') === 'mold'
      && url.searchParams.get('search') === fixture.mold_code;
  });
  await targetSearch.fill(fixture.mold_code);
  const targets = await targetResponse;
  expect(targets.status()).toBe(200);
  await maintenancePage.getByRole('option', {
    name: `${fixture.mold_code} - ${fixture.mold_name}`,
    exact: true,
  }).click();

  await maintenancePage.locator('select[name="type"]').selectOption('corrective');
  await maintenancePage.locator('select[name="priority"]').selectOption('high');
  await maintenancePage.getByLabel('Description').fill(`Production ownership guard ${tag}`);

  const createResponse = maintenancePage.waitForResponse((response) =>
    response.url().endsWith('/api/v1/maintenance/work-orders')
    && response.request().method() === 'POST',
  );
  await maintenancePage.getByRole('button', { name: 'Create work order', exact: true }).click();
  const createdResponse = await createResponse;
  const createdBody = await createdResponse.json() as Envelope<Record<string, unknown>>;
  expect(createdResponse.status(), JSON.stringify(createdBody)).toBe(201);
  const created = createdBody.data;
  const id = String(created.id);
  expect(created).toMatchObject({
    maintainable_type: 'mold',
    maintainable: { id: fixture.mold, code: fixture.mold_code },
    type: 'corrective',
    priority: 'high',
    description: `Production ownership guard ${tag}`,
    status: 'open',
    started_at: null,
    logs: [],
  });
  await expect(maintenancePage).toHaveURL(new RegExp(`/maintenance/work-orders/${id}$`));
  await expect(maintenancePage.getByRole('button', { name: 'Start', exact: true })).toBeVisible();
  await expect(maintenancePage.getByRole('button', { name: 'Cancel', exact: true })).toBeVisible();

  const beforeStart = await observedState(id);
  expect(beforeStart.mold).toMatchObject({ status: 'in_use', current_shot_count: 5000 });
  expect(beforeStart.machine).toMatchObject({ status: 'running' });
  expect(beforeStart.productionWorkOrder).toMatchObject({
    id: fixture.production_work_order,
    wo_number: fixture.production_wo_number,
    status: 'in_progress',
  });
  expect(beforeStart.audit).toEqual(expect.arrayContaining([
    expect.objectContaining({
      action: 'created',
      user: expect.objectContaining({
        email: 'maintenance@ogami.test',
        role: expect.objectContaining({ slug: 'maintenance_tech' }),
      }),
    }),
  ]));

  await maintenancePage.getByRole('button', { name: 'Start', exact: true }).click();
  const startDialog = maintenancePage.getByRole('dialog');
  const startResponse = maintenancePage.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/maintenance/work-orders/${id}/start`)
    && response.request().method() === 'PATCH',
  );
  await startDialog.getByRole('button', { name: 'Start', exact: true }).click();
  const blockedStart = await startResponse;
  const blockedBody = await blockedStart.json() as Envelope<unknown>;
  expect(blockedStart.status(), JSON.stringify(blockedBody)).toBe(422);
  const message = blockedBody.errors?.maintainable_id?.[0] ?? blockedBody.message ?? '';
  expect(message).toContain('active or unresolved production ownership');
  await expect(maintenancePage.getByText(message, { exact: true })).toBeVisible();

  const afterStart = await observedState(id);
  expect(afterStart).toEqual(beforeStart);
  expect(afterStart.maintenanceWorkOrder).toMatchObject({ status: 'open', started_at: null, logs: [] });

  await startDialog.getByRole('button', { name: 'Cancel', exact: true }).click();
  await expect(startDialog).toHaveCount(0);
  await maintenancePage.getByRole('button', { name: 'Cancel', exact: true }).click();
  const cancelDialog = maintenancePage.getByRole('dialog');
  await cancelDialog.getByLabel('Cancellation reason').fill('Mold remains assigned to active production.');
  const cancelResponse = maintenancePage.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/maintenance/work-orders/${id}/cancel`)
    && response.request().method() === 'PATCH',
  );
  await cancelDialog.getByRole('button', { name: 'Cancel work order', exact: true }).click();
  const cancelled = await cancelResponse;
  expect(cancelled.status(), await cancelled.text()).toBe(200);

  const afterCancel = await observedState(id);
  expect(afterCancel.maintenanceWorkOrder).toMatchObject({
    status: 'cancelled',
    started_at: null,
    remarks: 'Mold remains assigned to active production.',
    logs: [expect.objectContaining({ description: 'Cancelled: Mold remains assigned to active production.' })],
  });
  expect(afterCancel.mold).toEqual(beforeStart.mold);
  expect(afterCancel.moldHistory).toEqual(beforeStart.moldHistory);
  expect(afterCancel.machine).toEqual(beforeStart.machine);
  expect(afterCancel.productionWorkOrder).toEqual(beforeStart.productionWorkOrder);
  expect(afterCancel.audit.length).toBeGreaterThan(beforeStart.audit.length);
  expect(afterCancel.audit).toEqual(expect.arrayContaining([
    expect.objectContaining({
      action: 'updated',
      new_values: expect.objectContaining({ status: 'cancelled' }),
      user: expect.objectContaining({
        email: 'maintenance@ogami.test',
        role: expect.objectContaining({ slug: 'maintenance_tech' }),
      }),
    }),
  ]));
  await expect(maintenancePage.getByRole('button', { name: 'Start', exact: true })).toHaveCount(0);
  await expect(maintenancePage.getByRole('button', { name: 'Complete', exact: true })).toHaveCount(0);

  await network.waitForApiIdle();
  expect(network.failedRequests).toEqual([
    expect.objectContaining({
      url: expect.stringContaining(`/api/v1/maintenance/work-orders/${id}/start`),
      method: 'PATCH',
      status: 422,
    }),
  ]);
  expect(network.requestFailures).toEqual([]);
  expect(network.errors.filter((error) => !/422/.test(error))).toEqual([]);
});

test('successful maintenance start closes its dialog after the server confirms', async () => {
  await maintenancePage.goto('/maintenance/work-orders/create', { waitUntil: 'domcontentloaded' });
  await maintenancePage.locator('select[name="maintainable_type"]').selectOption('machine');

  const targetSearch = maintenancePage.getByRole('combobox', { name: 'Target', exact: true });
  const targetResponse = maintenancePage.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname === '/api/v1/maintenance/target-options'
      && url.searchParams.get('type') === 'machine'
      && url.searchParams.get('search') === fixture.maintenance_machine_code;
  });
  await targetSearch.fill(fixture.maintenance_machine_code);
  expect((await targetResponse).status()).toBe(200);
  await maintenancePage.getByRole('option', {
    name: `${fixture.maintenance_machine_code} - ${fixture.maintenance_machine_name}`,
    exact: true,
  }).click();
  await maintenancePage.locator('select[name="type"]').selectOption('corrective');
  await maintenancePage.locator('select[name="priority"]').selectOption('high');
  await maintenancePage.getByLabel('Description').fill(`Successful maintenance start ${tag}`);

  const createResponse = maintenancePage.waitForResponse((response) =>
    response.url().endsWith('/api/v1/maintenance/work-orders')
    && response.request().method() === 'POST',
  );
  await maintenancePage.getByRole('button', { name: 'Create work order', exact: true }).click();
  const createdResponse = await createResponse;
  const createdBody = await createdResponse.json() as Envelope<Record<string, unknown>>;
  expect(createdResponse.status(), JSON.stringify(createdBody)).toBe(201);
  const created = createdBody.data;
  const id = String(created.id);
  expect(created).toMatchObject({
    maintainable_type: 'machine',
    maintainable: { id: fixture.maintenance_machine, code: fixture.maintenance_machine_code },
    status: 'open',
    started_at: null,
    logs: [],
  });

  const beforeStart = await observedState(id, fixture.maintenance_machine);
  expect(beforeStart.machine).toMatchObject({ id: fixture.maintenance_machine, status: 'idle' });
  const auditBeforeStart = beforeStart.audit;

  await maintenancePage.getByRole('button', { name: 'Start', exact: true }).click();
  const startDialog = maintenancePage.getByRole('dialog');
  const detailRefresh = maintenancePage.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/maintenance/work-orders/${id}`)
    && response.request().method() === 'GET',
  );
  const startResponse = maintenancePage.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/maintenance/work-orders/${id}/start`)
    && response.request().method() === 'PATCH',
  );
  await startDialog.getByRole('button', { name: 'Start', exact: true }).click();
  const started = await startResponse;
  expect(started.status(), await started.text()).toBe(200);
  await detailRefresh;

  const afterStart = await observedState(id, fixture.maintenance_machine);
  expect(afterStart.maintenanceWorkOrder).toMatchObject({ status: 'in_progress', logs: [expect.objectContaining({ description: 'Maintenance started.' })] });
  expect(afterStart.machine).toMatchObject({ id: fixture.maintenance_machine, status: 'maintenance' });
  expect(afterStart.audit.length).toBeGreaterThan(auditBeforeStart.length);
  const modalCountAfterStart = await startDialog.count();
  if (modalCountAfterStart > 0) {
    await startDialog.getByRole('button', { name: 'Cancel', exact: true }).click();
  }

  await expect(maintenancePage.getByRole('button', { name: 'Complete', exact: true })).toBeVisible();
  await maintenancePage.getByRole('button', { name: 'Complete', exact: true }).click();
  const completeDialog = maintenancePage.getByRole('dialog');
  await completeDialog.getByLabel('Remarks (optional)').fill('Maintenance work completed and checked.');
  const completeResponse = maintenancePage.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/maintenance/work-orders/${id}/complete`)
    && response.request().method() === 'PATCH',
  );
  await completeDialog.getByRole('button', { name: 'Confirm complete', exact: true }).click();
  const completed = await completeResponse;
  expect(completed.status(), await completed.text()).toBe(200);

  const afterComplete = await observedState(id, fixture.maintenance_machine);
  expect(afterComplete.maintenanceWorkOrder).toMatchObject({ status: 'completed', remarks: 'Maintenance work completed and checked.' });
  expect(afterComplete.machine).toMatchObject({ id: fixture.maintenance_machine, status: 'idle' });
  expect(afterComplete.audit.length).toBeGreaterThan(afterStart.audit.length);
  await network.waitForApiIdle();
  expect(network.failedRequests).toHaveLength(1);
  expect(network.failedRequests[0]).toMatchObject({ method: 'PATCH', status: 422 });
  expect(network.failedRequests[0].url).toMatch(/\/api\/v1\/maintenance\/work-orders\/[^/]+\/start$/);
  expect(network.requestFailures).toEqual([]);
  expect(network.errors.filter((error) => !/422/.test(error))).toEqual([]);
  expect(modalCountAfterStart).toBe(0);
});
