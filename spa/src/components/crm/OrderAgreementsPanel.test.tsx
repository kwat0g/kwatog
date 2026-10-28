import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { salesOrdersApi } from '@/api/crm/salesOrders';
import { customerPortalApi } from '@/api/b2b/customer';
import { OrderAgreementsPanel } from './OrderAgreementsPanel';
import type { OrderAgreement, OrderAgreements } from '@/types/orderAgreements';

vi.mock('@/api/crm/salesOrders', () => ({ salesOrdersApi: {
  agreements: vi.fn(), publishAgreement: vi.fn(), recordAgreementAcknowledgment: vi.fn(), agreementFile: vi.fn(),
} }));
vi.mock('@/api/b2b/customer', () => ({ customerPortalApi: {
  agreements: vi.fn(), acknowledgeAgreement: vi.fn(), agreementFile: vi.fn(),
} }));

const agreement = (overrides: Partial<OrderAgreement> = {}): OrderAgreement => ({
  id: 'revision-hash', revision: 2, terms: 'Payment due in 30 days.',
  snapshot: { customer_id: 'customer-hash', payment_terms_days: 30, delivery_terms: 'Customer pickup', incoterm: null, subtotal: '100.00', vat_amount: '12.00', total_amount: '112.00', items: [{ product_id: 'product-hash', product_label: 'WB-001 · Wiper bushing', quantity: '10.000', unit_price: '10.00', delivery_date: '2026-11-01' }] },
  published_at: '2026-10-28T10:00:00Z', published_by: 'Sales User', file_name: null, acknowledgment: null, is_current: true, is_stale: false, ...overrides,
});
const payload = (revisions: OrderAgreement[], capabilities: Partial<OrderAgreements> = {}): OrderAgreements => ({ sales_order_id: 'order-hash', so_number: 'SO-42', can_publish: false, can_record_external: false, can_acknowledge: false, revisions, ...capabilities });
function renderPanel(props: React.ComponentProps<typeof OrderAgreementsPanel>) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const result = render(<QueryClientProvider client={client}><OrderAgreementsPanel {...props} /></QueryClientProvider>);
  return { ...result, client };
}

beforeEach(() => { vi.mocked(salesOrdersApi.agreements).mockResolvedValue(payload([])); vi.mocked(customerPortalApi.agreements).mockResolvedValue(payload([])); });
afterEach(() => { cleanup(); vi.clearAllMocks(); });

it('directs users to customer terms when no order-specific revision exists', async () => {
  vi.mocked(salesOrdersApi.agreements).mockResolvedValue(payload([]));
  renderPanel({ orderId: 'order-hash', realm: 'internal' });
  expect(await screen.findByText('No terms were recorded on this order. Set customer terms on the Customer profile.')).toBeVisible();
  expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /publish new revision/i })).not.toBeInTheDocument();
});

it('shows current text and snapshot, with prior revisions preserved in disclosures', async () => {
  vi.mocked(salesOrdersApi.agreements).mockResolvedValue(payload([agreement(), agreement({ id: 'old', revision: 1, terms: 'Earlier terms.', is_current: false })]));
  renderPanel({ orderId: 'order-hash', realm: 'internal' });
  expect(await screen.findByText('Payment due in 30 days.')).toBeVisible();
  expect(screen.getAllByText('30 days')).toHaveLength(2);
  expect(screen.getAllByText(/WB-001 · Wiper bushing/)).toHaveLength(2);
  expect(screen.getByText('Earlier terms.')).toBeInTheDocument();
});

it('shows saved customer payment, freight and warranty terms on the order', () => {
  const data = payload([], {
    payment_method: 'bank_transfer',
    customer_terms_snapshot: {
      agreement_status: 'in_effect', agreement_starts_on: '2026-10-01', agreement_ends_on: '2027-10-01',
      allowed_payment_methods: ['cash', 'check', 'bank_transfer'], shipping_payer: 'customer', payment_terms_days: 30,
      delivery_terms: null, incoterm: null,
      warranty_defaults: { warranty_duration_days: 30, warranty_report_window_days: 7, warranty_start_basis: 'delivery', warranty_covered_issues: ['manufacturing_defect'], warranty_remedies: ['replace'] },
    },
    warranty_terms: [{ product_id: 'product-hash', product_label: 'WB-001 · Wiper bushing', terms: { warranty_duration_days: 30, warranty_report_window_days: 7, warranty_start_basis: 'delivery', warranty_covered_issues: ['manufacturing_defect'], warranty_remedies: ['replace'] } }],
  });
  renderPanel({ orderId: 'order-hash', realm: 'customer', readOnlyData: data });
  expect(screen.getByText('Bank transfer')).toBeVisible();
  expect(screen.getByText('Customer (fee quoted separately)')).toBeVisible();
  expect(screen.getByText('Freight is not included in the order total. Sales must confirm any customer-paid charge separately.')).toBeVisible();
  expect(screen.getAllByText('30 days')).toHaveLength(2);
  expect(screen.getByText('7 days')).toBeVisible();
});

it('requires explicit review and acknowledges the displayed current revision', async () => {
  vi.mocked(customerPortalApi.agreements).mockResolvedValue(payload([agreement()], { can_acknowledge: true }));
  vi.mocked(customerPortalApi.acknowledgeAgreement).mockResolvedValue(payload([agreement({ acknowledgment: { method: 'portal', representative: 'Customer User', acknowledged_at: '2026-10-28T11:00:00Z', recorded_at: '2026-10-28T11:00:00Z', recorded_by: 'Customer User', reference: null } })]));
  renderPanel({ orderId: 'order-hash', realm: 'customer' });
  fireEvent.click(await screen.findByRole('checkbox', { name: /reviewed the current terms/i }));
  fireEvent.click(screen.getByRole('button', { name: 'Acknowledge terms' }));
  await waitFor(() => expect(customerPortalApi.acknowledgeAgreement).toHaveBeenCalledWith('order-hash', 'revision-hash'));
});

it('labels external acknowledgment as staff-recorded evidence', async () => {
  const externalAgreement = agreement({ acknowledgment: { method: 'external', representative: 'Pat Lee', acknowledged_at: '2026-10-28T09:00:00Z', recorded_at: '2026-10-28T10:00:00Z', recorded_by: 'Sales User', reference: 'Email confirmation' } });
  vi.mocked(salesOrdersApi.agreements).mockResolvedValue(payload([externalAgreement]));
  renderPanel({ orderId: 'order-hash', realm: 'internal' });
  expect(await screen.findByText('External acknowledgment recorded by staff')).toBeVisible();
  expect(screen.getByText('Evidence/reference: Email confirmation')).toBeVisible();
});

it('disables portal acknowledgment for stale current terms', async () => {
  vi.mocked(customerPortalApi.agreements).mockResolvedValue(payload([agreement({ is_stale: true })], { can_acknowledge: true }));
  renderPanel({ orderId: 'order-hash', realm: 'customer' });
  expect(await screen.findByText(/these terms are stale/i)).toBeVisible();
  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
});

it('renders supplied return data read-only without fetching or mutation controls', () => {
  const data = payload([agreement()], { can_publish: true, can_record_external: true, can_acknowledge: true });
  renderPanel({ orderId: 'order-hash', realm: 'internal', readOnlyData: data });
  expect(screen.getByText('Payment due in 30 days.')).toBeVisible();
  expect(screen.queryByRole('button', { name: /publish|record external|acknowledge terms/i })).not.toBeInTheDocument();
  expect(salesOrdersApi.agreements).not.toHaveBeenCalled();
  expect(customerPortalApi.agreements).not.toHaveBeenCalled();
  expect(salesOrdersApi.publishAgreement).not.toHaveBeenCalled();
  expect(salesOrdersApi.recordAgreementAcknowledgment).not.toHaveBeenCalled();
  expect(customerPortalApi.acknowledgeAgreement).not.toHaveBeenCalled();
});

it('downloads the authorized attachment and releases its object URL', async () => {
  const create = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:agreement');
  const revoke = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);
  const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
  vi.mocked(salesOrdersApi.agreementFile).mockResolvedValue(new Blob(['private file'], { type: 'application/pdf' }));
  renderPanel({ orderId: 'order-hash', realm: 'internal', readOnlyData: payload([agreement({ file_name: 'terms.pdf' })]) });
  fireEvent.click(screen.getByRole('button', { name: 'Download attachment' }));
  await waitFor(() => expect(salesOrdersApi.agreementFile).toHaveBeenCalledWith('order-hash', 'revision-hash'));
  await waitFor(() => expect(create).toHaveBeenCalledWith(expect.any(Blob)));
  expect(click).toHaveBeenCalledOnce();
  expect(revoke).toHaveBeenCalledWith('blob:agreement');
  create.mockRestore();
  revoke.mockRestore();
  click.mockRestore();
});

it('invalidates agreement and order detail caches after portal acknowledgment', async () => {
  vi.mocked(customerPortalApi.agreements).mockResolvedValue(payload([agreement()], { can_acknowledge: true }));
  vi.mocked(customerPortalApi.acknowledgeAgreement).mockResolvedValue(payload([agreement()]));
  const { client } = renderPanel({ orderId: 'order-hash', realm: 'customer' });
  const invalidate = vi.spyOn(client, 'invalidateQueries');
  fireEvent.click(await screen.findByRole('checkbox', { name: /reviewed the current terms/i }));
  fireEvent.click(screen.getByRole('button', { name: 'Acknowledge terms' }));
  await waitFor(() => expect(customerPortalApi.acknowledgeAgreement).toHaveBeenCalledWith('order-hash', 'revision-hash'));
  await waitFor(() => expect(invalidate).toHaveBeenCalledWith({ queryKey: ['order-agreements', 'customer', 'order-hash'] }));
  expect(invalidate).toHaveBeenCalledWith({ queryKey: ['portal', 'customer', 'order', 'order-hash'] });
});
