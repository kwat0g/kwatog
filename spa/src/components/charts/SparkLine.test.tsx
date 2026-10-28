import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { SparkLine } from './SparkLine';

describe('SparkLine', () => {
  it('exposes the metric and direction as an accessible text alternative', () => {
    const { container } = render(<SparkLine data={[12, 15, 14]} label="Open orders" />);

    expect(screen.getByRole('img', { name: 'Open orders: 3 points, rising from 12 to 14.' })).toBeInTheDocument();
    const points = container.querySelector('polyline')?.getAttribute('points') ?? '';
    const yCoordinates = points.split(' ').map((point) => Number(point.split(',')[1]));
    expect(Math.max(...yCoordinates) - Math.min(...yCoordinates)).toBeGreaterThan(1);
  });

  it('describes an empty series without rendering a false trend', () => {
    render(<SparkLine data={[]} label="On-time delivery" />);

    expect(screen.getByRole('img', { name: 'On-time delivery: no values.' })).toBeInTheDocument();
  });
});
