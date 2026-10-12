import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { forecastingApi } from '@/api/forecasting';
import { DemandForecastPanel } from './DemandForecastPanel';

function renderPanel() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, refetchOnWindowFocus: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <DemandForecastPanel />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('DemandForecastPanel', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  it('surfaces the mean forecast confidence in the header, read-only', async () => {
    vi.spyOn(forecastingApi, 'list').mockResolvedValue(
      [80, 60, null].map((confidence, i) => ({
        id: `f-${i}`,
        forecast_year: 2026,
        forecast_month: i + 1,
        method: 'moving_avg' as const,
        forecasted_quantity: 100 + i * 10,
        confidence_level: confidence,
        actual_quantity: null,
        variance: null,
        product: { id: 'p-1', part_number: 'WBR-001', name: 'Wiper Bushing' },
      })),
    );

    renderPanel();

    // Mean of the two scored rows; the unscored row does not dilute it.
    expect(await screen.findByText(/3 entries · 2026 · 70% avg conf/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /view all 3 forecasts/i })).toHaveAttribute(
      'href',
      '/forecasting/demand',
    );
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument();
  });
});
