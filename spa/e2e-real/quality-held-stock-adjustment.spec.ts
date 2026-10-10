import { readFileSync } from 'node:fs';
import { expect, test, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import {
  baseUrl,
  captureNetwork,
  loginAs,
  relevantErrors,
  ROLES,
  statefulCsrfHeaders,
  statefulHeaders,
} from './helpers';

type BrowserFixture = {
  reserved_item: string;
  reservation_location: string;
  quality_hold_item: string;
  quality_hold_location: string;
  quality_hold_on_hand: string;
  quality_hold_quantity: string;
};
type StockLevel = {
  item: { id: string };
  location: { id: string };
  quantity: string;
  reserved_quantity: string;
  usable_quantity: string;
  quality_held_quantity: string;
  weighted_avg_cost: string;
  lock_version: number;
};
type Adjustment = {
  id: string;
  status: string;
  reason: string;
  stock_movement: { id: string } | null;
};
type AuditRow = { action: string; new_values: Record<string, unknown> | null };
type Movement = { id: string; movement_type: string; reference_id: string | null };

const fixturePath = process.env.E2E_INVENTORY_FIXTURE_PATH;
if (!fixturePath) throw new Error('E2E_INVENTORY_FIXTURE_PATH must point to the isolated Inventory browser fixture.');
const fixture = JSON.parse(readFileSync(fixturePath, 'utf8')) as BrowserFixture;
const qcHoldError = 'Stock adjustment would consume QC-held stock; adjust only the unheld quantity or complete its Quality disposition first.';

async function readData<T>(page: Page, path: string): Promise<T> {
  const response = await page.request.get(`${baseUrl()}${path}`, { headers: statefulHeaders() });
  const body = await response.text();
  expect(response.status(), `${path}: ${body}`).toBe(200);
  return (JSON.parse(body) as { data: T }).data;
}

async function readLevels(page: Page, itemId = fixture.quality_hold_item): Promise<StockLevel[]> {
  return readData<StockLevel[]>(
    page,
    `/api/v1/inventory/stock-levels?item_id=${encodeURIComponent(itemId)}&per_page=100`,
  );
}

async function readAdjustments(page: Page, reason: string): Promise<Adjustment[]> {
  return readData<Adjustment[]>(
    page,
    `/api/v1/inventory/stock-adjustments?search=${encodeURIComponent(reason)}&per_page=100`,
  );
}

async function readMovements(page: Page, itemId = fixture.quality_hold_item): Promise<Movement[]> {
  return readData<Movement[]>(
    page,
    `/api/v1/inventory/stock-movements?item_id=${encodeURIComponent(itemId)}&reference_type=stock_adjustment&per_page=100`,
  );
}

async function readAdjustmentAudit(page: Page): Promise<AuditRow[]> {
  return readData<AuditRow[]>(page, '/api/v1/admin/audit-logs?action=created&model_type=StockAdjustment&per_page=100');
}

test('warehouse cannot record a stock adjustment that consumes QC-held quantity', async ({ browser }) => {
  const warehouse = ROLES.find(({ slug }) => slug === 'warehouse_staff')!;
  const admin = ROLES.find(({ slug }) => slug === 'system_admin')!;
  const contexts = await Promise.all(Array.from({ length: 2 }, () => browser.newContext()));
  const [maker, auditor] = await Promise.all(contexts.map((context) => context.newPage()));
  const network = captureNetwork(maker);

  try {
    await Promise.all([
      loginAs(maker, warehouse.email, warehouse.password),
      loginAs(auditor, admin.email, admin.password),
    ]);

    const beforeLevel = (await readLevels(maker)).find((level) => level.location.id === fixture.quality_hold_location);
    expect(beforeLevel).toMatchObject({
      quantity: fixture.quality_hold_on_hand,
      reserved_quantity: '0.000',
      usable_quantity: '98.000',
      quality_held_quantity: fixture.quality_hold_quantity,
    });
    const beforeMovements = await readMovements(maker);
    const beforeAudit = await readAdjustmentAudit(auditor);
    expect(await readAdjustments(maker, 'QC held adjustment')).toHaveLength(0);

    const reason = `QC held adjustment ${randomUUID()}`;
    await maker.goto('/inventory/stock-adjustments/create');
    await expect(maker.getByRole('heading', { name: 'Stock adjustment' })).toBeVisible();
    const itemSelect = maker.locator('select[name="item_id"]');
    const locationSelect = maker.locator('select[name="location_id"]');
    const itemPicker = maker.getByRole('combobox', { name: 'Item' });
    const locationPicker = maker.getByRole('combobox', { name: 'Location' });
    await itemPicker.fill('AUD-QC-HOLD');
    const itemOption = maker.getByRole('option', { name: /AUD-QC-HOLD — Inventory audit QC-held finished good/ });
    await expect(itemOption).toBeVisible();
    await itemOption.click();
    await locationPicker.fill('AUD-QC-HOLD-BIN');
    const locationOption = maker.getByRole('option', { name: /AUD-QC-HOLD-BIN/ });
    await expect(locationOption).toBeVisible();
    await locationOption.click();
    await expect(itemSelect).toHaveValue(fixture.quality_hold_item);
    await expect(locationSelect).toHaveValue(fixture.quality_hold_location);
    await maker.locator('select[name="direction"]').selectOption('out');
    await maker.locator('select[name="reason_code"]').selectOption('damage');
    await maker.locator('input[name="quantity"]').fill('99');
    await maker.locator('textarea[name="reason"]').fill(reason);

    const createResponsePromise = maker.waitForResponse((response) =>
      new URL(response.url()).pathname === '/api/v1/inventory/stock-adjustments'
      && response.request().method() === 'POST',
    );
    await maker.getByRole('button', { name: 'Record adjustment', exact: true }).click();
    const confirm = maker.getByRole('dialog', { name: 'Record this adjustment?' });
    await expect(confirm).toBeVisible();
    await confirm.getByRole('button', { name: 'Record', exact: true }).click();
    const createResponse = await createResponsePromise;
    expect(createResponse.status()).toBe(422);
    expect((await createResponse.json()).message).toBe(qcHoldError);
    await expect(maker.getByText(qcHoldError, { exact: true })).toBeVisible();

    // A caller cannot bypass the visible guard by posting directly to the API.
    const directAttempt = await maker.request.post(`${baseUrl()}/api/v1/inventory/stock-adjustments`, {
      headers: await statefulCsrfHeaders(maker),
      data: {
        item_id: fixture.quality_hold_item,
        location_id: fixture.quality_hold_location,
        direction: 'out',
        quantity: '99.000',
        reason_code: 'damage',
        reason,
      },
    });
    expect(directAttempt.status()).toBe(422);
    expect((await directAttempt.json()).message).toBe(qcHoldError);

    expect(await readAdjustments(maker, reason)).toHaveLength(0);
    expect(await readMovements(maker)).toEqual(beforeMovements);
    expect(await readAdjustmentAudit(auditor)).toEqual(beforeAudit);
    expect((await readLevels(maker)).find((level) => level.location.id === fixture.quality_hold_location)).toEqual(beforeLevel);
    await expect(maker.locator('input[name="quantity"]')).toHaveValue('99');
    await expect(maker.locator('textarea[name="reason"]')).toHaveValue(reason);

    expect(relevantErrors(network)).toEqual([
      'Failed to load resource: the server responded with a status of 422 (Unprocessable Content)',
    ]);
    expect(network.requestFailures).toEqual([]);
    expect(network.failedRequests).toHaveLength(1);
    expect(network.failedRequests[0]).toMatchObject({ method: 'POST', status: 422 });
    expect(new URL(network.failedRequests[0].url).pathname).toBe('/api/v1/inventory/stock-adjustments');
  } finally {
    network.stop();
    await Promise.all(contexts.map((context) => context.close()));
  }
});

test('warehouse cannot queue a high-value write-off above unreserved stock', async ({ browser }) => {
  const warehouse = ROLES.find(({ slug }) => slug === 'warehouse_staff')!;
  const admin = ROLES.find(({ slug }) => slug === 'system_admin')!;
  const contexts = await Promise.all(Array.from({ length: 2 }, () => browser.newContext()));
  const [maker, auditor] = await Promise.all(contexts.map((context) => context.newPage()));
  const network = captureNetwork(maker);

  try {
    await Promise.all([
      loginAs(maker, warehouse.email, warehouse.password),
      loginAs(auditor, admin.email, admin.password),
    ]);

    const beforeLevel = (await readLevels(maker, fixture.reserved_item))
      .find((level) => level.location.id === fixture.reservation_location);
    expect(beforeLevel).toBeDefined();
    expect(Number(beforeLevel!.quantity)).toBeGreaterThanOrEqual(2);
    expect(beforeLevel!.reserved_quantity).toBe(beforeLevel!.quantity);
    expect(beforeLevel).toMatchObject({
      usable_quantity: '0.000',
      quality_held_quantity: '0.000',
    });
    expect(Number(beforeLevel!.weighted_avg_cost) * 2).toBeGreaterThan(5);
    const beforeMovements = await readMovements(maker, fixture.reserved_item);
    const beforeAudit = await readAdjustmentAudit(auditor);
    expect(await readAdjustments(maker, 'Reserved stock adjustment')).toHaveLength(0);

    const reason = `Reserved stock adjustment ${randomUUID()}`;
    await maker.goto('/inventory/stock-adjustments/create');
    await expect(maker.getByRole('heading', { name: 'Stock adjustment' })).toBeVisible();
    const itemSelect = maker.locator('select[name="item_id"]');
    const locationSelect = maker.locator('select[name="location_id"]');
    const itemPicker = maker.getByRole('combobox', { name: 'Item' });
    const locationPicker = maker.getByRole('combobox', { name: 'Location' });
    await itemPicker.fill('AUD-RESERVED');
    const itemOption = maker.getByRole('option', { name: /AUD-RESERVED — Inventory Audit Reserved Material/ });
    await expect(itemOption).toBeVisible();
    await itemOption.click();
    await locationPicker.fill('AUD-RESERVED-BIN');
    const locationOption = maker.getByRole('option', { name: /AUD-RESERVED-BIN/ });
    await expect(locationOption).toBeVisible();
    await locationOption.click();
    await expect(itemSelect).toHaveValue(fixture.reserved_item);
    await expect(locationSelect).toHaveValue(fixture.reservation_location);
    await maker.locator('select[name="direction"]').selectOption('out');
    await maker.locator('select[name="reason_code"]').selectOption('damage');
    await maker.locator('input[name="quantity"]').fill('2');
    await maker.locator('textarea[name="reason"]').fill(reason);

    const createResponsePromise = maker.waitForResponse((response) =>
      new URL(response.url()).pathname === '/api/v1/inventory/stock-adjustments'
      && response.request().method() === 'POST',
    );
    await maker.getByRole('button', { name: 'Record adjustment', exact: true }).click();
    const confirm = maker.getByRole('dialog', { name: 'Record this adjustment?' });
    await expect(confirm).toBeVisible();
    await confirm.getByRole('button', { name: 'Record', exact: true }).click();
    const createResponse = await createResponsePromise;
    expect(createResponse.status()).toBe(422);
    const createBody = await createResponse.json() as { message: string };
    expect(createBody.message).toMatch(
      /^Insufficient available stock at location \d+ for item \d+: needed 2\.000, available 0\.000\.$/,
    );
    await expect(maker.getByText(createBody.message, { exact: true })).toBeVisible();

    const directAttempt = await maker.request.post(`${baseUrl()}/api/v1/inventory/stock-adjustments`, {
      headers: await statefulCsrfHeaders(maker),
      data: {
        item_id: fixture.reserved_item,
        location_id: fixture.reservation_location,
        direction: 'out',
        quantity: '2.000',
        reason_code: 'damage',
        reason,
      },
    });
    expect(directAttempt.status()).toBe(422);
    expect((await directAttempt.json()).message).toBe(createBody.message);

    expect(await readAdjustments(maker, reason)).toHaveLength(0);
    expect(await readMovements(maker, fixture.reserved_item)).toEqual(beforeMovements);
    expect(await readAdjustmentAudit(auditor)).toEqual(beforeAudit);
    expect((await readLevels(maker, fixture.reserved_item))
      .find((level) => level.location.id === fixture.reservation_location)).toEqual(beforeLevel);
    await expect(maker.locator('input[name="quantity"]')).toHaveValue('2');
    await expect(maker.locator('textarea[name="reason"]')).toHaveValue(reason);

    expect(relevantErrors(network)).toEqual([
      'Failed to load resource: the server responded with a status of 422 (Unprocessable Content)',
    ]);
    expect(network.requestFailures).toEqual([]);
    expect(network.failedRequests).toHaveLength(1);
    expect(network.failedRequests[0]).toMatchObject({ method: 'POST', status: 422 });
    expect(new URL(network.failedRequests[0].url).pathname).toBe('/api/v1/inventory/stock-adjustments');
  } finally {
    network.stop();
    await Promise.all(contexts.map((context) => context.close()));
  }
});
