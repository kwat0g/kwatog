const { chromium, expect } = require('@playwright/test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

(async () => {
  const base = 'http://localhost:18080';
  const browser = await chromium.launch({ headless: true });
  const output = '../.impeccable/review/item-bom-forms';
  fs.mkdirSync(output, { recursive: true });
  const errors = [];
  async function session(email) {
    const context = await browser.newContext({ baseURL: base, viewport: { width: 1440, height: 1000 } });
    await context.request.get('/sanctum/csrf-cookie', { headers: { Origin: base, Referer: base + '/' } });
    async function api(method, route, data) {
      const cookie = (await context.cookies()).find(row => row.name === 'XSRF-TOKEN');
      return context.request.fetch('/api/v1' + route, { method, data, headers: { Origin: base, Referer: base + '/', 'X-XSRF-TOKEN': decodeURIComponent(cookie.value) } });
    }
    const response = await api('POST', '/auth/sign-in', { email, password: 'password' });
    assert(response.ok(), await response.text());
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => { if (response.url().includes('/api/') && response.status() >= 500) errors.push(`${response.status()} ${response.url()}`); });
    return { page, api };
  }
  async function success(actor, method, route, data) {
    const response = await actor.api(method, route, data);
    assert(response.ok(), await response.text());
    return (await response.json()).data;
  }
  async function fits(page) {
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No document-level horizontal overflow');
  }
  try {
    const admin = await session('admin@ogami.test');
    const warehouse = await session('warehouse@ogami.test');
    const planner = await session('ppc@ogami.test');
    const tag = String(Date.now()).slice(-8);
    const sales = await session('crm@ogami.test');
    const officer = await session('customerservice@ogami.test');
    const categories = await success(admin, 'GET', '/inventory/item-categories');
    const raw = categories.find(row => row.item_type === 'raw_material');
    const fg = categories.find(row => row.item_type === 'finished_good');
    const product = await success(admin, 'POST', '/crm/products', { part_number: 'AUTO-BOM-' + tag, name: 'Automatic costing verification ' + tag, unit_of_measure: 'PCS', standard_cost: '10.00', base_selling_price: '50.00', minimum_selling_price: '15.00', portal_visibility: 'standard', is_active: true });
    const itemData = { unit_of_measure: 'PCS', standard_cost: '10.00', reorder_method: 'fixed_quantity', reorder_point: '0', safety_stock: '0', lead_time_days: 0 };
    await success(admin, 'POST', '/inventory/items', { ...itemData, category_id: fg.id, code: product.part_number, name: product.name });
    const material = await success(warehouse, 'POST', '/inventory/items', { ...itemData, category_id: raw.id, code: 'AUTO-MAT-' + tag, name: 'Costing material ' + tag });
    const bom = await success(planner, 'POST', '/mrp/boms', { product_id: product.id, items: [{ item_id: material.id, quantity_per_unit: '2', unit: 'PCS' }] });
    const buyers = await success(officer, 'GET', '/crm/customers');
    const date = new Date().toISOString().slice(0, 10);
    const end = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10);
    // Seed existing contract terms; new proposals use the acceptance E2E test.
    const fixture = Buffer.from(JSON.stringify({ product_id: product.id, customer_id: buyers[0].id, price: '50.00', pricing_method: 'tiered', tiers: [{ min_qty: 100, unit_price: '25.00' }, { min_qty: 500, unit_price: '20.00' }], effective_from: date, effective_to: end })).toString('base64');
    const seed = `$data=json_decode(base64_decode('${fixture}'),true); $data['product_id']=App\\Modules\\CRM\\Models\\Product::decodeHash($data['product_id']); $data['customer_id']=App\\Modules\\Accounting\\Models\\Customer::decodeHash($data['customer_id']); app(App\\Modules\\CRM\\Services\\PriceAgreementService::class)->create($data);`;
    require('node:child_process').execFileSync('docker', ['exec', 'ogami-audit-20261002-api', 'php', 'artisan', 'tinker', '--execute=' + seed], { encoding: 'utf8' });
    const agreement = (await success(sales, 'GET', '/crm/price-agreements?search=' + product.part_number))[0];
    await success(admin, 'PUT', '/inventory/items/' + material.id, { standard_cost: '20.0000' });
    require('node:child_process').execFileSync('docker', ['exec', 'ogami-audit-20261002-api', 'php', 'artisan', 'pricing:review-agreements', '--dispatch-only'], { encoding: 'utf8' });
    const refreshed = await success(planner, 'GET', '/mrp/boms/' + bom.id);
    assert.equal(refreshed.total_cost, '40.00');
    const terms = await success(sales, 'GET', '/crm/price-agreements/' + agreement.id);
    assert.equal(terms.tiers[0].unit_price, '25.00');
    assert(terms.pricing_warnings.some(warning => warning.includes('below a configured unit cost')));
    await sales.page.goto('/crm/price-agreements?search=' + product.part_number);
    await expect(sales.page.getByText(/^100\+ PCS: ₱?25\.00$/)).toBeVisible();
    await expect(sales.page.getByText(/^500\+ PCS: ₱?20\.00$/)).toBeVisible();
    await expect(sales.page.getByText(/^Below 100: ₱?50\.00$/)).toBeVisible();
    await fits(sales.page);
    await sales.page.screenshot({ path: output + '/agreement-automation-desktop.png', fullPage: true });
    await sales.page.setViewportSize({ width: 390, height: 844 });
    await fits(sales.page);
    await sales.page.screenshot({ path: output + '/agreement-automation-mobile.png', fullPage: true });
    const notificationResponse = await planner.api('GET', '/notifications');
    assert(notificationResponse.ok(), await notificationResponse.text());
    assert((await notificationResponse.text()).includes(product.part_number), 'PPC receives cost notification');
    const salesNotifications = await sales.api('GET', '/notifications');
    assert((await salesNotifications.text()).includes(product.part_number), 'Sales receives price review notification');
    const denied = await sales.api('POST', '/mrp/boms/' + bom.id + '/recalculate-cost');
    assert.equal(denied.status(), 403);
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ roles: ['admin', 'warehouse', 'ppc', 'sales', 'customer_service'], automatic_refresh: true, unchanged_agreement: true, visible_quantity_tiers: true, ppc_notified: true, sales_notified: true, role_permissions: true, browser_errors: errors }));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
