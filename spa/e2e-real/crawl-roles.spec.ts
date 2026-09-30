import { test, expect } from '@playwright/test';
import * as fs from 'node:fs';
import { captureNetwork, loginAs, ROLES } from './helpers';

/**
 * Phase 3 gate (role-aware surface): visit every static SPA route as EVERY
 * seeded role against the REAL backend. system_admin is covered by
 * crawl.spec.ts; this spec covers the other 15 roles.
 *
 * Per route records: final URL, bounced-to-sign-in, blank render, 403-ish
 * render, console errors, failed API calls → results/crawl-<role>.json.
 *
 * Hard gates (asserted):
 *   - zero HTTP >= 500 from the API (server exception = defect)
 *   - zero blank renders (white screen = defect)
 * Everything else (bounces, 403s, 4xx/429s) is recorded for the role/permission
 * matrix analysis, not asserted: the API throttle (60/min per user) makes 429s
 * harness noise in a bulk crawl; a bounce/403 in isolation is triaged manually.
 *
 * Parallel: each role logs in with its own email → its own auth-limiter bucket
 * (5/min/IP+email) and its own API throttle bucket (60/min per user). The crawl
 * is read-only against the dev DB, so parallel roles do not collide.
 * Run:  npx playwright test -c playwright.real.config.ts e2e-real/crawl-roles.spec.ts --workers=4
 */
test.describe.configure({ mode: 'parallel' });

const ALL: string[] = JSON.parse(
  fs.readFileSync(new URL('./routes.json', import.meta.url), 'utf8'),
);
const ROUTES = ALL.filter((r) => r !== '/login' && r !== '/sign-in' && r !== '/forgot-password' && !r.startsWith('/portal/'));
const ROLES_TO_CRAWL = ROLES.filter((r) => r.slug !== 'system_admin');

const CHUNK = 25;
const chunks: string[][] = [];
for (let i = 0; i < ROUTES.length; i += CHUNK) chunks.push(ROUTES.slice(i, i + CHUNK));

function appendRows(file: string, rows: Array<Record<string, unknown>>): void {
  const resultsUrl = new URL(`./results/${file}`, import.meta.url);
  fs.mkdirSync(new URL('./results/', import.meta.url), { recursive: true });
  let existing: Array<Record<string, unknown>> = [];
  try {
    existing = JSON.parse(fs.readFileSync(resultsUrl, 'utf8'));
  } catch {
    existing = [];
  }
  const seen = new Set(existing.map((r) => r.route));
  for (const r of rows) {
    if (!seen.has(r.route)) {
      existing.push(r);
      seen.add(r.route);
    }
  }
  fs.writeFileSync(resultsUrl, JSON.stringify(existing, null, 1));
}

ROLES_TO_CRAWL.forEach((role) => {
  const file = `crawl-${role.slug}.json`;

  chunks.forEach((routes, ci) => {
    test(`${role.slug}: chunk ${ci + 1}/${chunks.length} (${routes[0]} … ${routes[routes.length - 1]})`, async ({
      page,
    }) => {
      test.setTimeout(600_000);
      await loginAs(page, role.email, role.password);
      const rows: Array<Record<string, unknown>> = [];
      const hardFailures: string[] = [];

      for (const route of routes) {
        // eslint-disable-next-line no-await-in-loop
        const net = captureNetwork(page);
        // eslint-disable-next-line no-await-in-loop
        await page.goto(route, { waitUntil: 'domcontentloaded', timeout: 30_000 });
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(2500);
        const finalUrl = page.url().replace(/http:\/\/[^/]+/, '');
        const bodyText = ((await page.locator('body').textContent().catch(() => '')) ?? '').trim();
        const blank = bodyText.length < 20;
        const serverErrors = net.failedRequests.filter((r) => (r.status as number) >= 500);
        rows.push({
          route,
          role: role.slug,
          finalUrl,
          bouncedToLogin: /sign-in|\/login/.test(finalUrl),
          blank,
          forbidden: /403|forbidden|access denied|not authorized|don.t have permission/i.test(bodyText),
          consoleErrors: net.errors.slice(0, 5),
          failedApi: net.failedRequests.slice(0, 10),
        });
        if (serverErrors.length > 0) {
          hardFailures.push(`${route} server errors: ${JSON.stringify(serverErrors)}`);
        }
        if (blank) hardFailures.push(`${route} rendered blank`);
        net.stop();
        if (/sign-in|\/login/.test(finalUrl)) {
          // eslint-disable-next-line no-await-in-loop
          await loginAs(page, role.email, role.password);
        }
      }

      appendRows(file, rows);
      expect(hardFailures, hardFailures.join('\n')).toEqual([]);
    });
  });
});
