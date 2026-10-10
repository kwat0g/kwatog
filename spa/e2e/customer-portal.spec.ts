import { expect, test } from './fixtures';

const CUSTOMER = {
  id: 'c_portal_user_01',
  name: 'Acme Customer',
  email: 'portal@acme.test',
  customer_id: 'c_customer_01',
  customer_name: 'Acme Manufacturing',
  must_change_password: false,
};

const pagination = (currentPage: number, total: number, perPage = 25) => ({
  current_page: currentPage,
  last_page: Math.ceil(total / perPage),
  per_page: perPage,
  total,
  from: total === 0 ? null : (currentPage - 1) * perPage + 1,
  to: Math.min(currentPage * perPage, total),
});

async function mockCustomerSession(page: import('@playwright/test').Page, mustChangePassword = false): Promise<void> {
  await page.route('**/sanctum/csrf-cookie', async (route) => {
    await route.fulfill({ status: 204 });
  });
  await page.route('**/api/v1/auth/user', async (route) => {
    await route.fulfill({ status: 401, contentType: 'application/json', body: JSON.stringify({ message: 'Unauthenticated.' }) });
  });
  await page.route('**/api/v1/landing/contact', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ legal_name: 'Ogami Philippines', address: null }),
    });
  });
  await page.route('**/api/v1/{business-policies,b2b/customer/business-policies}', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { functional_currency_code: 'PHP', vat_status: 'VAT Registered', vat_rate: '0.12' } }),
    });
  });
  await page.route('**/api/v1/auth/sign-in', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: { realm: 'customer', must_change_password: mustChangePassword, user: null },
      }),
    });
  });
  await page.route('**/api/v1/b2b/customer/logout', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ message: 'Logged out.' }) });
  });
  await page.route('**/api/v1/b2b/customer/me', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { ...CUSTOMER, must_change_password: mustChangePassword } }),
    });
  });
  await page.route('**/api/v1/b2b/customer/dashboard', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          open_so_count: 1,
          pending_delivery_count: 1,
          open_invoice_count: 1,
          total_outstanding: '1250.00',
          recent_orders: [{
            id: 'so_customer_01',
            so_number: 'SO-1001',
            date: '2026-08-25',
            total_amount: '1250.00',
            status: 'confirmed',
            status_label: 'Confirmed',
          }],
          recent_invoices: [{
            id: 'invoice_customer_01',
            invoice_number: 'INV-1001',
            date: '2026-08-25',
            total_amount: '1250.00',
            balance: '1250.00',
            status: 'finalized',
            status_label: 'Finalized',
            due_date: '2026-09-24',
          }],
          recent_deliveries: [{
            id: 'delivery_customer_01',
            delivery_number: 'DLV-1001',
            status: 'scheduled',
            status_label: 'Scheduled',
            scheduled_date: '2026-09-15',
            delivered_at: null,
          }],
          recent_complaints: [{
            id: 'complaint_customer_01',
            complaint_number: 'CMP-1001',
            severity: 'medium',
            severity_label: 'Medium',
            status: 'open',
            status_label: 'Open',
            description: 'Packaging issue',
            affected_quantity: 2,
            received_date: '2026-08-24',
          }],
        },
      }),
    });
  });
}

async function signIn(page: import('@playwright/test').Page, testInfo: import('@playwright/test').TestInfo): Promise<void> {
  testInfo.annotations.push(
    { type: 'expected-http-response', description: '401 GET /api/v1/auth/user' },
  );
  await page.goto('/sign-in');
  await page.getByLabel('Email').fill(CUSTOMER.email);
  await page.getByLabel('Password', { exact: true }).fill('CustomerPass-1!');
  await page.getByRole('button', { name: 'Sign in' }).click();
}

test.describe('customer portal', () => {
  test('keeps large orders compact and applies one requested delivery date to all products', async ({ page }) => {
    await mockCustomerSession(page);
    const products = Array.from({ length: 20 }, (_, index) => ({
      id: `product-${index}`, part_number: `PART-${index}`, name: `Wiper bushing ${index}`,
      unit_of_measure: 'pcs', unit_price: '22.50', pricing_method: 'flat',
    }));
    await page.route('**/api/v1/b2b/customer/catalog*', async (route) => {
      await route.fulfill({ json: { data: products } });
    });
    await page.route('**/api/v1/b2b/customer/orders/quote', async (route) => {
      const request = route.request().postDataJSON() as {
        items: Array<{ product_id: string; quantity: string; delivery_date: string }>;
      };
      const money = (centavos: number) => (centavos / 100).toFixed(2);
      const items = request.items.map((item) => ({
        ...item,
        unit_price: '22.50',
        total: money(Number(item.quantity) * 2250),
        price_source: 'base' as const,
      }));
      const subtotalCentavos = items.reduce((sum, item) => sum + Number(item.quantity) * 2250, 0);
      const vatCentavos = Math.round(subtotalCentavos * 0.12);
      await route.fulfill({ json: { data: {
        items,
        subtotal: money(subtotalCentavos),
        vat_amount: money(vatCentavos),
        total_amount: money(subtotalCentavos + vatCentavos),
        signature: 'a'.repeat(64),
      } } });
    });
    let submittedItems: Array<{ product_id: string; quantity: string; delivery_date: string }> = [];
    await page.route('**/api/v1/b2b/customer/orders', async (route) => {
      submittedItems = route.request().postDataJSON().items;
      await route.fulfill({ status: 201, json: { data: { id: 'so-large' }, message: 'Order submitted.' } });
    });
    await page.route('**/api/v1/b2b/customer/orders/so-large*', async (route) => {
      await route.fulfill({ json: { data: route.request().url().endsWith('/chain') ? [] : {
        id: 'so-large', so_number: 'SO-LARGE', status: 'draft', total_amount: '450.00', items: [],
      } } });
    });
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto('/portal/customer/orders/new');
    await page.getByRole('button', { name: 'Add products', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('textbox', { name: 'Search products' })).toBeFocused();
    for (let index = 0; index < products.length; index++) {
      await page.getByRole('button', { name: `Add Wiper bushing ${index} to order`, exact: true }).click();
    }
    await page.getByRole('button', { name: 'Done selecting products' }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('button', { name: 'Add products', exact: true })).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(page.getByRole('region', { name: 'Order items' })).toBeFocused();
    const items = page.getByRole('region', { name: 'Order items' });
    await expect(page.getByRole('list', { name: 'Available products' })).toBeHidden();
    await expect(page.getByRole('spinbutton')).toHaveCount(20);
    await expect(page.getByTestId('order-vat')).toContainText('54.00');
    await expect(page.getByTestId('order-total')).toContainText('504.00');
    await expect(page.getByText('VAT included', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Requested delivery date', { exact: true })).toHaveCount(1);
    const desktop = await items.evaluate((element) => ({ visible: element.clientHeight, content: element.scrollHeight }));
    expect(desktop.visible).toBeLessThanOrEqual(480);
    expect(desktop.content).toBeGreaterThan(desktop.visible);
    await expect(page.getByRole('button', { name: 'Submit order for review', exact: true })).toBeInViewport();
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
    const heightWith20 = await page.evaluate(() => document.documentElement.scrollHeight);
    await page.getByRole('spinbutton', { name: 'Quantity for PART-19', exact: true }).fill('100');
    await expect(page.getByTestId('order-subtotal')).toContainText('2,677.50');
    for (let index = 19; index >= 4; index--) {
      await page.getByRole('button', { name: `Remove Wiper bushing ${index}`, exact: true }).click();
    }
    expect(heightWith20 - await page.evaluate(() => document.documentElement.scrollHeight)).toBeLessThan(160);
    await expect(page.getByLabel('Order date', { exact: true })).not.toBeEditable();
    await expect(page.getByRole('table', { name: 'Selected order items' })).toBeVisible();
    const notes = page.getByRole('textbox', { name: 'Order note (optional)' });
    await expect(notes).toBeVisible();
    await notes.fill('Deliver to receiving bay.');
    const deliveryDateInput = page.getByLabel('Requested delivery date', { exact: true });
    await deliveryDateInput.fill('2000-01-01');
    await expect(page.getByRole('button', { name: 'Submit order for review', exact: true })).toBeDisabled();
    const earliestDeliveryDate = await deliveryDateInput.getAttribute('min');
    if (!earliestDeliveryDate) throw new Error('The delivery date must expose its minimum date.');
    await deliveryDateInput.fill(earliestDeliveryDate);
    await page.getByRole('button', { name: 'Submit order for review', exact: true }).click();
    await expect(page).toHaveURL(/\/orders\/so-large$/);
    expect(submittedItems).toHaveLength(4);
    expect(submittedItems.every((item) => item.delivery_date === earliestDeliveryDate)).toBe(true);
  });

  test('logs in with the customer session and can sign out', async ({ page }, testInfo) => {
    await mockCustomerSession(page);
    await signIn(page, testInfo);

    await expect(page).toHaveURL(/\/portal\/customer$/);
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    await expect(page.getByText('Recent Deliveries')).toBeVisible();
    await expect(page.getByText('Recent Quality Complaints')).toBeVisible();
    await expect(page.getByRole('link', { name: 'DLV-1001' })).toHaveAttribute(
      'href',
      '/portal/customer/deliveries/delivery_customer_01',
    );

    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/sign-in$/);
  });

  test('rejects an external portal return target', async ({ page }, testInfo) => {
    testInfo.annotations.push(
      { type: 'expected-http-response', description: '401 GET /api/v1/auth/user' },
    );
    await mockCustomerSession(page);
    await page.goto('/portal/customer/login?from=https%3A%2F%2Fattacker.test');
    await expect(page).toHaveURL(/\/sign-in$/);
    await page.getByLabel('Email').fill(CUSTOMER.email);
    await page.getByLabel('Password', { exact: true }).fill('CustomerPass-1!');
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page).toHaveURL(/\/portal\/customer$/);
    expect(new URL(page.url()).origin).toBe('http://127.0.0.1:5174');
  });

  test('shows scheduled delivery dates and paginates customer lists', async ({ page }) => {
    await mockCustomerSession(page);
    await page.route('**/api/v1/b2b/customer/deliveries*', async (route) => {
      const currentPage = Number(new URL(route.request().url()).searchParams.get('page') ?? 1);
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [{
            id: `delivery_customer_0${currentPage}`,
            delivery_number: `DLV-100${currentPage}`,
            status: currentPage === 1 ? 'scheduled' : 'delivered',
            status_label: currentPage === 1 ? 'Scheduled' : 'Delivered',
            scheduled_date: '2026-09-15',
            delivered_at: currentPage === 1 ? null : '2026-08-20T09:00:00Z',
          }],
          meta: pagination(currentPage, 26),
          links: { first: '?page=1', last: '?page=2', prev: null, next: '?page=2' },
        }),
      });
    });

    await page.goto('/portal/customer/deliveries');
    await expect(page.getByText('Sep 15, 2026')).toBeVisible();
    await expect(page.getByText('(scheduled)')).toBeVisible();
    await expect(page.getByText('Page 1 of 2')).toBeVisible();

    await page.getByRole('button', { name: 'Next page' }).click();
    await expect(page.getByText('Page 2 of 2')).toBeVisible();
    await expect(page.getByText('DLV-1002')).toBeVisible();
  });

  test('redirects first-login customers to the forced password change screen', async ({ page }, testInfo) => {
    await mockCustomerSession(page, true);
    await page.route('**/api/v1/auth/password-policy', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: { minimum_length: 12 } }),
      });
    });

    await signIn(page, testInfo);

    await expect(page).toHaveURL(/\/portal\/customer\/change-password$/);
    await expect(page.getByText('Set your portal password')).toBeVisible();
  });

  test('keeps the customer navigation usable on a narrow viewport', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await mockCustomerSession(page);
    await page.goto('/portal/customer');

    const openNavigation = page.getByRole('button', { name: 'Open portal navigation' });
    await expect(openNavigation).toBeVisible();
    await openNavigation.click();
    await expect(openNavigation).toHaveAttribute('aria-expanded', 'true');

    const navigation = page.getByRole('navigation', { name: 'Customer Portal navigation' });
    await expect(navigation).toBeVisible();
    await expect(navigation.getByRole('link', { name: 'Orders' })).toBeVisible();
  });

  test('shows loading feedback while portal business policies are delayed', async ({ page }) => {
    let businessPoliciesResponded = false;
    page.on('response', (response) => {
      if (new URL(response.url()).pathname === '/api/v1/b2b/customer/business-policies') {
        businessPoliciesResponded = true;
      }
    });
    await mockCustomerSession(page);
    await page.route('**/api/v1/b2b/customer/business-policies', async (route) => {
      await new Promise((resolve) => setTimeout(resolve, 8_000));
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: { functional_currency_code: 'PHP', vat_status: 'VAT Registered', vat_rate: '0.12' } }),
      });
    });
    const policiesRequest = page.waitForRequest((request) =>
      new URL(request.url()).pathname === '/api/v1/b2b/customer/business-policies',
    );
    await page.goto('/portal/customer');
    await policiesRequest;
    await expect(page.locator('header[aria-label="Customer Portal header"]')).toBeVisible();
    expect(businessPoliciesResponded).toBe(false);

    await expect(page.locator('#portal-main-content')).toContainText('Loading portal page…', { timeout: 1_000 });
    expect(businessPoliciesResponded).toBe(false);
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
  });

  test('blocks portal money pages until business currency settings load', async ({ page }, testInfo) => {
    testInfo.annotations.push(
      { type: 'expected-http-response', description: '503 GET /api/v1/b2b/customer/business-policies' },
      { type: 'expected-http-response', description: '503 GET /api/v1/b2b/customer/business-policies' },
    );
    await mockCustomerSession(page);
    let policyRequests = 0;
    await page.route('**/api/v1/b2b/customer/business-policies', async (route) => {
      policyRequests += 1;
      if (policyRequests <= 2) {
        await route.fulfill({ status: 503, json: { message: 'Temporarily unavailable.' } });
        return;
      }
      await route.fulfill({
        status: 200,
        json: { data: { functional_currency_code: 'PHP', vat_status: 'VAT Registered', vat_rate: '0.12' } },
      });
    });

    await page.goto('/portal/customer');
    await expect.poll(() => policyRequests).toBe(2);
    await expect(page.getByRole('alert')).toContainText('Business currency settings are unavailable.');
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Retry business settings' }).click();
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    expect(policyRequests).toBe(3);
  });

  test('keeps the portal usable when notifications fail while policies are pending', async ({ page }, testInfo) => {
    testInfo.annotations.push(
      { type: 'expected-http-response', description: '503 GET /api/v1/b2b/customer/notifications' },
      { type: 'expected-http-response', description: '503 GET /api/v1/b2b/customer/notifications' },
    );
    await mockCustomerSession(page);
    let releasePolicyResponse!: () => void;
    await page.route('**/api/v1/b2b/customer/business-policies', async (route) => {
      await new Promise<void>((resolve) => { releasePolicyResponse = resolve; });
      await route.fulfill({
        status: 200,
        json: { data: { functional_currency_code: 'PHP', vat_status: 'VAT Registered', vat_rate: '0.12' } },
      });
    });
    let notificationRequests = 0;
    await page.route('**/api/v1/b2b/customer/notifications*', async (route) => {
      notificationRequests += 1;
      if (notificationRequests <= 2) {
        await route.fulfill({ status: 503, json: { message: 'Temporarily unavailable.' } });
        return;
      }
      await route.fulfill({
        status: 200,
        json: { data: [], meta: { ...pagination(1, 0, 8), unread_count: 0 } },
      });
    });
    const policyRequest = page.waitForRequest((request) =>
      new URL(request.url()).pathname === '/api/v1/b2b/customer/business-policies',
    );

    await page.goto('/portal/customer');
    await policyRequest;
    await expect(page.locator('#portal-main-content')).toContainText('Loading portal page…');
    await page.getByRole('button', { name: 'Notifications', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Could not load notifications' })).toBeVisible();
    await expect(page.locator('#portal-main-content')).toContainText('Loading portal page…');
    await page.getByRole('button', { name: 'Retry' }).click();
    await expect(page.getByRole('heading', { name: 'No notifications yet' })).toBeVisible();
    expect(notificationRequests).toBe(3);

    releasePolicyResponse();
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
  });

  test('retries one portal 401 when the realm session probe still succeeds', async ({ page }, testInfo) => {
    testInfo.annotations.push(
      { type: 'expected-http-response', description: '401 GET /api/v1/b2b/customer/business-policies' },
    );
    await mockCustomerSession(page);
    let policyRequests = 0;
    await page.route('**/api/v1/b2b/customer/business-policies', async (route) => {
      policyRequests += 1;
      if (policyRequests === 1) {
        await route.fulfill({ status: 401, json: { message: 'Unauthenticated.' } });
        return;
      }
      await route.fulfill({
        status: 200,
        json: { data: { functional_currency_code: 'PHP', vat_status: 'VAT Registered', vat_rate: '0.12' } },
      });
    });

    await page.goto('/portal/customer');

    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    expect(policyRequests).toBe(2);
    await expect(page).not.toHaveURL(/\/sign-in$/);
  });

  test('returns to the original customer page after session expiry during policy loading', async ({ page }, testInfo) => {
    testInfo.annotations.push(
      { type: 'expected-http-response', description: '401 GET /api/v1/b2b/customer/business-policies' },
      { type: 'expected-http-response', description: '401 GET /api/v1/b2b/customer/me' },
      { type: 'expected-http-response', description: '401 GET /api/v1/auth/user' },
    );
    await mockCustomerSession(page);
    await page.unroute('**/api/v1/b2b/customer/me');
    let portalMeCalls = 0;
    await page.route('**/api/v1/b2b/customer/me', async (route) => {
      portalMeCalls += 1;
      await route.fulfill(portalMeCalls !== 3
        ? { status: 200, json: { data: CUSTOMER } }
        : { status: 401, json: { message: 'Unauthenticated.' } });
    });
    let policyCalls = 0;
    let releasePolicyResponse!: () => void;
    await page.route('**/api/v1/b2b/customer/business-policies', async (route) => {
      policyCalls += 1;
      if (policyCalls === 1) {
        await new Promise<void>((resolve) => { releasePolicyResponse = resolve; });
        await route.fulfill({ status: 401, json: { message: 'Unauthenticated.' } });
        return;
      }
      await route.fulfill({
        status: 200,
        json: { data: { functional_currency_code: 'PHP', vat_status: 'VAT Registered', vat_rate: '0.12' } },
      });
    });
    await page.route('**/api/v1/b2b/customer/invoices*', async (route) => {
      await route.fulfill({
        status: 200,
        json: { data: [], meta: pagination(1, 0), links: { first: '', last: '', prev: null, next: null } },
      });
    });
    const policyRequest = page.waitForRequest((request) =>
      new URL(request.url()).pathname === '/api/v1/b2b/customer/business-policies',
    );

    await page.goto('/portal/customer/invoices');
    await policyRequest;
    await expect(page.locator('#portal-main-content')).toContainText('Loading portal page…');
    releasePolicyResponse();

    await expect(page).toHaveURL(/\/sign-in$/, { timeout: 10_000 });
    await expect(page.getByRole('heading', { name: 'Sign in to Ogami' })).toBeVisible();
    await expect(page.getByText('Your session expired. Please sign in again to continue.')).toBeVisible();
    await page.getByLabel('Email').fill(CUSTOMER.email);
    await page.getByLabel('Password', { exact: true }).fill('CustomerPass-1!');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/portal\/customer\/invoices$/);
    await expect(page.getByRole('heading', { name: 'Invoices', exact: true })).toBeVisible();
    expect(policyCalls).toBeGreaterThanOrEqual(2);
    expect(portalMeCalls).toBeGreaterThanOrEqual(4);
  });

  test('returns to the original customer page when portal bootstrap finds an expired session', async ({ page }, testInfo) => {
    testInfo.annotations.push(
      { type: 'expected-http-response', description: '401 GET /api/v1/b2b/customer/me' },
      { type: 'expected-http-response', description: '401 GET /api/v1/b2b/customer/me' },
      { type: 'expected-http-response', description: '401 GET /api/v1/auth/user' },
    );
    await mockCustomerSession(page);
    await page.unroute('**/api/v1/b2b/customer/me');
    let portalMeCalls = 0;
    await page.route('**/api/v1/b2b/customer/me', async (route) => {
      portalMeCalls += 1;
      await route.fulfill(portalMeCalls <= 2
        ? { status: 401, json: { message: 'Unauthenticated.' } }
        : { status: 200, json: { data: CUSTOMER } });
    });
    await page.route('**/api/v1/b2b/customer/invoices*', async (route) => {
      await route.fulfill({
        status: 200,
        json: { data: [], meta: pagination(1, 0), links: { first: '', last: '', prev: null, next: null } },
      });
    });

    await page.goto('/portal/customer/invoices');
    await expect(page).toHaveURL(/\/sign-in$/);
    await page.getByLabel('Email').fill(CUSTOMER.email);
    await page.getByLabel('Password', { exact: true }).fill('CustomerPass-1!');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/portal\/customer\/invoices$/);
    await expect(page.getByRole('heading', { name: 'Invoices', exact: true })).toBeVisible();
  });

  test('customer can respond to an order and reach the RMA form', async ({ page }) => {
    await mockCustomerSession(page);
    await page.route('**/api/v1/b2b/customer/orders/so_customer_01/chain', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
    });
    await page.route('**/api/v1/b2b/customer/orders/so_customer_01', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            id: 'so_customer_01',
            so_number: 'SO-1001',
            date: '2026-09-20',
            total_amount: '1500.00',
            status: 'draft',
            status_label: 'Draft',
            items: [{
              id: 'so_line_01',
              product: { id: 'product_01', part_number: 'WIP-001', name: 'Wiper pivot cap', unit_of_measure: 'pcs' },
              quantity: '100.00',
              unit_price: '15.00',
              total: '1500.00',
              delivery_date: '2026-10-01',
            }],
            capabilities: { can_respond: true, can_confirm: true },
            latest_response: null,
          },
        }),
      });
    });
    await page.route('**/api/v1/b2b/customer/orders/so_customer_01/respond', async (route) => {
      expect(route.request().postDataJSON()).toMatchObject({
        type: 'propose',
        items: [{ sales_order_item_id: 'so_line_01', proposed_quantity: '120.00', proposed_unit_price: '14.50' }],
      });
      await route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({ data: { id: 'response_01' }, message: 'Your response was submitted to our sales team.' }),
      });
    });

    await page.goto('/portal/customer/orders/so_customer_01');
    await page.getByLabel('Response').selectOption('propose');
    await page.getByLabel('Proposed quantity').fill('120.00');
    await page.getByLabel('Proposed unit price').fill('14.50');
    await page.getByRole('button', { name: 'Submit proposal' }).click();
    await expect(page.getByText('Your response was submitted to our sales team.')).toBeVisible();

    await page.route('**/api/v1/b2b/customer/return-requests?*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [], meta: pagination(1, 0), links: { first: '', last: '', prev: null, next: null } }),
      });
    });
    await page.getByRole('link', { name: 'Returns' }).click();
    await expect(page.getByText('No return requests yet')).toBeVisible();

    await page.route('**/api/v1/b2b/customer/return-requests/source-options', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: { customer: {
          invoices: [{ id: 'invoice_01', label: 'INV-1001', lines: [{
            id: 'invoice_line_01', product_id: 'product_01', item_id: 'item_01', quantity: '2.000', remaining_quantity: '2.000', unit_price: '15.00', label: 'Wiper pivot cap',
          }] }],
          salesOrders: [],
          deliveries: [],
        } } }),
      });
    });
    await page.route('**/api/v1/b2b/customer/return-requests', async (route) => {
      await route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({ data: {
          id: 'rma_customer_01', rma_number: 'RMA-1001', type: 'customer_return', status: 'draft',
          status_label: 'Draft', reason_code: null, reason_description: 'Packaging damaged', customer_notes: null,
          resolution: null, return_date: '2026-09-23', created_at: new Date().toISOString(), items: [],
        }, message: 'Return request submitted.' }),
      });
    });
    await page.route('**/api/v1/b2b/customer/return-requests/rma_customer_01', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: {
          id: 'rma_customer_01', rma_number: 'RMA-1001', type: 'customer_return', status: 'draft',
          status_label: 'Draft', reason_code: null, reason_description: 'Packaging damaged', customer_notes: null,
          resolution: null, return_date: '2026-09-23', created_at: new Date().toISOString(), items: [],
        } }),
      });
    });

    await page.getByRole('button', { name: 'Start a return' }).click();
    await page.getByLabel(/Delivered item/).selectOption('invoice:invoice_line_01');
    await page.getByLabel('Reason').fill('Packaging damaged');
    await page.getByRole('button', { name: 'Submit return request' }).click();
    await expect(page.getByText('RMA-1001')).toBeVisible();
  });
});
