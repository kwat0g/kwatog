import { expect, test } from '@playwright/test';
import { baseUrl, loginAs, statefulCsrfHeaders, statefulHeaders } from './helpers';

type MachineRow = { id: string; machine_code: string; name: string };

test('machine update refreshes a different-role viewer detail and list without reload', async ({ browser }) => {
  test.setTimeout(90_000);
  const base = baseUrl();
  const actorContext = await browser.newContext({ baseURL: base });
  const viewerContext = await browser.newContext({ baseURL: base });
  const actorPage = await actorContext.newPage();
  const viewerListPage = await viewerContext.newPage();
  const viewerDetailPage = await viewerContext.newPage();
  let machine: MachineRow | undefined;
  let originalName: string | undefined;
  let updatedName: string | undefined;
  let actorEditHeaders: Record<string, string> | undefined;
  let listEvents = 0;
  let detailEvents = 0;
  let listChannelReady = false;
  let detailChannelReady = false;
  let listEventAt: number | undefined;
  let detailEventAt: number | undefined;
  const listRefreshGets: number[] = [];
  const detailRefreshGets: number[] = [];

  viewerListPage.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      if (typeof frame.payload !== 'string') return;
      try {
        const message = JSON.parse(frame.payload) as { event?: string; channel?: string };
        if (message.event === 'pusher_internal:subscription_succeeded' && message.channel === 'private-entity.machine.visible') {
          listChannelReady = true;
        }
        if (message.event === 'chain.refresh' && message.channel === 'private-entity.machine.visible') {
          listEvents += 1;
          listEventAt ??= Date.now();
        }
      } catch {
        // Ignore non-event protocol frames.
      }
    });
  });
  viewerDetailPage.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      if (typeof frame.payload !== 'string') return;
      try {
        const message = JSON.parse(frame.payload) as { event?: string; channel?: string };
        if (message.event === 'pusher_internal:subscription_succeeded' && message.channel === `private-production.machine.${machine?.id}`) {
          detailChannelReady = true;
        }
        if (message.event === 'chain.refresh' && message.channel === `private-production.machine.${machine?.id}`) {
          detailEvents += 1;
          detailEventAt ??= Date.now();
        }
      } catch {
        // Ignore non-event protocol frames.
      }
    });
  });
  viewerListPage.on('request', (request) => {
    const url = new URL(request.url());
    if (request.method() === 'GET' && url.pathname === '/api/v1/mrp/machines') listRefreshGets.push(Date.now());
  });
  viewerDetailPage.on('request', (request) => {
    const url = new URL(request.url());
    if (machine && request.method() === 'GET' && url.pathname === `/api/v1/mrp/machines/${machine.id}`) {
      detailRefreshGets.push(Date.now());
    }
  });

  try {
    await loginAs(actorPage, 'admin@ogami.test', 'password');
    await loginAs(viewerListPage, 'production@ogami.test', 'password');

    const listResponse = await actorPage.request.get(`${base}/api/v1/mrp/machines?per_page=100`, {
      headers: statefulHeaders(),
    });
    expect(listResponse.status(), await listResponse.text()).toBe(200);
    const rows = (await listResponse.json() as { data: MachineRow[] }).data;
    expect(rows.length).toBeGreaterThan(0);
    machine = rows[0];
    originalName = machine.name;
    updatedName = `${originalName} realtime check`;

    await viewerListPage.goto('/mrp/machines', { waitUntil: 'domcontentloaded' });
    await expect(viewerListPage.getByRole('row').filter({ hasText: machine.machine_code })).toContainText(originalName);
    await viewerDetailPage.goto(`/mrp/machines/${machine.id}`, { waitUntil: 'domcontentloaded' });
    await expect(viewerDetailPage.getByRole('heading', { level: 1 })).toContainText(originalName);
    await expect.poll(() => listChannelReady, { timeout: 15_000 }).toBe(true);
    await expect.poll(() => detailChannelReady, { timeout: 15_000 }).toBe(true);

    await actorPage.goto(`/mrp/machines/${machine.id}/edit`, { waitUntil: 'domcontentloaded' });
    await expect(actorPage.getByLabel('Machine Name')).toHaveValue(originalName);
    actorEditHeaders = await statefulCsrfHeaders(actorPage);
    listEvents = 0;
    detailEvents = 0;
    listEventAt = undefined;
    detailEventAt = undefined;
    listRefreshGets.length = 0;
    detailRefreshGets.length = 0;

    const responsePromise = actorPage.waitForResponse((response) =>
      response.request().method() === 'PUT'
      && new URL(response.url()).pathname === `/api/v1/mrp/machines/${machine!.id}`,
    );
    await actorPage.getByLabel('Machine Name').fill(updatedName);
    await actorPage.getByRole('button', { name: 'Save changes', exact: true }).click();
    const response = await responsePromise;
    expect(response.status(), await response.text()).toBe(200);
    const actorResponseAt = Date.now();

    await expect(actorPage.getByRole('heading', { level: 1 })).toContainText(updatedName);
    await expect.poll(() => listEvents, { timeout: 10_000 }).toBeGreaterThan(0);
    await expect.poll(() => detailEvents, { timeout: 10_000 }).toBeGreaterThan(0);
    await expect(viewerListPage.getByRole('row').filter({ hasText: machine.machine_code })).toContainText(updatedName);
    const listRenderAt = Date.now();
    await expect(viewerDetailPage.getByRole('heading', { level: 1 })).toContainText(updatedName);
    const detailRenderAt = Date.now();
    expect(listRefreshGets.some((at) => at >= (listEventAt ?? Infinity))).toBe(true);
    expect(detailRefreshGets.some((at) => at >= (detailEventAt ?? Infinity))).toBe(true);

    await test.info().attach('machine-realtime-baseline.json', {
      body: Buffer.from(JSON.stringify({
        machineId: machine.id,
        actorRole: 'system_admin',
        viewerRole: 'production_manager',
        mutationStatus: response.status(),
        listEvents,
        detailEvents,
        listRefetchesAfterEvent: listRefreshGets.filter((at) => at >= (listEventAt ?? Infinity)).length,
        detailRefetchesAfterEvent: detailRefreshGets.filter((at) => at >= (detailEventAt ?? Infinity)).length,
        listRenderMsFromActorResponse: listRenderAt - actorResponseAt,
        detailRenderMsFromActorResponse: detailRenderAt - actorResponseAt,
      }, null, 2)),
      contentType: 'application/json',
    });
  } finally {
    if (machine && originalName && updatedName && originalName !== updatedName && actorEditHeaders) {
      const restore = await actorPage.request.put(`${base}/api/v1/mrp/machines/${machine.id}`, {
        headers: actorEditHeaders,
        data: { name: originalName },
      }).catch(() => null);
      expect(restore?.status(), 'Restore the original machine name after the realtime check.').toBe(200);
    }
    await actorContext.close();
    await viewerContext.close();
  }
});
