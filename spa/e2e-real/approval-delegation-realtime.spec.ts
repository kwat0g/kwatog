import { expect, test, type Page } from '@playwright/test';
import { baseUrl, loginAs, statefulCsrfHeaders, statefulHeaders } from './helpers';

type RealtimeFrame = {
  event?: string;
  channel?: string;
  data?: Record<string, unknown>;
  receivedAt?: number;
};

type PusherProtocolFrame = Omit<RealtimeFrame, 'data'> & { data?: unknown };

type SocketObservation = {
  path: string;
  openedAt: number;
  closeCount: number;
  socketErrorCount: number;
  protocolEvents: string[];
};

function watchDelegationSignals(
  page: Page,
  frames: RealtimeFrame[],
  managerChannelAuth: Array<{ channel: string | null; status: number }>,
  sockets: SocketObservation[],
): void {
  page.on('websocket', (socket) => {
    const observation: SocketObservation = {
      path: new URL(socket.url()).pathname,
      openedAt: Date.now(),
      closeCount: 0,
      socketErrorCount: 0,
      protocolEvents: [],
    };
    sockets.push(observation);
    socket.on('close', () => { observation.closeCount += 1; });
    socket.on('socketerror', () => { observation.socketErrorCount += 1; });
    socket.on('framereceived', (frame) => {
      try {
        const rawMessage = JSON.parse(String(frame.payload)) as PusherProtocolFrame;
        let data = rawMessage.data && typeof rawMessage.data === 'object' && !Array.isArray(rawMessage.data)
          ? rawMessage.data as Record<string, unknown>
          : undefined;
        if (typeof rawMessage.data === 'string') {
          try {
            const parsed = JSON.parse(rawMessage.data) as unknown;
            if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
              data = parsed as Record<string, unknown>;
            }
          } catch {
            // Keep non-JSON Pusher data opaque; it is not an application event payload.
          }
        }
        if (typeof rawMessage.event === 'string') {
          const channel = typeof rawMessage.channel === 'string' ? `:${rawMessage.channel}` : '';
          observation.protocolEvents.push(`${rawMessage.event}${channel}`);
        }
        if (rawMessage.event === 'approval.delegation.changed') {
          frames.push({ event: rawMessage.event, channel: rawMessage.channel, data, receivedAt: performance.now() });
        }
      } catch {
        // Ignore Pusher protocol frames.
      }
    });
  });
  page.on('response', (response) => {
    if (response.url().includes('/broadcasting/auth')) {
      const postData = response.request().postData() ?? '';
      let channel: string | null = null;
      try {
        const body = JSON.parse(postData) as { channel_name?: unknown };
        channel = typeof body.channel_name === 'string' ? body.channel_name : null;
      } catch {
        channel = new URLSearchParams(postData).get('channel_name');
      }
      if (channel === 'private-entity.approval_delegation.managers') {
        managerChannelAuth.push({ channel, status: response.status() });
      }
    }
  });
}

function dateInManila(daysFromNow: number): string {
  const date = new Date(Date.now() + daysFromNow * 24 * 60 * 60 * 1000);
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(date);
}

test('delegation create and revoke refresh an already-open manager list', async ({ browser }) => {
  test.setTimeout(90_000);
  const delegatorContext = await browser.newContext({ baseURL: baseUrl() });
  const managerContext = await browser.newContext({ baseURL: baseUrl() });
  const delegator = await delegatorContext.newPage();
  const manager = await managerContext.newPage();
  const managerFrames: RealtimeFrame[] = [];
  const managerChannelAuth: Array<{ channel: string | null; status: number }> = [];
  const managerSockets: SocketObservation[] = [];
  let createdDelegationId: string | undefined;
  let mutationStartedAt: number | undefined;
  let cleanupStatus: number | undefined;
  let revokeLatencyMs: number | undefined;
  let revokeFrameReceived = false;
  let revokedRowUpdated = false;
  let managerActionUpdated = false;

  watchDelegationSignals(manager, managerFrames, managerChannelAuth, managerSockets);

  try {
    await loginAs(delegator, 'finance@ogami.test', 'password');
    await loginAs(manager, 'admin@ogami.test', 'password');

    await manager.goto('/approvals/delegations', { waitUntil: 'domcontentloaded' });
    await expect(manager.getByRole('heading', { name: 'Current and past cover' })).toBeVisible();
    await expect.poll(() => managerChannelAuth.map((entry) => entry.status), {
      message: 'the manager list subscribes to its permission-gated private channel',
      timeout: 10_000,
    }).toContain(200);
    await expect(manager.getByRole('status', { name: 'Real-time updates: Live' })).toBeVisible({ timeout: 10_000 });

    const targetResponse = await delegator.request.get(
      `${baseUrl()}/api/v1/approval-delegations/candidates?q=purchasing%40ogami.test`,
      { headers: statefulHeaders() },
    );
    expect(targetResponse.status()).toBe(200);
    const target = (await targetResponse.json() as {
      data: Array<{ id: string; name: string; role: string | null }>;
    }).data.find((candidate) =>
      candidate.name.toLowerCase().includes('purchasing')
      || candidate.role?.toLowerCase().includes('purchasing'),
    );
    expect(target, 'the seeded purchasing account is available as a delegate').toBeTruthy();

    const delegationListResponse = await delegator.request.get(
      `${baseUrl()}/api/v1/approval-delegations`,
      { headers: statefulHeaders() },
    );
    expect(delegationListResponse.status()).toBe(200);
    const existing = (await delegationListResponse.json() as {
      data: Array<{ starts_at: string; ends_at: string; delegate: { id: string } | null }>;
    }).data;
    let candidateDay = 30 + Math.floor(Math.random() * 300);
    let startDate = dateInManila(candidateDay);
    let endDate = dateInManila(candidateDay + 2);
    while (existing.some((row) => row.delegate?.id === target?.id && row.starts_at === startDate && row.ends_at === endDate)) {
      candidateDay += 3;
      startDate = dateInManila(candidateDay);
      endDate = dateInManila(candidateDay + 2);
    }

    const reason = `realtime-manager-list-${Date.now()}`;
    await delegator.goto('/approvals/delegations', { waitUntil: 'domcontentloaded' });
    await delegator.getByLabel('Search active colleagues').fill('purchasing@ogami.test');
    await expect(delegator.getByRole('option', { name: /purchasing/i })).toBeAttached();
    await delegator.getByLabel('Delegate').selectOption(target!.id);
    await delegator.getByLabel('Start date').fill(startDate);
    await delegator.getByLabel('End date').fill(endDate);
    await delegator.getByLabel('Reason (optional)').fill(reason);

    const createResponsePromise = delegator.waitForResponse((response) =>
      response.url().endsWith('/api/v1/approval-delegations') && response.request().method() === 'POST',
    );
    mutationStartedAt = performance.now();
    await delegator.getByRole('button', { name: 'Set up approval cover', exact: true }).click();
    const createResponse = await createResponsePromise;
    expect(createResponse.status(), await createResponse.text()).toBe(201);
    const created = (await createResponse.json()) as { data: { id: string } };
    createdDelegationId = created.data.id;

    await expect(delegator.getByText(reason, { exact: true })).toBeVisible();
    await expect.poll(() => managerFrames.some((frame) => frame.channel === 'private-entity.approval_delegation.managers'), {
      message: 'the manager receives the minimal delegation refresh event',
      timeout: 10_000,
      intervals: [100, 250, 500, 1_000],
    }).toBe(true);
    await expect(manager.getByText(reason, { exact: true })).toBeVisible({ timeout: 10_000 });
    const managerRow = manager.locator('article').filter({ hasText: reason });
    await expect(managerRow.getByRole('button', { name: 'Revoke', exact: true })).toBeVisible();

    const event = managerFrames.find((frame) => frame.channel === 'private-entity.approval_delegation.managers');
    expect(event?.data).toMatchObject({ refresh: true, entity_type: 'approval_delegation', action: 'created' });
    const latencyMs = Math.round((event?.receivedAt ?? performance.now()) - mutationStartedAt);
    await test.info().attach('approval-delegation-manager-refresh.json', {
      body: Buffer.from(JSON.stringify({ latencyMs, eventId: event?.data?.event_id, reason }, null, 2)),
      contentType: 'application/json',
    });
    console.info(`[approval-delegation-manager-refresh] ${JSON.stringify({ latencyMs })}`);
    expect(latencyMs).toBeLessThanOrEqual(2_000);

    const revokeStartedAt = performance.now();
    const cleanup = await delegator.request.delete(
      `${baseUrl()}/api/v1/approval-delegations/${createdDelegationId}`,
      { headers: await statefulCsrfHeaders(delegator) },
    );
    cleanupStatus = cleanup.status();
    expect(cleanupStatus, await cleanup.text()).toBe(200);
    await expect.poll(() => managerFrames.some((frame) =>
      frame.channel === 'private-entity.approval_delegation.managers'
      && frame.data?.action === 'updated',
    ), {
      message: 'the manager receives the revoke refresh event',
      timeout: 5_000,
      intervals: [100, 250, 500, 1_000],
    }).toBe(true);
    const revokeFrame = managerFrames.find((frame) =>
      frame.channel === 'private-entity.approval_delegation.managers'
      && frame.data?.action === 'updated',
    );
    revokeFrameReceived = Boolean(revokeFrame);
    revokeLatencyMs = Math.round((revokeFrame?.receivedAt ?? performance.now()) - revokeStartedAt);
    console.info(`[approval-delegation-manager-revoke-refresh] ${JSON.stringify({ latencyMs: revokeLatencyMs })}`);
    expect(revokeLatencyMs).toBeLessThanOrEqual(2_000);
    await expect.poll(() => managerRow.getByText('Revoked', { exact: true }).isVisible(), {
      message: 'the manager list renders the retained record as revoked after the refresh event',
      timeout: 5_000,
      intervals: [100, 250, 500, 1_000],
    }).toBe(true);
    revokedRowUpdated = true;
    await expect(managerRow.getByRole('button', { name: 'Revoke', exact: true })).toHaveCount(0);
    managerActionUpdated = true;
  } finally {
    if (createdDelegationId && cleanupStatus !== 200) {
      const cleanup = await delegator.request.delete(
        `${baseUrl()}/api/v1/approval-delegations/${createdDelegationId}`,
        { headers: await statefulCsrfHeaders(delegator) },
      );
      cleanupStatus = cleanup.status();
    }
    await test.info().attach('approval-delegation-realtime-diagnostics.json', {
      body: Buffer.from(JSON.stringify({
        managerChannelAuth,
        managerSockets,
        cleanupStatus,
        revokeLatencyMs,
        revokeFrameReceived,
        revokedRowUpdated,
        managerActionUpdated,
        managerEvents: managerFrames.map((frame) => ({
          channel: frame.channel,
          action: frame.data?.action,
          receivedAt: frame.receivedAt,
        })),
      }, null, 2)),
      contentType: 'application/json',
    });
    await delegatorContext.close();
    await managerContext.close();
  }
});
