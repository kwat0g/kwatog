const { chromium } = require(process.cwd() + '/node_modules/@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const output = path.resolve('../docs/testing/evidence/remaining-modules-20261005');

// Auth session creation only; every business request made by this audit is GET.
(async () => {
  const base = 'http://localhost:18080';
  const browser = await chromium.launch({ headless: true });
  const results = [];
  try {
    for (const [role, email, routes, details, denials] of [
      ['maintenance', 'maintenance@ogami.test', ['/maintenance/work-orders', '/maintenance/work-orders/create', '/maintenance/schedules', '/maintenance/downtime'], [['/maintenance/work-orders', '/maintenance/work-orders']], ['/inventory/items', '/mrp/molds']],
      ['impex', 'impex@ogami.test', ['/supply-chain/shipments', '/supply-chain/shipments/create'], [['/supply-chain/shipments', '/supply-chain/shipments']], ['/supply-chain/vehicles']],
      ['warehouse', 'warehouse@ogami.test', ['/supply-chain/deliveries'], [['/supply-chain/deliveries', '/supply-chain/deliveries']], ['/accounting/journal-entries']],
      ['finance', 'finance@ogami.test', ['/accounting/journal-entries', '/accounting/journal-entries/create', '/accounting/invoices', '/accounting/bills', '/accounting/credit-notes', '/accounting/balance-sheet', '/accounting/periods', '/assets', '/assets/create', '/budgeting', '/budgeting/transfers'], [['/assets', '/assets'], ['/accounting/credit-notes', '/accounting/credit-notes']], ['/hr/employees']],
      ['hr', 'hr@ogami.test', ['/hr/employees', '/hr/attendance/shifts', '/hr/leaves', '/payroll/periods', '/hr/loans'], [], ['/supply-chain/shipments']],
      ['buyer', 'purchasing@ogami.test', ['/purchasing/purchase-requests', '/purchasing/rfqs', '/purchasing/purchase-orders', '/purchasing/approved-suppliers'], [], ['/assets']],
      ['admin', 'admin@ogami.test', ['/supply-chain/fleet', '/supply-chain/deliveries/create', '/maintenance/schedules/create', '/admin/sessions', '/admin/backups', '/admin/scheduled-exports', '/forecasting/demand', '/return-management'], [], []],
    ]) {
      const context = await browser.newContext({ baseURL: base });
      const headers = { Origin: base, Referer: base + '/', Accept: 'application/json' };
      await context.request.get('/sanctum/csrf-cookie', { headers });
      const xsrf = (await context.cookies()).find(c => c.name === 'XSRF-TOKEN');
      const login = await context.request.post('/api/v1/auth/sign-in', { headers: { ...headers, 'X-XSRF-TOKEN': decodeURIComponent(xsrf?.value || '') }, data: { email, password: 'password' } });
      if (!login.ok()) { results.push({ role, loginStatus: login.status() }); await context.close(); continue; }
      const page = await context.newPage();
      let apiErrors = [], exceptions = [];
      page.on('response', response => { if (response.url().includes('/api/') && response.status() >= 400) apiErrors.push({ path: new URL(response.url()).pathname, status: response.status() }); });
      page.on('pageerror', error => exceptions.push(error.message));
      const detailRoutes = [];
      for (const [endpoint, prefix] of details) {
        const response = await context.request.get('/api/v1' + endpoint, { headers });
        const body = await response.json().catch(() => ({}));
        const row = body.data?.[0];
        if (row?.id) detailRoutes.push(prefix + '/' + row.id);
      }
      for (const route of [...routes, ...detailRoutes]) {
        for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
          apiErrors = []; exceptions = [];
          await page.setViewportSize(viewport);
          const response = await page.goto(route, { waitUntil: 'networkidle', timeout: 25000 }).catch(error => ({ status: () => 0, error: error.message }));
          await page.waitForTimeout(200);
          const measurements = await page.evaluate(() => {
            const main = document.querySelector('main') || document.body;
            const visible = element => Boolean(element.getClientRects().length) && getComputedStyle(element).visibility !== 'hidden';
            return {
              documentWidth: document.documentElement.scrollWidth, viewportWidth: innerWidth,
              mainWidth: main.getBoundingClientRect().width,
              headings: [...main.querySelectorAll('h1,h2')].map(element => element.textContent?.trim()),
              unnamedInputs: [...main.querySelectorAll('input:not([type=hidden]),select,textarea')].filter(visible).filter(element => !element.labels?.length && !element.getAttribute('aria-label') && !element.getAttribute('aria-labelledby') && !element.getAttribute('title')).map(element => ({ tag: element.tagName, type: element.type, placeholder: element.placeholder || null })),
              text: main.innerText.slice(0, 1000),
            };
          });
          const entry = { role, route, viewport: viewport.width, finalUrl: page.url(), status: response?.status(), apiErrors: [...apiErrors], exceptions: [...exceptions], ...measurements };
          results.push(entry);
          if (viewport.width === 390 || apiErrors.length || exceptions.length) {
            const name = role + '-' + route.replaceAll('/', '_') + '-' + viewport.width + '.png';
            await page.screenshot({ path: path.join(output, name), fullPage: false });
          }
        }
      }
      for (const endpoint of denials) {
        const response = await context.request.get('/api/v1' + endpoint, { headers });
        results.push({ role, deniedEndpoint: endpoint, status: response.status() });
      }
      await context.close();
      fs.writeFileSync(path.join(output, 'browser-results.json'), JSON.stringify(results, null, 2));
      console.log(role + ': ' + routes.length + ' list/form pages; ' + detailRoutes.length + ' detail pages');
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
