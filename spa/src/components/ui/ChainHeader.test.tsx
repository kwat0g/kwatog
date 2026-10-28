import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ChainHeader } from './ChainHeader';

describe('ChainHeader', () => {
  it('reserves row-token space for its absolutely positioned stage labels', () => {
    const { container } = render(
      <ChainHeader
        steps={[
          { key: 'order', label: 'Sales order', date: '2026-04-03', state: 'done' },
          { key: 'production', label: 'Production', state: 'active' },
        ]}
      />,
    );

    expect(screen.getByText('Sales order')).toBeInTheDocument();
    expect(container.firstElementChild).toHaveClass('min-h-row');
  });
});
