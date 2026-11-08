import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { actionCenterApi } from '@/api/actionCenter';
import { useRealtimeSignal } from '@/hooks/useRealtimeSignal';
import type { ActionCenterData, ActionCenterTaskUpdate } from '@/types/actionCenter';
import ActionCenterPage from './index';

vi.mock('@/api/actionCenter', () => ({ actionCenterApi: { get: vi.fn(), updateTasks: vi.fn() } }));
vi.mock('@/hooks/useRealtimeSignal', () => ({ useRealtimeSignal: vi.fn() }));
vi.mock('@/stores/authStore', () => ({
  useAuthStore: (selector: (state: { user: { id: string; name: string } }) => unknown) =>
    selector({ user: { id: 'action-center-viewer', name: 'Viewer' } }),
}));

const queue: ActionCenterData = {
  category_options: [{ value: 'production', label: 'Production' }],
  items: [{
    id: 'work_order:wo-1',
    category: 'production',
    kind: 'work_order',
    title: 'Work order needs attention',
    description: 'The work order is overdue.',
    reference: 'WO-001',
    priority: 'high',
    status_label: 'In progress',
    link: '/production/work-orders/wo-1',
    created_at: '2026-11-01T00:00:00Z',
    due_at: null,
    age_hours: 2,
    is_overdue: false,
    owner_label: null,
    task_state: 'open',
    task_state_label: 'Open',
    assigned_to: null,
    updated_by: null,
    snoozed_until: null,
  }],
  summary: {
    total: 1,
    critical: 0,
    high: 1,
    overdue: 0,
    owned_by_me: 0,
    unassigned: 1,
    by_category: { production: 1 },
  },
  generated_at: '2026-11-02T00:00:00Z',
};

describe('Action Center task response freshness', () => {
  beforeEach(() => vi.clearAllMocks());

  it('subscribes to the current user private badge signal', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    vi.mocked(actionCenterApi.get).mockResolvedValue(queue);

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter><ActionCenterPage /></MemoryRouter>
      </QueryClientProvider>,
    );

    await screen.findByText('Work order needs attention');
    expect(useRealtimeSignal).toHaveBeenCalledWith(
      'badges_changed',
      'badge-user.action-center-viewer',
      '.BadgesChanged',
    );
    queryClient.clear();
  });

  it('shows the returned assignment and task state before the queue refetch completes', async () => {
    const updated: ActionCenterTaskUpdate = {
      item_id: 'work_order:wo-1',
      state: 'acknowledged',
      assigned_to: { id: 'user-1', name: 'Purchasing User' },
      snoozed_until: null,
    };
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    vi.mocked(actionCenterApi.get).mockResolvedValue(queue);
    vi.mocked(actionCenterApi.updateTasks).mockResolvedValue([updated]);

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter><ActionCenterPage /></MemoryRouter>
      </QueryClientProvider>,
    );

    await screen.findByText('Work order needs attention');
    vi.mocked(actionCenterApi.get).mockImplementation(() => new Promise(() => undefined));
    fireEvent.click(screen.getByRole('checkbox', { name: 'Select Work order needs attention' }));
    fireEvent.click(screen.getByRole('button', { name: 'Claim' }));

    await waitFor(() => expect(screen.getByText('Assigned: Purchasing User')).toBeInTheDocument());
    expect(screen.getByText('Acknowledged')).toBeInTheDocument();
    queryClient.clear();
  });
});
