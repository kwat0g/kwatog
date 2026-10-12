import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { CashFlowPanel } from './CashFlowPanel';
import type { CashFlowForecastData } from '@/types/forecasting-dashboard';

const data: CashFlowForecastData = {
  historical: [{ year: 2026, month: 7, value: 4000 }],
  forecast: [{ year: 2026, month: 10, value: 4500, confidence: 80 }],
  trend: 'up',
  kpi: { label: 'Projected Net Cash Flow (3mo)', value: '13500.00', unit: 'PHP', trend: 'up' },
  months: [
    { year: 2026, month: 10, inflow: 5000, outflow: 500, net: 4500, confidence: 80 },
    { year: 2026, month: 11, inflow: 5000, outflow: 500, net: 4500, confidence: 80 },
    { year: 2026, month: 12, inflow: 5000, outflow: 500, net: 4500, confidence: 80 },
  ],
  confidence: 80,
};

function renderPanel(props?: Partial<Parameters<typeof CashFlowPanel>[0]>) {
  return render(
    <MemoryRouter>
      <CashFlowPanel
        data={data}
        isLoading={false}
        isError={false}
        formatValue={(v) => `₱${v.toLocaleString()}`}
        {...props}
      />
    </MemoryRouter>,
  );
}

describe('CashFlowPanel', () => {
  it('shows the inflow/outflow split, confidence and a read-only link-out, never inputs', () => {
    renderPanel();

    expect(screen.getByText('Avg confidence 80%')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /open/i })).toHaveAttribute('href', '/forecasting/demand');
    // Read-only: no override inputs, no recompute controls.
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('switch')).not.toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });

  it('renders an empty state when the books carry no history', () => {
    renderPanel({ data: { ...data, months: [] } });

    expect(screen.getByText('No data yet')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /open/i })).not.toBeInTheDocument();
  });
});
