import { render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { Button } from '@/components/ui/Button';
import { PageHeader } from './PageHeader';

describe('PageHeader pattern slots', () => {
  it('shows supporting count and freshness metadata with secondary actions before one primary', () => {
    render(
      <MemoryRouter initialEntries={['/hr/employees']}>
        <PageHeader
          title="Employees"
          subtitle="42 active employees"
          lastUpdated={<time dateTime="2026-10-27T09:00:00Z">a few seconds ago</time>}
          secondaryActions={<Button variant="secondary">Export</Button>}
          primaryAction={<Button variant="primary">Add employee</Button>}
        />
      </MemoryRouter>,
    );

    expect(screen.getByRole('heading', { name: 'Employees' })).toBeInTheDocument();
    expect(screen.getByText('42 active employees')).toBeInTheDocument();
    expect(screen.getByText('a few seconds ago').parentElement).toHaveTextContent(
      'Updated a few seconds ago',
    );

    const header = screen.getByRole('heading', { name: 'Employees' }).parentElement?.parentElement;
    expect(header).not.toBeNull();
    expect(within(header as HTMLElement).getAllByRole('button').map((button) => button.textContent)).toEqual([
      'Export',
      'Add employee',
    ]);
  });

  it('keeps legacy action groups working', () => {
    render(
      <MemoryRouter>
        <PageHeader title="Orders" actions={<Button>New order</Button>} />
      </MemoryRouter>,
    );

    expect(screen.getByRole('button', { name: 'New order' })).toBeInTheDocument();
  });
});
