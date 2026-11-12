import { readFile } from 'node:fs/promises';
import { expect, test } from './fixtures';
import { actorPage, loadActors } from './actor-session';

test('CRM-CUSTOMER-SETUP: sales officer creates a customer with a pinned receiving location', async ({ browser }, testInfo) => {
  const actors = await loadActors();
  const salesOfficer = actors.find((actor) => actor.actor === 'sales_officer');
  if (!salesOfficer) throw new Error('The seeded sales_officer E2E account is missing.');

  const { context, page, diagnostics } = await actorPage(browser, salesOfficer.actor, {
    viewport: { width: 390, height: 844 },
  });
  let customerId: string | undefined;

  try {
    await page.route('**tile.openstreetmap.org/**', (route) => route.fulfill({
      status: 200,
      contentType: 'image/png',
      body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/nJ8AAAAASUVORK5CYII=', 'base64'),
    }));
    await page.goto('/crm/customers/create');
    await expect(page.getByRole('heading', { name: 'New customer' })).toBeVisible();
    const sampleAgreement = await readFile('e2e-process/fixtures/sample-customer-supply-agreement.pdf');
    await page.getByLabel('Select PDF files').setInputFiles({
      name: 'customer-agreement.pdf', mimeType: 'application/pdf', buffer: sampleAgreement,
    });
    await page.getByText('Review detected agreement terms').click();
    await expect(page.getByRole('textbox', { name: 'Customer name' })).toHaveValue('Demo Automotive Components, Inc.');
    await expect(page.getByRole('textbox', { name: 'Contact person' })).toHaveValue('Marissa Santos');
    await expect(page.getByRole('textbox', { name: 'Contact role / title' })).toHaveValue('Procurement Manager');
    await page.getByRole('button', { name: 'Continue' }).click();
    await expect(page.getByRole('heading', { name: 'Billing & payment' })).toBeVisible();
    await expect(page.getByRole('textbox', { name: 'Billing address' })).toHaveValue(/1200 Sample Avenue/);
    await expect(page.getByRole('spinbutton', { name: 'Default payment terms (days)' })).toHaveValue('30');
    await page.getByRole('button', { name: 'Continue' }).click();
    await expect(page.getByRole('heading', { name: 'Delivery' })).toBeVisible();
    await expect(page.getByRole('textbox', { name: 'Delivery address' })).toHaveValue(/Warehouse Gate B/);
    await page.getByRole('textbox', { name: 'Delivery latitude' }).fill('14.2500000');
    await page.getByRole('textbox', { name: 'Delivery longitude' }).fill('121.1000000');
    await page.getByRole('button', { name: 'Continue' }).click();
    await expect(page.getByRole('heading', { name: 'Agreement & products' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Product Ordering Setup' })).toBeVisible();
    await expect(page.getByText(/2 selected · \d+ active products loaded/)).toBeVisible();
    await expect(page.getByRole('checkbox', { name: /BB-001.*Wiper Motor Bobbin/ })).toBeChecked();
    await expect(page.getByRole('checkbox', { name: /BU-001.*Windshield Wiper Bushing/ })).toBeChecked();
    await expect(page.getByRole('textbox', { name: /Warranty option name for Wiper Motor Bobbin/ })).toHaveValue('12-month standard');
    await expect(page.getByRole('textbox', { name: /Warranty option name for Windshield Wiper Bushing/ })).toHaveValue('6-month limited');
    await expect(page.getByRole('spinbutton', { name: 'Minimum order quantity for BB-001' })).toHaveValue('10');
    await expect(page.getByRole('spinbutton', { name: 'Maximum order quantity for BB-001 (optional)' })).toHaveValue('500');
    await expect(page.getByRole('spinbutton', { name: 'Minimum order quantity for BU-001' })).toHaveValue('5');
    await expect(page.getByRole('spinbutton', { name: 'Maximum order quantity for BU-001 (optional)' })).toHaveValue('300');
    await expect(page.getByText('Fallback warranty settings (optional)', { exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Continue' }).click();
    await expect(page.getByRole('heading', { name: 'Review' })).toBeVisible();

    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath('customer-create-mobile.png'), fullPage: true });
    const createResponse = page.waitForResponse((response) =>
      response.request().method() === 'POST'
      && new URL(response.url()).pathname === '/api/v1/crm/customers',
    );
    await page.getByRole('button', { name: 'Create customer' }).click();
    const createdResponse = await createResponse;
    expect(createdResponse.status()).toBe(201);
    const createPayload = createdResponse.request().postDataJSON();
    expect(createPayload.portal_product_ids).toHaveLength(2);
    expect(createPayload.product_order_limits).toEqual([
      { product_id: expect.any(String), minimum_order_quantity: 10, maximum_order_quantity: 500 },
      { product_id: expect.any(String), minimum_order_quantity: 5, maximum_order_quantity: 300 },
    ]);
    expect(createPayload.product_warranties.map((option: { label: string }) => option.label))
      .toEqual(expect.arrayContaining(['12-month standard', '6-month limited']));
    await expect(page.getByRole('heading', { name: 'Set up customer' })).toBeVisible({ timeout: 20_000 });
    await expect(page).toHaveURL(/\/crm\/customers\/create\?setup=[^&]+$/);
    customerId = new URL(page.url()).searchParams.get('setup') ?? undefined;
    expect(customerId).toBeTruthy();
    await expect(page.getByText(/Product access and warranty choices are already set/)).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Saved product access and warranties' })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Agreement documents', exact: true })).toBeVisible();
    await expect(page.getByText('PDFs selected during customer creation')).toBeVisible();
    await expect(page.getByText('customer-agreement.pdf', { exact: true })).toBeVisible();

    const attachResponse = page.waitForResponse((response) =>
      response.request().method() === 'POST'
      && new URL(response.url()).pathname === `/api/v1/crm/customers/${customerId}/agreement-documents`,
    );
    await page.getByRole('button', { name: 'Attach customer-agreement.pdf' }).click();
    expect((await attachResponse).status()).toBe(201);
    await expect(page.getByRole('button', { name: 'Attached customer-agreement.pdf' })).toBeDisabled();

    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath('customer-agreement-setup-mobile.png'), fullPage: true });
    await page.getByRole('button', { name: 'Finish and open customer record' }).click();
    await expect(page).toHaveURL(new RegExp(`/crm/customers/${customerId}$`), { timeout: 20_000 });
    await expect(page.getByText('Procurement Manager', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Open delivery pin in map' }))
      .toHaveAttribute('href', /mlat=14\.25&mlon=121\.1/);

    await page.setViewportSize({ width: 1440, height: 1000 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath('customer-record-desktop.png'), fullPage: true });
    diagnostics.assertClean('customer creation and record review');
  } finally {
    if (customerId) {
      const status = await page.evaluate(async (id) => {
        const xsrfCookie = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='));
        const xsrfToken = xsrfCookie ? decodeURIComponent(xsrfCookie.slice('XSRF-TOKEN='.length)) : '';
        const response = await fetch(`/api/v1/crm/customers/${id}`, {
          method: 'DELETE',
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken,
          },
        });
        return response.status;
      }, customerId);
      expect(status).toBeLessThan(300);
    }
    await context.close();
  }
});
