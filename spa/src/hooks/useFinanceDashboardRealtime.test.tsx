import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import type { PropsWithChildren } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAuthStore } from '@/stores/authStore';
import { useRealtimeEvent } from './useRealtimeEvent';
import { useFinanceDashboardRealtime } from './useFinanceDashboardRealtime';

vi.mock('./useRealtimeEvent', () => ({ useRealtimeEvent: vi.fn() }));

describe('useFinanceDashboardRealtime', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useAuthStore.setState({
      permissions: new Set(['dashboard.accounting.view']),
      features: new Set(['accounting']),
    });
  });

  it('subscribes to the authorized Finance signal and refreshes aggregate queries', async () => {
    const queryClient = new QueryClient();
    queryClient.setQueryData(['dashboard', 'finance'], { generated_at: 'old' });
    queryClient.setQueryData(['chain-bottlenecks', 'finance-viewer', 'finance', 'own-audience'], { generated_at: 'old' });
    const wrapper = ({ children }: PropsWithChildren) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );

    renderHook(() => useFinanceDashboardRealtime(), { wrapper });

    const subscription = vi.mocked(useRealtimeEvent).mock.calls.find(
      ([channel, event]) => channel === 'dashboard.finance' && event === '.finance.dashboard.changed',
    );
    expect(subscription).toBeDefined();

    await act(async () => {
      subscription?.[2]({ refresh: true, event_id: 'event-finance-1' });
    });

    await waitFor(() => {
      expect(queryClient.getQueryState(['dashboard', 'finance'])?.isInvalidated).toBe(true);
      expect(queryClient.getQueryState(['chain-bottlenecks', 'finance-viewer', 'finance', 'own-audience'])?.isInvalidated).toBe(true);
    });
    queryClient.clear();
  });
});
