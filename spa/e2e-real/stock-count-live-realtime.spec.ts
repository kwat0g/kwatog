import { expect, test, type Page, type Response, type WebSocket } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import {
  baseUrl,
  loginAs,
  ROLES,
  statefulCsrfHeaders,
  statefulHeaders,
} from './helpers';

type ApiData<T> = { data: T };
type StockLocation = { id: string; code: string; full_code: string };
type StockLevel = {
  item: { id: string; code: string; unit_of_measure: string };
  location: StockLocation;
  quantity: string;
};
type CountLine = {
  id: string;
  item: { id: string; code: string; unit_of_measure: string };
  location: StockLocation;
  system_quantity: string;
  status: string;
  available_actions: string[];
};
type CountSession = {
  id: string;
  scope: string;
  status: string;
  warehouse: { id: string } | null;
  zone: { id: string } | null;
  available_actions: string[];
  items: CountLine[];
};
type WarehouseMap = Array<{
  id: string;
  zones: Array<{ id: string; locations: Array<{ id: string }> }>;
}>;
type SessionSummary = Pick<CountSession, 'scope' | 'status' | 'warehouse' | 'zone'>;
type AuthUser = { id: string; role: { slug: string } };

async function readData<T>(page: Page, path: string): Promise<T> {
  const response = await page.request.get(`${baseUrl()}${path}`, { headers: statefulHeaders() });
  const body = await response.text();
  expect(response.status(), `${path}: ${body}`).toBe(200);
  return (JSON.parse(body) as ApiData<T>).data;
}

function trackStockCountSocket(page: Page): {
  eventCount: () => number;
  recordChannelReady: () => boolean;
  stop: () => void;
} {
  let events = 0;
  let ready = false;
  const sockets = new Set<WebSocket>();
  const onSocket = (socket: WebSocket) => {
    sockets.add(socket);
    socket.on('framereceived', ({ payload }) => {
      const raw = typeof payload === 'string' ? payload : payload.toString();
      try {
        const frame = JSON.parse(raw) as { event?: string; channel?: string };
        if (frame.event === 'inventory.stock-count.changed') events += 1;
        if (frame.event === 'pusher_internal:subscription_succeeded'
          && frame.channel?.includes('inventory.stock-count-session.')) ready = true;
      } catch {
        // Retain only event and subscription metadata, never websocket payloads.
      }
    });
  };
  page.on('websocket', onSocket);

  return {
    eventCount: () => events,
    recordChannelReady: () => ready,
    stop: () => {
      page.off('websocket', onSocket);
      sockets.clear();
    },
  };
}

test('a cross-role Stock Count viewer sees status and actions refresh without reload', async ({ browser }) => {
  const makerRole = ROLES.find(({ slug }) => slug === 'warehouse_staff')!;
  const peerRole = ROLES.find(({ slug }) => slug === 'system_admin')!;
  const makerContext = await browser.newContext();
  const peerContext = await browser.newContext();
  const maker = await makerContext.newPage();
  const peer = await peerContext.newPage();
  const peerSocket = trackStockCountSocket(peer);
  let sessionId: string | null = null;

  try {
    await loginAs(maker, makerRole.email, makerRole.password);
    await loginAs(peer, peerRole.email, peerRole.password);
    const [makerUser, peerUser] = await Promise.all([
      readData<AuthUser>(maker, '/api/v1/auth/user'),
      readData<AuthUser>(peer, '/api/v1/auth/user'),
    ]);
    expect(makerUser.id).not.toBe(peerUser.id);
    expect(makerUser.role.slug).not.toBe(peerUser.role.slug);

    const [warehouses, levels, sessions] = await Promise.all([
      readData<WarehouseMap>(maker, '/api/v1/inventory/warehouse-map'),
      readData<StockLevel[]>(maker, '/api/v1/inventory/stock-levels?per_page=200'),
      readData<SessionSummary[]>(maker, '/api/v1/inventory/stock-counts?per_page=100'),
    ]);
    const scopesByLocation = new Map<string, { warehouseId: string; zoneId: string }>();
    for (const warehouse of warehouses) {
      for (const zone of warehouse.zones) {
        for (const location of zone.locations) {
          scopesByLocation.set(location.id, { warehouseId: warehouse.id, zoneId: zone.id });
        }
      }
    }

    const activeSessions = sessions.filter(({ status }) => status === 'in_progress');
    const candidate = levels
      .filter(({ quantity, location }) => Number(quantity) > 0 && scopesByLocation.has(location.id))
      .map((level) => ({ ...level, scope: scopesByLocation.get(level.location.id)! }))
      .filter(({ scope }) => !activeSessions.some((session) =>
        session.scope === 'full'
        || (session.scope === 'warehouse' && session.warehouse?.id === scope.warehouseId)
        || (session.scope === 'zone' && session.zone?.id === scope.zoneId),
      ))
      .sort((left, right) => {
        const leftSize = warehouses.find(({ id }) => id === left.scope.warehouseId)?.zones
          .find(({ id }) => id === left.scope.zoneId)?.locations.length ?? Number.MAX_SAFE_INTEGER;
        const rightSize = warehouses.find(({ id }) => id === right.scope.warehouseId)?.zones
          .find(({ id }) => id === right.scope.zoneId)?.locations.length ?? Number.MAX_SAFE_INTEGER;
        return leftSize - rightSize;
      })[0];
    expect(candidate, 'positive stock exists in an unfrozen warehouse zone').toBeTruthy();

    const title = `Cross-role live count ${randomUUID()}`;
    const created = await maker.request.post(`${baseUrl()}/api/v1/inventory/stock-counts`, {
      headers: await statefulCsrfHeaders(maker),
      data: {
        title,
        scope: 'zone',
        warehouse_id: candidate.scope.warehouseId,
        zone_id: candidate.scope.zoneId,
      },
    });
    expect(created.status(), await created.text()).toBe(201);
    const createdSession = (await created.json() as ApiData<CountSession>).data;
    sessionId = createdSession.id;
    expect(createdSession.available_actions).toContain('start');

    const started = await maker.request.post(`${baseUrl()}/api/v1/inventory/stock-counts/${sessionId}/start`, {
      headers: await statefulCsrfHeaders(maker),
    });
    expect(started.status(), await started.text()).toBe(200);

    const session = await readData<CountSession>(maker, `/api/v1/inventory/stock-counts/${sessionId}`);
    const target = session.items.find((line) =>
      line.item.id === candidate.item.id && line.location.id === candidate.location.id,
    );
    expect(target, 'selected positive stock appears in the session').toBeTruthy();
    expect(target!.status).toBe('pending');

    const sessionPath = `/api/v1/inventory/stock-counts/${sessionId}`;
    const sessionReads = Promise.all([maker, peer].map((page) =>
      page.waitForResponse((response) =>
        response.request().method() === 'GET'
        && new URL(response.url()).pathname === sessionPath
        && response.status() === 200,
      { timeout: 45_000 }),
    ));
    await Promise.all([
      maker.goto(`/inventory/stock-count?session_id=${sessionId}`),
      peer.goto(`/inventory/stock-count?session_id=${sessionId}`),
    ]);
    await sessionReads;
    const makerRow = maker.getByRole('row')
      .filter({ hasText: target!.item.code })
      .filter({ hasText: target!.location.full_code });
    const peerRow = peer.getByRole('row')
      .filter({ hasText: target!.item.code })
      .filter({ hasText: target!.location.full_code });
    await expect(makerRow.getByText('Pending', { exact: true })).toBeVisible();
    await expect(peerRow.getByText('Pending', { exact: true })).toBeVisible();
    await expect(makerRow.getByRole('button', { name: 'Count', exact: true })).toBeVisible();
    await expect(peerRow.getByRole('button', { name: 'Count', exact: true })).toBeVisible();
    await expect.poll(() => peerSocket.recordChannelReady(), { timeout: 10_000 }).toBe(true);

    let peerRefetchedStatus: string | null = null;
    const onPeerResponse = async (response: Response) => {
      if (response.request().method() !== 'GET'
        || new URL(response.url()).pathname !== `/api/v1/inventory/stock-counts/${sessionId}`
        || response.status() !== 200) return;
      try {
        const data = (await response.json() as ApiData<CountSession>).data;
        peerRefetchedStatus = data.items.find(({ id }) => id === target!.id)?.status ?? null;
      } catch {
        peerRefetchedStatus = null;
      }
    };
    peer.on('response', onPeerResponse);

    const eventCountBeforeAction = peerSocket.eventCount();
    await makerRow.getByRole('button', { name: 'Count', exact: true }).click();
    const countDialog = maker.getByRole('dialog', { name: `Count — ${target!.location.full_code}` });
    await countDialog.getByLabel('Counted quantity').fill(target!.system_quantity);
    const countResponsePromise = maker.waitForResponse((response) =>
      new URL(response.url()).pathname === `/api/v1/inventory/stock-counts/items/${target!.id}/count`
      && response.request().method() === 'POST',
    );
    await countDialog.getByRole('button', { name: 'Record', exact: true }).click();
    const countResponse = await countResponsePromise;
    const responseReceivedAt = Date.now();
    expect(countResponse.status()).toBe(200);
    const counted = (await countResponse.json() as ApiData<CountLine>).data;
    expect(counted.status).toBe('counted');
    expect(counted.available_actions).toContain('record_count');

    await expect.poll(() => peerSocket.eventCount(), { timeout: 10_000 })
      .toBeGreaterThan(eventCountBeforeAction);
    await expect.poll(() => peerRefetchedStatus, { timeout: 10_000 }).toBe('counted');
    await expect(makerRow.getByText('Counted', { exact: true })).toBeVisible();
    await expect(makerRow.getByRole('button', { name: 'Recount', exact: true })).toBeVisible();
    await expect(peerRow.getByText('Counted', { exact: true })).toBeVisible();
    await expect(peerRow.getByRole('button', { name: 'Recount', exact: true })).toBeVisible();
    const peerRenderMs = Date.now() - responseReceivedAt;

    console.log(JSON.stringify({
      scenario: 'stock_count_cross_role_count',
      maker_role: makerRole.slug,
      peer_role: peerRole.slug,
      mutation_status: countResponse.status(),
      peer_received_refresh_event: peerSocket.eventCount() > 0,
      peer_authorized_refetch_status: peerRefetchedStatus,
      peer_response_to_render_ms: peerRenderMs,
    }));
    peer.off('response', onPeerResponse);
  } finally {
    if (sessionId) {
      const current = await readData<CountSession>(maker, `/api/v1/inventory/stock-counts/${sessionId}`);
      if (['draft', 'in_progress'].includes(current.status)) {
        const cancelled = await maker.request.delete(`${baseUrl()}/api/v1/inventory/stock-counts/${sessionId}`, {
          headers: await statefulCsrfHeaders(maker),
        });
        expect(cancelled.status()).toBe(204);
      }
    }
    peerSocket.stop();
    await Promise.all([makerContext.close(), peerContext.close()]);
  }
});
