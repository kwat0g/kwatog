import { expect, test } from '@playwright/test';
import { baseUrl, captureNetwork, loginAs, ROLES, statefulHeaders } from './helpers';

test('Finance creates, deactivates, and reactivates an account with an actor audit trail', async ({ page }) => {
  const finance = ROLES.find((candidate) => candidate.slug === 'finance_officer')!;
  const admin = ROLES.find((candidate) => candidate.slug === 'system_admin')!;
  await loginAs(page, finance.email, finance.password);
  const net = captureNetwork(page);

  const accountsUrl = `${baseUrl()}/api/v1/accounts`;
  const list = await page.request.get(`${accountsUrl}?per_page=200`, { headers: statefulHeaders() });
  expect(list.status()).toBe(200);
  const usedCodes = new Set<string>((await list.json()).data.map((account: { code: string }) => account.code));
  let candidate = 999999;
  while (usedCodes.has(String(candidate)) && candidate >= 900000) candidate -= 1;
  expect(candidate).toBeGreaterThanOrEqual(900000);
  const code = String(candidate);
  const name = `Audit regression ${code}`;

  await page.goto('/accounting/coa');
  await expect(page.getByRole('button', { name: 'Add account' })).toBeVisible();
  await page.getByRole('button', { name: 'Add account' }).click();
  await expect(page.getByRole('heading', { name: 'New account' })).toBeVisible();
  await page.getByLabel('Account code').fill(code);
  await page.getByLabel('Type').selectOption('expense');
  await page.getByLabel('Account name').fill(name);
  await page.getByRole('button', { name: 'Create account' }).click();
  await expect(page.getByText(`Account ${code} created.`)).toBeVisible();
  await expect(page.getByRole('button', { name: `Deactivate ${code}` })).toBeVisible();

  const createdResponse = await page.request.get(`${accountsUrl}?search=${code}&per_page=10`, {
    headers: statefulHeaders(),
  });
  expect(createdResponse.status()).toBe(200);
  const created = (await createdResponse.json()).data.find((account: { code: string }) => account.code === code);
  expect(created).toMatchObject({ code, name, type: 'expense', normal_balance: 'debit', is_active: true });

  const xsrf = (await page.context().cookies(baseUrl())).find((cookie) => cookie.name === 'XSRF-TOKEN');
  expect(xsrf).toBeTruthy();
  const ignoredStatusWrite = await page.request.put(`${accountsUrl}/${created.id}`, {
    headers: { ...statefulHeaders(), 'X-XSRF-TOKEN': decodeURIComponent(xsrf!.value) },
    data: { is_active: false },
  });
  expect(ignoredStatusWrite.status()).toBe(200);
  let current = await page.request.get(`${accountsUrl}/${created.id}`, { headers: statefulHeaders() });
  expect((await current.json()).data.is_active).toBe(true);

  await page.getByRole('button', { name: `Deactivate ${code}` }).click();
  await expect(page.getByRole('heading', { name: 'Deactivate this account?' })).toBeVisible();
  await expect(page.getByText(`${code} · ${name}`)).toBeVisible();
  await page.getByRole('button', { name: 'Deactivate', exact: true }).click();
  await expect(page.getByText(`Account ${code} deactivated.`)).toBeVisible();
  await expect(page.getByRole('button', { name: `Activate ${code}` })).toBeVisible();
  current = await page.request.get(`${accountsUrl}/${created.id}`, { headers: statefulHeaders() });
  expect((await current.json()).data.is_active).toBe(false);

  await page.getByRole('button', { name: `Activate ${code}` }).click();
  await expect(page.getByRole('heading', { name: 'Activate this account?' })).toBeVisible();
  await page.getByRole('button', { name: 'Activate', exact: true }).click();
  await expect(page.getByText(`Account ${code} activated.`)).toBeVisible();
  await expect(page.getByRole('button', { name: `Deactivate ${code}` })).toBeVisible();
  current = await page.request.get(`${accountsUrl}/${created.id}`, { headers: statefulHeaders() });
  expect((await current.json()).data.is_active).toBe(true);
  expect(net.failedRequests).toEqual([]);
  expect(net.errors).toEqual([]);
  net.stop();

  await page.context().clearCookies();
  await loginAs(page, admin.email, admin.password);
  const trailUrl = new URL(`${baseUrl()}/api/v1/admin/audit-logs/entity`);
  trailUrl.searchParams.set('model_type', 'Account');
  trailUrl.searchParams.set('model_id', created.id);
  trailUrl.searchParams.set('per_page', '100');
  const trailResponse = await page.request.get(trailUrl.toString(), { headers: statefulHeaders() });
  expect(trailResponse.status()).toBe(200);
  const trail = (await trailResponse.json()).data;
  const createdEvent = trail.find((entry: { action: string }) => entry.action === 'created');
  expect(createdEvent).toMatchObject({
    actor_type: 'user',
    user: { email: finance.email, role: { slug: 'finance_officer' } },
    new_values: { code, name },
  });
  const statusEvents = trail
    .filter((entry: { action: string; new_values?: Record<string, unknown> }) =>
      entry.action === 'updated' && Object.hasOwn(entry.new_values ?? {}, 'is_active'),
    )
    .sort((left: { created_at: string }, right: { created_at: string }) =>
      Date.parse(left.created_at) - Date.parse(right.created_at),
    );
  expect(statusEvents).toHaveLength(2);
  expect(statusEvents[0]).toMatchObject({
    actor_type: 'user',
    user: { email: finance.email },
    old_values: { is_active: true },
    new_values: { is_active: false },
  });
  expect(statusEvents[1]).toMatchObject({
    actor_type: 'user',
    user: { email: finance.email },
    old_values: { is_active: false },
    new_values: { is_active: true },
  });
});
