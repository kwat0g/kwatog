import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { accountingOptionsApi } from '@/api/accounting/options';
import { invoicesApi } from '@/api/accounting/invoices';
import type { Invoice } from '@/types/accounting';
import InvoiceDetailPage from './detail';

vi.mock('@/api/accounting/options', () => ({ accountingOptionsApi: { list: vi.fn() } }));
vi.mock('@/api/accounting/invoices', () => ({
  invoicesApi: {
    show: vi.fn(),
    finalize: vi.fn(),
    approvePrebill: vi.fn(),
    cancel: vi.fn(),
    recordCollection: vi.fn(),
    pdfUrl: vi.fn(),
  },
}));
vi.mock('@/hooks/useChainProgress', () => ({ useChainProgress: () => {} }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('@/stores/authStore', () => ({
  useAuthStore: (selector: (state: { user: { id: string } }) => unknown) =>
    selector({ user: { id: 'poster' } }),
}));

function invoice(delivery: Invoice['delivery'] = null): Invoice {
  return {
    id: 'invoice-1',
    invoice_number: null,
    date: '2026-10-02',
    due_date: '2026-11-01',
    lifecycle_type: 'standard',
    sales_order: { id: 'so-1', so_number: 'SO-1' },
    delivery,
    created_by: { id: 'maker', name: 'Invoice maker' },
    is_vatable: false,
    subtotal: '100.00',
    vat_amount: '0.00',
    total_amount: '100.00',
    amount_paid: '0.00',
    balance: '100.00',
    status: 'draft',
    display_status: 'draft',
    is_overdue: false,
    aging_bucket: 'current',
    remarks: null,
    customer: { id: 'customer-1', name: 'Test customer' },
    items: [],
    collections: [],
  };
}

function renderPage(value: Invoice) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  vi.mocked(invoicesApi.show).mockResolvedValue(value);
  vi.mocked(accountingOptionsApi.list).mockResolvedValue({
    payment_methods: [],
    account_types: [],
    normal_balances: [],
    credit_note_types: [],
    credit_note_statuses: [],
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/accounting/invoices/invoice-1']}>
        <Routes>
          <Route path="/accounting/invoices/:id" element={<InvoiceDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('standard invoice delivery gate', () => {
  beforeEach(() => vi.clearAllMocks());

  it('shows invoice history and does not present a cancelled approval as active', async () => {
    const cancelledPrebill = {
      ...invoice(),
      status: 'cancelled',
      display_status: 'cancelled',
      lifecycle_type: 'prebill',
      prebill_approved_at: '2026-10-02T10:00:00Z',
      prebill_approved_by: { id: 'checker', name: 'Finance checker' },
      status_history: [
        {
          event_type: 'created' as const,
          from_status: null,
          to_status: 'draft' as const,
          actor_name: 'Finance maker',
          actor_type: 'user',
          occurred_at: '2026-10-02T09:00:00Z',
        },
        {
          event_type: 'prebill_approved' as const,
          from_status: 'draft' as const,
          to_status: 'draft' as const,
          actor_name: 'Finance checker',
          actor_type: 'user',
          occurred_at: '2026-10-02T10:00:00Z',
        },
        {
          event_type: 'status_changed' as const,
          from_status: 'draft' as const,
          to_status: 'cancelled' as const,
          actor_name: 'Finance poster',
          actor_type: 'user',
          occurred_at: '2026-10-02T11:00:00Z',
        },
      ],
    } as Invoice;

    renderPage(cancelledPrebill);

    expect(await screen.findByText('Activity')).toBeInTheDocument();
    expect(screen.getByText(/Invoice draft created\./)).toBeInTheDocument();
    expect(screen.getByText(/Prebill approved\./)).toBeInTheDocument();
    expect(screen.getByText(/Status changed from draft to cancelled\./)).toBeInTheDocument();
    expect(screen.getByText(/By Finance checker/)).toBeInTheDocument();
    expect(screen.getByText(/Cancelled after prebill approval/i)).toBeInTheDocument();
    expect(screen.queryByText(/Approved by Finance checker/)).not.toBeInTheDocument();
  });

  it('explains why an unlinked standard draft cannot be finalized', async () => {
    renderPage(invoice());

    expect(await screen.findByText('DRAFT')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Finalize' })).not.toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent('No delivery is linked');
  });

  it('keeps finalization blocked until the linked delivery is confirmed', async () => {
    renderPage(invoice({
      id: 'delivery-1',
      delivery_number: 'DR-1',
      status: 'loading',
    } as Invoice['delivery']));

    expect(await screen.findByText('DRAFT')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Finalize' })).not.toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent('Delivery DR-1 is loading');
  });

  it.each([
    ['return_pending', 'Resolve the return and confirm delivery before finalizing'],
    ['returned', 'Cancel this draft, complete a redelivery, and invoice its confirmed delivery'],
    ['cancelled', 'Cancel this draft and use a new confirmed delivery for invoicing'],
  ])('explains the recovery for a %s delivery', async (status, recovery) => {
    renderPage(invoice({
      id: 'delivery-1',
      delivery_number: 'DR-1',
      status,
    } as Invoice['delivery']));

    expect(await screen.findByText('DRAFT')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Finalize' })).not.toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent(recovery);
  });

  it('offers finalization when the linked delivery is confirmed', async () => {
    renderPage(invoice({
      id: 'delivery-1',
      delivery_number: 'DR-1',
      status: 'confirmed',
    } as Invoice['delivery']));

    expect(await screen.findByRole('button', { name: 'Finalize' })).toBeEnabled();
  });
});

it('hides ordinary collection before evidence and shows one review action afterward', async () => {
  const pending = { id: 'payment-1', status: 'pending' as const, amount: '100.00', sending_bank: 'BDO', reference_number: 'REF-1', payment_date: '2026-10-09', proof_file_name: 'proof.pdf', proof_mime: 'application/pdf', proof_size: 100, submitted_at: '2026-10-09T10:00:00Z', reviewed_at: null, rejection_reason: null, collection_id: null };
  const first = renderPage({ ...invoice(), status: 'finalized', display_status: 'unpaid', payment_submissions: [] });
  await screen.findByText('Awaiting customer payment submission.');
  expect(screen.queryByRole('button', { name: 'Record collection' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Review payment' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();
  expect(screen.getByText('Awaiting customer payment')).toBeInTheDocument();
  first.unmount();
  renderPage({ ...invoice(), status: 'finalized', display_status: 'unpaid', payment_submissions: [pending] });
  expect(await screen.findByRole('button', { name: 'Review payment' })).toBeEnabled();
  expect(screen.getByText('Awaiting payment verification')).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Record collection' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Record exceptional collection' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();
});
