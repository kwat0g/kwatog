import { expect, test } from './fixtures';
import { mockAuth } from './helpers';

test('RMA partial receipt preserves quantities and idempotency key across retry', async ({ page }) => {
  await mockAuth(page, {
    id: 'warehouse_receiver', name: 'Warehouse Receiver', email: 'warehouse@ogami.test',
    roleSlug: 'warehouse_receiver', roleName: 'Warehouse Receiver',
    permissions: ['return_management.view', 'return_management.receive'],
  });

  const base = {
    id: 'rma_partial', rma_number: 'RMA-0042', type: 'customer_return', type_label: 'Customer return',
    status: 'approved', status_label: 'Approved', is_editable: false, item_count: 1,
    return_date: '2026-09-25', reason_code: 'damaged', reason_description: 'Damaged in transit',
    resolution: 'credit', customer: { id: 'customer_01', name: 'Acme' }, vendor: null,
    source_label: 'DEL-0042', customer_notes: null, internal_notes: null, refund_amount: '100.00',
    items: [{ id: 'rma_line', quantity: '5.000', returned_quantity: '0.000', received_total: '0.000', remaining_quantity: '5.000', unit_price: '20.00', total: '100.00', condition: 'damaged', reason: 'Damaged in transit', product: { id: 'product_01', part_number: 'P-42', name: 'Part 42' }, item: null }],
    receipt_open: true, receipts: [], inspection_handoff: null, inspections: [], approval_records: [],
    created_at: '2026-09-25T00:00:00Z', approved_at: '2026-09-25T00:00:00Z', rejected_at: null, cancelled_at: null,
  };
  let current = base;
  await page.route('**/return-management/return-requests/rma_partial', async (route) => {
    if (route.request().resourceType() === 'document') return route.continue();
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: current }) });
  });
  await page.route('**/return-management/options**', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { types: [], statuses: [], reasons: [{ value: 'damaged', label: 'Damaged' }], resolutions: [{ value: 'credit', label: 'Credit' }], conditions: [{ value: 'damaged', label: 'Damaged' }], dispositions: [], disposition_matrix: {} } }) }));
  await page.route('**/inventory/warehouse', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ id: 'wh_01', code: 'WH1', name: 'Main warehouse', is_active: true, zones: [{ id: 'zone_01', code: 'Q', name: 'Quarantine', zone_type: 'quarantine', locations: [{ id: 'q_01', code: 'Q-01', is_active: true }] }, { id: 'zone_02', code: 'RTN', name: 'Returns', zone_type: 'returns', locations: [{ id: 'return_01', code: 'RTN-01', is_active: true }] }] }] }) }));
  let attempts = 0;
  const posts: Array<Record<string, unknown>> = [];
  await page.route('**/return-management/return-requests/rma_partial/receive', async (route) => {
    posts.push(route.request().postDataJSON() as Record<string, unknown>);
    attempts += 1;
    if (attempts === 1) return route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'Receipt could not be saved yet.' }) });
    current = { ...base, status: 'approved', status_label: 'Approved', receipt_open: true, receipts: [{ id: 'receipt_01', final_receipt: false, received_at: '2026-09-25T01:00:00Z', items: [{ return_request_item_id: 'rma_line', quantity: '2.000' }] }], items: [{ ...base.items[0], received_total: '2.000', remaining_quantity: '3.000', returned_quantity: '2.000' }] };
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: current }) });
  });

  await page.goto('/return-management/rma_partial');
  await page.getByRole('button', { name: 'Record Receipt' }).click();
  await expect(page.getByText(/quarantine location recorded/i)).toBeVisible();
  await page.locator('input[type="number"]').fill('2');
  await page.getByText('This is the final installment').click();
  await page.getByRole('button', { name: 'Record Partial Receipt' }).click();
  await expect(page.getByText('Receipt could not be saved yet.')).toBeVisible();
  await page.getByRole('button', { name: 'Record Partial Receipt' }).click();
  await expect(page.getByText('Partial receipt recorded.')).toBeVisible();
  expect(posts).toHaveLength(2);
  expect(posts[0]).toMatchObject({ final_receipt: false, received_quantities: { rma_line: '2' } });
  expect(posts[0].request_key).toBe(posts[1].request_key);
  await expect(page.getByText('2.000').first()).toBeVisible();
  await expect(page.getByText('Partial installment')).toBeVisible();
  await expect(page.getByText('Page not found')).toHaveCount(0);
});
