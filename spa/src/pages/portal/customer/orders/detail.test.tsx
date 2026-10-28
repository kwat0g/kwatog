import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { customerPortalApi } from '@/api/b2b/customer';
import CustomerOrderDetailPage from './detail';

vi.mock('react-router-dom', async (importOriginal) => ({
  ...(await importOriginal<typeof import('react-router-dom')>()),
  useParams: () => ({ id: 'so-1' }),
}));
vi.mock('@/api/b2b/customer', () => ({
  customerPortalApi: {
    getOrder: vi.fn(),
    getOrderChain: vi.fn(),
    agreements: vi.fn().mockResolvedValue({ sales_order_id: 'so-1', so_number: 'SO-1', can_publish: false, can_record_external: false, can_acknowledge: false, revisions: [] }),
    cancelOrder: vi.fn(),
    respondToOrder: vi.fn(),
    respondToDeliveryProposal: vi.fn(),
  },
}));
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

it('shows a failed order process read and retries it without hiding the order', async () => {
  vi.mocked(customerPortalApi.getOrder).mockResolvedValue({
    id: 'so-1', so_number: 'SO-1', date: '2026-10-04', status: 'confirmed',
    total_amount: '112.00', created_at: '2026-10-04',
  });
  vi.mocked(customerPortalApi.getOrderChain).mockRejectedValueOnce(new Error('Process unavailable')).mockResolvedValue([]);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><CustomerOrderDetailPage /></MemoryRouter></QueryClientProvider>);
  expect(await screen.findByRole('alert')).toHaveTextContent('Order progress could not be loaded');
  expect(screen.getByText('SO-1')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Retry order progress' }));
  await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
  client.clear();
});

it('renders part numbers and descriptions from the sales order product relation', async () => {
  vi.mocked(customerPortalApi.getOrderChain).mockResolvedValue([]);
  vi.mocked(customerPortalApi.getOrder).mockResolvedValue({
    id: 'so-1',
    so_number: 'SO-1',
    date: '2026-10-04',
    status: 'draft',
    subtotal: '2250.00',
    vat_amount: '270.00',
    total_amount: '2520.00',
    created_at: '2026-10-04',
    capabilities: { can_edit: true, can_cancel: true, can_respond: false, can_confirm: false },
    items: [
      {
        id: 'item-1',
        product: {
          id: 'product-1',
          part_number: 'WB-001',
          name: 'Standard Wiper Bushing',
          unit_of_measure: 'pcs',
        },
        quantity: '100',
        unit_price: '22.50',
        total: '2250.00',
        delivery_date: '2026-10-10',
      },
    ],
  });
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <MemoryRouter>
        <CustomerOrderDetailPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
  const row = await screen.findByRole('row', { name: /WB-001/ });
  expect(within(row).getByText('Standard Wiper Bushing')).toBeInTheDocument();
  expect(within(row).getByText('100')).toBeInTheDocument();
  expect(screen.getByText(/Includes .*270.00 VAT/)).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Edit order' })).toBeVisible();
  expect(screen.getByRole('button', { name: 'Cancel order' })).toBeVisible();
});

it('shows requested and proposed dates and asks the customer to accept or decline', async () => {
  vi.mocked(customerPortalApi.getOrderChain).mockResolvedValue([]);
  vi.mocked(customerPortalApi.respondToDeliveryProposal).mockResolvedValue({ data: null });
  vi.mocked(customerPortalApi.getOrder).mockResolvedValue({
    id: 'so-1',
    so_number: 'SO-1',
    date: '2026-10-04',
    status: 'draft',
    total_amount: '112.00',
    created_at: '2026-10-04',
    requested_delivery_dates: ['2026-10-10'],
    delivery_dates: ['2026-10-10'],
    capabilities: { can_respond: false, can_confirm: false, can_respond_delivery: true },
    latest_response: {
      id: 'proposal-1',
      type: 'delivery_date',
      status: 'pending',
      proposed_delivery_date: '2026-10-14',
      notes: 'Capacity delay',
      responded_at: null,
      resolved_at: null,
      resolution_notes: null,
      items: [],
    },
  });
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <MemoryRouter>
        <CustomerOrderDetailPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
  expect(await screen.findByText('Required delivery date')).toBeVisible();
  expect(screen.getByText('Capacity delay')).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Accept proposed date' }));
  expect(customerPortalApi.respondToDeliveryProposal).not.toHaveBeenCalled();
  const dialog = screen.getByRole('dialog');
  expect(within(dialog).getByText(/Sales will still need to confirm/)).toBeVisible();
  fireEvent.click(within(dialog).getByRole('button', { name: 'Confirm acceptance' }));
  await waitFor(() =>
    expect(customerPortalApi.respondToDeliveryProposal).toHaveBeenCalledWith(
      'so-1',
      'proposal-1',
      'accept',
    ),
  );
  expect(screen.getByRole('button', { name: 'Decline proposed date' })).toBeVisible();
});
