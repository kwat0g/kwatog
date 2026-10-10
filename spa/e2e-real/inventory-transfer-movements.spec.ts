import { expect, test, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { baseUrl, captureNetwork, loginAs, relevantErrors, ROLES, statefulHeaders } from './helpers';

const inventoryTag = process.env.E2E_INVENTORY_TAG;
if (!inventoryTag || !/^[a-z0-9-]{1,6}$/.test(inventoryTag)) {
  throw new Error('Set E2E_INVENTORY_TAG to an unused 1–6 character lowercase tag.');
}

type User = { id: string; name: string; email: string; permissions: string[] };
type StockLevel = {
  item: { id: string };
  location: { id: string };
  quantity: string;
  weighted_avg_cost: string;
  total_value: string;
};
type Movement = {
  id: string;
  movement_type: string;
  quantity: string;
  unit_cost: string;
  total_cost: string;
  reference_type: string | null;
  reference_id: string | null;
};
type Adjustment = {
  id: string;
  status: string;
  item: { id: string };
  location: { id: string };
  stock_movement: Movement | null;
};
type Transfer = {
  id: string;
  transfer_number: string;
  status: string;
  created_by: { id: string } | null;
  transferred_by: { id: string } | null;
  transferred_at: string | null;
};
type AuditRow = {
  action: string;
  user: { email: string } | null;
  new_values: Record<string, unknown> | null;
};
type SettingRow = { key: string; value: unknown };

async function readData<T>(page: Page, path: string): Promise<T> {
  const response = await page.request.get(`${baseUrl()}${path}`, { headers: statefulHeaders() });
  const body = await response.text();
  expect(response.status(), `${path}: ${body}`).toBe(200);
  return (JSON.parse(body) as { data: T }).data;
}

async function readUser(page: Page): Promise<User> {
  return readData<User>(page, '/api/v1/auth/user');
}

async function readLevels(page: Page, itemId: string): Promise<StockLevel[]> {
  return readData<StockLevel[]>(
    page,
    `/api/v1/inventory/stock-levels?item_id=${encodeURIComponent(itemId)}&per_page=100`,
  );
}

async function readTransfers(page: Page, itemId: string): Promise<Movement[]> {
  return readData<Movement[]>(
    page,
    `/api/v1/inventory/stock-movements?item_id=${encodeURIComponent(itemId)}&movement_type=transfer&per_page=100`,
  );
}

async function readTransfer(page: Page, id: string): Promise<Transfer> {
  return readData<Transfer>(page, `/api/v1/inventory/transfer-orders/${id}`);
}

async function readAudit(page: Page, id: string): Promise<AuditRow[]> {
  return readData<AuditRow[]>(
    page,
    `/api/v1/admin/audit-logs/entity?model_type=TransferOrder&model_id=${encodeURIComponent(id)}&per_page=50`,
  );
}

async function readApprovalThreshold(page: Page): Promise<number> {
  const groups = await readData<Record<string, SettingRow[]>>(page, '/api/v1/admin/settings');
  const value = groups.inventory?.find(({ key }) => key === 'inventory.adjustment_approval_threshold')?.value;
  expect(typeof value).toBe('number');
  return value as number;
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

test('transfer maker cannot execute own order; checker sees its stock movement', async ({ browser }) => {
  const warehouse = ROLES.find(({ slug }) => slug === 'warehouse_staff')!;
  const finance = ROLES.find(({ slug }) => slug === 'finance_officer')!;
  const admin = ROLES.find(({ slug }) => slug === 'system_admin')!;
  const contexts = await Promise.all(Array.from({ length: 4 }, () => browser.newContext()));
  const [maker, checker, auditor, approver] = await Promise.all(contexts.map((context) => context.newPage()));
  const makerNetwork = captureNetwork(maker);
  const checkerNetwork = captureNetwork(checker);
  const approverNetwork = captureNetwork(approver);
  const reason = `E2E transfer movement ${randomUUID()}`;
  let previousThreshold: number | undefined;
  let thresholdMayHaveChanged = false;

  try {
    await loginAs(maker, warehouse.email, warehouse.password);
    await loginAs(checker, 'warehouse-checker@ogami.test', 'password');
    await loginAs(auditor, admin.email, admin.password);
    await loginAs(approver, finance.email, finance.password);
    const makerUser = await readUser(maker);
    const checkerUser = await readUser(checker);
    expect(makerUser.permissions).toContain('inventory.adjust');
    expect(checkerUser.permissions).toContain('inventory.adjust');
    expect(checkerUser.id).not.toBe(makerUser.id);
    expect((await readUser(approver)).permissions).toContain('inventory.adjust.approve');
    previousThreshold = await readApprovalThreshold(auditor);
    if (previousThreshold !== 1) {
      thresholdMayHaveChanged = true;
      const update = await auditor.request.put(
        `${baseUrl()}/api/v1/admin/settings/inventory.adjustment_approval_threshold`,
        { headers: await csrfHeaders(auditor), data: { value: 1 } },
      );
      expect(update.status()).toBe(200);
      expect(await readApprovalThreshold(auditor)).toBe(1);
    }

    await maker.goto('/inventory/stock-adjustments/create');
    await expect(maker.getByRole('heading', { name: 'Stock adjustment' })).toBeVisible();
    const itemSelect = maker.locator('select[name="item_id"]');
    const locationSelect = maker.locator('select[name="location_id"]');
    const reasonSelect = maker.locator('select[name="reason_code"]');
    const itemPicker = maker.getByRole('combobox', { name: 'Item' });
    await itemPicker.fill(`E2E-TRF-${inventoryTag}`);
    const testItemOption = maker.getByRole('option', { name: new RegExp(`^E2E-TRF-${inventoryTag} —`) });
    await expect(testItemOption).toBeVisible();
    await expect(locationSelect.locator('option').nth(1)).toBeAttached();
    await expect(reasonSelect.locator('option').first()).toBeAttached();
    await testItemOption.click();
    const testItemId = await itemSelect.inputValue();
    expect(testItemId).toBeTruthy();
    const testItem = await readData<{ unit_of_measure: string }>(maker, `/api/v1/inventory/items/${testItemId}`);
    expect(testItem.unit_of_measure.toLowerCase()).toBe('kg');
    await locationSelect.selectOption({ index: 1 });
    await maker.locator('select[name="direction"]').selectOption('in');
    await reasonSelect.selectOption({ index: 0 });
    await maker.locator('input[name="quantity"]').fill('1.125');
    await maker.locator('input[name="unit_cost"]').fill('50.01');
    await maker.locator('textarea[name="reason"]').fill(reason);

    const itemId = await itemSelect.inputValue();
    const locationId = await locationSelect.inputValue();
    const beforeReceipt = (await readLevels(maker, itemId)).find((row) => row.location.id === locationId);
    const receiptResponse = maker.waitForResponse((response) =>
      new URL(response.url()).pathname === '/api/v1/inventory/stock-adjustments'
      && response.request().method() === 'POST',
    );
    await maker.getByRole('button', { name: 'Record adjustment', exact: true }).click();
    const recordDialog = maker.getByRole('dialog', { name: 'Record this adjustment?' });
    await expect(recordDialog).toBeVisible();
    await recordDialog.getByRole('button', { name: 'Record', exact: true }).click();
    const response = await receiptResponse;
    expect(response.status()).toBe(201);
    const adjustment = (await response.json() as { data: Adjustment }).data;
    expect(adjustment).toMatchObject({ status: 'pending', stock_movement: null });
    expect((await readLevels(maker, itemId)).find((row) => row.location.id === locationId)?.quantity ?? '0.000')
      .toBe(beforeReceipt?.quantity ?? '0.000');

    await approver.goto(`/inventory/stock-adjustments?status=pending&search=${encodeURIComponent(reason)}`);
    const adjustmentRow = approver.getByRole('row').filter({ hasText: reason });
    await expect(adjustmentRow.getByRole('button', { name: 'Approve', exact: true })).toBeVisible();
    const adjustmentApproveResponse = approver.waitForResponse((candidate) =>
      new URL(candidate.url()).pathname === `/api/v1/inventory/stock-adjustments/${adjustment.id}/approve`
      && candidate.request().method() === 'PATCH',
    );
    await adjustmentRow.getByRole('button', { name: 'Approve', exact: true }).click();
    const adjustmentApproveDialog = approver.getByRole('dialog', { name: 'Approve adjustment?' });
    await expect(adjustmentApproveDialog).toBeVisible();
    await adjustmentApproveDialog.getByRole('button', { name: 'Approve', exact: true }).click();
    const adjustmentApprove = await adjustmentApproveResponse;
    expect(adjustmentApprove.status()).toBe(200);
    expect((await adjustmentApprove.json() as { data: Adjustment }).data).toMatchObject({
      status: 'approved',
      stock_movement: expect.objectContaining({ reference_type: 'stock_adjustment' }),
    });
    const sourceBeforeTransfer = (await readLevels(maker, itemId)).find((row) => row.location.id === locationId)!;
    expect(milli(sourceBeforeTransfer.quantity) - milli(beforeReceipt?.quantity ?? '0.000')).toBe(1125n);

    await maker.goto('/inventory/transfer-orders');
    await expect(maker.getByRole('heading', { name: 'Transfer Orders', exact: true })).toBeVisible();
    await maker.getByRole('button', { name: 'New transfer', exact: true }).click();
    const createDialog = maker.getByRole('dialog', { name: 'New transfer order' });
    const transferItemPicker = createDialog.getByRole('combobox', { name: 'Item' });
    await transferItemPicker.fill(`E2E-TRF-${inventoryTag}`);
    const transferItemOption = maker.getByRole('option', {
      name: new RegExp(`^E2E-TRF-${inventoryTag} —`),
    });
    await expect(transferItemOption).toBeVisible();
    await transferItemOption.click();
    const sourceSelect = createDialog.getByLabel('From location');
    await expect(sourceSelect).toBeEnabled();
    await expect.poll(() => sourceSelect.locator('option').count()).toBeGreaterThan(1);
    await sourceSelect.selectOption(locationId);
    const destinationSelect = createDialog.getByLabel('To location');
    await expect(destinationSelect).toBeEnabled();
    await expect.poll(() => destinationSelect.locator('option').count()).toBeGreaterThan(1);
    await destinationSelect.selectOption({ index: 1 });
    const destinationId = await destinationSelect.inputValue();
    await createDialog.getByLabel('Quantity').fill('1.125');
    await createDialog.getByLabel('Reason (optional)').fill(reason);

    const sourceBeforeCreate = sourceBeforeTransfer;
    const destinationBeforeCreate = (await readLevels(maker, itemId)).find((row) => row.location.id === destinationId);
    const transfersBeforeCreate = await readTransfers(maker, itemId);
    const createTransferResponse = maker.waitForResponse((candidate) =>
      new URL(candidate.url()).pathname === '/api/v1/inventory/transfer-orders'
      && candidate.request().method() === 'POST',
    );
    await createDialog.getByRole('button', { name: 'Create transfer order', exact: true }).click();
    const createdTransferResponse = await createTransferResponse;
    expect(createdTransferResponse.status()).toBe(201);
    const transfer = (await createdTransferResponse.json() as { data: Transfer }).data;
    await expect(createDialog).toBeHidden();
    await expect(maker.getByRole('button').filter({ hasText: transfer.transfer_number })).toBeVisible();
    expect(transfer).toMatchObject({
      status: 'pending',
      created_by: { id: makerUser.id },
      transferred_by: null,
      transferred_at: null,
    });
    expect(Object.keys(transfer.created_by ?? {}).sort()).toEqual(['id', 'name']);
    expect((await readLevels(maker, itemId)).find((row) => row.location.id === locationId)?.quantity).toBe(sourceBeforeCreate.quantity);
    expect((await readLevels(maker, itemId)).find((row) => row.location.id === destinationId)?.quantity ?? '0.000')
      .toBe(destinationBeforeCreate?.quantity ?? '0.000');
    const movementsAfterCreate = await readTransfers(maker, itemId);
    expect(movementsAfterCreate).toEqual(transfersBeforeCreate);
    expect(movementsAfterCreate.filter(({ reference_id }) => reference_id === transfer.id)).toHaveLength(0);
    expect(await readAudit(auditor, transfer.id)).toEqual(expect.arrayContaining([
      expect.objectContaining({
        action: 'created',
        user: expect.objectContaining({ email: warehouse.email }),
        new_values: expect.objectContaining({ status: 'pending' }),
      }),
    ]));

    const wrongActor = await maker.request.post(
      `${baseUrl()}/api/v1/inventory/transfer-orders/${transfer.id}/execute`,
      { headers: await csrfHeaders(maker) },
    );
    expect(wrongActor.status()).toBe(422);
    expect((await wrongActor.json()).message).toBe('A transfer order creator cannot execute their own transfer.');
    const [pendingAfterRefusal, movementsAfterRefusal, levelsAfterRefusal, auditAfterRefusal] = await Promise.all([
      readTransfer(maker, transfer.id),
      readTransfers(maker, itemId),
      readLevels(maker, itemId),
      readAudit(auditor, transfer.id),
    ]);
    expect(pendingAfterRefusal.status).toBe('pending');
    expect(movementsAfterRefusal.filter(({ reference_id }) => reference_id === transfer.id)).toHaveLength(0);
    expect(levelsAfterRefusal.find((row) => row.location.id === locationId)?.quantity).toBe(sourceBeforeCreate.quantity);
    expect(levelsAfterRefusal.find((row) => row.location.id === destinationId)?.quantity ?? '0.000')
      .toBe(destinationBeforeCreate?.quantity ?? '0.000');
    expect(auditAfterRefusal.filter(({ action }) => action !== 'created')).toHaveLength(0);

    const ownOrder = maker.getByRole('button').filter({ hasText: transfer.transfer_number });
    await ownOrder.click();
    await expect(maker.getByRole('status').filter({
      hasText: 'Another warehouse user must execute a transfer you created.',
    })).toBeVisible();
    await expect(maker.getByRole('button', { name: 'Execute', exact: true })).toHaveCount(0);

    await checker.goto('/inventory/transfer-orders');
    await expect(checker.getByRole('heading', { name: 'Transfer Orders', exact: true })).toBeVisible();
    await checker.getByRole('button').filter({ hasText: transfer.transfer_number }).click();
    await expect(checker.getByRole('button', { name: 'Execute', exact: true })).toBeVisible();
    const executeResponse = checker.waitForResponse((candidate) =>
      new URL(candidate.url()).pathname === `/api/v1/inventory/transfer-orders/${transfer.id}/execute`
      && candidate.request().method() === 'POST',
    );
    await checker.getByRole('button', { name: 'Execute', exact: true }).click();
    const executeDialog = checker.getByRole('dialog', { name: 'Execute transfer?' });
    await expect(executeDialog).toBeVisible();
    await executeDialog.getByRole('button', { name: 'Execute', exact: true }).click();
    expect((await executeResponse).status()).toBe(200);

    const [transferred, transferMovementsAll, levelsAfter, transferAudit] = await Promise.all([
      readTransfer(checker, transfer.id),
      readTransfers(checker, itemId),
      readLevels(checker, itemId),
      readAudit(auditor, transfer.id),
    ]);
    expect(transferred).toMatchObject({ status: 'transferred', transferred_by: { id: checkerUser.id } });
    expect(Object.keys(transferred.created_by ?? {}).sort()).toEqual(['id', 'name']);
    expect(Object.keys(transferred.transferred_by ?? {}).sort()).toEqual(['id', 'name']);
    expect(transferred.transferred_at).toBeTruthy();
    const transferMovements = transferMovementsAll.filter(({ reference_id }) => reference_id === transfer.id);
    expect(transferMovements).toHaveLength(1);
    expect(transferMovements[0]).toMatchObject({
      movement_type: 'transfer',
      reference_type: 'stock_transfer',
      quantity: '1.125',
    });
    expect(transferMovements[0].unit_cost).toBe(sourceBeforeCreate.weighted_avg_cost);
    expect(cents(transferMovements[0].total_cost)).toBe(
      (milli(transferMovements[0].quantity) * tenThousandths(transferMovements[0].unit_cost) + 50_000n) / 100_000n,
    );
    const sourceAfter = levelsAfter.find((row) => row.location.id === locationId)!;
    const destinationAfter = levelsAfter.find((row) => row.location.id === destinationId)!;
    expect(milli(sourceBeforeCreate.quantity) - milli(sourceAfter.quantity)).toBe(1125n);
    expect(milli(destinationAfter.quantity) - milli(destinationBeforeCreate?.quantity ?? '0.000')).toBe(1125n);
    const displayedValueDrift = cents(sourceAfter.total_value) + cents(destinationAfter.total_value)
      - cents(sourceBeforeCreate.total_value) - cents(destinationBeforeCreate?.total_value ?? '0.00');
    // Per-location WAC is stored to four decimals and each displayed value is rounded to cents.
    // This assertion bounds observed display-only drift; the ledger records the open precision-policy question.
    expect(displayedValueDrift >= -1n && displayedValueDrift <= 1n).toBe(true);
    expect(transferAudit).toEqual(expect.arrayContaining([
      expect.objectContaining({
        action: 'updated',
        user: expect.objectContaining({ email: 'warehouse-checker@ogami.test' }),
        new_values: expect.objectContaining({ status: 'transferred' }),
      }),
    ]));

    await checker.getByRole('button', { name: 'View movements', exact: true }).click();
    await expect(checker).toHaveURL(/\/inventory\/stock-levels\?view=movements&type=transfer$/);
    await expect(checker.getByRole('heading', { name: 'Stock movements' })).toHaveCount(1);
    await expect(checker.getByRole('heading', { name: 'Stock movements' })).toBeVisible();
    const transferMovementRow = checker.getByRole('row')
      .filter({ hasText: `E2E-TRF-${inventoryTag}` })
      .filter({ hasText: 'stock transfer' })
      .filter({ hasText: transfer.id });
    await expect(transferMovementRow).toHaveCount(1);
    await expect(transferMovementRow).toBeVisible();
    await expect(transferMovementRow).toContainText('1.125');
    expect(relevantErrors(makerNetwork)).toEqual([]);
    expect(relevantErrors(checkerNetwork)).toEqual([]);
    expect(relevantErrors(approverNetwork)).toEqual([]);
    expect(makerNetwork.failedRequests).toEqual([]);
    expect(checkerNetwork.failedRequests).toEqual([]);
    expect(approverNetwork.failedRequests).toEqual([]);
  } finally {
    if (thresholdMayHaveChanged && previousThreshold !== undefined) {
      const restore = await auditor.request.put(
        `${baseUrl()}/api/v1/admin/settings/inventory.adjustment_approval_threshold`,
        { headers: await csrfHeaders(auditor), data: { value: previousThreshold } },
      );
      expect(restore.status()).toBe(200);
      expect(await readApprovalThreshold(auditor)).toBe(previousThreshold);
    }
    makerNetwork.stop();
    checkerNetwork.stop();
    approverNetwork.stop();
    await Promise.all(contexts.map((context) => context.close()));
  }
});
