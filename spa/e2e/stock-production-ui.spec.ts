import { test, expect } from '@playwright/test';
import { mockAuth } from './helpers';
for (const width of [390, 1440]) {
  test(`stock production validation at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 1000 });
    await page.clock.setFixedTime(new Date('2026-10-09T17:30:00Z'));
    await page.route('**/api/v1/**', (route) =>
      route.fulfill({ json: { data: [], meta: { total: 0, unread_count: 0 } } }),
    );
    await mockAuth(page, {
      id: 'ppc-ui',
      name: 'PPC',
      email: 'ppc@test.test',
      roleSlug: 'ppc_head',
      roleName: 'PPC Head',
      permissions: ['production.work_orders.view', 'production.wo.create', 'production.wo.confirm'],
      features: ['production', 'mrp', 'crm'],
    });
    await page.route('**/api/v1/production/work-orders/form-options', (route) =>
      route.fulfill({
        json: {
          data: {
            products: [
              {
                id: 'product',
                part_number: 'PC-001',
                name: 'Pivot Cap Cover',
                stock_production: { eligible: true, blockers: [] },
              },
              {
                id: 'blocked',
                part_number: 'PC-002',
                name: 'Needs setup',
                stock_production: { eligible: false, blockers: ['Publish QC specification.'] },
              },
            ],
            machines: [],
            molds: [],
          },
        },
      }),
    );
    await page.goto('/production/work-orders/create');
    await expect(page.getByRole('heading', { name: 'Produce for stock' })).toBeVisible();
    await expect(page.getByText(/^14-day planning window/)).toContainText('Oct 24, 2026');
    await expect(page.getByLabel(/Target completion date/)).not.toBeVisible();
    await expect(page.getByLabel(/Number of workers/)).toHaveCount(0);
    await expect(page.getByRole('option', { name: /PC-002/ })).toBeDisabled();
    await page.getByText('Products needing setup (1)').click();
    await expect(page.getByText('PC-002: Publish QC specification.')).toBeVisible();
    await page.getByRole('combobox', { name: /^Product/ }).selectOption('product');
    await page.getByLabel('Quantity', { exact: false }).fill('1000');
    await expect(page.getByRole('button', { name: 'Plan production' })).toBeEnabled();
    await page.getByText('Products needing setup (1)').click();
    await page.screenshot({
      path: `/tmp/stock-form-polish-20261009/ui-${width}.png`,
      fullPage: true,
    });
    await page.getByText('Planning options', { exact: true }).click();
    await page.getByLabel(/Target completion date/).fill('2026-10-09');
    await expect(page.getByText('Choose today or a future date in Manila.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Plan production' })).toBeDisabled();
    await page.getByLabel(/Target completion date/).fill('');
    await expect(page.getByRole('button', { name: 'Plan production' })).toBeEnabled();
    await page.getByLabel('Quantity', { exact: false }).fill('-1');
    await expect(page.getByText('Enter a positive whole-piece quantity.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Plan production' })).toBeDisabled();
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
    ).toBeTruthy();
    await page.screenshot({
      path: `/tmp/stock-form-polish-20261009/validation-${width}.png`,
      fullPage: true,
    });
    await page.getByLabel('Quantity', { exact: false }).fill('1000');
    let payload: Record<string, unknown> | undefined;
    await page.route('**/api/v1/production/work-orders', async (route) => {
      payload = route.request().postDataJSON();
      await route.fulfill({ status: 500, json: { message: 'Test connection interrupted.' } });
    });
    await page.getByRole('button', { name: 'Plan production' }).click();
    await expect(page.getByRole('alert')).toContainText('Test connection interrupted.');
    expect(payload).toMatchObject({
      quantity_target: 1000,
      production_purpose: 'stock',
      planned_end: '2026-10-24T23:59:59+08:00',
      minimum_workers: 1,
      maximum_workers: 1,
    });
    await page.route('**/api/v1/production/work-orders', (route) =>
      route.fulfill({ json: { data: { id: 'stock-test' } } }),
    );
    await page.route('**/api/v1/production/work-orders/stock-test', (route) =>
      route.fulfill({
        json: {
          data: {
            id: 'stock-test',
            wo_number: 'WO-STOCK-TEST',
            production_purpose: 'stock',
            status: 'planned',
            status_label: 'Planned',
            product: { id: 'product', part_number: 'PC-001', name: 'Pivot Cap Cover' },
            quantity_target: 1000,
            quantity_produced: 0,
            quantity_good: 0,
            quantity_rejected: 0,
            progress_percentage: 0,
            scrap_rate: '0.00',
            planned_start: '2026-10-09T17:30:00Z',
            planned_end: '2026-10-24T15:59:59Z',
            materials: [],
            outputs: [],
            inspections: [],
            children: [],
            next_statuses: [],
            material_lot_references: [],
            sales_order: null,
            machine: null,
            mold: null,
          },
        },
      }),
    );
    await page.route('**/api/v1/production/work-orders/stock-test/crew', (route) =>
      route.fulfill({
        json: {
          data: {
            current: null,
            history: [],
            can_assign: true,
            minimum_workers: 1,
            maximum_workers: 1,
          },
        },
      }),
    );
    let proposals = 0;
    await page.route(
      '**/api/v1/production/work-orders/stock-test/assignment-suggestion',
      (route) => {
        proposals++;
        return route.fulfill({ status: 422, json: { message: 'Review equipment availability.' } });
      },
    );
    await page.getByRole('button', { name: 'Plan production' }).click();
    await expect(page).toHaveURL(/\/production\/work-orders\/stock-test$/);
    await expect(page.getByRole('heading', { name: /WO-STOCK-TEST/ })).toBeVisible();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    expect(proposals).toBe(0);
    await page.getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(
      page.getByRole('heading', { name: 'Review and confirm work order' }),
    ).toBeVisible();
  });
}
