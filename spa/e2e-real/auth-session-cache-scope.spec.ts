import { expect, test } from '@playwright/test';
import { baseUrl, loginAs, statefulHeaders } from './helpers';

const BASE_URL = baseUrl();

test('signing out in one tab clears the authenticated peer tab', async ({ browser }) => {
  test.setTimeout(45_000);
  const context = await browser.newContext({ baseURL: BASE_URL });
  const firstTab = await context.newPage();
  const peerTab = await context.newPage();

  try {
    await loginAs(firstTab, 'employee@ogami.test', 'password');
    await Promise.all([firstTab.goto('/dashboard'), peerTab.goto('/dashboard')]);

    const profileMenu = /Profile menu for/;
    await expect(firstTab.getByRole('button', { name: profileMenu })).toBeVisible();
    await expect(peerTab.getByRole('button', { name: profileMenu })).toBeVisible();
    const peerIdentity = await peerTab.request.get(`${BASE_URL}/api/v1/auth/user`, {
      headers: statefulHeaders(),
    });
    expect(peerIdentity.status()).toBe(200);

    const logoutResponsePromise = firstTab.waitForResponse((response) =>
      response.url().endsWith('/api/v1/auth/logout') && response.request().method() === 'POST',
    );
    await firstTab.getByRole('button', { name: profileMenu }).click();
    await firstTab.getByRole('menuitem', { name: 'Sign out' }).click();
    const logoutResponse = await logoutResponsePromise;
    expect(logoutResponse.status()).toBe(204);

    await expect(firstTab).toHaveURL(/\/sign-in(?:\?.*)?$/, { timeout: 10_000 });
    await expect(peerTab).toHaveURL(/\/sign-in(?:\?.*)?$/, { timeout: 10_000 });
    await expect(peerTab.getByRole('button', { name: profileMenu })).toHaveCount(0);

    const peerAfterLogout = await peerTab.request.get(`${BASE_URL}/api/v1/auth/user`, {
      headers: statefulHeaders(),
    });
    expect(peerAfterLogout.status()).toBe(401);
  } finally {
    await context.close();
  }
});
