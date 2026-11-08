import { expect, test, type Page } from '@playwright/test';
import { baseUrl, loginAs } from './helpers';

const BASE_URL = baseUrl();

type BroadcastFrame = { event?: string; channel?: string; data?: string };
type PurchaseRequestListRead = {
  at: number;
  status: number;
  serverStatus?: string;
  cacheControl?: string;
  age?: string;
  serviceWorker?: boolean;
};

function watchPurchaseRequestFrames(page: Page, recordId: string, onFrame: (frame: BroadcastFrame) => void): void {
  page.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      if (typeof frame.payload !== 'string') return;
      try {
        const message = JSON.parse(frame.payload) as BroadcastFrame;
        if (message.channel === `private-chain.purchase_request.${recordId}`) onFrame(message);
      } catch {
        // Pusher protocol frames that are not JSON are not domain evidence.
      }
    });
  });
}

function watchPurchaseRequestListFrames(page: Page, onFrame: (frame: BroadcastFrame) => void): void {
  page.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      if (typeof frame.payload !== 'string') return;
      try {
        const message = JSON.parse(frame.payload) as BroadcastFrame;
        if (message.channel === 'private-entity.purchase_request.visible') onFrame(message);
      } catch {
        // Ignore non-JSON Pusher protocol frames.
      }
    });
  });
}

async function approvePurchaseRequest(page: Page, recordId: string): Promise<{
  respondedAt: number;
  serverStatus: string | undefined;
  cacheControl: string | undefined;
  age: string | undefined;
  via: string | undefined;
  cfCacheStatus: string | undefined;
}> {
  await page.goto(`/purchasing/purchase-requests/${recordId}`);
  const approveButton = page.getByRole('button', { name: 'Approve', exact: true });
  await expect(approveButton).toBeVisible();
  const responsePromise = page.waitForResponse((response) =>
    response.request().method() === 'PATCH'
    && response.url().includes(`/api/v1/purchasing/purchase-requests/${recordId}/approve`),
  );
  await approveButton.click();
  await page.getByRole('dialog').getByRole('button', { name: 'Approve', exact: true }).click();
  const response = await responsePromise;
  expect(response.status()).toBe(200);
  const body = await response.json() as { data?: { status?: unknown } };
  return {
    respondedAt: Date.now(),
    serverStatus: typeof body.data?.status === 'string' ? body.data.status : undefined,
    cacheControl: response.headers()['cache-control'],
    age: response.headers().age,
    via: response.headers().via,
    cfCacheStatus: response.headers()['cf-cache-status'],
  };
}

test('final PR approval refreshes an open peer detail and removes stale actions', async ({ browser }) => {
  test.setTimeout(90_000);

  const creatorContext = await browser.newContext({ baseURL: BASE_URL });
  const financeContext = await browser.newContext({ baseURL: BASE_URL });
  const viewerContext = await browser.newContext({ baseURL: BASE_URL });
  const actorContext = await browser.newContext({ baseURL: BASE_URL });
  await actorContext.routeWebSocket(/.*/, (socket) => socket.close({
    code: 1000,
    reason: 'Actor updates must come from the successful API response.',
  }));

  const creator = await creatorContext.newPage();
  const finance = await financeContext.newPage();
  const viewer = await viewerContext.newPage();
  const actor = await actorContext.newPage();
  let recordId = '';
  let requestNumber = '';
  let listChannelReady = false;
  const listRefreshReceivedAt: number[] = [];
  const listReads: PurchaseRequestListRead[] = [];
  const pendingListBodies: Promise<void>[] = [];
  let baselineListReadCount = 0;
  let baselineListSignalCount: number;
  const peerDetailReads: Array<{
    startedAt: number;
    completedAt: number;
    durationMs: number;
    status: number;
    serverStatus?: string;
    cacheControl?: string;
    age?: string;
    via?: string;
    cfCacheStatus?: string;
    serviceWorker?: boolean;
    serverCanCancel?: boolean;
  }> = [];
  const peerDetailRequestStarts = new Map<object, number>();
  const peerDetailRequestFailures: Array<{
    startedAt: number;
    failedAt: number;
    failure: string | null;
  }> = [];
  const deliveryHealthResponses: Array<{ at: number; status: number; delivery?: string }> = [];
  const pendingDetailBodies: Promise<void>[] = [];
  const viewerDocumentStatesAtEvents: Array<{
    at: number;
    visibilityState: DocumentVisibilityState;
    hasFocus: boolean;
  }> = [];
  let chainRefreshEvents = 0;
  const chainRefreshReceivedAt: number[] = [];
  let recordChannelReady = false;

  try {
    await loginAs(creator, 'purchasing@ogami.test', 'password');
    const reuseRecordId = process.env.E2E_REUSE_PR_ID;
    if (reuseRecordId) {
      recordId = reuseRecordId;
      const detailResponsePromise = creator.waitForResponse((response) =>
        response.request().method() === 'GET'
        && response.url().includes(`/api/v1/purchasing/purchase-requests/${recordId}`),
      );
      await creator.goto(`/purchasing/purchase-requests/${recordId}`);
      const detailResponse = await detailResponsePromise;
      expect(detailResponse.status()).toBe(200);
      const detailBody = await detailResponse.json() as {
        data?: { pr_number?: unknown; status?: unknown };
      };
      requestNumber = typeof detailBody.data?.pr_number === 'string' ? detailBody.data.pr_number : '';
      expect(detailBody.data?.status).toBe('pending');
      expect(requestNumber).not.toBe('');
    } else {
      await creator.goto('/purchasing/purchase-requests/create');
      await expect(creator.getByRole('heading', { name: 'New purchase request' })).toBeVisible();
      await creator.getByLabel('Priority').selectOption('normal');
      await creator.getByLabel('Sourcing method').selectOption('direct_po');
      const department = creator.getByRole('combobox', { name: 'Department' });
      await department.click();
      await creator.getByRole('option', { name: 'Production', exact: true }).click();
      await creator.getByLabel('Reason').fill(`Realtime final approval ${Date.now()}`);
      await creator.getByLabel('Description').fill('Disposable final approval freshness verification');
      await creator.getByRole('textbox', { name: 'Unit', exact: true }).fill('lot');
      await creator.getByLabel('Quantity').fill('1');
      await creator.getByLabel('Estimated unit price').fill('60000');
      await creator.getByRole('button', { name: 'Submit for approval', exact: true }).click();
      const createResponsePromise = creator.waitForResponse((response) =>
        response.request().method() === 'POST'
        && new URL(response.url()).pathname === '/api/v1/purchasing/purchase-requests',
      );
      await creator.getByRole('dialog').getByRole('button', { name: 'Submit', exact: true }).click();
      const createResponse = await createResponsePromise;
      expect(createResponse.status()).toBe(201);
      const createdBody = await createResponse.json() as { data?: { pr_number?: unknown } };
      requestNumber = typeof createdBody.data?.pr_number === 'string' ? createdBody.data.pr_number : '';
      expect(requestNumber).not.toBe('');
      await creator.waitForURL(/\/purchasing\/purchase-requests\/(?!create$)[^/]+$/);
      recordId = new URL(creator.url()).pathname.split('/').filter(Boolean).at(-1) ?? '';
      expect(recordId).not.toBe('');
    }
    creator.on('response', (response) => {
      if (response.request().method() !== 'GET') return;
      if (new URL(response.url()).pathname !== '/api/v1/purchasing/purchase-requests') return;
      const read: PurchaseRequestListRead = {
        at: Date.now(),
        status: response.status(),
        cacheControl: response.headers()['cache-control'],
        age: response.headers().age,
        serviceWorker: response.fromServiceWorker(),
      };
      listReads.push(read);
      pendingListBodies.push(response.json().then((body: unknown) => {
        const outer = body as { data?: unknown };
        const payload = outer?.data ?? body;
        const rows = Array.isArray(payload)
          ? payload
          : typeof payload === 'object' && payload !== null && 'data' in payload
            ? (payload as { data?: unknown }).data
            : undefined;
        if (!Array.isArray(rows)) return;
        const matching = rows.find((candidate) =>
          typeof candidate === 'object' && candidate !== null
          && 'pr_number' in candidate && candidate.pr_number === requestNumber,
        ) as { status?: unknown } | undefined;
        if (typeof matching?.status === 'string') read.serverStatus = matching.status;
      }).catch(() => undefined));
    });
    watchPurchaseRequestListFrames(creator, (frame) => {
      if (frame.event === 'pusher_internal:subscription_succeeded') listChannelReady = true;
      if (frame.event === 'chain.refresh') listRefreshReceivedAt.push(Date.now());
    });
    await creator.goto(`/purchasing/purchase-requests?search=${encodeURIComponent(requestNumber)}`);
    await expect(creator.getByRole('heading', { name: 'Purchase requests', exact: true })).toBeVisible();
    const initialCreatorListRow = creator.getByRole('row').filter({ hasText: requestNumber });
    await expect(initialCreatorListRow).toContainText('Pending');
    await expect.poll(() => listChannelReady, { timeout: 10_000 }).toBe(true);
    await Promise.all(pendingListBodies);
    expect(listReads.some((read) => read.status === 200 && read.serverStatus === 'pending')).toBe(true);

    if (process.env.E2E_SKIP_FINANCE !== '1') {
      await loginAs(finance, 'finance@ogami.test', 'password');
      await approvePurchaseRequest(finance, recordId);
      await expect(finance.getByText('Pending', { exact: true }).first()).toBeVisible();
      await expect(finance.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
      await expect.poll(() => listRefreshReceivedAt.length, { timeout: 5_000 }).toBeGreaterThan(0);
      await expect.poll(() => listReads.length, { timeout: 5_000 }).toBeGreaterThan(baselineListReadCount);
      await expect(creator.getByRole('row').filter({ hasText: requestNumber })).toContainText('Pending');
    }
    await Promise.all(pendingListBodies);
    baselineListReadCount = listReads.length;
    baselineListSignalCount = listRefreshReceivedAt.length;
  } catch (error) {
    await Promise.all([creatorContext.close(), financeContext.close(), viewerContext.close(), actorContext.close()]);
    throw error;
  }

  try {
    await loginAs(viewer, 'admin@ogami.test', 'password');
    const startedAt = Date.now();
    viewer.on('response', (response) => {
      if (response.url().includes('/api/v1/realtime/health')) {
        void response.json().then((body: { data?: { delivery?: string } }) => {
          deliveryHealthResponses.push({
            at: Date.now() - startedAt,
            status: response.status(),
            delivery: body.data?.delivery,
          });
        }).catch(() => {
          deliveryHealthResponses.push({ at: Date.now() - startedAt, status: response.status() });
        });
      }
      if (response.request().method() === 'GET'
        && response.url().includes(`/api/v1/purchasing/purchase-requests/${recordId}`)) {
        const completedAt = Date.now() - startedAt;
        const requestStartedAt = peerDetailRequestStarts.get(response.request()) ?? completedAt;
        const read = {
          startedAt: requestStartedAt,
          completedAt,
          durationMs: completedAt - requestStartedAt,
          status: response.status(),
          cacheControl: response.headers()['cache-control'],
          age: response.headers().age,
          via: response.headers().via,
          cfCacheStatus: response.headers()['cf-cache-status'],
          serviceWorker: response.fromServiceWorker(),
        };
        peerDetailReads.push(read);
        pendingDetailBodies.push(response.json().then((body: {
          data?: { status?: unknown; actions?: { can_cancel?: unknown } };
        }) => {
          if (typeof body.data?.status === 'string') read.serverStatus = body.data.status;
          if (typeof body.data?.actions?.can_cancel === 'boolean') {
            read.serverCanCancel = body.data.actions.can_cancel;
          }
        }).catch(() => undefined));
      }
    });
    viewer.on('request', (request) => {
      if (request.method() === 'GET'
        && request.url().includes(`/api/v1/purchasing/purchase-requests/${recordId}`)) {
        peerDetailRequestStarts.set(request, Date.now() - startedAt);
      }
    });
    viewer.on('requestfailed', (request) => {
      if (request.method() === 'GET'
        && request.url().includes(`/api/v1/purchasing/purchase-requests/${recordId}`)) {
        peerDetailRequestFailures.push({
          startedAt: peerDetailRequestStarts.get(request) ?? Date.now() - startedAt,
          failedAt: Date.now() - startedAt,
          failure: request.failure()?.errorText ?? null,
        });
      }
    });
    watchPurchaseRequestFrames(viewer, recordId, (frame) => {
      if (frame.event === 'pusher_internal:subscription_succeeded') recordChannelReady = true;
      if (frame.event === 'chain.refresh') {
        chainRefreshEvents += 1;
        chainRefreshReceivedAt.push(Date.now() - startedAt);
        void viewer.evaluate(() => ({
          visibilityState: document.visibilityState,
          hasFocus: document.hasFocus(),
        })).then((state) => viewerDocumentStatesAtEvents.push({ at: Date.now() - startedAt, ...state }));
      }
    });
    await viewer.goto(`/purchasing/purchase-requests/${recordId}`);
    await expect(viewer.locator('[data-layout="page-header"]').getByText('Pending', { exact: true })).toBeVisible();
    await expect(viewer.getByRole('button', { name: 'Cancel', exact: true })).toBeVisible();
    await viewer.evaluate(() => {
      const target = window as typeof window & {
        __purchaseRequestCancelActions?: { changedAt: number[]; visible: boolean };
      };
      const visible = () => Array.from(document.querySelectorAll('button'))
        .some((button) => button.textContent?.trim() === 'Cancel');
      const state = target.__purchaseRequestCancelActions = { changedAt: [], visible: visible() };
      const observer = new MutationObserver(() => {
        const next = visible();
        if (next !== state.visible) {
          state.visible = next;
          state.changedAt.push(Date.now());
        }
      });
      observer.observe(document.body, { childList: true, subtree: true, characterData: true });
      target.addEventListener('beforeunload', () => observer.disconnect(), { once: true });
    });
    await expect.poll(() => recordChannelReady, { timeout: 10_000 }).toBe(true);
    await loginAs(actor, 'vp@ogami.test', 'password');
    const actorResponse = await approvePurchaseRequest(actor, recordId);
    const actorResponseAt = actorResponse.respondedAt;
    await expect(actor.locator('[data-layout="page-header"]').getByText('Approved', { exact: true }))
      .toBeVisible({ timeout: 2_000 });
    await expect(actor.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);

    await expect(viewer.locator('[data-layout="page-header"]').getByText('Approved', { exact: true }))
      .toBeVisible({ timeout: 5_000 });
    await expect(viewer.getByRole('button', { name: 'Cancel', exact: true })).toHaveCount(0);
    const cancelActionVisibilityChanges = await viewer.evaluate(() =>
      (window as typeof window & {
        __purchaseRequestCancelActions?: { changedAt: number[] };
      }).__purchaseRequestCancelActions?.changedAt ?? [],
    );
    expect(cancelActionVisibilityChanges.length).toBeGreaterThan(0);
    // Use the in-page MutationObserver timestamp for the SLO. The locator
    // assertion above crosses the Playwright process boundary and adds driver
    // scheduling time that is not part of the user's visible update latency.
    const actorResponseToPeerRenderMs = cancelActionVisibilityChanges[0] - actorResponseAt;

    await expect.poll(() => listRefreshReceivedAt.length, { timeout: 5_000 }).toBeGreaterThan(baselineListSignalCount);
    await expect.poll(() => listReads.length, { timeout: 5_000 }).toBeGreaterThan(baselineListReadCount);
    const creatorListRow = creator.getByRole('row').filter({ hasText: requestNumber });
    await expect(creatorListRow).toContainText('Approved', { timeout: 5_000 });
    const actorResponseToListRenderMs = Date.now() - actorResponseAt;
    await Promise.all(pendingListBodies);
    const postActionListReads = listReads.filter((read) => read.at >= actorResponseAt);
    expect(postActionListReads.some((read) =>
      read.status === 200 && read.serverStatus === 'approved' && !read.serviceWorker,
    )).toBe(true);

    await Promise.all(pendingDetailBodies);
    const actorResponseMsFromStart = actorResponseAt - startedAt;
    const postActionDetailReads = peerDetailReads.filter((read) => read.completedAt >= actorResponseMsFromStart);
    const postActionFreshRead = postActionDetailReads.find((read) => read.serverStatus === 'approved');
    expect(chainRefreshEvents).toBeGreaterThan(0);
    expect(postActionDetailReads.length).toBeGreaterThan(0);
    expect(postActionFreshRead?.status).toBe(200);
    expect(postActionFreshRead?.serverCanCancel).toBe(false);

    console.log(`PURCHASE_REQUEST_FINAL_APPROVAL_REALTIME ${JSON.stringify({
      actorRole: 'vice_president',
      viewerRole: 'system_admin',
      actorSocketDisabled: true,
      actorServerStatus: actorResponse.serverStatus,
      actorResponseCache: {
        cacheControl: actorResponse.cacheControl,
        age: actorResponse.age,
        via: actorResponse.via,
        cfCacheStatus: actorResponse.cfCacheStatus,
      },
      recordChannelReady,
      receivedChainRefreshEvents: chainRefreshEvents,
      chainRefreshMsFromActorResponse: chainRefreshReceivedAt.map((at) => at - (actorResponseAt - startedAt)),
      viewerDocumentStatesAtEvents,
      deliveryHealthResponses,
      postActionDetailReads: postActionDetailReads.map((read) => ({
        ...read,
        startedMsFromActorResponse: read.startedAt - actorResponseMsFromStart,
      })),
      allDetailReads: peerDetailReads.map((read) => ({
        ...read,
        startedMsFromActorResponse: read.startedAt - actorResponseMsFromStart,
        completedMsFromActorResponse: read.completedAt - actorResponseMsFromStart,
      })),
      peerDetailRequestFailures: peerDetailRequestFailures.map((request) => ({
        ...request,
        failedMsFromActorResponse: request.failedAt - actorResponseMsFromStart,
      })),
      actorResponseToFirstDetailRefetchMs: postActionDetailReads[0]
        ? postActionDetailReads[0].startedAt - actorResponseMsFromStart
        : null,
      actorResponseToPeerRenderMs,
      actorResponseToListRenderMs,
      listRefreshMsFromActorResponse: listRefreshReceivedAt.map((at) => at - actorResponseAt),
      postActionListReads,
      cancelActionVisibilityChanges: cancelActionVisibilityChanges.map((at) => at - actorResponseAt),
      peerStatus: 'approved',
      staleCancelActionRemoved: true,
      listRenderedApprovedWithoutReload: true,
      actorResponseSloMs: 2_000,
    })}`);
    expect(actorResponseToPeerRenderMs).toBeLessThanOrEqual(2_000);
  } finally {
    await Promise.all([
      creatorContext.close(),
      financeContext.close(),
      viewerContext.close(),
      actorContext.close(),
    ]);
  }
});
