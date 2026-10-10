import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { baseUrl, loginAs, statefulCsrfHeaders, statefulHeaders } from './helpers';

let context: BrowserContext;
let page: Page;
let adminContext: BrowserContext;
let admin: Page;
const errors: string[] = [];

async function readData<T>(target: Page, path: string): Promise<T> {
  const response = await target.request.get(`${baseUrl()}${path}`, { headers: statefulHeaders() });
  const body = await response.text();
  expect(response.status(), `${path}: ${body}`).toBe(200);
  return (JSON.parse(body) as { data: T }).data;
}

test.beforeAll(async ({ browser }) => {
  context = await browser.newContext({ baseURL: baseUrl() });
  adminContext = await browser.newContext({ baseURL: baseUrl() });
  page = await context.newPage();
  admin = await adminContext.newPage();
  for (const [role, target] of [['impex', page], ['admin', admin]] as const) {
    target.on('console', (message) => {
      if (message.type() === 'error') errors.push(`${role}: ${message.text()}`);
    });
    target.on('pageerror', (error) => errors.push(`${role}: ${error.message}`));
    target.on('requestfailed', (request) => {
      if (request.url().includes('/api/')) errors.push(`${role}: ${request.method()} ${request.url()}`);
    });
    target.on('response', (response) => {
      if (response.url().includes('/api/') && response.status() >= 500) {
        errors.push(`${role}: ${response.status()} ${response.url()}`);
      }
    });
  }
  await loginAs(page, 'impex@ogami.test', 'password');
  await loginAs(admin, 'admin@ogami.test', 'password');
});

test.afterAll(async () => {
  await context.close();
  await adminContext.close();
  expect([...new Set(errors)]).toEqual([]);
});

test('fleet UI leaves in-use status to delivery dispatch and API rejects direct writes', async () => {
  const suffix = Date.now().toString(36).toUpperCase();
  const plate = `AUD-${suffix}`;
  const name = `Fleet audit ${suffix}`;
  await page.goto('/supply-chain/fleet', { waitUntil: 'domcontentloaded' });
  await expect(page.getByRole('heading', { name: 'Fleet' })).toBeVisible();

  await page.getByRole('button', { name: 'New vehicle', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('Plate number').fill(plate);
  await dialog.getByLabel('Name').fill(name);
  await dialog.getByLabel('Type').selectOption('truck');
  await dialog.getByLabel('Capacity (kg)').fill('1000.00');
  await dialog.getByLabel('Status').selectOption('available');
  const createPromise = page.waitForResponse((response) =>
    response.url().endsWith('/api/v1/supply-chain/vehicles') && response.request().method() === 'POST',
  );
  await dialog.getByRole('button', { name: 'Save vehicle', exact: true }).click();
  const createdResponse = await createPromise;
  expect(createdResponse.status(), await createdResponse.text()).toBe(201);
  const vehicle = (await createdResponse.json()).data as { id: string; plate_number: string; status: string };
  expect(vehicle).toMatchObject({ plate_number: plate, status: 'available' });
  await expect(page.getByText(plate, { exact: true })).toBeVisible();

  const vehicleAuditPath = `/api/v1/admin/audit-logs/entity?model_type=Vehicle&model_id=${encodeURIComponent(vehicle.id)}&per_page=100`;
  const auditBefore = await readData<Array<{ action: string; user: { email: string } | null }>>(admin, vehicleAuditPath);
  expect(auditBefore).toEqual(expect.arrayContaining([
    expect.objectContaining({ action: 'created', user: expect.objectContaining({ email: 'impex@ogami.test' }) }),
  ]));

  const row = page.getByRole('row').filter({ hasText: plate });
  await row.getByRole('button', { name: 'Edit', exact: true }).click();
  const editDialog = page.getByRole('dialog');
  const status = editDialog.getByLabel('Status');
  await expect(status).toHaveValue('available');
  await expect(status.locator('option[value="in_use"]')).toHaveCount(0);
  await expect(editDialog.getByText('In use is set by delivery dispatch and released when the delivery ends.'))
    .toBeVisible();

  const rejected = await page.request.patch(`${baseUrl()}/api/v1/supply-chain/vehicles/${vehicle.id}`, {
    headers: await statefulCsrfHeaders(page),
    data: { status: 'in_use' },
  });
  expect(rejected.status(), await rejected.text()).toBe(422);
  expect((await rejected.json()).message).toBe('Vehicle status is managed by delivery dispatch.');
  const rows = await readData<Array<{ id: string; status: string }>>(
    page,
    `/api/v1/supply-chain/vehicles?search=${encodeURIComponent(plate)}&per_page=100`,
  );
  expect(rows).toEqual(expect.arrayContaining([expect.objectContaining({ id: vehicle.id, status: 'available' })]));
  expect(await readData<typeof auditBefore>(admin, vehicleAuditPath)).toHaveLength(auditBefore.length);
});
