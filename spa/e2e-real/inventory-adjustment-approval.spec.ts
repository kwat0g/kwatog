import { expect, test, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import {
  baseUrl,
  captureNetwork,
  loginAs,
  relevantErrors,
  ROLES,
  statefulHeaders,
} from './helpers';

const inventoryTag = process.env.E2E_INVENTORY_TAG;
if (!inventoryTag || !/^[a-z0-9-]{1,6}$/.test(inventoryTag)) {
  throw new Error('Set E2E_INVENTORY_TAG to an unused 1–6 character lowercase tag.');
}

type User = { id: string; name: string; email: string; permissions: string[] };
type SettingRow = { key: string; value: unknown };
type WarehouseTree = {
  is_active: boolean;
  zones: Array<{ id: string; locations: Array<{ id: string; is_active: boolean }> }>;
};
type StockLevel = {
  item: { id: string };
  location: { id: string };
  quantity: string;
  weighted_avg_cost: string;
  lock_version: number;
};
type Movement = {
  id: string;
  movement_type: string;
  quantity: string;
  unit_cost: string;
  total_cost: string;
  reference_type: string | null;
  reference_id: string | null;
  gl_handoff: { status: string; journal_entry: { id: string; entry_number: string } | null };
};
type Adjustment = {
  id: string;
  direction: string;
  quantity: string;
  unit_cost: string;
  value: string;
  reason: string;
  status: string;
  item: { id: string };
  location: { id: string };
  requested_by: { id: string } | null;
  approved_by: { id: string } | null;
  approved_at: string | null;
  resolved_by: { id: string; name: string } | null;
  resolved_at: string | null;
  resolution_reason: string | null;
  stock_movement: Movement | null;
};
type AuditRow = {
  action: string;
  user: { email: string } | null;
  new_values: Record<string, unknown> | null;
};
type JournalEntry = {
  status: string;
  total_debit: string;
  total_credit: string;
  lines: Array<{ debit: string; credit: string }>;
};

async function readData<T>(page: Page, path: string): Promise<T> {
  const response = await page.request.get(`${baseUrl()}${path}`, { headers: statefulHeaders() });
  const body = await response.text();
  expect(response.status(), `${path}: ${body}`).toBe(200);
  return (JSON.parse(body) as { data: T }).data;
}

async function readUser(page: Page): Promise<User> {
  return readData<User>(page, '/api/v1/auth/user');
}

async function readApprovalThreshold(page: Page): Promise<number> {
  const groups = await readData<Record<string, SettingRow[]>>(page, '/api/v1/admin/settings');
  const value = groups.inventory?.find(({ key }) => key === 'inventory.adjustment_approval_threshold')?.value;
  expect(typeof value, 'approval threshold is present as a numeric setting').toBe('number');
  return value as number;
}

async function readAdjustments(page: Page, status: string, reason: string): Promise<Adjustment[]> {
  return readData<Adjustment[]>(
    page,
    `/api/v1/inventory/stock-adjustments?status=${status}&search=${encodeURIComponent(reason)}&per_page=100`,
  );
}

async function createPendingAdjustmentByUi(page: Page, itemCode: string, reason: string): Promise<Adjustment> {
  await page.goto('/inventory/stock-adjustments/create');
  await expect(page.getByRole('heading', { name: 'Stock adjustment' })).toBeVisible();
  const locationSelect = page.locator('select[name="location_id"]');
  const reasonSelect = page.locator('select[name="reason_code"]');
  await page.getByRole('combobox', { name: 'Item' }).fill(itemCode);
  const itemOption = page.getByRole('option', { name: new RegExp(`^${itemCode} —`) });
  await expect(itemOption).toBeVisible();
  await itemOption.click();
  await expect(locationSelect.locator('option').nth(1)).toBeAttached();
  await expect(reasonSelect.locator('option').first()).toBeAttached();
  await locationSelect.selectOption({ index: 1 });
  await page.locator('select[name="direction"]').selectOption('in');
  await reasonSelect.selectOption({ index: 0 });
  await page.locator('input[name="quantity"]').fill('1');
  await page.locator('input[name="unit_cost"]').fill('50');
  await page.locator('textarea[name="reason"]').fill(reason);

  const createResponsePromise = page.waitForResponse((response) =>
    new URL(response.url()).pathname === '/api/v1/inventory/stock-adjustments'
      && response.request().method() === 'POST',
  );
  await page.getByRole('button', { name: 'Record adjustment', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Record this adjustment?' });
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: 'Record', exact: true }).click();
  const response = await createResponsePromise;
  const body = (await response.json()) as { data: Adjustment };
  expect(response.status(), JSON.stringify(body)).toBe(201);
  await expect(page).toHaveURL(/\/inventory\/stock-levels\?view=movements$/);
  expect(body.data.status).toBe('pending');
  return body.data;
}

async function readLevels(page: Page, itemId: string): Promise<StockLevel[]> {
  return readData<StockLevel[]>(
    page,
    `/api/v1/inventory/stock-levels?item_id=${encodeURIComponent(itemId)}&per_page=200`,
  );
}

async function expectUnchangedLevel(page: Page, itemId: string, locationId: string, before: StockLevel | undefined) {
  const after = (await readLevels(page, itemId)).find((level) => level.location.id === locationId);
  if (before) {
    expect(after).toMatchObject({
      quantity: before.quantity,
      weighted_avg_cost: before.weighted_avg_cost,
      lock_version: before.lock_version,
    });
  } else {
    expect(after, 'a pending adjustment must not create a stock level').toBeUndefined();
  }
}

async function readMovements(page: Page, itemId: string): Promise<Movement[]> {
  return readData<Movement[]>(
    page,
    `/api/v1/inventory/stock-movements?item_id=${encodeURIComponent(itemId)}&reference_type=stock_adjustment&per_page=200`,
  );
}

async function auditTrail(page: Page, id: string): Promise<AuditRow[]> {
  return readData<AuditRow[]>(
    page,
    `/api/v1/admin/audit-logs/entity?model_type=StockAdjustment&model_id=${encodeURIComponent(id)}&per_page=100`,
  );
}

async function csrfHeaders(page: Page): Promise<Record<string, string>> {
  const cookie = (await page.context().cookies(baseUrl())).find(({ name }) => name === 'XSRF-TOKEN');
  expect(cookie, 'browser holds the Sanctum CSRF cookie').toBeTruthy();
  return { ...statefulHeaders(), 'X-XSRF-TOKEN': decodeURIComponent(cookie!.value) };
}

const milli = (value: string) => {
  const [whole, fraction = ''] = value.split('.');
  return BigInt(whole) * 1000n + BigInt(fraction.padEnd(3, '0').slice(0, 3));
};

const cents = (value: string) => {
  const [whole, fraction = ''] = value.split('.');
  return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0').slice(0, 2));
};

const tenThousandths = (value: string) => {
  const [whole, fraction = ''] = value.split('.');
  return BigInt(whole) * 10000n + BigInt(fraction.padEnd(4, '0').slice(0, 4));
};

const valueAtFourDecimals = (quantity: string, unitCost: string) =>
  milli(quantity) * tenThousandths(unitCost) / 1000n;

test('Finance approves a stock adjustment before stock and GL change', async ({ browser }) => {
  const finance = ROLES.find(({ slug }) => slug === 'finance_officer')!;
  const warehouse = ROLES.find(({ slug }) => slug === 'warehouse_staff')!;
  const admin = ROLES.find(({ slug }) => slug === 'system_admin')!;
  const contexts = await Promise.all(Array.from({ length: 3 }, () => browser.newContext()));
  const [maker, checker, auditor] = await Promise.all(contexts.map((context) => context.newPage()));
  const makerNetwork = captureNetwork(maker);
  const checkerNetwork = captureNetwork(checker);
  let previousThreshold: number | undefined;
  let thresholdMayHaveChanged = false;
  let createdInactiveLocationId: string | undefined;

  try {
    await loginAs(checker, finance.email, finance.password);
    await checker.goto('/inventory/stock-adjustments');
    await expect(checker.getByRole('heading', { name: 'Stock Adjustments' })).toBeVisible();
    await expect(checker.getByRole('link', { name: 'New adjustment', exact: true })).toHaveCount(0);

    await loginAs(auditor, admin.email, admin.password);
    const adminUser = await readUser(auditor);
    expect(adminUser.permissions).toContain('admin.settings.manage');
    previousThreshold = await readApprovalThreshold(auditor);
    if (previousThreshold !== 1) {
      thresholdMayHaveChanged = true;
      await auditor.goto('/admin/settings');
      await expect(auditor.getByRole('heading', { name: 'Settings & Configuration' })).toBeVisible();
      await auditor.getByRole('textbox', { name: 'Search settings' }).fill('Inventory Adjustment Approval Threshold');
      const thresholdLabel = auditor.getByText('Inventory Adjustment Approval Threshold', { exact: true });
      const thresholdRow = thresholdLabel.locator('xpath=../..');
      await auditor.getByRole('button', { name: 'Unlock to Edit' }).click();
      const thresholdInput = thresholdRow.locator('input[type="number"]');
      await expect(thresholdInput).toBeEnabled();
      const settingSave = auditor.waitForResponse((response) =>
        new URL(response.url()).pathname === '/api/v1/admin/settings/inventory.adjustment_approval_threshold'
        && response.request().method() === 'PUT',
      );
      await thresholdInput.fill('1');
      await thresholdInput.press('Tab');
      expect((await settingSave).status()).toBe(200);
      expect(await readApprovalThreshold(auditor)).toBe(1);
      const settingsAudit = await readData<AuditRow[]>(
        auditor,
        '/api/v1/admin/audit-logs?action=settings.updated&model_type=settings&per_page=100',
      );
      expect(settingsAudit).toEqual(expect.arrayContaining([
        expect.objectContaining({
          action: 'settings.updated',
          user: expect.objectContaining({ email: admin.email }),
          new_values: expect.objectContaining({ key: 'inventory.adjustment_approval_threshold', value: 1 }),
        }),
      ]));
    }

    const adminWarehouses = await readData<WarehouseTree[]>(auditor, '/api/v1/inventory/warehouse');
    const existingInactiveLocation = adminWarehouses.flatMap((warehouse) =>
      warehouse.zones.flatMap((zone) => zone.locations.map((location) => ({
        ...location,
        usable: warehouse.is_active && location.is_active,
      }))),
    ).find((location) => !location.usable);
    if (!existingInactiveLocation) {
      const zone = adminWarehouses.find((warehouse) => warehouse.is_active)?.zones[0];
      expect(zone, 'the test database has an active warehouse zone for an isolated inactive-location fixture').toBeTruthy();
      const created = await auditor.request.post(`${baseUrl()}/api/v1/inventory/locations`, {
        headers: await csrfHeaders(auditor),
        data: {
          zone_id: zone!.id,
          code: `E2E-${randomUUID().slice(0, 8).toUpperCase()}`,
          is_active: false,
        },
      });
      const createdBody = (await created.json()) as { data: { id: string } };
      expect(created.status(), JSON.stringify(createdBody)).toBe(201);
      createdInactiveLocationId = createdBody.data.id;
    }

    await loginAs(maker, warehouse.email, warehouse.password);

    const makerUser = await readUser(maker);
    const checkerUser = await readUser(checker);
    expect(makerUser.permissions).toContain('inventory.adjust');
    expect(makerUser.permissions).not.toContain('inventory.adjust.approve');
    expect(checkerUser.permissions).toContain('inventory.adjust.approve');
    const reason = `E2E stock adjustment ${randomUUID()}`;

    await maker.goto('/inventory/stock-adjustments/create');
    await expect(maker.getByRole('heading', { name: 'Stock adjustment' })).toBeVisible();
    const itemSelect = maker.locator('select[name="item_id"]');
    const locationSelect = maker.locator('select[name="location_id"]');
    const reasonSelect = maker.locator('select[name="reason_code"]');
    const warehouses = await readData<WarehouseTree[]>(maker, '/api/v1/inventory/warehouse');
    const inactiveLocation = warehouses.flatMap((warehouse) =>
      warehouse.zones.flatMap((zone) => zone.locations.map((location) => ({
        ...location,
        usable: warehouse.is_active && location.is_active,
      }))),
    ).find((location) => !location.usable);
    expect(inactiveLocation, 'the fixture exposes an inactive location for the server-side check').toBeTruthy();
    const itemPicker = maker.getByRole('combobox', { name: 'Item' });
    await itemPicker.fill(`E2E-ADJ-${inventoryTag}`);
    const testItemOption = maker.getByRole('option', { name: new RegExp(`^E2E-ADJ-${inventoryTag} —`) });
    await expect(testItemOption).toBeVisible();
    await expect(locationSelect.locator('option').nth(1)).toBeAttached();
    await expect(reasonSelect.locator('option').first()).toBeAttached();
    await testItemOption.click();
    const testItemId = await itemSelect.inputValue();
    expect(testItemId).toBeTruthy();
    await expect(locationSelect.locator(`option[value="${inactiveLocation!.id}"]`)).toHaveCount(0);
    await locationSelect.selectOption({ index: 1 });
    await maker.locator('select[name="direction"]').selectOption('in');
    await reasonSelect.selectOption({ index: 0 });
    await maker.locator('input[name="quantity"]').fill('1');
    await maker.locator('input[name="unit_cost"]').fill('50');
    await maker.locator('textarea[name="reason"]').fill(reason);

    const itemId = await itemSelect.inputValue();
    const locationId = await locationSelect.inputValue();
    const beforeLevel = (await readLevels(maker, itemId)).find((level) => level.location.id === locationId);
    const beforeMovements = await readMovements(maker, itemId);

    const inactiveReason = `E2E inactive location ${randomUUID()}`;
    const inactiveLevel = (await readLevels(maker, itemId)).find((level) => level.location.id === inactiveLocation!.id);
    const rejectedInactiveLocation = await maker.request.post(
      `${baseUrl()}/api/v1/inventory/stock-adjustments`,
      {
        headers: await csrfHeaders(maker),
        data: {
          item_id: itemId,
          location_id: inactiveLocation!.id,
          direction: 'in',
          quantity: '1',
          unit_cost: '50',
          reason: inactiveReason,
          reason_code: 'found_stock',
        },
      },
    );
    expect(rejectedInactiveLocation.status()).toBe(422);
    expect((await rejectedInactiveLocation.json()).message).toContain('missing or inactive');
    expect(await readAdjustments(maker, 'pending', inactiveReason)).toHaveLength(0);
    await expectUnchangedLevel(maker, itemId, inactiveLocation!.id, inactiveLevel);
    expect((await readMovements(maker, itemId)).map(({ id }) => id)).toEqual(beforeMovements.map(({ id }) => id));

    const beforeQuantity = beforeLevel?.quantity ?? '0.000';
    const beforeValue = beforeLevel?.weighted_avg_cost ?? '0.0000';
    const beforeVersion = beforeLevel?.lock_version ?? 0;

    const createResponsePromise = maker.waitForResponse((response) =>
      new URL(response.url()).pathname === '/api/v1/inventory/stock-adjustments'
      && response.request().method() === 'POST',
    );
    await maker.getByRole('button', { name: 'Record adjustment', exact: true }).click();
    const createDialog = maker.getByRole('dialog', { name: 'Record this adjustment?' });
    await expect(createDialog).toBeVisible();
    await createDialog.getByRole('button', { name: 'Record', exact: true }).click();
    const createResponse = await createResponsePromise;
    const createdBody = (await createResponse.json()) as { data: Adjustment };
    expect(createResponse.status()).toBe(201);
    await expect(maker).toHaveURL(/\/inventory\/stock-levels\?view=movements$/);

    const adjustmentId = createdBody.data.id;
    expect(createdBody.data).toMatchObject({
      status: 'pending',
      direction: 'in',
      quantity: '1.000',
      unit_cost: '50.0000',
      value: '50.00',
      reason,
      requested_by: { id: makerUser.id },
      approved_by: null,
      approved_at: null,
      stock_movement: null,
      item: { id: itemId },
      location: { id: locationId },
    });
    expect((await readAdjustments(maker, 'pending', reason)).map(({ id }) => id)).toEqual([adjustmentId]);
    await expectUnchangedLevel(maker, itemId, locationId, beforeLevel);
    const movementsAfterCreate = await readMovements(maker, itemId);
    expect(movementsAfterCreate).toHaveLength(beforeMovements.length);
    expect(movementsAfterCreate.filter(({ reference_id }) => reference_id === adjustmentId)).toHaveLength(0);
    expect((await auditTrail(auditor, adjustmentId)).filter((row) => row.action === 'created')).toEqual([
      expect.objectContaining({
        user: expect.objectContaining({ email: warehouse.email }),
        new_values: expect.objectContaining({ status: 'pending' }),
      }),
    ]);

    await checker.goto(`/inventory/stock-adjustments?status=pending&search=${encodeURIComponent(reason)}`);
    await expect(checker.getByText(reason, { exact: true })).toBeVisible();
    const row = checker.getByRole('row').filter({ hasText: reason });
    await expect(row.getByText('Pending')).toBeVisible();
    await expect(row.getByText('Awaiting Finance approval', { exact: true })).toBeVisible();
    await expect(row.getByText(makerUser.name, { exact: true })).toBeVisible();
    await expect(row.getByRole('button', { name: 'Approve', exact: true })).toBeVisible();
    await expect(row.getByRole('button', { name: 'Reject', exact: true })).toBeVisible();

    const blankReject = await checker.request.patch(
      `${baseUrl()}/api/v1/inventory/stock-adjustments/${adjustmentId}/reject`,
      { headers: await csrfHeaders(checker), data: { reason: '   ' } },
    );
    expect(blankReject.status()).toBe(422);
    expect((await blankReject.json()).errors).toHaveProperty('reason');
    expect((await readAdjustments(checker, 'pending', reason))[0]).toMatchObject({
      status: 'pending',
      approved_by: null,
      stock_movement: null,
    });
    await expectUnchangedLevel(maker, itemId, locationId, beforeLevel);
    expect((await readMovements(maker, itemId)).filter(({ reference_id }) => reference_id === adjustmentId)).toHaveLength(0);
    expect((await auditTrail(auditor, adjustmentId)).map(({ action }) => action)).toEqual(['created']);

    const wrongActor = await maker.request.patch(
      `${baseUrl()}/api/v1/inventory/stock-adjustments/${adjustmentId}/approve`,
      { headers: await csrfHeaders(maker) },
    );
    expect(wrongActor.status()).toBe(403);
    const wrongActorReject = await maker.request.patch(
      `${baseUrl()}/api/v1/inventory/stock-adjustments/${adjustmentId}/reject`,
      { headers: await csrfHeaders(maker), data: { reason: 'Not an authorized reviewer.' } },
    );
    expect(wrongActorReject.status()).toBe(403);
    expect((await readAdjustments(maker, 'pending', reason))[0]).toMatchObject({
      status: 'pending',
      approved_by: null,
      stock_movement: null,
    });
    await expectUnchangedLevel(maker, itemId, locationId, beforeLevel);
    const movementsAfterDeniedApprove = await readMovements(maker, itemId);
    expect(movementsAfterDeniedApprove).toHaveLength(beforeMovements.length);
    expect(movementsAfterDeniedApprove.filter(({ reference_id }) => reference_id === adjustmentId)).toHaveLength(0);

    const baseUnitBefore = await readData<{ unit_of_measure: string }>(auditor, `/api/v1/inventory/items/${itemId}`);
    expect(baseUnitBefore.unit_of_measure.toUpperCase()).toBe('PCS');
    const baseUnitError = 'Cannot change the base unit while a stock adjustment is pending.';
    await auditor.goto(`/inventory/items/${itemId}/edit`);
    await expect(auditor.getByRole('heading', { name: `Edit E2E-ADJ-${inventoryTag}` })).toBeVisible();
    const baseUnit = auditor.getByLabel('Base unit');
    await expect(baseUnit).toHaveValue('PCS');
    await baseUnit.selectOption('KG');
    const itemUpdate = auditor.waitForResponse((response) =>
      new URL(response.url()).pathname === `/api/v1/inventory/items/${itemId}`
      && response.request().method() === 'PUT',
    );
    await auditor.getByRole('button', { name: 'Save changes', exact: true }).click();
    expect((await itemUpdate).status()).toBe(422);
    await expect(auditor.getByText(baseUnitError, { exact: false })).toBeVisible();
    await expect(baseUnit).toHaveValue('KG');
    await expect(auditor).toHaveURL(new RegExp(`/inventory/items/${itemId}/edit$`));

    const directItemUpdate = await auditor.request.put(
      `${baseUrl()}/api/v1/inventory/items/${itemId}`,
      { headers: await csrfHeaders(auditor), data: { unit_of_measure: 'KG' } },
    );
    expect(directItemUpdate.status()).toBe(422);
    expect((await directItemUpdate.json()).message).toBe(baseUnitError);
    expect((await readData<{ unit_of_measure: string }>(auditor, `/api/v1/inventory/items/${itemId}`))
      .unit_of_measure.toUpperCase()).toBe('PCS');
    expect((await readAdjustments(maker, 'pending', reason))[0]).toMatchObject({
      status: 'pending',
      approved_by: null,
      stock_movement: null,
    });
    await expectUnchangedLevel(maker, itemId, locationId, beforeLevel);
    expect((await readMovements(maker, itemId)).filter(({ reference_id }) => reference_id === adjustmentId))
      .toHaveLength(0);
    expect((await auditTrail(auditor, adjustmentId)).map(({ action }) => action)).toEqual(['created']);

    const approveResponsePromise = checker.waitForResponse((response) =>
      new URL(response.url()).pathname === `/api/v1/inventory/stock-adjustments/${adjustmentId}/approve`
      && response.request().method() === 'PATCH',
    );
    await row.getByRole('button', { name: 'Approve', exact: true }).click();
    const approveDialog = checker.getByRole('dialog', { name: 'Approve adjustment?' });
    await expect(approveDialog).toBeVisible();
    await approveDialog.getByRole('button', { name: 'Approve', exact: true }).click();
    expect((await approveResponsePromise).status()).toBe(200);

    const approvedRows = await readAdjustments(checker, 'approved', reason);
    expect(approvedRows).toHaveLength(1);
    const approved = approvedRows[0];
    expect(approved).toMatchObject({
      status: 'approved',
      requested_by: { id: makerUser.id },
      approved_by: { id: checkerUser.id },
      reason,
      stock_movement: expect.objectContaining({
        movement_type: 'adjustment_in',
        quantity: '1.000',
        unit_cost: '50.0000',
        total_cost: '50.00',
        reference_type: 'stock_adjustment',
        reference_id: adjustmentId,
        gl_handoff: expect.objectContaining({ status: 'generated' }),
      }),
    });
    expect(approved.approved_at).toBeTruthy();

    await maker.goto(`/inventory/stock-adjustments?status=approved&search=${encodeURIComponent(reason)}`);
    const makerApprovedRow = maker.getByRole('row').filter({ hasText: reason });
    const approvalCell = makerApprovedRow.getByRole('cell').filter({ hasText: `Approved by ${checkerUser.name}` });
    await expect(approvalCell).toBeVisible();
    await expect(approvalCell).toContainText(/[A-Z][a-z]{2} \d{1,2}, \d{4} · \d{1,2}:\d{2} (?:AM|PM)/);

    const afterLevel = (await readLevels(maker, itemId)).find((level) => level.location.id === locationId);
    expect(afterLevel).toBeTruthy();
    expect(milli(afterLevel!.quantity) - milli(beforeQuantity)).toBe(1000n);
    const ledgerValue = valueAtFourDecimals(beforeQuantity, beforeValue)
      + valueAtFourDecimals('1.000', '50.0000');
    const wacAtSixDecimals = ledgerValue * 100000n / milli(afterLevel!.quantity);
    const expectedWac = (wacAtSixDecimals + 50n) / 100n;
    expect(tenThousandths(afterLevel!.weighted_avg_cost)).toBe(expectedWac);
    expect(afterLevel!.lock_version).toBe(beforeVersion + 1);

    const movementsAfterApprove = await readMovements(maker, itemId);
    expect(movementsAfterApprove).toHaveLength(beforeMovements.length + 1);
    const matchingMovements = movementsAfterApprove.filter(({ reference_id }) => reference_id === adjustmentId);
    expect(matchingMovements).toHaveLength(1);
    expect(matchingMovements[0]).toMatchObject({
      id: approved.stock_movement?.id,
      movement_type: 'adjustment_in',
      quantity: approved.stock_movement?.quantity,
      unit_cost: approved.stock_movement?.unit_cost,
      total_cost: approved.stock_movement?.total_cost,
    });
    const journalId = matchingMovements[0].gl_handoff.journal_entry?.id;
    expect(journalId).toBeTruthy();
    const journal = await readData<JournalEntry>(checker, `/api/v1/journal-entries/${journalId}`);
    expect(journal.status).toBe('posted');
    expect(cents(journal.total_debit)).toBe(5000n);
    expect(cents(journal.total_credit)).toBe(5000n);
    expect(journal.lines.reduce((sum, line) => sum + cents(line.debit), 0n)).toBe(5000n);
    expect(journal.lines.reduce((sum, line) => sum + cents(line.credit), 0n)).toBe(5000n);

    const approvalAudit = await auditTrail(auditor, adjustmentId);
    expect(approvalAudit).toEqual(expect.arrayContaining([
      expect.objectContaining({
        action: 'updated',
        user: expect.objectContaining({ email: finance.email }),
        new_values: expect.objectContaining({ status: 'approved' }),
      }),
    ]));
    await expect(checker.getByRole('row').filter({ hasText: reason })).toHaveCount(0);

    const repeatApprove = await checker.request.patch(
      `${baseUrl()}/api/v1/inventory/stock-adjustments/${adjustmentId}/approve`,
      { headers: await csrfHeaders(checker) },
    );
    expect(repeatApprove.status()).toBe(422);
    expect((await readAdjustments(checker, 'approved', reason))[0].stock_movement?.id).toBe(matchingMovements[0].id);
    const movementsAfterRepeat = await readMovements(maker, itemId);
    expect(movementsAfterRepeat).toHaveLength(beforeMovements.length + 1);
    expect(movementsAfterRepeat.filter(({ reference_id }) => reference_id === adjustmentId)).toHaveLength(1);
    expect((await readLevels(maker, itemId)).find((level) => level.location.id === locationId)).toMatchObject({
      quantity: afterLevel!.quantity,
      weighted_avg_cost: afterLevel!.weighted_avg_cost,
      lock_version: afterLevel!.lock_version,
    });

    const rejectionReason = `E2E rejected stock adjustment ${randomUUID()}`;
    const rejectedAdjustment = await createPendingAdjustmentByUi(maker, `E2E-ADJ-${inventoryTag}`, rejectionReason);
    expect(rejectedAdjustment.requested_by).toMatchObject({ id: makerUser.id });
    const levelBeforeReject = (await readLevels(maker, itemId)).find((level) => level.location.id === locationId);
    const movementsBeforeReject = await readMovements(maker, itemId);
    await checker.goto(`/inventory/stock-adjustments?status=pending&search=${encodeURIComponent(rejectionReason)}`);
    const rejectRow = checker.getByRole('row').filter({ hasText: rejectionReason });
    await expect(rejectRow.getByRole('button', { name: 'Reject', exact: true })).toBeVisible();
    await expect(rejectRow.getByRole('button', { name: 'Approve', exact: true })).toBeVisible();
    const rejectResponsePromise = checker.waitForResponse((response) =>
      new URL(response.url()).pathname === `/api/v1/inventory/stock-adjustments/${rejectedAdjustment.id}/reject`
        && response.request().method() === 'PATCH',
    );
    await rejectRow.getByRole('button', { name: 'Reject', exact: true }).click();
    const rejectDialog = checker.getByRole('dialog', { name: 'Reject adjustment?' });
    await expect(rejectDialog).toBeVisible();
    await rejectDialog.getByRole('textbox', { name: 'Rejection reason' }).fill('Missing recount evidence.');
    await rejectDialog.getByRole('button', { name: 'Reject adjustment', exact: true }).click();
    const rejectResponse = await rejectResponsePromise;
    expect(rejectResponse.status()).toBe(200);
    await expect(checker.getByText('Adjustment rejected. No stock movement was posted.')).toBeVisible();
    const rejectedRows = await readAdjustments(checker, 'rejected', rejectionReason);
    expect(rejectedRows).toHaveLength(1);
    expect(rejectedRows[0]).toMatchObject({
      status: 'rejected',
      requested_by: { id: makerUser.id },
      resolved_by: { id: checkerUser.id },
      resolution_reason: 'Missing recount evidence.',
      stock_movement: null,
    });
    expect(rejectedRows[0].resolved_at).toBeTruthy();
    await expectUnchangedLevel(maker, itemId, locationId, levelBeforeReject);
    expect((await readMovements(maker, itemId)).map(({ id }) => id)).toEqual(movementsBeforeReject.map(({ id }) => id));
    expect((await auditTrail(auditor, rejectedAdjustment.id))).toEqual(expect.arrayContaining([
      expect.objectContaining({
        action: 'updated',
        user: expect.objectContaining({ email: finance.email }),
        new_values: expect.objectContaining({ status: 'rejected', resolution_reason: 'Missing recount evidence.' }),
      }),
    ]));
    await expect(checker.getByRole('row').filter({ hasText: rejectionReason }).getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);

    const withdrawalReason = `E2E withdrawn stock adjustment ${randomUUID()}`;
    const withdrawnAdjustment = await createPendingAdjustmentByUi(maker, `E2E-ADJ-${inventoryTag}`, withdrawalReason);
    const levelBeforeWithdraw = (await readLevels(maker, itemId)).find((level) => level.location.id === locationId);
    const movementsBeforeWithdraw = await readMovements(maker, itemId);
    await maker.goto(`/inventory/stock-adjustments?status=pending&search=${encodeURIComponent(withdrawalReason)}`);
    const withdrawRow = maker.getByRole('row').filter({ hasText: withdrawalReason });
    await expect(withdrawRow.getByRole('button', { name: 'Withdraw', exact: true })).toBeVisible();
    await expect(withdrawRow.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
    await expect(withdrawRow.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
    const withdrawResponsePromise = maker.waitForResponse((response) =>
      new URL(response.url()).pathname === `/api/v1/inventory/stock-adjustments/${withdrawnAdjustment.id}/withdraw`
        && response.request().method() === 'PATCH',
    );
    await withdrawRow.getByRole('button', { name: 'Withdraw', exact: true }).click();
    const withdrawDialog = maker.getByRole('dialog', { name: 'Withdraw adjustment?' });
    await expect(withdrawDialog).toBeVisible();
    await expect(withdrawDialog).toContainText(`Withdraw your pending adjustment of 1 for E2E-ADJ-${inventoryTag}`);
    await withdrawDialog.getByRole('button', { name: 'Withdraw', exact: true }).click();
    const withdrawResponse = await withdrawResponsePromise;
    expect(withdrawResponse.status()).toBe(200);
    await expect(maker.getByText('Adjustment withdrawn. No stock movement was posted.')).toBeVisible();
    const withdrawnRows = await readAdjustments(maker, 'withdrawn', withdrawalReason);
    expect(withdrawnRows).toHaveLength(1);
    expect(withdrawnRows[0]).toMatchObject({
      status: 'withdrawn',
      requested_by: { id: makerUser.id },
      resolved_by: { id: makerUser.id },
      resolution_reason: null,
      stock_movement: null,
    });
    expect(withdrawnRows[0].resolved_at).toBeTruthy();
    await expectUnchangedLevel(maker, itemId, locationId, levelBeforeWithdraw);
    expect((await readMovements(maker, itemId)).map(({ id }) => id)).toEqual(movementsBeforeWithdraw.map(({ id }) => id));
    expect((await auditTrail(auditor, withdrawnAdjustment.id))).toEqual(expect.arrayContaining([
      expect.objectContaining({
        action: 'updated',
        user: expect.objectContaining({ email: warehouse.email }),
        new_values: expect.objectContaining({ status: 'withdrawn' }),
      }),
    ]));

    const repeatWithdraw = await maker.request.patch(
      `${baseUrl()}/api/v1/inventory/stock-adjustments/${withdrawnAdjustment.id}/withdraw`,
      { headers: await csrfHeaders(maker) },
    );
    expect(repeatWithdraw.status()).toBe(422);
    expect((await readAdjustments(maker, 'withdrawn', withdrawalReason))[0].id).toBe(withdrawnAdjustment.id);
    await expectUnchangedLevel(maker, itemId, locationId, levelBeforeWithdraw);
    expect((await readMovements(maker, itemId)).map(({ id }) => id)).toEqual(movementsBeforeWithdraw.map(({ id }) => id));

    expect(relevantErrors(makerNetwork)).toEqual([]);
    expect(relevantErrors(checkerNetwork)).toEqual([]);
    expect(makerNetwork.failedRequests).toEqual([]);
    expect(checkerNetwork.failedRequests).toEqual([]);
  } finally {
    if (createdInactiveLocationId) {
      const cleanup = await auditor.request.delete(
        `${baseUrl()}/api/v1/inventory/locations/${createdInactiveLocationId}`,
        { headers: await csrfHeaders(auditor) },
      );
      expect(cleanup.status()).toBe(204);
    }
    if (thresholdMayHaveChanged && previousThreshold !== undefined) {
      const restore = await auditor.request.put(
        `${baseUrl()}/api/v1/admin/settings/inventory.adjustment_approval_threshold`,
        {
          headers: await csrfHeaders(auditor),
          data: { value: previousThreshold },
        },
      );
      expect(restore.status()).toBe(200);
      expect(await readApprovalThreshold(auditor)).toBe(previousThreshold);
    }
    makerNetwork.stop();
    checkerNetwork.stop();
    await Promise.all(contexts.map((context) => context.close()));
  }
});
