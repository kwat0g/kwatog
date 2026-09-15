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
  const department = created.page.getByLabel('Department');
  await expect.poll(() => department.locator('option').count(), { timeout: 20_000 }).toBeGreaterThan(1);
  const production = await department.locator('option').evaluateAll((options) => options.find((option) => (option.textContent ?? '').includes('Production') && !(option.textContent ?? '').includes('Planning'))?.value ?? '');
  await department.selectOption(production);
  await created.page.getByLabel('Reason').fill('Live E2E sealed resin sourcing');
  await created.page.getByLabel('Quantity').fill('5');
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
    await expect(finance.page.getByText('Approved', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
  } finally {
    await finance.context.close();
  }

  return { ...created, url };
}

test.describe('Live sealed RFQ role workflow', () => {
  test('Purchasing creates and publishes an RFQ, supplier submits, Finance and QC observe the correct boundaries', async ({ browser }) => {
    test.setTimeout(180_000);
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
      await created.page.getByRole('button', { name: 'Next', exact: true }).click();
      await created.page.getByRole('button', { name: 'Create draft', exact: true }).click();
      await created.page.waitForURL(/\/purchasing\/rfqs\/(?!create(?:\?|$))[^/]+$/, { timeout: 20_000 });
      rfqUrl = created.page.url();
      await expect(created.page.getByRole('button', { name: 'Publish', exact: true })).toBeVisible();
      await created.page.getByRole('button', { name: 'Publish', exact: true }).click();
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
      await supplier.page.getByLabel('Offered quantity').fill('5');
      await supplier.page.getByLabel('Unit price').fill('120.00');
      await supplier.page.getByLabel('Lead time days').fill('7');
      await supplier.page.getByLabel('Formal quotation PDF').setInputFiles({ name: 'live-rfq-quotation.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 live RFQ') });
      await supplier.page.getByRole('button', { name: 'Submit sealed quotation', exact: true }).click();
      await expect(supplier.page).toHaveURL(/\/portal\/supplier\/rfqs\/[^/]+$/, { timeout: 20_000 });
    } finally {
      await supplier.context.close();
    }

    const finance = await newContext(browser);
    try {
      await login(finance.page, 'finance@ogami.test');
      await finance.page.goto(rfqUrl);
      await expect(finance.page.getByText('Open', { exact: true }).first()).toBeVisible();
      await expect(finance.page.getByRole('button', { name: 'Compare quotations', exact: true })).toHaveCount(0);
    } finally {
      await finance.context.close();
    }

    const qc = await newContext(browser);
    try {
      await login(qc.page, 'qc@ogami.test');
      await qc.page.goto(rfqUrl);
      await expect(qc.page.getByRole('button', { name: 'Compare quotations', exact: true })).toHaveCount(0);
    } finally {
      await qc.context.close();
    }
  });
});
