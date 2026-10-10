import { expect, test } from '@playwright/test';
import { baseUrl, captureNetwork, loginAs, relevantErrors, ROLES, statefulHeaders } from './helpers';

test('product editor selects the catalog UOM for a lower-case stored unit', async ({ page }) => {
  const admin = ROLES.find(({ slug }) => slug === 'system_admin')!;
  await loginAs(page, admin.email, admin.password);
  const network = captureNetwork(page);

  try {
    const [productsResponse, uomsResponse] = await Promise.all([
      page.request.get(`${baseUrl()}/api/v1/crm/products?per_page=100`, { headers: statefulHeaders() }),
      page.request.get(`${baseUrl()}/api/v1/inventory/uoms`, { headers: statefulHeaders() }),
    ]);
    expect(productsResponse.status()).toBe(200);
    expect(uomsResponse.status()).toBe(200);
    const products = (await productsResponse.json() as {
      data: Array<{ id: string; part_number: string; unit_of_measure: string }>;
    }).data;
    const uoms = (await uomsResponse.json() as { data: Array<{ code: string }> }).data;
    const product = products.find((candidate) => uoms.some((uom) =>
      uom.code.toUpperCase() === candidate.unit_of_measure.toUpperCase()
      && uom.code !== candidate.unit_of_measure,
    ));
    expect(product, 'reference data contains a lower-case product UOM').toBeTruthy();
    const canonicalUom = uoms.find(({ code }) => code.toUpperCase() === product!.unit_of_measure.toUpperCase())!;

    await page.goto(`/crm/products/${product!.id}/edit`);
    await expect(page.getByRole('heading', { name: `Edit ${product!.part_number}` })).toBeVisible();
    await expect(page.getByLabel('Unit of Measure')).toHaveValue(canonicalUom.code);
    await page.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(page).toHaveURL(/\/crm\/products$/);
    expect(relevantErrors(network)).toEqual([]);
    expect(network.failedRequests).toEqual([]);
  } finally {
    network.stop();
  }
});
