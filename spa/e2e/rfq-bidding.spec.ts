import { expect, test } from './fixtures';
import { loginAs } from './helpers-extended';

const BUYER_ACTIONS = {
  can_edit: false, can_publish: false, can_extend: false, can_close_now: false,
  can_cancel: false, can_capture_quote: false, can_upload_document: false,
  can_compare: true, can_award: true,
};
const VIEW_ONLY_ACTIONS = { ...BUYER_ACTIONS, can_award: false };
const NO_ACTIONS = { ...VIEW_ONLY_ACTIONS, can_compare: false };
const DRAFT_ACTIONS = {
  can_edit: true, can_publish: true, can_extend: false, can_close_now: false,
  can_cancel: true, can_capture_quote: false, can_upload_document: true,
  can_compare: false, can_award: false,
};

const RFQ = {
  id: 'rfq_demo_01',
  rfq_number: 'RFQ-202609-0001',
  status: 'closed' as const,
  status_label: 'Closed',
  title: 'Production resin sourcing',
  instructions: 'Quote the exact resin grade. Substitutions are not permitted.',
  issued_at: '2026-09-15T08:00:00Z',
  closes_at: '2026-09-20T08:00:00Z',
  closed_at: '2026-09-20T08:00:01Z',
  resolved_at: null,
  cancellation_reason: null,
  last_extension_reason: null,
  ranking_basis: 'ex_vat' as const,
  purchase_request: { id: 'pr_demo_01', pr_number: 'PR-202609-0001' },
  creator: { id: 'pur0001', name: 'Elena Cruz' },
  invited_count: 2,
  responded_count: 2,
  items: [{
    id: 'rfq_line_01', description: 'Polypropylene Resin Natural', specification: 'PP homopolymer, injection grade',
    quantity: '500.0000', unit: 'kg', required_delivery_date: '2026-10-01',
    awarded_quantity: '0.0000', remaining_quantity: '500.0000',
    item: { id: 'item_01', code: 'RM-001', name: 'PP Resin', unit_of_measure: 'kg' },
  }],
  invitations: [
    { id: 'invite_01', status: 'submitted', invited_at: '2026-09-15T08:00:00Z', viewed_at: '2026-09-16T09:00:00Z', exception_reason: null, vendor: { id: 'vendor_a', name: 'Alpha Polymers' }, reach: 'portal', portal_notified_at: '2026-09-15T08:00:00Z', email_notified_at: null, last_notification_error: null },
    { id: 'invite_02', status: 'submitted', invited_at: '2026-09-15T08:00:00Z', viewed_at: null, exception_reason: null, vendor: { id: 'vendor_b', name: 'Beta Materials' }, reach: 'email', portal_notified_at: null, email_notified_at: '2026-09-15T08:00:00Z', last_notification_error: null },
  ],
  quotes: [
    {
      id: 'quote_a', status: 'submitted' as const, submitted_at: '2026-09-18T09:00:00Z',
      vat_treatment: 'exclusive' as const, goods_amount: '30000.00', freight_amount: '500.00',
      vat_amount: '3600.00', total_delivered_cost: '34100.00', quote_valid_until: '2026-10-15',
      is_expired: false, payment_terms: '30 days', notes: null,
      quotation_original_filename: 'alpha-quotation.pdf', captured_manually: false,
      vendor: { id: 'vendor_a', name: 'Alpha Polymers' },
      items: [{ id: 'quote_line_a', request_for_quote_item_id: 'rfq_line_01', response_status: 'quoted' as const, offered_quantity: '500.0000', unit_price: '60.0000', line_total: '30000.00', allocated_delivered_cost: '34100.00', unit_delivered_cost: '68.2000', unit_net_cost: '61.0000', lead_time_days: 7, proposed_delivery_date: '2026-09-28', meets_required_date: true, is_recommended: true }],
      documents: [{ id: 'quote_doc_a', document_type: 'quotation_pdf', original_filename: 'alpha-quotation.pdf' }],
      supplier_performance: null,
    },
    {
      id: 'quote_b', status: 'submitted' as const, submitted_at: '2026-09-19T09:00:00Z',
      vat_treatment: 'exclusive' as const, goods_amount: '31500.00', freight_amount: '300.00',
      vat_amount: '3780.00', total_delivered_cost: '35580.00', quote_valid_until: '2026-10-10',
      is_expired: false, payment_terms: '30 days', notes: null,
      quotation_original_filename: 'beta-quotation.pdf', captured_manually: false,
      vendor: { id: 'vendor_b', name: 'Beta Materials' },
      items: [{ id: 'quote_line_b', request_for_quote_item_id: 'rfq_line_01', response_status: 'quoted' as const, offered_quantity: '500.0000', unit_price: '63.0000', line_total: '31500.00', allocated_delivered_cost: '35580.00', unit_delivered_cost: '71.1600', unit_net_cost: '63.6000', lead_time_days: 12, proposed_delivery_date: '2026-10-03', meets_required_date: false, is_recommended: false }],
      documents: [{ id: 'quote_doc_b', document_type: 'quotation_pdf', original_filename: 'beta-quotation.pdf' }],
      supplier_performance: null,
    },
  ],
  awards: [],
  documents: [],
  actions: BUYER_ACTIONS,
};

const pagination = { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 };

const PR = {
  id: 'pr_demo_01', pr_number: 'PR-202609-0001', date: '2026-09-15', reason: 'Material shortage',
  priority: 'normal', status: 'approved', po_conversion_status: 'sourcing_pending', total_estimated_amount: '32000.00', required_delivery_date: '2026-10-01',
  is_auto_generated: true, items: [{ id: 'pr_line_01', description: 'Polypropylene Resin Natural', quantity: '500.0000', unit: 'kg', estimated_unit_price: '64.00', estimated_total: '32000.00', item: { id: 'item_01', code: 'RM-001', name: 'PP Resin', unit_of_measure: 'kg' } }],
};

const RFQ_SETUP = {
  lines: [{ id: 'pr_line_01', description: 'Polypropylene Resin Natural', item_code: 'RM-001', unit: 'kg', remaining_quantity: '500.0000', has_item: true }],
  suppliers: [{ id: 'vendor_a', name: 'Alpha Polymers', qualified: true, lead_time_days: 7, reach: 'portal', email: 'sales@alpha.test' }],
};

const DRAFT_RFQ = {
  ...RFQ, status: 'draft' as const, status_label: 'Draft', issued_at: null, closed_at: null,
  responded_count: 0, invitations: [{ ...RFQ.invitations[0], status: 'invited' }],
  quotes: [], actions: DRAFT_ACTIONS,
};
const NO_QUOTE_CANCELLED_RFQ = {
  ...RFQ, status: 'cancelled' as const, status_label: 'Cancelled',
  cancellation_reason: 'No quotations were received before the deadline.',
  responded_count: 0, invitations: [{ ...RFQ.invitations[0], status: 'invited' }],
  quotes: [], actions: NO_ACTIONS,
};
type RfqFixture = typeof RFQ | typeof DRAFT_RFQ | typeof NO_QUOTE_CANCELLED_RFQ;
const SUPPLIER = { id: 'supplier_user_01', name: 'Supplier Portal User', email: 'portal@supp.test', must_change_password: false, is_active: true, status: 'active', failed_login_attempts: 0, locked_until: null, deleted_at: null, vendor: { id: 'vendor_a', name: 'Alpha Polymers' }, last_login_at: null, created_at: '2026-09-01T00:00:00Z' };
const SUPPLIER_DEADLINE = new Date(Date.now() + 7 * 86_400_000).toISOString();
const SUPPLIER_RFQ = {
  id: 'rfq_demo_01', rfq_number: 'RFQ-202610-0001', title: 'Production resin sourcing',
  instructions: 'Quote the exact resin grade. Substitutions are not permitted.',
  status: 'open' as const, status_label: 'Open', closes_at: SUPPLIER_DEADLINE, closed_at: null,
  invitation_status: 'invited' as const, can_quote: true, outcome: null,
  items: [{ id: 'rfq_line_01', description: 'Polypropylene Resin Natural', specification: 'PP homopolymer, injection grade', quantity: '500.0000', unit: 'kg', required_delivery_date: '2026-10-15', item: { id: 'item_01', code: 'RM-001', name: 'PP Resin', unit_of_measure: 'kg' } }],
  documents: [], quote: null, my_documents: [], awards: [],
};
const SUPPLIER_QUOTE = {
  id: 'quote_draft_01', status: 'draft' as const, submitted_at: null,
  vat_treatment: 'exclusive' as const, goods_amount: '30000.00', freight_amount: '500.00',
  vat_amount: '3600.00', total_delivered_cost: '34100.00', quote_valid_until: '2026-11-03',
  is_expired: false, payment_terms: '30 days', notes: null, quotation_original_filename: null,
  captured_manually: false, vendor: { id: 'vendor_a', name: 'Alpha Polymers' },
  items: [{ id: 'supplier_quote_line_01', request_for_quote_item_id: 'rfq_line_01', response_status: 'quoted' as const, offered_quantity: '500.0000', unit_price: '60.0000', line_total: '30000.00', allocated_delivered_cost: null, unit_delivered_cost: null, unit_net_cost: null, lead_time_days: 7, proposed_delivery_date: '2026-10-15', meets_required_date: null, is_recommended: false }],
  documents: [],
};

async function mockRfqApi(page: import('@playwright/test').Page, initial: RfqFixture = RFQ): Promise<void> {
  let awarded = false;
  let purchaseOrders: Array<{ id: string; po_number: string; total_amount: string; status: string; status_label: string }> = [];
  const readRfq = () => awarded ? {
    ...initial, status: 'awarded' as const, status_label: 'Awarded', resolved_at: '2026-09-20T08:01:00Z',
    actions: VIEW_ONLY_ACTIONS,
    awards: [{ id: 'award_01', awarded_quantity: '500.0000', awarded_unit_price: '60.0000', awarded_total_delivered_cost: '34100.00', award_reason: 'Lowest compliant delivered cost with earlier delivery.', awarded_at: '2026-09-20T08:01:00Z', vendor: { id: 'vendor_a', name: 'Alpha Polymers' }, rfq_item: { id: 'rfq_line_01', description: 'Polypropylene Resin Natural' }, purchase_order: { id: 'po_award_01', po_number: 'PO-202610-0001' } }],
  } : initial;
  await page.route('**/api/v1/purchasing/rfqs?*', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [readRfq()], meta: pagination }) });
  });
  await page.route('**/api/v1/purchasing/rfqs/rfq_demo_01', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: readRfq() }) });
  });
  await page.route('**/api/v1/purchasing/rfqs/rfq_demo_01/comparison', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: readRfq() }) });
  });
  await page.route('**/api/v1/purchasing/rfqs/rfq_demo_01/purchase-orders', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: purchaseOrders }) });
  });
  await page.route('**/api/v1/purchasing/rfqs/rfq_demo_01/award', async (route) => {
    expect(route.request().method()).toBe('POST');
    const request = route.request().postDataJSON() as { award_reason: string; lines: Array<{ request_for_quote_item_id: string; supplier_quote_item_id: string }> };
    expect(request.award_reason).toBeTruthy();
    expect(request.lines).toEqual([{ request_for_quote_item_id: 'rfq_line_01', supplier_quote_item_id: 'quote_line_a' }]);
    awarded = true;
    purchaseOrders = [{ id: 'po_award_01', po_number: 'PO-202610-0001', total_amount: '34100.00', status: 'draft', status_label: 'Draft' }];
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: readRfq(), purchase_orders: purchaseOrders }) });
  });
}

async function mockSupplierSession(page: import('@playwright/test').Page): Promise<{
  quoteWrites: Array<{ submit: boolean; vat_treatment: string; items: Array<Record<string, unknown>> }>;
  uploads: string[];
}> {
  const quoteWrites: Array<{ submit: boolean; vat_treatment: string; items: Array<Record<string, unknown>> }> = [];
  const uploadedDocuments: Array<{ id: string; document_type: string; original_filename: string }> = [];
  const uploads: string[] = [];
  let supplierQuote: Record<string, unknown> | null = null;
  const readSupplierRfq = () => ({
    ...SUPPLIER_RFQ,
    quote: supplierQuote,
    my_documents: uploadedDocuments,
  });

  await page.route('**/api/v1/landing/contact', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ legal_name: 'Ogami Philippines', address: null }) });
  });
  await page.route('**/api/v1/b2b/supplier/me', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: SUPPLIER }) });
  });
  await page.route('**/api/v1/b2b/supplier/notifications?*', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: [], meta: { current_page: 1, last_page: 1, per_page: 8, total: 0, unread_count: 0 } }),
    });
  });
  await page.route('**/api/v1/b2b/supplier/business-policies', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { functional_currency_code: 'PHP', vat_rate: '12.00' } }) });
  });
  await page.route('**/api/v1/b2b/supplier/rfqs?*', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [readSupplierRfq()], meta: pagination }) });
  });
  await page.route('**/api/v1/b2b/supplier/rfqs', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [readSupplierRfq()], meta: pagination }) });
  });
  await page.route('**/api/v1/b2b/supplier/rfqs/rfq_demo_01', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: readSupplierRfq() }) });
  });
  await page.route('**/api/v1/b2b/supplier/rfqs/rfq_demo_01/quote', async (route) => {
    expect(route.request().method()).toBe('PUT');
    const payload = route.request().postDataJSON() as { submit: boolean; vat_treatment: string; items: Array<Record<string, unknown>> };
    quoteWrites.push(payload);
    supplierQuote = {
      ...SUPPLIER_QUOTE,
      status: payload.submit ? 'submitted' : 'draft',
      submitted_at: payload.submit ? new Date().toISOString() : null,
      quotation_original_filename: uploadedDocuments.find((doc) => doc.document_type === 'quotation_pdf')?.original_filename ?? null,
      documents: uploadedDocuments,
    };
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: supplierQuote }) });
  });
  await page.route('**/api/v1/b2b/supplier/rfqs/rfq_demo_01/documents', async (route) => {
    expect(route.request().method()).toBe('POST');
    const documentType = uploadedDocuments.length === 0 ? 'quotation_pdf' : 'certificate_of_analysis';
    const document = { id: 'doc_' + (uploadedDocuments.length + 1), document_type: documentType, original_filename: documentType === 'quotation_pdf' ? 'quote.pdf' : 'coa.pdf' };
    uploadedDocuments.push(document);
    uploads.push(documentType);
    await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: document }) });
  });
  return { quoteWrites, uploads };
}

test.describe('RFQ bidding role workflow', () => {
  test('Purchasing Officer can open the RFQ list and detail', async ({ page }) => {
    await mockRfqApi(page);
    await loginAs(page, 'purchasing', '/purchasing/rfqs');
    await expect(page.getByText('RFQ-202609-0001', { exact: true })).toBeVisible();
    await expect(page.getByText('Production resin sourcing', { exact: true })).toBeVisible();
    await page.getByText('RFQ-202609-0001', { exact: true }).click();
    await expect(page).toHaveURL(/\/purchasing\/rfqs\/rfq_demo_01$/);
    await expect(page.getByText('Polypropylene Resin Natural', { exact: false }).first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'Compare & award', exact: true })).toBeVisible();
  });

  test('Purchasing Officer can compare sealed quotations and award a selected line', async ({ page }) => {
    await mockRfqApi(page);
    await loginAs(page, 'purchasing', '/purchasing/rfqs/rfq_demo_01/compare');
    await expect(page.getByRole('heading', { name: 'Compare & award', exact: true })).toBeVisible();
    await expect(page.getByText('Alpha Polymers', { exact: false }).first()).toBeVisible();
    await expect(page.getByText('Beta Materials', { exact: false }).first()).toBeVisible();
    await page.getByRole('radio', { name: 'Award Polypropylene Resin Natural to Alpha Polymers' }).check();
    await page.getByLabel('Reason for this award').fill('Lowest compliant delivered cost with earlier delivery.');
    await page.getByRole('button', { name: 'Award & create draft POs', exact: true }).click();
    await expect(page.getByText('Awarded. 1 draft purchase order(s) created.', { exact: true })).toBeVisible();
    await expect(page).toHaveURL(/\/purchasing\/rfqs\/rfq_demo_01$/);
    await expect(page.getByRole('link', { name: 'PO-202610-0001', exact: true })).toBeVisible();
  });

  test('Finance Officer can compare sealed quotations but cannot award', async ({ page }) => {
    await mockRfqApi(page, { ...RFQ, actions: VIEW_ONLY_ACTIONS });
    await loginAs(page, 'finance', '/purchasing/rfqs/rfq_demo_01/compare');
    await expect(page.getByRole('heading', { name: 'Compare & award', exact: true })).toBeVisible();
    await expect(page.getByText('60.00', { exact: false })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Award & create draft POs', exact: true })).toHaveCount(0);
  });

  test('Purchasing Officer can select only one supplier for each RFQ line', async ({ page }) => {
    await mockRfqApi(page);
    await loginAs(page, 'purchasing', '/purchasing/rfqs/rfq_demo_01/compare');
    const alpha = page.getByRole('radio', { name: 'Award Polypropylene Resin Natural to Alpha Polymers' });
    const beta = page.getByRole('radio', { name: 'Award Polypropylene Resin Natural to Beta Materials' });
    await alpha.check();
    await beta.check();
    await expect(alpha).not.toBeChecked();
    await expect(beta).toBeChecked();
  });

  test('QC Inspector can review a comparison but cannot award', async ({ page }) => {
    await mockRfqApi(page, { ...RFQ, actions: VIEW_ONLY_ACTIONS });
    await loginAs(page, 'qc', '/purchasing/rfqs/rfq_demo_01/compare');
    await expect(page.getByText('60.00', { exact: false })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Award & create draft POs', exact: true })).toHaveCount(0);
  });

  test('QC Inspector can open RFQ requirements and the read-only comparison', async ({ page }) => {
    await mockRfqApi(page, { ...RFQ, actions: VIEW_ONLY_ACTIONS });
    await loginAs(page, 'qc', '/purchasing/rfqs/rfq_demo_01');
    await expect(page.getByText('Polypropylene Resin Natural', { exact: false }).first()).toBeVisible();
    await page.getByRole('button', { name: 'View comparison', exact: true }).click();
    await expect(page).toHaveURL(/\/purchasing\/rfqs\/rfq_demo_01\/compare$/);
    await expect(page.getByRole('heading', { name: 'Compare & award', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Award & create draft POs', exact: true })).toHaveCount(0);
  });

  test('Purchasing Officer creates a draft from the sourcing form', async ({ page }) => {
    await page.route('**/api/v1/purchasing/purchase-requests/pr_demo_01', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: PR }) });
    });
    await page.route('**/api/v1/purchasing/purchase-requests/pr_demo_01/rfq-setup', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: RFQ_SETUP }) });
    });
    await page.route('**/api/v1/purchasing/purchase-requests/pr_demo_01/rfqs', async (route) => {
      expect(route.request().method()).toBe('POST');
      const payload = route.request().postDataJSON() as { invitations: Array<{ vendor_id: string }>; title: string; publish: boolean; closes_at: string };
      expect(payload.title).toBe('Competitive resin quotation');
      expect(payload.invitations).toEqual([{ vendor_id: 'vendor_a' }]);
      expect(payload.publish).toBe(false);
      expect(new Date(payload.closes_at).getTime()).toBeGreaterThan(Date.now());
      await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: DRAFT_RFQ }) });
    });
    await page.route('**/api/v1/purchasing/rfqs/rfq_demo_01', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: DRAFT_RFQ }) });
    });
    await loginAs(page, 'purchasing', '/purchasing/rfqs/create?purchase_request=pr_demo_01');
    await expect(page.getByRole('heading', { name: 'Start supplier RFQ' })).toBeVisible();
    await page.getByLabel('Title').fill('Competitive resin quotation');
    await page.getByRole('checkbox').first().check();
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page).toHaveURL(/\/purchasing\/rfqs\/rfq_demo_01$/);
  });

  test('Finance can review automatic cancellation when no supplier quotes', async ({ page }) => {
    await mockRfqApi(page, NO_QUOTE_CANCELLED_RFQ);
    await loginAs(page, 'finance', '/purchasing/rfqs/rfq_demo_01');
    await expect(page.getByText('No quotations were received before the deadline.', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'View comparison', exact: true })).toHaveCount(0);
  });

  test('Warehouse and Employee roles cannot access internal RFQs', async ({ page }) => {
    await loginAs(page, 'warehouse', '/purchasing/rfqs');
    await expect(page.getByText('Page not found', { exact: true })).toBeVisible();
    const employee = await page.context().newPage();
    await loginAs(employee, 'employee', '/purchasing/rfqs');
    await expect(employee.getByText('Page not found', { exact: true })).toBeVisible();
  });

  test('Supplier portal saves a draft, uploads its quotation, and submits before closure', async ({ page }) => {
    const { quoteWrites, uploads } = await mockSupplierSession(page);
    await page.goto('/portal/supplier/rfqs');
    await expect(page.getByText('RFQ-202610-0001', { exact: true })).toBeVisible();
    await page.getByText('RFQ-202610-0001', { exact: true }).click();
    await page.getByRole('link', { name: 'Prepare quotation' }).click();
    await page.getByLabel('Quantity').fill('500');
    await page.getByLabel(/Unit price/).fill('60.00');
    await page.getByLabel('Can deliver by').fill('2026-10-15');
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByText('Draft saved. Ogami cannot see it until you submit.', { exact: true })).toBeVisible();
    await page.getByLabel(/Quotation PDF/).setInputFiles({ name: 'quote.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 demo') });
    await page.getByLabel('Certificate of analysis (optional)').setInputFiles({ name: 'coa.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 coa') });
    await page.getByRole('button', { name: 'Submit quotation', exact: true }).click();
    await expect(page).toHaveURL(/\/portal\/supplier\/rfqs\/rfq_demo_01$/);
    expect(quoteWrites.map((write) => write.submit)).toEqual([false, true]);
    expect(quoteWrites[1].items[0]).toMatchObject({ request_for_quote_item_id: 'rfq_line_01', response_status: 'quoted', offered_quantity: '500', unit_price: '60.0000', proposed_delivery_date: '2026-10-15' });
    expect(uploads).toEqual(['quotation_pdf', 'certificate_of_analysis']);
  });
});
