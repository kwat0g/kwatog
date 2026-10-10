import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it } from 'vitest';
import { ChainHeader } from './ChainHeader';

afterEach(cleanup);

it('keeps a blocker and its recovery visible once without hovering', () => {
  render(
    <MemoryRouter>
      <ChainHeader
        steps={[
          { key: 'entered', label: 'Order entered', state: 'done' },
          {
            key: 'mrp',
            label: 'MRP Planned',
            state: 'rejected',
            href: '/mrp/plans',
            description: 'Invalid resin unit. PPC: correct the PR, then run MRP again.',
          },
        ]}
      />
    </MemoryRouter>,
  );
  expect(
    screen.getAllByText('Invalid resin unit. PPC: correct the PR, then run MRP again.'),
  ).toHaveLength(1);
  expect(screen.getByRole('link', { name: 'MRP Planned' })).toHaveAttribute('href', '/mrp/plans');
});

it('can hide status messages without removing linked chain steps', () => {
  render(
    <MemoryRouter>
      <ChainHeader
        showStatusMessages={false}
        steps={[
          {
            key: 'receipt',
            label: 'GRN',
            state: 'active',
            href: '/inventory/grn/receipt',
            description: 'Receipt created — awaiting QC / acceptance',
          },
        ]}
      />
    </MemoryRouter>,
  );

  expect(screen.queryByText('Receipt created — awaiting QC / acceptance')).not.toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'GRN' })).toHaveAttribute(
    'href',
    '/inventory/grn/receipt',
  );
});
