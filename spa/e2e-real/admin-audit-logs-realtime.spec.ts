import { expect, test } from '@playwright/test';
import { baseUrl, loginAs } from './helpers';

const BASE_URL = baseUrl();

test('an open audit log list refreshes after a second admin session logs in', async ({ browser }) => {
  const viewerContext = await browser.newContext({ baseURL: BASE_URL });
  const actorContext = await browser.newContext({ baseURL: BASE_URL });
  const viewer = await viewerContext.newPage();
  const actor = await actorContext.newPage();
  const signalFrames: string[] = [];
  const refreshedLists: Array<{ total: number; actions: string[] }> = [];
  let subscribedToAuditChannel = false;

  viewer.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      const payload = typeof frame.payload === 'string' ? frame.payload : frame.payload.toString();
      if (payload.includes('subscription_succeeded') && payload.includes('private-admin.audit-logs')) {
        subscribedToAuditChannel = true;
      }
      if (payload.includes('admin.audit-logs.changed')) signalFrames.push(payload);
    });
  });
  viewer.on('response', (response) => {
    const url = new URL(response.url());
    if (response.request().method() !== 'GET' || url.pathname !== '/api/v1/admin/audit-logs') return;

    void response.json().then((body: {
      data?: Array<{ action?: string }>;
      meta?: { total?: number };
    }) => {
      if (response.status() === 200 && typeof body.meta?.total === 'number') {
        refreshedLists.push({
          total: body.meta.total,
          actions: (body.data ?? []).map((row) => row.action ?? ''),
        });
      }
    }).catch(() => undefined);
  });

  try {
    await loginAs(viewer, 'admin@ogami.test', 'password');
    await viewer.goto('/admin/audit-logs');
    await expect(viewer.getByRole('heading', { name: 'Audit logs' })).toBeVisible();
    const count = viewer.getByText(/^[\d,]+ entries$/);
    await expect(count).toBeVisible();
    const initialTotalText = (await count.textContent())?.match(/^[\d,]+/)?.[0] ?? '';
    const initialTotal = Number(initialTotalText.replaceAll(',', ''));
    expect(Number.isFinite(initialTotal)).toBe(true);

    await expect.poll(() => subscribedToAuditChannel, { timeout: 10_000 }).toBe(true);

    await loginAs(actor, 'admin@ogami.test', 'password');
    const actorLoginResponseObservedAt = Date.now();

    await expect.poll(() => signalFrames.length, { timeout: 5_000 }).toBeGreaterThan(0);
    await expect.poll(
      () => refreshedLists.some((list) => list.total > initialTotal && list.actions.includes('login.success')),
      { timeout: 5_000 },
    ).toBe(true);
    await expect.poll(async () => {
      const text = await count.textContent();
      const displayedTotal = text?.match(/^[\d,]+/)?.[0];
      return displayedTotal ? Number(displayedTotal.replaceAll(',', '')) : initialTotal;
    }, { timeout: 5_000 }).toBeGreaterThan(initialTotal);

    const peerUpdateLatencyMs = Date.now() - actorLoginResponseObservedAt;
    console.info(
      `[realtime-e2e] admin.audit-logs peer_update_ms=${peerUpdateLatencyMs} `
      + `event_received=${signalFrames.length > 0} `
      + `authorized_refetch=${refreshedLists.some((list) => list.total > initialTotal && list.actions.includes('login.success'))}`,
    );
    expect(peerUpdateLatencyMs).toBeLessThan(2_000);
  } finally {
    await Promise.all([viewerContext.close(), actorContext.close()]);
  }
});
