const fs = require('fs');
const path = require('path');
const BASE = require('./require-audit-base-url');
const { chromium } = require('playwright-core');
const OUT = process.env.SCREENSHOT_DIR
  ? path.resolve(process.env.SCREENSHOT_DIR)
  : path.resolve(__dirname, '..', 'docs', 'defense-screenshots');

// [route, filename, label, content that proves the intended screen rendered]
const PAGES = [
  ['/dashboard', 'dashboard', 'Dashboard', /dashboard/i],
  ['/dashboard/plant-manager', 'dashboard-plant-manager', 'Plant Manager Forecast Dashboard', /Demand Forecast[\s\S]*Forecast Accuracy|Forecast Accuracy[\s\S]*Demand Forecast/i],
  ['/dashboard/ppc', 'dashboard-ppc', 'PPC Forecast Dashboard', /Demand Forecast[\s\S]*Forecast Accuracy|Forecast Accuracy[\s\S]*Demand Forecast/i],
  // __BATCH__ is substituted with the live hero batch number after login
  // (batch numbers embed the WO start date, so they shift per seed day).
  ['/quality/traceability?term=__BATCH__', 'traceability', 'ADV3 Traceability', (batch) => batch ? new RegExp(`Batch\\s+${batch}`) : /Traceability search/i],
  ['/production/work-orders', 'work-orders', 'ADV3 Work Orders', /work orders/i],
  ['/supply-chain/deliveries', 'deliveries', 'ADV7 Deliveries', /deliveries/i],
  ['/payroll/periods', 'payroll-periods', 'ADV1 Payroll Periods', /payroll/i],
  ['/budgeting', 'budgeting', 'ADV9 Budgeting', /budget/i],
  ['/forecasting/demand', 'forecasting-demand', 'ADV11 Forecast Demand', /include forecast in MRP/i],
  ['/forecasting/stock-out', 'forecasting-stockout', 'ADV11 Stock-out', /stock.out/i],
  ['/inventory/warehouse-map', 'warehouse-map', 'ADV8 Warehouse Map', /warehouse map/i],
  ['/inventory/stock-count', 'stock-count', 'ADV8 Stock Count', /Stock counts[\s\S]*\d+ sessions/i],
  ['/inventory/transfer-orders', 'transfer-orders', 'ADV8 Transfer Orders', /transfer orders/i],
  ['/inventory/picking', 'picking', 'ADV8 Picking', /picking/i],
  ['/purchasing/purchase-requests', 'purchase-requests', 'ADV6/9 PR Rehearsal', /Purchase requests[\s\S]*\d+ requests/i],
  ['/return-management', 'returns', 'ADV12 Returns', /Return Management \(RMA\)[\s\S]*\d+ return requests/i],
  ['/accounting/credit-notes', 'credit-notes', 'ADV12 Credit Notes', /credit notes/i],
  ['/admin/roles', 'roles', 'ADV4 Roles', /roles/i],
  ['/purchasing/chain', 'purchasing-chain', 'ADV5 Procurement Chain', /procure.to.pay|procurement chain/i],
];

function monitor(page) {
  const consoleErrors = [];
  const httpErrors = [];
  let authenticated = false;
  let expectedGuestProbeConsoleErrors = 0;
  // The SPA probes its internal session on guest portal/login pages; that one
  // unauthenticated response is expected until the subsequent login succeeds.
  page.on('console', (message) => {
    if (message.type() !== 'error') return;
    const expectedGuestProbe = expectedGuestProbeConsoleErrors > 0
      && /status of 401 \(Unauthorized\)/i.test(message.text());
    if (expectedGuestProbe) {
      expectedGuestProbeConsoleErrors -= 1;
      return;
    }
    consoleErrors.push(message.text().slice(0, 240));
  });
  page.on('pageerror', (error) => consoleErrors.push(`PAGEERROR: ${String(error).slice(0, 240)}`));
  page.on('response', (response) => {
    if (response.status() >= 400 && response.url().includes('/api/')) {
      const isGuestUserProbe = !authenticated
        && response.status() === 401
        && response.request().method() === 'GET'
        && new URL(response.url()).pathname === '/api/v1/auth/user';
      if (isGuestUserProbe) {
        expectedGuestProbeConsoleErrors += 1;
        return;
      }
      httpErrors.push(`${response.status()} ${response.request().method()} ${response.url()}`);
    }
  });
  return { consoleErrors, httpErrors, markAuthenticated: () => { authenticated = true; } };
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
    errors.markAuthenticated();
    const bodyText = await page.locator('body').innerText();
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
  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  const errors = monitor(page);
  const results = [];

  try {
    await page.goto(`${BASE}/sign-in`, { waitUntil: 'networkidle', timeout: 25000 });
    await page.fill('input[type="email"]', 'admin@ogami.test');
    await page.fill('input[type="password"]', 'password');
    const signIn = page.waitForResponse(
      (response) => response.url().endsWith('/api/v1/auth/sign-in') && response.request().method() === 'POST',
      { timeout: 15000 },
    );
    await page.click('button[type="submit"]');
    const response = await signIn;
    if (!response.ok()) throw new Error(`sign-in returned ${response.status()}`);
    await page.waitForURL((url) => url.pathname === '/dashboard', { timeout: 15000 });
    errors.markAuthenticated();
    results.push(['Internal login', `OK -> ${page.url()}`]);
  } catch (error) {
    results.push(['Internal login', `FAIL: ${String(error.message || error).slice(0, 160)}`]);
  }

  // Resolve a real work-order batch. If this isolated stack has none, exercise
  // the traceability search's empty state instead of inventing a batch number.
  let heroBatch = null;
  try {
    const woResp = await page.evaluate(async () => {
      const res = await fetch('/api/v1/production/work-orders?per_page=10', {
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) return null;
      const json = await res.json();
      const rows = json?.data ?? [];
      const first = rows.find((row) => row && row.batch_number);
      return first ? String(first.batch_number) : null;
    });
    if (woResp) heroBatch = woResp;
  } catch {
    // keep the fallback term
  }

  for (const [route, file, label, expected] of PAGES) {
    const resolvedRoute = route.replace('__BATCH__', encodeURIComponent(heroBatch ?? ''));
    const pageExpected = typeof expected === 'function' ? expected(heroBatch) : expected;
    const consoleBefore = errors.consoleErrors.length;
    const httpBefore = errors.httpErrors.length;
    let status = 'OK';
    try {
      const response = await page.goto(BASE + resolvedRoute, { waitUntil: 'networkidle', timeout: 25000 });
      if (!response || response.status() >= 400) {
        status = `FAIL: document HTTP ${response?.status() ?? 'none'}`;
      } else {
        if (label === 'Plant Manager Forecast Dashboard') {
          await page.locator('summary').filter({ hasText: 'Analysis & forecasts' }).click();
          await page.getByText('Demand Forecast', { exact: true }).waitFor({ state: 'visible', timeout: 10000 });
          await page.getByText('Forecast Accuracy', { exact: true }).waitFor({ state: 'visible', timeout: 10000 });
        }
        const bodyText = await page.locator('body').innerText();
        const problems = pageProblems(
          bodyText,
          pageExpected,
          errors.consoleErrors.slice(consoleBefore),
          errors.httpErrors.slice(httpBefore),
        );
        if (problems.length) status = `FAIL: ${problems.join(', ')}`;
      }
      await page.screenshot({ path: path.join(OUT, `${file}.png`), fullPage: false });
    } catch (error) {
      status = `FAIL: ${String(error.message || error).slice(0, 160)}`;
    }
    results.push([`${label} (${resolvedRoute})`, status]);
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
