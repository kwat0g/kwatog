import { expect, test } from './fixtures';
import { mockAuth } from './helpers';

const SOURCE = {
  id: 'delivery_case_01', kind: 'delivery', label: 'DEL-CASE-01', party_name: 'Acme Manufacturing',
};
const LINE = {
  id: 'delivery_line_01', part_number: 'P-100', description: 'Case part', unit: 'pcs',
  expected_quantity: '100.000', received_quantity: '90.000', maximum_quantity: '100.000', lot_number: null, can_return: true,
};

async function setup(page: import('@playwright/test').Page): Promise<{ posts: Array<{ requestKey?: string; received?: string; defective?: string }> }> {
  await mockAuth(page, {
    id: 'return_manager', name: 'Return Manager', email: 'returns@ogami.test',
    roleSlug: 'return_manager', roleName: 'Return Manager',
    permissions: ['return_management.view', 'return_management.manage', 'return_management.approve'],
  });
  const posts: Array<{ requestKey?: string; received?: string; defective?: string }> = [];
  await page.route('**/return-management/cases/sources**', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [SOURCE] }) });
  });
  await page.route('**/return-management/cases/source-options**', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
      source: { id: SOURCE.id, kind: SOURCE.kind, label: SOURCE.label }, type: 'customer', party: { id: 'customer_01', name: SOURCE.party_name }, lines: [LINE],
    } }) });
  });
  await page.route('**/return-management/cases', async (route) => {
    if (route.request().method() !== 'POST') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }) });
    const body = route.request().postDataJSON() as { request_key?: string; lines?: Array<{ received_quantity?: string; defective_quantity?: string }> };
    posts.push({ requestKey: body.request_key, received: body.lines?.[0]?.received_quantity, defective: body.lines?.[0]?.defective_quantity });
    if (posts.length === 1) {
      return route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'The report could not be saved yet.', errors: { description: ['Try submitting again.'] } }) });
    }
    await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: {
      id: 'case_01', case_number: 'CASE-0001', type: 'customer', status: 'submitted', status_label: 'Submitted', description: 'Damaged part', preferred_resolution: 'credit', resolution: null,
      resolution_notes: null, expected_date: null, created_at: '2026-09-25T00:00:00Z', resolved_at: null, party: { id: 'customer_01', name: SOURCE.party_name }, owner: null, source: { id: SOURCE.id, kind: SOURCE.kind, label: SOURCE.label }, lines: [], events: [], attachments: [],
    } }) });
  });
  await page.route('**/return-management/cases/case_01', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
      id: 'case_01', case_number: 'CASE-0001', type: 'customer', status: 'submitted', status_label: 'Submitted', description: 'Damaged during delivery', preferred_resolution: 'credit', resolution: null,
      resolution_notes: null, expected_date: null, created_at: '2026-09-25T00:00:00Z', resolved_at: null, party: { id: 'customer_01', name: SOURCE.party_name }, owner: null, source: { id: SOURCE.id, kind: SOURCE.kind, label: SOURCE.label },
      lines: [{ id: 'line_01', source_delivery_item_id: 'delivery_line_01', source_po_item_id: null, source_grn_item_id: null, product_id: 'product_01', item_id: 'item_01', product_label: 'P-100 Case part', item_label: null, description: 'Case part', unit: 'pcs', expected_quantity: '100.000', received_quantity: '90.000', missing_quantity: '10.000', defective_quantity: '2.000', verified_missing_quantity: null, verified_defective_quantity: null, returned_quantity: null, lot_number: null, serial_number: null, reason: 'Damaged during delivery' }],
      events: [{ id: 'event_01', action: 'submitted', message: 'Report submitted.', actor_type: 'customer', actor_name: 'Customer', is_public: true, created_at: '2026-09-25T00:00:00Z' }], attachments: [],
    } }) });
  });
  return { posts };
}

test('desktop intake submits a delivery problem and retry repeats the idempotent request', async ({ page }) => {
  const { posts } = await setup(page);
  await page.goto('/return-management/cases/new');
  await page.getByLabel('Search by document number').fill('DEL-CASE');
  await expect(page.getByText('DEL-CASE-01')).toBeVisible();
  await page.getByRole('button', { name: /DEL-CASE-01/ }).click();
  await expect(page.getByText('Affected goods')).toBeVisible();
  await page.getByRole('checkbox').first().check();
  await page.getByLabel('Damaged or defective').fill('2');
  await page.getByLabel('Explain the problem').fill('Damaged during delivery');
  await page.getByRole('button', { name: 'Submit report' }).click();
  await expect(page.getByText('The report could not be saved yet.')).toBeVisible();
  await page.getByRole('button', { name: 'Submit report' }).click();
  await expect(page.getByText('Report CASE-0001 submitted.')).toBeVisible();
  await expect(page).toHaveURL(/\/return-management\/cases\/case_01$/);
  await expect(page.getByRole('heading', { name: 'Reported problem' })).toBeVisible();
  await expect(page.getByText('10.000 pcs').first()).toBeVisible();
  await expect(page.getByText('Report submitted.')).toBeVisible();
  await expect(page.getByText('Page not found')).toHaveCount(0);
  expect(posts).toHaveLength(2);
  expect(posts[0].requestKey).toBe(posts[1].requestKey);
  expect(posts[1]).toMatchObject({ received: '90.000', defective: '2.000' });
  await page.screenshot({ path: '/tmp/return-case-desktop.png', fullPage: true });
});

test('customer portal intake reaches its case detail', async ({ page }) => {
  await page.route('**/api/v1/auth/user', (route) => route.fulfill({ status: 401, contentType: 'application/json', body: JSON.stringify({ message: 'Unauthenticated.' }) }));
  await page.route('**/api/v1/b2b/customer/me', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
    id: 'portal_user', name: 'Acme Contact', email: 'portal@acme.test', customer_id: 'customer_portal', customer_name: 'Acme', must_change_password: false,
  } }) }));
  await page.route('**/api/v1/b2b/customer/problems/sources**', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [SOURCE] }) }));
  await page.route('**/api/v1/b2b/customer/problems/source-options**', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
    source: { id: SOURCE.id, kind: SOURCE.kind, label: SOURCE.label }, type: 'customer', party: { id: 'customer_portal', name: SOURCE.party_name }, lines: [LINE],
  } }) }));
  await page.route('**/api/v1/b2b/customer/problems', async (route) => {
    if (route.request().method() !== 'POST') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } }) });
    await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: {
      id: 'portal_case', case_number: 'CASE-PORTAL', type: 'customer', status: 'submitted', status_label: 'Submitted', description: 'Portal damaged part', preferred_resolution: 'credit', resolution: null,
      resolution_notes: null, expected_date: null, created_at: '2026-09-25T00:00:00Z', resolved_at: null, party: { id: 'customer_portal', name: SOURCE.party_name }, owner: null, source: { id: SOURCE.id, kind: SOURCE.kind, label: SOURCE.label }, lines: [], events: [], attachments: [],
    } }) });
  });
  await page.route('**/api/v1/b2b/customer/problems/portal_case', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
    id: 'portal_case', case_number: 'CASE-PORTAL', type: 'customer', status: 'submitted', status_label: 'Submitted', description: 'Portal damaged part', preferred_resolution: 'credit', resolution: null, resolution_notes: null, expected_date: null, resolved_at: null,
    party: { id: 'customer_portal', name: SOURCE.party_name }, owner: null, source: { id: SOURCE.id, kind: SOURCE.kind, label: SOURCE.label }, lines: [{ id: 'portal_line', source_delivery_item_id: SOURCE.id, source_po_item_id: null, source_grn_item_id: null, product_id: 'product_01', item_id: 'item_01', product_label: 'P-100 Case part', item_label: null, description: 'Case part', unit: 'pcs', expected_quantity: '100.000', received_quantity: '90.000', missing_quantity: '10.000', defective_quantity: '2.000', verified_missing_quantity: null, verified_defective_quantity: null, returned_quantity: null, lot_number: null, serial_number: null, reason: 'Portal damaged part' }], events: [{ id: 'portal_event', action: 'submitted', message: 'Report submitted.', actor_type: 'customer', actor_name: 'Acme Contact', is_public: true, created_at: '2026-09-25T00:00:00Z' }], attachments: [],
  } }) }));
  await page.goto('/portal/customer/problems/new');
  await page.getByLabel('Search by document number').fill('DEL-CASE');
  await expect(page.getByText('DEL-CASE-01')).toBeVisible();
  await page.getByRole('button', { name: /DEL-CASE-01/ }).click();
  await page.getByRole('checkbox').first().check();
  await page.getByLabel('Damaged or defective').fill('2');
  await page.getByLabel('Explain the problem').fill('Portal damaged part');
  await page.getByRole('button', { name: 'Submit report' }).click();
  await expect(page).toHaveURL(/\/portal\/customer\/problems\/portal_case$/);
  await expect(page.getByRole('heading', { name: 'Reported problem' })).toBeVisible();
  await expect(page.getByText('Portal damaged part')).toBeVisible();
  await expect(page.getByText('Page not found')).toHaveCount(0);
});

test('internal supplier GRN submits the changed expected quantity', async ({ page }) => {
  await mockAuth(page, {
    id: 'grn_manager', name: 'Return Manager', email: 'grn@ogami.test', roleSlug: 'return_manager', roleName: 'Return Manager',
    permissions: ['return_management.view', 'return_management.manage'],
  });
  let submitted: Record<string, unknown> | null = null;
  await page.route('**/return-management/cases/sources**', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ id: 'grn_01', kind: 'grn', label: 'GRN-01', party_name: 'Supplier' }] }) }));
  await page.route('**/return-management/cases/source-options**', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { source: { id: 'grn_01', kind: 'grn', label: 'GRN-01' }, type: 'supplier', party: { id: 'vendor', name: 'Supplier' }, lines: [{ id: 'grn_line', part_number: 'P-GRN', description: 'GRN part', unit: 'pcs', expected_quantity: '90.000', received_quantity: '90.000', maximum_quantity: '100.000', lot_number: null, can_return: true }] } }) }));
  await page.route('**/return-management/cases', async (route) => {
    if (route.request().method() !== 'POST') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } }) });
    submitted = route.request().postDataJSON() as Record<string, unknown>;
    await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: {
      id: 'grn_case', case_number: 'CASE-GRN', type: 'supplier', status: 'submitted', status_label: 'Submitted', description: 'Short GRN', preferred_resolution: 'credit', resolution: null,
      resolution_notes: null, expected_date: null, created_at: null, resolved_at: null, party: null, owner: null,
      source: { id: 'grn_01', kind: 'grn', label: 'GRN-01' }, lines: [], events: [], attachments: [],
    } }) });
  });
  await page.goto('/return-management/cases/new');
  await page.getByRole('button', { name: 'Supplier receipt or order' }).click();
  await page.getByLabel('Search by document number').fill('GRN-01');
  await page.getByRole('button', { name: /GRN-01/ }).click();
  await page.getByRole('checkbox').first().check();
  await page.getByLabel('Expected for this shipment').fill('100');
  await page.getByLabel('Damaged or defective').fill('2');
  await page.getByLabel('Explain the problem').fill('Short GRN');
  await page.getByRole('button', { name: 'Submit report' }).click();
  await expect(page).toHaveURL(/\/return-management\/cases\/grn_case$/);
  expect((submitted?.lines as Array<Record<string, string>>)[0]).toMatchObject({ expected_quantity: '100.000', received_quantity: '90.000', defective_quantity: '2.000' });
});

function detailFixture(id: string, status: 'action_agreed' | 'in_progress', resolution: 'redelivery' | 'credit') {
  return { id, case_number: `CASE-${id}`, type: 'customer', status, status_label: status === 'action_agreed' ? 'Action agreed' : 'In progress', description: 'Authorization fixture', preferred_resolution: resolution, resolution, resolution_notes: 'Approved resolution', expected_date: null, created_at: '2026-09-25T00:00:00Z', resolved_at: null, party: { id: 'customer_auth', name: 'Acme' }, owner: null, source: { id: 'delivery_auth', kind: 'delivery', label: 'DEL-AUTH' }, lines: [], events: [], attachments: [] };
}

test('approver without manage can approve a no-charge customer replacement', async ({ page }) => {
  await mockAuth(page, { id: 'approver_only', name: 'Approver', email: 'approver@ogami.test', roleSlug: 'approver', roleName: 'Approver', permissions: ['return_management.view', 'return_management.approve'] });
  await page.route('**/return-management/cases/approve_case', (route) => {
    if (route.request().resourceType() === 'document') return route.continue();
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: detailFixture('approve_case', 'action_agreed', 'redelivery') }) });
  });
  await page.goto('/return-management/cases/approve_case');
  await expect(page.getByRole('button', { name: 'Approve no-charge replacement' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Create credit note' })).toHaveCount(0);
});

test('finance credit manager without return manage can create a credit in progress', async ({ page }) => {
  await mockAuth(page, { id: 'finance_only', name: 'Finance', email: 'finance@ogami.test', roleSlug: 'finance', roleName: 'Finance', permissions: ['return_management.view', 'accounting.credit_notes.manage'] });
  await page.route('**/return-management/cases/credit_case', (route) => {
    if (route.request().resourceType() === 'document') return route.continue();
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: detailFixture('credit_case', 'in_progress', 'credit') }) });
  });
  await page.goto('/return-management/cases/credit_case');
  await expect(page.getByRole('button', { name: 'Create credit note' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Approve no-charge replacement' })).toHaveCount(0);
});

test('supplier redelivery links additive receipts and retains both choices after a failed save', async ({ page }) => {
  await mockAuth(page, {
    id: 'supplier_manager', name: 'Returns', email: 'returns@ogami.test', roleSlug: 'return_manager', roleName: 'Return Manager',
    permissions: ['return_management.view', 'return_management.manage'],
  });
  const linked = {
    id: 'split_case', case_number: 'CASE-SPLIT', type: 'supplier', status: 'in_progress', status_label: 'In progress',
    description: 'Replacement shipment arrived in two receipts', preferred_resolution: 'redelivery', resolution: 'redelivery',
    resolution_notes: 'Accepted quantities accumulate across receipts.', expected_date: null, created_at: '2026-09-25T00:00:00Z', resolved_at: null,
    party: { id: 'supplier_01', name: 'Supplier' }, owner: null, source: { id: 'grn_source', kind: 'grn', label: 'GRN-SOURCE' },
    lines: [{ id: 'split_line', source_delivery_item_id: null, source_po_item_id: null, source_grn_item_id: 'source_grn_line', product_id: 'part_01', item_id: 'part_01', product_label: 'Replacement part', item_label: null, description: 'Replacement part', unit: 'pcs', expected_quantity: '10.000', received_quantity: '0.000', missing_quantity: '10.000', defective_quantity: '0.000', verified_missing_quantity: '10.000', verified_defective_quantity: '0.000', redelivered_quantity: '4.000', remaining_redelivery_quantity: '6.000', returned_quantity: null, lot_number: null, serial_number: null, reason: 'Short shipment' }],
    events: [], attachments: [], resolution_receipts: [{ id: 'grn4', grn_number: 'GRN-4', status: 'accepted', lines: [{ case_line_id: 'split_line', description: 'Replacement part', unit: 'pcs', quantity: '4.000' }] }],
  };
  let currentRecord = linked;
  await page.route('**/return-management/cases/split_case', async (route) => {
    if (route.request().resourceType() === 'document') return route.continue();
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: currentRecord }) });
  });
  await page.route('**/return-management/cases/split_case/resolution-options', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
    credit_notes: [], sales_orders: [], purchase_orders: [], deliveries: [], goods_receipts: [{ id: 'grn4', label: 'GRN-4' }, { id: 'grn6', label: 'GRN-6' }],
  } }) }));
  let attempts = 0;
  let submitted: Record<string, unknown> | null = null;
  await page.route('**/return-management/cases/split_case/actions', async (route) => {
    if (route.request().method() !== 'POST') return route.continue();
    attempts += 1;
    submitted = route.request().postDataJSON() as Record<string, unknown>;
    if (attempts === 1) return route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'Receipt links could not be saved yet.' }) });
    currentRecord = { ...linked, resolution_receipts: [linked.resolution_receipts[0], { id: 'grn6', grn_number: 'GRN-6', status: 'accepted', lines: [{ case_line_id: 'split_line', description: 'Replacement part', unit: 'pcs', quantity: '6.000' }] }], lines: [{ ...linked.lines[0], redelivered_quantity: '10.000', remaining_redelivery_quantity: '0.000' }] };
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: currentRecord }) });
  });
  await page.goto('/return-management/cases/split_case');
  await page.getByRole('button', { name: 'Link completed work' }).first().click();
  const grn4 = page.getByLabel('GRN-4');
  const grn6 = page.getByLabel('GRN-6');
  await expect(grn4).toBeVisible();
  await grn4.check();
  await grn6.check();
  await page.getByRole('button', { name: 'Save links' }).click();
  await expect(page.getByText('Receipt links could not be saved yet.')).toBeVisible();
  await expect(grn4).toBeChecked();
  await expect(grn6).toBeChecked();
  expect(submitted).toMatchObject({ action: 'link_resolution', resolution_goods_receipt_note_ids: ['grn4', 'grn6'] });
  await page.getByRole('button', { name: 'Save links' }).click();
  await expect(page.getByRole('heading', { name: 'Link completed work' })).toHaveCount(0);
  await expect(page.getByText('GRN-4')).toBeVisible();
  await expect(page.getByText('GRN-6')).toBeVisible();
  await expect(page.getByText('10.000 pcs').first()).toBeVisible();
  await expect(page.getByText('Page not found')).toHaveCount(0);
});

test('case revision permission allows replacing a cancelled RMA but blocks an active RMA', async ({ page }) => {
  await mockAuth(page, {
    id: 'reviewer', name: 'Reviewer', email: 'reviewer@ogami.test', roleSlug: 'return_manager', roleName: 'Return Manager',
    permissions: ['return_management.view', 'return_management.manage', 'return_management.approve'],
  });
  const fixture = (id: string, rmaStatus: string, canReviseAgreement: boolean) => ({
    ...detailFixture(id, 'in_progress', 'credit'), can_revise_agreement: canReviseAgreement,
    return_request: { id: `rma_${id}`, rma_number: `RMA-${id}`, status: rmaStatus },
  });
  await page.route('**/return-management/cases/revise_cancelled', async (route) => {
    if (route.request().resourceType() === 'document') return route.continue();
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: fixture('revise_cancelled', 'cancelled', true) }) });
  });
  await page.goto('/return-management/cases/revise_cancelled');
  await expect(page.getByRole('button', { name: 'Agree action' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Reject' })).toBeVisible();

  await page.route('**/return-management/cases/revise_active', async (route) => {
    if (route.request().resourceType() === 'document') return route.continue();
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: fixture('revise_active', 'approved', false) }) });
  });
  await page.goto('/return-management/cases/revise_active');
  await expect(page.getByRole('button', { name: 'Agree action' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Reject' })).toHaveCount(0);
});
