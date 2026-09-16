import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';

const PASSWORD = 'password';

async function newContext(browser: Browser): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ baseURL: 'http://localhost' });
  return { context, page: await context.newPage() };
}

async function login(page: Page, email: string, portal = false): Promise<void> {
  await page.goto(portal ? '/portal/supplier/login' : '/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).not.toHaveURL(portal ? /\/portal\/supplier\/login$/ : /\/login$/, { timeout: 20_000 });
  await page.locator(portal ? '#portal-main-content' : '#main-content').waitFor({ state: 'attached', timeout: 20_000 });
}

async function getSupplierVendorName(page: Page): Promise<string> {
  return page.evaluate(async () => {
    const response = await fetch('/api/v1/b2b/supplier/me', { credentials: 'include' });
    const body = await response.json() as { data?: { vendor?: { name?: string } } };
    return body.data?.vendor_name ?? '';
  });
}

async function createAndApprovePr(browser: Browser): Promise<{ context: BrowserContext; page: Page; url: string }> {
  const created = await newContext(browser);
  await login(created.page, 'purchasing@ogami.test');
  await created.page.goto('/purchasing/purchase-requests/create');
  await created.page.getByLabel('Priority').selectOption('normal');
  await created.page.getByLabel('Sourcing method').selectOption('rfq');
  const department = created.page.getByLabel('Department');
  await expect.poll(() => department.locator('option').count(), { timeout: 20_000 }).toBeGreaterThan(1);
  const production = await department.locator('option').evaluateAll((options) => options.find((option) => (option.textContent ?? '').includes('Production') && !(option.textContent ?? '').includes('Planning'))?.value ?? '');
  await department.selectOption(production);
  await created.page.getByLabel('Reason').fill('Live E2E sealed resin sourcing');
  await created.page.getByLabel('Quantity').fill('500');
  await created.page.getByLabel('Estimated unit price').fill('120.00');
  const item = created.page.locator('select[aria-label="Item"]');
  await expect.poll(() => item.locator('option').count(), { timeout: 20_000 }).toBeGreaterThan(1);
  const itemValue = await item.locator('option').evaluateAll((options) => options.find((option) => (option.textContent ?? '').includes('RM-001'))?.getAttribute('value') ?? '');
  await item.selectOption(itemValue);
  await created.page.getByRole('button', { name: 'Submit for approval', exact: true }).click();
  await created.page.getByRole('dialog').getByRole('button', { name: 'Submit', exact: true }).click();
  await created.page.waitForURL(/\/purchasing\/purchase-requests\/(?!create$)[^/]+$/, { timeout: 20_000 });
  const url = created.page.url();

  const finance = await newContext(browser);
  try {
    await login(finance.page, 'finance@ogami.test');
    await finance.page.goto(url);
    await finance.page.getByRole('button', { name: 'Approve', exact: true }).click();
    await finance.page.getByRole('dialog').getByRole('button', { name: 'Approve', exact: true }).click();
  } finally {
    await finance.context.close();
  }

  const vp = await newContext(browser);
  try {
    await login(vp.page, 'vp@ogami.test');
    await vp.page.goto(url);
    await expect(vp.page.getByRole('button', { name: 'Approve', exact: true })).toBeVisible({ timeout: 20_000 });
    await vp.page.getByRole('button', { name: 'Approve', exact: true }).click();
    await vp.page.getByRole('dialog').getByRole('button', { name: 'Approve', exact: true }).click();
    await expect(vp.page.getByText('Approved', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
  } finally {
    await vp.context.close();
  }

  return { ...created, url };
}

test.describe('Live sealed RFQ role workflow', () => {
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

    const created = await createAndApprovePr(browser);
    let rfqUrl: string;
    let closeAt: Date;
    try {
      await created.page.goto(created.url);
      await expect(created.page.getByRole('button', { name: 'Start RFQ', exact: true })).toBeVisible({ timeout: 20_000 });
      await created.page.getByRole('button', { name: 'Start RFQ', exact: true }).click();
      await created.page.waitForURL(/\/purchasing\/rfqs\/create\?purchase_request=/, { timeout: 20_000 });
      await created.page.getByRole('button', { name: 'Next', exact: true }).click();

      const supplierName = created.page.getByText(supplierVendorName, { exact: true }).first();
      await expect(supplierName).toBeVisible({ timeout: 20_000 });
      await supplierName.locator('xpath=../..').getByRole('checkbox').check();
      await created.page.getByRole('button', { name: 'Next', exact: true }).click();
      closeAt = new Date(Date.now() + 180_000);
      const localDeadline = await created.page.evaluate((timestamp) => {
        const value = new Date(timestamp);
        const pad = (part: number) => String(part).padStart(2, '0');
        return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}T${pad(value.getHours())}:${pad(value.getMinutes())}`;
      }, closeAt.getTime());
      await created.page.getByLabel('Submission deadline').fill(localDeadline);
      await created.page.getByRole('button', { name: 'Next', exact: true }).click();
      await created.page.getByRole('button', { name: 'Create draft', exact: true }).click();
      await created.page.waitForURL(/\/purchasing\/rfqs\/(?!create(?:\?|$))[^/]+$/, { timeout: 20_000 });
      rfqUrl = created.page.url();
      await expect(created.page.getByRole('button', { name: 'Publish', exact: true })).toBeVisible();
      const publishResponse = created.page.waitForResponse((response) => response.url().includes('/publish') && response.request().method() === 'POST');
      await created.page.getByRole('button', { name: 'Publish', exact: true }).click();
      const publishResult = await publishResponse;
      expect(publishResult.status()).toBe(200);
      await expect(created.page.getByText('Open', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
    } finally {
      await created.context.close();
    }

    const supplier = await newContext(browser);
    try {
      await login(supplier.page, 'portal@supp.test', true);
      await supplier.page.goto('/portal/supplier/rfqs');
      await expect(supplier.page.getByText(/RFQ-/).first()).toBeVisible({ timeout: 20_000 });
      await supplier.page.getByText(/RFQ-/, { exact: false }).first().click();
      await supplier.page.getByRole('link', { name: 'Prepare quotation' }).click();
      await supplier.page.getByRole('checkbox', { name: 'No quote' }).first().uncheck();
       await supplier.page.getByLabel('Offered quantity').fill('500');
      await supplier.page.getByLabel('Unit price').fill('120.00');
      await supplier.page.getByLabel('Lead time days').fill('7');
      await supplier.page.getByLabel('Formal quotation PDF').setInputFiles({ name: 'live-rfq-quotation.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 live RFQ') });
      await supplier.page.getByRole('button', { name: 'Submit sealed quotation', exact: true }).click();
      await expect(supplier.page).toHaveURL(/\/portal\/supplier\/rfqs\/[^/]+$/, { timeout: 20_000 });
    } finally {
      await supplier.context.close();
    }

    await expect.poll(() => Date.now(), { timeout: 180_000, intervals: [1_000] }).toBeGreaterThan(closeAt.getTime());

    const finance = await newContext(browser);
    try {
      await login(finance.page, 'finance@ogami.test');
      await finance.page.goto(`${rfqUrl}/compare`);
      await expect(finance.page.getByText('Quotation comparison', { exact: true })).toBeVisible({ timeout: 20_000 });
      await expect(finance.page.getByRole('button', { name: 'Award selected lines', exact: true })).toHaveCount(0);
    } finally {
      await finance.context.close();
    }

    const purchasing = await newContext(browser);
    let poUrl: string;
    try {
      await login(purchasing.page, 'purchasing@ogami.test');
      const poResponse = purchasing.page.waitForResponse((response) => response.url().includes('/rfqs/') && response.url().endsWith('/purchase-orders') && response.request().method() === 'GET');
      await purchasing.page.goto(`${rfqUrl}/compare`);
      await expect(purchasing.page.getByText('Quotation comparison', { exact: true })).toBeVisible({ timeout: 20_000 });
      await purchasing.page.getByRole('checkbox').first().check();
      const justification = purchasing.page.getByLabel('Single-response justification');
      if (await justification.count()) await justification.fill('The invited supplier was the only compliant source for this resin grade.');
      await purchasing.page.getByLabel('Award reason').fill('Lowest compliant delivered cost with the required delivery date.');
      await purchasing.page.getByRole('button', { name: 'Award selected lines', exact: true }).click();
      await expect(purchasing.page).toHaveURL(/\/purchasing\/rfqs\/[^/]+$/, { timeout: 20_000 });
      await poResponse;
      await expect(purchasing.page.getByText(/Awarded|Partially awarded/, { exact: false }).first()).toBeVisible();
      const poLink = purchasing.page.locator('a[href*="/purchasing/purchase-orders/"]').first();
      await expect(poLink).toBeVisible();
      await poLink.click();
      poUrl = purchasing.page.url();
      await expect(purchasing.page.getByRole('button', { name: 'Submit', exact: true })).toBeVisible({ timeout: 20_000 });
      await purchasing.page.getByRole('button', { name: 'Submit', exact: true }).click();
      await purchasing.page.getByRole('dialog').getByRole('button', { name: 'Submit', exact: true }).click();
      await expect(purchasing.page.getByText('Pending approval', { exact: true })).toBeVisible({ timeout: 20_000 });
    } finally {
      await purchasing.context.close();
    }

    const financePo = await newContext(browser);
    try {
      await login(financePo.page, 'finance@ogami.test');
      await financePo.page.goto(poUrl);
      await expect(financePo.page.getByRole('button', { name: 'Approve', exact: true })).toBeVisible({ timeout: 20_000 });
      await financePo.page.getByRole('button', { name: 'Approve', exact: true }).click();
      await financePo.page.getByRole('dialog').getByRole('button', { name: 'Approve', exact: true }).click();
    } finally {
      await financePo.context.close();
    }

    const vpPo = await newContext(browser);
    try {
      await login(vpPo.page, 'vp@ogami.test');
      await vpPo.page.goto(poUrl);
      await expect(vpPo.page.getByRole('button', { name: 'Approve', exact: true })).toBeVisible({ timeout: 20_000 });
      await vpPo.page.getByRole('button', { name: 'Approve', exact: true }).click();
      await vpPo.page.getByRole('dialog').getByRole('button', { name: 'Approve', exact: true }).click();
      await expect(vpPo.page.getByText('Approved', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
    } finally {
      await vpPo.context.close();
    }

    const qc = await newContext(browser);
    try {
      await login(qc.page, 'qc@ogami.test');
      await qc.page.goto(rfqUrl);
      await expect(qc.page.getByRole('button', { name: 'Compare quotations', exact: true })).toHaveCount(0);
    } finally {
      await qc.context.close();
    }

    const supplierOutcome = await newContext(browser);
    try {
      await login(supplierOutcome.page, 'portal@supp.test', true);
      await supplierOutcome.page.goto(`/portal/supplier/rfqs/${rfqUrl.split('/').pop()}`);
      await expect(supplierOutcome.page.getByText(/Your supplier was awarded|not selected|No award/, { exact: false })).toBeVisible({ timeout: 20_000 });
    } finally {
      await supplierOutcome.context.close();
    }
  });
});
