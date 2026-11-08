import { expect, test } from '@playwright/test';
import { localIsoDate } from '../src/lib/formatDate';
import { baseUrl, loginAs, statefulCsrfHeaders, statefulHeaders } from './helpers';

type DriverDeliveryRow = { id: string; status: string; scheduled_date: string | null };

function unwrapRows(payload: unknown): DriverDeliveryRow[] {
  if (!payload || typeof payload !== 'object') return [];
  const root = payload as { data?: unknown };
  if (Array.isArray(root.data)) return root.data as DriverDeliveryRow[];
  if (root.data && typeof root.data === 'object' && Array.isArray((root.data as { data?: unknown }).data)) {
    return (root.data as { data: DriverDeliveryRow[] }).data;
  }
  return [];
}

async function dateForLabel(page: import('@playwright/test').Page, label: string): Promise<string> {
  return (await page.locator('dt').filter({ hasText: label }).evaluate((node) =>
    node.nextElementSibling?.textContent?.trim() ?? '',
  ));
}

test('driver run sheet refreshes when office reschedules an assigned delivery', async ({ browser }) => {
  test.setTimeout(120_000);

  const driverContext = await browser.newContext();
  const officeContext = await browser.newContext();
  const driverPage = await driverContext.newPage();
  const officePage = await officeContext.newPage();
  const base = baseUrl();

  let deliveryId: string | undefined;
  let originalDate: string | null = null;
  let driverDetailGets = 0;
  let driverDetailGetTimes: number[] = [];
  let driverDeliveryEvents = 0;
  let driverChannel: string | undefined;
  let driverEventAt: number | undefined;
  let driverRenderAt: number | undefined;
  driverPage.on('request', (request) => {
    const url = new URL(request.url());
    if (request.method() === 'GET' && url.pathname.includes('/api/v1/driver/deliveries/')) {
      driverDetailGets += 1;
      driverDetailGetTimes.push(Date.now());
    }
  });
  driverPage.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      if (typeof frame.payload !== 'string') return;
      try {
        const message = JSON.parse(frame.payload) as { event?: string; channel?: string };
        // Driver refreshes are sent on the driver's private personal channel;
        // the payload intentionally does not include delivery identifiers.
        if (message.event === 'chain.refresh' && message.channel === driverChannel) {
          driverDeliveryEvents += 1;
          driverEventAt ??= Date.now();
        }
      } catch {
        // Ignore non-JSON WebSocket protocol frames.
      }
    });
  });

  try {
    await loginAs(driverPage, 'driver@ogami.test', 'password');
    await loginAs(officePage, 'admin@ogami.test', 'password');

    const authenticatedDriver = await driverPage.request.get(`${base}/api/v1/auth/user`, {
      headers: statefulHeaders(),
    });
    expect(authenticatedDriver.status()).toBe(200);
    const driverUserId = (await authenticatedDriver.json()).data?.id as string | undefined;
    expect(driverUserId).toBeTruthy();
    driverChannel = `private-user.${driverUserId}`;

    const driverResponse = await driverPage.request.get(`${base}/api/v1/driver/deliveries?per_page=100`, {
      headers: statefulHeaders(),
    });
    expect(driverResponse.status()).toBe(200);
    const assigned = unwrapRows(await driverResponse.json());
    const scheduled = assigned.find((row) => row.status === 'scheduled' && row.scheduled_date);
    test.skip(!scheduled, 'No scheduled delivery is assigned to the E2E driver account.');
    if (!scheduled) return;
    deliveryId = scheduled.id;
    originalDate = scheduled.scheduled_date;

    await driverPage.goto(`/driver/${deliveryId}`, { waitUntil: 'domcontentloaded' });
    await expect(driverPage.getByText('Planned dispatch', { exact: true })).toBeVisible();
    const driverDateBefore = await dateForLabel(driverPage, 'Planned dispatch');

    await officePage.goto(`/supply-chain/deliveries/${deliveryId}`, { waitUntil: 'domcontentloaded' });
    await expect(officePage.getByText('Shipment date', { exact: true })).toBeVisible();
    const officeDateBefore = await dateForLabel(officePage, 'Shipment date');
    expect(officeDateBefore).toBe(driverDateBefore);

    const newDate = localIsoDate(new Date(Date.now() + 365 * 24 * 60 * 60 * 1000));
    const patchHeaders = await statefulCsrfHeaders(officePage);
    driverDeliveryEvents = 0;
    driverEventAt = undefined;
    driverDetailGetTimes = [];
    const mutationResponse = await officePage.request.patch(
      `${base}/api/v1/supply-chain/deliveries/${deliveryId}/reschedule`,
      {
        headers: patchHeaders,
        data: { scheduled_date: newDate, reason: 'Realtime diagnostic reschedule.' },
      },
    );
    expect(mutationResponse.status(), await mutationResponse.text()).toBe(200);
    const mutationResponseAt = Date.now();

    await expect.poll(() => dateForLabel(officePage, 'Shipment date'), { timeout: 10_000 }).not.toBe(officeDateBefore);
    await expect.poll(() => driverDeliveryEvents, { timeout: 10_000 }).toBeGreaterThan(0);
    expect(driverEventAt).toBeDefined();
    await expect.poll(
      () => driverDetailGetTimes.some((requestedAt) => requestedAt >= (driverEventAt ?? Infinity)),
      { timeout: 10_000 },
    ).toBe(true);
    await expect.poll(() => dateForLabel(driverPage, 'Planned dispatch'), { timeout: 10_000 }).not.toBe(driverDateBefore);
    driverRenderAt = Date.now();
    expect(driverDetailGets).toBeGreaterThanOrEqual(2);

    await test.info().attach('driver-delivery-realtime-baseline.json', {
      body: Buffer.from(JSON.stringify({
        deliveryId,
        mutationStatus: mutationResponse.status(),
        driverDateBefore,
        driverDateAfterEvent: await dateForLabel(driverPage, 'Planned dispatch'),
        officeDateAfterEventRefetch: await dateForLabel(officePage, 'Shipment date'),
        driverDetailGets,
        deliveryEventsObservedOnDriver: driverDeliveryEvents,
        driverChannel,
        eventReceivedAt: driverEventAt ? new Date(driverEventAt).toISOString() : null,
        eventMsFromMutationResponse: driverEventAt === undefined ? null : driverEventAt - mutationResponseAt,
        authorizedGetMsAfterEvent: driverEventAt === undefined || !driverDetailGetTimes.length
          ? null
          : Math.min(...driverDetailGetTimes.filter((requestedAt) => requestedAt >= driverEventAt)) - driverEventAt,
        renderMsFromMutationResponse: driverRenderAt === undefined ? null : driverRenderAt - mutationResponseAt,
      }, null, 2)),
      contentType: 'application/json',
    });
  } finally {
    if (deliveryId && originalDate) {
      const headers = await statefulCsrfHeaders(officePage).catch(() => null);
      if (headers) {
        const restoreResponse = await officePage.request.patch(`${base}/api/v1/supply-chain/deliveries/${deliveryId}/reschedule`, {
          headers,
          data: { scheduled_date: originalDate, reason: 'Restore date after realtime diagnostic.' },
        }).catch(() => null);
        expect(restoreResponse?.status(), 'The diagnostic delivery date must be restored.').toBe(200);
        const restoredList = await driverPage.request.get(`${base}/api/v1/driver/deliveries?per_page=100`, {
          headers: statefulHeaders(),
        });
        expect(restoredList.status()).toBe(200);
        const restored = unwrapRows(await restoredList.json()).find((row) => row.id === deliveryId);
        expect(restored?.scheduled_date).toBe(originalDate);
      }
    }
    await driverContext.close();
    await officeContext.close();
  }
});
