import { test, expect } from '@playwright/test';
import * as fs from 'node:fs';
import { captureNetwork, loginAs, navigateSpa, ROLES } from './helpers';

/**
 * Phase 3 gate (role-aware surface): visit every static SPA route as EVERY
 * seeded role against the REAL backend. system_admin is covered by
 * crawl.spec.ts; this spec covers the other 15 roles.
 *
 * Bootstrap each chunk once, then traverse its routes through the real SPA
 * router. Full page reload per route hammered shared guest endpoints and gave
 * false 429s. Per-route UI/API checks stay unchanged.
 * Each role's result file is reset before its serialized chunks, so reruns
 * replace stale route evidence.
 *
 * Hard gates (asserted):
 *   - zero HTTP >= 500 from the API (server exception = defect)
 *   - zero blank renders (white screen = defect)
 *   - zero HTTP 429 from the API (rate limiting invalidates route evidence)
 * Permission-dependent 4xxs and bounces are recorded for matrix analysis.
 *
 * Parallelize across roles only. Chunks for the same role stay serial because
 * they share one per-user API throttle bucket and auth limiter.
 * Run:  npx playwright test -c playwright.real.config.ts e2e-real/crawl-roles.spec.ts --workers=4
 */
test.describe.configure({ mode: 'parallel' });

const ALL: string[] = JSON.parse(
  fs.readFileSync(new URL('./routes.json', import.meta.url), 'utf8'),
);
const ROUTES = ALL.filter((r) => r !== '/login' && r !== '/sign-in' && r !== '/forgot-password' && !r.startsWith('/portal/'));
const ROLES_TO_CRAWL = ROLES.filter((r) => r.slug !== 'system_admin');

const CHUNK = 25;
const FORBIDDEN_TEXT = /\b403\b|forbidden|access denied|not authorized|don.t have permission/i;
const chunks: string[][] = [];
for (let i = 0; i < ROUTES.length; i += CHUNK) chunks.push(ROUTES.slice(i, i + CHUNK));

test('account number 4030 is not reported as a forbidden page', () => {
  const accountCode = '4030 — Gain on Disposal of Asset';
  expect(FORBIDDEN_TEXT.test(accountCode)).toBe(false);
  expect(FORBIDDEN_TEXT.test('403 Forbidden')).toBe(true);
});

function appendRows(file: string, rows: Array<Record<string, unknown>>): void {
  const resultsUrl = new URL(`./results/${file}`, import.meta.url);
  fs.mkdirSync(new URL('./results/', import.meta.url), { recursive: true });
  let existing: Array<Record<string, unknown>>;
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

  test.describe(`${role.slug} route crawl`, () => {
    test.describe.configure({ mode: 'serial' });
    test.beforeAll(() => {
      fs.mkdirSync(new URL('./results/', import.meta.url), { recursive: true });
      fs.writeFileSync(new URL(`./results/${file}`, import.meta.url), '[]');
    });

    chunks.forEach((routes, ci) => {
      test(`${role.slug}: chunk ${ci + 1}/${chunks.length} (${routes[0]} … ${routes[routes.length - 1]})`, async ({
        page,
      }) => {
        test.setTimeout(600_000);
        await loginAs(page, role.email, role.password);
        const rows: Array<Record<string, unknown>> = [];
        const hardFailures: string[] = [];
        let appBooted = false;

        for (const route of routes) {
          const net = captureNetwork(page);
          if (appBooted) {
            await navigateSpa(page, route);
          } else {
            await page.goto(route, { waitUntil: 'domcontentloaded', timeout: 30_000 });
            appBooted = true;
          }
          await page.waitForTimeout(2500);
          const finalUrl = page.url().replace(/http:\/\/[^/]+/, '');
          const bodyText = ((await page.locator('body').innerText().catch(() => '')) ?? '').trim();
          const main = page.locator('main').first();
          const hasMain = (await page.locator('main').count()) > 0;
          const renderedText = hasMain ? (await main.innerText().catch(() => '')).trim() : bodyText;
          const blank = renderedText.length < 20;
          const serverErrors = net.failedRequests.filter((r) => (r.status as number) >= 500);
          const rateLimits = net.failedRequests.filter((r) => r.status === 429);
          rows.push({
            route,
            role: role.slug,
            finalUrl,
            bouncedToLogin: /sign-in|\/login/.test(finalUrl),
            blank,
            contentRoot: hasMain ? 'main' : 'body',
            forbidden: FORBIDDEN_TEXT.test(bodyText),
            consoleErrors: net.errors.slice(0, 5),
            failedApi: net.failedRequests.slice(0, 10),
          });
          if (serverErrors.length > 0) {
            hardFailures.push(`${route} server errors: ${JSON.stringify(serverErrors)}`);
          }
          if (rateLimits.length > 0) {
            hardFailures.push(`${route} rate-limited requests: ${JSON.stringify(rateLimits)}`);
          }
          if (blank) hardFailures.push(`${route} rendered blank`);
          net.stop();
          if (/sign-in|\/login/.test(finalUrl)) {
            await loginAs(page, role.email, role.password);
            appBooted = false;
          }
        }

        appendRows(file, rows);
        expect(hardFailures, hardFailures.join('\n')).toEqual([]);
      });
    });
  });
});
