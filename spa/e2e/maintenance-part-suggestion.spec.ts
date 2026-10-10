import { expect, test } from '@playwright/test';
import { mockAuth, ROLES } from './helpers-extended';

for (const width of [1280, 390]) {
  test(`target parts and usable stock at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await mockAuth(page, ROLES.maintenance);
    await page.route('**/api/v1/maintenance/work-orders/options', (route) => route.fulfill({ json: { data: { types: [], priorities: [], statuses: [] } } }));
    await page.route('**/api/v1/maintenance/work-orders/part-work', (route) => route.fulfill({ json: { data: {
      id: 'part-work', mwo_number: 'MWO-PART-1', maintainable_type: 'machine', type: 'corrective', priority: 'high',
      status: 'in_progress', available_actions: ['complete'], verification_required: false, cost: '0.00', description: 'Repair motor',
      maintainable: { id: 'machine-1', code: 'IMM-01', name: 'Test machine' }, assignee: null,
      logs: [], spare_parts: [], started_at: null, completed_at: null, downtime_minutes: 0,
    } } }));
    await page.route('**/api/v1/maintenance/spare-part-options*', (route) => {
      expect(new URL(route.request().url()).searchParams.get('work_order_id')).toBe('part-work');
      return route.fulfill({ json: { data: [{ id: 'part-1', code: 'BEARING', name: 'Usual bearing', unit_of_measure: 'PCS', history_count: 3, stock_status: 'available', stock: [{ location: { id: 'bin-1', code: 'A-01' }, available: '2.000' }] }] } });
    });
    await page.route('**/api/v1/maintenance/spare-part-stock*', (route) => route.fulfill({ json: { data: [{ location: { id: 'bin-1', code: 'A-01' }, available: '2.000' }] } }));
    await page.goto('/maintenance/work-orders/part-work');
    await page.getByRole('button', { name: width < 768 ? 'Add' : 'Add part', exact: true }).click();
    await expect(page.getByText(/Used in 3 completed repairs/)).toBeVisible();
    await page.getByRole('button', { name: /Usual bearing/ }).click();
    await page.getByLabel('Source location').selectOption('bin-1');
    await page.getByLabel('Quantity (PCS)').fill('3');
    await expect(page.getByRole('dialog').getByRole('button', { name: 'Add part', exact: true })).toBeDisabled();
    await page.getByLabel('Quantity (PCS)').fill('2');
    await expect(page.getByRole('dialog').getByRole('button', { name: 'Add part', exact: true })).toBeEnabled();
  });
}
