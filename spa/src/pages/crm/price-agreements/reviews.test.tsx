import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { pricingRequestsApi } from '@/api/crm/pricingRequests';
import { useAuthStore } from '@/stores/authStore';
import type { ItemCostReview, PriceRequest } from '@/types/pricing';
import PricingRequestsPage from './requests';
import CostReviewsPage from './cost-reviews';

vi.mock('@/api/crm/pricingRequests', () => ({
  pricingRequestsApi: {
    list: vi.fn(),
    show: vi.fn(),
    approve: vi.fn(),
    withdraw: vi.fn(),
    costs: vi.fn(),
    decideCost: vi.fn(),
    renewCost: vi.fn(),
  },
}));
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  useAuthStore.setState({ permissions: new Set(), user: null });
});
const request: PriceRequest = {
  id: 'request-1',
  product: {
    id: 'product-1',
    part_number: 'WB-001',
    name: 'Wiper Bushing',
    unit_of_measure: 'PCS',
  },
  customer: { id: 'customer-1', name: 'Toyota' },
  quantity: 100,
  requested_price: '25.00',
  notes: 'Customer needs volume pricing',
  status: 'pending_finance',
  version: 1,
  offer: {
    price: '30.00',
    pricing_method: 'tiered',
    tiers: [{ min_qty: 100, unit_price: '25.00' }],
    effective_from: '2026-10-04',
    effective_to: '2026-12-31',
    notes: 'Agreed delivery volume',
  },
};
const reviewed: PriceRequest = {
  ...request,
  version: 2,
  cost_review: {
    signature: 'current-signature',
    cost: '20.00',
    incomplete: false,
    minimum_price: '22.00',
    base_price: '30.00',
  },
};
const cost: ItemCostReview = {
  id: 'cost-1',
  item: { id: 'item-1', code: 'RM-001', name: 'Resin', unit_of_measure: 'kg' },
  receipt_number: 'GRN-001',
  previous_cost: '20.00',
  current_cost: '20.00',
  proposed_cost: '25.00',
  status: 'pending',
  version: 3,
  notes: null,
  history: [],
};
const page = <T,>(data: T[]) => ({
  data,
  meta: {
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: data.length,
    from: 1,
    to: data.length,
  },
  links: { first: '', last: '', prev: null, next: null },
});
function show(component: React.ReactNode, permissions: string[]) {
  useAuthStore.setState({ permissions: new Set(permissions), user: null });
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <MemoryRouter>{component}</MemoryRouter>
    </QueryClientProvider>,
  );
}
it('approves the freshly loaded proposal version and cost signature, retaining notes on error', async () => {
  vi.mocked(pricingRequestsApi.list).mockResolvedValue(page([request]));
  vi.mocked(pricingRequestsApi.show).mockResolvedValue(reviewed);
  vi.mocked(pricingRequestsApi.approve).mockRejectedValue(new Error('Offline'));
  show(<PricingRequestsPage />, ['crm.price_agreements.approve']);
  fireEvent.click(await screen.findByRole('button', { name: 'Review' }));
  await screen.findByRole('heading', { name: 'Cost basis' });
  fireEvent.change(screen.getByLabelText(/Review notes/), {
    target: { value: 'Reviewed current material costs' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Approve for customer review' }));
  await waitFor(() =>
    expect(pricingRequestsApi.approve).toHaveBeenCalledWith(
      'request-1',
      'Reviewed current material costs',
      2,
      'current-signature',
    ),
  );
  expect(await screen.findByRole('alert')).toHaveTextContent('Your notes have been kept');
  expect(screen.getByLabelText(/Review notes/)).toHaveValue('Reviewed current material costs');
  expect(screen.getByRole('dialog')).toBeVisible();
});
it('blocks Finance approval when manufacturing cost is incomplete', async () => {
  vi.mocked(pricingRequestsApi.list).mockResolvedValue(page([request]));
  vi.mocked(pricingRequestsApi.show).mockResolvedValue({
    ...reviewed,
    cost_review: { ...reviewed.cost_review!, incomplete: true },
  });
  show(<PricingRequestsPage />, ['crm.price_agreements.approve']);
  fireEvent.click(await screen.findByRole('button', { name: 'Review' }));
  await screen.findByText(/Cost incomplete. Approval blocked/);
  fireEvent.change(screen.getByLabelText(/Review notes/), {
    target: { value: 'Needs production costing' },
  });
  expect(screen.getByRole('button', { name: 'Approve for customer review' })).toBeDisabled();
  expect(pricingRequestsApi.approve).not.toHaveBeenCalled();
});
it('lets sales withdraw without exposing Finance approval or losing the withdrawal reason', async () => {
  vi.mocked(pricingRequestsApi.list).mockResolvedValue(page([request]));
  vi.mocked(pricingRequestsApi.withdraw).mockRejectedValue(new Error('Offline'));
  show(<PricingRequestsPage />, ['crm.price_agreements.propose']);
  expect(screen.queryByRole('tab', { name: 'Supplier cost reviews' })).not.toBeInTheDocument();
  fireEvent.click(await screen.findByRole('button', { name: 'Withdraw' }));
  fireEvent.change(screen.getByLabelText(/Withdrawal reason/), {
    target: { value: 'Customer changed purchasing plan' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Withdraw request' }));
  expect(await screen.findByRole('alert')).toBeVisible();
  expect(screen.getByLabelText(/Withdrawal reason/)).toHaveValue(
    'Customer changed purchasing plan',
  );
  expect(
    screen.queryByRole('button', { name: 'Approve for customer review' }),
  ).not.toBeInTheDocument();
  expect(pricingRequestsApi.show).not.toHaveBeenCalled();
});
it('preserves supplier review reason on failure and locks dialog dismissal during approval', async () => {
  vi.mocked(pricingRequestsApi.costs).mockResolvedValue(page([cost]));
  let reject!: (reason: Error) => void;
  vi.mocked(pricingRequestsApi.decideCost).mockImplementation(
    () =>
      new Promise((_, fail) => {
        reject = fail;
      }),
  );
  show(<CostReviewsPage />, ['inventory.costs.approve', 'inventory.costs.review']);
  fireEvent.click(await screen.findByRole('button', { name: 'Review' }));
  fireEvent.change(screen.getByLabelText(/Approval or rejection reason/), {
    target: { value: 'Supplier resin cost increase' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Approve standard cost' }));
  await waitFor(() =>
    expect(pricingRequestsApi.decideCost).toHaveBeenCalledWith(
      'cost-1',
      'approve',
      'Supplier resin cost increase',
      3,
    ),
  );
  expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
  fireEvent.keyDown(document, { key: 'Escape' });
  expect(screen.getByRole('dialog')).toBeVisible();
  reject(new Error('Offline'));
  expect(await screen.findByRole('alert')).toHaveTextContent('Your reason has been kept');
  expect(screen.getByLabelText(/Approval or rejection reason/)).toHaveValue(
    'Supplier resin cost increase',
  );
});
it('keeps supplier cost details readable without giving review-only staff approval controls', async () => {
  vi.mocked(pricingRequestsApi.costs).mockResolvedValue(
    page([{ ...cost, notes: 'Awaiting independent Finance review' }]),
  );
  show(<CostReviewsPage />, ['inventory.costs.review']);
  fireEvent.click(await screen.findByRole('button', { name: 'Details' }));
  expect(screen.getByText('Awaiting independent Finance review')).toBeVisible();
  expect(screen.queryByRole('button', { name: 'Approve standard cost' })).not.toBeInTheDocument();
  expect(screen.queryByLabelText('Approval or rejection reason')).not.toBeInTheDocument();
});
