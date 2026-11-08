import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import type { PropsWithChildren } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useRealtimeEvent } from './useRealtimeEvent';
import { usePurchasingDashboardRealtime } from './usePurchasingDashboardRealtime';

const access = vi.hoisted(() => ({ allowed: true }));

vi.mock('./useRealtimeEvent', () => ({ useRealtimeEvent: vi.fn() }));
vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({ can: () => access.allowed }),
}));

describe('usePurchasingDashboardRealtime', () => {
  beforeEach(() => {
    access.allowed = true;
    vi.clearAllMocks();
  });

  it('subscribes to the authorized dashboard channel and invalidates its snapshot', async () => {
    const queryClient = new QueryClient();
    queryClient.setQueryData(['dashboard', 'purchasing'], { generated_at: 'old' });
    const wrapper = ({ children }: PropsWithChildren) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );

    renderHook(() => usePurchasingDashboardRealtime(), { wrapper });

    const subscription = vi.mocked(useRealtimeEvent).mock.calls.find(
      ([channel, event]) => channel === 'dashboard.purchasing' && event === '.purchasing.dashboard.changed',
    );
    expect(subscription).toBeDefined();

    await act(async () => {
      subscription?.[2]({ refresh: true, event_id: 'purchasing-dashboard-1' });
    });

    await waitFor(() => {
      expect(queryClient.getQueryState(['dashboard', 'purchasing'])?.isInvalidated).toBe(true);
    });
  });

  it('does not subscribe when the dashboard permission is missing', () => {
    access.allowed = false;
    const queryClient = new QueryClient();
    const wrapper = ({ children }: PropsWithChildren) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );

    renderHook(() => usePurchasingDashboardRealtime(), { wrapper });

    expect(vi.mocked(useRealtimeEvent).mock.calls).toContainEqual([
      '',
      '.purchasing.dashboard.changed',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    ]);
  });
});
