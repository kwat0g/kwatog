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
