import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { priceAgreementsApi } from '@/api/crm/priceAgreements';
import type { PaginatedResponse } from '@/types';
import type { PriceAgreement } from '@/types/crm';
import PriceAgreementsListPage from './index';

vi.mock('@/api/crm/priceAgreements', () => ({
  priceAgreementsApi: { list: vi.fn(), delete: vi.fn(), restore: vi.fn() },
}));
vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({
    can: (permission: string) => permission === 'crm.price_agreements.propose',
    canAny: (...permissions: string[]) => permissions.includes('crm.price_agreements.propose'),
  }),
}));

const agreement: PriceAgreement = {
  id: 'agreement-1',
  product: { id: 'product-1', part_number: 'FILM-1', name: 'Film', unit_of_measure: 'kg' },
  customer: { id: 'customer-1', name: 'Acme' },
  price: '10.00',
  effective_from: '2026-01-01',
  effective_to: '2026-10-03',
  pricing_method: 'flat',
  pricing_method_label: 'Flat',
  tiers: null,
  is_currently_active: false,
  created_at: '2026-01-01T00:00:00.000Z',
  updated_at: '2026-10-03T00:00:00.000Z',
};

const response: PaginatedResponse<PriceAgreement> = {
  data: [agreement],
  meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
  links: { first: '', last: '', prev: null, next: null },
};

describe('PriceAgreementsListPage date actions', () => {
  const originalTimezone = process.env.TZ;

  beforeEach(() => {
    vi.stubEnv('TZ', 'Asia/Manila');
    vi.setSystemTime(new Date('2026-10-03T17:00:00.000Z'));
    vi.mocked(priceAgreementsApi.list).mockResolvedValue(response);
  });

  afterEach(() => {
    cleanup();
    vi.useRealTimers();
    if (originalTimezone === undefined) vi.unstubAllEnvs();
    else vi.stubEnv('TZ', originalTimezone);
  });

  it('shows archive action after the Manila expiry date before UTC midnight', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <PriceAgreementsListPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByRole('button', { name: 'Archive agreement' })).toBeVisible();
  });
});

it('keeps pricing blockers visible while disclosing the full warning only on demand', async () => {
  const warning =
    'Agreement price is below the minimum selling price; new quotes at this price are blocked.';
  vi.mocked(priceAgreementsApi.list).mockResolvedValue({
    ...response,
    data: [{ ...agreement, pricing_warnings: [warning] }],
  });
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <MemoryRouter>
        <PriceAgreementsListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
  expect(await screen.findByText('New quotes blocked')).toBeVisible();
  expect(screen.getByText(warning)).not.toBeVisible();
  fireEvent.click(screen.getByText('1 issue · Price details'));
  expect(screen.getByText(warning)).toBeVisible();
  expect(screen.getByRole('tab', { name: 'Pricing requests' })).toHaveAttribute(
    'href',
    '/crm/price-agreements/requests',
  );
  expect(screen.queryByRole('tab', { name: 'Supplier cost reviews' })).not.toBeInTheDocument();
});
