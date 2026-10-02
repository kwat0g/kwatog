import { test, expect } from '@playwright/test';
import * as fs from 'node:fs';
import { captureNetwork, loginAs } from './helpers';

/**
 * Phase 3 gate (broad surface): visit every static SPA route as system_admin
 * against the REAL backend. Records per-route: console errors, failed API
 * requests, blank renders, bounces to sign-in.
 *
 * Record-and-continue: each chunk ALWAYS appends its rows to
 * results/crawl-admin.json, then asserts only the hard gates:
 *   - zero HTTP >= 500 from the API (server crash/exception = defect)
 *   - zero blank renders (white screen = defect)
 * Bounces, 4xx, and 429s are recorded for triage, not asserted: this crawl
 * loads ~150 pages in minutes and trips the 60/min API throttle, which real
 * users never hit. A bounce under throttle is harness noise; a bounce in
 * isolation (repro single-route) is a defect.
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

const resultsUrl = new URL('./results/crawl-admin.json', import.meta.url);
function appendRows(rows: Array<Record<string, unknown>>): void {
  const dir = new URL('./results/', import.meta.url);
  fs.mkdirSync(dir, { recursive: true });
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

test.describe('admin route crawl (real backend)', () => {
  // Fresh file at the start of the first chunk only.
  test.beforeAll(() => {
    const dir = new URL('./results/', import.meta.url);
    fs.mkdirSync(dir, { recursive: true });
    fs.writeFileSync(resultsUrl, '[]');
  });

  chunks.forEach((routes, ci) => {
    test(`chunk ${ci + 1}/${chunks.length}: ${routes[0]} … ${routes[routes.length - 1]}`, async ({
      page,
    }) => {
      test.setTimeout(600_000);
      await loginAs(page, 'admin@ogami.test', 'password');
      const rows: Array<Record<string, unknown>> = [];
      const hardFailures: string[] = [];

      for (const route of routes) {
        const net = captureNetwork(page);
        await page.goto(route, { waitUntil: 'domcontentloaded', timeout: 30_000 });
        await page.waitForTimeout(2500);
        const finalUrl = page.url().replace(/http:\/\/[^/]+/, '');
        const bodyText = ((await page.locator('body').textContent().catch(() => '')) ?? '').trim();
        const blank = bodyText.length < 20;
        const serverErrors = net.failedRequests.filter((r) => (r.status as number) >= 500);
        rows.push({
          route,
          finalUrl,
          bouncedToLogin: /sign-in|\/login/.test(finalUrl),
          blank,
          consoleErrors: net.errors.slice(0, 5),
          failedApi: net.failedRequests.slice(0, 10),
        });
        if (serverErrors.length > 0) {
          hardFailures.push(`${route} server errors: ${JSON.stringify(serverErrors)}`);
        }
        if (blank) hardFailures.push(`${route} rendered blank`);
        net.stop();
        if (/sign-in|\/login/.test(finalUrl)) {
          await loginAs(page, 'admin@ogami.test', 'password');
        }
      }

      appendRows(rows);
      expect(hardFailures, hardFailures.join('\n')).toEqual([]);
    });
  });
});
