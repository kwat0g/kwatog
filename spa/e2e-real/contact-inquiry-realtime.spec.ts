import { randomUUID } from 'node:crypto';
import { expect, test, type BrowserContext, type Locator, type Page } from '@playwright/test';
import { baseUrl, loginAs, ROLES, statefulCsrfHeaders, statefulHeaders } from './helpers';

type Inquiry = {
  id: string;
  inquiry_no: string;
  status: 'new' | 'in_progress' | 'closed';
  available_actions: { mark_in_progress: boolean; close: boolean };
};

type InquiryList = {
  data: Inquiry[];
  meta: { total: number };
};

async function readInquiry(page: Page, email: string): Promise<Inquiry> {
  const response = await page.request.get(
    `${baseUrl()}/api/v1/crm/inquiries?search=${encodeURIComponent(email)}&per_page=1`,
    { headers: statefulHeaders() },
  );
  expect(response.status(), await response.text()).toBe(200);
  const list = (await response.json() as InquiryList).data;
  const inquiry = list[0];
  expect(inquiry, `the public inquiry for ${email} is visible to CRM readers`).toBeTruthy();
  return inquiry;
}

type InquirySignal = { receivedAt: number; actorId: string | null; eventId: string | null; entityId: string | null };
type ApiTiming = { at: number; status?: number; recordStatus?: string };

async function watchStatusMutation(locator: Locator, nextStatus: string, key: string): Promise<void> {
  await locator.evaluate((element, options) => {
    const state = window as unknown as Record<string, unknown>;
    state[options.key] = 0;
    const target = element.closest('tr') ?? element.parentElement ?? element;
    new MutationObserver(() => {
      if (target.textContent?.includes(options.nextStatus) && !state[options.key]) {
        state[options.key] = Date.now();
      }
    }).observe(target, { childList: true, subtree: true, characterData: true });
  }, { key, nextStatus });
}

async function statusMutationAt(page: Page, key: string): Promise<number> {
  return page.evaluate((property) => {
    const state = window as unknown as Record<string, unknown>;
    return typeof state[property] === 'number' ? state[property] as number : 0;
  }, key);
}

function observeInquirySignal(page: Page): { events: InquirySignal[]; subscribed: boolean } {
  const trace = { events: [] as InquirySignal[], subscribed: false };
  page.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      const raw = typeof frame.payload === 'string' ? frame.payload : frame.payload.toString('utf8');
      try {
        const message = JSON.parse(raw) as { event?: string; channel?: string; data?: string | Record<string, unknown> };
        if (message.event === 'pusher_internal:subscription_succeeded'
          && message.channel === 'private-entity.contact_inquiry.visible') {
          trace.subscribed = true;
        }
        const data = typeof message.data === 'string'
          ? JSON.parse(message.data) as { action?: string; actor_id?: string | null; event_id?: string; entity_id?: string | null }
          : message.data as { action?: string; actor_id?: string | null; event_id?: string; entity_id?: string | null } | undefined;
        if (message.event === 'crm.contact-inquiry.changed'
          && data?.action === 'status_changed'
          && message.channel === 'private-entity.contact_inquiry.visible') {
          trace.events.push({
            receivedAt: Date.now(),
            actorId: data.actor_id ?? null,
            eventId: data.event_id ?? null,
            entityId: data.entity_id ?? null,
          });
        }
      } catch {
        // Ignore Pusher protocol frames that do not carry an application event.
      }
    });
  });
  return trace;
}

test('a status change updates the acting CRM user and a different-role inquiry viewer', async ({ browser }) => {
  test.setTimeout(90_000);
  const base = baseUrl();
  const actorRole = ROLES.find(({ slug }) => slug === 'system_admin')!;
  const viewerRole = { slug: 'customer_service_officer', email: 'customerservice@ogami.test', password: 'password' };
  const actorContext = await browser.newContext({ baseURL: base });
  const viewerDetailContext = await browser.newContext({ baseURL: base });
  let viewerListContext: BrowserContext | undefined;
  await actorContext.routeWebSocket(/.*/, (socket) => socket.close({
    code: 1000,
    reason: 'Actor state must come from the mutation response, not its WebSocket.',
  }));
  const actorPage = await actorContext.newPage();
  const viewerDetailPage = await viewerDetailContext.newPage();
  let viewerListPage: Page;
  const suffix = randomUUID().replaceAll('-', '').slice(0, 12);
  const email = `realtime-inquiry-${suffix}@example.test`;
  const signal = observeInquirySignal(viewerDetailPage);
  let listSignal: ReturnType<typeof observeInquirySignal>;
  let inquiry: Inquiry | undefined;

  try {
    await loginAs(actorPage, actorRole.email, actorRole.password);
    await loginAs(viewerDetailPage, viewerRole.email, viewerRole.password);
    viewerListContext = await browser.newContext({
      baseURL: base,
      storageState: await viewerDetailContext.storageState(),
    });
    viewerListPage = await viewerListContext.newPage();
    listSignal = observeInquirySignal(viewerListPage);
    const actorUserResponse = await actorPage.request.get(`${base}/api/v1/auth/user`, { headers: statefulHeaders() });
    expect(actorUserResponse.status()).toBe(200);
    const actorUser = (await actorUserResponse.json()).data as { id: string; role: { slug: string } };
    expect(actorUser.role.slug).not.toBe(viewerRole.slug);

    const created = await actorPage.request.post(`${base}/api/v1/landing/contact-inquiry`, {
      headers: await statefulCsrfHeaders(actorPage),
      data: {
        full_name: `Realtime Probe ${suffix}`,
        company: 'Automated test fixture',
        email,
        phone: null,
        message: 'Disposable inquiry used to verify authorized real-time status refresh.',
        consent: true,
      },
    });
    expect(created.status(), await created.text()).toBe(201);
    inquiry = await readInquiry(actorPage, email);
    expect(inquiry.status).toBe('new');
    expect(inquiry.available_actions).toEqual({ mark_in_progress: true, close: true });
    const detailReads: ApiTiming[] = [];
    const listReads: ApiTiming[] = [];
    viewerDetailPage.on('request', (request) => {
      const path = new URL(request.url()).pathname;
      if (request.method() === 'GET' && path === `/api/v1/crm/inquiries/${inquiry!.id}`) {
        detailReads.push({ at: Date.now() });
      }
    });
    viewerDetailPage.on('response', (response) => {
      const path = new URL(response.url()).pathname;
      if (response.request().method() === 'GET' && path === `/api/v1/crm/inquiries/${inquiry!.id}`) {
        void response.json().then((body: { data?: { status?: string } }) => {
          detailReads.push({ at: Date.now(), status: response.status(), recordStatus: body.data?.status });
        }).catch(() => detailReads.push({ at: Date.now(), status: response.status() }));
      }
    });
    viewerListPage.on('request', (request) => {
      if (request.method() === 'GET' && new URL(request.url()).pathname === '/api/v1/crm/inquiries') {
        listReads.push({ at: Date.now() });
      }
    });
    viewerListPage.on('response', (response) => {
      if (response.request().method() === 'GET' && new URL(response.url()).pathname === '/api/v1/crm/inquiries') {
        listReads.push({ at: Date.now(), status: response.status() });
      }
    });

    await viewerListPage.goto('/crm/inquiries', { waitUntil: 'domcontentloaded' });
    const row = viewerListPage.getByRole('row').filter({ hasText: inquiry.inquiry_no });
    await expect(row.getByText('New', { exact: true })).toBeVisible();
    await viewerDetailPage.goto(`/crm/inquiries/${inquiry.id}`, { waitUntil: 'domcontentloaded' });
    await expect(viewerDetailPage.getByText('New', { exact: true })).toBeVisible();
    await watchStatusMutation(viewerDetailPage.getByText('New', { exact: true }), 'Closed', '__e2eInquiryDetailChangedAt');
    await watchStatusMutation(row.getByText('New', { exact: true }), 'Closed', '__e2eInquiryListChangedAt');
    await expect(viewerDetailPage.getByRole('button', { name: 'Close', exact: true })).toBeVisible();
    await expect.poll(() => signal.subscribed, { timeout: 15_000 }).toBe(true);
    await expect.poll(() => listSignal.subscribed, { timeout: 15_000 }).toBe(true);
    await viewerDetailPage.bringToFront();
    const detailVisibility = await viewerDetailPage.evaluate(() => document.visibilityState);
    expect(detailVisibility).toBe('visible');
    await actorPage.goto(`/crm/inquiries/${inquiry.id}`, { waitUntil: 'domcontentloaded' });
    await expect(actorPage.getByText('New', { exact: true })).toBeVisible();
    await watchStatusMutation(actorPage.getByText('New', { exact: true }), 'Closed', '__e2eInquiryActorChangedAt');
    await viewerDetailPage.bringToFront();
    const viewerVisibilityAtMutation = await viewerDetailPage.evaluate(() => document.visibilityState);
    expect(viewerVisibilityAtMutation).toBe('visible');
    const mutationStartedAt = Date.now();
    const priorDetailReadCount = detailReads.length;
    const priorListReadCount = listReads.length;
    const mutationResponse = actorPage.waitForResponse((response) =>
      response.request().method() === 'PATCH'
      && new URL(response.url()).pathname === `/api/v1/crm/inquiries/${inquiry!.id}/status`,
    );
    await actorPage.getByRole('button', { name: 'Close', exact: true })
      .evaluate((button) => (button as HTMLButtonElement).click());
    const response = await mutationResponse;
    expect(response.status(), await response.text()).toBe(200);
    const actorUpdatedAt = Date.now();
    const updated = (await response.json() as { data: Inquiry }).data;
    expect(updated.status).toBe('closed');
    expect(updated.available_actions).toEqual({ mark_in_progress: false, close: false });
    await expect(actorPage.getByText('Closed', { exact: true })).toBeVisible();
    await expect(actorPage.getByRole('button', { name: 'Mark in progress', exact: true })).toHaveCount(0);
    await expect(actorPage.getByRole('button', { name: 'Close', exact: true })).toHaveCount(0);

    await expect.poll(() => signal.events.some((event) =>
      event.receivedAt >= mutationStartedAt && event.actorId === actorUser.id
      && event.eventId !== null && event.entityId === inquiry!.id,
    ), { timeout: 10_000 }).toBe(true);
    const viewerVisibilityAfterSignal = await viewerDetailPage.evaluate(() => document.visibilityState);
    await expect(viewerDetailPage.getByText('Closed', { exact: true })).toBeVisible({ timeout: 10_000 });
    const detailStatusMutationAt = await statusMutationAt(viewerDetailPage, '__e2eInquiryDetailChangedAt');
    const detailApiResponseAt = detailReads.findLast((read) => read.status === 200 && read.recordStatus === 'closed')?.at;
    await expect(viewerDetailPage.getByRole('button', { name: 'Mark in progress', exact: true })).toHaveCount(0);
    await expect(viewerDetailPage.getByRole('button', { name: 'Close', exact: true })).toHaveCount(0);
    await expect.poll(() => listSignal.events.some((event) =>
      event.receivedAt >= mutationStartedAt && event.actorId === actorUser.id
      && event.eventId !== null && event.entityId === inquiry!.id,
    ), { timeout: 10_000 }).toBe(true);
    await viewerListPage.bringToFront();
    await expect(row.getByText('Closed', { exact: true })).toBeVisible({ timeout: 10_000 });
    const actorStatusMutationAt = await statusMutationAt(actorPage, '__e2eInquiryActorChangedAt');
    const listStatusMutationAt = await statusMutationAt(viewerListPage, '__e2eInquiryListChangedAt');
    const evidence = {
      actorRole: actorRole.slug,
      viewerRole: viewerRole.slug,
      actorSocketDisabled: true,
      detailVisibility,
      viewerVisibilityAtMutation,
      viewerVisibilityAfterSignal,
      detailSignalCount: signal.events.filter((event) => event.receivedAt >= mutationStartedAt
        && event.actorId === actorUser.id && event.eventId !== null && event.entityId === inquiry.id).length,
      listSignalCount: listSignal.events.filter((event) => event.receivedAt >= mutationStartedAt
        && event.actorId === actorUser.id && event.eventId !== null && event.entityId === inquiry.id).length,
      eventToDetailRequestMs: detailReads[priorDetailReadCount]?.at
        ? detailReads[priorDetailReadCount].at - signal.events.find((event) =>
          event.receivedAt >= mutationStartedAt && event.actorId === actorUser.id
          && event.eventId !== null && event.entityId === inquiry!.id,
        )!.receivedAt
        : null,
      eventToListRequestMs: listReads[priorListReadCount]?.at
        ? listReads[priorListReadCount].at - listSignal.events.find((event) =>
          event.receivedAt >= mutationStartedAt && event.actorId === actorUser.id
          && event.eventId !== null && event.entityId === inquiry!.id,
        )!.receivedAt
        : null,
      detailReadsAfterAction: detailReads.slice(priorDetailReadCount),
      listReadsAfterAction: listReads.slice(priorListReadCount),
      actorResponseToActorDomMs: actorStatusMutationAt - actorUpdatedAt,
      actorResponseToPeerDetailDomMs: detailStatusMutationAt - actorUpdatedAt,
      actorResponseToPeerListDomMs: listStatusMutationAt - actorUpdatedAt,
      eventToPeerDetailDomMs: detailStatusMutationAt - signal.events.find((event) =>
        event.receivedAt >= mutationStartedAt && event.actorId === actorUser.id
        && event.eventId !== null && event.entityId === inquiry!.id,
      )!.receivedAt,
      eventToPeerListDomMs: listStatusMutationAt - listSignal.events.find((event) =>
        event.receivedAt >= mutationStartedAt && event.actorId === actorUser.id
        && event.eventId !== null && event.entityId === inquiry!.id,
      )!.receivedAt,
      detailApiResponseToDomMutationMs: detailApiResponseAt && detailStatusMutationAt
        ? detailStatusMutationAt - detailApiResponseAt
        : null,
      inquiryState: updated.status,
      actorAndViewerActionsRemoved: true,
      listRowUpdatedWithoutNavigation: true,
    };
    console.info(`Contact Inquiry real-time evidence: ${JSON.stringify(evidence)}`);
    await test.info().attach('contact-inquiry-realtime.json', {
      body: JSON.stringify(evidence, null, 2),
      contentType: 'application/json',
    });
    expect(actorStatusMutationAt).toBeGreaterThan(0);
    expect(detailStatusMutationAt).toBeGreaterThan(0);
    expect(listStatusMutationAt).toBeGreaterThan(0);
    expect(evidence.actorResponseToActorDomMs).toBeLessThan(2_000);
    expect(evidence.eventToPeerDetailDomMs).toBeLessThan(2_000);
    expect(evidence.eventToPeerListDomMs).toBeLessThan(2_000);
  } finally {
    await Promise.all([
      actorContext.close(),
      viewerDetailContext.close(),
      ...(viewerListContext ? [viewerListContext.close()] : []),
    ]);
  }
});
