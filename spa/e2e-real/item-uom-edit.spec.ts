import { expect, test } from '@playwright/test';
import { baseUrl, captureNetwork, loginAs, relevantErrors, ROLES, statefulHeaders } from './helpers';

test('item editor selects the catalog UOM for a lower-case stored base unit', async ({ page }) => {
  const admin = ROLES.find(({ slug }) => slug === 'system_admin')!;
  await loginAs(page, admin.email, admin.password);
  const network = captureNetwork(page);

  try {
    const [itemsResponse, uomsResponse] = await Promise.all([
      page.request.get(`${baseUrl()}/api/v1/inventory/items?search=RM-001&per_page=100`, { headers: statefulHeaders() }),
      page.request.get(`${baseUrl()}/api/v1/inventory/uoms`, { headers: statefulHeaders() }),
    ]);
    expect(itemsResponse.status()).toBe(200);
    expect(uomsResponse.status()).toBe(200);
    const items = (await itemsResponse.json() as {
      data: Array<{ id: string; code: string; unit_of_measure: string }>;
    }).data;
    const uoms = (await uomsResponse.json() as { data: Array<{ code: string }> }).data;
    const item = items.find((candidate) => candidate.code === 'RM-001');
    expect(item, 'reference data contains the classified raw-material fixture').toBeTruthy();
    expect(item!.unit_of_measure).toBe('kg');
    expect(uoms.some((uom) =>
      uom.code.toUpperCase() === item!.unit_of_measure.toUpperCase()
      && uom.code !== item!.unit_of_measure,
    ), 'the fixture stores a lower-case base UOM matching the catalog').toBe(true);
    const canonicalUom = uoms.find(({ code }) => code.toUpperCase() === item!.unit_of_measure.toUpperCase())!;
    const detailResponse = await page.request.get(`${baseUrl()}/api/v1/inventory/items/${item!.id}`, {
      headers: statefulHeaders(),
    });
    expect(detailResponse.status()).toBe(200);
    const detail = (await detailResponse.json() as {
      data: {
        code: string;
        category: { id: string; name: string };
        item_type: string;
        item_type_label: string;
        unit_of_measure: string;
        reorder_method: string;
      };
    }).data;
    expect(detail.unit_of_measure).toBe(item!.unit_of_measure);

    await page.goto(`/inventory/items/${item!.id}/edit`);
    await expect(page.getByRole('heading', { name: `Edit ${item!.code}` })).toBeVisible();
    await expect(page.getByRole('combobox', { name: 'Category' })).toHaveValue(/Resins$/);
    await expect(page.getByText(`${detail.item_type_label} · determined by category`)).toBeVisible();
    await expect(page.getByLabel('Base unit')).toHaveValue(canonicalUom.code);
    await expect(page.getByLabel('Reorder method')).toHaveValue(detail.reorder_method);
    await page.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(page).toHaveURL(/\/inventory\/items$/);
    expect(relevantErrors(network)).toEqual([]);
    expect(network.failedRequests).toEqual([]);
  } finally {
    network.stop();
  }
});
