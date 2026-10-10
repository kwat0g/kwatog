import { test, expect } from '@playwright/test';
import { ROLES, baseUrl, captureNetwork, loginAs, loginViaUi, relevantErrors, statefulHeaders } from './helpers';

function unexpectedApiFailures(net: ReturnType<typeof captureNetwork>) {
  return net.failedRequests.filter((request) => {
    const path = new URL(request.url).pathname;
    return !(request.status === 401 && request.method === 'GET' && path === '/api/v1/auth/user');
  });
}

/**
 * Phase 1 gate: every seeded role can sign in against the REAL backend and
 * land in an authenticated shell through the UI form and API-cookie flow.
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
    const apiFailures = unexpectedApiFailures(net);
    expect(apiFailures, `API failures: ${JSON.stringify(apiFailures)}`).toEqual([]);
    net.stop();
  });

  for (const role of ROLES.filter((r) => r.slug !== 'system_admin')) {
    test(`UI sign-in form accepts: ${role.slug}`, async ({ page }) => {
      const net = captureNetwork(page);
      await loginViaUi(page, role.email, role.password);
      const me = await page.request.get(`${baseUrl()}/api/v1/auth/user`, {
        headers: statefulHeaders(),
      });
      expect(me.ok(), `${role.slug}: GET /auth/user -> ${me.status()}`).toBe(true);
      expect(JSON.stringify(await me.json())).toContain(role.email);
      const errs = relevantErrors(net);
      expect(errs, `${role.slug} console errors: ${errs.join(' | ')}`).toEqual([]);
      const apiFailures = unexpectedApiFailures(net);
      expect(apiFailures, `${role.slug} API failures: ${JSON.stringify(apiFailures)}`).toEqual([]);
      net.stop();
    });

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
      const errs = relevantErrors(net);
      expect(errs, `${role.slug} console errors: ${errs.join(' | ')}`).toEqual([]);
      const apiFailures = unexpectedApiFailures(net);
      expect(apiFailures, `${role.slug} API failures: ${JSON.stringify(apiFailures)}`).toEqual([]);
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
    const me = await page.request.get(`${base}/api/v1/auth/user`, { headers });
    expect(me.status(), 'bad credentials must not create an authenticated session').toBe(401);
  });
});
