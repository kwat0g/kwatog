import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
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
    reject: vi.fn(),
    costs: vi.fn(),
    decideCost: vi.fn(),
    renewCost: vi.fn(),
  },
}));
vi.mock('@/hooks/useRealtimeRecord', () => ({
  useRealtimeList: vi.fn(),
  useRealtimeRecord: vi.fn(),
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
  available_actions: {
    can_propose: true,
    can_approve: true,
    can_withdraw: true,
    can_accept: false,
    can_reject: false,
    can_cancel: false,
  },
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
function show(component: React.ReactNode, permissions: string[], initialEntry = '/') {
  useAuthStore.setState({ permissions: new Set(permissions), user: null });
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialEntry]}>{component}</MemoryRouter>
    </QueryClientProvider>,
  );
  return queryClient;
}
it('approves the freshly loaded proposal version and cost signature, retaining notes on error', async () => {
  vi.mocked(pricingRequestsApi.list).mockResolvedValue(page([request]));
  vi.mocked(pricingRequestsApi.show).mockResolvedValue(reviewed);
  vi.mocked(pricingRequestsApi.approve).mockRejectedValue(new Error('Offline'));
  show(<PricingRequestsPage />, ['crm.price_agreements.approve']);
  expect(await screen.findByRole('heading', { name: 'Pricing approvals' })).toBeVisible();
  expect(screen.getByRole('tab', { name: 'Pricing approvals' })).toBeVisible();
  fireEvent.click((await screen.findAllByRole('button', { name: 'Review' }))[0]);
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
  fireEvent.click((await screen.findAllByRole('button', { name: 'Review' }))[0]);
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
  fireEvent.click((await screen.findAllByRole('button', { name: 'Withdraw proposal' }))[0]);
  fireEvent.change(screen.getByLabelText(/Withdrawal reason/), {
    target: { value: 'Customer changed purchasing plan' },
  });
  fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Withdraw proposal' }));
  expect(await screen.findByRole('alert')).toBeVisible();
  expect(screen.getByLabelText(/Withdrawal reason/)).toHaveValue(
    'Customer changed purchasing plan',
  );
  expect(
    screen.queryByRole('button', { name: 'Approve for customer review' }),
  ).not.toBeInTheDocument();
  expect(pricingRequestsApi.show).not.toHaveBeenCalled();
});
it('lets CRM reject a customer-submitted request and hides Withdraw for that request', async () => {
  const customerRequest: PriceRequest = {
    ...request,
    status: 'requested',
    offer: null,
    available_actions: {
      ...request.available_actions!,
      can_withdraw: false,
      can_reject: true,
      can_approve: false,
    },
  };
  vi.mocked(pricingRequestsApi.list).mockResolvedValue(page([customerRequest]));
  vi.mocked(pricingRequestsApi.reject).mockResolvedValue({
    ...customerRequest,
    status: 'sales_rejected',
    version: 2,
    available_actions: { ...customerRequest.available_actions!, can_reject: false },
  });
  show(<PricingRequestsPage />, ['crm.price_agreements.propose']);

  expect((await screen.findAllByRole('button', { name: 'Reject request' }))[0]).toBeVisible();
  expect(screen.queryByRole('button', { name: 'Withdraw proposal' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Details' })).not.toBeInTheDocument();
  fireEvent.click((await screen.findAllByRole('button', { name: 'Reject request' }))[0]);
  fireEvent.change(screen.getByLabelText(/Reason for rejection/), {
    target: { value: 'Requested price is below the approved floor.' },
  });
  fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Reject request' }));
  await waitFor(() => expect(pricingRequestsApi.reject).toHaveBeenCalledWith(
    customerRequest.id,
    'Requested price is below the approved floor.',
    customerRequest.version,
  ));
});
it('disables an open withdrawal when the refreshed server row removes that action', async () => {
  vi.mocked(pricingRequestsApi.list).mockResolvedValue(page([request]));
  const queryClient = show(<PricingRequestsPage />, ['crm.price_agreements.propose']);

  fireEvent.click((await screen.findAllByRole('button', { name: 'Withdraw proposal' }))[0]);
  fireEvent.change(screen.getByLabelText(/Withdrawal reason/), {
    target: { value: 'Customer changed purchasing plan' },
  });
  await act(async () => {
    queryClient.setQueryData(
      ['pricing-requests', 1, undefined],
      page([{
        ...request,
        status: 'offered',
        version: 2,
        available_actions: { ...request.available_actions!, can_withdraw: false },
      }]),
    );
  });

  await waitFor(() => {
    expect(screen.getAllByText('Awaiting customer').length).toBeGreaterThan(0);
    expect(screen.getByRole('button', { name: 'Withdraw proposal' })).toBeDisabled();
  });
  expect(screen.getByLabelText(/Withdrawal reason/)).toHaveValue('Customer changed purchasing plan');
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
  fireEvent.click((await screen.findAllByRole('button', { name: 'Review' }))[0]);
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

it('seeds the acting reviewer list from the successful API response', async () => {
  const approved = {
    ...cost,
    status: 'approved' as const,
    updated_at: '2026-11-02T02:00:00.000Z',
    notes: 'Reviewed and approved',
  };
  vi.mocked(pricingRequestsApi.costs).mockResolvedValue(page([cost]));
  vi.mocked(pricingRequestsApi.decideCost).mockResolvedValue(approved);
  const queryClient = show(<CostReviewsPage />, [
    'inventory.costs.review',
    'inventory.costs.approve',
  ]);
  vi.spyOn(queryClient, 'invalidateQueries').mockResolvedValue(undefined);

  fireEvent.click((await screen.findAllByRole('button', { name: 'Review' }))[0]);
  fireEvent.change(screen.getByLabelText(/Approval or rejection reason/), {
    target: { value: 'Reviewed supplier evidence' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Approve standard cost' }));

  await waitFor(() => expect(pricingRequestsApi.decideCost).toHaveBeenCalled());
  expect(queryClient.getQueryData<{ data: ItemCostReview[] }>(['cost-reviews', 1, null])?.data[0]?.status)
    .toBe('approved');
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});

it('updates an open review modal after a peer change and keeps the unsent reason visible', async () => {
  const updated = {
    ...cost,
    status: 'approved' as const,
    version: 4,
    updated_at: '2026-11-02T02:00:00.000Z',
    notes: 'Approved by another reviewer',
  };
  vi.mocked(pricingRequestsApi.costs).mockResolvedValue(
    page([{ ...cost, updated_at: '2026-11-02T01:59:00.000Z' }]),
  );
  const queryClient = show(<CostReviewsPage />, [
    'inventory.costs.review',
    'inventory.costs.approve',
  ]);

  fireEvent.click((await screen.findAllByRole('button', { name: 'Review' }))[0]);
  fireEvent.change(screen.getByLabelText(/Approval or rejection reason/), {
    target: { value: 'My unsent review reason' },
  });

  await act(async () => {
    queryClient.setQueryData(['cost-reviews', 1, null], page([updated]));
  });
  expect(await screen.findByRole('cell', { name: 'Approved' })).toBeVisible();

  const dialog = screen.getByRole('dialog');
  expect(within(dialog).getByText('Approved')).toBeVisible();
  expect(within(dialog).getByRole('status')).toHaveTextContent(/changed while you were viewing it/i);
  expect(within(dialog).getByLabelText(/unsent reason/i)).toHaveValue('My unsent review reason');
  expect(within(dialog).queryByRole('button', { name: 'Approve standard cost' })).not.toBeInTheDocument();
});

it('opens the exact linked supplier cost review', async () => {
  vi.mocked(pricingRequestsApi.costs).mockResolvedValue(page([cost]));
  show(
    <CostReviewsPage />,
    ['inventory.costs.review'],
    '/crm/price-agreements/cost-reviews?review=cost-1',
  );
  expect(
    await screen.findByRole('heading', { name: 'Supplier cost review details' }),
  ).toBeVisible();
  expect(pricingRequestsApi.costs).toHaveBeenCalledWith(1, 'cost-1');
});

it('explains when a linked supplier cost review is unavailable', async () => {
  vi.mocked(pricingRequestsApi.costs).mockResolvedValue(page([]));
  show(
    <CostReviewsPage />,
    ['inventory.costs.review'],
    '/crm/price-agreements/cost-reviews?review=deleted-review',
  );
  expect(
    await screen.findByText('This review is unavailable or you no longer have access.'),
  ).toBeVisible();
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});
