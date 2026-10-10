import { test, expect } from '@playwright/test';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { fileURLToPath } from 'node:url';
import { captureNetwork, loginAs, navigateSpa } from './helpers';

/**
 * Phase 3 gate (broad surface): visit every static SPA route as system_admin
 * against the REAL backend. Records per-route: console errors, failed API
 * requests, blank renders, bounces to sign-in.
 *
 * Bootstrap each chunk once, then traverse its routes through the SPA router
 * to avoid repeatedly loading and rate-limiting shared guest endpoints.
 * Record-and-continue: each chunk ALWAYS appends its rows to
 * results/crawl-admin.json, then asserts the hard gates:
 *   - zero HTTP >= 500 from the API (server crash/exception = defect)
 *   - zero blank renders (white screen = defect)
 *   - zero HTTP 429 from the API (rate limiting invalidates route evidence)
 * Permission-dependent 4xxs and bounces are recorded for matrix triage.
 *
 * Sharded into ~25-route chunks so each test stays inside the API throttle
 * window and its own timeout. One login per chunk, same email spaced minutes
 * apart (auth limiter is 5/min per IP+email).
 */
const ALL: string[] = JSON.parse(
  fs.readFileSync(new URL('./routes.json', import.meta.url), 'utf8'),
);
const ROUTES = ALL.filter((r) => r !== '/login' && r !== '/forgot-password' && !r.startsWith('/portal/'));

const CHUNK = 25;
const chunks: string[][] = [];
for (let i = 0; i < ROUTES.length; i += CHUNK) chunks.push(ROUTES.slice(i, i + CHUNK));

const RESULTS_DIR = process.env.E2E_AUDIT_RESULTS_DIR
  ? path.resolve(process.env.E2E_AUDIT_RESULTS_DIR)
  : fileURLToPath(new URL('./results/', import.meta.url));
const resultsFile = path.join(RESULTS_DIR, 'crawl-admin.json');
function appendRows(rows: Array<Record<string, unknown>>): void {
  fs.mkdirSync(RESULTS_DIR, { recursive: true });
  let existing: Array<Record<string, unknown>>;
  try {
    existing = JSON.parse(fs.readFileSync(resultsFile, 'utf8'));
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
  fs.writeFileSync(resultsFile, JSON.stringify(existing, null, 1));
}

test.describe('admin route crawl (real backend)', () => {
  // Fresh file at the start of the first chunk only.
  test.beforeAll(() => {
    fs.mkdirSync(RESULTS_DIR, { recursive: true });
    fs.writeFileSync(resultsFile, '[]');
  });

  chunks.forEach((routes, ci) => {
    test(`chunk ${ci + 1}/${chunks.length}: ${routes[0]} … ${routes[routes.length - 1]}`, async ({
      page,
    }) => {
      test.setTimeout(600_000);
      await loginAs(page, 'admin@ogami.test', 'password');
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
        const routeMain = page.locator('main').first();
        if ((await page.locator('main').count()) === 0) {
          await routeMain.waitFor({ state: 'visible', timeout: 15_000 }).catch(() => undefined);
        }
        await page.waitForFunction(
          () => {
            const contentRoot = document.querySelector('main') ?? document.body;
            const hasText = contentRoot.innerText.trim().length >= 20;
            const hasVisibleSkeleton = Array.from(contentRoot.querySelectorAll('.animate-shimmer')).some((element) => {
              const { width, height } = element.getBoundingClientRect();
              return width > 0 && height > 0;
            });
            const hasVisibleLoadingStatus = Array.from(contentRoot.querySelectorAll('[role="status"][aria-label="Loading"]')).some((element) => {
              const { width, height } = element.getBoundingClientRect();
              return width > 0 && height > 0;
            });
            return hasText || hasVisibleSkeleton || hasVisibleLoadingStatus;
          },
          undefined,
          { timeout: 5000 },
        ).catch(() => undefined);
        try {
          await net.waitForApiIdle();
        } catch (error) {
          hardFailures.push(`${route} API requests did not settle: ${error instanceof Error ? error.message : String(error)}`);
        }
        const loadingIndicators = page.locator('main .animate-shimmer:visible, main [role="status"][aria-label="Loading"]:visible');
        await expect(loadingIndicators).toHaveCount(0, { timeout: 10_000 }).catch(() => undefined);
        const finalUrl = page.url().replace(/http:\/\/[^/]+/, '');
        const bodyText = ((await page.locator('body').innerText().catch(() => '')) ?? '').trim();
        const main = page.locator('main').first();
        const hasMain = (await page.locator('main').count()) > 0;
        const renderedText = hasMain ? (await main.innerText().catch(() => '')).trim() : bodyText;
        const hasVisibleSkeleton = await (hasMain ? main : page.locator('body'))
          .locator('.animate-shimmer:visible')
          .count()
          .then((count) => count > 0)
          .catch(() => false);
        const hasVisibleLoadingStatus = await (hasMain ? main : page.locator('body'))
          .locator('[role="status"][aria-label="Loading"]:visible')
          .count()
          .then((count) => count > 0)
          .catch(() => false);
        const blank = renderedText.length < 20 && !hasVisibleSkeleton && !hasVisibleLoadingStatus;
        const serverErrors = net.failedRequests.filter((r) => (r.status as number) >= 500);
        const rateLimits = net.failedRequests.filter((r) => r.status === 429);
        rows.push({
          route,
          finalUrl,
          bouncedToLogin: /sign-in|\/login/.test(finalUrl),
          blank,
          contentRoot: hasMain ? 'main' : 'body',
          hasVisibleSkeleton,
          hasVisibleLoadingStatus,
          consoleErrors: net.errors.slice(0, 5),
          requestFailures: net.requestFailures.slice(0, 10),
          failedApi: net.failedRequests.slice(0, 10),
        });
        if (serverErrors.length > 0) {
          hardFailures.push(`${route} server errors: ${JSON.stringify(serverErrors)}`);
        }
        if (rateLimits.length > 0) {
          hardFailures.push(`${route} rate-limited requests: ${JSON.stringify(rateLimits)}`);
        }
        if (!hasMain) {
          hardFailures.push(`${route} did not render the main landmark`);
        }
        if (hasVisibleSkeleton || hasVisibleLoadingStatus) {
          hardFailures.push(`${route} remained in a loading state after waiting for its route and API requests`);
        }
        if (blank) {
          hardFailures.push(`${route} rendered blank`);
        }
        net.stop();
        if (/sign-in|\/login/.test(finalUrl)) {
          await loginAs(page, 'admin@ogami.test', 'password');
          appBooted = false;
        }
      }

      appendRows(rows);
      expect(hardFailures, hardFailures.join('\n')).toEqual([]);
    });
  });
});
