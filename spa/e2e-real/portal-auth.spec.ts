import { expect, test } from '@playwright/test';
import { baseUrl, captureNetwork, loginViaUi, relevantErrors, statefulHeaders } from './helpers';

const portals = [
  {
    realm: 'customer',
    email: 'portal@cust.test',
    navigation: 'Customer Portal navigation',
    ownEndpoint: '/api/v1/b2b/customer/me',
    foreignEndpoint: '/api/v1/b2b/supplier/me',
  },
  {
    realm: 'supplier',
    email: 'portal@supp.test',
    navigation: 'Supplier Portal navigation',
    ownEndpoint: '/api/v1/b2b/supplier/me',
    foreignEndpoint: '/api/v1/b2b/customer/me',
  },
] as const;

for (const portal of portals) {
  test(`${portal.realm} portal signs in through UI, stays isolated, and logs out`, async ({ page }) => {
    const network = captureNetwork(page);

    try {
      await page.goto(`/portal/${portal.realm}/login`, { waitUntil: 'domcontentloaded' });
      await expect(page).toHaveURL(/\/sign-in$/);
      await loginViaUi(page, portal.email, 'password');
      await expect(page).toHaveURL(new RegExp(`/portal/${portal.realm}$`));
      await expect(page.getByRole('navigation', { name: portal.navigation })).toBeVisible();
      await expect(page.getByRole('heading', { name: 'Dashboard', exact: true })).toBeVisible();

      const sessionCookie = (await page.context().cookies(baseUrl())).find((cookie) => cookie.httpOnly);
      expect(sessionCookie, 'portal auth must use an HTTP-only session cookie').toBeDefined();
      const storage = await page.evaluate(() => [
        ...Object.values(localStorage),
        ...Object.values(sessionStorage),
      ].join('\n'));
      expect(storage).not.toMatch(/bearer|access[_-]?token/i);

      const ownResponse = await page.request.get(`${baseUrl()}${portal.ownEndpoint}`, {
        headers: statefulHeaders(),
      });
      expect(ownResponse.status()).toBe(200);
      const ownBody = await ownResponse.json();
      expect(ownBody.data.email).toBe(portal.email);
      expect(ownBody.data).not.toHaveProperty('password');
      expect(ownBody.data).not.toHaveProperty('token');

      const foreignResponse = await page.request.get(`${baseUrl()}${portal.foreignEndpoint}`, {
        headers: statefulHeaders(),
      });
      expect(foreignResponse.status(), 'one portal session must not authenticate in the other realm').toBe(401);

      const logoutResponse = page.waitForResponse((response) =>
        response.url().includes(portal.ownEndpoint.replace('/me', '/logout')) &&
        response.request().method() === 'POST',
      );
      await page.getByRole('button', { name: 'Sign out', exact: true }).click();
      expect((await logoutResponse).status()).toBe(200);
      await expect(page).toHaveURL(/\/sign-in$/);

      const afterLogout = await page.request.get(`${baseUrl()}${portal.ownEndpoint}`, {
        headers: statefulHeaders(),
      });
      expect(afterLogout.status(), 'logout must invalidate the portal session on the server').toBe(401);
    } finally {
      network.stop();
    }

    const unexpectedApiFailures = network.failedRequests.filter((request) => {
      const path = new URL(request.url).pathname;
      return !(request.status === 401 && request.method === 'GET' && path === '/api/v1/auth/user');
    });
    expect(unexpectedApiFailures).toEqual([]);
    expect(relevantErrors(network)).toEqual([]);
  });
}
