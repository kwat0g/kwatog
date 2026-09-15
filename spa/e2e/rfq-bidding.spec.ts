import { expect, test } from './fixtures';
import { loginAs } from './helpers-extended';

const RFQ = {
  id: 'rfq_demo_01',
  rfq_number: 'RFQ-202609-0001',
  status: 'closed' as const,
  status_label: 'Closed',
  title: 'Production resin sourcing',
  instructions: 'Quote the exact resin grade. Substitutions are not permitted.',
  currency: 'PHP',
  issued_at: '2026-09-15T08:00:00Z',
  closes_at: '2026-09-20T08:00:00Z',
  closed_at: '2026-09-20T08:00:01Z',
  evaluation_started_at: '2026-09-20T08:00:01Z',
  resolved_at: null,
  cancellation_reason: null,
  no_award_reason: null,
  budget_warning_level: null,
  budget_warning_message: null,
  purchase_request: { id: 'pr_demo_01', pr_number: 'PR-202609-0001' },
  creator: { id: 'pur0001', name: 'Elena Cruz' },
  items: [{
    id: 'rfq_line_01', description: 'Polypropylene Resin Natural', specification: 'PP homopolymer, injection grade',
    quantity: '500.0000', unit: 'kg', required_delivery_date: '2026-10-01', allow_partial_quantity: true,
    allow_substitute: false, item: { id: 'item_01', code: 'RM-001', name: 'PP Resin', unit_of_measure: 'kg' },
  }],
  invitations: [
    { id: 'invite_01', status: 'submitted', invited_at: '2026-09-15T08:00:00Z', viewed_at: '2026-09-16T09:00:00Z', exception_reason: null, vendor: { id: 'vendor_a', name: 'Alpha Polymers' } },
    { id: 'invite_02', status: 'submitted', invited_at: '2026-09-15T08:00:00Z', viewed_at: null, exception_reason: null, vendor: { id: 'vendor_b', name: 'Beta Materials' } },
  ],
  quotes: [
    {
      id: 'quote_a', version: 1, status: 'submitted' as const, submitted_at: '2026-09-18T09:00:00Z', withdrawn_at: null,
      is_current: true, vat_inclusive: false, vat_amount: '3600.00', freight_amount: '500.00', other_charges: '0.00',
      total_delivered_cost: '34100.00', quote_valid_until: '2026-10-15', payment_terms: '30 days', notes: null,
      quotation_original_filename: 'alpha-quotation.pdf', vendor: { id: 'vendor_a', name: 'Alpha Polymers' },
      items: [{ id: 'quote_line_a', response_status: 'quoted' as const, offered_quantity: '500.0000', unit_price: '60.0000', line_vat_amount: '3600.00', line_freight_amount: '500.00', line_other_charges: '0.00', line_total_delivered_cost: '34100.00', lead_time_days: 7, proposed_delivery_date: '2026-09-28', compliance_status: 'compliant', compliance_notes: null, rfq_item: { id: 'rfq_line_01', description: 'Polypropylene Resin Natural', quantity: '500.0000', unit: 'kg' } }],
    },
    {
      id: 'quote_b', version: 1, status: 'submitted' as const, submitted_at: '2026-09-19T09:00:00Z', withdrawn_at: null,
      is_current: true, vat_inclusive: false, vat_amount: '3780.00', freight_amount: '300.00', other_charges: '0.00',
      total_delivered_cost: '35380.00', quote_valid_until: '2026-10-10', payment_terms: '30 days', notes: null,
      quotation_original_filename: 'beta-quotation.pdf', vendor: { id: 'vendor_b', name: 'Beta Materials' },
      items: [{ id: 'quote_line_b', response_status: 'quoted' as const, offered_quantity: '500.0000', unit_price: '63.0000', line_vat_amount: '3780.00', line_freight_amount: '300.00', line_other_charges: '0.00', line_total_delivered_cost: '35380.00', lead_time_days: 12, proposed_delivery_date: '2026-10-03', compliance_status: 'pending', compliance_notes: null, rfq_item: { id: 'rfq_line_01', description: 'Polypropylene Resin Natural', quantity: '500.0000', unit: 'kg' } }],
    },
  ],
  awards: [],
  addenda: [],
};

const pagination = { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 };

const PR = {
  id: 'pr_demo_01', pr_number: 'PR-202609-0001', date: '2026-09-15', reason: 'Material shortage',
  priority: 'normal', status: 'approved', po_conversion_status: 'sourcing_pending', total_estimated_amount: '32000.00',
  is_auto_generated: true, items: [{ id: 'pr_line_01', description: 'Polypropylene Resin Natural', quantity: '500.0000', unit: 'kg', estimated_unit_price: '64.00', estimated_total: '32000.00', item: { id: 'item_01', code: 'RM-001', name: 'PP Resin', unit_of_measure: 'kg' } }],
};

const SOURCE_LINES = {
  lines: [{
    id: 'pr_line_01', description: 'Polypropylene Resin Natural', quantity: '500.0000', unit: 'kg', estimated_unit_price: '64.00', estimated_total: '32000.00', suggested_vendor_id: 'vendor_a', suggested_unit_price: '60.00',
    item: { id: 'item_01', code: 'RM-001', name: 'PP Resin' },
    candidates: [{ id: 'vendor_a', name: 'Alpha Polymers', tier: 'preferred', qualified: true, unit_price: '60.00', lead_time_days: 7, order_uom: 'bag', base_qty_per_order_unit: '25.0000' }],
  }],
};

const DRAFT_RFQ = { ...RFQ, status: 'draft' as const, status_label: 'Draft', issued_at: null, closed_at: null, evaluation_started_at: null };
const NO_AWARD_RFQ = { ...RFQ, status: 'no_award' as const, status_label: 'No award', no_award_reason: 'No valid supplier quotations were received before the deadline.' };
const SUPPLIER = { id: 'supplier_user_01', name: 'Supplier Portal User', email: 'portal@supp.test', must_change_password: false, is_active: true, status: 'active', failed_login_attempts: 0, locked_until: null, deleted_at: null, vendor: { id: 'vendor_a', name: 'Alpha Polymers' }, last_login_at: null, created_at: '2026-09-01T00:00:00Z' };
const SUPPLIER_QUOTE = { id: 'quote_draft_01', version: 1, status: 'draft' as const, submitted_at: null, withdrawn_at: null, is_current: true, vat_inclusive: false, vat_amount: '0.00', freight_amount: '500.00', other_charges: '0.00', total_delivered_cost: '30500.00', quote_valid_until: '2026-10-15', payment_terms: '30 days', notes: null, quotation_original_filename: null, vendor: { id: 'vendor_a', name: 'Alpha Polymers' }, items: [] };

async function mockRfqApi(page: import('@playwright/test').Page): Promise<void> {
  await page.route('**/api/v1/purchasing/rfqs?*', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [RFQ], meta: pagination }) });
  });
  await page.route('**/api/v1/purchasing/rfqs/rfq_demo_01', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: RFQ }) });
  });
  await page.route('**/api/v1/purchasing/rfqs/rfq_demo_01/comparison', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: RFQ }) });
  });
  await page.route('**/api/v1/purchasing/rfqs/rfq_demo_01/award', async (route) => {
    expect(route.request().method()).toBe('POST');
    const request = route.request().postDataJSON() as { awards: Array<{ award_reason: string }> };
    expect(request.awards[0].award_reason).toBeTruthy();
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: RFQ, purchase_orders: [] }) });
  });
}

async function mockSupplierSession(page: import('@playwright/test').Page): Promise<void> {
  await page.route('**/api/v1/landing/contact', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ legal_name: 'Ogami Philippines', address: null }) });
  });
  await page.route('**/api/v1/b2b/supplier/me', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: SUPPLIER }) });
  });
  await page.route('**/api/v1/b2b/supplier/business-policies', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { functional_currency_code: 'PHP' } }) });
  });
  await page.route('**/api/v1/b2b/supplier/rfqs?*', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [OPEN_RFQ], meta: pagination }) });
  });
  await page.route('**/api/v1/b2b/supplier/rfqs', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [OPEN_RFQ], meta: pagination }) });
  });
  await page.route('**/api/v1/b2b/supplier/rfqs/rfq_demo_01', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: OPEN_RFQ }) });
  });
  await page.route('**/api/v1/b2b/supplier/rfqs/rfq_demo_01/quotes', async (route) => {
    expect(route.request().method()).toBe('POST');
    await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: SUPPLIER_QUOTE }) });
  });
  await page.route('**/api/v1/b2b/supplier/rfqs/rfq_demo_01/documents', async (route) => {
    expect(route.request().method()).toBe('POST');
    await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: { id: 'doc_01', document_type: 'quotation_pdf', original_filename: 'quote.pdf' } }) });
  });
  await page.route('**/api/v1/b2b/supplier/rfqs/rfq_demo_01/quotes/quote_draft_01/submit', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { ...SUPPLIER_QUOTE, status: 'submitted' } }) });
  });
}

const OPEN_RFQ = { ...RFQ, status: 'open' as const, status_label: 'Open', closed_at: null, evaluation_started_at: null, resolved_at: null, quotes: [] };

test.describe('RFQ bidding role workflow', () => {
  test('Purchasing Officer can open the RFQ list and detail', async ({ page }) => {
    await mockRfqApi(page);
    await loginAs(page, 'purchasing', '/purchasing/rfqs');
    await expect(page.getByText('RFQ-202609-0001', { exact: true })).toBeVisible();
    await expect(page.getByText('Production resin sourcing', { exact: true })).toBeVisible();
    await page.getByText('RFQ-202609-0001', { exact: true }).click();
    await expect(page).toHaveURL(/\/purchasing\/rfqs\/rfq_demo_01$/);
    await expect(page.getByText('Polypropylene Resin Natural', { exact: false }).first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'Compare quotations', exact: true })).toBeVisible();
  });

  test('Finance Officer can compare sealed quotations and award a selected line', async ({ page }) => {
    await mockRfqApi(page);
    await loginAs(page, 'finance', '/purchasing/rfqs/rfq_demo_01/compare');
    await expect(page.getByText('Quotation comparison', { exact: true })).toBeVisible();
    await expect(page.getByText('Alpha Polymers', { exact: false }).first()).toBeVisible();
    await expect(page.getByText('Beta Materials', { exact: false }).first()).toBeVisible();
    await page.getByRole('radio').first().check();
    await page.getByLabel('Award reason').fill('Lowest compliant delivered cost with earlier delivery.');
    await page.getByRole('button', { name: 'Award selected lines', exact: true }).click();
    await expect(page).toHaveURL(/\/purchasing\/rfqs\/rfq_demo_01$/);
  });

  test('QC Inspector can view RFQ requirements but cannot open commercial comparison', async ({ page }) => {
    await mockRfqApi(page);
    await loginAs(page, 'qc', '/purchasing/rfqs/rfq_demo_01');
    await expect(page.getByText('Polypropylene Resin Natural', { exact: false }).first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'Compare quotations', exact: true })).toHaveCount(0);
    await page.goto('/purchasing/rfqs/rfq_demo_01/compare');
    await expect(page.getByText('Page not found', { exact: true })).toBeVisible();
  });

  test('Purchasing Officer creates an RFQ draft through the four-step wizard', async ({ page }) => {
    await page.route('**/api/v1/purchasing/purchase-requests/pr_demo_01', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: PR }) });
    });
    await page.route('**/api/v1/purchasing/purchase-requests/pr_demo_01/sourcing', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: SOURCE_LINES }) });
    });
    await page.route('**/api/v1/purchasing/purchase-requests/pr_demo_01/rfqs', async (route) => {
      expect(route.request().method()).toBe('POST');
      const payload = route.request().postDataJSON() as { invitations: Array<{ vendor_id: string }>; title: string };
      expect(payload.title).toBeTruthy();
      expect(payload.invitations).toEqual([{ vendor_id: 'vendor_a' }]);
      await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: DRAFT_RFQ }) });
    });
    await loginAs(page, 'purchasing', '/purchasing/rfqs/create?purchase_request=pr_demo_01');
    await expect(page.getByRole('heading', { name: 'Start supplier RFQ' })).toBeVisible();
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await page.getByRole('checkbox').first().check();
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await page.getByRole('button', { name: 'Create draft', exact: true }).click();
    await expect(page).toHaveURL(/\/purchasing\/rfqs\/rfq_demo_01$/);
  });

  test('Finance can review a no-award outcome without seeing an award action', async ({ page }) => {
    await page.route('**/api/v1/purchasing/rfqs/rfq_demo_01', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: NO_AWARD_RFQ }) });
    });
    await loginAs(page, 'finance', '/purchasing/rfqs/rfq_demo_01');
    await expect(page.getByText('No valid supplier quotations were received before the deadline.', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Compare quotations', exact: true })).toHaveCount(0);
  });

  test('Warehouse and Employee roles cannot access internal RFQs', async ({ page }) => {
    await loginAs(page, 'warehouse', '/purchasing/rfqs');
    await expect(page.getByText('Page not found', { exact: true })).toBeVisible();
    const employee = await page.context().newPage();
    await loginAs(employee, 'employee', '/purchasing/rfqs');
    await expect(employee.getByText('Page not found', { exact: true })).toBeVisible();
  });

  test('Supplier portal saves a draft, uploads its quotation, and submits before closure', async ({ page }) => {
    await mockSupplierSession(page);
    await page.goto('/portal/supplier/rfqs');
    await expect(page.getByText('RFQ-202609-0001', { exact: true })).toBeVisible();
    await page.getByText('RFQ-202609-0001', { exact: true }).click();
    await page.getByRole('link', { name: 'Prepare quotation' }).click();
    await page.getByRole('checkbox', { name: 'No quote' }).uncheck();
    await page.getByLabel('Offered quantity').fill('500');
    await page.getByLabel('Unit price').fill('60.00');
    await page.getByLabel('Lead time days').fill('7');
    await page.getByLabel('Formal quotation PDF').setInputFiles({ name: 'quote.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 demo') });
    await page.getByRole('button', { name: 'Submit sealed quotation', exact: true }).click();
    await expect(page).toHaveURL(/\/portal\/supplier\/rfqs\/rfq_demo_01$/);
  });
});
