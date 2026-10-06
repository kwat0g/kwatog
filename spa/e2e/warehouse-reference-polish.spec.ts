import { expect, test } from '@playwright/test';
import { loginAs } from './helpers-extended';

test('movements show readable WO references and debounce item/WO search', async ({ page }) => {
  await page.route('**/api/v1/**', (route) => route.fulfill({ json: { data: [] } }));
  await page.route('**/api/v1/inventory/stock-movements/options', (route) =>
    route.fulfill({ json: { data: { movement_types: [] } } }),
  );
  const searches: string[] = [];
  const row = {
    id: 'move-1',
    created_at: '2026-10-05T04:00:00+08:00',
    movement_type: 'material_issue',
    item: { id: 'item-1', code: 'RM-001', name: 'Resin', unit_of_measure: 'kg' },
    quantity: '0.945',
    unit_cost: '120',
    total_cost: '113.40',
    from_location: { id: 'bin-1', code: 'A-01' },
    to_location: null,
    gl_handoff: { status: 'generated', status_label: 'Posted to GL', message: null, at: null },
    reference_type: 'work_order',
    reference_id: 'wo-1',
    reference_label: 'WO-202610-0011',
    reference_work_order_id: 'wo-1',
    reference_url: '/production/work-orders/wo-1',
  };
  await page.route('**/api/v1/inventory/stock-movements?**', (route) => {
    const search = new URL(route.request().url()).searchParams.get('search');
    if (search) searches.push(search);
    return route.fulfill({
      json: { data: [row], meta: { total: 1, current_page: 1, last_page: 1, per_page: 50 } },
    });
  });
  await page.route('**/api/v1/inventory/items/options', (route) =>
    route.fulfill({ json: { data: { item_types: [] } } }),
  );
  await page.route('**/api/v1/inventory/stock-levels?**', (route) =>
    route.fulfill({
      json: { data: [], meta: { total: 0, current_page: 1, last_page: 1, per_page: 50 } },
    }),
  );
  await loginAs(page, 'warehouse', '/inventory/stock-levels?view=movements');
  await expect(page.getByText('WO-202610-0011', { exact: true })).toBeVisible();
  await expect(page.getByText('work_order #wo-1', { exact: true })).toHaveCount(0);
  await page
    .getByRole('textbox', { name: 'Search item, WO number, lot, or note…' })
    .fill('WO-202610-0011');
  await expect.poll(() => searches).toEqual(['WO-202610-0011']);
  await page.screenshot({ path: '/tmp/warehouse-movements-desktop.png', fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(
    true,
  );
  await page.screenshot({ path: '/tmp/warehouse-movements-mobile.png', fullPage: true });
  const stockLevelsView = page.getByRole('radio', { name: 'Stock levels', exact: true });
  await stockLevelsView.click();
  await expect(stockLevelsView).toBeChecked();
  await expect(page.getByRole('heading', { name: /^Stock levels/ })).toBeVisible();
});

test('stock levels warn about QC holds and submit debounced item search', async ({ page }) => {
  await page.route('**/api/v1/**', (route) => route.fulfill({ json: { data: [] } }));
  await page.route('**/api/v1/inventory/items/options', (route) =>
    route.fulfill({ json: { data: { item_types: [] } } }),
  );
  const searches: string[] = [];
  const stock = {
    item: { id: 'item-1', code: 'RM-001', name: 'Resin', unit_of_measure: 'kg' },
    location: { id: 'location-1', code: 'A1', full_code: 'MAIN-A1' },
    quantity: '8.000',
    reserved_quantity: '2.000',
    available: '6.000',
    usable_quantity: '3.000',
    quality_held_quantity: '3.000',
    expired_quantity: '0.000',
    weighted_avg_cost: '120.0000',
    total_value: '960.00',
    lock_version: 1,
    last_counted_at: null,
  };
  await page.route('**/api/v1/inventory/stock-levels?**', (route) => {
    const query = new URL(route.request().url()).searchParams.get('search') ?? '';
    if (query) searches.push(query);
    return route.fulfill({
      json: {
        data: query ? [] : [stock],
        meta: { total: query ? 0 : 1, current_page: 1, last_page: 1, per_page: 50 },
      },
    });
  });
  await loginAs(page, 'warehouse', '/inventory/stock-levels');
  await expect(page.getByText(/Usable stock excludes reservations, Quality holds/)).toBeVisible();
  const stockRow = page.getByRole('row').filter({ hasText: 'RM-001' });
  await expect(stockRow).toContainText('8.000');
  await expect(stockRow).toContainText('2.000');
  await expect(stockRow).toContainText('3.000 QC held');
  await page.getByRole('textbox', { name: 'Search item…' }).pressSequentially('RM-001');
  await expect.poll(() => searches).toEqual(['RM-001']);
  await expect(page.getByText('No matching stock', { exact: true })).toBeVisible();
});
