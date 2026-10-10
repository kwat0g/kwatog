const { chromium } = require('../spa/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const base = 'http://localhost:18080';
const out = '/tmp/portal-negotiation-browser';

(async () => {
  const browser = await chromium.launch({ headless: true });
  const errors = [];
  const forbiddenRequests = [];
  const sessions = [];
  fs.mkdirSync(out, { recursive: true });
  async function session(email, realm) {
    const context = await browser.newContext({ baseURL: base, viewport: { width: 1440, height: 1000 } });
    const headers = { Origin: base, Referer: base + '/', Accept: 'application/json' };
    await context.request.get('/sanctum/csrf-cookie', { headers });
    const token = (await context.cookies()).find(cookie => cookie.name === 'XSRF-TOKEN');
    headers['X-XSRF-TOKEN'] = decodeURIComponent(token.value);
    const login = await context.request.post(realm ? `/api/v1/b2b/${realm}/login` : '/api/v1/auth/sign-in', { headers, data: { email, password: 'password' } });
    assert(login.ok(), email + ': ' + await login.text());
    const page = await context.newPage();
    page.setDefaultTimeout(30000);
    page.on('pageerror', error => errors.push(email + ': ' + error.message));
    if (realm) page.on('request', request => { if (new URL(request.url()).pathname.startsWith('/api/v1/notifications')) forbiddenRequests.push(request.url()); });
    async function api(method, path, data, extra = {}) {
      const csrf = (await context.cookies()).find(cookie => cookie.name === 'XSRF-TOKEN');
      return context.request.fetch('/api/v1' + path, { method, data, headers: { ...headers, 'X-XSRF-TOKEN': decodeURIComponent(csrf.value), ...extra } });
    }
    sessions.push(context);
    return { page, api };
  }
  try {
    const sales = await session('crm@ogami.test');
    const customer = await session('portal@cust.test', 'customer');
    const supplier = await session('portal@supp.test', 'supplier');
    const catalogResponse = await customer.api('GET', '/b2b/customer/catalog');
    assert(catalogResponse.ok(), await catalogResponse.text());
    const product = (await catalogResponse.json()).data.find(item => item.unit_price != null);
    assert(product, 'Audit fixture requires priced customer product');
    const requested = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);
    const placed = await customer.api('POST', '/b2b/customer/orders', { notes: 'Portal negotiation browser fixture', items: [{ product_id: product.id, quantity: '100', delivery_date: requested }] }, { 'Idempotency-Key': 'portal-negotiation-' + Date.now() });
    assert(placed.ok(), await placed.text());
    const order = (await placed.json()).data;
    assert.equal(order.status, 'awaiting_confirmation');
    for (let round = 1; round <= 4; round++) {
      const date = new Date(Date.now() + (7 + round) * 86400000).toISOString().slice(0, 10);
      await sales.page.goto('/crm/sales-orders/' + order.id);
      await sales.page.getByRole('button', { name: 'Propose delivery date', exact: true }).click();
      await sales.page.getByLabel(/Proposed delivery date/).fill(date);
      await sales.page.getByLabel(/Reason for changing delivery date/).fill('Capacity proposal round ' + round);
      await sales.page.getByRole('button', { name: 'Send date proposal', exact: true }).click();
      await sales.page.getByText('Waiting for customer acceptance. Confirmation is blocked.', { exact: true }).waitFor();
      assert.equal(await sales.page.getByRole('button', { name: 'Confirm order', exact: true }).count(), 0);
      const pending = (await (await customer.api('GET', '/b2b/customer/orders/' + order.id)).json()).data;
      assert.equal(pending.status, 'delivery_negotiation');
      assert.equal(pending.delivery_dates[0], requested);
      const notices = await customer.api('GET', '/b2b/customer/notifications');
      assert(notices.ok(), await notices.text());
      assert((await notices.json()).data.some(notice => notice.type === 'sales_order.delivery_proposed' && notice.data.entity_id === order.id));
      await customer.page.goto('/portal/customer/orders/' + order.id);
      const decision = round === 4 ? 'accept' : 'reject';
      await customer.page.getByRole('button', { name: decision === 'accept' ? 'Accept proposed date' : 'Decline proposed date', exact: true }).click();
      const dialog = customer.page.getByRole('dialog');
      await dialog.waitFor();
      if (round === 1) {
        await dialog.getByRole('button', { name: 'Go back', exact: true }).click();
        assert.equal((await (await customer.api('GET', '/b2b/customer/orders/' + order.id)).json()).data.latest_response.status, 'pending');
        await customer.page.getByRole('button', { name: 'Decline proposed date', exact: true }).click();
      }
      const responsePromise = customer.page.waitForResponse(response => response.url().endsWith('/respond') && response.request().method() === 'POST');
      await dialog.getByRole('button', { name: decision === 'accept' ? 'Confirm acceptance' : 'Confirm decline', exact: true }).click();
      const response = await responsePromise;
      assert(response.ok(), await response.text());
      await dialog.waitFor({ state: 'hidden' });
      const updated = (await (await customer.api('GET', '/b2b/customer/orders/' + order.id)).json()).data;
      assert.equal(updated.status, decision === 'accept' ? 'awaiting_confirmation' : 'delivery_negotiation');
      assert.equal(updated.delivery_dates[0], decision === 'accept' ? date : requested);
      assert.equal(updated.items[0].quantity, '100.00');
      assert.equal(updated.total_amount, order.total_amount);
      assert.equal(updated.delivery_negotiations.length, round);
    }
    await customer.page.reload();
    await customer.page.getByText('Delivery negotiation history (4)', { exact: true }).click();
    await customer.page.getByText('Capacity proposal round 1', { exact: true }).waitFor();
    await customer.page.setViewportSize({ width: 390, height: 844 });
    assert(await customer.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await customer.page.screenshot({ path: out + '/customer-history-mobile.png', fullPage: true });
    for (const [realm, account] of [['customer', customer], ['supplier', supplier]]) {
      await account.page.goto(`/portal/${realm}/notifications`);
      await account.page.getByRole('heading', { name: 'Notifications', exact: true }).waitFor();
      await account.page.getByRole('button', { name: /Notifications/ }).first().waitFor();
      const inbox = await account.api('GET', `/b2b/${realm}/notifications`);
      assert(inbox.ok(), await inbox.text());
      const inboxData = await inbox.json();
      if (realm === 'supplier') assert(!inboxData.data.some(notice => notice.data?.entity_id === order.id));
      if (realm === 'customer') {
        const notice = inboxData.data.find(notice => notice.data?.entity_id === order.id);
        assert(notice, 'Customer notice must exist');
        const foreignRead = await supplier.api('PATCH', '/b2b/supplier/notifications/' + notice.id + '/read');
        assert.equal(foreignRead.status(), 404, 'Supplier must not mark customer notice read');
        const ownRead = await customer.api('PATCH', '/b2b/customer/notifications/' + notice.id + '/read');
        assert(ownRead.ok(), await ownRead.text());
        const refreshed = await customer.api('GET', '/b2b/customer/notifications');
        assert.equal((await refreshed.json()).meta.unread_count, inboxData.meta.unread_count - 1);
      }
      await account.page.screenshot({ path: `${out}/${realm}-inbox.png`, fullPage: true });
    }
    assert.deepEqual(errors, []);
    assert.deepEqual(forbiddenRequests, []);
    console.log(JSON.stringify({ roles: ['sales_officer', 'customer_portal', 'supplier_portal'], order: order.so_number, rounds: 4, rejected: 3, accepted: 1, confirmationDialogs: true, accountIsolation: true, screenshots: out }));
  } finally {
    for (const context of sessions) await context.close();
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
