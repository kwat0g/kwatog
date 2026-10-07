import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { localIsoDate } from '../src/lib/formatDate';
import { trimQuantity } from '../src/lib/quoteTotals';
import type { PurchaseOrder, PurchaseRequest, SupplierRfq } from '../src/types/purchasing';
import { baseUrl, statefulCsrfHeaders, statefulHeaders } from './helpers';

const PASSWORD = 'password';
const BASE_UNIT_PRICE = 600;
const VP_APPROVAL_THRESHOLD = 50_000;
const RFQ_TITLE = `Live RFQ chain ${Date.now()}`;

type InternalRfq = {
  id: string;
  title: string;
  status: string;
  invited_count: number;
  responded_count: number;
  items: Array<{ id: string; description: string }>;
  invitations: Array<{ status: string; exception_reason: string | null }>;
  quotes: unknown[];
  awards: Array<{ awarded_quantity: string; awarded_unit_price: string; purchase_order: { id: string; po_number: string } | null }>;
  actions: { can_close_now: boolean; can_compare: boolean; can_award: boolean };
};

type BrowserDiagnostics = {
  consoleErrors: string[];
  pageErrors: string[];
  failedRequests: Array<{ method: string; path: string; error: string }>;
  errorResponses: Array<{ method: string; path: string; status: number }>;
};

let browserDiagnostics: BrowserDiagnostics;

async function newContext(browser: Browser): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ baseURL: baseUrl() });
  const page = await context.newPage();
  page.on('console', (message) => {
    if (message.type() === 'error') browserDiagnostics.consoleErrors.push(message.text());
  });
  page.on('pageerror', (error) => browserDiagnostics.pageErrors.push(error.message));
  page.on('requestfailed', (request) => {
    browserDiagnostics.failedRequests.push({
      method: request.method(),
      path: new URL(request.url()).pathname,
      error: request.failure()?.errorText ?? 'unknown',
    });
  });
  page.on('response', (response) => {
    if (response.status() >= 400) {
      browserDiagnostics.errorResponses.push({
        method: response.request().method(),
        path: new URL(response.url()).pathname,
        status: response.status(),
      });
    }
  });
  return { context, page };
}

async function login(page: Page, email: string, portal = false): Promise<void> {
  await page.goto('/sign-in');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).not.toHaveURL(/\/sign-in$/, { timeout: 20_000 });
  await page.locator(portal ? '#portal-main-content' : '#main-content').waitFor({ state: 'attached', timeout: 20_000 });
}

async function getSupplierVendorName(page: Page): Promise<string> {
  return page.evaluate(async () => {
    const response = await fetch('/api/v1/b2b/supplier/me', { credentials: 'include' });
    const body = await response.json() as { data?: { vendor?: { name?: string } } };
    return body.data?.vendor_name ?? '';
  });
}

async function readInternalRfq(page: Page, id: string): Promise<InternalRfq> {
  const response = await page.request.get(`${baseUrl()}/api/v1/purchasing/rfqs/${id}`, {
    headers: statefulHeaders(),
  });
  expect(response.status(), 'RFQ state read').toBe(200);
  return (await response.json() as { data: InternalRfq }).data;
}

async function readSupplierRfq(page: Page, id: string): Promise<SupplierRfq> {
  const response = await page.request.get(`${baseUrl()}/api/v1/b2b/supplier/rfqs/${id}`, {
    headers: statefulHeaders(),
  });
  expect(response.status(), 'supplier RFQ state read').toBe(200);
  return (await response.json() as { data: SupplierRfq }).data;
}

async function readPurchaseRequest(page: Page, id: string): Promise<PurchaseRequest> {
  const response = await page.request.get(`${baseUrl()}/api/v1/purchasing/purchase-requests/${id}`, {
    headers: statefulHeaders(),
  });
  expect(response.status(), 'purchase request state read').toBe(200);
  return (await response.json() as { data: PurchaseRequest }).data;
}

async function readPurchaseOrder(page: Page, id: string): Promise<PurchaseOrder> {
  const response = await page.request.get(`${baseUrl()}/api/v1/purchasing/purchase-orders/${id}`, {
    headers: statefulHeaders(),
  });
  expect(response.status(), 'purchase order state read').toBe(200);
  return (await response.json() as { data: PurchaseOrder }).data;
}

async function expectInApprovalInbox(page: Page, number: string): Promise<void> {
  const inbox = page.getByText('My action required', { exact: true }).locator('xpath=../..');
  await expect(inbox.getByText(number, { exact: true })).toBeVisible({ timeout: 20_000 });
}

async function submitNeedOnlyPr(browser: Browser): Promise<{
  context: BrowserContext;
  page: Page;
  url: string;
  purchaseRequestId: string;
}> {
  const sales = await newContext(browser);
  let soNumber = '';
  try {
    await login(sales.page, 'crm@ogami.test');
    await sales.page.goto('/crm/sales-orders/create');
    await expect(sales.page.getByRole('heading', { name: 'New sales order' })).toBeVisible();
    const customer = sales.page.locator('select[name="customer_id"]');
    await expect.poll(() => customer.locator('option').count(), { timeout: 20_000 }).toBeGreaterThan(1);
    const customerOptions = await customer.locator('option').evaluateAll((nodes) => nodes.map((node) => ({ value: node.value, text: node.textContent ?? '' })));
    await customer.selectOption(customerOptions.find((option) => option.text.includes('Honda'))?.value ?? customerOptions.find((option) => option.value !== '')!.value);
    const product = sales.page.locator('select[name="items.0.product_id"]');
    await expect.poll(() => product.locator('option').count(), { timeout: 20_000 }).toBeGreaterThan(1);
    const productOptions = await product.locator('option').evaluateAll((nodes) => nodes.map((node) => ({ value: node.value, text: node.textContent ?? '' })));
    await product.selectOption(productOptions.find((option) => option.text.includes('WB-002'))?.value ?? productOptions.find((option) => option.value !== '')!.value);
    await sales.page.locator('input[name="items.0.quantity"]').fill('5000');
    const deliveryDate = new Date();
    deliveryDate.setDate(deliveryDate.getDate() + 30);
    await sales.page.locator('input[name="items.0.delivery_date"]').fill(localIsoDate(deliveryDate));
    await sales.page.getByRole('button', { name: 'Save & confirm', exact: true }).click();
    await sales.page.waitForURL(/\/crm\/sales-orders\/[^/]+$/, { timeout: 30_000 });
    await expect(sales.page.getByText('Confirmed', { exact: true }).first()).toBeVisible({ timeout: 30_000 });
    soNumber = (await sales.page.locator('h1').first().innerText()).match(/SO-\S+/)?.[0] ?? '';
    await expect.poll(async () => {
      await sales.page.reload({ waitUntil: 'networkidle' });
      return await sales.page.locator('a[href*="/mrp/plans/"]').count();
    }, { timeout: 75_000, intervals: [1_000, 2_000, 5_000] }).toBeGreaterThan(0);
  } finally {
    await sales.context.close();
  }

  const created = await newContext(browser);
  try {
    await login(created.page, 'purchasing@ogami.test');
    await created.page.goto('/purchasing/purchase-requests?per_page=100&is_auto_generated=true');
    const body = await created.page.evaluate(async () => (await fetch('/api/v1/purchasing/purchase-requests?per_page=100&is_auto_generated=true')).json());
    const autoPr = (body.data as Array<{ id: string; status: string; reason?: string }>).find((row) => row.status === 'draft' && row.reason?.includes(soNumber));
    expect(autoPr, `No auto PR linked to ${soNumber}`).toBeTruthy();
    await created.page.goto(`/purchasing/purchase-requests/${autoPr!.id}`);
    await created.page.getByRole('radio', { name: /Competitive RFQ/ }).check();
    await created.page.getByRole('button', { name: 'Save sourcing method', exact: true }).click();
    await created.page.getByRole('button', { name: 'Submit', exact: true }).click();
    await created.page.getByRole('dialog').getByRole('button', { name: 'Submit', exact: true }).click();
    await expect(created.page.getByText(/^Approved(?:\s+AUTO)?$/).first()).toBeVisible({ timeout: 20_000 });
    const submittedPr = await readPurchaseRequest(created.page, autoPr!.id);
    expect(submittedPr).toMatchObject({
      status: 'approved',
      sourcing_method: 'rfq',
      po_conversion_status: 'sourcing_pending',
      actions: { can_start_rfq: true, can_approve: false, can_submit: false },
    });
    expect(submittedPr.submitted_at).toBeTruthy();
    expect(submittedPr.approved_at).toBeTruthy();
    expect(submittedPr.approval_records).toEqual([]);
    expect(submittedPr.purchase_orders).toEqual([]);
    const url = created.page.url();

    return { ...created, url, purchaseRequestId: autoPr!.id };
  } finally {
    // The purchasing context remains open for the RFQ wizard.
  }
}

test.describe('Live sealed RFQ role workflow', () => {
  test.beforeEach(() => {
    browserDiagnostics = { consoleErrors: [], pageErrors: [], failedRequests: [], errorResponses: [] };
  });

  test.afterEach(() => {
    const expectedUnauthenticatedBootstrap = (response: BrowserDiagnostics['errorResponses'][number]) =>
      response.method === 'GET' && response.path === '/api/v1/auth/user' && response.status === 401;
    const expectedForbiddenAward = (response: BrowserDiagnostics['errorResponses'][number]) =>
      response.method === 'POST' && /^\/api\/v1\/purchasing\/rfqs\/[^/]+\/award$/.test(response.path) && response.status === 403;
    const expectedUnauthenticatedConsoleError = 'Failed to load resource: the server responded with a status of 401 (Unauthorized)';
    const expectedAbort = (request: BrowserDiagnostics['failedRequests'][number]) =>
      request.method === 'GET' && request.error === 'net::ERR_ABORTED';
    const expectedBootstrapResponses = browserDiagnostics.errorResponses.filter(expectedUnauthenticatedBootstrap);
    const expectedAwardDenials = browserDiagnostics.errorResponses.filter(expectedForbiddenAward);
    const expectedBootstrapConsoleErrors = browserDiagnostics.consoleErrors.filter((message) => message === expectedUnauthenticatedConsoleError);
    const unexpectedResponses = browserDiagnostics.errorResponses.filter((response) =>
      !expectedUnauthenticatedBootstrap(response) && !expectedForbiddenAward(response));
    const unexpectedRequestFailures = browserDiagnostics.failedRequests.filter((request) => !expectedAbort(request));
    const unexpectedConsoleErrors = browserDiagnostics.consoleErrors.filter((message) => message !== expectedUnauthenticatedConsoleError);
    console.log('Browser diagnostics:', JSON.stringify({
      consoleErrorCount: browserDiagnostics.consoleErrors.length,
      expectedUnauthenticatedBootstrapCount: expectedBootstrapResponses.length,
      expectedForbiddenAwardCount: expectedAwardDenials.length,
      pageErrors: browserDiagnostics.pageErrors,
      failedRequests: browserDiagnostics.failedRequests,
      errorResponses: browserDiagnostics.errorResponses,
    }));
    expect(unexpectedConsoleErrors, 'unexplained browser console errors').toEqual([]);
    expect(expectedBootstrapConsoleErrors, 'console errors must match unauthenticated bootstrap responses').toHaveLength(expectedBootstrapResponses.length);
    expect(browserDiagnostics.pageErrors, 'browser uncaught errors').toEqual([]);
    expect(unexpectedRequestFailures, 'browser failed requests').toEqual([]);
    expect(unexpectedResponses, 'browser HTTP error responses').toEqual([]);
  });

  test('Purchasing creates and publishes an RFQ, supplier submits, Finance and QC observe the correct boundaries', async ({ browser }) => {
    test.setTimeout(360_000);
    const supplierProbe = await newContext(browser);
    let supplierVendorName: string;
    try {
      await login(supplierProbe.page, 'portal@supp.test', true);
      supplierVendorName = await getSupplierVendorName(supplierProbe.page);
      expect(supplierVendorName).toBeTruthy();
    } finally {
      await supplierProbe.context.close();
    }

    const created = await submitNeedOnlyPr(browser);
    let rfqUrl: string;
    let closeAt: Date;
    try {
      await created.page.goto(created.url);
      await expect(created.page.getByRole('button', { name: 'Start RFQ', exact: true })).toBeVisible({ timeout: 20_000 });
      await created.page.getByRole('button', { name: 'Start RFQ', exact: true }).click();
      await created.page.waitForURL(/\/purchasing\/rfqs\/create\?purchase_request=/, { timeout: 20_000 });
      await expect(created.page.getByRole('heading', { name: 'Start supplier RFQ' })).toBeVisible();
      await created.page.getByLabel('Title').fill(RFQ_TITLE);
      const supplierName = created.page.getByText(supplierVendorName, { exact: true }).first();
      await expect(supplierName).toBeVisible({ timeout: 20_000 });
      await expect(supplierName.locator('xpath=../../..')).toContainText('Not qualified');
      await supplierName.locator('xpath=../..').getByRole('checkbox').check();
      await created.page.getByLabel('Reason for inviting a non-qualified supplier').fill(
        'Buyer verified the supplier documentation for this resin grade.',
      );
      closeAt = new Date(Date.now() + 30 * 60_000);
      const localDeadline = await created.page.evaluate((timestamp) => {
        const value = new Date(timestamp);
        const pad = (part: number) => String(part).padStart(2, '0');
        return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}T${pad(value.getHours())}:${pad(value.getMinutes())}`;
      }, closeAt.getTime());
      await created.page.getByLabel('Quotation deadline').fill(localDeadline);
      await created.page.getByRole('button', { name: 'Save draft', exact: true }).click();
      await created.page.waitForURL(/\/purchasing\/rfqs\/(?!create(?:\?|$))[^/]+$/, { timeout: 20_000 });
      rfqUrl = created.page.url();
      const rfqId = rfqUrl.split('/').pop()!;
      const draft = await readInternalRfq(created.page, rfqId);
      expect(draft).toMatchObject({
        title: RFQ_TITLE,
        status: 'draft',
        invited_count: 1,
        responded_count: 0,
        actions: { can_publish: true, can_close_now: false },
      });
      expect(draft.invitations[0]).toMatchObject({
        status: 'invited',
        exception_reason: 'Buyer verified the supplier documentation for this resin grade.',
      });
      await expect(created.page.getByRole('button', { name: 'Publish', exact: true })).toBeVisible();
      const publishResponse = created.page.waitForResponse((response) => response.url().includes('/publish') && response.request().method() === 'POST');
      await created.page.getByRole('button', { name: 'Publish', exact: true }).click();
      const publishResult = await publishResponse;
      expect(publishResult.status()).toBe(200);
      await expect(created.page.getByText('Open for quotes', { exact: true })).toBeVisible({ timeout: 20_000 });
      const open = await readInternalRfq(created.page, rfqId);
      expect(open.status).toBe('open');
      expect(open.quotes).toEqual([]);
      expect(open.actions.can_close_now).toBe(false);

      const prematureClose = await created.page.request.post(`${baseUrl()}/api/v1/purchasing/rfqs/${rfqId}/close`, {
        headers: await statefulCsrfHeaders(created.page),
      });
      expect(prematureClose.status()).toBe(422);
      expect(await prematureClose.text()).toMatch(/every invited supplier has submitted/i);
      const prematureCompare = await created.page.request.get(`${baseUrl()}/api/v1/purchasing/rfqs/${rfqId}/comparison`, {
        headers: statefulHeaders(),
      });
      expect(prematureCompare.status()).toBe(422);
      expect(await prematureCompare.text()).toMatch(/prices stay sealed/i);
      expect((await readInternalRfq(created.page, rfqId)).status).toBe('open');
    } finally {
      await created.context.close();
    }

    const supplier = await newContext(browser);
    const rfqId = rfqUrl.split('/').pop()!;
    let selectedLine: { quantity: number; total: number; vat: number };
    let quotedUnitPrice: number;
    let requestedLines: Array<{ id: string; description: string; quantity: string }>;
    try {
      await login(supplier.page, 'portal@supp.test', true);
      await supplier.page.goto(`/portal/supplier/rfqs?search=${encodeURIComponent(RFQ_TITLE)}`);
      await expect(supplier.page.getByText(RFQ_TITLE, { exact: true })).toBeVisible({ timeout: 20_000 });
      await supplier.page.getByText(RFQ_TITLE, { exact: true }).click();
      await supplier.page.getByRole('link', { name: 'Prepare quotation' }).click();
      const request = await readSupplierRfq(supplier.page, rfqId);
      expect(request.items.length).toBeGreaterThan(0);
      requestedLines = request.items.map(({ id, description, quantity }) => ({ id, description, quantity }));
      const selectedQuantity = Number(request.items[0]!.quantity);
      expect(selectedQuantity).toBeGreaterThan(0);
      quotedUnitPrice = Math.max(
        BASE_UNIT_PRICE,
        Math.ceil(((VP_APPROVAL_THRESHOLD + 1) / selectedQuantity) * 100) / 100,
      );
      const prices = supplier.page.getByLabel(/^Unit price/);
      const quantities = supplier.page.getByLabel('Quantity', { exact: true });
      const deliveryDates = supplier.page.getByLabel('Can deliver by', { exact: true });
      await expect(prices).toHaveCount(request.items.length);
      await expect(quantities).toHaveCount(request.items.length);
      await expect(deliveryDates).toHaveCount(request.items.length);
      const normalizedQuantities = request.items.map((item) => trimQuantity(item.quantity));
      const supplierDelivery = new Date();
      supplierDelivery.setDate(supplierDelivery.getDate() + 7);
      const deliveryDate = localIsoDate(supplierDelivery);
      for (const [index] of request.items.entries()) {
        await prices.nth(index).fill(quotedUnitPrice.toFixed(2));
        await quantities.nth(index).fill(normalizedQuantities[index]!);
        await deliveryDates.nth(index).fill(deliveryDate);
      }
      await supplier.page.getByLabel('Quotation PDF (required)').setInputFiles({ name: 'live-rfq-quotation.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 live RFQ') });
      const [wholeQuantity, fractionalQuantity = ''] = normalizedQuantities[0]!.split('.');
      const overPrecisionQuantity = `${wholeQuantity}.${fractionalQuantity.padEnd(4, '0')}`;
      const submitQuote = supplier.page.getByRole('button', { name: 'Submit quotation', exact: true });
      await quantities.first().fill(overPrecisionQuantity);
      await expect(submitQuote).toBeDisabled();
      await expect(supplier.page.getByRole('alert').filter({ hasText: /no more than 3 decimal places/i })).toBeVisible();
      await quantities.first().fill(normalizedQuantities[0]!);
      await expect(submitQuote).toBeEnabled();
      await submitQuote.click();
      await expect(supplier.page).toHaveURL(/\/portal\/supplier\/rfqs\/[^/]+$/, { timeout: 20_000 });
      await expect(supplier.page.getByText('Submitted', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
      const submitted = await readSupplierRfq(supplier.page, rfqId);
      expect(submitted.quote?.status).toBe('submitted');
      expect(submitted.quote?.items).toHaveLength(request.items.length);
      const expectedGoodsCents = request.items.reduce((total, item) => total + Math.round(Number(item.quantity) * quotedUnitPrice * 100), 0);
      for (const item of request.items) {
        const quoteItem = submitted.quote?.items.find((candidate) => candidate.request_for_quote_item_id === item.id);
        expect(quoteItem).toMatchObject({ response_status: 'quoted' });
        expect(Number(quoteItem?.offered_quantity)).toBe(Number(item.quantity));
        expect(Number(quoteItem?.unit_price)).toBe(quotedUnitPrice);
      }
      expect(Number(submitted.quote?.goods_amount)).toBe(expectedGoodsCents / 100);
      const selectedRequestLine = request.items[0]!;
      const quantity = Number(selectedRequestLine.quantity);
      const total = Math.round(quantity * quotedUnitPrice * 100) / 100;
      const vat = Math.round(
        total * Number(submitted.quote?.vat_amount) / Number(submitted.quote?.goods_amount) * 100,
      ) / 100;
      selectedLine = { quantity, total, vat };
      expect(Number(submitted.quote?.total_delivered_cost)).toBeCloseTo(
        Number(submitted.quote?.goods_amount) + Number(submitted.quote?.freight_amount) + Number(submitted.quote?.vat_amount),
        2,
      );
      expect(submitted.quote?.documents.some((document) => document.document_type === 'quotation_pdf')).toBe(true);
    } finally {
      await supplier.context.close();
    }

    const buyerCloser = await newContext(browser);
    try {
      await login(buyerCloser.page, 'purchasing@ogami.test');
      await buyerCloser.page.goto(rfqUrl);
      const waiting = await readInternalRfq(buyerCloser.page, rfqId);
      expect(waiting).toMatchObject({ status: 'open', invited_count: 1, responded_count: 1, actions: { can_close_now: true } });
      expect(waiting.invitations[0].status).toBe('submitted');
      await expect(buyerCloser.page.getByRole('button', { name: 'Close now', exact: true })).toBeVisible();
      await buyerCloser.page.getByRole('button', { name: 'Close now', exact: true }).click();
      await expect(buyerCloser.page.getByText('Ready to award', { exact: true })).toBeVisible({ timeout: 20_000 });
      const closed = await readInternalRfq(buyerCloser.page, rfqId);
      expect(closed).toMatchObject({ status: 'closed', actions: { can_close_now: false, can_compare: true, can_award: true } });
      expect(closed.quotes).toHaveLength(1);
      expect(closed.awards).toEqual([]);
    } finally {
      await buyerCloser.context.close();
    }

    const finance = await newContext(browser);
    try {
      await login(finance.page, 'finance@ogami.test');
      await finance.page.goto(`${rfqUrl}/compare`);
      await expect(finance.page.getByRole('heading', { name: 'Compare & award', exact: true })).toBeVisible({ timeout: 20_000 });
      await expect(finance.page.getByRole('button', { name: 'Award & create draft POs', exact: true })).toHaveCount(0);
      const deniedAward = await finance.page.request.post(`${baseUrl()}/api/v1/purchasing/rfqs/${rfqId}/award`, {
        headers: await statefulCsrfHeaders(finance.page),
        data: {},
      });
      expect(deniedAward.status()).toBe(403);
      expect(await deniedAward.text()).toContain('You do not have permission to perform this action.');
      const unchangedRfq = await readInternalRfq(finance.page, rfqId);
      expect(unchangedRfq).toMatchObject({ status: 'closed', awards: [] });
    } finally {
      await finance.context.close();
    }

    const purchasing = await newContext(browser);
    let poUrl: string;
    let poNumber: string;
    try {
      await login(purchasing.page, 'purchasing@ogami.test');
      const poResponse = purchasing.page.waitForResponse((response) => response.url().includes('/rfqs/') && response.url().endsWith('/purchase-orders') && response.request().method() === 'GET');
      await purchasing.page.goto(`${rfqUrl}/compare`);
      await expect(purchasing.page.getByRole('heading', { name: 'Compare & award', exact: true })).toBeVisible({ timeout: 20_000 });
      const comparison = await readInternalRfq(purchasing.page, rfqId);
      expect(comparison.items.map(({ id }) => id)).toEqual(requestedLines.map(({ id }) => id));
      const selectedRequestLine = comparison.items[0]!;
      await purchasing.page.getByRole('radio', {
        name: `Award ${selectedRequestLine.description} to ${supplierVendorName}`,
        exact: true,
      }).check();
      for (const item of comparison.items.slice(1)) {
        await purchasing.page.getByRole('radio', { name: `Do not award ${item.description}`, exact: true }).check();
      }
      await expect(purchasing.page.getByText(new RegExp(`1 of ${comparison.items.length} lines chosen`))).toBeVisible();
      await purchasing.page.getByLabel('Reason for this award').fill('The invited supplier was the only compliant source for this resin grade.');
      await purchasing.page.getByRole('button', { name: 'Award & create draft POs', exact: true }).click();
      await expect(purchasing.page).toHaveURL(/\/purchasing\/rfqs\/[^/]+$/, { timeout: 20_000 });
      await poResponse;
      await expect(purchasing.page.getByText(/Awarded|Partially awarded/, { exact: false }).first()).toBeVisible();
      const awardedRfq = await readInternalRfq(purchasing.page, rfqId);
      expect(awardedRfq.status).toBe('awarded');
      expect(awardedRfq.awards).toHaveLength(1);
      expect(Number(awardedRfq.awards[0].awarded_quantity)).toBe(selectedLine.quantity);
      expect(Number(awardedRfq.awards[0].awarded_unit_price)).toBe(quotedUnitPrice);
      expect(awardedRfq.awards[0].purchase_order?.id).toBeTruthy();
      const awardedPr = await readPurchaseRequest(purchasing.page, created.purchaseRequestId);
      expect(awardedPr).toMatchObject({ status: 'approved', po_conversion_status: 'partial' });
      expect(awardedPr.purchase_orders).toHaveLength(1);
      expect(awardedPr.purchase_orders?.[0]).toMatchObject({
        id: awardedRfq.awards[0].purchase_order!.id,
        status: 'draft',
      });
      expect(Number(awardedPr.purchase_orders?.[0]?.total_amount)).toBeCloseTo(selectedLine.total + selectedLine.vat, 2);
      const poLink = purchasing.page.locator('a[href*="/purchasing/purchase-orders/"]').first();
      await expect(poLink).toBeVisible();
      await poLink.click();
      poUrl = purchasing.page.url();
      const poId = poUrl.split('/').pop()!;
      const draftPo = await readPurchaseOrder(purchasing.page, poId);
      poNumber = draftPo.po_number;
      expect(draftPo).toMatchObject({ status: 'draft', rfq: { id: rfqId } });
      expect(Number(draftPo.items?.[0]?.quantity)).toBe(selectedLine.quantity);
      expect(Number(draftPo.items?.[0]?.unit_price)).toBe(quotedUnitPrice);
      expect(Number(draftPo.items?.[0]?.total)).toBe(selectedLine.total);
      expect(Number(draftPo.rfq_commercial?.vat_amount)).toBe(selectedLine.vat);
      expect(Number(draftPo.total_amount)).toBe(selectedLine.total + selectedLine.vat);
      expect(Number(draftPo.total_amount)).toBeGreaterThan(VP_APPROVAL_THRESHOLD);
      await expect(purchasing.page.getByRole('button', { name: 'Submit', exact: true })).toBeVisible({ timeout: 20_000 });
      await purchasing.page.getByRole('button', { name: 'Submit', exact: true }).click();
      await purchasing.page.getByRole('dialog').getByRole('button', { name: 'Submit', exact: true }).click();
      await expect(purchasing.page.getByText('Pending approval', { exact: true })).toBeVisible({ timeout: 20_000 });
      const submittedPo = await readPurchaseOrder(purchasing.page, poId);
      expect(submittedPo).toMatchObject({
        status: 'pending_approval',
        rfq: { id: rfqId },
      });
      expect(Number(submittedPo.items?.[0]?.quantity)).toBe(selectedLine.quantity);
      expect(Number(submittedPo.items?.[0]?.unit_price)).toBe(quotedUnitPrice);
      expect(Number(submittedPo.items?.[0]?.total)).toBe(selectedLine.total);
      expect(Number(submittedPo.rfq_commercial?.vat_amount)).toBe(selectedLine.vat);
      expect(Number(submittedPo.total_amount)).toBe(selectedLine.total + selectedLine.vat);
      expect(submittedPo.approval_records?.some((record) => record.action === 'pending')).toBe(true);
    } finally {
      await purchasing.context.close();
    }

    const financePo = await newContext(browser);
    try {
      await login(financePo.page, 'finance@ogami.test');
      await financePo.page.goto('/approvals');
      await expectInApprovalInbox(financePo.page, poNumber);
      await financePo.page.goto(poUrl);
      await expect(financePo.page.getByRole('button', { name: 'Approve', exact: true })).toBeVisible({ timeout: 20_000 });
      await financePo.page.getByRole('button', { name: 'Approve', exact: true }).click();
      await financePo.page.getByRole('dialog').getByRole('button', { name: 'Approve', exact: true }).click();
      await expect.poll(async () => {
        const state = await readPurchaseOrder(financePo.page, poUrl.split('/').pop()!);
        return state.approval_records?.some((record) => record.role_slug === 'finance_officer' && record.action === 'approved') ?? false;
      }, { timeout: 20_000, intervals: [250, 500, 1_000] }).toBe(true);
      const financeState = await readPurchaseOrder(financePo.page, poUrl.split('/').pop()!);
      expect(financeState.status).toBe('pending_approval');
      expect(financeState.approval_records).toEqual(expect.arrayContaining([
        expect.objectContaining({ role_slug: 'finance_officer', action: 'approved' }),
        expect.objectContaining({ role_slug: 'vice_president', action: 'pending' }),
      ]));
    } finally {
      await financePo.context.close();
    }

    const vpPo = await newContext(browser);
    try {
      await login(vpPo.page, 'vp@ogami.test');
      await vpPo.page.goto('/approvals');
      await expectInApprovalInbox(vpPo.page, poNumber);
      await vpPo.page.goto(poUrl);
      await expect(vpPo.page.getByRole('button', { name: 'Approve', exact: true })).toBeVisible({ timeout: 20_000 });
      await vpPo.page.getByRole('button', { name: 'Approve', exact: true }).click();
      await vpPo.page.getByRole('dialog').getByRole('button', { name: 'Approve', exact: true }).click();
      await expect.poll(async () => {
        return (await readPurchaseOrder(vpPo.page, poUrl.split('/').pop()!)).status;
      }, { timeout: 20_000, intervals: [250, 500, 1_000] }).toBe('approved');
      const approvedPo = await readPurchaseOrder(vpPo.page, poUrl.split('/').pop()!);
      expect(approvedPo.status).toBe('approved');
      expect(approvedPo.approved_at).toBeTruthy();
      expect(approvedPo.approval_records).toEqual(expect.arrayContaining([
        expect.objectContaining({ role_slug: 'finance_officer', action: 'approved' }),
        expect.objectContaining({ role_slug: 'vice_president', action: 'approved' }),
      ]));
      expect(approvedPo.approval_records?.some((record) => record.action === 'pending')).toBe(false);
    } finally {
      await vpPo.context.close();
    }

    const qc = await newContext(browser);
    try {
      await login(qc.page, 'qc@ogami.test');
      await qc.page.goto(rfqUrl);
      await expect(qc.page.getByRole('button', { name: 'View comparison', exact: true })).toBeVisible();
      await qc.page.getByRole('button', { name: 'View comparison', exact: true }).click();
      await expect(qc.page.getByRole('heading', { name: 'Compare & award', exact: true })).toBeVisible();
      await expect(qc.page.getByRole('button', { name: 'Award & create draft POs', exact: true })).toHaveCount(0);
    } finally {
      await qc.context.close();
    }

    const supplierOutcome = await newContext(browser);
    try {
      await login(supplierOutcome.page, 'portal@supp.test', true);
      await supplierOutcome.page.goto(`/portal/supplier/rfqs/${rfqUrl.split('/').pop()}`);
      await expect(supplierOutcome.page.getByText(/You were awarded one or more lines|Not selected this time/, { exact: false })).toBeVisible({ timeout: 20_000 });
    } finally {
      await supplierOutcome.context.close();
    }
  });
});
