import { test, expect } from '@playwright/test';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { captureNetwork, loginAs } from './helpers';

/**
 * Phase 3 gate (broad surface): visit every static SPA route as system_admin
 * against the REAL backend. Records per-route: console errors, failed API
 * requests (>=500 or unexpected), blank renders, bounces to sign-in.
 *
 * This is a FINDINGS GENERATOR, not a pass/fail gate: it writes
 * results/crawl-admin.json and fails only on server errors (>=500) or a
 * fully blank page. 4xx API responses and permission-shaped renders are
 * recorded for human triage (a missing button vs a visible-but-forbidden
 * button can only be judged per role — see auth.roles.spec.ts + role crawls).
 */
const ROUTES: string[] = JSON.parse(
  fs.readFileSync(new URL('./routes.json', import.meta.url), 'utf8'),
);

test.describe('admin route crawl (real backend)', () => {
  test('crawl all static routes', async ({ page }) => {
    await loginAs(page, 'admin@ogami.test', 'password');
    const rows: Array<Record<string, unknown>> = [];

    for (const route of ROUTES) {
      if (route.startsWith('/portal/') || route === '/login' || route === '/forgot-password') continue;
      // eslint-disable-next-line no-await-in-loop
      const net = captureNetwork(page);
      // eslint-disable-next-line no-await-in-loop
      await page.goto(route, { waitUntil: 'domcontentloaded', timeout: 30_000 });
      // eslint-disable-next-line no-await-in-loop
      await page.waitForTimeout(1200);
      const url = page.url();
      const bouncedToLogin = /sign-in|\/login/.test(url);
      const bodyText = ((await page.locator('body').textContent().catch(() => '')) ?? '').trim();
      const blank = bodyText.length < 20;
      const serverErrors = net.failedRequests.filter((r) => r.status >= 500);
      rows.push({
        route,
        finalUrl: url.replace(/http:\/\/[^/]+/, ''),
        bouncedToLogin,
        blank,
        consoleErrors: net.errors.slice(0, 5),
        failedApi: net.failedRequests.slice(0, 10),
      });
      expect(serverErrors, `${route} server errors: ${JSON.stringify(serverErrors)}`).toEqual([]);
      expect(blank, `${route} rendered blank`).toBe(false);
      expect(bouncedToLogin, `${route} bounced to login for admin`).toBe(false);
      net.stop();
    }

    const resultsDir = new URL('./results/', import.meta.url);
    fs.mkdirSync(resultsDir, { recursive: true });
    fs.writeFileSync(new URL('./results/crawl-admin.json', import.meta.url), JSON.stringify(rows, null, 1));
  });
});
