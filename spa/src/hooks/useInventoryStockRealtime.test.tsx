import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useInventoryStockRealtime } from './useInventoryStockRealtime';

const realtime = vi.hoisted(() => ({
  handler: undefined as ((payload: unknown) => void) | undefined,
  subscribe: vi.fn(),
}));

vi.mock('./useRealtimeEvent', () => ({
  useRealtimeEvent: (...args: unknown[]) => {
    const [, , handler] = args as [string, string, (payload: unknown) => void];
    realtime.handler = handler;
    realtime.subscribe(...args);
  },
}));

describe('useInventoryStockRealtime', () => {
  beforeEach(() => {
    realtime.handler = undefined;
    realtime.subscribe.mockClear();
  });

  it('subscribes to the private stock signal and invalidates stock-related active queries', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries');

    const { unmount } = renderHook(() => useInventoryStockRealtime(), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'inventory.dashboard',
      '.InventoryStockChanged',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    act(() => realtime.handler?.({ entity_type: 'inventory_stock' }));
    await waitFor(() => expect(invalidate).toHaveBeenCalled());
    const predicate = invalidate.mock.calls
      .map(([filters]) => filters?.predicate)
      .find((candidate) => typeof candidate === 'function');
    expect(predicate).toBeTypeOf('function');
    expect(predicate?.({ queryKey: ['inventory', 'stock-levels'] } as never)).toBe(true);
    expect(predicate?.({ queryKey: ['inventory', 'movements', { page: 1 }] } as never)).toBe(true);
    expect(predicate?.({ queryKey: ['dashboard', 'warehouse'] } as never)).toBe(true);
    expect(predicate?.({ queryKey: ['reference', 'uom'] } as never)).toBe(false);

    unmount();
    queryClient.clear();
  });
});
