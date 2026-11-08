import { expect, test } from '@playwright/test';
import { baseUrl, loginAs, statefulCsrfHeaders, statefulHeaders } from './helpers';

type MoldRow = {
  id: string;
  mold_code: string;
  name: string;
  product?: { part_number: string; name: string } | null;
};

test('mold update refreshes a different-role viewer detail and list without reload', async ({ browser }) => {
  test.setTimeout(90_000);
  const base = baseUrl();
  const actorContext = await browser.newContext({ baseURL: base });
  const viewerContext = await browser.newContext({ baseURL: base });
  const actorPage = await actorContext.newPage();
  const viewerListPage = await viewerContext.newPage();
  const viewerDetailPage = await viewerContext.newPage();
  let mold: MoldRow | undefined;
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
        if (message.event === 'pusher_internal:subscription_succeeded' && message.channel === 'private-entity.mold.visible') {
          listChannelReady = true;
        }
        if (message.event === 'chain.refresh' && message.channel === 'private-entity.mold.visible') {
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
        if (message.event === 'pusher_internal:subscription_succeeded' && message.channel === `private-production.mold.${mold?.id}`) {
          detailChannelReady = true;
        }
        if (message.event === 'chain.refresh' && message.channel === `private-production.mold.${mold?.id}`) {
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
    if (request.method() === 'GET' && url.pathname === '/api/v1/mrp/molds') listRefreshGets.push(Date.now());
  });
  viewerDetailPage.on('request', (request) => {
    const url = new URL(request.url());
    if (mold && request.method() === 'GET' && url.pathname === `/api/v1/mrp/molds/${mold.id}`) {
      detailRefreshGets.push(Date.now());
    }
  });

  try {
    await loginAs(actorPage, 'admin@ogami.test', 'password');
    await loginAs(viewerListPage, 'ppc@ogami.test', 'password');

    const listResponse = await actorPage.request.get(`${base}/api/v1/mrp/molds?per_page=100`, {
      headers: statefulHeaders(),
    });
    expect(listResponse.status(), await listResponse.text()).toBe(200);
    const rows = (await listResponse.json() as { data: MoldRow[] }).data;
    expect(rows.length).toBeGreaterThan(0);
    mold = rows[0];
    originalName = mold.name;
    updatedName = `${originalName} realtime check`;
    const productLabel = mold.product ? `${mold.product.part_number} — ${mold.product.name}` : undefined;
    expect(productLabel, 'The mold list response must identify the existing product.').toBeTruthy();

    await viewerListPage.goto('/mrp/molds', { waitUntil: 'domcontentloaded' });
    await expect(viewerListPage.getByRole('row').filter({ hasText: mold.mold_code })).toContainText(originalName);
    await viewerDetailPage.goto(`/mrp/molds/${mold.id}`, { waitUntil: 'domcontentloaded' });
    await expect(viewerDetailPage.getByRole('heading', { level: 1 })).toContainText(originalName);
    await expect.poll(() => listChannelReady, { timeout: 15_000 }).toBe(true);
    await expect.poll(() => detailChannelReady, { timeout: 15_000 }).toBe(true);

    await actorPage.goto(`/mrp/molds/${mold.id}/edit`, { waitUntil: 'domcontentloaded' });
    await expect(actorPage.getByLabel('Mold Name')).toHaveValue(originalName);
    // The current edit form does not retain the initial searchable Product
    // select value after its async options load. Select the same product so
    // this scenario exercises freshness without changing the linked product.
    await actorPage.getByRole('combobox', { name: 'Product' }).click();
    await actorPage.getByRole('option', { name: productLabel!, exact: true }).click();
    actorEditHeaders = await statefulCsrfHeaders(actorPage);
    listEvents = 0;
    detailEvents = 0;
    listEventAt = undefined;
    detailEventAt = undefined;
    listRefreshGets.length = 0;
    detailRefreshGets.length = 0;

    const responsePromise = actorPage.waitForResponse((response) =>
      response.request().method() === 'PUT'
      && new URL(response.url()).pathname === `/api/v1/mrp/molds/${mold!.id}`,
    );
    await actorPage.getByLabel('Mold Name').fill(updatedName);
    await actorPage.getByRole('button', { name: 'Save changes', exact: true }).click();
    const response = await responsePromise;
    expect(response.status(), await response.text()).toBe(200);
    const actorResponseAt = Date.now();

    await expect(actorPage.getByRole('heading', { level: 1 })).toContainText(updatedName);
    await expect.poll(() => listEvents, { timeout: 10_000 }).toBeGreaterThan(0);
    await expect.poll(() => detailEvents, { timeout: 10_000 }).toBeGreaterThan(0);
    await expect(viewerListPage.getByRole('row').filter({ hasText: mold.mold_code })).toContainText(updatedName);
    const listRenderAt = Date.now();
    await expect(viewerDetailPage.getByRole('heading', { level: 1 })).toContainText(updatedName);
    const detailRenderAt = Date.now();
    expect(listRefreshGets.some((at) => at >= (listEventAt ?? Infinity))).toBe(true);
    expect(detailRefreshGets.some((at) => at >= (detailEventAt ?? Infinity))).toBe(true);

    await test.info().attach('mold-realtime-baseline.json', {
      body: Buffer.from(JSON.stringify({
        moldId: mold.id,
        actorRole: 'system_admin',
        viewerRole: 'ppc_head',
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
    if (mold && originalName && updatedName && originalName !== updatedName && actorEditHeaders) {
      const restore = await actorPage.request.put(`${base}/api/v1/mrp/molds/${mold.id}`, {
        headers: actorEditHeaders,
        data: { name: originalName },
      }).catch(() => null);
      expect(restore?.status(), 'Restore the original mold name after the realtime check.').toBe(200);
    }
    await actorContext.close();
    await viewerContext.close();
  }
});
