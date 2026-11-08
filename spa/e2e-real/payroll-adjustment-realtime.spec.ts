import { expect, test, type Page } from '@playwright/test';
import { baseUrl, loginAs, ROLES, statefulCsrfHeaders, statefulHeaders } from './helpers';

const BASE_URL = baseUrl();

type PayrollList = {
  data: Array<{
    id: string;
    employee?: { employee_no: string; full_name: string } | null;
    period_start?: string;
    period_end?: string;
  }>;
};

async function createPendingAdjustment(maker: Page, reason: string): Promise<string> {
  const payrollResponse = await maker.request.get(BASE_URL + '/api/v1/payrolls?per_page=25', {
    headers: statefulHeaders(),
  });
  expect(payrollResponse.status()).toBe(200);
  const payrolls = (await payrollResponse.json()).data as PayrollList['data'];
  expect(payrolls.length).toBeGreaterThan(0);

  const createResponse = await maker.request.post(BASE_URL + '/api/v1/payroll-adjustments', {
    headers: await statefulCsrfHeaders(maker),
    data: {
      original_payroll_id: payrolls[0].id,
      type: 'overpayment',
      amount: '777.77',
      reason,
    },
  });
  expect(createResponse.status(), await createResponse.text()).toBe(201);
  const created = (await createResponse.json()).data as { id: string; status: string };
  expect(created.status).toBe('pending');
  return created.id;
}
test('payroll adjustment create and approval refresh authorized viewers and actions', async ({ browser }) => {
  const hr = ROLES.find(({ slug }) => slug === 'hr_officer')!;
  const admin = ROLES.find(({ slug }) => slug === 'system_admin')!;
  const makerContext = await browser.newContext({ baseURL: BASE_URL });
  const actorContext = await browser.newContext({ baseURL: BASE_URL });
  const viewerContext = await browser.newContext({ baseURL: BASE_URL });
  await actorContext.routeWebSocket(/.*/, (socket) => socket.close({
    code: 1000,
    reason: 'The acting user must render the API response without a WebSocket.',
  }));

  const maker = await makerContext.newPage();
  const actor = await actorContext.newPage();
  const viewer = await viewerContext.newPage();
  const boardFrames: string[] = [];
  const boardReads: number[] = [];
  const recordFrames: string[] = [];
  const boardSubscriptions: string[] = [];
  const recordSubscriptions: string[] = [];
  const startedAt = Date.now();
  let adjustmentId: string | undefined;
  let completed = false;

  viewer.on('response', (response) => {
    if (response.request().method() === 'GET'
      && new URL(response.url()).pathname === '/api/v1/approvals/board') {
      boardReads.push(Date.now() - startedAt);
    }
  });
  viewer.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      const payload = typeof frame.payload === 'string' ? frame.payload : String(frame.payload);
      if (payload.includes('BadgesChanged')) boardFrames.push(payload);
      if (payload.includes('private-badge-user.') && payload.includes('subscription_succeeded')) {
        boardSubscriptions.push('badge-user');
      }
    });
  });
  maker.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      const payload = typeof frame.payload === 'string' ? frame.payload : String(frame.payload);
      if (payload.includes('payroll.adjustment.changed')) recordFrames.push(payload);
      if (payload.includes('private-payroll.adjustment.') && payload.includes('subscription_succeeded')) {
        recordSubscriptions.push('payroll.adjustment');
      }
    });
  });

  try {
    await loginAs(maker, hr.email, hr.password);
    await loginAs(actor, admin.email, admin.password);
    await loginAs(viewer, admin.email, admin.password);

    const boardResponsePromise = viewer.waitForResponse((response) =>
      response.request().method() === 'GET'
      && new URL(response.url()).pathname === '/api/v1/approvals/board',
    );
    await viewer.goto('/approvals');
    const boardResponse = await boardResponsePromise;
    expect(boardResponse.status()).toBe(200);
    await expect.poll(() => boardSubscriptions.length, { timeout: 10_000 }).toBeGreaterThan(0);

    const payrollResponse = await maker.request.get(BASE_URL + '/api/v1/payrolls?per_page=25', {
      headers: statefulHeaders(),
    });
    expect(payrollResponse.status()).toBe(200);
    const payrolls = (await payrollResponse.json()).data as PayrollList['data'];
    expect(payrolls.length).toBeGreaterThan(0);

    await maker.goto('/payroll/adjustments/create');
    const sourcePayroll = maker.getByRole('combobox', { name: 'Source payroll' });
    const employeeNumber = payrolls[0].employee?.employee_no;
    expect(employeeNumber).toBeTruthy();
    const filteredPayrollResponsePromise = maker.waitForResponse((response) => {
      const url = new URL(response.url());
      return response.request().method() === 'GET'
        && url.pathname === '/api/v1/payrolls'
        && url.searchParams.get('search') === employeeNumber;
    });
    await sourcePayroll.fill(employeeNumber!);
    const filteredPayrollResponse = await filteredPayrollResponsePromise;
    expect(filteredPayrollResponse.status()).toBe(200);
    const payrollOption = maker.getByRole('option')
      .filter({ hasText: employeeNumber! })
      .filter({ hasText: payrolls[0].period_start! })
      .filter({ hasText: payrolls[0].period_end! });
    await expect(payrollOption).toBeVisible();
    await payrollOption.click();
    await maker.getByLabel('Type').selectOption('underpayment');
    await maker.getByLabel('Amount').fill('1234.56');
    const reason = 'Realtime payroll adjustment ' + Date.now();
    await maker.getByLabel('Reason').fill(reason);

    const createResponsePromise = maker.waitForResponse((response) =>
      response.request().method() === 'POST'
      && new URL(response.url()).pathname === '/api/v1/payroll-adjustments',
    );
    await maker.getByRole('button', { name: 'Submit adjustment', exact: true }).click();
    const createResponse = await createResponsePromise;
    expect(createResponse.status()).toBe(201);
    const created = (await createResponse.json()).data as { id: string; status: string };
    adjustmentId = created.id;
    expect(created.status).toBe('pending');

    await expect.poll(() => boardReads.length, { timeout: 5_000 }).toBeGreaterThan(1);
    await expect(viewer.getByText(adjustmentId, { exact: false })).toBeVisible();
    await expect.poll(() => boardFrames.length, { timeout: 5_000 }).toBeGreaterThan(0);

    await maker.goto('/payroll/adjustments/' + adjustmentId);
    await expect(maker.getByRole('heading', { name: 'Payroll adjustment' })).toBeVisible();
    await expect.poll(() => recordSubscriptions.length, { timeout: 10_000 }).toBeGreaterThan(0);

    await actor.goto('/payroll/adjustments');
    const pendingRow = actor.getByRole('row').filter({ hasText: reason });
    await expect(pendingRow.getByRole('button', { name: 'Approve', exact: true })).toBeVisible();
    const approveResponsePromise = actor.waitForResponse((response) =>
      response.request().method() === 'PATCH'
      && new URL(response.url()).pathname === '/api/v1/payroll-adjustments/' + adjustmentId + '/approve',
    );
    const boardReadsBeforeApproval = boardReads.length;
    const boardFramesBeforeApproval = boardFrames.length;
    await pendingRow.getByRole('button', { name: 'Approve', exact: true }).click();
    const dialog = actor.getByRole('dialog', { name: 'Approve payroll adjustment?' });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Approve', exact: true }).click();
    const approveResponse = await approveResponsePromise;
    expect(approveResponse.status()).toBe(200);
    completed = true;
    const actorResponseAt = Date.now();

    await expect(pendingRow.getByText('Approved', { exact: true })).toBeVisible();
    await expect(pendingRow.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
    await expect(maker.getByText('Approved', { exact: true })).toBeVisible({ timeout: 5_000 });
    await expect(maker.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
    await expect.poll(() => boardReads.length, { timeout: 5_000 }).toBeGreaterThan(boardReadsBeforeApproval);
    await expect(viewer.getByText(adjustmentId, { exact: false })).toHaveCount(0, { timeout: 5_000 });
    await expect.poll(() => boardFrames.length, { timeout: 5_000 }).toBeGreaterThan(boardFramesBeforeApproval);
    await expect.poll(() => recordFrames.length, { timeout: 5_000 }).toBeGreaterThan(0);

    console.info('[realtime-e2e] payroll_adjustment_board_refetch_ms='
      + ((boardReads[boardReadsBeforeApproval] ?? Date.now() - startedAt) - (actorResponseAt - startedAt))
      + '; maker_detail_fresh=true'
      + '; actor_socket_disabled=true');
  } finally {
    if (adjustmentId && !completed) {
      const cleanup = await actor.request.patch(
        BASE_URL + '/api/v1/payroll-adjustments/' + adjustmentId + '/approve',
        { headers: await statefulCsrfHeaders(actor) },
      );
      expect(cleanup.status()).toBe(200);
    }
    await Promise.all([makerContext.close(), actorContext.close(), viewerContext.close()]);
  }
});

test('payroll adjustment rejection refreshes the actor, open detail, and approval board', async ({ browser }) => {
  const hr = ROLES.find(({ slug }) => slug === 'hr_officer')!;
  const admin = ROLES.find(({ slug }) => slug === 'system_admin')!;
  const makerContext = await browser.newContext({ baseURL: BASE_URL });
  const actorContext = await browser.newContext({ baseURL: BASE_URL });
  const viewerContext = await browser.newContext({ baseURL: BASE_URL });
  await actorContext.routeWebSocket(/.*/, (socket) => socket.close({
    code: 1000,
    reason: 'The acting user must render the API response without a WebSocket.',
  }));

  const maker = await makerContext.newPage();
  const actor = await actorContext.newPage();
  const viewer = await viewerContext.newPage();
  const boardFrames: string[] = [];
  const boardReads: number[] = [];
  const recordFrames: string[] = [];
  const recordSubscriptions: string[] = [];
  const startedAt = Date.now();
  let adjustmentId: string | undefined;
  let completed = false;

  viewer.on('response', (response) => {
    if (response.request().method() === 'GET'
      && new URL(response.url()).pathname === '/api/v1/approvals/board') {
      boardReads.push(Date.now() - startedAt);
    }
  });
  viewer.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      const payload = typeof frame.payload === 'string' ? frame.payload : String(frame.payload);
      if (payload.includes('BadgesChanged')) boardFrames.push(payload);
    });
  });
  maker.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      const payload = typeof frame.payload === 'string' ? frame.payload : String(frame.payload);
      if (payload.includes('payroll.adjustment.changed')) recordFrames.push(payload);
      if (payload.includes('private-payroll.adjustment.') && payload.includes('subscription_succeeded')) {
        recordSubscriptions.push('payroll.adjustment');
      }
    });
  });

  try {
    await loginAs(maker, hr.email, hr.password);
    await loginAs(actor, admin.email, admin.password);
    await loginAs(viewer, admin.email, admin.password);

    const reason = 'Realtime payroll rejection ' + Date.now();
    adjustmentId = await createPendingAdjustment(maker, reason);
    await viewer.goto('/approvals');
    await expect(viewer.getByText(adjustmentId, { exact: false })).toBeVisible();

    await maker.goto('/payroll/adjustments/' + adjustmentId);
    await expect.poll(() => recordSubscriptions.length, { timeout: 10_000 }).toBeGreaterThan(0);

    await actor.goto('/payroll/adjustments');
    const pendingRow = actor.getByRole('row').filter({ hasText: reason });
    await expect(pendingRow.getByRole('button', { name: 'Reject', exact: true })).toBeVisible();
    const rejectResponsePromise = actor.waitForResponse((response) =>
      response.request().method() === 'PATCH'
      && new URL(response.url()).pathname === '/api/v1/payroll-adjustments/' + adjustmentId + '/reject',
    );
    const boardReadsBeforeReject = boardReads.length;
    const boardFramesBeforeReject = boardFrames.length;
    await pendingRow.getByRole('button', { name: 'Reject', exact: true }).click();
    const rejectDialog = actor.getByRole('dialog', { name: 'Reject adjustment' });
    await expect(rejectDialog).toBeVisible();
    await rejectDialog.getByLabel('Reason for rejection').fill('Duplicate payroll entry');
    await rejectDialog.getByRole('button', { name: 'Confirm reject', exact: true }).click();
    const rejectResponse = await rejectResponsePromise;
    expect(rejectResponse.status()).toBe(200);
    completed = true;
    const actorResponseAt = Date.now();

    await expect(pendingRow.getByText('Rejected', { exact: true })).toBeVisible();
    await expect(pendingRow.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
    await expect(maker.getByText('Rejected', { exact: true })).toBeVisible({ timeout: 5_000 });
    await expect.poll(() => boardReads.length, { timeout: 5_000 }).toBeGreaterThan(boardReadsBeforeReject);
    await expect(viewer.getByText(adjustmentId, { exact: false })).toHaveCount(0, { timeout: 5_000 });
    await expect.poll(() => boardFrames.length, { timeout: 5_000 }).toBeGreaterThan(boardFramesBeforeReject);
    await expect.poll(() => recordFrames.length, { timeout: 5_000 }).toBeGreaterThan(0);

    console.info('[realtime-e2e] payroll_adjustment_reject_board_refetch_ms='
      + ((boardReads[boardReadsBeforeReject] ?? Date.now() - startedAt) - (actorResponseAt - startedAt))
      + '; maker_detail_fresh=true; actor_socket_disabled=true');
  } finally {
    if (adjustmentId && !completed) {
      const cleanup = await actor.request.patch(
        BASE_URL + '/api/v1/payroll-adjustments/' + adjustmentId + '/reject',
        {
          headers: await statefulCsrfHeaders(actor),
          data: { remarks: 'E2E cleanup decision' },
        },
      );
      expect(cleanup.status()).toBe(200);
    }
    await Promise.all([makerContext.close(), actorContext.close(), viewerContext.close()]);
  }
});

test('refreshes an open approval dialog in another browser context after approval', async ({ browser }) => {
  const hr = ROLES.find(({ slug }) => slug === 'hr_officer')!;
  const admin = ROLES.find(({ slug }) => slug === 'system_admin')!;
  const makerContext = await browser.newContext({ baseURL: BASE_URL });
  const maker = await makerContext.newPage();
  let makerClosed = false;
  let adjustmentId: string | undefined;
  let completed = false;
  let actorResponseAt: number | undefined;
  let actorContext: Awaited<ReturnType<typeof browser.newContext>> | undefined;
  let peerContext: Awaited<ReturnType<typeof browser.newContext>> | undefined;

  try {
    await loginAs(maker, hr.email, hr.password);
    const reason = 'Realtime stale-dialog check ' + Date.now();
    adjustmentId = await createPendingAdjustment(maker, reason);
    await makerContext.close();
    makerClosed = true;

    // Keep the active freshness scenario to two browser contexts; the maker
    // context is closed before either reviewer opens the same pending row.
    actorContext = await browser.newContext({ baseURL: BASE_URL });
    peerContext = await browser.newContext({ baseURL: BASE_URL });
    await actorContext.routeWebSocket(/.*/, (socket) => socket.close({
      code: 1000,
      reason: 'The acting user must render the API response without a WebSocket.',
    }));
    const actor = await actorContext.newPage();
    const peer = await peerContext.newPage();
    const peerFrames: string[] = [];
    const peerListSubscriptions: string[] = [];

    peer.on('websocket', (socket) => {
      socket.on('framereceived', (frame) => {
        const payload = typeof frame.payload === 'string' ? frame.payload : String(frame.payload);
        if (payload.includes('payroll.adjustment.changed')) peerFrames.push(payload);
        if (payload.includes('private-entity.payroll_adjustment.visible')
          && payload.includes('subscription_succeeded')) {
          peerListSubscriptions.push('payroll_adjustment');
        }
      });
    });

    await loginAs(actor, admin.email, admin.password);
    await loginAs(peer, admin.email, admin.password);
    await peer.goto('/payroll/adjustments');
    const peerRow = peer.getByRole('row').filter({ hasText: reason });
    await expect(peerRow.getByRole('button', { name: 'Approve', exact: true })).toBeVisible();
    await expect.poll(() => peerListSubscriptions.length, { timeout: 10_000 }).toBeGreaterThan(0);

    await peerRow.getByRole('button', { name: 'Approve', exact: true }).click();
    const peerDialog = peer.getByRole('dialog', { name: 'Approve payroll adjustment?' });
    await expect(peerDialog).toBeVisible();
    await expect(peerDialog.getByRole('button', { name: 'Approve', exact: true })).toBeEnabled();

    // Capture the moment the open peer dialog reflects the fresh server action
    // state, rather than measuring when the Playwright process next polls it.
    const peerDialogUpdatedAt = peer.waitForFunction(() => {
      const dialog = document.querySelector('[role="dialog"]');
      return dialog?.textContent?.includes('This adjustment is no longer available for approval.')
        ? Date.now()
        : false;
    }).then((handle) => handle.jsonValue() as Promise<number>);

    const peerFramesBeforeApproval = peerFrames.length;
    const peerRefreshResponse = peer.waitForResponse(async (response) => {
      if (response.request().method() !== 'GET'
        || new URL(response.url()).pathname !== '/api/v1/payroll-adjustments'
        || response.status() !== 200) return false;
      try {
        const payload = await response.json();
        return payload.data?.some((row: { id: string; status: string }) =>
          row.id === adjustmentId && row.status === 'approved',
        ) ?? false;
      } catch {
        return false;
      }
    });
    await actor.goto('/payroll/adjustments');
    const actorRow = actor.getByRole('row').filter({ hasText: reason });
    await expect(actorRow.getByRole('button', { name: 'Approve', exact: true })).toBeVisible();
    const approveResponsePromise = actor.waitForResponse((response) =>
      response.request().method() === 'PATCH'
      && new URL(response.url()).pathname === '/api/v1/payroll-adjustments/' + adjustmentId + '/approve',
    ).then((response) => {
      actorResponseAt = Date.now();
      return response;
    });
    await actorRow.getByRole('button', { name: 'Approve', exact: true }).click();
    const actorDialog = actor.getByRole('dialog', { name: 'Approve payroll adjustment?' });
    await actorDialog.getByRole('button', { name: 'Approve', exact: true }).click();

    const [approveResponse, refreshedList] = await Promise.all([
      approveResponsePromise,
      peerRefreshResponse,
    ]);
    expect(approveResponse.status()).toBe(200);
    completed = true;
    expect(refreshedList.status()).toBe(200);
    const refreshed = (await refreshedList.json()).data as Array<{
      id: string;
      status: string;
      available_actions: { approve: boolean };
    }>;
    const updatedRow = refreshed.find((row) => row.id === adjustmentId);
    expect(updatedRow?.status).toBe('approved');
    expect(updatedRow?.available_actions.approve).toBe(false);

    await expect.poll(() => peerFrames.length, { timeout: 10_000 }).toBeGreaterThan(peerFramesBeforeApproval);
    await expect(peerRow).toContainText('Approved');
    await expect(peerDialog).toContainText('This adjustment is no longer available for approval.');
    await expect(peerDialog.getByRole('button', { name: 'Approve', exact: true })).toBeDisabled();
    const peerRenderedAt = await peerDialogUpdatedAt;
    expect(actorResponseAt).toBeDefined();

    await expect(actorRow).toContainText('Approved');
    await expect(actorRow.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
    console.info('[realtime-e2e] payroll_adjustment_open_dialog_peer_update_ms='
      + (peerRenderedAt - actorResponseAt!)
      + '; event_received=true; actor_socket_disabled=true; peer_context_same_account=true');
  } finally {
    if (!makerClosed) await makerContext.close();
    if (adjustmentId && !completed) {
      const cleanupContext = await browser.newContext({ baseURL: BASE_URL });
      try {
        const cleanup = await cleanupContext.newPage();
        await loginAs(cleanup, admin.email, admin.password);
        const current = await cleanup.request.get(
          BASE_URL + '/api/v1/payroll-adjustments/' + adjustmentId,
          { headers: statefulHeaders() },
        );
        if (current.status() === 200 && (await current.json()).data.status === 'pending') {
          const response = await cleanup.request.patch(
            BASE_URL + '/api/v1/payroll-adjustments/' + adjustmentId + '/approve',
            { headers: await statefulCsrfHeaders(cleanup) },
          );
          expect(response.status()).toBe(200);
        }
      } finally {
        await cleanupContext.close();
      }
    }
    await Promise.all([
      actorContext?.close(),
      peerContext?.close(),
    ]);
  }
});
