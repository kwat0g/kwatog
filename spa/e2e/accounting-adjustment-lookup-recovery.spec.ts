import { expect, test } from '@playwright/test';
import { loginAs } from './helpers-extended';

for (const form of [
  { path: '/accounting/bills/bill-1', recordEndpoint: '**/api/v1/bills/bill-1', record: { id: 'bill-1', bill_number: 'BILL-1', status: 'unpaid', balance: '100', total_amount: '100', amount_paid: '0', items: [], payments: [] }, open: 'Record payment', submit: 'Record' },
  { path: '/accounting/invoices/invoice-1', recordEndpoint: '**/api/v1/invoices/invoice-1', record: { id: 'invoice-1', invoice_number: 'INV-1', status: 'finalized', display_status: 'finalized', lifecycle_type: 'standard', balance: '100', total_amount: '100', amount_paid: '0', subtotal: '100', vat_amount: '0', items: [], collections: [] }, open: 'Record collection', submit: 'Record' },
]) {
  test(`${form.path} recovers failed cash-account lookup before payment entry`, async ({ page }) => {
    await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
    await page.route(form.recordEndpoint, route => route.fulfill({ json: { data: form.record } }));
    await page.route('**/accounting/options', route => route.fulfill({ json: { data: { payment_methods: [], credit_note_types: [], credit_note_statuses: [] } } }));
    let unavailable = true;
    await page.route('**/accounts?**', route => route.fulfill(unavailable
      ? { status: 503, json: { message: 'Accounts unavailable' } }
      : { json: { data: [] } }));
    await loginAs(page, 'admin', form.path);
    await page.getByRole('button', { name: form.open, exact: true }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('alert').filter({ hasText: 'Payment options could not be loaded' })).toBeVisible();
    await expect(dialog.getByRole('button', { name: form.submit, exact: true })).toBeDisabled();
    unavailable = false;
    await dialog.getByRole('button', { name: 'Retry payment options' }).click();
    await expect(dialog.getByRole('alert').filter({ hasText: 'Payment options could not be loaded' })).toBeHidden();
  });
}

test('stock adjustment reports unavailable policy and permits retry', async ({ page }) => {
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  let unavailable = true;
  await page.route('**/inventory/stock-adjustments/options', route => route.fulfill(unavailable
    ? { status: 503, json: { message: 'Adjustment policy unavailable' } }
    : { json: { data: {} } }));
  await loginAs(page, 'admin', '/inventory/stock-adjustments/create');
  await expect(page.getByRole('alert').filter({ hasText: 'Stock adjustment options could not be loaded' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Record adjustment', exact: true })).toBeDisabled();
  unavailable = false;
  await page.getByRole('button', { name: 'Retry options', exact: true }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Stock adjustment options could not be loaded' })).toBeHidden();
});
