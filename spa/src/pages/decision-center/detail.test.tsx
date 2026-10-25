import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { dssApi } from '@/api/dss';
import RecommendationDetailPage from './detail';
import { recommendationFixture } from './test-fixtures';

vi.mock('@/api/dss', () => ({
  dssApi: { show: vi.fn(), act: vi.fn(), outcome: vi.fn(), events: vi.fn() },
}));

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
});
