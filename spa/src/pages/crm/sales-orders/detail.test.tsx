import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { salesOrdersApi } from '@/api/crm/salesOrders';
import type { SalesOrder } from '@/types/crm';
import SalesOrderDetailPage from './detail';

vi.mock('@/api/crm/salesOrders', () => ({
  salesOrdersApi: {
    show: vi.fn(),
    chain: vi.fn(),
    confirm: vi.fn(),
    cancel: vi.fn(),
  },
}));
vi.mock('@/api/accounting/invoices', () => ({ invoicesApi: { finalize: vi.fn() } }));
vi.mock('@/hooks/useChainProgress', () => ({ useChainProgress: () => undefined }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => false }) }));

const order = (status_history: unknown[] = []): SalesOrder => ({
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
} as unknown as SalesOrder);

function renderPage(value: SalesOrder) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  vi.mocked(salesOrdersApi.show).mockResolvedValue(value);
  vi.mocked(salesOrdersApi.chain).mockResolvedValue([]);

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

  const textContent = (value: string) => (_: string, element: Element | null) => element?.textContent === value;

  it('renders recorded status events with actors and cancellation comment', async () => {
    renderPage(order([
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
    ]));

    expect(await screen.findByText(textContent('Sales order SO-202610-0001 created with status Draft.'))).toBeInTheDocument();
    expect(screen.getByText(textContent('Status changed from Draft to Confirmed.'))).toBeInTheDocument();
    expect(screen.getByText(textContent('Status changed from Confirmed to Cancelled.'))).toBeInTheDocument();
    expect(screen.getByText(/By Sales checker/)).toBeInTheDocument();
    expect(screen.getByText(/Customer withdrew/)).toBeInTheDocument();
    expect(screen.queryByText('Status: Cancelled')).not.toBeInTheDocument();
  });

  it('explains when legacy orders have no recorded status history', async () => {
    renderPage(order());

    expect(await screen.findByText('No status changes have been recorded.')).toBeInTheDocument();
  });
});
