import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Toolbar } from './Toolbar';

describe('Toolbar', () => {
  it('aligns start and end controls inside one named layout region', () => {
    const { container } = render(
      <Toolbar
        inset
        layout="filter-bar"
        start={<span>Search and filters</span>}
        end={<button type="button">Export</button>}
      />,
    );

    expect(container.querySelector('[data-layout="filter-bar"]')).toHaveClass('px-5');
    expect(container.querySelector('[data-toolbar-start]')).toHaveTextContent('Search and filters');
    expect(screen.getByRole('button', { name: 'Export' }).parentElement).toHaveAttribute('data-toolbar-end');
  });
});
