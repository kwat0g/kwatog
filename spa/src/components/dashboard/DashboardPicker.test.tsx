import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { dashboardLayoutApi, type DashboardLayoutItem } from '@/api/dashboard-layout';
import { DashboardPicker } from './DashboardPicker';

function item(overrides: Partial<DashboardLayoutItem> = {}): DashboardLayoutItem {
  return {
    key: 'self.dtr_today',
    name: 'My Shift Today',
    description: null,
    module: 'attendance',
    permission: null,
    render_kind: 'scalar',
    data: null,
    x: 0,
    y: 0,
    w: 4,
    h: 4,
    source: 'role',
    ...overrides,
  };
}

function renderPicker(layout: DashboardLayoutItem[] = [item()]) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, refetchOnWindowFocus: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <DashboardPicker layout={layout} layoutVersion="version-a" />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('DashboardPicker', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('adds a permitted widget and saves a packed layout', async () => {
    vi.spyOn(dashboardLayoutApi, 'widgets').mockResolvedValue([
      {
        key: 'self.dtr_today',
        name: 'My Shift Today',
        description: null,
        module: 'attendance',
        permission: null,
        render_kind: 'scalar',
        default_w: 4,
        default_h: 4,
      },
      {
        key: 'self.leave_balance',
        name: 'My Leave Balance',
        description: null,
        module: 'leave',
        permission: null,
        render_kind: 'scalar',
        default_w: 4,
        default_h: 4,
      },
    ]);
    const save = vi.spyOn(dashboardLayoutApi, 'save').mockResolvedValue({ items: [], version: 'version-b' });

    renderPicker();
    fireEvent.click(screen.getByRole('button', { name: /customize dashboard/i }));

    expect(await screen.findByText('My Leave Balance')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /my leave balance/i }));
    fireEvent.click(screen.getByRole('button', { name: /save layout/i }));

    await waitFor(() => expect(save).toHaveBeenCalledTimes(1));
    expect(save).toHaveBeenCalledWith([
      { key: 'self.dtr_today', x: 0, y: 0, w: 4, h: 4 },
      { key: 'self.leave_balance', x: 4, y: 0, w: 4, h: 4 },
    ], 'version-a');
  });

  it('removes a widget from the saved layout', async () => {
    vi.spyOn(dashboardLayoutApi, 'widgets').mockResolvedValue([
      {
        key: 'self.dtr_today',
        name: 'My Shift Today',
        description: null,
        module: 'attendance',
        permission: null,
        render_kind: 'scalar',
        default_w: 4,
        default_h: 4,
      },
    ]);
    const save = vi.spyOn(dashboardLayoutApi, 'save').mockResolvedValue({ items: [], version: 'version-b' });

    renderPicker();
    fireEvent.click(screen.getByRole('button', { name: /customize dashboard/i }));
    await screen.findByText('On your dashboard');
    fireEvent.click(screen.getByRole('button', { name: /remove my shift today/i }));
    fireEvent.click(screen.getByRole('button', { name: /save layout/i }));

    await waitFor(() => expect(save).toHaveBeenCalledWith([], 'version-a'));
  });
});
