import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { kpiApi } from '@/api/kpi';
import type { KpiScorecardItem, KpiTrendPoint } from '@/types/dashboard/kpi';
import ScorecardPage from './scorecard';

vi.mock('@/api/kpi', () => ({
  kpiApi: {
    scorecard: vi.fn(),
    trend: vi.fn(),
    trends: vi.fn(),
    compute: vi.fn(),
  },
}));

const scorecard: KpiScorecardItem[] = [{
  definition: {
    id: '1',
    code: 'oee',
    name: 'Overall Equipment Effectiveness',
    module: 'production',
    unit: 'percentage',
    direction: 'higher_is_better',
    target_value: '85.00',
    warning_threshold: '80.00',
  },
  snapshot: {
    actual_value: '83.50',
    target_value: '85.00',
    previous_value: '81.00',
    trend: 'up',
    status: 'warning',
    status_label: 'Warning',
    computed_at: null,
  },
}];

const trendPoints: KpiTrendPoint[] = [
  { period: '2026-08', value: '81.00', target: '85.00', status: 'warning' },
  { period: '2026-09', value: '83.50', target: '85.00', status: 'warning' },
];

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return {
    client,
    ...render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <ScorecardPage />
        </MemoryRouter>
      </QueryClientProvider>,
    ),
  };
}

describe('ScorecardPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(kpiApi.scorecard).mockResolvedValue(scorecard);
    vi.mocked(kpiApi.trends).mockResolvedValue({ oee: trendPoints });
  });

  it('renders scorecard trends without a fixed-size chart warning', async () => {
    const warning = vi.spyOn(console, 'warn').mockImplementation(() => {});

    try {
      const { client, container } = renderPage();

      expect(await screen.findByText('83.50%')).toBeInTheDocument();
      await waitFor(() => {
        expect(client.getQueryData(['kpi', 'trends', ['oee']])).toEqual({ oee: trendPoints });
        expect(container.querySelector('svg.recharts-surface')).not.toBeNull();
      });
      expect(warning).not.toHaveBeenCalledWith(expect.stringContaining('both fixed numbers'));
    } finally {
      warning.mockRestore();
    }
  });
});
