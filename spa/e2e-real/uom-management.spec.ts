import { expect, test } from '@playwright/test';
import { baseUrl, captureNetwork, loginAs, relevantErrors, ROLES, statefulCsrfHeaders, statefulHeaders } from './helpers';

test('system admin manages UOM catalog and item conversions through the real UI', async ({ page }) => {
  test.setTimeout(180_000);

  const admin = ROLES.find(({ slug }) => slug === 'system_admin')!;
  await loginAs(page, admin.email, admin.password);
  const network = captureNetwork(page);
  const code = `E2E${Date.now().toString().slice(-9)}`;
  const name = `Audit unit ${code}`;
  const updatedName = `${name} updated`;
  let createdUnitId: string | undefined;
  let itemId: string | undefined;
  let latestRunId: string | null = null;

  const latestMrpRun = async () => {
    const response = await page.request.get(`${baseUrl()}/api/v1/mrp/runs/latest`, {
      headers: statefulHeaders(),
    });
    expect(response.status()).toBe(200);
    return (await response.json() as { data: {
      id: string;
      status: string;
      summary: { trigger_reason?: string };
    } | null }).data;
  };

  const waitForUomReplan = async () => {
    await expect.poll(async () => {
      const run = await latestMrpRun();
      return run?.id !== latestRunId
        && run?.status === 'completed'
        && run.summary.trigger_reason === 'uom_conversion_changed';
    }, { timeout: 60_000, intervals: [250, 500, 1000] }).toBe(true);

    const run = await latestMrpRun();
    if (!run) throw new Error('Completed MRP replan was not returned.');
    latestRunId = run.id;
  };

  try {
    await page.goto('/inventory/uoms');
    await expect(page.getByRole('heading', { name: 'Units of Measure' })).toBeVisible();
    await page.getByRole('button', { name: 'New unit', exact: true }).click();

    const createDialog = page.getByRole('dialog', { name: 'New unit' });
    await createDialog.getByLabel('Code').fill(code);
    await createDialog.getByLabel('Name').fill(name);
    await createDialog.getByRole('button', { name: 'Create unit' }).click();

    const row = () => page.getByRole('row').filter({ hasText: code });
    await expect(row()).toContainText('Active');
    let unitsResponse = await page.request.get(`${baseUrl()}/api/v1/inventory/uoms`, {
      headers: statefulHeaders(),
    });
    expect(unitsResponse.status()).toBe(200);
    let units = (await unitsResponse.json() as { data: Array<{
      id: string;
      code: string;
      name: string;
      deleted_at: string | null;
    }> }).data;
    let unit = units.find((candidate) => candidate.code === code);
    expect(unit?.name).toBe(name);
    expect(unit?.deleted_at).toBeNull();
    createdUnitId = unit?.id;

    await page.getByRole('button', { name: `Edit ${code}` }).click();
    const editDialog = page.getByRole('dialog', { name: `Edit ${code}` });
    await editDialog.getByLabel('Name').fill(updatedName);
    await editDialog.getByRole('button', { name: 'Save changes' }).click();
    await expect(row()).toContainText(updatedName);

    unitsResponse = await page.request.get(`${baseUrl()}/api/v1/inventory/uoms`, {
      headers: statefulHeaders(),
    });
    units = (await unitsResponse.json() as { data: typeof units }).data;
    unit = units.find((candidate) => candidate.code === code);
    expect(unit?.name).toBe(updatedName);

    const itemsResponse = await page.request.get(`${baseUrl()}/api/v1/inventory/items?search=PKG-001&per_page=100`, {
      headers: statefulHeaders(),
    });
    expect(itemsResponse.status()).toBe(200);
    const items = (await itemsResponse.json() as { data: Array<{
      id: string;
      code: string;
      unit_of_measure: string;
    }> }).data;
    const item = items.find((candidate) => candidate.code === 'PKG-001');
    expect(item, 'the seeded packaging item is available').toBeTruthy();
    itemId = item!.id;
    const baseUnit = units.find(({ code: unitCode }) =>
      unitCode.toUpperCase() === item!.unit_of_measure.toUpperCase());
    expect(baseUnit, 'item base unit is present in the active catalog').toBeTruthy();
    latestRunId = (await latestMrpRun())?.id ?? null;

    await page.goto(`/inventory/items/${item!.id}`);
    await expect(page.getByRole('heading', { name: /PKG-001/ })).toBeVisible();
    await page.getByRole('button', { name: 'Add conversion' }).click();
    const conversionDialog = page.getByRole('dialog', { name: 'Add unit conversion' });
    const sourceUnit = conversionDialog.getByRole('combobox', { name: /Source unit/ });
    await expect(sourceUnit).toHaveCount(1);
    await sourceUnit.selectOption({ value: unit!.id });
    await conversionDialog.getByLabel('Base units per source unit').fill('2.5');
    await conversionDialog.getByRole('button', { name: 'Add conversion' }).click();

    const conversionRow = () => page.getByRole('row').filter({ hasText: code });
    await expect(conversionRow()).toContainText(`1 ${code} = 2.500000 ${baseUnit!.code}`);
    let conversionsResponse = await page.request.get(
      `${baseUrl()}/api/v1/inventory/items/${item!.id}/uom-conversions`,
      { headers: statefulHeaders() },
    );
    expect(conversionsResponse.status()).toBe(200);
    let conversions = (await conversionsResponse.json() as { data: Array<{
      id: string;
      from_uom: { code: string };
      factor: string;
    }> }).data;
    let conversion = conversions.find((candidate) => candidate.from_uom.code === code);
    expect(Number(conversion?.factor)).toBe(2.5);
    await waitForUomReplan();

    await page.getByRole('button', { name: `Edit ${code} conversion` }).click();
    const conversionEditDialog = page.getByRole('dialog', { name: `Edit ${code} conversion` });
    await conversionEditDialog.getByLabel('Base units per source unit').fill('3.25');
    await conversionEditDialog.getByRole('button', { name: 'Save changes' }).click();
    await expect(conversionRow()).toContainText(`1 ${code} = 3.250000 ${baseUnit!.code}`);

    conversionsResponse = await page.request.get(
      `${baseUrl()}/api/v1/inventory/items/${item!.id}/uom-conversions`,
      { headers: statefulHeaders() },
    );
    conversions = (await conversionsResponse.json() as { data: typeof conversions }).data;
    conversion = conversions.find((candidate) => candidate.from_uom.code === code);
    expect(Number(conversion?.factor)).toBe(3.25);
    await waitForUomReplan();

    await page.getByRole('button', { name: `Remove ${code} conversion` }).click();
    const removeDialog = page.getByRole('dialog', { name: 'Remove conversion?' });
    await removeDialog.getByRole('button', { name: 'Remove conversion' }).click();
    await expect(page.getByText('No alternate units configured')).toBeVisible();

    conversionsResponse = await page.request.get(
      `${baseUrl()}/api/v1/inventory/items/${item!.id}/uom-conversions`,
      { headers: statefulHeaders() },
    );
    conversions = (await conversionsResponse.json() as { data: typeof conversions }).data;
    expect(conversions.some((candidate) => candidate.from_uom.code === code)).toBe(false);
    await waitForUomReplan();

    await page.goto('/inventory/uoms');
    await page.getByRole('button', { name: `Archive ${code}` }).click();
    const archiveDialog = page.getByRole('dialog', { name: 'Archive unit?' });
    await archiveDialog.getByRole('button', { name: 'Archive' }).click();
    await expect(row()).toHaveCount(0);

    unitsResponse = await page.request.get(`${baseUrl()}/api/v1/inventory/uoms`, {
      headers: statefulHeaders(),
    });
    units = (await unitsResponse.json() as { data: typeof units }).data;
    expect(units.some((candidate) => candidate.code === code)).toBe(false);
    unitsResponse = await page.request.get(`${baseUrl()}/api/v1/inventory/uoms?trashed=only`, {
      headers: statefulHeaders(),
    });
    expect(unitsResponse.status()).toBe(200);
    units = (await unitsResponse.json() as { data: typeof units }).data;
    unit = units.find((candidate) => candidate.code === code);
    expect(unit?.deleted_at).toBeTruthy();

    await page.getByRole('radio', { name: 'Show archived records only' }).click();
    await expect(row()).toContainText('Archived');
    await page.getByRole('button', { name: `Restore ${code}` }).click();
    const restoreDialog = page.getByRole('dialog', { name: 'Restore unit?' });
    await restoreDialog.getByRole('button', { name: 'Restore' }).click();
    await expect(row()).toHaveCount(0);
    await page.getByRole('radio', { name: 'Show active records' }).click();
    await expect(row()).toContainText('Active');

    unitsResponse = await page.request.get(`${baseUrl()}/api/v1/inventory/uoms`, {
      headers: statefulHeaders(),
    });
    units = (await unitsResponse.json() as { data: typeof units }).data;
    unit = units.find((candidate) => candidate.code === code);
    expect(unit?.deleted_at).toBeNull();
  } finally {
    try {
      if (createdUnitId && itemId) {
        const response = await page.request.get(
          `${baseUrl()}/api/v1/inventory/items/${itemId}/uom-conversions`,
          { headers: statefulHeaders() },
        );
        if (response.ok()) {
          const conversions = (await response.json() as { data: Array<{
            id: string;
            from_uom: { code: string };
          }> }).data;
          const createdConversion = conversions.find(({ from_uom }) => from_uom.code === code);
          if (createdConversion) {
            const deleteResponse = await page.request.delete(
              `${baseUrl()}/api/v1/inventory/items/${itemId}/uom-conversions/${createdConversion.id}`,
              { headers: await statefulCsrfHeaders(page) },
            );
            expect([204, 404]).toContain(deleteResponse.status());
          }
        }
      }
      if (createdUnitId) {
        const deleteResponse = await page.request.delete(
          `${baseUrl()}/api/v1/inventory/uoms/${createdUnitId}`,
          { headers: await statefulCsrfHeaders(page) },
        );
        expect([204, 404]).toContain(deleteResponse.status());
      }
    } finally {
      network.stop();
    }
  }

  expect(network.failedRequests, 'failed API requests').toEqual([]);
  expect(relevantErrors(network)).toEqual([]);
});
