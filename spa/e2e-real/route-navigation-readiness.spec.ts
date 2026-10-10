import { expect, test } from '@playwright/test';
import * as fs from 'node:fs';
import { loginAs, navigateSpa } from './helpers';

const ALL: string[] = JSON.parse(fs.readFileSync(new URL('./routes.json', import.meta.url), 'utf8'));
const ROUTES = ALL.filter((route) => route !== '/login' && route !== '/sign-in' && route !== '/forgot-password' && !route.startsWith('/portal/'));
const ROUTE_CHUNK = ROUTES.slice(25, 50);

test('SPA crawl waits for permitted route after repeated guarded pages', async ({ page }) => {
  test.setTimeout(60_000);
  await loginAs(page, 'production@ogami.test', 'password');
  await page.goto(ROUTE_CHUNK[0], { waitUntil: 'domcontentloaded' });

  for (const route of ROUTE_CHUNK.slice(1)) {
    const complaintResponse = route === '/crm/complaints'
      ? page.waitForResponse((response) => {
        const url = new URL(response.url());
        return url.pathname === '/api/v1/crm/complaints' && response.request().method() === 'GET';
      }, { timeout: 10_000 })
      : undefined;
    await navigateSpa(page, route);
    await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => undefined);
    if (route === '/crm/complaints') {
      if (!complaintResponse) throw new Error('Complaint API response listener was not registered.');
      const response = await complaintResponse;
      expect(response.ok()).toBe(true);
      const result: { data: unknown[]; meta: { total: number; per_page: number } } = await response.json();
      expect(Array.isArray(result.data)).toBe(true);
      const mainText = await page.locator('main').innerText();
      expect(mainText).toContain('Customer complaints');
      expect(mainText).not.toContain('Page not found');
      if (result.data.length === 0) {
        await expect(page.getByText('No customer complaints')).toBeVisible({ timeout: 5000 });
      } else {
        await expect(page.getByText('No customer complaints')).toHaveCount(0);
        await expect(page.getByRole('row')).toHaveCount(Math.min(result.meta.total, result.meta.per_page) + 1);
      }
      const visibleSkeleton = await page.locator('main .animate-shimmer').evaluateAll((elements) => elements.some((element) => {
        const { width, height } = element.getBoundingClientRect();
        return width > 0 && height > 0;
      }));
      expect(visibleSkeleton).toBe(false);
    }
  }
});
