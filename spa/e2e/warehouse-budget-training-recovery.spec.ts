import { expect, test } from '@playwright/test';
import { loginAs } from './helpers-extended';

test('scanner count link selects the session and opens its count item', async ({ page }) => {
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  const item = { id: 'line-1', status: 'pending', system_quantity: '10', counted_quantity: null, variance: '0', variance_percent: '0', item: { code: 'RM-1', unit_of_measure: 'kg' }, location: { full_code: 'MAIN-A1' } };
  const session = { id: 'count-1', session_number: 'SC-1', title: 'Scanner count', status: 'in_progress', scope: 'full', items: [item], created_at: '2026-10-04T00:00:00Z' };
  await page.route('**/api/v1/inventory/stock-counts', route => route.fulfill({ json: { data: [] } }));
  await page.route('**/api/v1/inventory/stock-counts/count-1', route => route.fulfill({ json: { data: session } }));
  await page.route('**/api/v1/inventory/stock-counts/options', route => route.fulfill({ json: { data: { scopes: [], variance_tolerance_pct: 5 } } }));
  await loginAs(page, 'admin', '/inventory/stock-count?session_id=count-1&count_item_id=line-1');
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog.getByText('RM-1', { exact: true })).toBeVisible();
  await expect(dialog.getByRole('spinbutton', { name: /^Counted quantity/ })).toBeVisible();
});

test('stock count recovers policy and selected-session reads', async ({ page }) => {
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  const session = { id: 'count-1', session_number: 'SC-1', title: 'Audit count', status: 'draft', scope: 'full', items: [], created_at: '2026-10-04T00:00:00Z' };
  await page.route('**/api/v1/inventory/stock-counts?**', route => route.fulfill({ json: { data: [session] } }));
  let policyUnavailable = true;
  let detailUnavailable = true;
  await page.route('**/inventory/stock-counts/options', route => route.fulfill(policyUnavailable
    ? { status: 503, json: { message: 'Policy unavailable' } }
    : { json: { data: { scopes: [{ value: 'full', label: 'Full inventory' }], variance_tolerance_pct: 5 } } }));
  await page.route('**/inventory/stock-counts/count-1', route => route.fulfill(detailUnavailable
    ? { status: 503, json: { message: 'Count unavailable' } }
    : { json: { data: session } }));
  await loginAs(page, 'admin', '/inventory/stock-count');
  await expect(page.getByRole('alert').filter({ hasText: 'Stock count options could not be loaded' })).toBeVisible();
  await page.getByRole('button', { name: 'New session', exact: true }).click();
  await expect(page.getByRole('dialog').getByRole('button', { name: 'Create session', exact: true })).toBeDisabled();
  await page.getByRole('dialog').getByRole('button', { name: 'Cancel', exact: true }).click();
  policyUnavailable = false;
  await page.getByRole('button', { name: 'Retry count options', exact: true }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Stock count options could not be loaded' })).toBeHidden();
  await page.getByRole('button').filter({ hasText: 'SC-1' }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Count session could not be loaded' })).toBeVisible();
  detailUnavailable = false;
  await page.getByRole('button', { name: 'Retry session', exact: true }).click();
  await expect(page.getByText('SC-1 — Audit count', { exact: true })).toBeVisible();
});

test('warehouse transfer distinguishes failed locations and selected record', async ({ page }) => {
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  await page.route('**/inventory/items?**', route => route.fulfill({ json: { data: [], meta: { last_page: 1 } } }));
  const transfer = { id: 'transfer-1', transfer_number: 'TR-1', status: 'pending', item: { code: 'RM-1', name: 'Material' }, quantity: '1', created_at: '2026-10-04T00:00:00Z' };
  await page.route('**/api/v1/inventory/transfer-orders?**', route => route.fulfill({ json: { data: [transfer] } }));
  let locationsUnavailable = true;
  let detailUnavailable = true;
  await page.route('**/inventory/warehouse', route => route.fulfill(locationsUnavailable
    ? { status: 503, json: { message: 'Locations unavailable' } }
    : { json: { data: [] } }));
  await page.route('**/inventory/transfer-orders/transfer-1', route => route.fulfill(detailUnavailable
    ? { status: 503, json: { message: 'Transfer unavailable' } }
    : { json: { data: transfer } }));
  await loginAs(page, 'admin', '/inventory/transfer-orders');
  await page.getByRole('button', { name: 'New transfer', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('alert').filter({ hasText: 'Warehouse locations could not be loaded' })).toBeVisible();
  await expect(dialog.getByRole('button', { name: 'Create transfer order', exact: true })).toBeDisabled();
  locationsUnavailable = false;
  await dialog.getByRole('button', { name: 'Retry locations', exact: true }).click();
  await expect(dialog.getByRole('alert').filter({ hasText: 'Warehouse locations could not be loaded' })).toBeHidden();
  await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
  await page.getByRole('button').filter({ hasText: 'TR-1' }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Transfer details could not be loaded' })).toBeVisible();
  detailUnavailable = false;
  await page.getByRole('button', { name: 'Retry transfer', exact: true }).click();
  await expect(page.getByText('TR-1 — RM-1', { exact: true })).toBeVisible();
});

test('budget transfer recovers fiscal-year read before allocation selection', async ({ page }) => {
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  let unavailable = true;
  await page.route('**/budgets/fiscal-years', route => route.fulfill(unavailable
    ? { status: 503, json: { message: 'Years unavailable' } }
    : { json: { data: [{ id: 'fy-1', year: 2026, status: 'active' }] } }));
  await loginAs(page, 'finance', '/budgeting/transfers/create');
  await expect(page.getByRole('alert').filter({ hasText: 'Budget transfer options could not be loaded' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Request Transfer', exact: true })).toBeDisabled();
  unavailable = false;
  await page.getByRole('button', { name: 'Retry transfer options', exact: true }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Budget transfer options could not be loaded' })).toBeHidden();
  await expect(page.locator('select').first().locator('option', { hasText: '2026' })).toHaveCount(1);
});

test('training edit reports read failure instead of missing record', async ({ page }) => {
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  let unavailable = true;
  await page.route('**/hr/trainings/training-1', route => route.fulfill(unavailable
    ? { status: 503, json: { message: 'Training unavailable' } }
    : { json: { data: { id: 'training-1', name: 'Original training', is_certification: false } } }));
  await loginAs(page, 'hr', '/hr/trainings/training-1/edit');
  await expect(page.getByText('Training could not be loaded', { exact: true })).toBeVisible();
  unavailable = false;
  await page.getByRole('button', { name: 'Retry training', exact: true }).click();
  await expect(page.getByRole('textbox', { name: /^Name/ })).toHaveValue('Original training');
});

test('budget edit leaves loading state on failure and retries original record', async ({ page }) => {
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  await page.route('**/accounts?**', route => route.fulfill({ json: { data: [] } }));
  let unavailable = true;
  await page.route('**/budgets/budget-1', route => route.fulfill(unavailable
    ? { status: 503, json: { message: 'Budget unavailable' } }
    : { json: { data: { id: 'budget-1', name: 'Original budget', fiscal_year_id: 'fy-1', budget_type: 'operating', line_items: [] } } }));
  await loginAs(page, 'finance', '/budgeting/budget-1/edit');
  await expect(page.getByText('Budget could not be loaded', { exact: true })).toBeVisible();
  unavailable = false;
  await page.getByRole('button', { name: 'Retry budget', exact: true }).click();
  await expect(page.getByLabel('Budget Name')).toHaveValue('Original budget');
});

test('budget preserves entries and blocks save on failed prerequisite', async ({ page }) => {
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  await page.route('**/accounts?**', route => route.fulfill({ json: { data: [] } }));
  let unavailable = true;
  await page.route('**/budgets/options', route => route.fulfill(unavailable
    ? { status: 503, json: { message: 'Policy unavailable' } }
    : { json: { data: { budget_types: [{ value: 'operating', label: 'Operating' }] } } }));
  await loginAs(page, 'finance', '/budgeting/create');
  await page.getByLabel('Budget Name').fill('Retained budget');
  await expect(page.getByRole('alert').filter({ hasText: 'Budget options could not be loaded' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Create Budget', exact: true })).toBeDisabled();
  unavailable = false;
  await page.getByRole('button', { name: 'Retry budget options', exact: true }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Budget options could not be loaded' })).toBeHidden();
  await expect(page.getByLabel('Budget Name')).toHaveValue('Retained budget');
});

test('training reports unavailable departments and retains entered name', async ({ page }) => {
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  let unavailable = true;
  await page.route('**/hr/departments/tree', route => route.fulfill(unavailable
    ? { status: 503, json: { message: 'Departments unavailable' } }
    : { json: { data: [] } }));
  await loginAs(page, 'hr', '/hr/trainings/create');
  await page.getByRole('textbox', { name: /^Name/ }).fill('Safe handling');
  await expect(page.getByRole('alert').filter({ hasText: 'Training departments could not be loaded' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Create training', exact: true })).toBeDisabled();
  unavailable = false;
  await page.getByRole('button', { name: 'Retry departments', exact: true }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Training departments could not be loaded' })).toBeHidden();
  await expect(page.getByRole('textbox', { name: /^Name/ })).toHaveValue('Safe handling');
});
