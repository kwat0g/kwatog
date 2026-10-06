import { expect, test } from '@playwright/test';
import { loginAs } from './helpers-extended';

test('scanner bin link selects its warehouse, zone and bin, then retries detail', async ({ page }) => {
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  const bin = { id: 'bin-1', code: 'A1', full_code: 'MAIN-A1', stock_status: 'empty', current_quantity: '0' };
  await page.route('**/api/v1/inventory/warehouse-map', route => route.fulfill({ json: { data: [{ id: 'wh-1', name: 'Main', code: 'MAIN', zones: [{ id: 'zone-1', name: 'Storage', code: 'A', locations: [bin] }] }] } }));
  let unavailable = true;
  await page.route('**/api/v1/inventory/warehouse-map/bins/bin-1', route => route.fulfill(unavailable
    ? { status: 503, json: { message: 'Bin unavailable' } }
    : { json: { data: { location: bin, stock_levels: [], last_movement: null } } }));
  await loginAs(page, 'admin', '/inventory/warehouse-map?location_id=bin-1');
  await expect(page.getByText('Bin: MAIN-A1', { exact: true })).toBeVisible();
  await expect(page.getByRole('alert').filter({ hasText: 'Bin stock details could not be loaded' })).toBeVisible();
  unavailable = false;
  await page.getByRole('button', { name: 'Retry bin details', exact: true }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Bin stock details could not be loaded' })).toBeHidden();
});

test('scanner picking link selects its slip and recovers failed picking list', async ({ page }) => {
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  await page.route('**/api/v1/inventory/material-issues?**', route => route.fulfill({ json: {
    data: [{ id: 'slip-1', slip_number: 'MIS-1', created_at: '2026-10-04T00:00:00Z' }],
    meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
  } }));
  let unavailable = true;
  await page.route('**/api/v1/inventory/picking-lists/mis/slip-1', route => route.fulfill(unavailable
    ? { status: 503, json: { message: 'Picking unavailable' } }
    : { json: { data: { slip_number: 'MIS-1', total_lines: 0, total_items: 0, lines: [] } } }));
  await loginAs(page, 'admin', '/inventory/picking?material_issue_id=slip-1');
  await expect(page.getByRole('alert').filter({ hasText: 'Picking list could not be loaded' })).toBeVisible();
  unavailable = false;
  await page.getByRole('button', { name: 'Retry picking list', exact: true }).click();
  await expect(page.getByText('Picking: MIS-1', { exact: true })).toBeVisible();
});

test('scanner options fail visibly and allow retry before resolving', async ({ page }) => {
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  let unavailable = true;
  await page.route('**/api/v1/inventory/scan/options', route => route.fulfill(unavailable
    ? { status: 503, json: { message: 'Scan options unavailable' } }
    : { json: { data: { contexts: [{ value: 'lookup', label: 'Lookup' }] } } }));
  await loginAs(page, 'admin', '/inventory/scanner');
  await expect(page.getByRole('alert').filter({ hasText: 'Scanner options could not be loaded' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Resolve', exact: true })).toBeDisabled();
  unavailable = false;
  await page.getByRole('button', { name: 'Retry scanner options', exact: true }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Scanner options could not be loaded' })).toBeHidden();
});
