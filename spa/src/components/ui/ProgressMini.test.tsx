import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ProgressMini } from './ProgressMini';

describe('ProgressMini', () => {
  it('exposes a capped value and nearby text for the target', () => {
    render(
      <ProgressMini label="On hand vs reorder point" value={14} target={10} valueLabel="14" targetLabel="10" />,
    );
    expect(screen.getByRole('meter', { name: 'On hand vs reorder point: 14 of 10, target met' }))
      .toHaveAttribute('aria-valuenow', '10');
    expect(screen.getByText('14 / 10')).toBeInTheDocument();
  });
});
