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
  await page.route('**/api/v1/landing/contact', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ legal_name: 'Ogami Philippines', address: null }),
    });
  });
  await page.route('**/api/v1/business-policies', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { functional_currency_code: 'PHP' } }),
    });
  });
  await page.route('**/api/v1/b2b/customer/login', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          user: { ...CUSTOMER, must_change_password: mustChangePassword },
        },
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

async function signIn(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/portal/customer/login');
  await page.getByLabel('Email').fill(CUSTOMER.email);
  await page.getByLabel('Password').fill('CustomerPass-1!');
  await page.getByRole('button', { name: 'Sign in' }).click();
}

test.describe('customer portal', () => {
  test('logs in with the customer session and can sign out', async ({ page }) => {
    await mockCustomerSession(page);
    await signIn(page);

    await expect(page).toHaveURL(/\/portal\/customer$/);
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    await expect(page.getByText('Recent Deliveries')).toBeVisible();
    await expect(page.getByText('Recent Quality Complaints')).toBeVisible();
    await expect(page.getByRole('link', { name: 'DLV-1001' })).toHaveAttribute(
      'href',
      '/portal/customer/deliveries/delivery_customer_01',
    );

    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/portal\/customer\/login$/);
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
    await expect(page.getByText('2026-09-15')).toBeVisible();
    await expect(page.getByText('(scheduled)')).toBeVisible();
    await expect(page.getByText('Page 1 of 2')).toBeVisible();

    await page.getByRole('button', { name: 'Next page' }).click();
    await expect(page.getByText('Page 2 of 2')).toBeVisible();
    await expect(page.getByText('DLV-1002')).toBeVisible();
  });

  test('redirects first-login customers to the forced password change screen', async ({ page }) => {
    await mockCustomerSession(page, true);
    await page.route('**/api/v1/auth/password-policy', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: { minimum_length: 12 } }),
      });
    });

    await signIn(page);

    await expect(page).toHaveURL(/\/portal\/customer\/change-password$/);
    await expect(page.getByText('Set your portal password')).toBeVisible();
  });

  test('keeps the customer navigation usable on a narrow viewport', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await mockCustomerSession(page);
    await page.goto('/portal/customer');

    await expect(page.getByRole('button', { name: 'Open portal navigation' })).toBeVisible();
    await page.getByRole('button', { name: 'Open portal navigation' }).click();

    const navigation = page.getByRole('navigation', { name: 'Customer Portal navigation' });
    await expect(navigation).toBeVisible();
    await expect(navigation.getByRole('link', { name: 'Orders' })).toBeVisible();
  });
});
