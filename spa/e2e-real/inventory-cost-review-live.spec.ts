import { test, expect } from '@playwright/test';
import { baseUrl, loginAs } from './helpers';

const reviewId = process.env.E2E_COST_REVIEW_ID;

test('a delegated Purchasing viewer receives the cost-review status and loses stale decision actions', async ({ browser }) => {
  test.setTimeout(120_000);
  expect(reviewId, 'set E2E_COST_REVIEW_ID to a unique pending dev cost review').toBeTruthy();

  const actorContext = await browser.newContext({ baseURL: baseUrl() });
  const viewerContext = await browser.newContext({ baseURL: baseUrl() });
  const actor = await actorContext.newPage();
  const viewer = await viewerContext.newPage();
  const viewerEvents: string[] = [];
  const viewerReviewReads: number[] = [];
  let actorResponseAt: number | undefined;
  let viewerRenderedAt: number | undefined;

  viewer.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      try {
        const message = JSON.parse(String(frame.payload)) as { event?: string; channel?: string };
        if (message.event === 'inventory.cost-review.changed') {
          viewerEvents.push(`${message.channel ?? ''}:${message.event}`);
        }
      } catch {
        // Pusher protocol control frames are not JSON application events.
      }
    });
  });
  viewer.on('request', (request) => {
    if (request.method() === 'GET' && request.url().includes('/api/v1/inventory/cost-reviews')) {
      viewerReviewReads.push(Date.now());
    }
  });

  try {
    await loginAs(actor, 'finance@ogami.test', 'password');
    await loginAs(viewer, 'purchasing@ogami.test', 'password');

    const route = `/crm/price-agreements/cost-reviews?review=${reviewId}`;
    await actor.goto(route);
    await viewer.goto(route);

    await expect(actor.getByRole('button', { name: 'Approve standard cost' })).toBeVisible();
    await expect(viewer.getByRole('button', { name: 'Approve standard cost' })).toBeVisible();
    await viewer.getByLabel('Approval or rejection reason').fill('Realtime cross-user review verification');

    const approvalResponse = actor.waitForResponse((response) =>
      response.url().includes(`/api/v1/inventory/cost-reviews/${reviewId}/decide`)
      && response.request().method() === 'POST',
    );
    await actor.getByLabel('Approval or rejection reason').fill('Realtime cross-user review verification');
    await actor.getByRole('button', { name: 'Approve standard cost' }).click();
    const response = await approvalResponse;
    expect(response.status()).toBe(200);
    actorResponseAt = Date.now();

    await expect(actor.getByRole('table').getByText('Approved', { exact: true })).toBeVisible();
    await expect(actor.getByRole('table').getByRole('button', { name: 'Review' })).toHaveCount(0);
    await expect(viewer.getByRole('table').getByText('Approved', { exact: true })).toBeVisible({ timeout: 5_000 });
    await expect(viewer.getByRole('dialog').getByText('Approved', { exact: true })).toBeVisible({ timeout: 5_000 });
    viewerRenderedAt = Date.now();
    await expect(viewer.getByRole('dialog').getByRole('button', { name: 'Approve standard cost' })).toHaveCount(0);
    await expect(viewer.getByRole('dialog').getByRole('button', { name: 'Reject change' })).toHaveCount(0);

    console.log('Item cost-review freshness:', JSON.stringify({
      reviewId,
      viewerEvents,
      viewerReviewReads: viewerReviewReads.length,
      actorResponseAt,
      viewerRenderedAt,
      responseToRenderMs: actorResponseAt && viewerRenderedAt ? viewerRenderedAt - actorResponseAt : null,
    }));
    expect(viewerEvents.some((entry) => entry.endsWith(':inventory.cost-review.changed'))).toBe(true);
    expect(viewerReviewReads.length).toBeGreaterThanOrEqual(2);
  } finally {
    await actorContext.close();
    await viewerContext.close();
  }
});
