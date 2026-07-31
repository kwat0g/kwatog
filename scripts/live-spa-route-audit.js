const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright-core');

const ROOT = path.resolve(__dirname, '..');
const ROUTES_DIR = path.join(ROOT, 'spa', 'src', 'routes');
const BASE = (process.env.BASE_URL || 'http://localhost').replace(/\/$/, '');
const PASSWORD = process.env.AUDIT_PASSWORD || 'password';

function discoverStaticRoutes() {
  const routes = new Set();
  for (const file of fs.readdirSync(ROUTES_DIR).filter((name) => name.endsWith('.tsx'))) {
    const source = fs.readFileSync(path.join(ROUTES_DIR, file), 'utf8');
    for (const match of source.matchAll(/\bpath\s*=\s*"([^"]+)"/g)) {
      const route = match[1];
      if (route.startsWith('/') && !route.includes(':') && route !== '*') routes.add(route);
    }
  }
  return [...routes].sort();
}

function groupRoutes(routes) {
  const groups = {
    public: [],
    internal: [],
    employee: [],
    driver: [],
    maintenance: [],
    supplier: [],
    customer: [],
  };

  for (const route of routes) {
    if (route.startsWith('/portal/supplier')) groups.supplier.push(route);
    else if (route.startsWith('/portal/customer')) groups.customer.push(route);
    else if (route.startsWith('/self-service')) groups.employee.push(route);
    else if (route.startsWith('/driver')) groups.driver.push(route);
    else if (route.startsWith('/maintenance/mobile')) groups.maintenance.push(route);
    else if (route === '/' || route.startsWith('/careers') || ['/login', '/forgot-password', '/reset-password'].includes(route)) groups.public.push(route);
    else groups.internal.push(route);
  }
  return groups;
}

function monitor(page) {
  const consoleErrors = [];
  const httpErrors = [];

  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text().slice(0, 300));
  });
  page.on('pageerror', (error) => consoleErrors.push(`PAGEERROR: ${String(error).slice(0, 300)}`));
  page.on('response', (response) => {
    if (response.status() >= 400 && response.url().includes('/api/')) {
      httpErrors.push(`${response.status()} ${response.request().method()} ${response.url()}`);
    }
  });

  return { consoleErrors, httpErrors };
}

async function loginInternal(page, email) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 20_000 });
  await page.locator('input[type="email"]').fill(email);
  await page.locator('input[type="password"]').fill(PASSWORD);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 20_000 });
}

async function loginPortal(page, kind, email) {
  await page.goto(`${BASE}/portal/${kind}/login`, { waitUntil: 'domcontentloaded', timeout: 20_000 });
  await page.locator('input[type="email"]').fill(email);
  await page.locator('input[type="password"]').fill(PASSWORD);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(new RegExp(`/portal/${kind}/?$`), { timeout: 20_000 });
}

function pageProblems(body, finalUrl, consoleErrors, httpErrors, expectInternalSession) {
  const problems = [];
  const visibleError = [
    /something went wrong/i,
    /unexpected error/i,
    /can't load this dashboard/i,
    /failed to load/i,
    /page not found/i,
    /doesn't exist or has been moved/i,
    /access denied/i,
    /not authorized/i,
    /module disabled/i,
  ].find((pattern) => pattern.test(body));

  if (body.trim().length < 40) problems.push('blank or near-empty page');
  if (visibleError) problems.push(`visible error text: ${visibleError}`);
  if (expectInternalSession && new URL(finalUrl).pathname === '/login') problems.push('redirected to internal login');
  if (consoleErrors.length) problems.push(`${consoleErrors.length} browser error(s)`);
  if (httpErrors.length) problems.push(`${httpErrors.length} failed API response(s)`);
  return problems;
}

async function auditGroup(browser, name, routes, login, expectInternalSession = false) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  const events = monitor(page);
  const failures = [];

  if (login) await login(page);
  // Discard authentication/bootstrap diagnostics; each route is evaluated only
  // against events emitted after its own navigation begins.
  events.consoleErrors.length = 0;
  events.httpErrors.length = 0;

  for (let index = 0; index < routes.length; index++) {
    const route = routes[index];
    const consoleStart = events.consoleErrors.length;
    const httpStart = events.httpErrors.length;

    try {
      let response = null;
      if (index === 0) {
        // One hard navigation per session proves refresh/deep-link behavior.
        response = await page.goto(`${BASE}${route}`, { waitUntil: 'domcontentloaded', timeout: 20_000 });
      } else {
        // The remaining sweep follows the same client-side routing path as
        // sidebar links. This avoids re-running /auth/user hundreds of times
        // and tripping its intentional bootstrap rate limit.
        await page.evaluate((nextRoute) => {
          window.history.pushState({}, '', nextRoute);
          window.dispatchEvent(new PopStateEvent('popstate'));
        }, route);
      }
      await page.locator('body').waitFor({ state: 'attached', timeout: 5_000 });
      await page.waitForTimeout(900);

      const body = (await page.locator('body').innerText()).trim();
      const consoleErrors = events.consoleErrors.slice(consoleStart);
      const httpErrors = events.httpErrors.slice(httpStart);
      const problems = pageProblems(body, page.url(), consoleErrors, httpErrors, expectInternalSession);
      if (response && response.status() >= 400) problems.push(`document HTTP ${response.status()}`);

      if (problems.length) {
        failures.push({ route, finalUrl: page.url(), problems, consoleErrors, httpErrors });
      }
    } catch (error) {
      failures.push({
        route,
        finalUrl: page.url(),
        problems: [`navigation exception: ${String(error.message || error).slice(0, 300)}`],
        consoleErrors: events.consoleErrors.slice(consoleStart),
        httpErrors: events.httpErrors.slice(httpStart),
      });
    }

    if ((index + 1) % 20 === 0 || index === routes.length - 1) {
      console.log(`[${name}] ${index + 1}/${routes.length} routes checked; ${failures.length} failure(s)`);
    }
  }

  await context.close();
  return failures;
}

(async () => {
  const routes = discoverStaticRoutes();
  const groups = groupRoutes(routes);
  const requestedGroups = new Set((process.env.AUDIT_GROUPS || '').split(',').filter(Boolean));
  const browser = await chromium.launch({ channel: 'chrome', headless: true, args: ['--no-sandbox'] });
  const failures = [];
  let checkedRoutes = 0;

  const configurations = [
    ['public', groups.public.map((route) => route === '/reset-password' ? '/reset-password?token=audit-token&email=audit%40example.com' : route), null, false],
    ['internal', groups.internal, (page) => loginInternal(page, 'admin@ogami.test'), true],
    ['employee', groups.employee, (page) => loginInternal(page, 'employee@ogami.test'), true],
    ['driver', groups.driver, (page) => loginInternal(page, 'driver@ogami.test'), true],
    ['maintenance', groups.maintenance, (page) => loginInternal(page, 'maintenance@ogami.test'), true],
    ['supplier', groups.supplier.filter((route) => !route.endsWith('/login')), (page) => loginPortal(page, 'supplier', 'portal@supp.test')],
    ['customer', groups.customer.filter((route) => !route.endsWith('/login')), (page) => loginPortal(page, 'customer', 'portal@cust.test')],
  ];

  try {
    for (const [name, groupRoutes, login, expectInternalSession] of configurations) {
      if (requestedGroups.size && !requestedGroups.has(name)) continue;
      checkedRoutes += groupRoutes.length + (name === 'supplier' || name === 'customer' ? 1 : 0);
      failures.push(...await auditGroup(browser, name, groupRoutes, login, expectInternalSession));
    }
  } finally {
    await browser.close();
  }

  console.log(`\n=== ${checkedRoutes} static SPA routes checked; ${failures.length} failure(s) ===`);
  for (const failure of failures) {
    console.log(`\nFAIL ${failure.route} -> ${failure.finalUrl}`);
    for (const problem of failure.problems) console.log(`  - ${problem}`);
    for (const error of failure.httpErrors) console.log(`    HTTP ${error}`);
    for (const error of failure.consoleErrors) console.log(`    BROWSER ${error}`);
  }

  if (failures.length) process.exitCode = 1;
})().catch((error) => {
  console.error('FATAL', error);
  process.exit(1);
});
