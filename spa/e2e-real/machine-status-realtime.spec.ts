import { expect, test } from '@playwright/test';
import { baseUrl, loginAs, statefulCsrfHeaders } from './helpers';

type MachineRow = { id: string; machine_code: string; name: string; status: string };

test('machine status transition refreshes another role status and available actions', async ({ browser }) => {
  test.setTimeout(90_000);
  const base = baseUrl();
  const actorContext = await browser.newContext({ baseURL: base });
  const viewerContext = await browser.newContext({ baseURL: base });
  await actorContext.routeWebSocket(/.*/, (socket) => socket.close({ code: 1000, reason: 'Actor socket disabled for API-response verification.' }));
  const actorPage = await actorContext.newPage();
  const viewerListPage = await viewerContext.newPage();
  const viewerDetailPage = await viewerContext.newPage();
  const viewerDashboardPage = await viewerContext.newPage();
  const suffix = Date.now().toString(36).toUpperCase().slice(-8);
  const machineCode = `RT-${suffix}`;
  const machineName = `Realtime status ${suffix}`;
  let machine: MachineRow | undefined;
  let actorCsrfHeaders: Record<string, string> | undefined;
  let listChannelReady = false;
  let detailChannelReady = false;
  let listEvents = 0;
  let detailEvents = 0;
  let dashboardEvents = 0;
  let listEventAt = 0;
  let detailEventAt = 0;
  let dashboardEventAt = 0;
  const listRefreshGets: number[] = [];
  const detailRefreshGets: number[] = [];
  const dashboardRefreshGets: number[] = [];
  const realtimeHealth: string[] = [];
  const expectWorkerOutage = process.env.REALTIME_EXPECT_WORKER_OUTAGE === '1';

  viewerListPage.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      if (typeof frame.payload !== 'string') return;
      try {
        const message = JSON.parse(frame.payload) as { event?: string; channel?: string };
        if (message.event === 'pusher_internal:subscription_succeeded' && message.channel === 'private-entity.machine.visible') {
          listChannelReady = true;
        }
        if (message.event === 'chain.refresh' && message.channel === 'private-entity.machine.visible') {
          listEvents += 1;
          listEventAt ||= Date.now();
        }
      } catch {
        // Ignore non-event protocol frames.
      }
    });
  });
  viewerDetailPage.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      if (typeof frame.payload !== 'string') return;
      try {
        const message = JSON.parse(frame.payload) as { event?: string; channel?: string };
        if (machine && message.event === 'pusher_internal:subscription_succeeded'
          && message.channel === `private-production.machine.${machine.id}`) {
          detailChannelReady = true;
        }
        if (machine && message.event === 'chain.refresh'
          && message.channel === `private-production.machine.${machine.id}`) {
          detailEvents += 1;
          detailEventAt ||= Date.now();
        }
      } catch {
        // Ignore non-event protocol frames.
      }
    });
  });
  let dashboardChannelReady = false;
  viewerDashboardPage.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      if (typeof frame.payload !== 'string') return;
      try {
        const message = JSON.parse(frame.payload) as { event?: string; channel?: string };
        if (message.event === 'pusher_internal:subscription_succeeded' && message.channel === 'private-production.dashboard') {
          dashboardChannelReady = true;
        }
        if (message.event === 'chain.refresh' && message.channel === 'private-production.dashboard') {
          dashboardEvents += 1;
          dashboardEventAt ||= Date.now();
        }
      } catch {
        // Ignore non-event protocol frames.
      }
    });
  });
  viewerListPage.on('request', (request) => {
    const url = new URL(request.url());
    if (request.method() === 'GET' && url.pathname === '/api/v1/mrp/machines') listRefreshGets.push(Date.now());
  });
  viewerListPage.on('response', (response) => {
    const url = new URL(response.url());
    if (response.request().method() === 'GET' && url.pathname === '/api/v1/realtime/health') {
      void response.json().then((body: { data?: { delivery?: string } }) => {
        if (body.data?.delivery) realtimeHealth.push(body.data.delivery);
      }).catch(() => undefined);
    }
  });
  viewerDetailPage.on('request', (request) => {
    const url = new URL(request.url());
    if (machine && request.method() === 'GET' && url.pathname === `/api/v1/mrp/machines/${machine.id}`) {
      detailRefreshGets.push(Date.now());
    }
  });
  viewerDashboardPage.on('request', (request) => {
    const url = new URL(request.url());
    if (request.method() === 'GET' && url.pathname === '/api/v1/production/dashboard') {
      dashboardRefreshGets.push(Date.now());
    }
  });

  try {
    await loginAs(actorPage, 'admin@ogami.test', 'password');
    await loginAs(viewerListPage, 'production@ogami.test', 'password');

    actorCsrfHeaders = await statefulCsrfHeaders(actorPage);
    await viewerListPage.goto('/mrp/machines', { waitUntil: 'domcontentloaded' });
    const viewerSearch = viewerListPage.getByRole('search').getByLabel('Search code or name…');
    const emptySearchResponsePromise = viewerListPage.waitForResponse((response) => {
      const url = new URL(response.url());
      return response.request().method() === 'GET'
        && url.pathname === '/api/v1/mrp/machines'
        && url.searchParams.get('search') === machineCode;
    });
    await viewerSearch.fill(machineCode);
    const emptySearchResponse = await emptySearchResponsePromise;
    expect(emptySearchResponse.status()).toBe(200);
    await expect(viewerListPage.getByText('0 machines', { exact: true })).toBeVisible();
    const viewerRow = viewerListPage.getByRole('row').filter({ hasText: machineCode });
    await expect.poll(() => listChannelReady, { timeout: 15_000 }).toBe(true);
    listEvents = 0;
    listEventAt = 0;
    listRefreshGets.length = 0;

    const actorCreateStartedAt = Date.now();
    await actorPage.goto('/mrp/machines/create', { waitUntil: 'domcontentloaded' });
    await actorPage.getByLabel('Machine Code').fill(machineCode);
    await actorPage.getByLabel('Machine Name').fill(machineName);
    await actorPage.getByLabel('Machine Type').fill('injection_molder');
    await actorPage.getByLabel('Operators Required').fill('1');
    await actorPage.getByLabel('Available Hours / Day').fill('16');
    const createResponsePromise = actorPage.waitForResponse((response) =>
      response.request().method() === 'POST'
      && new URL(response.url()).pathname === '/api/v1/mrp/machines',
    );
    await actorPage.getByRole('button', { name: 'Create machine', exact: true }).click();
    const created = await createResponsePromise;
    expect(created.status(), await created.text()).toBe(201);
    machine = (await created.json() as { data: MachineRow }).data;
    expect(machine.status).toBe('idle');
    const actorCreateAt = Date.now();
    await expect(actorPage.getByRole('heading', { level: 1 })).toContainText(machineName);
    if (expectWorkerOutage) {
      await expect.poll(() => realtimeHealth.includes('degraded'), { timeout: 10_000 }).toBe(true);
      await viewerListPage.bringToFront();
      await viewerListPage.evaluate(() => window.dispatchEvent(new Event('focus')));
    } else {
      await expect.poll(() => listEvents, { timeout: 10_000 }).toBeGreaterThan(0);
    }
    await expect(viewerRow.getByText('Idle', { exact: true })).toBeVisible();
    const viewerCreateAt = Date.now();
    if (expectWorkerOutage) {
      expect(listEvents).toBe(0);
      expect(listRefreshGets.some((at) => at >= actorCreateStartedAt)).toBe(true);
    } else {
      expect(listRefreshGets.some((at) => at >= listEventAt)).toBe(true);
    }
    const createEvidence = {
      status: created.status(),
      viewerListEvents: listEvents,
      viewerListRefetchesAfterCreate: listRefreshGets.filter((at) => at >= actorCreateStartedAt).length,
      actorRenderedResponse: await actorPage.getByRole('heading', { level: 1 }).innerText(),
      actorResponseToViewerRenderMs: viewerCreateAt - actorCreateAt,
    };

    await viewerListPage.goto('/mrp/machines', { waitUntil: 'domcontentloaded' });
    await viewerSearch.fill(machineCode);
    await expect(viewerRow.getByText('Idle', { exact: true })).toBeVisible();
    const viewerTransition = viewerRow.getByLabel(`Transition ${machineCode}`);
    await expect(viewerTransition.locator('option[value="running"]')).toHaveCount(1);

    await viewerDetailPage.goto(`/mrp/machines/${machine.id}`, { waitUntil: 'domcontentloaded' });
    await expect(viewerDetailPage.getByRole('heading', { level: 1 })).toContainText('Idle');
    await viewerDashboardPage.goto('/production/dashboard', { waitUntil: 'domcontentloaded' });
    const machineUtilizationCard = viewerDashboardPage.getByText('Machine utilization', { exact: true }).locator('..');
    await expect(machineUtilizationCard).toBeVisible();
    await expect.poll(() => listChannelReady, { timeout: 15_000 }).toBe(true);
    await expect.poll(() => detailChannelReady, { timeout: 15_000 }).toBe(true);
    await expect.poll(() => dashboardChannelReady, { timeout: 15_000 }).toBe(true);
    if (!expectWorkerOutage) {
      await expect.poll(() => realtimeHealth.at(-1), { timeout: 10_000 }).toBe('healthy');
    }
    const initialDashboardIdleCount = Number((await machineUtilizationCard.innerText()).match(/(\d+) idle/)?.[1]);
    expect(Number.isFinite(initialDashboardIdleCount)).toBe(true);

    await actorPage.goto('/mrp/machines', { waitUntil: 'domcontentloaded' });
    const actorSearch = actorPage.getByRole('search').getByLabel('Search code or name…');
    await actorSearch.fill(machineCode);
    const actorRow = actorPage.getByRole('row').filter({ hasText: machineCode });
    await expect(actorRow.getByText('Idle', { exact: true })).toBeVisible();

    listEvents = 0;
    detailEvents = 0;
    listEventAt = 0;
    detailEventAt = 0;
    listRefreshGets.length = 0;
    detailRefreshGets.length = 0;
    dashboardEvents = 0;
    dashboardEventAt = 0;
    dashboardRefreshGets.length = 0;
    const actorOfflineStartedAt = Date.now();
    const offlineResponsePromise = actorPage.waitForResponse((response) =>
      response.request().method() === 'PATCH'
      && new URL(response.url()).pathname === `/api/v1/mrp/machines/${machine!.id}/transition-status`,
    );
    await actorRow.getByLabel(`Transition ${machineCode}`).selectOption('offline');
    const offlineResponse = await offlineResponsePromise;
    expect(offlineResponse.status(), await offlineResponse.text()).toBe(200);
    const actorOfflineAt = Date.now();
    await expect(actorRow.getByText('Offline', { exact: true })).toBeVisible();
    await expect(actorRow.getByLabel(`Transition ${machineCode}`).locator('option[value="running"]')).toHaveCount(0);
    if (expectWorkerOutage) {
      await expect.poll(() => realtimeHealth.includes('degraded'), { timeout: 10_000 }).toBe(true);
      await viewerListPage.bringToFront();
      await viewerListPage.evaluate(() => window.dispatchEvent(new Event('focus')));
      await expect(viewerRow.getByText('Offline', { exact: true })).toBeVisible();
    } else {
      await expect(viewerRow.getByText('Offline', { exact: true })).toBeVisible();
    }
    await expect(viewerTransition.locator('option[value="running"]')).toHaveCount(0);
    await expect(viewerTransition.locator('option[value="idle"]')).toHaveCount(1);
    const viewerListOfflineAt = Date.now();
    if (expectWorkerOutage) {
      await viewerDetailPage.bringToFront();
      await viewerDetailPage.evaluate(() => window.dispatchEvent(new Event('focus')));
    }
    await expect(viewerDetailPage.getByRole('heading', { level: 1 })).toContainText('Offline');
    const viewerDetailOfflineAt = Date.now();
    if (expectWorkerOutage) {
      await viewerDashboardPage.bringToFront();
      await viewerDashboardPage.evaluate(() => window.dispatchEvent(new Event('focus')));
    }
    await expect(machineUtilizationCard).toContainText(`${initialDashboardIdleCount - 1} idle`);
    const viewerDashboardOfflineAt = Date.now();
    if (expectWorkerOutage) {
      expect(listEvents).toBe(0);
      expect(detailEvents).toBe(0);
      expect(dashboardEvents).toBe(0);
      expect(listRefreshGets.some((at) => at >= actorOfflineStartedAt)).toBe(true);
      expect(detailRefreshGets.some((at) => at >= actorOfflineStartedAt)).toBe(true);
      expect(dashboardRefreshGets.some((at) => at >= actorOfflineStartedAt)).toBe(true);
    } else {
      await expect.poll(() => listEvents, { timeout: 10_000 }).toBeGreaterThan(0);
      await expect.poll(() => detailEvents, { timeout: 10_000 }).toBeGreaterThan(0);
      await expect.poll(() => dashboardEvents, { timeout: 10_000 }).toBeGreaterThan(0);
      await expect.poll(() => dashboardRefreshGets.some((at) => at >= dashboardEventAt), { timeout: 10_000 }).toBe(true);
      expect(listRefreshGets.some((at) => at >= listEventAt)).toBe(true);
      expect(detailRefreshGets.some((at) => at >= detailEventAt)).toBe(true);
    }
    const offlineEvidence = {
      to: 'offline', status: offlineResponse.status(), listEvents, detailEvents,
      listRefetchesAfterEvent: listEventAt ? listRefreshGets.filter((at) => at >= listEventAt).length : 0,
      detailRefetchesAfterEvent: detailEventAt ? detailRefreshGets.filter((at) => at >= detailEventAt).length : 0,
      listRefetchesAfterActionStart: listRefreshGets.filter((at) => at >= actorOfflineStartedAt).length,
      detailRefetchesAfterActionStart: detailRefreshGets.filter((at) => at >= actorOfflineStartedAt).length,
      dashboardEvents,
      dashboardRefetchesAfterEvent: dashboardEventAt ? dashboardRefreshGets.filter((at) => at >= dashboardEventAt).length : 0,
      dashboardRefetchesAfterActionStart: dashboardRefreshGets.filter((at) => at >= actorOfflineStartedAt).length,
      actorResponseToListMs: viewerListOfflineAt - actorOfflineAt,
      actorResponseToDetailMs: viewerDetailOfflineAt - actorOfflineAt,
      actorResponseToDashboardMs: viewerDashboardOfflineAt - actorOfflineAt,
    };

    listEvents = 0;
    detailEvents = 0;
    listEventAt = 0;
    detailEventAt = 0;
    listRefreshGets.length = 0;
    detailRefreshGets.length = 0;
    dashboardEvents = 0;
    dashboardEventAt = 0;
    dashboardRefreshGets.length = 0;
    const actorIdleStartedAt = Date.now();
    const idleResponsePromise = actorPage.waitForResponse((response) =>
      response.request().method() === 'PATCH'
      && new URL(response.url()).pathname === `/api/v1/mrp/machines/${machine!.id}/transition-status`,
    );
    await actorRow.getByLabel(`Transition ${machineCode}`).selectOption('idle');
    const idleResponse = await idleResponsePromise;
    expect(idleResponse.status(), await idleResponse.text()).toBe(200);
    const actorIdleAt = Date.now();
    await expect(actorRow.getByText('Idle', { exact: true })).toBeVisible();
    await expect(actorRow.getByLabel(`Transition ${machineCode}`).locator('option[value="running"]')).toHaveCount(1);
    if (!expectWorkerOutage) {
      await expect.poll(() => listEvents, { timeout: 10_000 }).toBeGreaterThan(0);
      await expect.poll(() => detailEvents, { timeout: 10_000 }).toBeGreaterThan(0);
    } else {
      await viewerListPage.bringToFront();
      await viewerListPage.evaluate(() => window.dispatchEvent(new Event('focus')));
    }
    await expect(viewerRow.getByText('Idle', { exact: true })).toBeVisible();
    await expect(viewerTransition.locator('option[value="running"]')).toHaveCount(1);
    const viewerListIdleAt = Date.now();
    if (expectWorkerOutage) {
      await viewerDetailPage.bringToFront();
      await viewerDetailPage.evaluate(() => window.dispatchEvent(new Event('focus')));
    }
    await expect(viewerDetailPage.getByRole('heading', { level: 1 })).toContainText('Idle');
    const viewerDetailIdleAt = Date.now();
    if (expectWorkerOutage) {
      await viewerDashboardPage.bringToFront();
      await viewerDashboardPage.evaluate(() => window.dispatchEvent(new Event('focus')));
    }
    if (expectWorkerOutage) {
      expect(listEvents).toBe(0);
      expect(detailEvents).toBe(0);
      expect(dashboardEvents).toBe(0);
      expect(listRefreshGets.some((at) => at >= actorIdleStartedAt)).toBe(true);
      expect(detailRefreshGets.some((at) => at >= actorIdleStartedAt)).toBe(true);
      expect(dashboardRefreshGets.some((at) => at >= actorIdleStartedAt)).toBe(true);
    } else {
      await expect.poll(() => dashboardEvents, { timeout: 10_000 }).toBeGreaterThan(0);
      await expect.poll(() => dashboardRefreshGets.some((at) => at >= dashboardEventAt), { timeout: 10_000 }).toBe(true);
    }
    await expect(machineUtilizationCard).toContainText(`${initialDashboardIdleCount} idle`);
    const viewerDashboardIdleAt = Date.now();
    expect(listRefreshGets.some((at) => at >= listEventAt)).toBe(true);
    expect(detailRefreshGets.some((at) => at >= detailEventAt)).toBe(true);
    const idleEvidence = {
      to: 'idle', status: idleResponse.status(), listEvents, detailEvents,
      listRefetchesAfterEvent: listEventAt ? listRefreshGets.filter((at) => at >= listEventAt).length : 0,
      detailRefetchesAfterEvent: detailEventAt ? detailRefreshGets.filter((at) => at >= detailEventAt).length : 0,
      listRefetchesAfterActionStart: listRefreshGets.filter((at) => at >= actorIdleStartedAt).length,
      detailRefetchesAfterActionStart: detailRefreshGets.filter((at) => at >= actorIdleStartedAt).length,
      dashboardEvents,
      dashboardRefetchesAfterEvent: dashboardEventAt ? dashboardRefreshGets.filter((at) => at >= dashboardEventAt).length : 0,
      dashboardRefetchesAfterActionStart: dashboardRefreshGets.filter((at) => at >= actorIdleStartedAt).length,
      actorResponseToListMs: viewerListIdleAt - actorIdleAt,
      actorResponseToDetailMs: viewerDetailIdleAt - actorIdleAt,
      actorResponseToDashboardMs: viewerDashboardIdleAt - actorIdleAt,
    };

    const browserEvidence = {
      machineId: machine.id,
      actorRole: 'system_admin',
      viewerRole: 'production_manager',
      actorSocketDisabled: true,
      degradedDeliveryObserved: realtimeHealth.includes('degraded'),
      deliveryHealthSamples: realtimeHealth,
      workerOutageFallback: expectWorkerOutage,
      create: createEvidence,
      transitions: [offlineEvidence, idleEvidence],
    };
    console.log(`REALTIME_EVIDENCE ${JSON.stringify(browserEvidence)}`);
    await test.info().attach('machine-status-realtime.json', {
      body: Buffer.from(JSON.stringify(browserEvidence, null, 2)),
      contentType: 'application/json',
    });
  } finally {
    if (machine && actorCsrfHeaders) {
      const removed = await actorPage.request.delete(`${base}/api/v1/mrp/machines/${machine.id}`, {
        headers: actorCsrfHeaders,
      }).catch(() => null);
      expect(removed?.status(), 'Archive the disposable machine after the realtime check.').toBe(204);
    }
    await actorContext.close();
    await viewerContext.close();
  }
});
