import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { kpiApi } from '@/api/kpi';
import { queryClient } from '@/lib/queryClient';
import type { KpiScorecardItem, KpiTrendPoint } from '@/types/dashboard/kpi';
import { KpiStrip } from './KpiStrip';

vi.mock('@/hooks/useRealtimeSignal', () => ({ useRealtimeSignal: vi.fn() }));

function renderStrip(codes: string[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <KpiStrip codes={codes} compact />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

const oeeItem: KpiScorecardItem = {
  definition: {
    id: 'kpi-id',
    code: 'oee',
    name: 'Overall Equipment Effectiveness',
    module: 'production',
    unit: 'percentage',
    direction: 'higher_is_better',
    target_value: '85.0000',
    warning_threshold: '80.0000',
  },
  snapshot: {
    actual_value: '83.5000',
    target_value: '85.0000',
    previous_value: '81.0000',
    trend: 'up',
    status: 'warning',
    status_label: 'Warning',
    computed_at: null,
  },
};

const oeeTrend: KpiTrendPoint[] = [
  { period: '2026-04', value: '79.00', target: '85.00', status: 'warning' },
  { period: '2026-05', value: '80.10', target: '85.00', status: 'warning' },
  { period: '2026-06', value: '80.50', target: '85.00', status: 'warning' },
  { period: '2026-07', value: '81.00', target: '85.00', status: 'warning' },
  { period: '2026-08', value: '82.20', target: '85.00', status: 'warning' },
  { period: '2026-09', value: '83.50', target: '85.00', status: 'warning' },
];

describe('KpiStrip', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('renders a role-filtered KPI without breaking the dashboard', async () => {
    vi.spyOn(kpiApi, 'scorecard').mockResolvedValue([{
      definition: {
        id: 'kpi-id',
        code: 'on_time_delivery',
        name: 'On-Time Delivery Rate',
        module: 'supply_chain',
        unit: 'percentage',
        direction: 'higher_is_better',
        target_value: '95.0000',
        warning_threshold: '90.0000',
      },
      snapshot: null,
    }]);
    vi.spyOn(kpiApi, 'trends').mockResolvedValue({});

    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <KpiStrip codes={['on_time_delivery']} />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByText('On-Time Delivery Rate')).toBeInTheDocument();
    expect(screen.getByRole('link')).toHaveAttribute('href', '/dashboard/ppc#kpi-on_time_delivery');
  });

  it('revalidates a cached scorecard on mount instead of trusting an old dashboard snapshot', async () => {
    const scorecard = vi.spyOn(kpiApi, 'scorecard').mockResolvedValue([oeeItem]);
    vi.spyOn(kpiApi, 'trends').mockResolvedValue({});
    const now = new Date();
    const previousMonth = new Date(now.getFullYear(), now.getMonth() - 1, 1);
    queryClient.clear();
    queryClient.setQueryData(
      ['kpi', 'scorecard', previousMonth.getFullYear(), previousMonth.getMonth() + 1],
      [{ ...oeeItem, snapshot: { ...oeeItem.snapshot!, actual_value: '70.0000' } }],
    );

    const view = render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <KpiStrip codes={['oee']} compact />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    await waitFor(() => expect(scorecard).toHaveBeenCalledTimes(1));
    expect(await screen.findByText('83.5%')).toBeInTheDocument();
    view.unmount();
    queryClient.clear();
  });

  it('renders status dot + 6-pt sparkline + MoM delta for a compact KPI', async () => {
    vi.spyOn(kpiApi, 'scorecard').mockResolvedValue([oeeItem]);
    vi.spyOn(kpiApi, 'trends').mockResolvedValue({ oee: oeeTrend });

    renderStrip(['oee']);

    expect(await screen.findByText('Overall Equipment Effectiveness')).toBeInTheDocument();
    // Status dot carries the snapshot status for assistive tech.
    expect(screen.getByLabelText('Status: Warning')).toBeInTheDocument();
    // 6-month sparkline from GET /dashboard/kpi/trends?months=6.
    expect(screen.getByRole('img', { name: /monthly trend: 6 points/i })).toBeInTheDocument();
    // MoM delta: 83.50 − 81.00 = +2.50 percentage points.
    expect(screen.getByText('+2.50%')).toBeInTheDocument();
  });

  it('renders an empty message (not a crash) when the scorecard strips every code', async () => {
    // Permission-stripped: the server omits KPIs the viewer may not read.
    vi.spyOn(kpiApi, 'scorecard').mockResolvedValue({} as unknown as KpiScorecardItem[]);
    vi.spyOn(kpiApi, 'trends').mockResolvedValue({});

    renderStrip(['oee']);

    expect(await screen.findByText('No KPI data available for this period.')).toBeInTheDocument();
  });
});
