import { expect, test } from '@playwright/test';
import { baseUrl, captureNetwork, loginAs, ROLES, statefulHeaders } from './helpers';

test('Finance cannot deactivate a Chart of Accounts parent with children', async ({ page }) => {
  const finance = ROLES.find((candidate) => candidate.slug === 'finance_officer')!;
  await loginAs(page, finance.email, finance.password);
  const net = captureNetwork(page);

  const accountsUrl = `${baseUrl()}/api/v1/accounts`;
  const accounts = await page.request.get(`${accountsUrl}?search=1000&per_page=200`, {
    headers: statefulHeaders(),
  });
  expect(accounts.status()).toBe(200);
  const root = (await accounts.json()).data.find((account: { code: string }) => account.code === '1000');
  expect(root).toBeTruthy();
  const xsrf = (await page.context().cookies(baseUrl())).find((cookie) => cookie.name === 'XSRF-TOKEN');
  expect(xsrf).toBeTruthy();

  await page.goto('/accounting/coa');
  await expect(page.getByRole('heading', { name: 'Chart of Accounts' })).toBeVisible();
  const deactivate = page.getByRole('button', { name: 'Deactivate 1000' });
  await expect(deactivate).toBeDisabled();
  await expect(deactivate).toHaveAttribute('title', 'Deactivate child accounts first');
  await expect(deactivate).toHaveAttribute('aria-description', 'Deactivate child accounts first');

  const response = await page.request.delete(`${accountsUrl}/${root.id}`, {
    headers: {
      ...statefulHeaders(),
      'X-XSRF-TOKEN': decodeURIComponent(xsrf!.value),
    },
  });
  expect(response.status()).toBe(422);
  expect(await response.json()).toMatchObject({
    message: 'Cannot deactivate an account that has child accounts.',
  });
  const unchanged = await page.request.get(`${accountsUrl}/${root.id}`, {
    headers: statefulHeaders(),
  });
  expect(unchanged.status()).toBe(200);
  expect((await unchanged.json()).data.is_active).toBe(true);
  expect(net.failedRequests).toEqual([]);
  expect(net.errors).toEqual([]);
  net.stop();
});
