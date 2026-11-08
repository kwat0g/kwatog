import { expect, test, type Page } from '@playwright/test';
import {
  baseUrl,
  loginAs,
  loginViaUi,
  statefulCsrfHeaders,
  statefulHeaders,
} from './helpers';

type RealtimeFrame = {
  event?: string;
  channel?: string;
  data?: unknown;
  receivedAt?: number;
};

function collectPortalSignals(
  page: Page,
  frames: RealtimeFrame[],
  authStatuses: number[],
  setPortalRedirectAt: (value: number) => void,
): void {
  page.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      try {
        const message = JSON.parse(String(frame.payload)) as RealtimeFrame;
        if (message.event === 'b2b.portal-access.revoked') {
          frames.push({ ...message, receivedAt: performance.now() });
        }
      } catch {
        // Ignore Pusher control frames; only record application events.
      }
    });
  });
  page.on('response', (response) => {
    if (response.url().includes('/api/v1/b2b/customer/broadcasting/auth')) {
      authStatuses.push(response.status());
    }
  });
  page.on('framenavigated', (frame) => {
    if (frame === page.mainFrame() && new URL(frame.url()).pathname.endsWith('/sign-in')) {
      setPortalRedirectAt(performance.now());
    }
  });
}

test('deactivating a customer portal account clears every open portal tab', async ({ browser }) => {
  test.setTimeout(90_000);

  const adminContext = await browser.newContext({ baseURL: baseUrl() });
  const customerContextA = await browser.newContext({ baseURL: baseUrl() });
  const customerContextB = await browser.newContext({ baseURL: baseUrl() });
  const adminPage = await adminContext.newPage();
  const customerPageA = await customerContextA.newPage();
  const customerPageB = await customerContextB.newPage();
  const revocationFramesA: RealtimeFrame[] = [];
  const revocationFramesB: RealtimeFrame[] = [];
  const channelAuthStatusesA: number[] = [];
  const channelAuthStatusesB: number[] = [];
  const portalRedirectAt: [number | null, number | null] = [null, null];
  let customerPortalUserId: string | undefined;
  let deactivated = false;
  let deactivationStartedAt: number | undefined;

  try {
    collectPortalSignals(customerPageA, revocationFramesA, channelAuthStatusesA, (at) => {
      if (deactivationStartedAt !== undefined && portalRedirectAt[0] === null) portalRedirectAt[0] = at;
    });
    collectPortalSignals(customerPageB, revocationFramesB, channelAuthStatusesB, (at) => {
      if (deactivationStartedAt !== undefined && portalRedirectAt[1] === null) portalRedirectAt[1] = at;
    });
    await loginAs(adminPage, 'admin@ogami.test', 'password');
    await loginViaUi(customerPageA, 'portal@cust.test', 'password');
    await loginViaUi(customerPageB, 'portal@cust.test', 'password');

    await customerPageA.goto('/portal/customer', { waitUntil: 'domcontentloaded' });
    await customerPageB.goto('/portal/customer', { waitUntil: 'domcontentloaded' });
    await expect(customerPageA.getByRole('navigation', { name: 'Customer Portal navigation' })).toBeVisible();
    await expect(customerPageB.getByRole('navigation', { name: 'Customer Portal navigation' })).toBeVisible();

    const customersResponse = await adminPage.request.get(
      `${baseUrl()}/api/v1/b2b/portal-access/customers?search=portal%40cust.test`,
      { headers: statefulHeaders() },
    );
    expect(customersResponse.status()).toBe(200);
    const customers = (await customersResponse.json() as {
      data: Array<{ id: string; email: string; status: string }>;
    }).data;
    const target = customers.find((customer) => customer.email === 'portal@cust.test');
    expect(target?.status, 'the shared E2E customer account must start active').toBe('active');
    customerPortalUserId = target?.id;
    expect(customerPortalUserId).toBeTruthy();

    const targetChannel = `private-portal.customer.user.${customerPortalUserId}`;
    await expect.poll(() => channelAuthStatusesA, {
      message: 'the first customer context subscribes through its own portal guard',
      timeout: 10_000,
    }).toContain(200);
    await expect.poll(() => channelAuthStatusesB, {
      message: 'the sibling customer context subscribes through its own portal guard',
      timeout: 10_000,
    }).toContain(200);

    const meUrl = `${baseUrl()}/api/v1/b2b/customer/me`;
    const activeIdentity = await customerContextA.request.get(meUrl, { headers: statefulHeaders() });
    expect(activeIdentity.status(), 'the portal guard grants access before deactivation').toBe(200);

    deactivationStartedAt = performance.now();
    const deactivate = await adminPage.request.patch(
      `${baseUrl()}/api/v1/b2b/portal-access/customers/${customerPortalUserId}/deactivate`,
      { headers: await statefulCsrfHeaders(adminPage), data: {} },
    );
    expect(deactivate.status(), await deactivate.text()).toBe(200);
    deactivated = true;

    const immediatePortalState = await Promise.all([customerPageA, customerPageB].map(async (page) => ({
      url: page.url(),
      navigationVisible: await page.getByRole('navigation', { name: 'Customer Portal navigation' }).isVisible().catch(() => false),
      title: await page.title(),
    })));
    await test.info().attach('portal-state-immediately-after-deactivation.json', {
      body: Buffer.from(JSON.stringify(immediatePortalState, null, 2)),
      contentType: 'application/json',
    });
    await Promise.all([
      customerPageA.screenshot().then((body) => test.info().attach('portal-tab-a-immediately-after-deactivation.png', {
        body,
        contentType: 'image/png',
      })),
      customerPageB.screenshot().then((body) => test.info().attach('portal-tab-b-immediately-after-deactivation.png', {
        body,
        contentType: 'image/png',
      })),
    ]);

    await expect.poll(() => revocationFramesA.length, {
      message: 'the exact customer portal principal should receive a content-free revocation event',
      timeout: 8_000,
      intervals: [100, 250, 500, 1_000],
    }).toBe(1);
    expect(revocationFramesA[0].channel).toBe(targetChannel);
    await expect.poll(() => revocationFramesB.length, {
      message: 'the same portal principal should receive the revocation in its sibling tab',
      timeout: 8_000,
      intervals: [100, 250, 500, 1_000],
    }).toBe(1);
    expect(revocationFramesB[0].channel).toBe(targetChannel);

    await expect(customerPageA).toHaveURL(/\/sign-in$/, { timeout: 10_000 });
    await expect(customerPageB).toHaveURL(/\/sign-in$/, { timeout: 10_000 });
    await expect(customerPageA.getByRole('navigation', { name: 'Customer Portal navigation' })).toHaveCount(0);
    await expect(customerPageB.getByRole('navigation', { name: 'Customer Portal navigation' })).toHaveCount(0);

    const deniedIdentity = await customerContextB.request.get(meUrl, { headers: statefulHeaders() });
    expect(deniedIdentity.status(), 'the server must deny reads after deactivation').toBe(401);

    const deliveryAndUiLatencyMs = {
      tabAEvent: Math.round((revocationFramesA[0].receivedAt ?? performance.now()) - deactivationStartedAt),
      tabBEvent: Math.round((revocationFramesB[0].receivedAt ?? performance.now()) - deactivationStartedAt),
      tabARedirect: Math.round((portalRedirectAt[0] ?? performance.now()) - deactivationStartedAt),
      tabBRedirect: Math.round((portalRedirectAt[1] ?? performance.now()) - deactivationStartedAt),
    };
    await test.info().attach('revocation-delivery-and-ui-latency.json', {
      body: Buffer.from(JSON.stringify(deliveryAndUiLatencyMs, null, 2)),
      contentType: 'application/json',
    });
    console.info(`[portal-access-revocation-latency] ${JSON.stringify(deliveryAndUiLatencyMs)}`);
    expect(Math.max(...Object.values(deliveryAndUiLatencyMs)), 'both open portal tabs meet the 2-second target')
      .toBeLessThanOrEqual(2_000);
  } finally {
    if (deactivated && customerPortalUserId) {
      await adminPage.request.patch(
        `${baseUrl()}/api/v1/b2b/portal-access/customers/${customerPortalUserId}/reactivate`,
        { headers: await statefulCsrfHeaders(adminPage), data: {} },
      );
    }
    await adminContext.close();
    await customerContextA.close();
    await customerContextB.close();
  }
});
