import { expect, test } from '@playwright/test';
import { baseUrl, captureNetwork, loginAs, ROLES, statefulHeaders } from './helpers';

const role = (slug: string) => ROLES.find((candidate) => candidate.slug === slug)!;

test('PPC BOM form loads item, product, and unit catalogs', async ({ page }) => {
  const ppc = role('ppc_head');
  await loginAs(page, ppc.email, ppc.password);
  const net = captureNetwork(page);
  const headers = statefulHeaders();
  const [productsResponse, itemsResponse] = await Promise.all([
    page.request.get(`${baseUrl()}/api/v1/crm/products/options?is_active=true`, { headers }),
    page.request.get(`${baseUrl()}/api/v1/inventory/items/catalog`, { headers }),
  ]);
  expect(productsResponse.status()).toBe(200);
  expect(itemsResponse.status()).toBe(200);
  const products = (await productsResponse.json() as {
    data: Array<{ part_number: string }>;
  }).data;
  const items = (await itemsResponse.json() as {
    data: Array<{ code: string; unit_of_measure: string }>;
  }).data;
  const unitsResponse = await page.request.get(`${baseUrl()}/api/v1/inventory/uoms`, { headers });
  expect(unitsResponse.status()).toBe(200);
  const units = (await unitsResponse.json() as { data: Array<{ code: string }> }).data;
  expect(products.length).toBeGreaterThan(0);
  expect(items.length).toBeGreaterThan(0);

  await page.goto('/mrp/boms/create');
  const product = page.getByRole('combobox', { name: 'Finished product' });
  await product.fill(products[0].part_number);
  const productOption = page.getByRole('listbox', { name: 'Finished product' })
    .getByRole('option').filter({ hasText: products[0].part_number });
  await expect(productOption).toBeVisible();
  await productOption.click();

  const item = items[0];
  const canonicalUnit = units.find((unit) => unit.code.toUpperCase() === item.unit_of_measure.toUpperCase())
    ?.code ?? item.unit_of_measure;
  const component = page.getByRole('combobox', { name: 'Component for line 1' });
  await component.fill(item.code);
  const componentOption = page.getByRole('listbox', { name: 'Component for line 1' })
    .getByRole('option').filter({ hasText: item.code });
  await expect(componentOption).toBeVisible();
  await componentOption.click();
  await expect(page.getByRole('textbox', { name: 'Unit for line 1' })).toHaveValue(canonicalUnit);

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

test('production manager can follow a BOM component link to its readable inventory item', async ({ page }) => {
  const production = role('production_manager');
  await loginAs(page, production.email, production.password);

  const listResponse = await page.request.get(`${baseUrl()}/api/v1/mrp/boms?per_page=100`, {
    headers: statefulHeaders(),
  });
  expect(listResponse.status()).toBe(200);
  const boms = (await listResponse.json() as {
    data: Array<{ id: string; item_count: number }>;
  }).data;
  const bom = boms.find((candidate) => candidate.item_count > 0);
  expect(bom).toBeTruthy();

  const detailResponse = await page.request.get(`${baseUrl()}/api/v1/mrp/boms/${bom!.id}`, {
    headers: statefulHeaders(),
  });
  expect(detailResponse.status()).toBe(200);
  const detail = (await detailResponse.json() as {
    data: { items: Array<{ item: { id: string; code: string } }> };
  }).data;
  const component = detail.items.find((line) => line.item)?.item;
  expect(component).toBeTruthy();
  const itemResponse = await page.request.get(`${baseUrl()}/api/v1/inventory/items/${component!.id}`, {
    headers: statefulHeaders(),
  });
  expect(itemResponse.status()).toBe(200);

  await page.goto(`/mrp/boms/${bom!.id}`, { waitUntil: 'domcontentloaded' });
  await expect(page.getByRole('link', { name: component!.code, exact: true }))
    .toHaveAttribute('href', `/inventory/items/${component!.id}`);
});

test('PPC can read BOM component labels without an inventory detail link', async ({ page }) => {
  const ppc = role('ppc_head');
  await loginAs(page, ppc.email, ppc.password);

  const listResponse = await page.request.get(`${baseUrl()}/api/v1/mrp/boms?per_page=100`, {
    headers: statefulHeaders(),
  });
  expect(listResponse.status()).toBe(200);
  const boms = (await listResponse.json() as {
    data: Array<{ id: string; item_count: number }>;
  }).data;
  const bom = boms.find((candidate) => candidate.item_count > 0);
  expect(bom).toBeTruthy();

  const detailResponse = await page.request.get(`${baseUrl()}/api/v1/mrp/boms/${bom!.id}`, {
    headers: statefulHeaders(),
  });
  expect(detailResponse.status()).toBe(200);
  const detail = (await detailResponse.json() as {
    data: { items: Array<{ item: { id: string; code: string } }> };
  }).data;
  const component = detail.items.find((line) => line.item)?.item;
  expect(component).toBeTruthy();
  const itemResponse = await page.request.get(`${baseUrl()}/api/v1/inventory/items/${component!.id}`, {
    headers: statefulHeaders(),
  });
  expect(itemResponse.status()).toBe(403);

  await page.goto(`/mrp/boms/${bom!.id}`, { waitUntil: 'domcontentloaded' });
  await expect(page.getByText(component!.code, { exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: component!.code, exact: true })).toHaveCount(0);
});
