import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { pricingRequestsApi } from '@/api/crm/pricingRequests';
import type { ItemCostReview } from '@/types/pricing';
import CostReviewsPage from './cost-reviews';

vi.mock('@/api/crm/pricingRequests', () => ({
  pricingRequestsApi: {
    costs: vi.fn(),
    decideCost: vi.fn(),
    renewCost: vi.fn(),
  },
}));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({
  can: () => true,
  canApprove: () => true,
  canAny: () => true,
  canAll: () => true,
}) }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeList: vi.fn() }));
vi.mock('react-hot-toast', () => ({ default: { error: vi.fn(), success: vi.fn() } }));

const review: ItemCostReview = {
  id: 'review-1',
  item: { id: 'item-1', code: 'RM-001', name: 'Resin', unit_of_measure: 'kg' },
  receipt_number: 'GRN-001',
  previous_cost: '10.0000',
  proposed_cost: '12.0000',
  current_cost: '10.0000',
  status: 'pending',
  version: 1,
  history: [],
  notes: null,
  updated_at: '2026-11-03T00:00:00Z',
  permissions: { view: true, review: true, approve: true },
  available_actions: { approve: false, reject: false, renew: false },
};

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(pricingRequestsApi.costs).mockResolvedValue({
    data: [review],
    links: { first: '', last: '', prev: null, next: null },
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
  });
});

it('uses server actions instead of stale local permissions for cost-review controls', async () => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter><CostReviewsPage /></MemoryRouter>
    </QueryClientProvider>,
  );

  const row = await screen.findByRole('row', { name: /RM-001/ });
  fireEvent.click(within(row).getByRole('button', { name: 'Details' }));

  expect(within(row).queryByRole('button', { name: 'Review' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument();
});

it('fails closed when the server action projection is missing', async () => {
  const legacyReview: ItemCostReview = { ...review };
  delete legacyReview.available_actions;
  vi.mocked(pricingRequestsApi.costs).mockResolvedValue({
    data: [legacyReview],
    links: { first: '', last: '', prev: null, next: null },
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
  });
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter><CostReviewsPage /></MemoryRouter>
    </QueryClientProvider>,
  );

  const row = await screen.findByRole('row', { name: /RM-001/ });
  expect(within(row).getByRole('button', { name: 'Details' })).toBeInTheDocument();
  fireEvent.click(within(row).getByRole('button', { name: 'Details' }));
  expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument();
});
