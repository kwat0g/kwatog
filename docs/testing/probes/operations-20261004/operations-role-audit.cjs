const { chromium } = require(process.cwd() + '/node_modules/@playwright/test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
  const base = 'http://localhost:18080';
  const browser = await chromium.launch({ headless: true });
  const results = [];
  try {
    for (const [role, email, route, checks] of [
      ['maintenance_tech', 'maintenance@ogami.test', '/maintenance/work-orders/create', ['/maintenance/spare-part-options?search=sp', '/maintenance/spare-part-stock?item_id=invalid', '/maintenance/target-options?type=mold', '/inventory/items', '/mrp/molds']],
      ['warehouse_staff', 'warehouse@ogami.test', '/inventory/grn/create', ['/inventory/grn/receivable-purchase-orders', '/supply-chain/deliveries']],
      ['impex_officer', 'impex@ogami.test', '/supply-chain/shipments', ['/supply-chain/shipments', '/supply-chain/vehicles']],
    ]) {
      const context = await browser.newContext({ baseURL: base });
      const headers = { Origin: base, Referer: base + '/', Accept: 'application/json' };
      await context.request.get('/sanctum/csrf-cookie', { headers });
      const xsrf = (await context.cookies()).find(c => c.name === 'XSRF-TOKEN');
      const login = await context.request.post('/api/v1/auth/sign-in', { headers: { ...headers, 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value) }, data: { email, password: 'password' } });
      assert(login.ok(), role + ' login: ' + login.status());
      const page = await context.newPage();
      const failures = [];
      page.on('response', r => { if (r.url().includes('/api/') && r.status() >= 400) failures.push({ path: new URL(r.url()).pathname, status: r.status() }); });
      await page.goto(route);
      await page.locator('main').waitFor({ state: 'visible', timeout: 20000 });
      if (role === 'maintenance_tech') {
        const typeSelect = page.locator('select').first();
        await Promise.all([page.waitForResponse(r => new URL(r.url()).pathname === '/api/v1/maintenance/target-options', { timeout: 15000 }), typeSelect.selectOption('mold')]);
      }
      const responses = [];
      for (const path of checks) {
        const r = await context.request.get('/api/v1' + path, { headers });
        responses.push({ path, status: r.status() });
      }
      results.push({ role, route, finalUrl: page.url(), failures, responses });
      await context.close();
    }
    fs.writeFileSync('/tmp/operations-role-audit.json', JSON.stringify(results, null, 2));
    console.log(JSON.stringify(results, null, 2));
  } finally { await browser.close(); }
})().catch(e => { console.error(e.message); process.exit(1); });
