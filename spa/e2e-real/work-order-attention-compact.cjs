// Read-only browser checks; crowded work orders are injected into list responses.
const { chromium, expect } = require('@playwright/test');
(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    for (const email of ['production@ogami.test', 'ppc@ogami.test']) {
      const context = await browser.newContext({ baseURL: 'https://47.129.228.44', ignoreHTTPSErrors: true });
      const headers = { Origin: 'https://47.129.228.44', Referer: 'https://47.129.228.44/', Accept: 'application/json' };
      await context.request.get('/sanctum/csrf-cookie', { headers });
      const token = (await context.cookies()).find(cookie => cookie.name === 'XSRF-TOKEN');
      expect((await context.request.post('/api/v1/auth/sign-in', { headers: { ...headers, 'X-XSRF-TOKEN': decodeURIComponent(token.value) }, data: { email, password: 'password' } })).status()).toBe(200);
      const endpoint = '/api/v1/production/work-orders/r9lgRVMgOw';
      const response = await context.request.get(endpoint, { headers });
      expect(response.status()).toBe(200);
      const before = (await response.json()).data;
      expect(before.sales_order_line).toEqual({ requested_delivery_date: '2026-10-13', delivery_date: '2026-10-13' });
      const page = await context.newPage();
      const errors = []; const writes = [];
      page.on('pageerror', error => errors.push(error.message));
      page.on('request', request => { if (request.method() !== 'GET' && request.url().includes('/production/work-orders')) writes.push(request.url()); });
      await page.route('**/api/v1/production/work-orders?**', async route => {
        const response = await route.fetch();
        expect(response.status()).toBe(200);
        const json = await response.json();
        json.data = Array.from({ length: 100 }, (_, index) => ({ ...before, id: `sample-${index}`, wo_number: `WO-SAMPLE-${String(index + 1).padStart(3, '0')}`, status: 'planned', schedule_warning: null, planned_start: '2026-10-02T00:00:00+08:00', planned_end: '2026-10-03T00:00:00+08:00' }));
        json.meta = { total: 100, current_page: 1, last_page: 1, per_page: 100, from: 1, to: 100 };
        await route.fulfill({ response, json });
      });
      for (const viewport of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
        await page.setViewportSize(viewport);
        await page.goto('/production/work-orders');
        const region = page.getByRole('region', { name: 'Work orders needing attention' });
        await expect(region.getByRole('button')).toHaveCount(100);
        const dimensions = await region.evaluate(element => ({ height: element.clientHeight, content: element.scrollHeight, overflow: getComputedStyle(element).overflowY }));
        expect(dimensions.height).toBeLessThanOrEqual(208);
        expect(dimensions.content).toBeGreaterThan(dimensions.height);
        expect(dimensions.overflow).toBe('auto');
        await expect(page.getByRole('columnheader', { name: 'Production due' })).toBeVisible();
        await expect(region.getByText('Production due Oct 3, 2026').first()).toBeVisible();
        await region.getByRole('button').last().focus();
        expect(await region.evaluate(element => element.scrollTop)).toBeGreaterThan(0);
        await region.evaluate(element => { element.scrollTop = 0; });
        await page.screenshot({ path: `/tmp/wo-attention-${email.split('@')[0]}-${viewport.width}.png` });
        await page.goto('/production/work-orders/r9lgRVMgOw');
        await expect(page.getByText('Production due', { exact: true })).toBeVisible();
        await expect(page.locator('dt').filter({ hasText: /^Production due$/ }).locator('+ dd')).toContainText('Oct 12, 2026');
        await expect(page.locator('dt').filter({ hasText: /^Requested delivery$/ }).locator('+ dd')).toContainText('Oct 13, 2026');
        await expect(page.locator('dt').filter({ hasText: /^Current delivery$/ }).locator('+ dd')).toContainText('Oct 13, 2026');
        await page.screenshot({ path: `/tmp/wo-due-${email.split('@')[0]}-${viewport.width}.png` });
      }
      expect(errors).toEqual([]); expect(writes).toEqual([]);
      const after = (await (await context.request.get(endpoint, { headers })).json()).data;
      expect([after.status, after.updated_at, after.planned_end]).toEqual([before.status, before.updated_at, before.planned_end]);
      console.log(`${email}: 100 attention cards bounded at 208px, keyboard scrolling, due dates, desktop/mobile; live WO unchanged.`);
      await context.close();
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
