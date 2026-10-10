import { expect, test } from '@playwright/test';
import { loginAs } from './helpers-extended';

test('delivery assignment distinguishes lookup outage from no available drivers and recovers', async ({ page }) => {
  let unavailable = true;
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  await page.route('**/api/v1/supply-chain/deliveries/options', route => route.fulfill({
    json: { data: { statuses: [], attempt_outcome_reasons: [] } },
  }));
  await page.route('**/api/v1/supply-chain/deliveries/proofs/options', route => route.fulfill({ json: { data: { proof_types: [] } } }));
  await page.route('**/api/v1/supply-chain/deliveries/deliveryRecovery', route => route.fulfill({
    json: { data: {
      id: 'deliveryRecovery', delivery_number: 'DR-RECOVERY', status: 'scheduled',
      items: [], proofs: [], vehicle: null, driver: null, sales_order: null,
      scheduled_date: '2026-10-20', coc_handoff: null, invoice_handoff: null,
    } },
  }));
  await page.route('**/api/v1/supply-chain/vehicles?*', route => route.fulfill({
    json: { data: [{ id: 'vehicleOne', name: 'Delivery truck', plate_number: 'ABC-123' }] },
  }));
  await page.route('**/api/v1/supply-chain/deliveries/driver-options', route => route.fulfill(unavailable
    ? { status: 503, json: { message: 'Driver lookup unavailable' } }
    : { json: { data: [{ id: 'driverOne', name: 'Assigned driver' }] } }));

  await loginAs(page, 'admin', '/supply-chain/deliveries/deliveryRecovery');
  await page.getByRole('button', { name: 'Assign driver & vehicle' }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Could not load delivery drivers' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Save assignment' })).toBeDisabled();
  unavailable = false;
  await page.getByRole('button', { name: 'Retry drivers' }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Could not load delivery drivers' })).toBeHidden();
  await page.getByRole('combobox', { name: 'Vehicle' }).selectOption('vehicleOne');
  await page.getByRole('combobox', { name: 'Driver' }).selectOption('driverOne');
  await page.getByLabel('Assignment reason').fill('Scheduled delivery assignment');
  await expect(page.getByRole('button', { name: 'Save assignment' })).toBeEnabled();
});

test('failed delivery workflow options are visible and can be retried', async ({ page }) => {
  let unavailable = true;
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  await page.route('**/api/v1/supply-chain/deliveries/deliveryRecovery', route => route.fulfill({
    json: { data: {
      id: 'deliveryRecovery', delivery_number: 'DR-RECOVERY', status: 'in_transit',
      items: [], proofs: [], vehicle: null, driver: null, sales_order: null,
    } },
  }));
  await page.route('**/api/v1/supply-chain/deliveries/options', route => route.fulfill(unavailable
    ? { status: 503, json: { message: 'Workflow options unavailable' } }
    : { json: { data: { statuses: [{ value: 'in_transit', label: 'In transit', next_status: 'delivered' }] } } }));
  await page.route('**/api/v1/supply-chain/deliveries/proofs/options', route => route.fulfill({ json: { data: { proof_types: [] } } }));
  await loginAs(page, 'admin', '/supply-chain/deliveries/deliveryRecovery');
  await expect(page.getByRole('alert').filter({ hasText: 'Could not load delivery workflow options' })).toBeVisible();
  unavailable = false;
  await page.getByRole('button', { name: 'Retry delivery options' }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Could not load delivery workflow options' })).toBeHidden();
  await expect(page.getByRole('button', { name: 'Mark delivered' })).toBeVisible();
});
