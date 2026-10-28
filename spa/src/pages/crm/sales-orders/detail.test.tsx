import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { salesOrdersApi } from '@/api/crm/salesOrders';
import type { SalesOrder, SalesOrderChainStep } from '@/types/crm';
import SalesOrderDetailPage from './detail';

vi.mock('@/api/crm/salesOrders', () => ({
  salesOrdersApi: {
    show: vi.fn(),
    agreements: vi.fn().mockResolvedValue({ sales_order_id: 'so-1', so_number: 'SO-1', can_publish: false, can_record_external: false, can_acknowledge: false, revisions: [] }),
    agreementFile: vi.fn(),
    publishAgreement: vi.fn(),
    recordAgreementAcknowledgment: vi.fn(),
    chain: vi.fn(),
    confirm: vi.fn(),
    cancel: vi.fn(),
    proposeDeliveryDate: vi.fn(),
  },
}));
vi.mock('@/api/accounting/invoices', () => ({ invoicesApi: { finalize: vi.fn() } }));
vi.mock('@/hooks/useChainProgress', () => ({ useChainProgress: () => undefined }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => false }) }));

const order = (status_history: unknown[] = []): SalesOrder =>
  ({
    id: 'so-1',
    so_number: 'SO-202610-0001',
    date: '2026-10-01',
    subtotal: '100.00',
    vat_amount: '12.00',
    total_amount: '112.00',
    status: 'cancelled',
    status_label: 'Cancelled',
    payment_terms_days: 30,
    delivery_terms: null,
    incoterm: null,
    notes: null,
    is_editable: false,
    is_cancellable: false,
    item_count: 0,
    customer: { id: 'customer-1', name: 'Test customer' },
    creator: { id: 'user-1', name: 'Sales maker' },
    items: [],
    created_at: '2026-10-01T09:00:00Z',
    updated_at: '2026-10-02T14:00:00Z',
    status_history,
  }) as unknown as SalesOrder;

function renderPage(value: SalesOrder, steps: SalesOrderChainStep[] = []) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  vi.mocked(salesOrdersApi.show).mockResolvedValue(value);
  vi.mocked(salesOrdersApi.chain).mockResolvedValue(steps);

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/crm/sales-orders/so-1']}>
        <Routes>
          <Route path="/crm/sales-orders/:id" element={<SalesOrderDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('SalesOrderDetailPage activity', () => {
  beforeEach(() => vi.clearAllMocks());

  const textContent = (value: string) => (_: string, element: Element | null) =>
    element?.textContent === value;

  it('renders recorded status events with actors and cancellation comment', async () => {
    renderPage(
      order([
        {
          event_type: 'created',
          status: 'draft',
          status_label: 'Draft',
          previous_status: null,
          previous_status_label: null,
          actor_name: 'Sales maker',
          occurred_at: '2026-10-01T09:00:00Z',
          comment: null,
        },
        {
          event_type: 'status_changed',
          status: 'confirmed',
          status_label: 'Confirmed',
          previous_status: 'draft',
          previous_status_label: 'Draft',
          actor_name: 'Sales checker',
          occurred_at: '2026-10-01T10:00:00Z',
          comment: null,
        },
        {
          event_type: 'status_changed',
          status: 'cancelled',
          status_label: 'Cancelled',
          previous_status: 'confirmed',
          previous_status_label: 'Confirmed',
          actor_name: 'Sales manager',
          occurred_at: '2026-10-02T14:00:00Z',
          comment: 'Customer withdrew',
        },
      ]),
    );

    expect(
      await screen.findByText(textContent('Sales order SO-202610-0001 created with status Draft.')),
    ).toBeInTheDocument();
    expect(
      screen.getByText(textContent('Status changed from Draft to Confirmed.')),
    ).toBeInTheDocument();
    expect(
      screen.getByText(textContent('Status changed from Confirmed to Cancelled.')),
    ).toBeInTheDocument();
    expect(screen.getByText(/By Sales checker/)).toBeInTheDocument();
    expect(screen.getByText(/Customer withdrew/)).toBeInTheDocument();
    expect(screen.queryByText('Status: Cancelled')).not.toBeInTheDocument();
  });

  it('explains when legacy orders have no recorded status history', async () => {
    renderPage(order());

    expect(await screen.findByText('No status changes have been recorded.')).toBeInTheDocument();
  });
});

it('shows required delivery date and pending proposal without commercial edit controls', async () => {
  renderPage({
    ...order(),
    status: 'draft',
    status_label: 'Draft',
    submission_source: 'customer_portal',
    requested_delivery_dates: ['2026-10-10'],
    delivery_dates: ['2026-10-10'],
    capabilities: { can_propose_delivery: true, awaiting_delivery_response: true },
    latest_response: {
      id: 'proposal-1',
      type: 'delivery_date',
      status: 'pending',
      proposed_delivery_date: '2026-10-14',
      notes: 'Capacity delay',
    },
  });
  expect(await screen.findByText('Required delivery date')).toBeVisible();
  expect(
    screen.getByText('Waiting for customer acceptance. Confirmation is blocked.'),
  ).toBeVisible();
  expect(screen.queryByRole('button', { name: /^Edit$/ })).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Propose delivery date' })).toBeVisible();
});

it('shows delivery readiness from completed production and QC', async () => {
  renderPage({ ...order(), status: 'in_production', status_label: 'In Production' }, [
    { key: 'in_production', label: 'In Production', state: 'done', date: '2026-10-09' },
    { key: 'qc_outgoing', label: 'QC Outgoing', state: 'done', date: '2026-10-09' },
    { key: 'delivered', label: 'Delivered', state: 'active', date: null },
  ]);
  expect(await screen.findByText('Ready for delivery')).toBeVisible();
});

it('shows awaiting QC after production completes', async () => {
  renderPage({ ...order(), status: 'in_production', status_label: 'In Production' }, [
    { key: 'in_production', label: 'In Production', state: 'done', date: '2026-10-09' },
    { key: 'qc_outgoing', label: 'QC Outgoing', state: 'active', date: null },
  ]);
  expect(await screen.findByText('Awaiting QC')).toBeVisible();
});
