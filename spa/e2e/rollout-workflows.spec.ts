import { expect, test } from './fixtures';
import { loginAs } from './helpers-extended';

const ITEM_ID = 'itemPlan01';

test.describe('Operational rollout workflows', () => {
  test.beforeEach(async ({ page }) => {
    // Keep shared layout queries (badges, notifications, preferences) from
    // reaching a live API; workflow-specific routes registered below win.
    await page.route('**/api/v1/**', (route) => route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: [] }),
    }));
    await page.route('**/api/v1/dashboards/badges', (route) => route.fulfill({
      status: 200, contentType: 'application/json', body: JSON.stringify({ data: {} }),
    }));
    await page.route('**/api/v1/notifications*', (route) => route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: [],
        meta: { current_page: 1, last_page: 1, per_page: 8, total: 0, unread_count: 0 },
      }),
    }));
  });

  test('QC publishes an item quality-plan revision', async ({ page }) => {
    let plans: Array<Record<string, unknown>> = [];
    const item = {
      id: ITEM_ID, code: 'RM-003', name: 'Plastic Resin Type C (PA)', description: null,
      category: null, item_type: 'raw_material', item_type_label: 'Raw material', unit_of_measure: 'KG',
      standard_cost: '100.0000', reorder_method: 'fixed_quantity', reorder_point: '50.000',
      safety_stock: '20.000', minimum_order_quantity: '10.000', lead_time_days: 7,
      is_critical: true, is_active: true, quality_plan_ready: false, abc_class: 'A',
      on_hand_quantity: '0.000', reserved_quantity: '0.000', available_quantity: '0.000', stock_status: 'critical',
    };
    await page.route(`**/api/v1/inventory/items/${ITEM_ID}`, (route) => route.fulfill({
      status: 200, contentType: 'application/json', body: JSON.stringify({ data: item }),
    }));
    await page.route(`**/api/v1/inventory/items/${ITEM_ID}/quality-plans`, async (route) => {
      if (route.request().method() === 'POST') {
        plans = [{
          id: 'plan01', version: 1, stage: 'incoming', sampling_method: 'aql', fixed_sample_size: null,
          aql_level: 'general_ii', parameters: [{ parameter_name: 'Moisture', parameter_type: 'dimensional' }],
          effective_from: '2026-07-28', effective_to: null, is_active: true, notes: null,
          vendor: null, creator: { id: 'qc01', name: 'QC Inspector' }, created_at: '2026-07-28T00:00:00Z',
        }];
        await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: plans[0] }) });
      } else {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: plans }) });
      }
    });
    await page.route('**/api/v1/vendors*', (route) => route.fulfill({
      status: 200, contentType: 'application/json',
      body: JSON.stringify({ data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }),
    }));

    await loginAs(page, 'qc', `/inventory/items/${ITEM_ID}/quality-plans`);
    await expect(page.getByRole('heading', { name: /quality plans/i })).toBeVisible();
    await page.getByPlaceholder('Parameter name').fill('Moisture');
    await page.getByRole('button', { name: 'Publish revision' }).click();
    await expect(page.getByText('v1')).toBeVisible();
    await expect(page.getByText('active', { exact: true })).toBeVisible();
  });

  test('warehouse scanner resolves an item into a receiving action', async ({ page }) => {
    await page.route('**/api/v1/inventory/scan/resolve', async (route) => {
      expect((await route.request().postDataJSON()).barcode).toBe('RM-003');
      await route.fulfill({
        status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
          type: 'item', entity: { id: ITEM_ID, code: 'RM-003', name: 'Plastic Resin Type C' },
          suggested_actions: [{ action: 'view_item', label: 'View item', params: { id: ITEM_ID }, href: `/inventory/items/${ITEM_ID}` }],
        } }),
      });
    });

    await loginAs(page, 'warehouse', '/inventory/scanner');
    await page.getByLabel('Barcode').fill('RM-003');
    await page.getByRole('button', { name: 'Resolve' }).click();
    await expect(page.getByText('Plastic Resin Type C')).toBeVisible();
    await expect(page.getByRole('button', { name: 'View item' })).toBeVisible();
  });

  test('exception workbench claims selected work in bulk', async ({ page }) => {
    let assigned = false;
    const response = () => ({ data: {
      items: [{
        id: 'quality:ncr:ncr01', category: 'quality', kind: 'ncr', title: 'Resolve NCR NCR-001',
        description: 'Material contamination', reference: 'NCR-001', priority: 'high', status_label: 'Open',
        link: '/quality/ncrs/ncr01', created_at: '2026-07-27T00:00:00Z', due_at: '2026-07-28T00:00:00Z',
        age_hours: 24, is_overdue: true, owner_label: null, task_state: assigned ? 'acknowledged' : 'open',
        assigned_to: assigned ? { id: 'wh01', name: 'Warehouse Staff' } : null, snoozed_until: null,
      }],
      summary: { total: 1, critical: 0, high: 1, overdue: 1, owned_by_me: assigned ? 1 : 0, unassigned: assigned ? 0 : 1, by_category: { quality: 1 } },
      generated_at: '2026-07-28T00:00:00Z',
    } });
    // The standalone /exceptions page was folded into the Action Center on
    // 2026-08-08 — same queue with approvals filtered out — and its route was
    // deleted, so this test had been loading the SPA's not-found page and never
    // reaching an assertion. The bulk triage it covers now lives behind
    // ?scope=exceptions, fed by the Action Center endpoint.
    await page.route('**/api/v1/dashboards/action-center', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(response()) }));
    await page.route('**/api/v1/dashboards/action-center/tasks', async (route) => {
      expect((await route.request().postDataJSON()).action).toBe('claim');
      assigned = true;
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
    });

    await loginAs(page, 'warehouse', '/action-center?scope=exceptions');
    await page.getByLabel('Select Resolve NCR NCR-001').check();
    await page.getByRole('button', { name: 'Claim' }).click();
    // The folded page words the owner line "Assigned: <name>".
    await expect(page.getByText('Assigned: Warehouse Staff')).toBeVisible();
  });

  test('administrator sees rollout health telemetry', async ({ page }) => {
    await page.route('**/api/v1/dashboards/rollout-health', (route) => route.fulfill({
      status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
        status: 'healthy', quality_plans: { eligible_items: 10, covered_items: 10, coverage_percent: 100, missing: [] },
        qc_triggers: { pending_grns_without_inspection: 0, failed_inspections_24h: 0, grace_minutes: 15 },
        scanner: { scans_24h: 12, unrecognized_24h: 0, recognition_rate: 100, top_unrecognized: [] },
        actions: { total: 2, overdue: 0, critical: 0, unassigned: 0 }, generated_at: '2026-07-28T00:00:00Z',
      } }),
    }));
    await loginAs(page, 'admin', '/admin/operations-health');
    await expect(page.getByRole('heading', { name: 'Operations Health' })).toBeVisible();
    await expect(page.getByText('100%').first()).toBeVisible();
    await expect(page.getByText('All eligible items are covered.')).toBeVisible();
  });
});
