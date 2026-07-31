import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { forecastingApi } from '@/api/forecasting';
import { ForecastAccuracyPanel } from './ForecastAccuracyPanel';

function renderPanel() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, refetchOnWindowFocus: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <ForecastAccuracyPanel year={2026} />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('ForecastAccuracyPanel', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  it('shows role-relevant forecast accuracy on the dashboard', async () => {
    vi.spyOn(forecastingApi, 'accuracySummary').mockResolvedValue({
      mape: 12.34,
      bias: 3.21,
      periods_evaluated: 18,
      monthly: [],
    });

    renderPanel();

    expect(await screen.findByText('12.3%')).toBeInTheDocument();
    expect(screen.getByText('+3.2%')).toBeInTheDocument();
    expect(screen.getByText('18')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /details/i })).toHaveAttribute('href', '/forecasting/accuracy');
  });

  it('keeps forecasting discoverable before actual periods are reconciled', async () => {
    vi.spyOn(forecastingApi, 'accuracySummary').mockResolvedValue({
      mape: null,
      bias: null,
      periods_evaluated: 0,
      monthly: [],
    });

    renderPanel();

    expect(await screen.findByText('No reconciled periods')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /details/i })).toBeInTheDocument();
  });

  it('renders a retryable error without breaking the parent dashboard', async () => {
    vi.spyOn(forecastingApi, 'accuracySummary').mockRejectedValue(new Error('network error'));

    renderPanel();

    await waitFor(() => expect(screen.getByText('Failed to load accuracy')).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument();
  });
});
