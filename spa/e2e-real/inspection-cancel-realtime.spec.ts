import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { baseUrl, loginAs, ROLES, statefulCsrfHeaders, statefulHeaders } from './helpers';

type Inspection = {
  id: string;
  inspection_number: string;
  status: string;
  stage: string;
  entity_type: string | null;
  entity_hash_id: string | null;
  result_version: number;
  product?: { id: string };
};

type RealtimeFrame = {
  at: number;
  event?: string;
  channel?: string;
  data?: { action?: string; entity_id?: string; actor_id?: string; event_id?: string };
};

function observeRealtime(page: Page): RealtimeFrame[] {
  const frames: RealtimeFrame[] = [];
  page.on('websocket', (socket) => socket.on('framereceived', (frame) => {
    const raw = typeof frame.payload === 'string' ? frame.payload : frame.payload.toString('utf8');
    try {
      const message = JSON.parse(raw) as RealtimeFrame & { data?: string | RealtimeFrame['data'] };
      frames.push({
        at: Date.now(),
        event: message.event,
        channel: message.channel,
        data: typeof message.data === 'string' ? JSON.parse(message.data) as RealtimeFrame['data'] : message.data,
      });
    } catch {
      // Ignore Pusher protocol frames without JSON data.
    }
  }));
  return frames;
}

async function watchStatusMutation(locator: import('@playwright/test').Locator, nextStatus: string, key: string): Promise<void> {
  await locator.evaluate((element, options) => {
    const state = window as unknown as Record<string, unknown>;
    state[options.key] = 0;
    const target = element.closest('tr') ?? element;
    new MutationObserver(() => {
      if (target.textContent?.includes(options.nextStatus) && !state[options.key]) {
        state[options.key] = Date.now();
      }
    }).observe(target, { childList: true, subtree: true, characterData: true });
  }, { nextStatus, key });
}

async function statusMutationAt(page: Page, key: string): Promise<number> {
  return page.evaluate((property) => {
    const value = (window as unknown as Record<string, unknown>)[property];
    return typeof value === 'number' ? value : 0;
  }, key);
}

async function apiData<T>(page: Page, path: string): Promise<T> {
  const response = await page.request.get(`${baseUrl()}${path}`, { headers: statefulHeaders() });
  const body = await response.text();
  expect(response.status(), `${path}: ${body}`).toBe(200);
  const data = (JSON.parse(body) as { data: T | { data: T } }).data;
  return Array.isArray(data) ? data as T : (data as { data: T }).data;
}

test('Inspection cancellation refreshes another role detail and list while actor socket is offline', async ({ browser }) => {
  test.setTimeout(90_000);
  const base = baseUrl();
  const actorRole = ROLES.find(({ slug }) => slug === 'qc_inspector')!;
  const viewerRole = ROLES.find(({ slug }) => slug === 'system_admin')!;
  const actorContext = await browser.newContext({ baseURL: base });
  // Install the socket block before the page initializes Echo.
  await actorContext.routeWebSocket(/.*/, (socket) => socket.close({
    code: 1000,
    reason: 'Actor state must come from the successful API response.',
  }));
  const actor = await actorContext.newPage();
  let detailContext: BrowserContext | undefined;
  let listContext: BrowserContext | undefined;
  let inspection: Inspection | undefined;
  let cancelSucceeded = false;

  try {
    await loginAs(actor, actorRole.email, actorRole.password);
    detailContext = await browser.newContext({ baseURL: base });
    const viewer = await detailContext.newPage();
    await loginAs(viewer, viewerRole.email, viewerRole.password);
    listContext = await browser.newContext({
      baseURL: base,
      storageState: await detailContext.storageState(),
    });
    const listViewer = await listContext.newPage();
    const detailFrames = observeRealtime(viewer);
    const listFrames = observeRealtime(listViewer);
    const detailReads: Array<{ at: number; status?: number; recordStatus?: string }> = [];
    viewer.on('request', (request) => {
      if (request.method() === 'GET'
        && new URL(request.url()).pathname === `/api/v1/quality/inspections/${inspection?.id}`) {
        detailReads.push({ at: Date.now() });
      }
    });
    viewer.on('response', (response) => {
      if (response.request().method() === 'GET'
        && new URL(response.url()).pathname === `/api/v1/quality/inspections/${inspection?.id}`) {
        void response.json().then((body: { data?: { status?: string } }) => {
          detailReads.push({ at: Date.now(), status: response.status(), recordStatus: body.data?.status });
        }).catch(() => detailReads.push({ at: Date.now(), status: response.status() }));
      }
    });

    const [workOrders, inspections, specs] = await Promise.all([
      apiData<Array<{ id: string; status: string; product: { id: string } }>>(viewer, '/api/v1/production/work-orders?per_page=100'),
      apiData<Inspection[]>(viewer, '/api/v1/quality/inspections?per_page=100'),
      apiData<Array<{ is_active: boolean; product?: { id: string }; product_id?: string }>>(viewer, '/api/v1/quality/inspection-specs?per_page=100'),
    ]);
    const productsWithActiveSpecs = new Set(specs
      .filter((spec) => spec.is_active)
      .map((spec) => String(spec.product?.id ?? spec.product_id ?? '')));
    const occupiedWorkOrderSlots = new Set(inspections
      .filter((row) => row.stage === 'in_process' && row.entity_type === 'work_order' && row.status !== 'cancelled')
      .map((row) => `${row.entity_hash_id}:${row.product?.id ?? ''}`));
    const source = workOrders.find((row) => {
      const productId = row.product?.id;
      return productId
        && productsWithActiveSpecs.has(productId)
        && !['closed', 'cancelled'].includes(row.status)
        && !occupiedWorkOrderSlots.has(`${row.id}:${productId}`);
    });
    expect(source, 'a work order with an active inspection spec and unused in-process inspection slot').toBeTruthy();

    const suffix = randomUUID().replaceAll('-', '').slice(0, 12);
    const reason = `Realtime inspection probe ${suffix}`;
    const create = await actor.request.post(`${base}/api/v1/quality/inspections`, {
      headers: await statefulCsrfHeaders(actor),
      data: {
        stage: 'in_process',
        product_id: source!.product.id,
        batch_quantity: 1,
        entity_type: 'work_order',
        entity_id: source!.id,
        notes: reason,
      },
    });
    expect([200, 201], await create.text()).toContain(create.status());
    inspection = (await create.json() as { data: Inspection }).data;
    expect(inspection.status).toBe('draft');

    await Promise.all([
      viewer.goto(`/quality/inspections/${inspection.id}`, { waitUntil: 'domcontentloaded' }),
      listViewer.goto('/quality/inspections', { waitUntil: 'domcontentloaded' }),
      actor.goto(`/quality/inspections/${inspection.id}`, { waitUntil: 'domcontentloaded' }),
    ]);
    const viewerStatus = viewer.locator('[data-layout="page-header"]').getByText('Draft', { exact: true });
    await expect(viewerStatus).toBeVisible();
    const viewerHeader = viewer.locator('[data-layout="page-header"]');
    await watchStatusMutation(viewerHeader, 'Cancelled', '__inspectionDetailStatusAt');
    await expect(viewer.getByRole('button', { name: 'Cancel', exact: true })).toBeVisible();
    const listRow = listViewer.getByRole('row').filter({ hasText: inspection.inspection_number });
    await expect(listRow).toContainText('Draft');
    await watchStatusMutation(listRow, 'Cancelled', '__inspectionListStatusAt');
    await expect.poll(() => detailFrames.some((frame) =>
      frame.event === 'pusher_internal:subscription_succeeded'
      && frame.channel === `private-inspection.${inspection!.id}`,
    ), { timeout: 15_000 }).toBe(true);
    await expect.poll(() => listFrames.some((frame) =>
      frame.event === 'pusher_internal:subscription_succeeded'
      && frame.channel === 'private-entity.inspection.visible',
    ), { timeout: 15_000 }).toBe(true);

    await viewer.bringToFront();
    await expect(actor.getByRole('button', { name: 'Cancel', exact: true })).toBeVisible();
    await watchStatusMutation(actor.locator('[data-layout="page-header"]'), 'Cancelled', '__inspectionActorStatusAt');
    await actor.getByRole('button', { name: 'Cancel', exact: true }).click();
    const dialog = actor.getByRole('dialog', { name: 'Cancel inspection?' });
    await dialog.getByRole('textbox', { name: 'Reason' }).fill(reason);
    const viewerVisibilityAtMutation = await viewer.evaluate(() => document.visibilityState);
    const actionStartedAt = Date.now();
    const cancelResponsePromise = actor.waitForResponse((response) =>
      response.request().method() === 'POST'
      && new URL(response.url()).pathname === `/api/v1/quality/inspections/${inspection!.id}/cancel`,
    );
    await dialog.getByRole('button', { name: 'Cancel inspection', exact: true }).click();
    const response = await cancelResponsePromise;
    expect(response.status(), await response.text()).toBe(200);
    const actorResponseAt = Date.now();
    const updated = (await response.json() as { data: Inspection }).data;
    expect(updated.status).toBe('cancelled');
    cancelSucceeded = true;
    await expect(actor.locator('[data-layout="page-header"]').getByText('Cancelled', { exact: true })).toBeVisible();
    await expect(actor.getByRole('button', { name: 'Cancel', exact: true })).toHaveCount(0);
    const actorStatusMutationAt = await statusMutationAt(actor, '__inspectionActorStatusAt');

    const matchesRecord = (frame: RealtimeFrame, channel: string) =>
      frame.event === 'inspection.refresh'
      && frame.channel === channel
      && frame.data?.entity_id === inspection!.id
      && frame.data.action === 'updated'
      && frame.data.event_id;
    await expect.poll(() => detailFrames.filter((frame) =>
      frame.at >= actionStartedAt && matchesRecord(frame, `private-inspection.${inspection!.id}`),
    ).length, { timeout: 10_000 }).toBeGreaterThan(0);
    await expect.poll(() => listFrames.filter((frame) =>
      frame.at >= actionStartedAt && matchesRecord(frame, 'private-entity.inspection.visible'),
    ).length, { timeout: 10_000 }).toBeGreaterThan(0);
    await expect(viewer.locator('[data-layout="page-header"]').getByText('Cancelled', { exact: true }))
      .toBeVisible({ timeout: 10_000 });
    await expect(viewer.getByRole('button', { name: 'Cancel', exact: true })).toHaveCount(0);
    const detailStatusMutationAt = await statusMutationAt(viewer, '__inspectionDetailStatusAt');
    await listViewer.bringToFront();
    await expect(listRow).toContainText('Cancelled', { timeout: 10_000 });
    const listStatusMutationAt = await statusMutationAt(listViewer, '__inspectionListStatusAt');

    const detailEvent = detailFrames.find((frame) =>
      frame.at >= actionStartedAt && matchesRecord(frame, `private-inspection.${inspection!.id}`));
    const listEvent = listFrames.find((frame) =>
      frame.at >= actionStartedAt && matchesRecord(frame, 'private-entity.inspection.visible'));
    const evidence = {
      inspection: inspection.id,
      inspectionNumber: inspection.inspection_number,
      actorRole: actorRole.slug,
      viewerRole: viewerRole.slug,
      actorSocketBlocked: true,
      viewerVisibilityAtMutation,
      detailAndListPrivateChannelsSubscribed: true,
      matchingDetailAndListEventsReceived: true,
      detailRefetchesAfterAction: detailReads,
      actorResponseStatus: updated.status,
      actorActionToActorUiMs: actorStatusMutationAt - actionStartedAt,
      actorResponseToPeerDetailMs: detailStatusMutationAt - actorResponseAt,
      actorResponseToPeerListMs: listStatusMutationAt - actorResponseAt,
      detailEventToPeerUiMs: detailEvent ? detailStatusMutationAt - detailEvent.at : null,
      listEventToPeerUiMs: listEvent ? listStatusMutationAt - listEvent.at : null,
      viewerDetailStatus: 'cancelled',
      viewerActionRemoved: true,
      viewerListStatus: 'cancelled',
    };
    console.info(`INSPECTION_REALTIME ${JSON.stringify(evidence)}`);
    await test.info().attach('inspection-cancel-realtime.json', {
      body: JSON.stringify(evidence, null, 2),
      contentType: 'application/json',
    });
    expect(evidence.actorActionToActorUiMs).toBeGreaterThanOrEqual(0);
    expect(evidence.actorActionToActorUiMs).toBeLessThan(2_000);
    expect(evidence.actorResponseToPeerDetailMs).toBeGreaterThanOrEqual(0);
    expect(evidence.actorResponseToPeerDetailMs).toBeLessThan(2_000);
    expect(evidence.actorResponseToPeerListMs).toBeGreaterThanOrEqual(0);
    expect(evidence.actorResponseToPeerListMs).toBeLessThan(2_000);
    expect(evidence.detailEventToPeerUiMs).toBeLessThan(2_000);
    expect(evidence.listEventToPeerUiMs).toBeLessThan(2_000);
  } finally {
    if (inspection && !cancelSucceeded) {
      const headers = await statefulCsrfHeaders(actor);
      const current = await actor.request.get(`${base}/api/v1/quality/inspections/${inspection.id}`, { headers: statefulHeaders() });
      if (current.ok()) {
        const latest = (await current.json() as { data: Inspection }).data;
        if (!['cancelled', 'passed', 'failed'].includes(latest.status)) {
          await actor.request.post(`${base}/api/v1/quality/inspections/${inspection.id}/cancel`, {
            headers,
            data: { reason: 'Cleanup after realtime probe', expected_result_version: latest.result_version },
          });
        }
      }
    }
    await Promise.all([actorContext.close(), detailContext?.close(), listContext?.close()].filter(Boolean));
  }
});
