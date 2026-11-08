import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  dashboardLayoutApi,
  dashboardLayoutQueryKeys,
  type DashboardLayoutItem,
} from '@/api/dashboard-layout';
import { useAuthStore } from '@/stores/authStore';
import { realtimeIdentityKey } from '@/lib/realtime/identity';
import { DashboardPicker } from './DashboardPicker';

function item(overrides: Partial<DashboardLayoutItem> = {}): DashboardLayoutItem {
  return {
    key: 'self.dtr_today',
    name: 'My Shift Today',
    description: null,
    module: 'attendance',
    permission: null,
    permission_any: null,
    render_kind: 'scalar',
    link_path: '/self-service',
    data: null,
    x: 0,
    y: 0,
    w: 4,
    h: 4,
    source: 'role',
    ...overrides,
  };
}

function renderPicker(layout: DashboardLayoutItem[] = [item()], layoutVersion = 'version-a') {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, refetchOnWindowFocus: false } },
  });

  const view = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <DashboardPicker layout={layout} layoutVersion={layoutVersion} />
      </MemoryRouter>
    </QueryClientProvider>,
  );

  return { ...view, queryClient };
}

describe('DashboardPicker', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('isolates personal dashboard cache keys by authenticated user', () => {
    expect(dashboardLayoutQueryKeys.widgets('user-a:role-a'))
      .not.toEqual(dashboardLayoutQueryKeys.widgets('user-b:role-a'));
    expect(dashboardLayoutQueryKeys.layout('user-a', true))
      .not.toEqual(dashboardLayoutQueryKeys.layout('user-b', true));
    expect(dashboardLayoutQueryKeys.widgetData('user-a', ['approvals.pending']))
      .not.toEqual(dashboardLayoutQueryKeys.widgetData('user-b', ['approvals.pending']));
  });

  it('isolates permission-filtered dashboard cache keys when one user changes access', () => {
    const user = {
      id: 'user-a',
      role: { id: 'role-a', slug: 'manager' },
      permissions: ['dashboard.view'],
      delegated_approvals: [],
      features: ['dashboard'],
    };
    const updatedUser = { ...user, permissions: ['dashboard.view', 'quality.view'] };
    const initialIdentity = realtimeIdentityKey(user);
    const updatedIdentity = realtimeIdentityKey(updatedUser);

    expect(dashboardLayoutQueryKeys.widgets(initialIdentity))
      .not.toEqual(dashboardLayoutQueryKeys.widgets(updatedIdentity));
    expect(dashboardLayoutQueryKeys.layout(initialIdentity, true))
      .not.toEqual(dashboardLayoutQueryKeys.layout(updatedIdentity, true));
    expect(dashboardLayoutQueryKeys.widgetData(initialIdentity, ['approvals.pending']))
      .not.toEqual(dashboardLayoutQueryKeys.widgetData(updatedIdentity, ['approvals.pending']));
  });

  it('adds a permitted widget and saves a packed layout', async () => {
    vi.spyOn(dashboardLayoutApi, 'widgets').mockResolvedValue([
      {
        key: 'self.dtr_today',
        name: 'My Shift Today',
        description: null,
        module: 'attendance',
        permission: null,
        permission_any: null,
        render_kind: 'scalar',
        link_path: '/self-service',
        default_w: 4,
        default_h: 4,
      },
      {
        key: 'self.leave_balance',
        name: 'My Leave Balance',
        description: null,
        module: 'leave',
        permission: null,
        permission_any: null,
        render_kind: 'scalar',
        link_path: '/self-service',
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
        permission_any: null,
        render_kind: 'scalar',
        link_path: '/self-service',
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

  it('preserves an in-progress draft when another tab refreshes the saved layout', async () => {
    vi.spyOn(dashboardLayoutApi, 'widgets').mockResolvedValue([
      {
        key: 'self.dtr_today',
        name: 'My Shift Today',
        description: null,
        module: 'attendance',
        permission: null,
        permission_any: null,
        render_kind: 'scalar',
        link_path: '/self-service',
        default_w: 4,
        default_h: 4,
      },
      {
        key: 'self.leave_balance',
        name: 'My Leave Balance',
        description: null,
        module: 'leave',
        permission: null,
        permission_any: null,
        render_kind: 'scalar',
        link_path: '/self-service',
        default_w: 4,
        default_h: 4,
      },
    ]);

    const view = renderPicker();
    fireEvent.click(screen.getByRole('button', { name: /customize dashboard/i }));
    await screen.findByText('On your dashboard');
    fireEvent.click(screen.getByRole('button', { name: /remove my shift today/i }));
    fireEvent.click(screen.getByRole('button', { name: /my leave balance/i }));

    view.rerender(
      <QueryClientProvider client={view.queryClient}>
        <MemoryRouter>
          <DashboardPicker
            layout={[item({ key: 'remote.widget', name: 'Remote widget', source: 'user' })]}
            layoutVersion="version-b"
          />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByText('My Leave Balance')).toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent(/layout changed in another tab/i);
    expect(screen.getByRole('button', { name: /save layout/i })).toBeDisabled();
  });

  it('applies the successful API layout response to the actor cache immediately', async () => {
    const savedItem = item({ source: 'user' });
    vi.spyOn(dashboardLayoutApi, 'widgets').mockResolvedValue([]);
    const save = vi.spyOn(dashboardLayoutApi, 'save').mockResolvedValue({ items: [savedItem], version: 'version-b' });

    const view = renderPicker();
    const layoutQueryKey = dashboardLayoutQueryKeys.layout(
      realtimeIdentityKey(useAuthStore.getState().user),
      true,
    );
    view.queryClient.setQueryData(layoutQueryKey, {
      items: [item()],
      version: 'version-a',
    });

    fireEvent.click(screen.getByRole('button', { name: /customize dashboard/i }));
    fireEvent.click(await screen.findByRole('button', { name: /save layout/i }));

    await waitFor(() => expect(save).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    await waitFor(() => {
      expect(view.queryClient.getQueryData(layoutQueryKey)).toMatchObject({
        version: 'version-b',
        items: [{ key: 'self.dtr_today', source: 'user', data: null }],
      });
    });
  });
});
