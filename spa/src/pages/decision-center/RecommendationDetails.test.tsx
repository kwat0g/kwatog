import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { dssApi } from '@/api/dss';
import RecommendationDetails from './RecommendationDetails';
import { recommendationFixture } from './test-fixtures';

vi.mock('@/api/dss', () => ({
  dssApi: { act: vi.fn(), outcome: vi.fn() },
}));

describe('RecommendationDetails', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(dssApi.act).mockResolvedValue(recommendationFixture());
    vi.mocked(dssApi.outcome).mockResolvedValue(recommendationFixture());
  });

  const renderDetails = (recommendation = recommendationFixture()) => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <RecommendationDetails recommendation={recommendation} />
        </MemoryRouter>
      </QueryClientProvider>,
    );
  };

  it('renders business summary and bounded evidence once without internal metadata', () => {
    const rec = recommendationFixture();
    renderDetails(rec);

    expect(
      screen.getByRole('heading', {
        name: 'Confirmed delivery awaiting customer invoice · DR-202610-0002',
      }),
    ).toBeVisible();
    expect(
      screen.getByText(
        'A workflow handoff is beyond its configured target; verify the source records.',
      ),
    ).toBeVisible();
    expect(screen.getByRole('region', { name: 'Sample affected documents table' })).toBeVisible();
    expect(screen.getByRole('button', { name: 'Review workflow queue' })).toBeVisible();
    expect(screen.queryByText(/dss\.chain_bottlenecks|cross_module|100%/)).not.toBeInTheDocument();
  });

  it('keeps open recommendation actions and required dismissal reason', async () => {
    const rec = recommendationFixture();
    renderDetails({ ...rec, status: 'open' });

    fireEvent.click(screen.getByRole('button', { name: 'Dismiss' }));
    fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Already resolved' } });
    fireEvent.click(screen.getByRole('button', { name: 'Confirm dismissal' }));

    await waitFor(() =>
      expect(dssApi.act).toHaveBeenCalledWith(rec.id, 'dismiss', {
        reason: 'Already resolved',
        snoozed_until: undefined,
      }),
    );
  });

  it('starts follow-up once and shows only permission-scoped source links', async () => {
    const rec = recommendationFixture({
      source_references: [
        {
          source_kind: 'work_order',
          public_id: 'wo_hash',
          display_reference: 'WO-42',
          destination: '/production/work-orders/wo_hash',
        },
      ],
      verification_status: 'unavailable',
      business_due_at: '2026-10-25T12:00:00Z',
      last_evaluated_at: '2026-10-23T12:00:00Z',
    });
    renderDetails(rec);

    expect(screen.getByRole('link', { name: 'WO-42' })).toHaveAttribute(
      'href',
      '/production/work-orders/wo_hash',
    );
    expect(screen.getByText('Automatic source verification unavailable')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Start follow-up' }));

    await waitFor(() =>
      expect(dssApi.act).toHaveBeenCalledWith(rec.id, 'start_follow_up', {
        reason: undefined,
        snoozed_until: undefined,
      }),
    );
  });

  it('shows verified source evidence in readable language', () => {
    const rec = recommendationFixture({
      status: 'resolved',
      verification_status: 'verified_clear',
      resolution_evidence: {
        condition: 'Production deadline',
        verification_method: 'Work order status',
        proof: 'Work order is no longer active.',
        verified_at: '2026-10-23T12:00:00Z',
        run_reference: 'run_hash',
      },
    });
    renderDetails(rec);

    expect(
      screen.getByText('Production deadline cleared. Work order is no longer active.'),
    ).toBeVisible();
    expect(screen.getByText(/Work order status · run run_hash/)).toBeVisible();
  });

  it('shows outcome entry after completion and records the measured result', async () => {
    const rec = recommendationFixture();
    renderDetails({ ...rec, status: 'executed' });

    fireEvent.change(screen.getByLabelText('Measured result'), {
      target: { value: '2 days saved' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Record outcome' }));

    await waitFor(() =>
      expect(dssApi.outcome).toHaveBeenCalledWith(rec.id, { note: '2 days saved' }),
    );
  });
});
