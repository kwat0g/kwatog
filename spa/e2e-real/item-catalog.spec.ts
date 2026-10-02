import { expect, test } from '@playwright/test';
import { baseUrl, captureNetwork, loginAs, ROLES, statefulHeaders } from './helpers';

const role = (slug: string) => ROLES.find((candidate) => candidate.slug === slug)!;

test('PPC BOM form loads item, product, and unit catalogs', async ({ page }) => {
  const ppc = role('ppc_head');
  await loginAs(page, ppc.email, ppc.password);
  const net = captureNetwork(page);

  await page.goto('/mrp/boms/create');
  await expect.poll(() => page.locator('select[name="product_id"] option').count()).toBeGreaterThan(1);
  await expect.poll(() => page.locator('select[name="items.0.item_id"] option').count()).toBeGreaterThan(1);
  await expect(page.locator('select[name="items.0.unit"] option[value="KG"]')).toBeAttached();

  expect(net.failedRequests).toEqual([]);
  expect(net.errors).toEqual([]);
  net.stop();
});

test('PPC can read only the item fields needed for a BOM', async ({ page }) => {
  const ppc = role('ppc_head');
  await loginAs(page, ppc.email, ppc.password);

  const response = await page.request.get(`${baseUrl()}/api/v1/inventory/items/catalog`, {
    headers: statefulHeaders(),
  });
  expect(response.status()).toBe(200);
  const body = await response.json();
  expect(body.data.length).toBeGreaterThan(0);
  expect(Object.keys(body.data[0]).sort()).toEqual(['code', 'id', 'name', 'unit_of_measure']);
});

test('employee cannot read the item catalog without an inventory or BOM permission', async ({ page }) => {
  const employee = role('employee');
  await loginAs(page, employee.email, employee.password);

  const response = await page.request.get(`${baseUrl()}/api/v1/inventory/items/catalog`, {
    headers: statefulHeaders(),
  });
  expect(response.status()).toBe(403);
});
