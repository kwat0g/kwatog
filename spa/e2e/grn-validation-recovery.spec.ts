import { expect, test } from '@playwright/test';
import { loginAs } from './helpers-extended';

test('receiving shows the specific rejected field and preserves receipt input', async ({ page }) => {
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  const po = { id: 'po-1', po_number: 'PO-1', status: 'supplier_accepted', vendor: null, items: [
    { id: 'line-1', item: { id: 'item-1', code: 'RM-1', name: 'Resin', unit_of_measure: 'kg' }, quantity: '10', quantity_remaining: '10', unit_price: '20' },
  ] };
  await page.route('**/inventory/grn/receivable-purchase-orders', route => route.fulfill({ json: { data: [po] } }));
  await page.route('**/inventory/grn/receivable-purchase-orders/po-1', route => route.fulfill({ json: { data: po } }));
  await page.route('**/inventory/warehouse', route => route.fulfill({ json: { data: [
    { id: 'wh-1', code: 'WH', name: 'Warehouse', is_active: true, zones: [{ id: 'zone-1', code: 'RM', name: 'Raw', zone_type: 'raw', locations: [{ id: 'loc-1', code: 'A', is_active: true }] }] },
  ] } }));
  await page.route('**/inventory/grn', route => route.fulfill({ status: 422, json: { message: 'Invalid receipt', errors: { 'items.0.lot_number': ['Lot number is required for this material.'] } } }));
  await loginAs(page, 'admin', '/inventory/grn/create?po_id=po-1');
  await page.getByRole('combobox', { name: 'Location', exact: true }).click();
  await page.getByRole('option', { name: 'WH-RM-A', exact: true }).click();
  await page.getByRole('button', { name: 'Create GRN', exact: true }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Create GRN', exact: true }).click();
  await expect(page.getByText('Lot number is required for this material.', { exact: true })).toBeVisible();
  await expect(page.getByRole('spinbutton', { name: 'Receive quantity' })).toHaveValue('10');
});
