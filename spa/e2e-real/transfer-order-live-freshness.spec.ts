import { expect, test, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { baseUrl, loginAs, ROLES, statefulCsrfHeaders, statefulHeaders } from './helpers';

type ApiResponse<T> = { data: T };
type Item = { id: string; code: string; is_active: boolean };
type StockLevel = { location: { id: string; full_code: string }; quantity: string };
type StockLevelResponse = { status: number; at: number };
type Location = { id: string; is_active: boolean; is_blocked: boolean };
type Warehouse = { zones?: Array<{ locations?: Location[] }> };
type User = { id: string; permissions: string[] };
type Transfer = {
  id: string;
  transfer_number: string;
  status: string;
  available_actions: { execute: boolean; cancel: boolean };
};

type RealtimeTrace = {
  subscriptions: Set<string>;
  recordEvents: number;
  listEvents: number;
  stockEvents: number;
  recordEventAt: number;
  listEventAt: number;
  stockEventAt: number;
  lastRecordEventAt: number;
};

async function readData<T>(page: Page, path: string): Promise<T> {
  const response = await page.request.get(`${baseUrl()}${path}`, { headers: statefulHeaders() });
  expect(response.status(), `${path} returned ${response.status()}`).toBe(200);
  return (await response.json() as ApiResponse<T>).data;
}

function stockFingerprint(levels: StockLevel[]): string {
  return levels.map(({ location, quantity }) => `${location.id}:${quantity}`).sort().join('|');
}

function stockQuantityText(value: string): string {
  return new Intl.NumberFormat('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 3 }).format(Number(value));
}

function traceRealtime(page: Page, recordId: string): RealtimeTrace {
  const trace: RealtimeTrace = {
    subscriptions: new Set<string>(),
    recordEvents: 0,
    listEvents: 0,
    stockEvents: 0,
    recordEventAt: 0,
    listEventAt: 0,
    stockEventAt: 0,
    lastRecordEventAt: 0,
  };
  const recordChannel = `private-inventory.transfer-order.${recordId}`;
  const listChannel = 'private-entity.transfer_order.visible';

  page.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      const payload = typeof frame.payload === 'string' ? frame.payload : frame.payload.toString('utf8');
      try {
        const message = JSON.parse(payload) as { event?: string; channel?: string };
        if (message.event === 'pusher_internal:subscription_succeeded' && message.channel) {
          trace.subscriptions.add(message.channel);
        }
        if (message.event === 'InventoryStockChanged' && message.channel === 'private-inventory.dashboard') {
          trace.stockEvents += 1;
          trace.stockEventAt = Date.now();
          return;
        }
        if (message.event !== 'inventory.transfer-order.changed') return;
        if (message.channel === recordChannel) {
          trace.recordEvents += 1;
          trace.recordEventAt = Date.now();
          trace.lastRecordEventAt = Date.now();
        }
        if (message.channel === listChannel) {
          trace.listEvents += 1;
          trace.listEventAt = Date.now();
        }
      } catch {
        // Ignore WebSocket protocol frames that are not JSON event messages.
      }
    });
  });

  return trace;
}

test('a system-admin transfer updates a warehouse peer and its actions without reload', async ({ browser }) => {
  const makerRole = ROLES.find(({ slug }) => slug === 'warehouse_staff')!;
  const actorRole = ROLES.find(({ slug }) => slug === 'system_admin')!;
  const actorContext = await browser.newContext({ baseURL: baseUrl() });
  const makerContext = await browser.newContext({ baseURL: baseUrl() });
  await actorContext.routeWebSocket(/.*/, (socket) => socket.close({
    code: 1000,
    reason: 'The acting user must reconcile from the API response.',
  }));
  const actor = await actorContext.newPage();
  const maker = await makerContext.newPage();
  const stockPage = await makerContext.newPage();
  let transferId: string | undefined;
  let transferred = false;

  try {
    await loginAs(maker, makerRole.email, makerRole.password);
    await loginAs(actor, actorRole.email, actorRole.password);

    const [actorUser, makerUser] = await Promise.all([
      readData<User>(actor, '/api/v1/auth/user'),
      readData<User>(maker, '/api/v1/auth/user'),
    ]);
    expect(actorUser.permissions).toContain('inventory.adjust');
    expect(makerUser.permissions).toContain('inventory.adjust');
    expect(actorRole.slug).not.toBe(makerRole.slug);

    const items = await readData<Item[]>(maker, '/api/v1/inventory/items?search=E2E-ADJ-rt1030&per_page=50');
    const item = items.find(({ code, is_active }) => code === 'E2E-ADJ-rt1030' && is_active);
    expect(item, 'the dev stock fixture item exists').toBeTruthy();
    const [levels, warehouses] = await Promise.all([
      readData<StockLevel[]>(maker, `/api/v1/inventory/stock-levels?item_id=${encodeURIComponent(item!.id)}&per_page=100`),
      readData<Warehouse[]>(maker, '/api/v1/inventory/warehouse'),
    ]);
    const source = levels.find(({ quantity }) => Number(quantity) >= 1);
    expect(source, 'the fixture has transferable stock').toBeTruthy();
    const locations = warehouses.flatMap(({ zones }) => (zones ?? []).flatMap(({ locations: zoneLocations }) => zoneLocations ?? []));
    const destination = locations.find((location) =>
      location.is_active && !location.is_blocked && location.id !== source!.location.id,
    );
    expect(destination, 'an active, unblocked destination exists').toBeTruthy();

    const reason = `Transfer realtime ${randomUUID()}`;
    const createResponse = await maker.request.post(`${baseUrl()}/api/v1/inventory/transfer-orders`, {
      headers: await statefulCsrfHeaders(maker),
      data: {
        from_location_id: source!.location.id,
        to_location_id: destination!.id,
        item_id: item!.id,
        quantity: '1.000',
        reason,
      },
    });
    expect(createResponse.status(), await createResponse.text()).toBe(201);
    const created = (await createResponse.json() as ApiResponse<Transfer>).data;
    transferId = created.id;
    expect(created).toMatchObject({
      status: 'pending',
      available_actions: { execute: false, cancel: true },
    });

    const makerRealtime = traceRealtime(maker, transferId);
    const stockRealtime = traceRealtime(stockPage, transferId);
    const peerDetailReads: number[] = [];
    const peerListReads: number[] = [];
    const stockLevelResponses: StockLevelResponse[] = [];
    maker.on('response', (response) => {
      if (response.request().method() !== 'GET') return;
      const path = new URL(response.url()).pathname;
      if (path === `/api/v1/inventory/transfer-orders/${transferId}`) peerDetailReads.push(Date.now());
      if (path === '/api/v1/inventory/transfer-orders') peerListReads.push(Date.now());
    });
    stockPage.on('response', (response) => {
      if (response.request().method() !== 'GET') return;
      if (new URL(response.url()).pathname !== '/api/v1/inventory/stock-levels') return;
      stockLevelResponses.push({ status: response.status(), at: Date.now() });
    });

    await maker.goto('/inventory/transfer-orders');
    await expect(maker.getByRole('heading', { name: 'Transfer Orders', exact: true })).toBeVisible();
    const makerRow = maker.getByRole('button').filter({ hasText: created.transfer_number });
    await expect(makerRow).toBeVisible();
    await makerRow.click();
    await expect(maker.getByRole('button', { name: 'Cancel', exact: true })).toBeVisible();
    await expect(maker.getByRole('button', { name: 'Execute', exact: true })).toHaveCount(0);
    await expect.poll(() => makerRealtime.subscriptions.has(`private-inventory.transfer-order.${transferId}`), {
      timeout: 10_000,
    }).toBe(true);

    await expect.poll(() => makerRealtime.subscriptions.has('private-entity.transfer_order.visible'), {
      timeout: 10_000,
    }).toBe(true);

    await stockPage.goto('/inventory/stock-levels?search=E2E-ADJ-rt1030&per_page=50');
    await expect(stockPage.getByRole('heading', { name: 'Stock levels', exact: true })).toBeVisible();
    await expect.poll(() => stockLevelResponses.some(({ status }) => status === 200), { timeout: 10_000 }).toBe(true);
    const stockRows = stockPage.getByRole('row').filter({ hasText: item!.code });
    await expect(stockRows.first()).toBeVisible();
    const stockRowsBeforeAction = await stockRows.allInnerTexts();
    await expect.poll(() => stockRealtime.subscriptions.has('private-inventory.dashboard'), {
      timeout: 10_000,
    }).toBe(true);
    const stockResponsesBeforeAction = stockLevelResponses.length;
    const stockFingerprintBefore = stockFingerprint(levels);

    await actor.goto('/inventory/transfer-orders');
    await expect(actor.getByRole('heading', { name: 'Transfer Orders', exact: true })).toBeVisible();
    const actorRow = actor.getByRole('button').filter({ hasText: created.transfer_number });
    await expect(actorRow).toBeVisible();
    await actorRow.click();
    await expect(actor.getByRole('button', { name: 'Execute', exact: true })).toBeVisible();
    const executeResponsePromise = actor.waitForResponse((response) =>
      response.request().method() === 'POST'
      && new URL(response.url()).pathname === `/api/v1/inventory/transfer-orders/${transferId}/execute`,
    );
    await actor.getByRole('button', { name: 'Execute', exact: true }).click();
    const executeDialog = actor.getByRole('dialog', { name: 'Execute transfer?' });
    await expect(executeDialog).toBeVisible();
    await executeDialog.getByRole('button', { name: 'Execute', exact: true }).click();
    const executeResponse = await executeResponsePromise;
    const executeBody = await executeResponse.text();
    expect(executeResponse.status(), executeBody).toBe(200);
    const actorResponseAt = Date.now();
    const actorResult = (JSON.parse(executeBody) as ApiResponse<Transfer>).data;
    expect(actorResult).toMatchObject({
      status: 'transferred',
      available_actions: { execute: false, cancel: false },
    });
    transferred = true;
    await expect(actor.getByRole('region', { name: 'Transfer details' }).getByText('Transferred', { exact: true })).toBeVisible();
    await expect(actor.getByRole('button', { name: 'Execute', exact: true })).toHaveCount(0);
    await expect(actor.getByRole('button', { name: 'Cancel', exact: true })).toHaveCount(0);

    await expect.poll(() => makerRealtime.recordEvents, { timeout: 5_000 }).toBeGreaterThan(0);
    await expect.poll(() => makerRealtime.listEvents, { timeout: 5_000 }).toBeGreaterThan(0);
    await expect.poll(() => stockRealtime.stockEvents, { timeout: 5_000 }).toBeGreaterThan(0);
    await expect.poll(() => peerDetailReads.length, { timeout: 5_000 }).toBeGreaterThan(1);
    await expect.poll(() => peerListReads.length, { timeout: 5_000 }).toBeGreaterThan(1);
    await expect.poll(() => stockLevelResponses.length, { timeout: 5_000 }).toBeGreaterThan(stockResponsesBeforeAction);
    await expect.poll(() => stockLevelResponses.at(-1)?.status, { timeout: 5_000 }).toBe(200);
    await expect(maker.getByRole('button', { name: 'Execute', exact: true })).toHaveCount(0);
    await expect(makerRow.getByText('Transferred', { exact: true })).toBeVisible();
    await expect(maker.getByRole('region', { name: 'Transfer details' }).getByText('Transferred', { exact: true })).toBeVisible();
    await expect(maker.getByRole('button', { name: 'Cancel', exact: true })).toHaveCount(0);
    const stockAfter = await readData<StockLevel[]>(
      maker,
      `/api/v1/inventory/stock-levels?item_id=${encodeURIComponent(item!.id)}&per_page=100`,
    );
    expect(stockFingerprint(stockAfter)).not.toBe(stockFingerprintBefore);
    await expect.poll(
      async () => JSON.stringify(await stockRows.allInnerTexts()),
      { timeout: 5_000 },
    ).not.toBe(JSON.stringify(stockRowsBeforeAction));
    const quantityBeforeByLocation = new Map(levels.map(({ location, quantity }) => [location.id, quantity]));
    const changedStockAfter = stockAfter.filter(({ location, quantity }) =>
      quantityBeforeByLocation.get(location.id) !== quantity,
    );
    expect(changedStockAfter.length).toBeGreaterThan(0);
    for (const level of changedStockAfter) {
      const row = stockRows.filter({ hasText: level.location.full_code });
      await expect(row).toHaveCount(1);
      await expect(row.getByRole('cell').nth(2)).toContainText(stockQuantityText(level.quantity));
    }

    const peerResponseAt = Math.max(
      peerDetailReads.at(-1)!,
      peerListReads.at(-1)!,
      stockLevelResponses.at(-1)!.at,
    );
    console.info(
      `Transfer Order live sample: actor response -> Warehouse detail/list/stock refetch responses in ${peerResponseAt - actorResponseAt} ms; `
      + `events record/list/stock=${makerRealtime.recordEvents}/${makerRealtime.listEvents}/${stockRealtime.stockEvents}; `
      + `event offsets record/list/stock=${makerRealtime.recordEventAt - actorResponseAt}/${makerRealtime.listEventAt - actorResponseAt}/${stockRealtime.stockEventAt - actorResponseAt} ms; `
      + `authorized GET response offsets detail/list/stock=${peerDetailReads.at(-1)! - actorResponseAt}/${peerListReads.at(-1)! - actorResponseAt}/${stockLevelResponses.at(-1)!.at - actorResponseAt} ms; `
      + `actor API response reconciled with actor socket closed.`,
    );
  } finally {
    if (transferId && !transferred) {
      const cancel = await maker.request.delete(`${baseUrl()}/api/v1/inventory/transfer-orders/${transferId}`, {
        headers: await statefulCsrfHeaders(maker).catch(() => statefulHeaders()),
      }).catch(() => null);
      if (cancel && cancel.status() !== 204 && cancel.status() !== 409) {
        console.warn(`Test transfer cleanup returned ${cancel.status()}.`);
      }
    }
    await Promise.all([actorContext.close(), makerContext.close()]);
  }
});
