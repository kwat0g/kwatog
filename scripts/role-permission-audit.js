const BASE = require('./require-audit-base-url');
const { createRequire } = require('node:module');
const path = require('node:path');
const spaRequire = createRequire(path.join(__dirname, '../spa/package.json'));
const { chromium } = spaRequire('playwright');

const PASSWORD = process.env.AUDIT_PASSWORD || 'password';

/*
 * How a denied page actually looks.
 *
 * This audit used to assert the literal word "Forbidden". PermissionGuard has
 * rendered the unified NotFoundState for a while — deliberately, so the UI never
 * confirms that a route or a record the user cannot read exists — so the assertion
 * had inverted itself: every correctly denied page reported "was accessible but
 * should be Forbidden", 30 of them, and what the audit was asking for was the
 * information leak back. Same defect the e2e specs carried until 68c2a007; this
 * script was missed in that sweep.
 *
 * Denial is now recognised by the not-found copy, and asserted in BOTH
 * directions: the denied page must look not-found, and no page may name its own
 * denial. Verified against the running app before changing it — employee at
 * /payroll/periods and /hr/loans and qc_inspector at /payroll/statutory each
 * render the not-found state, carry no page data, and fire no data request.
 */
const DENIED = /Page not found|doesn't exist or has been moved/i;
/*
 * Page-level denial must not name itself. Deliberately does NOT include the
 * "You do not have permission to perform this action." toast from
 * spa/src/api/client.ts: that is feedback on an action, which is legitimate, and
 * react-hot-toast keeps it mounted across SPA navigations — so one unsolicited
 * 403 was being attributed to the next several routes the audit visited. An
 * unsolicited 403 is a real defect, and it is already reported on its own by the
 * `emitted <status> <method>` check below, at the route that actually caused it.
 */
const LEAKS_DENIAL = /\bForbidden\b|access denied|not authoriz/i;

const ALL_ACCOUNTS = [
  ['system_admin', 'admin@ogami.test'],
  ['vice_president', 'vp@ogami.test'],
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
  ['sales_officer', 'crm@ogami.test'],
  ['customer_service_officer', 'customerservice@ogami.test'],
];
const requestedRoles = new Set((process.env.AUDIT_ROLES || '').split(',').filter(Boolean));
const ACCOUNTS = requestedRoles.size
  ? ALL_ACCOUNTS.filter(([role]) => requestedRoles.has(role))
  : ALL_ACCOUNTS;

const SURFACES = [
  { path: '/hr/attendance', roles: ['system_admin', 'hr_officer', 'department_head'] },
  { path: '/hr/leaves', roles: ['system_admin', 'hr_officer', 'department_head'] },
  { path: '/hr/loans', roles: ['system_admin', 'vice_president', 'hr_officer', 'finance_officer', 'production_manager', 'department_head'] },
  { path: '/payroll/periods', roles: ['system_admin', 'hr_officer', 'finance_officer'] },
  { path: '/payroll/statutory', roles: ['system_admin', 'hr_officer'] },
  // '/quality/documents' removed: no route and no sidebar item exist for it
  // anywhere in spa/src, so the expectation could never be met.
];

const SIDEBAR_SURFACES = [...SURFACES,
  { path: '/return-management', roles: ['system_admin', 'finance_officer', 'production_manager', 'ppc_head', 'purchasing_officer', 'warehouse_staff', 'qc_inspector', 'department_head', 'customer_service_officer'] },
  { path: '/hr/separations', roles: ['system_admin', 'hr_officer', 'finance_officer', 'warehouse_staff', 'maintenance_tech', 'department_head'] },
  { path: '/crm/complaints', roles: ['system_admin', 'vice_president', 'production_manager', 'qc_inspector', 'customer_service_officer'] },
  { path: '/supply-chain/fleet', roles: ['system_admin', 'impex_officer'] },
  { path: '/supply-chain/shipments', roles: ['system_admin', 'impex_officer', 'purchasing_officer'] },
  { path: '/crm/inquiries', roles: ['system_admin', 'customer_service_officer'] },
  { path: '/hr/recruitment', roles: ['system_admin', 'hr_officer'] },
  { path: '/hr/trainings', roles: ['system_admin', 'hr_officer'] },
  { path: '/hr/profile-update-requests', roles: ['system_admin', 'hr_officer', 'finance_officer', 'department_head'] },
  { path: '/admin/gov-tables', roles: ['system_admin', 'hr_officer'] },
];
const WAREHOUSE_NAV = [
  '/inventory/material-issues',
  '/inventory/stock-adjustments',
  '/inventory/warehouse-map',
  '/inventory/transfer-orders',
  '/inventory/picking',
];
const EXPECTED_WAREHOUSE_NAV = {
  system_admin: WAREHOUSE_NAV,
  finance_officer: ['/inventory/stock-adjustments'],
  warehouse_staff: WAREHOUSE_NAV,
};
const PROCUREMENT_NAV = [
  '/purchasing/chain',
  '/purchasing/purchase-orders',
  '/purchasing/purchase-requests',
  '/purchasing/rfqs',
  '/purchasing/approved-suppliers',
  '/purchasing/supplier-listings',
];
const EXPECTED_PROCUREMENT_NAV = {
  system_admin: PROCUREMENT_NAV,
  vice_president: ['/purchasing/purchase-orders', '/purchasing/purchase-requests', '/purchasing/rfqs'],
  finance_officer: ['/purchasing/purchase-orders', '/purchasing/purchase-requests', '/purchasing/rfqs'],
  purchasing_officer: PROCUREMENT_NAV,
  // These roles have `purchasing.view` for scoped supporting reads. The API
  // policy limits the returned PR/PO rows to their own work or department;
  // they do not receive the company-wide purchasing.chain.view permission.
  production_manager: ['/purchasing/purchase-orders', '/purchasing/purchase-requests'],
  qc_inspector: ['/purchasing/rfqs'],
  impex_officer: ['/purchasing/purchase-orders', '/purchasing/purchase-requests'],
  department_head: ['/purchasing/purchase-orders', '/purchasing/purchase-requests'],
};

async function login(page, email) {
  await page.goto(`${BASE}/sign-in`, { waitUntil: 'domcontentloaded', timeout: 20_000 });
  await page.locator('input[type="email"]').fill(email);
  await page.locator('input[type="password"]').fill(PASSWORD);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL((url) => url.pathname !== '/sign-in', { timeout: 20_000 });
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
  const configurationErrors = [];
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
        const sidebar = page.locator('aside[aria-label="Sidebar"]');
        await sidebar.waitFor({ state: 'visible', timeout: 10_000 });
        if (!await page.locator('aside a[href="/dashboard"][aria-current="page"]').count()) failures.push(`${role}: shared Dashboard is not active`);
        const inactive = sidebar.locator('[data-module]:not([data-module="Overview"]) button[aria-expanded]').first();
        if (await inactive.count()) {
          await inactive.click();
          await page.mouse.move(1200, 100);
          await page.waitForTimeout(5300);
          if (await inactive.getAttribute('aria-expanded') !== 'false') failures.push(`${role}: inactive module did not collapse`);
          checks += 1;

        }
        const expandAll = sidebar.getByRole('button', { name: 'Expand all', exact: true });
        if (await expandAll.isVisible().catch(() => false)) await expandAll.click();
        await sidebar.locator('nav a[href]').first().waitFor({ state: 'visible', timeout: 10_000 });
        browserErrors.length = 0;
        apiErrors.length = 0;
        let roleChecks = 0;

        const visibleSidebarRoutes = [...new Set(await page.locator('aside nav a[href]').evaluateAll((links) =>
          links.map((link) => link.getAttribute('href')).filter((href) => href && href.startsWith('/')),
        ))];
        if (process.env.AUDIT_PRINT_SIDEBAR === '1') {
          console.log(`SIDEBAR ${role}: ${visibleSidebarRoutes.join(', ')}`);
        }

        for (const surface of SIDEBAR_SURFACES) {
          checks += 1;
          roleChecks += 1;
          const expected = surface.roles.includes(role);
          const visible = await page.locator(`aside a[href="${surface.path}"]`).count() > 0;
          if (visible !== expected) {
            failures.push(`${role}: sidebar ${surface.path} expected ${expected ? 'visible' : 'hidden'}, got ${visible ? 'visible' : 'hidden'}`);
          }
        }

        for (const path of WAREHOUSE_NAV) {
          checks += 1;
          roleChecks += 1;
          const expected = EXPECTED_WAREHOUSE_NAV[role]?.includes(path) ?? false;
          const visible = await page.locator(`aside a[href="${path}"]`).count() > 0;
          if (visible !== expected) {
            failures.push(`${role}: sidebar ${path} expected ${expected ? 'visible' : 'hidden'}, got ${visible ? 'visible' : 'hidden'}`);
          }
        }

        for (const path of PROCUREMENT_NAV) {
          checks += 1;
          roleChecks += 1;
          const expected = EXPECTED_PROCUREMENT_NAV[role]?.includes(path) ?? false;
          const visible = await page.locator(`aside a[href="${path}"]`).count() > 0;
          if (visible !== expected) {
            failures.push(`${role}: sidebar ${path} expected ${expected ? 'visible' : 'hidden'}, got ${visible ? 'visible' : 'hidden'}`);
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
          if (DENIED.test(body)) failures.push(`${role}: visible sidebar route ${route} rendered the denied state`);
          if (LEAKS_DENIAL.test(body)) failures.push(`${role}: visible sidebar route ${route} named the denial: ${body.match(LEAKS_DENIAL)[0]}`);
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
          const denied = DENIED.test(body);
          const crashed = /Something went wrong|unexpected error/i.test(body);

          if (expected && denied) failures.push(`${role}: ${surface.path} was denied but should be reachable`);
          if (!expected && !denied) failures.push(`${role}: ${surface.path} was reachable but should be denied`);
          // Denial must not name itself, in either direction.
          if (LEAKS_DENIAL.test(body)) {
            failures.push(`${role}: ${surface.path} named the denial: ${body.match(LEAKS_DENIAL)[0]}`);
          }
          if (crashed) failures.push(`${role}: ${surface.path} hit an error boundary`);
          if (browserErrors.length > errorStart) {
            failures.push(`${role}: ${surface.path} emitted a browser error: ${browserErrors[errorStart].slice(0, 500)}`);
          }
          if (apiErrors.length > apiStart) failures.push(`${role}: ${surface.path} emitted ${apiErrors[apiStart]}`);
        }

        await navigateSpa(page, '/dashboard');
        await page.setViewportSize({ width: 390, height: 844 });
        await page.getByRole('button', { name: 'Toggle sidebar', exact: true }).click();
        const drawer = page.getByRole('dialog', { name: 'Menu', exact: true });
        await drawer.waitFor({ state: 'visible' });
        if (!await drawer.locator('a[href="/dashboard"][aria-current="page"]').count()) failures.push(`${role}: mobile Dashboard is not active`);
        const mobileExpand = drawer.getByRole('button', { name: 'Expand all', exact: true });
        if (await mobileExpand.count()) await mobileExpand.click();
        const mobileRoutes = [...new Set(await drawer.locator('nav a[href]').evaluateAll((links) => links.map((link) => link.getAttribute('href'))))];
        if (mobileRoutes.sort().join(',') !== visibleSidebarRoutes.sort().join(',')) failures.push(`${role}: desktop/mobile navigation differs`);
        await page.keyboard.press('Escape');
        if (await drawer.count()) failures.push(`${role}: mobile sidebar did not close on Escape`);
        checks += 3;
        roleChecks += 3;
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

  const knownFailures = process.env.AUDIT_SEPARATE_POLICY_ERRORS === '1' ? failures.filter((failure) => failure.includes('business-policies') || failure.includes('/action-center emitted a browser error: Failed to load resource: the server responded with a status of 422')) : [];
  configurationErrors.push(...knownFailures);
  for (let i = failures.length - 1; i >= 0; i--) if (knownFailures.includes(failures[i])) failures.splice(i, 1);
  console.log(`CONFIGURATION: ${configurationErrors.length} observations of missing/invalid company VAT policy`);
  console.log(`\n=== ${checks} RBAC browser checks across ${ACCOUNTS.length} roles; ${failures.length} failure(s) ===`);
  for (const failure of failures) console.log(`FAIL ${failure}`);
  if (failures.length) process.exitCode = 1;
})().catch((error) => {
  console.error('FATAL', error);
  process.exit(1);
});
