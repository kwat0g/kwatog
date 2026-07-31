const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright-core');

const ROOT = path.resolve(__dirname, '..');
const ROUTES_DIR = path.join(ROOT, 'spa', 'src', 'routes');
const BASE = (process.env.BASE_URL || 'http://localhost').replace(/\/$/, '');
const PASSWORD = process.env.AUDIT_PASSWORD || 'password';

const FIXTURE_ENDPOINTS = {
  public: {
    '/careers/:id': '/job-postings?per_page=1',
  },
  internal: {
    '/admin/audit-logs/:id': '/admin/audit-logs?per_page=1',
    '/admin/roles/:id/permissions': '/admin/roles?per_page=1',
    '/crm/complaints/:id': '/crm/complaints?per_page=1',
    '/factory/:woId/output': '/production/work-orders?per_page=1',
    '/hr/performance-reviews/:id/submit': '/hr/performance-reviews?per_page=1',
    '/hr/recruitment/applications/:id': '/hr/recruitment/applications?per_page=1',
    '/hr/recruitment/postings/:id': '/hr/recruitment/postings?per_page=1',
    '/hr/recruitment/postings/:id/edit': '/hr/recruitment/postings?per_page=1',
    '/hr/separations/:id': '/hr/clearances?per_page=1',
    '/hr/succession-plans/:id/edit': '/hr/succession-plans?per_page=1',
    '/inventory/mrb/:id': '/inventory/mrb?per_page=1',
    '/maintenance/schedules/:id': '/maintenance/schedules?per_page=1',
    '/maintenance/schedules/:id/edit': '/maintenance/schedules?per_page=1',
    '/payroll/periods/:id': '/payroll-periods?per_page=1',
    '/payroll/periods/:id/employee/:eid': '/payrolls?per_page=1',
    '/production/routings/:id': '/production/routings?per_page=1',
    '/purchasing/pr-templates/:id/edit': '/purchasing/pr-templates?per_page=1',
    '/quality/documents/:id': '/quality/documents?per_page=1',
    '/quality/ncr-templates/:id/edit': '/quality/ncr-templates?per_page=1',
    '/quality/spc/:id': '/quality/spc/charts?per_page=1',
    '/supply-chain/shipments/:id': '/supply-chain/shipments?per_page=1',
  },
  driver: {
    '/driver/:id': '/driver/deliveries?include_finalized=1&per_page=1',
    '/driver/:id/photo': '/driver/deliveries?include_finalized=1&per_page=1',
  },
};

function discoverRoutes() {
  const routes = new Set();
  for (const file of fs.readdirSync(ROUTES_DIR).filter((name) => name.endsWith('.tsx'))) {
    const source = fs.readFileSync(path.join(ROUTES_DIR, file), 'utf8');
    for (const match of source.matchAll(/\bpath\s*=\s*"([^"]+)"/g)) {
      if (match[1].startsWith('/') && match[1] !== '*') routes.add(match[1]);
    }
  }
  return [...routes].sort();
}

function groupFor(route) {
  if (route.startsWith('/portal/supplier')) return 'supplier';
  if (route.startsWith('/portal/customer')) return 'customer';
  if (route.startsWith('/self-service')) return 'employee';
  if (route.startsWith('/driver')) return 'driver';
  if (route.startsWith('/maintenance/mobile')) return 'maintenance';
  if (route === '/' || route.startsWith('/careers') || ['/login', '/forgot-password', '/reset-password'].includes(route)) return 'public';
  return 'internal';
}

function compileTemplate(template) {
  const escaped = template
    .split('/')
    .map((part) => part.startsWith(':') ? '[^/]+' : part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
    .join('/');
  return new RegExp(`^${escaped}/?$`);
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
  await page.waitForURL((url) => url.pathname !== '/login', { timeout: 20_000 });
}

async function loginPortal(page, kind, email) {
  await page.goto(`${BASE}/portal/${kind}/login`, { waitUntil: 'domcontentloaded', timeout: 20_000 });
  await page.locator('input[type="email"]').fill(email);
  await page.locator('input[type="password"]').fill(PASSWORD);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(new RegExp(`/portal/${kind}/?$`), { timeout: 20_000 });
}

async function spaNavigate(page, route) {
  await page.evaluate((nextRoute) => {
    window.history.pushState({}, '', nextRoute);
    window.dispatchEvent(new PopStateEvent('popstate'));
  }, route);
  await page.locator('body').waitFor({ state: 'attached', timeout: 5_000 });
}

function problemsFor(body, finalUrl, consoleErrors, httpErrors, internal) {
  const problems = [];
  const visibleError = [
    /something went wrong/i,
    /unexpected error/i,
    /failed to load/i,
    /page not found/i,
    /doesn't exist or has been moved/i,
    /access denied/i,
    /not authorized/i,
    /module disabled/i,
  ].find((pattern) => pattern.test(body));
  if (body.trim().length < 40) problems.push('blank or near-empty page');
  if (visibleError) problems.push(`visible error text: ${visibleError}`);
  if (internal && new URL(finalUrl).pathname === '/login') problems.push('redirected to internal login');
  if (consoleErrors.length) problems.push(`${consoleErrors.length} browser error(s)`);
  if (httpErrors.length) problems.push(`${httpErrors.length} failed API response(s)`);
  return problems;
}

async function auditGroup(browser, name, staticRoutes, templates, login, internal = false) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  const events = monitor(page);
  const candidates = new Map();
  const failures = [];

  if (login) await login(page);
  for (let index = 0; index < staticRoutes.length; index++) {
    const route = staticRoutes[index];
    if (page.url() === 'about:blank') {
      await page.goto(`${BASE}${route}`, { waitUntil: 'domcontentloaded', timeout: 20_000 });
    } else {
      await spaNavigate(page, route);
    }
    await page.waitForTimeout(700);
    const hrefs = await page.locator('a[href]').evaluateAll((anchors) => anchors.map((anchor) => anchor.getAttribute('href')).filter(Boolean));
    for (const href of hrefs) {
      let pathname;
      try { pathname = new URL(href, BASE).pathname; } catch { continue; }
      if (staticRoutes.includes(pathname)) continue;
      const template = templates.find((item) => item.regex.test(pathname));
      if (template && !candidates.has(template.route)) candidates.set(template.route, pathname);
    }
    if ((index + 1) % 25 === 0 || index === staticRoutes.length - 1) {
      console.log(`[${name}] discovery ${index + 1}/${staticRoutes.length}; ${candidates.size}/${templates.length} dynamic template fixture(s) found`);
    }
  }

  // Detail pages commonly expose edit/stock-card/action routes with the same
  // record identifier only after the detail page is opened. Reuse a discovered
  // identifier for sibling templates so coverage does not depend on whether an
  // index renders its action as an anchor or as an imperative button.
  for (const target of templates) {
    if (candidates.has(target.route)) continue;
    const root = target.route.slice(0, target.route.indexOf(':'));
    const sibling = [...candidates.entries()].find(([sourceTemplate]) => sourceTemplate.slice(0, sourceTemplate.indexOf(':')) === root);
    if (!sibling) continue;
    const [, sourcePath] = sibling;
    const identifier = sourcePath.slice(root.length).split('/')[0];
    const derived = target.route.replace(/:[^/]+/, identifier);
    if (!derived.includes(':')) candidates.set(target.route, derived);
  }

  for (const target of templates) {
    if (candidates.has(target.route)) continue;
    const endpoint = FIXTURE_ENDPOINTS[name]?.[target.route];
    if (!endpoint) continue;
    const result = await page.evaluate(async (fixtureEndpoint) => {
      const response = await fetch(`/api/v1${fixtureEndpoint}`, { headers: { Accept: 'application/json' } });
      if (!response.ok) return null;
      const payload = await response.json();
      const data = payload?.data?.data ?? payload?.data ?? payload;
      return Array.isArray(data) ? (data[0] ?? null) : null;
    }, endpoint);
    if (!result?.id) continue;

    let derived = target.route.replace(/:[^/]+/, String(result.period_id ?? result.period?.id ?? result.id));
    if (derived.includes(':eid')) derived = derived.replace(':eid', String(result.id));
    if (!derived.includes(':')) candidates.set(target.route, derived);
  }

  events.consoleErrors.length = 0;
  events.httpErrors.length = 0;
  let checked = 0;
  for (const [template, route] of candidates) {
    const consoleStart = events.consoleErrors.length;
    const httpStart = events.httpErrors.length;
    try {
      await spaNavigate(page, route);
      await page.waitForTimeout(900);
      const body = (await page.locator('body').innerText()).trim();
      const consoleErrors = events.consoleErrors.slice(consoleStart);
      const httpErrors = events.httpErrors.slice(httpStart);
      const problems = problemsFor(body, page.url(), consoleErrors, httpErrors, internal);
      if (problems.length) failures.push({ route, template, finalUrl: page.url(), problems, consoleErrors, httpErrors });
    } catch (error) {
      failures.push({ route, template, finalUrl: page.url(), problems: [`navigation exception: ${String(error.message || error).slice(0, 300)}`], consoleErrors: [], httpErrors: [] });
    }
    checked += 1;
  }

  const covered = new Set(candidates.keys());
  const uncovered = templates.map((item) => item.route).filter((template) => !covered.has(template));
  console.log(`[${name}] ${checked} seeded dynamic URLs checked; ${failures.length} failure(s); ${uncovered.length} template(s) without a discovered fixture`);
  await context.close();
  return { failures, checked, covered, uncovered };
}

(async () => {
  const allRoutes = discoverRoutes();
  const configurations = {
    public: [null, false],
    internal: [(page) => loginInternal(page, 'admin@ogami.test'), true],
    employee: [(page) => loginInternal(page, 'employee@ogami.test'), true],
    driver: [(page) => loginInternal(page, 'driver@ogami.test'), true],
    maintenance: [(page) => loginInternal(page, 'maintenance@ogami.test'), true],
    supplier: [(page) => loginPortal(page, 'supplier', 'portal@supp.test'), false],
    customer: [(page) => loginPortal(page, 'customer', 'portal@cust.test'), false],
  };
  const requested = new Set((process.env.AUDIT_GROUPS || '').split(',').filter(Boolean));
  const browser = await chromium.launch({ channel: 'chrome', headless: true, args: ['--no-sandbox'] });
  const failures = [];
  const uncovered = [];
  let checked = 0;

  try {
    for (const [name, [login, internal]] of Object.entries(configurations)) {
      if (requested.size && !requested.has(name)) continue;
      const staticRoutes = allRoutes.filter((route) => !route.includes(':') && groupFor(route) === name && !route.endsWith('/login'));
      const templates = allRoutes.filter((route) => route.includes(':') && groupFor(route) === name).map((route) => ({ route, regex: compileTemplate(route) }));
      if (!templates.length) continue;
      const result = await auditGroup(browser, name, staticRoutes, templates, login, internal);
      failures.push(...result.failures);
      uncovered.push(...result.uncovered.map((route) => `[${name}] ${route}`));
      checked += result.checked;
    }
  } finally {
    await browser.close();
  }

  console.log(`\n=== ${checked} seeded dynamic SPA URLs checked; ${failures.length} failure(s) ===`);
  for (const failure of failures) {
    console.log(`\nFAIL ${failure.route} (${failure.template}) -> ${failure.finalUrl}`);
    for (const problem of failure.problems) console.log(`  - ${problem}`);
    for (const error of failure.httpErrors) console.log(`    HTTP ${error}`);
    for (const error of failure.consoleErrors) console.log(`    BROWSER ${error}`);
  }
  if (uncovered.length) {
    console.log(`\nTemplates without a seeded link fixture (${uncovered.length}):`);
    for (const route of uncovered) console.log(`  ${route}`);
  }
  if (failures.length) process.exitCode = 1;
})().catch((error) => {
  console.error('FATAL', error);
  process.exit(1);
});
