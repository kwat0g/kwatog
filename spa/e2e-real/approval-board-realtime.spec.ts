import { test, expect, type Page } from '@playwright/test';
import { baseUrl, loginAs } from './helpers';

const BASE_URL = baseUrl();

async function selectDepartment(page: Page): Promise<void> {
  const select = page.getByRole('combobox', { name: 'Department' });
  await select.click();
  await page.getByRole('option', { name: 'Production', exact: true }).click();
}

test('updates the approval board when a different role advances a request', async ({ browser }) => {
  const creatorContext = await browser.newContext({ baseURL: BASE_URL });
  const creator = await creatorContext.newPage();
  let requestNumber: string;
  let recordUrl: string;

  try {
    await loginAs(creator, 'purchasing@ogami.test', 'password');
    await creator.goto('/purchasing/purchase-requests/create');
    await expect(creator.getByRole('heading', { name: 'New purchase request' })).toBeVisible();
    await creator.getByLabel('Priority').selectOption('normal');
    await creator.getByLabel('Sourcing method').selectOption('direct_po');
    await selectDepartment(creator);
    await creator.getByLabel('Reason').fill(`Realtime approval board reproduction ${Date.now()}`);
    await creator.getByLabel('Description').fill('Disposable real-time approval verification');
    await creator.getByRole('textbox', { name: 'Unit', exact: true }).fill('lot');
    await creator.getByLabel('Quantity').fill('1');
    await creator.getByLabel('Estimated unit price').fill('60000');
    await creator.getByRole('button', { name: 'Submit for approval', exact: true }).click();
    const submitDialog = creator.getByRole('dialog');
    await submitDialog.getByRole('button', { name: 'Submit', exact: true }).click();
    await creator.waitForURL(/\/purchasing\/purchase-requests\/(?!create$)[^/]+$/);
    recordUrl = creator.url();
    await expect(creator.locator('#main-content')).toContainText(/PR-\d{6}-\d{4}/);
    const detailText = await creator.locator('#main-content').innerText();
    requestNumber = detailText.match(/PR-[A-Z0-9-]+/)?.[0] ?? '';
    expect(requestNumber).not.toBe('');
    await expect(creator.getByText('Pending', { exact: true }).first()).toBeVisible();
  } finally {
    await creatorContext.close();
  }

  const viewerContext = await browser.newContext({ baseURL: BASE_URL });
  const actorContext = await browser.newContext({ baseURL: BASE_URL });
  await actorContext.routeWebSocket(/.*/, (socket) => socket.close({
    code: 1000,
    reason: 'Actor socket disabled for API-response verification.',
  }));
  const viewer = await viewerContext.newPage();
  const actor = await actorContext.newPage();
  const boardReads: Array<{ at: number; status: number }> = [];
  const socketFrames: Array<{ at: number; payload: string }> = [];
  const subscribedChannels: string[] = [];
  const startedAt = Date.now();
  viewer.on('response', (response) => {
    if (response.request().method() === 'GET' && response.url().includes('/api/v1/approvals/board')) {
      boardReads.push({ at: Date.now() - startedAt, status: response.status() });
    }
  });
  viewer.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      const payload = typeof frame.payload === 'string' ? frame.payload : String(frame.payload);
      if (payload.includes('BadgesChanged') || payload.includes('counts.changed')) {
        socketFrames.push({ at: Date.now() - startedAt, payload });
      }
      if (payload.includes('private-badge-user.') && payload.includes('subscription_succeeded')) {
        subscribedChannels.push('badge-user');
      }
    });
  });

  try {
    await Promise.all([
      loginAs(viewer, 'vp@ogami.test', 'password'),
      loginAs(actor, 'finance@ogami.test', 'password'),
    ]);
    const initialBoardResponse = viewer.waitForResponse((response) =>
      response.request().method() === 'GET' && response.url().includes('/api/v1/approvals/board'));
    await viewer.goto('/approvals');
    const initialBoard = await initialBoardResponse;
    expect(initialBoard.status()).toBe(200);
    const initialBody = await initialBoard.json() as {
      data?: {
        my_action?: Array<{ number: string; type: string }>;
        awaiting_others?: Array<{ number: string; type: string; step_order: number }>;
      };
    };
    expect(initialBody.data?.my_action?.some((card) => card.number === requestNumber)).toBe(false);
    expect(initialBody.data?.awaiting_others?.some((card) => card.number === requestNumber && card.step_order === 1)).toBe(true);
    await expect(viewer.getByText(requestNumber, { exact: false })).toBeVisible();
    await expect.poll(() => subscribedChannels.length, { timeout: 10_000 }).toBeGreaterThan(0);
    const baselineBoardReads = boardReads.length;
    const relevantFramesBeforeAction = socketFrames.length;

    await actor.goto(recordUrl);
    const approveButton = actor.getByRole('button', { name: 'Approve', exact: true });
    await expect(approveButton).toBeVisible();
    const actionStartedAt = Date.now();
    const approvalResponsePromise = actor.waitForResponse((response) =>
      response.request().method() === 'PATCH'
      && response.url().includes('/api/v1/purchasing/purchase-requests/')
      && response.url().endsWith('/approve'));
    await approveButton.click();
    const approvalDialog = actor.getByRole('dialog');
    await approvalDialog.getByRole('button', { name: 'Approve', exact: true }).click();
    const approvalResponse = await approvalResponsePromise;
    expect(approvalResponse.status()).toBe(200);
    const actorResponseAt = Date.now();
    await expect(actor.getByText('Pending', { exact: true }).first()).toBeVisible();
    await expect(actor.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
    const actorResponseToActorRenderMs = Date.now() - actorResponseAt;

    const myActionColumn = viewer.getByText('My action required', { exact: true }).locator('xpath=../..');
    await expect(myActionColumn.getByRole('button').filter({ hasText: requestNumber }))
      .toBeVisible({ timeout: 2_000 });
    const actorResponseToPeerBoardRenderMs = Date.now() - actorResponseAt;
    expect(boardReads.length).toBeGreaterThan(baselineBoardReads);
    expect(socketFrames.length).toBeGreaterThan(relevantFramesBeforeAction);
    const actionSignal = socketFrames.find(({ at }) => at >= actionStartedAt - startedAt);
    expect(actionSignal).toBeDefined();
    expect(boardReads.some(({ at }) => at >= (actionSignal?.at ?? Number.POSITIVE_INFINITY)))
      .toBe(true);

    const freshBoardResponse = await viewer.request.get(`${BASE_URL}/api/v1/approvals/board`, {
      headers: { Origin: BASE_URL, Referer: `${BASE_URL}/`, Accept: 'application/json' },
    });
    expect(freshBoardResponse.status()).toBe(200);
    const freshBody = await freshBoardResponse.json() as {
      data?: { my_action?: Array<{ number: string; type: string; step_order: number }> };
    };
    expect(freshBody.data?.my_action?.some((card) => card.number === requestNumber && card.step_order === 2)).toBe(true);

    console.log(`APPROVAL_BOARD_REALTIME ${JSON.stringify({
      requestNumber,
      viewerRole: 'vice_president',
      actorRole: 'finance_officer',
      viewerBoardReads: boardReads,
      receivedRelevantSocketFrames: socketFrames.length,
      badgeChannelSubscribed: subscribedChannels.length > 0,
      actorSocketDisabled: true,
      boardCardMovedToMyAction: await myActionColumn.getByRole('button').filter({ hasText: requestNumber }).count() > 0,
      freshAuthorizedApiShowsNextStep: true,
      actorResponseToPeerBoardRenderMs,
      actorResponseToActorRenderMs,
      elapsedAfterActorActionMs: Date.now() - startedAt,
    })}`);
  } finally {
    await Promise.all([viewerContext.close(), actorContext.close()]);
  }
});
