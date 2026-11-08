import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import type { PropsWithChildren } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useRealtimeEvent } from './useRealtimeEvent';
import { useProductionDashboardRealtime } from './useProductionDashboardRealtime';

vi.mock('./useRealtimeEvent', () => ({ useRealtimeEvent: vi.fn() }));

describe('useProductionDashboardRealtime', () => {
  beforeEach(() => vi.clearAllMocks());

  it('subscribes to the shared work-order refresh signal and invalidates the active dashboard query', async () => {
    const queryClient = new QueryClient();
    queryClient.setQueryData(['production', 'dashboard'], { generated_at: 'old' });
    queryClient.setQueryData(['dashboard', 'plant-manager', 'week'], { generated_at: 'old' });
    queryClient.setQueryData(['dashboard', 'ppc'], { generated_at: 'old' });
    queryClient.setQueryData(['dashboard', 'widget-data', ['forecast.capacity']], { generated_at: 'old' });
    queryClient.setQueryData(['chain-bottlenecks', 'ppc_head'], { generated_at: 'old' });
    const wrapper = ({ children }: PropsWithChildren) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );

    renderHook(() => useProductionDashboardRealtime(), { wrapper });

    const subscription = vi.mocked(useRealtimeEvent).mock.calls.find(
      ([channel, event]) => channel === 'production.dashboard' && event === '.chain.refresh',
    );
    expect(subscription).toBeDefined();

    await act(async () => {
      subscription?.[2]({ refresh: true, event_id: 'event-1' });
    });

    await waitFor(() => {
      expect(queryClient.getQueryState(['production', 'dashboard'])?.isInvalidated).toBe(true);
      expect(queryClient.getQueryState(['dashboard', 'plant-manager', 'week'])?.isInvalidated).toBe(true);
      expect(queryClient.getQueryState(['dashboard', 'ppc'])?.isInvalidated).toBe(true);
      expect(queryClient.getQueryState(['dashboard', 'widget-data', ['forecast.capacity']])?.isInvalidated).toBe(true);
      expect(queryClient.getQueryState(['chain-bottlenecks', 'ppc_head'])?.isInvalidated).toBe(true);
    });
  });

  it('uses the canonical machine refresh event instead of the legacy status broadcast', () => {
    const queryClient = new QueryClient();
    const wrapper = ({ children }: PropsWithChildren) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );

    renderHook(() => useProductionDashboardRealtime(), { wrapper });

    const subscriptions = vi.mocked(useRealtimeEvent).mock.calls.map(([channel, event]) => [channel, event]);
    expect(subscriptions).toContainEqual(['production.dashboard', '.chain.refresh']);
    expect(subscriptions).not.toContainEqual(['production.dashboard', '.machine.status_changed']);
    expect(subscriptions).not.toContainEqual(['production.dashboard', '.machine.breakdown_detected']);
    expect(subscriptions).toContainEqual(['production.dashboard', '.work_order.status_changed']);
  });
});
