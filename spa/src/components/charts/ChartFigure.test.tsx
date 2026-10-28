import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ChartFigure } from './ChartFigure';

describe('ChartFigure', () => {
  it('labels the chart and exposes a text table alternative', () => {
    render(
      <ChartFigure label="Monthly orders" columns={["Month", "Orders"]} rows={[["May", 12]]}>
        <svg />
      </ChartFigure>,
    );

    expect(screen.getByRole('img', { name: 'Monthly orders' })).toBeInTheDocument();
    const table = screen.getByRole('table', { name: 'Monthly orders data' });
    expect(table).toBeInTheDocument();
    expect(table.parentElement).toHaveClass('sr-only');
    expect(screen.getByRole('row', { name: 'May 12' })).toBeInTheDocument();
  });
});
