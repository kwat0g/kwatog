import { expect, test } from '@playwright/test';
import { baseUrl, loginAs, statefulCsrfHeaders, statefulHeaders } from './helpers';

type BomItem = {
  item: { id: string } | null;
  quantity_per_unit: string;
  unit: string;
  waste_factor: string;
  sort_order: number;
};

type BomRow = {
  id: string;
  version: number;
  is_active: boolean;
  item_count: number;
  cost_batch_size: string;
  product?: { id: string; part_number: string; name: string } | null;
  items?: BomItem[];
};

test('BOM version change refreshes cross-role detail, list and product summary without reload', async ({ browser }) => {
  test.setTimeout(120_000);
  const base = baseUrl();
  const actorContext = await browser.newContext({ baseURL: base });
  const viewerContext = await browser.newContext({ baseURL: base });
  const actorPage = await actorContext.newPage();
  const viewerListPage = await viewerContext.newPage();
  const viewerDetailPage = await viewerContext.newPage();
  const viewerProductPage = await viewerContext.newPage();
  let bom: BomRow | undefined;
  let actorHeaders: Record<string, string> | undefined;
  let originalItems: Array<{ item_id: string; quantity_per_unit: string; unit: string; waste_factor: string; sort_order: number }> | undefined;
  let changedQuantity: string | undefined;
  let listEvents = 0;
  let detailEvents = 0;
  let productEvents = 0;
  let listReady = false;
  let detailReady = false;
  let productReady = false;
  let listEventAt: number | undefined;
  let detailEventAt: number | undefined;
  let productEventAt: number | undefined;
  const listGets: number[] = [];
  const detailGets: number[] = [];
  const productGets: number[] = [];

  viewerListPage.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      if (typeof frame.payload !== 'string') return;
      try {
        const message = JSON.parse(frame.payload) as { event?: string; channel?: string };
        if (message.event === 'pusher_internal:subscription_succeeded' && message.channel === 'private-entity.bom.visible') listReady = true;
        if (message.event === 'chain.refresh' && message.channel === 'private-entity.bom.visible') {
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
        if (message.event === 'pusher_internal:subscription_succeeded' && message.channel === `private-production.bom.${bom?.id}`) detailReady = true;
        if (message.event === 'chain.refresh' && message.channel === `private-production.bom.${bom?.id}`) {
          detailEvents += 1;
          detailEventAt ??= Date.now();
        }
      } catch {
        // Ignore non-event protocol frames.
      }
    });
  });
  viewerProductPage.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      if (typeof frame.payload !== 'string') return;
      try {
        const message = JSON.parse(frame.payload) as { event?: string; channel?: string };
        if (message.event === 'pusher_internal:subscription_succeeded' && message.channel === `private-mrp.product-bom.${bom?.product?.id}`) productReady = true;
        if (message.event === 'chain.refresh' && message.channel === `private-mrp.product-bom.${bom?.product?.id}`) {
          productEvents += 1;
          productEventAt ??= Date.now();
        }
      } catch {
        // Ignore non-event protocol frames.
      }
    });
  });
  viewerListPage.on('request', (request) => {
    const url = new URL(request.url());
    if (request.method() === 'GET' && url.pathname === '/api/v1/mrp/boms') listGets.push(Date.now());
  });
  viewerDetailPage.on('request', (request) => {
    const url = new URL(request.url());
    if (bom && request.method() === 'GET' && url.pathname === `/api/v1/mrp/boms/${bom.id}`) detailGets.push(Date.now());
  });
  viewerProductPage.on('request', (request) => {
    const url = new URL(request.url());
    if (bom?.product && request.method() === 'GET' && url.pathname === `/api/v1/crm/products/${bom.product.id}`) productGets.push(Date.now());
  });

  try {
    await loginAs(actorPage, 'admin@ogami.test', 'password');
    await loginAs(viewerListPage, 'ppc@ogami.test', 'password');

    const listResponse = await actorPage.request.get(`${base}/api/v1/mrp/boms?per_page=100&is_active=true`, {
      headers: statefulHeaders(),
    });
    expect(listResponse.status(), await listResponse.text()).toBe(200);
    const rows = (await listResponse.json() as { data: BomRow[] }).data;
    expect(rows.length).toBeGreaterThan(0);
    for (const candidate of rows) {
      const response = await actorPage.request.get(`${base}/api/v1/mrp/boms/${candidate.id}`, { headers: statefulHeaders() });
      if (response.status() !== 200) continue;
      const detail = (await response.json() as { data: BomRow }).data;
      if (detail.is_active && detail.product?.id && detail.items?.length && detail.items.every((line) => line.item?.id)) {
        bom = detail;
        break;
      }
    }
    expect(bom, 'A live active BOM with at least one valid component is required.').toBeTruthy();
    const activeBom = bom!;
    originalItems = activeBom.items!.map((line, index) => ({
      item_id: line.item!.id,
      quantity_per_unit: line.quantity_per_unit,
      unit: line.unit,
      waste_factor: line.waste_factor,
      sort_order: line.sort_order ?? index,
    }));
    const firstQuantity = Number(originalItems[0].quantity_per_unit);
    changedQuantity = (firstQuantity + 0.0001).toFixed(4);
    expect(Number.isFinite(Number(changedQuantity))).toBe(true);
    actorHeaders = await statefulCsrfHeaders(actorPage);

    await viewerListPage.goto('/mrp/boms', { waitUntil: 'domcontentloaded' });
    await expect(viewerListPage.getByRole('row').filter({ hasText: activeBom.product!.part_number }).filter({ hasText: `v${activeBom.version}` })).toContainText('Active');
    await viewerDetailPage.goto(`/mrp/boms/${activeBom.id}`, { waitUntil: 'domcontentloaded' });
    await expect(viewerDetailPage.getByRole('heading', { level: 1 })).toContainText(activeBom.product!.part_number);
    await expect(viewerDetailPage.getByRole('button', { name: 'Recalculate cost' })).toBeVisible();
    await viewerProductPage.goto(`/crm/products/${activeBom.product!.id}`, { waitUntil: 'domcontentloaded' });
    await expect(viewerProductPage.getByText(`Active BOM version ${activeBom.version}.`)).toBeVisible();
    await expect.poll(() => listReady, { timeout: 15_000 }).toBe(true);
    await expect.poll(() => detailReady, { timeout: 15_000 }).toBe(true);
    await expect.poll(() => productReady, { timeout: 15_000 }).toBe(true);

    await actorPage.goto(`/mrp/boms/${activeBom.id}/edit`, { waitUntil: 'domcontentloaded' });
    await expect(actorPage.getByLabel('Quantity per unit for line 1')).toHaveValue(originalItems[0].quantity_per_unit);
    listEvents = 0;
    detailEvents = 0;
    productEvents = 0;
    listEventAt = undefined;
    detailEventAt = undefined;
    productEventAt = undefined;
    listGets.length = 0;
    detailGets.length = 0;
    productGets.length = 0;

    const responsePromise = actorPage.waitForResponse((response) =>
      response.request().method() === 'PUT'
      && new URL(response.url()).pathname === `/api/v1/mrp/boms/${activeBom.id}`,
    );
    await actorPage.getByLabel('Quantity per unit for line 1').fill(changedQuantity);
    await actorPage.getByRole('button', { name: 'Save new version', exact: true }).click();
    const response = await responsePromise;
    expect(response.status(), await response.text()).toBe(200);
    const actorResponseAt = Date.now();
    const newBom = (await response.json() as { data: BomRow }).data;

    await expect(actorPage.getByRole('heading', { level: 1 })).toContainText(activeBom.product!.part_number);
    await expect(actorPage.getByText(`v${newBom.version} · active`)).toBeVisible();
    await expect.poll(() => listEvents, { timeout: 10_000 }).toBeGreaterThan(0);
    await expect.poll(() => detailEvents, { timeout: 10_000 }).toBeGreaterThan(0);
    await expect.poll(() => productEvents, { timeout: 10_000 }).toBeGreaterThan(0);
    await expect(viewerDetailPage.getByText(`v${activeBom.version} · archived`)).toBeVisible();
    await expect(viewerDetailPage.getByRole('button', { name: 'Recalculate cost' })).toHaveCount(0);
    await expect(viewerDetailPage.getByRole('button', { name: 'Archive', exact: true })).toBeVisible();
    const detailRenderAt = Date.now();
    const row = viewerListPage.getByRole('row').filter({ hasText: activeBom.product!.part_number }).filter({ hasText: `v${newBom.version}` });
    await expect(row).toContainText('Active');
    const listRenderAt = Date.now();
    await expect(viewerProductPage.getByText(`Active BOM version ${newBom.version}.`)).toBeVisible();
    const productRenderAt = Date.now();

    expect(listGets.some((at) => at >= (listEventAt ?? Infinity))).toBe(true);
    expect(detailGets.some((at) => at >= (detailEventAt ?? Infinity))).toBe(true);
    expect(productGets.some((at) => at >= (productEventAt ?? Infinity))).toBe(true);
    const report = {
      bomId: activeBom.id,
      oldVersion: activeBom.version,
      newVersion: newBom.version,
      actorRole: 'system_admin',
      viewerRole: 'ppc_head',
      mutationStatus: response.status(),
      listEvents,
      detailEvents,
      productEvents,
      refetchesAfterEvent: {
        list: listGets.filter((at) => at >= (listEventAt ?? Infinity)).length,
        detail: detailGets.filter((at) => at >= (detailEventAt ?? Infinity)).length,
        product: productGets.filter((at) => at >= (productEventAt ?? Infinity)).length,
      },
      renderMsFromActorResponse: {
        list: listRenderAt - actorResponseAt,
        detail: detailRenderAt - actorResponseAt,
        product: productRenderAt - actorResponseAt,
      },
    };
    console.log(`BOM_REALTIME_REPORT ${JSON.stringify(report)}`);
    await test.info().attach('bom-realtime-baseline.json', {
      body: Buffer.from(JSON.stringify(report, null, 2)),
      contentType: 'application/json',
    });
  } finally {
    if (bom && originalItems && changedQuantity && actorHeaders) {
      const restoredItems = originalItems.map((line) => ({ ...line }));
      const restore = await actorPage.request.put(`${base}/api/v1/mrp/boms/${bom.id}`, {
        headers: actorHeaders,
        data: {
          product_id: bom.product!.id,
          cost_batch_size: bom.cost_batch_size,
          items: restoredItems,
        },
      }).catch(() => null);
      expect(restore?.status(), 'Restore the active BOM component definition after the realtime check.').toBe(200);
      const restoredActive = await actorPage.request.get(`${base}/api/v1/mrp/products/${bom.product!.id}/bom`, { headers: statefulHeaders() });
      expect(restoredActive.status(), await restoredActive.text()).toBe(200);
      const restored = (await restoredActive.json() as { data: BomRow }).data;
      expect(restored.items?.map((line) => line.quantity_per_unit)).toEqual(originalItems.map((line) => line.quantity_per_unit));
    }
    await actorContext.close();
    await viewerContext.close();
  }
});
