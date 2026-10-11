import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { DashboardShell } from './DashboardShell';

vi.mock('@/components/dashboard/TimeClockQuickAction', () => ({
  TimeClockQuickAction: () => null,
}));

function renderShell(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  );
}

function queryOf<T>(overrides: Partial<{ data: T | undefined; isLoading: boolean; isError: boolean; refetch: () => unknown }>) {
  return {
    data: undefined as T | undefined,
    isLoading: false,
    isError: false,
    refetch: vi.fn(),
    ...overrides,
  };
}

function Thrower(): ReactNode {
  throw new Error('widget blew up');
}

describe('DashboardShell', () => {
  it('shows a stale badge when updatedAt is older than the poll window', () => {
    renderShell(
      <DashboardShell<{ items: unknown[] }>
        title="Plant Manager Dashboard"
        query={queryOf({ data: { items: [] } })}
        updatedAt={new Date(Date.now() - 10 * 60_000).toISOString()}
        staleAfterMs={60_000}
      >
        {() => <div>body</div>}
      </DashboardShell>,
    );

    expect(screen.getByRole('status')).toHaveTextContent(/stale/i);
    expect(screen.getByText('body')).toBeInTheDocument();
  });

  it('does not show a stale badge for fresh data', () => {
    renderShell(
      <DashboardShell<{ items: unknown[] }>
        title="Plant Manager Dashboard"
        query={queryOf({ data: { items: [] } })}
        updatedAt={new Date().toISOString()}
        staleAfterMs={60_000}
      >
        {() => <div>body</div>}
      </DashboardShell>,
    );

    expect(screen.queryByRole('status')).not.toBeInTheDocument();
  });

  it('recovers a crashed widget with a retry button that re-fires the query', () => {
    const refetch = vi.fn();
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    try {
      renderShell(
        <DashboardShell<{ items: unknown[] }>
          title="Plant Manager Dashboard"
          query={queryOf({ data: { items: [] }, refetch })}
        >
          {() => <Thrower />}
        </DashboardShell>,
      );

      fireEvent.click(screen.getByRole('button', { name: /try again/i }));
      expect(refetch).toHaveBeenCalledTimes(1);
    } finally {
      error.mockRestore();
    }
  });

  it('shows an empty message (not a blank grid, not the error state) for an empty payload', () => {
    renderShell(
      <DashboardShell<{ items: unknown[] }>
        title="Plant Manager Dashboard"
        query={queryOf({ data: { items: [] } })}
        isEmpty={(d) => d.items.length === 0}
      >
        {() => <div>body</div>}
      </DashboardShell>,
    );

    expect(screen.getByText('No data yet')).toBeInTheDocument();
    expect(screen.queryByText('body')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /try again/i })).not.toBeInTheDocument();
  });

  it('renders the header slot above the body in every state', () => {
    const { unmount } = renderShell(
      <DashboardShell<{ items: unknown[] }>
        title="Plant Manager Dashboard"
        header={<div>strip</div>}
        query={queryOf({ data: { items: [] } })}
      >
        {() => <div>body</div>}
      </DashboardShell>,
    );
    expect(screen.getByText('strip')).toBeInTheDocument();
    unmount();

    renderShell(
      <DashboardShell<{ items: unknown[] }>
        title="Plant Manager Dashboard"
        header={<div>strip</div>}
        query={queryOf({ isLoading: true })}
      >
        {() => <div>body</div>}
      </DashboardShell>,
    );
    expect(screen.getByText('strip')).toBeInTheDocument();
  });
});
