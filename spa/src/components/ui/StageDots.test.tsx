import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { StageDots } from './StageDots';

describe('StageDots', () => {
  it('names each stage and marks the current step', () => {
    render(
      <StageDots
        label="Order to cash"
        steps={[
          { key: 'confirmed', label: 'Confirmed', state: 'done' },
          { key: 'production', label: 'In production', state: 'active' },
          { key: 'delivery', label: 'Delivered', state: 'pending' },
        ]}
      />,
    );

    expect(screen.getByRole('list', { name: 'Order to cash' })).toBeInTheDocument();
    expect(screen.getByText('Confirmed: Complete')).toBeInTheDocument();
    expect(screen.getByText('In production: Current').parentElement).toHaveAttribute(
      'aria-current',
      'step',
    );
    expect(screen.getByText('In production', { selector: 'span' })).toBeVisible();
    expect(screen.getByText('Delivered: Not started')).toBeInTheDocument();
  });

  it('distinguishes rejected and skipped stages beyond their color', () => {
    const { container } = render(
      <StageDots
        steps={[
          { key: 'qc', label: 'Quality', state: 'rejected' },
          { key: 'return', label: 'Return', state: 'skipped' },
        ]}
      />,
    );

    expect(screen.getByText('Quality: Rejected')).toBeInTheDocument();
    expect(screen.getByText('Return: Skipped')).toBeInTheDocument();
    const markers = container.querySelectorAll('[aria-hidden="true"]');
    expect(markers[0]).toHaveClass('rounded-sm');
    expect(markers[1]).toHaveClass('h-0.5', 'w-2', 'rounded-none');
  });
});
