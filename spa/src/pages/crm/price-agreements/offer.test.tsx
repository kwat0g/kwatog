import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { pricingRequestsApi } from '@/api/crm/pricingRequests';
import type { PriceRequest } from '@/types/pricing';
import PricingOfferPage from './offer';

vi.mock('@/api/crm/pricingRequests', () => ({ pricingRequestsApi: { show: vi.fn() } }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeRecord: vi.fn() }));
vi.mock('./form', () => ({ PriceAgreementForm: () => <div>Offer terms form</div> }));

const request = {
  id: 'request-1',
  product: { id: 'product-1', part_number: 'PC-001', name: 'Pivot Cap Cover', unit_of_measure: 'pcs' },
  customer: { id: 'customer-1', name: 'Demo Customer' },
  quantity: 500,
  requested_price: '48.00',
  notes: 'Planned volume order.',
  status: 'requested',
  version: 1,
  available_actions: { can_propose: true },
} as PriceRequest;

function renderPage() {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/crm/price-agreements/requests/request-1/offer']}>
        <Routes>
          <Route path="/crm/price-agreements/requests/:id/offer" element={<PricingOfferPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(pricingRequestsApi.show).mockResolvedValue(request);
});

afterEach(cleanup);

describe('pricing counteroffer context', () => {
  it('shows Sales the requested target and quantity rule for the final offer', async () => {
    renderPage();

    expect(await screen.findByText(/Customer target:/i)).toHaveTextContent('48.00 per pcs at planned quantity 500 pcs');
    expect(screen.getByText(/The requested quantity does not set a discount threshold/i)).toBeInTheDocument();
    expect(screen.getByText(/choose tiered pricing and set the qualifying minimum explicitly/i)).toBeInTheDocument();
    expect(screen.getByText(/A flat price applies at every quantity/i)).toBeInTheDocument();
    expect(screen.getByText(/You may counteroffer a different unit price/i)).toBeInTheDocument();
  });
});
