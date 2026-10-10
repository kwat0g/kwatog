import { expect, test } from '@playwright/test';
import { baseUrl, captureNetwork, loginAs, ROLES, statefulHeaders } from './helpers';

test('Vice President reads accounts but cannot create, edit, deactivate, or activate them', async ({ page }) => {
  const vp = ROLES.find((candidate) => candidate.slug === 'vice_president')!;
  await loginAs(page, vp.email, vp.password);
  const net = captureNetwork(page);

  await page.goto('/accounting/coa');
  await expect(page.getByRole('heading', { name: 'Chart of Accounts' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Add account' })).toHaveCount(0);
  await expect(page.getByText('Edit', { exact: true })).toHaveCount(0);
  await expect(page.getByRole('button', { name: /^Deactivate |^Activate / })).toHaveCount(0);

  const accountsUrl = `${baseUrl()}/api/v1/accounts`;
  const listed = await page.request.get(`${accountsUrl}?search=1000&per_page=200`, {
    headers: statefulHeaders(),
  });
  expect(listed.status()).toBe(200);
  const root = (await listed.json()).data.find((account: { code: string }) => account.code === '1000');
  expect(root).toBeTruthy();

  const xsrf = (await page.context().cookies(baseUrl())).find((cookie) => cookie.name === 'XSRF-TOKEN');
  expect(xsrf).toBeTruthy();
  const mutationHeaders = {
    ...statefulHeaders(),
    'X-XSRF-TOKEN': decodeURIComponent(xsrf!.value),
  };
  const create = await page.request.post(accountsUrl, {
    headers: mutationHeaders,
    data: { code: '989999', name: 'Unauthorized VP account', type: 'expense', normal_balance: 'debit' },
  });
  expect(create.status()).toBe(403);
  expect((await create.json()).message).toMatch(/permission/i);

  const update = await page.request.put(`${accountsUrl}/${root.id}`, {
    headers: mutationHeaders,
    data: { name: 'Unauthorized VP edit' },
  });
  expect(update.status()).toBe(403);
  const deactivate = await page.request.delete(`${accountsUrl}/${root.id}`, { headers: mutationHeaders });
  expect(deactivate.status()).toBe(403);
  const activate = await page.request.patch(`${accountsUrl}/${root.id}/activate`, { headers: mutationHeaders });
  expect(activate.status()).toBe(403);

  const unchanged = await page.request.get(`${accountsUrl}/${root.id}`, { headers: statefulHeaders() });
  expect(unchanged.status()).toBe(200);
  expect((await unchanged.json()).data).toMatchObject({ code: '1000', name: 'Assets', is_active: true });
  const absent = await page.request.get(`${accountsUrl}?search=989999&per_page=10`, {
    headers: statefulHeaders(),
  });
  expect(absent.status()).toBe(200);
  expect((await absent.json()).data).toEqual([]);
  expect(net.failedRequests).toEqual([]);
  expect(net.errors).toEqual([]);
  net.stop();
});

test('Employee cannot view the Chart of Accounts through UI or API', async ({ page }) => {
  const employee = ROLES.find((candidate) => candidate.slug === 'employee')!;
  await loginAs(page, employee.email, employee.password);
  const net = captureNetwork(page);

  await page.goto('/accounting/coa');
  await expect(page.getByText('Page not found', { exact: true })).toBeVisible();

  const accounts = await page.request.get(`${baseUrl()}/api/v1/accounts?search=1000`, {
    headers: statefulHeaders(),
  });
  expect(accounts.status()).toBe(403);
  expect((await accounts.json()).message).toMatch(/permission/i);
  const auditLogs = await page.request.get(`${baseUrl()}/api/v1/admin/audit-logs`, {
    headers: statefulHeaders(),
  });
  expect(auditLogs.status()).toBe(403);
  expect(net.failedRequests).toEqual([]);
  expect(net.errors).toEqual([]);
  net.stop();
});
