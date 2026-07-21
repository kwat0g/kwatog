const { chromium } = require('playwright-core');

const BASE = 'http://localhost';
const OUT = '/home/kwat0g/Desktop/kwatog/docs/defense-screenshots';

// [route, filename, label] — the showcase screens for the panel.
const PAGES = [
  ['/dashboard', 'dashboard', 'Dashboard'],
  ['/quality/traceability', 'traceability', 'ADV3 Traceability'],
  ['/production/work-orders', 'work-orders', 'ADV3 Work Orders'],
  ['/supply-chain/deliveries', 'deliveries', 'ADV7 Deliveries'],
  ['/payroll/periods', 'payroll-periods', 'ADV1 Payroll Periods'],
  ['/budgeting', 'budgeting', 'ADV9 Budgeting'],
  ['/forecasting/demand', 'forecasting-demand', 'ADV11 Forecast Demand'],
  ['/forecasting/stock-out', 'forecasting-stockout', 'ADV11 Stock-out'],
  ['/inventory/warehouse-map', 'warehouse-map', 'ADV8 Warehouse Map'],
  ['/inventory/stock-count', 'stock-count', 'ADV8 Stock Count'],
  ['/inventory/transfer-orders', 'transfer-orders', 'ADV8 Transfer Orders'],
  ['/inventory/picking', 'picking', 'ADV8 Picking'],
  ['/return-management', 'returns', 'ADV12 Returns'],
  ['/accounting/credit-notes', 'credit-notes', 'ADV12 Credit Notes'],
  ['/admin/roles', 'roles', 'ADV4 Roles'],
  ['/purchasing/chain', 'purchasing-chain', 'ADV5 Procurement Chain'],
];

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true, args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  const consoleErrors = [];
  page.on('console', m => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 160)); });
  page.on('pageerror', e => consoleErrors.push('PAGEERROR: ' + String(e).slice(0, 160)));

  const results = [];

  // Login
  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.fill('input[type="email"]', 'admin@ogami.test');
  await page.fill('input[type="password"]', 'password');
  await page.click('button[type="submit"]');
  await page.waitForTimeout(2500);
  const afterLogin = page.url();
  results.push(['login', afterLogin.includes('/login') ? 'FAIL still on login' : 'OK -> ' + afterLogin]);

  for (const [route, file, label] of PAGES) {
    const before = consoleErrors.length;
    let status = 'OK';
    try {
      const resp = await page.goto(BASE + route, { waitUntil: 'networkidle', timeout: 20000 });
      await page.waitForTimeout(1200);
      // Heuristic render check: look for an error-boundary / 403 / blank.
      const bodyText = (await page.textContent('body')) || '';
      if (/something went wrong|error boundary|unexpected error/i.test(bodyText)) status = 'RENDER-ERROR';
      else if (/page not found|doesn't exist or has been moved/i.test(bodyText)) status = '404-NOT-FOUND';
      else if (/403|not authorized|permission/i.test(bodyText) && bodyText.length < 400) status = 'FORBIDDEN?';
      else if (bodyText.trim().length < 60) status = 'BLANK?';
      await page.screenshot({ path: `${OUT}/${file}.png`, fullPage: false });
    } catch (e) {
      status = 'NAV-FAIL: ' + String(e.message || e).slice(0, 80);
    }
    const newErrs = consoleErrors.slice(before);
    results.push([label + ' (' + route + ')', status + (newErrs.length ? ` | ${newErrs.length} console err` : '')]);
  }

  console.log('\n===== SMOKE-WALK RESULTS =====');
  for (const [k, v] of results) console.log((v.startsWith('OK') ? '✅' : '⚠️ ') + ' ' + k.padEnd(46) + ' ' + v);
  if (consoleErrors.length) {
    console.log('\n--- first console errors ---');
    [...new Set(consoleErrors)].slice(0, 12).forEach(e => console.log('  ' + e));
  }
  await browser.close();
})().catch(e => { console.error('FATAL', e); process.exit(1); });
