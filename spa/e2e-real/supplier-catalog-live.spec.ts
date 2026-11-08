import { test, expect, type Page } from '@playwright/test';
import { baseUrl, statefulHeaders } from './helpers';

const PASSWORD = 'password';

async function login(page: Page, email: string, portal = false): Promise<void> {
  await page.goto('/sign-in');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).not.toHaveURL(/\/sign-in$/, { timeout: 20_000 });
  await page.locator(portal ? '#portal-main-content' : '#main-content').waitFor({ state: 'attached', timeout: 20_000 });
}

test('supplier sees an approval update and loses the stale edit action without reloading', async ({ browser }) => {
  test.setTimeout(120_000);

  const supplierContext = await browser.newContext({ baseURL: baseUrl() });
  const purchasingContext = await browser.newContext({ baseURL: baseUrl() });
  const supplierPage = await supplierContext.newPage();
  const purchasingPage = await purchasingContext.newPage();
  const supplierEvents: string[] = [];
  const supplierRealtimeFrames: string[] = [];
  const supplierItemListingGets: number[] = [];
  const supplierAuthResponses: number[] = [];
  let supplierWebsocketCount = 0;
  let approveResponseAt: number | undefined;
  let supplierUpdatedAt: number | undefined;
  let supplierListingId = '';
  const supplierCode = `E2E-RT-${Date.now()}`;

  supplierPage.on('websocket', (socket) => {
    supplierWebsocketCount++;
    socket.on('framereceived', (frame) => {
      const rawFrame = String(frame.payload);
      try {
        const message = JSON.parse(rawFrame) as { event?: string; channel?: string };
        supplierRealtimeFrames.push(`${message.event ?? 'no-event'}:${message.channel ?? ''}`);
        if (message.event === 'supplier.catalog.changed') {
          supplierEvents.push(`${message.channel ?? ''}:${message.event}`);
        }
      } catch {
        // Pusher protocol control frames are not JSON payloads.
        supplierRealtimeFrames.push(rawFrame.slice(0, 100));
      }
    });
  });
  supplierPage.on('request', (request) => {
    if (request.method() === 'GET' && request.url().includes('/api/v1/b2b/supplier/item-listings')) {
      supplierItemListingGets.push(Date.now());
    }
  });
  supplierPage.on('response', (response) => {
    if (response.url().includes('/api/v1/b2b/supplier/broadcasting/auth')) {
      supplierAuthResponses.push(response.status());
    }
  });
  try {
    await login(supplierPage, 'portal@supp.test', true);
    await supplierPage.goto('/portal/supplier/item-listings');

    const catalogResponse = await supplierPage.request.get(
      `${baseUrl()}/api/v1/b2b/supplier/item-catalog?per_page=100`,
      { headers: statefulHeaders() },
    );
    expect(catalogResponse.status()).toBe(200);
    const catalog = (await catalogResponse.json() as { data: Array<{ id: string }> }).data;
    const ownListingsResponse = await supplierPage.request.get(
      `${baseUrl()}/api/v1/b2b/supplier/item-listings?per_page=100`,
      { headers: statefulHeaders() },
    );
    expect(ownListingsResponse.status()).toBe(200);
    const ownListings = (await ownListingsResponse.json() as { data: Array<{ item?: { id: string } }> }).data;
    const availableItem = catalog.find((item) => !ownListings.some((listing) => listing.item?.id === item.id));
    expect(availableItem, 'supplier needs an unused item from the current catalog').toBeTruthy();

    await supplierPage.getByRole('button', { name: 'New listing', exact: true }).click();
    await expect.poll(() => supplierPage.getByLabel('Ogami item').locator('option').count()).toBeGreaterThan(1);
    await supplierPage.getByLabel('Ogami item').selectOption(availableItem!.id);
    await supplierPage.getByLabel('Your part number').fill(supplierCode);
    await supplierPage.getByLabel('Your item name').fill(`Realtime approval ${supplierCode}`);
    await supplierPage.getByLabel('Price (₱)').fill('314.15');
    await supplierPage.getByLabel('Lead time (days)').fill('10');
    const submittedResponse = supplierPage.waitForResponse((response) =>
      response.url().includes('/api/v1/b2b/supplier/item-listings')
      && response.request().method() === 'POST'
      && response.status() === 201,
    );
    await supplierPage.getByRole('button', { name: 'Submit for review', exact: true }).click();
    const submitted = await submittedResponse;
    const submittedBody = await submitted.json() as { data: { id: string } };
    supplierListingId = submittedBody.data.id;
    const supplierRow = supplierPage.getByRole('row').filter({ hasText: supplierCode });
    await expect(supplierRow.getByText('Pending review', { exact: true })).toBeVisible();
    await expect(supplierRow.getByRole('button', { name: 'Edit listing' })).toBeVisible();

    await login(purchasingPage, 'purchasing@ogami.test');
    await purchasingPage.goto(`/purchasing/supplier-listings?search=${encodeURIComponent(supplierCode)}`);
    const reviewRow = purchasingPage.getByRole('row').filter({ hasText: supplierCode });
    await expect(reviewRow).toBeVisible();
    await expect(reviewRow.getByText('Pending review', { exact: true })).toBeVisible();
    const readsBeforeApproval = supplierItemListingGets.length;
    const approvalResponse = purchasingPage.waitForResponse((response) =>
      response.url().includes(`/api/v1/purchasing/supplier-listings/${supplierListingId}/approve`)
      && response.request().method() === 'PATCH',
    );
    await reviewRow.getByRole('button', { name: 'Approve', exact: true }).click();
    const approvalResult = await approvalResponse;
    expect(approvalResult.status()).toBe(200);
    approveResponseAt = Date.now();
    await expect(reviewRow.getByText('Approved', { exact: true })).toBeVisible();
    await expect(reviewRow.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
    try {
      await expect(supplierRow.getByText('Approved', { exact: true }),
        'supplier portal should refetch after the internal approval signal',
      ).toBeVisible({ timeout: 5_000 });
      supplierUpdatedAt = Date.now();
    } catch {
      // Capture the stale action and missing refetch before the assertion below fails.
    }
    const supplierEditActionCount = await supplierRow.getByRole('button', { name: 'Edit listing' }).count();

    console.log('Supplier approval freshness:', JSON.stringify({
      supplierListingId,
      supplierEvents,
      supplierRealtimeFrames,
      supplierWebsocketCount,
      supplierListingGetsBeforeApproval: readsBeforeApproval,
      supplierListingGetsAfterCatchup: supplierItemListingGets.length,
      supplierAuthResponses,
      supplierEditActionCount,
      supplierSawApproved: supplierUpdatedAt !== undefined,
      approvalResponseToSupplierRenderMs: supplierUpdatedAt !== undefined && approveResponseAt !== undefined
        ? supplierUpdatedAt - approveResponseAt
        : null,
    }));
    expect(supplierUpdatedAt, 'supplier portal status should update from the authorized refresh signal').toBeDefined();
    expect(supplierEditActionCount).toBe(0);
    expect(supplierEvents.some((entry) => entry.startsWith('private-supplier.vendor.'))).toBe(true);
    expect(supplierAuthResponses).toContain(200);
    expect(supplierItemListingGets.length).toBeGreaterThan(readsBeforeApproval);
  } finally {
    await supplierContext.close();
    await purchasingContext.close();
  }
});
