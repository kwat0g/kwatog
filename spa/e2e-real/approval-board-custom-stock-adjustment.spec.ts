import { expect, test, type Page } from '@playwright/test';
import { baseUrl, loginAs, ROLES, statefulCsrfHeaders, statefulHeaders } from './helpers';

const BASE_URL = baseUrl();
const inventoryTag = process.env.E2E_INVENTORY_TAG;

if (!inventoryTag || !/^[a-z0-9-]{1,6}$/.test(inventoryTag)) {
  throw new Error('Set E2E_INVENTORY_TAG to an existing isolated adjustment item tag.');
}

type ApprovalBoard = {
  my_action: Array<{ id: string; type: string }>;
};
type BadgeMap = Record<string, { count: number }>;

async function expectApprovalQueueBadge(page: Page, count: number): Promise<void> {
  if (count > 0) {
    const signalButton = page.getByRole('button', { name: /^View all Approval Queue signals:/ });
    await expect(signalButton).toHaveAccessibleName(
      new RegExp(`View all Approval Queue signals:.*${count}.*Approvals requiring your action`),
    );
    return;
  }
  const link = page.getByRole('link', { name: /^Approval Queue/ });
  await expect(link).toBeVisible();
  await expect(link).toHaveAccessibleName('Approval Queue');
}

async function readThreshold(page: Page): Promise<number> {
  const response = await page.request.get(BASE_URL + '/api/v1/admin/settings', {
    headers: statefulHeaders(),
  });
  expect(response.status()).toBe(200);
  const groups = (await response.json()).data as Record<string, Array<{ key: string; value: unknown }>>;
  const setting = Object.values(groups).flat().find((row) =>
    row.key === 'inventory.adjustment_approval_threshold',
  );
  expect(setting).toBeTruthy();
  return Number(setting!.value);
}

async function setThreshold(page: Page, value: number): Promise<void> {
  const response = await page.request.put(
    BASE_URL + '/api/v1/admin/settings/inventory.adjustment_approval_threshold',
    { headers: await statefulCsrfHeaders(page), data: { value } },
  );
  expect(response.status(), await response.text()).toBe(200);
}

test('a custom stock-adjustment decision removes its card from another role live approval board', async ({ browser }) => {
  const warehouse = ROLES.find(({ slug }) => slug === 'warehouse_staff')!;
  const finance = ROLES.find(({ slug }) => slug === 'finance_officer')!;
  const admin = ROLES.find(({ slug }) => slug === 'system_admin')!;
  const makerContext = await browser.newContext({ baseURL: BASE_URL });
  const actorContext = await browser.newContext({ baseURL: BASE_URL });
  const viewerContext = await browser.newContext({ baseURL: BASE_URL });
  await actorContext.routeWebSocket(/.*/, (socket) => socket.close({
    code: 1000,
    reason: 'The actor must render the API response without a WebSocket.',
  }));
  const maker = await makerContext.newPage();
  const actor = await actorContext.newPage();
  const viewer = await viewerContext.newPage();

  let previousThreshold: number | undefined;
  let thresholdChanged = false;
  let adjustmentId: string | undefined;
  let approved = false;
  const boardReads: number[] = [];
  const badgeReads: number[] = [];
  const badgeApprovalCounts: number[] = [];
  const boardFrames: string[] = [];
  const badgeSubscriptions: string[] = [];
  let adjustmentListChannelReady = false;
  let adjustmentListEvents = 0;
  const adjustmentListReads: number[] = [];
  const testStartedAt = Date.now();
  maker.on('response', (response) => {
    if (response.request().method() === 'GET'
      && new URL(response.url()).pathname === '/api/v1/inventory/stock-adjustments') {
      adjustmentListReads.push(Date.now() - testStartedAt);
    }
  });
  maker.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      if (typeof frame.payload !== 'string') return;
      try {
        const message = JSON.parse(frame.payload) as { event?: string; channel?: string };
        if (message.event === 'pusher_internal:subscription_succeeded'
          && message.channel === 'private-entity.stock_adjustment.visible') {
          adjustmentListChannelReady = true;
        }
        if (message.event === 'inventory.stock-adjustment.changed'
          && message.channel === 'private-entity.stock_adjustment.visible') {
          adjustmentListEvents += 1;
        }
      } catch {
        // Ignore non-event protocol frames.
      }
    });
  });
  viewer.on('response', (response) => {
    if (response.request().method() === 'GET'
      && new URL(response.url()).pathname === '/api/v1/approvals/board') {
      boardReads.push(Date.now() - testStartedAt);
    }
    if (response.request().method() === 'GET'
      && new URL(response.url()).pathname === '/api/v1/dashboards/badges') {
      badgeReads.push(Date.now() - testStartedAt);
      void response.json().then((body: { data?: BadgeMap }) => {
        badgeApprovalCounts.push(body.data?.approvals?.count ?? 0);
      }).catch(() => undefined);
    }
  });
  viewer.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      const payload = typeof frame.payload === 'string' ? frame.payload : String(frame.payload);
      if (payload.includes('BadgesChanged')) boardFrames.push(payload);
      if (payload.includes('private-badge-user.') && payload.includes('subscription_succeeded')) {
        badgeSubscriptions.push('badge-user');
      }
    });
  });

  try {
    await loginAs(maker, warehouse.email, warehouse.password);
    await loginAs(actor, finance.email, finance.password);
    await loginAs(viewer, admin.email, admin.password);

    previousThreshold = await readThreshold(viewer);
    if (previousThreshold !== 1) {
      thresholdChanged = true;
      await setThreshold(viewer, 1);
      expect(await readThreshold(viewer)).toBe(1);
    }

    const initialBoardPromise = viewer.waitForResponse((response) =>
      response.request().method() === 'GET'
      && new URL(response.url()).pathname === '/api/v1/approvals/board',
    );
    const initialBadgePromise = viewer.waitForResponse((response) =>
      response.request().method() === 'GET'
      && new URL(response.url()).pathname === '/api/v1/dashboards/badges',
    );
    await viewer.goto('/approvals');
    const initialBoardResponse = await initialBoardPromise;
    expect(initialBoardResponse.status()).toBe(200);
    const initialBadgeResponse = await initialBadgePromise;
    expect(initialBadgeResponse.status()).toBe(200);
    const initialBadges = (await initialBadgeResponse.json()).data as BadgeMap;
    const initialApprovalCount = initialBadges.approvals?.count ?? 0;
    await expect.poll(() => badgeSubscriptions.length, { timeout: 10_000 }).toBeGreaterThan(0);
    const boardReadsBeforeCreate = boardReads.length;
    const badgeReadsBeforeCreate = badgeReads.length;
    const boardFramesBeforeCreate = boardFrames.length;

    const reason = 'Realtime custom inbox ' + Date.now();
    const itemCode = 'E2E-ADJ-' + inventoryTag;
    await maker.goto('/inventory/stock-adjustments/create');
    await expect(maker.getByRole('heading', { name: 'Stock adjustment' })).toBeVisible();
    const itemPicker = maker.getByRole('combobox', { name: 'Item' });
    await itemPicker.fill(itemCode);
    const itemOption = maker.getByRole('option', { name: new RegExp('^' + itemCode + ' —') });
    await expect(itemOption).toBeVisible();
    await itemOption.click();

    const location = maker.locator('select[name="location_id"]');
    const reasonCode = maker.locator('select[name="reason_code"]');
    await maker.locator('select[name="direction"]').selectOption('in');
    await expect(location.locator('option').nth(1)).toBeAttached();
    await expect(reasonCode.locator('option').nth(1)).toBeAttached();
    await location.selectOption({ index: 1 });
    await reasonCode.selectOption({ index: 1 });
    await maker.locator('input[name="quantity"]').fill('1');
    await maker.locator('input[name="unit_cost"]').fill('50');
    await maker.locator('textarea[name="reason"]').fill(reason);

    const createResponsePromise = maker.waitForResponse((response) =>
      response.request().method() === 'POST'
      && new URL(response.url()).pathname === '/api/v1/inventory/stock-adjustments',
    );
    await maker.getByRole('button', { name: 'Review adjustment', exact: true }).click();
    const createDialog = maker.getByRole('dialog', { name: 'Submit stock adjustment?' });
    await expect(createDialog).toBeVisible();
    await createDialog.getByRole('button', { name: 'Submit adjustment', exact: true }).click();
    const createResponse = await createResponsePromise;
    expect(createResponse.status()).toBe(201);
    const created = (await createResponse.json()).data as { id: string; status: string };
    adjustmentId = created.id;
    expect(created.status).toBe('pending');

    await expect.poll(() => boardReads.length, { timeout: 5_000 }).toBeGreaterThan(boardReadsBeforeCreate);
    await expect.poll(() => badgeReads.length, { timeout: 5_000 }).toBeGreaterThan(badgeReadsBeforeCreate);
    await expect.poll(() => boardFrames.length, { timeout: 5_000 }).toBeGreaterThan(boardFramesBeforeCreate);
    await expect.poll(() => badgeApprovalCounts.at(-1), { timeout: 5_000 }).toBe(initialApprovalCount + 1);
    await expect(viewer.getByText(adjustmentId, { exact: false })).toBeVisible();
    const createdBadgesResponse = await viewer.request.get(BASE_URL + '/api/v1/dashboards/badges', {
      headers: statefulHeaders(),
    });
    expect(createdBadgesResponse.status()).toBe(200);
    const createdBadges = (await createdBadgesResponse.json()).data as BadgeMap;
    const createdApprovalCount = createdBadges.approvals?.count ?? 0;
    expect(createdApprovalCount).toBe(initialApprovalCount + 1);
    await expectApprovalQueueBadge(viewer, createdApprovalCount);

    // Keep the requester on an all-status list so a peer approval must update
    // both the row status and its Withdraw action without navigation.
    await maker.goto('/inventory/stock-adjustments?search=' + encodeURIComponent(reason));
    const makerRow = maker.getByRole('row').filter({ hasText: reason });
    await expect(makerRow.getByText('Pending', { exact: true })).toBeVisible();
    await expect(makerRow.getByRole('button', { name: 'Withdraw', exact: true })).toBeVisible();
    await expect.poll(() => adjustmentListChannelReady, { timeout: 10_000 }).toBe(true);

    const boardReadsBeforeApproval = boardReads.length;
    const badgeReadsBeforeApproval = badgeReads.length;
    const boardFramesBeforeApproval = boardFrames.length;

    await actor.goto('/inventory/stock-adjustments?search=' + encodeURIComponent(reason));
    const pendingRow = actor.getByRole('row').filter({ hasText: reason });
    await expect(pendingRow.getByRole('button', { name: 'Approve', exact: true })).toBeVisible();
    await expect(pendingRow.getByRole('button', { name: 'Reject', exact: true })).toBeVisible();
    const listReadsBeforeApproval = adjustmentListReads.length;
    const peerListRefetchPromise = maker.waitForResponse((response) =>
      response.request().method() === 'GET'
      && new URL(response.url()).pathname === '/api/v1/inventory/stock-adjustments',
      { timeout: 5_000 },
    );
    const approveResponsePromise = actor.waitForResponse((response) =>
      response.request().method() === 'PATCH'
      && new URL(response.url()).pathname === '/api/v1/inventory/stock-adjustments/' + adjustmentId + '/approve',
    );
    await pendingRow.getByRole('button', { name: 'Approve', exact: true }).click();
    const approveDialog = actor.getByRole('dialog', { name: 'Approve adjustment?' });
    await expect(approveDialog).toBeVisible();
    await approveDialog.getByRole('button', { name: 'Approve', exact: true }).click();
    const approveResponse = await approveResponsePromise;
    expect(approveResponse.status()).toBe(200);
    approved = true;
    const actorResponseAt = Date.now();

    await expect(pendingRow.getByText('Approved', { exact: true })).toBeVisible({ timeout: 5_000 });
    await expect(pendingRow.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
    await expect(pendingRow.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
    await expect(makerRow.getByText('Approved', { exact: true })).toBeVisible({ timeout: 5_000 });
    await expect(makerRow.getByRole('button', { name: 'Withdraw', exact: true })).toHaveCount(0);
    await peerListRefetchPromise;
    expect(adjustmentListReads.length).toBeGreaterThan(listReadsBeforeApproval);
    expect(adjustmentListEvents).toBeGreaterThan(0);
    const peerListRenderAt = Date.now();

    await expect.poll(() => boardReads.length, { timeout: 5_000 })
      .toBeGreaterThan(boardReadsBeforeApproval);
    await expect.poll(() => badgeReads.length, { timeout: 5_000 })
      .toBeGreaterThan(badgeReadsBeforeApproval);
    await expect(viewer.getByText(adjustmentId, { exact: false })).toHaveCount(0, { timeout: 5_000 });
    expect(boardFrames.length).toBeGreaterThan(boardFramesBeforeApproval);

    const freshBoardResponse = await viewer.request.get(BASE_URL + '/api/v1/approvals/board', {
      headers: statefulHeaders(),
    });
    expect(freshBoardResponse.status()).toBe(200);
    const freshBoard = (await freshBoardResponse.json()).data as ApprovalBoard;
    expect(freshBoard.my_action.some((card) =>
      card.id === adjustmentId && card.type === 'stock_adjustment',
    )).toBe(false);

    const freshBadgesResponse = await viewer.request.get(BASE_URL + '/api/v1/dashboards/badges', {
      headers: statefulHeaders(),
    });
    expect(freshBadgesResponse.status()).toBe(200);
    const freshBadges = (await freshBadgesResponse.json()).data as BadgeMap;
    const finalApprovalCount = freshBadges.approvals?.count ?? 0;
    expect(finalApprovalCount).toBe(initialApprovalCount);
    await expect.poll(() => badgeApprovalCounts.at(-1), { timeout: 5_000 }).toBe(finalApprovalCount);
    await expectApprovalQueueBadge(viewer, finalApprovalCount);

    console.info('[realtime-e2e] custom_stock_adjustment_peer_list_refresh_ms='
      + (peerListRenderAt - actorResponseAt)
      + '; entity_events=' + adjustmentListEvents
      + '; actor_buttons_reconciled_with_socket_disabled=true'
      + '; custom_stock_adjustment_board_refresh_ms='
      + ((boardReads[boardReadsBeforeApproval] ?? actorResponseAt - testStartedAt) - (actorResponseAt - testStartedAt))
      + '; event_frames=' + (boardFrames.length - boardFramesBeforeApproval)
      + '; actor_socket_disabled=true');
  } finally {
    if (adjustmentId && !approved) {
      const withdrawal = await maker.request.patch(
        BASE_URL + '/api/v1/inventory/stock-adjustments/' + adjustmentId + '/withdraw',
        { headers: await statefulCsrfHeaders(maker) },
      );
      expect(withdrawal.status()).toBe(200);
    }
    if (thresholdChanged && previousThreshold !== undefined) {
      await setThreshold(viewer, previousThreshold);
      expect(await readThreshold(viewer)).toBe(previousThreshold);
    }
    await Promise.all([makerContext.close(), actorContext.close(), viewerContext.close()]);
  }
});
