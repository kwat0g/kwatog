import { readFileSync } from 'node:fs';
import { expect, test, type Browser, type Page } from '@playwright/test';
import {
  baseUrl,
  captureNetwork,
  loginAs,
  relevantErrors,
  statefulCsrfHeaders,
  type NetworkCapture,
} from './helpers';

const BASE_URL = baseUrl();
const fixturePath = process.env.E2E_DELIVERY_PRECISION_FIXTURE_PATH;
if (!fixturePath) throw new Error('Set E2E_DELIVERY_PRECISION_FIXTURE_PATH to an isolated delivery fixture manifest.');

type Fixture = {
  tag: string;
  database: string;
  sales_order: string;
  sales_order_number: string;
  sales_order_line: string;
  inspection: string;
  item: string;
  quantity: string;
  vehicle: string;
  driver: string;
  driver_email: string;
};

type Delivery = {
  id: string;
  status: string;
  departed_at: string | null;
  driver: { id: string } | null;
  vehicle: { id: string } | null;
  has_attempt_outcome: boolean;
  attempt_outcome: {
    reason_code: string;
    lines: Array<{
      shipped_quantity: string;
      customer_received_quantity: string;
      truck_return_quantity: string;
      truck_return_damaged_quantity: string;
      unaccounted_quantity: string;
    }>;
  } | null;
  items: Array<{
    id: string;
    quantity_dispatched: string;
    stock_movements: Array<{ id: string; quantity: string; lot_number: string | null }>;
  }>;
};

const fixture = JSON.parse(readFileSync(fixturePath, 'utf8')) as Fixture;

async function apiData<T>(page: Page, path: string): Promise<T> {
  const response = await page.request.get(`${BASE_URL}/api/v1${path}`, {
    headers: await statefulCsrfHeaders(page),
  });
  expect(response.status(), `${path}: ${await response.text()}`).toBe(200);
  return (await response.json() as { data: T }).data;
}

async function readDelivery(page: Page, id: string): Promise<Delivery> {
  return apiData<Delivery>(page, `/supply-chain/deliveries/${id}`);
}

async function stockQuantity(page: Page): Promise<string> {
  const rows = await apiData<Array<{ quantity: string }>>(
    page,
    `/inventory/stock-levels?item_id=${encodeURIComponent(fixture.item)}&per_page=100`,
  );
  return rows.reduce((sum, row) => sum + Number(row.quantity), 0).toFixed(3);
}

async function advanceDriver(page: Page, id: string, label: string, status: string): Promise<void> {
  await page.goto(`/driver/${id}`);
  await page.getByRole('button', { name: new RegExp(`^Mark ${label}$`, 'i') }).click();
  const responsePromise = page.waitForResponse((response) =>
    response.url().endsWith(`/driver/deliveries/${id}/status`) && response.request().method() === 'PATCH');
  await page.getByRole('dialog').getByRole('button', { name: new RegExp(`^Mark ${label}$`, 'i') }).click();
  const response = await responsePromise;
  const body = await response.json() as { data: { status: string } };
  expect(response.status(), JSON.stringify(body)).toBe(200);
  expect(body.data.status).toBe(status);
  await expect(page.getByRole('dialog')).toHaveCount(0);
}

test('driver can report 3-decimal truck return and reconcile exact residual', async ({ browser }: { browser: Browser }) => {
  expect(fixture.database).toBe('ogami_test_audit_20261002');
  const impexContext = await browser.newContext({ baseURL: BASE_URL });
  const driverContext = await browser.newContext({ baseURL: BASE_URL });
  const warehouseContext = await browser.newContext({ baseURL: BASE_URL });
  const impex = await impexContext.newPage();
  const driver = await driverContext.newPage();
  const warehouse = await warehouseContext.newPage();
  const monitors: NetworkCapture[] = [];
  try {
    await loginAs(impex, 'impex@ogami.test', 'password');
    await loginAs(driver, fixture.driver_email, 'password');
    await loginAs(warehouse, 'warehouse@ogami.test', 'password');
    monitors.push(captureNetwork(impex), captureNetwork(driver), captureNetwork(warehouse));
    expect(await stockQuantity(warehouse)).toBe('1.000');

    await impex.goto('/supply-chain/deliveries/create');
    const order = impex.getByRole('combobox', { name: 'Sales order', exact: true });
    await order.fill(fixture.sales_order_number);
    const orderOption = impex.getByRole('option', { name: new RegExp(fixture.sales_order_number) });
    await expect(orderOption).toBeVisible();
    await orderOption.click();
    const line = impex.getByRole('combobox', { name: 'Sales order line', exact: true });
    await line.click();
    const lineOption = impex.getByRole('option', { name: new RegExp(`DP-${fixture.tag}-KG`) });
    await expect(lineOption).toBeVisible();
    await lineOption.click();
    const inspection = impex.getByRole('combobox', { name: 'Passed outgoing inspection', exact: true });
    await inspection.click();
    const inspectionOption = impex.getByRole('option', { name: new RegExp(`QC-DP-OUT-${fixture.tag}`) });
    await expect(inspectionOption).toBeVisible();
    await inspectionOption.click();
    await impex.getByLabel(/^Qty/).fill('1.00');
    const createResponse = impex.waitForResponse((response) =>
      response.url().endsWith('/api/v1/supply-chain/deliveries') && response.request().method() === 'POST');
    await impex.getByRole('button', { name: 'Create delivery', exact: true }).click();
    const created = await createResponse;
    expect(created.status(), await created.text()).toBe(201);
    const createdBody = await created.json() as { data: { id: string } };
    const id = createdBody.data.id;
    const scheduled = await readDelivery(impex, id);
    expect(scheduled).toMatchObject({ status: 'scheduled', has_attempt_outcome: false, driver: null });
    expect(scheduled.items[0]).toMatchObject({ quantity_dispatched: '1.000', stock_movements: [] });
    expect(await stockQuantity(warehouse)).toBe('1.000');

    await impex.goto(`/supply-chain/deliveries/${id}`);
    await impex.getByRole('button', { name: 'Assign driver & vehicle' }).click();
    const dialog = impex.getByRole('dialog');
    await dialog.getByLabel(/^Vehicle/).selectOption(fixture.vehicle);
    await dialog.getByLabel(/^Driver/).selectOption(fixture.driver);
    await dialog.getByLabel(/^Assignment reason/).fill('Approved load and available driver for this delivery.');
    const assignmentResponse = impex.waitForResponse((response) =>
      response.url().endsWith(`/supply-chain/deliveries/${id}/assignment`) && response.request().method() === 'PATCH');
    await dialog.getByRole('button', { name: 'Save assignment' }).click();
    const assignedResponse = await assignmentResponse;
    expect(assignedResponse.status(), await assignedResponse.text()).toBe(200);
    const assigned = await readDelivery(impex, id);
    expect(assigned).toMatchObject({ status: 'scheduled', driver: { id: fixture.driver }, vehicle: { id: fixture.vehicle } });
    expect(assigned.items[0].stock_movements).toHaveLength(0);
    expect(await stockQuantity(warehouse)).toBe('1.000');

    await advanceDriver(driver, id, 'loading', 'loading');
    const loading = await readDelivery(impex, id);
    expect(loading).toMatchObject({ status: 'loading', departed_at: null, has_attempt_outcome: false });
    expect(loading.items[0].stock_movements).toHaveLength(0);
    expect(await stockQuantity(warehouse)).toBe('1.000');
    await warehouse.goto(`/supply-chain/deliveries/${id}`);
    await expect(warehouse.getByRole('button', { name: 'Record depot count', exact: true })).toHaveCount(0);
    expect((await readDelivery(warehouse, id)).status).toBe('loading');

    await advanceDriver(driver, id, 'in transit', 'in_transit');
    const dispatched = await readDelivery(impex, id);
    expect(dispatched.status).toBe('in_transit');
    expect(dispatched.departed_at).toBeTruthy();
    expect(dispatched.items[0].stock_movements).toHaveLength(1);
    expect(dispatched.items[0].stock_movements[0].quantity).toBe('1.000');
    expect(dispatched.items[0].stock_movements[0].lot_number).toBe(`DP-LOT-${fixture.tag}`);
    expect(await stockQuantity(warehouse)).toBe('0.000');

    await impex.goto(`/supply-chain/deliveries/${id}`);
    await impex.getByRole('button', { name: 'Report delivery problem', exact: true }).click();
    const impexTruckReturn = impex.getByLabel(/^Returning on truck/);
    await impex.getByLabel(/^Received by customer/).fill('0');
    await impexTruckReturn.fill('0.999');
    expect(await impexTruckReturn.getAttribute('step')).toBe('0.001');
    await expect(impex.getByText(/^Unaccounted quantity:/)).toContainText('Unaccounted quantity: 0.001.');
    await impex.getByRole('button', { name: 'Close', exact: true }).click();

    await driver.getByRole('button', { name: 'Report delivery problem', exact: true }).click();
    await driver.getByLabel(/^What happened/).selectOption('customer_refused');
    await driver.getByLabel(/^Received by customer/).fill('0');
    const truckReturn = driver.getByLabel(/^Returning on truck/);
    await truckReturn.fill('0.999');
    await driver.getByText('Record damaged quantities (if any)', { exact: true }).click();
    const damagedOnTruck = driver.getByLabel(/^Damaged among truck returns/);
    await damagedOnTruck.fill('0.001');
    await driver.getByLabel('Additional details', { exact: true }).fill('Customer refused the load; depot count will verify the returning goods.');

    expect.soft(await truckReturn.getAttribute('step')).toBe('0.001');
    expect.soft(await damagedOnTruck.getAttribute('step')).toBe('0.001');
    expect.soft(await truckReturn.evaluate((input) => (input as HTMLInputElement).validity.stepMismatch)).toBe(false);
    await expect.soft(driver.getByText(/^Unaccounted quantity:/)).toContainText('Unaccounted quantity: 0.001.');

    if (await truckReturn.evaluate((input) => (input as HTMLInputElement).validity.valid)) {
      const outcomeResponse = driver.waitForResponse((response) =>
        response.url().endsWith(`/driver/deliveries/${id}/attempt-outcome`) && response.request().method() === 'POST');
      await driver.getByRole('button', { name: 'Save delivery outcome', exact: true }).click();
      const saved = await outcomeResponse;
      expect(saved.status(), await saved.text()).toBe(200);
      const outcome = await readDelivery(impex, id);
      expect(outcome.status).toBe('return_pending');
      expect(outcome.has_attempt_outcome).toBe(true);
      expect(outcome.attempt_outcome?.reason_code).toBe('customer_refused');
      expect(outcome.attempt_outcome?.lines).toMatchObject([{
        shipped_quantity: '1.000',
        customer_received_quantity: '0.000',
        truck_return_quantity: '0.999',
        truck_return_damaged_quantity: '0.001',
        unaccounted_quantity: '0.001',
      }]);
      expect(outcome.items[0].stock_movements).toHaveLength(1);
      expect(await stockQuantity(warehouse)).toBe('0.000');
      await warehouse.goto(`/supply-chain/deliveries/${id}`);
      await expect(warehouse.getByRole('button', { name: 'Record depot count', exact: true })).toBeVisible();
      expect((await readDelivery(warehouse, id)).status).toBe('return_pending');
    }

    for (const monitor of monitors) {
      expect(relevantErrors(monitor), 'unexpected browser console or page errors').toEqual([]);
      expect(monitor.failedRequests, 'unexpected failed API responses').toEqual([]);
      expect(monitor.requestFailures, 'unexpected failed requests').toEqual([]);
      monitor.stop();
    }
  } finally {
    await Promise.allSettled([impexContext.close(), driverContext.close(), warehouseContext.close()]);
  }
});
