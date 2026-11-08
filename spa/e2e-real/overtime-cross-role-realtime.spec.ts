import { expect, test, type Page } from '@playwright/test';
import { baseUrl, loginAs, statefulHeaders } from './helpers';

const BASE_URL = baseUrl();

type BroadcastFrame = { event?: string; channel?: string; data?: unknown };
type SignalReceipt = {
  channelKind: 'personal-private' | 'entity-private' | 'other';
  eventId: string | null;
  action: string | null;
  version: number | string | null;
  occurredAt: string | null;
  receivedAt: string;
};

function signalReceipt(frame: BroadcastFrame): SignalReceipt | null {
  if (frame.event !== 'attendance.overtime.changed') return null;

  let payload = frame.data;
  if (typeof payload === 'string') {
    try {
      payload = JSON.parse(payload) as unknown;
    } catch {
      payload = null;
    }
  }
  const data = payload && typeof payload === 'object' ? payload as Record<string, unknown> : {};
  const channelKind = frame.channel?.startsWith('private-overtime.user.')
    ? 'personal-private'
    : frame.channel === 'private-entity.overtime_request.visible'
      ? 'entity-private'
      : 'other';

  return {
    channelKind,
    eventId: typeof data.event_id === 'string' ? data.event_id : null,
    action: typeof data.action === 'string' ? data.action : null,
    version: typeof data.version === 'number' || typeof data.version === 'string' ? data.version : null,
    occurredAt: typeof data.occurred_at === 'string' ? data.occurred_at : null,
    receivedAt: new Date().toISOString(),
  };
}

function watchFrames(page: Page, onFrame: (frame: BroadcastFrame) => void): void {
  page.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      if (typeof frame.payload !== 'string') return;
      try {
        onFrame(JSON.parse(frame.payload) as BroadcastFrame);
      } catch {
        // Ignore protocol frames that are not JSON messages.
      }
    });
  });
}

test('overtime request and approval refresh the requester and approver without reload', async ({ browser }) => {
  test.setTimeout(90_000);

  const requesterContext = await browser.newContext({ baseURL: BASE_URL });
  const approverContext = await browser.newContext({ baseURL: BASE_URL });
  const requester = await requesterContext.newPage();
  const approver = await approverContext.newPage();
  const reason = `Realtime cross-role ${Date.now()}`;
  const startedAt = Date.now();
  const scenarioStartedAt = new Date(startedAt).toISOString();
  const requesterChannels = new Set<string>();
  const approverChannels = new Set<string>();
  const requesterEventReceipts: SignalReceipt[] = [];
  const approverEventReceipts: SignalReceipt[] = [];
  let approvalIfMatch: string | undefined;
  const approverListReads: number[] = [];

  watchFrames(requester, (frame) => {
    if (frame.event === 'pusher_internal:subscription_succeeded' && frame.channel?.startsWith('private-overtime.user.')) {
      requesterChannels.add(frame.channel);
    }
    const receipt = signalReceipt(frame);
    if (receipt) requesterEventReceipts.push(receipt);
  });
  watchFrames(approver, (frame) => {
    if (frame.event === 'pusher_internal:subscription_succeeded' && frame.channel === 'private-entity.overtime_request.visible') {
      approverChannels.add(frame.channel);
    }
    const receipt = signalReceipt(frame);
    if (receipt) approverEventReceipts.push(receipt);
  });
  approver.on('response', (response) => {
    if (response.request().method() === 'GET' && response.url().includes('/api/v1/attendance/overtime-requests')) {
      approverListReads.push(Date.now() - startedAt);
    }
  });
  approver.on('request', (request) => {
    if (request.method() === 'PATCH' && request.url().endsWith('/approve')) {
      approvalIfMatch = request.headers()['if-match'];
    }
  });

  try {
    await loginAs(requester, 'employee@ogami.test', 'password');
    await loginAs(approver, 'depthead@ogami.test', 'password');
    await Promise.all([
      requester.goto('/self-service/overtime'),
      approver.goto('/hr/attendance/overtime?signal=awaiting_my_approval'),
    ]);

    await expect(requester.getByRole('heading', { name: 'Overtime Requests' })).toBeVisible();
    await expect(approver.getByRole('heading', { name: 'Overtime requests' })).toBeVisible();
    await expect.poll(() => requesterChannels.size, { timeout: 10_000 }).toBeGreaterThan(0);
    await expect.poll(() => approverChannels.size, { timeout: 10_000 }).toBe(1);

    await requester.getByRole('button', { name: 'Apply for OT', exact: true }).first().click();
    const optionsResponse = await approver.request.get(`${BASE_URL}/api/v1/attendance/overtime-requests/options`, {
      headers: statefulHeaders(),
    });
    expect(optionsResponse.status()).toBe(200);
    const { data: overtimeOptions } = await optionsResponse.json() as {
      data: { request_future_days: number };
    };
    const dateInput = requester.locator('input[type="date"]');
    await expect(dateInput).toHaveCount(1);
    const earliestDate = await dateInput.getAttribute('min');
    expect(earliestDate).toBeTruthy();
    const requestedDate = new Date(`${earliestDate}T00:00:00.000Z`);
    requestedDate.setUTCDate(requestedDate.getUTCDate() + overtimeOptions.request_future_days);
    await dateInput.fill(requestedDate.toISOString().slice(0, 10));
    await requester.getByRole('textbox', { name: 'Reason', exact: true }).fill(reason);
    const submitStartedAt = Date.now();
    const submitResponsePromise = requester.waitForResponse((response) =>
      response.request().method() === 'POST'
      && response.url().endsWith('/api/v1/hr/self-service/overtime'),
    );
    await requester.getByRole('button', { name: 'Submit for approval', exact: true }).click();
    const submitResponse = await submitResponsePromise;
    expect(submitResponse.status()).toBe(201);
    const submittedRecord = (await submitResponse.json()).data as { version: number };
    expect(submittedRecord.version).toBeGreaterThan(0);
    const submitResponseAt = Date.now();

    const requesterPendingRow = requester.getByRole('row').filter({ hasText: reason });
    await expect(requesterPendingRow).toContainText('Pending', { timeout: 2_000 });
    const requesterActorRenderMs = Date.now() - submitResponseAt;

    const approverPendingRow = approver.getByRole('row').filter({ hasText: reason });
    await expect(approverPendingRow).toBeVisible({ timeout: 10_000 });
    await expect(approverPendingRow.getByRole('button', { name: 'Approve', exact: true })).toBeVisible();
    const createPeerRenderMs = Date.now() - submitResponseAt;

    await approverPendingRow.getByRole('button', { name: 'Approve', exact: true }).click();
    const approvalDialog = approver.getByRole('dialog');
    const approvalResponsePromise = approver.waitForResponse((response) =>
      response.request().method() === 'PATCH'
      && response.url().includes('/api/v1/attendance/overtime-requests/')
      && response.url().endsWith('/approve'),
    );
    await approvalDialog.getByRole('button', { name: 'Approve', exact: true }).click();
    const approvalResponse = await approvalResponsePromise;
    expect(approvalResponse.status()).toBe(200);
    expect(approvalIfMatch).toBe(`"${submittedRecord.version}"`);
    const approvedRecord = (await approvalResponse.json()).data as { version: number };
    expect(approvedRecord.version).toBeGreaterThan(submittedRecord.version);
    const approvalResponseAt = Date.now();

    await expect(approverPendingRow).toHaveCount(0, { timeout: 5_000 });
    await expect(requester.getByRole('row').filter({ hasText: reason })).toContainText('Approved', { timeout: 10_000 });
    const decisionPeerRenderMs = Date.now() - approvalResponseAt;

    expect(requesterEventReceipts.length).toBeGreaterThanOrEqual(2);
    expect(approverEventReceipts.length).toBeGreaterThanOrEqual(2);
    expect(approverListReads.some((at) => at >= submitStartedAt - startedAt)).toBe(true);

    console.log(`OVERTIME_REALTIME ${JSON.stringify({
      requesterRole: 'employee',
      approverRole: 'department_head',
      approverListChannelSubscribed: approverChannels.size === 1,
      requesterPersonalChannelSubscribed: requesterChannels.size > 0,
      scenarioStartedAt,
      requesterEventReceipts,
      approverEventReceipts,
      requesterActorRenderMs,
      createPeerRenderMs,
      decisionPeerRenderMs,
      approverListReadOffsetsMs: approverListReads,
      approvalResponseStatus: approvalResponse.status(),
    })}`);
  } finally {
    await Promise.all([requesterContext.close(), approverContext.close()]);
  }
});
