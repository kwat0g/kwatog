import { expect, test } from '@playwright/test';
import { baseUrl, captureNetwork, loginAs, ROLES, statefulHeaders } from './helpers';

test('Finance bill and invoice forms load the unit catalog', async ({ page }) => {
  const finance = ROLES.find((candidate) => candidate.slug === 'finance_officer')!;
  await loginAs(page, finance.email, finance.password);
  const net = captureNetwork(page);

  await page.goto('/accounting/bills/create');
  await expect(page.locator('select[name="items.0.unit"] option[value="KG"]')).toBeAttached();
  await page.goto('/accounting/invoices/create');
  await expect(page.locator('select[name="items.0.unit"] option[value="KG"]')).toBeAttached();

  expect(net.failedRequests).toEqual([]);
  expect(net.errors).toEqual([]);
  net.stop();
});

test('employee cannot read the unit catalog without a form permission', async ({ page }) => {
  const employee = ROLES.find((candidate) => candidate.slug === 'employee')!;
  await loginAs(page, employee.email, employee.password);

  const response = await page.request.get(`${baseUrl()}/api/v1/inventory/uoms`, {
    headers: statefulHeaders(),
  });
  expect(response.status()).toBe(403);
});
