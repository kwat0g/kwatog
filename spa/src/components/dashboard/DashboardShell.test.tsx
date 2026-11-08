import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DashboardAttentionList, DashboardShell } from './DashboardShell';

const { canPermission, actionCenterGet } = vi.hoisted(() => ({
  canPermission: vi.fn((_permission: string) => false),
  actionCenterGet: vi.fn(),
}));

vi.mock('@/components/dashboard/TimeClockQuickAction', () => ({
  TimeClockQuickAction: () => null,
}));
vi.mock('@/api/actionCenter', () => ({ actionCenterApi: { get: actionCenterGet } }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: canPermission }) }));
vi.mock('@/hooks/useRealtimeSignal', () => ({ useRealtimeSignal: vi.fn() }));

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
  beforeEach(() => {
    canPermission.mockReturnValue(false);
    actionCenterGet.mockReset();
  });

  it('revalidates the cached action-center snapshot when the dashboard mounts', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    client.setQueryData(['action-center'], { items: [], summary: { total: 0 } });
    canPermission.mockReturnValue(true);
    actionCenterGet.mockResolvedValue({ items: [], summary: { total: 0 } });

    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <DashboardAttentionList />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByRole('heading', { name: 'Needs your attention' })).toBeInTheDocument();
    expect(actionCenterGet).toHaveBeenCalledTimes(1);
    client.clear();
  });

  it('shows the server snapshot timestamp when a dashboard does not pass an updatedAt prop', () => {
    renderShell(
      <DashboardShell<{ items: unknown[]; generated_at: string }>
        title="Warehouse Dashboard"
        query={queryOf({
          data: { items: [], generated_at: '2026-11-04T08:15:00.000Z' },
        })}
      >
        {() => <div>body</div>}
      </DashboardShell>,
    );

    expect(screen.getByText(/As of/i)).toBeInTheDocument();
    expect(screen.getByText(/Nov 4, 2026/i)).toBeInTheDocument();
  });

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
