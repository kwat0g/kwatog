import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { FilterBar } from './FilterBar';

describe('FilterBar', () => {
  it('shows how many filters are currently narrowing the list', () => {
    render(
      <FilterBar
        filters={[{
          key: 'status',
          label: 'Status',
          type: 'select',
          options: [{ value: 'active', label: 'Active' }],
        }]}
        values={{ status: 'active', search: 'bolt' }}
      />,
    );

    expect(screen.getByText('2 active filters')).toBeInTheDocument();
  });
});
