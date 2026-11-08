import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { useCustomerInvoiceRealtime } from './useCustomerInvoiceRealtime';

const { subscribe, scheduleInvalidation } = vi.hoisted(() => ({
  subscribe: vi.fn(),
  scheduleInvalidation: vi.fn(),
}));

vi.mock('./useRealtimeEvent', () => ({
  useRealtimeEvent: (...args: unknown[]) => subscribe(...args),
}));
vi.mock('@/lib/realtime/invalidationMap', () => ({
  scheduleRealtimeListInvalidation: (...args: unknown[]) => scheduleInvalidation(...args),
}));

describe('useCustomerInvoiceRealtime', () => {
  it("subscribes to the customer's private channel and refreshes all invoice-dependent portal views", () => {
    const queryClient = new QueryClient();
    const wrapper = ({ children }: { children: React.ReactNode }) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );

    renderHook(() => useCustomerInvoiceRealtime('customer-hash'), { wrapper });

    expect(subscribe).toHaveBeenCalledTimes(1);
    const [channel, event, handler, onSubscribed, onError] = subscribe.mock.calls[0] as [
      string,
      string,
      () => void,
      () => void,
      () => void,
    ];
    expect(channel).toBe('customer.invoice.customer-hash');
    expect(event).toBe('.chain.refresh');

    act(() => handler());
    expect(scheduleInvalidation).toHaveBeenCalledWith(queryClient, 'invoice');
    scheduleInvalidation.mockClear();

    act(() => onSubscribed());
    expect(scheduleInvalidation).toHaveBeenCalledTimes(1);
    scheduleInvalidation.mockClear();

    act(() => onError());
    expect(scheduleInvalidation).toHaveBeenCalledTimes(1);
  });

  it('does not subscribe before the portal account identity is available', () => {
    const queryClient = new QueryClient();
    const wrapper = ({ children }: { children: React.ReactNode }) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );

    renderHook(() => useCustomerInvoiceRealtime(undefined), { wrapper });

    expect(subscribe).toHaveBeenCalledWith('', '.chain.refresh', expect.any(Function), expect.any(Function), expect.any(Function));
  });
});
