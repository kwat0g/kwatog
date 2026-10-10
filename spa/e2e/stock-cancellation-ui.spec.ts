import { test, expect } from '@playwright/test';
import { mockAuth } from './helpers';
for (const width of [390, 1440]) {
  test(`stock cancellation reason and blocker at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [], meta: { total: 0, unread_count: 0 } } }));
    await mockAuth(page, { id: 'ppc-ui', name: 'PPC', email: 'ppc@test.test', roleSlug: 'ppc_head', roleName: 'PPC Head',
      permissions: ['production.work_orders.view', 'production.wo.cancel_stock'], features: ['production', 'mrp'] });
    await page.route('**/api/v1/production/work-orders/stock-test', route => route.fulfill({ json: { data: {
      id: 'stock-test', wo_number: 'WO-STOCK-TEST', production_purpose: 'stock', status: 'confirmed', status_label: 'Confirmed',
      product: { id: 'product', part_number: 'PC-001', name: 'Pivot Cap Cover' },
      quantity_target: 1000, quantity_produced: 0, quantity_good: 0, quantity_rejected: 0, progress_percentage: 0, scrap_rate: '0.00',
      actual_start: null, planned_start: '2026-10-10T01:00:00Z', planned_end: '2026-10-11T01:00:00Z',
      materials: [], outputs: [], inspections: [], children: [], next_statuses: [], material_lot_references: [], sales_order: null,
      machine: { id: 'machine', machine_code: 'IMM-03', name: 'Sumitomo' }, mold: { id: 'mold', mold_code: 'M-PC-002', name: 'Pivot Cap mold' },
    } } }));
    await page.route('**/api/v1/production/work-orders/stock-test/crew', route => route.fulfill({ json: { data: {
      current: null, history: [], can_assign: false, minimum_workers: 1, maximum_workers: 1,
      schedule: { id: 'schedule', scheduled_start: '2026-10-10T01:00:00Z', scheduled_end: '2026-10-10T02:00:00Z' },
    } } }));
    let submissions = 0;
    await page.route('**/api/v1/production/work-orders/stock-test/cancel', async route => {
      submissions++;
      expect(route.request().postDataJSON()).toEqual({ reason: 'Stock no longer needed.' });
      await route.fulfill({ status: 422, json: { message: 'Review committed purchasing PR-TEST before cancelling stock production.' } });
    });
    await page.goto('/production/work-orders/stock-test');
    await page.getByRole('button', { name: 'Cancel WO', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText('IMM-03');
    const submit = dialog.getByRole('button', { name: 'Cancel WO', exact: true });
    await expect(submit).toBeDisabled();
    await dialog.getByLabel(/Cancellation reason/).fill('   ');
    await expect(submit).toBeDisabled();
    await dialog.getByLabel(/Cancellation reason/).fill('Stock no longer needed.');
    await expect(submit).toBeEnabled();
    await submit.click();
    await expect(dialog.getByRole('alert')).toContainText('Review committed purchasing PR-TEST');
    await expect(dialog).toBeVisible();
    expect(submissions).toBe(1);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    await page.screenshot({ path: `/tmp/stock-cancel-20261009/modal-${width}.png`, fullPage: true });
    await dialog.getByRole('button', { name: 'Keep work order' }).click();
    await expect(dialog).toHaveCount(0);
  });
}
