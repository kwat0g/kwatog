import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { useDriverDeliveryRealtime } from './useDriverDeliveryRealtime';

const { subscribe } = vi.hoisted(() => ({
  subscribe: vi.fn(),
}));

vi.mock('./useRealtimeEvent', () => ({
  useRealtimeEvent: (...args: unknown[]) => subscribe(...args),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: (selector: (state: { user: { id: string } }) => unknown) =>
    selector({ user: { id: 'driver-hash' } }),
}));

describe('useDriverDeliveryRealtime', () => {
  it('listens on the permission-scoped driver channel and refreshes only driver queries', async () => {
    const queryClient = new QueryClient();
    const invalidateQueries = vi.spyOn(queryClient, 'invalidateQueries').mockResolvedValue(undefined);
    const wrapper = ({ children }: { children: React.ReactNode }) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );

    renderHook(() => useDriverDeliveryRealtime(), { wrapper });

    expect(subscribe).toHaveBeenCalledTimes(1);
    const [channel, event, handler, onSubscribed, onError] = subscribe.mock.calls[0] as [
      string,
      string,
      () => void,
      () => void,
      () => void,
    ];
    expect(channel).toBe('driver.driver-hash');
    expect(event).toBe('.chain.refresh');

    act(() => handler());
    await waitFor(() => expect(invalidateQueries.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['driver', 'today'],
    } as never))).toBe(true));
    invalidateQueries.mockClear();

    act(() => onSubscribed());
    await waitFor(() => expect(invalidateQueries.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['driver', 'today'],
    } as never))).toBe(true));
    invalidateQueries.mockClear();

    act(() => onError());
    await waitFor(() => expect(invalidateQueries.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['driver', 'today'],
    } as never))).toBe(true));
  });
});
