import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { dssApi } from '@/api/dss';
import RecommendationDetailPage from './detail';
import { recommendationFixture } from './test-fixtures';

vi.mock('@/api/dss', () => ({
  dssApi: { show: vi.fn(), act: vi.fn(), outcome: vi.fn(), events: vi.fn() },
}));
vi.mock('@/hooks/useRealtimeSignal', () => ({ useRealtimeSignal: vi.fn() }));

describe('Decision Center recommendation detail', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(dssApi.show).mockResolvedValue(recommendationFixture());
    vi.mocked(dssApi.events).mockResolvedValue({ data: [], next_cursor: null });
  });

  it('shows business wording, recorded evidence, and a readable workflow action', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/decision-center/rec-1']}>
          <Routes>
            <Route path="/decision-center/:id" element={<RecommendationDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(
      await screen.findByText('Confirmed delivery awaiting customer invoice · DR-202610-0002'),
    ).toBeVisible();
    expect(screen.getByRole('region', { name: 'Sample affected documents table' })).toBeVisible();
    expect(
      screen.getAllByText(
        'A workflow handoff is beyond its configured target; verify the source records.',
      ),
    ).toHaveLength(1);
    expect(screen.getByRole('button', { name: 'Review workflow queue' })).toBeVisible();
    expect(screen.queryByText(/dss\.chain_bottlenecks|cross_module|100%/)).not.toBeInTheDocument();
  });

  it('updates the acting detail and available buttons from the action response', async () => {
    const open = recommendationFixture();
    const accepted = recommendationFixture({ status: 'accepted' });
    vi.mocked(dssApi.act).mockResolvedValue(accepted);
    let finishRefetch: ((value: typeof accepted) => void) | undefined;
    vi.mocked(dssApi.show)
      .mockResolvedValueOnce(open)
      .mockImplementationOnce(() => new Promise((resolve) => { finishRefetch = resolve; }));
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/decision-center/rec-1']}>
          <Routes>
            <Route path="/decision-center/:id" element={<RecommendationDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.click(await screen.findByRole('button', { name: 'Accept' }));

    await waitFor(() => expect(dssApi.act).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(dssApi.show).toHaveBeenCalledTimes(2));
    expect(screen.getByText('Acknowledged')).toBeVisible();
    expect(screen.queryByRole('button', { name: 'Accept' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Mark as executed' })).toBeVisible();
    finishRefetch?.(accepted);
  });

  it('renders decision buttons only when the server marks those actions available', async () => {
    const recommendation = recommendationFixture({
      available_actions: {
        accept: false,
        dismiss: false,
        snooze: false,
        execute: false,
        start_follow_up: false,
        record_outcome: false,
      },
    });
    vi.mocked(dssApi.show).mockResolvedValue(recommendation);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/decision-center/rec-1']}>
          <Routes>
            <Route path="/decision-center/:id" element={<RecommendationDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByText('New')).toBeVisible();
    expect(screen.queryByRole('button', { name: 'Accept' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Dismiss' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Start follow-up' })).not.toBeInTheDocument();
  });
});
