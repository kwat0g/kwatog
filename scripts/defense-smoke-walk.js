const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright-core');

const BASE = (process.env.BASE_URL || 'http://localhost').replace(/\/$/, '');
const OUT = process.env.SCREENSHOT_DIR
  ? path.resolve(process.env.SCREENSHOT_DIR)
  : path.resolve(__dirname, '..', 'docs', 'defense-screenshots');

// [route, filename, label, content that proves the intended screen rendered]
const PAGES = [
  ['/dashboard', 'dashboard', 'Dashboard', /dashboard/i],
  ['/quality/traceability?term=BATCH-20260709-0001', 'traceability', 'ADV3 Traceability', /IMM-01[\s\S]*M-WB-001|M-WB-001[\s\S]*IMM-01/i],
  ['/production/work-orders', 'work-orders', 'ADV3 Work Orders', /work orders/i],
  ['/supply-chain/deliveries', 'deliveries', 'ADV7 Deliveries', /deliveries/i],
  ['/payroll/periods', 'payroll-periods', 'ADV1 Payroll Periods', /payroll/i],
  ['/budgeting', 'budgeting', 'ADV9 Budgeting', /budget/i],
  ['/forecasting/demand', 'forecasting-demand', 'ADV11 Forecast Demand', /include forecast in MRP/i],
  ['/forecasting/stock-out', 'forecasting-stockout', 'ADV11 Stock-out', /stock.out/i],
  ['/inventory/warehouse-map', 'warehouse-map', 'ADV8 Warehouse Map', /warehouse map/i],
  ['/inventory/stock-count', 'stock-count', 'ADV8 Stock Count', /Defense Demo.*Zone Freeze/i],
  ['/inventory/transfer-orders', 'transfer-orders', 'ADV8 Transfer Orders', /transfer orders/i],
  ['/inventory/picking', 'picking', 'ADV8 Picking', /picking/i],
  ['/purchasing/purchase-requests', 'purchase-requests', 'ADV6/9 PR Rehearsal', /PR-DEMO-(CONVERT|BUDGET)/i],
  ['/return-management', 'returns', 'ADV12 Returns', /RMA-DEMO-SUP-READY/i],
  ['/accounting/credit-notes', 'credit-notes', 'ADV12 Credit Notes', /credit notes/i],
  ['/admin/roles', 'roles', 'ADV4 Roles', /roles/i],
  ['/purchasing/chain', 'purchasing-chain', 'ADV5 Procurement Chain', /procure.to.pay|procurement chain/i],
];

function monitor(page) {
  const consoleErrors = [];
  const httpErrors = [];
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text().slice(0, 240));
  });
  page.on('pageerror', (error) => consoleErrors.push(`PAGEERROR: ${String(error).slice(0, 240)}`));
  page.on('response', (response) => {
    if (response.status() >= 400 && response.url().includes('/api/')) {
      httpErrors.push(`${response.status()} ${response.request().method()} ${response.url()}`);
    }
  });
  return { consoleErrors, httpErrors };
}

function pageProblems(bodyText, expected, newConsoleErrors, newHttpErrors) {
  const problems = [];
  if (/something went wrong|error boundary|unexpected error/i.test(bodyText)) problems.push('render error');
  if (/module disabled/i.test(bodyText)) problems.push('module disabled');
  if (/page not found|doesn't exist or has been moved/i.test(bodyText)) problems.push('404 page');
  if (bodyText.trim().length < 60) problems.push('blank page');
  if (expected && !expected.test(bodyText)) problems.push('expected demo content missing');
  if (newConsoleErrors.length) problems.push(`${newConsoleErrors.length} console error(s)`);
  if (newHttpErrors.length) problems.push(`${newHttpErrors.length} failed API response(s)`);
  return problems;
}

async function portalCheck(browser, kind, email, expected) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  const errors = monitor(page);
  let status = 'OK';
  try {
    await page.goto(`${BASE}/portal/${kind}/login`, { waitUntil: 'networkidle', timeout: 25000 });
    await page.fill('input[type="email"]', email);
    await page.fill('input[type="password"]', 'password');
    await page.click('button[type="submit"]');
    await page.waitForURL(new RegExp(`/portal/${kind}/?$`), { timeout: 15000 });
    await page.waitForFunction(
      ({ source, flags }) => new RegExp(source, flags).test(document.body.innerText),
      { source: expected.source, flags: expected.flags },
      { timeout: 15000 },
    );
    const bodyText = (await page.textContent('body')) || '';
    const problems = pageProblems(bodyText, expected, errors.consoleErrors, errors.httpErrors);
    if (problems.length) status = `FAIL: ${problems.join(', ')}`;
    await page.screenshot({ path: path.join(OUT, `${kind}-portal.png`), fullPage: false });
  } catch (error) {
    status = `FAIL: ${String(error.message || error).slice(0, 160)}`;
  }
  await context.close();
  return [`ADV10 ${kind[0].toUpperCase()}${kind.slice(1)} Portal`, status];
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ channel: 'chrome', headless: true, args: ['--no-sandbox'] });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  const errors = monitor(page);
  const results = [];

  try {
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle', timeout: 25000 });
    await page.fill('input[type="email"]', 'admin@ogami.test');
    await page.fill('input[type="password"]', 'password');
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 15000 });
    results.push(['Internal login', `OK -> ${page.url()}`]);
  } catch (error) {
    results.push(['Internal login', `FAIL: ${String(error.message || error).slice(0, 160)}`]);
  }

  for (const [route, file, label, expected] of PAGES) {
    const consoleBefore = errors.consoleErrors.length;
    const httpBefore = errors.httpErrors.length;
    let status = 'OK';
    try {
      const response = await page.goto(BASE + route, { waitUntil: 'networkidle', timeout: 25000 });
      if (!response || response.status() >= 400) {
        status = `FAIL: document HTTP ${response?.status() ?? 'none'}`;
      } else {
        const bodyText = (await page.textContent('body')) || '';
        const problems = pageProblems(
          bodyText,
          expected,
          errors.consoleErrors.slice(consoleBefore),
          errors.httpErrors.slice(httpBefore),
        );
        if (problems.length) status = `FAIL: ${problems.join(', ')}`;
      }
      await page.screenshot({ path: path.join(OUT, `${file}.png`), fullPage: false });
    } catch (error) {
      status = `FAIL: ${String(error.message || error).slice(0, 160)}`;
    }
    results.push([`${label} (${route})`, status]);
  }

  await context.close();
  results.push(await portalCheck(browser, 'supplier', 'portal@supp.test', /Open POs/i));
  results.push(await portalCheck(browser, 'customer', 'portal@cust.test', /Open Orders/i));
  await browser.close();

  console.log('\n===== STRICT DEFENSE SMOKE RESULTS =====');
  for (const [label, status] of results) {
    console.log(`${status.startsWith('OK') ? 'PASS' : 'FAIL'}  ${label.padEnd(68)} ${status}`);
  }

  if (results.some(([, status]) => !status.startsWith('OK'))) process.exitCode = 1;
})().catch((error) => {
  console.error('FATAL', error);
  process.exit(1);
});
