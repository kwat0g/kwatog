import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { AttendanceRiskPanel } from './AttendanceRiskPanel';
import type { AttendanceForecastData } from '@/types/forecasting-dashboard';

const data: AttendanceForecastData = {
  historical: [],
  forecast: [{ year: 2026, month: 10, value: 96.5, confidence: 90 }],
  trend: 'stable',
  kpi: { label: 'Projected Attendance Rate (3mo)', value: '96.5', unit: 'pct', trend: 'stable' },
  months: [
    { year: 2026, month: 10, expected_rate: 96.5, leave_load: 2.1, risk: 'low', confidence: 90 },
    { year: 2026, month: 11, expected_rate: 96.5, leave_load: 9.4, risk: 'medium', confidence: 90 },
    { year: 2026, month: 12, expected_rate: 96.5, leave_load: 17.8, risk: 'high', confidence: 90 },
  ],
  risk: 'high',
  confidence: 90,
};

function renderPanel(props?: Partial<Parameters<typeof AttendanceRiskPanel>[0]>) {
  return render(
    <MemoryRouter>
      <AttendanceRiskPanel
        data={data}
        isLoading={false}
        isError={false}
        {...props}
      />
    </MemoryRouter>,
  );
}

describe('AttendanceRiskPanel', () => {
  it('shows the expected rate, leave load, risk state and a read-only link-out, never inputs', () => {
    renderPanel();

    expect(screen.getByText('Avg confidence 90%')).toBeInTheDocument();
    expect(screen.getByText('Projected Attendance Rate (3mo)')).toBeInTheDocument();
    expect(screen.getAllByText('high risk').length).toBeGreaterThan(0);
    expect(screen.getByRole('link', { name: /open/i })).toHaveAttribute('href', '/hr/attendance');
    // Read-only: no override inputs, no recompute controls.
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('switch')).not.toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });

  it('renders an empty state when no attendance history exists', () => {
    renderPanel({ data: { ...data, months: [], risk: 'low' } });

    expect(screen.getByText('No data yet')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /open/i })).not.toBeInTheDocument();
  });
});
