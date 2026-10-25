import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { dssApi } from '@/api/dss';
import RecommendationDetails from './RecommendationDetails';
import { recommendationFixture } from './test-fixtures';

vi.mock('@/api/dss', () => ({
  dssApi: { act: vi.fn(), outcome: vi.fn(), events: vi.fn(), affectedRecords: vi.fn() },
}));

describe('RecommendationDetails', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(dssApi.act).mockResolvedValue(recommendationFixture());
    vi.mocked(dssApi.outcome).mockResolvedValue(recommendationFixture());
    vi.mocked(dssApi.events).mockResolvedValue({ data: [], next_cursor: null });
    vi.mocked(dssApi.affectedRecords).mockResolvedValue({ data: [], total: 0, next_cursor: null, historical: false });
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

  it('lets future snoozes resume or dismiss, but not accept or snooze again', () => {
    const rec = recommendationFixture({
      status: 'snoozed',
      snoozed_until: '2099-01-02T00:00:00Z',
    });
    renderDetails(rec);

    expect(screen.getByText('Snoozed until')).toBeVisible();
    expect(screen.getByRole('button', { name: 'Resume follow-up' })).toBeVisible();
    expect(screen.getByRole('button', { name: 'Dismiss' })).toBeVisible();
    expect(screen.queryByRole('button', { name: 'Accept' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Snooze' })).not.toBeInTheDocument();
  });

  it('allows due snoozes to accept and expired snoozes have no actions', () => {
    const due = recommendationFixture({
      status: 'snoozed',
      snoozed_until: '2000-01-02T00:00:00Z',
    });
    const { unmount } = renderDetails(due);
    expect(screen.getByRole('button', { name: 'Resume follow-up' })).toBeVisible();
    expect(screen.getByRole('button', { name: 'Accept' })).toBeVisible();
    expect(screen.getByRole('button', { name: 'Dismiss' })).toBeVisible();
    unmount();

    renderDetails(recommendationFixture({ status: 'expired', snoozed_until: '2099-01-02T00:00:00Z' }));
    expect(screen.queryByRole('button', { name: 'Resume follow-up' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Accept' })).not.toBeInTheDocument();
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

  it('shows the complete affected-record workflow with human-readable requirements', async () => {
    vi.mocked(dssApi.affectedRecords).mockResolvedValue({
      data: [{
        reference: 'RM-01',
        name: 'Resin mix',
        requirement: 'Usable reorder point',
        destination: '/inventory/items/item_hash/edit',
      }],
      total: 1,
      next_cursor: null,
      historical: false,
    });
    const recommendation = recommendationFixture({
      detector_id: 'dss.master_hygiene',
      domain: 'platform',
      type: 'master_hygiene',
      rationale: {
        gap: 'items_missing_reorder_point',
        affected_count: 1,
        evidence: ['RM-01'],
        why_it_matters: 'Items without a usable reorder point are invisible to auto-replenishment.',
      },
      action: { route: '/inventory/items', label: 'Open master data' },
    });
    renderDetails(recommendation);

    expect(await screen.findByRole('table', { name: 'Affected master-data records' })).toBeVisible();
    expect(screen.getByText('Usable reorder point')).toBeVisible();
    expect(screen.getByRole('link', { name: 'Open record' })).toHaveAttribute(
      'href',
      '/inventory/items/item_hash/edit',
    );
    expect(screen.queryByText(/items_missing_reorder_point|why_it_matters/)).not.toBeInTheDocument();
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

  it('loads older activity with cursor and shows curated event details', async () => {
    vi.mocked(dssApi.events)
      .mockResolvedValueOnce({
        data: [{ id: 'new', label: 'Accepted', at: '2026-10-23T12:00:00Z', actor: 'Sam', reason: null, outcome_summary: null }],
        next_cursor: 'cursor-older',
      })
      .mockResolvedValueOnce({
        data: [{ id: 'old', label: 'Viewed', at: '2026-10-22T12:00:00Z', actor: null, reason: null, outcome_summary: null }],
        next_cursor: null,
      });
    renderDetails();

    expect(await screen.findByText('Accepted')).toBeVisible();
    expect(screen.getByText('Sam')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Load older events' }));
    expect(await screen.findByText('Viewed')).toBeVisible();
    expect(dssApi.events).toHaveBeenNthCalledWith(2, recommendationFixture().id, 'cursor-older');
  });

  it('shows empty activity and can retry a failed timeline request', async () => {
    vi.mocked(dssApi.events).mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce({ data: [], next_cursor: null });
    renderDetails();

    expect(await screen.findByText('Could not load activity.')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
    expect(await screen.findByText('No activity recorded yet.')).toBeVisible();
  });
});
