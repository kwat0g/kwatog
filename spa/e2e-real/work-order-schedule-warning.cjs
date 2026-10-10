// Read-only checks. Inject an expired warning into browser responses without changing live records.
const { chromium, expect } = require('@playwright/test');

(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    for (const email of ['production@ogami.test', 'ppc@ogami.test']) {
      const context = await browser.newContext({ baseURL: 'https://47.129.228.44', ignoreHTTPSErrors: true });
      const headers = { Origin: 'https://47.129.228.44', Referer: 'https://47.129.228.44/', Accept: 'application/json' };
      await context.request.get('/sanctum/csrf-cookie', { headers });
      const token = (await context.cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
      expect((await context.request.post('/api/v1/auth/sign-in', {
        headers: { ...headers, 'X-XSRF-TOKEN': decodeURIComponent(token.value) },
        data: { email, password: 'password' },
      })).status()).toBe(200);
      const url = '/api/v1/production/work-orders/r9lgRVMgOw';
      const beforeResponse = await context.request.get(url, { headers });
      expect(beforeResponse.status()).toBe(200);
      const before = (await beforeResponse.json()).data;
      expect(Object.hasOwn(before, 'schedule_warning')).toBe(true);
      let warning = { kind: 'schedule_passed', message: 'Schedule passed. Reschedule before starting.', scheduled_start: '2026-10-07T03:00:00+08:00', scheduled_end: '2026-10-07T04:38:00+08:00' };
      const page = await context.newPage();
      const errors = [];
      const writes = [];
      page.on('pageerror', (error) => errors.push(error.message));
      page.on('request', (request) => {
        if (request.method() !== 'GET' && request.url().includes('/production/work-orders')) writes.push(request.url());
      });
      await page.route('**/api/v1/production/work-orders**', async (route) => {
        const path = new URL(route.request().url()).pathname;
        if (path !== url && path !== '/api/v1/production/work-orders') return route.continue();
        const response = await route.fetch();
        expect(response.status()).toBe(200);
        const json = await response.json();
        if (Array.isArray(json.data)) {
          const target = json.data.find((row) => row.id === before.id) ?? json.data[0];
          expect(target).toBeTruthy();
          target.schedule_warning = warning;
        } else json.data.schedule_warning = warning;
        await route.fulfill({ response, json });
      });
      for (const viewport of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
        await page.setViewportSize(viewport);
        await page.goto('/production/work-orders');
        await expect(page.getByText('Schedule passed', { exact: true })).toBeVisible();
        await page.screenshot({ path: `/tmp/wo-warning-${email.split('@')[0]}-table-${viewport.width}.png`, fullPage: true });
        await page.goto('/production/work-orders/r9lgRVMgOw');
        const banner = page.getByRole('status').filter({ hasText: warning.message });
        await expect(banner).toBeVisible();
        await expect(banner).toContainText('Scheduled finish: Oct 7, 2026 · 4:38 AM (Asia/Manila)');
        await page.screenshot({ path: `/tmp/wo-warning-${email.split('@')[0]}-record-${viewport.width}.png`, fullPage: true });
        expect(await banner.evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true);
      }
      warning = { kind: 'start_overdue', message: 'Start overdue. Click Start to check readiness.', scheduled_start: '2026-10-07T04:13:00+08:00', scheduled_end: '2026-10-07T05:55:00+08:00' };
      for (const viewport of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
        await page.setViewportSize(viewport);
        await page.goto('/production/work-orders');
        await expect(page.getByText('Start overdue', { exact: true })).toBeVisible();
        await page.goto('/production/work-orders/r9lgRVMgOw');
        const banner = page.getByRole('status').filter({ hasText: warning.message });
        await expect(banner).toBeVisible();
        await expect(banner).toContainText('Scheduled start: Oct 7, 2026 · 4:13 AM (Asia/Manila)');
        await page.screenshot({ path: `/tmp/wo-late-start-${email.split('@')[0]}-${viewport.width}.png`, fullPage: true });
        expect(await banner.evaluate(element => element.scrollWidth <= element.clientWidth)).toBe(true);
      }
      expect(errors).toEqual([]);
      expect(writes).toEqual([]);
      const after = (await (await context.request.get(url, { headers })).json()).data;
      expect([after.status, after.updated_at, after.quantity_produced, after.schedule_warning])
        .toEqual([before.status, before.updated_at, before.quantity_produced, before.schedule_warning]);
      console.log(`${email}: missed-start and expired warnings verified on table and record at 1440px and 390px; live WO unchanged.`);
      await context.close();
    }
  } finally {
    await browser.close();
  }
})().catch((error) => { console.error(error); process.exitCode = 1; });
