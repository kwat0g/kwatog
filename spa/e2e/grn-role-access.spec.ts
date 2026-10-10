import { expect, test } from './fixtures';
import { loginAs } from './helpers-extended';

test.beforeEach(async ({ page }) => {
  await page.route('**/api/v1/inventory/grn?*', (route) => route.fulfill({
    json: {
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null },
      links: { first: '', last: '', prev: null, next: null },
    },
  }));
  await page.route('**/api/v1/inventory/grn/options', (route) => route.fulfill({
    json: { data: { statuses: [{ value: 'draft', label: 'Draft' }], default_qc_result: 'pending' } },
  }));
});

test('purchasing can view GRNs without receiving creation controls', async ({ page }) => {
  await loginAs(page, 'purchasing', '/inventory/grn');
  await expect(page.getByRole('heading', { name: 'Goods Receipt Notes', exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'No goods received yet', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'New GRN', exact: true })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Record First GRN', exact: true })).toHaveCount(0);
});

test('warehouse keeps the New GRN control for additional deliveries', async ({ page }) => {
  await loginAs(page, 'warehouse', '/inventory/grn');
  await expect(page.getByRole('heading', { name: 'Goods Receipt Notes', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'New GRN', exact: true })).toBeVisible();
});
