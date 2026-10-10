import { expect, test } from './fixtures';
import { loginAs } from './helpers-extended';

test('warehouse can review a WO allocation and record handover on an existing issue', async ({
  page,
}) => {
  await page.route('**/api/v1/**', (route) => route.fulfill({ json: { data: [] } }));
  await page.route('**/inventory/items/options', (route) =>
    route.fulfill({ json: { data: { item_types: [] } } }),
  );
  await page.route('**/inventory/stock-movements/options', (route) =>
    route.fulfill({ json: { data: { movement_types: [] } } }),
  );
  const meta = { total: 1, current_page: 1, last_page: 1, per_page: 50, from: 1, to: 1 };
  await page.route('**/inventory/material-reservations?**', (route) =>
    route.fulfill({
      json: {
        data: [
          {
            id: 'wo-1',
            work_order: { id: 'wo-1', wo_number: 'WO-202610-0011' },
            materials: [
              {
                id: 'reservation-1',
                work_order: { id: 'wo-1', wo_number: 'WO-202610-0011' },
                item: { id: 'item-1', code: 'RM-001', name: 'Resin', unit_of_measure: 'kg' },
                location: { id: 'location-1', full_code: 'A-01' },
                quantity: '3.000',
                status: 'reserved',
                reserved_at: '2026-10-05T04:00:00+08:00',
                ended_at: null,
              },
            ],
          },
        ],
        meta,
      },
    }),
  );
  let handover: null | {
    recipient_name: string;
    quantity: string;
    handed_over_at: string;
    recorded_by: string;
  } = null;
  await page.route('**/inventory/stock-movements?**', (route) =>
    route.fulfill({
      json: {
        data: [
          {
            id: 'issue-1',
            created_at: '2026-10-05T04:00:00+08:00',
            movement_type: 'material_issue',
            item: { id: 'item-1', code: 'RM-001', name: 'Resin', unit_of_measure: 'kg' },
            quantity: '3.000',
            unit_cost: '1',
            total_cost: '3',
            from_location: { id: 'location-1', code: 'A-01' },
            to_location: null,
            reference_type: 'work_order',
            reference_id: 'wo-1',
            reference_label: 'WO-202610-0011',
            reference_url: '/production/work-orders/wo-1',
            handover,
          },
        ],
        meta,
      },
    }),
  );
  await page.route('**/stock-movements/issue-1/handover-options', (route) =>
    route.fulfill({
      json: {
        data: {
          quantity: '3.000',
          recipients: [{ value: 'employee-1', label: 'Catherine Rivera' }],
        },
      },
    }),
  );
  await page.route('**/stock-movements/issue-1/handover', (route) => {
    expect(route.request().postDataJSON()).toEqual({
      received_by_employee_id: 'employee-1',
      expected_quantity: '3.000',
    });
    handover = {
      recipient_name: 'Catherine Rivera',
      quantity: '3.000',
      handed_over_at: '2026-10-05T04:05:00+08:00',
      recorded_by: 'Warehouse inspector',
    };
    return route.fulfill({ json: { data: handover } });
  });
  await loginAs(page, 'warehouse', '/inventory/stock-levels?view=reservations');
  await expect(page.getByRole('heading', { name: 'Material reservations' })).toBeVisible();
  await expect(page.getByText('WO-202610-0011', { exact: true })).toBeVisible();
  await expect(page.getByText('1 material', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Expand row' }).click();
  await expect(page.getByText('3.000 kg', { exact: true })).toBeVisible();
  await page.getByRole('radio', { name: 'Movements', exact: true }).click();
  await page.getByRole('button', { name: 'Expand row' }).last().click();
  await page.getByRole('button', { name: 'Record handover' }).click();
  await page.getByRole('combobox', { name: 'Received by' }).click();
  await page.getByRole('option', { name: 'Catherine Rivera' }).click();
  await page.getByRole('button', { name: 'Confirm handover' }).click();
  await expect(page.getByText('Handed to Catherine Rivera')).toBeVisible();
  await page.locator('div.overflow-x-auto').evaluateAll((tables) =>
    tables.forEach((table) => {
      table.scrollLeft = 0;
    }),
  );
  await page.screenshot({ path: '/tmp/warehouse-followup-desktop.png', fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.screenshot({ path: '/tmp/warehouse-followup-mobile.png', fullPage: true });
});

test('scanner context uses searchable document numbers', async ({ page }) => {
  await page.route('**/api/v1/**', (route) => route.fulfill({ json: { data: [] } }));
  await page.route('**/inventory/scan/options', (route) =>
    route.fulfill({
      json: {
        data: {
          contexts: [
            { value: 'lookup', label: 'General lookup' },
            { value: 'wo_id', label: 'Work order issue' },
          ],
        },
      },
    }),
  );
  const searches: string[] = [];
  await page.route('**/inventory/scan/records?**', (route) => {
    searches.push(new URL(route.request().url()).searchParams.get('search') ?? '');
    return route.fulfill({ json: { data: [{ value: 'wo-1', label: 'WO-202610-0011' }] } });
  });
  await page.route('**/inventory/scan/resolve', (route) => {
    expect(route.request().postDataJSON()).toEqual({
      barcode: 'RM-001',
      context: { wo_id: 'wo-1' },
    });
    return route.fulfill({
      json: { data: { type: 'item', entity: { code: 'RM-001' }, suggested_actions: [] } },
    });
  });
  await loginAs(page, 'warehouse', '/inventory/scanner');
  await page.getByLabel('Workflow').selectOption('wo_id');
  await page.getByRole('combobox', { name: 'Context record' }).fill('WO-202610');
  await expect.poll(() => searches.filter(Boolean)).toEqual(['WO-202610']);
  await page.getByRole('option', { name: 'WO-202610-0011' }).click();
  await page.getByLabel('Barcode').fill('RM-001');
  await page.getByRole('button', { name: 'Resolve' }).click();
  await expect(page.getByText('Scan result', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Record ID')).toHaveCount(0);
});
