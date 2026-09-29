import { test, expect } from '@playwright/test';
import { ROLES, baseUrl, captureNetwork, loginAs, loginViaUi, relevantErrors, statefulHeaders } from './helpers';

/**
 * Phase 1 gate: every seeded role can sign in against the REAL backend and
 * land in an authenticated shell. One UI-driven login (proves the form works
 * end to end); the rest via API-cookie login with 429 backoff (rate limit is
 * 5/min/IP — hammering the form 16x would test the limiter, not auth).
 *
 * Fails on: sign-in rejection, missing session (GET /api/v1/auth/user 401),
 * console errors during bootstrap, or failed API calls on the landing page.
 */
test.describe('real-backend role sign-in', () => {
  test('UI sign-in form works end to end (system_admin)', async ({ page }) => {
    const net = captureNetwork(page);
    await loginViaUi(page, 'admin@ogami.test', 'password');
    const me = await page.request.get(`${baseUrl()}/api/v1/auth/user`, {
      headers: statefulHeaders(),
    });
    expect(me.ok()).toBe(true);
    const body = await me.json();
    expect(JSON.stringify(body)).toContain('admin@ogami.test');
    const errs = relevantErrors(net);
    expect(errs, `console errors: ${errs.join(' | ')}`).toEqual([]);
    net.stop();
  });

  for (const role of ROLES.filter((r) => r.slug !== 'system_admin')) {
    test(`role session bootstraps: ${role.slug}`, async ({ page }) => {
      const net = captureNetwork(page);
      await loginAs(page, role.email, role.password);
      await page.goto('/dashboard');
      // Authenticated shell renders (sidebar or dashboard heading), not a
      // bounce back to sign-in.
      await expect(page).not.toHaveURL(/sign-in/, { timeout: 20_000 });
      const me = await page.request.get(`${baseUrl()}/api/v1/auth/user`, {
        headers: statefulHeaders(),
      });
      expect(me.ok(), `${role.slug}: GET /auth/user -> ${me.status()}`).toBe(true);
      const serverErrors = net.failedRequests.filter((r) => r.status >= 500);
      expect(
        serverErrors,
        `${role.slug} server errors: ${JSON.stringify(serverErrors)}`,
      ).toEqual([]);
      net.stop();
    });
  }

  test('wrong password is rejected (no session)', async ({ page }) => {
    const base = baseUrl();
    const headers = statefulHeaders();
    await page.request.get(`${base}/sanctum/csrf-cookie`, { headers });
    const cookies = await page.context().cookies();
    const xsrf = cookies.find((c) => c.name === 'XSRF-TOKEN');
    const postHeaders: Record<string, string> = { ...headers };
    if (xsrf) postHeaders['X-XSRF-TOKEN'] = decodeURIComponent(xsrf.value);
    const res = await page.request.post(`${base}/api/v1/auth/sign-in`, {
      headers: postHeaders,
      data: { email: 'admin@ogami.test', password: 'wrong-password-123' },
    });
    expect([401, 422]).toContain(res.status());
  });
});
