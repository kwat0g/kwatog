import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { kpiApi } from '@/api/kpi';
import { KpiStrip } from './KpiStrip';

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

    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <KpiStrip codes={['on_time_delivery']} />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByText('On-Time Delivery Rate')).toBeInTheDocument();
    expect(screen.getByRole('link')).toHaveAttribute('href', '/dashboard/scorecard');
  });
});
