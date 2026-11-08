import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import type { PropsWithChildren } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAuthStore } from '@/stores/authStore';
import { useRealtimeEvent } from './useRealtimeEvent';
import { usePpcDashboardRealtime } from './usePpcDashboardRealtime';

vi.mock('./useRealtimeEvent', () => ({ useRealtimeEvent: vi.fn() }));

describe('usePpcDashboardRealtime', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useAuthStore.setState({ permissions: new Set(['dashboard.ppc.view']) });
  });

  it('subscribes to the authorized PPC signal and invalidates its active dashboard query', async () => {
    const queryClient = new QueryClient();
    queryClient.setQueryData(['dashboard', 'ppc'], { generated_at: 'old' });
    queryClient.setQueryData(['chain-bottlenecks', 'ppc_head'], { generated_at: 'old' });
    const wrapper = ({ children }: PropsWithChildren) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );

    renderHook(() => usePpcDashboardRealtime(), { wrapper });

    const subscription = vi.mocked(useRealtimeEvent).mock.calls.find(
      ([channel, event]) => channel === 'dashboard.ppc' && event === '.ppc.dashboard.changed',
    );
    expect(subscription).toBeDefined();

    await act(async () => {
      subscription?.[2]({ refresh: true, event_id: 'event-ppc-1' });
    });

    await waitFor(() => {
      expect(queryClient.getQueryState(['dashboard', 'ppc'])?.isInvalidated).toBe(true);
      expect(queryClient.getQueryState(['chain-bottlenecks', 'ppc_head'])?.isInvalidated).toBe(true);
    });
    queryClient.clear();
  });
});
