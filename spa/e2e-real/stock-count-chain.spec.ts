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

type User = { id: string; name: string; email: string; permissions: string[] };
type Warehouse = { id: string; zones: Array<{ id: string; locations: Array<{ id: string }> }> };
type WarehouseTree = {
  id: string;
  is_active: boolean;
  zones: Array<{ id: string; code: string; locations: Array<{ id: string; code: string }> }>;
};
type StockLevel = {
  item: { id: string; code: string };
  location: { id: string; full_code: string };
  quantity: string;
  weighted_avg_cost: string;
};
type CountItem = {
  id: string;
  item: { id: string; code: string };
  location: { id: string; full_code: string };
  system_quantity: string;
  counted_quantity: string | null;
  variance: string;
  status: string;
  counted_by: User | null;
  verified_by: User | null;
};
type CountSession = {
  id: string;
  session_number: string;
  title: string;
  status: string;
  frozen_at: string | null;
  completed_at: string | null;
  created_by: User | null;
  approved_by: User | null;
  items: CountItem[];
};
type AuditRow = {
  action: string;
  user: { email: string } | null;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
};

async function readData<T>(page: Page, path: string): Promise<T> {
  const response = await page.request.get(`${baseUrl()}${path}`, { headers: statefulHeaders() });
  const body = await response.text();
  expect(response.status(), `${path}: ${body}`).toBe(200);
  return (JSON.parse(body) as { data: T }).data;
}

async function readSession(page: Page, id: string): Promise<CountSession> {
  return readData<CountSession>(page, `/api/v1/inventory/stock-counts/${id}`);
}

async function readAudit(page: Page, model: string, id: string): Promise<AuditRow[]> {
  return readData<AuditRow[]>(
    page,
    `/api/v1/admin/audit-logs/entity?model_type=${model}&model_id=${encodeURIComponent(id)}&per_page=100`,
  );
}

async function csrfHeaders(page: Page): Promise<Record<string, string>> {
  const cookie = (await page.context().cookies(baseUrl())).find(({ name }) => name === 'XSRF-TOKEN');
  expect(cookie, 'browser holds the Sanctum CSRF cookie').toBeTruthy();
  return { ...statefulHeaders(), 'X-XSRF-TOKEN': decodeURIComponent(cookie!.value) };
}

test('Warehouse staff walk stock count through a distinct checker', async ({ browser }) => {
  const warehouse = ROLES.find(({ slug }) => slug === 'warehouse_staff')!;
  const admin = ROLES.find(({ slug }) => slug === 'system_admin')!;
  const contexts = await Promise.all(Array.from({ length: 3 }, () => browser.newContext()));
  const [maker, checker, auditor] = await Promise.all(contexts.map((context) => context.newPage()));
  const makerNetwork = captureNetwork(maker);
  const checkerNetwork = captureNetwork(checker);
  const title = `E2E stock count ${randomUUID()}`;
  let createdSessionId: string | null = null;

  try {
    await Promise.all([
      loginAs(maker, warehouse.email, warehouse.password),
      loginAs(checker, 'warehouse-checker@ogami.test', 'password'),
      loginAs(auditor, admin.email, admin.password),
    ]);
    const makerUser = await readData<User>(maker, '/api/v1/auth/user');
    const checkerUser = await readData<User>(checker, '/api/v1/auth/user');
    expect(makerUser.permissions).toContain('inventory.stock_count.manage');
    expect(checkerUser.permissions).toContain('inventory.stock_count.manage');
    expect(checkerUser.id).not.toBe(makerUser.id);

    const priorSessions = await readData<Array<Pick<CountSession, 'id' | 'title' | 'status'>>>(
      maker,
      '/api/v1/inventory/stock-counts',
    );
    for (const prior of priorSessions.filter(({ title: sessionTitle, status }) =>
      sessionTitle.startsWith('E2E stock count') && ['draft', 'in_progress'].includes(status),
    )) {
      const cancelled = await maker.request.delete(
        `${baseUrl()}/api/v1/inventory/stock-counts/${prior.id}`,
        { headers: await csrfHeaders(maker) },
      );
      expect(cancelled.status()).toBe(204);
      expect((await readSession(maker, prior.id)).status).toBe('cancelled');
    }

    const warehouses = await readData<Warehouse[]>(maker, '/api/v1/inventory/warehouse-map');
    const levels = await readData<StockLevel[]>(maker, '/api/v1/inventory/stock-levels?per_page=200');
    const zoneByLocation = new Map<string, string>();
    for (const currentWarehouse of warehouses) {
      for (const zone of currentWarehouse.zones) {
        for (const location of zone.locations) zoneByLocation.set(location.id, zone.id);
      }
    }
    const countsByZone = new Map<string, number>();
    for (const level of levels) {
      const zoneId = zoneByLocation.get(level.location.id);
      if (zoneId) countsByZone.set(zoneId, (countsByZone.get(zoneId) ?? 0) + 1);
    }
    const target = [...countsByZone.entries()].sort((a, b) => a[1] - b[1])[0];
    expect(target, 'the test database has a stock level in an active warehouse zone').toBeTruthy();
    const [zoneId] = target!;
    const targetWarehouse = warehouses.find((currentWarehouse) =>
      currentWarehouse.zones.some((zone) => zone.id === zoneId),
    );
    expect(targetWarehouse).toBeTruthy();

    await maker.goto('/inventory/stock-count');
    await expect(maker.getByRole('heading', { name: 'Warehouse Map' })).toBeVisible();
    await expect(maker.getByRole('button', { name: 'New session', exact: true })).toBeVisible();
    await maker.getByRole('button', { name: 'New session', exact: true }).click();
    const createDialog = maker.getByRole('dialog', { name: 'New count session' });
    await createDialog.getByLabel('Title').fill(title);
    await createDialog.getByLabel('Scope').selectOption('zone');
    await createDialog.getByLabel('Warehouse').selectOption(targetWarehouse!.id);
    await createDialog.getByLabel('Zone').selectOption(zoneId);

    const createResponsePromise = maker.waitForResponse((response) =>
      new URL(response.url()).pathname === '/api/v1/inventory/stock-counts'
      && response.request().method() === 'POST',
    );
    await createDialog.getByRole('button', { name: 'Create session', exact: true }).click();
    const createResponse = await createResponsePromise;
    expect(createResponse.status()).toBe(201);
    const created = (await createResponse.json() as { data: CountSession }).data;
    createdSessionId = created.id;
    expect(created.status).toBe('draft');

    let session = await readSession(maker, created.id);
    expect(session).toMatchObject({ id: created.id, title, status: 'draft', created_by: { id: makerUser.id } });
    expect(session.items.length).toBeGreaterThan(0);
    expect(session.items.every((item) => item.status === 'pending')).toBe(true);
    expect(await readAudit(auditor, 'StockCountSession', created.id)).toEqual(expect.arrayContaining([
      expect.objectContaining({
        action: 'created',
        user: expect.objectContaining({ email: warehouse.email }),
      }),
    ]));

    await maker.getByRole('button', { name: 'Start count', exact: true }).click();
    const startDialog = maker.getByRole('dialog', { name: 'Start count session?' });
    const startResponsePromise = maker.waitForResponse((response) =>
      new URL(response.url()).pathname === `/api/v1/inventory/stock-counts/${created.id}/start`
      && response.request().method() === 'POST',
    );
    await startDialog.getByRole('button', { name: 'Start counting', exact: true }).click();
    expect((await startResponsePromise).status()).toBe(200);
    session = await readSession(maker, created.id);
    expect(session.status).toBe('in_progress');
    expect(session.frozen_at).toBeTruthy();
    expect(session.items.every((item) => item.status === 'pending')).toBe(true);

    for (const countItem of session.items) {
      const row = maker.getByRole('row')
        .filter({ hasText: countItem.item.code })
        .filter({ hasText: countItem.location.full_code });
      await row.getByRole('button', { name: 'Count', exact: true }).click();
      const countDialog = maker.getByRole('dialog', { name: `Count — ${countItem.location.full_code}` });
      await countDialog.getByLabel('Counted quantity').fill(countItem.system_quantity);
      const recordResponsePromise = maker.waitForResponse((response) =>
        new URL(response.url()).pathname === `/api/v1/inventory/stock-counts/items/${countItem.id}/count`
        && response.request().method() === 'POST',
      );
      await countDialog.getByRole('button', { name: 'Record', exact: true }).click();
      expect((await recordResponsePromise).status()).toBe(200);

      session = await readSession(maker, created.id);
      const saved = session.items.find(({ id }) => id === countItem.id)!;
      expect(saved).toMatchObject({
        status: 'counted',
        counted_quantity: countItem.system_quantity,
        variance: '0.000',
        counted_by: { id: makerUser.id },
      });
      expect(session.items.filter(({ status }) => status === 'pending')).toHaveLength(
        session.items.length - session.items.filter(({ status }) => status === 'counted').length,
      );
      expect(await readAudit(auditor, 'StockCountItem', countItem.id)).toEqual(expect.arrayContaining([
        expect.objectContaining({
          action: 'updated',
          user: expect.objectContaining({ email: warehouse.email }),
          old_values: expect.objectContaining({ status: 'pending' }),
          new_values: expect.objectContaining({ status: 'counted', counted_quantity: countItem.system_quantity }),
        }),
      ]));
    }

    const beforeForbiddenComplete = await readSession(maker, created.id);
    const selfComplete = await maker.request.post(
      `${baseUrl()}/api/v1/inventory/stock-counts/${created.id}/complete`,
      { headers: await csrfHeaders(maker) },
    );
    expect(selfComplete.status()).toBe(422);
    expect((await selfComplete.json() as { message: string }).message).toContain('different user');
    expect(await readSession(maker, created.id)).toEqual(beforeForbiddenComplete);
    await expect(maker.getByRole('button', { name: 'Complete session', exact: true })).toHaveCount(0);

    await checker.goto('/inventory/stock-count');
    await expect(checker.getByRole('button').filter({ hasText: created.session_number })).toBeVisible();
    await checker.getByRole('button').filter({ hasText: created.session_number }).click();
    await expect(checker.getByRole('button', { name: 'Complete session', exact: true })).toBeVisible();
    await checker.getByRole('button', { name: 'Complete session', exact: true }).click();
    const completeDialog = checker.getByRole('dialog', { name: 'Complete count session?' });
    const completeResponsePromise = checker.waitForResponse((response) =>
      new URL(response.url()).pathname === `/api/v1/inventory/stock-counts/${created.id}/complete`
      && response.request().method() === 'POST',
    );
    await completeDialog.getByRole('button', { name: 'Complete', exact: true }).click();
    expect((await completeResponsePromise).status()).toBe(200);
    const completed = await readSession(checker, created.id);
    expect(completed).toMatchObject({
      status: 'completed',
      completed_at: expect.any(String),
      approved_by: { id: checkerUser.id },
    });
    expect(completed.items.every((item) => item.status === 'counted')).toBe(true);
    expect(await readAudit(auditor, 'StockCountSession', created.id)).toEqual(expect.arrayContaining([
      expect.objectContaining({
        action: 'updated',
        user: expect.objectContaining({ email: 'warehouse-checker@ogami.test' }),
        new_values: expect.objectContaining({ status: 'completed' }),
      }),
    ]));
    expect(relevantErrors(makerNetwork)).toEqual([]);
    expect(relevantErrors(checkerNetwork)).toEqual([]);
    expect(makerNetwork.failedRequests).toEqual([]);
    expect(checkerNetwork.failedRequests).toEqual([]);
  } finally {
    if (createdSessionId) {
      const active = await readSession(maker, createdSessionId);
      if (['draft', 'in_progress'].includes(active.status)) {
        const cancelled = await maker.request.delete(
          `${baseUrl()}/api/v1/inventory/stock-counts/${createdSessionId}`,
          { headers: await csrfHeaders(maker) },
        );
        expect(cancelled.status()).toBe(204);
        expect((await readSession(maker, createdSessionId)).status).toBe('cancelled');
      }
    }
    makerNetwork.stop();
    checkerNetwork.stop();
    await Promise.all(contexts.map((context) => context.close()));
  }
});

test('A variance checker cannot recount or post their own approval', async ({ browser }) => {
  const warehouse = ROLES.find(({ slug }) => slug === 'warehouse_staff')!;
  const admin = ROLES.find(({ slug }) => slug === 'system_admin')!;
  const contexts = await Promise.all(Array.from({ length: 4 }, () => browser.newContext()));
  const [maker, checker, finisher, auditor] = await Promise.all(contexts.map((context) => context.newPage()));
  const makerNetwork = captureNetwork(maker);
  const checkerNetwork = captureNetwork(checker);
  const finisherNetwork = captureNetwork(finisher);
  const title = `E2E variance count ${randomUUID()}`;
  let createdSessionId: string | null = null;

  try {
    await Promise.all([
      loginAs(maker, warehouse.email, warehouse.password),
      loginAs(checker, 'warehouse-checker@ogami.test', 'password'),
      loginAs(finisher, 'warehouse-finisher@ogami.test', 'password'),
      loginAs(auditor, admin.email, admin.password),
    ]);
    const [makerUser, checkerUser, finisherUser] = await Promise.all([
      readData<User>(maker, '/api/v1/auth/user'),
      readData<User>(checker, '/api/v1/auth/user'),
      readData<User>(finisher, '/api/v1/auth/user'),
    ]);
    expect(new Set([makerUser.id, checkerUser.id, finisherUser.id]).size).toBe(3);
    expect(checkerUser.role.slug).toBe('warehouse_staff');
    expect(finisherUser.role.slug).toBe('warehouse_staff');

    const priorSessions = await readData<Array<Pick<CountSession, 'id' | 'title' | 'status'>>>(
      maker,
      '/api/v1/inventory/stock-counts',
    );
    for (const prior of priorSessions.filter(({ title: sessionTitle, status }) =>
      sessionTitle.startsWith('E2E variance count') && ['draft', 'in_progress'].includes(status),
    )) {
      const cancelled = await maker.request.delete(
        `${baseUrl()}/api/v1/inventory/stock-counts/${prior.id}`,
        { headers: await csrfHeaders(maker) },
      );
      expect(cancelled.status()).toBe(204);
      expect((await readSession(maker, prior.id)).status).toBe('cancelled');
    }

    const [warehouses, levels, options] = await Promise.all([
      readData<Warehouse[]>(maker, '/api/v1/inventory/warehouse-map'),
      readData<StockLevel[]>(maker, '/api/v1/inventory/stock-levels?per_page=200'),
      readData<{ variance_tolerance_pct: number }>(maker, '/api/v1/inventory/stock-counts/options'),
    ]);
    const zoneByLocation = new Map<string, string>();
    for (const currentWarehouse of warehouses) {
      for (const zone of currentWarehouse.zones) {
        for (const location of zone.locations) zoneByLocation.set(location.id, zone.id);
      }
    }
    const levelsByZone = new Map<string, StockLevel[]>();
    for (const level of levels) {
      const zoneId = zoneByLocation.get(level.location.id);
      if (zoneId) levelsByZone.set(zoneId, [...(levelsByZone.get(zoneId) ?? []), level]);
    }
    const target = [...levelsByZone.entries()].find(([, zoneLevels]) =>
      zoneLevels.some(({ quantity }) => Number(quantity) > 0),
    );
    expect(target, 'the test database has positive stock in an active warehouse zone').toBeTruthy();
    const [zoneId, zoneLevels] = target!;
    const varianceLevel = zoneLevels.find(({ quantity }) => Number(quantity) > 0)!;
    const targetWarehouse = warehouses.find((currentWarehouse) =>
      currentWarehouse.zones.some((zone) => zone.id === zoneId),
    );
    expect(targetWarehouse).toBeTruthy();

    const originalQuantity = Number(varianceLevel.quantity);
    const firstCount = (originalQuantity + Math.max(0.001, originalQuantity * (options.variance_tolerance_pct + 5) / 100)).toFixed(3);
    const recountQuantity = (Number(firstCount) + 0.125).toFixed(3);
    const movementsBefore = await readData<Array<{ id: string }>>(
      maker,
      `/api/v1/inventory/stock-movements?item_id=${encodeURIComponent(varianceLevel.item.id)}&per_page=200`,
    );

    await maker.goto('/inventory/stock-count');
    await expect(maker.getByRole('button', { name: 'New session', exact: true })).toBeVisible();
    await maker.getByRole('button', { name: 'New session', exact: true }).click();
    const createDialog = maker.getByRole('dialog', { name: 'New count session' });
    await createDialog.getByLabel('Title').fill(title);
    await createDialog.getByLabel('Scope').selectOption('zone');
    await createDialog.getByLabel('Warehouse').selectOption(targetWarehouse!.id);
    await createDialog.getByLabel('Zone').selectOption(zoneId);
    const createResponsePromise = maker.waitForResponse((response) =>
      new URL(response.url()).pathname === '/api/v1/inventory/stock-counts'
      && response.request().method() === 'POST',
    );
    await createDialog.getByRole('button', { name: 'Create session', exact: true }).click();
    const createResponse = await createResponsePromise;
    expect(createResponse.status()).toBe(201);
    const created = (await createResponse.json() as { data: CountSession }).data;
    createdSessionId = created.id;
    expect(created.status).toBe('draft');

    let session = await readSession(maker, created.id);
    expect(session).toMatchObject({ status: 'draft', created_by: { id: makerUser.id } });
    expect(session.items.length).toBeGreaterThan(0);
    const varianceLine = session.items.find(({ item, location }) =>
      item.id === varianceLevel.item.id && location.id === varianceLevel.location.id,
    );
    expect(varianceLine, 'the selected positive stock level is included in the scoped count').toBeTruthy();
    const lineId = varianceLine!.id;
    expect(session.items.every(({ status }) => status === 'pending')).toBe(true);

    await maker.getByRole('button', { name: 'Start count', exact: true }).click();
    const startDialog = maker.getByRole('dialog', { name: 'Start count session?' });
    const startResponsePromise = maker.waitForResponse((response) =>
      new URL(response.url()).pathname === `/api/v1/inventory/stock-counts/${created.id}/start`
      && response.request().method() === 'POST',
    );
    await startDialog.getByRole('button', { name: 'Start counting', exact: true }).click();
    expect((await startResponsePromise).status()).toBe(200);
    session = await readSession(maker, created.id);
    expect(session.status).toBe('in_progress');
    expect(session.items.every(({ status }) => status === 'pending')).toBe(true);

    for (const countItem of session.items) {
      const row = maker.getByRole('row')
        .filter({ hasText: countItem.item.code })
        .filter({ hasText: countItem.location.full_code });
      await row.getByRole('button', { name: 'Count', exact: true }).click();
      const countDialog = maker.getByRole('dialog', { name: `Count — ${countItem.location.full_code}` });
      await countDialog.getByLabel('Counted quantity').fill(countItem.id === lineId ? firstCount : countItem.system_quantity);
      const recordResponsePromise = maker.waitForResponse((response) =>
        new URL(response.url()).pathname === `/api/v1/inventory/stock-counts/items/${countItem.id}/count`
        && response.request().method() === 'POST',
      );
      await countDialog.getByRole('button', { name: 'Record', exact: true }).click();
      expect((await recordResponsePromise).status()).toBe(200);
      session = await readSession(maker, created.id);
      const saved = session.items.find(({ id }) => id === countItem.id)!;
      expect(saved.status).toBe('counted');
      expect(saved.counted_by?.id).toBe(makerUser.id);
      expect(saved.verified_by).toBeNull();
      if (countItem.id === lineId) {
        expect(saved.counted_quantity).toBe(firstCount);
        expect(Number(saved.variance_percent)).toBeGreaterThan(options.variance_tolerance_pct);
      } else {
        expect(saved.variance).toBe('0.000');
      }
    }

    const makerRow = maker.getByRole('row')
      .filter({ hasText: varianceLine!.item.code })
      .filter({ hasText: varianceLine!.location.full_code });
    await expect(makerRow.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
    await expect(maker.getByText('A different user must approve.', { exact: true })).toBeVisible();
    const beforeSelfApproval = await readSession(maker, created.id);
    const selfApproval = await maker.request.post(
      `${baseUrl()}/api/v1/inventory/stock-counts/items/${lineId}/approve`,
      { headers: await csrfHeaders(maker) },
    );
    expect(selfApproval.status()).toBe(422);
    expect((await readSession(maker, created.id)).items).toEqual(beforeSelfApproval.items);

    await checker.goto('/inventory/stock-count');
    await checker.getByRole('button').filter({ hasText: created.session_number }).click();
    const checkerRow = checker.getByRole('row')
      .filter({ hasText: varianceLine!.item.code })
      .filter({ hasText: varianceLine!.location.full_code });
    await checkerRow.getByRole('button', { name: 'Approve', exact: true }).click();
    const approveDialog = checker.getByRole('dialog', { name: 'Approve variance?' });
    const approveResponsePromise = checker.waitForResponse((response) =>
      new URL(response.url()).pathname === `/api/v1/inventory/stock-counts/items/${lineId}/approve`
      && response.request().method() === 'POST',
    );
    await approveDialog.getByRole('button', { name: 'Approve', exact: true }).click();
    expect((await approveResponsePromise).status()).toBe(200);
    session = await readSession(checker, created.id);
    expect(session.items.find(({ id }) => id === lineId)).toMatchObject({
      status: 'verified',
      verified_by: { id: checkerUser.id },
    });
    expect(await readAudit(auditor, 'StockCountItem', lineId)).toEqual(expect.arrayContaining([
      expect.objectContaining({
        action: 'updated',
        user: expect.objectContaining({ email: 'warehouse-checker@ogami.test' }),
        old_values: expect.objectContaining({ status: 'counted' }),
        new_values: expect.objectContaining({ status: 'verified' }),
      }),
    ]));

    await maker.goto('/inventory/stock-count');
    await maker.getByRole('button').filter({ hasText: created.session_number }).click();
    await expect(makerRow.getByRole('button', { name: 'Recount', exact: true })).toBeVisible();
    await expect(makerRow.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
    await makerRow.getByRole('button', { name: 'Recount', exact: true }).click();
    const recountDialog = maker.getByRole('dialog', { name: `Count — ${varianceLine!.location.full_code}` });
    await expect(recountDialog.getByLabel('Counted quantity')).toHaveValue(firstCount);
    await recountDialog.getByLabel('Counted quantity').fill(recountQuantity);
    const recountResponsePromise = maker.waitForResponse((response) =>
      new URL(response.url()).pathname === `/api/v1/inventory/stock-counts/items/${lineId}/count`
      && response.request().method() === 'POST',
    );
    await recountDialog.getByRole('button', { name: 'Record', exact: true }).click();
    expect((await recountResponsePromise).status()).toBe(200);
    session = await readSession(maker, created.id);
    expect(session.items.find(({ id }) => id === lineId)).toMatchObject({
      status: 'counted',
      counted_quantity: recountQuantity,
      counted_by: { id: makerUser.id },
      verified_by: null,
    });

    const movementsWhilePending = await readData<Array<{ id: string }>>(
      maker,
      `/api/v1/inventory/stock-movements?item_id=${encodeURIComponent(varianceLevel.item.id)}&per_page=200`,
    );
    expect(movementsWhilePending).toEqual(movementsBefore);
    const beforeUnapprovedCompletion = await readSession(finisher, created.id);
    const unapprovedComplete = await finisher.request.post(
      `${baseUrl()}/api/v1/inventory/stock-counts/${created.id}/complete`,
      { headers: await csrfHeaders(finisher) },
    );
    expect(unapprovedComplete.status()).toBe(422);
    expect(await readSession(finisher, created.id)).toEqual(beforeUnapprovedCompletion);

    await checker.goto('/inventory/stock-count');
    await checker.getByRole('button').filter({ hasText: created.session_number }).click();
    await checker.getByRole('row')
      .filter({ hasText: varianceLine!.item.code })
      .filter({ hasText: varianceLine!.location.full_code })
      .getByRole('button', { name: 'Approve', exact: true }).click();
    const approveAgainDialog = checker.getByRole('dialog', { name: 'Approve variance?' });
    const approveAgainResponsePromise = checker.waitForResponse((response) =>
      new URL(response.url()).pathname === `/api/v1/inventory/stock-counts/items/${lineId}/approve`
      && response.request().method() === 'POST',
    );
    await approveAgainDialog.getByRole('button', { name: 'Approve', exact: true }).click();
    expect((await approveAgainResponsePromise).status()).toBe(200);
    session = await readSession(checker, created.id);
    expect(session.items.find(({ id }) => id === lineId)).toMatchObject({
      status: 'verified',
      counted_quantity: recountQuantity,
      verified_by: { id: checkerUser.id },
    });
    await expect(checker.getByRole('button', { name: 'Complete session', exact: true })).toHaveCount(0);
    await expect(checker.getByText('A user who counted or approved a line cannot complete this count.')).toBeVisible();
    const beforeCheckerCompletion = await readSession(checker, created.id);
    const checkerComplete = await checker.request.post(
      `${baseUrl()}/api/v1/inventory/stock-counts/${created.id}/complete`,
      { headers: await csrfHeaders(checker) },
    );
    expect(checkerComplete.status()).toBe(422);
    expect(await readSession(checker, created.id)).toEqual(beforeCheckerCompletion);
    expect(await readData<Array<{ id: string }>>(
      checker,
      `/api/v1/inventory/stock-movements?item_id=${encodeURIComponent(varianceLevel.item.id)}&per_page=200`,
    )).toEqual(movementsBefore);

    await finisher.goto('/inventory/stock-count');
    await finisher.getByRole('button').filter({ hasText: created.session_number }).click();
    await expect(finisher.getByRole('button', { name: 'Complete session', exact: true })).toBeVisible();
    await finisher.getByRole('button', { name: 'Complete session', exact: true }).click();
    const completeDialog = finisher.getByRole('dialog', { name: 'Complete count session?' });
    const completeResponsePromise = finisher.waitForResponse((response) =>
      new URL(response.url()).pathname === `/api/v1/inventory/stock-counts/${created.id}/complete`
      && response.request().method() === 'POST',
    );
    await completeDialog.getByRole('button', { name: 'Complete', exact: true }).click();
    expect((await completeResponsePromise).status()).toBe(200);
    const completed = await readSession(finisher, created.id);
    expect(completed).toMatchObject({
      status: 'completed',
      completed_at: expect.any(String),
      approved_by: { id: finisherUser.id },
    });
    expect(completed.items.find(({ id }) => id === lineId)).toMatchObject({
      status: 'adjusted',
      counted_quantity: recountQuantity,
      counted_by: { id: makerUser.id },
      verified_by: { id: checkerUser.id },
    });
    const levelsAfter = await readData<StockLevel[]>(maker, '/api/v1/inventory/stock-levels?per_page=200');
    expect(Number(levelsAfter.find(({ item, location }) =>
      item.id === varianceLevel.item.id && location.id === varianceLevel.location.id,
    )?.quantity)).toBe(Number(recountQuantity));
    const movementsAfter = await readData<Array<{ id: string }>>(
      maker,
      `/api/v1/inventory/stock-movements?item_id=${encodeURIComponent(varianceLevel.item.id)}&per_page=200`,
    );
    expect(movementsAfter).toHaveLength(movementsBefore.length + 1);
    expect(await readAudit(auditor, 'StockCountSession', created.id)).toEqual(expect.arrayContaining([
      expect.objectContaining({
        action: 'updated',
        user: expect.objectContaining({ email: 'warehouse-finisher@ogami.test' }),
        new_values: expect.objectContaining({ status: 'completed' }),
      }),
    ]));
    expect(relevantErrors(makerNetwork)).toEqual([]);
    expect(relevantErrors(checkerNetwork)).toEqual([]);
    expect(relevantErrors(finisherNetwork)).toEqual([]);
    expect(makerNetwork.failedRequests).toEqual([]);
    expect(checkerNetwork.failedRequests).toEqual([]);
    expect(finisherNetwork.failedRequests).toEqual([]);
  } finally {
    if (createdSessionId) {
      const active = await readSession(maker, createdSessionId);
      if (['draft', 'in_progress'].includes(active.status)) {
        const cancelled = await maker.request.delete(
          `${baseUrl()}/api/v1/inventory/stock-counts/${createdSessionId}`,
          { headers: await csrfHeaders(maker) },
        );
        expect(cancelled.status()).toBe(204);
        expect((await readSession(maker, createdSessionId)).status).toBe('cancelled');
      }
    }
    makerNetwork.stop();
    checkerNetwork.stop();
    finisherNetwork.stop();
    await Promise.all(contexts.map((context) => context.close()));
  }
});

test('An empty stock-count draft cannot start and can be cancelled', async ({ browser }) => {
  const warehouseRole = ROLES.find(({ slug }) => slug === 'warehouse_staff')!;
  const admin = ROLES.find(({ slug }) => slug === 'system_admin')!;
  const contexts = await Promise.all(Array.from({ length: 2 }, () => browser.newContext()));
  const [maker, auditor] = await Promise.all(contexts.map((context) => context.newPage()));
  const makerNetwork = captureNetwork(maker);
  const suffix = randomUUID().replace(/-/g, '').slice(0, 8).toUpperCase();
  const zoneCode = `E${suffix}`;
  const locationCode = `E${suffix}`;
  const title = `E2E empty count ${randomUUID()}`;
  let zoneId: string | null = null;
  let locationId: string | null = null;
  let sessionId: string | null = null;

  try {
    await Promise.all([
      loginAs(maker, warehouseRole.email, warehouseRole.password),
      loginAs(auditor, admin.email, admin.password),
    ]);
    const makerUser = await readData<User>(maker, '/api/v1/auth/user');
    expect(makerUser.permissions).toContain('inventory.warehouse.manage');

    const warehouses = await readData<WarehouseTree[]>(maker, '/api/v1/inventory/warehouse');
    const warehouse = warehouses.find(({ is_active }) => is_active);
    expect(warehouse, 'an active warehouse is seeded in the test database').toBeTruthy();
    const createZone = await maker.request.post(`${baseUrl()}/api/v1/inventory/zones`, {
      headers: await csrfHeaders(maker),
      data: {
        warehouse_id: warehouse!.id,
        name: `Empty count ${suffix}`,
        code: zoneCode,
        zone_type: 'staging',
      },
    });
    expect(createZone.status()).toBe(201);
    zoneId = ((await createZone.json()) as { data: { id: string } }).data.id;
    const createLocation = await maker.request.post(`${baseUrl()}/api/v1/inventory/locations`, {
      headers: await csrfHeaders(maker),
      data: { zone_id: zoneId, code: locationCode, is_active: true, is_blocked: false },
    });
    expect(createLocation.status()).toBe(201);
    locationId = ((await createLocation.json()) as { data: { id: string } }).data.id;

    const levels = await readData<StockLevel[]>(maker, '/api/v1/inventory/stock-levels?per_page=200');
    expect(levels.some(({ location }) => location.id === locationId)).toBe(false);

    await maker.goto('/inventory/stock-count');
    await maker.getByRole('button', { name: 'New session', exact: true }).click();
    const createDialog = maker.getByRole('dialog', { name: 'New count session' });
    await createDialog.getByLabel('Title').fill(title);
    await createDialog.getByLabel('Scope').selectOption('zone');
    await createDialog.getByLabel('Warehouse').selectOption(warehouse!.id);
    await createDialog.getByLabel('Zone').selectOption(zoneId);
    const createResponsePromise = maker.waitForResponse((response) =>
      new URL(response.url()).pathname === '/api/v1/inventory/stock-counts'
      && response.request().method() === 'POST',
    );
    await createDialog.getByRole('button', { name: 'Create session', exact: true }).click();
    const createResponse = await createResponsePromise;
    expect(createResponse.status()).toBe(201);
    const created = (await createResponse.json() as { data: CountSession }).data;
    sessionId = created.id;
    expect(created.status).toBe('draft');

    const beforeStart = await readSession(maker, created.id);
    expect(beforeStart).toMatchObject({
      status: 'draft',
      frozen_at: null,
      created_by: { id: makerUser.id },
      items: [],
    });
    const auditBeforeStart = await readAudit(auditor, 'StockCountSession', created.id);
    await expect(maker.getByRole('button', { name: 'Start count', exact: true })).toBeDisabled();
    await expect(maker.getByRole('button', { name: 'Cancel', exact: true })).toBeVisible();
    await expect(maker.getByText('No stock items are in this scope. Cancel and choose a scope with stock.')).toBeVisible();

    const startResponse = await maker.request.post(
      `${baseUrl()}/api/v1/inventory/stock-counts/${created.id}/start`,
      { headers: await csrfHeaders(maker) },
    );
    expect(startResponse.status()).toBe(422);
    expect((await startResponse.json() as { message: string }).message).toBe(
      'This scope has no stock items to count. Choose a scope with stock or cancel this session.',
    );
    expect(await readSession(maker, created.id)).toEqual(beforeStart);
    expect(await readAudit(auditor, 'StockCountSession', created.id)).toEqual(auditBeforeStart);

    await maker.getByRole('button', { name: 'Cancel', exact: true }).click();
    const cancelDialog = maker.getByRole('dialog', { name: 'Cancel session?' });
    const cancelResponsePromise = maker.waitForResponse((response) =>
      new URL(response.url()).pathname === `/api/v1/inventory/stock-counts/${created.id}`
      && response.request().method() === 'DELETE',
    );
    await cancelDialog.getByRole('button', { name: 'Cancel session', exact: true }).click();
    expect((await cancelResponsePromise).status()).toBe(204);
    expect((await readSession(maker, created.id)).status).toBe('cancelled');
    expect(await readAudit(auditor, 'StockCountSession', created.id)).toEqual(expect.arrayContaining([
      expect.objectContaining({
        action: 'updated',
        user: expect.objectContaining({ email: warehouseRole.email }),
        new_values: expect.objectContaining({ status: 'cancelled' }),
      }),
    ]));
    expect(relevantErrors(makerNetwork)).toEqual([]);
    expect(makerNetwork.failedRequests).toEqual([]);
  } finally {
    if (sessionId) {
      const active = await readSession(maker, sessionId);
      if (['draft', 'in_progress'].includes(active.status)) {
        const cancelled = await maker.request.delete(
          `${baseUrl()}/api/v1/inventory/stock-counts/${sessionId}`,
          { headers: await csrfHeaders(maker) },
        );
        expect(cancelled.status()).toBe(204);
      }
    }
    if (locationId) {
      const deleted = await maker.request.delete(`${baseUrl()}/api/v1/inventory/locations/${locationId}`, {
        headers: await csrfHeaders(maker),
      });
      expect(deleted.status()).toBe(204);
    }
    if (zoneId) {
      const deleted = await maker.request.delete(`${baseUrl()}/api/v1/inventory/zones/${zoneId}`, {
        headers: await csrfHeaders(maker),
      });
      expect(deleted.status()).toBe(204);
    }
    makerNetwork.stop();
    await Promise.all(contexts.map((context) => context.close()));
  }
});
