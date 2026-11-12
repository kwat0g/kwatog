import { expect, test } from './fixtures';

const CUSTOMER = {
  id: 'portal_customer_flow_01',
  name: 'Customer Portal User',
  email: 'customer-flow@example.test',
  customer_id: 'customer_flow_01',
  customer_name: 'Customer Flow Manufacturing',
  must_change_password: false,
};

async function mockCustomerSession(page: import('@playwright/test').Page): Promise<void> {
  await page.route('**/api/v1/auth/user', (route) => route.fulfill({ status: 401, json: { message: 'Unauthenticated.' } }));
  await page.route('**/api/v1/landing/contact', (route) => route.fulfill({ json: { legal_name: 'Ogami Philippines', address: null } }));
  await page.route('**/api/v1/auth/sign-in', (route) => route.fulfill({ json: {
    data: { realm: 'customer', must_change_password: false, user: null },
  } }));
  await page.route('**/api/v1/b2b/customer/me', (route) => route.fulfill({ json: { data: CUSTOMER } }));
  await page.route('**/api/v1/b2b/customer/business-policies', (route) => route.fulfill({ json: {
    data: { functional_currency_code: 'PHP', vat_status: 'VAT Registered', vat_rate: '0.12' },
  } }));
  await page.route('**/api/v1/b2b/customer/badges', (route) => route.fulfill({ json: { data: { unpaid: 0, overdue: 0 } } }));
  await page.route('**/api/v1/b2b/customer/logout', (route) => route.fulfill({ json: { message: 'Logged out.' } }));
  await page.route('**/api/v1/b2b/customer/dashboard', (route) => route.fulfill({ json: { data: {
    open_so_count: 1, pending_delivery_count: 0, open_invoice_count: 0, total_outstanding: '0.00',
    recent_orders: [], recent_invoices: [], recent_deliveries: [], recent_complaints: [],
  } } }));
}

async function signIn(page: import('@playwright/test').Page, testInfo: import('@playwright/test').TestInfo): Promise<void> {
  testInfo.annotations.push({ type: 'expected-http-response', description: '401 GET /api/v1/auth/user' });
  await page.goto('/sign-in');
  await page.getByLabel('Email').fill(CUSTOMER.email);
  await page.getByLabel('Password', { exact: true }).fill('CustomerPass-1!');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/portal\/customer$/);
}

test.describe('customer portal workflow additions', () => {
  test('starts, skips, and replays the first-run guide while keeping the portal navigation usable', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await mockCustomerSession(page);
    await signIn(page, testInfo);
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();

    const guide = page.getByRole('dialog');
    await expect(guide.getByRole('heading', { name: 'Welcome to your customer portal' })).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath('customer-walkthrough-mobile.png'), fullPage: true });
    await guide.getByRole('button', { name: 'Start walkthrough' }).click();
    await guide.getByRole('button', { name: 'Next' }).click();
    await expect(guide.getByRole('heading', { name: 'Orders' })).toBeVisible();
    await expect(guide.getByRole('link', { name: 'Place Order' })).toHaveAttribute('href', '/portal/customer/orders/new');

    await guide.getByRole('button', { name: 'Skip walkthrough' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.getByRole('button', { name: 'Help' }).click();
    await expect(page.getByRole('dialog').getByRole('heading', { name: 'Welcome to your customer portal' })).toBeVisible();
  });

  test('submits customer order feedback on mobile without collecting payment data', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.addInitScript((storageKey) => window.localStorage.setItem(storageKey, 'completed'),
      'ogami:portal-walkthrough:customer:portal_customer_flow_01:v1');
    await mockCustomerSession(page);

    const order = {
      id: 'so-feedback-01', so_number: 'SO-FEEDBACK-01', date: '2026-11-01', status: 'delivered',
      status_label: 'Delivered', total_amount: '112.00', subtotal: '100.00', vat_amount: '12.00',
      created_at: '2026-11-01T09:00:00Z', payment_terms_days: 30, delivery_terms: null,
      items: [], deliveries: [{ id: 'delivery-01', delivery_number: 'DLV-01', status: 'delivered', status_label: 'Delivered', scheduled_date: '2026-11-04' }],
      capabilities: { can_edit: false, can_cancel: false, can_respond: false, can_confirm: false },
      customer_feedback: { can_submit: true, submitted: false },
    };
    await page.route('**/api/v1/b2b/customer/orders/so-feedback-01/chain', (route) => route.fulfill({ json: { data: [] } }));
    await page.route('**/api/v1/b2b/customer/orders/so-feedback-01/agreements', (route) => route.fulfill({ json: {
      data: { sales_order_id: 'so-feedback-01', so_number: order.so_number, can_publish: false, can_record_external: false, can_acknowledge: false, revisions: [] },
    } }));
    await page.route('**/api/v1/b2b/customer/orders/so-feedback-01', (route) => route.fulfill({ json: { data: order } }));

    let receivedPayload: unknown;
    await page.route('**/api/v1/b2b/customer/orders/so-feedback-01/feedback', async (route) => {
      receivedPayload = route.request().postDataJSON();
      await route.fulfill({ status: 201, json: {
        data: { can_submit: false, submitted: true, submitted_at: '2026-11-10T08:00:00Z' },
        message: 'Thank you for sharing feedback.',
      } });
    });

    await signIn(page, testInfo);
    await page.goto('/portal/customer/orders/so-feedback-01');
    await expect(page.getByRole('heading', { name: 'Order feedback' })).toBeVisible();
    await page.getByLabel('Rating').selectOption('5');
    await page.getByLabel('Comment (optional)').fill('Delivery arrived on time.');
    const feedbackForm = page.locator('form').filter({ has: page.getByRole('button', { name: 'Send feedback' }) });
    await expect(feedbackForm).toBeVisible();
    const ratingHeight = await page.getByLabel('Rating').evaluate((element) => element.getBoundingClientRect().height);
    const sendHeight = await page.getByRole('button', { name: 'Send feedback' }).evaluate((element) => element.getBoundingClientRect().height);
    expect(ratingHeight).toBeGreaterThanOrEqual(44);
    expect(sendHeight).toBeGreaterThanOrEqual(44);
    await page.screenshot({ path: testInfo.outputPath('customer-order-feedback-mobile.png'), fullPage: true });
    await page.getByRole('button', { name: 'Send feedback' }).click();
    await expect(page.locator('#portal-main-content').getByText('Thank you for sharing feedback.', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Send feedback' })).toHaveCount(0);
    expect(receivedPayload).toEqual({ rating: 5, comment: 'Delivery arrived on time.' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await expect(feedbackForm).toHaveCount(0);
  });
});
