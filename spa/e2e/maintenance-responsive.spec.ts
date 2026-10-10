import { expect, test } from '@playwright/test';
import { loginAs } from './helpers-extended';

test('maintenance work orders use one URL with desktop rows and phone cards', async ({ page }) => {
  await page.route('**/api/v1/maintenance/work-orders/options', (route) => route.fulfill({
    json: { data: {
      types: [{ value: 'corrective', label: 'Corrective' }],
      priorities: [{ value: 'high', label: 'High' }],
      statuses: [{ value: 'open', label: 'Open', is_terminal: false }],
    } },
  }));
  await page.route('**/api/v1/maintenance/work-orders?*', (route) => route.fulfill({
    json: {
      data: [{
        id: 'work-1', mwo_number: 'MWO-TEST-0001', type: 'corrective', priority: 'high',
        status: 'open', cost: '0.00', maintainable: { id: 'machine-1', code: 'IMM-01', name: 'Test machine' },
        assignee: null,
      }],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
    },
  }));

  await loginAs(page, 'maintenance', '/maintenance/work-orders');
  await expect(page.getByRole('table')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Mobile view' })).toHaveCount(0);

  await page.setViewportSize({ width: 390, height: 844 });
  await expect(page.getByRole('table')).toBeHidden();
  await expect(page.getByRole('link', { name: /MWO-TEST-0001/ })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

  await page.goto('/maintenance/mobile');
  await expect(page).toHaveURL(/\/maintenance\/work-orders$/);
  await expect(page.getByRole('link', { name: /MWO-TEST-0001/ })).toBeVisible();
});

test('view-only role sees work-order detail without technician actions on a phone', async ({ page }) => {
  await page.route('**/api/v1/maintenance/work-orders/work-1', (route) => route.fulfill({
    json: { data: {
      id: 'work-1', mwo_number: 'MWO-TEST-0001', maintainable_type: 'machine', type: 'corrective', priority: 'high',
      status: 'in_progress', available_actions: ['complete', 'cancel'], cost: '0.00',
      description: 'Inspect machine', maintainable: { id: 'machine-1', code: 'IMM-01', name: 'Test machine' },
      assignee: null, spare_parts: [], logs: [], started_at: null, completed_at: null,
      downtime_minutes: 0, remarks: null,
    } },
  }));
  await page.setViewportSize({ width: 390, height: 844 });
  await loginAs(page, 'production', '/maintenance/work-orders/work-1');

  await expect(page.getByText('Inspect machine')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Complete work order' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Cancel work order' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Add', exact: true })).toHaveCount(0);
});

test('technician actions remain on the canonical detail URL at both widths', async ({ page }) => {
  let partRequest: Record<string, unknown> | undefined;
  await page.route('**/api/v1/maintenance/spare-part-options?*', (route) => route.fulfill({
    json: { data: [{ id: 'part-1', code: 'SP-1', name: 'Test grease', unit_of_measure: 'KG' }] },
  }));
  await page.route('**/api/v1/maintenance/spare-part-stock?*', (route) => route.fulfill({
    json: { data: [{ location: { id: 'location-1', code: 'STOCK' }, available: '2.000' }] },
  }));
  await page.route('**/api/v1/maintenance/work-orders/work-1/spare-parts', (route) => {
    partRequest = route.request().postDataJSON() as Record<string, unknown>;
    return route.fulfill({ json: { data: { id: 'usage-1' } } });
  });
  await page.route('**/api/v1/maintenance/work-orders/options', (route) => route.fulfill({
    json: { data: {
      types: [{ value: 'corrective', label: 'Corrective' }],
      priorities: [{ value: 'high', label: 'High' }],
      statuses: [
        { value: 'open', label: 'Open', is_terminal: false },
        { value: 'in_progress', label: 'In progress', is_terminal: false },
        { value: 'completed', label: 'Completed', is_terminal: true },
      ],
    } },
  }));
  await page.route('**/api/v1/maintenance/work-orders/work-1', (route) => route.fulfill({
    json: { data: {
      id: 'work-1', mwo_number: 'MWO-TEST-0001', maintainable_type: 'machine', type: 'corrective', priority: 'high',
      status: 'in_progress', available_actions: ['complete', 'cancel'], cost: '0.00',
      description: 'Inspect machine', maintainable: { id: 'machine-1', code: 'IMM-01', name: 'Test machine' },
      assignee: null, spare_parts: [], logs: [], started_at: null, completed_at: null,
      downtime_minutes: 0, remarks: null,
    } },
  }));
  await loginAs(page, 'maintenance', '/maintenance/work-orders/work-1');
  await expect(page.getByRole('heading', { name: 'MWO-TEST-0001' })).toBeVisible();
  await expect(page.getByText('IMM-01 · Test machine')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Add part' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Complete', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Cancel', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Add part' }).click();
  const partDialog = page.getByRole('dialog');
  await partDialog.getByLabel('Search spare parts').fill('grease');
  await partDialog.getByRole('button', { name: /Test grease/ }).click();
  await partDialog.getByLabel('Source location').selectOption('location-1');
  await partDialog.getByLabel('Quantity (KG)').fill('3');
  await expect(partDialog.getByRole('button', { name: 'Add part', exact: true })).toBeDisabled();
  await partDialog.getByLabel('Quantity (KG)').fill('1');
  await partDialog.getByRole('button', { name: 'Add part', exact: true }).click();
  await expect(partDialog).toHaveCount(0);
  expect(partRequest).toMatchObject({
    item_id: 'part-1', location_id: 'location-1', quantity: '1',
    operation_key: expect.any(String),
  });

  await page.setViewportSize({ width: 390, height: 844 });
  await expect(page).toHaveURL(/\/maintenance\/work-orders\/work-1$/);
  await expect(page.getByRole('button', { name: 'Add', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Complete work order' })).toBeVisible();
  await page.getByRole('button', { name: 'Add', exact: true }).click();
  await expect(page.getByRole('dialog').getByLabel('Search spare parts')).toBeVisible();
  await page.getByRole('dialog').getByRole('button', { name: 'Close' }).click();
  await page.goto('/maintenance/mobile/work-1');
  await expect(page).toHaveURL(/\/maintenance\/work-orders\/work-1$/);
  await expect(page.getByRole('heading', { name: 'MWO-TEST-0001' })).toBeVisible();
});

test('unused spare part return validates quantity on desktop and phone', async ({ page }) => {
  const requests: Array<Record<string, unknown>> = [];
  await page.route('**/api/v1/maintenance/work-orders/work-1/spare-parts/usage-1/returns', (route) => {
    requests.push(route.request().postDataJSON() as Record<string, unknown>);
    return route.fulfill({ json: { data: { id: 'usage-1', returned_quantity: '1.000', returned_cost: '10.00' } } });
  });
  await page.route('**/api/v1/maintenance/work-orders/work-1', (route) => route.fulfill({
    json: { data: {
      id: 'work-1', mwo_number: 'MWO-TEST-0001', maintainable_type: 'machine', type: 'corrective', priority: 'high',
      status: 'in_progress', available_actions: ['complete'], cost: '20.00', description: 'Inspect machine',
      maintainable: { id: 'machine-1', code: 'IMM-01', name: 'Test machine' },
      spare_parts: [{ id: 'usage-1', item: { id: 'part-1', code: 'SP-1', name: 'Grease', unit: 'KG' },
        quantity: '2.000', lot_number: 'LOT-A', returned_quantity: '0.000', unit_cost: '10.0000', total_cost: '20.00', returned_cost: '0.00' }],
      logs: [], started_at: null, completed_at: null, downtime_minutes: 0, remarks: null,
    } },
  }));
  await loginAs(page, 'maintenance', '/maintenance/work-orders/work-1');
  await page.getByRole('button', { name: 'Return Grease from lot LOT-A' }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('Return quantity').fill('3');
  await expect(dialog.getByRole('button', { name: 'Confirm return' })).toBeDisabled();
  await dialog.getByLabel('Return quantity').fill('1');
  await dialog.getByRole('button', { name: 'Confirm return' }).click();
  await expect.poll(() => requests.length).toBe(1);
  expect(requests[0]).toMatchObject({ quantity: '1', operation_key: expect.any(String) });

  await page.setViewportSize({ width: 390, height: 844 });
  await page.getByRole('button', { name: 'Return Grease from lot LOT-A' }).click();
  await expect(page.getByRole('dialog').getByLabel('Return quantity')).toBeVisible();
});
