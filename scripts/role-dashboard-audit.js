const BASE = require('./require-audit-base-url');
const { chromium, firefox } = require('playwright');

const PASSWORD = process.env.AUDIT_PASSWORD || 'password';
const ROLES = [
  ['system_admin', 'admin@ogami.test', '/dashboard/admin'],
  ['vice_president', 'vp@ogami.test', '/dashboard/company'],
  ['hr_officer', 'hr@ogami.test', '/dashboard/hr'],
  ['finance_officer', 'finance@ogami.test', '/dashboard/finance'],
  ['customer_service_officer', 'customerservice@ogami.test', '/dashboard/customer-service'],
  ['sales_officer', 'crm@ogami.test', '/dashboard/sales'],
  ['production_manager', 'production@ogami.test', '/dashboard/plant-manager'],
  ['ppc_head', 'ppc@ogami.test', '/dashboard/ppc'],
  ['purchasing_officer', 'purchasing@ogami.test', '/dashboard/purchasing'],
  ['warehouse_staff', 'warehouse@ogami.test', '/dashboard/warehouse'],
  ['qc_inspector', 'qc@ogami.test', '/dashboard/quality'],
  ['maintenance_tech', 'maintenance@ogami.test', '/dashboard/maintenance'],
  ['maintenance_supervisor', 'maintenancehead@ogami.test', '/dashboard/maintenance'],
  ['impex_officer', 'impex@ogami.test', '/dashboard/logistics'],
  ['department_head', 'depthead@ogami.test', '/dashboard/department'],
  ['employee', 'employee@ogami.test', '/dashboard/default'],
  ['driver', 'driver@ogami.test', '/dashboard/default'],
];
const requestedRoles = new Set((process.env.AUDIT_ROLES || '').split(',').filter(Boolean));
const ACCOUNTS = requestedRoles.size ? ROLES.filter(([role]) => requestedRoles.has(role)) : ROLES;

const VISIBLE_ERRORS = [
  /something went wrong/i,
  /unexpected error/i,
  /can't access property/i,
  /dispatcher is null/i,
  /failed to load/i,
  /page not found/i,
  /access denied/i,
  /not authorized/i,
  /you do not have permission to perform this action/i,
];

async function login(page, email, onAuthenticated) {
  await page.goto(`${BASE}/sign-in`, { waitUntil: 'domcontentloaded', timeout: 20_000 });
  await page.locator('input[type="email"]').fill(email);
  await page.locator('input[type="password"]').fill(PASSWORD);
  const signIn = page.waitForResponse(
    (response) => response.url().endsWith('/api/v1/auth/sign-in') && response.request().method() === 'POST',
    { timeout: 20_000 },
  );
  await page.locator('button[type="submit"]').click();
  const response = await signIn;
  if (!response.ok()) throw new Error(`sign-in returned ${response.status()}`);
  onAuthenticated();
  await page.waitForURL((url) => !['/login', '/sign-in'].includes(url.pathname), { timeout: 20_000 });
}

async function auditRole(browser, browserName, expectedRole, email, expectedPath) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  const browserErrors = [];
  const apiErrors = [];
  let authenticated = false;

  page.on('pageerror', (error) => browserErrors.push(String(error)));
  page.on('console', (message) => {
    if (message.type() === 'error') browserErrors.push(message.text());
  });
  page.on('response', (response) => {
    if (
      response.url().endsWith('/api/v1/auth/sign-in') &&
      response.request().method() === 'POST' &&
      response.ok()
    ) {
      authenticated = true;
      return;
    }
    if (authenticated && response.status() >= 400 && response.url().includes('/api/')) {
      apiErrors.push(`${response.status()} ${response.request().method()} ${response.url()}`);
    }
  });

  const problems = [];
  try {
    await login(page, email, () => {
      browserErrors.length = 0;
    });
    const identity = await page.evaluate(async () => {
      const response = await fetch('/api/v1/auth/user', { credentials: 'include' });
      if (!response.ok) return null;
      const body = await response.json();
      return body.data ?? body;
    });
    if (identity?.role?.slug !== expectedRole) {
      problems.push(`signed in as ${identity?.role?.slug ?? 'unknown'}, expected ${expectedRole}`);
    }
    await page.goto(`${BASE}${expectedPath}`, { waitUntil: 'domcontentloaded', timeout: 20_000 });
    await page.locator('body').waitFor({ state: 'visible', timeout: 5_000 });
    await page.waitForTimeout(1500);

    const finalPath = new URL(page.url()).pathname;
    const body = (await page.locator('body').innerText()).trim();
    if (finalPath !== expectedPath) problems.push(`redirected to ${finalPath}`);
    if (body.length < 40) problems.push('blank or near-empty dashboard');
    const visibleError = VISIBLE_ERRORS.find((pattern) => pattern.test(body));
    if (visibleError) problems.push(`visible error matching ${visibleError}`);
    if (browserErrors.length) problems.push(`${browserErrors.length} browser error(s)`);
    if (apiErrors.length) problems.push(`${apiErrors.length} failed API response(s)`);
  } catch (error) {
    problems.push(`audit exception: ${String(error.message || error)}`);
  } finally {
    await context.close();
  }

  const label = `${browserName} ${email} ${expectedPath}`;
  if (!problems.length) {
    console.log(`PASS ${label}`);
    return null;
  }
  return { label, problems, browserErrors, apiErrors };
}

(async () => {
  const failures = [];
  let checked = 0;

  for (const [browserName, browserType] of [['chromium', chromium], ['firefox', firefox]]) {
    const browser = await browserType.launch({ headless: true });
    try {
      for (const [role, email, path] of ACCOUNTS) {
        checked += 1;
        const failure = await auditRole(browser, browserName, role, email, path);
        if (failure) failures.push(failure);
      }
    } finally {
      await browser.close();
    }
  }

  console.log(`\n=== ${checked} role-dashboard checks across ${ACCOUNTS.length} roles; ${failures.length} failure(s) ===`);
  for (const failure of failures) {
    console.log(`\nFAIL ${failure.label}`);
    for (const problem of failure.problems) console.log(`  - ${problem}`);
    for (const error of failure.apiErrors) console.log(`    API ${error}`);
    for (const error of failure.browserErrors) console.log(`    BROWSER ${error.slice(0, 500)}`);
  }

  if (failures.length) process.exitCode = 1;
})().catch((error) => {
  console.error('FATAL', error);
  process.exit(1);
});
