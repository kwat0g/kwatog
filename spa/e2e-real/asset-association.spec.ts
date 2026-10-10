import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { localIsoDate } from '../src/lib/formatDate';
import { baseUrl, captureNetwork, loginAs, relevantErrors, statefulCsrfHeaders, statefulHeaders } from './helpers';

async function readData<T>(page: Page, path: string): Promise<T> {
  const response = await page.request.get(`${baseUrl()}${path}`, { headers: statefulHeaders() });
  const body = await response.text();
  expect(response.status(), `${path}: ${body}`).toBe(200);
  return (JSON.parse(body) as { data: T }).data;
}

test('finance links a fleet vehicle from asset detail and server rejects unauthorized unlink', async ({ browser }) => {
  const suffix = Date.now().toString(36).toUpperCase();
  const plate = `AUD-${suffix}`;
  const vehicleName = `Asset audit vehicle ${suffix}`;
  const assetName = `Asset audit asset ${suffix}`;
  const contexts: BrowserContext[] = [];

  const newRolePage = async (email: string): Promise<Page> => {
    const context = await browser.newContext({ baseURL: baseUrl() });
    contexts.push(context);
    const page = await context.newPage();
    await loginAs(page, email, 'password');
    return page;
  };

  const impex = await newRolePage('impex@ogami.test');
  const finance = await newRolePage('finance@ogami.test');
  const employee = await newRolePage('employee@ogami.test');
  const network = captureNetwork(finance);

  try {
    await impex.goto('/supply-chain/fleet', { waitUntil: 'domcontentloaded' });
    await expect(impex.getByRole('heading', { name: 'Fleet' })).toBeVisible();
    await impex.getByRole('button', { name: 'New vehicle', exact: true }).click();
    const vehicleDialog = impex.getByRole('dialog');
    await vehicleDialog.getByLabel('Plate number').fill(plate);
    await vehicleDialog.getByLabel('Name').fill(vehicleName);
    await vehicleDialog.getByLabel('Type').selectOption('truck');
    await vehicleDialog.getByLabel('Capacity (kg)').fill('1000.00');
    const vehicleCreate = impex.waitForResponse((response) =>
      response.url().endsWith('/api/v1/supply-chain/vehicles') && response.request().method() === 'POST',
    );
    await vehicleDialog.getByRole('button', { name: 'Save vehicle', exact: true }).click();
    const vehicleResponse = await vehicleCreate;
    expect(vehicleResponse.status(), await vehicleResponse.text()).toBe(201);
    const vehicle = (await vehicleResponse.json()).data as { id: string; plate_number: string; asset: unknown };
    expect(vehicle).toMatchObject({ plate_number: plate });
    await expect(impex.getByText(plate, { exact: true })).toBeVisible();

    await finance.goto('/assets/create', { waitUntil: 'domcontentloaded' });
    await expect(finance.getByRole('heading', { name: 'New asset' })).toBeVisible();
    await finance.getByLabel('Name').fill(assetName);
    await finance.getByLabel('Category').selectOption('vehicle');
    await finance.getByLabel('Acquisition date').fill(localIsoDate());
    await finance.getByLabel('Acquisition cost').fill('100.00');
    await finance.getByLabel('Useful life (years)').fill('5');
    const assetCreate = finance.waitForResponse((response) =>
      response.url().endsWith('/api/v1/assets') && response.request().method() === 'POST',
    );
    await finance.getByRole('button', { name: 'Create asset', exact: true }).click();
    const assetResponse = await assetCreate;
    expect(assetResponse.status(), await assetResponse.text()).toBe(201);
    const asset = (await assetResponse.json()).data as { id: string; asset_code: string };
    await expect(finance).toHaveURL(new RegExp(`/assets/${asset.id}$`));
    await expect(finance.getByText('No operational record linked.')).toBeVisible();

    const optionsResponse = finance.waitForResponse((response) =>
      response.url().includes(`/api/v1/assets/${asset.id}/association-options`) && response.request().method() === 'GET',
    );
    await finance.getByRole('button', { name: 'Associate vehicle', exact: true }).click();
    const options = await optionsResponse;
    expect(options.status()).toBe(200);
    expect((await options.json()).data).toEqual(expect.arrayContaining([
      expect.objectContaining({ id: vehicle.id, code: plate, name: vehicleName }),
    ]));
    await finance.getByLabel('Vehicle', { exact: true }).selectOption({ label: `${plate} · ${vehicleName}` });
    const linkResponsePromise = finance.waitForResponse((response) =>
      response.url().endsWith(`/api/v1/assets/${asset.id}/association`) && response.request().method() === 'PATCH',
    );
    await finance.getByRole('button', { name: 'Save association', exact: true }).click();
    const linkResponse = await linkResponsePromise;
    expect(linkResponse.status(), await linkResponse.text()).toBe(200);
    const linked = (await linkResponse.json()).data as { association: { type: string; id: string; code: string } };
    expect(linked.association).toMatchObject({ type: 'vehicle', id: vehicle.id, code: plate });
    await expect(finance.getByText(plate, { exact: true })).toBeVisible();
    expect(await readData<{ association: { id: string } }>(
      finance,
      `/api/v1/assets/${asset.id}`,
    )).toMatchObject({ association: { id: vehicle.id } });

    const forbidden = await employee.request.patch(`${baseUrl()}/api/v1/assets/${asset.id}/association`, {
      headers: await statefulCsrfHeaders(employee),
      data: { target_type: 'vehicle', target_id: null },
    });
    expect(forbidden.status(), await forbidden.text()).toBe(403);
    expect(await readData<{ association: { id: string } }>(
      finance,
      `/api/v1/assets/${asset.id}`,
    )).toMatchObject({ association: { id: vehicle.id } });

    await finance.getByRole('button', { name: 'Clear association', exact: true }).click();
    const clearDialog = finance.getByRole('dialog', { name: 'Clear operational association?' });
    await expect(clearDialog.getByText(new RegExp(plate))).toBeVisible();
    const clearResponsePromise = finance.waitForResponse((response) =>
      response.url().endsWith(`/api/v1/assets/${asset.id}/association`) && response.request().method() === 'PATCH',
    );
    await clearDialog.getByRole('button', { name: 'Clear association', exact: true }).click();
    const clearResponse = await clearResponsePromise;
    expect(clearResponse.status(), await clearResponse.text()).toBe(200);
    await expect(finance.getByText('No operational record linked.')).toBeVisible();
    expect(await readData<{ association: unknown }>(finance, `/api/v1/assets/${asset.id}`)).toMatchObject({ association: null });

    const vehicles = await readData<Array<{ id: string; asset: unknown }>>(
      impex,
      `/api/v1/supply-chain/vehicles?search=${encodeURIComponent(plate)}&per_page=100`,
    );
    expect(vehicles).toEqual(expect.arrayContaining([expect.objectContaining({ id: vehicle.id, asset: null })]));
    expect(relevantErrors(network)).toEqual([]);
    expect(network.failedRequests.filter(({ status }) => status >= 500)).toEqual([]);
  } finally {
    network.stop();
    await Promise.all(contexts.map((context) => context.close()));
  }
});
