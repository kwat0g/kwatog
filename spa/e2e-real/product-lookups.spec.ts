import { expect, test } from '@playwright/test';
import { baseUrl, captureNetwork, loginAs, ROLES, statefulHeaders } from './helpers';

const role = (slug: string) => ROLES.find((candidate) => candidate.slug === slug)!;

test('Production Manager mold form loads limited product options', async ({ page }) => {
  const production = role('production_manager');
  await loginAs(page, production.email, production.password);
  const net = captureNetwork(page);

  await page.goto('/mrp/molds/create');
  const product = page.getByRole('combobox', { name: 'Product' });
  await product.click();
  await expect(page.getByRole('listbox', { name: 'Product' }).getByRole('option').first()).toBeVisible();

  expect(net.failedRequests).toEqual([]);
  expect(net.errors).toEqual([]);
  net.stop();
});

test('Production Manager capability screen loads limited product options', async ({ page }) => {
  const production = role('production_manager');
  await loginAs(page, production.email, production.password);
  const net = captureNetwork(page);

  await page.goto('/quality/capability');
  const product = page.getByLabel('Product');
  await expect.poll(() => product.locator('option').count()).toBeGreaterThan(1);

  expect(net.failedRequests).toEqual([]);
  expect(net.errors).toEqual([]);
  net.stop();
});

test('QC inspection and NCR forms load product options', async ({ page }) => {
  const qc = role('qc_inspector');
  await loginAs(page, qc.email, qc.password);
  const net = captureNetwork(page);

  await page.goto('/quality/inspections/new');
  await expect.poll(() => page.locator('select[name="product_id"] option').count()).toBeGreaterThan(1);
  await page.goto('/quality/ncrs/new');
  await expect.poll(() => page.locator('select[name="product_id"] option').count()).toBeGreaterThan(1);

  expect(net.failedRequests).toEqual([]);
  expect(net.errors).toEqual([]);
  net.stop();
});

test('Production Manager can read product option fields without finance data', async ({ page }) => {
  const production = role('production_manager');
  await loginAs(page, production.email, production.password);

  const response = await page.request.get(`${baseUrl()}/api/v1/crm/products/options?is_active=true`, {
    headers: statefulHeaders(),
  });
  expect(response.status()).toBe(200);
  const body = await response.json();
  expect(body.data.length).toBeGreaterThan(0);
  expect(Object.keys(body.data[0]).sort()).toEqual(['id', 'name', 'part_number', 'unit_of_measure']);
});

test('employee cannot read product options without a product, mold, or quality permission', async ({ page }) => {
  const employee = role('employee');
  await loginAs(page, employee.email, employee.password);

  const response = await page.request.get(`${baseUrl()}/api/v1/crm/products/options`, {
    headers: statefulHeaders(),
  });
  expect(response.status()).toBe(403);
});
