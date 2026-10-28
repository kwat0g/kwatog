import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { StatusDot } from './StatusDot';

describe('StatusDot', () => {
  it('pairs a semantic marker with visible state text', () => {
    const { container } = render(<StatusDot label="Needs review" variant="warning" />);

    expect(screen.getByText('Needs review')).toBeInTheDocument();
    expect(container.querySelector('[aria-hidden="true"]')).toHaveClass('bg-warning');
  });
});
