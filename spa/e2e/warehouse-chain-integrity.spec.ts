import { expect, test } from '@playwright/test';
import { loginAs, mockAuth, ROLES } from './helpers-extended';

test('warehouse map fits phone with many warehouses and mixed bin units', async ({ page }) => {
  await page.route('**/api/v1/**', (route) => route.fulfill({ json: { data: [] } }));
  await page.route('**/api/v1/inventory/warehouse-map*', (route) =>
    route.fulfill({
      json: {
        data: Array.from({ length: 12 }, (_, index) => ({
          id: `wh-${index}`,
          code: `WH-${index}`,
          name: `Production warehouse ${index}`,
          zones: [
            {
              id: `zone-${index}`,
              code: 'A',
              name: 'Storage',
              locations: [
                {
                  id: `bin-${index}`,
                  code: 'A-01',
                  full_code: 'WH-A-01',
                  current_item: null,
                  current_quantity: '100.000',
                  stock_status: 'ok',
                  stock_status_label: 'In stock',
                  is_blocked: false,
                },
              ],
            },
          ],
        })),
      },
    }),
  );
  await page.setViewportSize({ width: 390, height: 844 });
  await loginAs(page, 'admin', '/inventory/warehouse-map');
  await page.getByRole('button', { name: /A Storage/ }).click();
  await expect(page.getByText('Multiple items', { exact: true })).toBeVisible();
  await expect(page.getByRole('combobox', { name: 'Warehouse' })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
  await page.screenshot({ path: '/tmp/warehouse-map-polished-mobile.png', fullPage: true });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.screenshot({ path: '/tmp/warehouse-map-polished-desktop.png', fullPage: true });
  await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
  await page.screenshot({ path: '/tmp/warehouse-map-polished-dark.png', fullPage: true });
});

test('count-only user does not load restricted warehouse map or navigate into it', async ({
  page,
}) => {
  let mapRequests = 0;
  await page.route('**/api/v1/**', (route) => route.fulfill({ json: { data: [] } }));
  await page.route('**/api/v1/inventory/warehouse-map*', (route) => {
    mapRequests++;
    return route.fulfill({ status: 403, json: { message: 'Forbidden' } });
  });
  await mockAuth(page, {
    ...ROLES.warehouse,
    permissions: ROLES.warehouse.permissions.filter(
      (permission) => permission !== 'inventory.warehouse.manage',
    ),
  });
  await page.goto('/inventory/stock-count');
  await expect(page.getByRole('button', { name: 'New session', exact: true })).toBeVisible();
  await expect(page.getByRole('radio', { name: 'Map', exact: true })).toHaveCount(0);
  expect(mapRequests).toBe(0);
  expect(page.url()).toContain('/inventory/stock-count');
});

test('stock levels distinguish usable quantities from expired and held physical stock', async ({
  page,
}) => {
  await page.route('**/api/v1/**', (route) => route.fulfill({ json: { data: [] } }));
  await page.route('**/api/v1/inventory/stock-levels*', (route) =>
    route.fulfill({
      json: {
        data: [
          {
            item: {
              id: 'resin',
              code: 'RM-EXPIRED',
              name: 'Traceable resin',
              unit_of_measure: 'kg',
            },
            location: { full_code: 'WH-A-01' },
            quantity: '10.000',
            reserved_quantity: '1.000',
            available: '9.000',
            usable_quantity: '4.000',
            expired_quantity: '3.000',
            quality_held_quantity: '2.000',
            weighted_avg_cost: '5.0000',
            total_value: '50.00',
          },
        ],
        meta: { current_page: 1, per_page: 50, total: 1, last_page: 1 },
      },
    }),
  );
  await loginAs(page, 'warehouse', '/inventory/stock-levels');
  await expect(page.getByRole('columnheader', { name: 'Usable now', exact: true })).toBeVisible();
  const row = page.getByRole('row').filter({ hasText: 'RM-EXPIRED' });
  await expect(row.getByText('4.000', { exact: true })).toBeVisible();
  await expect(row.getByText('3.000 expired', { exact: true })).toBeVisible();
  await expect(row.getByText('2.000 QC held', { exact: true })).toBeVisible();
  await expect(row.getByText('10.000 kg', { exact: true })).toBeVisible();
});
