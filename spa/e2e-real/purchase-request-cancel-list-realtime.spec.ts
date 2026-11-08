import { expect, test, type Request } from '@playwright/test';
import { baseUrl, loginAs, statefulCsrfHeaders, statefulHeaders } from './helpers';

const BASE_URL = baseUrl();
const LIST_CHANNEL = 'private-entity.purchase_request.visible';

type BroadcastFrame = { event?: string; channel?: string };

test('cancelling a draft Purchase Request removes it from a peer filtered list without reload', async ({ browser }) => {
  test.setTimeout(60_000);

  const actorContext = await browser.newContext({ baseURL: BASE_URL });
  const viewerContext = await browser.newContext({ baseURL: BASE_URL });
  const actor = await actorContext.newPage();
  const viewer = await viewerContext.newPage();
  let listChannelReady = false;
  let listRefreshReceivedAt: number | null = null;
  const peerListRequestStartedAt = new WeakMap<Request, number>();
  const runStartedAt = Date.now();

  viewer.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      if (typeof frame.payload !== 'string') return;
      try {
        const message = JSON.parse(frame.payload) as BroadcastFrame;
        if (message.channel !== LIST_CHANNEL) return;
        if (message.event === 'pusher_internal:subscription_succeeded') listChannelReady = true;
        if (message.event === 'chain.refresh') listRefreshReceivedAt = Date.now();
      } catch {
        // Pusher protocol frames that are not JSON are not domain-event evidence.
      }
    });
  });

  viewer.on('request', (request) => {
    const url = new URL(request.url());
    if (request.method() === 'GET' && url.pathname === '/api/v1/purchasing/purchase-requests') {
      peerListRequestStartedAt.set(request, Date.now());
    }
  });

  try {
    await loginAs(actor, 'purchasing@ogami.test', 'password');
    const departmentResponse = await actor.request.get(
      `${BASE_URL}/api/v1/hr/departments?per_page=100`,
      { headers: statefulHeaders() },
    );
    expect(departmentResponse.status()).toBe(200);
    const departmentBody = await departmentResponse.json() as {
      data?: Array<{ id: string; name: string }>;
    };
    const productionDepartment = departmentBody.data?.find((department) => department.name === 'Production');
    expect(productionDepartment?.id).toBeTruthy();

    const createdResponse = await actor.request.post(
      `${BASE_URL}/api/v1/purchasing/purchase-requests`,
      {
        headers: await statefulCsrfHeaders(actor),
        data: {
          priority: 'normal',
          sourcing_method: 'direct_po',
          department_id: productionDepartment?.id,
          reason: `Realtime list cancel ${Date.now()}`,
          items: [{
            description: 'Disposable draft list freshness verification',
            quantity: '1',
            unit: 'lot',
            estimated_unit_price: '60000',
          }],
        },
      },
    );
    expect([200, 201]).toContain(createdResponse.status());
    const created = await createdResponse.json() as {
      data?: { id?: string; pr_number?: string; status?: string };
    };
    const recordId = created.data?.id ?? '';
    const recordNumber = created.data?.pr_number ?? '';
    expect(recordId).not.toBe('');
    expect(recordNumber).not.toBe('');
    expect(created.data?.status).toBe('draft');

    await loginAs(viewer, 'admin@ogami.test', 'password');
    await viewer.goto('/purchasing/purchase-requests?status=draft');
    const peerRow = viewer.getByRole('table').getByText(recordNumber, { exact: true });
    await expect(peerRow).toBeVisible({ timeout: 20_000 });
    await expect.poll(() => listChannelReady, { timeout: 10_000 }).toBe(true);

    const peerRefetchPromise = viewer.waitForResponse((response) => {
      const url = new URL(response.url());
      const requestStartedAt = peerListRequestStartedAt.get(response.request());
      return response.request().method() === 'GET'
        && url.pathname === '/api/v1/purchasing/purchase-requests'
        && url.searchParams.get('status') === 'draft'
        && listRefreshReceivedAt !== null
        && requestStartedAt !== undefined
        && requestStartedAt >= listRefreshReceivedAt;
    }, { timeout: 15_000 });

    const cancelRequestStartedAt = Date.now();
    const cancelResponse = await actor.request.patch(
      `${BASE_URL}/api/v1/purchasing/purchase-requests/${recordId}/cancel`,
      { headers: await statefulCsrfHeaders(actor) },
    );
    const cancelResponseAt = Date.now();
    expect(cancelResponse.status()).toBe(200);
    const cancelBody = await cancelResponse.json() as { data?: { status?: string } };
    expect(cancelBody.data?.status).toBe('cancelled');

    const peerRefetch = await peerRefetchPromise;
    const peerRefetchAt = Date.now();
    const peerRefetchStartedAt = peerListRequestStartedAt.get(peerRefetch.request()) ?? 0;
    expect(peerRefetch.status()).toBe(200);
    const peerBody = await peerRefetch.json() as { data?: Array<{ id?: string }> };
    const peerResultContainsRecord = peerBody.data?.some((request) => request.id === recordId) ?? false;
    expect(peerResultContainsRecord).toBe(false);
    await expect(peerRow).toHaveCount(0, { timeout: 10_000 });

    console.log(`PURCHASE_REQUEST_CANCEL_LIST_REALTIME ${JSON.stringify({
      actorRole: 'purchasing',
      viewerRole: 'system_admin',
      actorStatus: cancelBody.data?.status,
      listChannelReady,
      listSignalMsFromCancelRequest: listRefreshReceivedAt === null
        ? null
        : listRefreshReceivedAt - cancelRequestStartedAt,
      listSignalMsFromCancelResponse: listRefreshReceivedAt === null
        ? null
        : listRefreshReceivedAt - cancelResponseAt,
      peerRefetchStartedMsFromSignal: listRefreshReceivedAt === null
        ? null
        : peerRefetchStartedAt - listRefreshReceivedAt,
      peerRefetchCompletedMsFromCancelResponse: peerRefetchAt - cancelResponseAt,
      peerListStatus: peerRefetch.status(),
      peerListContainsCancelledRecord: peerResultContainsRecord,
      peerRowRemovedWithoutReload: true,
      testElapsedMs: Date.now() - runStartedAt,
    })}`);
  } finally {
    await Promise.all([actorContext.close(), viewerContext.close()]);
  }
});
