import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { ForecastPanel } from './ForecastPanel';
import type { ForecastPanelData } from '@/types/forecasting-dashboard';

const data: ForecastPanelData = {
  historical: Array.from({ length: 12 }, (_, i) => ({ year: 2025, month: i + 1, value: 40 + i })),
  forecast: [
    { year: 2026, month: 1, value: 52, confidence: 80 },
    { year: 2026, month: 2, value: 53, confidence: 70 },
    { year: 2026, month: 3, value: 54, confidence: 60 },
  ],
  trend: 'up',
  kpi: { label: 'Projected Headcount', value: '53', unit: 'count', trend: 'up' },
};

function renderPanel(props?: Partial<Parameters<typeof ForecastPanel>[0]>) {
  return render(
    <MemoryRouter>
      <ForecastPanel
        data={data}
        isLoading={false}
        isError={false}
        title="Headcount Forecast"
        detailsHref="/hr/employees"
        {...props}
      />
    </MemoryRouter>,
  );
}

describe('ForecastPanel', () => {
  it('shows the mean forecast confidence and a read-only link-out, never override inputs', () => {
    renderPanel();

    expect(screen.getByText('Avg confidence 70%')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /open/i })).toHaveAttribute('href', '/hr/employees');
    // Read-only: override + MRP toggle stay on the /forecasting pages.
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('switch')).not.toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });

  it('renders no link when no data surface is provided', () => {
    renderPanel({ detailsHref: undefined });

    expect(screen.queryByRole('link', { name: /open/i })).not.toBeInTheDocument();
  });
});
