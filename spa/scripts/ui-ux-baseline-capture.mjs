#!/usr/bin/env node
/**
 * OGAMI ERP — UI/UX baseline capture harness (Phase 0).
 *
 * Logs in as a demo account via the real sign-in form (HTTP-only cookie session,
 * no token smuggling) and screenshots a list of routes at several viewports.
 *
 * Usage:
 *   node scripts/ui-ux/baseline-capture.mjs --role admin
 *   node scripts/ui-ux/baseline-capture.mjs --role qc --routes /quality/ncs,/quality/inspections
 *   node scripts/ui-ux/baseline-capture.mjs --role admin --label after
 *
 * Writes to docs/ui-ux/screenshots/<label>/<role>/<slug>-<width>.png
 *
 * Chromium (not Lightpanda) is required: this measures rendering.
 */

import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import process from 'node:process';

/** Screenshots land in <repo root>/docs/ui-ux/screenshots regardless of cwd. */
const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

/** The API's SANCTUM_STATEFUL_DOMAINS pins the session to APP_URL, so the
 *  capture must use that origin — a 127.0.0.1 origin 419s on sign-in. */
const BASE = process.env.OGAMI_BASE_URL ?? 'https://47.129.228.44';

/** Demo accounts seeded by api/database/seeders/DemoAccountSeeder.php */
const ACCOUNTS = {
  admin: 'admin@ogami.test',
  hr: 'hr@ogami.test',
  finance: 'finance@ogami.test',
  production: 'production@ogami.test',
  ppc: 'ppc@ogami.test',
  purchasing: 'purchasing@ogami.test',
  vp: 'vp@ogami.test',
  employee: 'employee@ogami.test',
  sales: 'crm@ogami.test',
  warehouse: 'warehouse@ogami.test',
  qc: 'qc@ogami.test',
  // The deployed DB diverges from DemoAccountSeeder: maintenance@ogami.test holds
  // maintenance_supervisor here, not maintenance_tech. Keys name the AUTHENTICATED role.
  maintenance_supervisor: 'maintenance@ogami.test',
  // maintenance_tech: intentionally absent — no account with a derivable password holds it
  // (the three holders are @ogami.local accounts; one must change its password). FINDINGS F-008.
  impex: 'impex@ogami.test',
  depthead: 'depthead@ogami.test',
  driver: 'driver@ogami.test',
  customer_service: 'customerservice@ogami.test',
};
const PASSWORD = 'password';

const VIEWPORTS = [
  { width: 375, height: 812, name: 'mobile' },
  { width: 768, height: 1024, name: 'tablet' },
  { width: 1440, height: 900, name: 'desktop' },
];

const DEFAULT_ROUTES = {
  admin: ['/dashboard', '/action-center', '/approvals', '/admin/users', '/admin/roles', '/chains'],
  qc: ['/dashboard', '/quality/inspections', '/quality/ncrs', '/quality/inspection-specs'],
  warehouse: ['/dashboard', '/inventory/items', '/inventory/grn', '/inventory/stock-levels'],
  employee: ['/dashboard', '/self-service/profile', '/self-service/payslips'],
  hr: ['/dashboard', '/hr/employees', '/hr/leaves', '/payroll/periods'],
  finance: ['/dashboard', '/accounting/invoices', '/accounting/bills', '/budgeting'],
  production: ['/dashboard', '/production/work-orders', '/production/schedule'],
  ppc: ['/dashboard', '/mrp/plans', '/production/schedule'],
  purchasing: ['/dashboard', '/purchasing/purchase-orders', '/purchasing/purchase-requests'],
  vp: ['/dashboard', '/approvals', '/chains'],
  sales: ['/dashboard', '/crm/sales-orders', '/crm/customers'],
  maintenance_supervisor: ['/dashboard', '/maintenance/work-orders', '/maintenance/schedules'],
  impex: ['/dashboard', '/supply-chain/deliveries', '/supply-chain/fleet'],
  depthead: ['/dashboard', '/approvals', '/hr/leaves'],
  driver: ['/driver', '/dashboard'],
  customer_service: ['/dashboard', '/crm/complaints', '/return-management'],
};

function arg(name, fallback = undefined) {
  const index = process.argv.indexOf(`--${name}`);
  return index === -1 ? fallback : process.argv[index + 1];
}

function slug(route) {
  return route.replace(/^\//, '').replace(/[^a-z0-9]+/gi, '-') || 'root';
}

async function login(page, email) {
  await page.goto(`${BASE}/sign-in`, { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="email"], input[type="email"]').first().fill(email);
  await page.locator('input[name="password"]').first().fill(PASSWORD);
  await page.getByRole('button', { name: /sign in|log in/i }).first().click();
  await page.waitForURL((url) => !url.pathname.startsWith('/sign-in'), { timeout: 20000 });
}

async function main() {
  const role = arg('role', 'admin');
  const label = arg('label', 'before');
  const email = ACCOUNTS[role];
  if (!email) {
    console.error(`Unknown role "${role}". Known: ${Object.keys(ACCOUNTS).join(', ')}`);
    process.exit(1);
  }
  const explicitRoutes = arg('routes');
  const routes = explicitRoutes
    ? explicitRoutes.split(',').filter(Boolean)
    : (DEFAULT_ROUTES[role] ?? ['/dashboard']);
  const outRoot = path.join(REPO_ROOT, 'docs', 'ui-ux', 'screenshots', label, role);
  await mkdir(outRoot, { recursive: true });

  const browser = await chromium.launch();
  /* TLS is internal (APP_URL=https://47.129.228.44); Chromium must not reject it. */
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage();
  /*
   * Accessible names come from Chromium's own accessibility tree, NOT from a
   * hand-rolled DOM heuristic. An earlier version of this probe inferred the
   * name from `aria-label ?? title ?? innerText ?? alt ?? placeholder` and
   * reported the topbar brand link as unnamed — `innerText` excludes both a
   * `display:none` sibling and an <img> alt, so the heuristic was wrong about a
   * link that Chromium names correctly from its logo. Never hand-roll what the
   * browser already computes.
   */
  const cdp = await context.newCDPSession(page);
  await cdp.send('Accessibility.enable');
  const consoleErrors = [];
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(`${page.url()} :: ${message.text()}`);
  });
  page.on('pageerror', (error) => consoleErrors.push(`${page.url()} :: ${error.message}`));

  const report = [];
  try {
    await login(page, email);
    for (const route of routes) {
      for (const viewport of VIEWPORTS) {
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        const started = Date.now();
        const response = await page.goto(`${BASE}${route}`, { waitUntil: 'networkidle', timeout: 30000 });
        await page.waitForTimeout(400);
        const file = path.join(outRoot, `${slug(route)}-${viewport.width}.png`);
        await page.screenshot({ path: file, fullPage: true });
        const { nodes } = await cdp.send('Accessibility.getFullAXTree');
        const INTERACTIVE_ROLES = new Set([
          'button',
          'link',
          'textbox',
          'searchbox',
          'combobox',
          'listbox',
          'checkbox',
          'radio',
          'slider',
          'menuitem',
          'menuitemcheckbox',
          'menuitemradio',
          'tab',
          'switch',
          'spinbutton',
        ]);
        const unnamedAxNodes = nodes.filter(
          (node) =>
            !node.ignored &&
            INTERACTIVE_ROLES.has(node.role?.value) &&
            !(node.name?.value ?? '').trim(),
        );

        const metrics = await page.evaluate(() => {
          return {
            scrollWidth: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
            title: document.title,
            heading: document.querySelector('h1')?.textContent?.trim() ?? null,
            counts: {
              buttons: document.querySelectorAll('button').length,
              links: document.querySelectorAll('a[href]').length,
              inputs: document.querySelectorAll('input:not([type="hidden"]), select, textarea').length,
              tables: document.querySelectorAll('table').length,
              rows: document.querySelectorAll('tbody tr').length,
              dialogs: document.querySelectorAll('[role="dialog"]').length,
              charts: document.querySelectorAll('svg.recharts-surface').length,
            },
            a11y: {
              imagesWithoutAlt: Array.from(document.querySelectorAll('img')).filter(
                (img) => !img.hasAttribute('alt'),
              ).length,
              positiveTabIndex: document.querySelectorAll(
                '[tabindex]:not([tabindex="0"]):not([tabindex="-1"])',
              ).length,
            },
            /**
             * Unintended overflow — measured against IN-FLOW content only.
             *
             * `scrollWidth > clientWidth` on its own is not evidence of a
             * defect, and a heuristic that stops there reports design decisions
             * as bugs:
             *   - `.truncate` and `.sr-only` overflow on purpose (the long-text
             *     rule and the visually-hidden rule);
             *   - a deliberate OVERLAY inflates its ancestor's scrollWidth —
             *     EmptyState positions a 52–72px `DatumMark` absolutely inside a
             *     40px icon slot, so the wrapper's scrollWidth is 56 by design.
             * An element is only reported when a static/relative descendant's
             * right edge actually crosses it.
             */
            overflowing: (() => {
              const hasInFlowOverflow = (root) => {
                const box = root.getBoundingClientRect();
                const limit = box.left + root.clientWidth + 8;
                const walk = (node) => {
                  for (const child of node.children) {
                    const position = getComputedStyle(child).position;
                    if (position === 'absolute' || position === 'fixed') continue;
                    const rect = child.getBoundingClientRect();
                    if (rect.width > 0 && rect.right > limit) return true;
                    if (walk(child)) return true;
                  }
                  return false;
                };
                if (walk(root)) return true;
                /*
                 * A LEAF whose own text paints past its box — e.g. a
                 * fixed-width monospace label holding a longer word. It has no
                 * element children to measure, so scrollWidth is the only
                 * signal. Skipping this case is how a real defect becomes
                 * invisible to the probe.
                 */
                return root.children.length === 0 && (root.textContent ?? '').trim() !== '';
              };
              return Array.from(document.querySelectorAll('main *'))
                .filter((el) => el.clientWidth > 0 && el.scrollWidth > el.clientWidth + 8)
                .filter((el) => !String(el.className).includes('truncate'))
                .filter((el) => !String(el.className).includes('sr-only'))
                .filter((el) => !el.closest('[class*="overflow-x"], [class*="overflow-auto"]'))
                .filter(hasInFlowOverflow)
                .slice(0, 10)
                .map((el) => {
                  const style = getComputedStyle(el);
                  return `${el.tagName.toLowerCase()}${el.className ? `.${String(el.className).slice(0, 50)}` : ''} (${el.scrollWidth}>${el.clientWidth}, overflow-x:${style.overflowX})`;
                });
            })(),
          };
        });
        report.push({
          route,
          viewport: viewport.name,
          status: response?.status() ?? null,
          ms: Date.now() - started,
          horizontalPageScroll: metrics.scrollWidth > metrics.clientWidth + 1,
          heading: metrics.heading,
          title: metrics.title,
          counts: metrics.counts,
          a11y: {
            ...metrics.a11y,
            /** From Chromium's accessibility tree — the authoritative name check. */
            unnamedInteractive: unnamedAxNodes.length,
            unnamedInteractiveDetail: unnamedAxNodes
              .slice(0, 8)
              .map((node) => `${node.role?.value}${node.description?.value ? ` (${node.description.value})` : ''}`),
          },
          overflowing: metrics.overflowing,
          file: path.relative(REPO_ROOT, file),
        });
        console.log(
          `${route} @${viewport.width} -> ${response?.status()} ${Date.now() - started}ms` +
            (metrics.scrollWidth > metrics.clientWidth + 1 ? ' [H-SCROLL]' : ''),
        );
      }
    }
  } finally {
    await browser.close();
  }

  await writeFile(
    path.join(outRoot, 'report.json'),
    JSON.stringify({ role, label, base: BASE, routes, consoleErrors, report }, null, 2),
  );
  if (consoleErrors.length > 0) {
    console.log(`\n${consoleErrors.length} console error(s) captured (see report.json)`);
  }
  console.log(`\nWrote ${report.length} screenshot(s) to ${outRoot}`);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
