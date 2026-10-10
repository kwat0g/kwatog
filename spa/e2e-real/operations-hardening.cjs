const { chromium } = require('@playwright/test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
  const base = 'http://localhost:18080',
    fixture = JSON.parse(fs.readFileSync('/tmp/operations-browser-fixtures.json', 'utf8').trim());
  const output = '.impeccable/review/operations';
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true }),
    errors = [];
  async function session(email) {
    const context = await browser.newContext({
      baseURL: base,
      viewport: { width: 1440, height: 1000 },
    });
    const headers = { Origin: base, Referer: base + '/', Accept: 'application/json' };
    await context.request.get('/sanctum/csrf-cookie', { headers });
    async function api(method, path, data) {
      const cookie = (await context.cookies()).find((c) => c.name === 'XSRF-TOKEN');
      return context.request.fetch('/api/v1' + path, {
        method,
        data,
        headers: { ...headers, 'X-XSRF-TOKEN': decodeURIComponent(cookie.value) },
      });
    }
    const login = await api('POST', '/auth/sign-in', { email, password: 'password' });
    assert(login.ok(), await login.text());
    const page = await context.newPage();
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('response', (r) => {
      if (r.url().includes('/api/') && r.status() >= 400) errors.push(r.status() + ' ' + r.url());
    });
    return { page, api };
  }
  async function success(actor, method, path, data) {
    const r = await actor.api(method, path, data);
    assert(r.ok(), await r.text());
    return (await r.json()).data;
  }
  try {
    const tech = await session('maintenance@ogami.test'),
      warehouse = await session('warehouse@ogami.test'),
      impex = await session('impex@ogami.test');
    await tech.page.goto('/maintenance/work-orders/create');
    await tech.page.getByLabel('Description').waitFor();
    await Promise.all([
      tech.page.waitForResponse(
        (r) => new URL(r.url()).pathname === '/api/v1/maintenance/target-options',
      ),
      tech.page.locator('select').first().selectOption('mold'),
    ]);
    await tech.page.screenshot({
      path: output + '/maintenance-create-desktop.png',
      fullPage: true,
    });
    assert.equal((await tech.api('GET', '/inventory/items')).status(), 403);
    assert.equal((await tech.api('GET', '/mrp/molds')).status(), 403);
    const targets = await success(
      tech,
      'GET',
      '/maintenance/target-options?type=mold&search=' + fixture.mold_code,
    );
    assert(targets.some((r) => r.id === fixture.mold));
    const order = await success(tech, 'POST', '/maintenance/work-orders', {
      maintainable_type: 'mold',
      maintainable_id: fixture.mold,
      type: 'corrective',
      priority: 'medium',
      description: 'Isolated browser maintenance safety check',
    });
    await success(tech, 'PATCH', '/maintenance/work-orders/' + order.id + '/start');
    await tech.page.setViewportSize({ width: 390, height: 844 });
    await tech.page.goto('/maintenance/work-orders/' + order.id);
    await tech.page.getByRole('button', { name: 'Add', exact: true }).click();
    await tech.page.getByLabel('Search spare parts').fill(fixture.item_code);
    await tech.page.getByRole('button', { name: /Operations test spare grease/ }).click();
    await tech.page.getByLabel('Source location').selectOption(fixture.location);
    await tech.page.getByLabel('Quantity (KG)').fill('3');
    assert.equal(
      await tech.page.getByRole('button', { name: 'Add part', exact: true }).isEnabled(),
      false,
    );
    await tech.page.getByLabel('Quantity (KG)').fill('0.001');
    await tech.page.screenshot({
      path: output + '/maintenance-spare-part-mobile.png',
      fullPage: true,
    });
    assert(await tech.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await Promise.all([
      tech.page.waitForResponse(
        (r) => r.url().includes('/spare-parts') && r.request().method() === 'POST' && r.ok(),
      ),
      tech.page.getByRole('button', { name: 'Add part', exact: true }).click(),
    ]);
    const recorded = await success(tech, 'GET', '/maintenance/work-orders/' + order.id);
    assert.equal(recorded.spare_parts[0].quantity, '0.001');
    assert.equal(recorded.spare_parts[0].unit_cost, '1.2345');
    const stock = await success(
      tech,
      'GET',
      '/maintenance/spare-part-stock?item_id=' + fixture.item,
    );
    assert.equal(stock[0].available, '1.999');
    await success(tech, 'PATCH', '/maintenance/work-orders/' + order.id + '/complete', {
      remarks: 'Browser safety verification complete',
    });
    const po = await success(
      warehouse,
      'GET',
      '/inventory/grn/receivable-purchase-orders/' + fixture.po,
    );
    assert.equal(po.items[0].base_quantity_remaining, '10.000');
    await warehouse.page.goto('/inventory/grn/create');
    await warehouse.page.getByRole('combobox', { name: 'Purchase order', exact: true }).waitFor();
    const picker = warehouse.page.getByRole('combobox', { name: 'Purchase order', exact: true });
    await picker.fill(fixture.po_number);
    await warehouse.page.getByRole('option', { name: new RegExp(fixture.po_number) }).click();
    await warehouse.page.getByLabel('Receive quantity', { exact: true }).waitFor();
    assert.equal(
      await warehouse.page.getByLabel('Receive quantity', { exact: true }).inputValue(),
      '10.000',
    );
    const receivedUnit = warehouse.page.getByLabel('Received UOM (base)', { exact: true });
    assert.equal(await receivedUnit.inputValue(), 'KG');
    assert.equal(await receivedUnit.getAttribute('readonly'), '');
    await warehouse.page.screenshot({ path: output + '/receiving-desktop.png', fullPage: true });
    await impex.page.goto('/supply-chain/shipments');
    await impex.page.locator('main').waitFor();
    assert.equal((await impex.api('GET', '/supply-chain/vehicles')).status(), 200);
    assert.deepEqual(errors, []);
    const result = {
      roles: ['maintenance_tech', 'warehouse_staff', 'impex_officer'],
      narrow_lookup_access: true,
      general_inventory_access: false,
      fractional_spare_quantity: recorded.spare_parts[0].quantity,
      remaining_stock: stock[0].available,
      purchase_bags: 2,
      receiving_base_kg: 10,
      browser_errors: errors,
      maintenance_order: order.id,
    };
    fs.writeFileSync(output + '/role-results.json', JSON.stringify(result, null, 2));
    console.log(JSON.stringify(result));
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
