const { chromium } = require('playwright');

const BASE = (process.env.BASE_URL || 'http://localhost').replace(/\/$/, '');
const PASSWORD = process.env.AUDIT_PASSWORD || 'password';

const ALL_ACCOUNTS = [
  ['system_admin', 'admin@ogami.test'],
  ['hr_officer', 'hr@ogami.test'],
  ['finance_officer', 'finance@ogami.test'],
  ['production_manager', 'production@ogami.test'],
  ['ppc_head', 'ppc@ogami.test'],
  ['purchasing_officer', 'purchasing@ogami.test'],
  ['warehouse_staff', 'warehouse@ogami.test'],
  ['qc_inspector', 'qc@ogami.test'],
  ['maintenance_tech', 'maintenance@ogami.test'],
  ['impex_officer', 'impex@ogami.test'],
  ['department_head', 'depthead@ogami.test'],
  ['employee', 'employee@ogami.test'],
  ['driver', 'driver@ogami.test'],
];
const requestedRoles = new Set((process.env.AUDIT_ROLES || '').split(',').filter(Boolean));
const ACCOUNTS = requestedRoles.size
  ? ALL_ACCOUNTS.filter(([role]) => requestedRoles.has(role))
  : ALL_ACCOUNTS;

const SURFACES = [
  { path: '/hr/attendance', roles: ['system_admin', 'hr_officer', 'department_head'] },
  { path: '/hr/leaves', roles: ['system_admin', 'hr_officer', 'department_head'] },
  { path: '/hr/loans', roles: ['system_admin', 'hr_officer', 'finance_officer', 'department_head'] },
  { path: '/payroll/periods', roles: ['system_admin', 'hr_officer', 'finance_officer'] },
  { path: '/payroll/statutory', roles: ['system_admin', 'hr_officer', 'finance_officer'] },
  { path: '/quality/documents', roles: ['system_admin', 'qc_inspector'] },
];

const SIDEBAR_SURFACES = SURFACES.filter((surface) => surface.path !== '/hr/loans');

async function login(page, email) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 20_000 });
  await page.locator('input[type="email"]').fill(email);
  await page.locator('input[type="password"]').fill(PASSWORD);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL((url) => url.pathname !== '/login', { timeout: 20_000 });
}

async function navigateSpa(page, route) {
  await page.evaluate((nextRoute) => {
    window.history.pushState({}, '', nextRoute);
    window.dispatchEvent(new PopStateEvent('popstate'));
  }, route);
  await page.locator('body').waitFor({ state: 'visible', timeout: 5_000 });
  await page.waitForTimeout(650);
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const failures = [];
  let checks = 0;

  try {
    for (const [role, email] of ACCOUNTS) {
      const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
      const page = await context.newPage();
      const browserErrors = [];
      const apiErrors = [];
      page.on('pageerror', (error) => browserErrors.push(String(error)));
      page.on('console', (message) => {
        if (message.type() === 'error') browserErrors.push(message.text());
      });
      page.on('response', (response) => {
        if (response.status() >= 400 && response.url().includes('/api/')) {
          apiErrors.push(`${response.status()} ${response.request().method()} ${response.url()}`);
        }
      });

      try {
        const failureStart = failures.length;
        await login(page, email);
        await page.locator('aside nav a[href]').first().waitFor({ state: 'visible', timeout: 10_000 });
        browserErrors.length = 0;
        apiErrors.length = 0;
        let roleChecks = 0;

        const visibleSidebarRoutes = [...new Set(await page.locator('aside nav a[href]').evaluateAll((links) =>
          links.map((link) => link.getAttribute('href')).filter((href) => href && href.startsWith('/')),
        ))];

        for (const surface of SIDEBAR_SURFACES) {
          checks += 1;
          roleChecks += 1;
          const expected = surface.roles.includes(role);
          const visible = await page.locator(`aside a[href="${surface.path}"]`).count() > 0;
          if (visible !== expected) {
            failures.push(`${role}: sidebar ${surface.path} expected ${expected ? 'visible' : 'hidden'}, got ${visible ? 'visible' : 'hidden'}`);
          }
        }

        // Every link the sidebar exposes must open without a frontend guard,
        // failed API authorization, or error boundary for that exact role.
        for (const route of visibleSidebarRoutes) {
          checks += 1;
          roleChecks += 1;
          const errorStart = browserErrors.length;
          const apiStart = apiErrors.length;
          await navigateSpa(page, route);
          const body = await page.locator('body').innerText();
          if (/\bForbidden\b/i.test(body)) failures.push(`${role}: visible sidebar route ${route} rendered Forbidden`);
          if (/Something went wrong|unexpected error/i.test(body)) failures.push(`${role}: visible sidebar route ${route} hit an error boundary`);
          if (browserErrors.length > errorStart) {
            failures.push(`${role}: visible sidebar route ${route} emitted a browser error: ${browserErrors[errorStart].slice(0, 500)}`);
          }
          if (apiErrors.length > apiStart) {
            failures.push(`${role}: visible sidebar route ${route} emitted ${apiErrors[apiStart]}`);
          }
        }

        for (const surface of SURFACES) {
          checks += 1;
          roleChecks += 1;
          const expected = surface.roles.includes(role);
          const errorStart = browserErrors.length;
          const apiStart = apiErrors.length;
          await navigateSpa(page, surface.path);
          const body = await page.locator('body').innerText();
          const forbidden = /\bForbidden\b/i.test(body);
          const crashed = /Something went wrong|unexpected error/i.test(body);

          if (expected && forbidden) failures.push(`${role}: ${surface.path} unexpectedly rendered Forbidden`);
          if (!expected && !forbidden) failures.push(`${role}: ${surface.path} was accessible but should be Forbidden`);
          if (crashed) failures.push(`${role}: ${surface.path} hit an error boundary`);
          if (browserErrors.length > errorStart) {
            failures.push(`${role}: ${surface.path} emitted a browser error: ${browserErrors[errorStart].slice(0, 500)}`);
          }
          if (apiErrors.length > apiStart) failures.push(`${role}: ${surface.path} emitted ${apiErrors[apiStart]}`);
        }

        const outcome = failures.length === failureStart ? 'PASS' : 'FAIL';
        console.log(`${outcome} ${role} (${roleChecks} checks, ${visibleSidebarRoutes.length} visible routes)`);
      } catch (error) {
        failures.push(`${role}: audit exception ${String(error.message || error)}`);
      } finally {
        await context.close();
      }
    }
  } finally {
    await browser.close();
  }

  console.log(`\n=== ${checks} RBAC browser checks across ${ACCOUNTS.length} roles; ${failures.length} failure(s) ===`);
  for (const failure of failures) console.log(`FAIL ${failure}`);
  if (failures.length) process.exitCode = 1;
})().catch((error) => {
  console.error('FATAL', error);
  process.exit(1);
});
