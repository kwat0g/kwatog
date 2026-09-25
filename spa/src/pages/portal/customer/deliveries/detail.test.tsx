import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import CustomerDeliveryDetailPage from './detail';
import { customerPortalApi } from '@/api/b2b/customer';
import type { PortalDeliveryDetail } from '@/types/b2b';

/**
 * O2C audit 2026-09-25 — "Confirm Receipt" showed on every delivered shipment
 * and answered 422 until the driver's proof existed, which the customer cannot
 * upload. The page now follows the server's `can_confirm` flag and explains
 * the wait instead.
 */
vi.mock('@/api/b2b/customer', () => ({
  customerPortalApi: { getDelivery: vi.fn(), confirmDelivery: vi.fn(), viewDeliveryProof: vi.fn() },
}));
vi.mock('react-router-dom', async (importOriginal) => ({
  ...(await importOriginal<typeof import('react-router-dom')>()),
  useParams: () => ({ id: 'dr-1' }),
}));

const delivered = {
  id: 'dr-1',
  delivery_number: 'DR-202609-0001',
  delivered_at: '2026-09-25T08:00:00Z',
  status: 'delivered',
  status_label: 'Delivered',
  scheduled_date: '2026-09-25',
  sales_order: { id: 'so-1', so_number: 'SO-202609-0001' },
  items: [],
  proofs: [],
  receiver_name: null,
  confirmed_at: null,
  driver: null,
} as PortalDeliveryDetail;

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <CustomerDeliveryDetailPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('CustomerDeliveryDetailPage', () => {
  it('hides Confirm Receipt and explains the wait until a proof of delivery exists', async () => {
    vi.mocked(customerPortalApi.getDelivery).mockResolvedValue({ ...delivered, can_confirm: false });

    renderPage();

    expect(await screen.findByText(/Awaiting our driver's proof of delivery/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Confirm Receipt/ })).not.toBeInTheDocument();
  });

  it('offers Confirm Receipt once the server says the shipment can be confirmed', async () => {
    vi.mocked(customerPortalApi.getDelivery).mockResolvedValue({ ...delivered, can_confirm: true });

    renderPage();

    expect(await screen.findByRole('button', { name: /Confirm Receipt/ })).toBeInTheDocument();
    expect(screen.queryByText(/Awaiting our driver's proof of delivery/)).not.toBeInTheDocument();
  });
  it('links to an open problem report instead of asking for another proof', async () => {
    vi.mocked(customerPortalApi.getDelivery).mockResolvedValue({
      ...delivered, can_confirm: false,
      billing_hold: { case_id: 'case-1', case_number: 'CASE-1', message: 'Awaiting resolution.' },
    });
    renderPage();
    expect(await screen.findByRole('link', { name: 'CASE-1' })).toHaveAttribute('href', '/portal/customer/problems/case-1');
    expect(screen.queryByRole('button', { name: /Confirm Receipt/ })).not.toBeInTheDocument();
    expect(screen.queryByText(/Awaiting our driver's proof of delivery/)).not.toBeInTheDocument();
  });

});
