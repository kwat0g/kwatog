import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { CapacityPanel } from './CapacityPanel';
import type { CapacityForecastData } from '@/types/forecasting-dashboard';

const data: CapacityForecastData = {
  historical: [],
  forecast: [{ year: 2026, month: 10, value: 62.5, confidence: 80 }],
  trend: 'up',
  kpi: { label: 'Projected Capacity Load (3mo)', value: '95.0', unit: 'pct', trend: 'up' },
  months: [
    { year: 2026, month: 10, load_hours: 300, capacity_hours: 480, util_pct: 62.5, confidence: 80 },
    { year: 2026, month: 11, load_hours: 456, capacity_hours: 480, util_pct: 95.0, confidence: 80 },
    { year: 2026, month: 12, load_hours: 100, capacity_hours: 480, util_pct: 20.8, confidence: 80 },
  ],
  breach: true,
  confidence: 80,
};

function renderPanel(props?: Partial<Parameters<typeof CapacityPanel>[0]>) {
  return render(
    <MemoryRouter>
      <CapacityPanel
        data={data}
        isLoading={false}
        isError={false}
        {...props}
      />
    </MemoryRouter>,
  );
}

describe('CapacityPanel', () => {
  it('shows the load/capacity split, confidence, overload state and a read-only link-out, never inputs', () => {
    renderPanel();

    expect(screen.getByText('Avg confidence 80%')).toBeInTheDocument();
    expect(screen.getAllByText('Overload').length).toBeGreaterThan(0);
    expect(screen.getByRole('link', { name: /open/i })).toHaveAttribute('href', '/production/schedule');
    // Read-only: no override inputs, no recompute controls.
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('switch')).not.toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });

  it('renders an empty state when no machine capacity is configured', () => {
    renderPanel({ data: { ...data, months: [], breach: false } });

    expect(screen.getByText('No data yet')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /open/i })).not.toBeInTheDocument();
  });
});
