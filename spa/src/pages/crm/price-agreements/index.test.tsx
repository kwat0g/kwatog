import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { priceAgreementsApi } from '@/api/crm/priceAgreements';
import type { PaginatedResponse } from '@/types';
import type { PriceAgreement } from '@/types/crm';
import PriceAgreementsListPage from './index';

const { grantedPermissions } = vi.hoisted(() => ({ grantedPermissions: new Set<string>() }));

vi.mock('@/api/crm/priceAgreements', () => ({
  priceAgreementsApi: {
    list: vi.fn(), delete: vi.fn(), restore: vi.fn(), previewTermination: vi.fn(), terminationHistory: vi.fn(),
    requestTermination: vi.fn(), cancelTerminationRequest: vi.fn(),
  },
}));
vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({
    can: (permission: string) => grantedPermissions.has(permission),
    canAny: (...permissions: string[]) => permissions.some((permission) => grantedPermissions.has(permission)),
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

describe('PriceAgreementsListPage termination actions', () => {
  beforeEach(() => {
    grantedPermissions.clear();
    grantedPermissions.add('crm.price_agreements.propose');
    grantedPermissions.add('crm.price_agreements.manage');
    /* Freeze the instant, not the runner's timezone. The page compares against the
       PHILIPPINE calendar date (`manilaIsoDate`), so 2026-10-03T17:00Z — 01:00 on
       Oct 4 in Manila — is the boundary under test. This used to stub
       `process.env.TZ`, which made the outcome depend on a process-wide env write
       landing before the next Date lookup; it flipped under full-suite load. */
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-03T17:00:00.000Z'));
    vi.mocked(priceAgreementsApi.previewTermination).mockResolvedValue({
      scheduled: false,
      coverage_date: '2026-10-05',
      has_coverage: true,
      coverage_source: 'base',
    });
    vi.mocked(priceAgreementsApi.list).mockResolvedValue(response);
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

    expect((await screen.findAllByRole('button', { name: 'Archive agreement' })).length).toBeGreaterThan(0);
  });

  it('sends an active agreement termination to the customer for signature without changing it first', async () => {
    vi.setSystemTime(new Date('2026-10-03T17:00:00.000Z'));
    vi.mocked(priceAgreementsApi.list).mockResolvedValue({
      ...response,
      data: [{ ...agreement, effective_from: '2026-10-01', effective_to: '2026-10-10', is_currently_active: true }],
    });
    vi.mocked(priceAgreementsApi.requestTermination).mockResolvedValue({} as never);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <PriceAgreementsListPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.click((await screen.findAllByRole('button', { name: 'Request termination' }))[0]);
    expect(await screen.findByText(/the price terms remain unchanged until the customer signs/i)).toBeVisible();
    const submit = (await screen.findAllByRole('button', { name: 'Send for customer signature' }))[0];
    expect(submit).toBeDisabled();
    fireEvent.change(screen.getByRole('textbox', { name: 'Reason' }), {
      target: { value: 'Customer requested termination in writing.' },
    });
    expect(submit).toBeEnabled();
    fireEvent.click(submit);

    await waitFor(() => {
      expect(priceAgreementsApi.requestTermination).toHaveBeenCalledWith('agreement-1', {
        last_valid_date: '2026-10-04',
        reason: 'Customer requested termination in writing.',
        coverage_gap_acknowledged: false,
      });
    });
  });

  it('requires a manager to acknowledge a future price-coverage gap', async () => {
    vi.mocked(priceAgreementsApi.list).mockResolvedValue({
      ...response,
      data: [{ ...agreement, effective_from: '2026-10-01', effective_to: '2026-10-10', is_currently_active: true }],
    });
    vi.mocked(priceAgreementsApi.previewTermination).mockResolvedValue({
      scheduled: false,
      coverage_date: '2026-10-05',
      has_coverage: false,
      coverage_source: 'none',
    });
    vi.mocked(priceAgreementsApi.requestTermination).mockResolvedValue({} as never);
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter><PriceAgreementsListPage /></MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.click((await screen.findAllByRole('button', { name: 'Request termination' }))[0]);
    fireEvent.change(screen.getByRole('textbox', { name: 'Reason' }), { target: { value: 'Customer requested an earlier end.' } });
    const submit = (await screen.findAllByRole('button', { name: 'Send for customer signature' }))[0];
    expect(await screen.findByText(/no agreement or valid base price covers orders on/i)).toBeVisible();
    expect(submit).toBeDisabled();
    fireEvent.click(screen.getByRole('checkbox', { name: /I understand orders may be blocked/i }));
    expect(submit).toBeEnabled();
  });

  it('lets managers cancel a pending customer signature request', async () => {
    vi.mocked(priceAgreementsApi.list).mockResolvedValue({
      ...response,
      data: [{
        ...agreement,
        effective_from: '2026-10-01',
        effective_to: '2026-10-10',
        is_currently_active: true,
        termination_status: 'awaiting_customer_signature',
        termination_document_id: 'document-1',
        termination_reason: 'Customer requested an earlier end.',
      }],
    });
    vi.mocked(priceAgreementsApi.cancelTerminationRequest).mockResolvedValue(agreement);
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter><PriceAgreementsListPage /></MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findAllByText('Awaiting customer signature')).not.toHaveLength(0);
    fireEvent.click((await screen.findAllByRole('button', { name: 'Cancel signature request' }))[0]);
    fireEvent.click(await screen.findByRole('button', { name: 'Cancel request' }));
    await waitFor(() => expect(priceAgreementsApi.cancelTerminationRequest).toHaveBeenCalledWith('agreement-1', 'document-1'));
  });

  it('shows the full customer-signature termination history', async () => {
    vi.mocked(priceAgreementsApi.list).mockResolvedValue({
      ...response,
      data: [{
        ...agreement,
        termination_status: 'ended_early',
        termination_reason: 'Customer requested an earlier end.',
        termination_history_count: 2,
      }],
    });
    vi.mocked(priceAgreementsApi.terminationHistory).mockResolvedValue({
      has_older_entries: false,
      entries: [
        {
          status: 'awaiting_customer_signature',
          reason: 'Customer requested an earlier end.',
          occurred_at: '2026-10-01T10:00:00.000Z',
          actor_type: 'user',
          signed_name: null,
          kind: 'early_termination',
          last_valid_date: '2026-10-04',
          document_id: 'document-1',
        },
        {
          status: 'ended_early',
          reason: 'Customer requested an earlier end.',
          occurred_at: '2026-10-02T10:00:00.000Z',
          actor_type: 'customer_portal',
          signed_name: 'Buyer Authorized Signatory',
          kind: 'early_termination',
          last_valid_date: '2026-10-04',
          document_id: 'document-1',
        },
      ],
    });
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter><PriceAgreementsListPage /></MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.click((await screen.findAllByRole('button', { name: 'View termination history for Acme' }))[0]);
    expect(await screen.findByRole('heading', { name: 'Termination history' })).toBeVisible();
    expect(await screen.findByText('Customer signature requested')).toBeVisible();
    expect(await screen.findByText('Customer signed early termination')).toBeVisible();
    expect(screen.getByText('Signed by Buyer Authorized Signatory')).toBeVisible();
    expect(screen.getAllByText('Customer requested an earlier end.').length).toBeGreaterThanOrEqual(2);
  });

  it('offers cancellation for a scheduled agreement', async () => {
    vi.mocked(priceAgreementsApi.list).mockResolvedValue({
      ...response,
      data: [{ ...agreement, effective_from: '2026-10-10', effective_to: '2026-12-10', is_currently_active: false }],
    });
    vi.mocked(priceAgreementsApi.previewTermination).mockResolvedValue({
      scheduled: true,
      coverage_date: '2026-10-10',
      has_coverage: true,
      coverage_source: 'base',
    });
    vi.mocked(priceAgreementsApi.requestTermination).mockResolvedValue({} as never);
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter><PriceAgreementsListPage /></MemoryRouter>
      </QueryClientProvider>,
    );
    fireEvent.click((await screen.findAllByRole('button', { name: 'Request termination' }))[0]);
    expect(await screen.findByText(/this agreement has not started yet/i)).toBeVisible();
    expect(screen.queryByRole('textbox', { name: 'Last valid date' })).not.toBeInTheDocument();
    expect((await screen.findAllByRole('button', { name: 'Send for customer signature' }))[0]).toBeDisabled();
  });

  it('shows proposal actions to proposers without granting manager actions', async () => {
    grantedPermissions.clear();
    grantedPermissions.add('crm.price_agreements.propose');
    vi.mocked(priceAgreementsApi.list).mockResolvedValue(response);
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter>
          <PriceAgreementsListPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect((await screen.findAllByRole('link', { name: 'Propose replacement agreement for Acme' })).length).toBeGreaterThan(0);
    expect(screen.queryByRole('button', { name: 'Archive agreement' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Restore agreement for Acme' })).not.toBeInTheDocument();
  });
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  grantedPermissions.clear();
  vi.clearAllMocks();
});

it('keeps pricing blockers visible while disclosing the full warning only on demand', async () => {
  grantedPermissions.add('crm.price_agreements.propose');
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
  expect((await screen.findAllByText('New quotes blocked')).length).toBeGreaterThan(0);
  const warningDetails = (await screen.findAllByText('1 issue · Price details'))[0];
  expect(warningDetails.closest('details')).not.toHaveAttribute('open');
  fireEvent.click(warningDetails);
  expect((await screen.findAllByText(warning)).length).toBeGreaterThan(0);
  expect((await screen.findAllByRole('tab', { name: 'Pricing requests' }))[0]).toHaveAttribute(
    'href',
    '/crm/price-agreements/requests',
  );
  expect(screen.queryByRole('tab', { name: 'Supplier cost reviews' })).not.toBeInTheDocument();
});
